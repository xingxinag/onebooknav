<?php
/**
 * OneBookNav 数据迁移测试脚本
 * 验证BookNavImporter和OneNavImporter的功能完整性
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置路径
$rootPath = dirname(__DIR__);
require_once $rootPath . '/bootstrap.php';
require_once $rootPath . '/app/Services/BookNavImporter.php';
require_once $rootPath . '/app/Services/OneNavImporter.php';

class MigrationTester
{
    private $testResults = [];
    private $tempDb;
    private $testDbPath;

    public function __construct()
    {
        $this->testDbPath = $rootPath . '/data/test_migration.db';
        $this->initTestDatabase();
    }

    /**
     * 初始化测试数据库
     */
    private function initTestDatabase()
    {
        try {
            // 删除现有测试数据库
            if (file_exists($this->testDbPath)) {
                unlink($this->testDbPath);
            }

            $this->tempDb = new PDO("sqlite:{$this->testDbPath}");
            $this->tempDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 创建基本表结构
            $this->createTablesSchema();

            $this->log("测试数据库初始化成功", true);
        } catch (Exception $e) {
            $this->log("测试数据库初始化失败: " . $e->getMessage(), false);
        }
    }

    /**
     * 创建表结构
     */
    private function createTablesSchema()
    {
        $schemas = [
            // 用户表
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                salt VARCHAR(32),
                role VARCHAR(20) DEFAULT 'user',
                status VARCHAR(20) DEFAULT 'active',
                last_login_at DATETIME,
                avatar VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 分类表
            "CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                description TEXT,
                icon VARCHAR(100),
                color VARCHAR(7),
                sort_order INTEGER DEFAULT 0,
                parent_id INTEGER DEFAULT 0,
                user_id INTEGER,
                is_private BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 网站表
            "CREATE TABLE websites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(200) NOT NULL,
                url VARCHAR(500) NOT NULL,
                description TEXT,
                category_id INTEGER NOT NULL,
                user_id INTEGER,
                icon VARCHAR(255),
                favicon_url VARCHAR(500),
                sort_order INTEGER DEFAULT 0,
                clicks INTEGER DEFAULT 0,
                weight INTEGER DEFAULT 0,
                status VARCHAR(20) DEFAULT 'active',
                is_private BOOLEAN DEFAULT 0,
                properties TEXT,
                last_checked_at DATETIME,
                check_status VARCHAR(20),
                response_time INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 标签表
            "CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) UNIQUE NOT NULL,
                color VARCHAR(7),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 网站标签关联表
            "CREATE TABLE website_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                website_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                UNIQUE(website_id, tag_id)
            )",

            // 邀请码表
            "CREATE TABLE invitation_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(32) UNIQUE NOT NULL,
                created_by INTEGER,
                used_by INTEGER,
                max_uses INTEGER DEFAULT 1,
                used_count INTEGER DEFAULT 0,
                expires_at DATETIME,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                used_at DATETIME
            )",

            // 站点设置表
            "CREATE TABLE site_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key VARCHAR(100) UNIQUE NOT NULL,
                value TEXT,
                type VARCHAR(20) DEFAULT 'string',
                description TEXT,
                group_name VARCHAR(50) DEFAULT 'general',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 死链检查表
            "CREATE TABLE deadlink_checks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                website_id INTEGER NOT NULL,
                check_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) NOT NULL,
                response_time INTEGER,
                http_status INTEGER,
                error_message TEXT
            )"
        ];

        foreach ($schemas as $schema) {
            $this->tempDb->exec($schema);
        }
    }

    /**
     * 测试BookNavImporter
     */
    public function testBookNavImporter()
    {
        $this->log("开始测试BookNavImporter...");

        try {
            $importer = new BookNavImporter($this->tempDb);

            // 测试获取导入器信息
            $info = $importer->getInfo();
            $this->log("导入器信息: " . $info['name'] . " v" . $info['version'], true);

            // 测试数据库验证（无效路径）
            $validation = $importer->validateSource('/invalid/path/app.db');
            $this->log("无效路径验证: " . ($validation['valid'] ? "失败" : "通过"), !$validation['valid']);

            // 创建模拟BookNav数据库
            $this->createMockBookNavDatabase();

            // 测试数据库验证（有效路径）
            $mockDbPath = dirname($this->testDbPath) . '/mock_booknav.db';
            $validation = $importer->validateSource($mockDbPath);
            $this->log("有效路径验证: " . ($validation['valid'] ? "通过" : "失败"), $validation['valid']);

            if ($validation['valid']) {
                // 测试数据统计
                $stats = $importer->getSourceStats($mockDbPath);
                $this->log("数据统计 - 用户: {$stats['users']}, 分类: {$stats['categories']}, 网站: {$stats['websites']}", true);

                // 测试预览功能
                $preview = $importer->previewImport($mockDbPath, 5);
                $this->log("预览数据 - 用户: " . count($preview['users']) . ", 分类: " . count($preview['categories']) . ", 网站: " . count($preview['websites']), true);

                // 测试导入功能
                $importResult = $importer->import($mockDbPath);
                $this->log("数据导入: " . ($importResult ? "成功" : "失败"), $importResult);

                // 验证导入结果
                $this->verifyImportedData('BookNav');

                // 标记导入完成
                $markResult = $importer->markImported();
                $this->log("标记导入完成: " . ($markResult ? "成功" : "失败"), $markResult);

                // 检查导入状态
                $hasImported = $importer->hasImported();
                $this->log("检查导入状态: " . ($hasImported ? "已导入" : "未导入"), $hasImported);
            }

        } catch (Exception $e) {
            $this->log("BookNavImporter测试失败: " . $e->getMessage(), false);
        }
    }

    /**
     * 测试OneNavImporter
     */
    public function testOneNavImporter()
    {
        $this->log("开始测试OneNavImporter...");

        try {
            $importer = new OneNavImporter($this->tempDb);

            // 测试获取导入器信息
            $info = $importer->getInfo();
            $this->log("导入器信息: " . $info['name'] . " v" . $info['version'], true);

            // 创建模拟OneNav数据库
            $this->createMockOneNavDatabase();

            // 测试数据库验证
            $mockDbPath = dirname($this->testDbPath) . '/mock_onenav.db3';
            $validation = $importer->validateSource($mockDbPath);
            $this->log("数据库验证: " . ($validation['valid'] ? "通过" : "失败"), $validation['valid']);

            if ($validation['valid']) {
                // 测试兼容性检查
                $compatibility = $importer->checkCompatibility($mockDbPath);
                $this->log("兼容性检查: " . ($compatibility['compatible'] ? "兼容" : "不兼容"), $compatibility['compatible']);

                // 测试数据统计
                $stats = $importer->getSourceStats($mockDbPath);
                $this->log("数据统计 - 分类: {$stats['categories']}, 链接: {$stats['links']}, 设置: {$stats['settings']}", true);

                // 测试结构分析
                $analysis = $importer->analyzeStructure($mockDbPath);
                $this->log("结构分析 - 表数量: " . count($analysis['tables']) . ", 分类分布: " . count($analysis['link_distribution']), true);

                // 测试预览功能
                $preview = $importer->previewImport($mockDbPath, 5);
                $this->log("预览数据 - 分类: " . count($preview['categories']) . ", 链接: " . count($preview['links']), true);

                // 测试导入功能
                $importResult = $importer->import($mockDbPath);
                $this->log("数据导入: " . ($importResult ? "成功" : "失败"), $importResult);

                // 验证导入结果
                $this->verifyImportedData('OneNav');

                // 标记导入完成
                $markResult = $importer->markImported();
                $this->log("标记导入完成: " . ($markResult ? "成功" : "失败"), $markResult);
            }

        } catch (Exception $e) {
            $this->log("OneNavImporter测试失败: " . $e->getMessage(), false);
        }
    }

    /**
     * 创建模拟BookNav数据库
     */
    private function createMockBookNavDatabase()
    {
        $mockDbPath = dirname($this->testDbPath) . '/mock_booknav.db';

        if (file_exists($mockDbPath)) {
            unlink($mockDbPath);
        }

        $mockDb = new PDO("sqlite:{$mockDbPath}");
        $mockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 创建BookNav表结构
        $mockDb->exec("CREATE TABLE user (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            salt VARCHAR(32),
            role VARCHAR(20) DEFAULT 'user',
            is_active BOOLEAN DEFAULT 1,
            last_login DATETIME,
            avatar VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $mockDb->exec("CREATE TABLE category (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(100),
            sort_order INTEGER DEFAULT 0,
            user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $mockDb->exec("CREATE TABLE website (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(200) NOT NULL,
            url VARCHAR(500) NOT NULL,
            description TEXT,
            category_id INTEGER NOT NULL,
            user_id INTEGER,
            icon VARCHAR(255),
            favicon_url VARCHAR(500),
            sort_order INTEGER DEFAULT 0,
            clicks INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            is_private BOOLEAN DEFAULT 0,
            last_checked DATETIME,
            check_status VARCHAR(20),
            response_time INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 插入测试数据
        $mockDb->exec("INSERT INTO user (username, email, password_hash, role) VALUES
            ('admin', 'admin@test.com', 'hash123', 'admin'),
            ('user1', 'user1@test.com', 'hash456', 'user')");

        $mockDb->exec("INSERT INTO category (name, description, sort_order) VALUES
            ('搜索引擎', '常用搜索引擎', 1),
            ('开发工具', '编程开发相关工具', 2),
            ('社交媒体', '社交网络平台', 3)");

        $mockDb->exec("INSERT INTO website (title, url, description, category_id, sort_order) VALUES
            ('Google', 'https://google.com', '全球最大搜索引擎', 1, 1),
            ('百度', 'https://baidu.com', '中文搜索引擎', 1, 2),
            ('GitHub', 'https://github.com', '代码托管平台', 2, 1),
            ('Stack Overflow', 'https://stackoverflow.com', '程序员问答社区', 2, 2),
            ('Twitter', 'https://twitter.com', '微博客社交网络', 3, 1)");
    }

    /**
     * 创建模拟OneNav数据库
     */
    private function createMockOneNavDatabase()
    {
        $mockDbPath = dirname($this->testDbPath) . '/mock_onenav.db3';

        if (file_exists($mockDbPath)) {
            unlink($mockDbPath);
        }

        $mockDb = new PDO("sqlite:{$mockDbPath}");
        $mockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 创建OneNav表结构
        $mockDb->exec("CREATE TABLE on_categorys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pid INTEGER DEFAULT 0,
            name VARCHAR(100) NOT NULL,
            font VARCHAR(100),
            description TEXT,
            order_list INTEGER DEFAULT 0,
            add_time INTEGER,
            up_time INTEGER
        )");

        $mockDb->exec("CREATE TABLE on_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fid INTEGER NOT NULL,
            title VARCHAR(200) NOT NULL,
            url VARCHAR(500) NOT NULL,
            description TEXT,
            icon TEXT,
            order_list INTEGER DEFAULT 0,
            click INTEGER DEFAULT 0,
            weight INTEGER DEFAULT 0,
            property VARCHAR(50),
            add_time INTEGER,
            up_time INTEGER
        )");

        $mockDb->exec("CREATE TABLE on_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name VARCHAR(100) NOT NULL,
            option_value TEXT,
            option_description TEXT
        )");

        // 插入测试数据
        $time = time();

        $mockDb->exec("INSERT INTO on_categorys (name, font, order_list, add_time) VALUES
            ('搜索引擎', 'icon-search', 1, {$time}),
            ('开发工具', 'icon-code', 2, {$time}),
            ('社交网络', 'icon-social', 3, {$time})");

        $mockDb->exec("INSERT INTO on_links (fid, title, url, description, order_list, click, weight, property, add_time) VALUES
            (1, 'Google', 'https://google.com', '全球搜索引擎', 1, 100, 5, 'normal', {$time}),
            (1, '百度', 'https://baidu.com', '中文搜索引擎', 2, 80, 4, 'normal', {$time}),
            (2, 'GitHub', 'https://github.com', '代码托管', 1, 150, 5, 'normal', {$time}),
            (2, 'VS Code', 'https://code.visualstudio.com', '代码编辑器', 2, 90, 4, 'normal', {$time}),
            (3, 'Twitter', 'https://twitter.com', '微博客', 1, 120, 4, 'normal', {$time})");

        $mockDb->exec("INSERT INTO on_options (option_name, option_value, option_description) VALUES
            ('site_name', 'OneNav 导航', '站点名称'),
            ('site_description', '个人导航网站', '站点描述'),
            ('theme', 'default', '当前主题')");
    }

    /**
     * 验证导入的数据
     */
    private function verifyImportedData($source)
    {
        try {
            // 检查用户数据
            if ($source === 'BookNav') {
                $userCount = $this->tempDb->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $this->log("导入用户数量: {$userCount}", $userCount > 0);
            }

            // 检查分类数据
            $categoryCount = $this->tempDb->query("SELECT COUNT(*) FROM categories")->fetchColumn();
            $this->log("导入分类数量: {$categoryCount}", $categoryCount > 0);

            // 检查网站数据
            $websiteCount = $this->tempDb->query("SELECT COUNT(*) FROM websites")->fetchColumn();
            $this->log("导入网站数量: {$websiteCount}", $websiteCount > 0);

            // 检查设置数据
            $settingCount = $this->tempDb->query("SELECT COUNT(*) FROM site_settings WHERE group_name LIKE '%imported%'")->fetchColumn();
            $this->log("导入设置数量: {$settingCount}", $settingCount >= 0);

            // 验证数据完整性
            $stmt = $this->tempDb->query("
                SELECT w.title, w.url, c.name as category_name
                FROM websites w
                LEFT JOIN categories c ON w.category_id = c.id
                LIMIT 3
            ");
            $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($samples as $sample) {
                $this->log("样本数据: {$sample['title']} -> {$sample['category_name']}", true);
            }

        } catch (Exception $e) {
            $this->log("验证导入数据失败: " . $e->getMessage(), false);
        }
    }

    /**
     * 运行所有测试
     */
    public function runAllTests()
    {
        $this->log("开始运行OneBookNav数据迁移测试...");
        $this->log("=" . str_repeat("=", 50));

        $this->testBookNavImporter();
        $this->log("-" . str_repeat("-", 50));

        $this->testOneNavImporter();
        $this->log("=" . str_repeat("=", 50));

        $this->printSummary();
        $this->cleanup();
    }

    /**
     * 记录测试结果
     */
    private function log($message, $success = null)
    {
        $timestamp = date('Y-m-d H:i:s');
        $status = '';

        if ($success === true) {
            $status = ' [✓]';
            $this->testResults['passed']++;
        } elseif ($success === false) {
            $status = ' [✗]';
            $this->testResults['failed']++;
        } else {
            $this->testResults['info']++;
        }

        echo "[{$timestamp}] {$message}{$status}\n";
    }

    /**
     * 打印测试摘要
     */
    private function printSummary()
    {
        $total = ($this->testResults['passed'] ?? 0) + ($this->testResults['failed'] ?? 0);
        $passed = $this->testResults['passed'] ?? 0;
        $failed = $this->testResults['failed'] ?? 0;
        $info = $this->testResults['info'] ?? 0;

        $this->log("测试摘要:");
        $this->log("总计测试: {$total}");
        $this->log("通过: {$passed}");
        $this->log("失败: {$failed}");
        $this->log("信息: {$info}");
        $this->log("成功率: " . ($total > 0 ? round(($passed / $total) * 100, 2) : 0) . "%");
    }

    /**
     * 清理测试文件
     */
    private function cleanup()
    {
        $filesToClean = [
            $this->testDbPath,
            dirname($this->testDbPath) . '/mock_booknav.db',
            dirname($this->testDbPath) . '/mock_onenav.db3'
        ];

        foreach ($filesToClean as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->log("测试文件清理完成");
    }
}

// 运行测试
try {
    $tester = new MigrationTester();
    $tester->runAllTests();
} catch (Exception $e) {
    echo "测试执行失败: " . $e->getMessage() . "\n";
    exit(1);
}