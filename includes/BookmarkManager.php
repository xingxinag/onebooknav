<?php
/**
 * Enhanced Bookmark Management System
 * Merged features from BookNav and OneNav
 * Handles categories and bookmarks with hierarchical structure, AI search, and advanced features
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AISearch.php';
require_once __DIR__ . '/DragSortManager.php';
require_once __DIR__ . '/DeadLinkChecker.php';

class BookmarkManager {
    private $db;
    private $currentUserId;
    private $aiSearch;
    private $dragSortManager;
    private $deadLinkChecker;

    public function __construct($userId = null) {
        $this->db = Database::getInstance();
        $this->currentUserId = $userId;
        $this->aiSearch = new AISearch();
        $this->dragSortManager = new DragSortManager($userId);
        $this->deadLinkChecker = new DeadLinkChecker();
    }

    // Category Management

    /**
     * Get all categories for a user
     */
    public function getCategories($userId = null, $includePrivate = true) {
        $userId = $userId ?? $this->currentUserId;

        $sql = "SELECT * FROM categories WHERE user_id = ?";
        $params = [$userId];

        if (!$includePrivate) {
            $sql .= " AND is_private = 0";
        }

        $sql .= " ORDER BY parent_id, weight, name";

        $categories = $this->db->fetchAll($sql, $params);
        return $this->buildCategoryTree($categories);
    }

    /**
     * Get public categories for guest view
     */
    public function getPublicCategories() {
        $sql = "SELECT c.*, u.username FROM categories c
                JOIN users u ON c.user_id = u.id
                WHERE c.is_private = 0
                ORDER BY c.parent_id, c.weight, c.name";

        $categories = $this->db->fetchAll($sql);
        return $this->buildCategoryTree($categories);
    }

    /**
     * Create a new category
     */
    public function createCategory($name, $parentId = null, $icon = null, $color = null, $isPrivate = false) {
        if (empty($name)) {
            throw new Exception('Category name is required');
        }

        // Get next weight
        $weight = $this->getNextCategoryWeight($parentId);

        $categoryId = $this->db->insert('categories', [
            'name' => $name,
            'parent_id' => $parentId,
            'user_id' => $this->currentUserId,
            'icon' => $icon,
            'color' => $color,
            'weight' => $weight,
            'is_private' => $isPrivate ? 1 : 0
        ]);

        return $this->getCategory($categoryId);
    }

    /**
     * Update category
     */
    public function updateCategory($categoryId, $data) {
        $category = $this->getCategory($categoryId);

        if (!$category || $category['user_id'] != $this->currentUserId) {
            throw new Exception('Category not found or access denied');
        }

        $allowedFields = ['name', 'parent_id', 'icon', 'color', 'weight', 'is_private'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return false;
        }

        // Prevent circular references in parent_id
        if (isset($updateData['parent_id']) && $updateData['parent_id']) {
            if ($this->wouldCreateCircular($categoryId, $updateData['parent_id'])) {
                throw new Exception('Invalid parent category - would create circular reference');
            }
        }

        return $this->db->update('categories', $updateData, 'id = ?', [$categoryId]);
    }

    /**
     * Delete category and move bookmarks to parent or uncategorized
     */
    public function deleteCategory($categoryId) {
        $category = $this->getCategory($categoryId);

        if (!$category || $category['user_id'] != $this->currentUserId) {
            throw new Exception('Category not found or access denied');
        }

        $this->db->beginTransaction();

        try {
            // Move child categories to parent
            $this->db->update('categories', [
                'parent_id' => $category['parent_id']
            ], 'parent_id = ?', [$categoryId]);

            // Get or create "Uncategorized" category
            $uncategorizedId = $this->getOrCreateUncategorizedCategory();

            // Move bookmarks to uncategorized
            $this->db->update('bookmarks', [
                'category_id' => $uncategorizedId
            ], 'category_id = ?', [$categoryId]);

            // Delete the category
            $this->db->delete('categories', 'id = ?', [$categoryId]);

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Reorder categories
     */
    public function reorderCategories($categoryIds) {
        if (!is_array($categoryIds)) {
            throw new Exception('Invalid category order data');
        }

        $this->db->beginTransaction();

        try {
            foreach ($categoryIds as $index => $categoryId) {
                $this->db->update('categories', [
                    'weight' => $index
                ], 'id = ? AND user_id = ?', [$categoryId, $this->currentUserId]);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // Bookmark Management

    /**
     * Get bookmarks by category
     */
    public function getBookmarksByCategory($categoryId, $includePrivate = true) {
        $sql = "SELECT * FROM bookmarks WHERE category_id = ?";
        $params = [$categoryId];

        if (!$includePrivate) {
            $sql .= " AND is_private = 0";
        }

        $sql .= " ORDER BY weight, title";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get all bookmarks for a user
     */
    public function getUserBookmarks($userId = null, $includePrivate = true) {
        $userId = $userId ?? $this->currentUserId;

        $sql = "SELECT b.*, c.name as category_name FROM bookmarks b
                JOIN categories c ON b.category_id = c.id
                WHERE b.user_id = ?";
        $params = [$userId];

        if (!$includePrivate) {
            $sql .= " AND b.is_private = 0";
        }

        $sql .= " ORDER BY c.weight, c.name, b.weight, b.title";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * AI-powered search bookmarks (enhanced from OneNav)
     */
    public function searchBookmarks($query, $userId = null, $includePrivate = true) {
        $userId = $userId ?? $this->currentUserId;
        return $this->aiSearch->searchBookmarks($query, $userId);
    }

    /**
     * Get search suggestions
     */
    public function getSearchSuggestions($userId = null) {
        $userId = $userId ?? $this->currentUserId;
        return $this->aiSearch->getSearchSuggestions($userId);
    }

    /**
     * Create a new bookmark (enhanced with backup URL support from OneNav)
     */
    public function createBookmark($title, $url, $categoryId, $description = null, $keywords = null, $isPrivate = false, $backupUrl = null, $tags = null) {
        if (empty($title) || empty($url)) {
            throw new Exception('Title and URL are required');
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL format');
        }

        // Validate backup URL if provided
        if ($backupUrl && !filter_var($backupUrl, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid backup URL format');
        }

        // Check if category exists and belongs to user
        $category = $this->getCategory($categoryId);
        if (!$category || $category['user_id'] != $this->currentUserId) {
            throw new Exception('Invalid category');
        }

        // Check for duplicate URL in user's bookmarks
        if ($this->bookmarkExists($url)) {
            throw new Exception('Bookmark with this URL already exists');
        }

        // Get next sort order using drag sort manager
        $sortOrder = $this->dragSortManager->getNextBookmarkSortOrder($categoryId);

        // Try to fetch icon and website info
        $iconUrl = $this->fetchFavicon($url);
        $websiteInfo = $this->fetchWebsiteInfo($url);

        $bookmarkId = $this->db->insert('bookmarks', [
            'title' => $title,
            'url' => $url,
            'backup_url' => $backupUrl,
            'description' => $description ?: $websiteInfo['description'],
            'keywords' => $keywords,
            'tags' => $tags,
            'icon_url' => $iconUrl,
            'category_id' => $categoryId,
            'user_id' => $this->currentUserId,
            'sort_order' => $sortOrder,
            'weight' => $sortOrder, // For backward compatibility
            'is_private' => $isPrivate ? 1 : 0,
            'click_count' => 0,
            'is_working' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $this->getBookmark($bookmarkId);
    }

    /**
     * Update bookmark
     */
    public function updateBookmark($bookmarkId, $data) {
        $bookmark = $this->getBookmark($bookmarkId);

        if (!$bookmark || $bookmark['user_id'] != $this->currentUserId) {
            throw new Exception('Bookmark not found or access denied');
        }

        $allowedFields = ['title', 'url', 'description', 'keywords', 'category_id', 'weight', 'is_private'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return false;
        }

        // Validate URL if being updated
        if (isset($updateData['url']) && !filter_var($updateData['url'], FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL format');
        }

        // Validate category if being updated
        if (isset($updateData['category_id'])) {
            $category = $this->getCategory($updateData['category_id']);
            if (!$category || $category['user_id'] != $this->currentUserId) {
                throw new Exception('Invalid category');
            }
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update('bookmarks', $updateData, 'id = ?', [$bookmarkId]);
    }

    /**
     * Delete bookmark
     */
    public function deleteBookmark($bookmarkId) {
        $bookmark = $this->getBookmark($bookmarkId);

        if (!$bookmark || $bookmark['user_id'] != $this->currentUserId) {
            throw new Exception('Bookmark not found or access denied');
        }

        return $this->db->delete('bookmarks', 'id = ?', [$bookmarkId]);
    }

    /**
     * Increment bookmark click count
     */
    public function incrementClickCount($bookmarkId) {
        return $this->db->update('bookmarks', [
            'click_count' => 'click_count + 1'
        ], 'id = ?', [$bookmarkId]);
    }

    /**
     * Reorder bookmarks within a category
     */
    public function reorderBookmarks($bookmarkIds) {
        if (!is_array($bookmarkIds)) {
            throw new Exception('Invalid bookmark order data');
        }

        $this->db->beginTransaction();

        try {
            foreach ($bookmarkIds as $index => $bookmarkId) {
                $this->db->update('bookmarks', [
                    'weight' => $index
                ], 'id = ? AND user_id = ?', [$bookmarkId, $this->currentUserId]);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // Helper methods

    private function getCategory($categoryId) {
        return $this->db->fetchOne("SELECT * FROM categories WHERE id = ?", [$categoryId]);
    }

    private function getBookmark($bookmarkId) {
        return $this->db->fetchOne("SELECT * FROM bookmarks WHERE id = ?", [$bookmarkId]);
    }

    private function buildCategoryTree($categories) {
        $tree = [];
        $lookup = [];

        // First pass: create lookup array and initialize children
        foreach ($categories as $category) {
            $category['children'] = [];
            $lookup[$category['id']] = $category;
        }

        // Second pass: build tree structure
        foreach ($lookup as $id => $category) {
            if ($category['parent_id'] === null) {
                $tree[] = &$lookup[$id];
            } else {
                $lookup[$category['parent_id']]['children'][] = &$lookup[$id];
            }
        }

        return $tree;
    }

    private function getNextCategoryWeight($parentId) {
        $result = $this->db->fetchOne(
            "SELECT MAX(weight) as max_weight FROM categories WHERE parent_id = ? AND user_id = ?",
            [$parentId, $this->currentUserId]
        );
        return ($result['max_weight'] ?? 0) + 1;
    }

    private function getNextBookmarkWeight($categoryId) {
        $result = $this->db->fetchOne(
            "SELECT MAX(weight) as max_weight FROM bookmarks WHERE category_id = ? AND user_id = ?",
            [$categoryId, $this->currentUserId]
        );
        return ($result['max_weight'] ?? 0) + 1;
    }

    private function wouldCreateCircular($categoryId, $newParentId) {
        $currentId = $newParentId;

        while ($currentId !== null) {
            if ($currentId == $categoryId) {
                return true;
            }

            $parent = $this->db->fetchOne(
                "SELECT parent_id FROM categories WHERE id = ?",
                [$currentId]
            );

            $currentId = $parent ? $parent['parent_id'] : null;
        }

        return false;
    }

    private function getOrCreateUncategorizedCategory() {
        $uncategorized = $this->db->fetchOne(
            "SELECT id FROM categories WHERE name = 'Uncategorized' AND user_id = ? AND parent_id IS NULL",
            [$this->currentUserId]
        );

        if ($uncategorized) {
            return $uncategorized['id'];
        }

        // Create uncategorized category
        return $this->db->insert('categories', [
            'name' => 'Uncategorized',
            'user_id' => $this->currentUserId,
            'icon' => 'fas fa-folder',
            'weight' => 999999 // Put it at the end
        ]);
    }

    private function bookmarkExists($url) {
        $result = $this->db->fetchOne(
            "SELECT id FROM bookmarks WHERE url = ? AND user_id = ?",
            [$url, $this->currentUserId]
        );
        return $result !== false;
    }

    private function fetchFavicon($url) {
        try {
            $parsedUrl = parse_url($url);
            $faviconUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/favicon.ico';

            // Simple check if favicon exists
            $headers = @get_headers($faviconUrl);
            if ($headers && strpos($headers[0], '200') !== false) {
                return $faviconUrl;
            }
        } catch (Exception $e) {
            // Ignore errors
        }

        return null;
    }

    /**
     * Check bookmark links for dead links
     */
    public function checkBookmarkStatus($bookmarkId) {
        $bookmark = $this->getBookmark($bookmarkId);

        if (!$bookmark) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $bookmark['url']);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, LINK_CHECK_USER_AGENT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Update bookmark status
        $this->db->update('bookmarks', [
            'status_code' => $statusCode,
            'last_checked' => date('Y-m-d H:i:s')
        ], 'id = ?', [$bookmarkId]);

        return $statusCode;
    }

    /**
     * Get statistics for user's bookmarks
     */
    public function getStats($userId = null) {
        $userId = $userId ?? $this->currentUserId;

        $stats = [];

        // Total bookmarks
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM bookmarks WHERE user_id = ?",
            [$userId]
        );
        $stats['total_bookmarks'] = $result['total'];

        // Total categories
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM categories WHERE user_id = ?",
            [$userId]
        );
        $stats['total_categories'] = $result['total'];

        // Private bookmarks
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM bookmarks WHERE user_id = ? AND is_private = 1",
            [$userId]
        );
        $stats['private_bookmarks'] = $result['total'];

        // Dead links (status code >= 400)
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM bookmarks WHERE user_id = ? AND status_code >= 400",
            [$userId]
        );
        $stats['dead_links'] = $result['total'];

        return $stats;
    }

    // Enhanced methods from merged projects

    /**
     * Drag sort bookmark (from OneNav)
     */
    public function dragSortBookmark($bookmarkId, $newPosition, $categoryId = null) {
        return $this->dragSortManager->updateBookmarkOrder($bookmarkId, $newPosition, $categoryId);
    }

    /**
     * Drag sort category (from OneNav)
     */
    public function dragSortCategory($categoryId, $newPosition) {
        return $this->dragSortManager->updateCategoryOrder($categoryId, $newPosition);
    }

    /**
     * Check bookmark for dead links (from BookNav)
     */
    public function checkBookmarkDeadLink($bookmarkId) {
        return $this->deadLinkChecker->checkBookmark($bookmarkId);
    }

    /**
     * Check all user bookmarks for dead links
     */
    public function checkAllDeadLinks($callback = null) {
        return $this->deadLinkChecker->checkAllUserBookmarks($this->currentUserId, $callback);
    }

    /**
     * Get dead links report
     */
    public function getDeadLinksReport() {
        return $this->deadLinkChecker->getDeadLinksReport($this->currentUserId);
    }

    /**
     * Batch create bookmarks from import
     */
    public function batchCreateBookmarks($bookmarks) {
        $this->db->beginTransaction();
        $results = [];

        try {
            foreach ($bookmarks as $bookmark) {
                try {
                    $result = $this->createBookmark(
                        $bookmark['title'],
                        $bookmark['url'],
                        $bookmark['category_id'],
                        $bookmark['description'] ?? null,
                        $bookmark['keywords'] ?? null,
                        $bookmark['is_private'] ?? false,
                        $bookmark['backup_url'] ?? null,
                        $bookmark['tags'] ?? null
                    );
                    $results[] = ['success' => true, 'bookmark' => $result];
                } catch (Exception $e) {
                    $results[] = ['success' => false, 'error' => $e->getMessage(), 'data' => $bookmark];
                }
            }

            $this->db->commit();
            return $results;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Import bookmarks from OneNav format
     */
    public function importFromOneNav($data) {
        $imported = ['categories' => 0, 'bookmarks' => 0, 'errors' => []];

        try {
            // Import categories first
            if (isset($data['categories'])) {
                foreach ($data['categories'] as $categoryData) {
                    try {
                        $this->createCategory(
                            $categoryData['name'],
                            $categoryData['parent_id'] ?? null,
                            $categoryData['icon'] ?? 'fas fa-folder',
                            $categoryData['color'] ?? '#007bff',
                            $categoryData['is_private'] ?? false
                        );
                        $imported['categories']++;
                    } catch (Exception $e) {
                        $imported['errors'][] = "Category '{$categoryData['name']}': " . $e->getMessage();
                    }
                }
            }

            // Import bookmarks
            if (isset($data['bookmarks'])) {
                foreach ($data['bookmarks'] as $bookmarkData) {
                    try {
                        $this->createBookmark(
                            $bookmarkData['title'],
                            $bookmarkData['url'],
                            $bookmarkData['category_id'],
                            $bookmarkData['description'] ?? null,
                            $bookmarkData['keywords'] ?? null,
                            $bookmarkData['is_private'] ?? false,
                            $bookmarkData['backup_url'] ?? null,
                            $bookmarkData['tags'] ?? null
                        );
                        $imported['bookmarks']++;
                    } catch (Exception $e) {
                        $imported['errors'][] = "Bookmark '{$bookmarkData['title']}': " . $e->getMessage();
                    }
                }
            }

        } catch (Exception $e) {
            $imported['errors'][] = "Import error: " . $e->getMessage();
        }

        return $imported;
    }

    /**
     * Export bookmarks to OneNav format
     */
    public function exportToOneNav($userId = null) {
        $userId = $userId ?? $this->currentUserId;

        $categories = $this->getCategories($userId, true);
        $bookmarks = $this->getUserBookmarks($userId, true);

        return [
            'version' => '2.0',
            'export_date' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'categories' => $categories,
            'bookmarks' => $bookmarks
        ];
    }

    /**
     * Fetch website information for auto-fill
     */
    private function fetchWebsiteInfo($url) {
        $info = ['title' => '', 'description' => ''];

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'OneBookNav/1.0'
                ]
            ]);

            $html = @file_get_contents($url, false, $context);
            if ($html) {
                // Extract title
                if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                    $info['title'] = trim(html_entity_decode($matches[1]));
                }

                // Extract description
                if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
                    $info['description'] = trim(html_entity_decode($matches[1]));
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }

        return $info;
    }

    /**
     * Enhanced favicon fetching with multiple fallbacks
     */
    private function fetchFavicon($url) {
        try {
            $parsedUrl = parse_url($url);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

            // Try common favicon locations
            $faviconUrls = [
                $baseUrl . '/favicon.ico',
                $baseUrl . '/favicon.png',
                $baseUrl . '/apple-touch-icon.png',
                $baseUrl . '/android-chrome-192x192.png'
            ];

            foreach ($faviconUrls as $faviconUrl) {
                $headers = @get_headers($faviconUrl, 1);
                if ($headers && strpos($headers[0], '200') !== false) {
                    return $faviconUrl;
                }
            }

            // Try parsing HTML for favicon link
            $html = @file_get_contents($url);
            if ($html && preg_match('/<link[^>]*rel=["\'](?:icon|shortcut icon)["\'][^>]*href=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
                $faviconUrl = $matches[1];
                if (!parse_url($faviconUrl, PHP_URL_HOST)) {
                    $faviconUrl = $baseUrl . '/' . ltrim($faviconUrl, '/');
                }
                return $faviconUrl;
            }

        } catch (Exception $e) {
            // Ignore errors
        }

        return null;
    }

    /**
     * Get bookmark click statistics
     */
    public function getClickStats($userId = null, $period = '30days') {
        $userId = $userId ?? $this->currentUserId;

        $dateFilter = match($period) {
            '7days' => 'DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
            '30days' => 'DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
            '90days' => 'DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)',
            default => '1=1'
        };

        $sql = "SELECT b.title, b.url, b.click_count, c.name as category_name
                FROM bookmarks b
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE (b.user_id = :user_id OR b.is_private = 0)
                AND $dateFilter
                ORDER BY b.click_count DESC
                LIMIT 20";

        return $this->db->query($sql, ['user_id' => $userId]);
    }
}
?>