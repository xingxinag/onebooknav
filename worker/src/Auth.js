/**
 * OneBookNav Cloudflare Workers 身份验证
 * 基于JWT和会话的认证系统
 */

export class Auth {
    constructor(sessionsKV, durableObject) {
        this.sessionsKV = sessionsKV;
        this.durableObject = durableObject;
        this.jwtSecret = 'your-jwt-secret'; // 应该从环境变量获取
    }

    /**
     * 用户登录
     */
    async login(request) {
        try {
            const { username, password, email, remember } = await request.json();

            // 验证输入
            if ((!username && !email) || !password) {
                return this.errorResponse('用户名/邮箱和密码是必填的', 400);
            }

            // 查找用户（这里需要集成数据库查询）
            const user = await this.findUser(username || email);
            if (!user) {
                return this.errorResponse('用户不存在', 401);
            }

            // 验证密码
            const isValidPassword = await this.verifyPassword(password, user.password_hash);
            if (!isValidPassword) {
                return this.errorResponse('密码错误', 401);
            }

            // 生成会话
            const sessionData = await this.createSession(user, remember);

            return new Response(JSON.stringify({
                success: true,
                data: {
                    user: {
                        id: user.id,
                        username: user.username,
                        email: user.email,
                        role: user.role
                    },
                    token: sessionData.token,
                    expires_at: sessionData.expires_at
                }
            }), {
                status: 200,
                headers: {
                    'Content-Type': 'application/json',
                    'Set-Cookie': this.createSessionCookie(sessionData.sessionId, sessionData.expires_at)
                }
            });

        } catch (error) {
            console.error('Login error:', error);
            return this.errorResponse('登录失败', 500);
        }
    }

    /**
     * 用户登出
     */
    async logout(request) {
        try {
            const sessionId = this.getSessionIdFromRequest(request);
            if (sessionId) {
                // 删除会话
                await this.deleteSession(sessionId);
            }

            return new Response(JSON.stringify({
                success: true,
                message: '登出成功'
            }), {
                status: 200,
                headers: {
                    'Content-Type': 'application/json',
                    'Set-Cookie': 'session_id=; HttpOnly; Secure; SameSite=Strict; Max-Age=0; Path=/'
                }
            });

        } catch (error) {
            console.error('Logout error:', error);
            return this.errorResponse('登出失败', 500);
        }
    }

    /**
     * 获取当前用户信息
     */
    async getUser(request) {
        try {
            const user = await this.getUserFromRequest(request);
            if (!user) {
                return null;
            }

            return {
                id: user.id,
                username: user.username,
                email: user.email,
                role: user.role,
                last_login_at: user.last_login_at
            };

        } catch (error) {
            console.error('Get user error:', error);
            return null;
        }
    }

    /**
     * 要求身份验证
     */
    async requireAuth(request) {
        const user = await this.getUserFromRequest(request);
        if (!user) {
            throw new Error('Authentication required');
        }
        return user;
    }

    /**
     * 验证管理员权限
     */
    async requireAdmin(request) {
        const user = await this.requireAuth(request);
        if (user.role !== 'admin' && user.role !== 'superadmin') {
            throw new Error('Admin access required');
        }
        return user;
    }

    /**
     * 从请求中获取用户信息
     */
    async getUserFromRequest(request) {
        // 尝试从Authorization头获取token
        const authHeader = request.headers.get('Authorization');
        if (authHeader && authHeader.startsWith('Bearer ')) {
            const token = authHeader.substring(7);
            return await this.getUserFromToken(token);
        }

        // 尝试从Cookie获取会话ID
        const sessionId = this.getSessionIdFromRequest(request);
        if (sessionId) {
            return await this.getUserFromSession(sessionId);
        }

        return null;
    }

    /**
     * 从Token获取用户
     */
    async getUserFromToken(token) {
        try {
            const payload = await this.verifyJWT(token);
            if (!payload || !payload.userId) {
                return null;
            }

            // 从数据库获取最新用户信息
            return await this.findUserById(payload.userId);

        } catch (error) {
            console.error('Get user from token error:', error);
            return null;
        }
    }

    /**
     * 从会话获取用户
     */
    async getUserFromSession(sessionId) {
        try {
            // 从KV存储获取会话
            const sessionData = await this.sessionsKV.get(sessionId);
            if (!sessionData) {
                return null;
            }

            const session = JSON.parse(sessionData);

            // 检查会话是否过期
            if (new Date(session.expires_at) < new Date()) {
                await this.deleteSession(sessionId);
                return null;
            }

            // 返回用户信息
            return session.user;

        } catch (error) {
            console.error('Get user from session error:', error);
            return null;
        }
    }

    /**
     * 创建会话
     */
    async createSession(user, remember = false) {
        const sessionId = this.generateSessionId();
        const expiresAt = new Date();

        if (remember) {
            expiresAt.setDate(expiresAt.getDate() + 30); // 30天
        } else {
            expiresAt.setHours(expiresAt.getHours() + 24); // 24小时
        }

        const sessionData = {
            sessionId,
            user: {
                id: user.id,
                username: user.username,
                email: user.email,
                role: user.role
            },
            expires_at: expiresAt.toISOString(),
            created_at: new Date().toISOString(),
            last_active: new Date().toISOString()
        };

        // 存储到KV
        await this.sessionsKV.put(sessionId, JSON.stringify(sessionData), {
            expirationTtl: Math.floor((expiresAt.getTime() - Date.now()) / 1000)
        });

        // 生成JWT Token
        const token = await this.generateJWT({
            userId: user.id,
            sessionId: sessionId,
            exp: Math.floor(expiresAt.getTime() / 1000)
        });

        return {
            sessionId,
            token,
            expires_at: expiresAt.toISOString()
        };
    }

    /**
     * 删除会话
     */
    async deleteSession(sessionId) {
        await this.sessionsKV.delete(sessionId);
    }

    /**
     * 生成会话ID
     */
    generateSessionId() {
        const array = new Uint8Array(32);
        crypto.getRandomValues(array);
        return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    /**
     * 从请求中获取会话ID
     */
    getSessionIdFromRequest(request) {
        const cookieHeader = request.headers.get('Cookie');
        if (!cookieHeader) return null;

        const cookies = this.parseCookies(cookieHeader);
        return cookies.session_id || null;
    }

    /**
     * 解析Cookie
     */
    parseCookies(cookieHeader) {
        const cookies = {};
        cookieHeader.split(';').forEach(cookie => {
            const [name, ...rest] = cookie.trim().split('=');
            if (name && rest.length > 0) {
                cookies[name] = rest.join('=');
            }
        });
        return cookies;
    }

    /**
     * 创建会话Cookie
     */
    createSessionCookie(sessionId, expiresAt) {
        const expires = new Date(expiresAt).toUTCString();
        return `session_id=${sessionId}; HttpOnly; Secure; SameSite=Strict; Expires=${expires}; Path=/`;
    }

    /**
     * 生成JWT Token
     */
    async generateJWT(payload) {
        const header = {
            alg: 'HS256',
            typ: 'JWT'
        };

        const encodedHeader = this.base64UrlEncode(JSON.stringify(header));
        const encodedPayload = this.base64UrlEncode(JSON.stringify(payload));

        const signature = await this.generateHMAC(`${encodedHeader}.${encodedPayload}`, this.jwtSecret);
        const encodedSignature = this.base64UrlEncode(signature);

        return `${encodedHeader}.${encodedPayload}.${encodedSignature}`;
    }

    /**
     * 验证JWT Token
     */
    async verifyJWT(token) {
        try {
            const [encodedHeader, encodedPayload, encodedSignature] = token.split('.');

            // 验证签名
            const expectedSignature = await this.generateHMAC(
                `${encodedHeader}.${encodedPayload}`,
                this.jwtSecret
            );
            const expectedEncodedSignature = this.base64UrlEncode(expectedSignature);

            if (encodedSignature !== expectedEncodedSignature) {
                throw new Error('Invalid signature');
            }

            // 解码payload
            const payload = JSON.parse(this.base64UrlDecode(encodedPayload));

            // 检查过期时间
            if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
                throw new Error('Token expired');
            }

            return payload;

        } catch (error) {
            console.error('JWT verification error:', error);
            return null;
        }
    }

    /**
     * 生成HMAC签名
     */
    async generateHMAC(data, secret) {
        const encoder = new TextEncoder();
        const key = await crypto.subtle.importKey(
            'raw',
            encoder.encode(secret),
            { name: 'HMAC', hash: 'SHA-256' },
            false,
            ['sign']
        );

        const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(data));
        return new Uint8Array(signature);
    }

    /**
     * Base64 URL编码
     */
    base64UrlEncode(data) {
        let encoded;
        if (typeof data === 'string') {
            encoded = btoa(data);
        } else {
            // Uint8Array
            encoded = btoa(String.fromCharCode(...data));
        }
        return encoded.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    /**
     * Base64 URL解码
     */
    base64UrlDecode(encoded) {
        let base64 = encoded.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) {
            base64 += '=';
        }
        return atob(base64);
    }

    /**
     * 验证密码
     */
    async verifyPassword(password, hash) {
        // 这里应该使用适当的密码哈希验证
        // 简化实现，实际应该使用bcrypt或类似的库
        const encoder = new TextEncoder();
        const data = encoder.encode(password);
        const hashBuffer = await crypto.subtle.digest('SHA-256', data);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');

        return hashHex === hash;
    }

    /**
     * 生成密码哈希
     */
    async hashPassword(password) {
        const encoder = new TextEncoder();
        const data = encoder.encode(password);
        const hashBuffer = await crypto.subtle.digest('SHA-256', data);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /**
     * 查找用户（需要数据库集成）
     */
    async findUser(usernameOrEmail) {
        // TODO: 集成数据库查询
        // 这里应该调用Database类的方法
        return null;
    }

    /**
     * 根据ID查找用户
     */
    async findUserById(id) {
        // TODO: 集成数据库查询
        return null;
    }

    /**
     * 错误响应
     */
    errorResponse(message, status = 400) {
        return new Response(JSON.stringify({
            success: false,
            error: message
        }), {
            status,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}