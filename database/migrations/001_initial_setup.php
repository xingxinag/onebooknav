<?php
/**
 * OneBookNav - 初始化数据库迁移
 *
 * 创建基础数据库结构
 */

class Migration_001_InitialSetup
{
    public function up($db)
    {
        // 读取并执行 schema.sql
        $schemaFile = __DIR__ . '/../schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception('Schema file not found: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);
        $statements = explode(';', $sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $db->exec($statement);
            }
        }

        // 创建默认管理员用户
        $this->createDefaultAdmin($db);

        return true;
    }

    public function down($db)
    {
        // 删除所有表（按依赖关系倒序）
        $tables = [
            'audit_logs',
            'backup_logs',
            'search_logs',
            'click_logs',
            'user_favorites',
            'deadlink_checks',
            'website_tags',
            'tags',
            'websites',
            'categories',
            'invitation_codes',
            'site_settings',
            'user_sessions',
            'users',
            'migrations'
        ];

        foreach ($tables as $table) {
            $db->exec("DROP TABLE IF EXISTS {$table}");
        }

        // 删除视图
        $db->exec("DROP VIEW IF EXISTS website_stats");
        $db->exec("DROP VIEW IF EXISTS user_activity_stats");

        return true;
    }

    private function createDefaultAdmin($db)
    {
        // 从环境变量或默认值获取管理员信息
        $username = $_ENV['ADMIN_USERNAME'] ?? 'admin';
        $email = $_ENV['ADMIN_EMAIL'] ?? 'admin@example.com';
        $password = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';

        // 检查用户是否已存在
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            return; // 用户已存在，跳过创建
        }

        // 生成密码哈希
        $salt = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password . $salt, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);

        // 创建管理员用户
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password_hash, salt, role, status, created_at)
            VALUES (?, ?, ?, ?, 'admin', 'active', CURRENT_TIMESTAMP)
        ");

        $stmt->execute([$username, $email, $passwordHash, $salt]);

        // 记录创建日志
        error_log("OneBookNav: Default admin user created - Username: {$username}, Email: {$email}");
    }

    public function getDescription()
    {
        return "Initial database setup with all tables, indexes, triggers, and default data";
    }

    public function getVersion()
    {
        return '1.0.0';
    }
}