<?php

namespace App\Core;

/**
 * HTTP 请求类
 *
 * 封装HTTP请求信息和处理
 */
class Request
{
    private array $parameters = [];
    private array $query;
    private array $post;
    private array $files;
    private array $cookies;
    private array $server;
    private array $headers;
    private ?string $body = null;

    public function __construct()
    {
        $this->query = $_GET ?? [];
        $this->post = $_POST ?? [];
        $this->files = $_FILES ?? [];
        $this->cookies = $_COOKIE ?? [];
        $this->server = $_SERVER ?? [];
        $this->headers = $this->parseHeaders();
        $this->body = $this->getRequestBody();
    }

    /**
     * 解析HTTP头
     */
    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower($key))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    /**
     * 获取请求体
     */
    private function getRequestBody(): ?string
    {
        return file_get_contents('php://input') ?: null;
    }

    /**
     * 获取请求方法
     */
    public function getMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * 获取请求路径
     */
    public function getPath(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $path ? rtrim($path, '/') ?: '/' : '/';
    }

    /**
     * 获取完整URL
     */
    public function getUrl(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->getHost();
        $uri = $this->server['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $uri;
    }

    /**
     * 获取主机名
     */
    public function getHost(): string
    {
        return $this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    /**
     * 检查是否为HTTPS
     */
    public function isSecure(): bool
    {
        return (isset($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
               (isset($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] == 443) ||
               (isset($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * 检查是否为AJAX请求
     */
    public function isAjax(): bool
    {
        return strtolower($this->getHeader('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * 检查是否为JSON请求
     */
    public function isJson(): bool
    {
        return strpos($this->getHeader('Content-Type', ''), 'application/json') !== false;
    }

    /**
     * 获取客户端IP
     */
    public function getClientIp(): string
    {
        $ipHeaders = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($this->server[$header])) {
                $ips = explode(',', $this->server[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 获取用户代理
     */
    public function getUserAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * 获取HTTP头
     */
    public function getHeader(string $name, $default = null)
    {
        return $this->headers[$name] ?? $default;
    }

    /**
     * 获取所有HTTP头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 获取GET参数
     */
    public function query(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /**
     * 获取POST参数
     */
    public function post(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    /**
     * 获取请求参数（POST优先，然后GET）
     */
    public function input(string $key = null, $default = null)
    {
        if ($key === null) {
            return array_merge($this->query, $this->post);
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * 获取JSON数据
     */
    public function json(string $key = null, $default = null)
    {
        static $jsonData = null;

        if ($jsonData === null && $this->body) {
            $jsonData = json_decode($this->body, true) ?? [];
        }

        if ($key === null) {
            return $jsonData ?? [];
        }

        return $jsonData[$key] ?? $default;
    }

    /**
     * 获取上传文件
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * 获取所有上传文件
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * 检查是否有上传文件
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * 获取Cookie
     */
    public function cookie(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->cookies;
        }
        return $this->cookies[$key] ?? $default;
    }

    /**
     * 获取路由参数
     */
    public function parameter(string $key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * 设置路由参数
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * 获取所有路由参数
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * 获取请求体内容
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * 验证输入数据
     */
    public function validate(array $rules): array
    {
        $errors = [];
        $data = $this->input();

        foreach ($rules as $field => $rule) {
            $ruleList = is_string($rule) ? explode('|', $rule) : $rule;
            $value = $data[$field] ?? null;

            foreach ($ruleList as $singleRule) {
                $error = $this->validateField($field, $value, $singleRule);
                if ($error) {
                    $errors[$field][] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * 验证单个字段
     */
    private function validateField(string $field, $value, string $rule): ?string
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $ruleValue = $parts[1] ?? null;

        switch ($ruleName) {
            case 'required':
                if (empty($value)) {
                    return "字段 {$field} 是必需的";
                }
                break;

            case 'min':
                if (strlen($value) < (int)$ruleValue) {
                    return "字段 {$field} 最少需要 {$ruleValue} 个字符";
                }
                break;

            case 'max':
                if (strlen($value) > (int)$ruleValue) {
                    return "字段 {$field} 最多允许 {$ruleValue} 个字符";
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "字段 {$field} 必须是有效的邮箱地址";
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return "字段 {$field} 必须是有效的URL";
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    return "字段 {$field} 必须是数字";
                }
                break;

            case 'alpha':
                if (!ctype_alpha($value)) {
                    return "字段 {$field} 只能包含字母";
                }
                break;

            case 'alphanumeric':
                if (!ctype_alnum($value)) {
                    return "字段 {$field} 只能包含字母和数字";
                }
                break;
        }

        return null;
    }

    /**
     * 获取授权令牌
     */
    public function bearerToken(): ?string
    {
        $header = $this->getHeader('Authorization');
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * 检查内容类型
     */
    public function expectsJson(): bool
    {
        return $this->isAjax() ||
               strpos($this->getHeader('Accept', ''), 'application/json') !== false;
    }

    /**
     * 获取语言偏好
     */
    public function getPreferredLanguage(array $available = ['zh-CN', 'en']): string
    {
        $acceptLanguage = $this->getHeader('Accept-Language', '');

        if (!$acceptLanguage) {
            return $available[0] ?? 'zh-CN';
        }

        $languages = [];
        foreach (explode(',', $acceptLanguage) as $lang) {
            $parts = explode(';q=', trim($lang));
            $languages[trim($parts[0])] = isset($parts[1]) ? (float)$parts[1] : 1.0;
        }

        arsort($languages);

        foreach ($languages as $lang => $quality) {
            if (in_array($lang, $available)) {
                return $lang;
            }

            // 检查语言的简写形式
            $shortLang = explode('-', $lang)[0];
            foreach ($available as $availableLang) {
                if (strpos($availableLang, $shortLang) === 0) {
                    return $availableLang;
                }
            }
        }

        return $available[0] ?? 'zh-CN';
    }
}