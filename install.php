<?php
/**
 * OneBookNav Installation Script
 * Simple web-based installer for easy setup
 */

// Check if already installed
if (file_exists(__DIR__ . '/config/config.php')) {
    header('Location: index.php');
    exit;
}

// Start session first
session_start();

$step = $_GET['step'] ?? 1;
$errors = [];
$success = false;

// Handle form submissions
if ($_POST) {
    switch ($step) {
        case 2:
            // Environment check
            $step = 3;
            break;

        case 3:
            // Database configuration
            $errors = validateDatabaseConfig($_POST);
            if (empty($errors)) {
                $_SESSION['db_config'] = $_POST;
                $step = 4;
            }
            break;

        case 4:
            // Admin user creation
            $errors = validateAdminConfig($_POST);
            if (empty($errors)) {
                $_SESSION['admin_config'] = $_POST;
                $step = 5;
            }
            break;

        case 5:
            // Final installation
            $errors = performInstallation();
            if (empty($errors)) {
                $success = true;
            }
            break;
    }
}

function checkEnvironment() {
    $checks = [
        'PHP Version >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO Extension' => extension_loaded('pdo'),
        'SQLite Extension' => extension_loaded('pdo_sqlite'),
        'cURL Extension' => extension_loaded('curl'),
        'OpenSSL Extension' => extension_loaded('openssl'),
        'mbstring Extension' => extension_loaded('mbstring'),
        'Config Directory Writable' => is_writable(__DIR__ . '/config'),
        'Data Directory Writable' => is_writable(__DIR__ . '/data') || mkdir(__DIR__ . '/data', 0755, true)
    ];

    return $checks;
}

function validateDatabaseConfig($data) {
    $errors = [];

    if (empty($data['db_type'])) {
        $errors[] = 'Database type is required';
    }

    if ($data['db_type'] === 'mysql' || $data['db_type'] === 'pgsql') {
        if (empty($data['db_host'])) $errors[] = 'Database host is required';
        if (empty($data['db_name'])) $errors[] = 'Database name is required';
        if (empty($data['db_user'])) $errors[] = 'Database user is required';
    }

    return $errors;
}

function validateAdminConfig($data) {
    $errors = [];

    if (empty($data['admin_username']) || strlen($data['admin_username']) < 3) {
        $errors[] = 'Admin username must be at least 3 characters';
    }

    if (empty($data['admin_password']) || strlen($data['admin_password']) < 6) {
        $errors[] = 'Admin password must be at least 6 characters';
    }

    if ($data['admin_password'] !== $data['admin_password_confirm']) {
        $errors[] = 'Admin passwords do not match';
    }

    if (!empty($data['admin_email']) && !filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid admin email address';
    }

    return $errors;
}

function performInstallation() {
    $errors = [];

    try {
        // Create config file
        $configContent = generateConfigFile();
        file_put_contents(__DIR__ . '/config/config.php', $configContent);

        // Initialize database
        require_once __DIR__ . '/config/config.php';
        require_once __DIR__ . '/includes/Database.php';
        require_once __DIR__ . '/includes/Auth.php';

        $db = Database::getInstance();
        $auth = new Auth();

        // Create admin user
        $adminConfig = $_SESSION['admin_config'];
        $auth->createAdminUser(
            $adminConfig['admin_username'],
            $adminConfig['admin_password'],
            $adminConfig['admin_email']
        );

        // Create necessary directories
        $dirs = ['data/logs', 'data/cache', 'data/backups', 'data/uploads'];
        foreach ($dirs as $dir) {
            if (!is_dir(__DIR__ . '/' . $dir)) {
                mkdir(__DIR__ . '/' . $dir, 0755, true);
            }
        }

        // Clean up session
        session_destroy();

    } catch (Exception $e) {
        $errors[] = 'Installation failed: ' . $e->getMessage();
    }

    return $errors;
}

function generateConfigFile() {
    $dbConfig = $_SESSION['db_config'];
    $siteConfig = $_SESSION['site_config'] ?? [];

    $template = file_get_contents(__DIR__ . '/config/config.sample.php');

    $replacements = [
        "define('DB_TYPE', 'sqlite');" => "define('DB_TYPE', '{$dbConfig['db_type']}');",
        "define('DB_HOST', 'localhost');" => "define('DB_HOST', '{$dbConfig['db_host']}');",
        "define('DB_NAME', 'onebooknav');" => "define('DB_NAME', '{$dbConfig['db_name']}');",
        "define('DB_USER', 'root');" => "define('DB_USER', '{$dbConfig['db_user']}');",
        "define('DB_PASS', '');" => "define('DB_PASS', '{$dbConfig['db_pass']}');",
        "define('SITE_TITLE', 'OneBookNav');" => "define('SITE_TITLE', '" . ($siteConfig['site_title'] ?? 'OneBookNav') . "');",
        "define('SITE_URL', 'http://localhost');" => "define('SITE_URL', 'http://{$_SERVER['HTTP_HOST']}');",
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneBookNav Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card mt-5">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-bookmark me-2"></i>
                            OneBookNav Installation
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Progress Steps -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="progress">
                                    <div class="progress-bar" role="progressbar" style="width: <?php echo ($step / 5) * 100; ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <small class="<?php echo $step >= 1 ? 'text-primary fw-bold' : 'text-muted'; ?>">Welcome</small>
                                    <small class="<?php echo $step >= 2 ? 'text-primary fw-bold' : 'text-muted'; ?>">Check</small>
                                    <small class="<?php echo $step >= 3 ? 'text-primary fw-bold' : 'text-muted'; ?>">Database</small>
                                    <small class="<?php echo $step >= 4 ? 'text-primary fw-bold' : 'text-muted'; ?>">Admin</small>
                                    <small class="<?php echo $step >= 5 ? 'text-primary fw-bold' : 'text-muted'; ?>">Install</small>
                                </div>
                            </div>
                        </div>

                        <!-- Error Messages -->
                        <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <h6>Please fix the following errors:</h6>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <!-- Success Message -->
                        <?php if ($success): ?>
                        <div class="alert alert-success">
                            <h5><i class="fas fa-check-circle me-2"></i>Installation Complete!</h5>
                            <p>OneBookNav has been successfully installed. You can now:</p>
                            <ul>
                                <li>Access your bookmark manager at <a href="index.php">homepage</a></li>
                                <li>Login with your admin credentials</li>
                                <li>Start organizing your bookmarks</li>
                            </ul>
                        </div>
                        <?php else: ?>

                        <!-- Step Content -->
                        <?php if ($step == 1): ?>
                        <!-- Welcome Step -->
                        <h5>Welcome to OneBookNav</h5>
                        <p>This installer will help you set up OneBookNav on your server. OneBookNav is a powerful bookmark management system that combines the best features from BookNav and OneNav.</p>

                        <div class="alert alert-info">
                            <h6>Features included:</h6>
                            <ul class="mb-0">
                                <li>Multi-user support with role-based access</li>
                                <li>Hierarchical category organization</li>
                                <li>Import/export from BookNav and OneNav</li>
                                <li>WebDAV backup support</li>
                                <li>Modern responsive interface</li>
                                <li>Multiple deployment options</li>
                            </ul>
                        </div>

                        <form method="GET">
                            <input type="hidden" name="step" value="2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrow-right me-2"></i>Start Installation
                            </button>
                        </form>

                        <?php elseif ($step == 2): ?>
                        <!-- Environment Check Step -->
                        <h5>Environment Check</h5>
                        <p>Checking your server environment for compatibility...</p>

                        <?php $checks = checkEnvironment(); ?>
                        <div class="list-group mb-3">
                            <?php foreach ($checks as $check => $passed): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo $check; ?>
                                <?php if ($passed): ?>
                                <span class="badge bg-success"><i class="fas fa-check"></i></span>
                                <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times"></i></span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (array_search(false, $checks) !== false): ?>
                        <div class="alert alert-warning">
                            <strong>Warning:</strong> Some requirements are not met. Please fix the issues above before continuing.
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-refresh me-2"></i>Recheck
                        </button>
                        <?php else: ?>
                        <form method="POST">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrow-right me-2"></i>Continue
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php elseif ($step == 3): ?>
                        <!-- Database Configuration Step -->
                        <h5>Database Configuration</h5>
                        <p>Configure your database connection settings.</p>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="db_type" class="form-label">Database Type</label>
                                <select class="form-select" id="db_type" name="db_type" required onchange="toggleDatabaseFields()">
                                    <option value="sqlite" <?php echo ($_POST['db_type'] ?? 'sqlite') === 'sqlite' ? 'selected' : ''; ?>>SQLite (Recommended)</option>
                                    <option value="mysql" <?php echo ($_POST['db_type'] ?? '') === 'mysql' ? 'selected' : ''; ?>>MySQL</option>
                                    <option value="pgsql" <?php echo ($_POST['db_type'] ?? '') === 'pgsql' ? 'selected' : ''; ?>>PostgreSQL</option>
                                </select>
                                <div class="form-text">SQLite is recommended for most installations as it requires no additional setup.</div>
                            </div>

                            <div id="database-fields" style="<?php echo ($_POST['db_type'] ?? 'sqlite') === 'sqlite' ? 'display: none;' : ''; ?>">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="db_host" class="form-label">Database Host</label>
                                            <input type="text" class="form-control" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="db_port" class="form-label">Port</label>
                                            <input type="number" class="form-control" id="db_port" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="db_name" class="form-label">Database Name</label>
                                    <input type="text" class="form-control" id="db_name" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'onebooknav'); ?>">
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="db_user" class="form-label">Database User</label>
                                            <input type="text" class="form-control" id="db_user" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="db_pass" class="form-label">Database Password</label>
                                            <input type="password" class="form-control" id="db_pass" name="db_pass">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrow-right me-2"></i>Continue
                            </button>
                        </form>

                        <?php elseif ($step == 4): ?>
                        <!-- Admin User Step -->
                        <h5>Create Admin User</h5>
                        <p>Create the administrator account for OneBookNav.</p>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="admin_username" class="form-label">Admin Username</label>
                                <input type="text" class="form-control" id="admin_username" name="admin_username" value="<?php echo htmlspecialchars($_POST['admin_username'] ?? ''); ?>" required>
                                <div class="form-text">Minimum 3 characters, letters, numbers, underscore and hyphen only.</div>
                            </div>

                            <div class="mb-3">
                                <label for="admin_email" class="form-label">Admin Email (Optional)</label>
                                <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>">
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="admin_password" class="form-label">Admin Password</label>
                                        <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                                        <div class="form-text">Minimum 6 characters.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="admin_password_confirm" class="form-label">Confirm Password</label>
                                        <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm" required>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrow-right me-2"></i>Continue
                            </button>
                        </form>

                        <?php elseif ($step == 5): ?>
                        <!-- Installation Step -->
                        <h5>Installing OneBookNav</h5>
                        <p>Please wait while OneBookNav is being installed...</p>

                        <div class="alert alert-info">
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm me-3" role="status"></div>
                                <div>
                                    <strong>Installing...</strong><br>
                                    Creating configuration file, initializing database, and setting up admin user.
                                </div>
                            </div>
                        </div>

                        <form method="POST" id="installForm">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-cog me-2"></i>Start Installation
                            </button>
                        </form>

                        <script>
                            // Auto-submit form after a short delay
                            setTimeout(function() {
                                document.getElementById('installForm').submit();
                            }, 2000);
                        </script>

                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <small class="text-muted">
                        OneBookNav v1.0.0 -
                        <a href="https://github.com/onebooknav/onebooknav" target="_blank">Documentation</a> |
                        <a href="https://github.com/onebooknav/onebooknav/issues" target="_blank">Support</a>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleDatabaseFields() {
            const dbType = document.getElementById('db_type').value;
            const fields = document.getElementById('database-fields');

            if (dbType === 'sqlite') {
                fields.style.display = 'none';
            } else {
                fields.style.display = 'block';
            }
        }
    </script>
</body>
</html>