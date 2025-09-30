<?php

namespace App\Database;

use PDO;
use PDOException;
use Exception;

/**
 * OneBookNav - 数据库连接池
 *
 * 提供高效的数据库连接管理和性能监控
 */
class ConnectionPool
{
    private array $config;
    private array $pool = [];
    private array $activeConnections = [];
    private array $metrics = [];
    private int $createdConnections = 0;
    private int $maxConnections;
    private int $minConnections;
    private int $idleTimeout;
    private bool $enableMonitoring;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxConnections = $config['pool']['max_connections'] ?? 10;
        $this->minConnections = $config['pool']['min_connections'] ?? 1;
        $this->idleTimeout = $config['pool']['idle_timeout'] ?? 300; // 5分钟
        $this->enableMonitoring = $config['performance']['query_cache'] ?? true;

        // 初始化最小连接数
        $this->initializePool();
    }

    /**
     * 获取数据库连接
     */
    public function getConnection(): PDO
    {
        $startTime = microtime(true);

        try {
            $connection = $this->acquireConnection();
            $this->recordMetric('connection_acquire_time', microtime(true) - $startTime);
            return $connection;
        } catch (Exception $e) {
            $this->recordMetric('connection_acquire_error', 1);
            throw $e;
        }
    }

    /**
     * 释放数据库连接
     */
    public function releaseConnection(PDO $connection): void
    {
        $connectionId = spl_object_id($connection);

        if (isset($this->activeConnections[$connectionId])) {
            // 检查连接是否仍然有效
            if ($this->isConnectionValid($connection)) {
                // 将连接返回到池中
                $this->pool[] = [
                    'connection' => $connection,
                    'last_used' => time(),
                    'created_at' => $this->activeConnections[$connectionId]['created_at']
                ];
            } else {
                // 连接无效，减少创建计数
                $this->createdConnections--;
            }

            unset($this->activeConnections[$connectionId]);
        }
    }

    /**
     * 获取连接池状态
     */
    public function getStatus(): array
    {
        $this->cleanupIdleConnections();

        return [
            'pool_size' => count($this->pool),
            'active_connections' => count($this->activeConnections),
            'created_connections' => $this->createdConnections,
            'max_connections' => $this->maxConnections,
            'min_connections' => $this->minConnections,
            'metrics' => $this->getMetrics()
        ];
    }

    /**
     * 获取性能指标
     */
    public function getMetrics(): array
    {
        return [
            'connection_acquire_time_avg' => $this->getAverageMetric('connection_acquire_time'),
            'connection_acquire_errors' => $this->getMetricSum('connection_acquire_error'),
            'query_execution_time_avg' => $this->getAverageMetric('query_execution_time'),
            'slow_queries' => $this->getMetricSum('slow_query'),
            'cache_hits' => $this->getMetricSum('cache_hit'),
            'cache_misses' => $this->getMetricSum('cache_miss'),
        ];
    }

    /**
     * 执行查询并监控性能
     */
    public function executeQuery(PDO $connection, string $sql, array $params = []): \PDOStatement
    {
        $startTime = microtime(true);
        $queryHash = md5($sql);

        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);

            $executionTime = microtime(true) - $startTime;
            $this->recordMetric('query_execution_time', $executionTime);

            // 记录慢查询
            $slowQueryThreshold = $this->config['performance']['slow_query_log'] ?? 1000; // 毫秒
            if ($executionTime * 1000 > $slowQueryThreshold) {
                $this->recordSlowQuery($sql, $executionTime, $stmt->rowCount());
                $this->recordMetric('slow_query', 1);
            }

            return $stmt;
        } catch (PDOException $e) {
            $this->recordMetric('query_error', 1);
            throw $e;
        }
    }

    /**
     * 初始化连接池
     */
    private function initializePool(): void
    {
        for ($i = 0; $i < $this->minConnections; $i++) {
            try {
                $connection = $this->createConnection();
                $this->pool[] = [
                    'connection' => $connection,
                    'last_used' => time(),
                    'created_at' => time()
                ];
            } catch (Exception $e) {
                // 记录错误但继续初始化
                error_log("Failed to initialize connection pool: " . $e->getMessage());
            }
        }
    }

    /**
     * 获取连接
     */
    private function acquireConnection(): PDO
    {
        // 清理空闲连接
        $this->cleanupIdleConnections();

        // 从池中获取连接
        if (!empty($this->pool)) {
            $poolItem = array_pop($this->pool);
            $connection = $poolItem['connection'];

            // 验证连接
            if ($this->isConnectionValid($connection)) {
                $connectionId = spl_object_id($connection);
                $this->activeConnections[$connectionId] = [
                    'acquired_at' => time(),
                    'created_at' => $poolItem['created_at']
                ];
                return $connection;
            }
        }

        // 创建新连接
        if ($this->createdConnections < $this->maxConnections) {
            $connection = $this->createConnection();
            $connectionId = spl_object_id($connection);
            $this->activeConnections[$connectionId] = [
                'acquired_at' => time(),
                'created_at' => time()
            ];
            return $connection;
        }

        throw new Exception('Connection pool exhausted. Maximum connections reached.');
    }

    /**
     * 创建新的数据库连接
     */
    private function createConnection(): PDO
    {
        $dsn = $this->buildDsn();
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;
        $options = $this->config['options'] ?? [];

        // 添加默认选项
        $options = array_merge([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 30,
        ], $options);

        try {
            $connection = new PDO($dsn, $username, $password, $options);

            // SQLite 特定优化
            if ($this->config['driver'] === 'sqlite') {
                $this->optimizeSqliteConnection($connection);
            }

            $this->createdConnections++;
            return $connection;
        } catch (PDOException $e) {
            throw new Exception("Failed to create database connection: " . $e->getMessage());
        }
    }

    /**
     * 构建 DSN
     */
    private function buildDsn(): string
    {
        $driver = $this->config['driver'];

        switch ($driver) {
            case 'sqlite':
                return "sqlite:" . $this->config['database'];

            case 'mysql':
                $host = $this->config['host'] ?? '127.0.0.1';
                $port = $this->config['port'] ?? 3306;
                $dbname = $this->config['database'];
                $charset = $this->config['charset'] ?? 'utf8mb4';
                return "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            case 'pgsql':
                $host = $this->config['host'] ?? '127.0.0.1';
                $port = $this->config['port'] ?? 5432;
                $dbname = $this->config['database'];
                return "pgsql:host={$host};port={$port};dbname={$dbname}";

            default:
                throw new Exception("Unsupported database driver: {$driver}");
        }
    }

    /**
     * 优化 SQLite 连接
     */
    private function optimizeSqliteConnection(PDO $connection): void
    {
        $optimizations = [
            "PRAGMA journal_mode = WAL",
            "PRAGMA synchronous = NORMAL",
            "PRAGMA cache_size = 64000",
            "PRAGMA temp_store = MEMORY",
            "PRAGMA mmap_size = 268435456", // 256MB
            "PRAGMA foreign_keys = ON",
        ];

        foreach ($optimizations as $pragma) {
            try {
                $connection->exec($pragma);
            } catch (PDOException $e) {
                // 记录警告但继续
                error_log("SQLite optimization warning: " . $e->getMessage());
            }
        }
    }

    /**
     * 检查连接是否有效
     */
    private function isConnectionValid(PDO $connection): bool
    {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 清理空闲连接
     */
    private function cleanupIdleConnections(): void
    {
        $currentTime = time();
        $this->pool = array_filter($this->pool, function($item) use ($currentTime) {
            $isValid = ($currentTime - $item['last_used']) < $this->idleTimeout;
            if (!$isValid) {
                $this->createdConnections--;
            }
            return $isValid;
        });
    }

    /**
     * 记录性能指标
     */
    private function recordMetric(string $name, $value): void
    {
        if (!$this->enableMonitoring) {
            return;
        }

        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = [];
        }

        $this->metrics[$name][] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        // 保持最近1000条记录
        if (count($this->metrics[$name]) > 1000) {
            array_shift($this->metrics[$name]);
        }
    }

    /**
     * 获取平均指标
     */
    private function getAverageMetric(string $name): float
    {
        if (!isset($this->metrics[$name]) || empty($this->metrics[$name])) {
            return 0.0;
        }

        $values = array_column($this->metrics[$name], 'value');
        return array_sum($values) / count($values);
    }

    /**
     * 获取指标总和
     */
    private function getMetricSum(string $name): int
    {
        if (!isset($this->metrics[$name])) {
            return 0;
        }

        return count($this->metrics[$name]);
    }

    /**
     * 记录慢查询
     */
    private function recordSlowQuery(string $sql, float $executionTime, int $rowCount): void
    {
        try {
            // 这里可以将慢查询记录到数据库或日志文件
            $logData = [
                'query_hash' => md5($sql),
                'query_sql' => $sql,
                'execution_time' => $executionTime,
                'rows_returned' => $rowCount,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            error_log("Slow Query: " . json_encode($logData));
        } catch (Exception $e) {
            // 记录错误但不影响主流程
            error_log("Failed to log slow query: " . $e->getMessage());
        }
    }

    /**
     * 关闭连接池
     */
    public function close(): void
    {
        // 关闭所有池中的连接
        foreach ($this->pool as $item) {
            $item['connection'] = null;
        }
        $this->pool = [];

        // 记录活跃连接（应该由调用者释放）
        if (!empty($this->activeConnections)) {
            error_log("Warning: " . count($this->activeConnections) . " active connections not properly released");
        }

        $this->activeConnections = [];
        $this->createdConnections = 0;
    }

    /**
     * 获取连接统计信息
     */
    public function getStatistics(): array
    {
        $this->cleanupIdleConnections();

        $totalQueries = 0;
        $totalExecutionTime = 0;

        if (isset($this->metrics['query_execution_time'])) {
            $queries = $this->metrics['query_execution_time'];
            $totalQueries = count($queries);
            $totalExecutionTime = array_sum(array_column($queries, 'value'));
        }

        return [
            'connections' => [
                'pool_size' => count($this->pool),
                'active' => count($this->activeConnections),
                'created_total' => $this->createdConnections,
                'max_allowed' => $this->maxConnections,
            ],
            'queries' => [
                'total_executed' => $totalQueries,
                'total_execution_time' => $totalExecutionTime,
                'average_execution_time' => $totalQueries > 0 ? $totalExecutionTime / $totalQueries : 0,
                'slow_queries' => $this->getMetricSum('slow_query'),
                'errors' => $this->getMetricSum('query_error'),
            ],
            'cache' => [
                'hits' => $this->getMetricSum('cache_hit'),
                'misses' => $this->getMetricSum('cache_miss'),
                'hit_ratio' => $this->calculateCacheHitRatio(),
            ],
        ];
    }

    /**
     * 计算缓存命中率
     */
    private function calculateCacheHitRatio(): float
    {
        $hits = $this->getMetricSum('cache_hit');
        $misses = $this->getMetricSum('cache_miss');
        $total = $hits + $misses;

        return $total > 0 ? ($hits / $total) * 100 : 0.0;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();
    }
}