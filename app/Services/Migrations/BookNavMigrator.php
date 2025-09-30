<?php

namespace App\Services\Migrations;

use Exception;
use PDO;

/**
 * BookNav 数据迁移器
 *
 * 从 BookNav 系统迁移数据到 OneBookNav
 */
class BookNavMigrator extends BaseMigrator
{
    public function getName(): string
    {
        return 'BookNav';
    }

    public function getDescription(): string
    {
        return '从 BookNav 导航系统迁移书签数据';
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

                    // 检查 BookNav 特征表
                    $booknavTables = ['users', 'categories', 'websites', 'website_tag'];
                    $matches = array_intersect($booknavTables, $tables);

                    if (count($matches) >= 3) {
                        return 95; // 高置信度
                    } elseif (count($matches) >= 2) {
                        return 70; // 中等置信度
                    }
                } catch (Exception $e) {
                    // 不是有效的 SQLite 文件
                }
            }

            // 检测 SQL 导出文件
            if (strpos($input, 'CREATE TABLE') !== false &&
                (strpos($input, 'websites') !== false || strpos($input, 'categories') !== false)) {
                return 80;
            }

            // 检测 JSON 格式
            $data = json_decode($input, true);
            if ($data && isset($data['bookmarks']) && isset($data['categories'])) {
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
        $this->log('开始解析 BookNav 数据');

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

        // 解析用户数据
        try {
            $stmt = $pdo->query("SELECT * FROM users");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result['users'][] = $this->normalizeUser([
                    'id' => $row['id'],
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'role' => $row['role'] ?? 'user',
                    'status' => $row['is_active'] ? 'active' : 'inactive',
                    'created_at' => $row['created_at']
                ]);
            }
        } catch (Exception $e) {
            $this->log("解析用户数据失败: " . $e->getMessage(), 'warning');
        }

        // 解析分类数据
        try {
            $stmt = $pdo->query("SELECT * FROM categories ORDER BY sort_order, name");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result['categories'][] = $this->normalizeCategory([
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'] ?? '',
                    'icon' => $row['icon'] ?? 'fas fa-folder',
                    'color' => $row['color'] ?? '#667eea',
                    'sort_order' => $row['sort_order'] ?? 0,
                    'parent_id' => $row['parent_id'] ?? null,
                    'is_active' => $row['is_active'] ?? true,
                    'created_at' => $row['created_at']
                ]);
            }
        } catch (Exception $e) {
            $this->log("解析分类数据失败: " . $e->getMessage(), 'error');
        }

        // 解析书签数据
        try {
            $sql = "SELECT w.*, c.name as category_name,
                           GROUP_CONCAT(t.name) as tag_names
                    FROM websites w
                    LEFT JOIN categories c ON w.category_id = c.id
                    LEFT JOIN website_tag wt ON w.id = wt.website_id
                    LEFT JOIN tags t ON wt.tag_id = t.id
                    WHERE w.status = 'active'
                    GROUP BY w.id
                    ORDER BY w.sort_order, w.title";

            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tags = [];
                if (!empty($row['tag_names'])) {
                    $tagNames = explode(',', $row['tag_names']);
                    foreach ($tagNames as $tagName) {
                        $tags[] = ['name' => trim($tagName)];
                    }
                }

                $result['bookmarks'][] = $this->normalizeBookmark([
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'url' => $row['url'],
                    'description' => $row['description'] ?? '',
                    'category' => $row['category_name'] ?? '未分类',
                    'category_id' => $row['category_id'],
                    'tags' => $tags,
                    'icon' => $row['icon'],
                    'favicon_url' => $row['favicon_url'],
                    'sort_order' => $row['sort_order'] ?? 0,
                    'weight' => $row['weight'] ?? 0,
                    'is_private' => $row['is_private'] ?? false,
                    'is_featured' => $row['is_featured'] ?? false,
                    'keywords' => $row['keywords'] ?? '',
                    'notes' => $row['notes'] ?? '',
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ]);
            }
        } catch (Exception $e) {
            $this->log("解析书签数据失败: " . $e->getMessage(), 'error');
        }

        // 解析标签数据
        try {
            $stmt = $pdo->query("SELECT DISTINCT name FROM tags ORDER BY name");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result['tags'][] = $this->normalizeTag([
                    'name' => $row['name'],
                    'color' => '#e2e8f0'
                ]);
            }
        } catch (Exception $e) {
            $this->log("解析标签数据失败: " . $e->getMessage(), 'warning');
        }

        $this->log("BookNav 数据解析完成: " .
                  count($result['categories']) . " 个分类, " .
                  count($result['bookmarks']) . " 个书签, " .
                  count($result['tags']) . " 个标签, " .
                  count($result['users']) . " 个用户");

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
        if (isset($data['categories'])) {
            foreach ($data['categories'] as $category) {
                $result['categories'][] = $this->normalizeCategory($category);
            }
        }

        // 解析书签
        if (isset($data['bookmarks'])) {
            foreach ($data['bookmarks'] as $bookmark) {
                $result['bookmarks'][] = $this->normalizeBookmark($bookmark);
            }
        }

        // 解析标签
        if (isset($data['tags'])) {
            foreach ($data['tags'] as $tag) {
                $result['tags'][] = $this->normalizeTag($tag);
            }
        }

        // 解析用户
        if (isset($data['users'])) {
            foreach ($data['users'] as $user) {
                $result['users'][] = $this->normalizeUser($user);
            }
        }

        return $result;
    }

    /**
     * 从 SQL 文件解析
     */
    private function parseFromSql(string $sqlContent, array $options): array
    {
        // 创建临时 SQLite 数据库
        $tempDb = tempnam(sys_get_temp_dir(), 'booknav_migration_');

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
}