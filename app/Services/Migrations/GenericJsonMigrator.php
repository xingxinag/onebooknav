<?php

namespace App\Services\Migrations;

use Exception;

/**
 * 通用 JSON 数据迁移器
 *
 * 支持从各种 JSON 格式导入书签数据
 */
class GenericJsonMigrator extends BaseMigrator
{
    public function getName(): string
    {
        return '通用 JSON';
    }

    public function getDescription(): string
    {
        return '从 JSON 格式文件导入书签数据';
    }

    public function getSupportedFormats(): array
    {
        return ['json'];
    }

    public function detect($input): int
    {
        if (!is_string($input)) {
            return 0;
        }

        $content = '';
        if (file_exists($input)) {
            $content = file_get_contents($input);
        } else {
            $content = $input;
        }

        if (empty($content)) {
            return 0;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 0;
        }

        $confidence = 20; // 基础 JSON 格式分数

        // 检查是否包含书签相关字段
        if ($this->hasBookmarkFields($data)) {
            $confidence += 50;
        }

        // 检查数据结构
        if ($this->hasStructuredData($data)) {
            $confidence += 20;
        }

        // 检查是否有多个书签
        $bookmarkCount = $this->countBookmarks($data);
        if ($bookmarkCount > 1) {
            $confidence += 10;
        }

        return min($confidence, 95);
    }

    public function validate($input): array
    {
        $errors = [];

        if (!is_string($input)) {
            $errors[] = '输入必须是字符串或文件路径';
        } else {
            $content = '';
            if (file_exists($input)) {
                if (!is_readable($input)) {
                    $errors[] = '无法读取文件';
                } else {
                    $content = file_get_contents($input);
                }
            } else {
                $content = $input;
            }

            if (empty($content)) {
                $errors[] = '文件内容为空';
            } else {
                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = 'JSON 格式错误: ' . json_last_error_msg();
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(implode('; ', $errors));
        }

        return ['valid' => true];
    }

    public function parse($input, array $options = []): array
    {
        $this->log('开始解析 JSON 数据');

        $content = '';
        if (file_exists($input)) {
            $content = file_get_contents($input);
        } else {
            $content = $input;
        }

        $content = $this->convertToUtf8($content);
        $data = json_decode($content, true);

        $result = [
            'categories' => [],
            'bookmarks' => [],
            'tags' => [],
            'users' => []
        ];

        // 尝试不同的解析策略
        $this->parseJsonData($data, $result);

        // 提取标签
        $this->extractTagsFromBookmarks($result);

        $this->log("JSON 数据解析完成: " .
                  count($result['categories']) . " 个分类, " .
                  count($result['bookmarks']) . " 个书签, " .
                  count($result['tags']) . " 个标签");

        return $result;
    }

    /**
     * 解析 JSON 数据
     */
    private function parseJsonData(array $data, array &$result): void
    {
        // 策略1: 直接包含 bookmarks 字段
        if (isset($data['bookmarks']) && is_array($data['bookmarks'])) {
            $this->parseBookmarksArray($data['bookmarks'], $result);

            if (isset($data['categories']) && is_array($data['categories'])) {
                $this->parseCategoriesArray($data['categories'], $result);
            }
            return;
        }

        // 策略2: 直接是书签数组
        if ($this->isBookmarksArray($data)) {
            $this->parseBookmarksArray($data, $result);
            return;
        }

        // 策略3: 查找嵌套的书签数据
        $this->findAndParseBookmarks($data, $result);
    }

    /**
     * 解析书签数组
     */
    private function parseBookmarksArray(array $bookmarks, array &$result): void
    {
        $categories = [];
        $categoryIdCounter = 1;

        foreach ($bookmarks as $item) {
            if (!is_array($item)) {
                continue;
            }

            $bookmark = $this->extractBookmarkFromItem($item);

            if (empty($bookmark['title']) || empty($bookmark['url'])) {
                continue;
            }

            // 处理分类
            $categoryName = $bookmark['category'] ?? '默认分类';
            if (!empty($categoryName) && !isset($categories[$categoryName])) {
                $categories[$categoryName] = [
                    'id' => $categoryIdCounter++,
                    'name' => $categoryName,
                    'description' => '',
                    'icon' => 'fas fa-folder',
                    'sort_order' => count($categories)
                ];
            }

            if (isset($categories[$categoryName])) {
                $bookmark['category_id'] = $categories[$categoryName]['id'];
            }

            $result['bookmarks'][] = $this->normalizeBookmark($bookmark);
        }

        // 添加分类到结果
        foreach ($categories as $category) {
            $result['categories'][] = $this->normalizeCategory($category);
        }
    }

    /**
     * 解析分类数组
     */
    private function parseCategoriesArray(array $categories, array &$result): void
    {
        foreach ($categories as $item) {
            if (!is_array($item)) {
                continue;
            }

            $category = $this->extractCategoryFromItem($item);
            if (!empty($category['name'])) {
                $result['categories'][] = $this->normalizeCategory($category);
            }
        }
    }

    /**
     * 从项目中提取书签数据
     */
    private function extractBookmarkFromItem(array $item): array
    {
        $bookmark = [];

        // 标题字段
        $titleFields = ['title', 'name', 'label', 'text', '标题', '名称'];
        foreach ($titleFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $bookmark['title'] = (string)$item[$field];
                break;
            }
        }

        // URL 字段
        $urlFields = ['url', 'link', 'href', 'address', '网址', '链接'];
        foreach ($urlFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $bookmark['url'] = (string)$item[$field];
                break;
            }
        }

        // 描述字段
        $descFields = ['description', 'desc', 'note', 'notes', 'comment', '描述', '说明', '备注'];
        foreach ($descFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $bookmark['description'] = (string)$item[$field];
                break;
            }
        }

        // 分类字段
        $categoryFields = ['category', 'folder', 'group', 'type', '分类', '文件夹', '分组'];
        foreach ($categoryFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $bookmark['category'] = (string)$item[$field];
                break;
            }
        }

        // 标签字段
        $tagFields = ['tags', 'tag', 'keywords', 'labels', '标签', '关键词'];
        foreach ($tagFields as $field) {
            if (isset($item[$field])) {
                $bookmark['tags'] = $this->normalizeTags($item[$field]);
                break;
            }
        }

        // 图标字段
        $iconFields = ['icon', 'favicon', 'image', '图标'];
        foreach ($iconFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $bookmark['favicon_url'] = (string)$item[$field];
                break;
            }
        }

        // 时间字段
        $timeFields = ['created_at', 'created', 'date', 'timestamp', '创建时间', '添加时间'];
        foreach ($timeFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $bookmark['created_at'] = $this->parseDateTime((string)$item[$field]);
                break;
            }
        }

        // 其他字段
        if (isset($item['is_private'])) {
            $bookmark['is_private'] = (bool)$item['is_private'];
        }

        if (isset($item['is_featured'])) {
            $bookmark['is_featured'] = (bool)$item['is_featured'];
        }

        if (isset($item['sort_order'])) {
            $bookmark['sort_order'] = (int)$item['sort_order'];
        }

        return $bookmark;
    }

    /**
     * 从项目中提取分类数据
     */
    private function extractCategoryFromItem(array $item): array
    {
        $category = [];

        // 名称字段
        $nameFields = ['name', 'title', 'label', '名称', '标题'];
        foreach ($nameFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $category['name'] = (string)$item[$field];
                break;
            }
        }

        // 描述字段
        $descFields = ['description', 'desc', '描述', '说明'];
        foreach ($descFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $category['description'] = (string)$item[$field];
                break;
            }
        }

        // 图标字段
        if (isset($item['icon'])) {
            $category['icon'] = (string)$item['icon'];
        }

        // 颜色字段
        if (isset($item['color'])) {
            $category['color'] = (string)$item['color'];
        }

        // 排序字段
        if (isset($item['sort_order'])) {
            $category['sort_order'] = (int)$item['sort_order'];
        }

        // 父级字段
        if (isset($item['parent_id'])) {
            $category['parent_id'] = (int)$item['parent_id'];
        }

        return $category;
    }

    /**
     * 检查是否包含书签字段
     */
    private function hasBookmarkFields($data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $bookmarkIndicators = ['bookmarks', 'links', 'sites', 'urls'];
        foreach ($bookmarkIndicators as $indicator) {
            if (isset($data[$indicator])) {
                return true;
            }
        }

        // 检查是否直接是书签数组
        return $this->isBookmarksArray($data);
    }

    /**
     * 检查是否为书签数组
     */
    private function isBookmarksArray(array $data): bool
    {
        if (empty($data) || !isset($data[0])) {
            return false;
        }

        $firstItem = $data[0];
        if (!is_array($firstItem)) {
            return false;
        }

        // 检查是否包含URL字段
        $urlFields = ['url', 'link', 'href'];
        foreach ($urlFields as $field) {
            if (isset($firstItem[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否有结构化数据
     */
    private function hasStructuredData($data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $structureIndicators = ['categories', 'folders', 'groups', 'tags'];
        foreach ($structureIndicators as $indicator) {
            if (isset($data[$indicator])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 计算书签数量
     */
    private function countBookmarks($data): int
    {
        if (!is_array($data)) {
            return 0;
        }

        if (isset($data['bookmarks']) && is_array($data['bookmarks'])) {
            return count($data['bookmarks']);
        }

        if ($this->isBookmarksArray($data)) {
            return count($data);
        }

        return 0;
    }

    /**
     * 查找并解析书签
     */
    private function findAndParseBookmarks($data, array &$result): void
    {
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($key === 'bookmarks' || $key === 'links' || $key === 'sites') {
                    $this->parseBookmarksArray($value, $result);
                } elseif ($this->isBookmarksArray($value)) {
                    $this->parseBookmarksArray($value, $result);
                } else {
                    // 递归查找
                    $this->findAndParseBookmarks($value, $result);
                }
            }
        }
    }

    /**
     * 从书签中提取标签
     */
    private function extractTagsFromBookmarks(array &$result): void
    {
        $tagSet = [];

        foreach ($result['bookmarks'] as $bookmark) {
            if (isset($bookmark['tags'])) {
                foreach ($bookmark['tags'] as $tag) {
                    $tagName = $tag['name'];
                    if (!isset($tagSet[$tagName])) {
                        $tagSet[$tagName] = $this->normalizeTag([
                            'name' => $tagName,
                            'color' => $this->generateTagColor($tagName)
                        ]);
                    }
                }
            }
        }

        $result['tags'] = array_values($tagSet);
    }

    /**
     * 为标签生成颜色
     */
    private function generateTagColor(string $tagName): string
    {
        $colors = [
            '#3b82f6', '#ef4444', '#10b981', '#f59e0b',
            '#8b5cf6', '#06b6d4', '#84cc16', '#f97316',
            '#ec4899', '#6366f1', '#14b8a6', '#eab308'
        ];

        $hash = crc32($tagName);
        $index = abs($hash) % count($colors);

        return $colors[$index];
    }
}