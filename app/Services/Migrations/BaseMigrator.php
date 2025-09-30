<?php

namespace App\Services\Migrations;

use App\Services\DatabaseService;
use Exception;

/**
 * 迁移器基类
 *
 * 提供通用的迁移功能和数据处理方法
 */
abstract class BaseMigrator implements MigratorInterface
{
    protected DatabaseService $database;

    public function __construct(DatabaseService $database)
    {
        $this->database = $database;
    }

    /**
     * 标准化书签数据结构
     */
    protected function normalizeBookmark(array $bookmark): array
    {
        return [
            'id' => $bookmark['id'] ?? null,
            'title' => $this->sanitizeString($bookmark['title'] ?? ''),
            'url' => $this->sanitizeUrl($bookmark['url'] ?? ''),
            'description' => $this->sanitizeString($bookmark['description'] ?? ''),
            'category' => $this->sanitizeString($bookmark['category'] ?? ''),
            'category_id' => $bookmark['category_id'] ?? null,
            'tags' => $this->normalizeTags($bookmark['tags'] ?? []),
            'icon' => $bookmark['icon'] ?? null,
            'favicon_url' => $this->sanitizeUrl($bookmark['favicon_url'] ?? ''),
            'sort_order' => (int)($bookmark['sort_order'] ?? 0),
            'weight' => (int)($bookmark['weight'] ?? 0),
            'is_private' => (bool)($bookmark['is_private'] ?? false),
            'is_featured' => (bool)($bookmark['is_featured'] ?? false),
            'keywords' => $this->sanitizeString($bookmark['keywords'] ?? ''),
            'notes' => $this->sanitizeString($bookmark['notes'] ?? ''),
            'created_at' => $this->parseDateTime($bookmark['created_at'] ?? null),
            'updated_at' => $this->parseDateTime($bookmark['updated_at'] ?? null)
        ];
    }

    /**
     * 标准化分类数据结构
     */
    protected function normalizeCategory(array $category): array
    {
        return [
            'id' => $category['id'] ?? null,
            'name' => $this->sanitizeString($category['name'] ?? ''),
            'description' => $this->sanitizeString($category['description'] ?? ''),
            'icon' => $category['icon'] ?? 'fas fa-folder',
            'color' => $this->validateColor($category['color'] ?? '#667eea'),
            'sort_order' => (int)($category['sort_order'] ?? 0),
            'parent_id' => $category['parent_id'] ?? null,
            'is_active' => (bool)($category['is_active'] ?? true),
            'created_at' => $this->parseDateTime($category['created_at'] ?? null)
        ];
    }

    /**
     * 标准化标签数据结构
     */
    protected function normalizeTag(array $tag): array
    {
        return [
            'id' => $tag['id'] ?? null,
            'name' => $this->sanitizeString($tag['name'] ?? ''),
            'color' => $this->validateColor($tag['color'] ?? '#e2e8f0'),
            'description' => $this->sanitizeString($tag['description'] ?? ''),
            'created_at' => $this->parseDateTime($tag['created_at'] ?? null)
        ];
    }

    /**
     * 标准化用户数据结构
     */
    protected function normalizeUser(array $user): array
    {
        return [
            'id' => $user['id'] ?? null,
            'username' => $this->sanitizeString($user['username'] ?? ''),
            'email' => $this->sanitizeEmail($user['email'] ?? ''),
            'role' => $user['role'] ?? 'user',
            'status' => $user['status'] ?? 'active',
            'avatar' => $user['avatar'] ?? null,
            'preferences' => $user['preferences'] ?? null,
            'created_at' => $this->parseDateTime($user['created_at'] ?? null)
        ];
    }

    /**
     * 清理字符串
     */
    protected function sanitizeString(string $string): string
    {
        $string = trim($string);
        $string = htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
        return $string;
    }

    /**
     * 清理URL
     */
    protected function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) {
            return '';
        }

        // 如果不是完整URL，尝试添加协议
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * 清理邮箱
     */
    protected function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * 标准化标签
     */
    protected function normalizeTags($tags): array
    {
        if (is_string($tags)) {
            // 支持逗号分隔的标签字符串
            $tags = explode(',', $tags);
        }

        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $tagName = trim($tag);
                if (!empty($tagName)) {
                    $normalized[] = ['name' => $tagName];
                }
            } elseif (is_array($tag) && isset($tag['name'])) {
                $normalized[] = $this->normalizeTag($tag);
            }
        }

        return $normalized;
    }

    /**
     * 验证颜色值
     */
    protected function validateColor(string $color): string
    {
        $color = trim($color);

        // 验证十六进制颜色
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }

        // 验证简写十六进制颜色
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color)) {
            return $color;
        }

        // 默认颜色
        return '#667eea';
    }

    /**
     * 解析日期时间
     */
    protected function parseDateTime(?string $dateTime): ?string
    {
        if (empty($dateTime)) {
            return null;
        }

        try {
            // 尝试解析各种日期格式
            $formats = [
                'Y-m-d H:i:s',
                'Y-m-d',
                'Y/m/d H:i:s',
                'Y/m/d',
                'd/m/Y H:i:s',
                'd/m/Y',
                'd-m-Y H:i:s',
                'd-m-Y'
            ];

            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $dateTime);
                if ($date !== false) {
                    return $date->format('Y-m-d H:i:s');
                }
            }

            // 尝试strtotime
            $timestamp = strtotime($dateTime);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }

            return null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 检测编码并转换为UTF-8
     */
    protected function convertToUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $content;
    }

    /**
     * 验证文件格式
     */
    protected function validateFileFormat(string $content, array $expectedFormats): bool
    {
        $content = strtolower(trim($content));

        foreach ($expectedFormats as $format) {
            switch ($format) {
                case 'html':
                    if (strpos($content, '<html') !== false ||
                        strpos($content, '<!doctype') !== false ||
                        strpos($content, '<a href') !== false) {
                        return true;
                    }
                    break;

                case 'json':
                    if (json_decode($content) !== null) {
                        return true;
                    }
                    break;

                case 'csv':
                    if (strpos($content, ',') !== false || strpos($content, ';') !== false) {
                        return true;
                    }
                    break;

                case 'xml':
                    if (strpos($content, '<?xml') !== false || strpos($content, '<') !== false) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }

    /**
     * 提取网站域名
     */
    protected function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? '';
    }

    /**
     * 生成网站图标URL
     */
    protected function generateFaviconUrl(string $url): string
    {
        $domain = $this->extractDomain($url);
        if (empty($domain)) {
            return '';
        }

        return "https://{$domain}/favicon.ico";
    }

    /**
     * 记录迁移日志
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$this->getName()}: {$message}";
        error_log($logMessage);
    }
}