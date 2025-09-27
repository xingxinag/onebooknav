# OneBookNav 故障排除指南

## 🚨 Cloudflare Workers 部署问题

### 错误1: "Service unavailable - Configuration error"

**症状**: 访问网站显示 503 错误，控制台显示配置错误

**原因**: 环境变量或数据库绑定未正确设置

**解决方案**:
1. 检查环境变量是否完整：
   ```
   SITE_TITLE, DEFAULT_ADMIN_USERNAME, AUTO_CREATE_ADMIN,
   DEFAULT_ADMIN_PASSWORD
   ```

2. 确认 D1 数据库绑定：
   - 变量名必须是 `DB`（大写）
   - 数据库名为 `onebooknav`
   - 数据库已初始化（执行过 schema.sql）

3. 重新部署：**Save and Deploy**

### 错误2: "DEFAULT_ADMIN_PASSWORD secret is required"

**症状**: 控制台显示需要管理员密码密钥

**原因**: 未设置管理员密码环境变量

**解决方案**:
1. Worker → **设置** → **变量** → **环境变量**
2. 添加：`DEFAULT_ADMIN_PASSWORD = YourSecurePassword123!`
3. 重新部署

### 错误4: 登录失败 401 错误

**症状**: 使用管理员账户登录时显示 401 未授权错误

**原因**: 管理员账户未正确创建或密码验证失败

**解决方案**:
1. 检查环境变量设置：
   ```
   DEFAULT_ADMIN_USERNAME = admin
   DEFAULT_ADMIN_PASSWORD = YourPassword123
   AUTO_CREATE_ADMIN = true
   ```
2. 查看 Worker 日志确认管理员账户是否创建成功
3. 如果问题持续，删除并重新创建 D1 数据库
4. 重新初始化数据库表结构

### 错误3: "D1 Database binding (DB) is not configured"

**症状**: 数据库连接失败

**解决方案**:
1. 创建 D1 数据库：**Workers & Pages** → **D1 SQL Database** → **创建数据库** `onebooknav`
2. 添加绑定：Worker → **设置** → **变量** → **D1 数据库绑定**
   - 变量名：`DB`
   - 数据库：`onebooknav`
3. 初始化数据库：在 D1 控制台执行 `data/schema.sql`

### 错误4: "Missing entry-point to Worker script"

**症状**: Pages Git 集成部署失败

**解决方案**:
1. 确保根目录有 `wrangler.jsonc` 文件
2. 检查构建设置：
   - 框架预设：None
   - 构建命令：留空
   - 构建输出目录：留空

---

## 🐳 Docker 部署问题

### 错误1: 端口占用

**症状**: `bind: address already in use`

**解决方案**:
```bash
# 停止占用端口的服务
sudo lsof -i :3080
sudo kill -9 <PID>

# 或更改端口
docker-compose down
# 编辑 docker-compose.yml 更改端口
docker-compose up -d
```

### 错误2: 权限问题

**症状**: 文件写入失败，403 错误

**解决方案**:
```bash
# 设置正确权限
sudo chown -R www-data:www-data /var/www/html/data
sudo chmod -R 755 /var/www/html
sudo chmod -R 777 /var/www/html/data
```

### 错误3: 数据库连接失败

**症状**: "Connection refused" 或 "Unknown database"

**解决方案**:
1. 检查数据库容器状态：`docker-compose ps`
2. 查看数据库日志：`docker-compose logs mysql`
3. 重新创建数据库：
   ```bash
   docker-compose down -v
   docker-compose up -d
   ```

---

## 📦 PHP 直接部署问题

### 错误1: 500 内部服务器错误

**原因**: PHP 配置问题

**解决方案**:
1. 检查 PHP 版本：`php -v`（需要 7.4+）
2. 检查必需扩展：
   ```bash
   php -m | grep -E "(pdo|json|mbstring|openssl)"
   ```
3. 查看错误日志：
   ```bash
   tail -f /var/log/apache2/error.log
   # 或
   tail -f /var/log/nginx/error.log
   ```

### 错误2: 数据库连接失败

**解决方案**:
1. 检查数据库配置：`config/config.php`
2. 确认数据库服务运行：`systemctl status mysql`
3. 测试连接：
   ```php
   <?php
   try {
       $pdo = new PDO("mysql:host=localhost;dbname=onebooknav", "user", "password");
       echo "连接成功";
   } catch(PDOException $e) {
       echo "连接失败: " . $e->getMessage();
   }
   ?>
   ```

### 错误3: 文件权限问题

**症状**: 上传失败，配置保存失败

**解决方案**:
```bash
# Apache
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html
sudo chmod -R 777 /var/www/html/data
sudo chmod -R 777 /var/www/html/config

# Nginx
sudo chown -R nginx:nginx /var/www/html
sudo chmod -R 755 /var/www/html
sudo chmod -R 777 /var/www/html/data
sudo chmod -R 777 /var/www/html/config
```

---

## 🔧 常见功能问题

### 登录问题

**症状**: 无法登录，密码错误

**解决方案**:
1. 重置管理员密码：
   ```sql
   UPDATE users SET password_hash = '$2y$10$example_hash' WHERE username = 'admin';
   ```
2. 清除浏览器缓存和 Cookie
3. 检查 DEFAULT_ADMIN_PASSWORD 是否设置

### 书签导入失败

**症状**: 导入书签时出现错误

**解决方案**:
1. 检查文件格式（支持 JSON、HTML、CSV）
2. 确认文件大小限制
3. 查看服务器日志获取详细错误信息

### 图标加载失败

**症状**: 网站图标显示为默认图标

**解决方案**:
1. 检查网络连接
2. 确认目标网站支持 favicon
3. 手动设置图标 URL

---

## 🔍 调试工具

### 查看日志

**Cloudflare Workers**:
- 实时日志：`wrangler tail`
- 控制台：Worker → **监控** → **日志**

**Docker**:
```bash
docker-compose logs -f onebooknav
docker-compose logs -f mysql
```

**PHP**:
```bash
tail -f /var/log/apache2/error.log
tail -f /var/log/php7.4-fpm.log
```

### 数据库检查

**D1 Database**:
```sql
-- 检查表是否存在
SELECT name FROM sqlite_master WHERE type='table';

-- 检查用户
SELECT * FROM users;
```

**MySQL/PostgreSQL**:
```sql
SHOW TABLES;
SELECT * FROM users LIMIT 5;
```

---

## 📞 获取帮助

如果以上解决方案都无法解决问题：

1. **查看项目文档**: [README.md](README.md)
2. **提交 Issue**: [GitHub Issues](https://github.com/onebooknav/onebooknav/issues)
3. **社区讨论**: [GitHub Discussions](https://github.com/onebooknav/onebooknav/discussions)

**提交问题时请包含**:
- 部署方式（Cloudflare/Docker/PHP）
- 错误信息截图
- 相关日志
- 环境信息（PHP版本、浏览器等）