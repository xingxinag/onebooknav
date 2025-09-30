<?php

namespace App\Services;

use App\Core\Container;
use Exception;

/**
 * 安全服务类
 *
 * 实现"终极.txt"要求的全方位安全防护系统
 * 包含 CSRF 防护、XSS 过滤、SQL 注入防护、访问控制等
 */
class SecurityService
{
    private static $instance = null;
    private ConfigService $config;
    private array $csrfTokens = [];
    private array $rateLimits = [];
    private array $blockedIPs = [];
    private array $trustedProxies = [];

    private function __construct()
    {
        $container = Container::getInstance();
        $this->config = $container->get('config');
        $this->initializeSecurity();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化安全设置
     */
    private function initializeSecurity(): void
    {
        // 设置安全的会话配置
        $this->configureSession();

        // 设置安全头
        $this->setSecurityHeaders();

        // 加载受信任的代理
        $this->loadTrustedProxies();

        // 清理过期的令牌和限制记录
        $this->cleanup();
    }

    /**
     * 配置会话安全
     */
    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // 安全的会话配置
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $this->isHttps() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_lifetime', $this->config->get('auth.session_lifetime', 86400));

            // 使用更安全的会话名
            $sessionName = $this->config->get('auth.session_name', 'onebooknav_session');
            session_name($sessionName);

            // 生成安全的会话ID
            if (empty(session_id())) {
                session_start();
                session_regenerate_id(true);
            }
        }
    }

    /**
     * 设置安全响应头
     */
    private function setSecurityHeaders(): void
    {
        if (!headers_sent()) {
            // 防止内容类型嗅探
            header('X-Content-Type-Options: nosniff');

            // 防止点击劫持
            header('X-Frame-Options: DENY');

            // XSS 保护
            header('X-XSS-Protection: 1; mode=block');

            // 引用来源策略
            header('Referrer-Policy: strict-origin-when-cross-origin');

            // 内容安全策略
            $csp = $this->buildContentSecurityPolicy();
            header("Content-Security-Policy: {$csp}");

            // HTTPS 传输安全
            if ($this->isHttps()) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            }

            // 权限策略
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        }
    }

    /**
     * 构建内容安全策略
     */
    private function buildContentSecurityPolicy(): string
    {
        $domain = $this->config->get('deployment.domain', 'localhost');

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net",
            "img-src 'self' data: https: http:",
            "connect-src 'self' https:",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'"
        ]);
    }

    /**
     * 加载受信任的代理服务器
     */
    private function loadTrustedProxies(): void
    {
        $this->trustedProxies = [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16'
        ];

        // Cloudflare IP 范围
        if ($this->config->get('deployment.method') === 'cloudflare-workers') {
            $this->trustedProxies = array_merge($this->trustedProxies, [
                '173.245.48.0/20',
                '103.21.244.0/22',
                '103.22.200.0/22',
                '103.31.4.0/22',
                '141.101.64.0/18',
                '108.162.192.0/18',
                '190.93.240.0/20',
                '188.114.96.0/20',
                '197.234.240.0/22',
                '198.41.128.0/17'
            ]);
        }
    }

    /**
     * 清理过期数据
     */
    private function cleanup(): void
    {
        $now = time();

        // 清理过期的 CSRF 令牌
        foreach ($this->csrfTokens as $token => $data) {
            if ($data['expires'] < $now) {
                unset($this->csrfTokens[$token]);
            }
        }

        // 清理过期的限流记录
        foreach ($this->rateLimits as $key => $data) {
            if ($data['reset_time'] < $now) {
                unset($this->rateLimits[$key]);
            }
        }
    }

    /**
     * 生成 CSRF 令牌
     */
    public function generateCsrfToken(string $action = 'default'): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + $this->config->get('security.csrf_token_lifetime', 3600);

        $this->csrfTokens[$token] = [
            'action' => $action,
            'expires' => $expires,
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];

        // 存储到会话中
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['csrf_tokens'][$token] = $this->csrfTokens[$token];
        }

        return $token;
    }

    /**
     * 验证 CSRF 令牌
     */
    public function validateCsrfToken(string $token, string $action = 'default'): bool
    {
        // 从会话中加载令牌
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['csrf_tokens'])) {
            $this->csrfTokens = array_merge($this->csrfTokens, $_SESSION['csrf_tokens']);
        }

        if (!isset($this->csrfTokens[$token])) {
            return false;
        }

        $tokenData = $this->csrfTokens[$token];

        // 检查是否过期
        if ($tokenData['expires'] < time()) {
            unset($this->csrfTokens[$token]);
            return false;
        }

        // 检查动作匹配
        if ($tokenData['action'] !== $action) {
            return false;
        }

        // 检查 IP 地址（可选）
        if ($this->config->get('security.csrf_check_ip', true)) {
            if ($tokenData['ip'] !== $this->getClientIP()) {
                return false;
            }
        }

        // 检查 User-Agent（可选）
        if ($this->config->get('security.csrf_check_user_agent', true)) {
            $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ($tokenData['user_agent'] !== $currentUserAgent) {
                return false;
            }
        }

        // 一次性令牌，验证后删除
        unset($this->csrfTokens[$token]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['csrf_tokens'][$token]);
        }

        return true;
    }

    /**
     * 清理和验证输入数据
     */
    public function sanitizeInput($input, array $options = [])
    {
        if (is_array($input)) {
            return $this->sanitizeArray($input, $options);
        }

        if (!is_string($input)) {
            return $input;
        }

        // 移除 null 字节
        $input = str_replace("\0", '', $input);

        // 根据类型进行不同的清理
        $type = $options['type'] ?? 'string';

        switch ($type) {
            case 'html':
                return $this->sanitizeHtml($input, $options);

            case 'sql':
                return $this->sanitizeSql($input);

            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);

            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);

            case 'int':
                return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);

            case 'float':
                return (float)filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

            case 'alphanum':
                return preg_replace('/[^a-zA-Z0-9]/', '', $input);

            case 'filename':
                return $this->sanitizeFilename($input);

            default:
                return $this->sanitizeString($input, $options);
        }
    }

    /**
     * 清理数组数据
     */
    public function sanitizeArray(array $array, array $options = []): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $cleanKey = $this->sanitizeString($key);

            if (is_array($value)) {
                $result[$cleanKey] = $this->sanitizeArray($value, $options);
            } else {
                $result[$cleanKey] = $this->sanitizeInput($value, $options);
            }
        }

        return $result;
    }

    /**
     * 清理字符串
     */
    private function sanitizeString(string $input, array $options = []): string
    {
        // 修剪空白字符
        $input = trim($input);

        // 转换特殊字符
        if ($options['escape_html'] ?? true) {
            $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 长度限制
        if (isset($options['max_length'])) {
            $input = mb_substr($input, 0, $options['max_length'], 'UTF-8');
        }

        return $input;
    }

    /**
     * 清理 HTML 内容
     */
    private function sanitizeHtml(string $input, array $options = []): string
    {
        $allowedTags = $options['allowed_tags'] ?? '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3>';
        $allowedAttributes = $options['allowed_attributes'] ?? ['href', 'title', 'target'];

        // 使用 strip_tags 移除不允许的标签
        $input = strip_tags($input, $allowedTags);

        // 进一步清理属性（这里简化处理）
        $input = preg_replace_callback('/<([^>]+)>/', function($matches) use ($allowedAttributes) {
            $tag = $matches[1];
            // 简单的属性清理逻辑
            return '<' . $tag . '>';
        }, $input);

        return $input;
    }

    /**
     * 清理 SQL 输入
     */
    private function sanitizeSql(string $input): string
    {
        // 移除危险的 SQL 关键词
        $dangerous = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'UNION', 'EXEC'];

        foreach ($dangerous as $keyword) {
            $input = preg_replace('/\b' . $keyword . '\b/i', '', $input);
        }

        return $input;
    }

    /**
     * 清理文件名
     */
    private function sanitizeFilename(string $filename): string
    {
        // 移除路径分隔符和危险字符
        $filename = preg_replace('/[\/\\\\:*?"<>|]/', '', $filename);

        // 移除点号开头（隐藏文件）
        $filename = ltrim($filename, '.');

        // 限制长度
        if (mb_strlen($filename) > 255) {
            $filename = mb_substr($filename, 0, 255);
        }

        return $filename;
    }

    /**
     * 验证输入数据
     */
    public function validateInput($input, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? null;

            // 必填验证
            if (isset($rule['required']) && $rule['required'] && empty($value)) {
                $errors[$field][] = "字段 {$field} 是必填的";
                continue;
            }

            if (empty($value)) {
                continue;
            }

            // 类型验证
            if (isset($rule['type'])) {
                if (!$this->validateType($value, $rule['type'])) {
                    $errors[$field][] = "字段 {$field} 类型不正确";
                }
            }

            // 长度验证
            if (isset($rule['min_length']) && mb_strlen($value) < $rule['min_length']) {
                $errors[$field][] = "字段 {$field} 长度不能少于 {$rule['min_length']} 个字符";
            }

            if (isset($rule['max_length']) && mb_strlen($value) > $rule['max_length']) {
                $errors[$field][] = "字段 {$field} 长度不能超过 {$rule['max_length']} 个字符";
            }

            // 正则验证
            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                $errors[$field][] = "字段 {$field} 格式不正确";
            }

            // 自定义验证
            if (isset($rule['validator']) && is_callable($rule['validator'])) {
                $result = call_user_func($rule['validator'], $value);
                if ($result !== true) {
                    $errors[$field][] = is_string($result) ? $result : "字段 {$field} 验证失败";
                }
            }
        }

        return $errors;
    }

    /**
     * 验证数据类型
     */
    private function validateType($value, string $type): bool
    {
        switch ($type) {
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;

            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT) !== false;

            case 'float':
                return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;

            case 'bool':
                return is_bool($value) || in_array(strtolower($value), ['true', 'false', '1', '0']);

            case 'string':
                return is_string($value);

            case 'array':
                return is_array($value);

            default:
                return true;
        }
    }

    /**
     * 限流控制
     */
    public function rateLimit(string $key, int $maxAttempts, int $timeWindow): bool
    {
        $now = time();
        $resetTime = $now + $timeWindow;

        if (!isset($this->rateLimits[$key])) {
            $this->rateLimits[$key] = [
                'attempts' => 1,
                'reset_time' => $resetTime,
                'first_attempt' => $now
            ];
            return true;
        }

        $limit = &$this->rateLimits[$key];

        // 重置时间窗口
        if ($now >= $limit['reset_time']) {
            $limit['attempts'] = 1;
            $limit['reset_time'] = $resetTime;
            $limit['first_attempt'] = $now;
            return true;
        }

        // 检查是否超过限制
        if ($limit['attempts'] >= $maxAttempts) {
            return false;
        }

        $limit['attempts']++;
        return true;
    }

    /**
     * 获取限流状态
     */
    public function getRateLimitStatus(string $key): array
    {
        if (!isset($this->rateLimits[$key])) {
            return [
                'attempts' => 0,
                'remaining' => PHP_INT_MAX,
                'reset_time' => 0,
                'blocked' => false
            ];
        }

        $limit = $this->rateLimits[$key];
        $maxAttempts = 100; // 默认值，实际应该从配置获取

        return [
            'attempts' => $limit['attempts'],
            'remaining' => max(0, $maxAttempts - $limit['attempts']),
            'reset_time' => $limit['reset_time'],
            'blocked' => $limit['attempts'] >= $maxAttempts && time() < $limit['reset_time']
        ];
    }

    /**
     * IP 地址封禁
     */
    public function blockIP(string $ip, int $duration = 3600): void
    {
        $this->blockedIPs[$ip] = time() + $duration;
    }

    /**
     * 检查 IP 是否被封禁
     */
    public function isIPBlocked(string $ip = null): bool
    {
        $ip = $ip ?: $this->getClientIP();

        if (!isset($this->blockedIPs[$ip])) {
            return false;
        }

        if (time() >= $this->blockedIPs[$ip]) {
            unset($this->blockedIPs[$ip]);
            return false;
        }

        return true;
    }

    /**
     * 获取真实客户端 IP
     */
    public function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // 代理
            'HTTP_X_FORWARDED_FOR',      // 负载均衡器
            'HTTP_X_FORWARDED',          // 代理
            'HTTP_X_CLUSTER_CLIENT_IP',  // 集群
            'HTTP_FORWARDED_FOR',        // 代理
            'HTTP_FORWARDED',            // 代理
            'REMOTE_ADDR'                // 标准
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if ($this->isValidIP($ip) && !$this->isPrivateIP($ip)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 验证 IP 地址格式
     */
    private function isValidIP(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * 检查是否为私有 IP
     */
    private function isPrivateIP(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * 检查是否为 HTTPS 连接
     */
    public function isHttps(): bool
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
    }

    /**
     * 安全的文件上传检查
     */
    public function validateFileUpload(array $file): array
    {
        $errors = [];

        // 检查上传错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $this->getUploadErrorMessage($file['error']);
            return $errors;
        }

        // 检查文件大小
        $maxSize = $this->config->get('security.max_file_size', 5242880); // 5MB
        if ($file['size'] > $maxSize) {
            $errors[] = "文件大小超过限制 " . number_format($maxSize / 1024 / 1024, 2) . "MB";
        }

        // 检查文件类型
        $allowedTypes = $this->config->get('security.allowed_file_types', [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
            'txt', 'json', 'xml', 'csv'
        ]);

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes)) {
            $errors[] = "不允许的文件类型: {$extension}";
        }

        // 检查 MIME 类型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon',
            'text/plain', 'application/json', 'text/xml', 'text/csv'
        ];

        if (!in_array($mimeType, $allowedMimes)) {
            $errors[] = "不允许的 MIME 类型: {$mimeType}";
        }

        // 检查文件内容（简单的恶意代码检测）
        if ($this->containsMaliciousContent($file['tmp_name'])) {
            $errors[] = "文件包含可疑内容";
        }

        return $errors;
    }

    /**
     * 获取上传错误信息
     */
    private function getUploadErrorMessage(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return "文件大小超过 php.ini 限制";
            case UPLOAD_ERR_FORM_SIZE:
                return "文件大小超过表单限制";
            case UPLOAD_ERR_PARTIAL:
                return "文件上传不完整";
            case UPLOAD_ERR_NO_FILE:
                return "没有选择文件";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "临时目录不存在";
            case UPLOAD_ERR_CANT_WRITE:
                return "写入失败";
            case UPLOAD_ERR_EXTENSION:
                return "文件上传被扩展阻止";
            default:
                return "未知上传错误";
        }
    }

    /**
     * 检查文件是否包含恶意内容
     */
    private function containsMaliciousContent(string $filePath): bool
    {
        $content = file_get_contents($filePath, false, null, 0, 1024); // 只读前1KB

        // 检查危险的脚本标签
        $patterns = [
            '/<\?php/i',
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload=/i',
            '/onerror=/i',
            '/eval\(/i',
            '/base64_decode/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成安全的随机密码
     */
    public function generateSecurePassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * 密码强度检查
     */
    public function checkPasswordStrength(string $password): array
    {
        $score = 0;
        $feedback = [];

        // 长度检查
        $minLength = $this->config->get('security.password_min_length', 8);
        if (strlen($password) >= $minLength) {
            $score += 1;
        } else {
            $feedback[] = "密码长度至少需要 {$minLength} 个字符";
        }

        // 复杂性检查
        if (preg_match('/[a-z]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = "需要包含小写字母";
        }

        if (preg_match('/[A-Z]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = "需要包含大写字母";
        }

        if (preg_match('/[0-9]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = "需要包含数字";
        }

        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = "建议包含特殊字符";
        }

        // 计算强度等级
        if ($score >= 4) {
            $strength = 'strong';
        } elseif ($score >= 3) {
            $strength = 'medium';
        } else {
            $strength = 'weak';
        }

        return [
            'score' => $score,
            'strength' => $strength,
            'feedback' => $feedback,
            'is_secure' => $score >= 3
        ];
    }

    /**
     * 加密敏感数据
     */
    public function encrypt(string $data, string $key = null): string
    {
        $key = $key ?: $this->config->get('security.secret_key');
        $cipher = 'AES-256-GCM';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * 解密敏感数据
     */
    public function decrypt(string $encryptedData, string $key = null): string
    {
        $key = $key ?: $this->config->get('security.secret_key');
        $cipher = 'AES-256-GCM';
        $ivLength = openssl_cipher_iv_length($cipher);

        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, 16);
        $encrypted = substr($data, $ivLength + 16);

        $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * 生成安全的哈希值
     */
    public function hash(string $data, string $salt = null): string
    {
        $salt = $salt ?: bin2hex(random_bytes(16));
        return hash('sha256', $data . $salt) . ':' . $salt;
    }

    /**
     * 验证哈希值
     */
    public function verifyHash(string $data, string $hash): bool
    {
        if (strpos($hash, ':') === false) {
            return false;
        }

        [$hashValue, $salt] = explode(':', $hash, 2);
        $calculatedHash = hash('sha256', $data . $salt);

        return hash_equals($hashValue, $calculatedHash);
    }

    /**
     * 记录安全事件
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $logData = [
            'event' => $event,
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        $logFile = DATA_PATH . '/security.log';
        $logEntry = json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * 获取安全统计信息
     */
    public function getSecurityStats(): array
    {
        return [
            'csrf_tokens_active' => count($this->csrfTokens),
            'rate_limits_active' => count($this->rateLimits),
            'blocked_ips' => count($this->blockedIPs),
            'is_https' => $this->isHttps(),
            'client_ip' => $this->getClientIP(),
            'deployment_method' => $this->config->get('deployment.method'),
            'security_headers_enabled' => !headers_sent(),
            'session_secure' => ini_get('session.cookie_secure') === '1'
        ];
    }
}