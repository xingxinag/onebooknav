<?php

namespace App\Services\Migrations;

use Exception;
use PDO;

/**
 * OneNav 数据迁移器
 *
 * 从 OneNav 系统迁移数据到 OneBookNav
 */
class OneNavMigrator extends BaseMigrator
{
    public function getName(): string
    {
        return 'OneNav';
    }

    public function getDescription(): string
    {
        return '从 OneNav 导航系统迁移书签数据';
    }

    public function getSupportedFormats(): array
    {
        return ['sqlite', 'sql', 'json'];
    }

    public function detect($input): int
    {
        if (is_string($input)) {
            // 检测 SQLite 数据库文件
            if (file_exists($input) && is_readable($input)) {
                try {
                    $pdo = new PDO("sqlite:{$input}");
                    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

                    // 检查 OneNav 特征表
                    $onenavTables = ['on_categorys', 'on_links', 'on_options'];
                    $matches = array_intersect($onenavTables, $tables);

                    if (count($matches) >= 2) {
                        return 95; // 高置信度
                    }

                    // 也检查通用表名
                    $genericTables = ['categorys', 'links', 'options'];
                    $genericMatches = array_intersect($genericTables, $tables);
                    if (count($genericMatches) >= 2) {
                        return 80; // 中等置信度
                    }
                } catch (Exception $e) {
                    // 不是有效的 SQLite 文件
                }
            }

            // 检测 SQL 导出文件
            if (strpos($input, 'CREATE TABLE') !== false &&
                (strpos($input, 'on_links') !== false || strpos($input, 'on_categorys') !== false)) {
                return 85;
            }

            // 检测 JSON 格式
            $data = json_decode($input, true);
            if ($data && (isset($data['links']) || isset($data['categorys']))) {
                return 75;
            }
        }

        return 0;
    }

    public function validate($input): array
    {
        $errors = [];

        if (is_string($input)) {
            if (file_exists($input)) {
                // SQLite 文件验证
                if (!is_readable($input)) {
                    $errors[] = '无法读取数据库文件';
                }

                try {
                    $pdo = new PDO("sqlite:{$input}");
                    $pdo->query("SELECT 1");
                } catch (Exception $e) {
                    $errors[] = '数据库文件格式错误: ' . $e->getMessage();
                }
            } else {
                // JSON/SQL 内容验证
                if (empty(trim($input))) {
                    $errors[] = '输入内容不能为空';
                }

                $data = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE &&
                    strpos($input, 'CREATE TABLE') === false) {
                    $errors[] = '输入格式无效，必须是有效的 JSON 或 SQL 文件';
                }
            }
        } else {
            $errors[] = '输入必须是文件路径或数据内容';
        }

        if (!empty($errors)) {
            throw new Exception(implode('; ', $errors));
        }

        return ['valid' => true];
    }

    public function parse($input, array $options = []): array
    {
        $this->log('开始解析 OneNav 数据');

        if (file_exists($input)) {
            return $this->parseFromDatabase($input, $options);
        } else {
            $data = json_decode($input, true);
            if ($data) {
                return $this->parseFromJson($data, $options);
            } else {
                return $this->parseFromSql($input, $options);
            }
        }
    }

    /**
     * 从 SQLite 数据库解析数据
     */
    private function parseFromDatabase(string $dbPath, array $options): array
    {
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $result = [
            'categories' => [],
            'bookmarks' => [],
            'tags' => [],
            'users' => []
        ];

        // 检测表名前缀
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $prefix = '';
        if (in_array('on_categorys', $tables)) {
            $prefix = 'on_';
        }

        $categoryTable = $prefix . 'categorys';
        $linkTable = $prefix . 'links';
        $optionTable = $prefix . 'options';

        // 解析分类数据
        try {
            if (in_array($categoryTable, $tables)) {
                $stmt = $pdo->query("SELECT * FROM {$categoryTable} ORDER BY sort, name");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $result['categories'][] = $this->normalizeCategory([
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'description' => $row['description'] ?? '',
                        'icon' => $this->parseOneNavIcon($row['icon'] ?? ''),
                        'color' => $row['color'] ?? '#667eea',
                        'sort_order' => $row['sort'] ?? 0,
                        'parent_id' => ($row['pid'] ?? 0) > 0 ? $row['pid'] : null,
                        'is_active' => !isset($row['status']) || $row['status'] != 0,
                        'created_at' => $this->parseOneNavDateTime($row['add_time'] ?? null)
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->log("解析分类数据失败: " . $e->getMessage(), 'error');
        }

        // 解析书签数据
        try {
            if (in_array($linkTable, $tables)) {
                $sql = "SELECT l.*, c.name as category_name
                        FROM {$linkTable} l
                        LEFT JOIN {$categoryTable} c ON l.fid = c.id
                        ORDER BY l.sort, l.title";

                $stmt = $pdo->query($sql);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // 解析标签
                    $tags = [];
                    if (!empty($row['tag'])) {
                        $tagNames = explode(',', $row['tag']);
                        foreach ($tagNames as $tagName) {
                            $tagName = trim($tagName);
                            if (!empty($tagName)) {
                                $tags[] = ['name' => $tagName];
                            }
                        }
                    }

                    $result['bookmarks'][] = $this->normalizeBookmark([
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'url' => $row['url'],
                        'description' => $row['desc'] ?? $row['description'] ?? '',
                        'category' => $row['category_name'] ?? '未分类',
                        'category_id' => ($row['fid'] ?? 0) > 0 ? $row['fid'] : null,
                        'tags' => $tags,
                        'icon' => $this->parseOneNavIcon($row['icon'] ?? ''),
                        'favicon_url' => $this->generateFaviconFromOneNav($row),
                        'sort_order' => $row['sort'] ?? 0,
                        'weight' => $row['weight'] ?? 0,
                        'is_private' => isset($row['private']) && $row['private'] == 1,
                        'is_featured' => isset($row['featured']) && $row['featured'] == 1,
                        'keywords' => $row['keywords'] ?? '',
                        'notes' => $row['note'] ?? '',
                        'created_at' => $this->parseOneNavDateTime($row['add_time'] ?? null),
                        'updated_at' => $this->parseOneNavDateTime($row['up_time'] ?? null)
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->log("解析书签数据失败: " . $e->getMessage(), 'error');
        }

        // 从书签中提取标签
        $tagSet = [];
        foreach ($result['bookmarks'] as $bookmark) {
            foreach ($bookmark['tags'] as $tag) {
                $tagSet[$tag['name']] = $tag;
            }
        }
        $result['tags'] = array_values($tagSet);

        $this->log("OneNav 数据解析完成: " .
                  count($result['categories']) . " 个分类, " .
                  count($result['bookmarks']) . " 个书签, " .
                  count($result['tags']) . " 个标签");

        return $result;
    }

    /**
     * 从 JSON 数据解析
     */
    private function parseFromJson(array $data, array $options): array
    {
        $result = [
            'categories' => [],
            'bookmarks' => [],
            'tags' => [],
            'users' => []
        ];

        // 解析分类
        if (isset($data['categorys'])) {
            foreach ($data['categorys'] as $category) {
                $result['categories'][] = $this->normalizeCategory([
                    'id' => $category['id'] ?? null,
                    'name' => $category['name'] ?? '',
                    'description' => $category['description'] ?? '',
                    'icon' => $this->parseOneNavIcon($category['icon'] ?? ''),
                    'color' => $category['color'] ?? '#667eea',
                    'sort_order' => $category['sort'] ?? 0,
                    'parent_id' => ($category['pid'] ?? 0) > 0 ? $category['pid'] : null
                ]);
            }
        }

        // 解析书签
        if (isset($data['links'])) {
            foreach ($data['links'] as $link) {
                $tags = [];
                if (!empty($link['tag'])) {
                    $tagNames = explode(',', $link['tag']);
                    foreach ($tagNames as $tagName) {
                        $tagName = trim($tagName);
                        if (!empty($tagName)) {
                            $tags[] = ['name' => $tagName];
                        }
                    }
                }

                $result['bookmarks'][] = $this->normalizeBookmark([
                    'id' => $link['id'] ?? null,
                    'title' => $link['title'] ?? '',
                    'url' => $link['url'] ?? '',
                    'description' => $link['desc'] ?? $link['description'] ?? '',
                    'category_id' => ($link['fid'] ?? 0) > 0 ? $link['fid'] : null,
                    'tags' => $tags,
                    'icon' => $this->parseOneNavIcon($link['icon'] ?? ''),
                    'sort_order' => $link['sort'] ?? 0,
                    'is_private' => isset($link['private']) && $link['private'] == 1,
                    'created_at' => $this->parseOneNavDateTime($link['add_time'] ?? null)
                ]);
            }
        }

        // 提取标签
        $tagSet = [];
        foreach ($result['bookmarks'] as $bookmark) {
            foreach ($bookmark['tags'] as $tag) {
                $tagSet[$tag['name']] = $tag;
            }
        }
        $result['tags'] = array_values($tagSet);

        return $result;
    }

    /**
     * 从 SQL 文件解析
     */
    private function parseFromSql(string $sqlContent, array $options): array
    {
        $tempDb = tempnam(sys_get_temp_dir(), 'onenav_migration_');

        try {
            $pdo = new PDO("sqlite:{$tempDb}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 执行 SQL 语句
            $pdo->exec($sqlContent);

            // 使用数据库解析方法
            $result = $this->parseFromDatabase($tempDb, $options);

            return $result;

        } finally {
            // 清理临时文件
            if (file_exists($tempDb)) {
                unlink($tempDb);
            }
        }
    }

    /**
     * 解析 OneNav 图标格式
     */
    private function parseOneNavIcon(string $icon): string
    {
        if (empty($icon)) {
            return 'fas fa-folder';
        }

        // OneNav 图标格式转换
        if (strpos($icon, 'fa-') === 0) {
            return 'fas ' . $icon;
        }

        if (strpos($icon, 'icon-') === 0) {
            // 转换 icon- 前缀为 FontAwesome
            $iconMap = [
                'icon-folder' => 'fas fa-folder',
                'icon-link' => 'fas fa-link',
                'icon-home' => 'fas fa-home',
                'icon-star' => 'fas fa-star',
                'icon-heart' => 'fas fa-heart',
                'icon-bookmark' => 'fas fa-bookmark',
                'icon-tag' => 'fas fa-tag',
                'icon-file' => 'fas fa-file'
            ];

            return $iconMap[$icon] ?? 'fas fa-folder';
        }

        // 如果是图片URL，保持原样
        if (strpos($icon, 'http') === 0 || strpos($icon, '/') !== false) {
            return $icon;
        }

        return 'fas fa-folder';
    }

    /**
     * 生成 OneNav 书签的 favicon URL
     */
    private function generateFaviconFromOneNav(array $link): string
    {
        // 如果有自定义图标
        if (!empty($link['icon'])) {
            $icon = $link['icon'];
            if (strpos($icon, 'http') === 0) {
                return $icon;
            }
            if (strpos($icon, '/') !== false) {
                return $icon;
            }
        }

        // 生成默认 favicon
        return $this->generateFaviconUrl($link['url'] ?? '');
    }

    /**
     * 解析 OneNav 时间格式
     */
    private function parseOneNavDateTime($timestamp): ?string
    {
        if (empty($timestamp)) {
            return null;
        }

        // OneNav 通常使用时间戳
        if (is_numeric($timestamp)) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        // 尝试其他格式
        return $this->parseDateTime($timestamp);
    }
}