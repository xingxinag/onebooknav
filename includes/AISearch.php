<?php
/**
 * AI Search functionality - merged from OneNav
 * Intelligent bookmark search with AI-powered matching
 */

class AISearch {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * AI-powered search that matches user queries with bookmarks
     * Combines keyword matching with intelligent relevance scoring
     */
    public function searchBookmarks($query, $userId = null) {
        if (empty($query)) {
            return [];
        }

        // Clean and prepare search terms
        $searchTerms = $this->prepareSearchTerms($query);

        // Build base SQL
        $sql = "SELECT b.*, c.name as category_name, c.color as category_color
                FROM bookmarks b
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE (b.is_private = 0";

        $params = [];

        if ($userId) {
            $sql .= " OR b.user_id = :user_id";
            $params['user_id'] = $userId;
        }

        $sql .= ") AND (";

        // Add search conditions for each term
        $searchConditions = [];
        foreach ($searchTerms as $i => $term) {
            $paramKey = "term_$i";
            $searchConditions[] = "(
                b.title LIKE :$paramKey OR
                b.url LIKE :$paramKey OR
                b.description LIKE :$paramKey OR
                b.tags LIKE :$paramKey OR
                c.name LIKE :$paramKey
            )";
            $params[$paramKey] = "%$term%";
        }

        $sql .= implode(' AND ', $searchConditions);
        $sql .= ") ORDER BY b.click_count DESC, b.created_at DESC";

        $results = $this->db->query($sql, $params);

        // Apply AI scoring
        return $this->applyAIScoring($results, $query);
    }

    /**
     * Prepare search terms for better matching
     */
    private function prepareSearchTerms($query) {
        // Remove special characters and split by space
        $query = preg_replace('/[^\w\s\u4e00-\u9fff]/u', ' ', $query);
        $terms = array_filter(explode(' ', $query), function($term) {
            return strlen(trim($term)) > 1;
        });

        return array_map('trim', $terms);
    }

    /**
     * Apply AI-like scoring to rank results by relevance
     */
    private function applyAIScoring($results, $originalQuery) {
        $scored = [];

        foreach ($results as $bookmark) {
            $score = $this->calculateRelevanceScore($bookmark, $originalQuery);
            $bookmark['ai_score'] = $score;
            $scored[] = $bookmark;
        }

        // Sort by AI score descending
        usort($scored, function($a, $b) {
            return $b['ai_score'] <=> $a['ai_score'];
        });

        return $scored;
    }

    /**
     * Calculate relevance score for a bookmark
     */
    private function calculateRelevanceScore($bookmark, $query) {
        $score = 0;
        $queryLower = strtolower($query);

        // Title match (highest weight)
        if (stripos($bookmark['title'], $query) !== false) {
            $score += 100;
            if (stripos(strtolower($bookmark['title']), $queryLower) === 0) {
                $score += 50; // Bonus for starting match
            }
        }

        // URL match
        if (stripos($bookmark['url'], $query) !== false) {
            $score += 60;
        }

        // Description match
        if (!empty($bookmark['description']) && stripos($bookmark['description'], $query) !== false) {
            $score += 40;
        }

        // Tags match
        if (!empty($bookmark['tags']) && stripos($bookmark['tags'], $query) !== false) {
            $score += 70;
        }

        // Category match
        if (!empty($bookmark['category_name']) && stripos($bookmark['category_name'], $query) !== false) {
            $score += 30;
        }

        // Click count boost (popular items get slight boost)
        $score += min($bookmark['click_count'] * 2, 20);

        // Freshness factor (newer items get slight boost)
        $daysSinceCreated = (time() - strtotime($bookmark['created_at'])) / (24 * 60 * 60);
        if ($daysSinceCreated < 30) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Get search suggestions based on popular terms
     */
    public function getSearchSuggestions($userId = null) {
        $sql = "SELECT title, tags, click_count
                FROM bookmarks
                WHERE is_private = 0";

        $params = [];
        if ($userId) {
            $sql .= " OR user_id = :user_id";
            $params['user_id'] = $userId;
        }

        $sql .= " ORDER BY click_count DESC LIMIT 20";

        $results = $this->db->query($sql, $params);
        $suggestions = [];

        foreach ($results as $bookmark) {
            // Extract keywords from titles and tags
            $words = preg_split('/[\s,]+/', $bookmark['title'] . ' ' . $bookmark['tags']);
            foreach ($words as $word) {
                $word = trim(strtolower($word));
                if (strlen($word) > 2 && !in_array($word, $suggestions)) {
                    $suggestions[] = $word;
                }
            }
        }

        return array_slice($suggestions, 0, 10);
    }
}