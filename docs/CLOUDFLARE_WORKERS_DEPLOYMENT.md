# OneBookNav Cloudflare Workers Deployment Guide

## Issues Fixed

### 1. PHP Install Script Error (CRITICAL)
**Problem**: The deployment was failing because the root `package.json` had an "install" script that runs `php install.php`, but PHP is not available in Cloudflare Workers environment.

**Solution**: Removed the problematic install script from `onebooknav/package.json:23`.

### 2. Security Issues (CRITICAL)
**Problem**: Admin password was stored in plain text in environment variables in `wrangler.toml`.

**Solution**:
- Removed hardcoded passwords from `wrangler.toml`
- Modified the admin initialization code to require passwords to be set via secrets
- Added password strength validation (minimum 8 characters)

### 3. Configuration Issues
**Problems**:
- Incorrect D1 database binding syntax in environment configurations
- Optional services (R2, KV) were configured as required
- Routes were hardcoded for custom domains

**Solutions**:
- Fixed D1 database binding syntax (added double brackets `[[env.development.d1_databases]]`)
- Made R2 and KV bindings optional (commented out)
- Made custom domain routes optional

### 4. Missing Environment Validation
**Problem**: No validation of required environment variables and bindings.

**Solution**: Added comprehensive environment validation that checks:
- D1 database binding presence
- Required secrets for admin creation
- JWT secret for authentication

## 部署错误原因分析

**根本原因：** 命令在项目根目录执行了 `npx wrangler deploy`，但 wrangler 配置和入口文件在 `workers/` 子目录中。

**项目结构：**
- 主项目：PHP 应用，入口文件是 `index.php`
- Workers 子项目：在 `workers/` 目录中，包含完整的配置和代码

## Deployment Steps

### 1. Prerequisites
```bash
# Install Wrangler CLI
npm install -g wrangler

# Login to Cloudflare
wrangler login
```

### 2. Create D1 Database
```bash
cd onebooknav/workers
wrangler d1 create onebooknav
```

Update `wrangler.toml` with the returned database ID.

### 3. Set Required Secrets

#### JWT_SECRET 密钥设置
**作用：**
- 用于生成和验证 JSON Web Token (JWT)
- JWT 用于用户身份验证和会话管理
- 确保用户登录状态的安全性

**生成强密钥的方法：**
- 在线生成器：访问 https://www.uuidgenerator.net/ 生成 UUID
- 命令行生成：`openssl rand -hex 32`
- 或使用任意64位随机字符串

```bash
# 设置 JWT 密钥
wrangler secret put JWT_SECRET
# 提示输入时，粘贴你的随机密钥，例如：
# abc123def456ghi789jkl012mno345pqr678stu901vwx234yzabc567def890
```

#### DEFAULT_ADMIN_PASSWORD 密钥设置
**作用：**
- 设置系统默认管理员账户的密码
- 首次部署时会自动创建管理员账户
- 用户名默认为 "admin"（在 wrangler.toml 中配置）

```bash
# 设置管理员密码
wrangler secret put DEFAULT_ADMIN_PASSWORD
# 提示输入时，输入你的管理员密码，例如：
# MySecurePassword123!
```

**安全要求：**
- 密码最少8个字符
- 建议包含大小写字母、数字和特殊字符
- 密码将存储在 Cloudflare 安全环境中，不会出现在代码里

### 4. Initialize Database
```bash
# Run database migrations (if schema file exists)
wrangler d1 execute onebooknav --file=../data/schema.sql
```

### 5. Deploy

**方案1（推荐）：使用 workers 目录中的现有配置**
```bash
cd onebooknav/workers
wrangler deploy

# 或部署到指定环境
wrangler deploy --env development
wrangler deploy --env production
```

**方案2：使用根目录的新配置**
如果已在根目录创建了 wrangler.toml，可以：
```bash
cd onebooknav
wrangler deploy
```

### 6. 完整操作步骤
```bash
# 1. 进入 workers 目录
cd onebooknav/workers

# 2. 创建 D1 数据库
wrangler d1 create onebooknav

# 3. 设置 JWT 密钥
wrangler secret put JWT_SECRET
# 提示输入时，粘贴你的随机密钥

# 4. 设置管理员密码
wrangler secret put DEFAULT_ADMIN_PASSWORD
# 提示输入时，输入你的管理员密码

# 5. 更新 wrangler.toml 中的 database_id
# 将步骤2返回的数据库ID替换到配置文件中

# 6. 部署
wrangler deploy
```

部署完成后，你可以用 `admin` 用户名和设置的密码登录管理后台。

## Environment Configurations

### Development
- Uses `onebooknav-dev` database
- Has development-specific settings

### Production
- Uses `onebooknav-prod` database
- Production-optimized settings

## Optional Services

### R2 Storage (for static assets)
If you want to use R2 for static assets, uncomment the R2 binding in `wrangler.toml`:
```toml
[[r2_buckets]]
binding = "STORAGE"
bucket_name = "onebooknav-assets"
```

### KV Storage (for caching)
If you want to use KV for caching, uncomment the KV binding in `wrangler.toml`:
```toml
[[kv_namespaces]]
binding = "CACHE"
id = "your-kv-namespace-id"
```

### Custom Domains
If you want to use a custom domain, uncomment and configure the routes in `wrangler.toml`:
```toml
[routes]
pattern = "onebooknav.your-domain.com/*"
zone_name = "your-domain.com"
```

## Security Notes

1. **Never store passwords in plain text** - Always use secrets for sensitive data
2. **JWT Secret** - Generate a strong, random JWT secret
3. **Admin Password** - Use a strong password (minimum 8 characters)
4. **Environment Separation** - Use different databases for development and production

## Troubleshooting

### Common Errors

1. **"php: command not found"**
   - Fixed by removing the PHP install script from package.json

2. **"DEFAULT_ADMIN_PASSWORD secret not set"**
   - Run: `wrangler secret put DEFAULT_ADMIN_PASSWORD`

3. **"JWT_SECRET is required"**
   - Run: `wrangler secret put JWT_SECRET`

4. **"D1 Database binding (DB) is not configured"**
   - Check your wrangler.toml database configuration
   - Ensure database_id is set correctly

### Logs
```bash
# View deployment logs
wrangler tail

# View specific environment logs
wrangler tail --env production
```

## Files Modified

1. `onebooknav/package.json` - Removed PHP install script
2. `onebooknav/workers/wrangler.toml` - Fixed security and configuration issues
3. `onebooknav/workers/index.js` - Added security and validation improvements

The deployment should now work correctly without the PHP dependency error.