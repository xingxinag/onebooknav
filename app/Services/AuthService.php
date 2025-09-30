<?php

namespace App\Services;

use App\Core\Container;
use App\Models\User;
use App\Models\InvitationCode;
use Exception;

/**
 * 用户认证服务
 *
 * 实现"终极.txt"要求的完整用户认证和权限管理系统
 * 融合 BookNav 和 OneNav 的所有认证功能
 */
class AuthService
{
    private static $instance = null;
    private DatabaseService $database;
    private ConfigService $config;
    private SecurityService $security;
    private ?array $currentUser = null;
    private string $sessionKey = 'onebooknav_user_session';

    private function __construct()
    {
        $container = Container::getInstance();
        $this->database = $container->get('database');
        $this->config = $container->get('config');
        $this->security = $container->get('security');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 用户登录
     *
     * 支持用户名或邮箱登录，包含完整的安全验证
     */
    public function login(string $identifier, string $password, bool $remember = false): array
    {
        try {
            // 查找用户
            $user = $this->findUserByIdentifier($identifier);
            if (!$user) {
                $this->recordAuthAttempt($identifier, false, 'user_not_found');
                throw new AuthException('用户名或密码错误');
            }

            // 检查用户状态
            if ($user['status'] !== 'active') {
                $this->recordAuthAttempt($identifier, false, 'user_inactive');
                throw new AuthException('账户已被禁用');
            }

            // 检查账户锁定
            if ($this->isAccountLocked($user)) {
                $this->recordAuthAttempt($identifier, false, 'account_locked');
                throw new AuthException('账户已被锁定，请稍后再试');
            }

            // 验证密码
            if (!$this->verifyPassword($password, $user)) {
                $this->incrementFailedAttempts($user['id']);
                $this->recordAuthAttempt($identifier, false, 'invalid_password');
                throw new AuthException('用户名或密码错误');
            }

            // 登录成功处理
            $this->handleSuccessfulLogin($user, $remember);
            $this->recordAuthAttempt($identifier, true, 'login_success');

            return $this->formatUserData($user);

        } catch (AuthException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->recordAuthAttempt($identifier, false, 'system_error');
            throw new AuthException('登录过程中发生错误，请稍后再试');
        }
    }

    /**
     * 用户注册
     *
     * 完整的注册流程，支持邀请码和数据验证
     */
    public function register(array $data, ?string $invitationCode = null): array
    {
        try {
            // 检查注册设置
            $this->validateRegistrationSettings($invitationCode);

            // 验证注册数据
            $this->validateRegistrationData($data);

            // 检查用户是否已存在
            $this->checkUserExists($data);

            // 验证并使用邀请码
            $invitationData = null;
            if ($invitationCode) {
                $invitationData = $this->validateAndUseInvitationCode($invitationCode);
            }

            // 创建用户
            $user = $this->createUser($data, $invitationData);

            // 记录注册日志
            $this->recordAuthAttempt($data['username'], true, 'registration_success');

            return $this->formatUserData($user);

        } catch (AuthException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new AuthException('注册过程中发生错误，请稍后再试');
        }
    }

    /**
     * 用户登出
     */
    public function logout(): bool
    {
        try {
            $userId = $this->getCurrentUserId();

            // 清除会话
            $this->clearSession();

            // 清除记住我令牌
            $this->clearRememberToken();

            // 记录登出日志
            if ($userId) {
                $this->recordUserAction($userId, 'logout');
            }

            $this->currentUser = null;
            return true;

        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查用户是否已登录
     */
    public function check(): bool
    {
        if ($this->currentUser !== null) {
            return true;
        }

        // 检查会话
        if ($this->checkSession()) {
            return true;
        }

        // 检查记住我令牌
        if ($this->checkRememberToken()) {
            return true;
        }

        return false;
    }

    /**
     * 获取当前用户
     */
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }

        return $this->currentUser;
    }

    /**
     * 获取当前用户ID
     */
    public function getCurrentUserId(): ?int
    {
        $user = $this->user();
        return $user ? (int)$user['id'] : null;
    }

    /**
     * 检查用户角色
     */
    public function hasRole(string $role): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        return $user['role'] === $role;
    }

    /**
     * 检查用户是否为管理员
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * 检查用户权限
     */
    public function hasPermission(string $permission): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        // 管理员拥有所有权限
        if ($user['role'] === 'admin') {
            return true;
        }

        // 根据角色判断权限
        $rolePermissions = $this->getRolePermissions($user['role']);
        return in_array($permission, $rolePermissions);
    }

    /**
     * 更改密码
     */
    public function changePassword(string $currentPassword, string $newPassword): bool
    {
        $user = $this->user();
        if (!$user) {
            throw new AuthException('用户未登录');
        }

        // 验证当前密码
        if (!$this->verifyPassword($currentPassword, $user)) {
            throw new AuthException('当前密码错误');
        }

        // 验证新密码强度
        $this->validatePassword($newPassword);

        // 更新密码
        $hashedPassword = $this->hashPassword($newPassword);
        $salt = bin2hex(random_bytes(16));

        $this->database->update('users', [
            'password_hash' => $hashedPassword,
            'salt' => $salt,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$user['id']]);

        // 记录操作日志
        $this->recordUserAction($user['id'], 'password_changed');

        return true;
    }

    /**
     * 重置密码
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        // 验证重置令牌
        $resetData = $this->validateResetToken($token);

        // 验证新密码
        $this->validatePassword($newPassword);

        // 更新密码
        $hashedPassword = $this->hashPassword($newPassword);
        $salt = bin2hex(random_bytes(16));

        $this->database->update('users', [
            'password_hash' => $hashedPassword,
            'salt' => $salt,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$resetData['user_id']]);

        // 删除重置令牌
        $this->database->delete('password_resets', 'token = ?', [$token]);

        // 记录操作日志
        $this->recordUserAction($resetData['user_id'], 'password_reset');

        return true;
    }

    /**
     * 生成密码重置令牌
     */
    public function generatePasswordResetToken(string $email): ?string
    {
        $user = $this->findUserByEmail($email);
        if (!$user) {
            // 出于安全考虑，不提示用户不存在
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 3600); // 1小时有效期

        // 删除旧的重置令牌
        $this->database->delete('password_resets', 'user_id = ?', [$user['id']]);

        // 创建新的重置令牌
        $this->database->insert('password_resets', [
            'user_id' => $user['id'],
            'token' => $token,
            'expires_at' => $expiry,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $token;
    }

    /**
     * 创建邀请码
     */
    public function createInvitationCode(int $maxUses = 1, ?string $expiresAt = null): string
    {
        $code = strtoupper(bin2hex(random_bytes(8)));
        $createdBy = $this->getCurrentUserId();

        $this->database->insert('invitation_codes', [
            'code' => $code,
            'created_by' => $createdBy,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $code;
    }

    /**
     * 获取用户统计信息
     */
    public function getUserStats(): array
    {
        $totalUsers = $this->database->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
        $activeUsers = $this->database->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch()['count'];
        $adminUsers = $this->database->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch()['count'];
        $recentLogins = $this->database->query("SELECT COUNT(*) as count FROM users WHERE last_login_at > ?", [date('Y-m-d H:i:s', time() - 86400)])->fetch()['count'];

        return [
            'total_users' => (int)$totalUsers,
            'active_users' => (int)$activeUsers,
            'admin_users' => (int)$adminUsers,
            'recent_logins' => (int)$recentLogins
        ];
    }

    // ==================== 私有方法 ====================

    /**
     * 通过标识符查找用户
     */
    private function findUserByIdentifier(string $identifier): ?array
    {
        // 尝试按用户名查找
        $user = $this->database->find('users', $identifier, 'username');
        if ($user) {
            return $user;
        }

        // 如果是邮箱格式，按邮箱查找
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return $this->database->find('users', $identifier, 'email');
        }

        return null;
    }

    /**
     * 通过邮箱查找用户
     */
    private function findUserByEmail(string $email): ?array
    {
        return $this->database->find('users', $email, 'email');
    }

    /**
     * 验证密码
     */
    private function verifyPassword(string $password, array $user): bool
    {
        return password_verify($password . $user['salt'], $user['password_hash']);
    }

    /**
     * 哈希密码
     */
    private function hashPassword(string $password, ?string $salt = null): string
    {
        if ($salt === null) {
            $salt = bin2hex(random_bytes(16));
        }
        return password_hash($password . $salt, PASSWORD_DEFAULT);
    }

    /**
     * 检查账户是否被锁定
     */
    private function isAccountLocked(array $user): bool
    {
        if (!$user['locked_until']) {
            return false;
        }

        $lockedUntil = strtotime($user['locked_until']);
        return $lockedUntil > time();
    }

    /**
     * 增加失败尝试次数
     */
    private function incrementFailedAttempts(int $userId): void
    {
        $maxAttempts = $this->config->get('security.max_login_attempts', 5);
        $lockDuration = $this->config->get('security.lock_duration', 1800); // 30分钟

        $this->database->query(
            "UPDATE users SET login_attempts = login_attempts + 1 WHERE id = ?",
            [$userId]
        );

        // 检查是否需要锁定账户
        $user = $this->database->find('users', $userId);
        if ($user['login_attempts'] >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockDuration);
            $this->database->update('users', [
                'locked_until' => $lockedUntil
            ], 'id = ?', [$userId]);
        }
    }

    /**
     * 处理成功登录
     */
    private function handleSuccessfulLogin(array $user, bool $remember): void
    {
        // 重置失败尝试次数
        $this->database->update('users', [
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'login_count' => $user['login_count'] + 1
        ], 'id = ?', [$user['id']]);

        // 设置会话
        $this->setSession($user);

        // 设置记住我令牌
        if ($remember) {
            $this->setRememberToken($user['id']);
        }

        $this->currentUser = $user;
    }

    /**
     * 验证注册设置
     */
    private function validateRegistrationSettings(?string $invitationCode): void
    {
        $registrationEnabled = $this->config->get('app.registration_enabled', true);
        if (!$registrationEnabled) {
            throw new AuthException('当前不允许注册新用户');
        }

        $invitationRequired = $this->config->get('app.invitation_required', false);
        if ($invitationRequired && !$invitationCode) {
            throw new AuthException('注册需要邀请码');
        }
    }

    /**
     * 验证注册数据
     */
    private function validateRegistrationData(array $data): void
    {
        $errors = [];

        // 验证用户名
        if (empty($data['username'])) {
            $errors[] = '用户名不能为空';
        } elseif (strlen($data['username']) < 3) {
            $errors[] = '用户名至少需要3个字符';
        } elseif (strlen($data['username']) > 50) {
            $errors[] = '用户名不能超过50个字符';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['username'])) {
            $errors[] = '用户名只能包含字母、数字、下划线和短横线';
        }

        // 验证邮箱
        if (empty($data['email'])) {
            $errors[] = '邮箱不能为空';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确';
        } elseif (strlen($data['email']) > 100) {
            $errors[] = '邮箱长度不能超过100个字符';
        }

        // 验证密码
        if (empty($data['password'])) {
            $errors[] = '密码不能为空';
        } else {
            try {
                $this->validatePassword($data['password']);
            } catch (AuthException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new AuthException(implode('; ', $errors));
        }
    }

    /**
     * 验证密码强度
     */
    private function validatePassword(string $password): void
    {
        $minLength = $this->config->get('security.password_min_length', 8);
        $requireUppercase = $this->config->get('security.password_require_uppercase', true);
        $requireLowercase = $this->config->get('security.password_require_lowercase', true);
        $requireNumbers = $this->config->get('security.password_require_numbers', true);
        $requireSymbols = $this->config->get('security.password_require_symbols', false);

        if (strlen($password) < $minLength) {
            throw new AuthException("密码至少需要{$minLength}个字符");
        }

        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            throw new AuthException('密码必须包含大写字母');
        }

        if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
            throw new AuthException('密码必须包含小写字母');
        }

        if ($requireNumbers && !preg_match('/\d/', $password)) {
            throw new AuthException('密码必须包含数字');
        }

        if ($requireSymbols && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            throw new AuthException('密码必须包含特殊字符');
        }

        // 检查常见弱密码
        $weakPasswords = ['password', '123456', 'admin', 'root', 'guest'];
        if (in_array(strtolower($password), $weakPasswords)) {
            throw new AuthException('密码过于简单，请使用更复杂的密码');
        }
    }

    /**
     * 检查用户是否已存在
     */
    private function checkUserExists(array $data): void
    {
        if ($this->database->find('users', $data['username'], 'username')) {
            throw new AuthException('用户名已存在');
        }

        if ($this->database->find('users', $data['email'], 'email')) {
            throw new AuthException('邮箱已被注册');
        }
    }

    /**
     * 验证并使用邀请码
     */
    private function validateAndUseInvitationCode(string $code): ?array
    {
        $invitation = $this->database->query(
            "SELECT * FROM invitation_codes WHERE code = ? AND is_active = 1",
            [$code]
        )->fetch();

        if (!$invitation) {
            throw new AuthException('邀请码不存在或已失效');
        }

        // 检查过期时间
        if ($invitation['expires_at'] && strtotime($invitation['expires_at']) < time()) {
            throw new AuthException('邀请码已过期');
        }

        // 检查使用次数
        if ($invitation['used_count'] >= $invitation['max_uses']) {
            throw new AuthException('邀请码已达到最大使用次数');
        }

        return $invitation;
    }

    /**
     * 创建用户
     */
    private function createUser(array $data, ?array $invitationData = null): array
    {
        $salt = bin2hex(random_bytes(16));
        $hashedPassword = $this->hashPassword($data['password'], $salt);

        $userId = $this->database->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => $hashedPassword,
            'salt' => $salt,
            'role' => 'user',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // 更新邀请码使用情况
        if ($invitationData) {
            $this->database->update('invitation_codes', [
                'used_count' => $invitationData['used_count'] + 1,
                'used_by' => $userId,
                'used_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$invitationData['id']]);
        }

        return $this->database->find('users', $userId);
    }

    /**
     * 格式化用户数据
     */
    private function formatUserData(array $user): array
    {
        unset($user['password_hash'], $user['salt']);
        return $user;
    }

    /**
     * 设置会话
     */
    private function setSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION[$this->sessionKey] = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'login_time' => time()
        ];
    }

    /**
     * 检查会话
     */
    private function checkSession(): bool
    {
        if (!isset($_SESSION[$this->sessionKey])) {
            return false;
        }

        $sessionData = $_SESSION[$this->sessionKey];
        $user = $this->database->find('users', $sessionData['user_id']);

        if (!$user || $user['status'] !== 'active') {
            $this->clearSession();
            return false;
        }

        $this->currentUser = $user;
        return true;
    }

    /**
     * 清除会话
     */
    private function clearSession(): void
    {
        unset($_SESSION[$this->sessionKey]);
    }

    /**
     * 设置记住我令牌
     */
    private function setRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (30 * 24 * 3600); // 30天

        setcookie(
            'onebooknav_remember',
            $token,
            $expiry,
            '/',
            '',
            $this->isHttps(),
            true
        );

        // 存储令牌到数据库
        $this->database->insert('remember_tokens', [
            'user_id' => $userId,
            'token' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', $expiry),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 检查记住我令牌
     */
    private function checkRememberToken(): bool
    {
        if (!isset($_COOKIE['onebooknav_remember'])) {
            return false;
        }

        $token = $_COOKIE['onebooknav_remember'];
        $hashedToken = hash('sha256', $token);

        $tokenData = $this->database->query(
            "SELECT * FROM remember_tokens WHERE token = ? AND expires_at > ?",
            [$hashedToken, date('Y-m-d H:i:s')]
        )->fetch();

        if (!$tokenData) {
            $this->clearRememberToken();
            return false;
        }

        $user = $this->database->find('users', $tokenData['user_id']);
        if (!$user || $user['status'] !== 'active') {
            $this->clearRememberToken();
            return false;
        }

        $this->currentUser = $user;
        $this->setSession($user);
        return true;
    }

    /**
     * 清除记住我令牌
     */
    private function clearRememberToken(): void
    {
        if (isset($_COOKIE['onebooknav_remember'])) {
            $token = $_COOKIE['onebooknav_remember'];
            $hashedToken = hash('sha256', $token);

            // 从数据库删除令牌
            $this->database->delete('remember_tokens', 'token = ?', [$hashedToken]);

            // 清除Cookie
            setcookie('onebooknav_remember', '', time() - 3600, '/');
        }
    }

    /**
     * 验证重置令牌
     */
    private function validateResetToken(string $token): array
    {
        $resetData = $this->database->query(
            "SELECT * FROM password_resets WHERE token = ? AND expires_at > ?",
            [$token, date('Y-m-d H:i:s')]
        )->fetch();

        if (!$resetData) {
            throw new AuthException('重置令牌无效或已过期');
        }

        return $resetData;
    }

    /**
     * 获取角色权限
     */
    private function getRolePermissions(string $role): array
    {
        $permissions = [
            'admin' => ['*'], // 管理员拥有所有权限
            'user' => [
                'bookmarks.view',
                'bookmarks.create',
                'bookmarks.edit_own',
                'bookmarks.delete_own',
                'categories.view',
                'profile.edit'
            ],
            'guest' => [
                'bookmarks.view_public'
            ]
        ];

        return $permissions[$role] ?? [];
    }

    /**
     * 记录认证尝试
     */
    private function recordAuthAttempt(string $identifier, bool $success, string $action): void
    {
        try {
            $this->database->insert('audit_logs', [
                'user_id' => $this->getCurrentUserId(),
                'action' => $success ? "AUTH_SUCCESS: {$action}" : "AUTH_FAILED: {$action}",
                'table_name' => 'users',
                'old_values' => json_encode(['identifier' => $identifier]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Failed to record auth attempt: " . $e->getMessage());
        }
    }

    /**
     * 记录用户操作
     */
    private function recordUserAction(int $userId, string $action): void
    {
        try {
            $this->database->insert('audit_logs', [
                'user_id' => $userId,
                'action' => $action,
                'table_name' => 'users',
                'record_id' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Failed to record user action: " . $e->getMessage());
        }
    }

    /**
     * 检查是否为HTTPS
     */
    private function isHttps(): bool
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }
}

/**
 * 认证异常类
 */
class AuthException extends Exception
{
    public function __construct(string $message = "", int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}