<?php
/**
 * OneBookNav - 数据库迁移管理器
 *
 * 负责执行和管理数据库迁移
 */

class DatabaseMigrator
{
    private $db;
    private $migrationsPath;
    private $config;

    public function __construct($db, $config = [])
    {
        $this->db = $db;
        $this->migrationsPath = __DIR__ . '/migrations';
        $this->config = array_merge([
            'table' => 'migrations',
            'backup_before_migration' => true,
            'verify_after_migration' => true,
        ], $config);

        $this->ensureMigrationsTable();
    }

    /**
     * 执行所有待执行的迁移
     */
    public function migrate()
    {
        $pendingMigrations = $this->getPendingMigrations();

        if (empty($pendingMigrations)) {
            echo "No pending migrations.\n";
            return true;
        }

        echo "Found " . count($pendingMigrations) . " pending migrations:\n";

        foreach ($pendingMigrations as $migration) {
            echo "Executing migration: {$migration}\n";

            if ($this->config['backup_before_migration']) {
                $this->createBackup($migration);
            }

            try {
                $this->executeMigration($migration, 'up');
                $this->recordMigration($migration);
                echo "✓ Migration {$migration} executed successfully\n";
            } catch (Exception $e) {
                echo "✗ Migration {$migration} failed: " . $e->getMessage() . "\n";
                throw $e;
            }
        }

        if ($this->config['verify_after_migration']) {
            $this->verifyDatabase();
        }

        echo "All migrations completed successfully!\n";
        return true;
    }

    /**
     * 回滚指定数量的迁移
     */
    public function rollback($steps = 1)
    {
        $executedMigrations = $this->getExecutedMigrations($steps);

        if (empty($executedMigrations)) {
            echo "No migrations to rollback.\n";
            return true;
        }

        echo "Rolling back " . count($executedMigrations) . " migrations:\n";

        foreach (array_reverse($executedMigrations) as $migration) {
            echo "Rolling back migration: {$migration['migration']}\n";

            try {
                $this->executeMigration($migration['migration'], 'down');
                $this->removeMigrationRecord($migration['migration']);
                echo "✓ Migration {$migration['migration']} rolled back successfully\n";
            } catch (Exception $e) {
                echo "✗ Rollback {$migration['migration']} failed: " . $e->getMessage() . "\n";
                throw $e;
            }
        }

        echo "Rollback completed successfully!\n";
        return true;
    }

    /**
     * 重置数据库（执行所有迁移的 down 方法）
     */
    public function reset()
    {
        $executedMigrations = $this->getExecutedMigrations();

        echo "Resetting database...\n";

        foreach (array_reverse($executedMigrations) as $migration) {
            $this->executeMigration($migration['migration'], 'down');
            $this->removeMigrationRecord($migration['migration']);
        }

        echo "Database reset completed!\n";
        return true;
    }

    /**
     * 刷新数据库（重置后重新迁移）
     */
    public function refresh()
    {
        $this->reset();
        $this->migrate();
        return true;
    }

    /**
     * 获取迁移状态
     */
    public function status()
    {
        $allMigrations = $this->getAllMigrationFiles();
        $executedMigrations = $this->getExecutedMigrations();
        $executedList = array_column($executedMigrations, 'migration');

        echo "Migration Status:\n";
        echo "================\n";

        foreach ($allMigrations as $migration) {
            $status = in_array($migration, $executedList) ? '✓ Executed' : '✗ Pending';
            echo "{$status} - {$migration}\n";
        }

        $pendingCount = count($allMigrations) - count($executedList);
        echo "\nTotal: " . count($allMigrations) . " migrations\n";
        echo "Executed: " . count($executedList) . " migrations\n";
        echo "Pending: {$pendingCount} migrations\n";

        return true;
    }

    /**
     * 创建新的迁移文件
     */
    public function createMigration($name, $template = 'basic')
    {
        $timestamp = date('Y_m_d_His');
        $className = 'Migration_' . $timestamp . '_' . studly_case($name);
        $filename = sprintf("%03d_%s.php", $this->getNextMigrationNumber(), snake_case($name));
        $filepath = $this->migrationsPath . '/' . $filename;

        $content = $this->getMigrationTemplate($className, $template);

        file_put_contents($filepath, $content);

        echo "Migration created: {$filename}\n";
        echo "Class: {$className}\n";

        return $filepath;
    }

    private function ensureMigrationsTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->config['table']} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ";

        $this->db->exec($sql);
    }

    private function getPendingMigrations()
    {
        $allMigrations = $this->getAllMigrationFiles();
        $executedMigrations = $this->getExecutedMigrations();
        $executedList = array_column($executedMigrations, 'migration');

        return array_diff($allMigrations, $executedList);
    }

    private function getAllMigrationFiles()
    {
        $files = glob($this->migrationsPath . '/*.php');
        $migrations = [];

        foreach ($files as $file) {
            $migrations[] = basename($file, '.php');
        }

        sort($migrations);
        return $migrations;
    }

    private function getExecutedMigrations($limit = null)
    {
        $sql = "SELECT migration, batch, executed_at FROM {$this->config['table']} ORDER BY id ASC";
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function executeMigration($migration, $direction)
    {
        $filepath = $this->migrationsPath . '/' . $migration . '.php';

        if (!file_exists($filepath)) {
            throw new Exception("Migration file not found: {$filepath}");
        }

        require_once $filepath;

        $className = $this->getMigrationClassName($migration);

        if (!class_exists($className)) {
            throw new Exception("Migration class not found: {$className}");
        }

        $instance = new $className();

        if (!method_exists($instance, $direction)) {
            throw new Exception("Method {$direction} not found in {$className}");
        }

        // 开始事务
        $this->db->beginTransaction();

        try {
            $result = $instance->$direction($this->db);

            if ($result === false) {
                throw new Exception("Migration {$direction} method returned false");
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function recordMigration($migration)
    {
        $batch = $this->getNextBatch();
        $stmt = $this->db->prepare("INSERT INTO {$this->config['table']} (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);
    }

    private function removeMigrationRecord($migration)
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->config['table']} WHERE migration = ?");
        $stmt->execute([$migration]);
    }

    private function getNextBatch()
    {
        $stmt = $this->db->query("SELECT MAX(batch) as max_batch FROM {$this->config['table']}");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result['max_batch'] ?? 0) + 1;
    }

    private function getNextMigrationNumber()
    {
        $files = $this->getAllMigrationFiles();
        $maxNumber = 0;

        foreach ($files as $file) {
            if (preg_match('/^(\d+)_/', $file, $matches)) {
                $maxNumber = max($maxNumber, (int)$matches[1]);
            }
        }

        return $maxNumber + 1;
    }

    private function getMigrationClassName($migration)
    {
        // 从文件名生成类名
        if (preg_match('/^(\d+)_(.+)$/', $migration, $matches)) {
            return 'Migration_' . $matches[1] . '_' . studly_case($matches[2]);
        }

        return 'Migration_' . studly_case($migration);
    }

    private function createBackup($migration)
    {
        $backupDir = __DIR__ . '/../backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = "{$backupDir}/backup_before_{$migration}_{$timestamp}.sql";

        // SQLite 备份
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->createSqliteBackup($backupFile);
        }
    }

    private function createSqliteBackup($backupFile)
    {
        $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $backup = "-- OneBookNav Database Backup\n";
        $backup .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";

        foreach ($tables as $table) {
            $backup .= "-- Table: {$table}\n";
            $backup .= "DROP TABLE IF EXISTS {$table};\n";

            // 获取表结构
            $stmt = $this->db->query("SELECT sql FROM sqlite_master WHERE name = '{$table}'");
            $createSql = $stmt->fetchColumn();
            $backup .= $createSql . ";\n\n";

            // 获取数据
            $stmt = $this->db->query("SELECT * FROM {$table}");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $backup .= "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES\n";

                $values = [];
                foreach ($rows as $row) {
                    $rowValues = array_map(function($value) {
                        return $this->db->quote($value);
                    }, array_values($row));
                    $values[] = "(" . implode(', ', $rowValues) . ")";
                }

                $backup .= implode(",\n", $values) . ";\n\n";
            }
        }

        file_put_contents($backupFile, $backup);
    }

    private function verifyDatabase()
    {
        try {
            // 验证数据库完整性
            $stmt = $this->db->query("PRAGMA integrity_check");
            $result = $stmt->fetchColumn();

            if ($result !== 'ok') {
                throw new Exception("Database integrity check failed: {$result}");
            }

            echo "✓ Database integrity verified\n";
        } catch (Exception $e) {
            echo "⚠ Database verification warning: " . $e->getMessage() . "\n";
        }
    }

    private function getMigrationTemplate($className, $template)
    {
        $templates = [
            'basic' => "<?php\n\nclass {$className}\n{\n    public function up(\$db)\n    {\n        // Add migration code here\n        return true;\n    }\n\n    public function down(\$db)\n    {\n        // Add rollback code here\n        return true;\n    }\n\n    public function getDescription()\n    {\n        return 'Migration description';\n    }\n\n    public function getVersion()\n    {\n        return '1.0.0';\n    }\n}",

            'table' => "<?php\n\nclass {$className}\n{\n    public function up(\$db)\n    {\n        \$sql = \"\n            CREATE TABLE IF NOT EXISTS example_table (\n                id INTEGER PRIMARY KEY AUTOINCREMENT,\n                name VARCHAR(255) NOT NULL,\n                created_at DATETIME DEFAULT CURRENT_TIMESTAMP\n            )\n        \";\n        \$db->exec(\$sql);\n        return true;\n    }\n\n    public function down(\$db)\n    {\n        \$db->exec('DROP TABLE IF EXISTS example_table');\n        return true;\n    }\n\n    public function getDescription()\n    {\n        return 'Create example table';\n    }\n\n    public function getVersion()\n    {\n        return '1.0.0';\n    }\n}"
        ];

        return $templates[$template] ?? $templates['basic'];
    }
}

// 辅助函数
function snake_case($string)
{
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
}

function studly_case($string)
{
    return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $string)));
}