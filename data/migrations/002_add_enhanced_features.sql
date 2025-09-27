-- Migration 002: Add Enhanced Features for Existing Installations
-- This migration adds new columns and tables for existing OneBookNav installations

BEGIN TRANSACTION;

-- Add new columns to existing tables if they don't exist

-- Add new columns to users table
ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user';
ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT 1;
ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255);
ALTER TABLE users ADD COLUMN last_login DATETIME;
ALTER TABLE users ADD COLUMN login_count INTEGER DEFAULT 0;

-- Add new columns to categories table
ALTER TABLE categories ADD COLUMN description TEXT;
ALTER TABLE categories ADD COLUMN parent_id INTEGER;
ALTER TABLE categories ADD COLUMN sort_order INTEGER DEFAULT 0;
ALTER TABLE categories ADD COLUMN is_active BOOLEAN DEFAULT 1;

-- Add new columns to bookmarks table
ALTER TABLE bookmarks ADD COLUMN backup_url TEXT;
ALTER TABLE bookmarks ADD COLUMN tags TEXT;
ALTER TABLE bookmarks ADD COLUMN sort_order INTEGER DEFAULT 0;
ALTER TABLE bookmarks ADD COLUMN is_working BOOLEAN DEFAULT 1;
ALTER TABLE bookmarks ADD COLUMN main_url_status INTEGER;
ALTER TABLE bookmarks ADD COLUMN backup_url_status INTEGER;
ALTER TABLE bookmarks ADD COLUMN last_checked DATETIME;

-- Create new tables if they don't exist

-- Invite codes table
CREATE TABLE IF NOT EXISTS invite_codes (
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
CREATE TABLE IF NOT EXISTS invite_code_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER NOT NULL,
    used_by INTEGER NOT NULL,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Dead link check logs
CREATE TABLE IF NOT EXISTS dead_link_checks (
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
CREATE TABLE IF NOT EXISTS scheduled_checks (
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
CREATE TABLE IF NOT EXISTS click_logs (
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

-- User sessions
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Import logs
CREATE TABLE IF NOT EXISTS import_logs (
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

-- Backup logs
CREATE TABLE IF NOT EXISTS backup_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    backup_type VARCHAR(50) NOT NULL,
    file_path VARCHAR(500),
    file_size INTEGER,
    status VARCHAR(20) DEFAULT 'created',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
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

-- User preferences
CREATE TABLE IF NOT EXISTS user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, preference_key)
);

-- Create new indexes
CREATE INDEX IF NOT EXISTS idx_bookmarks_sort ON bookmarks(sort_order);
CREATE INDEX IF NOT EXISTS idx_bookmarks_status ON bookmarks(is_working);
CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories(parent_id);
CREATE INDEX IF NOT EXISTS idx_categories_sort ON categories(sort_order);
CREATE INDEX IF NOT EXISTS idx_dead_link_checks_bookmark ON dead_link_checks(bookmark_id);
CREATE INDEX IF NOT EXISTS idx_dead_link_checks_date ON dead_link_checks(checked_at);
CREATE INDEX IF NOT EXISTS idx_click_logs_bookmark ON click_logs(bookmark_id);
CREATE INDEX IF NOT EXISTS idx_click_logs_user ON click_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_click_logs_date ON click_logs(clicked_at);
CREATE INDEX IF NOT EXISTS idx_invite_codes_code ON invite_codes(code);
CREATE INDEX IF NOT EXISTS idx_invite_codes_active ON invite_codes(is_active);
CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires ON user_sessions(expires_at);

-- Add new settings for enhanced features
INSERT OR IGNORE INTO settings (setting_key, setting_value, setting_type, is_public, category, description) VALUES
('enable_ai_search', '1', 'boolean', 1, 'features', 'Enable AI-powered search'),
('enable_drag_sort', '1', 'boolean', 1, 'features', 'Enable drag and drop sorting'),
('show_backup_urls', '1', 'boolean', 1, 'features', 'Show backup URL options'),
('enable_dead_link_check', '1', 'boolean', 0, 'features', 'Enable automatic dead link checking'),
('dead_link_check_interval', 'weekly', 'string', 0, 'features', 'Dead link check interval'),
('enable_click_tracking', '1', 'boolean', 0, 'features', 'Enable click tracking'),
('require_invite_code', '1', 'boolean', 0, 'user', 'Require invite code for registration'),
('max_bookmarks_per_user', '1000', 'integer', 0, 'limits', 'Maximum bookmarks per user'),
('max_categories_per_user', '50', 'integer', 0, 'limits', 'Maximum categories per user'),
('backup_retention_days', '30', 'integer', 0, 'backup', 'Backup retention period in days');

-- Update existing admin user to superadmin role if exists
UPDATE users SET role = 'superadmin' WHERE username = 'admin' AND role != 'superadmin';

-- Migrate existing bookmark weights to sort_order
UPDATE bookmarks SET sort_order = weight WHERE sort_order = 0 AND weight > 0;

-- Migrate existing category weights to sort_order
UPDATE categories SET sort_order = weight WHERE sort_order = 0 AND weight > 0;

COMMIT;