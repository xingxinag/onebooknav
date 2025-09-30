<?php
/**
 * OneBookNav 数据迁移脚本
 * 支持从 BookNav、OneNav 和浏览器书签导入数据
 */

require_once __DIR__ . '/../bootstrap.php';

class DataMigrator {
    private $db;
    private $importStats = [
        'users' => 0,
        'categories' => 0,
        'websites' => 0,
        'errors' => 0
    ];

    public function __construct() {
        $this->initDatabase();
    }

    private function initDatabase() {
        $dbPath = __DIR__ . '/../data/onebooknav.db';
        try {
            $this->db = new PDO("sqlite:{$dbPath}");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->log("数据库连接失败: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
    }

    /**
     * 从 BookNav 迁移数据
     */
    public function migrateFromBookNav($booknavDbPath) {
        $this->log("开始从 BookNav 迁移数据: {$booknavDbPath}");

        if (!file_exists($booknavDbPath)) {
            throw new Exception("BookNav 数据库文件不存在: {$booknavDbPath}");
        }

        try {
            $sourceDb = new PDO("sqlite:{$booknavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->db->beginTransaction();

            // 迁移用户
            $this->migrateBookNavUsers($sourceDb);

            // 迁移分类
            $this->migrateBookNavCategories($sourceDb);

            // 迁移网站
            $this->migrateBookNavWebsites($sourceDb);

            $this->db->commit();

            $this->log("BookNav 数据迁移完成");
            $this->printStats();

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->log("BookNav 迁移失败: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * 迁移 BookNav 用户
     */
    private function migrateBookNavUsers($sourceDb) {
        $stmt = $sourceDb->query("SELECT * FROM user");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            try {
                $insertStmt = $this->db->prepare("
                    INSERT OR IGNORE INTO users
                    (username, email, password_hash, is_admin, is_superadmin, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
                ");

                $insertStmt->execute([
                    $user['username'],
                    $user['email'],
                    $user['password_hash'],
                    $user['is_admin'] ?? 0,
                    $user['is_superadmin'] ?? 0,
                    $user['created_at'] ?? date('Y-m-d H:i:s')
                ]);

                $this->importStats['users']++;
                $this->log("迁移用户: {$user['username']}");

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("用户迁移失败 {$user['username']}: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * 迁移 BookNav 分类
     */
    private function migrateBookNavCategories($sourceDb) {
        $stmt = $sourceDb->query("SELECT * FROM category ORDER BY weight ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $categoryMap = [];

        foreach ($categories as $category) {
            try {
                $insertStmt = $this->db->prepare("
                    INSERT INTO categories
                    (name, description, icon, color, weight, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
                ");

                $insertStmt->execute([
                    $category['name'],
                    $category['description'] ?? '',
                    $category['icon'] ?? '',
                    $category['color'] ?? '#2563eb',
                    $category['weight'] ?? 0,
                    $category['created_at'] ?? date('Y-m-d H:i:s')
                ]);

                $newId = $this->db->lastInsertId();
                $categoryMap[$category['id']] = $newId;

                $this->importStats['categories']++;
                $this->log("迁移分类: {$category['name']}");

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("分类迁移失败 {$category['name']}: " . $e->getMessage(), 'ERROR');
            }
        }

        return $categoryMap;
    }

    /**
     * 迁移 BookNav 网站
     */
    private function migrateBookNavWebsites($sourceDb) {
        $stmt = $sourceDb->query("SELECT * FROM website ORDER BY weight ASC");
        $websites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($websites as $website) {
            try {
                // 获取对应的分类 ID
                $categoryStmt = $this->db->prepare("
                    SELECT id FROM categories WHERE name = (
                        SELECT name FROM category WHERE id = ? LIMIT 1
                    )
                ");
                $categoryStmt->execute([$website['category_id']]);
                $categoryId = $categoryStmt->fetchColumn();

                if (!$categoryId) {
                    // 创建默认分类
                    $defaultStmt = $this->db->prepare("
                        INSERT OR IGNORE INTO categories (name) VALUES ('未分类')
                    ");
                    $defaultStmt->execute();
                    $categoryId = $this->db->lastInsertId() ?: 1;
                }

                $insertStmt = $this->db->prepare("
                    INSERT INTO websites
                    (title, url, description, icon, category_id, user_id, weight, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 1, ?, ?, datetime('now'))
                ");

                $insertStmt->execute([
                    $website['title'],
                    $website['url'],
                    $website['description'] ?? '',
                    $website['icon'] ?? '',
                    $categoryId,
                    $website['weight'] ?? 0,
                    $website['created_at'] ?? date('Y-m-d H:i:s')
                ]);

                $this->importStats['websites']++;
                $this->log("迁移网站: {$website['title']}");

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("网站迁移失败 {$website['title']}: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * 从 OneNav 迁移数据
     */
    public function migrateFromOneNav($onenavDbPath) {
        $this->log("开始从 OneNav 迁移数据: {$onenavDbPath}");

        if (!file_exists($onenavDbPath)) {
            throw new Exception("OneNav 数据库文件不存在: {$onenavDbPath}");
        }

        try {
            $sourceDb = new PDO("sqlite:{$onenavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->db->beginTransaction();

            // 迁移分类
            $categoryMap = $this->migrateOneNavCategories($sourceDb);

            // 迁移链接
            $this->migrateOneNavLinks($sourceDb, $categoryMap);

            $this->db->commit();

            $this->log("OneNav 数据迁移完成");
            $this->printStats();

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->log("OneNav 迁移失败: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * 迁移 OneNav 分类
     */
    private function migrateOneNavCategories($sourceDb) {
        $stmt = $sourceDb->query("SELECT * FROM on_categorys ORDER BY weight ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $categoryMap = [];

        foreach ($categories as $category) {
            try {
                $insertStmt = $this->db->prepare("
                    INSERT INTO categories
                    (name, description, icon, color, weight, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");

                $insertStmt->execute([
                    $category['name'],
                    $category['description'] ?? '',
                    $category['font_icon'] ?? '',
                    $category['color'] ?? '#2563eb',
                    $category['weight'] ?? 0
                ]);

                $newId = $this->db->lastInsertId();
                $categoryMap[$category['id']] = $newId;

                $this->importStats['categories']++;
                $this->log("迁移分类: {$category['name']}");

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("分类迁移失败 {$category['name']}: " . $e->getMessage(), 'ERROR');
            }
        }

        return $categoryMap;
    }

    /**
     * 迁移 OneNav 链接
     */
    private function migrateOneNavLinks($sourceDb, $categoryMap) {
        $stmt = $sourceDb->query("SELECT * FROM on_links ORDER BY weight ASC");
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($links as $link) {
            try {
                $categoryId = $categoryMap[$link['fid']] ?? 1;

                $insertStmt = $this->db->prepare("
                    INSERT INTO websites
                    (title, url, description, icon, category_id, user_id, weight, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 1, ?, datetime('now'), datetime('now'))
                ");

                $insertStmt->execute([
                    $link['title'],
                    $link['url'],
                    $link['description'] ?? '',
                    $link['icon'] ?? '',
                    $categoryId,
                    $link['weight'] ?? 0
                ]);

                $this->importStats['websites']++;
                $this->log("迁移链接: {$link['title']}");

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("链接迁移失败 {$link['title']}: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * 从浏览器书签导入
     */
    public function importFromBookmarkFile($filePath, $format = 'html') {
        $this->log("开始导入书签文件: {$filePath}");

        if (!file_exists($filePath)) {
            throw new Exception("书签文件不存在: {$filePath}");
        }

        try {
            $this->db->beginTransaction();

            switch (strtolower($format)) {
                case 'html':
                    $this->importHTMLBookmarks($filePath);
                    break;
                case 'json':
                    $this->importJSONBookmarks($filePath);
                    break;
                case 'csv':
                    $this->importCSVBookmarks($filePath);
                    break;
                default:
                    throw new Exception("不支持的文件格式: {$format}");
            }

            $this->db->commit();

            $this->log("书签导入完成");
            $this->printStats();

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->log("书签导入失败: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * 导入 HTML 书签
     */
    private function importHTMLBookmarks($filePath) {
        $content = file_get_contents($filePath);

        // 解析 HTML 书签结构
        $dom = new DOMDocument();
        @$dom->loadHTML($content);

        $xpath = new DOMXPath($dom);

        // 查找所有书签文件夹（DT > H3）
        $folders = $xpath->query('//dt/h3');
        $categoryMap = [];

        foreach ($folders as $folder) {
            $categoryName = trim($folder->textContent);
            if (empty($categoryName)) {
                $categoryName = '未分类';
            }

            // 创建分类
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO categories (name, created_at, updated_at)
                VALUES (?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([$categoryName]);

            $stmt = $this->db->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->execute([$categoryName]);
            $categoryId = $stmt->fetchColumn();

            $categoryMap[$categoryName] = $categoryId;
            $this->importStats['categories']++;
        }

        // 查找所有链接（A 标签）
        $links = $xpath->query('//dt/a[@href]');

        foreach ($links as $link) {
            $title = trim($link->textContent);
            $url = $link->getAttribute('href');
            $icon = $link->getAttribute('icon') ?: '';

            if (empty($title) || empty($url)) {
                continue;
            }

            // 找到所属分类
            $parentFolder = $xpath->query('ancestor::dl/preceding-sibling::dt/h3', $link)->item(0);
            $categoryName = $parentFolder ? trim($parentFolder->textContent) : '未分类';
            $categoryId = $categoryMap[$categoryName] ?? 1;

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO websites
                    (title, url, icon, category_id, user_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, datetime('now'), datetime('now'))
                ");

                $stmt->execute([$title, $url, $icon, $categoryId]);

                $this->importStats['websites']++;
                $this->log("导入书签: {$title}");

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("书签导入失败 {$title}: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * 导入 JSON 书签
     */
    private function importJSONBookmarks($filePath) {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON 格式错误: " . json_last_error_msg());
        }

        $this->processJSONBookmarks($data);
    }

    /**
     * 递归处理 JSON 书签
     */
    private function processJSONBookmarks($data, $parentCategoryId = null) {
        if (isset($data['children'])) {
            foreach ($data['children'] as $item) {
                if ($item['type'] === 'folder') {
                    // 创建分类
                    $stmt = $this->db->prepare("
                        INSERT INTO categories
                        (name, parent_id, created_at, updated_at)
                        VALUES (?, ?, datetime('now'), datetime('now'))
                    ");
                    $stmt->execute([$item['name'], $parentCategoryId]);

                    $categoryId = $this->db->lastInsertId();
                    $this->importStats['categories']++;

                    // 递归处理子项
                    $this->processJSONBookmarks($item, $categoryId);

                } elseif ($item['type'] === 'url') {
                    // 创建书签
                    $stmt = $this->db->prepare("
                        INSERT INTO websites
                        (title, url, category_id, user_id, created_at, updated_at)
                        VALUES (?, ?, ?, 1, datetime('now'), datetime('now'))
                    ");
                    $stmt->execute([
                        $item['name'],
                        $item['url'],
                        $parentCategoryId ?: 1
                    ]);

                    $this->importStats['websites']++;
                }
            }
        }
    }

    /**
     * 导入 CSV 书签
     */
    private function importCSVBookmarks($filePath) {
        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle);

        // 检查 CSV 格式
        $expectedColumns = ['title', 'url', 'category', 'description'];
        $columnMap = [];

        foreach ($expectedColumns as $col) {
            $index = array_search($col, $header);
            if ($index !== false) {
                $columnMap[$col] = $index;
            }
        }

        if (!isset($columnMap['title']) || !isset($columnMap['url'])) {
            throw new Exception("CSV 文件缺少必需列: title, url");
        }

        $categories = [];

        while (($row = fgetcsv($handle)) !== false) {
            $title = $row[$columnMap['title']] ?? '';
            $url = $row[$columnMap['url']] ?? '';
            $categoryName = $row[$columnMap['category']] ?? '未分类';
            $description = $row[$columnMap['description']] ?? '';

            if (empty($title) || empty($url)) {
                continue;
            }

            // 获取或创建分类
            if (!isset($categories[$categoryName])) {
                $stmt = $this->db->prepare("
                    INSERT OR IGNORE INTO categories (name, created_at, updated_at)
                    VALUES (?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([$categoryName]);

                $stmt = $this->db->prepare("SELECT id FROM categories WHERE name = ?");
                $stmt->execute([$categoryName]);
                $categories[$categoryName] = $stmt->fetchColumn();

                $this->importStats['categories']++;
            }

            // 创建书签
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO websites
                    (title, url, description, category_id, user_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, datetime('now'), datetime('now'))
                ");

                $stmt->execute([
                    $title,
                    $url,
                    $description,
                    $categories[$categoryName]
                ]);

                $this->importStats['websites']++;

            } catch (Exception $e) {
                $this->importStats['errors']++;
                $this->log("CSV 书签导入失败 {$title}: " . $e->getMessage(), 'ERROR');
            }
        }

        fclose($handle);
    }

    /**
     * 验证和清理数据
     */
    public function validateAndCleanup() {
        $this->log("开始数据验证和清理...");

        // 删除重复的网站
        $this->removeDuplicateWebsites();

        // 修复无效的分类关联
        $this->fixInvalidCategories();

        // 验证 URL 格式
        $this->validateUrls();

        $this->log("数据验证和清理完成");
    }

    /**
     * 删除重复网站
     */
    private function removeDuplicateWebsites() {
        $stmt = $this->db->prepare("
            DELETE FROM websites WHERE id NOT IN (
                SELECT MIN(id) FROM websites GROUP BY url
            )
        ");
        $stmt->execute();

        $removed = $stmt->rowCount();
        $this->log("删除重复网站: {$removed} 条");
    }

    /**
     * 修复无效分类
     */
    private function fixInvalidCategories() {
        // 获取默认分类 ID
        $stmt = $this->db->prepare("
            SELECT id FROM categories WHERE name = '未分类' LIMIT 1
        ");
        $stmt->execute();
        $defaultCategoryId = $stmt->fetchColumn();

        if (!$defaultCategoryId) {
            $stmt = $this->db->prepare("
                INSERT INTO categories (name, created_at, updated_at)
                VALUES ('未分类', datetime('now'), datetime('now'))
            ");
            $stmt->execute();
            $defaultCategoryId = $this->db->lastInsertId();
        }

        // 修复无效的分类关联
        $stmt = $this->db->prepare("
            UPDATE websites SET category_id = ?
            WHERE category_id NOT IN (SELECT id FROM categories)
        ");
        $stmt->execute([$defaultCategoryId]);

        $fixed = $stmt->rowCount();
        $this->log("修复无效分类关联: {$fixed} 条");
    }

    /**
     * 验证 URL
     */
    private function validateUrls() {
        $stmt = $this->db->query("SELECT id, url FROM websites");
        $websites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fixed = 0;

        foreach ($websites as $website) {
            $url = trim($website['url']);

            // 修复缺少协议的 URL
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'http://' . $url;

                $updateStmt = $this->db->prepare("UPDATE websites SET url = ? WHERE id = ?");
                $updateStmt->execute([$url, $website['id']]);

                $fixed++;
            }
        }

        $this->log("修复 URL 格式: {$fixed} 条");
    }

    /**
     * 打印统计信息
     */
    private function printStats() {
        $this->log("=== 导入统计 ===");
        $this->log("用户: {$this->importStats['users']}");
        $this->log("分类: {$this->importStats['categories']}");
        $this->log("网站: {$this->importStats['websites']}");
        $this->log("错误: {$this->importStats['errors']}");
        $this->log("===============");
    }

    /**
     * 日志记录
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

        echo $logMessage;

        $logFile = __DIR__ . '/../logs/migrate.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// 命令行执行
if (php_sapi_name() === 'cli') {
    $options = getopt('s:f:t:cv', [
        'source:', 'file:', 'type:', 'cleanup', 'validate', 'help'
    ]);

    if (isset($options['help'])) {
        echo "OneBookNav 数据迁移工具\n\n";
        echo "使用方法:\n";
        echo "  php migrate.php [选项]\n\n";
        echo "选项:\n";
        echo "  -s, --source        源类型 (booknav|onenav|bookmarks)\n";
        echo "  -f, --file          源文件路径\n";
        echo "  -t, --type          书签文件类型 (html|json|csv)\n";
        echo "  -c, --cleanup       数据清理\n";
        echo "  -v, --validate      数据验证\n";
        echo "      --help          显示帮助\n\n";
        echo "示例:\n";
        echo "  php migrate.php -s booknav -f /path/to/booknav.db\n";
        echo "  php migrate.php -s bookmarks -f bookmarks.html -t html\n";
        echo "  php migrate.php -c -v\n";
        exit(0);
    }

    $migrator = new DataMigrator();

    try {
        if (isset($options['c']) || isset($options['cleanup']) ||
            isset($options['v']) || isset($options['validate'])) {
            $migrator->validateAndCleanup();
        } else {
            $source = $options['s'] ?? $options['source'] ?? '';
            $file = $options['f'] ?? $options['file'] ?? '';

            if (empty($source) || empty($file)) {
                echo "错误: 需要指定源类型和文件路径\n";
                exit(1);
            }

            switch ($source) {
                case 'booknav':
                    $migrator->migrateFromBookNav($file);
                    break;
                case 'onenav':
                    $migrator->migrateFromOneNav($file);
                    break;
                case 'bookmarks':
                    $type = $options['t'] ?? $options['type'] ?? 'html';
                    $migrator->importFromBookmarkFile($file, $type);
                    break;
                default:
                    echo "错误: 不支持的源类型: {$source}\n";
                    exit(1);
            }

            // 自动执行数据验证和清理
            $migrator->validateAndCleanup();
        }

        echo "操作完成!\n";

    } catch (Exception $e) {
        echo "操作失败: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>