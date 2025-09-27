<?php
/**
 * Backup and Migration System
 * Supports importing from BookNav and OneNav, plus WebDAV backup functionality
 */

require_once __DIR__ . '/Database.php';

class BackupManager {
    private $db;
    private $backupPath;
    private $webdavConfig;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->backupPath = BACKUP_PATH;
        $this->webdavConfig = [
            'enabled' => WEBDAV_ENABLED,
            'url' => WEBDAV_URL,
            'username' => WEBDAV_USERNAME,
            'password' => WEBDAV_PASSWORD,
            'auto_backup' => WEBDAV_AUTO_BACKUP
        ];

        // Ensure backup directory exists
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * Create a full backup of the current database
     */
    public function createBackup($userId, $description = null) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "onebooknav_backup_{$timestamp}.json";
        $filepath = $this->backupPath . $filename;

        try {
            // Export all data
            $data = $this->exportAllData();

            // Add metadata
            $backup = [
                'metadata' => [
                    'version' => '1.0.0',
                    'type' => 'onebooknav_full',
                    'created_at' => date('Y-m-d H:i:s'),
                    'created_by' => $userId,
                    'description' => $description,
                    'total_users' => count($data['users']),
                    'total_categories' => count($data['categories']),
                    'total_bookmarks' => count($data['bookmarks'])
                ],
                'data' => $data
            ];

            // Write backup file
            $result = file_put_contents($filepath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if ($result === false) {
                throw new Exception('Failed to write backup file');
            }

            // Record backup in database
            $backupId = $this->db->insert('backups', [
                'filename' => $filename,
                'size' => filesize($filepath),
                'backup_type' => 'full',
                'created_by' => $userId
            ]);

            // Auto-upload to WebDAV if enabled
            if ($this->webdavConfig['enabled'] && $this->webdavConfig['auto_backup']) {
                $this->uploadToWebDAV($filename);
            }

            // Cleanup old backups
            $this->cleanupOldBackups();

            return [
                'id' => $backupId,
                'filename' => $filename,
                'size' => filesize($filepath),
                'path' => $filepath
            ];

        } catch (Exception $e) {
            // Clean up failed backup file
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            throw $e;
        }
    }

    /**
     * Restore from OneBookNav backup
     */
    public function restoreFromBackup($backupFile, $userId, $options = []) {
        if (!file_exists($backupFile)) {
            throw new Exception('Backup file not found');
        }

        $backupData = json_decode(file_get_contents($backupFile), true);

        if (!$backupData) {
            throw new Exception('Invalid backup file format');
        }

        // Determine backup type and restore accordingly
        $metadata = $backupData['metadata'] ?? [];
        $type = $metadata['type'] ?? 'unknown';

        switch ($type) {
            case 'onebooknav_full':
                return $this->restoreOnebooknavBackup($backupData, $userId, $options);
            case 'booknav_export':
                return $this->restoreBooknavBackup($backupData, $userId, $options);
            case 'onenav_export':
                return $this->restoreOnenavBackup($backupData, $userId, $options);
            default:
                // Try to auto-detect format
                return $this->autoDetectAndRestore($backupData, $userId, $options);
        }
    }

    /**
     * Import from BookNav database
     */
    public function importFromBookNav($booknavDbPath, $userId, $options = []) {
        if (!file_exists($booknavDbPath)) {
            throw new Exception('BookNav database file not found');
        }

        try {
            // Connect to BookNav SQLite database
            $booknavPdo = new PDO('sqlite:' . $booknavDbPath);
            $booknavPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->db->beginTransaction();

            $stats = ['categories' => 0, 'bookmarks' => 0, 'errors' => []];

            // Import categories
            $categories = $booknavPdo->query("SELECT * FROM category ORDER BY parent_id, id")->fetchAll(PDO::FETCH_ASSOC);
            $categoryMapping = [];

            foreach ($categories as $category) {
                try {
                    $newCategoryId = $this->db->insert('categories', [
                        'name' => $category['name'],
                        'parent_id' => $categoryMapping[$category['parent_id']] ?? null,
                        'user_id' => $userId,
                        'icon' => $category['icon'] ?? 'fas fa-folder',
                        'color' => $category['color'] ?? null,
                        'weight' => $category['weight'] ?? 0,
                        'is_private' => $category['is_private'] ?? 0
                    ]);

                    $categoryMapping[$category['id']] = $newCategoryId;
                    $stats['categories']++;

                } catch (Exception $e) {
                    $stats['errors'][] = "Category '{$category['name']}': " . $e->getMessage();
                }
            }

            // Import websites/bookmarks
            $websites = $booknavPdo->query("SELECT * FROM website ORDER BY category_id, weight")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($websites as $website) {
                try {
                    $categoryId = $categoryMapping[$website['category_id']] ?? null;

                    if (!$categoryId) {
                        // Create a default category if mapping not found
                        $categoryId = $this->getOrCreateDefaultCategory($userId);
                    }

                    $this->db->insert('bookmarks', [
                        'title' => $website['title'],
                        'url' => $website['url'],
                        'description' => $website['description'] ?? null,
                        'keywords' => $website['keywords'] ?? null,
                        'icon_url' => $website['icon_url'] ?? null,
                        'category_id' => $categoryId,
                        'user_id' => $userId,
                        'weight' => $website['weight'] ?? 0,
                        'is_private' => $website['is_private'] ?? 0,
                        'click_count' => $website['clicks'] ?? 0
                    ]);

                    $stats['bookmarks']++;

                } catch (Exception $e) {
                    $stats['errors'][] = "Bookmark '{$website['title']}': " . $e->getMessage();
                }
            }

            $this->db->commit();
            return $stats;

        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception('BookNav import failed: ' . $e->getMessage());
        }
    }

    /**
     * Import from OneNav database
     */
    public function importFromOneNav($onenavDbPath, $userId, $options = []) {
        if (!file_exists($onenavDbPath)) {
            throw new Exception('OneNav database file not found');
        }

        try {
            // Connect to OneNav SQLite database
            $onenavPdo = new PDO('sqlite:' . $onenavDbPath);
            $onenavPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->db->beginTransaction();

            $stats = ['categories' => 0, 'bookmarks' => 0, 'errors' => []];

            // Import categories
            $categories = $onenavPdo->query("SELECT * FROM on_categorys ORDER BY fid, weight")->fetchAll(PDO::FETCH_ASSOC);
            $categoryMapping = [];

            foreach ($categories as $category) {
                try {
                    $newCategoryId = $this->db->insert('categories', [
                        'name' => $category['name'],
                        'parent_id' => $categoryMapping[$category['fid']] ?? null,
                        'user_id' => $userId,
                        'icon' => $category['font_icon'] ?? 'fas fa-folder',
                        'weight' => $category['weight'] ?? 0,
                        'is_private' => $category['property'] == 'private' ? 1 : 0
                    ]);

                    $categoryMapping[$category['id']] = $newCategoryId;
                    $stats['categories']++;

                } catch (Exception $e) {
                    $stats['errors'][] = "Category '{$category['name']}': " . $e->getMessage();
                }
            }

            // Import links
            $links = $onenavPdo->query("SELECT * FROM on_links ORDER BY fid, weight")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($links as $link) {
                try {
                    $categoryId = $categoryMapping[$link['fid']] ?? null;

                    if (!$categoryId) {
                        $categoryId = $this->getOrCreateDefaultCategory($userId);
                    }

                    $this->db->insert('bookmarks', [
                        'title' => $link['title'],
                        'url' => $link['url'],
                        'description' => $link['description'] ?? null,
                        'keywords' => $link['keywords'] ?? null,
                        'icon_url' => $link['icon'] ?? null,
                        'category_id' => $categoryId,
                        'user_id' => $userId,
                        'weight' => $link['weight'] ?? 0,
                        'is_private' => $link['property'] == 'private' ? 1 : 0,
                        'status_code' => $link['status_code'] ?? null,
                        'last_checked' => $link['last_checked_time'] ?? null
                    ]);

                    $stats['bookmarks']++;

                } catch (Exception $e) {
                    $stats['errors'][] = "Bookmark '{$link['title']}': " . $e->getMessage();
                }
            }

            $this->db->commit();
            return $stats;

        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception('OneNav import failed: ' . $e->getMessage());
        }
    }

    /**
     * Import from browser bookmarks HTML file
     */
    public function importFromBrowserBookmarks($htmlFile, $userId, $options = []) {
        if (!file_exists($htmlFile)) {
            throw new Exception('Bookmark file not found');
        }

        $html = file_get_contents($htmlFile);

        if (!$html) {
            throw new Exception('Failed to read bookmark file');
        }

        try {
            $this->db->beginTransaction();

            $stats = ['categories' => 0, 'bookmarks' => 0, 'errors' => []];

            // Parse HTML bookmarks
            $dom = new DOMDocument();
            @$dom->loadHTML($html);

            $this->parseBrowserBookmarks($dom, $userId, null, $stats);

            $this->db->commit();
            return $stats;

        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception('Browser bookmarks import failed: ' . $e->getMessage());
        }
    }

    /**
     * Export user data to various formats
     */
    public function exportUserData($userId, $format = 'json', $includePrivate = true) {
        $data = $this->getUserData($userId, $includePrivate);

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            case 'html':
                return $this->exportToHTML($data);

            case 'csv':
                return $this->exportToCSV($data);

            case 'bookmarks_html':
                return $this->exportToBrowserBookmarks($data);

            default:
                throw new Exception('Unsupported export format');
        }
    }

    /**
     * WebDAV operations
     */
    public function uploadToWebDAV($filename) {
        if (!$this->webdavConfig['enabled']) {
            throw new Exception('WebDAV is not enabled');
        }

        $localPath = $this->backupPath . $filename;
        $remotePath = $this->webdavConfig['url'] . '/' . $filename;

        $ch = curl_init();
        $file = fopen($localPath, 'r');

        curl_setopt($ch, CURLOPT_URL, $remotePath);
        curl_setopt($ch, CURLOPT_USERPWD, $this->webdavConfig['username'] . ':' . $this->webdavConfig['password']);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $file);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localPath));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        fclose($file);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('WebDAV upload failed with HTTP code: ' . $httpCode);
        }

        return true;
    }

    public function listWebDAVBackups() {
        if (!$this->webdavConfig['enabled']) {
            throw new Exception('WebDAV is not enabled');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->webdavConfig['url']);
        curl_setopt($ch, CURLOPT_USERPWD, $this->webdavConfig['username'] . ':' . $this->webdavConfig['password']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('Failed to list WebDAV backups');
        }

        // Parse WebDAV response (simplified)
        $backups = [];
        // This would need proper XML parsing for production use

        return $backups;
    }

    // Private helper methods

    private function exportAllData() {
        return [
            'users' => $this->db->fetchAll("SELECT id, username, email, role, avatar_url, created_at, is_active FROM users"),
            'categories' => $this->db->fetchAll("SELECT * FROM categories"),
            'bookmarks' => $this->db->fetchAll("SELECT * FROM bookmarks"),
            'settings' => $this->db->fetchAll("SELECT * FROM settings WHERE is_public = 1")
        ];
    }

    private function getUserData($userId, $includePrivate = true) {
        $sql = "SELECT * FROM categories WHERE user_id = ?";
        if (!$includePrivate) {
            $sql .= " AND is_private = 0";
        }
        $categories = $this->db->fetchAll($sql, [$userId]);

        $sql = "SELECT * FROM bookmarks WHERE user_id = ?";
        if (!$includePrivate) {
            $sql .= " AND is_private = 0";
        }
        $bookmarks = $this->db->fetchAll($sql, [$userId]);

        return [
            'categories' => $categories,
            'bookmarks' => $bookmarks
        ];
    }

    private function getOrCreateDefaultCategory($userId) {
        $category = $this->db->fetchOne(
            "SELECT id FROM categories WHERE name = 'Imported' AND user_id = ?",
            [$userId]
        );

        if ($category) {
            return $category['id'];
        }

        return $this->db->insert('categories', [
            'name' => 'Imported',
            'user_id' => $userId,
            'icon' => 'fas fa-download',
            'weight' => 0
        ]);
    }

    private function cleanupOldBackups() {
        // Get list of backup files
        $backups = $this->db->fetchAll(
            "SELECT * FROM backups ORDER BY created_at DESC LIMIT -1 OFFSET ?",
            [BACKUP_MAX_FILES]
        );

        foreach ($backups as $backup) {
            $filepath = $this->backupPath . $backup['filename'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            $this->db->delete('backups', 'id = ?', [$backup['id']]);
        }
    }

    private function parseBrowserBookmarks($node, $userId, $parentCategoryId, &$stats) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            if ($node->nodeName === 'H3') {
                // This is a folder/category
                $categoryName = trim($node->textContent);

                try {
                    $categoryId = $this->db->insert('categories', [
                        'name' => $categoryName,
                        'parent_id' => $parentCategoryId,
                        'user_id' => $userId,
                        'icon' => 'fas fa-folder',
                        'weight' => $stats['categories']
                    ]);

                    $stats['categories']++;

                    // Process next sibling (DL containing the bookmarks)
                    $nextSibling = $node->nextSibling;
                    while ($nextSibling && $nextSibling->nodeType !== XML_ELEMENT_NODE) {
                        $nextSibling = $nextSibling->nextSibling;
                    }

                    if ($nextSibling && $nextSibling->nodeName === 'DL') {
                        $this->parseBrowserBookmarks($nextSibling, $userId, $categoryId, $stats);
                    }

                } catch (Exception $e) {
                    $stats['errors'][] = "Category '$categoryName': " . $e->getMessage();
                }

            } elseif ($node->nodeName === 'A') {
                // This is a bookmark
                $title = trim($node->textContent);
                $url = $node->getAttribute('href');

                if ($title && $url) {
                    try {
                        $categoryId = $parentCategoryId ?: $this->getOrCreateDefaultCategory($userId);

                        $this->db->insert('bookmarks', [
                            'title' => $title,
                            'url' => $url,
                            'category_id' => $categoryId,
                            'user_id' => $userId,
                            'weight' => $stats['bookmarks']
                        ]);

                        $stats['bookmarks']++;

                    } catch (Exception $e) {
                        $stats['errors'][] = "Bookmark '$title': " . $e->getMessage();
                    }
                }
            }
        }

        // Process child nodes
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $this->parseBrowserBookmarks($child, $userId, $parentCategoryId, $stats);
            }
        }
    }

    private function exportToHTML($data) {
        $html = "<!DOCTYPE html>\n<html>\n<head>\n<title>OneBookNav Export</title>\n</head>\n<body>\n";
        $html .= "<h1>OneBookNav Bookmarks</h1>\n";

        // Build category tree
        $categories = [];
        foreach ($data['categories'] as $category) {
            $categories[$category['id']] = $category;
        }

        // Group bookmarks by category
        $bookmarksByCategory = [];
        foreach ($data['bookmarks'] as $bookmark) {
            $bookmarksByCategory[$bookmark['category_id']][] = $bookmark;
        }

        // Render categories and bookmarks
        foreach ($categories as $category) {
            if ($category['parent_id'] === null) {
                $html .= $this->renderCategoryHTML($category, $categories, $bookmarksByCategory);
            }
        }

        $html .= "</body>\n</html>";
        return $html;
    }

    private function exportToCSV($data) {
        $csv = "Title,URL,Category,Description,Keywords,Private\n";

        $categoryNames = [];
        foreach ($data['categories'] as $category) {
            $categoryNames[$category['id']] = $category['name'];
        }

        foreach ($data['bookmarks'] as $bookmark) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s"' . "\n",
                str_replace('"', '""', $bookmark['title']),
                $bookmark['url'],
                $categoryNames[$bookmark['category_id']] ?? 'Unknown',
                str_replace('"', '""', $bookmark['description'] ?? ''),
                str_replace('"', '""', $bookmark['keywords'] ?? ''),
                $bookmark['is_private'] ? 'Yes' : 'No'
            );
        }

        return $csv;
    }

    private function exportToBrowserBookmarks($data) {
        $html = "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n";
        $html .= "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n";
        $html .= "<TITLE>Bookmarks</TITLE>\n";
        $html .= "<H1>Bookmarks</H1>\n<DL><p>\n";

        // Build category tree and render
        $categories = [];
        foreach ($data['categories'] as $category) {
            $categories[$category['id']] = $category;
        }

        $bookmarksByCategory = [];
        foreach ($data['bookmarks'] as $bookmark) {
            $bookmarksByCategory[$bookmark['category_id']][] = $bookmark;
        }

        foreach ($categories as $category) {
            if ($category['parent_id'] === null) {
                $html .= $this->renderCategoryBookmarks($category, $categories, $bookmarksByCategory);
            }
        }

        $html .= "</DL><p>\n";
        return $html;
    }

    private function renderCategoryHTML($category, $categories, $bookmarksByCategory, $level = 0) {
        $indent = str_repeat('  ', $level);
        $html = $indent . "<h" . ($level + 2) . ">{$category['name']}</h" . ($level + 2) . ">\n";

        // Render bookmarks in this category
        if (isset($bookmarksByCategory[$category['id']])) {
            $html .= $indent . "<ul>\n";
            foreach ($bookmarksByCategory[$category['id']] as $bookmark) {
                $html .= $indent . "  <li><a href=\"{$bookmark['url']}\">{$bookmark['title']}</a>";
                if ($bookmark['description']) {
                    $html .= " - {$bookmark['description']}";
                }
                $html .= "</li>\n";
            }
            $html .= $indent . "</ul>\n";
        }

        // Render child categories
        foreach ($categories as $child) {
            if ($child['parent_id'] == $category['id']) {
                $html .= $this->renderCategoryHTML($child, $categories, $bookmarksByCategory, $level + 1);
            }
        }

        return $html;
    }

    private function renderCategoryBookmarks($category, $categories, $bookmarksByCategory, $level = 0) {
        $indent = str_repeat('  ', $level);
        $html = $indent . "<DT><H3>{$category['name']}</H3>\n";
        $html .= $indent . "<DL><p>\n";

        // Render bookmarks
        if (isset($bookmarksByCategory[$category['id']])) {
            foreach ($bookmarksByCategory[$category['id']] as $bookmark) {
                $html .= $indent . "  <DT><A HREF=\"{$bookmark['url']}\">{$bookmark['title']}</A>\n";
            }
        }

        // Render child categories
        foreach ($categories as $child) {
            if ($child['parent_id'] == $category['id']) {
                $html .= $this->renderCategoryBookmarks($child, $categories, $bookmarksByCategory, $level + 1);
            }
        }

        $html .= $indent . "</DL><p>\n";
        return $html;
    }

    private function restoreOnebooknavBackup($backupData, $userId, $options) {
        // Implementation for restoring OneBookNav format
        $this->db->beginTransaction();

        try {
            $stats = ['users' => 0, 'categories' => 0, 'bookmarks' => 0, 'errors' => []];

            // Restore categories and bookmarks for the specific user
            $data = $backupData['data'];

            // Filter data for specific user if needed
            if (isset($options['user_filter'])) {
                $data['categories'] = array_filter($data['categories'], function($cat) use ($options) {
                    return $cat['user_id'] == $options['user_filter'];
                });
                $data['bookmarks'] = array_filter($data['bookmarks'], function($bm) use ($options) {
                    return $bm['user_id'] == $options['user_filter'];
                });
            }

            // Update user_id to current user
            foreach ($data['categories'] as &$category) {
                $category['user_id'] = $userId;
            }
            foreach ($data['bookmarks'] as &$bookmark) {
                $bookmark['user_id'] = $userId;
            }

            // Import categories
            $categoryMapping = [];
            foreach ($data['categories'] as $category) {
                $oldId = $category['id'];
                unset($category['id']);

                $newId = $this->db->insert('categories', $category);
                $categoryMapping[$oldId] = $newId;
                $stats['categories']++;
            }

            // Import bookmarks with updated category IDs
            foreach ($data['bookmarks'] as $bookmark) {
                unset($bookmark['id']);
                $bookmark['category_id'] = $categoryMapping[$bookmark['category_id']] ?? null;

                if ($bookmark['category_id']) {
                    $this->db->insert('bookmarks', $bookmark);
                    $stats['bookmarks']++;
                }
            }

            $this->db->commit();
            return $stats;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function restoreBooknavBackup($backupData, $userId, $options) {
        // Placeholder for BookNav format restoration
        throw new Exception('BookNav backup restoration not yet implemented');
    }

    private function restoreOnenavBackup($backupData, $userId, $options) {
        // Placeholder for OneNav format restoration
        throw new Exception('OneNav backup restoration not yet implemented');
    }

    private function autoDetectAndRestore($backupData, $userId, $options) {
        // Try to auto-detect the backup format based on structure
        if (isset($backupData['data']['categories']) && isset($backupData['data']['bookmarks'])) {
            return $this->restoreOnebooknavBackup($backupData, $userId, $options);
        }

        throw new Exception('Unable to detect backup format');
    }
}
?>