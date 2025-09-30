<?php

namespace App\Services\Migrations;

use Exception;

/**
 * CSV 数据迁移器
 *
 * 支持从 CSV 文件导入书签数据
 */
class CsvMigrator extends BaseMigrator
{
    public function getName(): string
    {
        return 'CSV';
    }

    public function getDescription(): string
    {
        return '从 CSV 格式文件导入书签数据';
    }

    public function getSupportedFormats(): array
    {
        return ['csv', 'tsv', 'txt'];
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

        $lines = explode("\n", trim($content));
        if (count($lines) < 2) {
            return 0;
        }

        $confidence = 0;

        // 检查是否包含逗号或制表符分隔符
        $firstLine = $lines[0];
        $commaCount = substr_count($firstLine, ',');
        $tabCount = substr_count($firstLine, "\t");
        $semicolonCount = substr_count($firstLine, ';');

        if ($commaCount >= 2 || $tabCount >= 2 || $semicolonCount >= 2) {
            $confidence += 30;
        }

        // 检查常见的书签字段
        $lowerFirstLine = strtolower($firstLine);
        $bookmarkFields = ['title', 'url', 'link', 'name', 'address', 'description', 'category'];
        $fieldMatches = 0;

        foreach ($bookmarkFields as $field) {
            if (strpos($lowerFirstLine, $field) !== false) {
                $fieldMatches++;
            }
        }

        if ($fieldMatches >= 2) {
            $confidence += 40;
        }

        if ($fieldMatches >= 3) {
            $confidence += 20;
        }

        // 检查第二行是否也符合格式
        if (count($lines) > 1) {
            $secondLine = $lines[1];
            $secondCommaCount = substr_count($secondLine, ',');
            $secondTabCount = substr_count($secondLine, "\t");

            if (abs($commaCount - $secondCommaCount) <= 1 || abs($tabCount - $secondTabCount) <= 1) {
                $confidence += 10;
            }
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
                $lines = explode("\n", trim($content));
                if (count($lines) < 2) {
                    $errors[] = 'CSV 文件至少需要包含标题行和一行数据';
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
        $this->log('开始解析 CSV 数据');

        $content = '';
        if (file_exists($input)) {
            $content = file_get_contents($input);
        } else {
            $content = $input;
        }

        $content = $this->convertToUtf8($content);
        $delimiter = $this->detectDelimiter($content);

        $result = [
            'categories' => [],
            'bookmarks' => [],
            'tags' => [],
            'users' => []
        ];

        $lines = explode("\n", trim($content));
        $headers = $this->parseCSVLine($lines[0], $delimiter);
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        // 映射字段名
        $fieldMap = $this->createFieldMap($headers);

        $categories = [];
        $categoryIdCounter = 1;

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }

            $values = $this->parseCSVLine($line, $delimiter);
            if (count($values) < count($headers)) {
                // 补齐缺失的值
                $values = array_pad($values, count($headers), '');
            }

            $row = array_combine($headers, $values);
            $bookmark = $this->extractBookmarkFromRow($row, $fieldMap);

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

        // 提取标签
        $this->extractTagsFromBookmarks($result);

        $this->log("CSV 数据解析完成: " .
                  count($result['categories']) . " 个分类, " .
                  count($result['bookmarks']) . " 个书签, " .
                  count($result['tags']) . " 个标签");

        return $result;
    }

    /**
     * 检测 CSV 分隔符
     */
    private function detectDelimiter(string $content): string
    {
        $lines = explode("\n", $content);
        $firstLine = $lines[0] ?? '';

        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($firstLine, $delimiter);
        }

        arsort($counts);
        $bestDelimiter = array_key_first($counts);

        return $bestDelimiter;
    }

    /**
     * 解析 CSV 行
     */
    private function parseCSVLine(string $line, string $delimiter): array
    {
        // 简单的 CSV 解析，支持引号包围的字段
        $fields = [];
        $field = '';
        $inQuotes = false;
        $quoteChar = '"';

        for ($i = 0; $i < strlen($line); $i++) {
            $char = $line[$i];

            if ($char === $quoteChar) {
                if ($inQuotes && isset($line[$i + 1]) && $line[$i + 1] === $quoteChar) {
                    // 转义的引号
                    $field .= $quoteChar;
                    $i++; // 跳过下一个引号
                } else {
                    // 切换引号状态
                    $inQuotes = !$inQuotes;
                }
            } elseif ($char === $delimiter && !$inQuotes) {
                // 字段分隔符
                $fields[] = $field;
                $field = '';
            } else {
                $field .= $char;
            }
        }

        // 添加最后一个字段
        $fields[] = $field;

        return $fields;
    }

    /**
     * 创建字段映射
     */
    private function createFieldMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));

            // 标题字段
            if (in_array($header, ['title', 'name', '标题', '名称', '网站名称'])) {
                $map['title'] = $index;
            }
            // URL 字段
            elseif (in_array($header, ['url', 'link', 'address', 'href', '网址', '链接', '地址'])) {
                $map['url'] = $index;
            }
            // 描述字段
            elseif (in_array($header, ['description', 'desc', 'note', 'notes', '描述', '说明', '备注'])) {
                $map['description'] = $index;
            }
            // 分类字段
            elseif (in_array($header, ['category', 'folder', 'group', '分类', '文件夹', '分组'])) {
                $map['category'] = $index;
            }
            // 标签字段
            elseif (in_array($header, ['tags', 'tag', 'keywords', '标签', '关键词'])) {
                $map['tags'] = $index;
            }
            // 图标字段
            elseif (in_array($header, ['icon', 'favicon', '图标'])) {
                $map['icon'] = $index;
            }
            // 创建时间
            elseif (in_array($header, ['created', 'created_at', 'date', '创建时间', '添加时间'])) {
                $map['created_at'] = $index;
            }
        }

        return $map;
    }

    /**
     * 从 CSV 行提取书签数据
     */
    private function extractBookmarkFromRow(array $row, array $fieldMap): array
    {
        $bookmark = [];

        foreach ($fieldMap as $field => $index) {
            $value = $row[$index] ?? '';
            $value = trim($value);

            if (!empty($value)) {
                $bookmark[$field] = $value;
            }
        }

        // 处理标签
        if (isset($bookmark['tags'])) {
            $bookmark['tags'] = $this->normalizeTags($bookmark['tags']);
        }

        return $bookmark;
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