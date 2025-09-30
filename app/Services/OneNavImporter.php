<?php
/**
 * OneBookNav - OneNav 数据导入服务
 *
 * 提供从 OneNav 项目导入数据到 OneBookNav 的统一接口
 * 符合终极.txt要求的命名规范
 */

class OneNavImporter
{
    private $sourceDb;
    private $targetDb;
    private $migrationInstance;

    private $tableMapping = [
        'on_categorys' => 'categories',
        'on_links' => 'websites',
        'on_options' => 'site_settings'
    ];

    public function __construct($database = null)
    {
        $this->targetDb = $database;

        // 加载现有的迁移实现
        require_once __DIR__ . '/../../database/migrations/003_onenav_migration.php';
        $this->migrationInstance = new Migration_003_OnenavMigration();
    }

    /**
     * 执行OneNav数据导入
     */
    public function import($onenavDbPath = null)
    {
        try {
            if ($onenavDbPath) {
                // 如果指定了数据库路径，使用指定路径
                $_ENV['ONENAV_DB_PATH'] = $onenavDbPath;
            }

            // 调用现有的迁移逻辑
            return $this->migrationInstance->up($this->targetDb);

        } catch (Exception $e) {
            error_log("OneNavImporter failed: " . $e->getMessage());
            throw new Exception("OneNav数据导入失败: " . $e->getMessage());
        }
    }

    /**
     * 验证OneNav数据库是否可访问
     */
    public function validateSource($onenavDbPath)
    {
        if (!file_exists($onenavDbPath)) {
            return [
                'valid' => false,
                'error' => 'OneNav数据库文件不存在'
            ];
        }

        try {
            $testDb = new PDO("sqlite:{$onenavDbPath}");
            $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 检查必要的表是否存在
            $requiredTables = ['on_categorys', 'on_links'];
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
                'message' => 'OneNav数据库验证成功'
            ];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'OneNav数据库连接失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 获取OneNav数据统计信息
     */
    public function getSourceStats($onenavDbPath)
    {
        try {
            $sourceDb = new PDO("sqlite:{$onenavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stats = [];

            // 统计分类数量
            $stmt = $sourceDb->query("SELECT COUNT(*) FROM on_categorys");
            $stats['categories'] = $stmt->fetchColumn();

            // 统计链接数量
            $stmt = $sourceDb->query("SELECT COUNT(*) FROM on_links");
            $stats['links'] = $stats['websites'] = $stmt->fetchColumn();

            // 统计设置数量
            try {
                $stmt = $sourceDb->query("SELECT COUNT(*) FROM on_options");
                $stats['options'] = $stats['settings'] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['options'] = $stats['settings'] = 0;
            }

            // 统计不同状态的链接
            try {
                $stmt = $sourceDb->query("
                    SELECT property, COUNT(*) as count
                    FROM on_links
                    GROUP BY property
                ");
                $stats['link_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {
                $stats['link_status'] = [];
            }

            return $stats;

        } catch (Exception $e) {
            throw new Exception("获取OneNav数据统计失败: " . $e->getMessage());
        }
    }

    /**
     * 预览导入数据
     */
    public function previewImport($onenavDbPath, $limit = 10)
    {
        try {
            $sourceDb = new PDO("sqlite:{$onenavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $preview = [];

            // 预览分类
            $stmt = $sourceDb->query("
                SELECT name, font as icon, order_list as sort_order
                FROM on_categorys
                ORDER BY order_list
                LIMIT {$limit}
            ");
            $preview['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 预览链接
            $stmt = $sourceDb->query("
                SELECT l.title, l.url, l.description, c.name as category_name,
                       l.click as clicks, l.weight, l.property as status
                FROM on_links l
                LEFT JOIN on_categorys c ON l.fid = c.id
                ORDER BY l.order_list
                LIMIT {$limit}
            ");
            $preview['links'] = $preview['websites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 预览设置
            try {
                $stmt = $sourceDb->query("
                    SELECT option_name as name, option_value as value
                    FROM on_options
                    LIMIT {$limit}
                ");
                $preview['options'] = $preview['settings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $preview['options'] = $preview['settings'] = [];
            }

            return $preview;

        } catch (Exception $e) {
            throw new Exception("预览OneNav数据失败: " . $e->getMessage());
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
            error_log("OneNavImporter rollback failed: " . $e->getMessage());
            throw new Exception("OneNav数据回滚失败: " . $e->getMessage());
        }
    }

    /**
     * 获取导入器信息
     */
    public function getInfo()
    {
        return [
            'name' => 'OneNavImporter',
            'description' => '从OneNav项目导入数据到OneBookNav',
            'version' => '1.0.0',
            'compatible_versions' => ['OneNav 1.x', 'OneNav 2.x'],
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
     * 检查是否已经导入过OneNav数据
     */
    public function hasImported()
    {
        try {
            // 检查是否存在OneNav导入的标记
            $stmt = $this->targetDb->query("
                SELECT COUNT(*) FROM site_settings
                WHERE key = 'onenav_imported' AND value = '1'
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
                'onenav_imported',
                '1',
                'boolean',
                'OneNav数据导入标记',
                'import_status'
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Failed to mark OneNav import: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 分析OneNav数据结构
     */
    public function analyzeStructure($onenavDbPath)
    {
        try {
            $sourceDb = new PDO("sqlite:{$onenavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $analysis = [];

            // 分析表结构
            $stmt = $sourceDb->query("SELECT name FROM sqlite_master WHERE type='table'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $analysis['tables'] = $tables;

            // 分析分类层级结构
            $stmt = $sourceDb->query("
                SELECT id, name, pid, order_list
                FROM on_categorys
                ORDER BY order_list
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $analysis['category_hierarchy'] = $this->buildCategoryTree($categories);

            // 分析链接分布
            $stmt = $sourceDb->query("
                SELECT c.name as category, COUNT(l.id) as link_count
                FROM on_categorys c
                LEFT JOIN on_links l ON c.id = l.fid
                GROUP BY c.id, c.name
                ORDER BY link_count DESC
            ");
            $analysis['link_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $analysis;

        } catch (Exception $e) {
            throw new Exception("分析OneNav数据结构失败: " . $e->getMessage());
        }
    }

    /**
     * 构建分类树形结构
     */
    private function buildCategoryTree($categories, $parentId = 0)
    {
        $tree = [];

        foreach ($categories as $category) {
            if ($category['pid'] == $parentId) {
                $children = $this->buildCategoryTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['children'] = $children;
                }
                $tree[] = $category;
            }
        }

        return $tree;
    }

    /**
     * 检查数据兼容性
     */
    public function checkCompatibility($onenavDbPath)
    {
        $validation = $this->validateSource($onenavDbPath);
        if (!$validation['valid']) {
            return $validation;
        }

        try {
            $sourceDb = new PDO("sqlite:{$onenavDbPath}");
            $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $compatibility = ['compatible' => true, 'issues' => [], 'warnings' => []];

            // 检查字段兼容性
            $stmt = $sourceDb->query("PRAGMA table_info(on_links)");
            $linkFields = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);

            $requiredFields = ['title', 'url', 'fid'];
            foreach ($requiredFields as $field) {
                if (!in_array($field, $linkFields)) {
                    $compatibility['issues'][] = "缺少必要字段: on_links.{$field}";
                    $compatibility['compatible'] = false;
                }
            }

            // 检查URL格式
            $stmt = $sourceDb->query("SELECT COUNT(*) FROM on_links WHERE url NOT LIKE 'http%'");
            $invalidUrls = $stmt->fetchColumn();
            if ($invalidUrls > 0) {
                $compatibility['warnings'][] = "发现 {$invalidUrls} 个可能无效的URL格式";
            }

            return $compatibility;

        } catch (Exception $e) {
            return [
                'compatible' => false,
                'error' => '兼容性检查失败: ' . $e->getMessage()
            ];
        }
    }
}