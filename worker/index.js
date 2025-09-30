/**
 * OneBookNav Cloudflare Workers 入口文件
 *
 * 实现边缘计算部署的完整功能
 * 包括路由、数据存储、缓存、安全等
 */

import { OneBookNavApp } from './src/App';
import { Router } from './src/Router';
import { Database } from './src/Database';
import { Cache } from './src/Cache';
import { Auth } from './src/Auth';
import { Security } from './src/Security';
import { ErrorHandler } from './src/ErrorHandler';

/**
 * Durable Object 类 - 用于状态管理
 */
export class OneBookNavState extends DurableObject {
    constructor(ctx, env) {
        super(ctx, env);
        this.sessions = new Map();
        this.rateLimits = new Map();
    }

    async fetch(request) {
        const url = new URL(request.url);
        const path = url.pathname;

        switch (path) {
            case '/session':
                return this.handleSession(request);
            case '/rate-limit':
                return this.handleRateLimit(request);
            default:
                return new Response('Not Found', { status: 404 });
        }
    }

    async handleSession(request) {
        if (request.method === 'GET') {
            const sessionId = new URL(request.url).searchParams.get('id');
            const session = this.sessions.get(sessionId);
            return new Response(JSON.stringify(session || null));
        }

        if (request.method === 'POST') {
            const { sessionId, data, ttl } = await request.json();
            this.sessions.set(sessionId, { data, expires: Date.now() + (ttl * 1000) });

            // 清理过期会话
            this.cleanupExpiredSessions();

            return new Response('OK');
        }

        if (request.method === 'DELETE') {
            const sessionId = new URL(request.url).searchParams.get('id');
            this.sessions.delete(sessionId);
            return new Response('OK');
        }

        return new Response('Method Not Allowed', { status: 405 });
    }

    async handleRateLimit(request) {
        const { key, limit, window } = await request.json();
        const now = Date.now();
        const windowStart = Math.floor(now / (window * 1000)) * (window * 1000);

        const rateLimitKey = `${key}:${windowStart}`;
        const current = this.rateLimits.get(rateLimitKey) || 0;

        if (current >= limit) {
            return new Response(JSON.stringify({ allowed: false, remaining: 0 }));
        }

        this.rateLimits.set(rateLimitKey, current + 1);

        // 清理旧的限流记录
        this.cleanupOldRateLimits(now, window * 1000);

        return new Response(JSON.stringify({
            allowed: true,
            remaining: limit - current - 1
        }));
    }

    cleanupExpiredSessions() {
        const now = Date.now();
        for (const [sessionId, session] of this.sessions.entries()) {
            if (session.expires && session.expires < now) {
                this.sessions.delete(sessionId);
            }
        }
    }

    cleanupOldRateLimits(now, windowMs) {
        const cutoff = now - windowMs * 2; // 保留2个窗口期的数据
        for (const [key] of this.rateLimits.entries()) {
            const timestamp = parseInt(key.split(':').pop());
            if (timestamp < cutoff) {
                this.rateLimits.delete(key);
            }
        }
    }
}

/**
 * 主 Worker 处理函数
 */
export default {
    async fetch(request, env, ctx) {
        try {
            // 初始化应用组件
            const app = new OneBookNavApp(env, ctx);
            const router = new Router();
            const database = new Database(env.ONEBOOKNAV_DB);
            const cache = new Cache(env.ONEBOOKNAV_CACHE);
            const auth = new Auth(env.ONEBOOKNAV_SESSIONS, env.ONEBOOKNAV_STATE);
            const security = new Security(env.ONEBOOKNAV_STATE);

            // 安全检查
            const securityCheck = await security.checkRequest(request);
            if (!securityCheck.allowed) {
                return new Response(securityCheck.message, {
                    status: securityCheck.status,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            // 设置路由
            await this.setupRoutes(router, { app, database, cache, auth, security, env });

            // 处理请求
            const response = await router.handle(request);

            // 添加安全头
            return security.addSecurityHeaders(response);

        } catch (error) {
            console.error('Worker error:', error);
            return ErrorHandler.handleError(error, request);
        }
    },

    /**
     * 设置应用路由
     */
    async setupRoutes(router, services) {
        const { app, database, cache, auth, security, env } = services;

        // 静态资源路由
        router.get('/assets/*', async (request) => {
            return await this.handleStaticAssets(request, env);
        });

        // API 路由
        router.get('/api/health', async () => {
            return new Response(JSON.stringify({
                status: 'ok',
                version: env.APP_VERSION,
                timestamp: new Date().toISOString()
            }), {
                headers: { 'Content-Type': 'application/json' }
            });
        });

        // 认证路由
        router.post('/api/auth/login', async (request) => {
            return await auth.login(request);
        });

        router.post('/api/auth/logout', async (request) => {
            return await auth.logout(request);
        });

        router.get('/api/auth/user', async (request) => {
            return await auth.getUser(request);
        });

        // 书签管理路由
        router.get('/api/bookmarks', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.getBookmarks(request, user);
        });

        router.post('/api/bookmarks', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.createBookmark(request, user);
        });

        router.put('/api/bookmarks/:id', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.updateBookmark(request, user);
        });

        router.delete('/api/bookmarks/:id', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.deleteBookmark(request, user);
        });

        // 搜索路由
        router.get('/api/search', async (request) => {
            return await app.searchBookmarks(request);
        });

        // 分类管理路由
        router.get('/api/categories', async (request) => {
            return await app.getCategories(request);
        });

        router.post('/api/categories', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.createCategory(request, user);
        });

        // 导入导出路由
        router.post('/api/import', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.importBookmarks(request, user);
        });

        router.get('/api/export', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user) {
                return new Response('Unauthorized', { status: 401 });
            }
            return await app.exportBookmarks(request, user);
        });

        // 页面路由
        router.get('/', async (request) => {
            return await app.renderHomePage(request);
        });

        router.get('/login', async (request) => {
            return await app.renderLoginPage(request);
        });

        router.get('/admin', async (request) => {
            const user = await auth.requireAuth(request);
            if (!user || user.role !== 'admin') {
                return new Response('Forbidden', { status: 403 });
            }
            return await app.renderAdminPage(request, user);
        });

        // 捕获所有其他路由
        router.all('*', async (request) => {
            return await app.renderNotFoundPage(request);
        });
    },

    /**
     * 处理静态资源
     */
    async handleStaticAssets(request, env) {
        const url = new URL(request.url);
        const assetPath = url.pathname.replace('/assets/', '');

        // 从 R2 存储获取静态资源
        const object = await env.ONEBOOKNAV_STORAGE.get(`assets/${assetPath}`);

        if (!object) {
            return new Response('Not Found', { status: 404 });
        }

        const headers = new Headers();
        object.writeHttpMetadata(headers);
        headers.set('etag', object.httpEtag);
        headers.set('cache-control', 'public, max-age=86400'); // 缓存1天

        return new Response(object.body, { headers });
    },

    /**
     * 定时任务处理
     */
    async scheduled(controller, env, ctx) {
        const cron = controller.cron;

        try {
            switch (cron) {
                case '0 2 * * *': // 每天凌晨2点备份
                    await this.dailyBackup(env, ctx);
                    break;

                case '*/30 * * * *': // 每30分钟检查死链
                    await this.checkDeadLinks(env, ctx);
                    break;

                case '0 0 * * 0': // 每周日清理
                    await this.weeklyCleanup(env, ctx);
                    break;
            }
        } catch (error) {
            console.error('Scheduled task error:', error);
        }
    },

    /**
     * 每日备份任务
     */
    async dailyBackup(env, ctx) {
        console.log('Starting daily backup...');

        // 备份数据到 R2
        const database = new Database(env.ONEBOOKNAV_DB);
        const backupData = await database.exportData();

        const timestamp = new Date().toISOString().split('T')[0];
        const backupKey = `backups/daily_${timestamp}.json`;

        await env.ONEBOOKNAV_STORAGE.put(backupKey, JSON.stringify(backupData), {
            httpMetadata: {
                contentType: 'application/json'
            }
        });

        console.log('Daily backup completed');
    },

    /**
     * 检查死链任务
     */
    async checkDeadLinks(env, ctx) {
        console.log('Starting dead link check...');

        const database = new Database(env.ONEBOOKNAV_DB);
        const bookmarks = await database.getAllBookmarks();

        for (const bookmark of bookmarks) {
            ctx.waitUntil(this.checkBookmarkUrl(bookmark, database));
        }
    },

    /**
     * 检查单个书签URL
     */
    async checkBookmarkUrl(bookmark, database) {
        try {
            const response = await fetch(bookmark.url, {
                method: 'HEAD',
                timeout: 10000
            });

            const isAlive = response.status < 400;
            await database.updateBookmarkStatus(bookmark.id, isAlive);

        } catch (error) {
            await database.updateBookmarkStatus(bookmark.id, false);
        }
    },

    /**
     * 每周清理任务
     */
    async weeklyCleanup(env, ctx) {
        console.log('Starting weekly cleanup...');

        // 清理旧的备份文件
        const objects = await env.ONEBOOKNAV_STORAGE.list({ prefix: 'backups/' });
        const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - 30); // 保留30天

        for (const obj of objects.objects) {
            if (obj.uploaded < cutoffDate) {
                await env.ONEBOOKNAV_STORAGE.delete(obj.key);
            }
        }

        // 清理KV缓存
        const cache = new Cache(env.ONEBOOKNAV_CACHE);
        await cache.cleanup();

        console.log('Weekly cleanup completed');
    }
};