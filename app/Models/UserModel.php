<?php
/**
 * OneBookNav - 用户模型
 */

require_once __DIR__ . '/BaseModel.php';

class UserModel extends BaseModel
{
    protected $table = 'users';
    protected $fillable = [
        'username', 'email', 'password_hash', 'salt', 'role', 'status',
        'last_login_at', 'last_login_ip', 'avatar', 'preferences'
    ];
    protected $casts = [
        'login_attempts' => 'integer',
        'preferences' => 'json',
        'last_login_at' => 'datetime'
    ];

    /**
     * 根据用户名查找用户
     */
    public function findByUsername($username)
    {
        return $this->where('username', $username);
    }

    /**
     * 根据邮箱查找用户
     */
    public function findByEmail($email)
    {
        return $this->where('email', $email);
    }

    /**
     * 验证用户密码
     */
    public function verifyPassword($password, $hash, $salt)
    {
        return password_verify($password . $salt, $hash);
    }

    /**
     * 创建密码哈希
     */
    public function hashPassword($password, $salt = null)
    {
        if (!$salt) {
            $salt = bin2hex(random_bytes(16));
        }

        $hash = password_hash($password . $salt, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);

        return ['hash' => $hash, 'salt' => $salt];
    }

    /**
     * 创建用户
     */
    public function createUser($data)
    {
        if (isset($data['password'])) {
            $passwordData = $this->hashPassword($data['password']);
            $data['password_hash'] = $passwordData['hash'];
            $data['salt'] = $passwordData['salt'];
            unset($data['password']);
        }

        $data['status'] = $data['status'] ?? 'active';
        $data['role'] = $data['role'] ?? 'user';

        return $this->create($data);
    }

    /**
     * 更新用户密码
     */
    public function updatePassword($userId, $newPassword)
    {
        $passwordData = $this->hashPassword($newPassword);

        return $this->update($userId, [
            'password_hash' => $passwordData['hash'],
            'salt' => $passwordData['salt']
        ]);
    }

    /**
     * 更新最后登录信息
     */
    public function updateLastLogin($userId, $ip = null)
    {
        return $this->update($userId, [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip ?: $_SERVER['REMOTE_ADDR'] ?? null,
            'login_attempts' => 0
        ]);
    }

    /**
     * 增加登录尝试次数
     */
    public function incrementLoginAttempts($userId)
    {
        $user = $this->find($userId);
        if (!$user) return false;

        $attempts = ($user['login_attempts'] ?? 0) + 1;
        $lockUntil = null;

        // 如果尝试次数超过5次，锁定账户15分钟
        if ($attempts >= 5) {
            $lockUntil = date('Y-m-d H:i:s', time() + 900); // 15分钟
        }

        $updateData = ['login_attempts' => $attempts];
        if ($lockUntil) {
            $updateData['locked_until'] = $lockUntil;
        }

        return $this->update($userId, $updateData);
    }

    /**
     * 检查用户是否被锁定
     */
    public function isLocked($user)
    {
        if (!isset($user['locked_until']) || !$user['locked_until']) {
            return false;
        }

        return strtotime($user['locked_until']) > time();
    }

    /**
     * 解锁用户
     */
    public function unlockUser($userId)
    {
        return $this->update($userId, [
            'login_attempts' => 0,
            'locked_until' => null
        ]);
    }

    /**
     * 获取活跃用户
     */
    public function getActiveUsers($limit = 10)
    {
        $sql = "
            SELECT u.*, COUNT(cl.id) as click_count
            FROM {$this->table} u
            LEFT JOIN click_logs cl ON u.id = cl.user_id
            WHERE u.status = 'active'
            GROUP BY u.id
            ORDER BY u.last_login_at DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取用户统计
     */
    public function getUserStats($userId)
    {
        $sql = "
            SELECT
                (SELECT COUNT(*) FROM websites WHERE user_id = ?) as website_count,
                (SELECT COUNT(*) FROM categories WHERE user_id = ?) as category_count,
                (SELECT COUNT(*) FROM user_favorites WHERE user_id = ?) as favorite_count,
                (SELECT COUNT(*) FROM click_logs WHERE user_id = ?) as click_count,
                (SELECT MAX(clicked_at) FROM click_logs WHERE user_id = ?) as last_activity
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * 搜索用户
     */
    public function searchUsers($keyword, $page = 1, $perPage = 20)
    {
        return $this->search($keyword, ['username', 'email'], $page, $perPage);
    }

    /**
     * 获取管理员用户
     */
    public function getAdmins()
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE role = 'admin' AND status = 'active'");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 更新用户偏好设置
     */
    public function updatePreferences($userId, $preferences)
    {
        $user = $this->find($userId);
        if (!$user) return false;

        $currentPreferences = json_decode($user['preferences'] ?? '{}', true);
        $newPreferences = array_merge($currentPreferences, $preferences);

        return $this->update($userId, [
            'preferences' => json_encode($newPreferences)
        ]);
    }

    /**
     * 获取用户偏好设置
     */
    public function getPreferences($userId, $key = null)
    {
        $user = $this->find($userId);
        if (!$user) return null;

        $preferences = json_decode($user['preferences'] ?? '{}', true);

        if ($key) {
            return $preferences[$key] ?? null;
        }

        return $preferences;
    }

    /**
     * 清理过期的锁定状态
     */
    public function cleanupExpiredLocks()
    {
        $sql = "UPDATE {$this->table} SET locked_until = NULL, login_attempts = 0 WHERE locked_until < ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([date('Y-m-d H:i:s')]);
    }

    /**
     * 获取用户注册统计
     */
    public function getRegistrationStats($days = 30)
    {
        $sql = "
            SELECT
                DATE(created_at) as date,
                COUNT(*) as count
            FROM {$this->table}
            WHERE created_at >= DATE('now', '-{$days} days')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ";

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}