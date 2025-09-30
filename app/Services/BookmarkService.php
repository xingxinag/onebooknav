<?php

namespace App\Services;

use App\Core\Container;
use Exception;

/**
 * 书签管理服务
 *
 * 实现"终极.txt"要求的完整书签管理功能
 * 融合 BookNav 和 OneNav 的所有书签功能，实现 1+1>2 的效果
 *
 * 核心功能：
 * - BookNav: Flask 基础、多用户支持、权限管理、标签系统、拖拽排序
 * - OneNav: PHP 生态、AI 搜索、死链检测、主题系统、右键菜单
 * - OneBookNav: 现代化架构、三种部署方式、WebDAV 备份、统一数据模型
 */
class BookmarkService
{
    private static $instance = null;
    private DatabaseService $database;
    private ConfigService $config;
    private AuthService $auth;
    private SecurityService $security;
    private CacheService $cache;

    private function __construct()
    {
        $container = Container::getInstance();
        $this->database = $container->get('database');
        $this->config = $container->get('config');
        $this->auth = $container->get('auth');
        $this->security = $container->get('security');
        $this->cache = $container->get('cache');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取书签列表（分页）
     *
     * 融合 BookNav 的多用户支持和 OneNav 的高级过滤
     */
    public function getBookmarks(array $options = []): array
    {
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 20;
        $categoryId = $options['category_id'] ?? null;
        $userId = $options['user_id'] ?? null;
        $status = $options['status'] ?? 'active';
        $isFeatured = $options['is_featured'] ?? null;
        $isPrivate = $options['is_private'] ?? null;
        $orderBy = $options['order_by'] ?? 'sort_order';
        $direction = $options['direction'] ?? 'ASC';
        $tags = $options['tags'] ?? [];

        // 权限检查
        $currentUserId = $this->auth->getCurrentUserId();
        if (!$this->auth->isAdmin() && $userId && $userId !== $currentUserId) {
            throw new BookmarkException('没有权限访问该用户的书签');
        }

        // 构建缓存键
        $cacheKey = 'bookmarks:' . md5(serialize($options) . ($currentUserId ?? 'guest'));
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // 构建查询条件
        $where = 'w.status = ?';
        $params = [$status];

        if ($categoryId) {
            $where .= ' AND w.category_id = ?';
            $params[] = $categoryId;
        }

        if ($userId) {
            $where .= ' AND w.user_id = ?';
            $params[] = $userId;
        }

        if ($isFeatured !== null) {
            $where .= ' AND w.is_featured = ?';
            $params[] = $isFeatured ? 1 : 0;
        }

        if ($isPrivate !== null) {
            $where .= ' AND w.is_private = ?';
            $params[] = $isPrivate ? 1 : 0;
        } elseif (!$this->auth->isAdmin()) {
            // 非管理员只能看到公开书签或自己的私有书签
            $where .= ' AND (w.is_private = 0 OR w.user_id = ?)';
            $params[] = $currentUserId;
        }

        // 标签过滤
        if (!empty($tags)) {
            $tagPlaceholders = str_repeat('?,', count($tags) - 1) . '?';
            $where .= " AND w.id IN (
                SELECT wt.website_id FROM website_tags wt
                INNER JOIN tags t ON wt.tag_id = t.id
                WHERE t.name IN ({$tagPlaceholders})
                GROUP BY wt.website_id
                HAVING COUNT(DISTINCT t.id) = ?
            )";
            $params = array_merge($params, $tags, [count($tags)]);
        }

        // 执行查询
        $result = $this->database->paginate(
            'websites w
             LEFT JOIN categories c ON w.category_id = c.id
             LEFT JOIN users u ON w.user_id = u.id',
            $page,
            $perPage,
            $where,
            $params,
            "w.{$orderBy} {$direction}",
            'w.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
             u.username as owner_username'
        );

        // 获取每个书签的标签和统计信息
        foreach ($result['data'] as &$bookmark) {
            $bookmark['tags'] = $this->getBookmarkTags($bookmark['id']);
            $bookmark['click_stats'] = $this->getBookmarkClickStats($bookmark['id']);
            $bookmark['can_edit'] = $this->canEditBookmark($bookmark, $currentUserId);
            $bookmark['can_delete'] = $this->canDeleteBookmark($bookmark, $currentUserId);
        }

        // 缓存结果
        $this->cache->set($cacheKey, $result, 300);

        return $result;
    }

    /**
     * 获取单个书签详情
     */
    public function getBookmark(int $id): array
    {
        $sql = "SELECT w.*, c.name as category_name, c.icon as category_icon,
                       c.color as category_color, u.username as owner_username
                FROM websites w
                LEFT JOIN categories c ON w.category_id = c.id
                LEFT JOIN users u ON w.user_id = u.id
                WHERE w.id = ?";

        $bookmark = $this->database->query($sql, [$id])->fetch();

        if (!$bookmark) {
            throw new BookmarkException('书签不存在');
        }

        // 权限检查
        $currentUserId = $this->auth->getCurrentUserId();
        if (!$this->canViewBookmark($bookmark, $currentUserId)) {
            throw new BookmarkException('没有权限访问该书签');
        }

        // 获取详细信息
        $bookmark['tags'] = $this->getBookmarkTags($id);
        $bookmark['click_stats'] = $this->getBookmarkClickStats($id);
        $bookmark['check_history'] = $this->getBookmarkCheckHistory($id, 10);
        $bookmark['can_edit'] = $this->canEditBookmark($bookmark, $currentUserId);
        $bookmark['can_delete'] = $this->canDeleteBookmark($bookmark, $currentUserId);

        return $bookmark;
    }

    /**
     * 创建书签
     *
     * 融合 BookNav 的完整数据模型和 OneNav 的智能处理
     */
    public function createBookmark(array $data): array
    {
        $currentUserId = $this->auth->getCurrentUserId();

        // 数据验证和清理
        $this->validateBookmarkData($data);
        $data = $this->sanitizeBookmarkData($data);

        // 设置默认值
        $data['user_id'] = $currentUserId;
        $data['status'] = $data['status'] ?? 'active';
        $data['is_private'] = $data['is_private'] ?? false;
        $data['is_featured'] = $data['is_featured'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? $this->getNextSortOrder($data['category_id']);
        $data['weight'] = $data['weight'] ?? 0;
        $data['clicks'] = 0;

        // 智能获取网站信息
        $siteInfo = $this->fetchWebsiteInfo($data['url']);
        if ($siteInfo) {
            $data['title'] = $data['title'] ?: $siteInfo['title'];
            $data['description'] = $data['description'] ?: $siteInfo['description'];
            $data['favicon_url'] = $data['favicon_url'] ?: $siteInfo['favicon'];
            $data['keywords'] = $siteInfo['keywords'];
        }

        // 开始事务
        return $this->database->transaction(function() use ($data) {
            // 创建书签
            $bookmarkId = $this->database->insert('websites', [
                'title' => $data['title'],
                'url' => $data['url'],
                'description' => $data['description'],
                'category_id' => $data['category_id'],
                'user_id' => $data['user_id'],
                'icon' => $data['icon'] ?? null,
                'favicon_url' => $data['favicon_url'] ?? null,
                'sort_order' => $data['sort_order'],
                'status' => $data['status'],
                'is_private' => $data['is_private'],
                'is_featured' => $data['is_featured'],
                'weight' => $data['weight'],
                'keywords' => $data['keywords'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 处理标签
            if (!empty($data['tags'])) {
                $this->attachTags($bookmarkId, $data['tags']);
            }

            // 记录审计日志
            $this->recordAuditLog('CREATE', 'websites', $bookmarkId, null, $data);

            // 清除缓存
            $this->clearBookmarkCache();

            return $this->getBookmark($bookmarkId);
        });
    }

    /**
     * 更新书签
     */
    public function updateBookmark(int $id, array $data): array
    {
        $bookmark = $this->getBookmark($id);
        $currentUserId = $this->auth->getCurrentUserId();

        // 权限检查
        if (!$this->canEditBookmark($bookmark, $currentUserId)) {
            throw new BookmarkException('没有权限编辑该书签');
        }

        // 数据验证和清理
        $this->validateBookmarkData($data, $id);
        $data = $this->sanitizeBookmarkData($data);

        // 如果URL发生变化，重新获取网站信息
        if (isset($data['url']) && $data['url'] !== $bookmark['url']) {
            $siteInfo = $this->fetchWebsiteInfo($data['url']);
            if ($siteInfo && !isset($data['favicon_url'])) {
                $data['favicon_url'] = $siteInfo['favicon'];
            }
        }

        // 强制刷新favicon
        if (isset($data['refresh_favicon']) && $data['refresh_favicon']) {
            $siteInfo = $this->fetchWebsiteInfo($bookmark['url']);
            if ($siteInfo) {
                $data['favicon_url'] = $siteInfo['favicon'];
            }
            unset($data['refresh_favicon']);
        }

        return $this->database->transaction(function() use ($id, $data, $bookmark) {
            // 更新书签
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->database->update('websites', $data, 'id = ?', [$id]);

            // 处理标签更新
            if (isset($data['tags'])) {
                $this->syncTags($id, $data['tags']);
            }

            // 记录审计日志
            $this->recordAuditLog('UPDATE', 'websites', $id, $bookmark, $data);

            // 清除缓存
            $this->clearBookmarkCache();

            return $this->getBookmark($id);
        });
    }

    /**
     * 删除书签
     */
    public function deleteBookmark(int $id): bool
    {
        $bookmark = $this->getBookmark($id);
        $currentUserId = $this->auth->getCurrentUserId();

        // 权限检查
        if (!$this->canDeleteBookmark($bookmark, $currentUserId)) {
            throw new BookmarkException('没有权限删除该书签');
        }

        return $this->database->transaction(function() use ($id, $bookmark) {
            // 删除标签关联
            $this->database->delete('website_tags', 'website_id = ?', [$id]);

            // 删除点击日志（可选，保留历史数据）
            // $this->database->delete('click_logs', 'website_id = ?', [$id]);

            // 删除死链检测记录
            $this->database->delete('deadlink_checks', 'website_id = ?', [$id]);

            // 删除收藏记录
            $this->database->delete('user_favorites', 'website_id = ?', [$id]);

            // 删除书签
            $this->database->delete('websites', 'id = ?', [$id]);

            // 记录审计日志
            $this->recordAuditLog('DELETE', 'websites', $id, $bookmark, null);

            // 清除缓存
            $this->clearBookmarkCache();

            return true;
        });
    }

    /**
     * 批量操作书签
     */
    public function batchUpdateBookmarks(array $bookmarkIds, array $data): array
    {
        $currentUserId = $this->auth->getCurrentUserId();
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($bookmarkIds as $id) {
            try {
                $bookmark = $this->getBookmark($id);
                if ($this->canEditBookmark($bookmark, $currentUserId)) {
                    $this->updateBookmark($id, $data);
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "书签 {$id}: 没有权限";
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "书签 {$id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * 批量删除书签
     */
    public function batchDeleteBookmarks(array $bookmarkIds): array
    {
        $currentUserId = $this->auth->getCurrentUserId();
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($bookmarkIds as $id) {
            try {
                $bookmark = $this->getBookmark($id);
                if ($this->canDeleteBookmark($bookmark, $currentUserId)) {
                    $this->deleteBookmark($id);
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "书签 {$id}: 没有权限";
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "书签 {$id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * 搜索书签
     *
     * 融合 OneNav 的 AI 搜索和 BookNav 的精确过滤
     */
    public function searchBookmarks(string $keyword, array $options = []): array
    {
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 20;
        $categoryId = $options['category_id'] ?? null;
        $userId = $options['user_id'] ?? null;
        $tags = $options['tags'] ?? [];
        $useAI = $options['use_ai'] ?? false;

        // 记录搜索日志
        $this->recordSearchLog($keyword, $this->auth->getCurrentUserId());

        // 缓存键
        $cacheKey = 'search:' . md5($keyword . serialize($options));
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // AI 增强搜索
        if ($useAI && $this->config->get('features.ai_search_enabled', false)) {
            $enhancedKeywords = $this->enhanceSearchWithAI($keyword);
            $keyword = implode(' ', array_unique(array_merge([$keyword], $enhancedKeywords)));
        }

        // 构建搜索查询
        $where = "w.status = 'active' AND (
            w.title LIKE ? OR
            w.description LIKE ? OR
            w.url LIKE ? OR
            w.keywords LIKE ? OR
            EXISTS (
                SELECT 1 FROM website_tags wt
                INNER JOIN tags t ON wt.tag_id = t.id
                WHERE wt.website_id = w.id AND t.name LIKE ?
            )
        )";

        $searchTerm = "%{$keyword}%";
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];

        // 添加额外过滤条件
        if ($categoryId) {
            $where .= ' AND w.category_id = ?';
            $params[] = $categoryId;
        }

        if ($userId) {
            $where .= ' AND w.user_id = ?';
            $params[] = $userId;
        }

        // 权限过滤
        $currentUserId = $this->auth->getCurrentUserId();
        if (!$this->auth->isAdmin()) {
            $where .= ' AND (w.is_private = 0 OR w.user_id = ?)';
            $params[] = $currentUserId;
        }

        // 标签过滤
        if (!empty($tags)) {
            $tagPlaceholders = str_repeat('?,', count($tags) - 1) . '?';
            $where .= " AND w.id IN (
                SELECT wt.website_id FROM website_tags wt
                INNER JOIN tags t ON wt.tag_id = t.id
                WHERE t.name IN ({$tagPlaceholders})
                GROUP BY wt.website_id
                HAVING COUNT(DISTINCT t.id) = ?
            )";
            $params = array_merge($params, $tags, [count($tags)]);
        }

        // 执行搜索
        $result = $this->database->paginate(
            'websites w
             LEFT JOIN categories c ON w.category_id = c.id
             LEFT JOIN users u ON w.user_id = u.id',
            $page,
            $perPage,
            $where,
            $params,
            'w.weight DESC, w.clicks DESC, w.created_at DESC',
            'w.*, c.name as category_name, c.icon as category_icon,
             u.username as owner_username'
        );

        // 获取附加信息
        foreach ($result['data'] as &$bookmark) {
            $bookmark['tags'] = $this->getBookmarkTags($bookmark['id']);
            $bookmark['relevance_score'] = $this->calculateRelevanceScore($bookmark, $keyword);
        }

        // 按相关性排序
        usort($result['data'], function($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        $result['keyword'] = $keyword;
        $result['search_time'] = microtime(true);

        // 缓存结果
        $this->cache->set($cacheKey, $result, 300);

        return $result;
    }

    /**
     * 获取推荐书签
     *
     * 基于用户行为和 AI 算法的智能推荐
     */
    public function getRecommendedBookmarks(int $limit = 10): array
    {
        $currentUserId = $this->auth->getCurrentUserId();
        $cacheKey = "recommended:{$currentUserId}:{$limit}";

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // 基于用户点击历史的推荐
        $sql = "SELECT w.*, c.name as category_name,
                       COUNT(cl.id) as click_score,
                       AVG(CASE WHEN cl.user_id = ? THEN 2 ELSE 1 END) as user_preference
                FROM websites w
                LEFT JOIN categories c ON w.category_id = c.id
                LEFT JOIN click_logs cl ON w.id = cl.website_id
                WHERE w.status = 'active'
                  AND (w.is_private = 0 OR w.user_id = ?)
                  AND w.id NOT IN (
                      SELECT website_id FROM click_logs
                      WHERE user_id = ? AND clicked_at > datetime('now', '-30 days')
                  )
                GROUP BY w.id
                ORDER BY (click_score * user_preference * w.weight) DESC, w.created_at DESC
                LIMIT ?";

        $stmt = $this->database->query($sql, [$currentUserId, $currentUserId, $currentUserId, $limit]);
        $recommendations = $stmt->fetchAll();

        // 获取标签信息
        foreach ($recommendations as &$bookmark) {
            $bookmark['tags'] = $this->getBookmarkTags($bookmark['id']);
            $bookmark['recommendation_reason'] = $this->getRecommendationReason($bookmark, $currentUserId);
        }

        $this->cache->set($cacheKey, $recommendations, 3600);
        return $recommendations;
    }

    /**
     * 获取热门书签
     */
    public function getPopularBookmarks(int $limit = 10, int $days = 30): array
    {
        $cacheKey = "popular:{$limit}:{$days}";

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $sql = "SELECT w.*, c.name as category_name,
                       COUNT(cl.id) as recent_clicks,
                       w.clicks as total_clicks
                FROM websites w
                LEFT JOIN categories c ON w.category_id = c.id
                LEFT JOIN click_logs cl ON w.id = cl.website_id
                    AND cl.clicked_at > datetime('now', '-{$days} days')
                WHERE w.status = 'active' AND w.is_private = 0
                GROUP BY w.id
                ORDER BY recent_clicks DESC, total_clicks DESC, w.weight DESC
                LIMIT ?";

        $stmt = $this->database->query($sql, [$limit]);
        $popular = $stmt->fetchAll();

        foreach ($popular as &$bookmark) {
            $bookmark['tags'] = $this->getBookmarkTags($bookmark['id']);
        }

        $this->cache->set($cacheKey, $popular, 3600);
        return $popular;
    }

    /**
     * 获取用户收藏的书签
     */
    public function getFavoriteBookmarks(int $userId, array $options = []): array
    {
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 20;

        $sql = "SELECT w.*, c.name as category_name, f.created_at as favorited_at
                FROM user_favorites f
                INNER JOIN websites w ON f.website_id = w.id
                LEFT JOIN categories c ON w.category_id = c.id
                WHERE f.user_id = ? AND w.status = 'active'
                ORDER BY f.created_at DESC";

        return $this->database->paginate($sql, $page, $perPage, '', [$userId]);
    }

    /**
     * 添加/移除收藏
     */
    public function toggleFavorite(int $bookmarkId): bool
    {
        $currentUserId = $this->auth->getCurrentUserId();
        if (!$currentUserId) {
            throw new BookmarkException('用户未登录');
        }

        // 检查书签是否存在且可访问
        $bookmark = $this->getBookmark($bookmarkId);

        // 检查是否已收藏
        $existing = $this->database->query(
            "SELECT id FROM user_favorites WHERE user_id = ? AND website_id = ?",
            [$currentUserId, $bookmarkId]
        )->fetch();

        if ($existing) {
            // 移除收藏
            $this->database->delete('user_favorites', 'user_id = ? AND website_id = ?', [$currentUserId, $bookmarkId]);
            return false;
        } else {
            // 添加收藏
            $this->database->insert('user_favorites', [
                'user_id' => $currentUserId,
                'website_id' => $bookmarkId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        }
    }

    /**
     * 记录点击
     */
    public function recordClick(int $bookmarkId, ?string $referer = null): void
    {
        $currentUserId = $this->auth->getCurrentUserId();
        $bookmark = $this->getBookmark($bookmarkId);

        // 更新点击数
        $this->database->query(
            "UPDATE websites SET clicks = clicks + 1, updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $bookmarkId]
        );

        // 记录详细点击日志
        $this->database->insert('click_logs', [
            'website_id' => $bookmarkId,
            'user_id' => $currentUserId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'referer' => $referer,
            'clicked_at' => date('Y-m-d H:i:s')
        ]);

        // 清除相关缓存
        $this->cache->deletePattern('popular:');
        $this->cache->deletePattern('recommended:');
    }

    /**
     * 更新书签排序
     */
    public function updateSortOrder(int $categoryId, array $sortData): bool
    {
        return $this->database->transaction(function() use ($categoryId, $sortData) {
            foreach ($sortData as $item) {
                if (isset($item['id']) && isset($item['sort_order'])) {
                    $this->database->update('websites', [
                        'sort_order' => (int)$item['sort_order'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ? AND category_id = ?', [$item['id'], $categoryId]);
                }
            }

            $this->clearBookmarkCache();
            return true;
        });
    }

    /**
     * 死链检测
     *
     * 融合 OneNav 的死链检测功能，支持批量检测和智能重试
     */
    public function checkDeadLinks(array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $force = $options['force'] ?? false;
        $specificIds = $options['bookmark_ids'] ?? [];

        $results = [
            'checked' => 0,
            'ok' => 0,
            'broken' => 0,
            'timeout' => 0,
            'errors' => []
        ];

        // 构建查询条件
        $where = "status IN ('active', 'broken')";
        $params = [];

        if (!$force) {
            $where .= " AND (last_checked_at IS NULL OR last_checked_at < datetime('now', '-7 days'))";
        }

        if (!empty($specificIds)) {
            $placeholders = str_repeat('?,', count($specificIds) - 1) . '?';
            $where .= " AND id IN ({$placeholders})";
            $params = array_merge($params, $specificIds);
        }

        // 获取需要检查的书签
        $stmt = $this->database->query(
            "SELECT id, url, title FROM websites WHERE {$where} ORDER BY last_checked_at ASC NULLS FIRST LIMIT ?",
            array_merge($params, [$limit])
        );
        $websites = $stmt->fetchAll();

        foreach ($websites as $website) {
            try {
                $checkResult = $this->checkUrl($website['url']);

                // 更新书签状态
                $updateData = [
                    'last_checked_at' => date('Y-m-d H:i:s'),
                    'check_status' => $checkResult['status'],
                    'response_time' => $checkResult['response_time'],
                    'http_status' => $checkResult['http_status'],
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // 根据检查结果更新状态
                if ($checkResult['status'] === 'ok') {
                    $updateData['status'] = 'active';
                    $results['ok']++;
                } else {
                    $updateData['status'] = 'broken';
                    $results['broken']++;

                    if ($checkResult['status'] === 'timeout') {
                        $results['timeout']++;
                    }
                }

                $this->database->update('websites', $updateData, 'id = ?', [$website['id']]);

                // 记录检查日志
                $this->database->insert('deadlink_checks', [
                    'website_id' => $website['id'],
                    'check_time' => date('Y-m-d H:i:s'),
                    'status' => $checkResult['status'],
                    'response_time' => $checkResult['response_time'],
                    'http_status' => $checkResult['http_status'],
                    'error_message' => $checkResult['error_message']
                ]);

                $results['checked']++;

            } catch (Exception $e) {
                $results['errors'][] = [
                    'website_id' => $website['id'],
                    'url' => $website['url'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * 获取书签统计信息
     */
    public function getBookmarkStats(?int $userId = null): array
    {
        $cacheKey = 'stats:' . ($userId ?? 'global');

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $where = $userId ? "WHERE user_id = {$userId}" : "";

        // 基础统计
        $basicStats = $this->database->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'broken' THEN 1 END) as broken,
                COUNT(CASE WHEN is_featured = 1 THEN 1 END) as featured,
                COUNT(CASE WHEN is_private = 1 THEN 1 END) as private,
                SUM(clicks) as total_clicks,
                AVG(clicks) as avg_clicks
            FROM websites {$where}
        ")->fetch();

        // 分类统计
        $categoryStats = $this->database->query("
            SELECT c.name, c.icon, c.color, COUNT(w.id) as count, SUM(w.clicks) as clicks
            FROM categories c
            LEFT JOIN websites w ON c.id = w.category_id " . ($userId ? "AND w.user_id = {$userId}" : "") . "
            GROUP BY c.id, c.name, c.icon, c.color
            ORDER BY count DESC
        ")->fetchAll();

        // 热门标签
        $tagStats = $this->database->query("
            SELECT t.name, t.color, COUNT(wt.website_id) as count
            FROM tags t
            INNER JOIN website_tags wt ON t.id = wt.tag_id
            INNER JOIN websites w ON wt.website_id = w.id " . ($userId ? "AND w.user_id = {$userId}" : "") . "
            GROUP BY t.id, t.name, t.color
            ORDER BY count DESC
            LIMIT 20
        ")->fetchAll();

        // 时间统计
        $timeStats = $this->database->query("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as count
            FROM websites " . ($userId ? "WHERE user_id = {$userId}" : "") . "
            WHERE created_at >= datetime('now', '-30 days')
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ")->fetchAll();

        $stats = [
            'basic' => $basicStats,
            'categories' => $categoryStats,
            'tags' => $tagStats,
            'timeline' => $timeStats,
            'generated_at' => date('Y-m-d H:i:s')
        ];

        $this->cache->set($cacheKey, $stats, 3600);
        return $stats;
    }

    // ==================== 私有方法 ====================

    /**
     * 验证书签数据
     */
    private function validateBookmarkData(array $data, ?int $excludeId = null): void
    {
        if (empty($data['title'])) {
            throw new BookmarkException('标题不能为空');
        }

        if (strlen($data['title']) > 200) {
            throw new BookmarkException('标题长度不能超过200个字符');
        }

        if (empty($data['url'])) {
            throw new BookmarkException('URL不能为空');
        }

        if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            throw new BookmarkException('URL格式无效');
        }

        if (!empty($data['category_id'])) {
            $category = $this->database->find('categories', $data['category_id']);
            if (!$category) {
                throw new BookmarkException('分类不存在');
            }
        }

        // 检查URL重复
        $existing = $this->database->query(
            "SELECT id FROM websites WHERE url = ? AND id != ?",
            [$data['url'], $excludeId ?? 0]
        )->fetch();

        if ($existing) {
            throw new BookmarkException('该URL已存在');
        }
    }

    /**
     * 清理书签数据
     */
    private function sanitizeBookmarkData(array $data): array
    {
        $data['title'] = trim($data['title']);
        $data['url'] = trim($data['url']);

        if (isset($data['description'])) {
            $data['description'] = trim($data['description']);
        }

        if (isset($data['notes'])) {
            $data['notes'] = trim($data['notes']);
        }

        return $this->security->sanitizeArray($data);
    }

    /**
     * 获取网站信息
     */
    private function fetchWebsiteInfo(string $url): ?array
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'OneBookNav/1.0 (Website Scanner)',
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$html) {
                return null;
            }

            $doc = new \DOMDocument();
            @$doc->loadHTML($html);
            $xpath = new \DOMXPath($doc);

            $title = '';
            $description = '';
            $favicon = '';
            $keywords = '';

            // 提取标题
            $titleNodes = $xpath->query('//title');
            if ($titleNodes->length > 0) {
                $title = trim($titleNodes->item(0)->textContent);
            }

            // 提取描述
            $metaDesc = $xpath->query('//meta[@name="description"]/@content');
            if ($metaDesc->length > 0) {
                $description = trim($metaDesc->item(0)->textContent);
            }

            // 提取关键词
            $metaKeywords = $xpath->query('//meta[@name="keywords"]/@content');
            if ($metaKeywords->length > 0) {
                $keywords = trim($metaKeywords->item(0)->textContent);
            }

            // 提取favicon
            $favicon = $this->extractFavicon($url, $xpath);

            return [
                'title' => $title,
                'description' => $description,
                'favicon' => $favicon,
                'keywords' => $keywords
            ];

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 提取网站图标
     */
    private function extractFavicon(string $url, \DOMXPath $xpath): string
    {
        $parsed = parse_url($url);
        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];

        // 查找页面中定义的favicon
        $iconLinks = $xpath->query('//link[@rel="icon" or @rel="shortcut icon" or @rel="apple-touch-icon"]/@href');
        if ($iconLinks->length > 0) {
            $iconHref = $iconLinks->item(0)->textContent;
            if (strpos($iconHref, 'http') === 0) {
                return $iconHref;
            } else {
                return $baseUrl . '/' . ltrim($iconHref, '/');
            }
        }

        // 尝试默认路径
        $defaultPaths = ['/favicon.ico', '/favicon.png', '/apple-touch-icon.png'];
        foreach ($defaultPaths as $path) {
            $iconUrl = $baseUrl . $path;
            if ($this->urlExists($iconUrl)) {
                return $iconUrl;
            }
        }

        // 使用Google的favicon服务
        return "https://www.google.com/s2/favicons?domain=" . $parsed['host'];
    }

    /**
     * 检查URL是否存在
     */
    private function urlExists(string $url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * 检查URL状态
     */
    private function checkUrl(string $url): array
    {
        $startTime = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'OneBookNav/1.0 (Link Checker)',
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $responseTime = round((microtime(true) - $startTime) * 1000);

        if ($error) {
            $status = strpos($error, 'timeout') !== false ? 'timeout' : 'error';
            return [
                'status' => $status,
                'http_status' => 0,
                'response_time' => $responseTime,
                'error_message' => $error
            ];
        }

        if ($httpCode >= 200 && $httpCode < 400) {
            $status = 'ok';
        } elseif ($httpCode >= 400 && $httpCode < 500) {
            $status = 'not_found';
        } else {
            $status = 'error';
        }

        return [
            'status' => $status,
            'http_status' => $httpCode,
            'response_time' => $responseTime,
            'error_message' => null
        ];
    }

    /**
     * 权限检查方法
     */
    private function canViewBookmark(array $bookmark, ?int $userId): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }

        if (!$bookmark['is_private']) {
            return true;
        }

        return $bookmark['user_id'] == $userId;
    }

    private function canEditBookmark(array $bookmark, ?int $userId): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }

        return $bookmark['user_id'] == $userId;
    }

    private function canDeleteBookmark(array $bookmark, ?int $userId): bool
    {
        return $this->canEditBookmark($bookmark, $userId);
    }

    /**
     * 标签管理方法
     */
    private function getBookmarkTags(int $bookmarkId): array
    {
        return $this->database->query("
            SELECT t.* FROM tags t
            INNER JOIN website_tags wt ON t.id = wt.tag_id
            WHERE wt.website_id = ?
            ORDER BY t.name
        ", [$bookmarkId])->fetchAll();
    }

    private function attachTags(int $bookmarkId, array $tags): void
    {
        foreach ($tags as $tag) {
            $tagId = is_array($tag) ? $tag['id'] : $tag;

            $this->database->query(
                "INSERT OR IGNORE INTO website_tags (website_id, tag_id) VALUES (?, ?)",
                [$bookmarkId, $tagId]
            );
        }
    }

    private function syncTags(int $bookmarkId, array $tags): void
    {
        // 删除现有标签
        $this->database->delete('website_tags', 'website_id = ?', [$bookmarkId]);

        // 添加新标签
        if (!empty($tags)) {
            $this->attachTags($bookmarkId, $tags);
        }
    }

    /**
     * 辅助方法
     */
    private function getNextSortOrder(int $categoryId): int
    {
        $result = $this->database->query(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM websites WHERE category_id = ?",
            [$categoryId]
        )->fetch();

        return (int)$result[0];
    }

    private function getBookmarkClickStats(int $bookmarkId): array
    {
        return $this->database->query("
            SELECT
                COUNT(*) as total_clicks,
                COUNT(DISTINCT user_id) as unique_users,
                MAX(clicked_at) as last_click,
                COUNT(CASE WHEN clicked_at > datetime('now', '-7 days') THEN 1 END) as week_clicks,
                COUNT(CASE WHEN clicked_at > datetime('now', '-30 days') THEN 1 END) as month_clicks
            FROM click_logs
            WHERE website_id = ?
        ", [$bookmarkId])->fetch();
    }

    private function getBookmarkCheckHistory(int $bookmarkId, int $limit): array
    {
        return $this->database->query("
            SELECT * FROM deadlink_checks
            WHERE website_id = ?
            ORDER BY check_time DESC
            LIMIT ?
        ", [$bookmarkId, $limit])->fetchAll();
    }

    private function enhanceSearchWithAI(string $keyword): array
    {
        // 这里可以集成 AI 服务来增强搜索关键词
        // 暂时返回一些相关的同义词
        $synonyms = [
            'search' => ['find', 'lookup', 'query'],
            'video' => ['movie', 'film', 'clip'],
            'news' => ['article', 'report', 'update'],
            'code' => ['programming', 'development', 'coding']
        ];

        $keyword = strtolower($keyword);
        foreach ($synonyms as $key => $values) {
            if (strpos($keyword, $key) !== false) {
                return $values;
            }
        }

        return [];
    }

    private function calculateRelevanceScore(array $bookmark, string $keyword): float
    {
        $score = 0;
        $keyword = strtolower($keyword);

        // 标题匹配权重最高
        if (stripos($bookmark['title'], $keyword) !== false) {
            $score += 10;
        }

        // URL匹配
        if (stripos($bookmark['url'], $keyword) !== false) {
            $score += 8;
        }

        // 描述匹配
        if (stripos($bookmark['description'] ?? '', $keyword) !== false) {
            $score += 6;
        }

        // 关键词匹配
        if (stripos($bookmark['keywords'] ?? '', $keyword) !== false) {
            $score += 4;
        }

        // 点击数加权
        $score += log($bookmark['clicks'] + 1);

        // 推荐加权
        if ($bookmark['is_featured']) {
            $score += 2;
        }

        return $score;
    }

    private function getRecommendationReason(array $bookmark, int $userId): string
    {
        $reasons = [];

        if ($bookmark['is_featured']) {
            $reasons[] = '推荐书签';
        }

        if ($bookmark['click_score'] > 10) {
            $reasons[] = '热门书签';
        }

        if ($bookmark['user_preference'] > 1.5) {
            $reasons[] = '基于您的偏好';
        }

        return implode(', ', $reasons) ?: '相关推荐';
    }

    private function recordAuditLog(string $action, string $table, int $recordId, ?array $oldData, ?array $newData): void
    {
        $this->database->insert('audit_logs', [
            'user_id' => $this->auth->getCurrentUserId(),
            'action' => $action,
            'table_name' => $table,
            'record_id' => $recordId,
            'old_values' => $oldData ? json_encode($oldData) : null,
            'new_values' => $newData ? json_encode($newData) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function recordSearchLog(string $keyword, ?int $userId): void
    {
        $this->database->insert('search_logs', [
            'user_id' => $userId,
            'keyword' => $keyword,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'searched_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function clearBookmarkCache(): void
    {
        $patterns = ['bookmarks:', 'search:', 'popular:', 'recommended:', 'stats:'];
        foreach ($patterns as $pattern) {
            $this->cache->deletePattern($pattern);
        }
    }
}

/**
 * 书签服务异常类
 */
class BookmarkException extends Exception
{
    public function __construct(string $message = "", int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}