<?php
/**
 * OneBookNav - OneNav 数据迁移
 *
 * 从 OneNav 项目迁移数据到 OneBookNav
 */

class Migration_003_OnenavMigration
{
    private $sourceDb;
    private $targetDb;

    public function up($db)
    {
        $this->targetDb = $db;

        // 获取 OneNav 数据库路径
        $onenavDbPath = $this->getOnenavDatabasePath();

        if (!$onenavDbPath || !file_exists($onenavDbPath)) {
            error_log("OneNav database not found, skipping migration");
            return true;
        }

        try {
            $this->sourceDb = new PDO("sqlite:{$onenavDbPath}");
            $this->sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->migrateCategories();
            $this->migrateLinks();
            $this->migrateOptions();

            error_log("OneNav migration completed successfully");
            return true;

        } catch (Exception $e) {
            error_log("OneNav migration failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function down($db)
    {
        // 删除迁移的数据
        $db->exec("DELETE FROM categories WHERE description LIKE '%OneNav导入%'");
        $db->exec("DELETE FROM websites WHERE description LIKE '%OneNav导入%'");
        $db->exec("DELETE FROM site_settings WHERE group_name = 'onenav_imported'");

        return true;
    }

    private function getOnenavDatabasePath()
    {
        // 尝试多个可能的 OneNav 数据库位置
        $possiblePaths = [
            $_ENV['ONENAV_DB_PATH'] ?? null,
            __DIR__ . '/../../onenav-main/onenav-main/data/onenav.db3',
            __DIR__ . '/../../../onenav-main/onenav-main/data/onenav.db3',
            __DIR__ . '/../../onenav/data/onenav.db3',
            __DIR__ . '/../../../onenav/data/onenav.db3',
        ];

        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function migrateCategories()
    {
        try {
            $stmt = $this->sourceDb->query("SELECT * FROM on_categorys ORDER BY order_list, id");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categories as $category) {
                // 检查分类是否已存在
                $checkStmt = $this->targetDb->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
                $checkStmt->execute([$category['name']]);

                if ($checkStmt->fetchColumn() > 0) {
                    continue;
                }

                $insertStmt = $this->targetDb->prepare("
                    INSERT INTO categories (
                        name, description, icon, sort_order, created_at
                    ) VALUES (?, ?, ?, ?, ?)
                ");

                $description = "OneNav导入分类";
                if (!empty($category['description'])) {
                    $description .= ": " . $category['description'];
                }

                $insertStmt->execute([
                    $category['name'],
                    $description,
                    $this->mapCategoryIcon($category['font']),
                    $category['order_list'] ?? 0,
                    $this->convertTimestamp($category['add_time'])
                ]);
            }
        } catch (Exception $e) {
            error_log("OneNav category migration error: " . $e->getMessage());
        }
    }

    private function migrateLinks()
    {
        try {
            $stmt = $this->sourceDb->query("
                SELECT l.*, c.name as category_name
                FROM on_links l
                LEFT JOIN on_categorys c ON l.fid = c.id
                ORDER BY l.fid, l.order_list, l.id
            ");
            $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($links as $link) {
                // 获取对应的分类ID
                $categoryId = $this->getCategoryId($link['category_name'] ?: '未分类');

                $insertStmt = $this->targetDb->prepare("
                    INSERT INTO websites (
                        title, url, description, category_id, icon, favicon_url,
                        sort_order, clicks, weight, status, properties,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                // 处理属性
                $properties = json_encode([
                    'onenav_id' => $link['id'],
                    'original_fid' => $link['fid'],
                    'original_weight' => $link['weight'],
                    'original_property' => $link['property'],
                    'migration_source' => 'onenav'
                ]);

                $description = "OneNav导入链接";
                if (!empty($link['description'])) {
                    $description .= ": " . $link['description'];
                }

                $insertStmt->execute([
                    $link['title'],
                    $link['url'],
                    $description,
                    $categoryId,
                    $this->extractIcon($link['icon']),
                    $this->generateFaviconUrl($link['url']),
                    $link['order_list'] ?? 0,
                    $link['click'] ?? 0,
                    $link['weight'] ?? 0,
                    $this->mapLinkStatus($link['property']),
                    $properties,
                    $this->convertTimestamp($link['add_time']),
                    $this->convertTimestamp($link['up_time'])
                ]);
            }
        } catch (Exception $e) {
            error_log("OneNav links migration error: " . $e->getMessage());
        }
    }

    private function migrateOptions()
    {
        try {
            $stmt = $this->sourceDb->query("SELECT * FROM on_options");
            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($options as $option) {
                $insertStmt = $this->targetDb->prepare("
                    INSERT OR REPLACE INTO site_settings (
                        key, value, type, description, group_name
                    ) VALUES (?, ?, ?, ?, ?)
                ");

                $key = 'onenav_' . $option['option_name'];
                $description = "OneNav导入设置: " . ($option['option_description'] ?? $option['option_name']);

                $insertStmt->execute([
                    $key,
                    $option['option_value'],
                    $this->detectValueType($option['option_value']),
                    $description,
                    'onenav_imported'
                ]);
            }
        } catch (Exception $e) {
            error_log("OneNav options migration error: " . $e->getMessage());
        }
    }

    private function getCategoryId($categoryName)
    {
        $stmt = $this->targetDb->prepare("SELECT id FROM categories WHERE name = ?");
        $stmt->execute([$categoryName]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            // 创建新分类
            $insertStmt = $this->targetDb->prepare("
                INSERT INTO categories (name, description, sort_order)
                VALUES (?, ?, ?)
            ");
            $insertStmt->execute([
                $categoryName,
                "OneNav导入分类: " . $categoryName,
                999
            ]);
            $id = $this->targetDb->lastInsertId();
        }

        return $id;
    }

    private function mapCategoryIcon($font)
    {
        if (empty($font)) {
            return 'fas fa-folder';
        }

        // OneNav 图标映射到 FontAwesome
        $iconMap = [
            'icon-search' => 'fas fa-search',
            'icon-social' => 'fas fa-share-alt',
            'icon-news' => 'fas fa-newspaper',
            'icon-code' => 'fas fa-code',
            'icon-game' => 'fas fa-gamepad',
            'icon-study' => 'fas fa-graduation-cap',
            'icon-tool' => 'fas fa-tools',
            'icon-music' => 'fas fa-music',
            'icon-video' => 'fas fa-video',
            'icon-shopping' => 'fas fa-shopping-cart',
        ];

        return $iconMap[$font] ?? 'fas fa-folder';
    }

    private function extractIcon($iconData)
    {
        if (empty($iconData)) {
            return null;
        }

        // 如果是图片URL，返回URL
        if (filter_var($iconData, FILTER_VALIDATE_URL)) {
            return $iconData;
        }

        // 如果是base64图像，保存为文件
        if (strpos($iconData, 'data:image/') === 0) {
            $imageData = base64_decode(explode(',', $iconData)[1]);
            $filename = 'imported_' . md5($iconData) . '.png';
            $filepath = __DIR__ . '/../../public/uploads/icons/' . $filename;

            if (!file_exists(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }

            file_put_contents($filepath, $imageData);
            return '/uploads/icons/' . $filename;
        }

        return null;
    }

    private function generateFaviconUrl($url)
    {
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            return "https://www.google.com/s2/favicons?domain=" . $parsed['host'];
        }
        return null;
    }

    private function mapLinkStatus($property)
    {
        // OneNav property 字段映射到状态
        if (strpos($property, 'disabled') !== false) {
            return 'inactive';
        }
        if (strpos($property, 'broken') !== false) {
            return 'broken';
        }
        return 'active';
    }

    private function convertTimestamp($timestamp)
    {
        if (empty($timestamp)) {
            return null;
        }

        // OneNav 时间戳转换
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function detectValueType($value)
    {
        if (is_numeric($value)) {
            return 'integer';
        }
        if (in_array(strtolower($value), ['true', 'false', '1', '0'])) {
            return 'boolean';
        }
        if (json_decode($value) !== null) {
            return 'json';
        }
        return 'string';
    }

    public function getDescription()
    {
        return "Migrate data from OneNav to OneBookNav";
    }

    public function getVersion()
    {
        return '1.0.0';
    }
}