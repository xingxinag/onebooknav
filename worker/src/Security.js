/**
 * OneBookNav Cloudflare Workers 安全管理
 * 边缘计算安全防护系统
 */

export class Security {
    constructor(durableObject) {
        this.durableObject = durableObject;
        this.rateLimitWindowMs = 60000; // 1分钟
        this.rateLimitMax = 100; // 每分钟最大请求数
    }

    /**
     * 检查请求安全性
     */
    async checkRequest(request) {
        const url = new URL(request.url);
        const ip = this.getClientIP(request);
        const userAgent = request.headers.get('User-Agent') || '';

        // 1. 检查恶意IP
        if (await this.isBlockedIP(ip)) {
            return {
                allowed: false,
                status: 403,
                message: 'IP被阻止访问'
            };
        }

        // 2. 检查User-Agent
        if (this.isSuspiciousUserAgent(userAgent)) {
            return {
                allowed: false,
                status: 403,
                message: '不被允许的客户端'
            };
        }

        // 3. 检查请求频率限制
        const rateLimitResult = await this.checkRateLimit(ip, request);
        if (!rateLimitResult.allowed) {
            return {
                allowed: false,
                status: 429,
                message: '请求过于频繁',
                retryAfter: rateLimitResult.retryAfter
            };
        }

        // 4. 检查请求大小
        const contentLength = request.headers.get('Content-Length');
        if (contentLength && parseInt(contentLength) > 10 * 1024 * 1024) { // 10MB
            return {
                allowed: false,
                status: 413,
                message: '请求内容过大'
            };
        }

        // 5. 检查路径遍历攻击
        if (this.hasPathTraversal(url.pathname)) {
            return {
                allowed: false,
                status: 400,
                message: '无效的请求路径'
            };
        }

        // 6. 检查SQL注入攻击
        const queryString = url.search;
        if (this.hasSQLInjection(queryString)) {
            return {
                allowed: false,
                status: 400,
                message: '检测到潜在的SQL注入攻击'
            };
        }

        // 7. 检查XSS攻击
        if (this.hasXSSAttempt(queryString)) {
            return {
                allowed: false,
                status: 400,
                message: '检测到潜在的XSS攻击'
            };
        }

        return { allowed: true };
    }

    /**
     * 检查频率限制
     */
    async checkRateLimit(ip, request) {
        try {
            const key = `rate_limit:${ip}`;
            const durableObjectId = this.durableObject.idFromName(key);
            const durableObjectStub = this.durableObject.get(durableObjectId);

            const rateLimitRequest = new Request('https://dummy.com/rate-limit', {
                method: 'POST',
                body: JSON.stringify({
                    key: ip,
                    limit: this.rateLimitMax,
                    window: this.rateLimitWindowMs / 1000
                })
            });

            const response = await durableObjectStub.fetch(rateLimitRequest);
            const result = await response.json();

            return {
                allowed: result.allowed,
                remaining: result.remaining,
                retryAfter: result.retryAfter
            };

        } catch (error) {
            console.error('Rate limit check error:', error);
            // 如果检查失败，允许请求通过
            return { allowed: true };
        }
    }

    /**
     * 获取客户端IP
     */
    getClientIP(request) {
        return request.headers.get('CF-Connecting-IP') ||
               request.headers.get('X-Forwarded-For') ||
               request.headers.get('X-Real-IP') ||
               'unknown';
    }

    /**
     * 检查是否为被阻止的IP
     */
    async isBlockedIP(ip) {
        // 内置的恶意IP列表
        const blockedIPs = [
            '0.0.0.0',
            '127.0.0.1'
        ];

        // 检查IP格式
        if (!this.isValidIP(ip)) {
            return true;
        }

        // 检查私有网络IP（在生产环境中可能需要阻止）
        if (this.isPrivateIP(ip)) {
            return false; // 开发环境允许私有IP
        }

        return blockedIPs.includes(ip);
    }

    /**
     * 验证IP地址格式
     */
    isValidIP(ip) {
        const ipv4Regex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        const ipv6Regex = /^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;
        return ipv4Regex.test(ip) || ipv6Regex.test(ip);
    }

    /**
     * 检查是否为私有IP
     */
    isPrivateIP(ip) {
        const privateRanges = [
            /^10\./,
            /^172\.(1[6-9]|2[0-9]|3[01])\./,
            /^192\.168\./,
            /^127\./,
            /^169\.254\./
        ];

        return privateRanges.some(range => range.test(ip));
    }

    /**
     * 检查可疑的User-Agent
     */
    isSuspiciousUserAgent(userAgent) {
        const suspiciousPatterns = [
            /bot/i,
            /crawler/i,
            /spider/i,
            /scanner/i,
            /nikto/i,
            /sqlmap/i,
            /nmap/i,
            /masscan/i,
            /zmap/i,
            /curl/i,
            /wget/i,
            /python-requests/i,
            // 空或过短的User-Agent
            /^.{0,10}$/
        ];

        // 允许的爬虫
        const allowedBots = [
            /googlebot/i,
            /bingbot/i,
            /slurp/i, // Yahoo
            /duckduckbot/i,
            /baiduspider/i,
            /yandexbot/i
        ];

        // 检查是否为允许的爬虫
        if (allowedBots.some(pattern => pattern.test(userAgent))) {
            return false;
        }

        return suspiciousPatterns.some(pattern => pattern.test(userAgent));
    }

    /**
     * 检查路径遍历攻击
     */
    hasPathTraversal(path) {
        const traversalPatterns = [
            /\.\./,
            /\.\\\\/,
            /\.\/\.\./,
            /\.\.\\/,
            /%2e%2e/i,
            /%252e%252e/i,
            /\0/
        ];

        return traversalPatterns.some(pattern => pattern.test(path));
    }

    /**
     * 检查SQL注入攻击
     */
    hasSQLInjection(queryString) {
        const sqlPatterns = [
            /(\bunion\b|\bselect\b|\binsert\b|\bupdate\b|\bdelete\b|\bdrop\b|\bcreate\b|\balter\b)/i,
            /(\bor\b|\band\b)\s+\d+\s*=\s*\d+/i,
            /'\s*(or|and)\s+'/i,
            /;\s*(drop|delete|insert|update)/i,
            /\/\*.*\*\//,
            /--\s/,
            /#.*$/m,
            /\bexec\b/i,
            /\bsp_/i,
            /\bxp_/i
        ];

        const decodedQuery = decodeURIComponent(queryString);
        return sqlPatterns.some(pattern => pattern.test(decodedQuery));
    }

    /**
     * 检查XSS攻击
     */
    hasXSSAttempt(queryString) {
        const xssPatterns = [
            /<script[^>]*>.*?<\/script>/i,
            /javascript:/i,
            /vbscript:/i,
            /on\w+\s*=/i,
            /<iframe[^>]*>/i,
            /<object[^>]*>/i,
            /<embed[^>]*>/i,
            /<link[^>]*>/i,
            /<meta[^>]*>/i,
            /expression\s*\(/i,
            /url\s*\(/i,
            /@import/i,
            /alert\s*\(/i,
            /confirm\s*\(/i,
            /prompt\s*\(/i,
            /eval\s*\(/i
        ];

        const decodedQuery = decodeURIComponent(queryString);
        return xssPatterns.some(pattern => pattern.test(decodedQuery));
    }

    /**
     * 添加安全响应头
     */
    addSecurityHeaders(response) {
        const headers = new Headers(response.headers);

        // 基本安全头
        headers.set('X-Content-Type-Options', 'nosniff');
        headers.set('X-Frame-Options', 'DENY');
        headers.set('X-XSS-Protection', '1; mode=block');
        headers.set('Referrer-Policy', 'strict-origin-when-cross-origin');
        headers.set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // CSP头
        const csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'"
        ].join('; ');
        headers.set('Content-Security-Policy', csp);

        // HSTS头（仅HTTPS）
        if (response.url && response.url.startsWith('https://')) {
            headers.set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return new Response(response.body, {
            status: response.status,
            statusText: response.statusText,
            headers
        });
    }

    /**
     * 验证CSRF令牌
     */
    async validateCSRFToken(request, expectedToken) {
        const token = request.headers.get('X-CSRF-Token') ||
                     request.headers.get('X-Requested-With');

        if (!token || token !== expectedToken) {
            return false;
        }

        return true;
    }

    /**
     * 生成CSRF令牌
     */
    async generateCSRFToken() {
        const array = new Uint8Array(32);
        crypto.getRandomValues(array);
        return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    /**
     * 过滤危险字符
     */
    sanitizeInput(input) {
        if (typeof input !== 'string') {
            return input;
        }

        return input
            .replace(/[<>\"'&]/g, char => {
                const entities = {
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#x27;',
                    '&': '&amp;'
                };
                return entities[char];
            })
            .replace(/javascript:/gi, '')
            .replace(/vbscript:/gi, '')
            .replace(/on\w+=/gi, '');
    }

    /**
     * 验证文件上传安全性
     */
    validateFileUpload(file, allowedTypes = ['image/jpeg', 'image/png', 'image/gif']) {
        // 检查文件类型
        if (!allowedTypes.includes(file.type)) {
            return {
                valid: false,
                error: '不被允许的文件类型'
            };
        }

        // 检查文件大小（5MB限制）
        if (file.size > 5 * 1024 * 1024) {
            return {
                valid: false,
                error: '文件大小超过限制'
            };
        }

        // 检查文件名
        const dangerousPatterns = [
            /\.php$/i,
            /\.jsp$/i,
            /\.asp$/i,
            /\.js$/i,
            /\.html$/i,
            /\.exe$/i,
            /\.sh$/i,
            /\.bat$/i
        ];

        if (dangerousPatterns.some(pattern => pattern.test(file.name))) {
            return {
                valid: false,
                error: '不安全的文件名'
            };
        }

        return { valid: true };
    }

    /**
     * 生成安全的随机字符串
     */
    generateSecureRandom(length = 32) {
        const array = new Uint8Array(length);
        crypto.getRandomValues(array);
        return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    /**
     * 记录安全事件
     */
    async logSecurityEvent(event, details = {}) {
        const logEntry = {
            timestamp: new Date().toISOString(),
            event,
            details,
            severity: this.getEventSeverity(event)
        };

        console.warn('Security Event:', JSON.stringify(logEntry));

        // 在实际应用中，这里可以发送到日志收集系统
        // 或者存储到KV中进行进一步分析
    }

    /**
     * 获取事件严重程度
     */
    getEventSeverity(event) {
        const severityMap = {
            'blocked_ip': 'high',
            'rate_limit_exceeded': 'medium',
            'sql_injection_attempt': 'critical',
            'xss_attempt': 'high',
            'path_traversal_attempt': 'high',
            'suspicious_user_agent': 'low',
            'csrf_token_invalid': 'medium',
            'file_upload_rejected': 'medium'
        };

        return severityMap[event] || 'low';
    }
}