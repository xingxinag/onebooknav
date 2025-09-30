<?php

namespace App\Services\Backups;

use Exception;

/**
 * WebDAV 备份驱动
 *
 * 支持将备份文件上传到 WebDAV 服务器
 * 兼容 NextCloud、ownCloud、坚果云等 WebDAV 服务
 */
class WebDAVBackupDriver implements BackupDriverInterface
{
    private string $url;
    private string $username;
    private string $password;
    private string $remotePath;
    private $curl;

    public function __construct(array $config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->remotePath = trim($config['remote_path'] ?? '/onebooknav-backups/', '/');

        $this->initializeCurl();
        $this->ensureRemoteDirectory();
    }

    public function upload(string $localPath, string $remoteName): string
    {
        if (!file_exists($localPath)) {
            throw new Exception("本地文件不存在: {$localPath}");
        }

        $remoteUrl = $this->getRemoteUrl($remoteName);
        $fileHandle = fopen($localPath, 'rb');

        if (!$fileHandle) {
            throw new Exception("无法打开本地文件: {$localPath}");
        }

        try {
            curl_setopt_array($this->curl, [
                CURLOPT_URL => $remoteUrl,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fileHandle,
                CURLOPT_INFILESIZE => filesize($localPath),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream'
                ]
            ]);

            $response = curl_exec($this->curl);
            $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

            if ($response === false) {
                throw new Exception('WebDAV 上传失败: ' . curl_error($this->curl));
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new Exception("WebDAV 上传失败，HTTP 状态码: {$httpCode}");
            }

            return $remoteUrl;

        } finally {
            fclose($fileHandle);
        }
    }

    public function download(string $remoteName, string $localPath): bool
    {
        $remoteUrl = $this->getRemoteUrl($remoteName);
        $localDir = dirname($localPath);

        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        $fileHandle = fopen($localPath, 'wb');
        if (!$fileHandle) {
            return false;
        }

        try {
            curl_setopt_array($this->curl, [
                CURLOPT_URL => $remoteUrl,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_FILE => $fileHandle,
                CURLOPT_UPLOAD => false
            ]);

            $response = curl_exec($this->curl);
            $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

            if ($response === false || $httpCode >= 400) {
                return false;
            }

            return true;

        } finally {
            fclose($fileHandle);
        }
    }

    public function delete(string $remoteName): bool
    {
        $remoteUrl = $this->getRemoteUrl($remoteName);

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $remoteUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_UPLOAD => false,
            CURLOPT_FILE => null
        ]);

        $response = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        return $response !== false && ($httpCode >= 200 && $httpCode < 300);
    }

    public function list(): array
    {
        $remoteUrl = $this->url . '/' . $this->remotePath . '/';

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $remoteUrl,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_UPLOAD => false,
            CURLOPT_FILE => null,
            CURLOPT_HTTPHEADER => [
                'Depth: 1',
                'Content-Type: application/xml'
            ],
            CURLOPT_POSTFIELDS => '<?xml version="1.0" encoding="utf-8"?>
                <d:propfind xmlns:d="DAV:">
                    <d:prop>
                        <d:displayname/>
                        <d:getcontentlength/>
                        <d:getlastmodified/>
                        <d:resourcetype/>
                    </d:prop>
                </d:propfind>'
        ]);

        $response = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode >= 400) {
            return [];
        }

        return $this->parseWebDAVResponse($response);
    }

    public function exists(string $remoteName): bool
    {
        $remoteUrl = $this->getRemoteUrl($remoteName);

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $remoteUrl,
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_NOBODY => true,
            CURLOPT_UPLOAD => false,
            CURLOPT_FILE => null
        ]);

        $response = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        return $response !== false && $httpCode === 200;
    }

    public function cleanup(array $retention): int
    {
        $files = $this->list();
        $deletedCount = 0;

        $maxFiles = $retention['max_count'] ?? 10;
        $maxAge = $retention['days'] ?? 30;
        $cutoffTime = time() - ($maxAge * 24 * 3600);

        // 按修改时间排序
        usort($files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });

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
        return 'webdav';
    }

    public function testConnection(): bool
    {
        try {
            $remoteUrl = $this->url . '/' . $this->remotePath . '/';

            curl_setopt_array($this->curl, [
                CURLOPT_URL => $remoteUrl,
                CURLOPT_CUSTOMREQUEST => 'OPTIONS',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_UPLOAD => false,
                CURLOPT_FILE => null
            ]);

            $response = curl_exec($this->curl);
            $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

            return $response !== false && ($httpCode >= 200 && $httpCode < 300);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 初始化 cURL
     */
    private function initializeCurl(): void
    {
        $this->curl = curl_init();

        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'OneBookNav WebDAV Client',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
    }

    /**
     * 确保远程目录存在
     */
    private function ensureRemoteDirectory(): void
    {
        $remoteUrl = $this->url . '/' . $this->remotePath . '/';

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $remoteUrl,
            CURLOPT_CUSTOMREQUEST => 'MKCOL',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_UPLOAD => false,
            CURLOPT_FILE => null
        ]);

        curl_exec($this->curl);
        // 忽略错误，目录可能已存在
    }

    /**
     * 获取远程文件 URL
     */
    private function getRemoteUrl(string $remoteName): string
    {
        $remoteName = urlencode(basename($remoteName));
        return $this->url . '/' . $this->remotePath . '/' . $remoteName;
    }

    /**
     * 解析 WebDAV PROPFIND 响应
     */
    private function parseWebDAVResponse(string $xmlResponse): array
    {
        $files = [];

        try {
            $doc = new \DOMDocument();
            $doc->loadXML($xmlResponse);
            $xpath = new \DOMXPath($doc);

            // 注册 WebDAV 命名空间
            $xpath->registerNamespace('d', 'DAV:');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $href = $xpath->query('d:href', $response)->item(0);
                $displayName = $xpath->query('.//d:displayname', $response)->item(0);
                $contentLength = $xpath->query('.//d:getcontentlength', $response)->item(0);
                $lastModified = $xpath->query('.//d:getlastmodified', $response)->item(0);
                $resourceType = $xpath->query('.//d:resourcetype/d:collection', $response);

                // 跳过目录
                if ($resourceType->length > 0) {
                    continue;
                }

                if ($href && $displayName) {
                    $fileName = $displayName->textContent;

                    // 只返回备份文件
                    if (preg_match('/\.(zip|tar|gz|sql)$/i', $fileName)) {
                        $files[] = [
                            'name' => $fileName,
                            'path' => $href->textContent,
                            'size' => $contentLength ? (int)$contentLength->textContent : 0,
                            'modified' => $lastModified ? strtotime($lastModified->textContent) : 0,
                            'type' => pathinfo($fileName, PATHINFO_EXTENSION)
                        ];
                    }
                }
            }

        } catch (Exception $e) {
            // XML 解析失败，返回空数组
        }

        return $files;
    }

    /**
     * 析构函数，关闭 cURL
     */
    public function __destruct()
    {
        if ($this->curl) {
            curl_close($this->curl);
        }
    }
}