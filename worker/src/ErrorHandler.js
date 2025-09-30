/**
 * OneBookNav Cloudflare Workers 错误处理
 * 统一错误处理和日志记录
 */

export class ErrorHandler {
    /**
     * 处理错误并返回适当的响应
     */
    static handleError(error, request = null) {
        // 记录错误
        console.error('Error occurred:', {
            message: error.message,
            stack: error.stack,
            url: request ? request.url : 'unknown',
            method: request ? request.method : 'unknown',
            timestamp: new Date().toISOString()
        });

        // 根据错误类型返回不同的响应
        if (error instanceof ValidationError) {
            return this.createErrorResponse(400, 'VALIDATION_ERROR', error.message, error.details);
        }

        if (error instanceof AuthenticationError) {
            return this.createErrorResponse(401, 'AUTHENTICATION_ERROR', error.message);
        }

        if (error instanceof AuthorizationError) {
            return this.createErrorResponse(403, 'AUTHORIZATION_ERROR', error.message);
        }

        if (error instanceof NotFoundError) {
            return this.createErrorResponse(404, 'NOT_FOUND', error.message);
        }

        if (error instanceof RateLimitError) {
            return this.createErrorResponse(429, 'RATE_LIMIT_EXCEEDED', error.message, {
                retryAfter: error.retryAfter
            });
        }

        if (error instanceof DatabaseError) {
            return this.createErrorResponse(500, 'DATABASE_ERROR', '数据库操作失败');
        }

        if (error instanceof NetworkError) {
            return this.createErrorResponse(502, 'NETWORK_ERROR', '网络请求失败');
        }

        // 默认内部服务器错误
        return this.createErrorResponse(500, 'INTERNAL_ERROR', '内部服务器错误');
    }

    /**
     * 创建错误响应
     */
    static createErrorResponse(status, code, message, details = null) {
        const errorResponse = {
            success: false,
            error: {
                code,
                message,
                timestamp: new Date().toISOString()
            }
        };

        if (details) {
            errorResponse.error.details = details;
        }

        return new Response(JSON.stringify(errorResponse), {
            status,
            headers: {
                'Content-Type': 'application/json',
                'X-Error-Code': code
            }
        });
    }

    /**
     * 处理异步错误
     */
    static async handleAsync(fn, request = null) {
        try {
            return await fn();
        } catch (error) {
            return this.handleError(error, request);
        }
    }

    /**
     * 包装异步函数以自动处理错误
     */
    static wrapAsync(fn) {
        return async (request) => {
            try {
                return await fn(request);
            } catch (error) {
                return this.handleError(error, request);
            }
        };
    }

    /**
     * 验证必需参数
     */
    static validateRequired(data, requiredFields) {
        const missing = [];

        for (const field of requiredFields) {
            if (data[field] === undefined || data[field] === null || data[field] === '') {
                missing.push(field);
            }
        }

        if (missing.length > 0) {
            throw new ValidationError(`缺少必需字段: ${missing.join(', ')}`, { missing });
        }
    }

    /**
     * 验证数据类型
     */
    static validateTypes(data, schema) {
        const errors = [];

        for (const [field, expectedType] of Object.entries(schema)) {
            if (data[field] !== undefined) {
                const actualType = typeof data[field];
                if (actualType !== expectedType) {
                    errors.push(`字段 ${field} 期望类型为 ${expectedType}，实际为 ${actualType}`);
                }
            }
        }

        if (errors.length > 0) {
            throw new ValidationError('数据类型验证失败', { errors });
        }
    }

    /**
     * 验证字符串长度
     */
    static validateStringLength(value, fieldName, minLength = 0, maxLength = Infinity) {
        if (typeof value !== 'string') {
            throw new ValidationError(`${fieldName} 必须是字符串`);
        }

        if (value.length < minLength) {
            throw new ValidationError(`${fieldName} 长度不能少于 ${minLength} 个字符`);
        }

        if (value.length > maxLength) {
            throw new ValidationError(`${fieldName} 长度不能超过 ${maxLength} 个字符`);
        }
    }

    /**
     * 验证URL格式
     */
    static validateURL(url, fieldName = 'URL') {
        try {
            new URL(url);
        } catch (error) {
            throw new ValidationError(`${fieldName} 格式无效`);
        }
    }

    /**
     * 验证邮箱格式
     */
    static validateEmail(email, fieldName = '邮箱') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            throw new ValidationError(`${fieldName} 格式无效`);
        }
    }

    /**
     * 验证数字范围
     */
    static validateNumberRange(value, fieldName, min = -Infinity, max = Infinity) {
        if (typeof value !== 'number' || isNaN(value)) {
            throw new ValidationError(`${fieldName} 必须是有效数字`);
        }

        if (value < min) {
            throw new ValidationError(`${fieldName} 不能小于 ${min}`);
        }

        if (value > max) {
            throw new ValidationError(`${fieldName} 不能大于 ${max}`);
        }
    }

    /**
     * 安全地解析JSON
     */
    static async safeParseJSON(request, maxSize = 1024 * 1024) {
        try {
            const contentLength = request.headers.get('Content-Length');
            if (contentLength && parseInt(contentLength) > maxSize) {
                throw new ValidationError('请求体过大');
            }

            const text = await request.text();
            if (text.length > maxSize) {
                throw new ValidationError('请求体过大');
            }

            return JSON.parse(text);
        } catch (error) {
            if (error instanceof ValidationError) {
                throw error;
            }
            throw new ValidationError('无效的JSON格式');
        }
    }

    /**
     * 创建成功响应
     */
    static createSuccessResponse(data = null, message = '操作成功', status = 200) {
        const response = {
            success: true,
            message,
            timestamp: new Date().toISOString()
        };

        if (data !== null) {
            response.data = data;
        }

        return new Response(JSON.stringify(response), {
            status,
            headers: { 'Content-Type': 'application/json' }
        });
    }

    /**
     * 记录操作日志
     */
    static logOperation(operation, details = {}) {
        console.log('Operation:', {
            operation,
            details,
            timestamp: new Date().toISOString()
        });
    }

    /**
     * 记录性能指标
     */
    static logPerformance(operation, startTime, details = {}) {
        const duration = Date.now() - startTime;
        console.log('Performance:', {
            operation,
            duration: `${duration}ms`,
            details,
            timestamp: new Date().toISOString()
        });
    }
}

// 自定义错误类

export class ValidationError extends Error {
    constructor(message, details = null) {
        super(message);
        this.name = 'ValidationError';
        this.details = details;
    }
}

export class AuthenticationError extends Error {
    constructor(message = '身份验证失败') {
        super(message);
        this.name = 'AuthenticationError';
    }
}

export class AuthorizationError extends Error {
    constructor(message = '权限不足') {
        super(message);
        this.name = 'AuthorizationError';
    }
}

export class NotFoundError extends Error {
    constructor(message = '资源不存在') {
        super(message);
        this.name = 'NotFoundError';
    }
}

export class RateLimitError extends Error {
    constructor(message = '请求过于频繁', retryAfter = 60) {
        super(message);
        this.name = 'RateLimitError';
        this.retryAfter = retryAfter;
    }
}

export class DatabaseError extends Error {
    constructor(message = '数据库操作失败', originalError = null) {
        super(message);
        this.name = 'DatabaseError';
        this.originalError = originalError;
    }
}

export class NetworkError extends Error {
    constructor(message = '网络请求失败', statusCode = null) {
        super(message);
        this.name = 'NetworkError';
        this.statusCode = statusCode;
    }
}

export class ConfigurationError extends Error {
    constructor(message = '配置错误') {
        super(message);
        this.name = 'ConfigurationError';
    }
}

// 错误处理中间件工厂
export function createErrorMiddleware() {
    return ErrorHandler.wrapAsync;
}

// 验证中间件工厂
export function createValidationMiddleware(schema) {
    return async (request) => {
        try {
            const data = await ErrorHandler.safeParseJSON(request);

            if (schema.required) {
                ErrorHandler.validateRequired(data, schema.required);
            }

            if (schema.types) {
                ErrorHandler.validateTypes(data, schema.types);
            }

            // 将验证后的数据添加到请求对象
            request.validatedData = data;
            return null; // 继续处理

        } catch (error) {
            return ErrorHandler.handleError(error, request);
        }
    };
}