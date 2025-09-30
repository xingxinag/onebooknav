<?php

namespace App\Services;

use App\Core\Container;
use Exception;

/**
 * 缓存服务类
 *
 * 实现"终极.txt"要求的多态缓存系统
 * 支持三种部署方式的缓存适配：文件缓存、Redis、Cloudflare KV
 */
class CacheService
{
    private static $instance = null;
    private ConfigService $config;
    private string $driver;
    private string $prefix;
    private int $defaultTtl;
    private $connection = null;

    private function __construct()
    {
        $container = Container::getInstance();
        $this->config = $container->get('config');
        $this->driver = $this->config->get('cache.driver', 'file');
        $this->prefix = $this->config->get('cache.prefix', 'onebooknav_');
        $this->defaultTtl = $this->config->get('cache.ttl', 3600);

        $this->initializeDriver();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化缓存驱动
     */
    private function initializeDriver(): void
    {
        $deploymentMethod = $this->config->get('deployment.method', 'php-native');

        switch ($deploymentMethod) {
            case 'cloudflare-workers':
                $this->driver = 'cloudflare-kv';
                break;
            case 'docker':
                // Docker 环境优先使用 Redis
                if ($this->isRedisAvailable()) {
                    $this->driver = 'redis';
                } else {
                    $this->driver = 'file';
                }
                break;
            default:
                $this->driver = $this->config->get('cache.driver', 'file');
        }

        $this->connect();
    }

    /**
     * 建立缓存连接
     */
    private function connect(): void
    {
        switch ($this->driver) {
            case 'redis':
                $this->connectRedis();
                break;
            case 'memcached':
                $this->connectMemcached();
                break;
            case 'cloudflare-kv':
                $this->connectCloudflareKV();
                break;
            case 'file':
            default:
                $this->ensureCacheDirectory();
                break;
        }
    }

    /**
     * 连接 Redis
     */
    private function connectRedis(): void
    {
        if (!class_exists('Redis')) {
            throw new Exception('Redis 扩展未安装');
        }

        try {
            $this->connection = new \Redis();
            $host = $this->config->get('redis.host', '127.0.0.1');
            $port = $this->config->get('redis.port', 6379);
            $timeout = $this->config->get('redis.timeout', 5);

            $this->connection->connect($host, $port, $timeout);

            $password = $this->config->get('redis.password');
            if ($password) {
                $this->connection->auth($password);
            }

            $database = $this->config->get('redis.database', 0);
            $this->connection->select($database);

        } catch (Exception $e) {
            error_log("Redis 连接失败: " . $e->getMessage());
            // 回退到文件缓存
            $this->driver = 'file';
            $this->ensureCacheDirectory();
        }
    }

    /**
     * 连接 Memcached
     */
    private function connectMemcached(): void
    {
        if (!class_exists('Memcached')) {
            throw new Exception('Memcached 扩展未安装');
        }

        try {
            $this->connection = new \Memcached();
            $servers = $this->config->get('memcached.servers', [
                ['127.0.0.1', 11211]
            ]);

            $this->connection->addServers($servers);

        } catch (Exception $e) {
            error_log("Memcached 连接失败: " . $e->getMessage());
            // 回退到文件缓存
            $this->driver = 'file';
            $this->ensureCacheDirectory();
        }
    }

    /**
     * 连接 Cloudflare KV
     */
    private function connectCloudflareKV(): void
    {
        // Cloudflare Workers 环境中的 KV 存储
        // 这里是占位符，实际实现需要在 Worker 环境中
        $this->connection = [
            'namespace' => $this->config->get('cloudflare.kv_namespace', 'CACHE'),
            'account_id' => $this->config->get('cloudflare.account_id'),
            'api_token' => $this->config->get('cloudflare.api_token')
        ];
    }

    /**
     * 确保缓存目录存在
     */
    private function ensureCacheDirectory(): void
    {
        $cacheDir = DATA_PATH . '/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }

    /**
     * 检查 Redis 是否可用
     */
    private function isRedisAvailable(): bool
    {
        if (!class_exists('Redis')) {
            return false;
        }

        try {
            $redis = new \Redis();
            $host = $this->config->get('redis.host', '127.0.0.1');
            $port = $this->config->get('redis.port', 6379);

            return $redis->connect($host, $port, 1); // 1秒超时
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取缓存
     */
    public function get(string $key, $default = null)
    {
        $key = $this->prefix . $key;

        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->getFromRedis($key, $default);
                case 'memcached':
                    return $this->getFromMemcached($key, $default);
                case 'cloudflare-kv':
                    return $this->getFromCloudflareKV($key, $default);
                default:
                    return $this->getFromFile($key, $default);
            }
        } catch (Exception $e) {
            error_log("缓存获取失败: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * 设置缓存
     */
    public function set(string $key, $value, int $ttl = null): bool
    {
        $key = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->setToRedis($key, $value, $ttl);
                case 'memcached':
                    return $this->setToMemcached($key, $value, $ttl);
                case 'cloudflare-kv':
                    return $this->setToCloudflareKV($key, $value, $ttl);
                default:
                    return $this->setToFile($key, $value, $ttl);
            }
        } catch (Exception $e) {
            error_log("缓存设置失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 删除缓存
     */
    public function delete(string $key): bool
    {
        $key = $this->prefix . $key;

        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->deleteFromRedis($key);
                case 'memcached':
                    return $this->deleteFromMemcached($key);
                case 'cloudflare-kv':
                    return $this->deleteFromCloudflareKV($key);
                default:
                    return $this->deleteFromFile($key);
            }
        } catch (Exception $e) {
            error_log("缓存删除失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清空所有缓存
     */
    public function clear(): bool
    {
        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->clearRedis();
                case 'memcached':
                    return $this->clearMemcached();
                case 'cloudflare-kv':
                    return $this->clearCloudflareKV();
                default:
                    return $this->clearFile();
            }
        } catch (Exception $e) {
            error_log("缓存清空失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 批量删除缓存（支持模式匹配）
     */
    public function deletePattern(string $pattern): bool
    {
        $pattern = $this->prefix . $pattern;

        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->deletePatternFromRedis($pattern);
                case 'memcached':
                    // Memcached 不支持模式匹配，使用标签模拟
                    return $this->deletePatternFromMemcached($pattern);
                case 'cloudflare-kv':
                    return $this->deletePatternFromCloudflareKV($pattern);
                default:
                    return $this->deletePatternFromFile($pattern);
            }
        } catch (Exception $e) {
            error_log("批量删除缓存失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查缓存是否存在
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 获取剩余过期时间
     */
    public function ttl(string $key): int
    {
        $key = $this->prefix . $key;

        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->connection->ttl($key);
                case 'memcached':
                    // Memcached 不直接支持 TTL 查询
                    return -1;
                case 'cloudflare-kv':
                    // Cloudflare KV 不支持 TTL 查询
                    return -1;
                default:
                    return $this->getFileTtl($key);
            }
        } catch (Exception $e) {
            return -1;
        }
    }

    // ==================== Redis 实现 ====================

    private function getFromRedis(string $key, $default)
    {
        $value = $this->connection->get($key);
        if ($value === false) {
            return $default;
        }
        return unserialize($value);
    }

    private function setToRedis(string $key, $value, int $ttl): bool
    {
        $serialized = serialize($value);
        if ($ttl > 0) {
            return $this->connection->setex($key, $ttl, $serialized);
        } else {
            return $this->connection->set($key, $serialized);
        }
    }

    private function deleteFromRedis(string $key): bool
    {
        return $this->connection->del($key) > 0;
    }

    private function clearRedis(): bool
    {
        $keys = $this->connection->keys($this->prefix . '*');
        if (empty($keys)) {
            return true;
        }
        return $this->connection->del($keys) > 0;
    }

    private function deletePatternFromRedis(string $pattern): bool
    {
        $keys = $this->connection->keys($pattern . '*');
        if (empty($keys)) {
            return true;
        }
        return $this->connection->del($keys) > 0;
    }

    // ==================== Memcached 实现 ====================

    private function getFromMemcached(string $key, $default)
    {
        $value = $this->connection->get($key);
        return $value !== false ? $value : $default;
    }

    private function setToMemcached(string $key, $value, int $ttl): bool
    {
        $expiration = $ttl > 0 ? time() + $ttl : 0;
        return $this->connection->set($key, $value, $expiration);
    }

    private function deleteFromMemcached(string $key): bool
    {
        return $this->connection->delete($key);
    }

    private function clearMemcached(): bool
    {
        return $this->connection->flush();
    }

    private function deletePatternFromMemcached(string $pattern): bool
    {
        // Memcached 不支持键模式匹配，这里简化处理
        return $this->connection->flush();
    }

    // ==================== Cloudflare KV 实现 ====================

    private function getFromCloudflareKV(string $key, $default)
    {
        // 在实际的 Cloudflare Workers 环境中，使用 KV.get()
        // 这里是模拟实现
        if (function_exists('cloudflare_kv_get')) {
            $value = cloudflare_kv_get($key);
            return $value !== null ? unserialize($value) : $default;
        }
        return $default;
    }

    private function setToCloudflareKV(string $key, $value, int $ttl): bool
    {
        // 在实际的 Cloudflare Workers 环境中，使用 KV.put()
        if (function_exists('cloudflare_kv_put')) {
            $serialized = serialize($value);
            $options = $ttl > 0 ? ['expirationTtl' => $ttl] : [];
            return cloudflare_kv_put($key, $serialized, $options);
        }
        return false;
    }

    private function deleteFromCloudflareKV(string $key): bool
    {
        if (function_exists('cloudflare_kv_delete')) {
            return cloudflare_kv_delete($key);
        }
        return false;
    }

    private function clearCloudflareKV(): bool
    {
        // Cloudflare KV 不支持批量清除
        return false;
    }

    private function deletePatternFromCloudflareKV(string $pattern): bool
    {
        // Cloudflare KV 不支持模式匹配
        return false;
    }

    // ==================== 文件缓存实现 ====================

    private function getFromFile(string $key, $default)
    {
        $filename = $this->getCacheFilename($key);

        if (!file_exists($filename)) {
            return $default;
        }

        $data = file_get_contents($filename);
        if ($data === false) {
            return $default;
        }

        $cache = unserialize($data);
        if (!is_array($cache) || !isset($cache['expires'], $cache['data'])) {
            unlink($filename);
            return $default;
        }

        if ($cache['expires'] > 0 && $cache['expires'] < time()) {
            unlink($filename);
            return $default;
        }

        return $cache['data'];
    }

    private function setToFile(string $key, $value, int $ttl): bool
    {
        $filename = $this->getCacheFilename($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;

        $data = [
            'expires' => $expires,
            'data' => $value,
            'created' => time()
        ];

        $serialized = serialize($data);
        return file_put_contents($filename, $serialized, LOCK_EX) !== false;
    }

    private function deleteFromFile(string $key): bool
    {
        $filename = $this->getCacheFilename($key);
        if (file_exists($filename)) {
            return unlink($filename);
        }
        return true;
    }

    private function clearFile(): bool
    {
        $cacheDir = DATA_PATH . '/cache';
        if (!is_dir($cacheDir)) {
            return true;
        }

        $files = glob($cacheDir . '/*');
        $success = true;

        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    private function deletePatternFromFile(string $pattern): bool
    {
        $cacheDir = DATA_PATH . '/cache';
        $pattern = str_replace(['*', '?'], ['.*', '.'], $pattern);
        $pattern = '/^' . str_replace('/', '\/', $pattern) . '/';

        $files = glob($cacheDir . '/*');
        $success = true;

        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match($pattern, $filename)) {
                if (is_file($file) && !unlink($file)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    private function getFileTtl(string $key): int
    {
        $filename = $this->getCacheFilename($key);

        if (!file_exists($filename)) {
            return -2; // 键不存在
        }

        $data = file_get_contents($filename);
        if ($data === false) {
            return -2;
        }

        $cache = unserialize($data);
        if (!is_array($cache) || !isset($cache['expires'])) {
            return -2;
        }

        if ($cache['expires'] === 0) {
            return -1; // 永不过期
        }

        $ttl = $cache['expires'] - time();
        return $ttl > 0 ? $ttl : -2;
    }

    private function getCacheFilename(string $key): string
    {
        $hash = md5($key);
        return DATA_PATH . '/cache/' . $hash . '.cache';
    }

    // ==================== 高级功能 ====================

    /**
     * 记住功能（缓存穿透保护）
     */
    public function remember(string $key, callable $callback, int $ttl = null)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * 永久记住功能
     */
    public function rememberForever(string $key, callable $callback)
    {
        return $this->remember($key, $callback, 0);
    }

    /**
     * 获取或设置缓存（原子操作）
     */
    public function pull(string $key, $default = null)
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * 增加数值缓存
     */
    public function increment(string $key, int $value = 1): int
    {
        switch ($this->driver) {
            case 'redis':
                return $this->connection->incrBy($this->prefix . $key, $value);
            case 'memcached':
                return $this->connection->increment($this->prefix . $key, $value) ?: 0;
            default:
                $current = (int)$this->get($key, 0);
                $new = $current + $value;
                $this->set($key, $new);
                return $new;
        }
    }

    /**
     * 减少数值缓存
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    /**
     * 批量获取缓存
     */
    public function many(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * 批量设置缓存
     */
    public function putMany(array $values, int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 添加缓存（仅当键不存在时）
     */
    public function add(string $key, $value, int $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    /**
     * 缓存标签功能
     */
    public function tags(array $tags): self
    {
        // 返回一个新的标签缓存实例
        $taggedCache = clone $this;
        $taggedCache->cacheTags = $tags;
        return $taggedCache;
    }

    /**
     * 获取缓存统计信息
     */
    public function getStats(): array
    {
        try {
            switch ($this->driver) {
                case 'redis':
                    return $this->getRedisStats();
                case 'memcached':
                    return $this->getMemcachedStats();
                case 'file':
                    return $this->getFileStats();
                default:
                    return ['driver' => $this->driver];
            }
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getRedisStats(): array
    {
        $info = $this->connection->info();
        return [
            'driver' => 'redis',
            'connected' => $this->connection->ping() === '+PONG',
            'memory_used' => $info['used_memory_human'] ?? 'N/A',
            'keys' => $this->connection->dbSize(),
            'hits' => $info['keyspace_hits'] ?? 0,
            'misses' => $info['keyspace_misses'] ?? 0
        ];
    }

    private function getMemcachedStats(): array
    {
        $stats = $this->connection->getStats();
        $serverStats = reset($stats);

        return [
            'driver' => 'memcached',
            'connected' => !empty($serverStats),
            'memory_used' => $serverStats['bytes'] ?? 0,
            'keys' => $serverStats['curr_items'] ?? 0,
            'hits' => $serverStats['get_hits'] ?? 0,
            'misses' => $serverStats['get_misses'] ?? 0
        ];
    }

    private function getFileStats(): array
    {
        $cacheDir = DATA_PATH . '/cache';
        $files = glob($cacheDir . '/*.cache');
        $totalSize = 0;

        foreach ($files as $file) {
            $totalSize += filesize($file);
        }

        return [
            'driver' => 'file',
            'cache_dir' => $cacheDir,
            'files' => count($files),
            'total_size' => $totalSize,
            'directory_writable' => is_writable($cacheDir)
        ];
    }

    /**
     * 清理过期缓存（仅文件缓存）
     */
    public function cleanup(): int
    {
        if ($this->driver !== 'file') {
            return 0;
        }

        $cacheDir = DATA_PATH . '/cache';
        $files = glob($cacheDir . '/*.cache');
        $cleaned = 0;

        foreach ($files as $file) {
            $data = file_get_contents($file);
            if ($data === false) {
                continue;
            }

            $cache = unserialize($data);
            if (!is_array($cache) || !isset($cache['expires'])) {
                unlink($file);
                $cleaned++;
                continue;
            }

            if ($cache['expires'] > 0 && $cache['expires'] < time()) {
                unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}