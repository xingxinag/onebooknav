<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Services\BookmarkService;
use App\Services\AuthService;
use App\Services\ConfigService;

/**
 * 首页控制器
 *
 * 处理主页面的显示和基础功能
 */
class HomeController extends Controller
{
    private BookmarkService $bookmarkService;
    private AuthService $authService;
    private ConfigService $configService;

    public function __construct()
    {
        parent::__construct();
        $this->bookmarkService = BookmarkService::getInstance();
        $this->authService = AuthService::getInstance();
        $this->configService = ConfigService::getInstance();
    }

    /**
     * 首页
     */
    public function index()
    {
        try {
            $user = $this->authService->user();
            $userId = $user['id'] ?? null;

            // 获取统计数据
            $stats = $this->bookmarkService->getBookmarkStats($userId);

            // 获取推荐书签
            $recommendedBookmarks = [];
            if ($userId) {
                $recommendedBookmarks = $this->bookmarkService->getRecommendedBookmarks(8);
            }

            // 获取热门书签
            $popularBookmarks = $this->bookmarkService->getPopularBookmarks(8);

            // 获取最近添加的书签
            $recentOptions = [
                'page' => 1,
                'per_page' => 5,
                'user_id' => $userId,
                'order_by' => 'created_at',
                'direction' => 'DESC'
            ];
            $recentResult = $this->bookmarkService->getBookmarks($recentOptions);
            $recentBookmarks = $recentResult['data'] ?? [];

            // 获取分类列表
            $categories = $this->getCategories();

            // 视图数据
            $data = [
                'title' => $this->configService->get('app.name', 'OneBookNav'),
                'user' => $user,
                'stats' => $stats,
                'recommendedBookmarks' => $recommendedBookmarks,
                'popularBookmarks' => $popularBookmarks,
                'recentBookmarks' => $recentBookmarks,
                'categories' => $categories,
                'currentPage' => 'home',
                'csrfToken' => $this->security->generateCsrfToken('home')
            ];

            return $this->view('pages/home', $data);

        } catch (\Exception $e) {
            error_log("Home page error: " . $e->getMessage());

            // 显示错误页面或基础页面
            return $this->view('pages/home', [
                'title' => '首页 - OneBookNav',
                'user' => null,
                'stats' => ['total_bookmarks' => 0, 'total_clicks' => 0, 'categories_count' => 0, 'recent_clicks' => 0],
                'recommendedBookmarks' => [],
                'popularBookmarks' => [],
                'recentBookmarks' => [],
                'categories' => [],
                'currentPage' => 'home',
                'error' => '加载页面时发生错误，请刷新页面重试'
            ]);
        }
    }

    /**
     * 分类页面
     */
    public function category()
    {
        $categoryId = (int)$this->request->getParam('id');
        $page = (int)$this->request->getQuery('page', 1);
        $perPage = (int)$this->request->getQuery('per_page', 20);

        try {
            $user = $this->authService->user();
            $userId = $user['id'] ?? null;

            // 获取分类信息
            $category = $this->database->find('categories', $categoryId);
            if (!$category) {
                return $this->notFound('分类不存在');
            }

            // 获取该分类下的书签
            $options = [
                'page' => $page,
                'per_page' => $perPage,
                'category_id' => $categoryId,
                'user_id' => $userId,
                'order_by' => 'sort_order',
                'direction' => 'ASC'
            ];

            $result = $this->bookmarkService->getBookmarks($options);

            // 获取分类列表
            $categories = $this->getCategories();

            // 面包屑导航
            $breadcrumbs = [
                ['title' => '首页', 'url' => '/'],
                ['title' => htmlspecialchars($category['name'])]
            ];

            $data = [
                'title' => htmlspecialchars($category['name']) . ' - OneBookNav',
                'user' => $user,
                'category' => $category,
                'bookmarks' => $result['data'],
                'pagination' => $result,
                'categories' => $categories,
                'currentPage' => 'category',
                'currentCategory' => $categoryId,
                'breadcrumbs' => $breadcrumbs,
                'csrfToken' => $this->security->generateCsrfToken('category')
            ];

            return $this->view('pages/category', $data);

        } catch (\Exception $e) {
            error_log("Category page error: " . $e->getMessage());
            return $this->error('加载分类页面失败');
        }
    }

    /**
     * 搜索页面
     */
    public function search()
    {
        $keyword = trim($this->request->getQuery('q', ''));
        $page = (int)$this->request->getQuery('page', 1);
        $perPage = (int)$this->request->getQuery('per_page', 20);
        $categoryId = $this->request->getQuery('category');
        $useAI = $this->request->getQuery('ai', false);

        try {
            $user = $this->authService->user();

            if (empty($keyword)) {
                return $this->redirect('/');
            }

            // 执行搜索
            $options = [
                'page' => $page,
                'per_page' => $perPage,
                'category_id' => $categoryId,
                'use_ai' => $useAI
            ];

            $result = $this->bookmarkService->searchBookmarks($keyword, $options);

            // 获取分类列表
            $categories = $this->getCategories();

            // 面包屑导航
            $breadcrumbs = [
                ['title' => '首页', 'url' => '/'],
                ['title' => '搜索结果']
            ];

            $data = [
                'title' => '搜索: ' . htmlspecialchars($keyword) . ' - OneBookNav',
                'user' => $user,
                'keyword' => $keyword,
                'bookmarks' => $result['data'],
                'pagination' => $result,
                'categories' => $categories,
                'currentPage' => 'search',
                'breadcrumbs' => $breadcrumbs,
                'useAI' => $useAI,
                'csrfToken' => $this->security->generateCsrfToken('search')
            ];

            return $this->view('pages/search', $data);

        } catch (\Exception $e) {
            error_log("Search page error: " . $e->getMessage());
            return $this->error('搜索失败');
        }
    }

    /**
     * 收藏页面
     */
    public function favorites()
    {
        $user = $this->authService->user();
        if (!$user) {
            return $this->redirect('/login');
        }

        $page = (int)$this->request->getQuery('page', 1);
        $perPage = (int)$this->request->getQuery('per_page', 20);

        try {
            // 获取用户收藏的书签
            $options = [
                'page' => $page,
                'per_page' => $perPage
            ];

            $result = $this->bookmarkService->getFavoriteBookmarks($user['id'], $options);

            // 获取分类列表
            $categories = $this->getCategories();

            // 面包屑导航
            $breadcrumbs = [
                ['title' => '首页', 'url' => '/'],
                ['title' => '我的收藏']
            ];

            $data = [
                'title' => '我的收藏 - OneBookNav',
                'user' => $user,
                'bookmarks' => $result['data'],
                'pagination' => $result,
                'categories' => $categories,
                'currentPage' => 'favorites',
                'breadcrumbs' => $breadcrumbs,
                'csrfToken' => $this->security->generateCsrfToken('favorites')
            ];

            return $this->view('pages/favorites', $data);

        } catch (\Exception $e) {
            error_log("Favorites page error: " . $e->getMessage());
            return $this->error('加载收藏页面失败');
        }
    }

    /**
     * 热门书签页面
     */
    public function popular()
    {
        $page = (int)$this->request->getQuery('page', 1);
        $days = (int)$this->request->getQuery('days', 30);

        try {
            $user = $this->authService->user();

            // 获取热门书签
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $popularBookmarks = $this->bookmarkService->getPopularBookmarks($limit + $offset, $days);

            // 分页处理
            $bookmarks = array_slice($popularBookmarks, $offset, $limit);
            $total = count($popularBookmarks);

            $pagination = [
                'data' => $bookmarks,
                'total' => $total,
                'page' => $page,
                'per_page' => $limit,
                'total_pages' => ceil($total / $limit),
                'has_next' => $page * $limit < $total,
                'has_prev' => $page > 1
            ];

            // 获取分类列表
            $categories = $this->getCategories();

            // 面包屑导航
            $breadcrumbs = [
                ['title' => '首页', 'url' => '/'],
                ['title' => '热门书签']
            ];

            $data = [
                'title' => '热门书签 - OneBookNav',
                'user' => $user,
                'bookmarks' => $bookmarks,
                'pagination' => $pagination,
                'categories' => $categories,
                'currentPage' => 'popular',
                'breadcrumbs' => $breadcrumbs,
                'days' => $days,
                'csrfToken' => $this->security->generateCsrfToken('popular')
            ];

            return $this->view('pages/popular', $data);

        } catch (\Exception $e) {
            error_log("Popular page error: " . $e->getMessage());
            return $this->error('加载热门页面失败');
        }
    }

    /**
     * 最近访问页面
     */
    public function recent()
    {
        $user = $this->authService->user();
        if (!$user) {
            return $this->redirect('/login');
        }

        $page = (int)$this->request->getQuery('page', 1);
        $perPage = (int)$this->request->getQuery('per_page', 20);

        try {
            // 获取用户最近访问的书签
            $sql = "SELECT DISTINCT w.*, c.name as category_name, c.icon as category_icon,
                           cl.clicked_at as last_visit
                    FROM websites w
                    LEFT JOIN categories c ON w.category_id = c.id
                    INNER JOIN click_logs cl ON w.id = cl.website_id
                    WHERE cl.user_id = ? AND w.status = 'active'
                    ORDER BY cl.clicked_at DESC
                    LIMIT ? OFFSET ?";

            $offset = ($page - 1) * $perPage;
            $stmt = $this->database->query($sql, [$user['id'], $perPage, $offset]);
            $bookmarks = $stmt->fetchAll();

            // 获取总数
            $countSql = "SELECT COUNT(DISTINCT w.id) as total
                         FROM websites w
                         INNER JOIN click_logs cl ON w.id = cl.website_id
                         WHERE cl.user_id = ? AND w.status = 'active'";
            $countStmt = $this->database->query($countSql, [$user['id']]);
            $total = $countStmt->fetch()['total'];

            $pagination = [
                'data' => $bookmarks,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
                'has_next' => $page * $perPage < $total,
                'has_prev' => $page > 1
            ];

            // 获取分类列表
            $categories = $this->getCategories();

            // 面包屑导航
            $breadcrumbs = [
                ['title' => '首页', 'url' => '/'],
                ['title' => '最近访问']
            ];

            $data = [
                'title' => '最近访问 - OneBookNav',
                'user' => $user,
                'bookmarks' => $bookmarks,
                'pagination' => $pagination,
                'categories' => $categories,
                'currentPage' => 'recent',
                'breadcrumbs' => $breadcrumbs,
                'csrfToken' => $this->security->generateCsrfToken('recent')
            ];

            return $this->view('pages/recent', $data);

        } catch (\Exception $e) {
            error_log("Recent page error: " . $e->getMessage());
            return $this->error('加载最近访问页面失败');
        }
    }

    /**
     * 获取分类列表
     */
    private function getCategories(): array
    {
        try {
            $user = $this->authService->user();
            $userId = $user['id'] ?? null;

            $where = 'is_active = 1';
            $params = [];

            if (!$this->authService->isAdmin()) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }

            $categories = $this->database->findAll(
                'categories',
                $where,
                $params,
                'sort_order ASC, name ASC'
            );

            // 获取每个分类的书签数量
            foreach ($categories as &$category) {
                $countSql = "SELECT COUNT(*) as count FROM websites
                            WHERE category_id = ? AND status = 'active'";
                $countParams = [$category['id']];

                if (!$this->authService->isAdmin()) {
                    $countSql .= " AND (is_private = 0 OR user_id = ?)";
                    $countParams[] = $userId;
                }

                $countStmt = $this->database->query($countSql, $countParams);
                $category['website_count'] = $countStmt->fetch()['count'];
            }

            return $categories;

        } catch (\Exception $e) {
            error_log("Get categories error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 书签组件渲染
     */
    public function renderBookmark(array $bookmark, string $view = 'grid'): string
    {
        ob_start();
        $showActions = true;
        $showMeta = true;
        include ROOT_PATH . '/templates/components/bookmark.php';
        return ob_get_clean();
    }
}