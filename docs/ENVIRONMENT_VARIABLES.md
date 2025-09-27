# OneBookNav 环境变量标准

## 📋 概述

OneBookNav 支持三种部署方式，每种方式都使用统一的环境变量标准，确保配置的一致性和可移植性。

## 🎯 通用环境变量

### 必需变量

| 变量名 | 默认值 | 说明 | 适用部署方式 |
|--------|--------|------|-------------|
| `SITE_TITLE` | OneBookNav | 站点标题 | 全部 |
| `DEFAULT_ADMIN_USERNAME` | admin | 默认管理员用户名 | 全部 |
| `DEFAULT_ADMIN_PASSWORD` | - | 默认管理员密码 | 全部 |
| `AUTO_CREATE_ADMIN` | true | 是否自动创建管理员 | 全部 |

### 可选变量

| 变量名 | 默认值 | 说明 | 适用部署方式 |
|--------|--------|------|-------------|
| `DEFAULT_ADMIN_EMAIL` | admin@example.com | 管理员邮箱 | 全部 |
| `SITE_DESCRIPTION` | Personal bookmark management | 站点描述 | PHP, Docker |
| `SITE_URL` | http://localhost | 站点 URL | PHP, Docker |
| `DB_TYPE` | sqlite | 数据库类型 | PHP, Docker |
| `DEBUG_MODE` | false | 调试模式 | PHP, Docker |
| `ALLOW_REGISTRATION` | true | 允许用户注册 | PHP, Docker |

## 🔧 按部署方式的配置

### 1. Cloudflare Workers

**通过 Cloudflare Dashboard 设置:**

**基础配置 (Environment Variables):**
```
SITE_TITLE = OneBookNav
DEFAULT_ADMIN_USERNAME = admin
DEFAULT_ADMIN_EMAIL = admin@example.com
AUTO_CREATE_ADMIN = true
```

**安全变量 (Environment Variables):**
```
DEFAULT_ADMIN_PASSWORD = YourSecurePassword123!
```

**数据库绑定 (D1 Database Bindings):**
```
变量名: DB
数据库: onebooknav
```

**wrangler.toml 示例:**
```toml
name = "onebooknav"
main = "index.js"
compatibility_date = "2024-01-01"

[vars]
SITE_TITLE = "OneBookNav"
DEFAULT_ADMIN_USERNAME = "admin"
DEFAULT_ADMIN_EMAIL = "admin@example.com"
AUTO_CREATE_ADMIN = "true"

[[d1_databases]]
binding = "DB"
database_name = "onebooknav"
database_id = "YOUR_DATABASE_ID_HERE"
```

### 2. Docker Compose

**docker-compose.yml 示例:**
```yaml
version: '3.8'
services:
  onebooknav:
    build: .
    environment:
      # 站点配置
      - SITE_TITLE=OneBookNav
      - SITE_URL=http://localhost:3080
      - SITE_DESCRIPTION=Personal bookmark management

      # 数据库配置
      - DB_TYPE=sqlite

      # 管理员账户
      - DEFAULT_ADMIN_USERNAME=admin
      - DEFAULT_ADMIN_PASSWORD=admin679
      - DEFAULT_ADMIN_EMAIL=admin@example.com
      - AUTO_CREATE_ADMIN=true

      # 功能配置
      - DEBUG_MODE=false
      - ALLOW_REGISTRATION=true
      - ENABLE_PWA=true
```

**环境文件 (.env):**
```bash
# 站点基本信息
SITE_TITLE=OneBookNav
SITE_DESCRIPTION=Personal bookmark management system
SITE_KEYWORDS=bookmarks, navigation, links
SITE_URL=http://localhost:3080

# 数据库配置
DB_TYPE=sqlite
DB_FILE=./data/onebooknav.db

# 管理员账户配置
DEFAULT_ADMIN_USERNAME=admin
DEFAULT_ADMIN_PASSWORD=your_secure_password
DEFAULT_ADMIN_EMAIL=admin@example.com
AUTO_CREATE_ADMIN=true

# 功能开关
ALLOW_REGISTRATION=true
REQUIRE_EMAIL_VERIFICATION=false
ENABLE_WEBDAV_BACKUP=true
ENABLE_API=true
ENABLE_PWA=true

# 安全配置
SESSION_NAME=onebooknav_session
SESSION_LIFETIME=2592000
CSRF_TOKEN_NAME=_token

# 文件上传配置
UPLOAD_MAX_SIZE=2097152
ALLOWED_ICON_TYPES=jpg,jpeg,png,gif,svg,ico

# 缓存配置
CACHE_ENABLED=true
CACHE_TYPE=file
CACHE_PATH=./data/cache/
CACHE_TTL=3600

# 调试和开发
DEBUG_MODE=false
LOG_LEVEL=INFO
LOG_PATH=./data/logs/
```

### 3. PHP 直接部署

**config/config.php 示例:**
```php
<?php
// 站点配置
define('SITE_TITLE', $_ENV['SITE_TITLE'] ?? 'OneBookNav');
define('SITE_DESCRIPTION', $_ENV['SITE_DESCRIPTION'] ?? 'Personal bookmark management');
define('SITE_URL', $_ENV['SITE_URL'] ?? 'http://localhost');

// 数据库配置
define('DB_TYPE', $_ENV['DB_TYPE'] ?? 'sqlite');
define('DB_FILE', $_ENV['DB_FILE'] ?? __DIR__ . '/../data/onebooknav.db');

// 管理员账户
define('DEFAULT_ADMIN_USERNAME', $_ENV['DEFAULT_ADMIN_USERNAME'] ?? 'admin');
define('DEFAULT_ADMIN_PASSWORD', $_ENV['DEFAULT_ADMIN_PASSWORD'] ?? 'admin679');
define('DEFAULT_ADMIN_EMAIL', $_ENV['DEFAULT_ADMIN_EMAIL'] ?? 'admin@example.com');
define('AUTO_CREATE_ADMIN', filter_var($_ENV['AUTO_CREATE_ADMIN'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// 功能配置
define('ALLOW_REGISTRATION', filter_var($_ENV['ALLOW_REGISTRATION'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('DEBUG_MODE', filter_var($_ENV['DEBUG_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
```

**使用 .env 文件 (可选):**
```bash
# 创建 .env 文件
cp .env.example .env

# 编辑配置
nano .env
```

## 🔐 敏感变量处理

### Cloudflare Workers
敏感变量通过 Environment Variables 设置，不在代码中暴露:
```bash
# 通过 wrangler CLI 设置 (推荐)
wrangler secret put DEFAULT_ADMIN_PASSWORD

# 或在 Dashboard 中设置
Environment Variables → Add variable
```

### Docker
敏感变量通过环境文件或 Docker secrets:
```yaml
# 使用环境文件
env_file:
  - .env

# 或使用 Docker secrets
secrets:
  - admin_password
```

### PHP 部署
敏感变量通过系统环境变量或 .env 文件:
```bash
# 系统环境变量
export DEFAULT_ADMIN_PASSWORD="secure_password"

# .env 文件
echo "DEFAULT_ADMIN_PASSWORD=secure_password" >> .env
```

## 📊 环境变量验证

### 验证脚本示例
```bash
#!/bin/bash
# validate_env.sh

required_vars=(
    "SITE_TITLE"
    "DEFAULT_ADMIN_USERNAME"
    "DEFAULT_ADMIN_PASSWORD"
    "AUTO_CREATE_ADMIN"
)

for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        echo "❌ Required environment variable $var is not set"
        exit 1
    else
        echo "✅ $var is set"
    fi
done

echo "🎉 All required environment variables are set"
```

### 配置检查清单

#### Cloudflare Workers
- [ ] `SITE_TITLE` 已设置
- [ ] `DEFAULT_ADMIN_USERNAME` 已设置
- [ ] `DEFAULT_ADMIN_PASSWORD` 已设置 (Environment Variables)
- [ ] `AUTO_CREATE_ADMIN` 设为 `true`
- [ ] D1 数据库绑定已配置 (变量名: DB)

#### Docker
- [ ] `docker-compose.yml` 中环境变量已设置
- [ ] `.env` 文件已创建 (如果使用)
- [ ] 敏感变量已正确配置
- [ ] 数据卷已挂载 (`onebooknav_data`)

#### PHP 部署
- [ ] `config/config.php` 已配置
- [ ] 数据库连接已测试
- [ ] 文件权限已设置 (data 目录可写)
- [ ] Web 服务器配置已更新

## 🔄 环境变量迁移

### 从旧版本升级
如果从使用 JWT_SECRET 的旧版本升级:

1. **删除 JWT_SECRET**
```bash
# Cloudflare Workers
# 在 Dashboard 中删除 JWT_SECRET 环境变量

# Docker
# 从 docker-compose.yml 或 .env 中删除 JWT_SECRET

# PHP
# 从 config.php 中删除 JWT_SECRET 定义
```

2. **验证新配置**
```bash
# 检查必需变量是否存在
grep -E "(SITE_TITLE|DEFAULT_ADMIN)" config.php
```

### 部署方式迁移
从一种部署方式迁移到另一种时的环境变量映射:

| 功能 | Cloudflare Workers | Docker | PHP |
|------|-------------------|--------|-----|
| 站点标题 | `SITE_TITLE` | `SITE_TITLE` | `SITE_TITLE` |
| 管理员用户名 | `DEFAULT_ADMIN_USERNAME` | `DEFAULT_ADMIN_USERNAME` | `DEFAULT_ADMIN_USERNAME` |
| 管理员密码 | `DEFAULT_ADMIN_PASSWORD` | `DEFAULT_ADMIN_PASSWORD` | `DEFAULT_ADMIN_PASSWORD` |
| 自动创建管理员 | `AUTO_CREATE_ADMIN` | `AUTO_CREATE_ADMIN` | `AUTO_CREATE_ADMIN` |
| 数据库类型 | N/A (D1) | `DB_TYPE` | `DB_TYPE` |
| 调试模式 | N/A | `DEBUG_MODE` | `DEBUG_MODE` |

## 📝 最佳实践

### 1. 变量命名规范
- 使用大写字母和下划线
- 使用描述性名称
- 添加适当的前缀 (如 `DEFAULT_`, `ENABLE_`)

### 2. 默认值策略
- 为所有变量提供合理的默认值
- 敏感变量 (如密码) 不设默认值
- 布尔值使用 `true`/`false` 字符串

### 3. 安全考虑
- 敏感变量通过专门的机制设置
- 不在代码仓库中提交敏感信息
- 定期轮换密码和密钥

### 4. 文档维护
- 保持环境变量文档的更新
- 提供各部署方式的示例配置
- 说明变量的作用和影响

## 🔗 相关文档

- [Cloudflare Workers 部署指南](workers-console-setup.md)
- [Docker 部署指南](DOCKER_DEPLOYMENT.md)
- [PHP 部署指南](PHP_DEPLOYMENT.md)
- [故障排除指南](TROUBLESHOOTING.md)