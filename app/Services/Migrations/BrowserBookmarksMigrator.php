<?php

namespace App\Services\Migrations;

use Exception;
use DOMDocument;
use DOMXPath;

/**
 * 浏览器书签迁移器
 *
 * 支持从 Chrome、Firefox、Edge、Safari 等浏览器导入书签
 */
class BrowserBookmarksMigrator extends BaseMigrator
{
    public function getName(): string
    {
        return '浏览器书签';
    }

    public function getDescription(): string
    {
        return '从浏览器书签文件 (HTML) 导入书签数据';
    }

    public function getSupportedFormats(): array
    {
        return ['html', 'htm'];
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

        $content = strtolower($content);
        $confidence = 0;

        // 检查 HTML 书签文件特征
        if (strpos($content, '<!doctype netscape-bookmark-file-1>') !== false) {
            $confidence += 40;
        }

        if (strpos($content, '<meta http-equiv="content-type" content="text/html; charset=utf-8">') !== false) {
            $confidence += 20;
        }

        if (strpos($content, '<title>bookmarks</title>') !== false) {
            $confidence += 20;
        }

        if (strpos($content, '<h1>bookmarks</h1>') !== false ||
            strpos($content, '<h1>书签</h1>') !== false) {
            $confidence += 15;
        }

        if (strpos($content, '<dt><a href=') !== false) {
            $confidence += 20;
        }

        if (strpos($content, 'add_date=') !== false) {
            $confidence += 10;
        }

        // Chrome 特征
        if (strpos($content, 'chrome://') !== false) {
            $confidence += 5;
        }

        // Firefox 特征
        if (strpos($content, 'places-node') !== false) {
            $confidence += 5;
        }

        return min($confidence, 95);
    }

    public function validate($input): array
    {
        $errors = [];

        if (!is_string($input)) {
            $errors[] = '输入必须是字符串或文件路径';
            throw new Exception(implode('; ', $errors));
        }

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
        }

        // 验证 HTML 格式
        $content = $this->convertToUtf8($content);
        if (!$this->validateFileFormat($content, ['html'])) {
            $errors[] = '文件格式不是有效的 HTML 书签文件';
        }

        if (!empty($errors)) {
            throw new Exception(implode('; ', $errors));
        }

        return ['valid' => true];
    }

    public function parse($input, array $options = []): array
    {
        $this->log('开始解析浏览器书签数据');

        $content = '';
        if (file_exists($input)) {
            $content = file_get_contents($input);
        } else {
            $content = $input;
        }

        $content = $this->convertToUtf8($content);

        // 创建 DOM 文档
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($content);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        $result = [
            'categories' => [],
            'bookmarks' => [],
            'tags' => [],
            'users' => []
        ];

        // 解析书签结构
        $this->parseBookmarkStructure($xpath, $result, $options);

        $this->log("浏览器书签解析完成: " .
                  count($result['categories']) . " 个分类, " .
                  count($result['bookmarks']) . " 个书签");

        return $result;
    }

    /**
     * 解析书签结构
     */
    private function parseBookmarkStructure(DOMXPath $xpath, array &$result, array $options): void
    {
        $categories = [];
        $bookmarks = [];
        $categoryIdCounter = 1;

        // 查找所有书签文件夹和链接
        $this->parseBookmarkNode($xpath, $doc->documentElement, $categories, $bookmarks, $categoryIdCounter, null);

        // 处理分类
        foreach ($categories as $category) {
            $result['categories'][] = $this->normalizeCategory($category);
        }

        // 处理书签
        foreach ($bookmarks as $bookmark) {
            $result['bookmarks'][] = $this->normalizeBookmark($bookmark);
        }

        // 提取标签（从书签描述或其他字段）
        $this->extractTags($result);
    }

    /**
     * 递归解析书签节点
     */
    private function parseBookmarkNode(DOMXPath $xpath, $node, array &$categories, array &$bookmarks, int &$categoryIdCounter, ?int $parentId): void
    {
        // 查找文件夹 (H3 标签)
        $folders = $xpath->query('.//h3', $node);
        foreach ($folders as $folder) {
            $categoryName = trim($folder->textContent);
            if (empty($categoryName)) {
                continue;
            }

            $categoryId = $categoryIdCounter++;
            $category = [
                'id' => $categoryId,
                'name' => $categoryName,
                'description' => '',
                'icon' => 'fas fa-folder',
                'parent_id' => $parentId,
                'sort_order' => count($categories)
            ];

            // 检查特殊属性
            $folderDate = $folder->getAttribute('add_date');
            if ($folderDate) {
                $category['created_at'] = date('Y-m-d H:i:s', intval($folderDate));
            }

            $categories[] = $category;

            // 递归处理子文件夹
            $nextDL = $folder->nextSibling;
            while ($nextDL && $nextDL->nodeName !== 'dl') {
                $nextDL = $nextDL->nextSibling;
            }

            if ($nextDL) {
                $this->parseBookmarkNode($xpath, $nextDL, $categories, $bookmarks, $categoryIdCounter, $categoryId);
            }
        }

        // 查找书签链接 (A 标签)
        $links = $xpath->query('.//dt/a[@href]', $node);
        foreach ($links as $link) {
            $url = $link->getAttribute('href');
            $title = trim($link->textContent);

            if (empty($url) || empty($title)) {
                continue;
            }

            // 跳过浏览器内部链接
            if (strpos($url, 'chrome://') === 0 ||
                strpos($url, 'about:') === 0 ||
                strpos($url, 'moz-extension://') === 0) {
                continue;
            }

            $bookmark = [
                'title' => $title,
                'url' => $url,
                'description' => '',
                'category_id' => $parentId,
                'sort_order' => count($bookmarks)
            ];

            // 解析书签属性
            $addDate = $link->getAttribute('add_date');
            if ($addDate) {
                $bookmark['created_at'] = date('Y-m-d H:i:s', intval($addDate));
            }

            $lastModified = $link->getAttribute('last_modified');
            if ($lastModified) {
                $bookmark['updated_at'] = date('Y-m-d H:i:s', intval($lastModified));
            }

            $icon = $link->getAttribute('icon');
            if ($icon) {
                $bookmark['favicon_url'] = $icon;
            }

            // 检查描述 (DD 标签)
            $dt = $link->parentNode;
            $dd = $dt->nextSibling;
            while ($dd && $dd->nodeName !== 'dd') {
                $dd = $dd->nextSibling;
            }

            if ($dd) {
                $description = trim($dd->textContent);
                if (!empty($description)) {
                    $bookmark['description'] = $description;
                }
            }

            // 解析标签（从描述或其他属性）
            $bookmark['tags'] = $this->parseBookmarkTags($bookmark);

            $bookmarks[] = $bookmark;
        }
    }

    /**
     * 从书签中解析标签
     */
    private function parseBookmarkTags(array $bookmark): array
    {
        $tags = [];

        // 从描述中提取标签（格式：#tag1 #tag2）
        if (!empty($bookmark['description'])) {
            $description = $bookmark['description'];
            if (preg_match_all('/#([^\s#]+)/', $description, $matches)) {
                foreach ($matches[1] as $tagName) {
                    $tags[] = ['name' => $tagName];
                }
            }
        }

        // 从URL中推断标签
        $domain = $this->extractDomain($bookmark['url']);
        if (!empty($domain)) {
            // 根据域名添加标签
            $domainTags = $this->getDomainTags($domain);
            foreach ($domainTags as $tag) {
                $tags[] = ['name' => $tag];
            }
        }

        return $tags;
    }

    /**
     * 根据域名获取相关标签
     */
    private function getDomainTags(string $domain): array
    {
        $domainTagMap = [
            'github.com' => ['开发', '代码'],
            'stackoverflow.com' => ['开发', '问答'],
            'google.com' => ['搜索'],
            'baidu.com' => ['搜索'],
            'youtube.com' => ['视频'],
            'bilibili.com' => ['视频'],
            'zhihu.com' => ['知识', '问答'],
            'weibo.com' => ['社交'],
            'twitter.com' => ['社交'],
            'facebook.com' => ['社交'],
            'linkedin.com' => ['社交', '职业'],
            'amazon.com' => ['购物'],
            'taobao.com' => ['购物'],
            'tmall.com' => ['购物'],
            'jd.com' => ['购物'],
            'netflix.com' => ['娱乐', '视频'],
            'spotify.com' => ['音乐'],
            'news.ycombinator.com' => ['新闻', '技术'],
            'reddit.com' => ['社区'],
            'medium.com' => ['博客'],
            'juejin.cn' => ['开发', '技术'],
            'csdn.net' => ['开发', '技术'],
            'cnblogs.com' => ['博客', '技术']
        ];

        return $domainTagMap[$domain] ?? [];
    }

    /**
     * 提取所有标签到结果中
     */
    private function extractTags(array &$result): void
    {
        $tagSet = [];

        foreach ($result['bookmarks'] as $bookmark) {
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