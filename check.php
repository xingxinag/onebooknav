<?php
/**
 * OneBookNav System Check
 * 系统环境检查工具
 */

// 防止在生产环境运行
if (!defined('DEBUG_MODE') || !DEBUG_MODE) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['confirm'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>系统检查 - OneBookNav</title>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container mt-5">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">⚠️ 安全警告</h5>
                            </div>
                            <div class="card-body">
                                <p>系统检查工具包含敏感信息，仅应在开发环境使用。</p>
                                <p>在生产环境中，请删除此文件或确保其不可公开访问。</p>
                                <form method="POST">
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="confirm" name="confirm" value="1" required>
                                        <label class="form-check-label" for="confirm">
                                            我确认这是在开发环境中运行
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-warning">继续检查</button>
                                    <a href="index.php" class="btn btn-secondary">返回首页</a>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$checks = [];
$errors = [];
$warnings = [];

// PHP版本检查
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.4.0', '>=')) {
    $checks['PHP版本'] = ['status' => 'success', 'message' => $phpVersion, 'description' => '满足最低要求 (>= 7.4)'];
} else {
    $checks['PHP版本'] = ['status' => 'error', 'message' => $phpVersion, 'description' => '需要 PHP 7.4 或更高版本'];
    $errors[] = 'PHP版本过低';
}

// 必需扩展检查
$requiredExtensions = [
    'pdo' => 'PDO - 数据库抽象层',
    'pdo_sqlite' => 'PDO SQLite - SQLite 数据库支持',
    'curl' => 'cURL - HTTP 请求支持',
    'mbstring' => 'mbstring - 多字节字符串支持',
    'openssl' => 'OpenSSL - 加密支持',
    'json' => 'JSON - JSON 数据处理'
];

foreach ($requiredExtensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        $checks[$desc] = ['status' => 'success', 'message' => '已安装', 'description' => ''];
    } else {
        $checks[$desc] = ['status' => 'error', 'message' => '未安装', 'description' => ''];
        $errors[] = "缺少 $ext 扩展";
    }
}

// 可选扩展检查
$optionalExtensions = [
    'gd' => 'GD - 图像处理支持',
    'zip' => 'Zip - 压缩文件支持',
    'fileinfo' => 'FileInfo - 文件类型检测'
];

foreach ($optionalExtensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        $checks[$desc] = ['status' => 'success', 'message' => '已安装', 'description' => '可选扩展'];
    } else {
        $checks[$desc] = ['status' => 'warning', 'message' => '未安装', 'description' => '可选扩展，建议安装'];
        $warnings[] = "建议安装 $ext 扩展";
    }
}

// 目录权限检查
$directories = [
    'data' => __DIR__ . '/data',
    'data/logs' => __DIR__ . '/data/logs',
    'data/cache' => __DIR__ . '/data/cache',
    'data/backups' => __DIR__ . '/data/backups',
    'data/uploads' => __DIR__ . '/data/uploads',
    'config' => __DIR__ . '/config'
];

foreach ($directories as $name => $path) {
    if (!is_dir($path)) {
        $checks["目录 $name"] = ['status' => 'warning', 'message' => '不存在', 'description' => '将在安装时创建'];
        $warnings[] = "目录 $name 不存在";
    } elseif (!is_writable($path)) {
        $checks["目录 $name"] = ['status' => 'error', 'message' => '不可写', 'description' => '需要写入权限'];
        $errors[] = "目录 $name 不可写";
    } else {
        $checks["目录 $name"] = ['status' => 'success', 'message' => '可写', 'description' => '权限正确'];
    }
}

// 配置文件检查
if (file_exists(__DIR__ . '/config/config.php')) {
    $checks['配置文件'] = ['status' => 'success', 'message' => '已存在', 'description' => '系统已配置'];

    // 如果配置文件存在，进行数据库连接测试
    try {
        require_once __DIR__ . '/config/config.php';
        require_once __DIR__ . '/includes/Database.php';

        $db = Database::getInstance();
        $version = $db->getVersion();
        $checks['数据库连接'] = ['status' => 'success', 'message' => "已连接 (v$version)", 'description' => '数据库工作正常'];

        // 检查表结构
        $tables = ['users', 'categories', 'bookmarks', 'settings'];
        $missingTables = [];

        foreach ($tables as $table) {
            $result = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]);
            if (!$result) {
                $missingTables[] = $table;
            }
        }

        if (empty($missingTables)) {
            $checks['数据库表'] = ['status' => 'success', 'message' => '完整', 'description' => '所有必需表都存在'];
        } else {
            $checks['数据库表'] = ['status' => 'warning', 'message' => '不完整', 'description' => '缺少: ' . implode(', ', $missingTables)];
            $warnings[] = '数据库表不完整';
        }

    } catch (Exception $e) {
        $checks['数据库连接'] = ['status' => 'error', 'message' => '连接失败', 'description' => $e->getMessage()];
        $errors[] = '数据库连接失败';
    }
} else {
    $checks['配置文件'] = ['status' => 'warning', 'message' => '不存在', 'description' => '需要运行安装程序'];
    $warnings[] = '配置文件不存在';
}

// Web服务器检查
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
if (strpos($serverSoftware, 'Apache') !== false) {
    $checks['Web服务器'] = ['status' => 'success', 'message' => 'Apache', 'description' => '支持 .htaccess'];
} elseif (strpos($serverSoftware, 'nginx') !== false) {
    $checks['Web服务器'] = ['status' => 'success', 'message' => 'Nginx', 'description' => '需要配置重写规则'];
} else {
    $checks['Web服务器'] = ['status' => 'warning', 'message' => $serverSoftware, 'description' => '可能需要额外配置'];
}

// URL重写检查
$checks['URL重写'] = ['status' => 'info', 'message' => '未测试', 'description' => '需要手动测试'];

// HTTPS检查
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $checks['HTTPS'] = ['status' => 'success', 'message' => '已启用', 'description' => '连接安全'];
} else {
    $checks['HTTPS'] = ['status' => 'warning', 'message' => '未启用', 'description' => '建议在生产环境启用'];
    $warnings[] = 'HTTPS 未启用';
}

// 内存限制检查
$memoryLimit = ini_get('memory_limit');
$memoryLimitBytes = return_bytes($memoryLimit);
if ($memoryLimitBytes >= 128 * 1024 * 1024) { // 128MB
    $checks['内存限制'] = ['status' => 'success', 'message' => $memoryLimit, 'description' => '充足'];
} else {
    $checks['内存限制'] = ['status' => 'warning', 'message' => $memoryLimit, 'description' => '建议至少 128MB'];
    $warnings[] = '内存限制较低';
}

// 执行时间限制检查
$maxExecutionTime = ini_get('max_execution_time');
if ($maxExecutionTime == 0 || $maxExecutionTime >= 30) {
    $checks['执行时间限制'] = ['status' => 'success', 'message' => $maxExecutionTime == 0 ? '无限制' : $maxExecutionTime . 's', 'description' => '充足'];
} else {
    $checks['执行时间限制'] = ['status' => 'warning', 'message' => $maxExecutionTime . 's', 'description' => '可能影响大文件操作'];
    $warnings[] = '执行时间限制较短';
}

// 文件上传限制检查
$uploadMaxFilesize = ini_get('upload_max_filesize');
$postMaxSize = ini_get('post_max_size');
$checks['文件上传限制'] = ['status' => 'info', 'message' => "上传: $uploadMaxFilesize, POST: $postMaxSize", 'description' => '用于备份导入'];

function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}

function getStatusBadge($status) {
    switch ($status) {
        case 'success':
            return 'bg-success';
        case 'error':
            return 'bg-danger';
        case 'warning':
            return 'bg-warning text-dark';
        case 'info':
        default:
            return 'bg-info';
    }
}

function getStatusIcon($status) {
    switch ($status) {
        case 'success':
            return '<i class="fas fa-check-circle text-success"></i>';
        case 'error':
            return '<i class="fas fa-times-circle text-danger"></i>';
        case 'warning':
            return '<i class="fas fa-exclamation-triangle text-warning"></i>';
        case 'info':
        default:
            return '<i class="fas fa-info-circle text-info"></i>';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统检查 - OneBookNav</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <!-- Header -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-stethoscope me-2"></i>
                            OneBookNav 系统检查
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 text-center">
                                <div class="h2 text-success"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'success'; })); ?></div>
                                <div class="text-muted">通过</div>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="h2 text-warning"><?php echo count($warnings); ?></div>
                                <div class="text-muted">警告</div>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="h2 text-danger"><?php echo count($errors); ?></div>
                                <div class="text-muted">错误</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>发现严重问题：</h6>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <hr>
                    <p class="mb-0">请解决这些问题后再继续安装或使用。</p>
                </div>
                <?php elseif (!empty($warnings)): ?>
                <div class="alert alert-warning">
                    <h6><i class="fas fa-info-circle me-2"></i>建议改进：</h6>
                    <ul class="mb-0">
                        <?php foreach ($warnings as $warning): ?>
                        <li><?php echo htmlspecialchars($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <hr>
                    <p class="mb-0">这些问题不会阻止安装，但建议修复以获得最佳体验。</p>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <h6><i class="fas fa-check-circle me-2"></i>系统检查通过！</h6>
                    <p class="mb-0">您的服务器环境满足 OneBookNav 的运行要求。</p>
                </div>
                <?php endif; ?>

                <!-- Detailed Results -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">详细检查结果</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>检查项目</th>
                                        <th>状态</th>
                                        <th>结果</th>
                                        <th>说明</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($checks as $name => $check): ?>
                                    <tr>
                                        <td><?php echo getStatusIcon($check['status']) . ' ' . htmlspecialchars($name); ?></td>
                                        <td>
                                            <span class="badge <?php echo getStatusBadge($check['status']); ?>">
                                                <?php echo ucfirst($check['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($check['message']); ?></td>
                                        <td><?php echo htmlspecialchars($check['description']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="card mt-4">
                    <div class="card-body text-center">
                        <?php if (empty($errors)): ?>
                            <?php if (!file_exists(__DIR__ . '/config/config.php')): ?>
                            <a href="install.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-play me-2"></i> 开始安装
                            </a>
                            <?php else: ?>
                            <a href="index.php" class="btn btn-success btn-lg">
                                <i class="fas fa-home me-2"></i> 进入系统
                            </a>
                            <?php endif; ?>
                        <?php else: ?>
                        <p class="text-muted">请先解决上述错误，然后刷新页面重新检查。</p>
                        <button class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-redo me-2"></i> 重新检查
                        </button>
                        <?php endif; ?>

                        <a href="README.md" class="btn btn-outline-info ms-2" target="_blank">
                            <i class="fas fa-book me-2"></i> 查看文档
                        </a>
                    </div>
                </div>

                <!-- Footer -->
                <div class="text-center mt-4 text-muted">
                    <small>
                        OneBookNav v1.0.0 |
                        PHP <?php echo PHP_VERSION; ?> |
                        <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>