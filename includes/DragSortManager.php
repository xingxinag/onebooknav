<?php
/**
 * Drag and Drop Sort Manager - merged from OneNav
 * Handles drag and drop functionality for bookmarks and categories
 */

class DragSortManager {
    private $db;
    private $userId;

    public function __construct($userId = null) {
        $this->db = Database::getInstance();
        $this->userId = $userId;
    }

    /**
     * Update bookmark sort order after drag and drop
     */
    public function updateBookmarkOrder($bookmarkId, $newPosition, $categoryId = null) {
        try {
            $this->db->beginTransaction();

            // Get current bookmark info
            $bookmark = $this->db->fetchOne("SELECT * FROM bookmarks WHERE id = :id", ['id' => $bookmarkId]);
            if (!$bookmark) {
                throw new Exception("Bookmark not found");
            }

            // Check permissions
            if ($this->userId && $bookmark['user_id'] != $this->userId) {
                throw new Exception("Permission denied");
            }

            $oldCategoryId = $bookmark['category_id'];
            $newCategoryId = $categoryId ?: $oldCategoryId;

            // If moving to different category
            if ($oldCategoryId != $newCategoryId) {
                // Update category
                $this->db->query("UPDATE bookmarks SET category_id = :category_id WHERE id = :id", [
                    'category_id' => $newCategoryId,
                    'id' => $bookmarkId
                ]);

                // Reorder old category
                $this->reorderBookmarksInCategory($oldCategoryId);
            }

            // Get all bookmarks in target category
            $sql = "SELECT id, sort_order FROM bookmarks WHERE category_id = :category_id";
            $params = ['category_id' => $newCategoryId];

            if ($this->userId) {
                $sql .= " AND (user_id = :user_id OR is_private = 0)";
                $params['user_id'] = $this->userId;
            }

            $sql .= " ORDER BY sort_order ASC";
            $bookmarks = $this->db->query($sql, $params);

            // Remove the moved bookmark from the list
            $filteredBookmarks = array_filter($bookmarks, function($b) use ($bookmarkId) {
                return $b['id'] != $bookmarkId;
            });

            // Insert the moved bookmark at new position
            $newList = array_values($filteredBookmarks);
            array_splice($newList, $newPosition, 0, [['id' => $bookmarkId]]);

            // Update sort orders
            foreach ($newList as $index => $item) {
                $this->db->query("UPDATE bookmarks SET sort_order = :sort_order WHERE id = :id", [
                    'sort_order' => $index + 1,
                    'id' => $item['id']
                ]);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Update category sort order
     */
    public function updateCategoryOrder($categoryId, $newPosition) {
        try {
            $this->db->beginTransaction();

            // Get current category info
            $category = $this->db->fetchOne("SELECT * FROM categories WHERE id = :id", ['id' => $categoryId]);
            if (!$category) {
                throw new Exception("Category not found");
            }

            // Check permissions
            if ($this->userId && $category['user_id'] != $this->userId) {
                throw new Exception("Permission denied");
            }

            // Get all categories at the same level
            $sql = "SELECT id, sort_order FROM categories WHERE parent_id";
            $params = [];

            if ($category['parent_id']) {
                $sql .= " = :parent_id";
                $params['parent_id'] = $category['parent_id'];
            } else {
                $sql .= " IS NULL";
            }

            if ($this->userId) {
                $sql .= " AND (user_id = :user_id OR is_private = 0)";
                $params['user_id'] = $this->userId;
            }

            $sql .= " ORDER BY sort_order ASC";
            $categories = $this->db->query($sql, $params);

            // Remove the moved category from the list
            $filteredCategories = array_filter($categories, function($c) use ($categoryId) {
                return $c['id'] != $categoryId;
            });

            // Insert the moved category at new position
            $newList = array_values($filteredCategories);
            array_splice($newList, $newPosition, 0, [['id' => $categoryId]]);

            // Update sort orders
            foreach ($newList as $index => $item) {
                $this->db->query("UPDATE categories SET sort_order = :sort_order WHERE id = :id", [
                    'sort_order' => $index + 1,
                    'id' => $item['id']
                ]);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Reorder bookmarks in a category to fill gaps
     */
    private function reorderBookmarksInCategory($categoryId) {
        $sql = "SELECT id FROM bookmarks WHERE category_id = :category_id";
        $params = ['category_id' => $categoryId];

        if ($this->userId) {
            $sql .= " AND (user_id = :user_id OR is_private = 0)";
            $params['user_id'] = $this->userId;
        }

        $sql .= " ORDER BY sort_order ASC";
        $bookmarks = $this->db->query($sql, $params);

        foreach ($bookmarks as $index => $bookmark) {
            $this->db->query("UPDATE bookmarks SET sort_order = :sort_order WHERE id = :id", [
                'sort_order' => $index + 1,
                'id' => $bookmark['id']
            ]);
        }
    }

    /**
     * Get next available sort order for a category
     */
    public function getNextCategorySortOrder($parentId = null) {
        $sql = "SELECT MAX(sort_order) as max_order FROM categories WHERE parent_id";
        $params = [];

        if ($parentId) {
            $sql .= " = :parent_id";
            $params['parent_id'] = $parentId;
        } else {
            $sql .= " IS NULL";
        }

        if ($this->userId) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $this->userId;
        }

        $result = $this->db->fetchOne($sql, $params);
        return ($result['max_order'] ?? 0) + 1;
    }

    /**
     * Get next available sort order for bookmarks in a category
     */
    public function getNextBookmarkSortOrder($categoryId) {
        $sql = "SELECT MAX(sort_order) as max_order FROM bookmarks WHERE category_id = :category_id";
        $params = ['category_id' => $categoryId];

        if ($this->userId) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $this->userId;
        }

        $result = $this->db->fetchOne($sql, $params);
        return ($result['max_order'] ?? 0) + 1;
    }
}