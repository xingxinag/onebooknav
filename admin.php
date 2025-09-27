<?php
/**
 * OneBookNav - Admin Panel
 * Administrative interface for system management
 */

// Check if config exists
if (!file_exists(__DIR__ . '/config/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';

// Initialize components
$auth = new Auth();
$db = Database::getInstance();

// Require admin access
$auth->requireAdmin();
$currentUser = $auth->getCurrentUser();

// Get system statistics
$stats = [
    'total_users' => $db->fetchOne("SELECT COUNT(*) as count FROM users")['count'],
    'total_categories' => $db->fetchOne("SELECT COUNT(*) as count FROM categories")['count'],
    'total_bookmarks' => $db->fetchOne("SELECT COUNT(*) as count FROM bookmarks")['count'],
    'total_backups' => $db->fetchOne("SELECT COUNT(*) as count FROM backups")['count']
];

// Get recent activity
$recentUsers = $db->fetchAll("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
$recentBackups = $db->fetchAll("SELECT * FROM backups ORDER BY created_at DESC LIMIT 5");

// Get system info
$systemInfo = [
    'php_version' => PHP_VERSION,
    'db_type' => DB_TYPE,
    'site_title' => SITE_TITLE,
    'debug_mode' => DEBUG_MODE ? 'Enabled' : 'Disabled',
    'disk_usage' => is_dir(__DIR__ . '/data') ? round(array_sum(array_map('filesize', glob(__DIR__ . '/data/**/*'))) / 1024 / 1024, 2) . ' MB' : 'N/A'
];

// Generate CSRF token
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理面板 - <?php echo htmlspecialchars(SITE_TITLE); ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="/">
                <i class="fas fa-tools me-2"></i>
                管理面板
            </a>

            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-home me-1"></i> 返回首页
                </a>
                <a class="nav-link" href="#" onclick="logout()">
                    <i class="fas fa-sign-out-alt me-1"></i> 退出
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="#dashboard" data-bs-toggle="tab">
                                <i class="fas fa-tachometer-alt me-2"></i>
                                仪表板
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#users" data-bs-toggle="tab">
                                <i class="fas fa-users me-2"></i>
                                用户管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#system" data-bs-toggle="tab">
                                <i class="fas fa-cogs me-2"></i>
                                系统设置
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#backups" data-bs-toggle="tab">
                                <i class="fas fa-download me-2"></i>
                                备份管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#logs" data-bs-toggle="tab">
                                <i class="fas fa-file-alt me-2"></i>
                                系统日志
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">OneBookNav 管理面板</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-success" onclick="createBackup()">
                                <i class="fas fa-plus me-1"></i> 创建备份
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tab content -->
                <div class="tab-content">
                    <!-- Dashboard -->
                    <div class="tab-pane fade show active" id="dashboard">
                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h5 class="card-title"><?php echo $stats['total_users']; ?></h5>
                                                <p class="card-text">用户总数</p>
                                            </div>
                                            <div class="align-self-center">
                                                <i class="fas fa-users fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h5 class="card-title"><?php echo $stats['total_categories']; ?></h5>
                                                <p class="card-text">分类总数</p>
                                            </div>
                                            <div class="align-self-center">
                                                <i class="fas fa-folder fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h5 class="card-title"><?php echo $stats['total_bookmarks']; ?></h5>
                                                <p class="card-text">书签总数</p>
                                            </div>
                                            <div class="align-self-center">
                                                <i class="fas fa-bookmark fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h5 class="card-title"><?php echo $stats['total_backups']; ?></h5>
                                                <p class="card-text">备份总数</p>
                                            </div>
                                            <div class="align-self-center">
                                                <i class="fas fa-download fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- System Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title"><i class="fas fa-info-circle me-2"></i>系统信息</h5>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td><strong>PHP 版本</strong></td>
                                                <td><?php echo $systemInfo['php_version']; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>数据库类型</strong></td>
                                                <td><?php echo strtoupper($systemInfo['db_type']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>站点标题</strong></td>
                                                <td><?php echo htmlspecialchars($systemInfo['site_title']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>调试模式</strong></td>
                                                <td><?php echo $systemInfo['debug_mode']; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>数据占用</strong></td>
                                                <td><?php echo $systemInfo['disk_usage']; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title"><i class="fas fa-clock me-2"></i>最近用户</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($recentUsers)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recentUsers as $user): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                                <div class="ms-2 me-auto">
                                                    <div class="fw-bold"><?php echo htmlspecialchars($user['username']); ?></div>
                                                    <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></small>
                                                </div>
                                                <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?> rounded-pill">
                                                    <?php echo $user['role']; ?>
                                                </span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <p class="text-muted">暂无用户数据</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Users Management -->
                    <div class="tab-pane fade" id="users">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">用户管理</h5>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                    <i class="fas fa-plus me-1"></i> 添加用户
                                </button>
                            </div>
                            <div class="card-body">
                                <div id="usersTable">
                                    <!-- Users table will be loaded here -->
                                    <p class="text-center">加载中...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Settings -->
                    <div class="tab-pane fade" id="system">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">系统设置</h5>
                            </div>
                            <div class="card-body">
                                <form id="systemSettingsForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="siteTitle" class="form-label">站点标题</label>
                                                <input type="text" class="form-control" id="siteTitle" name="site_title" value="<?php echo htmlspecialchars(SITE_TITLE); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="allowRegistration" class="form-label">允许注册</label>
                                                <select class="form-select" id="allowRegistration" name="allow_registration">
                                                    <option value="1" <?php echo ALLOW_REGISTRATION ? 'selected' : ''; ?>>是</option>
                                                    <option value="0" <?php echo !ALLOW_REGISTRATION ? 'selected' : ''; ?>>否</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary">保存设置</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Backup Management -->
                    <div class="tab-pane fade" id="backups">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">备份管理</h5>
                            </div>
                            <div class="card-body">
                                <div id="backupsList">
                                    <!-- Backup list will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Logs -->
                    <div class="tab-pane fade" id="logs">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">系统日志</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <select class="form-select" id="logFileSelect" onchange="loadLogFile()">
                                        <option value="">选择日志文件...</option>
                                        <option value="app.log">应用日志</option>
                                        <option value="error.log">错误日志</option>
                                        <option value="access.log">访问日志</option>
                                    </select>
                                </div>
                                <div id="logContent" style="height: 400px; overflow-y: auto; background: #f8f9fa; padding: 1rem; border-radius: 0.375rem;">
                                    <p class="text-muted">请选择要查看的日志文件</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">添加用户</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addUserForm">
                        <div class="mb-3">
                            <label for="newUsername" class="form-label">用户名</label>
                            <input type="text" class="form-control" id="newUsername" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="newEmail" class="form-label">邮箱（可选）</label>
                            <input type="email" class="form-control" id="newEmail" name="email">
                        </div>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">密码</label>
                            <input type="password" class="form-control" id="newPassword" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="newRole" class="form-label">角色</label>
                            <select class="form-select" id="newRole" name="role" required>
                                <option value="user">普通用户</option>
                                <option value="admin">管理员</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="addUser()">添加用户</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global admin configuration
        window.AdminConfig = {
            csrfToken: '<?php echo $csrfToken; ?>',
            apiBase: '/api'
        };

        // Load users table
        function loadUsersTable() {
            fetch('/api/admin/users', {
                headers: {
                    'X-CSRF-Token': window.AdminConfig.csrfToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderUsersTable(data.data);
                } else {
                    document.getElementById('usersTable').innerHTML = '<p class="text-danger">加载失败: ' + data.error + '</p>';
                }
            })
            .catch(error => {
                document.getElementById('usersTable').innerHTML = '<p class="text-danger">网络错误</p>';
            });
        }

        // Render users table
        function renderUsersTable(users) {
            if (!users || users.length === 0) {
                document.getElementById('usersTable').innerHTML = '<p class="text-muted">暂无用户数据</p>';
                return;
            }

            let html = `
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>邮箱</th>
                            <th>角色</th>
                            <th>注册时间</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            users.forEach(user => {
                html += `
                    <tr>
                        <td>${user.id}</td>
                        <td>${user.username}</td>
                        <td>${user.email || '-'}</td>
                        <td><span class="badge bg-${user.role === 'admin' ? 'danger' : 'primary'}">${user.role}</span></td>
                        <td>${new Date(user.created_at).toLocaleDateString()}</td>
                        <td><span class="badge bg-${user.is_active ? 'success' : 'secondary'}">${user.is_active ? '活跃' : '禁用'}</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="editUser(${user.id})">编辑</button>
                            ${user.role !== 'superadmin' ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${user.id})">删除</button>` : ''}
                        </td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            document.getElementById('usersTable').innerHTML = html;
        }

        // Add user
        function addUser() {
            const form = document.getElementById('addUserForm');
            const formData = new FormData(form);

            fetch('/api/admin/users', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': window.AdminConfig.csrfToken
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
                    form.reset();
                    loadUsersTable();
                    showAlert('用户添加成功', 'success');
                } else {
                    showAlert('添加失败: ' + data.error, 'danger');
                }
            })
            .catch(error => {
                showAlert('网络错误', 'danger');
            });
        }

        // Create backup
        function createBackup() {
            fetch('/api/backup/create', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': window.AdminConfig.csrfToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('备份创建成功', 'success');
                    if (document.getElementById('backups').classList.contains('active')) {
                        loadBackupsList();
                    }
                } else {
                    showAlert('备份失败: ' + data.error, 'danger');
                }
            })
            .catch(error => {
                showAlert('网络错误', 'danger');
            });
        }

        // Load backups list
        function loadBackupsList() {
            fetch('/api/backup/list', {
                headers: {
                    'X-CSRF-Token': window.AdminConfig.csrfToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderBackupsList(data.data);
                } else {
                    document.getElementById('backupsList').innerHTML = '<p class="text-danger">加载失败: ' + data.error + '</p>';
                }
            });
        }

        // Render backups list
        function renderBackupsList(backups) {
            if (!backups || backups.length === 0) {
                document.getElementById('backupsList').innerHTML = '<p class="text-muted">暂无备份文件</p>';
                return;
            }

            let html = '<div class="list-group">';
            backups.forEach(backup => {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${backup.filename}</h6>
                            <p class="mb-1">${(backup.size / 1024).toFixed(2)} KB - ${new Date(backup.created_at).toLocaleString()}</p>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" onclick="downloadBackup('${backup.filename}')">下载</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup(${backup.id})">删除</button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            document.getElementById('backupsList').innerHTML = html;
        }

        // Download backup
        function downloadBackup(filename) {
            window.location.href = `/api/backup/download/${filename}`;
        }

        // Show alert
        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.container-fluid').insertBefore(alert, document.querySelector('.row'));

            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }

        // Logout
        function logout() {
            fetch('/api/auth/logout', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': window.AdminConfig.csrfToken
                }
            })
            .then(() => {
                window.location.href = '/';
            });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Load initial data
            loadUsersTable();

            // Tab change handlers
            document.querySelector('[href="#backups"]').addEventListener('shown.bs.tab', loadBackupsList);
        });
    </script>
</body>
</html>