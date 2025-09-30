<?php
/**
 * OneBookNav - 分类模型
 */

require_once __DIR__ . '/BaseModel.php';

class CategoryModel extends BaseModel
{
    protected $table = 'categories';
    protected $fillable = [
        'name', 'description', 'icon', 'color', 'sort_order', 'parent_id', 'is_active', 'user_id'
    ];
    protected $casts = [
        'sort_order' => 'integer',
        'parent_id' => 'integer',
        'user_id' => 'integer',
        'is_active' => 'boolean'
    ];

    /**
     * 获取所有顶级分类
     */
    public function getTopLevelCategories($userId = null)
    {
        $sql = "SELECT * FROM {$this->table} WHERE parent_id IS NULL AND is_active = 1";
        $params = [];

        if ($userId !== null) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND user_id IS NULL";
        }

        $sql .= " ORDER BY sort_order ASC, name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取指定分类的子分类
     */
    public function getSubCategories($parentId, $userId = null)
    {
        $sql = "SELECT * FROM {$this->table} WHERE parent_id = ? AND is_active = 1";
        $params = [$parentId];

        if ($userId !== null) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        }

        $sql .= " ORDER BY sort_order ASC, name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取分类树
     */
    public function getCategoryTree($userId = null)
    {
        $topLevel = $this->getTopLevelCategories($userId);
        $tree = [];

        foreach ($topLevel as $category) {
            $category['children'] = $this->getSubCategories($category['id'], $userId);
            $category['website_count'] = $this->getWebsiteCount($category['id']);
            $tree[] = $category;
        }

        return $tree;
    }

    /**
     * 获取分类下的网站数量
     */
    public function getWebsiteCount($categoryId)
    {
        $sql = "SELECT COUNT(*) FROM websites WHERE category_id = ? AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$categoryId]);
        return $stmt->fetchColumn();
    }

    /**
     * 获取分类的完整路径
     */
    public function getCategoryPath($categoryId)
    {
        $path = [];
        $currentId = $categoryId;

        while ($currentId) {
            $category = $this->find($currentId);
            if (!$category) break;

            array_unshift($path, $category);
            $currentId = $category['parent_id'];
        }

        return $path;
    }

    /**
     * 检查分类名称是否已存在
     */
    public function nameExists($name, $parentId = null, $excludeId = null)
    {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE name = ?";
        $params = [$name];

        if ($parentId) {
            $sql .= " AND parent_id = ?";
            $params[] = $parentId;
        } else {
            $sql .= " AND parent_id IS NULL";
        }

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * 获取下一个排序顺序
     */
    public function getNextSortOrder($parentId = null)
    {
        $sql = "SELECT MAX(sort_order) FROM {$this->table}";
        $params = [];

        if ($parentId) {
            $sql .= " WHERE parent_id = ?";
            $params[] = $parentId;
        } else {
            $sql .= " WHERE parent_id IS NULL";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $maxOrder = $stmt->fetchColumn();

        return ($maxOrder ?? 0) + 1;
    }

    /**
     * 更新排序顺序
     */
    public function updateSortOrder($categoryId, $newOrder)
    {
        $category = $this->find($categoryId);
        if (!$category) return false;

        $oldOrder = $category['sort_order'];
        $parentId = $category['parent_id'];

        $this->beginTransaction();

        try {
            // 如果新顺序大于旧顺序，将中间的项目向前移动
            if ($newOrder > $oldOrder) {
                $sql = "UPDATE {$this->table} SET sort_order = sort_order - 1
                        WHERE sort_order > ? AND sort_order <= ?";
                $params = [$oldOrder, $newOrder];
            } else {
                // 如果新顺序小于旧顺序，将中间的项目向后移动
                $sql = "UPDATE {$this->table} SET sort_order = sort_order + 1
                        WHERE sort_order >= ? AND sort_order < ?";
                $params = [$newOrder, $oldOrder];
            }

            if ($parentId) {
                $sql .= " AND parent_id = ?";
                $params[] = $parentId;
            } else {
                $sql .= " AND parent_id IS NULL";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            // 更新当前分类的排序
            $this->update($categoryId, ['sort_order' => $newOrder]);

            $this->commit();
            return true;

        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 移动分类到新的父分类
     */
    public function moveToParent($categoryId, $newParentId)
    {
        $category = $this->find($categoryId);
        if (!$category) return false;

        // 检查是否会创建循环引用
        if ($this->wouldCreateCycle($categoryId, $newParentId)) {
            throw new Exception("Moving category would create a cycle");
        }

        $newSortOrder = $this->getNextSortOrder($newParentId);

        return $this->update($categoryId, [
            'parent_id' => $newParentId,
            'sort_order' => $newSortOrder
        ]);
    }

    /**
     * 检查是否会创建循环引用
     */
    private function wouldCreateCycle($categoryId, $newParentId)
    {
        if (!$newParentId) return false;

        $currentParentId = $newParentId;

        while ($currentParentId) {
            if ($currentParentId == $categoryId) {
                return true;
            }

            $parent = $this->find($currentParentId);
            $currentParentId = $parent ? $parent['parent_id'] : null;
        }

        return false;
    }

    /**
     * 删除分类及其子分类
     */
    public function deleteCascade($categoryId)
    {
        $this->beginTransaction();

        try {
            // 获取所有子分类
            $children = $this->getSubCategories($categoryId);

            // 递归删除子分类
            foreach ($children as $child) {
                $this->deleteCascade($child['id']);
            }

            // 将该分类下的网站移动到默认分类
            $stmt = $this->db->prepare("UPDATE websites SET category_id = 1 WHERE category_id = ?");
            $stmt->execute([$categoryId]);

            // 删除分类
            $this->delete($categoryId);

            $this->commit();
            return true;

        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 搜索分类
     */
    public function searchCategories($keyword, $userId = null, $page = 1, $perPage = 20)
    {
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM {$this->table} WHERE (name LIKE ? OR description LIKE ?) AND is_active = 1";
        $params = ["%{$keyword}%", "%{$keyword}%"];

        if ($userId !== null) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        }

        // 计算总数
        $countStmt = $this->db->prepare(str_replace('SELECT *', 'SELECT COUNT(*)', $sql));
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // 获取数据
        $sql .= " ORDER BY name ASC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * 获取用户的分类
     */
    public function getUserCategories($userId, $includePublic = true)
    {
        $sql = "SELECT * FROM {$this->table} WHERE user_id = ? AND is_active = 1";
        $params = [$userId];

        if ($includePublic) {
            $sql = "SELECT * FROM {$this->table} WHERE (user_id = ? OR user_id IS NULL) AND is_active = 1";
        }

        $sql .= " ORDER BY sort_order ASC, name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取分类统计
     */
    public function getCategoryStats($categoryId)
    {
        $sql = "
            SELECT
                c.*,
                COUNT(w.id) as website_count,
                SUM(w.clicks) as total_clicks,
                MAX(w.updated_at) as last_updated
            FROM {$this->table} c
            LEFT JOIN websites w ON c.id = w.category_id AND w.status = 'active'
            WHERE c.id = ?
            GROUP BY c.id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$categoryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * 批量更新分类状态
     */
    public function updateStatus($categoryIds, $status)
    {
        if (empty($categoryIds)) return false;

        $placeholders = str_repeat('?,', count($categoryIds) - 1) . '?';
        $sql = "UPDATE {$this->table} SET is_active = ? WHERE id IN ({$placeholders})";

        $params = array_merge([$status], $categoryIds);
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 导出分类数据
     */
    public function exportCategories($userId = null)
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1";
        $params = [];

        if ($userId !== null) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        }

        $sql .= " ORDER BY parent_id ASC, sort_order ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}