<?php
/**
 * OneBookNav 核心配置文件
 */

// 基础配置
define('DEBUG', $_ENV['DEBUG'] ?? false);
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');

// 数据库配置
define('DB_TYPE', $_ENV['DB_TYPE'] ?? 'sqlite');
define('DB_PATH', $_ENV['DB_PATH'] ?? APP_ROOT . '/data/database.db');
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'onebooknav');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// 安全配置
define('SECRET_KEY', $_ENV['SECRET_KEY'] ?? 'your-secret-key-here');
define('SESSION_LIFETIME', $_ENV['SESSION_LIFETIME'] ?? 86400); // 24小时
define('CSRF_TOKEN_LIFETIME', $_ENV['CSRF_TOKEN_LIFETIME'] ?? 3600); // 1小时

// 管理员配置
define('ADMIN_USERNAME', $_ENV['ADMIN_USERNAME'] ?? 'admin');
define('ADMIN_EMAIL', $_ENV['ADMIN_EMAIL'] ?? 'admin@example.com');
define('ADMIN_PASSWORD', $_ENV['ADMIN_PASSWORD'] ?? 'admin123');

// 功能配置
define('ENABLE_REGISTRATION', $_ENV['ENABLE_REGISTRATION'] ?? false);
define('ENABLE_INVITATION_CODE', $_ENV['ENABLE_INVITATION_CODE'] ?? true);
define('INVITATION_CODE_LENGTH', $_ENV['INVITATION_CODE_LENGTH'] ?? 8);

// 缓存配置
define('CACHE_ENABLED', $_ENV['CACHE_ENABLED'] ?? true);
define('CACHE_TYPE', $_ENV['CACHE_TYPE'] ?? 'file'); // file, redis, memcached
define('CACHE_TTL', $_ENV['CACHE_TTL'] ?? 3600);
define('CACHE_PATH', $_ENV['CACHE_PATH'] ?? APP_ROOT . '/data/cache');

// 备份配置
define('BACKUP_ENABLED', $_ENV['BACKUP_ENABLED'] ?? true);
define('BACKUP_INTERVAL', $_ENV['BACKUP_INTERVAL'] ?? 86400); // 24小时
define('BACKUP_KEEP_DAYS', $_ENV['BACKUP_KEEP_DAYS'] ?? 30);
define('BACKUP_PATH', $_ENV['BACKUP_PATH'] ?? APP_ROOT . '/backups');

// WebDAV 配置
define('WEBDAV_ENABLED', $_ENV['WEBDAV_ENABLED'] ?? false);
define('WEBDAV_URL', $_ENV['WEBDAV_URL'] ?? '');
define('WEBDAV_USERNAME', $_ENV['WEBDAV_USERNAME'] ?? '');
define('WEBDAV_PASSWORD', $_ENV['WEBDAV_PASSWORD'] ?? '');

// AI 搜索配置
define('AI_ENABLED', $_ENV['AI_ENABLED'] ?? false);
define('AI_API_URL', $_ENV['AI_API_URL'] ?? '');
define('AI_API_KEY', $_ENV['AI_API_KEY'] ?? '');
define('AI_MODEL', $_ENV['AI_MODEL'] ?? 'gpt-3.5-turbo');

// 主题配置
define('DEFAULT_THEME', $_ENV['DEFAULT_THEME'] ?? 'default');
define('THEME_PATH', APP_ROOT . '/themes');

// 上传配置
define('UPLOAD_MAX_SIZE', $_ENV['UPLOAD_MAX_SIZE'] ?? 10485760); // 10MB
define('UPLOAD_ALLOWED_TYPES', $_ENV['UPLOAD_ALLOWED_TYPES'] ?? 'jpg,jpeg,png,gif,ico,svg');
define('UPLOAD_PATH', APP_ROOT . '/public/uploads');

// API 配置
define('API_ENABLED', $_ENV['API_ENABLED'] ?? true);
define('API_RATE_LIMIT', $_ENV['API_RATE_LIMIT'] ?? 100); // 每小时请求次数

// 邮件配置（用于通知）
define('MAIL_ENABLED', $_ENV['MAIL_ENABLED'] ?? false);
define('MAIL_SMTP_HOST', $_ENV['MAIL_SMTP_HOST'] ?? '');
define('MAIL_SMTP_PORT', $_ENV['MAIL_SMTP_PORT'] ?? 587);
define('MAIL_SMTP_USER', $_ENV['MAIL_SMTP_USER'] ?? '');
define('MAIL_SMTP_PASS', $_ENV['MAIL_SMTP_PASS'] ?? '');

// 应用配置数组（用于动态配置）
return [
    // 应用基础信息
    'app' => [
        'name' => APP_NAME,
        'version' => APP_VERSION,
        'environment' => APP_ENV,
        'debug' => DEBUG,
        'timezone' => 'Asia/Shanghai',
        'locale' => 'zh-CN',
    ],

    // 数据库配置
    'database' => [
        'type' => DB_TYPE,
        'sqlite' => [
            'path' => DB_PATH,
        ],
        'mysql' => [
            'host' => DB_HOST,
            'database' => DB_NAME,
            'username' => DB_USER,
            'password' => DB_PASS,
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],
    ],

    // 安全配置
    'security' => [
        'secret_key' => SECRET_KEY,
        'session_lifetime' => SESSION_LIFETIME,
        'csrf_token_lifetime' => CSRF_TOKEN_LIFETIME,
        'password_min_length' => 6,
        'max_login_attempts' => 5,
        'lockout_duration' => 900, // 15分钟
    ],

    // 功能开关
    'features' => [
        'registration' => ENABLE_REGISTRATION,
        'invitation_code' => ENABLE_INVITATION_CODE,
        'ai_search' => AI_ENABLED,
        'backup' => BACKUP_ENABLED,
        'webdav' => WEBDAV_ENABLED,
        'api' => API_ENABLED,
        'mail' => MAIL_ENABLED,
    ],

    // 缓存配置
    'cache' => [
        'enabled' => CACHE_ENABLED,
        'type' => CACHE_TYPE,
        'ttl' => CACHE_TTL,
        'path' => CACHE_PATH,
    ],

    // 备份配置
    'backup' => [
        'enabled' => BACKUP_ENABLED,
        'interval' => BACKUP_INTERVAL,
        'keep_days' => BACKUP_KEEP_DAYS,
        'path' => BACKUP_PATH,
        'webdav' => [
            'enabled' => WEBDAV_ENABLED,
            'url' => WEBDAV_URL,
            'username' => WEBDAV_USERNAME,
            'password' => WEBDAV_PASSWORD,
        ],
    ],

    // AI 配置
    'ai' => [
        'enabled' => AI_ENABLED,
        'api_url' => AI_API_URL,
        'api_key' => AI_API_KEY,
        'model' => AI_MODEL,
        'temperature' => 0.7,
        'max_tokens' => 1000,
    ],

    // 主题配置
    'theme' => [
        'default' => DEFAULT_THEME,
        'path' => THEME_PATH,
        'cache_enabled' => true,
    ],

    // 上传配置
    'upload' => [
        'max_size' => UPLOAD_MAX_SIZE,
        'allowed_types' => explode(',', UPLOAD_ALLOWED_TYPES),
        'path' => UPLOAD_PATH,
    ],

    // API 配置
    'api' => [
        'enabled' => API_ENABLED,
        'rate_limit' => API_RATE_LIMIT,
        'version' => 'v1',
        'prefix' => '/api',
    ],
];