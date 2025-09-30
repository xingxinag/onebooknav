/**
 * OneBookNav Cloudflare Workers 路由器
 * 边缘计算路由管理
 */

export class Router {
    constructor() {
        this.routes = {
            GET: new Map(),
            POST: new Map(),
            PUT: new Map(),
            DELETE: new Map(),
            PATCH: new Map(),
            OPTIONS: new Map()
        };
        this.middlewares = [];
    }

    /**
     * 添加GET路由
     */
    get(path, handler) {
        this.addRoute('GET', path, handler);
        return this;
    }

    /**
     * 添加POST路由
     */
    post(path, handler) {
        this.addRoute('POST', path, handler);
        return this;
    }

    /**
     * 添加PUT路由
     */
    put(path, handler) {
        this.addRoute('PUT', path, handler);
        return this;
    }

    /**
     * 添加DELETE路由
     */
    delete(path, handler) {
        this.addRoute('DELETE', path, handler);
        return this;
    }

    /**
     * 添加PATCH路由
     */
    patch(path, handler) {
        this.addRoute('PATCH', path, handler);
        return this;
    }

    /**
     * 添加所有HTTP方法路由
     */
    all(path, handler) {
        ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'].forEach(method => {
            this.addRoute(method, path, handler);
        });
        return this;
    }

    /**
     * 添加中间件
     */
    use(middleware) {
        this.middlewares.push(middleware);
        return this;
    }

    /**
     * 处理请求
     */
    async handle(request) {
        const method = request.method;
        const url = new URL(request.url);
        const pathname = url.pathname;

        // 执行中间件
        for (const middleware of this.middlewares) {
            const result = await middleware(request);
            if (result instanceof Response) {
                return result;
            }
        }

        // 查找匹配的路由
        const routeMap = this.routes[method];
        if (!routeMap) {
            return this.notFound();
        }

        for (const [pattern, handler] of routeMap) {
            const match = this.matchRoute(pattern, pathname);
            if (match) {
                try {
                    // 将路由参数添加到请求对象
                    request.params = match.params;
                    return await handler(request);
                } catch (error) {
                    console.error('Route handler error:', error);
                    return this.serverError(error);
                }
            }
        }

        return this.notFound();
    }

    /**
     * 添加路由
     */
    addRoute(method, path, handler) {
        if (!this.routes[method]) {
            this.routes[method] = new Map();
        }
        this.routes[method].set(path, handler);
    }

    /**
     * 匹配路由
     */
    matchRoute(pattern, pathname) {
        // 处理通配符路由
        if (pattern.includes('*')) {
            const regexPattern = pattern.replace(/\*/g, '.*');
            const regex = new RegExp(`^${regexPattern}$`);
            if (regex.test(pathname)) {
                return { params: {} };
            }
            return null;
        }

        // 处理参数路由（如 /api/bookmarks/:id）
        if (pattern.includes(':')) {
            const patternParts = pattern.split('/');
            const pathnameParts = pathname.split('/');

            if (patternParts.length !== pathnameParts.length) {
                return null;
            }

            const params = {};
            let matches = true;

            for (let i = 0; i < patternParts.length; i++) {
                const patternPart = patternParts[i];
                const pathnamePart = pathnameParts[i];

                if (patternPart.startsWith(':')) {
                    // 提取参数
                    const paramName = patternPart.slice(1);
                    params[paramName] = pathnamePart;
                } else if (patternPart !== pathnamePart) {
                    matches = false;
                    break;
                }
            }

            return matches ? { params } : null;
        }

        // 精确匹配
        if (pattern === pathname) {
            return { params: {} };
        }

        return null;
    }

    /**
     * 404响应
     */
    notFound() {
        return new Response(JSON.stringify({
            success: false,
            error: 'Route not found'
        }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' }
        });
    }

    /**
     * 500响应
     */
    serverError(error) {
        return new Response(JSON.stringify({
            success: false,
            error: 'Internal server error',
            message: error.message
        }), {
            status: 500,
            headers: { 'Content-Type': 'application/json' }
        });
    }

    /**
     * CORS预检响应
     */
    corsResponse(request) {
        const headers = {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Max-Age': '86400'
        };

        if (request.method === 'OPTIONS') {
            return new Response(null, { status: 204, headers });
        }

        return null;
    }
}

/**
 * 创建CORS中间件
 */
export function createCorsMiddleware(options = {}) {
    const {
        origin = '*',
        methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        headers = ['Content-Type', 'Authorization', 'X-Requested-With'],
        maxAge = 86400
    } = options;

    return async (request) => {
        const corsHeaders = {
            'Access-Control-Allow-Origin': origin,
            'Access-Control-Allow-Methods': methods.join(', '),
            'Access-Control-Allow-Headers': headers.join(', '),
            'Access-Control-Max-Age': maxAge.toString()
        };

        if (request.method === 'OPTIONS') {
            return new Response(null, { status: 204, headers: corsHeaders });
        }

        // 为其他响应添加CORS头
        return null;
    };
}

/**
 * 创建日志中间件
 */
export function createLogMiddleware() {
    return async (request) => {
        const start = Date.now();
        const method = request.method;
        const url = new URL(request.url);
        const path = url.pathname;

        console.log(`[${new Date().toISOString()}] ${method} ${path} - Start`);

        // 在响应后记录
        return null;
    };
}

/**
 * 创建限流中间件
 */
export function createRateLimitMiddleware(env, options = {}) {
    const {
        windowMs = 60000, // 1分钟
        max = 100, // 最大请求数
        keyGenerator = (request) => {
            const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
            return `rate_limit:${ip}`;
        }
    } = options;

    return async (request) => {
        const key = keyGenerator(request);
        const now = Date.now();
        const windowStart = Math.floor(now / windowMs) * windowMs;
        const rateLimitKey = `${key}:${windowStart}`;

        try {
            // 获取当前窗口的请求数
            const currentCount = await env.ONEBOOKNAV_CACHE.get(rateLimitKey);
            const count = currentCount ? parseInt(currentCount) : 0;

            if (count >= max) {
                return new Response(JSON.stringify({
                    success: false,
                    error: 'Too many requests',
                    retryAfter: Math.ceil((windowStart + windowMs - now) / 1000)
                }), {
                    status: 429,
                    headers: {
                        'Content-Type': 'application/json',
                        'Retry-After': Math.ceil((windowStart + windowMs - now) / 1000).toString(),
                        'X-RateLimit-Limit': max.toString(),
                        'X-RateLimit-Remaining': Math.max(0, max - count - 1).toString(),
                        'X-RateLimit-Reset': Math.ceil((windowStart + windowMs) / 1000).toString()
                    }
                });
            }

            // 增加计数
            await env.ONEBOOKNAV_CACHE.put(rateLimitKey, (count + 1).toString(), {
                expirationTtl: Math.ceil(windowMs / 1000)
            });

        } catch (error) {
            console.error('Rate limit middleware error:', error);
            // 限流失败时允许请求通过
        }

        return null;
    };
}

/**
 * 创建身份验证中间件
 */
export function createAuthMiddleware(auth, options = {}) {
    const {
        excludePaths = ['/api/auth/login', '/api/health', '/'],
        requireAuth = true
    } = options;

    return async (request) => {
        const url = new URL(request.url);
        const path = url.pathname;

        // 检查是否需要跳过认证
        if (excludePaths.some(excludePath => {
            if (excludePath.endsWith('*')) {
                return path.startsWith(excludePath.slice(0, -1));
            }
            return path === excludePath;
        })) {
            return null;
        }

        // 验证身份
        try {
            const user = await auth.getUser(request);
            if (!user && requireAuth) {
                return new Response(JSON.stringify({
                    success: false,
                    error: 'Authentication required'
                }), {
                    status: 401,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            // 将用户信息添加到请求
            request.user = user;
        } catch (error) {
            console.error('Auth middleware error:', error);
            if (requireAuth) {
                return new Response(JSON.stringify({
                    success: false,
                    error: 'Authentication failed'
                }), {
                    status: 401,
                    headers: { 'Content-Type': 'application/json' }
                });
            }
        }

        return null;
    };
}