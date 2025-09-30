<?php

namespace App\Services;

use App\Core\Container;
use App\Services\Migrations\BookNavMigrator;
use App\Services\Migrations\OneNavMigrator;
use App\Services\Migrations\BrowserBookmarksMigrator;
use App\Services\Migrations\GenericJsonMigrator;
use App\Services\Migrations\CsvMigrator;
use Exception;
use PDO;

/**
 * 数据迁移服务类
 *
 * 实现"终极.txt"要求的数据迁移和导入功能
 * 支持从 BookNav、OneNav 等系统迁移数据
 */
class MigrationService
{
    private static $instance = null;
    private DatabaseService $database;
    private ConfigService $config;
    private SecurityService $security;
    private array $migrators = [];

    private function __construct()
    {
        $container = Container::getInstance();
        $this->database = $container->get('database');
        $this->config = $container->get('config');
        $this->security = $container->get('security');

        $this->initializeMigrators();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化迁移器
     */
    private function initializeMigrators(): void
    {
        $this->migrators = [
            'booknav' => new BookNavMigrator($this->database),
            'onenav' => new OneNavMigrator($this->database),
            'browser_bookmarks' => new BrowserBookmarksMigrator($this->database),
            'generic_json' => new GenericJsonMigrator($this->database),
            'csv' => new CsvMigrator($this->database)
        ];
    }

    /**
     * 检测导入源类型
     */
    public function detectImportSource($input): array
    {
        $detectionResults = [];

        foreach ($this->migrators as $type => $migrator) {
            try {
                $confidence = $migrator->detect($input);
                if ($confidence > 0) {
                    $detectionResults[] = [
                        'type' => $type,
                        'name' => $migrator->getName(),
                        'confidence' => $confidence,
                        'description' => $migrator->getDescription()
                    ];
                }
            } catch (Exception $e) {
                // 检测失败，跳过
                continue;
            }
        }

        // 按置信度排序
        usort($detectionResults, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        return $detectionResults;
    }

    /**
     * 执行数据迁移
     */
    public function migrate(string $sourceType, $input, array $options = []): array
    {
        if (!isset($this->migrators[$sourceType])) {
            throw new Exception("不支持的迁移源类型: {$sourceType}");
        }

        $migrator = $this->migrators[$sourceType];
        $migrationId = uniqid('migration_');

        try {
            $this->logMigrationStart($migrationId, $sourceType, $options);

            // 验证输入
            $migrator->validate($input);

            // 解析数据
            $parsedData = $migrator->parse($input, $options);

            // 数据预处理
            $processedData = $this->preprocessData($parsedData, $options);

            // 开始迁移事务
            $result = $this->database->transaction(function() use ($processedData, $options, $migrationId) {
                return $this->performMigration($processedData, $options, $migrationId);
            });

            // 后处理
            $this->postProcessMigration($result, $options);

            $this->logMigrationComplete($migrationId, $result);

            return $result;

        } catch (Exception $e) {
            $this->logMigrationError($migrationId, $e);
            throw $e;
        }
    }

    /**
     * 数据预处理
     */
    private function preprocessData(array $data, array $options): array
    {
        $processed = [
            'categories' => [],
            'bookmarks' => [],
            'tags' => [],
            'users' => []
        ];

        // 处理分类
        if (isset($data['categories'])) {
            foreach ($data['categories'] as $category) {
                $processed['categories'][] = $this->preprocessCategory($category, $options);
            }
        }

        // 处理书签
        if (isset($data['bookmarks'])) {
            foreach ($data['bookmarks'] as $bookmark) {
                $processed['bookmarks'][] = $this->preprocessBookmark($bookmark, $options);
            }
        }

        // 处理标签
        if (isset($data['tags'])) {
            foreach ($data['tags'] as $tag) {
                $processed['tags'][] = $this->preprocessTag($tag, $options);
            }
        }

        // 处理用户
        if (isset($data['users'])) {
            foreach ($data['users'] as $user) {
                $processed['users'][] = $this->preprocessUser($user, $options);
            }
        }

        return $processed;
    }

    /**
     * 预处理分类
     */
    private function preprocessCategory(array $category, array $options): array
    {
        return [
            'name' => $this->security->sanitizeInput($category['name'] ?? '未命名分类', ['type' => 'string']),
            'description' => $this->security->sanitizeInput($category['description'] ?? '', ['type' => 'string']),
            'icon' => $category['icon'] ?? 'fas fa-folder',
            'color' => $this->validateColor($category['color'] ?? '#667eea'),
            'sort_order' => (int)($category['sort_order'] ?? 0),
            'parent_id' => $category['parent_id'] ?? null,
            'is_active' => $category['is_active'] ?? true,
            'user_id' => $options['target_user_id'] ?? null,
            'original_id' => $category['id'] ?? null
        ];
    }

    /**
     * 预处理书签
     */
    private function preprocessBookmark(array $bookmark, array $options): array
    {
        $url = $bookmark['url'] ?? '';
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception("无效的URL: {$url}");
        }

        return [
            'title' => $this->security->sanitizeInput($bookmark['title'] ?? '未命名书签', ['type' => 'string']),
            'url' => $url,
            'description' => $this->security->sanitizeInput($bookmark['description'] ?? '', ['type' => 'string']),
            'category_id' => null, // 将在迁移时解析
            'category_name' => $bookmark['category'] ?? '默认分类',
            'user_id' => $options['target_user_id'] ?? null,
            'icon' => $bookmark['icon'] ?? null,
            'favicon_url' => $bookmark['favicon_url'] ?? null,
            'sort_order' => (int)($bookmark['sort_order'] ?? 0),
            'weight' => (int)($bookmark['weight'] ?? 0),
            'is_private' => $bookmark['is_private'] ?? false,
            'is_featured' => $bookmark['is_featured'] ?? false,
            'tags' => $bookmark['tags'] ?? [],
            'keywords' => $bookmark['keywords'] ?? null,
            'notes' => $bookmark['notes'] ?? null,
            'created_at' => $this->parseDateTime($bookmark['created_at'] ?? null),
            'original_id' => $bookmark['id'] ?? null
        ];
    }

    /**
     * 预处理标签
     */
    private function preprocessTag(array $tag, array $options): array
    {
        return [
            'name' => $this->security->sanitizeInput($tag['name'] ?? '', ['type' => 'string']),
            'color' => $this->validateColor($tag['color'] ?? '#e2e8f0'),
            'description' => $this->security->sanitizeInput($tag['description'] ?? '', ['type' => 'string']),
            'original_id' => $tag['id'] ?? null
        ];
    }

    /**
     * 预处理用户
     */
    private function preprocessUser(array $user, array $options): array
    {
        return [
            'username' => $this->security->sanitizeInput($user['username'] ?? '', ['type' => 'string']),
            'email' => $this->security->sanitizeInput($user['email'] ?? '', ['type' => 'email']),
            'role' => $user['role'] ?? 'user',
            'status' => $user['status'] ?? 'active',
            'avatar' => $user['avatar'] ?? null,
            'preferences' => $user['preferences'] ?? null,
            'created_at' => $this->parseDateTime($user['created_at'] ?? null),
            'original_id' => $user['id'] ?? null
        ];
    }

    /**
     * 执行迁移
     */
    private function performMigration(array $data, array $options, string $migrationId): array
    {
        $result = [
            'migration_id' => $migrationId,
            'imported' => [
                'categories' => 0,
                'bookmarks' => 0,
                'tags' => 0,
                'users' => 0
            ],
            'skipped' => [
                'categories' => 0,
                'bookmarks' => 0,
                'tags' => 0,
                'users' => 0
            ],
            'errors' => [],
            'mapping' => [
                'categories' => [],
                'tags' => [],
                'users' => []
            ]
        ];

        // 迁移用户
        if (!empty($data['users']) && ($options['import_users'] ?? false)) {
            $result = $this->migrateUsers($data['users'], $options, $result);
        }

        // 迁移分类
        if (!empty($data['categories'])) {
            $result = $this->migrateCategories($data['categories'], $options, $result);
        }

        // 迁移标签
        if (!empty($data['tags'])) {
            $result = $this->migrateTags($data['tags'], $options, $result);
        }

        // 迁移书签
        if (!empty($data['bookmarks'])) {
            $result = $this->migrateBookmarks($data['bookmarks'], $options, $result);
        }

        return $result;
    }

    /**
     * 迁移用户
     */
    private function migrateUsers(array $users, array $options, array $result): array
    {
        foreach ($users as $user) {
            try {
                // 检查用户是否已存在
                $existing = $this->database->query(
                    "SELECT id FROM users WHERE username = ? OR email = ?",
                    [$user['username'], $user['email']]
                )->fetch();

                if ($existing && !($options['overwrite_users'] ?? false)) {
                    $result['skipped']['users']++;
                    $result['mapping']['users'][$user['original_id']] = $existing['id'];
                    continue;
                }

                // 创建或更新用户
                if ($existing && ($options['overwrite_users'] ?? false)) {
                    $this->database->update('users', [
                        'role' => $user['role'],
                        'status' => $user['status'],
                        'avatar' => $user['avatar'],
                        'preferences' => $user['preferences'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);

                    $userId = $existing['id'];
                } else {
                    // 生成临时密码
                    $tempPassword = $this->security->generateSecurePassword(12);
                    $salt = bin2hex(random_bytes(16));
                    $hashedPassword = password_hash($tempPassword . $salt, PASSWORD_DEFAULT);

                    $userId = $this->database->insert('users', [
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'password_hash' => $hashedPassword,
                        'salt' => $salt,
                        'role' => $user['role'],
                        'status' => $user['status'],
                        'avatar' => $user['avatar'],
                        'preferences' => $user['preferences'],
                        'created_at' => $user['created_at'] ?: date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }

                $result['imported']['users']++;
                $result['mapping']['users'][$user['original_id']] = $userId;

            } catch (Exception $e) {
                $result['errors'][] = "导入用户 '{$user['username']}' 失败: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * 迁移分类
     */
    private function migrateCategories(array $categories, array $options, array $result): array
    {
        foreach ($categories as $category) {
            try {
                // 检查分类是否已存在
                $existing = null;
                if (!($options['overwrite_categories'] ?? false)) {
                    $existing = $this->database->query(
                        "SELECT id FROM categories WHERE name = ? AND user_id IS ?",
                        [$category['name'], $category['user_id']]
                    )->fetch();
                }

                if ($existing) {
                    $result['skipped']['categories']++;
                    $result['mapping']['categories'][$category['original_id']] = $existing['id'];
                    continue;
                }

                // 创建分类
                $categoryId = $this->database->insert('categories', [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'icon' => $category['icon'],
                    'color' => $category['color'],
                    'sort_order' => $category['sort_order'],
                    'parent_id' => $category['parent_id'],
                    'is_active' => $category['is_active'],
                    'user_id' => $category['user_id'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $result['imported']['categories']++;
                $result['mapping']['categories'][$category['original_id']] = $categoryId;

            } catch (Exception $e) {
                $result['errors'][] = "导入分类 '{$category['name']}' 失败: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * 迁移标签
     */
    private function migrateTags(array $tags, array $options, array $result): array
    {
        foreach ($tags as $tag) {
            try {
                // 检查标签是否已存在
                $existing = $this->database->query(
                    "SELECT id FROM tags WHERE name = ?",
                    [$tag['name']]
                )->fetch();

                if ($existing && !($options['overwrite_tags'] ?? false)) {
                    $result['skipped']['tags']++;
                    $result['mapping']['tags'][$tag['original_id']] = $existing['id'];
                    continue;
                }

                // 创建或更新标签
                if ($existing && ($options['overwrite_tags'] ?? false)) {
                    $this->database->update('tags', [
                        'color' => $tag['color'],
                        'description' => $tag['description']
                    ], 'id = ?', [$existing['id']]);

                    $tagId = $existing['id'];
                } else {
                    $tagId = $this->database->insert('tags', [
                        'name' => $tag['name'],
                        'color' => $tag['color'],
                        'description' => $tag['description'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }

                $result['imported']['tags']++;
                $result['mapping']['tags'][$tag['original_id']] = $tagId;

            } catch (Exception $e) {
                $result['errors'][] = "导入标签 '{$tag['name']}' 失败: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * 迁移书签
     */
    private function migrateBookmarks(array $bookmarks, array $options, array $result): array
    {
        foreach ($bookmarks as $bookmark) {
            try {
                // 解析分类ID
                $categoryId = $this->resolveCategoryId($bookmark, $result['mapping']['categories'], $options);

                // 检查书签是否已存在
                $existing = null;
                if (!($options['overwrite_bookmarks'] ?? false)) {
                    $existing = $this->database->query(
                        "SELECT id FROM websites WHERE url = ?",
                        [$bookmark['url']]
                    )->fetch();
                }

                if ($existing) {
                    $result['skipped']['bookmarks']++;
                    continue;
                }

                // 获取网站信息
                if ($options['fetch_metadata'] ?? true) {
                    $bookmark = $this->enrichBookmarkMetadata($bookmark);
                }

                // 创建书签
                $bookmarkId = $this->database->insert('websites', [
                    'title' => $bookmark['title'],
                    'url' => $bookmark['url'],
                    'description' => $bookmark['description'],
                    'category_id' => $categoryId,
                    'user_id' => $bookmark['user_id'],
                    'icon' => $bookmark['icon'],
                    'favicon_url' => $bookmark['favicon_url'],
                    'sort_order' => $bookmark['sort_order'],
                    'weight' => $bookmark['weight'],
                    'status' => 'active',
                    'is_private' => $bookmark['is_private'],
                    'is_featured' => $bookmark['is_featured'],
                    'keywords' => $bookmark['keywords'],
                    'notes' => $bookmark['notes'],
                    'created_at' => $bookmark['created_at'] ?: date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // 关联标签
                $this->attachBookmarkTags($bookmarkId, $bookmark['tags'], $result['mapping']['tags']);

                $result['imported']['bookmarks']++;

            } catch (Exception $e) {
                $result['errors'][] = "导入书签 '{$bookmark['title']}' 失败: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * 解析分类ID
     */
    private function resolveCategoryId(array $bookmark, array $categoryMapping, array $options): int
    {
        // 如果有原始分类ID映射
        if (isset($bookmark['category_id']) && isset($categoryMapping[$bookmark['category_id']])) {
            return $categoryMapping[$bookmark['category_id']];
        }

        // 如果指定了目标分类
        if (isset($options['target_category_id'])) {
            return $options['target_category_id'];
        }

        // 按分类名称查找
        if (!empty($bookmark['category_name'])) {
            $category = $this->database->query(
                "SELECT id FROM categories WHERE name = ? AND (user_id IS NULL OR user_id = ?)",
                [$bookmark['category_name'], $bookmark['user_id']]
            )->fetch();

            if ($category) {
                return $category['id'];
            }

            // 创建新分类
            return $this->database->insert('categories', [
                'name' => $bookmark['category_name'],
                'icon' => 'fas fa-folder',
                'color' => '#667eea',
                'sort_order' => 999,
                'is_active' => true,
                'user_id' => $bookmark['user_id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // 使用默认分类
        $defaultCategory = $this->database->query(
            "SELECT id FROM categories WHERE name = '默认分类' OR name = 'Default' LIMIT 1"
        )->fetch();

        if ($defaultCategory) {
            return $defaultCategory['id'];
        }

        // 创建默认分类
        return $this->database->insert('categories', [
            'name' => '默认分类',
            'icon' => 'fas fa-folder',
            'color' => '#667eea',
            'sort_order' => 0,
            'is_active' => true,
            'user_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 关联书签标签
     */
    private function attachBookmarkTags(int $bookmarkId, array $tags, array $tagMapping): void
    {
        foreach ($tags as $tagName) {
            if (is_array($tagName)) {
                $tagName = $tagName['name'] ?? '';
            }

            if (empty($tagName)) {
                continue;
            }

            // 查找或创建标签
            $tag = $this->database->query(
                "SELECT id FROM tags WHERE name = ?",
                [$tagName]
            )->fetch();

            if (!$tag) {
                $tagId = $this->database->insert('tags', [
                    'name' => $tagName,
                    'color' => '#e2e8f0',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $tagId = $tag['id'];
            }

            // 关联书签和标签
            try {
                $this->database->insert('website_tags', [
                    'website_id' => $bookmarkId,
                    'tag_id' => $tagId,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                // 可能已存在关联，忽略错误
            }
        }
    }

    /**
     * 丰富书签元数据
     */
    private function enrichBookmarkMetadata(array $bookmark): array
    {
        if (empty($bookmark['favicon_url']) || empty($bookmark['title'])) {
            try {
                $info = $this->fetchUrlMetadata($bookmark['url']);

                if (empty($bookmark['title']) && !empty($info['title'])) {
                    $bookmark['title'] = $info['title'];
                }

                if (empty($bookmark['description']) && !empty($info['description'])) {
                    $bookmark['description'] = $info['description'];
                }

                if (empty($bookmark['favicon_url']) && !empty($info['favicon'])) {
                    $bookmark['favicon_url'] = $info['favicon'];
                }

            } catch (Exception $e) {
                // 获取元数据失败，使用原有数据
            }
        }

        return $bookmark;
    }

    /**
     * 获取URL元数据
     */
    private function fetchUrlMetadata(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'OneBookNav Migration Tool',
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$html) {
            throw new Exception('无法获取URL内容');
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);

        $info = [
            'title' => '',
            'description' => '',
            'favicon' => ''
        ];

        // 提取标题
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $info['title'] = trim($titleNodes->item(0)->textContent);
        }

        // 提取描述
        $metaDesc = $xpath->query('//meta[@name="description"]/@content');
        if ($metaDesc->length > 0) {
            $info['description'] = trim($metaDesc->item(0)->textContent);
        }

        // 提取favicon
        $iconLinks = $xpath->query('//link[@rel="icon" or @rel="shortcut icon"]/@href');
        if ($iconLinks->length > 0) {
            $iconHref = $iconLinks->item(0)->textContent;
            $parsed = parse_url($url);
            $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];

            if (strpos($iconHref, 'http') === 0) {
                $info['favicon'] = $iconHref;
            } else {
                $info['favicon'] = $baseUrl . '/' . ltrim($iconHref, '/');
            }
        }

        return $info;
    }

    /**
     * 后处理迁移
     */
    private function postProcessMigration(array $result, array $options): void
    {
        // 更新分类书签数量
        $this->database->query("
            UPDATE categories SET website_count = (
                SELECT COUNT(*) FROM websites WHERE category_id = categories.id AND status = 'active'
            )
        ");

        // 更新标签使用计数
        $this->database->query("
            UPDATE tags SET usage_count = (
                SELECT COUNT(*) FROM website_tags WHERE tag_id = tags.id
            )
        ");
    }

    /**
     * 验证颜色值
     */
    private function validateColor(string $color): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }
        return '#667eea'; // 默认颜色
    }

    /**
     * 解析日期时间
     */
    private function parseDateTime(?string $dateTime): ?string
    {
        if (empty($dateTime)) {
            return null;
        }

        try {
            $timestamp = strtotime($dateTime);
            if ($timestamp === false) {
                return null;
            }
            return date('Y-m-d H:i:s', $timestamp);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 获取支持的迁移源
     */
    public function getSupportedSources(): array
    {
        $sources = [];

        foreach ($this->migrators as $type => $migrator) {
            $sources[$type] = [
                'name' => $migrator->getName(),
                'description' => $migrator->getDescription(),
                'supported_formats' => $migrator->getSupportedFormats()
            ];
        }

        return $sources;
    }

    /**
     * 验证迁移数据
     */
    public function validateMigrationData(string $sourceType, $input): array
    {
        if (!isset($this->migrators[$sourceType])) {
            throw new Exception("不支持的迁移源类型: {$sourceType}");
        }

        return $this->migrators[$sourceType]->validate($input);
    }

    /**
     * 预览迁移数据
     */
    public function previewMigrationData(string $sourceType, $input, array $options = []): array
    {
        if (!isset($this->migrators[$sourceType])) {
            throw new Exception("不支持的迁移源类型: {$sourceType}");
        }

        $migrator = $this->migrators[$sourceType];

        // 验证输入
        $migrator->validate($input);

        // 解析数据
        $parsedData = $migrator->parse($input, $options);

        // 返回预览信息
        return [
            'source_type' => $sourceType,
            'source_name' => $migrator->getName(),
            'stats' => [
                'categories' => count($parsedData['categories'] ?? []),
                'bookmarks' => count($parsedData['bookmarks'] ?? []),
                'tags' => count($parsedData['tags'] ?? []),
                'users' => count($parsedData['users'] ?? [])
            ],
            'sample_data' => [
                'categories' => array_slice($parsedData['categories'] ?? [], 0, 5),
                'bookmarks' => array_slice($parsedData['bookmarks'] ?? [], 0, 10),
                'tags' => array_slice($parsedData['tags'] ?? [], 0, 10),
                'users' => array_slice($parsedData['users'] ?? [], 0, 5)
            ]
        ];
    }

    /**
     * 记录迁移日志
     */
    private function logMigrationStart(string $migrationId, string $sourceType, array $options): void
    {
        error_log("Starting migration {$migrationId} from {$sourceType}");
    }

    private function logMigrationComplete(string $migrationId, array $result): void
    {
        $stats = $result['imported'];
        error_log("Migration {$migrationId} completed: " . json_encode($stats));
    }

    private function logMigrationError(string $migrationId, Exception $e): void
    {
        error_log("Migration {$migrationId} failed: " . $e->getMessage());
    }
}