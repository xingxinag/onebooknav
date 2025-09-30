<?php

namespace App\Services;

use Exception;

/**
 * 配置管理服务
 *
 * 实现"终极.txt"要求的统一配置管理系统
 * 支持三种部署方式的配置适配：PHP原生、Docker容器、Cloudflare Workers
 */
class ConfigService
{
    private static $instance = null;
    private array $config = [];
    private array $runtimeConfig = [];
    private string $configPath;
    private bool $isDirty = false;
    private array $envVars = [];

    private function __construct()
    {
        $this->configPath = DATA_PATH . '/config.json';
        $this->loadEnvironmentVariables();
        $this->loadConfiguration();
        $this->initializeDefaults();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 加载环境变量
     */
    private function loadEnvironmentVariables(): void
    {
        // 从 .env 文件加载
        $envFile = ROOT_PATH . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    $this->envVars[$key] = $value;
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv("$key=$value");
                    }
                }
            }
        }

        // 检测运行环境
        $this->detectEnvironment();
    }

    /**
     * 检测运行环境
     */
    private function detectEnvironment(): void
    {
        // 检测 Cloudflare Workers 环境
        if (isset($_ENV['CF_PAGES']) || isset($_ENV['CLOUDFLARE_WORKER_ID'])) {
            $this->envVars['DEPLOYMENT_METHOD'] = 'cloudflare-workers';
            $this->envVars['APP_ENV'] = 'production';
        }
        // 检测 Docker 环境
        elseif (file_exists('/.dockerenv') || isset($_ENV['DOCKER_CONTAINER'])) {
            $this->envVars['DEPLOYMENT_METHOD'] = 'docker';
        }
        // PHP 原生环境
        else {
            $this->envVars['DEPLOYMENT_METHOD'] = 'php-native';
        }
    }

    /**
     * 加载配置文件
     */
    private function loadConfiguration(): void
    {
        if (file_exists($this->configPath)) {
            $content = file_get_contents($this->configPath);
            $this->config = json_decode($content, true) ?? [];
        }

        // 从数据库加载配置（如果数据库可用）
        $this->loadDatabaseConfig();
    }

    /**
     * 从数据库加载配置
     */
    private function loadDatabaseConfig(): void
    {
        try {
            if (!class_exists('App\Services\DatabaseService')) {
                return;
            }

            $database = DatabaseService::getInstance();
            if (!$database->tableExists('site_settings')) {
                return;
            }

            $settings = $database->findAll('site_settings', 'is_public = 1 OR is_public = 0');
            foreach ($settings as $setting) {
                $key = $setting['group_name'] . '.' . $setting['key'];
                $value = $this->parseConfigValue($setting['value'], $setting['type']);
                $this->config[$key] = $value;
            }
        } catch (Exception $e) {
            // 数据库不可用时忽略错误
            error_log("Failed to load database config: " . $e->getMessage());
        }
    }

    /**
     * 解析配置值
     */
    private function parseConfigValue($value, string $type)
    {
        switch ($type) {
            case 'boolean':
                return in_array(strtolower($value), ['1', 'true', 'yes', 'on']);
            case 'integer':
                return (int)$value;
            case 'json':
                return json_decode($value, true) ?? $value;
            default:
                return $value;
        }
    }

    /**
     * 初始化默认配置
     */
    private function initializeDefaults(): void
    {
        $defaults = [
            // 应用基础配置
            'app.name' => 'OneBookNav',
            'app.version' => '1.0.0',
            'app.environment' => $this->env('APP_ENV', 'production'),
            'app.debug' => $this->env('APP_DEBUG', false),
            'app.timezone' => $this->env('APP_TIMEZONE', 'Asia/Shanghai'),
            'app.locale' => $this->env('APP_LOCALE', 'zh_CN'),

            // 部署方式配置
            'deployment.method' => $this->envVars['DEPLOYMENT_METHOD'] ?? 'php-native',
            'deployment.domain' => $this->env('APP_DOMAIN', $_SERVER['HTTP_HOST'] ?? 'localhost'),
            'deployment.url' => $this->env('APP_URL', 'http://localhost'),

            // 数据库配置
            'database.driver' => $this->env('DB_DRIVER', 'sqlite'),
            'database.path' => $this->env('DB_PATH', DATA_PATH . '/database.db'),
            'database.host' => $this->env('DB_HOST', 'localhost'),
            'database.port' => $this->env('DB_PORT', '3306'),
            'database.name' => $this->env('DB_DATABASE', 'onebooknav'),
            'database.username' => $this->env('DB_USERNAME', ''),
            'database.password' => $this->env('DB_PASSWORD', ''),
            'database.charset' => $this->env('DB_CHARSET', 'utf8mb4'),

            // 用户认证配置
            'auth.session_name' => 'onebooknav_session',
            'auth.session_lifetime' => (int)$this->env('SESSION_LIFETIME', 86400 * 30), // 30天
            'auth.remember_lifetime' => (int)$this->env('REMEMBER_LIFETIME', 86400 * 365), // 1年
            'auth.max_login_attempts' => (int)$this->env('MAX_LOGIN_ATTEMPTS', 5),
            'auth.lockout_duration' => (int)$this->env('LOCKOUT_DURATION', 1800), // 30分钟

            // 安全配置
            'security.secret_key' => $this->env('SECRET_KEY', $this->generateSecretKey()),
            'security.csrf_token_lifetime' => (int)$this->env('CSRF_LIFETIME', 3600),
            'security.password_min_length' => (int)$this->env('PASSWORD_MIN_LENGTH', 8),
            'security.password_require_uppercase' => $this->env('PASSWORD_REQUIRE_UPPERCASE', true),
            'security.password_require_lowercase' => $this->env('PASSWORD_REQUIRE_LOWERCASE', true),
            'security.password_require_numbers' => $this->env('PASSWORD_REQUIRE_NUMBERS', true),
            'security.password_require_symbols' => $this->env('PASSWORD_REQUIRE_SYMBOLS', false),
            'security.max_file_size' => (int)$this->env('MAX_FILE_SIZE', 5242880), // 5MB

            // 功能特性配置
            'features.registration_enabled' => $this->env('REGISTRATION_ENABLED', true),
            'features.invitation_required' => $this->env('INVITATION_REQUIRED', false),
            'features.guest_access_enabled' => $this->env('GUEST_ACCESS_ENABLED', true),
            'features.ai_search_enabled' => $this->env('AI_SEARCH_ENABLED', false),
            'features.deadlink_check_enabled' => $this->env('DEADLINK_CHECK_ENABLED', true),
            'features.backup_enabled' => $this->env('BACKUP_ENABLED', true),
            'features.webdav_enabled' => $this->env('WEBDAV_ENABLED', false),

            // AI 服务配置
            'ai.provider' => $this->env('AI_PROVIDER', 'openai'),
            'ai.api_key' => $this->env('AI_API_KEY', ''),
            'ai.model' => $this->env('AI_MODEL', 'gpt-3.5-turbo'),
            'ai.timeout' => (int)$this->env('AI_TIMEOUT', 30),

            // WebDAV 备份配置
            'webdav.url' => $this->env('WEBDAV_URL', ''),
            'webdav.username' => $this->env('WEBDAV_USERNAME', ''),
            'webdav.password' => $this->env('WEBDAV_PASSWORD', ''),
            'webdav.remote_path' => $this->env('WEBDAV_REMOTE_PATH', '/onebooknav-backups/'),

            // 缓存配置
            'cache.driver' => $this->env('CACHE_DRIVER', 'file'),
            'cache.ttl' => (int)$this->env('CACHE_TTL', 3600),
            'cache.prefix' => $this->env('CACHE_PREFIX', 'onebooknav_'),

            // 邮件配置
            'mail.driver' => $this->env('MAIL_DRIVER', 'smtp'),
            'mail.host' => $this->env('MAIL_HOST', 'localhost'),
            'mail.port' => (int)$this->env('MAIL_PORT', 587),
            'mail.username' => $this->env('MAIL_USERNAME', ''),
            'mail.password' => $this->env('MAIL_PASSWORD', ''),
            'mail.encryption' => $this->env('MAIL_ENCRYPTION', 'tls'),
            'mail.from_address' => $this->env('MAIL_FROM_ADDRESS', 'noreply@localhost'),
            'mail.from_name' => $this->env('MAIL_FROM_NAME', 'OneBookNav'),

            // 主题配置
            'theme.default' => $this->env('DEFAULT_THEME', 'default'),
            'theme.mobile' => $this->env('MOBILE_THEME', 'mobile'),
            'theme.admin' => $this->env('ADMIN_THEME', 'admin'),

            // 性能配置
            'performance.enable_gzip' => $this->env('ENABLE_GZIP', true),
            'performance.enable_opcache' => $this->env('ENABLE_OPCACHE', true),
            'performance.query_log_enabled' => $this->env('QUERY_LOG_ENABLED', false),
            'performance.slow_query_threshold' => (float)$this->env('SLOW_QUERY_THRESHOLD', 1.0),

            // 日志配置
            'logging.level' => $this->env('LOG_LEVEL', 'error'),
            'logging.max_files' => (int)$this->env('LOG_MAX_FILES', 10),
            'logging.max_size' => (int)$this->env('LOG_MAX_SIZE', 10485760), // 10MB

            // 管理员账户
            'admin.username' => $this->env('ADMIN_USERNAME', 'admin'),
            'admin.email' => $this->env('ADMIN_EMAIL', 'admin@localhost'),
            'admin.password' => $this->env('ADMIN_PASSWORD', 'admin123'),
        ];

        // 合并默认配置，不覆盖已存在的配置
        foreach ($defaults as $key => $value) {
            if (!$this->has($key)) {
                $this->config[$key] = $value;
            }
        }
    }

    /**
     * 获取环境变量
     */
    private function env(string $key, $default = null)
    {
        // 优先从环境变量获取
        if (isset($this->envVars[$key])) {
            $value = $this->envVars[$key];
        } elseif (isset($_ENV[$key])) {
            $value = $_ENV[$key];
        } elseif (($value = getenv($key)) !== false) {
            // getenv 返回值处理
        } else {
            return $default;
        }

        // 类型转换
        if (is_string($default)) {
            return (string)$value;
        } elseif (is_bool($default)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        } elseif (is_int($default)) {
            return (int)$value;
        } elseif (is_float($default)) {
            return (float)$value;
        }

        return $value;
    }

    /**
     * 生成密钥
     */
    private function generateSecretKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 获取配置值
     */
    public function get(string $key, $default = null)
    {
        // 优先从运行时配置获取
        if (isset($this->runtimeConfig[$key])) {
            return $this->runtimeConfig[$key];
        }

        // 从主配置获取
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }

        // 支持点号分隔的键
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (is_array($value) && isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * 设置配置值
     */
    public function set(string $key, $value): void
    {
        $this->runtimeConfig[$key] = $value;
        $this->isDirty = true;
    }

    /**
     * 永久设置配置值
     */
    public function setPermanent(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $segment) {
            if (!isset($config[$segment]) || !is_array($config[$segment])) {
                $config[$segment] = [];
            }
            $config = &$config[$segment];
        }

        $config = $value;
        $this->isDirty = true;
        $this->save();
    }

    /**
     * 检查配置是否存在
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 删除配置
     */
    public function remove(string $key): void
    {
        if (isset($this->runtimeConfig[$key])) {
            unset($this->runtimeConfig[$key]);
        }

        $keys = explode('.', $key);
        $config = &$this->config;

        for ($i = 0; $i < count($keys) - 1; $i++) {
            if (!isset($config[$keys[$i]]) || !is_array($config[$keys[$i]])) {
                return;
            }
            $config = &$config[$keys[$i]];
        }

        unset($config[end($keys)]);
        $this->isDirty = true;
    }

    /**
     * 获取所有配置
     */
    public function all(): array
    {
        return array_merge($this->config, $this->runtimeConfig);
    }

    /**
     * 获取指定组的配置
     */
    public function group(string $group): array
    {
        $result = [];
        $prefix = $group . '.';
        $allConfig = $this->all();

        foreach ($allConfig as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $value;
            }
        }

        return $result;
    }

    /**
     * 保存配置到文件
     */
    public function save(): bool
    {
        try {
            $configDir = dirname($this->configPath);
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }

            $content = json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $result = file_put_contents($this->configPath, $content, LOCK_EX);

            if ($result !== false) {
                $this->isDirty = false;
                return true;
            }

            return false;
        } catch (Exception $e) {
            error_log("Failed to save config: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 重新加载配置
     */
    public function reload(): void
    {
        $this->config = [];
        $this->runtimeConfig = [];
        $this->loadConfiguration();
        $this->initializeDefaults();
    }

    /**
     * 获取环境信息
     */
    public function getEnvironmentInfo(): array
    {
        return [
            'deployment_method' => $this->get('deployment.method'),
            'php_version' => PHP_VERSION,
            'environment' => $this->get('app.environment'),
            'debug_mode' => $this->get('app.debug'),
            'timezone' => $this->get('app.timezone'),
            'locale' => $this->get('app.locale'),
            'database_driver' => $this->get('database.driver'),
            'cache_driver' => $this->get('cache.driver'),
            'features' => $this->group('features'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];
    }

    /**
     * 验证配置
     */
    public function validate(): array
    {
        $errors = [];

        // 验证必需配置
        $required = [
            'app.name',
            'security.secret_key',
            'database.driver'
        ];

        foreach ($required as $key) {
            if (!$this->has($key) || empty($this->get($key))) {
                $errors[] = "必需配置项 {$key} 未设置或为空";
            }
        }

        // 验证数据库配置
        if ($this->get('database.driver') === 'mysql') {
            $mysqlRequired = ['database.host', 'database.name', 'database.username'];
            foreach ($mysqlRequired as $key) {
                if (!$this->has($key) || empty($this->get($key))) {
                    $errors[] = "MySQL 数据库配置项 {$key} 未设置";
                }
            }
        }

        // 验证目录权限
        $directories = [
            DATA_PATH => '数据目录',
            ROOT_PATH . '/backups' => '备份目录'
        ];

        foreach ($directories as $dir => $name) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $errors[] = "{$name} {$dir} 创建失败";
                }
            } elseif (!is_writable($dir)) {
                $errors[] = "{$name} {$dir} 不可写";
            }
        }

        // 验证密码策略
        $minLength = $this->get('security.password_min_length', 8);
        if ($minLength < 6) {
            $errors[] = "密码最小长度不能少于6位";
        }

        return $errors;
    }

    /**
     * 导出配置
     */
    public function export(bool $includeSecrets = false): array
    {
        $config = $this->all();

        if (!$includeSecrets) {
            // 移除敏感信息
            $sensitiveKeys = [
                'security.secret_key',
                'database.password',
                'mail.password',
                'webdav.password',
                'ai.api_key',
                'admin.password'
            ];

            foreach ($sensitiveKeys as $key) {
                if (isset($config[$key])) {
                    $config[$key] = '***';
                }
            }
        }

        return $config;
    }

    /**
     * 从数组导入配置
     */
    public function import(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->setPermanent($key, $value);
        }
    }

    /**
     * 重置为默认配置
     */
    public function reset(): void
    {
        $this->config = [];
        $this->runtimeConfig = [];
        $this->initializeDefaults();
        $this->save();
    }

    /**
     * 获取部署方式特定配置
     */
    public function getDeploymentConfig(): array
    {
        $method = $this->get('deployment.method');

        switch ($method) {
            case 'cloudflare-workers':
                return [
                    'runtime' => 'edge',
                    'database_type' => 'cloudflare-d1',
                    'cache_type' => 'cloudflare-kv',
                    'file_storage' => 'cloudflare-r2',
                    'max_execution_time' => 30,
                    'memory_limit' => '128MB'
                ];

            case 'docker':
                return [
                    'runtime' => 'container',
                    'database_type' => 'sqlite',
                    'cache_type' => 'redis',
                    'file_storage' => 'local',
                    'max_execution_time' => 300,
                    'memory_limit' => '512MB'
                ];

            default: // php-native
                return [
                    'runtime' => 'native',
                    'database_type' => 'sqlite',
                    'cache_type' => 'file',
                    'file_storage' => 'local',
                    'max_execution_time' => (int)ini_get('max_execution_time'),
                    'memory_limit' => ini_get('memory_limit')
                ];
        }
    }

    /**
     * 析构函数 - 自动保存配置
     */
    public function __destruct()
    {
        if ($this->isDirty) {
            $this->save();
        }
    }
}