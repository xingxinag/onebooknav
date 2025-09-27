<?php
/**
 * Database Management Class
 * Unified database abstraction for SQLite, MySQL, and PostgreSQL
 */

class Database {
    private static $instance = null;
    private $pdo = null;
    private $config = [];

    private function __construct() {
        $this->initializeConfig();
        $this->connect();
        $this->createTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeConfig() {
        $this->config = [
            'type' => DB_TYPE,
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASS,
            'charset' => DB_CHARSET,
            'file' => DB_FILE
        ];
    }

    private function connect() {
        try {
            switch ($this->config['type']) {
                case 'sqlite':
                    $this->connectSQLite();
                    break;
                case 'mysql':
                    $this->connectMySQL();
                    break;
                case 'pgsql':
                    $this->connectPostgreSQL();
                    break;
                default:
                    throw new Exception('Unsupported database type: ' . $this->config['type']);
            }

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    private function connectSQLite() {
        // Ensure data directory exists
        $dataDir = dirname($this->config['file']);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $dsn = 'sqlite:' . $this->config['file'];
        $this->pdo = new PDO($dsn);

        // Enable foreign key constraints
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
    }

    private function connectMySQL() {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['name'],
            $this->config['charset']
        );

        $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['charset']}",
            PDO::ATTR_PERSISTENT => false
        ]);
    }

    private function connectPostgreSQL() {
        $dsn = sprintf(
            'pgsql:host=%s;dbname=%s',
            $this->config['host'],
            $this->config['name']
        );

        $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass']);
    }

    private function createTables() {
        $tables = $this->getTableSchemas();

        foreach ($tables as $table => $sql) {
            // Check if table exists
            if (!$this->tableExists($table)) {
                $this->pdo->exec($sql);
            }
        }

        // Insert default settings
        $this->insertDefaultSettings();
    }

    private function tableExists($tableName) {
        try {
            switch ($this->config['type']) {
                case 'sqlite':
                    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
                    break;
                case 'mysql':
                    $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?";
                    break;
                case 'pgsql':
                    $sql = "SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename=?";
                    break;
            }

            $stmt = $this->pdo->prepare($sql);
            if ($this->config['type'] === 'mysql') {
                $stmt->execute([$this->config['name'], $tableName]);
            } else {
                $stmt->execute([$tableName]);
            }

            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getTableSchemas() {
        $autoIncrement = $this->config['type'] === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT';
        $timestampDefault = $this->config['type'] === 'sqlite' ? "datetime('now')" : 'CURRENT_TIMESTAMP';

        return [
            'users' => "
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY $autoIncrement,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    email VARCHAR(100),
                    role VARCHAR(20) DEFAULT 'user',
                    avatar_url VARCHAR(255),
                    created_at TIMESTAMP DEFAULT $timestampDefault,
                    last_login TIMESTAMP,
                    is_active BOOLEAN DEFAULT 1,
                    settings TEXT
                )",

            'categories' => "
                CREATE TABLE categories (
                    id INTEGER PRIMARY KEY $autoIncrement,
                    name VARCHAR(100) NOT NULL,
                    parent_id INTEGER DEFAULT NULL,
                    user_id INTEGER NOT NULL,
                    icon VARCHAR(50),
                    color VARCHAR(7),
                    weight INTEGER DEFAULT 0,
                    is_private BOOLEAN DEFAULT 0,
                    created_at TIMESTAMP DEFAULT $timestampDefault,
                    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )",

            'bookmarks' => "
                CREATE TABLE bookmarks (
                    id INTEGER PRIMARY KEY $autoIncrement,
                    title VARCHAR(255) NOT NULL,
                    url TEXT NOT NULL,
                    description TEXT,
                    keywords TEXT,
                    icon_url VARCHAR(255),
                    category_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    weight INTEGER DEFAULT 0,
                    is_private BOOLEAN DEFAULT 0,
                    click_count INTEGER DEFAULT 0,
                    last_checked TIMESTAMP,
                    status_code INTEGER,
                    created_at TIMESTAMP DEFAULT $timestampDefault,
                    updated_at TIMESTAMP DEFAULT $timestampDefault,
                    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )",

            'settings' => "
                CREATE TABLE settings (
                    id INTEGER PRIMARY KEY $autoIncrement,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    setting_type VARCHAR(20) DEFAULT 'string',
                    is_public BOOLEAN DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT $timestampDefault
                )",

            'backups' => "
                CREATE TABLE backups (
                    id INTEGER PRIMARY KEY $autoIncrement,
                    filename VARCHAR(255) NOT NULL,
                    size INTEGER NOT NULL,
                    backup_type VARCHAR(20) DEFAULT 'full',
                    created_by INTEGER NOT NULL,
                    created_at TIMESTAMP DEFAULT $timestampDefault,
                    FOREIGN KEY (created_by) REFERENCES users(id)
                )",

            'sessions' => "
                CREATE TABLE sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    payload TEXT,
                    last_activity TIMESTAMP DEFAULT $timestampDefault,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )"
        ];
    }

    private function insertDefaultSettings() {
        $defaultSettings = [
            ['site_title', SITE_TITLE, 'string', 1],
            ['site_description', SITE_DESCRIPTION, 'string', 1],
            ['site_keywords', SITE_KEYWORDS, 'string', 1],
            ['allow_registration', ALLOW_REGISTRATION ? '1' : '0', 'boolean', 1],
            ['default_theme', DEFAULT_THEME, 'string', 1],
            ['version', '1.0.0', 'string', 1],
            ['installation_date', date('Y-m-d H:i:s'), 'string', 0]
        ];

        foreach ($defaultSettings as $setting) {
            $sql = "INSERT OR IGNORE INTO settings (setting_key, setting_value, setting_type, is_public) VALUES (?, ?, ?, ?)";
            if ($this->config['type'] !== 'sqlite') {
                $sql = str_replace('INSERT OR IGNORE', 'INSERT IGNORE', $sql);
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($setting);
        }

        // Create default admin account if enabled and no admin exists
        if (defined('AUTO_CREATE_ADMIN') && AUTO_CREATE_ADMIN) {
            $this->createDefaultAdminAccount();
        }
    }

    private function createDefaultAdminAccount() {
        try {
            // Check if any admin user exists
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin')");
            $stmt->execute();
            $adminCount = $stmt->fetchColumn();

            if ($adminCount == 0) {
                // Create default admin user
                $passwordHash = password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_ARGON2ID);

                $sql = "INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, " .
                       ($this->config['type'] === 'sqlite' ? "datetime('now')" : "NOW()") . ")";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    DEFAULT_ADMIN_USERNAME,
                    DEFAULT_ADMIN_EMAIL,
                    $passwordHash,
                    'admin'
                ]);

                error_log("OneBookNav: Default admin account created - Username: " . DEFAULT_ADMIN_USERNAME);
            }
        } catch (Exception $e) {
            error_log("OneBookNav: Failed to create default admin account - " . $e->getMessage());
        }
    }

    public function getPDO() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception('Query failed: ' . $e->getMessage());
        }
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);

        $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES ($placeholders)";

        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->execute();
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $fields = [];
        foreach (array_keys($data) as $field) {
            $fields[] = "$field = :$field";
        }

        $sql = "UPDATE $table SET " . implode(', ', $fields) . " WHERE $where";

        $stmt = $this->pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        foreach ($whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->execute();
    }

    public function delete($table, $where, $whereParams = []) {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->pdo->prepare($sql);

        foreach ($whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->execute();
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollback();
    }

    public function getVersion() {
        $result = $this->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'version'");
        return $result ? $result['setting_value'] : '1.0.0';
    }

    public function backup($filename = null) {
        if (!$filename) {
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        }

        $backupPath = BACKUP_PATH . $filename;

        // Ensure backup directory exists
        if (!is_dir(BACKUP_PATH)) {
            mkdir(BACKUP_PATH, 0755, true);
        }

        if ($this->config['type'] === 'sqlite') {
            // Simple SQLite backup by copying the file
            $sourceFile = $this->config['file'];
            $backupFile = BACKUP_PATH . str_replace('.sql', '.db', $filename);

            if (copy($sourceFile, $backupFile)) {
                // Record backup in database
                $this->insert('backups', [
                    'filename' => str_replace('.sql', '.db', $filename),
                    'size' => filesize($backupFile),
                    'created_by' => $_SESSION['user_id'] ?? 1
                ]);
                return $backupFile;
            }
        }

        return false;
    }
}
?>