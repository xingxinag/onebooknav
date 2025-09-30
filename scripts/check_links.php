<?php
/**
 * OneBookNav 死链检查脚本
 * 定期检查网站链接的可用性
 */

require_once __DIR__ . '/../bootstrap.php';

class LinkChecker {
    private $db;
    private $config;
    private $stats = [
        'checked' => 0,
        'alive' => 0,
        'dead' => 0,
        'timeout' => 0,
        'error' => 0
    ];

    public function __construct() {
        $this->config = require __DIR__ . '/../config/app.php';
        $this->initDatabase();
    }

    private function initDatabase() {
        $dbPath = __DIR__ . '/../data/onebooknav.db';
        try {
            $this->db = new PDO("sqlite:{$dbPath}");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->log("数据库连接失败: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
    }

    /**
     * 检查所有链接
     */
    public function checkAllLinks($options = []) {
        $this->log("开始检查所有链接...");

        $limit = $options['limit'] ?? 0;
        $batchSize = $options['batch_size'] ?? 50;

        try {
            $sql = "SELECT id, title, url, status FROM websites WHERE status != -1";
            if ($limit > 0) {
                $sql .= " LIMIT {$limit}";
            }

            $stmt = $this->db->query($sql);
            $websites = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = count($websites);
            $this->log("共需检查 {$total} 个链接");

            // 分批处理
            $batches = array_chunk($websites, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $this->log("处理批次 " . ($batchIndex + 1) . "/" . count($batches));
                $this->checkBatch($batch, $options);

                // 避免过于频繁的请求
                if ($options['delay'] ?? true) {
                    sleep(1);
                }
            }

            $this->log("链接检查完成");
            $this->printStats();

        } catch (Exception $e) {
            $this->log("链接检查失败: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * 检查单个批次
     */
    private function checkBatch($websites, $options) {
        $multiHandle = curl_multi_init();
        $curlHandles = [];

        // 创建并发请求
        foreach ($websites as $website) {
            $ch = $this->createCurlHandle($website['url'], $options);
            $curlHandles[$website['id']] = [
                'handle' => $ch,
                'website' => $website
            ];
            curl_multi_add_handle($multiHandle, $ch);
        }

        // 执行并发请求
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // 处理结果
        foreach ($curlHandles as $websiteId => $data) {
            $website = $data['website'];
            $ch = $data['handle'];

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);

            $this->processCheckResult($website, $httpCode, $responseTime, $error);

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
    }

    /**
     * 创建 CURL 句柄
     */
    private function createCurlHandle($url, $options) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 10,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 5,
            CURLOPT_USERAGENT => $options['user_agent'] ?? 'OneBookNav/1.0 (+https://github.com/onebooknav)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOBODY => true, // 只检查头部，不下载内容
            CURLOPT_FRESH_CONNECT => true
        ]);

        return $ch;
    }

    /**
     * 处理检查结果
     */
    private function processCheckResult($website, $httpCode, $responseTime, $error) {
        $this->stats['checked']++;

        $status = $this->determineStatus($httpCode, $error);
        $statusText = $this->getStatusText($status, $httpCode, $error);

        // 更新数据库
        $this->updateWebsiteStatus($website['id'], $status, $responseTime, $statusText);

        // 记录日志
        $this->logCheckResult($website, $status, $responseTime, $statusText);

        // 更新统计
        switch ($status) {
            case 1:
                $this->stats['alive']++;
                break;
            case 0:
                $this->stats['dead']++;
                break;
            case -2:
                $this->stats['timeout']++;
                break;
            default:
                $this->stats['error']++;
        }
    }

    /**
     * 确定状态
     */
    private function determineStatus($httpCode, $error) {
        if (!empty($error)) {
            if (strpos($error, 'timeout') !== false) {
                return -2; // 超时
            }
            return 0; // 错误
        }

        if ($httpCode >= 200 && $httpCode < 400) {
            return 1; // 正常
        } elseif ($httpCode >= 400) {
            return 0; // 客户端/服务器错误
        }

        return 0; // 其他情况视为错误
    }

    /**
     * 获取状态文本
     */
    private function getStatusText($status, $httpCode, $error) {
        if (!empty($error)) {
            return $error;
        }

        $statusMessages = [
            200 => 'OK',
            201 => 'Created',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout'
        ];

        return $statusMessages[$httpCode] ?? "HTTP {$httpCode}";
    }

    /**
     * 更新网站状态
     */
    private function updateWebsiteStatus($websiteId, $status, $responseTime, $statusText) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO website_checks
            (website_id, status, response_time, status_text, checked_at)
            VALUES (?, ?, ?, ?, datetime('now'))
        ");

        $stmt->execute([
            $websiteId,
            $status,
            round($responseTime * 1000), // 转换为毫秒
            $statusText
        ]);

        // 更新网站表的状态
        $stmt = $this->db->prepare("
            UPDATE websites
            SET status = ?, last_checked = datetime('now')
            WHERE id = ?
        ");

        $stmt->execute([$status, $websiteId]);
    }

    /**
     * 记录检查结果
     */
    private function logCheckResult($website, $status, $responseTime, $statusText) {
        $statusIcon = [
            1 => '✓',
            0 => '✗',
            -2 => '⏱'
        ][$status] ?? '?';

        $time = round($responseTime * 1000);
        $this->log("{$statusIcon} {$website['title']} ({$time}ms) - {$statusText}");
    }

    /**
     * 检查特定网站
     */
    public function checkWebsite($websiteId) {
        $stmt = $this->db->prepare("SELECT * FROM websites WHERE id = ?");
        $stmt->execute([$websiteId]);
        $website = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$website) {
            throw new Exception("网站不存在: ID {$websiteId}");
        }

        $this->log("检查网站: {$website['title']} - {$website['url']}");

        $ch = $this->createCurlHandle($website['url'], []);
        $result = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = curl_error($ch);

        curl_close($ch);

        $this->processCheckResult($website, $httpCode, $responseTime, $error);

        return [
            'status' => $this->determineStatus($httpCode, $error),
            'http_code' => $httpCode,
            'response_time' => $responseTime,
            'error' => $error
        ];
    }

    /**
     * 获取死链报告
     */
    public function getDeadLinksReport() {
        $stmt = $this->db->query("
            SELECT w.id, w.title, w.url, w.status, w.last_checked,
                   c.name as category_name,
                   wc.status_text, wc.response_time
            FROM websites w
            LEFT JOIN categories c ON w.category_id = c.id
            LEFT JOIN website_checks wc ON w.id = wc.website_id
            WHERE w.status = 0
            ORDER BY w.last_checked DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取检查统计
     */
    public function getCheckStats() {
        $stats = [];

        // 总体统计
        $stmt = $this->db->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as alive,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as dead,
                SUM(CASE WHEN status = -2 THEN 1 ELSE 0 END) as timeout,
                SUM(CASE WHEN last_checked IS NULL THEN 1 ELSE 0 END) as unchecked
            FROM websites
            WHERE status != -1
        ");

        $stats['overall'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // 分类统计
        $stmt = $this->db->query("
            SELECT
                c.name as category,
                COUNT(w.id) as total,
                SUM(CASE WHEN w.status = 1 THEN 1 ELSE 0 END) as alive,
                SUM(CASE WHEN w.status = 0 THEN 1 ELSE 0 END) as dead
            FROM categories c
            LEFT JOIN websites w ON c.id = w.category_id AND w.status != -1
            GROUP BY c.id, c.name
            ORDER BY c.name
        ");

        $stats['by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }

    /**
     * 清理旧的检查记录
     */
    public function cleanupOldChecks($keepDays = 30) {
        $stmt = $this->db->prepare("
            DELETE FROM website_checks
            WHERE checked_at < datetime('now', '-{$keepDays} days')
        ");

        $stmt->execute();
        $removed = $stmt->rowCount();

        $this->log("清理了 {$removed} 条旧检查记录");
    }

    /**
     * 打印统计信息
     */
    private function printStats() {
        $this->log("=== 检查统计 ===");
        $this->log("总检查: {$this->stats['checked']}");
        $this->log("正常: {$this->stats['alive']}");
        $this->log("死链: {$this->stats['dead']}");
        $this->log("超时: {$this->stats['timeout']}");
        $this->log("错误: {$this->stats['error']}");
        $this->log("===============");
    }

    /**
     * 日志记录
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

        echo $logMessage;

        $logFile = __DIR__ . '/../logs/check_links.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// 命令行执行
if (php_sapi_name() === 'cli') {
    $options = getopt('i:l:b:t:c:rs', [
        'id:', 'limit:', 'batch:', 'timeout:', 'cleanup:', 'report', 'stats', 'help'
    ]);

    if (isset($options['help'])) {
        echo "OneBookNav 链接检查工具\n\n";
        echo "使用方法:\n";
        echo "  php check_links.php [选项]\n\n";
        echo "选项:\n";
        echo "  -i, --id            检查特定网站 ID\n";
        echo "  -l, --limit         限制检查数量\n";
        echo "  -b, --batch         批处理大小 (默认: 50)\n";
        echo "  -t, --timeout       超时时间 (默认: 10 秒)\n";
        echo "  -c, --cleanup       清理旧记录 (天数)\n";
        echo "  -r, --report        生成死链报告\n";
        echo "  -s, --stats         显示统计信息\n";
        echo "      --help          显示帮助\n";
        exit(0);
    }

    $checker = new LinkChecker();

    try {
        if (isset($options['i']) || isset($options['id'])) {
            // 检查特定网站
            $websiteId = $options['i'] ?? $options['id'];
            $result = $checker->checkWebsite($websiteId);
            echo "检查结果: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";

        } elseif (isset($options['r']) || isset($options['report'])) {
            // 生成死链报告
            $deadLinks = $checker->getDeadLinksReport();
            echo "死链报告:\n";
            foreach ($deadLinks as $link) {
                echo "- [{$link['category_name']}] {$link['title']} - {$link['url']}\n";
                echo "  状态: {$link['status_text']}, 检查时间: {$link['last_checked']}\n\n";
            }

        } elseif (isset($options['s']) || isset($options['stats'])) {
            // 显示统计信息
            $stats = $checker->getCheckStats();
            echo "整体统计:\n";
            echo "- 总数: {$stats['overall']['total']}\n";
            echo "- 正常: {$stats['overall']['alive']}\n";
            echo "- 死链: {$stats['overall']['dead']}\n";
            echo "- 超时: {$stats['overall']['timeout']}\n";
            echo "- 未检查: {$stats['overall']['unchecked']}\n\n";

            echo "分类统计:\n";
            foreach ($stats['by_category'] as $cat) {
                echo "- {$cat['category']}: {$cat['alive']}/{$cat['total']} 正常\n";
            }

        } elseif (isset($options['c']) || isset($options['cleanup'])) {
            // 清理旧记录
            $days = $options['c'] ?? $options['cleanup'] ?? 30;
            $checker->cleanupOldChecks($days);

        } else {
            // 检查所有链接
            $checkOptions = [
                'limit' => $options['l'] ?? $options['limit'] ?? 0,
                'batch_size' => $options['b'] ?? $options['batch'] ?? 50,
                'timeout' => $options['t'] ?? $options['timeout'] ?? 10
            ];

            $checker->checkAllLinks($checkOptions);
        }

        echo "操作完成!\n";

    } catch (Exception $e) {
        echo "操作失败: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>