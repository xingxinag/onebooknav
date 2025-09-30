# OneBookNav 完整部署指南

## 📋 概述

OneBookNav 支持三种主要部署方式，每种方式都有其独特的优势：

1. **PHP 直接部署** - 适合传统虚拟主机和 VPS
2. **Docker 容器化部署** - 适合现代云原生环境
3. **Cloudflare Workers 部署** - 适合边缘计算和全球加速

## 🚀 部署方式一：PHP 直接部署

### 系统要求

| 组件 | 版本要求 | 说明 |
|------|----------|------|
| PHP | >= 8.0 | 推荐 8.1+ |
| SQLite | >= 3.35 | 内置支持 |
| Web服务器 | Apache/Nginx | 支持 URL 重写 |
| 磁盘空间 | >= 100MB | 基础安装 |
| 内存 | >= 256MB | PHP 运行时 |

### 安装步骤

#### 1. 下载和准备

```bash
# 克隆项目
git clone https://github.com/onebooknav/onebooknav.git
cd onebooknav

# 或下载发布版本
wget https://github.com/onebooknav/onebooknav/releases/latest/download/onebooknav.zip
unzip onebooknav.zip
```

#### 2. 设置权限

```bash
# Linux/macOS
chmod 755 -R ./
chmod 777 ./data
chmod 777 ./logs
chmod 777 ./backups

# 确保 Web 服务器可以访问
chown -R www-data:www-data ./
```

#### 3. 配置环境

```bash
# 复制环境配置文件
cp .env.example .env

# 编辑配置文件
nano .env
```

**.env 配置示例：**

```env
# 应用配置
APP_NAME=OneBookNav
APP_ENV=production
APP_DEBUG=false
APP_VERSION=1.0.0

# 数据库配置
DB_TYPE=sqlite
DB_PATH=./data/database.db

# 安全配置
SECRET_KEY=your-secret-key-here
SESSION_LIFETIME=86400
CSRF_TOKEN_LIFETIME=3600

# 管理员配置
ADMIN_USERNAME=admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=admin123

# 功能开关
ENABLE_REGISTRATION=false
ENABLE_INVITATION_CODE=true
INVITATION_CODE_LENGTH=8

# 备份配置
BACKUP_ENABLED=true
BACKUP_INTERVAL=86400
BACKUP_KEEP_DAYS=30

# WebDAV 备份
WEBDAV_ENABLED=false
WEBDAV_URL=https://your-webdav-server
WEBDAV_USERNAME=username
WEBDAV_PASSWORD=password
```

#### 4. 初始化数据库

```bash
# 运行数据库迁移
php scripts/migrate.php --action=init

# 或通过 Web 界面初始化
# 访问 http://yourdomain.com/install
```

#### 5. Web 服务器配置

**Apache (.htaccess)：**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.php [QSA,L]

# 安全配置
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

<Files "*.log">
    Order allow,deny
    Deny from all
</Files>
```

**Nginx 配置：**

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/onebooknav/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

## 🐳 部署方式二：Docker 容器化部署

### 快速启动

```bash
# 使用 docker-compose
git clone https://github.com/onebooknav/onebooknav.git
cd onebooknav
docker-compose up -d
```

### 自定义配置

#### docker-compose.yml 详细配置

```yaml
version: '3.8'

services:
  onebooknav:
    build:
      context: .
      target: production
    container_name: onebooknav_app
    restart: unless-stopped
    ports:
      - "3080:80"
    volumes:
      - ./data:/var/www/html/data:rw
      - ./logs:/var/www/html/logs:rw
      - ./backups:/var/www/html/backups:rw
      - ./uploads:/var/www/html/public/uploads:rw
    environment:
      - APP_ENV=production
      - DB_PATH=/var/www/html/data/database.db
      - ADMIN_USERNAME=admin
      - ADMIN_PASSWORD=your-secure-password
    depends_on:
      - redis
    networks:
      - onebooknav_network

  redis:
    image: redis:7-alpine
    container_name: onebooknav_redis
    restart: unless-stopped
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD:-onebooknav123}
    volumes:
      - redis_data:/data
    networks:
      - onebooknav_network

  nginx:
    image: nginx:alpine
    container_name: onebooknav_nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./docker/nginx/ssl:/etc/nginx/ssl:ro
      - ./logs/nginx:/var/log/nginx:rw
    depends_on:
      - onebooknav
    networks:
      - onebooknav_network
    profiles:
      - nginx

volumes:
  redis_data:
    driver: local

networks:
  onebooknav_network:
    driver: bridge
```

### 高级部署选项

#### 1. 生产环境优化

```bash
# 构建生产镜像
docker build --target production -t onebooknav:latest .

# 运行优化容器
docker run -d \
  --name onebooknav \
  -p 8080:80 \
  -v ./data:/var/www/html/data \
  -v ./backups:/var/www/html/backups \
  --memory="512m" \
  --cpus="1.0" \
  --restart=unless-stopped \
  onebooknav:latest
```

#### 2. 集群部署

```yaml
# docker-swarm.yml
version: '3.8'

services:
  onebooknav:
    image: onebooknav:latest
    deploy:
      replicas: 3
      restart_policy:
        condition: on-failure
        delay: 10s
        max_attempts: 3
      resources:
        limits:
          memory: 512M
          cpus: '1.0'
    volumes:
      - onebooknav_data:/var/www/html/data
    networks:
      - onebooknav_overlay

volumes:
  onebooknav_data:
    driver_opts:
      type: nfs
      o: addr=your-nfs-server,rw
      device: :/path/to/shared/storage

networks:
  onebooknav_overlay:
    driver: overlay
    attachable: true
```

## ☁️ 部署方式三：Cloudflare Workers 部署

### 前置准备

1. **Cloudflare 账户** - 免费账户即可
2. **Wrangler CLI** - Cloudflare 官方工具
3. **Node.js** - 用于构建和部署

### 安装步骤

#### 1. 安装 Wrangler CLI

```bash
npm install -g wrangler

# 登录 Cloudflare
wrangler login
```

#### 2. 配置项目

```bash
cd workers/

# 复制配置文件
cp wrangler.toml.example wrangler.toml

# 编辑配置
nano wrangler.toml
```

**wrangler.toml 配置：**

```toml
name = "onebooknav"
main = "index.js"
compatibility_date = "2024-01-01"
compatibility_flags = ["nodejs_compat"]

# 生产环境变量
[env.production.vars]
ENVIRONMENT = "production"
VERSION = "1.0.0"
ADMIN_USERNAME = "admin"
ADMIN_PASSWORD = "your-secure-password"

# KV 存储空间
[[env.production.kv_namespaces]]
binding = "ONEBOOKNAV_DATA"
id = "your-kv-namespace-id"

[[env.production.kv_namespaces]]
binding = "STATIC_ASSETS"
id = "your-static-kv-namespace-id"

# D1 数据库（可选）
[[env.production.d1_databases]]
binding = "ONEBOOKNAV_DB"
database_name = "onebooknav"
database_id = "your-d1-database-id"

# 自定义域名
[env.production]
route = "nav.yourdomain.com/*"
```

#### 3. 创建 KV 存储空间

```bash
# 创建数据存储空间
wrangler kv:namespace create "ONEBOOKNAV_DATA" --env production

# 创建静态资源存储空间
wrangler kv:namespace create "STATIC_ASSETS" --env production

# 创建 D1 数据库（可选）
wrangler d1 create onebooknav
```

#### 4. 部署应用

```bash
# 部署到生产环境
wrangler deploy --env production

# 设置密钥
wrangler secret put DATABASE_URL --env production
wrangler secret put ADMIN_PASSWORD --env production
```

#### 5. 初始化数据

```bash
# 上传初始数据到 KV
wrangler kv:key put "categories" "[]" --binding=ONEBOOKNAV_DATA --env production
wrangler kv:key put "websites" "[]" --binding=ONEBOOKNAV_DATA --env production

# 上传静态资源
wrangler kv:key put "/assets/css/app.css" --path="./assets/css/app.css" --binding=STATIC_ASSETS --env production
```

### Workers 特定配置

#### 环境变量管理

```bash
# 查看所有环境变量
wrangler secret list --env production

# 设置环境变量
wrangler secret put SECRET_KEY --env production
wrangler secret put WEBDAV_URL --env production
wrangler secret put WEBDAV_TOKEN --env production
```

#### 自定义域名设置

```bash
# 添加自定义域名
wrangler route create "nav.yourdomain.com/*" --env production

# 配置 DNS
# 在 Cloudflare DNS 面板中添加 CNAME 记录：
# nav.yourdomain.com -> your-worker.workers.dev
```

## 🔧 数据迁移指南

### 从 BookNav 迁移

```bash
# 直接迁移
php scripts/migrate.php --source=/path/to/booknav.db --type=booknav

# Docker 环境迁移
docker exec -it onebooknav_app php scripts/migrate.php --source=/data/booknav.db --type=booknav
```

### 从 OneNav 迁移

```bash
# 直接迁移
php scripts/migrate.php --source=/path/to/onenav.db3 --type=onenav

# 包含用户数据迁移
php scripts/migrate.php --source=/path/to/onenav.db3 --type=onenav --include-users
```

### 浏览器书签导入

```bash
# HTML 格式书签
php scripts/migrate.php --source=/path/to/bookmarks.html --type=bookmarks --format=html

# JSON 格式书签
php scripts/migrate.php --source=/path/to/bookmarks.json --type=bookmarks --format=json
```

## 🛡️ 安全配置

### SSL/TLS 配置

#### Let's Encrypt 证书

```bash
# 安装 certbot
sudo apt-get install certbot python3-certbot-nginx

# 获取证书
sudo certbot --nginx -d yourdomain.com

# 自动续期
sudo crontab -e
# 添加：0 0 * * * /usr/bin/certbot renew --quiet
```

#### 自签名证书（开发环境）

```bash
# 生成证书
openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem -days 365 -nodes

# 配置 Nginx
server {
    listen 443 ssl;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    # ... 其他配置
}
```

### 防火墙配置

```bash
# UFW 配置
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# iptables 配置
iptables -A INPUT -p tcp --dport 22 -j ACCEPT
iptables -A INPUT -p tcp --dport 80 -j ACCEPT
iptables -A INPUT -p tcp --dport 443 -j ACCEPT
```

## 📊 监控和维护

### 健康检查

```bash
# HTTP 健康检查
curl -f http://yourdomain.com/health || echo "Health check failed"

# 数据库检查
php scripts/health-check.php --check=database

# 磁盘空间检查
df -h | grep -E '(8[0-9]|9[0-9])%'
```

### 日志管理

```bash
# 查看应用日志
tail -f logs/app.log

# 查看错误日志
tail -f logs/error.log

# 日志轮转配置
cat > /etc/logrotate.d/onebooknav << EOF
/var/www/onebooknav/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 www-data www-data
}
EOF
```

### 备份策略

#### 自动备份脚本

```bash
#!/bin/bash
# backup.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/onebooknav"
APP_DIR="/var/www/onebooknav"

# 创建备份目录
mkdir -p $BACKUP_DIR

# 数据库备份
sqlite3 $APP_DIR/data/database.db ".backup $BACKUP_DIR/database_$DATE.db"

# 文件备份
tar -czf $BACKUP_DIR/files_$DATE.tar.gz -C $APP_DIR data uploads

# 清理旧备份（保留30天）
find $BACKUP_DIR -name "*.db" -mtime +30 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete

echo "Backup completed: $BACKUP_DIR"
```

#### 定时备份

```bash
# 添加到 crontab
crontab -e

# 每天凌晨2点备份
0 2 * * * /path/to/backup.sh

# 每6小时增量备份
0 */6 * * * /usr/bin/php /var/www/onebooknav/scripts/backup.php --type=incremental
```

## 🔍 故障排除

### 常见问题

#### 1. 数据库权限问题

```bash
# 检查权限
ls -la data/
# 应该显示：-rw-rw-rw- ... database.db

# 修复权限
chmod 666 data/database.db
chmod 777 data/
```

#### 2. Web 服务器配置问题

```bash
# 检查 URL 重写
echo "<?php phpinfo(); ?>" > test.php
# 访问 /test.php，查看 mod_rewrite 是否启用

# 检查 PHP 扩展
php -m | grep -i sqlite
```

#### 3. Docker 容器问题

```bash
# 查看容器日志
docker logs onebooknav_app

# 进入容器调试
docker exec -it onebooknav_app /bin/sh

# 重建容器
docker-compose down
docker-compose up --build -d
```

#### 4. Cloudflare Workers 问题

```bash
# 查看部署日志
wrangler tail --env production

# 检查 KV 存储
wrangler kv:key list --binding=ONEBOOKNAV_DATA --env production

# 调试模式部署
wrangler dev --env development
```

### 性能优化

#### PHP 优化

```ini
; php.ini 优化配置
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=2
opcache.fast_shutdown=1

; 会话优化
session.cache_limiter=nocache
session.cache_expire=180
```

#### 数据库优化

```sql
-- SQLite 优化
PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA cache_size=64000;
PRAGMA temp_store=MEMORY;
PRAGMA mmap_size=268435456;

-- 定期维护
VACUUM;
ANALYZE;
```

## 📞 支持和帮助

### 获取帮助

- **文档**: [https://docs.onebooknav.com](https://docs.onebooknav.com)
- **GitHub Issues**: [https://github.com/onebooknav/issues](https://github.com/onebooknav/issues)
- **社区讨论**: [https://github.com/onebooknav/discussions](https://github.com/onebooknav/discussions)
- **邮件支持**: support@onebooknav.com

### 贡献指南

1. Fork 本仓库
2. 创建功能分支: `git checkout -b feature/new-feature`
3. 提交更改: `git commit -am 'Add new feature'`
4. 推送分支: `git push origin feature/new-feature`
5. 提交 Pull Request

### 许可证

本项目采用 MIT 许可证 - 详见 [LICENSE](LICENSE) 文件

---

**OneBookNav** - 让导航更简单，让访问更快速 🚀