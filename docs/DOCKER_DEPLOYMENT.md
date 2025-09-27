# OneBookNav Docker 部署完整指南

## 🐳 概述

OneBookNav 支持多种 Docker 部署方式，从简单的单容器 SQLite 部署到复杂的多容器生产环境。

## 🚀 快速开始

### 方法1: 最简单部署

```bash
# 克隆项目
git clone https://github.com/onebooknav/onebooknav.git
cd onebooknav

# 启动服务
docker-compose up -d

# 访问 http://localhost:3080
# 默认账户: admin / admin679
```

### 方法2: 自定义配置

1. **复制环境变量模板**
```bash
cp .env.example .env
```

2. **编辑环境变量**
```bash
nano .env
```

3. **启动服务**
```bash
docker-compose up -d
```

## 🔧 环境变量配置

### 必需变量
```bash
# 站点基本信息
SITE_TITLE="OneBookNav"
SITE_URL="http://localhost:3080"

# 数据库配置
DB_TYPE="sqlite"  # 或 mysql

# 管理员账户
DEFAULT_ADMIN_USERNAME="admin"
DEFAULT_ADMIN_PASSWORD="your_secure_password"
DEFAULT_ADMIN_EMAIL="admin@example.com"
AUTO_CREATE_ADMIN=true
```

### 可选变量
```bash
# 功能开关
DEBUG_MODE=false
ALLOW_REGISTRATION=true
ENABLE_PWA=true

# 文件上传
UPLOAD_MAX_SIZE=2097152  # 2MB

# 缓存配置
CACHE_ENABLED=true
CACHE_TYPE="file"  # 或 redis
```

## 📋 部署方式对比

| 部署方式 | 数据库 | 缓存 | 适用场景 | 命令 |
|----------|--------|------|----------|------|
| **基础部署** | SQLite | 文件 | 个人使用 | `docker-compose up -d` |
| **开发模式** | SQLite | 文件 | 开发调试 | `docker-compose --profile development up -d` |
| **MySQL版本** | MySQL | 文件 | 中型站点 | `docker-compose --profile with-mysql up -d` |
| **Redis缓存** | SQLite | Redis | 高性能 | `docker-compose --profile with-cache up -d` |
| **完整生产** | MySQL | Redis | 大型部署 | `docker-compose --profile with-mysql --profile with-cache up -d` |

## 🏗️ 详细部署方式

### 1. 基础部署 (SQLite)
最简单的部署方式，适合个人使用。

```bash
docker-compose up -d
```

**特点:**
- SQLite 数据库
- 文件缓存
- 单容器部署
- 端口: 3080

### 2. 开发模式
包含开发工具和调试功能。

```bash
docker-compose --profile development up -d
```

**特点:**
- 热重载
- XDebug 支持 (端口 9003)
- 详细日志
- 源码挂载

### 3. MySQL 生产环境
使用 MySQL 数据库的生产环境。

```bash
docker-compose --profile with-mysql up -d
```

**环境变量 (MySQL):**
```bash
DB_TYPE="mysql"
DB_HOST="mysql"
DB_NAME="onebooknav"
DB_USER="onebooknav"
DB_PASS="onebooknav"

# MySQL 容器配置
MYSQL_ROOT_PASSWORD="rootpassword"
MYSQL_DATABASE="onebooknav"
MYSQL_USER="onebooknav"
MYSQL_PASSWORD="onebooknav"
```

### 4. Redis 缓存加速
添加 Redis 缓存提升性能。

```bash
docker-compose --profile with-cache up -d
```

**环境变量 (Redis):**
```bash
CACHE_TYPE="redis"
REDIS_HOST="redis"
REDIS_PORT=6379
```

### 5. 完整生产环境
MySQL + Redis + SSL 代理的完整方案。

```bash
docker-compose --profile with-mysql --profile with-cache --profile with-proxy up -d
```

## 🔐 安全配置

### SSL/HTTPS 设置
```bash
# 启用 SSL 代理
docker-compose --profile with-proxy up -d

# 证书存放位置
./ssl/cert.pem
./ssl/key.pem
```

### 数据备份
```bash
# 备份 SQLite 数据库
docker-compose exec onebooknav cp /var/www/html/data/onebooknav.db /var/www/html/data/backups/

# 备份 MySQL 数据库
docker-compose exec mysql mysqldump -u root -p onebooknav > backup.sql
```

## 🛠️ 常用操作

### 查看日志
```bash
# 查看所有服务日志
docker-compose logs -f

# 查看特定服务日志
docker-compose logs -f onebooknav
docker-compose logs -f mysql
docker-compose logs -f redis
```

### 服务管理
```bash
# 启动服务
docker-compose up -d

# 停止服务
docker-compose down

# 重启服务
docker-compose restart

# 更新镜像
docker-compose pull && docker-compose up -d
```

### 数据管理
```bash
# 进入容器
docker-compose exec onebooknav bash

# 查看数据库
docker-compose exec mysql mysql -u root -p onebooknav

# 查看 Redis
docker-compose exec redis redis-cli
```

## 🔧 故障排除

### 常见问题

#### 1. 端口被占用
```bash
# 检查端口占用
netstat -tlnp | grep 3080

# 修改端口 (docker-compose.yml)
ports:
  - "8080:80"  # 改为 8080
```

#### 2. 权限问题
```bash
# 修复数据目录权限
sudo chown -R www-data:www-data ./data
sudo chmod -R 755 ./data
```

#### 3. 数据库连接失败
```bash
# 检查 MySQL 服务状态
docker-compose ps mysql

# 查看 MySQL 日志
docker-compose logs mysql

# 重新初始化数据库
docker-compose down -v
docker-compose up -d
```

#### 4. 内存不足
```bash
# 查看容器资源使用
docker stats

# 限制内存使用 (docker-compose.yml)
deploy:
  resources:
    limits:
      memory: 512M
```

### 健康检查
```bash
# 检查服务健康状态
docker-compose ps

# 手动健康检查
curl -f http://localhost:3080 || echo "Service unavailable"
```

## 📊 性能优化

### 生产环境推荐配置
```yaml
# docker-compose.override.yml
version: '3.8'
services:
  onebooknav:
    deploy:
      resources:
        limits:
          memory: 256M
          cpus: '0.5'
    environment:
      - DEBUG_MODE=false
      - CACHE_ENABLED=true
      - LOG_LEVEL=ERROR
```

### 监控配置
```bash
# 添加监控服务 (可选)
docker-compose --profile monitoring up -d
```

## 🔄 更新升级

### 常规更新
```bash
# 1. 备份数据
docker-compose exec onebooknav cp /var/www/html/data /backup/

# 2. 拉取最新代码
git pull

# 3. 重新构建并启动
docker-compose build --no-cache
docker-compose up -d
```

### 版本升级
```bash
# 查看当前版本
docker-compose exec onebooknav cat /var/www/html/config/config.php | grep VERSION

# 升级到新版本
git checkout v2.0.0
docker-compose build --no-cache
docker-compose up -d
```

## 📞 获取帮助

如果遇到问题，请：
1. 查看日志: `docker-compose logs -f`
2. 检查服务状态: `docker-compose ps`
3. 参考故障排除指南: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
4. 提交 Issue: [GitHub Issues](https://github.com/onebooknav/onebooknav/issues)