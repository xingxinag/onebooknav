-- OneBookNav Cloudflare D1 数据库结构
-- 为边缘计算优化的数据库架构

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('user', 'admin', 'superadmin')),
    avatar_url VARCHAR(255),
    settings TEXT DEFAULT '{}', -- JSON格式的用户设置
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    is_active BOOLEAN DEFAULT 1,
    email_verified BOOLEAN DEFAULT 0
);

-- 分类表
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(100),
    color VARCHAR(7) DEFAULT '#007bff',
    parent_id INTEGER,
    user_id INTEGER,
    sort_order INTEGER DEFAULT 0,
    is_public BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 书签表
CREATE TABLE IF NOT EXISTS bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    description TEXT,
    category_id INTEGER,
    tags TEXT DEFAULT '[]', -- JSON数组格式
    icon_url VARCHAR(255),
    user_id INTEGER NOT NULL,
    click_count INTEGER DEFAULT 0,
    is_favorite BOOLEAN DEFAULT 0,
    is_private BOOLEAN DEFAULT 0,
    is_alive BOOLEAN DEFAULT 1,
    last_checked_at DATETIME,
    last_clicked_at DATETIME,
    sort_order INTEGER DEFAULT 0,
    metadata TEXT DEFAULT '{}', -- JSON格式的元数据
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 会话表
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id VARCHAR(64) UNIQUE NOT NULL,
    user_id INTEGER NOT NULL,
    data TEXT DEFAULT '{}',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 标签表
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    color VARCHAR(7) DEFAULT '#6c757d',
    user_id INTEGER,
    usage_count INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 书签标签关联表
CREATE TABLE IF NOT EXISTS bookmark_tags (
    bookmark_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (bookmark_id, tag_id),
    FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- 分享链接表
CREATE TABLE IF NOT EXISTS shared_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bookmark_id INTEGER NOT NULL,
    share_token VARCHAR(32) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    expires_at DATETIME,
    view_count INTEGER DEFAULT 0,
    max_views INTEGER,
    created_by INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- 活动日志表
CREATE TABLE IF NOT EXISTS activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action VARCHAR(50) NOT NULL,
    resource_type VARCHAR(50),
    resource_id INTEGER,
    details TEXT DEFAULT '{}',
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 系统设置表
CREATE TABLE IF NOT EXISTS system_settings (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT,
    type VARCHAR(20) DEFAULT 'string' CHECK (type IN ('string', 'number', 'boolean', 'json')),
    description TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 备份记录表
CREATE TABLE IF NOT EXISTS backup_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(255) NOT NULL,
    file_size INTEGER,
    backup_type VARCHAR(20) DEFAULT 'full' CHECK (backup_type IN ('full', 'incremental')),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed')),
    storage_location VARCHAR(255),
    checksum VARCHAR(64),
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    error_message TEXT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 索引创建
CREATE INDEX IF NOT EXISTS idx_bookmarks_user_id ON bookmarks(user_id);
CREATE INDEX IF NOT EXISTS idx_bookmarks_category_id ON bookmarks(category_id);
CREATE INDEX IF NOT EXISTS idx_bookmarks_created_at ON bookmarks(created_at);
CREATE INDEX IF NOT EXISTS idx_bookmarks_updated_at ON bookmarks(updated_at);
CREATE INDEX IF NOT EXISTS idx_bookmarks_title ON bookmarks(title);
CREATE INDEX IF NOT EXISTS idx_bookmarks_url ON bookmarks(url);
CREATE INDEX IF NOT EXISTS idx_bookmarks_click_count ON bookmarks(click_count);
CREATE INDEX IF NOT EXISTS idx_bookmarks_is_favorite ON bookmarks(is_favorite);

CREATE INDEX IF NOT EXISTS idx_categories_user_id ON categories(user_id);
CREATE INDEX IF NOT EXISTS idx_categories_parent_id ON categories(parent_id);
CREATE INDEX IF NOT EXISTS idx_categories_sort_order ON categories(sort_order);

CREATE INDEX IF NOT EXISTS idx_sessions_session_id ON sessions(session_id);
CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);

CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(name);
CREATE INDEX IF NOT EXISTS idx_tags_user_id ON tags(user_id);

CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id ON activity_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at ON activity_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs(action);

CREATE INDEX IF NOT EXISTS idx_shared_links_share_token ON shared_links(share_token);
CREATE INDEX IF NOT EXISTS idx_shared_links_bookmark_id ON shared_links(bookmark_id);
CREATE INDEX IF NOT EXISTS idx_shared_links_expires_at ON shared_links(expires_at);

-- 全文搜索索引（如果D1支持）
-- CREATE VIRTUAL TABLE IF NOT EXISTS bookmarks_fts USING fts5(title, description, url, content='bookmarks', content_rowid='id');

-- 插入默认系统设置
INSERT OR IGNORE INTO system_settings (key, value, type, description) VALUES
('app_name', 'OneBookNav', 'string', '应用名称'),
('app_version', '1.0.0', 'string', '应用版本'),
('maintenance_mode', 'false', 'boolean', '维护模式'),
('registration_enabled', 'false', 'boolean', '是否允许用户注册'),
('max_bookmarks_per_user', '10000', 'number', '每用户最大书签数'),
('max_categories_per_user', '100', 'number', '每用户最大分类数'),
('backup_enabled', 'true', 'boolean', '是否启用自动备份'),
('backup_interval', '24', 'number', '备份间隔（小时）'),
('dead_link_check_enabled', 'true', 'boolean', '是否启用死链检查'),
('dead_link_check_interval', '168', 'number', '死链检查间隔（小时）'),
('ai_search_enabled', 'false', 'boolean', '是否启用AI搜索'),
('max_upload_size', '5242880', 'number', '最大上传文件大小（字节）'),
('session_lifetime', '86400', 'number', '会话生命周期（秒）'),
('rate_limit_enabled', 'true', 'boolean', '是否启用频率限制'),
('rate_limit_max_requests', '100', 'number', '频率限制最大请求数/分钟');

-- 创建默认管理员用户（密码：admin123，实际部署时应修改）
INSERT OR IGNORE INTO users (username, email, password_hash, role, is_active, email_verified) VALUES
('admin', 'admin@onebooknav.com', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'superadmin', 1, 1);

-- 创建默认分类
INSERT OR IGNORE INTO categories (name, description, icon, color, user_id, sort_order, is_public) VALUES
('技术', '技术相关网站', 'fas fa-code', '#007bff', 1, 1, 1),
('娱乐', '娱乐休闲网站', 'fas fa-gamepad', '#28a745', 1, 2, 1),
('学习', '学习教育网站', 'fas fa-graduation-cap', '#17a2b8', 1, 3, 1),
('工具', '实用工具网站', 'fas fa-tools', '#ffc107', 1, 4, 1),
('新闻', '新闻资讯网站', 'fas fa-newspaper', '#dc3545', 1, 5, 1),
('社交', '社交媒体网站', 'fas fa-users', '#6f42c1', 1, 6, 1);

-- 创建默认书签
INSERT OR IGNORE INTO bookmarks (title, url, description, category_id, user_id, icon_url, sort_order) VALUES
('Google', 'https://www.google.com', '全球最大的搜索引擎', 4, 1, 'https://www.google.com/favicon.ico', 1),
('GitHub', 'https://github.com', '全球最大的代码托管平台', 1, 1, 'https://github.com/favicon.ico', 2),
('Stack Overflow', 'https://stackoverflow.com', '程序员问答社区', 1, 1, 'https://stackoverflow.com/favicon.ico', 3),
('YouTube', 'https://www.youtube.com', '全球最大的视频分享平台', 2, 1, 'https://www.youtube.com/favicon.ico', 4),
('MDN Web Docs', 'https://developer.mozilla.org', 'Web开发者文档', 3, 1, 'https://developer.mozilla.org/favicon.ico', 5);

-- 创建触发器来自动更新updated_at字段
CREATE TRIGGER IF NOT EXISTS update_users_updated_at
    AFTER UPDATE ON users
    BEGIN
        UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS update_categories_updated_at
    AFTER UPDATE ON categories
    BEGIN
        UPDATE categories SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS update_bookmarks_updated_at
    AFTER UPDATE ON bookmarks
    BEGIN
        UPDATE bookmarks SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS update_system_settings_updated_at
    AFTER UPDATE ON system_settings
    BEGIN
        UPDATE system_settings SET updated_at = CURRENT_TIMESTAMP WHERE key = NEW.key;
    END;

-- 清理过期会话的触发器
CREATE TRIGGER IF NOT EXISTS cleanup_expired_sessions
    AFTER INSERT ON sessions
    BEGIN
        DELETE FROM sessions WHERE expires_at < CURRENT_TIMESTAMP;
    END;