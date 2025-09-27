# OneBookNav Workers 控制台部署指南

## 🎯 适用场景
你已经在 Cloudflare Dashboard 中创建了 Worker，需要配置环境变量和数据库绑定。

## 🚀 详细步骤

### 步骤1: 复制代码
1. 打开 [workers/index.js](workers/index.js) 文件
2. 复制所有内容（Ctrl+A, Ctrl+C）
3. 在 Cloudflare Workers 编辑器中粘贴，替换 Hello World 代码
4. 点击 **Save and Deploy**

### 步骤2: 创建 D1 数据库
1. 在 Cloudflare Dashboard 中，进入 **Workers & Pages** → **D1 SQL Database**
2. 点击 **Create database**
3. 数据库名称输入: `onebooknav`
4. 点击 **Create**

### 步骤3: 初始化数据库
1. 进入刚创建的 `onebooknav` 数据库
2. 点击 **Console**
3. 复制 [data/schema.sql](data/schema.sql) 的内容
4. 粘贴到控制台中执行，创建表结构

### 步骤4: 配置 D1 数据库绑定
1. 回到你的 Worker 页面
2. 进入 **Settings** → **Variables**
3. 在 **D1 Database Bindings** 部分点击 **Add binding**
4. 配置如下:
   ```
   Variable name: DB
   D1 database: onebooknav
   ```
5. 点击 **Save**

### 步骤5: 配置环境变量
在 **Environment Variables** 部分添加以下变量:

**基础配置:**
```
SITE_TITLE = OneBookNav
DEFAULT_ADMIN_USERNAME = admin
DEFAULT_ADMIN_EMAIL = admin@example.com
AUTO_CREATE_ADMIN = true
```

**安全密钥:**
```
DEFAULT_ADMIN_PASSWORD = YourSecurePassword123!
```

> ⚠️ **重要**:
> - DEFAULT_ADMIN_PASSWORD：建议包含大小写字母、数字、特殊字符

### 步骤6: 部署并测试
1. 点击 **Save and Deploy**
2. 等待部署完成
3. 访问你的 Worker 域名
4. 使用账户登录:
   - 用户名: `admin`
   - 密码: 你设置的 `DEFAULT_ADMIN_PASSWORD`

## 🔧 故障排除

### 错误1: "Service unavailable - Configuration error"
**原因**: 环境变量未正确设置
**解决**: 检查所有环境变量是否已添加，特别是 DEFAULT_ADMIN_PASSWORD

### 错误2: "D1 Database binding (DB) is not configured"
**原因**: D1 数据库绑定未设置或变量名错误
**解决**: 确保绑定变量名为 "DB"，数据库名为 "onebooknav"

### 错误3: 数据库相关错误
**原因**: 数据库表未创建
**解决**: 在 D1 控制台执行 schema.sql 创建表结构

## 📋 完整检查清单

- [ ] ✅ 已复制 workers/index.js 代码到 Worker
- [ ] ✅ 已创建 D1 数据库 "onebooknav"
- [ ] ✅ 已执行 schema.sql 初始化数据库
- [ ] ✅ 已配置 D1 绑定 (变量名: DB)
- [ ] ✅ 已设置基础环境变量 (SITE_TITLE, AUTO_CREATE_ADMIN 等)
- [ ] ✅ 已设置安全变量 (DEFAULT_ADMIN_PASSWORD)
- [ ] ✅ 已保存并部署
- [ ] ✅ 测试访问和登录功能

## 🎉 成功！
如果所有步骤都正确完成，你现在应该能够访问 OneBookNav 并使用管理员账户登录了。