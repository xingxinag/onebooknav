# OneBookNav 部署指南

OneBookNav 是一个现代化的书签导航系统，融合了 BookNav 和 OneNav 的优势，实现了"统一核心，多态适配"的架构设计。支持三种主要的部署方式。

## 🌟 项目特色

### 核心优势
- **统一架构**: 一套代码支持三种部署方式
- **功能融合**: BookNav + OneNav = 1+1>2 的效果
- **现代化设计**: 响应式界面，支持暗色模式
- **完整迁移**: 无缝从现有导航系统迁移数据
- **企业级功能**: 备份、安全、监控一应俱全

### 技术架构
- **PHP 8.0+**: 现代PHP开发
- **SQLite/MySQL**: 灵活的数据库支持
- **依赖注入**: IoC容器管理
- **服务化设计**: 模块化架构
- **安全第一**: 全方位安全防护

## 🚀 部署方式选择

### 1. 🐳 Docker 容器化部署 (推荐)
**适用场景**: 生产环境、云服务器
**优势**: 环境隔离、易于扩展、便于维护
**要求**: Docker 和 Docker Compose

### 2. 🔧 PHP 原生部署
**适用场景**: 传统虚拟主机、VPS
**优势**: 资源占用小、兼容性好
**要求**: PHP 8.0+、Web服务器

### 3. ⚡ Cloudflare Workers 边缘部署
**适用场景**: 全球化应用、高性能需求
**优势**: 零冷启动、全球分布、无服务器
**要求**: Cloudflare 账户、Wrangler CLI

---

## 🐳 Docker 部署（推荐）

### 快速开始

1. **准备环境**
   ```bash
   # 确保已安装 Docker 和 Docker Compose
   docker --version
   docker-compose --version
   ```

2. **下载项目**
   ```bash
   git clone https://github.com/your-repo/onebooknav.git
   cd onebooknav
   ```

3. **配置环境**
   ```bash
   # 复制环境配置文件
   cp .env.new .env

   # 编辑配置文件
   nano .env
   ```

4. **启动服务**
   ```bash
   # 基础启动（SQLite + 文件缓存）
   docker-compose up -d

   # 完整启动（MySQL + Redis + Nginx）
   docker-compose --profile mysql --profile redis --profile nginx up -d
   ```

5. **访问应用**
   - 默认地址: http://localhost:8080
   - 管理员: admin / admin123

### Docker 高级配置

#### 端口配置
```bash
# 修改 .env 文件
HTTP_PORT=8080      # Web端口
HTTPS_PORT=8443     # SSL端口
MYSQL_PORT=3306     # MySQL端口
REDIS_PORT=6379     # Redis端口
```

#### 数据持久化
```yaml
# docker-compose.yml 已配置数据卷
volumes:
  - ./data:/var/www/html/data           # 数据库文件
  - ./backups:/var/www/html/backups     # 备份文件
  - ./logs:/var/www/html/logs           # 日志文件
  - ./themes:/var/www/html/themes       # 自定义主题
```

#### SSL/HTTPS 配置
```bash
# 1. 生成SSL证书
mkdir -p docker/ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout docker/ssl/nginx.key \
  -out docker/ssl/nginx.crt

# 2. 启用HTTPS
echo "ENABLE_HTTPS=true" >> .env

# 3. 重启Nginx
docker-compose restart nginx
```

---

## 🔧 PHP 原生部署

### 系统要求

**服务器环境:**
- PHP 8.0+
- Apache 2.4+ 或 Nginx 1.18+
- SQLite 3.0+ (推荐) 或 MySQL 5.7+

**必需PHP扩展:**
```bash
php -m | grep -E "(pdo_sqlite|pdo_mysql|mbstring|curl|gd|zip|xml|json)"
```

### 安装步骤

1. **下载源码**
   ```bash
   # 下载最新版本
   wget https://github.com/your-repo/onebooknav/releases/latest/download/onebooknav.zip
   unzip onebooknav.zip -d /var/www/html/
   cd /var/www/html/onebooknav
   ```

2. **设置权限**
   ```bash
   # 设置基本权限
   chmod -R 755 .

   # 设置数据目录权限
   chmod -R 777 data/ backups/ logs/

   # 设置所有者
   chown -R www-data:www-data .
   ```

3. **配置Web服务器**

   **Apache 配置** (已包含 .htaccess):
   ```apache
   <VirtualHost *:80>
       ServerName your-domain.com
       DocumentRoot /var/www/html/onebooknav/public

       <Directory /var/www/html/onebooknav/public>
           AllowOverride All
           Require all granted
       </Directory>

       ErrorLog ${APACHE_LOG_DIR}/onebooknav_error.log
       CustomLog ${APACHE_LOG_DIR}/onebooknav_access.log combined
   </VirtualHost>
   ```

   **Nginx 配置**:
   ```nginx
   server {
       listen 80;
       server_name your-domain.com;
       root /var/www/html/onebooknav/public;
       index index.php index.html;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
       }

       location ~ /\.(?!well-known).* {
           deny all;
       }

       # 安全配置
       location ~* \.(env|ini|log|conf)$ {
           deny all;
       }

       # 静态文件缓存
       location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2)$ {
           expires 1y;
           add_header Cache-Control "public, immutable";
       }
   }
   ```

4. **应用配置**
   ```bash
   # 复制配置文件
   cp .env.new .env

   # 编辑配置
   nano .env

   # 初始化数据库
   php console migrate

   # 创建管理员账户
   php console admin:create admin admin@example.com yourpassword
   ```

### 定时任务配置

```bash
# 编辑用户crontab
crontab -e

# 添加定时任务
# 每天凌晨2点自动备份
0 2 * * * /usr/bin/php /var/www/html/onebooknav/console backup:auto

# 每周日检查死链
0 0 * * 0 /usr/bin/php /var/www/html/onebooknav/console links:check

# 每天凌晨1点清理缓存
0 1 * * * /usr/bin/php /var/www/html/onebooknav/console cache:clean

# 每小时清理过期会话
0 * * * * /usr/bin/php /var/www/html/onebooknav/console sessions:gc
```

---

## ⚡ Cloudflare Workers 边缘部署

### 准备工作

1. **安装工具**
   ```bash
   # 安装 Node.js 和 npm
   curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
   sudo apt-get install -y nodejs

   # 安装 Wrangler CLI
   npm install -g wrangler
   ```

2. **登录 Cloudflare**
   ```bash
   wrangler login
   ```

### 部署步骤

1. **配置项目**
   ```bash
   # 复制配置文件
   cp wrangler.toml.example wrangler.toml

   # 编辑配置，填入你的信息
   nano wrangler.toml
   ```

2. **创建资源**
   ```bash
   # 创建 KV 存储命名空间
   wrangler kv:namespace create "ONEBOOKNAV_DATA"
   wrangler kv:namespace create "ONEBOOKNAV_CACHE"
   wrangler kv:namespace create "ONEBOOKNAV_SESSIONS"

   # 创建 D1 数据库
   wrangler d1 create onebooknav

   # 创建 R2 存储桶
   wrangler r2 bucket create onebooknav-files
   ```

3. **配置环境变量**
   ```bash
   # 设置密钥
   wrangler secret put ADMIN_PASSWORD
   wrangler secret put JWT_SECRET
   wrangler secret put WEBDAV_PASSWORD
   ```

4. **初始化数据**
   ```bash
   # 初始化数据库结构
   wrangler d1 execute onebooknav --file=./database/schema.sql

   # 插入初始数据
   wrangler d1 execute onebooknav --file=./database/seeds.sql
   ```

5. **部署应用**
   ```bash
   # 构建项目
   npm run build:worker

   # 部署到 Cloudflare
   wrangler deploy
   ```

### Workers 高级功能

#### 自定义域名
```bash
# 添加自定义路由
wrangler route create "nav.yourdomain.com/*" onebooknav
```

#### 环境管理
```bash
# 部署到不同环境
wrangler deploy --env staging
wrangler deploy --env production
```

#### 监控调试
```bash
# 实时日志
wrangler tail

# 本地开发
wrangler dev --local

# 性能分析
wrangler analytics
```

---

## 🔄 数据迁移

OneBookNav 支持从多种系统迁移数据：

### 支持的数据源
- **BookNav**: SQLite数据库文件
- **OneNav**: SQLite数据库文件
- **浏览器书签**: Chrome、Firefox、Edge、Safari
- **CSV文件**: 自定义格式
- **JSON文件**: 标准格式

### 迁移步骤

1. **Web界面迁移** (推荐)
   ```
   登录管理后台 → 数据管理 → 导入数据 → 选择数据源
   ```

2. **命令行迁移**
   ```bash
   # BookNav 数据库迁移
   php console migrate:booknav /path/to/booknav.db

   # OneNav 数据库迁移
   php console migrate:onenav /path/to/onenav.db

   # 浏览器书签迁移
   php console migrate:browser /path/to/bookmarks.html

   # CSV 文件迁移
   php console migrate:csv /path/to/bookmarks.csv
   ```

3. **迁移选项**
   ```bash
   # 覆盖现有数据
   php console migrate:booknav --overwrite /path/to/data.db

   # 指定目标分类
   php console migrate:browser --category="导入书签" /path/to/bookmarks.html

   # 预览迁移数据
   php console migrate:preview /path/to/data.db
   ```

---

## 🔒 安全配置

### SSL/TLS 配置

1. **使用 Let's Encrypt** (推荐)
   ```bash
   # 安装 certbot
   sudo apt-get install certbot python3-certbot-nginx

   # 获取证书
   sudo certbot --nginx -d your-domain.com

   # 自动续期
   sudo crontab -e
   0 12 * * * /usr/bin/certbot renew --quiet
   ```

2. **自签名证书** (开发环境)
   ```bash
   openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
     -keyout /etc/ssl/private/onebooknav.key \
     -out /etc/ssl/certs/onebooknav.crt
   ```

### 安全最佳实践

1. **修改默认密码**
   ```bash
   # 首次登录后立即修改
   用户中心 → 修改密码
   ```

2. **防火墙配置**
   ```bash
   # UFW 防火墙
   sudo ufw allow 22      # SSH
   sudo ufw allow 80      # HTTP
   sudo ufw allow 443     # HTTPS
   sudo ufw enable
   ```

3. **文件权限**
   ```bash
   # 限制敏感文件权限
   chmod 600 .env
   chmod -R 600 data/
   chmod -R 755 public/
   ```

4. **定期备份**
   ```bash
   # 启用自动备份
   echo "BACKUP_ENABLED=true" >> .env
   echo "BACKUP_INTERVAL=24" >> .env
   echo "WEBDAV_ENABLED=true" >> .env
   ```

---

## 📊 监控维护

### 健康检查

```bash
# 检查应用状态
curl -f http://your-domain.com/api/health

# 检查数据库连接
php console db:check

# 检查文件权限
php console system:check
```

### 日志管理

```bash
# 查看应用日志
tail -f logs/app.log

# 查看错误日志
tail -f logs/error.log

# 查看访问日志
tail -f /var/log/nginx/access.log
```

### 性能优化

1. **PHP 优化**
   ```ini
   # php.ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=4000
   opcache.revalidate_freq=2
   ```

2. **数据库优化**
   ```bash
   # SQLite 优化
   echo "PRAGMA journal_mode=WAL;" | sqlite3 data/database.db
   echo "PRAGMA synchronous=NORMAL;" | sqlite3 data/database.db
   ```

3. **Web服务器优化**
   ```nginx
   # Nginx 配置
   gzip on;
   gzip_vary on;
   gzip_min_length 1024;
   gzip_types text/plain text/css application/json application/javascript;
   ```

---

## 🆘 故障排除

### 常见问题

1. **500 错误**
   ```bash
   # 检查错误日志
   tail -f logs/error.log

   # 检查权限
   chmod -R 777 data/ logs/ backups/

   # 检查PHP错误
   php -l index.php
   ```

2. **数据库连接失败**
   ```bash
   # 检查配置
   cat .env | grep DB_

   # 测试连接
   php console db:test

   # 重新初始化
   php console migrate
   ```

3. **Web服务器配置**
   ```bash
   # Apache 语法检查
   sudo apache2ctl configtest

   # Nginx 语法检查
   sudo nginx -t

   # 重启服务
   sudo systemctl restart apache2  # 或 nginx
   ```

### 调试模式

```bash
# 开启调试模式
echo "APP_DEBUG=true" >> .env

# 查看详细错误
echo "LOG_LEVEL=debug" >> .env

# 关闭生产模式
echo "APP_ENV=development" >> .env
```

---

## 📚 更多资源

- 📖 [完整文档](https://docs.onebooknav.com)
- 🐛 [问题反馈](https://github.com/your-repo/onebooknav/issues)
- 💬 [社区讨论](https://github.com/your-repo/onebooknav/discussions)
- 🔄 [更新日志](https://github.com/your-repo/onebooknav/releases)
- 📧 [技术支持](mailto:support@onebooknav.com)

## 📄 许可证

OneBookNav 采用 MIT 许可证开源，详见 [LICENSE](LICENSE) 文件。

---

*OneBookNav - 让书签管理更简单 🚀*