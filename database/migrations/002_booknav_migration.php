<?php
/**
 * OneBookNav - BookNav 数据迁移
 *
 * 从 BookNav 项目迁移数据到 OneBookNav
 */

class Migration_002_BooknavMigration
{
    private $sourceDb;
    private $targetDb;
    private $tableMapping = [
        'user' => 'users',
        'category' => 'categories',
        'website' => 'websites',
        'website_tag' => 'website_tags',
        'invitation_code' => 'invitation_codes',
        'site_settings' => 'site_settings',
        'deadlink_check' => 'deadlink_checks'
    ];

    public function up($db)
    {
        $this->targetDb = $db;

        // 获取 BookNav 数据库路径
        $booknavDbPath = $this->getBooknavDatabasePath();

        if (!$booknavDbPath || !file_exists($booknavDbPath)) {
            error_log("BookNav database not found, skipping migration");
            return true;
        }

        try {
            $this->sourceDb = new PDO("sqlite:{$booknavDbPath}");
            $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->migrateUsers();
            $this->migrateCategories();
            $this->migrateWebsites();
            $this->migrateWebsiteTags();
            $this->migrateInvitationCodes();
            $this->migrateSiteSettings();
            $this->migrateDeadlinkChecks();

            error_log("BookNav migration completed successfully");
            return true;

        } catch (Exception $e) {
            error_log("BookNav migration failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function down($db)
    {
        // 删除迁移的数据（基于创建时间或特殊标记）
        $db->exec("DELETE FROM users WHERE created_at < (SELECT MIN(created_at) FROM users WHERE username = 'admin')");
        $db->exec("DELETE FROM categories WHERE id > 6"); // 保留默认分类
        $db->exec("DELETE FROM websites WHERE id > 0");
        $db->exec("DELETE FROM website_tags WHERE id > 0");
        $db->exec("DELETE FROM invitation_codes WHERE id > 0");
        $db->exec("DELETE FROM deadlink_checks WHERE id > 0");

        return true;
    }

    private function getBooknavDatabasePath()
    {
        // 尝试多个可能的 BookNav 数据库位置
        $possiblePaths = [
            $_ENV['BOOKNAV_DB_PATH'] ?? null,
            __DIR__ . '/../../book-nav-main/book-nav-main/app.db',
            __DIR__ . '/../../book-nav-main/book-nav-main/data/app.db',
            __DIR__ . '/../../../book-nav-main/book-nav-main/app.db',
            __DIR__ . '/../../../book-nav-main/book-nav-main/data/app.db',
        ];

        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function migrateUsers()
    {
        $stmt = $this->sourceDb->query("SELECT * FROM user ORDER BY id");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            // 检查用户是否已存在
            $checkStmt = $this->targetDb->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $checkStmt->execute([$user['username'], $user['email']]);

            if ($checkStmt->fetchColumn() > 0) {
                continue; // 跳过已存在的用户
            }

            $insertStmt = $this->targetDb->prepare("
                INSERT INTO users (
                    username, email, password_hash, salt, role, status,
                    last_login_at, avatar, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $user['username'],
                $user['email'],
                $user['password_hash'],
                $user['salt'] ?? bin2hex(random_bytes(16)),
                $this->mapRole($user['role']),
                $user['is_active'] ? 'active' : 'inactive',
                $user['last_login'] ?? null,
                $user['avatar'] ?? null,
                $user['created_at'],
                $user['updated_at']
            ]);
        }
    }

    private function migrateCategories()
    {
        $stmt = $this->sourceDb->query("SELECT * FROM category ORDER BY sort_order, id");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($categories as $category) {
            // 检查分类是否已存在
            $checkStmt = $this->targetDb->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
            $checkStmt->execute([$category['name']]);

            if ($checkStmt->fetchColumn() > 0) {
                continue;
            }

            $insertStmt = $this->targetDb->prepare("
                INSERT INTO categories (
                    name, description, icon, sort_order, user_id, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $category['name'],
                $category['description'],
                $category['icon'],
                $category['sort_order'],
                $category['user_id'] ?? null,
                $category['created_at'],
                $category['updated_at']
            ]);
        }
    }

    private function migrateWebsites()
    {
        $stmt = $this->sourceDb->query("SELECT * FROM website ORDER BY category_id, sort_order, id");
        $websites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($websites as $website) {
            // 获取对应的分类ID
            $categoryStmt = $this->targetDb->prepare("SELECT id FROM categories WHERE name = (SELECT name FROM category WHERE id = ? LIMIT 1)");
            $categoryStmt->execute([$website['category_id']]);
            $categoryId = $categoryStmt->fetchColumn();

            if (!$categoryId) {
                $categoryId = 1; // 默认分类
            }

            $insertStmt = $this->targetDb->prepare("
                INSERT INTO websites (
                    title, url, description, category_id, user_id, icon, favicon_url,
                    sort_order, clicks, status, is_private, last_checked_at,
                    check_status, response_time, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $website['title'],
                $website['url'],
                $website['description'],
                $categoryId,
                $website['user_id'] ?? null,
                $website['icon'],
                $website['favicon_url'] ?? null,
                $website['sort_order'],
                $website['clicks'] ?? 0,
                $this->mapWebsiteStatus($website['status']),
                $website['is_private'] ?? false,
                $website['last_checked'] ?? null,
                $website['check_status'] ?? null,
                $website['response_time'] ?? null,
                $website['created_at'],
                $website['updated_at']
            ]);
        }
    }

    private function migrateWebsiteTags()
    {
        // 检查源数据库是否有 website_tag 表
        try {
            $stmt = $this->sourceDb->query("SELECT * FROM website_tag");
            $websiteTags = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($websiteTags as $websiteTag) {
                // 获取对应的网站ID和标签ID
                $websiteStmt = $this->targetDb->prepare("SELECT id FROM websites WHERE title = (SELECT title FROM website WHERE id = ? LIMIT 1)");
                $websiteStmt->execute([$websiteTag['website_id']]);
                $websiteId = $websiteStmt->fetchColumn();

                if (!$websiteId) {
                    continue;
                }

                // 创建或获取标签
                $tagName = $websiteTag['tag_name'] ?? 'imported';
                $tagStmt = $this->targetDb->prepare("INSERT OR IGNORE INTO tags (name) VALUES (?)");
                $tagStmt->execute([$tagName]);

                $tagIdStmt = $this->targetDb->prepare("SELECT id FROM tags WHERE name = ?");
                $tagIdStmt->execute([$tagName]);
                $tagId = $tagIdStmt->fetchColumn();

                // 创建关联
                $insertStmt = $this->targetDb->prepare("INSERT OR IGNORE INTO website_tags (website_id, tag_id) VALUES (?, ?)");
                $insertStmt->execute([$websiteId, $tagId]);
            }
        } catch (Exception $e) {
            // 表不存在，跳过
        }
    }

    private function migrateInvitationCodes()
    {
        try {
            $stmt = $this->sourceDb->query("SELECT * FROM invitation_code");
            $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($codes as $code) {
                $insertStmt = $this->targetDb->prepare("
                    INSERT OR IGNORE INTO invitation_codes (
                        code, created_by, used_by, max_uses, used_count,
                        expires_at, is_active, created_at, used_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insertStmt->execute([
                    $code['code'],
                    $code['created_by'],
                    $code['used_by'],
                    $code['max_uses'] ?? 1,
                    $code['used_count'] ?? 0,
                    $code['expires_at'],
                    $code['is_active'] ?? true,
                    $code['created_at'],
                    $code['used_at']
                ]);
            }
        } catch (Exception $e) {
            // 表不存在，跳过
        }
    }

    private function migrateSiteSettings()
    {
        try {
            $stmt = $this->sourceDb->query("SELECT * FROM site_settings");
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($settings as $setting) {
                $insertStmt = $this->targetDb->prepare("
                    INSERT OR REPLACE INTO site_settings (
                        key, value, type, description, group_name
                    ) VALUES (?, ?, ?, ?, ?)
                ");

                $insertStmt->execute([
                    $setting['key'],
                    $setting['value'],
                    $setting['type'] ?? 'string',
                    $setting['description'],
                    'imported'
                ]);
            }
        } catch (Exception $e) {
            // 表不存在，跳过
        }
    }

    private function migrateDeadlinkChecks()
    {
        try {
            $stmt = $this->sourceDb->query("SELECT * FROM deadlink_check ORDER BY check_time DESC LIMIT 1000");
            $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($checks as $check) {
                // 获取对应的网站ID
                $websiteStmt = $this->targetDb->prepare("SELECT id FROM websites WHERE url = (SELECT url FROM website WHERE id = ? LIMIT 1)");
                $websiteStmt->execute([$check['website_id']]);
                $websiteId = $websiteStmt->fetchColumn();

                if (!$websiteId) {
                    continue;
                }

                $insertStmt = $this->targetDb->prepare("
                    INSERT INTO deadlink_checks (
                        website_id, check_time, status, response_time,
                        http_status, error_message
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");

                $insertStmt->execute([
                    $websiteId,
                    $check['check_time'],
                    $this->mapCheckStatus($check['status']),
                    $check['response_time'],
                    $check['http_status'],
                    $check['error_message']
                ]);
            }
        } catch (Exception $e) {
            // 表不存在，跳过
        }
    }

    private function mapRole($role)
    {
        $roleMap = [
            'superadmin' => 'admin',
            'admin' => 'admin',
            'user' => 'user',
            'guest' => 'guest'
        ];

        return $roleMap[$role] ?? 'user';
    }

    private function mapWebsiteStatus($status)
    {
        $statusMap = [
            'active' => 'active',
            'inactive' => 'inactive',
            'pending' => 'pending',
            'disabled' => 'inactive',
            'broken' => 'broken'
        ];

        return $statusMap[$status] ?? 'active';
    }

    private function mapCheckStatus($status)
    {
        $statusMap = [
            'ok' => 'ok',
            'success' => 'ok',
            'error' => 'error',
            'failed' => 'error',
            'timeout' => 'timeout',
            'not_found' => 'not_found',
            '404' => 'not_found'
        ];

        return $statusMap[$status] ?? 'error';
    }

    public function getDescription()
    {
        return "Migrate data from BookNav to OneBookNav";
    }

    public function getVersion()
    {
        return '1.0.0';
    }
}