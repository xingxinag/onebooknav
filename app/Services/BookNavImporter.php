<?php
/**
 * OneBookNav - BookNav 数据导入服务
 *
 * 提供从 BookNav 项目导入数据到 OneBookNav 的统一接口
 * 符合终极.txt要求的命名规范
 */

class BookNavImporter
{
    private $sourceDb;
    private $targetDb;
    private $migrationInstance;

    private $tableMapping = [
        'user' => 'users',
        'category' => 'categories',
        'website' => 'websites',
        'website_tag' => 'website_tags',
        'invitation_code' => 'invitation_codes',
        'site_settings' => 'site_settings',
        'deadlink_check' => 'deadlink_checks'
    ];

    public function __construct($database = null)
    {
        $this->targetDb = $database;

        // 加载现有的迁移实现
        require_once __DIR__ . '/../../database/migrations/002_booknav_migration.php';
        $this->migrationInstance = new Migration_002_BooknavMigration();
    }

    /**
     * 执行BookNav数据导入
     */
    public function import($booknavDbPath = null)
    {
        try {
            if ($booknavDbPath) {
                // 如果指定了数据库路径，使用指定路径
                $_ENV['BOOKNAV_DB_PATH'] = $booknavDbPath;
            }

            // 调用现有的迁移逻辑
            return $this->migrationInstance->up($this->targetDb);

        } catch (Exception $e) {
            error_log("BookNavImporter failed: " . $e->getMessage());
            throw new Exception("BookNav数据导入失败: " . $e->getMessage());
        }
    }

    /**
     * 验证BookNav数据库是否可访问
     */
    public function validateSource($booknavDbPath)
    {
        if (!file_exists($booknavDbPath)) {
            return [
                'valid' => false,
                'error' => 'BookNav数据库文件不存在'
            ];
        }

        try {
            $testDb = new PDO("sqlite:{$booknavDbPath}");
            $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 检查必要的表是否存在
            $requiredTables = ['user', 'category', 'website'];
            foreach ($requiredTables as $table) {
                $stmt = $testDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
                if (!$stmt->fetchColumn()) {
                    return [
                        'valid' => false,
                        'error' => "缺少必要的表: {$table}"
                    ];
                }
            }

            return [
                'valid' => true,
                'message' => 'BookNav数据库验证成功'
            ];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'BookNav数据库连接失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 获取BookNav数据统计信息
     */
    public function getSourceStats($booknavDbPath)
    {
        try {
            $sourceDb = new PDO("sqlite:{$booknavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stats = [];

            // 统计用户数量
            $stmt = $sourceDb->query("SELECT COUNT(*) FROM user");
            $stats['users'] = $stmt->fetchColumn();

            // 统计分类数量
            $stmt = $sourceDb->query("SELECT COUNT(*) FROM category");
            $stats['categories'] = $stmt->fetchColumn();

            // 统计网站数量
            $stmt = $sourceDb->query("SELECT COUNT(*) FROM website");
            $stats['websites'] = $stmt->fetchColumn();

            // 统计邀请码数量（如果表存在）
            try {
                $stmt = $sourceDb->query("SELECT COUNT(*) FROM invitation_code");
                $stats['invitation_codes'] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['invitation_codes'] = 0;
            }

            return $stats;

        } catch (Exception $e) {
            throw new Exception("获取BookNav数据统计失败: " . $e->getMessage());
        }
    }

    /**
     * 预览导入数据
     */
    public function previewImport($booknavDbPath, $limit = 10)
    {
        try {
            $sourceDb = new PDO("sqlite:{$booknavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $preview = [];

            // 预览用户
            $stmt = $sourceDb->query("SELECT username, email, role FROM user LIMIT {$limit}");
            $preview['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 预览分类
            $stmt = $sourceDb->query("SELECT name, description FROM category ORDER BY sort_order LIMIT {$limit}");
            $preview['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 预览网站
            $stmt = $sourceDb->query("
                SELECT w.title, w.url, w.description, c.name as category_name
                FROM website w
                LEFT JOIN category c ON w.category_id = c.id
                ORDER BY w.sort_order
                LIMIT {$limit}
            ");
            $preview['websites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $preview;

        } catch (Exception $e) {
            throw new Exception("预览BookNav数据失败: " . $e->getMessage());
        }
    }

    /**
     * 回滚导入的数据
     */
    public function rollback()
    {
        try {
            return $this->migrationInstance->down($this->targetDb);
        } catch (Exception $e) {
            error_log("BookNavImporter rollback failed: " . $e->getMessage());
            throw new Exception("BookNav数据回滚失败: " . $e->getMessage());
        }
    }

    /**
     * 获取导入器信息
     */
    public function getInfo()
    {
        return [
            'name' => 'BookNavImporter',
            'description' => '从BookNav项目导入数据到OneBookNav',
            'version' => '1.0.0',
            'compatible_versions' => ['BookNav 1.x', 'BookNav 2.x'],
            'required_tables' => array_keys($this->tableMapping),
            'table_mapping' => $this->tableMapping
        ];
    }

    /**
     * 设置目标数据库
     */
    public function setTargetDatabase($database)
    {
        $this->targetDb = $database;
        return $this;
    }

    /**
     * 检查是否已经导入过BookNav数据
     */
    public function hasImported()
    {
        try {
            // 检查是否存在BookNav导入的标记
            $stmt = $this->targetDb->query("
                SELECT COUNT(*) FROM site_settings
                WHERE key = 'booknav_imported' AND value = '1'
            ");

            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 标记导入完成
     */
    public function markImported()
    {
        try {
            $stmt = $this->targetDb->prepare("
                INSERT OR REPLACE INTO site_settings (key, value, type, description, group_name)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                'booknav_imported',
                '1',
                'boolean',
                'BookNav数据导入标记',
                'import_status'
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Failed to mark BookNav import: " . $e->getMessage());
            return false;
        }
    }
}