<?php
/**
 * OneBookNav - Main Entry Point
 * Unified bookmark management system
 */

// Check if config exists, if not redirect to installation
if (!file_exists(__DIR__ . '/config/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/BookmarkManager.php';

// Initialize components
$auth = new Auth();
$db = Database::getInstance();

// Get current user
$currentUser = $auth->getCurrentUser();
$isGuest = !$currentUser;

// Get site settings
$siteSettings = [];
$settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE is_public = 1");
foreach ($settings as $setting) {
    $siteSettings[$setting['setting_key']] = $setting['setting_value'];
}

// Handle quick bookmark addition via URL parameter
if (isset($_GET['add_url']) && $currentUser) {
    $quickUrl = filter_var($_GET['add_url'], FILTER_VALIDATE_URL);
    if ($quickUrl) {
        try {
            $bookmarkManager = new BookmarkManager($currentUser['id']);

            // Get or create default category
            $categories = $bookmarkManager->getCategories();
            $defaultCategoryId = $categories[0]['id'] ?? null;

            if ($defaultCategoryId) {
                // Extract title from URL
                $title = parse_url($quickUrl, PHP_URL_HOST);
                $bookmark = $bookmarkManager->createBookmark($title, $quickUrl, $defaultCategoryId);
                $quickAddSuccess = "Bookmark added successfully!";
            }
        } catch (Exception $e) {
            $quickAddError = $e->getMessage();
        }
    }
}

// Load user's categories and bookmarks
$categories = [];
$bookmarks = [];

if ($currentUser) {
    $bookmarkManager = new BookmarkManager($currentUser['id']);
    $categories = $bookmarkManager->getCategories();
    $bookmarks = $bookmarkManager->getUserBookmarks();
} else {
    // Load public categories and bookmarks for guests
    $bookmarkManager = new BookmarkManager();
    $categories = $bookmarkManager->getPublicCategories();
}

// Generate CSRF token
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($siteSettings['site_description'] ?? 'Personal bookmark management'); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($siteSettings['site_keywords'] ?? 'bookmarks, navigation'); ?>">

    <title><?php echo htmlspecialchars($siteSettings['site_title'] ?? 'OneBookNav'); ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="assets/css/app.css" rel="stylesheet">

    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?php echo PWA_THEME_COLOR; ?>">

    <!-- Apple PWA Support -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo PWA_SHORT_NAME; ?>">
    <link rel="apple-touch-icon" href="favicon.ico">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="/">
                <i class="fas fa-bookmark me-2"></i>
                <?php echo htmlspecialchars($siteSettings['site_title'] ?? 'OneBookNav'); ?>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if ($currentUser): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="addDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-plus me-1"></i> Add
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addBookmarkModal">
                                <i class="fas fa-link me-2"></i> Bookmark
                            </a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                <i class="fas fa-folder me-2"></i> Category
                            </a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Search -->
                <form class="d-flex me-3" role="search" id="searchForm">
                    <input class="form-control" type="search" placeholder="Search bookmarks..." id="searchInput">
                    <button class="btn btn-outline-light" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </form>

                <!-- User Menu -->
                <ul class="navbar-nav">
                    <?php if ($currentUser): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user me-1"></i>
                            <?php echo htmlspecialchars($currentUser['username']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal">
                                <i class="fas fa-cog me-2"></i> Settings
                            </a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#backupModal">
                                <i class="fas fa-download me-2"></i> Backup & Import
                            </a></li>
                            <?php if ($auth->isAdmin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="admin.php">
                                <i class="fas fa-tools me-2"></i> Admin Panel
                            </a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="logout()">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Quick Add Success/Error Messages -->
    <?php if (isset($quickAddSuccess)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($quickAddSuccess); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($quickAddError)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($quickAddError); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
                <div class="position-sticky pt-3">
                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                        <span>Categories</span>
                        <?php if ($currentUser): ?>
                        <a class="link-secondary" href="#" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus"></i>
                        </a>
                        <?php endif; ?>
                    </h6>
                    <ul class="nav flex-column" id="categoriesList">
                        <?php if (!empty($categories)): ?>
                            <?php echo renderCategoryTree($categories); ?>
                        <?php else: ?>
                        <li class="nav-item">
                            <span class="nav-link text-muted">No categories yet</span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>

            <!-- Main Content Area -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2" id="currentCategoryTitle">All Bookmarks</h1>
                    <?php if ($currentUser): ?>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleView('grid')">
                                <i class="fas fa-th"></i> Grid
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleView('list')">
                                <i class="fas fa-list"></i> List
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addBookmarkModal">
                            <i class="fas fa-plus me-1"></i> Add Bookmark
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Bookmarks Grid -->
                <div id="bookmarksContainer" class="row">
                    <!-- Bookmarks will be loaded here -->
                </div>

                <!-- Empty State -->
                <div id="emptyState" class="text-center py-5" style="display: none;">
                    <i class="fas fa-bookmark fa-3x text-muted mb-3"></i>
                    <h3 class="text-muted">No bookmarks found</h3>
                    <p class="text-muted">Start by adding your first bookmark!</p>
                    <?php if ($currentUser): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookmarkModal">
                        <i class="fas fa-plus me-2"></i> Add Bookmark
                    </button>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modals -->
    <?php include 'templates/modals.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="assets/js/app.js"></script>

    <!-- PWA Service Worker -->
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js');
        }
    </script>

    <!-- Initialize App -->
    <script>
        // Global configuration
        window.OneBookNav = {
            user: <?php echo json_encode($currentUser); ?>,
            csrfToken: '<?php echo $csrfToken; ?>',
            categories: <?php echo json_encode($categories); ?>,
            bookmarks: <?php echo json_encode($bookmarks); ?>,
            isGuest: <?php echo $isGuest ? 'true' : 'false'; ?>
        };

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            OneBookNavApp.init();
        });
    </script>
</body>
</html>

<?php
function renderCategoryTree($categories, $level = 0) {
    $html = '';

    foreach ($categories as $category) {
        $indent = str_repeat('&nbsp;&nbsp;', $level);
        $icon = $category['icon'] ?: 'fas fa-folder';

        $html .= '<li class="nav-item">';
        $html .= '<a class="nav-link category-link" href="#" data-category-id="' . $category['id'] . '">';
        $html .= $indent . '<i class="' . htmlspecialchars($icon) . ' me-2"></i>';
        $html .= htmlspecialchars($category['name']);
        $html .= '</a>';

        // Render children if any
        if (!empty($category['children'])) {
            $html .= '<ul class="nav flex-column ms-3">';
            $html .= renderCategoryTree($category['children'], $level + 1);
            $html .= '</ul>';
        }

        $html .= '</li>';
    }

    return $html;
}
?>