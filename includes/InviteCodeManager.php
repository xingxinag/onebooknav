<?php
/**
 * Invite Code Manager - merged from BookNav
 * Handles invite-only registration system
 */

class InviteCodeManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Generate new invite code
     */
    public function generateInviteCode($createdBy, $expiresAt = null, $maxUses = 1) {
        $code = $this->generateRandomCode();

        // Ensure code is unique
        while ($this->codeExists($code)) {
            $code = $this->generateRandomCode();
        }

        $sql = "INSERT INTO invite_codes (code, created_by, expires_at, max_uses, used_count, created_at)
                VALUES (:code, :created_by, :expires_at, :max_uses, 0, NOW())";

        $params = [
            'code' => $code,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses
        ];

        $this->db->query($sql, $params);
        return $code;
    }

    /**
     * Validate invite code
     */
    public function validateInviteCode($code) {
        $sql = "SELECT * FROM invite_codes
                WHERE code = :code
                AND is_active = 1
                AND (expires_at IS NULL OR expires_at > NOW())
                AND (max_uses = 0 OR used_count < max_uses)";

        $inviteCode = $this->db->fetchOne($sql, ['code' => $code]);

        if (!$inviteCode) {
            return false;
        }

        return $inviteCode;
    }

    /**
     * Use invite code (increment usage count)
     */
    public function useInviteCode($code, $usedBy) {
        $inviteCode = $this->validateInviteCode($code);
        if (!$inviteCode) {
            throw new Exception("Invalid or expired invite code");
        }

        // Record usage
        $this->db->query("INSERT INTO invite_code_uses (invite_code_id, used_by, used_at) VALUES (:invite_code_id, :used_by, NOW())", [
            'invite_code_id' => $inviteCode['id'],
            'used_by' => $usedBy
        ]);

        // Increment usage count
        $this->db->query("UPDATE invite_codes SET used_count = used_count + 1 WHERE id = :id", [
            'id' => $inviteCode['id']
        ]);

        // Deactivate if max uses reached
        if ($inviteCode['max_uses'] > 0 && ($inviteCode['used_count'] + 1) >= $inviteCode['max_uses']) {
            $this->db->query("UPDATE invite_codes SET is_active = 0 WHERE id = :id", [
                'id' => $inviteCode['id']
            ]);
        }

        return true;
    }

    /**
     * Get all invite codes for admin
     */
    public function getAllInviteCodes($createdBy = null) {
        $sql = "SELECT ic.*, u.username as created_by_username,
                (SELECT COUNT(*) FROM invite_code_uses WHERE invite_code_id = ic.id) as actual_uses
                FROM invite_codes ic
                LEFT JOIN users u ON ic.created_by = u.id";

        $params = [];
        if ($createdBy) {
            $sql .= " WHERE ic.created_by = :created_by";
            $params['created_by'] = $createdBy;
        }

        $sql .= " ORDER BY ic.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Deactivate invite code
     */
    public function deactivateInviteCode($id, $userId = null) {
        $sql = "UPDATE invite_codes SET is_active = 0 WHERE id = :id";
        $params = ['id' => $id];

        if ($userId) {
            $sql .= " AND created_by = :created_by";
            $params['created_by'] = $userId;
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Delete invite code
     */
    public function deleteInviteCode($id, $userId = null) {
        // First delete usage records
        $this->db->query("DELETE FROM invite_code_uses WHERE invite_code_id = :id", ['id' => $id]);

        // Then delete the invite code
        $sql = "DELETE FROM invite_codes WHERE id = :id";
        $params = ['id' => $id];

        if ($userId) {
            $sql .= " AND created_by = :created_by";
            $params['created_by'] = $userId;
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Check if invite code exists
     */
    private function codeExists($code) {
        $result = $this->db->fetchOne("SELECT id FROM invite_codes WHERE code = :code", ['code' => $code]);
        return $result !== false;
    }

    /**
     * Generate random invite code
     */
    private function generateRandomCode($length = 8) {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }

    /**
     * Clean up expired invite codes
     */
    public function cleanupExpiredCodes() {
        $sql = "UPDATE invite_codes SET is_active = 0
                WHERE expires_at < NOW() AND is_active = 1";
        return $this->db->query($sql);
    }

    /**
     * Get invite code statistics
     */
    public function getInviteCodeStats($userId = null) {
        $sql = "SELECT
                COUNT(*) as total_codes,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_codes,
                SUM(used_count) as total_uses,
                COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expired_codes
                FROM invite_codes";

        $params = [];
        if ($userId) {
            $sql .= " WHERE created_by = :created_by";
            $params['created_by'] = $userId;
        }

        return $this->db->fetchOne($sql, $params);
    }
}