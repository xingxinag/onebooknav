<?php
/**
 * OneBookNav - 安全管理器
 *
 * 处理各种安全检查和防护措施
 */

class SecurityManager
{
    private $config;
    private $rateLimiter;
    private $csrfTokens = [];

    public function __construct($config)
    {
        $this->config = $config;
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * 执行安全检查
     */
    public function performSecurityChecks()
    {
        // IP 过滤检查
        $this->checkIpFilter();

        // 速率限制检查
        $this->checkRateLimit();

        // 恶意请求检查
        $this->checkMaliciousRequest();

        // 设置安全头
        $this->setSecurityHeaders();
    }

    /**
     * IP 过滤检查
     */
    private function checkIpFilter()
    {
        if (!$this->config['ip_filtering']['enabled']) {
            return;
        }

        $clientIp = $this->getClientIp();
        $whitelist = $this->config['ip_filtering']['whitelist'];
        $blacklist = $this->config['ip_filtering']['blacklist'];

        // 检查黑名单
        if (!empty($blacklist) && $this->isIpInList($clientIp, $blacklist)) {
            $this->blockRequest('IP blocked by blacklist');
        }

        // 检查白名单
        if (!empty($whitelist) && !$this->isIpInList($clientIp, $whitelist)) {
            $this->blockRequest('IP not in whitelist');
        }
    }

    /**
     * 速率限制检查
     */
    private function checkRateLimit()
    {
        if (!$this->config['rate_limiting']['enabled']) {
            return;
        }

        $clientIp = $this->getClientIp();
        $endpoint = $_SERVER['REQUEST_URI'];

        // API 请求限制
        if (strpos($endpoint, '/api/') === 0) {
            $this->rateLimiter->check(
                "api:{$clientIp}",
                $this->config['rate_limiting']['api_requests']['max_attempts'],
                $this->config['rate_limiting']['api_requests']['decay_minutes'] * 60
            );
        }

        // 搜索请求限制
        if (strpos($endpoint, '/search') !== false) {
            $this->rateLimiter->check(
                "search:{$clientIp}",
                $this->config['rate_limiting']['search_requests']['max_attempts'],
                $this->config['rate_limiting']['search_requests']['decay_minutes'] * 60
            );
        }

        // 上传请求限制
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES) && !empty($_FILES)) {
            $this->rateLimiter->check(
                "upload:{$clientIp}",
                $this->config['rate_limiting']['upload_requests']['max_attempts'],
                $this->config['rate_limiting']['upload_requests']['decay_minutes'] * 60
            );
        }
    }

    /**
     * 恶意请求检查
     */
    private function checkMaliciousRequest()
    {
        // 检查 SQL 注入
        if ($this->config['sql_injection']['enabled']) {
            $this->checkSqlInjection();
        }

        // 检查 XSS 攻击
        if ($this->config['xss']['enabled']) {
            $this->checkXssAttack();
        }

        // 检查文件上传
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES) && !empty($_FILES)) {
            $this->checkFileUpload();
        }
    }

    /**
     * SQL 注入检查
     */
    private function checkSqlInjection()
    {
        $patterns = $this->config['sql_injection']['forbidden_keywords'];
        $allInput = array_merge($_GET, $_POST, $_COOKIE);

        foreach ($allInput as $key => $value) {
            if (is_string($value)) {
                foreach ($patterns as $pattern) {
                    if (stripos($value, $pattern) !== false) {
                        $this->logSecurityEvent('SQL_INJECTION_ATTEMPT', [
                            'pattern' => $pattern,
                            'input' => $key,
                            'value' => substr($value, 0, 100)
                        ]);
                        $this->blockRequest('Malicious input detected');
                    }
                }
            }
        }
    }

    /**
     * XSS 攻击检查
     */
    private function checkXssAttack()
    {
        $patterns = $this->config['xss']['forbidden_patterns'];
        $allInput = array_merge($_GET, $_POST);

        foreach ($allInput as $key => $value) {
            if (is_string($value)) {
                foreach ($patterns as $pattern) {
                    if (stripos($value, $pattern) !== false) {
                        $this->logSecurityEvent('XSS_ATTEMPT', [
                            'pattern' => $pattern,
                            'input' => $key,
                            'value' => substr($value, 0, 100)
                        ]);
                        $this->blockRequest('Malicious script detected');
                    }
                }
            }
        }
    }

    /**
     * 文件上传检查
     */
    private function checkFileUpload()
    {
        foreach ($_FILES as $fileInput) {
            if ($fileInput['error'] === UPLOAD_ERR_OK) {
                $this->validateUploadedFile($fileInput);
            }
        }
    }

    /**
     * 验证上传文件
     */
    private function validateUploadedFile($file)
    {
        $config = $this->config['upload'];

        // 检查文件大小
        if ($file['size'] > $config['max_file_size']) {
            throw new SecurityException('File size exceeds maximum allowed size');
        }

        // 检查文件类型
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $config['allowed_extensions'])) {
            throw new SecurityException('File type not allowed');
        }

        // 检查 MIME 类型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $config['allowed_mime_types'])) {
            throw new SecurityException('Invalid file MIME type');
        }

        // 验证图片内容
        if ($config['validate_image_content'] && in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
            $imageSize = getimagesize($file['tmp_name']);
            if (!$imageSize) {
                throw new SecurityException('Invalid image file');
            }
        }

        // 恶意软件扫描（如果启用）
        if ($config['scan_for_malware']) {
            $this->scanForMalware($file['tmp_name']);
        }
    }

    /**
     * CSRF 令牌生成
     */
    public function generateCsrfToken()
    {
        if (!$this->config['csrf']['enabled']) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->csrfTokens[$token] = time() + $this->config['csrf']['expire_time'];

        // 存储到会话
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['csrf_tokens'][$token] = $this->csrfTokens[$token];
        }

        return $token;
    }

    /**
     * CSRF 令牌验证
     */
    public function verifyCsrfToken($token)
    {
        if (!$this->config['csrf']['enabled']) {
            return true;
        }

        if (!$token) {
            return false;
        }

        // 从会话中获取令牌
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['csrf_tokens'][$token])) {
            $expiry = $_SESSION['csrf_tokens'][$token];

            if (time() <= $expiry) {
                // 如果配置为使用后删除
                if ($this->config['csrf']['regenerate_on_use']) {
                    unset($_SESSION['csrf_tokens'][$token]);
                }
                return true;
            } else {
                // 清理过期令牌
                unset($_SESSION['csrf_tokens'][$token]);
            }
        }

        return false;
    }

    /**
     * 内容过滤
     */
    public function filterContent($content, $type = 'html')
    {
        switch ($type) {
            case 'html':
                return $this->filterHtml($content);
            case 'url':
                return $this->filterUrl($content);
            case 'text':
                return $this->filterText($content);
            default:
                return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * HTML 过滤
     */
    private function filterHtml($content)
    {
        if (!$this->config['xss']['enabled']) {
            return $content;
        }

        $allowedTags = $this->config['xss']['allowed_tags'];
        $cleanHtml = '';

        // 简单的 HTML 清理（在生产环境中建议使用 HTML Purifier）
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));

        foreach ($dom->getElementsByTagName('*') as $element) {
            $tagName = strtolower($element->tagName);

            if (isset($allowedTags[$tagName])) {
                $allowedAttributes = $allowedTags[$tagName];

                // 移除不允许的属性
                $attributes = [];
                foreach ($element->attributes as $attr) {
                    if (in_array($attr->name, $allowedAttributes)) {
                        $attributes[$attr->name] = $attr->value;
                    }
                }

                // 重建元素
                foreach ($attributes as $name => $value) {
                    $element->setAttribute($name, $value);
                }
            } else {
                // 移除不允许的标签，保留内容
                $element->parentNode->replaceChild(
                    $dom->createTextNode($element->textContent),
                    $element
                );
            }
        }

        return $dom->saveHTML();
    }

    /**
     * URL 过滤
     */
    private function filterUrl($url)
    {
        $config = $this->config['content_validation']['url_validation'];

        // 基本 URL 验证
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new SecurityException('Invalid URL format');
        }

        $parsedUrl = parse_url($url);

        // 检查协议
        if (!in_array($parsedUrl['scheme'] ?? '', ['http', 'https'])) {
            throw new SecurityException('Invalid URL scheme');
        }

        // 检查本地地址
        if (!$config['allow_localhost'] && in_array($parsedUrl['host'] ?? '', ['localhost', '127.0.0.1', '::1'])) {
            throw new SecurityException('Localhost URLs not allowed');
        }

        // 检查私有 IP
        if (!$config['allow_private_ips']) {
            $ip = gethostbyname($parsedUrl['host'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new SecurityException('Private IP addresses not allowed');
            }
        }

        return $url;
    }

    /**
     * 文本过滤
     */
    private function filterText($text)
    {
        // 移除控制字符
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // HTML 实体编码
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 设置安全头
     */
    private function setSecurityHeaders()
    {
        if (!$this->config['headers']['enabled']) {
            return;
        }

        $headers = $this->config['headers'];

        if ($headers['x_frame_options']) {
            header("X-Frame-Options: {$headers['x_frame_options']}");
        }

        if ($headers['x_content_type_options']) {
            header("X-Content-Type-Options: {$headers['x_content_type_options']}");
        }

        if ($headers['x_xss_protection']) {
            header("X-XSS-Protection: {$headers['x_xss_protection']}");
        }

        if ($headers['strict_transport_security']) {
            header("Strict-Transport-Security: {$headers['strict_transport_security']}");
        }

        if ($headers['content_security_policy']) {
            header("Content-Security-Policy: {$headers['content_security_policy']}");
        }

        if ($headers['referrer_policy']) {
            header("Referrer-Policy: {$headers['referrer_policy']}");
        }

        if ($headers['permissions_policy']) {
            header("Permissions-Policy: {$headers['permissions_policy']}");
        }
    }

    /**
     * 获取客户端 IP
     */
    private function getClientIp()
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 检查 IP 是否在列表中
     */
    private function isIpInList($ip, $list)
    {
        foreach ($list as $item) {
            if (strpos($item, '/') !== false) {
                // CIDR 范围
                if ($this->ipInCidr($ip, $item)) {
                    return true;
                }
            } else {
                // 单个 IP
                if ($ip === $item) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 检查 IP 是否在 CIDR 范围内
     */
    private function ipInCidr($ip, $cidr)
    {
        list($subnet, $mask) = explode('/', $cidr);
        return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
    }

    /**
     * 恶意软件扫描
     */
    private function scanForMalware($filePath)
    {
        // 这里可以集成 ClamAV 或其他恶意软件扫描器
        // 示例实现：检查常见的恶意文件签名

        $handle = fopen($filePath, 'rb');
        $header = fread($handle, 1024);
        fclose($handle);

        // 检查可执行文件头
        $maliciousSignatures = [
            'MZ',       // Windows 可执行文件
            '\x7fELF',  // Linux 可执行文件
            '#!/bin/',  // Shell 脚本
            '<?php',    // PHP 脚本
        ];

        foreach ($maliciousSignatures as $signature) {
            if (strpos($header, $signature) === 0) {
                throw new SecurityException('Potentially malicious file detected');
            }
        }
    }

    /**
     * 记录安全事件
     */
    private function logSecurityEvent($event, $details = [])
    {
        if (!$this->config['audit_log']['enabled']) {
            return;
        }

        $logData = [
            'event' => $event,
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => $details
        ];

        // 写入日志文件
        error_log("SECURITY_EVENT: " . json_encode($logData));

        // 写入数据库
        try {
            $db = Application::getInstance()->getDatabase();
            $stmt = $db->prepare("
                INSERT INTO audit_logs (action, ip_address, user_agent, new_values, created_at)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $event,
                $logData['ip'],
                $logData['user_agent'],
                json_encode($details),
                $logData['timestamp']
            ]);
        } catch (Exception $e) {
            error_log("Failed to log security event to database: " . $e->getMessage());
        }
    }

    /**
     * 阻止请求
     */
    private function blockRequest($reason)
    {
        $this->logSecurityEvent('REQUEST_BLOCKED', ['reason' => $reason]);

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Access denied',
            'message' => 'Your request has been blocked for security reasons'
        ]);
        exit;
    }
}

/**
 * 速率限制器
 */
class RateLimiter
{
    private $storage = [];

    public function check($key, $maxAttempts, $decaySeconds)
    {
        $now = time();
        $windowStart = $now - $decaySeconds;

        // 清理过期记录
        if (isset($this->storage[$key])) {
            $this->storage[$key] = array_filter($this->storage[$key], function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            });
        }

        // 检查当前请求数
        $currentAttempts = count($this->storage[$key] ?? []);

        if ($currentAttempts >= $maxAttempts) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded'
            ]);
            exit;
        }

        // 记录当前请求
        $this->storage[$key][] = $now;
    }
}

/**
 * 安全异常类
 */
class SecurityException extends Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}