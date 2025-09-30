<?php
/**
 * OneBookNav 数据备份脚本
 * 支持自动备份、增量备份、远程备份和压缩加密
 */

require_once __DIR__ . '/../bootstrap.php';

class BackupManager {
    private $config;
    private $db;
    private $backupDir;

    public function __construct() {
        $this->config = require __DIR__ . '/../config/app.php';
        $this->backupDir = __DIR__ . '/../backups';
        $this->ensureBackupDirectory();
        $this->initDatabase();
    }

    private function ensureBackupDirectory() {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
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
     * 执行完整备份
     */
    public function fullBackup($options = []) {
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "onebooknav_full_backup_{$timestamp}";

        $this->log("开始完整备份: {$backupName}");

        try {
            // 1. 数据库备份
            $dbBackupPath = $this->backupDatabase($backupName);

            // 2. 文件备份
            $fileBackupPath = $this->backupFiles($backupName);

            // 3. 创建备份清单
            $manifest = $this->createManifest($backupName, [
                'database' => $dbBackupPath,
                'files' => $fileBackupPath
            ]);

            // 4. 压缩备份
            if ($options['compress'] ?? true) {
                $compressedPath = $this->compressBackup($backupName);
                $this->cleanupTempFiles($backupName);
                $finalPath = $compressedPath;
            } else {
                $finalPath = $this->backupDir . '/' . $backupName;
            }

            // 5. 加密备份（如果启用）
            if ($options['encrypt'] ?? false) {
                $finalPath = $this->encryptBackup($finalPath, $options['password'] ?? '');
            }

            // 6. 远程备份
            if ($options['remote'] ?? false) {
                $this->uploadToRemote($finalPath, $options);
            }

            // 7. 清理旧备份
            $this->cleanupOldBackups($options['keep_days'] ?? 30);

            $this->log("备份完成: {$finalPath}");
            $this->recordBackup($backupName, $finalPath, 'full');

            return $finalPath;

        } catch (Exception $e) {
            $this->log("备份失败: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * 执行增量备份
     */
    public function incrementalBackup($options = []) {
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "onebooknav_incremental_backup_{$timestamp}";

        $this->log("开始增量备份: {$backupName}");

        try {
            $lastBackupTime = $this->getLastBackupTime();

            // 获取变更的数据
            $changes = $this->getDataChanges($lastBackupTime);

            if (empty($changes)) {
                $this->log("没有数据变更，跳过增量备份");
                return null;
            }

            // 创建增量备份
            $backupPath = $this->createIncrementalBackup($backupName, $changes);

            if ($options['compress'] ?? true) {
                $backupPath = $this->compressBackup($backupName);
            }

            $this->log("增量备份完成: {$backupPath}");
            $this->recordBackup($backupName, $backupPath, 'incremental');

            return $backupPath;

        } catch (Exception $e) {
            $this->log("增量备份失败: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * 备份数据库
     */
    private function backupDatabase($backupName) {
        $dbPath = __DIR__ . '/../data/onebooknav.db';
        $backupPath = $this->backupDir . "/{$backupName}_database.db";

        // 使用 SQLite BACKUP API
        $this->db->exec("VACUUM INTO '{$backupPath}'");

        $this->log("数据库备份完成: {$backupPath}");
        return $backupPath;
    }

    /**
     * 备份文件
     */
    private function backupFiles($backupName) {
        $filesToBackup = [
            __DIR__ . '/../public/assets/uploads',
            __DIR__ . '/../config',
            __DIR__ . '/../.env'
        ];

        $backupPath = $this->backupDir . "/{$backupName}_files.tar";

        $tar = new PharData($backupPath);

        foreach ($filesToBackup as $path) {
            if (file_exists($path)) {
                if (is_dir($path)) {
                    $this->addDirectoryToTar($tar, $path, basename($path));
                } else {
                    $tar->addFile($path, basename($path));
                }
            }
        }

        $this->log("文件备份完成: {$backupPath}");
        return $backupPath;
    }

    /**
     * 添加目录到 TAR
     */
    private function addDirectoryToTar($tar, $dir, $baseName) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $relativePath = $baseName . '/' . $iterator->getSubPathName();
            $tar->addFile($file->getPathname(), $relativePath);
        }
    }

    /**
     * 创建备份清单
     */
    private function createManifest($backupName, $files) {
        $manifest = [
            'backup_name' => $backupName,
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'type' => 'full',
            'files' => $files,
            'checksums' => []
        ];

        // 计算文件校验和
        foreach ($files as $type => $path) {
            if (file_exists($path)) {
                $manifest['checksums'][$type] = hash_file('sha256', $path);
            }
        }

        $manifestPath = $this->backupDir . "/{$backupName}_manifest.json";
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));

        return $manifestPath;
    }

    /**
     * 压缩备份
     */
    private function compressBackup($backupName) {
        $backupDir = $this->backupDir . '/' . $backupName;
        $compressedPath = $this->backupDir . "/{$backupName}.tar.gz";

        // 创建临时目录
        mkdir($backupDir, 0755, true);

        // 移动所有相关文件到临时目录
        $pattern = $this->backupDir . "/{$backupName}_*";
        foreach (glob($pattern) as $file) {
            rename($file, $backupDir . '/' . basename($file));
        }

        // 压缩
        $tar = new PharData($compressedPath);
        $tar->buildFromDirectory($backupDir);

        return $compressedPath;
    }

    /**
     * 加密备份
     */
    private function encryptBackup($backupPath, $password) {
        if (empty($password)) {
            $password = $this->generateSecurePassword();
            $this->log("生成的加密密码: {$password}", 'INFO');
        }

        $encryptedPath = $backupPath . '.enc';

        $inputFile = fopen($backupPath, 'rb');
        $outputFile = fopen($encryptedPath, 'wb');

        $key = hash('sha256', $password, true);
        $iv = openssl_random_pseudo_bytes(16);

        fwrite($outputFile, $iv);

        while (!feof($inputFile)) {
            $chunk = fread($inputFile, 8192);
            $encrypted = openssl_encrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            fwrite($outputFile, $encrypted);
        }

        fclose($inputFile);
        fclose($outputFile);

        // 删除未加密的文件
        unlink($backupPath);

        return $encryptedPath;
    }

    /**
     * 上传到远程
     */
    private function uploadToRemote($backupPath, $options) {
        $remoteType = $options['remote_type'] ?? 'webdav';

        switch ($remoteType) {
            case 'webdav':
                $this->uploadToWebDAV($backupPath, $options);
                break;
            case 'ftp':
                $this->uploadToFTP($backupPath, $options);
                break;
            case 's3':
                $this->uploadToS3($backupPath, $options);
                break;
            default:
                throw new Exception("不支持的远程存储类型: {$remoteType}");
        }
    }

    /**
     * WebDAV 上传
     */
    private function uploadToWebDAV($backupPath, $options) {
        $url = $options['webdav_url'];
        $username = $options['webdav_username'];
        $password = $options['webdav_password'];

        $remotePath = $url . '/' . basename($backupPath);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $remotePath,
            CURLOPT_USERPWD => "{$username}:{$password}",
            CURLOPT_PUT => true,
            CURLOPT_INFILE => fopen($backupPath, 'rb'),
            CURLOPT_INFILESIZE => filesize($backupPath),
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_RETURNTRANSFER => true
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("WebDAV 上传失败，HTTP 代码: {$httpCode}");
        }

        $this->log("备份已上传到 WebDAV: {$remotePath}");
    }

    /**
     * 获取数据变更
     */
    private function getDataChanges($since) {
        $changes = [];

        $tables = ['users', 'categories', 'websites', 'audit_logs'];

        foreach ($tables as $table) {
            $stmt = $this->db->prepare("
                SELECT * FROM {$table}
                WHERE updated_at > ? OR created_at > ?
            ");
            $stmt->execute([$since, $since]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $changes[$table] = $rows;
            }
        }

        return $changes;
    }

    /**
     * 创建增量备份
     */
    private function createIncrementalBackup($backupName, $changes) {
        $backupPath = $this->backupDir . "/{$backupName}.json";

        $backupData = [
            'type' => 'incremental',
            'timestamp' => date('c'),
            'changes' => $changes
        ];

        file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

        return $backupPath;
    }

    /**
     * 获取最后备份时间
     */
    private function getLastBackupTime() {
        $stmt = $this->db->prepare("
            SELECT MAX(created_at) FROM backup_logs WHERE status = 'completed'
        ");
        $stmt->execute();

        return $stmt->fetchColumn() ?: '1970-01-01 00:00:00';
    }

    /**
     * 记录备份信息
     */
    private function recordBackup($name, $path, $type) {
        $stmt = $this->db->prepare("
            INSERT INTO backup_logs
            (name, path, type, size, status, created_at)
            VALUES (?, ?, ?, ?, 'completed', datetime('now'))
        ");

        $size = file_exists($path) ? filesize($path) : 0;
        $stmt->execute([$name, $path, $type, $size]);
    }

    /**
     * 清理旧备份
     */
    private function cleanupOldBackups($keepDays) {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));

        $stmt = $this->db->prepare("
            SELECT path FROM backup_logs
            WHERE created_at < ? AND status = 'completed'
        ");
        $stmt->execute([$cutoffDate]);

        $oldBackups = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($oldBackups as $backupPath) {
            if (file_exists($backupPath)) {
                unlink($backupPath);
                $this->log("删除旧备份: {$backupPath}");
            }
        }

        // 删除数据库记录
        $stmt = $this->db->prepare("
            DELETE FROM backup_logs
            WHERE created_at < ? AND status = 'completed'
        ");
        $stmt->execute([$cutoffDate]);
    }

    /**
     * 清理临时文件
     */
    private function cleanupTempFiles($backupName) {
        $pattern = $this->backupDir . "/{$backupName}_*";
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $tempDir = $this->backupDir . '/' . $backupName;
        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * 删除目录
     */
    private function removeDirectory($dir) {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * 生成安全密码
     */
    private function generateSecurePassword($length = 32) {
        return bin2hex(openssl_random_pseudo_bytes($length / 2));
    }

    /**
     * 日志记录
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

        echo $logMessage;

        $logFile = __DIR__ . '/../logs/backup.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// 命令行执行
if (php_sapi_name() === 'cli') {
    $options = getopt('t:c:e:r:k:', [
        'type:', 'compress::', 'encrypt::', 'remote::', 'keep-days:', 'help'
    ]);

    if (isset($options['help'])) {
        echo "OneBookNav 备份工具\n\n";
        echo "使用方法:\n";
        echo "  php backup.php [选项]\n\n";
        echo "选项:\n";
        echo "  -t, --type          备份类型 (full|incremental)\n";
        echo "  -c, --compress      启用压缩\n";
        echo "  -e, --encrypt       启用加密\n";
        echo "  -r, --remote        远程备份\n";
        echo "  -k, --keep-days     保留天数 (默认: 30)\n";
        echo "      --help          显示帮助\n";
        exit(0);
    }

    $backup = new BackupManager();

    $backupOptions = [
        'compress' => isset($options['c']) || isset($options['compress']),
        'encrypt' => isset($options['e']) || isset($options['encrypt']),
        'remote' => isset($options['r']) || isset($options['remote']),
        'keep_days' => $options['k'] ?? $options['keep-days'] ?? 30
    ];

    $type = $options['t'] ?? $options['type'] ?? 'full';

    try {
        if ($type === 'incremental') {
            $backup->incrementalBackup($backupOptions);
        } else {
            $backup->fullBackup($backupOptions);
        }

        echo "备份操作完成!\n";
    } catch (Exception $e) {
        echo "备份失败: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>