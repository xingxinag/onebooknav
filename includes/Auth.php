<?php
/**
 * Authentication and Authorization System
 * Handles user registration, login, and role-based access control
 */

require_once __DIR__ . '/Database.php';

class Auth {
    private $db;
    private $sessionName;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->sessionName = SESSION_NAME;

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->sessionName);
            session_start();
        }
    }

    /**
     * Register a new user
     */
    public function register($username, $password, $email = null, $role = 'user') {
        // Check if registration is allowed
        if (!ALLOW_REGISTRATION && $role === 'user') {
            throw new Exception('Registration is currently disabled');
        }

        // Validate input
        $this->validateRegistrationInput($username, $password, $email);

        // Check if username already exists
        if ($this->userExists($username)) {
            throw new Exception('Username already exists');
        }

        // Check if email already exists (if provided)
        if ($email && $this->emailExists($email)) {
            throw new Exception('Email already registered');
        }

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        // Create user
        $userId = $this->db->insert('users', [
            'username' => $username,
            'password_hash' => $passwordHash,
            'email' => $email,
            'role' => $role,
            'is_active' => 1
        ]);

        return $userId;
    }

    /**
     * Login user with username/email and password
     */
    public function login($usernameOrEmail, $password, $rememberMe = false) {
        // Find user by username or email
        $user = $this->getUserByUsernameOrEmail($usernameOrEmail);

        if (!$user) {
            throw new Exception('Invalid credentials');
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            throw new Exception('Invalid credentials');
        }

        // Check if user is active
        if (!$user['is_active']) {
            throw new Exception('Account is disabled');
        }

        // Update last login
        $this->db->update('users', [
            'last_login' => date('Y-m-d H:i:s')
        ], 'id = ?', [$user['id']]);

        // Create session
        $this->createSession($user, $rememberMe);

        return $user;
    }

    /**
     * Logout current user
     */
    public function logout() {
        // Remove session from database
        if (isset($_SESSION['session_id'])) {
            $this->db->delete('sessions', 'id = ?', [$_SESSION['session_id']]);
        }

        // Destroy session
        session_destroy();

        // Clear session cookie
        setcookie($this->sessionName, '', time() - 3600, '/');
    }

    /**
     * Get current authenticated user
     */
    public function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE id = ? AND is_active = 1",
            [$_SESSION['user_id']]
        );

        return $user ?: null;
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated() {
        return $this->getCurrentUser() !== null;
    }

    /**
     * Check if user has specific role
     */
    public function hasRole($role) {
        $user = $this->getCurrentUser();
        return $user && $user['role'] === $role;
    }

    /**
     * Check if user is admin (admin or superadmin)
     */
    public function isAdmin() {
        $user = $this->getCurrentUser();
        return $user && in_array($user['role'], ['admin', 'superadmin']);
    }

    /**
     * Check if user is superadmin
     */
    public function isSuperAdmin() {
        return $this->hasRole('superadmin');
    }


    /**
     * Change user password
     */
    public function changePassword($userId, $oldPassword, $newPassword) {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);

        if (!$user) {
            throw new Exception('User not found');
        }

        if (!password_verify($oldPassword, $user['password_hash'])) {
            throw new Exception('Current password is incorrect');
        }

        $this->validatePassword($newPassword);

        $newPasswordHash = password_hash($newPassword, PASSWORD_ARGON2ID);

        return $this->db->update('users', [
            'password_hash' => $newPasswordHash
        ], 'id = ?', [$userId]);
    }

    /**
     * Update user profile
     */
    public function updateProfile($userId, $data) {
        $allowedFields = ['email', 'avatar_url', 'settings'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return false;
        }

        return $this->db->update('users', $updateData, 'id = ?', [$userId]);
    }

    /**
     * Create admin user (during installation)
     */
    public function createAdminUser($username, $password, $email) {
        // Check if any admin already exists
        $existingAdmin = $this->db->fetchOne(
            "SELECT id FROM users WHERE role IN ('admin', 'superadmin')"
        );

        if ($existingAdmin) {
            throw new Exception('Admin user already exists');
        }

        return $this->register($username, $password, $email, 'superadmin');
    }

    // Private helper methods

    private function validateRegistrationInput($username, $password, $email) {
        if (empty($username) || strlen($username) < 3) {
            throw new Exception('Username must be at least 3 characters long');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            throw new Exception('Username can only contain letters, numbers, underscores, and hyphens');
        }

        $this->validatePassword($password);

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }
    }

    private function validatePassword($password) {
        if (empty($password) || strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters long');
        }
    }

    private function userExists($username) {
        $result = $this->db->fetchOne(
            "SELECT id FROM users WHERE username = ?",
            [$username]
        );
        return $result !== false;
    }

    private function emailExists($email) {
        $result = $this->db->fetchOne(
            "SELECT id FROM users WHERE email = ?",
            [$email]
        );
        return $result !== false;
    }

    private function getUserByUsernameOrEmail($usernameOrEmail) {
        return $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$usernameOrEmail, $usernameOrEmail]
        );
    }

    private function createSession($user, $rememberMe = false) {
        $sessionId = bin2hex(random_bytes(32));
        $sessionLifetime = $rememberMe ? SESSION_LIFETIME : 3600; // 1 hour for non-persistent

        // Store session in database
        $this->db->insert('sessions', [
            'id' => $sessionId,
            'user_id' => $user['id'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'payload' => json_encode([
                'username' => $user['username'],
                'role' => $user['role']
            ])
        ]);

        // Set session variables
        $_SESSION['session_id'] = $sessionId;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // Set session cookie
        if ($rememberMe) {
            session_set_cookie_params($sessionLifetime);
        }
    }

    /**
     * Clean expired sessions
     */
    public function cleanExpiredSessions() {
        $expiredTime = date('Y-m-d H:i:s', time() - SESSION_LIFETIME);
        return $this->db->delete('sessions', 'last_activity < ?', [$expiredTime]);
    }

    /**
     * Require authentication for API endpoints
     */
    public function requireAuth() {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
    }

    /**
     * Require admin role
     */
    public function requireAdmin() {
        $this->requireAuth();
        if (!$this->isAdmin()) {
            // Log the access attempt for debugging
            error_log("Admin access denied for user: " . ($this->getCurrentUser()['username'] ?? 'anonymous'));
            error_log("User role: " . ($this->getCurrentUser()['role'] ?? 'none'));

            http_response_code(403);
            echo json_encode([
                'error' => 'Admin access required',
                'user_role' => $this->getCurrentUser()['role'] ?? null
            ]);
            exit;
        }
    }

    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

}
?>