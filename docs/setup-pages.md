# OneBookNav Cloudflare Pages 部署设置指南

## 🚀 快速设置

### 步骤1: 确认项目结构
✅ Workers 配置文件位于 `workers/wrangler.toml`
✅ Workers 代码位于 `workers/index.js`
✅ 数据库初始化脚本位于 `data/schema.sql`

### 步骤2: Cloudflare Pages 控制台设置

#### 2.1 访问你的 Pages 项目
1. 登录 [Cloudflare Dashboard](https://dash.cloudflare.com)
2. 进入 **Workers & Pages**
3. 选择你的项目

#### 2.2 配置 D1 数据库绑定
1. 进入 **设置** → **Functions**
2. **D1 数据库绑定** → **添加绑定**:
   ```
   变量名称: DB
   D1 数据库: [创建新数据库 "onebooknav"]
   ```

#### 2.3 配置环境变量
进入 **设置** → **环境变量**，添加以下变量:

**基础变量：**
```
SITE_TITLE = OneBookNav
DEFAULT_ADMIN_USERNAME = admin
DEFAULT_ADMIN_EMAIL = admin@example.com
AUTO_CREATE_ADMIN = true
```

**安全密钥：**
```
DEFAULT_ADMIN_PASSWORD = [你的管理员密码，建议包含大小写字母、数字、特殊字符]
```

#### 2.4 初始化数据库
在控制台中运行 D1 命令初始化数据库结构：
1. 进入 **Workers & Pages** → **D1 SQL Database**
2. 选择 "onebooknav" 数据库
3. 在 **控制台** 中执行初始化 SQL（复制 `data/schema.sql` 的内容）

### 步骤3: 重新部署
1. 返回 **部署** 页面
2. 点击 **重试部署** 或推送新的 commit

## 🎉 完成！

现在访问你的 Pages 域名，使用以下账户登录：
- **用户名**: admin
- **密码**: 你设置的 DEFAULT_ADMIN_PASSWORD

## 🔧 故障排除

### 如果仍然出现错误：
1. 检查所有环境变量是否正确设置
2. 确认 D1 数据库绑定变量名为 "DB"
3. 验证数据库已初始化（有 users, categories, bookmarks 表）
4. 查看 Functions 日志获取详细错误信息

### 常见问题：
- **"validateEnvironment failed"**: 检查环境变量是否完整
- **"D1 Database binding not configured"**: 检查 D1 绑定设置
- **"DEFAULT_ADMIN_PASSWORD secret not set"**: 检查密钥变量设置