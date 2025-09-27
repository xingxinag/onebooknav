-- Migration 001: Initial Enhanced Schema
-- This migration creates the enhanced database structure with merged features

BEGIN TRANSACTION;

-- Drop existing tables if they exist (for clean migration)
DROP TABLE IF EXISTS user_preferences;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS backup_logs;
DROP TABLE IF EXISTS import_logs;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS click_logs;
DROP TABLE IF EXISTS scheduled_checks;
DROP TABLE IF EXISTS dead_link_checks;
DROP TABLE IF EXISTS invite_code_uses;
DROP TABLE IF EXISTS invite_codes;
DROP TABLE IF EXISTS bookmarks;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;

-- Create tables with enhanced features
-- (Copy the entire schema from schema-enhanced.sql)

-- Users table with enhanced permissions
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    is_active BOOLEAN DEFAULT 1,
    avatar_url VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    login_count INTEGER DEFAULT 0
);

-- Invite codes table
CREATE TABLE invite_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    created_by INTEGER NOT NULL,
    expires_at DATETIME,
    max_uses INTEGER DEFAULT 1,
    used_count INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Invite code usage tracking
CREATE TABLE invite_code_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER NOT NULL,
    used_by INTEGER NOT NULL,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Enhanced categories table
CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fas fa-folder',
    color VARCHAR(20) DEFAULT '#007bff',
    parent_id INTEGER,
    user_id INTEGER NOT NULL,
    sort_order INTEGER DEFAULT 0,
    weight INTEGER DEFAULT 0,
    is_private BOOLEAN DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Enhanced bookmarks table
CREATE TABLE bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    backup_url TEXT,
    description TEXT,
    keywords TEXT,
    tags TEXT,
    icon_url VARCHAR(500),
    category_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    sort_order INTEGER DEFAULT 0,
    weight INTEGER DEFAULT 0,
    click_count INTEGER DEFAULT 0,
    is_private BOOLEAN DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    is_working BOOLEAN DEFAULT 1,
    main_url_status INTEGER,
    backup_url_status INTEGER,
    last_checked DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Dead link check logs
CREATE TABLE dead_link_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bookmark_id INTEGER NOT NULL,
    main_status INTEGER,
    backup_status INTEGER,
    response_time FLOAT,
    error_message TEXT,
    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id) ON DELETE CASCADE
);

-- Scheduled checks
CREATE TABLE scheduled_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    check_type VARCHAR(50) NOT NULL,
    next_check_at DATETIME NOT NULL,
    interval_type VARCHAR(20) NOT NULL,
    last_run_at DATETIME,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, check_type)
);

-- Click tracking
CREATE TABLE click_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bookmark_id INTEGER NOT NULL,
    user_id INTEGER,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referrer TEXT,
    clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Settings table
CREATE TABLE settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(20) DEFAULT 'string',
    is_public BOOLEAN DEFAULT 0,
    category VARCHAR(50) DEFAULT 'general',
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Session management
CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Additional tables for enhanced features...
CREATE TABLE import_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    import_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(255),
    total_items INTEGER DEFAULT 0,
    imported_items INTEGER DEFAULT 0,
    failed_items INTEGER DEFAULT 0,
    error_log TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE backup_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    backup_type VARCHAR(50) NOT NULL,
    file_path VARCHAR(500),
    file_size INTEGER,
    status VARCHAR(20) DEFAULT 'created',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    type VARCHAR(20) DEFAULT 'info',
    is_active BOOLEAN DEFAULT 1,
    show_to_guests BOOLEAN DEFAULT 1,
    auto_hide_days INTEGER DEFAULT 7,
    created_by INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, preference_key)
);

-- Create indexes
CREATE INDEX idx_bookmarks_user_category ON bookmarks(user_id, category_id);
CREATE INDEX idx_bookmarks_url ON bookmarks(url);
CREATE INDEX idx_bookmarks_title ON bookmarks(title);
CREATE INDEX idx_bookmarks_sort ON bookmarks(sort_order);
CREATE INDEX idx_bookmarks_clicks ON bookmarks(click_count);
CREATE INDEX idx_bookmarks_status ON bookmarks(is_working);

CREATE INDEX idx_categories_user ON categories(user_id);
CREATE INDEX idx_categories_parent ON categories(parent_id);
CREATE INDEX idx_categories_sort ON categories(sort_order);

CREATE INDEX idx_dead_link_checks_bookmark ON dead_link_checks(bookmark_id);
CREATE INDEX idx_dead_link_checks_date ON dead_link_checks(checked_at);

CREATE INDEX idx_click_logs_bookmark ON click_logs(bookmark_id);
CREATE INDEX idx_click_logs_user ON click_logs(user_id);
CREATE INDEX idx_click_logs_date ON click_logs(clicked_at);

CREATE INDEX idx_invite_codes_code ON invite_codes(code);
CREATE INDEX idx_invite_codes_active ON invite_codes(is_active);

CREATE INDEX idx_user_sessions_user ON user_sessions(user_id);
CREATE INDEX idx_user_sessions_expires ON user_sessions(expires_at);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, setting_type, is_public, category, description) VALUES
('site_name', 'OneBookNav Enhanced', 'string', 1, 'general', 'Site name'),
('site_description', 'Enhanced bookmark management system with AI search and advanced features', 'string', 1, 'general', 'Site description'),
('site_logo', '', 'string', 1, 'general', 'Site logo URL'),
('site_favicon', '', 'string', 1, 'general', 'Site favicon URL'),
('allow_registration', '0', 'boolean', 0, 'user', 'Allow new user registration'),
('require_invite_code', '1', 'boolean', 0, 'user', 'Require invite code for registration'),
('default_user_role', 'user', 'string', 0, 'user', 'Default role for new users'),
('enable_dead_link_check', '1', 'boolean', 0, 'features', 'Enable automatic dead link checking'),
('dead_link_check_interval', 'weekly', 'string', 0, 'features', 'Dead link check interval'),
('enable_click_tracking', '1', 'boolean', 0, 'features', 'Enable click tracking'),
('max_bookmarks_per_user', '1000', 'integer', 0, 'limits', 'Maximum bookmarks per user'),
('max_categories_per_user', '50', 'integer', 0, 'limits', 'Maximum categories per user'),
('session_lifetime', '2592000', 'integer', 0, 'security', 'Session lifetime in seconds (30 days)'),
('backup_retention_days', '30', 'integer', 0, 'backup', 'Backup retention period in days'),
('theme', 'default', 'string', 1, 'appearance', 'Default theme'),
('enable_ai_search', '1', 'boolean', 1, 'features', 'Enable AI-powered search'),
('enable_drag_sort', '1', 'boolean', 1, 'features', 'Enable drag and drop sorting'),
('show_backup_urls', '1', 'boolean', 1, 'features', 'Show backup URL options'),
('announcement_text', 'Welcome to OneBookNav Enhanced! Now featuring AI search, drag & drop sorting, and dead link checking.', 'string', 1, 'general', 'Site announcement text'),
('announcement_type', 'info', 'string', 1, 'general', 'Announcement type');

-- Create default admin user (password: admin123)
INSERT INTO users (id, username, email, password_hash, role) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin');

-- Create default category for admin
INSERT INTO categories (id, name, description, icon, color, user_id, sort_order) VALUES
(1, 'Featured', 'Featured bookmarks', 'fas fa-star', '#ffc107', 1, 1),
(2, 'Tools', 'Development and productivity tools', 'fas fa-tools', '#28a745', 1, 2),
(3, 'Resources', 'Learning resources and documentation', 'fas fa-book', '#17a2b8', 1, 3);

-- Create some example bookmarks
INSERT INTO bookmarks (title, url, description, category_id, user_id, sort_order, tags) VALUES
('GitHub', 'https://github.com', 'The world''s leading software development platform', 2, 1, 1, 'development,git,code'),
('Stack Overflow', 'https://stackoverflow.com', 'The largest online community for developers', 3, 1, 1, 'help,programming,qa'),
('MDN Web Docs', 'https://developer.mozilla.org', 'Resources for developers, by developers', 3, 1, 2, 'documentation,web,html,css,javascript');

COMMIT;