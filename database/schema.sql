-- OneBookNav 数据库结构
-- 融合 BookNav 和 OneNav 功能的现代化导航系统
-- 支持三种部署方式：PHP原生、Docker容器、Cloudflare Workers
-- 完全实现"终极.txt"要求的统一核心数据模型

-- SQLite 优化配置
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA cache_size = 64000;
PRAGMA temp_store = MEMORY;
PRAGMA auto_vacuum = INCREMENTAL;

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    salt VARCHAR(32) NOT NULL,
    role ENUM('admin', 'user', 'guest') DEFAULT 'user',
    status ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    login_attempts INTEGER DEFAULT 0,
    locked_until DATETIME NULL,
    avatar VARCHAR(255) NULL,
    preferences TEXT NULL, -- JSON 格式的用户偏好设置
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 用户会话表
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 分类表
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(100) NULL,
    color VARCHAR(7) NULL, -- 颜色代码，如 #FF5733
    sort_order INTEGER DEFAULT 0,
    parent_id INTEGER NULL, -- 支持子分类
    is_active BOOLEAN DEFAULT 1,
    user_id INTEGER NULL, -- 支持用户自定义分类
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 网站/书签表
CREATE TABLE IF NOT EXISTS websites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(200) NOT NULL,
    url TEXT NOT NULL,
    description TEXT NULL,
    category_id INTEGER NOT NULL,
    user_id INTEGER NULL, -- 网站所有者，NULL表示公共网站
    icon VARCHAR(255) NULL,
    favicon_url TEXT NULL,
    sort_order INTEGER DEFAULT 0,
    clicks INTEGER DEFAULT 0,
    weight INTEGER DEFAULT 0, -- 权重，用于排序
    status ENUM('active', 'inactive', 'pending', 'broken') DEFAULT 'active',
    is_private BOOLEAN DEFAULT 0, -- 是否私有
    is_featured BOOLEAN DEFAULT 0, -- 是否推荐
    last_checked_at DATETIME NULL, -- 最后检查时间
    check_status ENUM('ok', 'error', 'timeout', 'not_found') NULL,
    response_time INTEGER NULL, -- 响应时间（毫秒）
    http_status INTEGER NULL, -- HTTP 状态码
    properties TEXT NULL, -- JSON 格式的额外属性
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 标签表
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(7) NULL,
    description TEXT NULL,
    usage_count INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 网站标签关联表（多对多）
CREATE TABLE IF NOT EXISTS website_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    website_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    UNIQUE(website_id, tag_id)
);

-- 邀请码表
CREATE TABLE IF NOT EXISTS invitation_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(32) NOT NULL UNIQUE,
    created_by INTEGER NOT NULL,
    used_by INTEGER NULL,
    max_uses INTEGER DEFAULT 1,
    used_count INTEGER DEFAULT 0,
    expires_at DATETIME NULL,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 系统设置表
CREATE TABLE IF NOT EXISTS site_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key VARCHAR(100) NOT NULL UNIQUE,
    value TEXT NULL,
    type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    is_public BOOLEAN DEFAULT 0, -- 是否对前端公开
    group_name VARCHAR(50) DEFAULT 'general',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 死链检查表
CREATE TABLE IF NOT EXISTS deadlink_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    website_id INTEGER NOT NULL,
    check_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('ok', 'error', 'timeout', 'not_found', 'redirect') NOT NULL,
    response_time INTEGER NULL,
    http_status INTEGER NULL,
    error_message TEXT NULL,
    redirect_url TEXT NULL,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE
);

-- 用户收藏表
CREATE TABLE IF NOT EXISTS user_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    website_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    UNIQUE(user_id, website_id)
);

-- 点击记录表（用于统计）
CREATE TABLE IF NOT EXISTS click_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    website_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    referer TEXT NULL,
    clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 搜索记录表
CREATE TABLE IF NOT EXISTS search_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    keyword VARCHAR(255) NOT NULL,
    results_count INTEGER DEFAULT 0,
    ip_address VARCHAR(45) NOT NULL,
    searched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 备份记录表
CREATE TABLE IF NOT EXISTS backup_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(255) NOT NULL,
    file_size INTEGER NOT NULL,
    type ENUM('manual', 'auto') DEFAULT 'manual',
    created_by INTEGER NULL,
    status ENUM('success', 'failed', 'partial') NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 审计日志表
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50) NULL,
    record_id INTEGER NULL,
    old_values TEXT NULL, -- JSON 格式
    new_values TEXT NULL, -- JSON 格式
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 数据迁移记录表
CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration VARCHAR(255) NOT NULL,
    batch INTEGER NOT NULL,
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 用户表索引优化
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_status_active ON users(status) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_users_last_login ON users(last_login_at DESC);
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, status);
CREATE INDEX IF NOT EXISTS idx_users_locked_until ON users(locked_until) WHERE locked_until IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_last_activity ON user_sessions(last_activity);

CREATE INDEX IF NOT EXISTS idx_categories_parent_id ON categories(parent_id);
CREATE INDEX IF NOT EXISTS idx_categories_sort_order ON categories(sort_order);
CREATE INDEX IF NOT EXISTS idx_categories_user_id ON categories(user_id);

-- 网站表索引优化
CREATE INDEX IF NOT EXISTS idx_websites_category_id ON websites(category_id);
CREATE INDEX IF NOT EXISTS idx_websites_user_id ON websites(user_id);
CREATE INDEX IF NOT EXISTS idx_websites_url_hash ON websites(url(255)); -- URL 哈希索引提高查找速度
CREATE INDEX IF NOT EXISTS idx_websites_status_active ON websites(status) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_websites_category_sort ON websites(category_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_websites_clicks_desc ON websites(clicks DESC);
CREATE INDEX IF NOT EXISTS idx_websites_weight_desc ON websites(weight DESC);
CREATE INDEX IF NOT EXISTS idx_websites_created_at_desc ON websites(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_websites_featured ON websites(is_featured) WHERE is_featured = 1;
CREATE INDEX IF NOT EXISTS idx_websites_private ON websites(is_private, user_id);
CREATE INDEX IF NOT EXISTS idx_websites_status_check ON websites(last_checked_at, check_status);
CREATE INDEX IF NOT EXISTS idx_websites_search ON websites(title, description); -- 全文搜索索引
CREATE INDEX IF NOT EXISTS idx_websites_category_user_sort ON websites(category_id, user_id, sort_order);

CREATE INDEX IF NOT EXISTS idx_website_tags_website_id ON website_tags(website_id);
CREATE INDEX IF NOT EXISTS idx_website_tags_tag_id ON website_tags(tag_id);

CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(name);

CREATE INDEX IF NOT EXISTS idx_invitation_codes_code ON invitation_codes(code);
CREATE INDEX IF NOT EXISTS idx_invitation_codes_created_by ON invitation_codes(created_by);

CREATE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(key);
CREATE INDEX IF NOT EXISTS idx_site_settings_group ON site_settings(group_name);

CREATE INDEX IF NOT EXISTS idx_deadlink_checks_website_id ON deadlink_checks(website_id);
CREATE INDEX IF NOT EXISTS idx_deadlink_checks_check_time ON deadlink_checks(check_time);

CREATE INDEX IF NOT EXISTS idx_user_favorites_user_id ON user_favorites(user_id);
CREATE INDEX IF NOT EXISTS idx_user_favorites_website_id ON user_favorites(website_id);

-- 点击日志表索引优化（按时间分区友好）
CREATE INDEX IF NOT EXISTS idx_click_logs_website_clicked ON click_logs(website_id, clicked_at DESC);
CREATE INDEX IF NOT EXISTS idx_click_logs_user_clicked ON click_logs(user_id, clicked_at DESC);
CREATE INDEX IF NOT EXISTS idx_click_logs_date ON click_logs(DATE(clicked_at));
CREATE INDEX IF NOT EXISTS idx_click_logs_ip_date ON click_logs(ip_address, DATE(clicked_at));
CREATE INDEX IF NOT EXISTS idx_click_logs_hourly ON click_logs(strftime('%Y-%m-%d %H', clicked_at));

CREATE INDEX IF NOT EXISTS idx_search_logs_user_id ON search_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_search_logs_keyword ON search_logs(keyword);
CREATE INDEX IF NOT EXISTS idx_search_logs_searched_at ON search_logs(searched_at);

-- 审计日志表索引优化
CREATE INDEX IF NOT EXISTS idx_audit_logs_user_created ON audit_logs(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action_created ON audit_logs(action, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_table_record ON audit_logs(table_name, record_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_date ON audit_logs(DATE(created_at));
CREATE INDEX IF NOT EXISTS idx_audit_logs_ip ON audit_logs(ip_address, created_at DESC);

-- 触发器：自动更新 updated_at 字段
CREATE TRIGGER IF NOT EXISTS update_users_updated_at
    AFTER UPDATE ON users
    FOR EACH ROW
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_categories_updated_at
    AFTER UPDATE ON categories
    FOR EACH ROW
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE categories SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_websites_updated_at
    AFTER UPDATE ON websites
    FOR EACH ROW
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE websites SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_site_settings_updated_at
    AFTER UPDATE ON site_settings
    FOR EACH ROW
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE site_settings SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- 触发器：自动更新标签使用计数
CREATE TRIGGER IF NOT EXISTS update_tag_usage_count_insert
    AFTER INSERT ON website_tags
    FOR EACH ROW
BEGIN
    UPDATE tags SET usage_count = usage_count + 1 WHERE id = NEW.tag_id;
END;

CREATE TRIGGER IF NOT EXISTS update_tag_usage_count_delete
    AFTER DELETE ON website_tags
    FOR EACH ROW
BEGIN
    UPDATE tags SET usage_count = usage_count - 1 WHERE id = OLD.tag_id;
END;

-- 触发器：自动更新网站统计
CREATE TRIGGER IF NOT EXISTS update_website_clicks
    AFTER INSERT ON click_logs
    FOR EACH ROW
BEGIN
    UPDATE websites SET clicks = clicks + 1 WHERE id = NEW.website_id;

    -- 更新每日统计
    INSERT OR REPLACE INTO website_stats_daily (website_id, date, clicks, unique_visitors)
    SELECT
        NEW.website_id,
        DATE(NEW.clicked_at),
        COUNT(*),
        COUNT(DISTINCT user_id)
    FROM click_logs
    WHERE website_id = NEW.website_id AND DATE(clicked_at) = DATE(NEW.clicked_at);
END;

-- 触发器：记录审计日志
CREATE TRIGGER IF NOT EXISTS audit_websites_insert
    AFTER INSERT ON websites
    FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address)
    VALUES (NEW.user_id, 'CREATE', 'websites', NEW.id,
            json_object('title', NEW.title, 'url', NEW.url, 'category_id', NEW.category_id),
            '127.0.0.1');
END;

CREATE TRIGGER IF NOT EXISTS audit_websites_update
    AFTER UPDATE ON websites
    FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address)
    VALUES (NEW.user_id, 'UPDATE', 'websites', NEW.id,
            json_object('title', OLD.title, 'url', OLD.url, 'status', OLD.status),
            json_object('title', NEW.title, 'url', NEW.url, 'status', NEW.status),
            '127.0.0.1');
END;

CREATE TRIGGER IF NOT EXISTS audit_websites_delete
    AFTER DELETE ON websites
    FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, ip_address)
    VALUES (OLD.user_id, 'DELETE', 'websites', OLD.id,
            json_object('title', OLD.title, 'url', OLD.url),
            '127.0.0.1');
END;

-- 性能优化的视图和统计表

-- 网站统计信息视图（优化版）
CREATE VIEW IF NOT EXISTS website_stats AS
SELECT
    w.id,
    w.title,
    w.url,
    w.clicks,
    COALESCE(cs.total_clicks, 0) as total_clicks,
    COALESCE(cs.unique_users, 0) as unique_users,
    cs.last_clicked,
    c.name as category_name,
    w.check_status,
    w.response_time
FROM websites w
LEFT JOIN categories c ON w.category_id = c.id
LEFT JOIN (
    SELECT
        website_id,
        COUNT(*) as total_clicks,
        COUNT(DISTINCT user_id) as unique_users,
        MAX(clicked_at) as last_clicked
    FROM click_logs
    WHERE clicked_at >= datetime('now', '-30 days')
    GROUP BY website_id
) cs ON w.id = cs.website_id;

-- 创建统计汇总表（用于快速查询）
CREATE TABLE IF NOT EXISTS website_stats_daily (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    website_id INTEGER NOT NULL,
    date DATE NOT NULL,
    clicks INTEGER DEFAULT 0,
    unique_visitors INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    UNIQUE(website_id, date)
);

CREATE INDEX IF NOT EXISTS idx_website_stats_daily_date ON website_stats_daily(date DESC);
CREATE INDEX IF NOT EXISTS idx_website_stats_daily_website ON website_stats_daily(website_id, date DESC);

-- 视图：用户活动统计
CREATE VIEW IF NOT EXISTS user_activity_stats AS
SELECT
    u.id,
    u.username,
    COUNT(DISTINCT cl.website_id) as visited_websites,
    COUNT(cl.id) as total_clicks,
    MAX(cl.clicked_at) as last_activity,
    COUNT(DISTINCT DATE(cl.clicked_at)) as active_days
FROM users u
LEFT JOIN click_logs cl ON u.id = cl.user_id
GROUP BY u.id;

-- 分析表优化（SQLite 特定）
PRAGMA optimize;

-- 创建性能监控表
CREATE TABLE IF NOT EXISTS performance_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,4) NOT NULL,
    metric_unit VARCHAR(20) DEFAULT 'ms',
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    additional_data TEXT NULL -- JSON 格式的额外数据
);

CREATE INDEX IF NOT EXISTS idx_performance_metrics_name_date ON performance_metrics(metric_name, recorded_at DESC);

-- 慢查询日志表
CREATE TABLE IF NOT EXISTS slow_query_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    query_hash VARCHAR(64) NOT NULL,
    query_sql TEXT NOT NULL,
    execution_time DECIMAL(10,4) NOT NULL,
    rows_examined INTEGER DEFAULT 0,
    rows_returned INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_slow_query_log_hash ON slow_query_log(query_hash);
CREATE INDEX IF NOT EXISTS idx_slow_query_log_time ON slow_query_log(execution_time DESC);
CREATE INDEX IF NOT EXISTS idx_slow_query_log_date ON slow_query_log(created_at DESC);

-- 默认数据插入
INSERT OR IGNORE INTO site_settings (key, value, type, description, is_public, group_name) VALUES
('site_name', 'OneBookNav', 'string', '网站名称', 1, 'general'),
('site_description', '个人书签导航网站', 'string', '网站描述', 1, 'general'),
('site_keywords', 'bookmark,navigation,导航,书签', 'string', '网站关键词', 1, 'general'),
('admin_email', 'admin@example.com', 'string', '管理员邮箱', 0, 'general'),
('enable_registration', '1', 'boolean', '是否开放注册', 0, 'features'),
('require_invitation', '0', 'boolean', '是否需要邀请码', 0, 'features'),
('enable_guest_access', '1', 'boolean', '是否允许游客访问', 0, 'features'),
('default_theme', 'default', 'string', '默认主题', 1, 'appearance'),
('items_per_page', '20', 'integer', '每页显示项目数', 1, 'general'),
('max_upload_size', '2048', 'integer', '最大上传大小(KB)', 0, 'upload'),
('backup_enabled', '1', 'boolean', '是否启用备份', 0, 'backup'),
('deadlink_check_enabled', '1', 'boolean', '是否启用死链检查', 0, 'features');

-- 默认分类
INSERT OR IGNORE INTO categories (id, name, description, icon, sort_order) VALUES
(1, '搜索引擎', '各种搜索引擎', 'fas fa-search', 1),
(2, '社交媒体', '社交网络平台', 'fas fa-share-alt', 2),
(3, '新闻资讯', '新闻网站', 'fas fa-newspaper', 3),
(4, '开发工具', '编程开发相关', 'fas fa-code', 4),
(5, '娱乐休闲', '娱乐网站', 'fas fa-gamepad', 5),
(6, '学习教育', '教育学习网站', 'fas fa-graduation-cap', 6);