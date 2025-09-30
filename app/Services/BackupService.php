<?php

namespace App\Services;

use App\Core\Container;
use App\Services\Backups\LocalBackupDriver;
use App\Services\Backups\WebDAVBackupDriver;
use Exception;
use ZipArchive;

/**
 * 备份服务类
 *
 * 实现"终极.txt"要求的完整备份和恢复系统
 * 支持WebDAV、本地存储、自动备份等多种方式
 */
class BackupService
{
    private static $instance = null;
    private DatabaseService $database;
    private ConfigService $config;
    private SecurityService $security;
    private string $backupPath;
    private array $drivers = [];

    private function __construct()
    {
        $container = Container::getInstance();
        $this->database = $container->get('database');
        $this->config = $container->get('config');
        $this->security = $container->get('security');

        $this->backupPath = ROOT_PATH . '/backups';
        $this->ensureBackupDirectory();
        $this->initializeDrivers();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 确保备份目录存在
     */
    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0750, true);
        }

        // 创建.htaccess保护备份目录
        $htaccessFile = $this->backupPath . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Order Deny,Allow\nDeny from all\n");
        }
    }

    /**
     * 初始化备份驱动
     */
    private function initializeDrivers(): void
    {
        // 本地存储驱动
        $this->drivers['local'] = new LocalBackupDriver($this->backupPath);

        // WebDAV驱动
        if ($this->config->get('webdav.enabled', false)) {
            $this->drivers['webdav'] = new WebDAVBackupDriver([
                'url' => $this->config->get('webdav.url'),
                'username' => $this->config->get('webdav.username'),
                'password' => $this->config->get('webdav.password'),
                'remote_path' => $this->config->get('webdav.remote_path', '/onebooknav-backups/')
            ]);
        }

        // TODO: FTP驱动和云存储驱动可在后续版本中实现
    }

    /**
     * 创建完整备份
     */
    public function createFullBackup(array $options = []): array
    {
        $backupId = uniqid('backup_');
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "onebooknav_full_{$timestamp}_{$backupId}.zip";
        $localPath = $this->backupPath . '/' . $filename;

        try {
            $this->logBackupStart($backupId, 'full', $options);

            // 创建ZIP文件
            $zip = new ZipArchive();
            if ($zip->open($localPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception('无法创建备份文件');
            }

            // 备份数据库
            $this->addDatabaseToZip($zip, $options);

            // 备份配置文件
            $this->addConfigToZip($zip, $options);

            // 备份用户上传文件
            $this->addUploadsToZip($zip, $options);

            // 备份主题文件（如果自定义）
            $this->addThemesToZip($zip, $options);

            // 添加元数据
            $this->addMetadataToZip($zip, $backupId, $options);

            $zip->close();

            // 验证备份文件
            $this->validateBackupFile($localPath);

            // 记录备份信息
            $backupInfo = [
                'id' => $backupId,
                'filename' => $filename,
                'local_path' => $localPath,
                'size' => filesize($localPath),
                'type' => 'full',
                'created_at' => date('Y-m-d H:i:s'),
                'checksum' => hash_file('sha256', $localPath)
            ];

            $this->saveBackupRecord($backupInfo);

            // 上传到远程存储
            $uploadResults = $this->uploadToRemoteStorages($localPath, $filename, $options);
            $backupInfo['remote_locations'] = $uploadResults;

            // 清理旧备份
            if ($options['cleanup_old'] ?? true) {
                $this->cleanupOldBackups($options);
            }

            $this->logBackupComplete($backupId, $backupInfo);

            return $backupInfo;

        } catch (Exception $e) {
            $this->logBackupError($backupId, $e);

            // 清理失败的备份文件
            if (file_exists($localPath)) {
                unlink($localPath);
            }

            throw $e;
        }
    }

    /**
     * 创建增量备份
     */
    public function createIncrementalBackup(array $options = []): array
    {
        $lastBackup = $this->getLastBackup();
        if (!$lastBackup) {
            // 如果没有上次备份，创建完整备份
            return $this->createFullBackup($options);
        }

        $backupId = uniqid('backup_inc_');
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "onebooknav_inc_{$timestamp}_{$backupId}.zip";
        $localPath = $this->backupPath . '/' . $filename;

        try {
            $this->logBackupStart($backupId, 'incremental', $options);

            $lastBackupTime = $lastBackup['created_at'];

            // 创建ZIP文件
            $zip = new ZipArchive();
            if ($zip->open($localPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception('无法创建备份文件');
            }

            // 备份变更的数据
            $this->addChangedDataToZip($zip, $lastBackupTime, $options);

            // 备份变更的文件
            $this->addChangedFilesToZip($zip, $lastBackupTime, $options);

            // 添加增量备份元数据
            $metadata = [
                'type' => 'incremental',
                'base_backup_id' => $lastBackup['id'],
                'since' => $lastBackupTime
            ];
            $this->addMetadataToZip($zip, $backupId, array_merge($options, $metadata));

            $zip->close();

            // 验证备份文件
            $this->validateBackupFile($localPath);

            $backupInfo = [
                'id' => $backupId,
                'filename' => $filename,
                'local_path' => $localPath,
                'size' => filesize($localPath),
                'type' => 'incremental',
                'base_backup_id' => $lastBackup['id'],
                'created_at' => date('Y-m-d H:i:s'),
                'checksum' => hash_file('sha256', $localPath)
            ];

            $this->saveBackupRecord($backupInfo);

            // 上传到远程存储
            $uploadResults = $this->uploadToRemoteStorages($localPath, $filename, $options);
            $backupInfo['remote_locations'] = $uploadResults;

            $this->logBackupComplete($backupId, $backupInfo);

            return $backupInfo;

        } catch (Exception $e) {
            $this->logBackupError($backupId, $e);

            if (file_exists($localPath)) {
                unlink($localPath);
            }

            throw $e;
        }
    }

    /**
     * 恢复备份
     */
    public function restoreBackup(string $backupId, array $options = []): bool
    {
        try {
            $backup = $this->getBackupInfo($backupId);
            if (!$backup) {
                throw new Exception('备份不存在');
            }

            $this->logRestoreStart($backupId, $options);

            // 下载备份文件（如果需要）
            $backupPath = $this->ensureBackupFileLocal($backup);

            // 创建恢复点
            if ($options['create_restore_point'] ?? true) {
                $restorePoint = $this->createRestorePoint();
                $options['restore_point'] = $restorePoint;
            }

            // 验证备份文件
            $this->validateBackupFile($backupPath);

            // 解压备份文件
            $extractPath = $this->extractBackupFile($backupPath);

            try {
                // 恢复数据库
                if ($options['restore_database'] ?? true) {
                    $this->restoreDatabase($extractPath, $options);
                }

                // 恢复配置文件
                if ($options['restore_config'] ?? true) {
                    $this->restoreConfig($extractPath, $options);
                }

                // 恢复文件
                if ($options['restore_files'] ?? true) {
                    $this->restoreFiles($extractPath, $options);
                }

                // 恢复主题
                if ($options['restore_themes'] ?? true) {
                    $this->restoreThemes($extractPath, $options);
                }

                // 清理临时文件
                $this->cleanupTempFiles($extractPath);

                $this->logRestoreComplete($backupId);

                return true;

            } catch (Exception $e) {
                // 恢复失败，尝试回滚到恢复点
                if (isset($options['restore_point'])) {
                    $this->rollbackToRestorePoint($options['restore_point']);
                }

                throw $e;
            }

        } catch (Exception $e) {
            $this->logRestoreError($backupId, $e);
            throw $e;
        }
    }

    /**
     * 迁移数据到新系统
     */
    public function migrateToNewSystem(array $config): array
    {
        $migrationId = uniqid('migration_');

        try {
            $this->logMigrationStart($migrationId, $config);

            $result = [
                'id' => $migrationId,
                'steps' => [],
                'success' => false,
                'errors' => []
            ];

            // 步骤1: 导出当前数据
            $exportResult = $this->exportForMigration($config);
            $result['steps']['export'] = $exportResult;

            // 步骤2: 准备目标系统
            if ($config['prepare_target'] ?? true) {
                $prepareResult = $this->prepareTargetSystem($config);
                $result['steps']['prepare'] = $prepareResult;
            }

            // 步骤3: 传输数据
            $transferResult = $this->transferMigrationData($exportResult, $config);
            $result['steps']['transfer'] = $transferResult;

            // 步骤4: 验证迁移
            if ($config['verify_migration'] ?? true) {
                $verifyResult = $this->verifyMigration($config);
                $result['steps']['verify'] = $verifyResult;
            }

            // 步骤5: 清理
            if ($config['cleanup_source'] ?? false) {
                $cleanupResult = $this->cleanupSourceData($config);
                $result['steps']['cleanup'] = $cleanupResult;
            }

            $result['success'] = true;
            $this->logMigrationComplete($migrationId, $result);

            return $result;

        } catch (Exception $e) {
            $this->logMigrationError($migrationId, $e);
            throw $e;
        }
    }

    /**
     * 添加数据库到ZIP
     */
    private function addDatabaseToZip(ZipArchive $zip, array $options): void
    {
        $databasePath = $this->exportDatabase($options);
        $zip->addFile($databasePath, 'database/database.sql');

        // 添加数据库结构
        $schemaPath = $this->exportDatabaseSchema();
        $zip->addFile($schemaPath, 'database/schema.sql');
    }

    /**
     * 导出数据库
     */
    private function exportDatabase(array $options): string
    {
        $tempFile = tempnam($this->backupPath, 'db_export_');
        $excludeTables = $options['exclude_tables'] ?? ['audit_logs', 'click_logs'];

        try {
            $handle = fopen($tempFile, 'w');

            // 写入头部信息
            fwrite($handle, "-- OneBookNav Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Version: " . $this->config->get('app.version', '1.0.0') . "\n\n");

            // 禁用外键检查
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

            // 获取所有表
            $tables = $this->database->query("SHOW TABLES")->fetchAll();

            foreach ($tables as $table) {
                $tableName = reset($table);

                if (in_array($tableName, $excludeTables)) {
                    continue;
                }

                // 导出表结构
                $createTableResult = $this->database->query("SHOW CREATE TABLE `{$tableName}`")->fetch();
                $createTableSql = $createTableResult['Create Table'];

                fwrite($handle, "-- Table structure for `{$tableName}`\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");
                fwrite($handle, $createTableSql . ";\n\n");

                // 导出表数据
                $rows = $this->database->query("SELECT * FROM `{$tableName}`")->fetchAll();

                if (!empty($rows)) {
                    fwrite($handle, "-- Data for table `{$tableName}`\n");
                    fwrite($handle, "LOCK TABLES `{$tableName}` WRITE;\n");

                    $columns = array_keys($rows[0]);
                    $columnsStr = '`' . implode('`, `', $columns) . '`';

                    foreach (array_chunk($rows, 1000) as $chunk) {
                        $values = [];
                        foreach ($chunk as $row) {
                            $escapedValues = array_map(function($value) {
                                return $value === null ? 'NULL' : "'" . addslashes($value) . "'";
                            }, array_values($row));
                            $values[] = '(' . implode(', ', $escapedValues) . ')';
                        }

                        $sql = "INSERT INTO `{$tableName}` ({$columnsStr}) VALUES " . implode(', ', $values) . ";\n";
                        fwrite($handle, $sql);
                    }

                    fwrite($handle, "UNLOCK TABLES;\n\n");
                }
            }

            // 恢复外键检查
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");

            fclose($handle);

            return $tempFile;

        } catch (Exception $e) {
            fclose($handle);
            unlink($tempFile);
            throw $e;
        }
    }

    /**
     * 导出数据库结构
     */
    private function exportDatabaseSchema(): string
    {
        $schemaFile = ROOT_PATH . '/database/schema.sql';
        if (file_exists($schemaFile)) {
            return $schemaFile;
        }

        // 如果schema.sql不存在，动态生成
        $tempFile = tempnam($this->backupPath, 'schema_');
        $handle = fopen($tempFile, 'w');

        fwrite($handle, "-- OneBookNav Database Schema\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");

        $tables = $this->database->query("SHOW TABLES")->fetchAll();

        foreach ($tables as $table) {
            $tableName = reset($table);
            $createTableResult = $this->database->query("SHOW CREATE TABLE `{$tableName}`")->fetch();
            $createTableSql = $createTableResult['Create Table'];

            fwrite($handle, "-- Table: {$tableName}\n");
            fwrite($handle, $createTableSql . ";\n\n");
        }

        fclose($handle);

        return $tempFile;
    }

    /**
     * 添加配置到ZIP
     */
    private function addConfigToZip(ZipArchive $zip, array $options): void
    {
        // 导出配置（排除敏感信息）
        $config = $this->config->export(false);
        $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $zip->addFromString('config/settings.json', $configJson);

        // 备份.env文件（如果存在且包含非敏感信息）
        $envFile = ROOT_PATH . '/.env';
        if (file_exists($envFile) && ($options['include_env'] ?? false)) {
            $envContent = $this->sanitizeEnvFile($envFile);
            $zip->addFromString('config/.env', $envContent);
        }
    }

    /**
     * 清理.env文件敏感信息
     */
    private function sanitizeEnvFile(string $envFile): string
    {
        $content = file_get_contents($envFile);
        $lines = explode("\n", $content);
        $sanitized = [];

        $sensitiveKeys = [
            'SECRET_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD',
            'WEBDAV_PASSWORD', 'AI_API_KEY', 'ADMIN_PASSWORD'
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                $sanitized[] = $line;
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);

                if (in_array($key, $sensitiveKeys)) {
                    $sanitized[] = $key . '=***';
                } else {
                    $sanitized[] = $line;
                }
            } else {
                $sanitized[] = $line;
            }
        }

        return implode("\n", $sanitized);
    }

    /**
     * 添加上传文件到ZIP
     */
    private function addUploadsToZip(ZipArchive $zip, array $options): void
    {
        $uploadsPath = PUBLIC_PATH . '/uploads';
        if (is_dir($uploadsPath)) {
            $this->addDirectoryToZip($zip, $uploadsPath, 'uploads/');
        }

        // 备份头像文件
        $avatarsPath = PUBLIC_PATH . '/avatars';
        if (is_dir($avatarsPath)) {
            $this->addDirectoryToZip($zip, $avatarsPath, 'avatars/');
        }

        // 备份favicon文件
        $faviconsPath = DATA_PATH . '/favicons';
        if (is_dir($faviconsPath)) {
            $this->addDirectoryToZip($zip, $faviconsPath, 'favicons/');
        }
    }

    /**
     * 添加主题到ZIP
     */
    private function addThemesToZip(ZipArchive $zip, array $options): void
    {
        $themesPath = ROOT_PATH . '/themes';
        if (is_dir($themesPath)) {
            $this->addDirectoryToZip($zip, $themesPath, 'themes/');
        }

        // 备份自定义CSS
        $customCssPath = PUBLIC_PATH . '/assets/css/custom.css';
        if (file_exists($customCssPath)) {
            $zip->addFile($customCssPath, 'assets/css/custom.css');
        }
    }

    /**
     * 添加目录到ZIP
     */
    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = $zipPath . substr($filePath, strlen($dir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    /**
     * 添加元数据到ZIP
     */
    private function addMetadataToZip(ZipArchive $zip, string $backupId, array $options): void
    {
        $metadata = [
            'backup_id' => $backupId,
            'version' => $this->config->get('app.version', '1.0.0'),
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => 'OneBookNav Backup Service',
            'type' => $options['type'] ?? 'full',
            'options' => $options,
            'system_info' => [
                'php_version' => PHP_VERSION,
                'platform' => PHP_OS,
                'deployment_method' => $this->config->get('deployment.method')
            ]
        ];

        $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $zip->addFromString('metadata.json', $metadataJson);

        // 添加校验文件
        $zip->addFromString('backup.info', "OneBookNav Backup\nID: {$backupId}\nCreated: " . date('Y-m-d H:i:s'));
    }

    /**
     * 验证备份文件
     */
    private function validateBackupFile(string $backupPath): void
    {
        if (!file_exists($backupPath)) {
            throw new Exception('备份文件不存在');
        }

        if (filesize($backupPath) === 0) {
            throw new Exception('备份文件为空');
        }

        // 验证ZIP文件完整性
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CHECKCONS) !== TRUE) {
            throw new Exception('备份文件损坏');
        }

        // 检查必要的文件
        $requiredFiles = ['metadata.json', 'backup.info'];
        foreach ($requiredFiles as $file) {
            if ($zip->locateName($file) === false) {
                $zip->close();
                throw new Exception("备份文件缺少必要文件: {$file}");
            }
        }

        $zip->close();
    }

    /**
     * 保存备份记录
     */
    private function saveBackupRecord(array $backupInfo): void
    {
        $this->database->insert('backup_logs', [
            'backup_id' => $backupInfo['id'],
            'filename' => $backupInfo['filename'],
            'file_size' => $backupInfo['size'],
            'type' => $backupInfo['type'],
            'status' => 'success',
            'checksum' => $backupInfo['checksum'],
            'created_at' => $backupInfo['created_at']
        ]);
    }

    /**
     * 上传到远程存储
     */
    private function uploadToRemoteStorages(string $localPath, string $filename, array $options): array
    {
        $results = [];
        $enabledDrivers = $options['remote_storages'] ?? array_keys($this->drivers);

        foreach ($enabledDrivers as $driverName) {
            if (!isset($this->drivers[$driverName]) || $driverName === 'local') {
                continue;
            }

            try {
                $driver = $this->drivers[$driverName];
                $remoteUrl = $driver->upload($localPath, $filename);

                $results[$driverName] = [
                    'success' => true,
                    'url' => $remoteUrl,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];

            } catch (Exception $e) {
                $results[$driverName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                error_log("Failed to upload backup to {$driverName}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * 清理旧备份
     */
    private function cleanupOldBackups(array $options): void
    {
        $retention = $options['retention'] ?? [
            'days' => 30,
            'max_count' => 10
        ];

        // 清理本地旧备份
        $this->cleanupLocalBackups($retention);

        // 清理远程旧备份
        foreach ($this->drivers as $driverName => $driver) {
            if ($driverName === 'local') {
                continue;
            }

            try {
                $driver->cleanup($retention);
            } catch (Exception $e) {
                error_log("Failed to cleanup remote backups on {$driverName}: " . $e->getMessage());
            }
        }
    }

    /**
     * 清理本地备份
     */
    private function cleanupLocalBackups(array $retention): void
    {
        $files = glob($this->backupPath . '/onebooknav_*.zip');

        // 按修改时间排序（最新的在前）
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $deletedCount = 0;
        $cutoffTime = time() - ($retention['days'] * 24 * 3600);
        $maxCount = $retention['max_count'] ?? 10;

        foreach ($files as $index => $file) {
            $shouldDelete = false;

            // 超过最大数量限制
            if ($index >= $maxCount) {
                $shouldDelete = true;
            }

            // 超过时间限制
            if (filemtime($file) < $cutoffTime) {
                $shouldDelete = true;
            }

            if ($shouldDelete) {
                unlink($file);
                $deletedCount++;

                // 从数据库删除记录
                $filename = basename($file);
                $this->database->delete('backup_logs', 'filename = ?', [$filename]);
            }
        }

        if ($deletedCount > 0) {
            error_log("Cleaned up {$deletedCount} old backup files");
        }
    }

    /**
     * 获取备份列表
     */
    public function getBackupList(array $options = []): array
    {
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 20;
        $type = $options['type'] ?? null;

        $where = '1=1';
        $params = [];

        if ($type) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }

        return $this->database->paginate(
            'backup_logs',
            $page,
            $perPage,
            $where,
            $params,
            'created_at DESC'
        );
    }

    /**
     * 获取备份信息
     */
    public function getBackupInfo(string $backupId): ?array
    {
        return $this->database->query(
            "SELECT * FROM backup_logs WHERE backup_id = ?",
            [$backupId]
        )->fetch();
    }

    /**
     * 删除备份
     */
    public function deleteBackup(string $backupId): bool
    {
        try {
            $backup = $this->getBackupInfo($backupId);
            if (!$backup) {
                throw new Exception('备份不存在');
            }

            // 删除本地文件
            $localPath = $this->backupPath . '/' . $backup['filename'];
            if (file_exists($localPath)) {
                unlink($localPath);
            }

            // 删除远程文件
            foreach ($this->drivers as $driverName => $driver) {
                if ($driverName === 'local') {
                    continue;
                }

                try {
                    $driver->delete($backup['filename']);
                } catch (Exception $e) {
                    error_log("Failed to delete remote backup on {$driverName}: " . $e->getMessage());
                }
            }

            // 删除数据库记录
            $this->database->delete('backup_logs', 'backup_id = ?', [$backupId]);

            return true;

        } catch (Exception $e) {
            error_log("Failed to delete backup {$backupId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取最后一次备份
     */
    private function getLastBackup(): ?array
    {
        return $this->database->query(
            "SELECT * FROM backup_logs WHERE status = 'success' ORDER BY created_at DESC LIMIT 1"
        )->fetch();
    }

    /**
     * 记录备份日志
     */
    private function logBackupStart(string $backupId, string $type, array $options): void
    {
        error_log("Starting {$type} backup: {$backupId}");
    }

    private function logBackupComplete(string $backupId, array $backupInfo): void
    {
        $size = number_format($backupInfo['size'] / 1024 / 1024, 2);
        error_log("Backup completed: {$backupId} ({$size} MB)");
    }

    private function logBackupError(string $backupId, Exception $e): void
    {
        error_log("Backup failed: {$backupId} - " . $e->getMessage());
    }

    private function logRestoreStart(string $backupId, array $options): void
    {
        error_log("Starting restore: {$backupId}");
    }

    private function logRestoreComplete(string $backupId): void
    {
        error_log("Restore completed: {$backupId}");
    }

    private function logRestoreError(string $backupId, Exception $e): void
    {
        error_log("Restore failed: {$backupId} - " . $e->getMessage());
    }

    private function logMigrationStart(string $migrationId, array $config): void
    {
        error_log("Starting migration: {$migrationId}");
    }

    private function logMigrationComplete(string $migrationId, array $result): void
    {
        error_log("Migration completed: {$migrationId}");
    }

    private function logMigrationError(string $migrationId, Exception $e): void
    {
        error_log("Migration failed: {$migrationId} - " . $e->getMessage());
    }
}