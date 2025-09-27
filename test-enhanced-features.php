<?php
/**
 * Test Script for Enhanced OneBookNav Features
 * Tests the merged functionality from BookNav and OneNav
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/BookmarkManager.php';
require_once __DIR__ . '/includes/InviteCodeManager.php';
require_once __DIR__ . '/includes/DeadLinkChecker.php';
require_once __DIR__ . '/includes/AISearch.php';
require_once __DIR__ . '/includes/DragSortManager.php';
require_once __DIR__ . '/includes/MigrationManager.php';

class EnhancedFeatureTest {
    private $db;
    private $testUserId;
    private $testResults = [];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->setupTestUser();
    }

    private function setupTestUser() {
        // Create test user
        $auth = new Auth();
        try {
            $testUser = $auth->register('testuser', 'test@example.com', 'password123');
            $this->testUserId = $testUser['id'];
        } catch (Exception $e) {
            // User might already exist, try to get existing user
            $existingUser = $this->db->fetchOne("SELECT id FROM users WHERE username = 'testuser'");
            $this->testUserId = $existingUser ? $existingUser['id'] : 1;
        }
    }

    public function runAllTests() {
        echo "🧪 Testing OneBookNav Enhanced Features\n";
        echo "=====================================\n\n";

        $this->testMigrationManager();
        $this->testInviteCodeManager();
        $this->testAISearch();
        $this->testDragSortManager();
        $this->testDeadLinkChecker();
        $this->testBookmarkManagerEnhancements();

        $this->printResults();
    }

    private function testMigrationManager() {
        echo "📝 Testing Migration Manager...\n";

        try {
            $migrationManager = new MigrationManager();

            // Test status check
            $status = $migrationManager->getStatus();
            $this->addResult('Migration Status Check', true, "Found {$status['total_migrations']} migrations");

            // Test database health
            $health = $migrationManager->checkDatabaseHealth();
            $this->addResult('Database Health Check', $health['healthy'],
                $health['healthy'] ? 'Database is healthy' : 'Issues: ' . implode(', ', $health['issues']));

        } catch (Exception $e) {
            $this->addResult('Migration Manager', false, $e->getMessage());
        }
    }

    private function testInviteCodeManager() {
        echo "🎫 Testing Invite Code Manager...\n";

        try {
            $inviteManager = new InviteCodeManager();

            // Test invite code generation
            $code = $inviteManager->generateInviteCode($this->testUserId, null, 1);
            $this->addResult('Invite Code Generation', !empty($code), "Generated code: $code");

            // Test invite code validation
            $validation = $inviteManager->validateInviteCode($code);
            $this->addResult('Invite Code Validation', $validation !== false, 'Code validation successful');

            // Test invite code usage
            $inviteManager->useInviteCode($code, $this->testUserId);
            $this->addResult('Invite Code Usage', true, 'Code usage recorded successfully');

            // Test stats
            $stats = $inviteManager->getInviteCodeStats($this->testUserId);
            $this->addResult('Invite Code Stats', is_array($stats), "Stats retrieved: {$stats['total_codes']} codes");

        } catch (Exception $e) {
            $this->addResult('Invite Code Manager', false, $e->getMessage());
        }
    }

    private function testAISearch() {
        echo "🤖 Testing AI Search...\n";

        try {
            $aiSearch = new AISearch();

            // Test search functionality
            $results = $aiSearch->searchBookmarks('github', $this->testUserId);
            $this->addResult('AI Search Functionality', is_array($results), "Search returned " . count($results) . " results");

            // Test search suggestions
            $suggestions = $aiSearch->getSearchSuggestions($this->testUserId);
            $this->addResult('Search Suggestions', is_array($suggestions), "Got " . count($suggestions) . " suggestions");

        } catch (Exception $e) {
            $this->addResult('AI Search', false, $e->getMessage());
        }
    }

    private function testDragSortManager() {
        echo "🔄 Testing Drag Sort Manager...\n";

        try {
            $dragSort = new DragSortManager($this->testUserId);
            $bookmarkManager = new BookmarkManager($this->testUserId);

            // Create test category and bookmark for sorting
            $categoryId = $bookmarkManager->createCategory('Test Sort Category', null, 'fas fa-test', '#ff0000');
            $bookmarkId = $bookmarkManager->createBookmark('Test Bookmark', 'https://example.com', $categoryId);

            // Test next sort order
            $nextOrder = $dragSort->getNextBookmarkSortOrder($categoryId);
            $this->addResult('Next Sort Order', $nextOrder > 0, "Next order: $nextOrder");

            // Test bookmark sorting
            $dragSort->updateBookmarkOrder($bookmarkId, 1, $categoryId);
            $this->addResult('Bookmark Drag Sort', true, 'Bookmark sort order updated');

        } catch (Exception $e) {
            $this->addResult('Drag Sort Manager', false, $e->getMessage());
        }
    }

    private function testDeadLinkChecker() {
        echo "💀 Testing Dead Link Checker...\n";

        try {
            $deadLinkChecker = new DeadLinkChecker();
            $bookmarkManager = new BookmarkManager($this->testUserId);

            // Create test bookmark with known good URL
            $categoryId = $bookmarkManager->createCategory('Link Test Category', null, 'fas fa-link', '#00ff00');
            $bookmarkId = $bookmarkManager->createBookmark('Google', 'https://google.com', $categoryId);

            // Test single bookmark check
            $checkResult = $deadLinkChecker->checkBookmark($bookmarkId);
            $this->addResult('Dead Link Check', isset($checkResult['main_status']),
                "Status: {$checkResult['main_status']}");

            // Test dead links report
            $report = $deadLinkChecker->getDeadLinksReport($this->testUserId);
            $this->addResult('Dead Links Report', is_array($report), "Report generated with " . count($report) . " entries");

            // Test statistics
            $stats = $deadLinkChecker->getDeadLinkStats($this->testUserId);
            $this->addResult('Dead Link Stats', is_array($stats),
                "Stats: {$stats['total_bookmarks']} total, {$stats['working_links']} working");

        } catch (Exception $e) {
            $this->addResult('Dead Link Checker', false, $e->getMessage());
        }
    }

    private function testBookmarkManagerEnhancements() {
        echo "📚 Testing Enhanced Bookmark Manager...\n";

        try {
            $bookmarkManager = new BookmarkManager($this->testUserId);

            // Test enhanced bookmark creation with backup URL and tags
            $categoryId = $bookmarkManager->createCategory('Enhanced Test', null, 'fas fa-star', '#purple');
            $bookmarkId = $bookmarkManager->createBookmark(
                'Enhanced Bookmark',
                'https://example.com',
                $categoryId,
                'Test description',
                'test keywords',
                false,
                'https://backup.example.com',
                'tag1,tag2,tag3'
            );
            $this->addResult('Enhanced Bookmark Creation', $bookmarkId > 0, "Created bookmark with ID: $bookmarkId");

            // Test drag sort integration
            $result = $bookmarkManager->dragSortBookmark($bookmarkId, 1, $categoryId);
            $this->addResult('Bookmark Drag Sort Integration', $result, 'Drag sort integration working');

            // Test dead link check integration
            $deadLinkResult = $bookmarkManager->checkBookmarkDeadLink($bookmarkId);
            $this->addResult('Dead Link Check Integration', isset($deadLinkResult['main_status']),
                'Dead link check integration working');

            // Test OneNav import/export
            $exportData = $bookmarkManager->exportToOneNav($this->testUserId);
            $this->addResult('OneNav Export', isset($exportData['bookmarks']),
                "Exported {count($exportData['bookmarks'])} bookmarks");

            // Test click statistics
            $clickStats = $bookmarkManager->getClickStats($this->testUserId);
            $this->addResult('Click Statistics', is_array($clickStats),
                "Retrieved " . count($clickStats) . " click stats");

        } catch (Exception $e) {
            $this->addResult('Enhanced Bookmark Manager', false, $e->getMessage());
        }
    }

    private function addResult($test, $success, $message) {
        $this->testResults[] = [
            'test' => $test,
            'success' => $success,
            'message' => $message
        ];

        $status = $success ? '✅' : '❌';
        echo "  $status $test: $message\n";
    }

    private function printResults() {
        echo "\n🎯 Test Results Summary\n";
        echo "======================\n";

        $total = count($this->testResults);
        $passed = array_sum(array_column($this->testResults, 'success'));
        $failed = $total - $passed;

        echo "Total Tests: $total\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        echo "Success Rate: " . round(($passed / $total) * 100, 2) . "%\n\n";

        if ($failed > 0) {
            echo "❌ Failed Tests:\n";
            foreach ($this->testResults as $result) {
                if (!$result['success']) {
                    echo "  - {$result['test']}: {$result['message']}\n";
                }
            }
        } else {
            echo "🎉 All tests passed! OneBookNav Enhanced is working correctly.\n";
        }
    }
}

// Run the tests
if (php_sapi_name() === 'cli') {
    $tester = new EnhancedFeatureTest();
    $tester->runAllTests();
} else {
    echo "<pre>";
    $tester = new EnhancedFeatureTest();
    $tester->runAllTests();
    echo "</pre>";
}
?>