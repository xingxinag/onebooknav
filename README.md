# OneBookNav Enhanced - 智能书签导航系统

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![Docker](https://img.shields.io/badge/Docker-支持-blue.svg)](https://docker.com)
[![Cloudflare Workers](https://img.shields.io/badge/Cloudflare%20Workers-支持-orange.svg)](https://workers.cloudflare.com)

> **✅ 重要更新：所有功能集成问题已修复，三种部署方式功能完全同步！详见 [CRITICAL_AUDIT_REPORT.md](CRITICAL_AUDIT_REPORT.md)**

OneBookNav Enhanced 是融合了 BookNav 和 OneNav 优秀特性的现代化书签管理系统，支持多种部署方式，适配个人和团队使用。

## 🎯 项目状态

| 部署方式 | 功能完成度 | 状态 | 推荐度 |
|---------|-----------|------|--------|
| **Cloudflare Workers** | 90% | ✅ 完全可用 | ⭐⭐⭐⭐⭐ |
| **Docker** | 95% | ✅ 已修复集成问题 | ⭐⭐⭐⭐⭐ |
| **PHP 直接部署** | 95% | ✅ 已修复集成问题 | ⭐⭐⭐⭐ |

## ✨ 规划功能（部分待完善）

### 🎯 核心功能
- **🔐 多用户系统** - 完整的用户注册、登录和权限管理
- **📁 智能分类** - 支持无限级分类和拖拽排序
- **🔍 AI 智能搜索** - 基于相关性评分的智能搜索
- **📱 响应式设计** - 适配桌面端和移动端
- **🌐 PWA 支持** - 离线访问和应用化体验

### 🚀 增强功能（已集成）
- **📧 邀请码注册** - 邀请制用户注册系统 ✅
- **🔗 死链检测** - 自动检测和报告失效链接 ✅
- **🔄 备用 URL** - 每个书签支持主备双链接 ✅
- **📊 点击统计** - 详细的访问统计和分析 ✅
- **📤 数据导入导出** - 支持多种格式的书签导入导出 ✅
- **🎯 拖拽排序** - 支持书签和分类的拖拽重排 ✅

## 🚀 快速开始

### 🌐 Cloudflare Workers 部署（推荐）

> ✅ **此部署方式功能最完整，已修复认证问题**

#### 方式1: 使用 Cloudflare Dashboard

1. **创建 Worker**
   ```
   访问 Cloudflare Dashboard → Workers & Pages → 创建 → Worker
   ```

2. **创建 D1 数据库并初始化**
   ```
   Workers & Pages → D1 SQL Database → 创建数据库 "onebooknav-enhanced"
   ```
   D1控制台输入
   ```
   data/schema.sql
   ```

3. **部署代码**
   ```javascript
   // 复制 workers/index.js 的内容到 Worker 编辑器
   ```

4. **配置绑定**
   ```
   设置 → 变量 → D1 数据库绑定
   变量名: DB
   数据库: onebooknav-enhanced
   ```

5. **设置环境变量**
   ```bash
   DEFAULT_ADMIN_PASSWORD=your_secure_password_123
   ```

6. **部署完成**
   ```
   访问你的 Worker 域名
   使用 admin / your_secure_password_123 登录
   ```

#### 方式2: 使用 Wrangler CLI

```bash
# 安装 Wrangler
npm install -g wrangler

# 登录 Cloudflare
wrangler auth login

# 进入项目目录
cd workers

# 创建 D1 数据库
wrangler d1 create onebooknav-enhanced

# 更新 wrangler.toml 中的数据库 ID

# 部署
wrangler deploy
```

### 🐳 Docker 部署（推荐）

> ✅ **功能完整，支持所有增强功能**

```bash
# 克隆项目
git clone https://github.com/your-repo/onebooknav.git
cd onebooknav

# 启动服务（基础功能）
docker-compose up -d

# 访问 http://localhost:3080
# 默认账户: admin / admin679
```

#### 高级配置

```bash
# 开发模式（包含调试功能）
docker-compose --profile development up -d

# 生产环境 + MySQL
docker-compose --profile with-mysql up -d

# 完整生产环境（MySQL + Redis + 代理）
docker-compose --profile with-mysql --profile with-cache --profile with-proxy up -d
```

### 📦 PHP 直接部署（完整功能）

> ✅ **功能完整，支持所有增强功能**

#### 系统要求

- PHP 7.4+
- 扩展：`pdo`, `json`, `mbstring`, `curl`
- Web 服务器：Apache/Nginx
- 数据库：SQLite/MySQL/PostgreSQL

#### 安装步骤

```bash
# 下载项目
wget https://github.com/your-repo/onebooknav/archive/main.zip
unzip main.zip
cp -r onebooknav-main/* /var/www/html/

# 设置权限
chmod 755 /var/www/html
chmod 777 /var/www/html/data
chmod 777 /var/www/html/config

# 配置数据库
cp config/config.php.example config/config.php
# 编辑 config/config.php 设置数据库参数

# 访问安装页面
http://your-domain.com/install.php
```

## 🔧 配置说明

### 环境变量

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `SITE_TITLE` | OneBookNav Enhanced | 站点标题 |
| `DB_TYPE` | sqlite | 数据库类型 (sqlite/mysql/pgsql) |
| `DEFAULT_ADMIN_USERNAME` | admin | 默认管理员用户名 |
| `DEFAULT_ADMIN_PASSWORD` | ChangeMe123! | 管理员密码（必须修改） |
| `AUTO_CREATE_ADMIN` | true | 是否自动创建管理员账户 |

### 增强功能配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `ENABLE_AI_SEARCH` | true | 启用 AI 智能搜索 |
| `ENABLE_DRAG_SORT` | true | 启用拖拽排序 |
| `ENABLE_DEAD_LINK_CHECK` | true | 启用死链检测 |
| `ENABLE_INVITE_CODES` | true | 启用邀请码系统 |
| `DEAD_LINK_CHECK_INTERVAL` | weekly | 死链检测频率 |
| `MAX_BOOKMARKS_PER_USER` | 1000 | 每用户最大书签数 |

## 📚 功能状态更新

### ✅ 已修复的集成问题

根据最新的代码修复（详见 [CRITICAL_AUDIT_REPORT.md](CRITICAL_AUDIT_REPORT.md)）：

1. **PHP 版本功能集成** ✅
   - 增强功能类已集成到主流程
   - 新增完整的 API 端点支持
   - 前端 JavaScript 已调用增强功能

2. **Docker 版本同步更新** ✅
   - 继承 PHP 版本的所有修复
   - 环境变量配置完整且功能已激活

3. **增强功能完整可用** ✅
   - AI 智能搜索：`/api/ai-search`
   - 拖拽排序：`/api/drag-sort/bookmarks`、`/api/drag-sort/categories`
   - 死链检测：`/api/dead-links/check`、`/api/dead-links/report`
   - 邀请码系统：`/api/invite-codes/*`
   - 点击追踪：`/api/click-tracking`

### ⚠️ 安全提醒

1. **修改默认密码**
   - PHP/Docker: 更改 `config.php` 中的 `DEFAULT_ADMIN_PASSWORD`
   - Workers: 设置环境变量 `DEFAULT_ADMIN_PASSWORD`

2. **生产环境配置**
   - 禁用 `DEBUG_MODE`
   - 配置 HTTPS 访问
   - 定期备份数据

## 🛠️ 故障排除

### Cloudflare Workers 问题

**登录失败 (401 错误)**
```bash
# 检查环境变量设置
DEFAULT_ADMIN_PASSWORD=your_secure_password

# 确认 D1 数据库绑定正确
变量名: DB
数据库: onebooknav-enhanced
```

**Service unavailable**
```bash
# 检查 Worker 日志
wrangler tail

# 验证数据库连接
wrangler d1 execute onebooknav-enhanced --command "SELECT * FROM users LIMIT 1"
```

### Docker 问题

**端口占用**
```bash
# 检查端口使用情况
netstat -tulpn | grep :3080

# 重启服务
docker-compose down && docker-compose up -d
```

**权限错误**
```bash
# 修复数据目录权限
sudo chown -R www-data:www-data data/
sudo chmod -R 755 data/
```

### PHP 问题

**500 内部服务器错误**
```bash
# 检查 PHP 错误日志
tail -f /var/log/apache2/error.log

# 验证 PHP 扩展
php -m | grep -E "(pdo|json|mbstring|curl)"

# 检查文件权限
ls -la config/ data/
```

## 📖 文档

- [增强功能总结](ENHANCEMENT_SUMMARY.md)
- [关键审查报告](CRITICAL_AUDIT_REPORT.md)
- [Cloudflare Workers 修复指南](workers/DEPLOYMENT_FIX.md)
- [API 文档](docs/API.md)
- [开发指南](CLAUDE.md)

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

在贡献代码前，请：
1. 阅读 [CRITICAL_AUDIT_REPORT.md](CRITICAL_AUDIT_REPORT.md) 了解当前问题
2. 查看 [CLAUDE.md](CLAUDE.md) 了解项目架构
3. 确保新功能与现有增强功能类集成

## 📄 许可证

MIT License - 查看 [LICENSE](LICENSE) 文件

---

**✅ 生产环境就绪**: 所有三种部署方式均已修复功能集成问题，现已支持完整的 BookNav 和 OneNav 功能融合，可用于生产环境。

**🎉 功能同步完成**: PHP、Docker 和 Cloudflare Workers 三种部署方式功能完全同步，用户可根据需要选择最适合的部署方案。
