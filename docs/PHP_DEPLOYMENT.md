# OneBookNav PHP 直接部署指南

## 📦 概述

PHP 直接部署是最传统的部署方式，适合共享主机、VPS 或已有 LAMP/LEMP 环境的服务器。

## ⚙️ 系统要求

### 必需组件
- **PHP 7.4+** (推荐 PHP 8.0+)
- **Web 服务器**: Apache 或 Nginx
- **PHP 扩展**:
  - `pdo` - 数据库连接
  - `json` - JSON 处理
  - `mbstring` - 多字节字符串
- **文件权限**: 可写的 data 目录

### 数据库支持
- **SQLite** (默认) - 无需额外配置
- **MySQL 5.7+** - 适合多用户环境
- **PostgreSQL 10+** - 高性能场景

## 🚀 快速部署

### 方法1: 下载发布版本
```bash
# 下载最新版本
wget https://github.com/onebooknav/onebooknav/archive/refs/heads/main.zip
unzip main.zip
cd onebooknav-main

# 移动到网站根目录
sudo mv * /var/www/html/
```

### 方法2: Git 克隆
```bash
# 克隆代码库
git clone https://github.com/onebooknav/onebooknav.git
cd onebooknav

# 移动到网站根目录
sudo cp -r * /var/www/html/
```

## 🔧 配置步骤

### 1. 设置文件权限
```bash
# 设置基本权限
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html

# 数据目录需要写权限
sudo chmod -R 777 /var/www/html/data
sudo chmod -R 777 /var/www/html/config
```

### 2. 环境配置

#### 选项A: 使用安装向导 (推荐)
1. 访问 `http://your-domain.com/install.php`
2. 按照向导完成配置:
   - 选择数据库类型
   - 设置管理员账户
   - 配置站点信息
3. 删除 `install.php` 文件

#### 选项B: 手动配置
1. **复制配置文件**
```bash
cp config/config.php config/config.php.bak  # 备份
nano config/config.php  # 编辑配置
```

2. **基础配置**
```php
<?php
// 站点配置
define('SITE_TITLE', 'OneBookNav');
define('SITE_DESCRIPTION', 'Personal bookmark management');
define('SITE_URL', 'http://your-domain.com');

// 数据库配置 (SQLite)
define('DB_TYPE', 'sqlite');
define('DB_FILE', __DIR__ . '/../data/onebooknav.db');

// 管理员账户
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'your_secure_password');
define('DEFAULT_ADMIN_EMAIL', 'admin@example.com');
define('AUTO_CREATE_ADMIN', true);
```

### 3. Web 服务器配置

#### Apache (.htaccess)
项目已包含 `.htaccess` 文件，确保 Apache 开启 `mod_rewrite`:
```bash
# Ubuntu/Debian
sudo a2enmod rewrite
sudo systemctl restart apache2

# CentOS/RHEL
sudo systemctl restart httpd
```

#### Nginx 配置示例
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html;
    index index.php;

    # 静态文件缓存
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # API 路由
    location /api/ {
        try_files $uri $uri/ /api/index.php?$query_string;
    }

    # 安全设置
    location ~ /\. {
        deny all;
    }

    location ~ /(config|data|includes)/ {
        deny all;
    }
}
```

## 🗄️ 数据库配置

### SQLite (默认)
```php
define('DB_TYPE', 'sqlite');
define('DB_FILE', __DIR__ . '/../data/onebooknav.db');
```

**优点**: 无需额外配置，自动创建数据库文件
**适用**: 个人使用，小型站点

### MySQL 配置
```php
define('DB_TYPE', 'mysql');
define('DB_HOST', 'localhost');
define('DB_NAME', 'onebooknav');
define('DB_USER', 'onebooknav_user');
define('DB_PASS', 'secure_password');
define('DB_CHARSET', 'utf8mb4');
```

**数据库准备**:
```sql
-- 创建数据库和用户
CREATE DATABASE onebooknav CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'onebooknav_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON onebooknav.* TO 'onebooknav_user'@'localhost';
FLUSH PRIVILEGES;
```

### PostgreSQL 配置
```php
define('DB_TYPE', 'pgsql');
define('DB_HOST', 'localhost');
define('DB_NAME', 'onebooknav');
define('DB_USER', 'onebooknav_user');
define('DB_PASS', 'secure_password');
```

## 🛡️ 安全配置

### 1. 文件保护
```bash
# 保护敏感目录
echo "deny from all" > /var/www/html/config/.htaccess
echo "deny from all" > /var/www/html/data/.htaccess
echo "deny from all" > /var/www/html/includes/.htaccess
```

### 2. 配置文件安全
```php
// config/config.php 顶部添加
<?php
// 防止直接访问
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}
```

### 3. 文件上传限制
```php
// 上传设置
define('UPLOAD_MAX_SIZE', 1024 * 1024 * 2); // 2MB
define('ALLOWED_ICON_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico']);
```

## 🎯 环境变量配置

### 开发环境
```php
define('DEBUG_MODE', true);
define('LOG_LEVEL', 'DEBUG');
define('CACHE_ENABLED', false);
```

### 生产环境
```php
define('DEBUG_MODE', false);
define('LOG_LEVEL', 'ERROR');
define('CACHE_ENABLED', true);
define('CACHE_TYPE', 'file');
```

### 功能开关
```php
// 用户功能
define('ALLOW_REGISTRATION', true);
define('REQUIRE_EMAIL_VERIFICATION', false);

// 功能模块
define('ENABLE_WEBDAV_BACKUP', true);
define('ENABLE_API', true);
define('ENABLE_PWA', true);
```

## 🔄 维护操作

### 备份数据
```bash
# SQLite 备份
cp /var/www/html/data/onebooknav.db /backup/onebooknav_$(date +%Y%m%d_%H%M%S).db

# MySQL 备份
mysqldump -u root -p onebooknav > /backup/onebooknav_$(date +%Y%m%d_%H%M%S).sql

# 完整备份
tar -czf /backup/onebooknav_full_$(date +%Y%m%d_%H%M%S).tar.gz /var/www/html
```

### 日志管理
```bash
# 查看日志
tail -f /var/www/html/data/logs/app.log

# 清理旧日志
find /var/www/html/data/logs -name "*.log" -mtime +30 -delete
```

### 更新升级
```bash
# 1. 备份当前版本
cp -r /var/www/html /backup/onebooknav_backup_$(date +%Y%m%d)

# 2. 下载新版本
wget https://github.com/onebooknav/onebooknav/archive/refs/heads/main.zip
unzip main.zip

# 3. 保留配置和数据
cp -r /backup/onebooknav_backup_*/config /var/www/html/
cp -r /backup/onebooknav_backup_*/data /var/www/html/

# 4. 更新代码
cp -r onebooknav-main/* /var/www/html/

# 5. 修复权限
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 777 /var/www/html/data
```

## 🔧 故障排除

### 常见问题

#### 1. 白屏/500 错误
```bash
# 检查 PHP 错误日志
tail -f /var/log/apache2/error.log
# 或
tail -f /var/log/nginx/error.log

# 检查文件权限
ls -la /var/www/html/
ls -la /var/www/html/data/
```

#### 2. 数据库连接失败
```bash
# 检查 MySQL 服务
sudo systemctl status mysql

# 测试数据库连接
mysql -u onebooknav_user -p onebooknav

# 检查 PHP PDO 扩展
php -m | grep pdo
```

#### 3. 文件上传失败
```bash
# 检查 PHP 配置
php -i | grep upload_max_filesize
php -i | grep post_max_size

# 检查目录权限
ls -la /var/www/html/data/uploads/
```

#### 4. 伪静态不工作
```bash
# Apache: 检查 mod_rewrite
apache2ctl -M | grep rewrite

# 检查 .htaccess 文件
cat /var/www/html/.htaccess

# Nginx: 检查配置语法
nginx -t
```

### 性能优化

#### 1. PHP 优化
```ini
; php.ini 优化设置
memory_limit = 256M
max_execution_time = 60
max_input_vars = 3000
post_max_size = 32M
upload_max_filesize = 32M

; OPcache 启用
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 4000
```

#### 2. 数据库优化
```sql
-- MySQL 优化
OPTIMIZE TABLE users, categories, bookmarks;

-- 添加索引 (如果缺失)
CREATE INDEX idx_bookmarks_user_id ON bookmarks(user_id);
CREATE INDEX idx_categories_user_id ON categories(user_id);
```

#### 3. 缓存配置
```php
// 启用文件缓存
define('CACHE_ENABLED', true);
define('CACHE_TYPE', 'file');
define('CACHE_PATH', __DIR__ . '/../data/cache/');
define('CACHE_TTL', 3600); // 1小时
```

## 📊 监控和日志

### 访问日志分析
```bash
# 分析访问最多的页面
awk '{print $7}' /var/log/apache2/access.log | sort | uniq -c | sort -nr | head -10

# 分析 API 调用
grep "/api/" /var/log/apache2/access.log | awk '{print $7}' | sort | uniq -c | sort -nr
```

### 健康检查脚本
```bash
#!/bin/bash
# health_check.sh

URL="http://your-domain.com"
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" $URL)

if [ $RESPONSE -eq 200 ]; then
    echo "OK: Site is responding"
else
    echo "ERROR: Site returned HTTP $RESPONSE"
    # 可以添加邮件通知
fi
```

## 📞 获取帮助

如果遇到问题:
1. 检查 PHP 错误日志
2. 查看项目日志: `data/logs/app.log`
3. 参考故障排除文档: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
4. 提交 Issue: [GitHub Issues](https://github.com/onebooknav/onebooknav/issues)

## 🔗 相关文档

- [Docker 部署指南](DOCKER_DEPLOYMENT.md)
- [Cloudflare Workers 部署](workers-console-setup.md)
- [故障排除指南](TROUBLESHOOTING.md)
- [API 文档](docs/API.md)