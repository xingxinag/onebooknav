<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Services\BookmarkService;
use App\Services\AuthService;
use App\Services\SecurityService;
use Exception;

/**
 * API 控制器
 *
 * 处理所有 AJAX 请求和 API 接口
 */
class ApiController extends Controller
{
    private BookmarkService $bookmarkService;
    private AuthService $authService;
    private SecurityService $securityService;

    public function __construct()
    {
        parent::__construct();
        $this->bookmarkService = BookmarkService::getInstance();
        $this->authService = AuthService::getInstance();
        $this->securityService = SecurityService::getInstance();

        // API 请求验证
        $this->validateApiRequest();
    }

    /**
     * 验证 API 请求
     */
    private function validateApiRequest(): void
    {
        // 检查 CSRF 令牌
        if (in_array($this->request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $token = $this->request->getHeader('X-CSRF-Token') ?: $this->request->getPost('_token');
            $action = $this->request->getUri();

            if (!$this->securityService->validateCsrfToken($token, $action)) {
                $this->jsonError('无效的请求令牌', 403);
                exit;
            }
        }

        // 限流检查
        $clientIP = $this->securityService->getClientIP();
        $rateKey = "api_request:{$clientIP}";

        if (!$this->securityService->rateLimit($rateKey, 100, 60)) { // 每分钟最多100次请求
            $this->jsonError('请求过于频繁，请稍后再试', 429);
            exit;
        }
    }

    /**
     * 搜索书签
     */
    public function searchBookmarks()
    {
        try {
            $keyword = trim($this->request->getPost('keyword', ''));
            $categoryId = $this->request->getPost('category_id');
            $useAI = $this->request->getPost('use_ai', false);
            $page = (int)$this->request->getPost('page', 1);
            $perPage = (int)$this->request->getPost('per_page', 10);

            if (empty($keyword) || strlen($keyword) < 2) {
                return $this->jsonError('搜索关键词至少需要2个字符');
            }

            $options = [
                'page' => $page,
                'per_page' => $perPage,
                'category_id' => $categoryId,
                'use_ai' => $useAI
            ];

            $result = $this->bookmarkService->searchBookmarks($keyword, $options);

            return $this->jsonSuccess($result);

        } catch (Exception $e) {
            error_log("Search API error: " . $e->getMessage());
            return $this->jsonError('搜索失败');
        }
    }

    /**
     * 创建书签
     */
    public function createBookmark()
    {
        try {
            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            // 获取和验证数据
            $data = $this->validateBookmarkData();

            // 创建书签
            $bookmark = $this->bookmarkService->createBookmark($data);

            return $this->jsonSuccess($bookmark, '书签添加成功');

        } catch (Exception $e) {
            error_log("Create bookmark API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 更新书签
     */
    public function updateBookmark()
    {
        try {
            $id = (int)$this->request->getParam('id');

            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            // 获取和验证数据
            $data = $this->validateBookmarkData(false);

            // 更新书签
            $bookmark = $this->bookmarkService->updateBookmark($id, $data);

            return $this->jsonSuccess($bookmark, '书签更新成功');

        } catch (Exception $e) {
            error_log("Update bookmark API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 删除书签
     */
    public function deleteBookmark()
    {
        try {
            $id = (int)$this->request->getParam('id');

            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            // 删除书签
            $result = $this->bookmarkService->deleteBookmark($id);

            return $this->jsonSuccess(['deleted' => $result], '书签删除成功');

        } catch (Exception $e) {
            error_log("Delete bookmark API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 获取书签详情
     */
    public function getBookmark()
    {
        try {
            $id = (int)$this->request->getParam('id');

            $bookmark = $this->bookmarkService->getBookmark($id);

            return $this->jsonSuccess($bookmark);

        } catch (Exception $e) {
            error_log("Get bookmark API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 批量操作书签
     */
    public function batchBookmarks()
    {
        try {
            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            $action = $this->request->getPost('action');
            $bookmarkIds = $this->request->getPost('bookmark_ids', []);
            $data = $this->request->getPost('data', []);

            if (empty($bookmarkIds) || !is_array($bookmarkIds)) {
                return $this->jsonError('请选择要操作的书签');
            }

            $result = null;

            switch ($action) {
                case 'update':
                    $result = $this->bookmarkService->batchUpdateBookmarks($bookmarkIds, $data);
                    break;

                case 'delete':
                    $result = $this->bookmarkService->batchDeleteBookmarks($bookmarkIds);
                    break;

                default:
                    return $this->jsonError('不支持的操作');
            }

            return $this->jsonSuccess($result, '批量操作完成');

        } catch (Exception $e) {
            error_log("Batch bookmarks API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 收藏/取消收藏书签
     */
    public function toggleFavorite()
    {
        try {
            $bookmarkId = (int)$this->request->getPost('bookmark_id');

            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            $isFavorited = $this->bookmarkService->toggleFavorite($bookmarkId);

            $message = $isFavorited ? '已添加到收藏' : '已从收藏中移除';

            return $this->jsonSuccess([
                'is_favorited' => $isFavorited
            ], $message);

        } catch (Exception $e) {
            error_log("Toggle favorite API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 记录点击
     */
    public function recordClick()
    {
        try {
            $bookmarkId = (int)$this->request->getPost('bookmark_id');
            $referer = $this->request->getPost('referer');

            $this->bookmarkService->recordClick($bookmarkId, $referer);

            return $this->jsonSuccess(['recorded' => true]);

        } catch (Exception $e) {
            error_log("Record click API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 更新书签排序
     */
    public function updateSort()
    {
        try {
            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            $categoryId = (int)$this->request->getPost('category_id');
            $sortData = $this->request->getPost('sort_data', []);

            if (empty($sortData) || !is_array($sortData)) {
                return $this->jsonError('排序数据不能为空');
            }

            $result = $this->bookmarkService->updateSortOrder($categoryId, $sortData);

            return $this->jsonSuccess(['updated' => $result], '排序更新成功');

        } catch (Exception $e) {
            error_log("Update sort API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 获取网站信息
     */
    public function fetchUrlInfo()
    {
        try {
            $url = $this->request->getPost('url');

            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return $this->jsonError('无效的URL');
            }

            // 限流：每个IP每分钟最多获取10次网站信息
            $clientIP = $this->securityService->getClientIP();
            $rateKey = "fetch_url:{$clientIP}";

            if (!$this->securityService->rateLimit($rateKey, 10, 60)) {
                return $this->jsonError('请求过于频繁');
            }

            // 获取网站信息
            $info = $this->fetchWebsiteInfo($url);

            return $this->jsonSuccess($info);

        } catch (Exception $e) {
            error_log("Fetch URL info API error: " . $e->getMessage());
            return $this->jsonError('获取网站信息失败');
        }
    }

    /**
     * 检查死链
     */
    public function checkDeadLinks()
    {
        try {
            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            $options = [
                'limit' => (int)$this->request->getPost('limit', 50),
                'force' => $this->request->getPost('force', false),
                'bookmark_ids' => $this->request->getPost('bookmark_ids', [])
            ];

            $result = $this->bookmarkService->checkDeadLinks($options);

            return $this->jsonSuccess($result, '死链检查完成');

        } catch (Exception $e) {
            error_log("Check dead links API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 导入书签
     */
    public function importBookmarks()
    {
        try {
            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            $importType = $this->request->getPost('import_type', 'file');
            $categoryId = $this->request->getPost('category_id');
            $overwrite = $this->request->getPost('overwrite', false);

            $result = null;

            if ($importType === 'file') {
                $file = $this->request->getFile('file');
                if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                    return $this->jsonError('请选择要导入的文件');
                }

                // 文件安全检查
                $validation = $this->securityService->validateFileUpload($file);
                if (!empty($validation)) {
                    return $this->jsonError(implode('; ', $validation));
                }

                $result = $this->importFromFile($file, $categoryId, $overwrite);

            } elseif ($importType === 'url') {
                $url = $this->request->getPost('url');
                if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    return $this->jsonError('请输入有效的网络地址');
                }

                $result = $this->importFromUrl($url, $categoryId, $overwrite);

            } else {
                return $this->jsonError('不支持的导入类型');
            }

            return $this->jsonSuccess($result, '导入完成');

        } catch (Exception $e) {
            error_log("Import bookmarks API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 导出书签
     */
    public function exportBookmarks()
    {
        try {
            // 权限检查
            if (!$this->authService->check()) {
                return $this->jsonError('请先登录', 401);
            }

            $format = $this->request->getQuery('format', 'html');
            $categoryId = $this->request->getQuery('category_id');
            $includePrivate = $this->request->getQuery('include_private', false);

            // 获取用户书签
            $user = $this->authService->user();
            $options = [
                'user_id' => $user['id'],
                'category_id' => $categoryId,
                'per_page' => 10000 // 导出所有书签
            ];

            if (!$includePrivate) {
                $options['is_private'] = false;
            }

            $result = $this->bookmarkService->getBookmarks($options);
            $bookmarks = $result['data'];

            $filename = 'bookmarks_' . date('Y-m-d_H-i-s');
            $content = '';

            switch ($format) {
                case 'html':
                    $content = $this->exportToHtml($bookmarks);
                    $filename .= '.html';
                    break;

                case 'json':
                    $content = $this->exportToJson($bookmarks);
                    $filename .= '.json';
                    break;

                case 'csv':
                    $content = $this->exportToCsv($bookmarks);
                    $filename .= '.csv';
                    break;

                default:
                    return $this->jsonError('不支持的导出格式');
            }

            // 设置下载头
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));

            echo $content;
            exit;

        } catch (Exception $e) {
            error_log("Export bookmarks API error: " . $e->getMessage());
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 验证书签数据
     */
    private function validateBookmarkData(bool $required = true): array
    {
        $rules = [
            'title' => [
                'required' => $required,
                'type' => 'string',
                'max_length' => 200
            ],
            'url' => [
                'required' => $required,
                'type' => 'url'
            ],
            'description' => [
                'type' => 'string',
                'max_length' => 1000
            ],
            'category_id' => [
                'required' => $required,
                'type' => 'int'
            ],
            'tags' => [
                'type' => 'string'
            ],
            'is_featured' => [
                'type' => 'bool'
            ],
            'is_private' => [
                'type' => 'bool'
            ]
        ];

        $data = [];
        foreach ($rules as $field => $rule) {
            $value = $this->request->getPost($field);

            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        // 验证数据
        $errors = $this->securityService->validateInput($data, $rules);
        if (!empty($errors)) {
            $errorMessages = [];
            foreach ($errors as $field => $fieldErrors) {
                $errorMessages = array_merge($errorMessages, $fieldErrors);
            }
            throw new Exception(implode('; ', $errorMessages));
        }

        // 清理数据
        return $this->securityService->sanitizeArray($data);
    }

    /**
     * 获取网站信息
     */
    private function fetchWebsiteInfo(string $url): array
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'OneBookNav/1.0',
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$html) {
                throw new Exception('无法访问该网站');
            }

            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new \DOMXPath($doc);

            $info = [
                'title' => '',
                'description' => '',
                'favicon' => ''
            ];

            // 提取标题
            $titleNodes = $xpath->query('//title');
            if ($titleNodes->length > 0) {
                $info['title'] = trim($titleNodes->item(0)->textContent);
            }

            // 提取描述
            $metaDesc = $xpath->query('//meta[@name="description"]/@content');
            if ($metaDesc->length > 0) {
                $info['description'] = trim($metaDesc->item(0)->textContent);
            }

            // 提取favicon
            $info['favicon'] = $this->extractFavicon($url, $xpath);

            return $info;

        } catch (Exception $e) {
            throw new Exception('获取网站信息失败: ' . $e->getMessage());
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

        // 使用默认favicon路径
        return $baseUrl . '/favicon.ico';
    }

    /**
     * 从文件导入书签
     */
    private function importFromFile(array $file, ?int $categoryId, bool $overwrite): array
    {
        $content = file_get_contents($file['tmp_name']);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        switch ($extension) {
            case 'html':
                return $this->importFromHtml($content, $categoryId, $overwrite);
            case 'json':
                return $this->importFromJson($content, $categoryId, $overwrite);
            case 'csv':
                return $this->importFromCsv($content, $categoryId, $overwrite);
            default:
                throw new Exception('不支持的文件格式');
        }
    }

    /**
     * 从HTML导入书签
     */
    private function importFromHtml(string $content, ?int $categoryId, bool $overwrite): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($content);
        $xpath = new \DOMXPath($doc);

        $links = $xpath->query('//a[@href]');
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($links as $link) {
            $url = $link->getAttribute('href');
            $title = trim($link->textContent);

            if (empty($url) || empty($title)) {
                continue;
            }

            try {
                $data = [
                    'title' => $title,
                    'url' => $url,
                    'category_id' => $categoryId
                ];

                // 检查是否已存在
                if (!$overwrite) {
                    $existing = $this->database->query(
                        "SELECT id FROM websites WHERE url = ?",
                        [$url]
                    )->fetch();

                    if ($existing) {
                        $skipped++;
                        continue;
                    }
                }

                $this->bookmarkService->createBookmark($data);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "导入 {$title} 失败: " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * 从JSON导入书签
     */
    private function importFromJson(string $content, ?int $categoryId, bool $overwrite): array
    {
        $data = json_decode($content, true);
        if (!$data) {
            throw new Exception('无效的JSON格式');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($data as $item) {
            if (!isset($item['url']) || !isset($item['title'])) {
                continue;
            }

            try {
                $bookmarkData = [
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'description' => $item['description'] ?? '',
                    'category_id' => $categoryId
                ];

                // 检查是否已存在
                if (!$overwrite) {
                    $existing = $this->database->query(
                        "SELECT id FROM websites WHERE url = ?",
                        [$item['url']]
                    )->fetch();

                    if ($existing) {
                        $skipped++;
                        continue;
                    }
                }

                $this->bookmarkService->createBookmark($bookmarkData);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "导入 {$item['title']} 失败: " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * 导出为HTML格式
     */
    private function exportToHtml(array $bookmarks): string
    {
        $html = '<!DOCTYPE NETSCAPE-Bookmark-file-1>
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>Bookmarks</TITLE>
<H1>Bookmarks</H1>
<DL><p>';

        $currentCategory = null;

        foreach ($bookmarks as $bookmark) {
            if ($bookmark['category_name'] !== $currentCategory) {
                if ($currentCategory !== null) {
                    $html .= '</DL><p>';
                }
                $html .= '<DT><H3>' . htmlspecialchars($bookmark['category_name']) . '</H3>';
                $html .= '<DL><p>';
                $currentCategory = $bookmark['category_name'];
            }

            $html .= '<DT><A HREF="' . htmlspecialchars($bookmark['url']) . '"';
            if (!empty($bookmark['created_at'])) {
                $html .= ' ADD_DATE="' . strtotime($bookmark['created_at']) . '"';
            }
            $html .= '>' . htmlspecialchars($bookmark['title']) . '</A>';

            if (!empty($bookmark['description'])) {
                $html .= '<DD>' . htmlspecialchars($bookmark['description']);
            }
        }

        if ($currentCategory !== null) {
            $html .= '</DL><p>';
        }

        $html .= '</DL><p>';

        return $html;
    }

    /**
     * 导出为JSON格式
     */
    private function exportToJson(array $bookmarks): string
    {
        $data = [];

        foreach ($bookmarks as $bookmark) {
            $data[] = [
                'title' => $bookmark['title'],
                'url' => $bookmark['url'],
                'description' => $bookmark['description'] ?? '',
                'category' => $bookmark['category_name'],
                'tags' => array_column($bookmark['tags'] ?? [], 'name'),
                'created_at' => $bookmark['created_at'],
                'clicks' => $bookmark['clicks']
            ];
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 导出为CSV格式
     */
    private function exportToCsv(array $bookmarks): string
    {
        $csv = "标题,网址,描述,分类,创建时间,点击次数\n";

        foreach ($bookmarks as $bookmark) {
            $row = [
                $bookmark['title'],
                $bookmark['url'],
                $bookmark['description'] ?? '',
                $bookmark['category_name'],
                $bookmark['created_at'],
                $bookmark['clicks']
            ];

            $csv .= '"' . implode('","', array_map('str_replace', array_fill(0, count($row), '"'), array_fill(0, count($row), '""'), $row)) . "\"\n";
        }

        return $csv;
    }
}