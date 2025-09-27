<?php
/**
 * OneBookNav API Router
 * RESTful API endpoints for all functionality
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/BookmarkManager.php';
require_once __DIR__ . '/../includes/BackupManager.php';
require_once __DIR__ . '/../includes/AISearch.php';
require_once __DIR__ . '/../includes/DragSortManager.php';
require_once __DIR__ . '/../includes/DeadLinkChecker.php';
require_once __DIR__ . '/../includes/InviteCodeManager.php';

class APIRouter {
    private $auth;
    private $db;
    private $bookmarkManager;
    private $backupManager;
    private $aiSearch;
    private $dragSortManager;
    private $deadLinkChecker;
    private $inviteCodeManager;
    private $method;
    private $endpoint;
    private $params;

    public function __construct() {
        // CORS headers for cross-origin requests
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $this->auth = new Auth();
        $this->db = Database::getInstance();

        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->parseRequest();
    }

    public function route() {
        try {
            switch ($this->endpoint[0] ?? '') {
                case 'auth':
                    return $this->handleAuth();

                case 'categories':
                    return $this->handleCategories();

                case 'bookmarks':
                    return $this->handleBookmarks();

                case 'backup':
                    return $this->handleBackup();

                case 'admin':
                    return $this->handleAdmin();

                case 'settings':
                    return $this->handleSettings();

                case 'search':
                    return $this->handleSearch();

                case 'stats':
                    return $this->handleStats();

                case 'ai-search':
                    return $this->handleAISearch();

                case 'drag-sort':
                    return $this->handleDragSort();

                case 'dead-links':
                    return $this->handleDeadLinks();

                case 'invite-codes':
                    return $this->handleInviteCodes();

                case 'click-tracking':
                    return $this->handleClickTracking();

                default:
                    $this->sendError('Endpoint not found', 404);
            }
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function parseRequest() {
        $requestUri = $_SERVER['REQUEST_URI'];
        $scriptName = $_SERVER['SCRIPT_NAME'];

        // Remove script name from URI
        $path = str_replace(dirname($scriptName), '', $requestUri);
        $path = ltrim($path, '/');

        // Remove query string
        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }

        // Split into segments
        $this->endpoint = array_filter(explode('/', $path));

        // Remove 'api' from path if present
        if ($this->endpoint[0] === 'api') {
            array_shift($this->endpoint);
        }

        // Parse parameters from URL and body
        $this->params = array_merge($_GET, $_POST);

        // Parse JSON body
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $jsonData = json_decode($input, true);
            if ($jsonData) {
                $this->params = array_merge($this->params, $jsonData);
            }
        }
    }

    private function handleAuth() {
        switch ($this->method) {
            case 'POST':
                switch ($this->endpoint[1] ?? '') {
                    case 'login':
                        return $this->login();
                    case 'register':
                        return $this->register();
                    case 'logout':
                        return $this->logout();
                    default:
                        $this->sendError('Auth endpoint not found', 404);
                }
                break;

            case 'GET':
                switch ($this->endpoint[1] ?? '') {
                    case 'user':
                        return $this->getCurrentUser();
                    case 'check':
                        return $this->checkAuth();
                    default:
                        $this->sendError('Auth endpoint not found', 404);
                }
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleCategories() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();
        $this->bookmarkManager = new BookmarkManager($user['id']);

        switch ($this->method) {
            case 'GET':
                if (isset($this->endpoint[1])) {
                    // Get specific category
                    return $this->getCategory($this->endpoint[1]);
                } else {
                    // Get all categories
                    return $this->getCategories();
                }
                break;

            case 'POST':
                return $this->createCategory();

            case 'PUT':
                if (isset($this->endpoint[1])) {
                    return $this->updateCategory($this->endpoint[1]);
                }
                $this->sendError('Category ID required', 400);
                break;

            case 'DELETE':
                if (isset($this->endpoint[1])) {
                    return $this->deleteCategory($this->endpoint[1]);
                }
                $this->sendError('Category ID required', 400);
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleBookmarks() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();
        $this->bookmarkManager = new BookmarkManager($user['id']);

        switch ($this->method) {
            case 'GET':
                if (isset($this->endpoint[1])) {
                    if ($this->endpoint[1] === 'category' && isset($this->endpoint[2])) {
                        return $this->getBookmarksByCategory($this->endpoint[2]);
                    } else {
                        return $this->getBookmark($this->endpoint[1]);
                    }
                } else {
                    return $this->getUserBookmarks();
                }
                break;

            case 'POST':
                if (isset($this->endpoint[1]) && $this->endpoint[1] === 'reorder') {
                    return $this->reorderBookmarks();
                } else {
                    return $this->createBookmark();
                }
                break;

            case 'PUT':
                if (isset($this->endpoint[1])) {
                    return $this->updateBookmark($this->endpoint[1]);
                }
                $this->sendError('Bookmark ID required', 400);
                break;

            case 'DELETE':
                if (isset($this->endpoint[1])) {
                    return $this->deleteBookmark($this->endpoint[1]);
                }
                $this->sendError('Bookmark ID required', 400);
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleBackup() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();
        $this->backupManager = new BackupManager();

        switch ($this->method) {
            case 'GET':
                if (isset($this->endpoint[1])) {
                    switch ($this->endpoint[1]) {
                        case 'list':
                            return $this->listBackups();
                        case 'export':
                            return $this->exportUserData();
                        default:
                            $this->sendError('Backup endpoint not found', 404);
                    }
                } else {
                    return $this->listBackups();
                }
                break;

            case 'POST':
                switch ($this->endpoint[1] ?? '') {
                    case 'create':
                        return $this->createBackup();
                    case 'restore':
                        return $this->restoreBackup();
                    case 'import':
                        return $this->importData();
                    default:
                        $this->sendError('Backup endpoint not found', 404);
                }
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleSearch() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();
        $this->bookmarkManager = new BookmarkManager($user['id']);

        if ($this->method === 'GET' || $this->method === 'POST') {
            $query = $this->params['q'] ?? $this->params['query'] ?? '';

            if (empty($query)) {
                $this->sendError('Search query required', 400);
            }

            $results = $this->bookmarkManager->searchBookmarks($query);
            $this->sendResponse($results);
        } else {
            $this->sendError('Method not allowed', 405);
        }
    }

    private function handleStats() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();
        $this->bookmarkManager = new BookmarkManager($user['id']);

        if ($this->method === 'GET') {
            $stats = $this->bookmarkManager->getStats();
            $this->sendResponse($stats);
        } else {
            $this->sendError('Method not allowed', 405);
        }
    }

    // Auth methods
    private function login() {
        $username = $this->params['username'] ?? '';
        $password = $this->params['password'] ?? '';
        $rememberMe = $this->params['remember_me'] ?? false;

        if (empty($username) || empty($password)) {
            $this->sendError('Username and password required', 400);
        }

        try {
            $user = $this->auth->login($username, $password, $rememberMe);

            $this->sendResponse([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 401);
        }
    }

    private function register() {
        if (!ALLOW_REGISTRATION) {
            $this->sendError('Registration is disabled', 403);
        }

        $username = $this->params['username'] ?? '';
        $password = $this->params['password'] ?? '';
        $email = $this->params['email'] ?? null;

        try {
            $userId = $this->auth->register($username, $password, $email);
            $this->sendResponse(['success' => true, 'user_id' => $userId]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function logout() {
        $this->auth->logout();
        $this->sendResponse(['success' => true]);
    }

    private function getCurrentUser() {
        $user = $this->auth->getCurrentUser();
        if ($user) {
            unset($user['password_hash']);
            $this->sendResponse($user);
        } else {
            $this->sendError('Not authenticated', 401);
        }
    }

    private function checkAuth() {
        $this->sendResponse(['authenticated' => $this->auth->isAuthenticated()]);
    }

    // Category methods
    private function getCategories() {
        $includePrivate = $this->params['include_private'] ?? true;
        $categories = $this->bookmarkManager->getCategories(null, $includePrivate);
        $this->sendResponse($categories);
    }

    private function createCategory() {
        $name = $this->params['name'] ?? '';
        $parentId = $this->params['parent_id'] ?? null;
        $icon = $this->params['icon'] ?? null;
        $color = $this->params['color'] ?? null;
        $isPrivate = $this->params['is_private'] ?? false;

        try {
            $category = $this->bookmarkManager->createCategory($name, $parentId, $icon, $color, $isPrivate);
            $this->sendResponse($category);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function updateCategory($categoryId) {
        try {
            $result = $this->bookmarkManager->updateCategory($categoryId, $this->params);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function deleteCategory($categoryId) {
        try {
            $result = $this->bookmarkManager->deleteCategory($categoryId);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    // Bookmark methods
    private function getUserBookmarks() {
        $includePrivate = $this->params['include_private'] ?? true;
        $bookmarks = $this->bookmarkManager->getUserBookmarks(null, $includePrivate);
        $this->sendResponse($bookmarks);
    }

    private function getBookmarksByCategory($categoryId) {
        $includePrivate = $this->params['include_private'] ?? true;
        $bookmarks = $this->bookmarkManager->getBookmarksByCategory($categoryId, $includePrivate);
        $this->sendResponse($bookmarks);
    }

    private function createBookmark() {
        $title = $this->params['title'] ?? '';
        $url = $this->params['url'] ?? '';
        $categoryId = $this->params['category_id'] ?? null;
        $description = $this->params['description'] ?? null;
        $keywords = $this->params['keywords'] ?? null;
        $isPrivate = $this->params['is_private'] ?? false;

        try {
            $bookmark = $this->bookmarkManager->createBookmark($title, $url, $categoryId, $description, $keywords, $isPrivate);
            $this->sendResponse($bookmark);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function updateBookmark($bookmarkId) {
        try {
            $result = $this->bookmarkManager->updateBookmark($bookmarkId, $this->params);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function deleteBookmark($bookmarkId) {
        try {
            $result = $this->bookmarkManager->deleteBookmark($bookmarkId);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function reorderBookmarks() {
        $bookmarkIds = $this->params['bookmark_ids'] ?? [];

        try {
            $result = $this->bookmarkManager->reorderBookmarks($bookmarkIds);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    // Backup methods
    private function createBackup() {
        $user = $this->auth->getCurrentUser();
        $description = $this->params['description'] ?? null;

        try {
            $backup = $this->backupManager->createBackup($user['id'], $description);
            $this->sendResponse($backup);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function exportUserData() {
        $user = $this->auth->getCurrentUser();
        $format = $this->params['format'] ?? 'json';
        $includePrivate = $this->params['include_private'] ?? true;

        try {
            $data = $this->backupManager->exportUserData($user['id'], $format, $includePrivate);

            // Set appropriate headers for download
            $filename = "onebooknav_export_" . date('Y-m-d') . ".$format";

            switch ($format) {
                case 'json':
                    header('Content-Type: application/json');
                    break;
                case 'html':
                case 'bookmarks_html':
                    header('Content-Type: text/html');
                    break;
                case 'csv':
                    header('Content-Type: text/csv');
                    break;
            }

            header("Content-Disposition: attachment; filename=\"$filename\"");
            echo $data;
            exit;

        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function listBackups() {
        $backups = $this->db->fetchAll("SELECT * FROM backups ORDER BY created_at DESC");
        $this->sendResponse($backups);
    }

    private function importData() {
        // Handle file upload and import
        if (!isset($_FILES['file'])) {
            $this->sendError('No file uploaded', 400);
        }

        $user = $this->auth->getCurrentUser();
        $file = $_FILES['file'];
        $importType = $this->params['type'] ?? 'auto';

        try {
            switch ($importType) {
                case 'booknav':
                    $stats = $this->backupManager->importFromBookNav($file['tmp_name'], $user['id']);
                    break;
                case 'onenav':
                    $stats = $this->backupManager->importFromOneNav($file['tmp_name'], $user['id']);
                    break;
                case 'browser':
                    $stats = $this->backupManager->importFromBrowserBookmarks($file['tmp_name'], $user['id']);
                    break;
                case 'backup':
                    $stats = $this->backupManager->restoreFromBackup($file['tmp_name'], $user['id']);
                    break;
                default:
                    $this->sendError('Invalid import type', 400);
            }

            $this->sendResponse($stats);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    // Admin methods
    private function handleAdmin() {
        $this->auth->requireAdmin();

        switch ($this->method) {
            case 'GET':
                switch ($this->endpoint[1] ?? '') {
                    case 'users':
                        return $this->getUsers();
                    case 'stats':
                        return $this->getAdminStats();
                    case 'logs':
                        return $this->getLogs();
                    default:
                        return $this->sendResponse(['message' => 'Admin panel API']);
                }
                break;

            case 'POST':
                switch ($this->endpoint[1] ?? '') {
                    case 'users':
                        return $this->createUser();
                    case 'backup':
                        return $this->createSystemBackup();
                    default:
                        $this->sendError('Admin endpoint not found', 404);
                }
                break;

            case 'PUT':
                if (isset($this->endpoint[1]) && $this->endpoint[1] === 'users' && isset($this->endpoint[2])) {
                    return $this->updateUser($this->endpoint[2]);
                }
                $this->sendError('User ID required', 400);
                break;

            case 'DELETE':
                if (isset($this->endpoint[1]) && $this->endpoint[1] === 'users' && isset($this->endpoint[2])) {
                    return $this->deleteUser($this->endpoint[2]);
                }
                $this->sendError('User ID required', 400);
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleSettings() {
        $this->auth->requireAuth();

        switch ($this->method) {
            case 'GET':
                return $this->getSettings();

            case 'POST':
            case 'PUT':
                return $this->updateSettings();

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function getUsers() {
        $users = $this->db->fetchAll("SELECT id, username, email, role, created_at, last_login, is_active FROM users ORDER BY created_at DESC");
        $this->sendResponse($users);
    }

    private function createUser() {
        $username = $this->params['username'] ?? '';
        $password = $this->params['password'] ?? '';
        $email = $this->params['email'] ?? null;
        $role = $this->params['role'] ?? 'user';

        try {
            $userId = $this->auth->register($username, $password, $email, $role);
            $this->sendResponse(['success' => true, 'user_id' => $userId]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function updateUser($userId) {
        $allowedFields = ['username', 'email', 'role', 'is_active'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($this->params[$field])) {
                $updates[] = "$field = ?";
                $params[] = $this->params[$field];
            }
        }

        if (empty($updates)) {
            $this->sendError('No valid fields to update', 400);
        }

        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";

        try {
            $result = $this->db->execute($sql, $params);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function deleteUser($userId) {
        try {
            $result = $this->db->execute("DELETE FROM users WHERE id = ?", [$userId]);
            $this->sendResponse(['success' => $result]);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 400);
        }
    }

    private function getAdminStats() {
        $stats = [
            'total_users' => $this->db->fetchOne("SELECT COUNT(*) FROM users"),
            'total_bookmarks' => $this->db->fetchOne("SELECT COUNT(*) FROM bookmarks"),
            'total_categories' => $this->db->fetchOne("SELECT COUNT(*) FROM categories"),
            'disk_usage' => $this->calculateDiskUsage()
        ];
        $this->sendResponse($stats);
    }

    private function getLogs() {
        $logType = $this->params['type'] ?? 'app';
        $limit = min((int)($this->params['limit'] ?? 100), 1000);

        // For now, return mock logs since we don't have a logging system implemented
        $this->sendResponse([
            'logs' => [],
            'message' => 'Log viewing not implemented yet'
        ]);
    }

    private function createSystemBackup() {
        $user = $this->auth->getCurrentUser();
        if (!$this->backupManager) {
            $this->backupManager = new BackupManager();
        }
        try {
            $backup = $this->backupManager->createBackup($user['id'], 'System backup');
            $this->sendResponse($backup);
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function getSettings() {
        $settings = $this->db->fetchAll("SELECT setting_key, setting_value, setting_type FROM settings WHERE is_public = 1");
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['setting_value'];
        }
        $this->sendResponse($result);
    }

    private function updateSettings() {
        $this->auth->requireAdmin();

        foreach ($this->params as $key => $value) {
            if (in_array($key, ['site_title', 'site_description', 'allow_registration', 'default_theme'])) {
                $this->db->execute(
                    "UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?",
                    [$value, date('Y-m-d H:i:s'), $key]
                );
            }
        }

        $this->sendResponse(['success' => true]);
    }

    private function calculateDiskUsage() {
        $dataPath = __DIR__ . '/../data';
        if (!is_dir($dataPath)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataPath));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    // Enhanced Features Handlers

    private function handleAISearch() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();

        if (!$this->aiSearch) {
            $this->aiSearch = new AISearch();
        }

        if ($this->method === 'GET' || $this->method === 'POST') {
            $query = $this->params['q'] ?? $this->params['query'] ?? '';

            if (empty($query)) {
                $this->sendError('Search query required', 400);
            }

            try {
                $results = $this->aiSearch->searchBookmarks($query, $user['id']);
                $this->sendResponse($results);
            } catch (Exception $e) {
                $this->sendError($e->getMessage(), 500);
            }
        } else {
            $this->sendError('Method not allowed', 405);
        }
    }

    private function handleDragSort() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();

        if (!$this->dragSortManager) {
            $this->dragSortManager = new DragSortManager();
        }

        switch ($this->method) {
            case 'POST':
                $type = $this->endpoint[1] ?? '';

                if ($type === 'bookmarks') {
                    $bookmarkIds = $this->params['bookmark_ids'] ?? [];
                    $categoryId = $this->params['category_id'] ?? null;

                    try {
                        $result = $this->dragSortManager->updateBookmarkOrder($bookmarkIds, $categoryId, $user['id']);
                        $this->sendResponse(['success' => $result]);
                    } catch (Exception $e) {
                        $this->sendError($e->getMessage(), 400);
                    }

                } elseif ($type === 'categories') {
                    $categoryIds = $this->params['category_ids'] ?? [];

                    try {
                        $result = $this->dragSortManager->updateCategoryOrder($categoryIds, $user['id']);
                        $this->sendResponse(['success' => $result]);
                    } catch (Exception $e) {
                        $this->sendError($e->getMessage(), 400);
                    }
                } else {
                    $this->sendError('Invalid sort type', 400);
                }
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleDeadLinks() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();

        if (!$this->deadLinkChecker) {
            $this->deadLinkChecker = new DeadLinkChecker();
        }

        switch ($this->method) {
            case 'GET':
                $action = $this->endpoint[1] ?? '';

                if ($action === 'report') {
                    try {
                        $report = $this->deadLinkChecker->getDeadLinksReport($user['id']);
                        $this->sendResponse($report);
                    } catch (Exception $e) {
                        $this->sendError($e->getMessage(), 500);
                    }
                } else {
                    $this->sendError('Invalid action', 400);
                }
                break;

            case 'POST':
                $action = $this->endpoint[1] ?? '';

                if ($action === 'check') {
                    $bookmarkId = $this->params['bookmark_id'] ?? null;

                    try {
                        if ($bookmarkId) {
                            $result = $this->deadLinkChecker->checkBookmark($bookmarkId, $user['id']);
                        } else {
                            $result = $this->deadLinkChecker->checkMultipleBookmarks($user['id']);
                        }
                        $this->sendResponse($result);
                    } catch (Exception $e) {
                        $this->sendError($e->getMessage(), 500);
                    }
                } else {
                    $this->sendError('Invalid action', 400);
                }
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleInviteCodes() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();

        if (!$this->inviteCodeManager) {
            $this->inviteCodeManager = new InviteCodeManager();
        }

        switch ($this->method) {
            case 'GET':
                if ($user['role'] !== 'admin' && $user['role'] !== 'superadmin') {
                    $this->sendError('Admin access required', 403);
                }

                try {
                    $codes = $this->inviteCodeManager->getInviteCodes($user['id']);
                    $this->sendResponse($codes);
                } catch (Exception $e) {
                    $this->sendError($e->getMessage(), 500);
                }
                break;

            case 'POST':
                $action = $this->endpoint[1] ?? '';

                if ($action === 'generate') {
                    if ($user['role'] !== 'admin' && $user['role'] !== 'superadmin') {
                        $this->sendError('Admin access required', 403);
                    }

                    $expiresAt = $this->params['expires_at'] ?? null;
                    $maxUses = $this->params['max_uses'] ?? 1;

                    try {
                        $code = $this->inviteCodeManager->generateInviteCode($user['id'], $expiresAt, $maxUses);
                        $this->sendResponse($code);
                    } catch (Exception $e) {
                        $this->sendError($e->getMessage(), 500);
                    }

                } elseif ($action === 'validate') {
                    $code = $this->params['code'] ?? '';

                    try {
                        $isValid = $this->inviteCodeManager->validateInviteCode($code);
                        $this->sendResponse(['valid' => $isValid]);
                    } catch (Exception $e) {
                        $this->sendError($e->getMessage(), 500);
                    }
                } else {
                    $this->sendError('Invalid action', 400);
                }
                break;

            case 'DELETE':
                if ($user['role'] !== 'admin' && $user['role'] !== 'superadmin') {
                    $this->sendError('Admin access required', 403);
                }

                $codeId = $this->endpoint[1] ?? null;
                if (!$codeId) {
                    $this->sendError('Code ID required', 400);
                }

                try {
                    $result = $this->inviteCodeManager->deactivateInviteCode($codeId, $user['id']);
                    $this->sendResponse(['success' => $result]);
                } catch (Exception $e) {
                    $this->sendError($e->getMessage(), 500);
                }
                break;

            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function handleClickTracking() {
        $this->auth->requireAuth();
        $user = $this->auth->getCurrentUser();

        if ($this->method === 'POST') {
            $bookmarkId = $this->params['bookmark_id'] ?? null;

            if (!$bookmarkId) {
                $this->sendError('Bookmark ID required', 400);
            }

            try {
                // Record click in database
                $this->db->execute(
                    "UPDATE bookmarks SET click_count = click_count + 1, last_clicked = ? WHERE id = ? AND user_id = ?",
                    [date('Y-m-d H:i:s'), $bookmarkId, $user['id']]
                );

                // Log click activity
                $this->db->execute(
                    "INSERT INTO click_logs (bookmark_id, user_id, ip_address, user_agent, clicked_at) VALUES (?, ?, ?, ?, ?)",
                    [
                        $bookmarkId,
                        $user['id'],
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                        date('Y-m-d H:i:s')
                    ]
                );

                $this->sendResponse(['success' => true, 'message' => 'Click recorded']);
            } catch (Exception $e) {
                $this->sendError($e->getMessage(), 500);
            }
        } else {
            $this->sendError('Method not allowed', 405);
        }
    }

    // Utility methods
    private function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function sendError($message, $statusCode = 400) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Initialize and route the request
try {
    $router = new APIRouter();
    $router->route();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'timestamp' => date('c')
    ]);
}
?>