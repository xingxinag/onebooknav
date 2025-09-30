<?php

namespace App\Services\Backups;

use Exception;

/**
 * 本地备份驱动
 *
 * 管理本地文件系统中的备份文件
 */
class LocalBackupDriver implements BackupDriverInterface
{
    private string $backupPath;

    public function __construct(string $backupPath)
    {
        $this->backupPath = rtrim($backupPath, '/\\');
        $this->ensureDirectory();
    }

    public function upload(string $localPath, string $remoteName): string
    {
        if (!file_exists($localPath)) {
            throw new Exception("本地文件不存在: {$localPath}");
        }

        $targetPath = $this->getRemotePath($remoteName);
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        if (!copy($localPath, $targetPath)) {
            throw new Exception("复制文件失败: {$localPath} -> {$targetPath}");
        }

        return $targetPath;
    }

    public function download(string $remoteName, string $localPath): bool
    {
        $remotePath = $this->getRemotePath($remoteName);

        if (!file_exists($remotePath)) {
            return false;
        }

        $localDir = dirname($localPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        return copy($remotePath, $localPath);
    }

    public function delete(string $remoteName): bool
    {
        $remotePath = $this->getRemotePath($remoteName);

        if (!file_exists($remotePath)) {
            return true; // 文件已不存在，视为删除成功
        }

        return unlink($remotePath);
    }

    public function list(): array
    {
        $files = [];
        $pattern = $this->backupPath . '/*.{zip,tar,gz,sql}';
        $globFiles = glob($pattern, GLOB_BRACE);

        foreach ($globFiles as $file) {
            if (is_file($file)) {
                $files[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => filemtime($file),
                    'type' => pathinfo($file, PATHINFO_EXTENSION)
                ];
            }
        }

        // 按修改时间降序排列
        usort($files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });

        return $files;
    }

    public function exists(string $remoteName): bool
    {
        $remotePath = $this->getRemotePath($remoteName);
        return file_exists($remotePath);
    }

    public function cleanup(array $retention): int
    {
        $files = $this->list();
        $deletedCount = 0;

        $maxFiles = $retention['max_count'] ?? 10;
        $maxAge = $retention['days'] ?? 30;
        $cutoffTime = time() - ($maxAge * 24 * 3600);

        foreach ($files as $index => $file) {
            $shouldDelete = false;

            // 超过最大文件数量
            if ($index >= $maxFiles) {
                $shouldDelete = true;
            }

            // 超过最大保留时间
            if ($file['modified'] < $cutoffTime) {
                $shouldDelete = true;
            }

            if ($shouldDelete) {
                if ($this->delete($file['name'])) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }

    public function getName(): string
    {
        return 'local';
    }

    public function testConnection(): bool
    {
        return is_dir($this->backupPath) && is_writable($this->backupPath);
    }

    /**
     * 确保备份目录存在
     */
    private function ensureDirectory(): void
    {
        if (!is_dir($this->backupPath)) {
            if (!mkdir($this->backupPath, 0755, true)) {
                throw new Exception("无法创建备份目录: {$this->backupPath}");
            }
        }

        if (!is_writable($this->backupPath)) {
            throw new Exception("备份目录不可写: {$this->backupPath}");
        }

        // 创建 .htaccess 保护文件
        $htaccessPath = $this->backupPath . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "Order Deny,Allow\nDeny from all\n";
            file_put_contents($htaccessPath, $htaccessContent);
        }

        // 创建 index.html 防止目录浏览
        $indexPath = $this->backupPath . '/index.html';
        if (!file_exists($indexPath)) {
            file_put_contents($indexPath, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Forbidden</h1></body></html>');
        }
    }

    /**
     * 获取远程文件的完整路径
     */
    private function getRemotePath(string $remoteName): string
    {
        // 防止路径遍历攻击
        $remoteName = basename($remoteName);
        return $this->backupPath . '/' . $remoteName;
    }

    /**
     * 获取备份统计信息
     */
    public function getStats(): array
    {
        $files = $this->list();
        $totalSize = 0;
        $oldestFile = null;
        $newestFile = null;

        foreach ($files as $file) {
            $totalSize += $file['size'];

            if ($oldestFile === null || $file['modified'] < $oldestFile['modified']) {
                $oldestFile = $file;
            }

            if ($newestFile === null || $file['modified'] > $newestFile['modified']) {
                $newestFile = $file;
            }
        }

        return [
            'total_files' => count($files),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
            'oldest_file' => $oldestFile,
            'newest_file' => $newestFile,
            'disk_space' => [
                'free' => disk_free_space($this->backupPath),
                'total' => disk_total_space($this->backupPath),
                'used' => disk_total_space($this->backupPath) - disk_free_space($this->backupPath)
            ]
        ];
    }

    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2) . ' ' . $units[$unit];
    }
}