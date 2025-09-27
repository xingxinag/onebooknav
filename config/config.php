<?php
/**
 * OneBookNav Configuration
 *
 * This is the main configuration file for OneBookNav.
 * Modify the settings below according to your environment.
 */

// Database Configuration
define('DB_TYPE', 'sqlite'); // sqlite, mysql, pgsql
define('DB_HOST', 'localhost');
define('DB_NAME', 'onebooknav');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// SQLite specific settings (when DB_TYPE is 'sqlite')
define('DB_FILE', __DIR__ . '/../data/onebooknav.db');

// Site Configuration
define('SITE_TITLE', 'OneBookNav');
define('SITE_DESCRIPTION', 'Your Personal Navigation Hub');
define('SITE_KEYWORDS', 'bookmarks, navigation, links, personal');
define('SITE_URL', 'http://localhost');
define('ADMIN_EMAIL', 'admin@example.com');

// Security Settings
define('SESSION_NAME', 'onebooknav_session');
define('SESSION_LIFETIME', 86400 * 30); // 30 days
define('CSRF_TOKEN_NAME', '_token');

// Features
define('ALLOW_REGISTRATION', true);
define('REQUIRE_EMAIL_VERIFICATION', false);
define('ENABLE_WEBDAV_BACKUP', true);
define('ENABLE_API', true);
define('ENABLE_PWA', true);

// Default Admin Account (auto-created on first run)
// SECURITY WARNING: Change these defaults before deployment
define('DEFAULT_ADMIN_USERNAME', getenv('ADMIN_USERNAME') ?: 'admin');
define('DEFAULT_ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'admin679'); // Must be changed in production
define('DEFAULT_ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'admin@example.com');
define('AUTO_CREATE_ADMIN', getenv('AUTO_CREATE_ADMIN') !== 'false');

// File Upload Settings
define('UPLOAD_MAX_SIZE', 1024 * 1024 * 2); // 2MB
define('ALLOWED_ICON_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico']);
define('UPLOAD_PATH', __DIR__ . '/../data/uploads/');

// WebDAV Settings (for backup)
define('WEBDAV_ENABLED', false);
define('WEBDAV_URL', '');
define('WEBDAV_USERNAME', '');
define('WEBDAV_PASSWORD', '');
define('WEBDAV_AUTO_BACKUP', false);

// Cache Settings
define('CACHE_ENABLED', true);
define('CACHE_TYPE', 'file'); // file, redis, memcached
define('CACHE_PATH', __DIR__ . '/../data/cache/');
define('CACHE_TTL', 3600); // 1 hour

// Backup Settings
define('BACKUP_PATH', __DIR__ . '/../data/backups/');
define('BACKUP_MAX_FILES', 10);
define('AUTO_BACKUP_ENABLED', true);
define('AUTO_BACKUP_INTERVAL', 86400 * 7); // Weekly

// Link Checking
define('LINK_CHECK_ENABLED', true);
define('LINK_CHECK_TIMEOUT', 10);
define('LINK_CHECK_USER_AGENT', 'OneBookNav/1.0 (+http://localhost)');

// Theme Settings
define('DEFAULT_THEME', 'default');
define('THEME_PATH', __DIR__ . '/../assets/themes/');

// Debug & Development
define('DEBUG_MODE', false);
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARN, ERROR
define('LOG_PATH', __DIR__ . '/../data/logs/');

// API Settings
define('API_RATE_LIMIT', 100); // requests per hour
define('API_VERSION', 'v1');

// PWA Settings
define('PWA_NAME', 'OneBookNav');
define('PWA_SHORT_NAME', 'OBN');
define('PWA_THEME_COLOR', '#007bff');
define('PWA_BACKGROUND_COLOR', '#ffffff');

// Timezone
define('TIMEZONE', 'UTC');
date_default_timezone_set(TIMEZONE);

// Error Reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
?>