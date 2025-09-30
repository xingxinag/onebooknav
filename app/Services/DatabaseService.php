<?php

namespace App\Services;

use PDO;
use PDOException;
use Exception;

/**
 * 数据库服务类
 *
 * 实现"统一核心，多态适配"的数据库管理
 * 支持 SQLite、MySQL、PostgreSQL 等多种数据库
 */
class DatabaseService
{
    private static $instance = null;
    private PDO $connection;
    private array $config;
    private string $driver;
    private bool $transactionActive = false;
    private array $queryLog = [];
    private bool $logQueries = false;

    private function __construct()
    {
        $this->config = $this->loadConfig();
        $this->driver = $this->config['driver'] ?? 'sqlite';
        $this->logQueries = $this->config['log_queries'] ?? false;
        $this->connect();
        $this->initializeDatabase();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 加载数据库配置
     */
    private function loadConfig(): array
    {
        $defaultConfig = [
            'driver' => 'sqlite',
            'database' => DATA_PATH . '/database.db',
            'host' => 'localhost',
            'port' => '3306',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        ];

        // 从环境变量或配置文件加载配置
        $config = $defaultConfig;

        // 环境变量覆盖
        if ($databaseUrl = getenv('DATABASE_URL')) {
            $parsed = parse_url($databaseUrl);
            $config = array_merge($config, [
                'driver' => $parsed['scheme'] === 'mysql' ? 'mysql' : $parsed['scheme'],
                'host' => $parsed['host'] ?? 'localhost',
                'port' => $parsed['port'] ?? '3306',
                'database' => ltrim($parsed['path'] ?? '', '/'),
                'username' => $parsed['user'] ?? '',
                'password' => $parsed['pass'] ?? '',
            ]);
        } else {
            // 单独的环境变量
            $config['driver'] = getenv('DB_DRIVER') ?: $config['driver'];
            $config['host'] = getenv('DB_HOST') ?: $config['host'];
            $config['port'] = getenv('DB_PORT') ?: $config['port'];
            $config['database'] = getenv('DB_DATABASE') ?: $config['database'];
            $config['username'] = getenv('DB_USERNAME') ?: $config['username'];
            $config['password'] = getenv('DB_PASSWORD') ?: $config['password'];
        }

        return $config;
    }

    /**
     * 建立数据库连接
     */
    private function connect(): void
    {
        try {
            $dsn = $this->buildDsn();
            $options = $this->config['options'];

            $this->connection = new PDO(
                $dsn,
                $this->config['username'] ?? null,
                $this->config['password'] ?? null,
                $options
            );

            // SQLite 特定配置
            if ($this->driver === 'sqlite') {
                $this->connection->exec('PRAGMA foreign_keys = ON');
                $this->connection->exec('PRAGMA journal_mode = WAL');
                $this->connection->exec('PRAGMA synchronous = NORMAL');
                $this->connection->exec('PRAGMA cache_size = 64000');
                $this->connection->exec('PRAGMA temp_store = MEMORY');
                $this->connection->exec('PRAGMA auto_vacuum = INCREMENTAL');
            }

            // MySQL 特定配置
            if ($this->driver === 'mysql') {
                $this->connection->exec("SET NAMES {$this->config['charset']} COLLATE {$this->config['collation']}");
                $this->connection->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            }

        } catch (PDOException $e) {
            throw new Exception("数据库连接失败: " . $e->getMessage());
        }
    }

    /**
     * 构建 DSN 字符串
     */
    private function buildDsn(): string
    {
        switch ($this->driver) {
            case 'sqlite':
                // 确保数据库目录存在
                $dbPath = $this->config['database'];
                $dbDir = dirname($dbPath);
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                return "sqlite:{$dbPath}";

            case 'mysql':
                $host = $this->config['host'];
                $port = $this->config['port'];
                $database = $this->config['database'];
                $charset = $this->config['charset'];
                return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

            case 'pgsql':
                $host = $this->config['host'];
                $port = $this->config['port'];
                $database = $this->config['database'];
                return "pgsql:host={$host};port={$port};dbname={$database}";

            default:
                throw new Exception("不支持的数据库驱动: {$this->driver}");
        }
    }

    /**
     * 初始化数据库（创建表结构）
     */
    private function initializeDatabase(): void
    {
        $schemaFile = ROOT_PATH . '/database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            if ($sql) {
                try {
                    // 分割并执行 SQL 语句
                    $statements = $this->splitSqlStatements($sql);
                    foreach ($statements as $statement) {
                        $statement = trim($statement);
                        if (!empty($statement) && !$this->isComment($statement)) {
                            $this->connection->exec($statement);
                        }
                    }
                } catch (PDOException $e) {
                    error_log("数据库初始化失败: " . $e->getMessage());
                    // 不抛出异常，允许应用继续运行
                }
            }
        }
    }

    /**
     * 分割 SQL 语句
     */
    private function splitSqlStatements(string $sql): array
    {
        // 移除注释
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // 按分号分割，但要考虑字符串中的分号
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];

            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                $inString = false;
            } elseif (!$inString && $char === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (!empty(trim($current))) {
            $statements[] = trim($current);
        }

        return array_filter($statements);
    }

    /**
     * 检查是否为注释行
     */
    private function isComment(string $line): bool
    {
        $line = trim($line);
        return empty($line) ||
               strpos($line, '--') === 0 ||
               strpos($line, '/*') === 0 ||
               strpos($line, 'PRAGMA') === 0;
    }

    /**
     * 获取 PDO 连接
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * 执行查询
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $startTime = microtime(true);

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            // 记录查询日志
            if ($this->logQueries) {
                $this->logQuery($sql, $params, microtime(true) - $startTime);
            }

            return $stmt;
        } catch (PDOException $e) {
            $this->logError($sql, $params, $e);
            throw new Exception("查询执行失败: " . $e->getMessage());
        }
    }

    /**
     * 执行插入操作
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":{$col}", $columns);

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $this->query($sql, $data);
        return (int)$this->connection->lastInsertId();
    }

    /**
     * 执行更新操作
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = array_map(fn($col) => "{$col} = :{$col}", array_keys($data));
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$where}";

        $params = array_merge($data, $whereParams);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * 执行删除操作
     */
    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $whereParams);
        return $stmt->rowCount();
    }

    /**
     * 查找单条记录
     */
    public function find(string $table, $id, string $column = 'id'): ?array
    {
        $sql = "SELECT * FROM {$table} WHERE {$column} = :{$column} LIMIT 1";
        $stmt = $this->query($sql, [$column => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * 查找所有记录
     */
    public function findAll(string $table, string $where = '', array $params = [], string $orderBy = '', int $limit = 0): array
    {
        $sql = "SELECT * FROM {$table}";

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        if (!empty($orderBy)) {
            $sql .= " ORDER BY {$orderBy}";
        }

        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * 分页查询
     */
    public function paginate(string $table, int $page = 1, int $perPage = 20, string $where = '', array $params = [], string $orderBy = ''): array
    {
        $offset = ($page - 1) * $perPage;

        // 计算总数
        $countSql = "SELECT COUNT(*) as total FROM {$table}";
        if (!empty($where)) {
            $countSql .= " WHERE {$where}";
        }
        $countStmt = $this->query($countSql, $params);
        $total = (int)$countStmt->fetch()['total'];

        // 获取数据
        $sql = "SELECT * FROM {$table}";
        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }
        if (!empty($orderBy)) {
            $sql .= " ORDER BY {$orderBy}";
        }
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->query($sql, $params);
        $data = $stmt->fetchAll();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'has_next' => $page * $perPage < $total,
            'has_prev' => $page > 1
        ];
    }

    /**
     * 开始事务
     */
    public function beginTransaction(): bool
    {
        if (!$this->transactionActive) {
            $result = $this->connection->beginTransaction();
            $this->transactionActive = $result;
            return $result;
        }
        return false;
    }

    /**
     * 提交事务
     */
    public function commit(): bool
    {
        if ($this->transactionActive) {
            $result = $this->connection->commit();
            $this->transactionActive = false;
            return $result;
        }
        return false;
    }

    /**
     * 回滚事务
     */
    public function rollback(): bool
    {
        if ($this->transactionActive) {
            $result = $this->connection->rollback();
            $this->transactionActive = false;
            return $result;
        }
        return false;
    }

    /**
     * 执行事务
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 检查表是否存在
     */
    public function tableExists(string $table): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name = ?";
            } else {
                $sql = "SHOW TABLES LIKE ?";
            }

            $stmt = $this->query($sql, [$table]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取表结构
     */
    public function getTableSchema(string $table): array
    {
        try {
            if ($this->driver === 'sqlite') {
                $sql = "PRAGMA table_info({$table})";
            } elseif ($this->driver === 'mysql') {
                $sql = "DESCRIBE {$table}";
            } else {
                throw new Exception("不支持的数据库驱动");
            }

            $stmt = $this->query($sql);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 记录查询日志
     */
    private function logQuery(string $sql, array $params, float $executionTime): void
    {
        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'time' => $executionTime,
            'timestamp' => time()
        ];

        // 记录慢查询
        if ($executionTime > 1.0) { // 超过1秒的查询
            $this->logSlowQuery($sql, $params, $executionTime);
        }

        // 限制日志数量
        if (count($this->queryLog) > 100) {
            array_shift($this->queryLog);
        }
    }

    /**
     * 记录慢查询
     */
    private function logSlowQuery(string $sql, array $params, float $executionTime): void
    {
        $logEntry = [
            'query_hash' => md5($sql),
            'query_sql' => $sql,
            'execution_time' => $executionTime,
            'params' => json_encode($params),
            'created_at' => date('Y-m-d H:i:s')
        ];

        try {
            if ($this->tableExists('slow_query_log')) {
                $this->insert('slow_query_log', $logEntry);
            }
        } catch (Exception $e) {
            error_log("记录慢查询失败: " . $e->getMessage());
        }
    }

    /**
     * 记录错误日志
     */
    private function logError(string $sql, array $params, Exception $e): void
    {
        $error = [
            'sql' => $sql,
            'params' => $params,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        error_log("数据库错误: " . json_encode($error, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取查询日志
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * 清空查询日志
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * 数据库优化
     */
    public function optimize(): void
    {
        try {
            if ($this->driver === 'sqlite') {
                $this->connection->exec('PRAGMA optimize');
                $this->connection->exec('PRAGMA incremental_vacuum');
                $this->connection->exec('ANALYZE');
            } elseif ($this->driver === 'mysql') {
                // MySQL 优化表
                $tables = $this->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $this->connection->exec("OPTIMIZE TABLE {$table}");
                }
            }
        } catch (Exception $e) {
            error_log("数据库优化失败: " . $e->getMessage());
        }
    }

    /**
     * 备份数据库
     */
    public function backup(string $backupPath): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                $dbPath = $this->config['database'];
                return copy($dbPath, $backupPath);
            } else {
                // 对于其他数据库，需要使用 mysqldump 等工具
                throw new Exception("暂不支持 {$this->driver} 数据库的备份");
            }
        } catch (Exception $e) {
            error_log("数据库备份失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 恢复数据库
     */
    public function restore(string $backupPath): bool
    {
        try {
            if ($this->driver === 'sqlite' && file_exists($backupPath)) {
                $dbPath = $this->config['database'];
                $this->connection = null; // 关闭连接
                $result = copy($backupPath, $dbPath);
                $this->connect(); // 重新连接
                return $result;
            } else {
                throw new Exception("暂不支持 {$this->driver} 数据库的恢复");
            }
        } catch (Exception $e) {
            error_log("数据库恢复失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取数据库大小
     */
    public function getDatabaseSize(): int
    {
        try {
            if ($this->driver === 'sqlite') {
                $dbPath = $this->config['database'];
                return file_exists($dbPath) ? filesize($dbPath) : 0;
            } elseif ($this->driver === 'mysql') {
                $sql = "SELECT SUM(data_length + index_length) as size
                        FROM information_schema.tables
                        WHERE table_schema = ?";
                $stmt = $this->query($sql, [$this->config['database']]);
                $result = $stmt->fetch();
                return (int)($result['size'] ?? 0);
            }
            return 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * 获取数据库统计信息
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'driver' => $this->driver,
                'size' => $this->getDatabaseSize(),
                'tables' => 0,
                'records' => 0
            ];

            // 获取表数量和记录数
            if ($this->driver === 'sqlite') {
                $tablesResult = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $tables = $tablesResult->fetchAll(PDO::FETCH_COLUMN);
                $stats['tables'] = count($tables);

                foreach ($tables as $table) {
                    $countResult = $this->query("SELECT COUNT(*) as count FROM {$table}");
                    $count = $countResult->fetch();
                    $stats['records'] += (int)$count['count'];
                }
            }

            return $stats;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 关闭数据库连接
     */
    public function close(): void
    {
        $this->connection = null;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        // 自动提交未完成的事务
        if ($this->transactionActive) {
            try {
                $this->rollback();
            } catch (Exception $e) {
                error_log("自动回滚事务失败: " . $e->getMessage());
            }
        }
    }
}