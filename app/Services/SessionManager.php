<?php
/**
 * OneBookNav - 会话管理器
 *
 * 处理用户会话的创建、维护和销毁
 */

class SessionManager
{
    private $config;
    private $csrfToken;

    public function __construct($config)
    {
        $this->config = $config;
        $this->initializeSession();
    }

    /**
     * 初始化会话
     */
    private function initializeSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // 设置会话参数
            session_set_cookie_params([
                'lifetime' => $this->config['lifetime'],
                'path' => $this->config['cookie']['path'],
                'domain' => $this->config['cookie']['domain'],
                'secure' => $this->config['cookie']['secure'],
                'httponly' => $this->config['cookie']['http_only'],
                'samesite' => $this->config['cookie']['same_site']
            ]);

            session_name($this->config['cookie']['name']);
            session_start();

            // 验证会话安全性
            $this->validateSession();

            // 生成 CSRF 令牌
            $this->initializeCsrfToken();
        }
    }

    /**
     * 验证会话安全性
     */
    private function validateSession()
    {
        // 检查会话劫持
        if (!$this->isValidSession()) {
            $this->destroy();
            session_start();
        }

        // 检查会话超时
        if ($this->isSessionExpired()) {
            $this->destroy();
            session_start();
        }

        // 更新最后活动时间
        $_SESSION['last_activity'] = time();

        // 定期重新生成会话ID
        if ($this->shouldRegenerateId()) {
            $this->regenerate();
        }
    }

    /**
     * 检查会话是否有效
     */
    private function isValidSession()
    {
        // 检查用户代理
        if (isset($_SESSION['user_agent'])) {
            $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ($_SESSION['user_agent'] !== $currentUserAgent) {
                return false;
            }
        } else {
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }

        // 检查 IP 地址（可选，可能会对移动用户造成问题）
        if (isset($_SESSION['ip_address']) && $this->config['check_ip']) {
            $currentIp = $this->getClientIp();
            if ($_SESSION['ip_address'] !== $currentIp) {
                return false;
            }
        } else {
            $_SESSION['ip_address'] = $this->getClientIp();
        }

        return true;
    }

    /**
     * 检查会话是否过期
     */
    private function isSessionExpired()
    {
        // 检查空闲超时
        if (isset($_SESSION['last_activity'])) {
            $idleTime = time() - $_SESSION['last_activity'];
            if ($idleTime > $this->config['timeout_idle']) {
                return true;
            }
        }

        // 检查绝对超时
        if (isset($_SESSION['created_at'])) {
            $totalTime = time() - $_SESSION['created_at'];
            if ($totalTime > $this->config['timeout_absolute']) {
                return true;
            }
        } else {
            $_SESSION['created_at'] = time();
        }

        return false;
    }

    /**
     * 检查是否应该重新生成会话ID
     */
    private function shouldRegenerateId()
    {
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
            return false;
        }

        // 每30分钟重新生成一次
        return (time() - $_SESSION['last_regeneration']) > 1800;
    }

    /**
     * 重新生成会话ID
     */
    public function regenerate($deleteOld = true)
    {
        session_regenerate_id($deleteOld);
        $_SESSION['last_regeneration'] = time();
    }

    /**
     * 设置会话值
     */
    public function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    /**
     * 获取会话值
     */
    public function get($key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * 检查会话键是否存在
     */
    public function has($key)
    {
        return isset($_SESSION[$key]);
    }

    /**
     * 删除会话值
     */
    public function remove($key)
    {
        unset($_SESSION[$key]);
    }

    /**
     * 获取所有会话数据
     */
    public function all()
    {
        return $_SESSION;
    }

    /**
     * 清空会话数据
     */
    public function clear()
    {
        $_SESSION = [];
    }

    /**
     * 销毁会话
     */
    public function destroy()
    {
        // 清空会话数据
        $_SESSION = [];

        // 删除会话 cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // 销毁会话
        session_destroy();
    }

    /**
     * 闪存消息 - 设置一次性消息
     */
    public function flash($key, $value)
    {
        $_SESSION['flash'][$key] = $value;
    }

    /**
     * 获取闪存消息
     */
    public function getFlash($key, $default = null)
    {
        $value = $_SESSION['flash'][$key] ?? $default;
        unset($_SESSION['flash'][$key]);
        return $value;
    }

    /**
     * 检查是否有闪存消息
     */
    public function hasFlash($key)
    {
        return isset($_SESSION['flash'][$key]);
    }

    /**
     * 获取所有闪存消息
     */
    public function getFlashes()
    {
        $flashes = $_SESSION['flash'] ?? [];
        $_SESSION['flash'] = [];
        return $flashes;
    }

    /**
     * 初始化 CSRF 令牌
     */
    private function initializeCsrfToken()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->generateCsrfToken();
        }
        $this->csrfToken = $_SESSION['csrf_token'];
    }

    /**
     * 生成 CSRF 令牌
     */
    private function generateCsrfToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 获取 CSRF 令牌
     */
    public function getCsrfToken()
    {
        return $this->csrfToken;
    }

    /**
     * 验证 CSRF 令牌
     */
    public function verifyCsrfToken($token)
    {
        return hash_equals($this->csrfToken, $token);
    }

    /**
     * 刷新 CSRF 令牌
     */
    public function refreshCsrfToken()
    {
        $this->csrfToken = $this->generateCsrfToken();
        $_SESSION['csrf_token'] = $this->csrfToken;
        return $this->csrfToken;
    }

    /**
     * 获取会话ID
     */
    public function getId()
    {
        return session_id();
    }

    /**
     * 获取会话状态
     */
    public function getStatus()
    {
        return session_status();
    }

    /**
     * 检查会话是否活跃
     */
    public function isActive()
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * 获取会话统计信息
     */
    public function getStats()
    {
        return [
            'id' => session_id(),
            'status' => session_status(),
            'created_at' => $_SESSION['created_at'] ?? null,
            'last_activity' => $_SESSION['last_activity'] ?? null,
            'last_regeneration' => $_SESSION['last_regeneration'] ?? null,
            'user_agent' => $_SESSION['user_agent'] ?? null,
            'ip_address' => $_SESSION['ip_address'] ?? null,
            'data_size' => strlen(serialize($_SESSION)),
            'csrf_token' => $this->csrfToken
        ];
    }

    /**
     * 会话垃圾回收
     */
    public function gc($maxLifetime = null)
    {
        if ($maxLifetime === null) {
            $maxLifetime = $this->config['lifetime'];
        }

        // 这里可以实现自定义的会话清理逻辑
        // 例如清理数据库中过期的会话记录
        return session_gc();
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
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 保存会话数据到数据库
     */
    public function saveToDatabase()
    {
        if (!$this->isActive()) {
            return false;
        }

        try {
            $db = Application::getInstance()->getDatabase();

            $stmt = $db->prepare("
                INSERT OR REPLACE INTO user_sessions
                (id, user_id, ip_address, user_agent, payload, last_activity)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                session_id(),
                $_SESSION['user_id'] ?? null,
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                serialize($_SESSION),
                time()
            ]);

            return true;

        } catch (Exception $e) {
            error_log("Failed to save session to database: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 从数据库加载会话数据
     */
    public function loadFromDatabase($sessionId)
    {
        try {
            $db = Application::getInstance()->getDatabase();

            $stmt = $db->prepare("
                SELECT * FROM user_sessions
                WHERE id = ? AND last_activity > ?
            ");

            $stmt->execute([
                $sessionId,
                time() - $this->config['lifetime']
            ]);

            $sessionData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sessionData) {
                $_SESSION = unserialize($sessionData['payload']);
                return true;
            }

            return false;

        } catch (Exception $e) {
            error_log("Failed to load session from database: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清理过期会话
     */
    public function cleanupExpiredSessions()
    {
        try {
            $db = Application::getInstance()->getDatabase();

            $stmt = $db->prepare("
                DELETE FROM user_sessions
                WHERE last_activity < ?
            ");

            $stmt->execute([time() - $this->config['lifetime']]);

            return $stmt->rowCount();

        } catch (Exception $e) {
            error_log("Failed to cleanup expired sessions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 获取活跃会话数量
     */
    public function getActiveSessionCount()
    {
        try {
            $db = Application::getInstance()->getDatabase();

            $stmt = $db->prepare("
                SELECT COUNT(*) FROM user_sessions
                WHERE last_activity > ?
            ");

            $stmt->execute([time() - $this->config['lifetime']]);

            return $stmt->fetchColumn();

        } catch (Exception $e) {
            error_log("Failed to get active session count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 获取用户的所有会话
     */
    public function getUserSessions($userId)
    {
        try {
            $db = Application::getInstance()->getDatabase();

            $stmt = $db->prepare("
                SELECT id, ip_address, user_agent, last_activity, created_at
                FROM user_sessions
                WHERE user_id = ? AND last_activity > ?
                ORDER BY last_activity DESC
            ");

            $stmt->execute([
                $userId,
                time() - $this->config['lifetime']
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Failed to get user sessions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 终止用户的其他会话
     */
    public function terminateOtherSessions($userId, $currentSessionId = null)
    {
        try {
            $db = Application::getInstance()->getDatabase();

            $currentSessionId = $currentSessionId ?: session_id();

            $stmt = $db->prepare("
                DELETE FROM user_sessions
                WHERE user_id = ? AND id != ?
            ");

            $stmt->execute([$userId, $currentSessionId]);

            return $stmt->rowCount();

        } catch (Exception $e) {
            error_log("Failed to terminate other sessions: " . $e->getMessage());
            return 0;
        }
    }
}