# Cloudflare Workers 部署

## 快速部署

### 1. 创建 D1 数据库
```bash
wrangler d1 create onebooknav
```

复制输出中的 database_id

### 2. 配置 wrangler.toml
```bash
cp wrangler.toml.example wrangler.toml
```

编辑 `wrangler.toml`，替换 `YOUR_DATABASE_ID_HERE` 为实际的数据库ID

### 3. 设置密码
```bash
wrangler secret put DEFAULT_ADMIN_PASSWORD
```
输入：`admin679`

### 4. 部署
```bash
wrangler deploy
```

## 登录信息
- 用户名：`admin`
- 密码：`admin679`

## 调试
```bash
wrangler tail
```