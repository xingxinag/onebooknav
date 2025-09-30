<?php
/**
 * OneBookNav - 网站/书签模型
 */

require_once __DIR__ . '/BaseModel.php';

class WebsiteModel extends BaseModel
{
    protected $table = 'websites';
    protected $fillable = [
        'title', 'url', 'description', 'category_id', 'user_id', 'icon', 'favicon_url',
        'sort_order', 'clicks', 'weight', 'status', 'is_private', 'is_featured',
        'last_checked_at', 'check_status', 'response_time', 'http_status', 'properties'
    ];
    protected $casts = [
        'category_id' => 'integer',
        'user_id' => 'integer',
        'sort_order' => 'integer',
        'clicks' => 'integer',
        'weight' => 'integer',
        'is_private' => 'boolean',
        'is_featured' => 'boolean',
        'response_time' => 'integer',
        'http_status' => 'integer',
        'properties' => 'json',
        'last_checked_at' => 'datetime'
    ];

    /**
     * 根据分类获取网站列表
     */
    public function getByCategory($categoryId, $userId = null, $page = 1, $perPage = 20)
    {
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT w.*, c.name as category_name
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            WHERE w.category_id = ? AND w.status = 'active'
        ";
        $params = [$categoryId];

        // 如果指定了用户，只显示公开的或用户自己的网站
        if ($userId !== null) {
            $sql .= " AND (w.is_private = 0 OR w.user_id = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND w.is_private = 0";
        }

        // 计算总数
        $countStmt = $this->db->prepare(str_replace('SELECT w.*, c.name as category_name', 'SELECT COUNT(*)', $sql));
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // 获取数据
        $sql .= " ORDER BY w.sort_order ASC, w.weight DESC, w.clicks DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map([$this, 'castAttributes'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * 获取推荐网站
     */
    public function getFeatured($limit = 10, $userId = null)
    {
        $sql = "
            SELECT w.*, c.name as category_name
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            WHERE w.is_featured = 1 AND w.status = 'active'
        ";
        $params = [];

        if ($userId !== null) {
            $sql .= " AND (w.is_private = 0 OR w.user_id = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND w.is_private = 0";
        }

        $sql .= " ORDER BY w.weight DESC, w.clicks DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castAttributes'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 获取热门网站
     */
    public function getPopular($limit = 10, $userId = null, $days = 30)
    {
        $sql = "
            SELECT w.*, c.name as category_name, COUNT(cl.id) as recent_clicks
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            LEFT JOIN click_logs cl ON w.id = cl.website_id AND cl.clicked_at >= DATE('now', '-{$days} days')
            WHERE w.status = 'active'
        ";
        $params = [];

        if ($userId !== null) {
            $sql .= " AND (w.is_private = 0 OR w.user_id = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND w.is_private = 0";
        }

        $sql .= " GROUP BY w.id ORDER BY recent_clicks DESC, w.clicks DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castAttributes'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 搜索网站
     */
    public function search($keyword, $userId = null, $page = 1, $perPage = 20)
    {
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT w.*, c.name as category_name,
                   (CASE
                       WHEN w.title LIKE ? THEN 3
                       WHEN w.description LIKE ? THEN 2
                       WHEN w.url LIKE ? THEN 1
                       ELSE 0
                   END) as relevance
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            WHERE (w.title LIKE ? OR w.description LIKE ? OR w.url LIKE ?)
              AND w.status = 'active'
        ";

        $likeKeyword = "%{$keyword}%";
        $params = [$likeKeyword, $likeKeyword, $likeKeyword, $likeKeyword, $likeKeyword, $likeKeyword];

        if ($userId !== null) {
            $sql .= " AND (w.is_private = 0 OR w.user_id = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND w.is_private = 0";
        }

        // 计算总数
        $countSql = str_replace('SELECT w.*, c.name as category_name, (CASE WHEN w.title LIKE ? THEN 3 WHEN w.description LIKE ? THEN 2 WHEN w.url LIKE ? THEN 1 ELSE 0 END) as relevance', 'SELECT COUNT(*)', $sql);
        $countParams = array_slice($params, 3); // 移除前三个相关性参数
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = $countStmt->fetchColumn();

        // 获取数据
        $sql .= " ORDER BY relevance DESC, w.clicks DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 记录搜索日志
        $this->logSearch($keyword, $total, $userId);

        return [
            'data' => array_map([$this, 'castAttributes'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'keyword' => $keyword
        ];
    }

    /**
     * 增加点击量
     */
    public function incrementClicks($websiteId, $userId = null)
    {
        $this->beginTransaction();

        try {
            // 更新网站点击量
            $stmt = $this->db->prepare("UPDATE {$this->table} SET clicks = clicks + 1 WHERE id = ?");
            $stmt->execute([$websiteId]);

            // 记录点击日志
            $this->logClick($websiteId, $userId);

            $this->commit();
            return true;

        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 记录点击日志
     */
    private function logClick($websiteId, $userId = null)
    {
        $stmt = $this->db->prepare("
            INSERT INTO click_logs (website_id, user_id, ip_address, user_agent, referer)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $websiteId,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['HTTP_REFERER'] ?? null
        ]);
    }

    /**
     * 记录搜索日志
     */
    private function logSearch($keyword, $resultsCount, $userId = null)
    {
        $stmt = $this->db->prepare("
            INSERT INTO search_logs (user_id, keyword, results_count, ip_address)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $keyword,
            $resultsCount,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    /**
     * 获取用户的网站
     */
    public function getUserWebsites($userId, $page = 1, $perPage = 20)
    {
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT w.*, c.name as category_name
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            WHERE w.user_id = ?
        ";

        // 计算总数
        $countStmt = $this->db->prepare(str_replace('SELECT w.*, c.name as category_name', 'SELECT COUNT(*)', $sql));
        $countStmt->execute([$userId]);
        $total = $countStmt->fetchColumn();

        // 获取数据
        $sql .= " ORDER BY w.updated_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $perPage, $offset]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map([$this, 'castAttributes'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * 检查URL是否已存在
     */
    public function urlExists($url, $excludeId = null)
    {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE url = ?";
        $params = [$url];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * 获取下一个排序顺序
     */
    public function getNextSortOrder($categoryId)
    {
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM {$this->table} WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        $maxOrder = $stmt->fetchColumn();

        return ($maxOrder ?? 0) + 1;
    }

    /**
     * 更新排序顺序
     */
    public function updateSortOrder($websiteId, $newOrder)
    {
        $website = $this->find($websiteId);
        if (!$website) return false;

        $oldOrder = $website['sort_order'];
        $categoryId = $website['category_id'];

        $this->beginTransaction();

        try {
            if ($newOrder > $oldOrder) {
                $sql = "UPDATE {$this->table} SET sort_order = sort_order - 1
                        WHERE category_id = ? AND sort_order > ? AND sort_order <= ?";
                $params = [$categoryId, $oldOrder, $newOrder];
            } else {
                $sql = "UPDATE {$this->table} SET sort_order = sort_order + 1
                        WHERE category_id = ? AND sort_order >= ? AND sort_order < ?";
                $params = [$categoryId, $newOrder, $oldOrder];
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $this->update($websiteId, ['sort_order' => $newOrder]);

            $this->commit();
            return true;

        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 批量更新状态
     */
    public function updateStatus($websiteIds, $status)
    {
        if (empty($websiteIds)) return false;

        $placeholders = str_repeat('?,', count($websiteIds) - 1) . '?';
        $sql = "UPDATE {$this->table} SET status = ? WHERE id IN ({$placeholders})";

        $params = array_merge([$status], $websiteIds);
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 获取死链检查结果
     */
    public function getDeadLinks($limit = 50)
    {
        $sql = "
            SELECT w.*, c.name as category_name, dc.check_time, dc.error_message
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            LEFT JOIN deadlink_checks dc ON w.id = dc.website_id
            WHERE w.check_status IN ('error', 'timeout', 'not_found')
            ORDER BY dc.check_time DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        return array_map([$this, 'castAttributes'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 获取网站统计
     */
    public function getWebsiteStats($websiteId)
    {
        $sql = "
            SELECT
                w.*,
                COUNT(cl.id) as total_clicks,
                COUNT(DISTINCT cl.user_id) as unique_visitors,
                COUNT(DISTINCT DATE(cl.clicked_at)) as active_days,
                MAX(cl.clicked_at) as last_clicked,
                AVG(w.response_time) as avg_response_time
            FROM {$this->table} w
            LEFT JOIN click_logs cl ON w.id = cl.website_id
            WHERE w.id = ?
            GROUP BY w.id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$websiteId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $this->castAttributes($result) : null;
    }

    /**
     * 导出网站数据
     */
    public function exportWebsites($userId = null, $categoryId = null)
    {
        $sql = "
            SELECT w.*, c.name as category_name
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            WHERE w.status = 'active'
        ";
        $params = [];

        if ($userId !== null) {
            $sql .= " AND (w.is_private = 0 OR w.user_id = ?)";
            $params[] = $userId;
        }

        if ($categoryId !== null) {
            $sql .= " AND w.category_id = ?";
            $params[] = $categoryId;
        }

        $sql .= " ORDER BY c.name ASC, w.sort_order ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castAttributes'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 自动获取网站图标
     */
    public function fetchFavicon($url)
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['host'])) {
            return null;
        }

        $domain = $parsedUrl['host'];
        $faviconUrls = [
            "https://www.google.com/s2/favicons?domain={$domain}",
            "https://{$domain}/favicon.ico",
            "https://{$domain}/favicon.png",
            "http://{$domain}/favicon.ico"
        ];

        foreach ($faviconUrls as $faviconUrl) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'user_agent' => 'OneBookNav/1.0'
                ]
            ]);

            $headers = @get_headers($faviconUrl, 1, $context);
            if ($headers && strpos($headers[0], '200') !== false) {
                return $faviconUrl;
            }
        }

        return null;
    }

    /**
     * 获取最新添加的网站
     */
    public function getLatest($limit = 10, $userId = null)
    {
        $sql = "
            SELECT w.*, c.name as category_name
            FROM {$this->table} w
            LEFT JOIN categories c ON w.category_id = c.id
            WHERE w.status = 'active'
        ";
        $params = [];

        if ($userId !== null) {
            $sql .= " AND (w.is_private = 0 OR w.user_id = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND w.is_private = 0";
        }

        $sql .= " ORDER BY w.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castAttributes'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}