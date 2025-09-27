<?php
/**
 * Dead Link Checker - merged from BookNav
 * Automated dead link detection and management
 */

class DeadLinkChecker {
    private $db;
    private $timeout = 10;
    private $userAgent = 'OneBookNav/1.0 (Dead Link Checker)';

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Check a single bookmark for dead links
     */
    public function checkBookmark($bookmarkId) {
        $bookmark = $this->db->fetchOne("SELECT * FROM bookmarks WHERE id = :id", ['id' => $bookmarkId]);
        if (!$bookmark) {
            throw new Exception("Bookmark not found");
        }

        $status = $this->checkUrl($bookmark['url']);
        $backupStatus = null;

        // Check backup URL if exists
        if (!empty($bookmark['backup_url'])) {
            $backupStatus = $this->checkUrl($bookmark['backup_url']);
        }

        // Update bookmark status
        $this->updateBookmarkStatus($bookmarkId, $status, $backupStatus);

        // Log the check
        $this->logLinkCheck($bookmarkId, $status, $backupStatus);

        return [
            'bookmark_id' => $bookmarkId,
            'main_status' => $status,
            'backup_status' => $backupStatus,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Check multiple bookmarks (batch processing)
     */
    public function checkMultipleBookmarks($bookmarkIds, $callback = null) {
        $results = [];
        $total = count($bookmarkIds);

        foreach ($bookmarkIds as $index => $bookmarkId) {
            try {
                $result = $this->checkBookmark($bookmarkId);
                $results[] = $result;

                // Call progress callback if provided
                if ($callback && is_callable($callback)) {
                    $progress = ($index + 1) / $total * 100;
                    $callback($progress, $result);
                }

                // Small delay to avoid overwhelming servers
                usleep(500000); // 0.5 second delay

            } catch (Exception $e) {
                $results[] = [
                    'bookmark_id' => $bookmarkId,
                    'error' => $e->getMessage(),
                    'checked_at' => date('Y-m-d H:i:s')
                ];
            }
        }

        return $results;
    }

    /**
     * Check all bookmarks for a user
     */
    public function checkAllUserBookmarks($userId, $callback = null) {
        $bookmarks = $this->db->query("SELECT id FROM bookmarks WHERE user_id = :user_id OR is_private = 0", [
            'user_id' => $userId
        ]);

        $bookmarkIds = array_column($bookmarks, 'id');
        return $this->checkMultipleBookmarks($bookmarkIds, $callback);
    }

    /**
     * Get dead links report
     */
    public function getDeadLinksReport($userId = null) {
        $sql = "SELECT b.*, c.name as category_name,
                dlc.main_status, dlc.backup_status, dlc.last_checked
                FROM bookmarks b
                LEFT JOIN categories c ON b.category_id = c.id
                LEFT JOIN (
                    SELECT bookmark_id, main_status, backup_status, last_checked
                    FROM dead_link_checks dlc1
                    WHERE dlc1.checked_at = (
                        SELECT MAX(dlc2.checked_at)
                        FROM dead_link_checks dlc2
                        WHERE dlc2.bookmark_id = dlc1.bookmark_id
                    )
                ) dlc ON b.id = dlc.bookmark_id
                WHERE (dlc.main_status >= 400 OR dlc.main_status = 0)";

        $params = [];
        if ($userId) {
            $sql .= " AND (b.user_id = :user_id OR b.is_private = 0)";
            $params['user_id'] = $userId;
        }

        $sql .= " ORDER BY dlc.last_checked DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Get link check history for a bookmark
     */
    public function getBookmarkCheckHistory($bookmarkId, $limit = 10) {
        $sql = "SELECT * FROM dead_link_checks
                WHERE bookmark_id = :bookmark_id
                ORDER BY checked_at DESC
                LIMIT :limit";

        return $this->db->query($sql, [
            'bookmark_id' => $bookmarkId,
            'limit' => $limit
        ]);
    }

    /**
     * Schedule automatic dead link checking
     */
    public function scheduleAutomaticCheck($userId = null, $interval = 'weekly') {
        $nextCheck = match($interval) {
            'daily' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'weekly' => date('Y-m-d H:i:s', strtotime('+1 week')),
            'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
            default => date('Y-m-d H:i:s', strtotime('+1 week'))
        };

        $sql = "INSERT INTO scheduled_checks (user_id, check_type, next_check_at, interval_type, created_at)
                VALUES (:user_id, 'dead_link', :next_check_at, :interval_type, NOW())
                ON DUPLICATE KEY UPDATE
                next_check_at = :next_check_at,
                interval_type = :interval_type";

        return $this->db->query($sql, [
            'user_id' => $userId,
            'next_check_at' => $nextCheck,
            'interval_type' => $interval
        ]);
    }

    /**
     * Check URL status
     */
    private function checkUrl($url) {
        if (empty($url)) {
            return 0;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_NOBODY => true, // HEAD request only
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return 0; // Connection error
        }

        return $httpCode;
    }

    /**
     * Update bookmark status in database
     */
    private function updateBookmarkStatus($bookmarkId, $mainStatus, $backupStatus = null) {
        $isWorking = ($mainStatus >= 200 && $mainStatus < 400) ||
                    ($backupStatus && $backupStatus >= 200 && $backupStatus < 400);

        $sql = "UPDATE bookmarks SET
                main_url_status = :main_status,
                backup_url_status = :backup_status,
                is_working = :is_working,
                last_checked = NOW()
                WHERE id = :id";

        return $this->db->query($sql, [
            'main_status' => $mainStatus,
            'backup_status' => $backupStatus,
            'is_working' => $isWorking ? 1 : 0,
            'id' => $bookmarkId
        ]);
    }

    /**
     * Log link check result
     */
    private function logLinkCheck($bookmarkId, $mainStatus, $backupStatus = null) {
        $sql = "INSERT INTO dead_link_checks (bookmark_id, main_status, backup_status, checked_at)
                VALUES (:bookmark_id, :main_status, :backup_status, NOW())";

        return $this->db->query($sql, [
            'bookmark_id' => $bookmarkId,
            'main_status' => $mainStatus,
            'backup_status' => $backupStatus
        ]);
    }

    /**
     * Clean up old check logs
     */
    public function cleanupOldLogs($daysToKeep = 30) {
        $sql = "DELETE FROM dead_link_checks
                WHERE checked_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        return $this->db->query($sql, ['days' => $daysToKeep]);
    }

    /**
     * Get dead link statistics
     */
    public function getDeadLinkStats($userId = null) {
        $sql = "SELECT
                COUNT(*) as total_bookmarks,
                SUM(CASE WHEN is_working = 0 THEN 1 ELSE 0 END) as dead_links,
                SUM(CASE WHEN is_working = 1 THEN 1 ELSE 0 END) as working_links,
                SUM(CASE WHEN last_checked IS NULL THEN 1 ELSE 0 END) as unchecked_links
                FROM bookmarks";

        $params = [];
        if ($userId) {
            $sql .= " WHERE user_id = :user_id OR is_private = 0";
            $params['user_id'] = $userId;
        }

        return $this->db->fetchOne($sql, $params);
    }
}