<?php
/**
 * OneBookNav 健康检查端点
 * 用于负载均衡器和监控系统检查应用状态
 */

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '1.0.0',
    'checks' => []
];

try {
    // 检查数据库连接
    $dbPath = __DIR__ . '/../data/onebooknav.db';
    if (file_exists($dbPath)) {
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $userCount = $stmt->fetchColumn();

        $health['checks']['database'] = [
            'status' => 'ok',
            'response_time' => 0,
            'user_count' => $userCount
        ];
    } else {
        $health['checks']['database'] = [
            'status' => 'error',
            'message' => 'Database file not found'
        ];
        $health['status'] = 'unhealthy';
    }
} catch (Exception $e) {
    $health['checks']['database'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    $health['status'] = 'unhealthy';
}

// 检查文件权限
try {
    $dataDir = __DIR__ . '/../data';
    $testFile = $dataDir . '/health_test.tmp';

    if (is_writable($dataDir)) {
        file_put_contents($testFile, 'test');
        unlink($testFile);

        $health['checks']['filesystem'] = [
            'status' => 'ok',
            'writable' => true
        ];
    } else {
        $health['checks']['filesystem'] = [
            'status' => 'error',
            'message' => 'Data directory not writable'
        ];
        $health['status'] = 'unhealthy';
    }
} catch (Exception $e) {
    $health['checks']['filesystem'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    $health['status'] = 'unhealthy';
}

// 检查 PHP 配置
$health['checks']['php'] = [
    'status' => 'ok',
    'version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'upload_max_filesize' => ini_get('upload_max_filesize')
];

// 检查系统负载
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $health['checks']['system'] = [
        'status' => 'ok',
        'load_average' => [
            '1min' => round($load[0], 2),
            '5min' => round($load[1], 2),
            '15min' => round($load[2], 2)
        ]
    ];
}

// 设置 HTTP 状态码
http_response_code($health['status'] === 'healthy' ? 200 : 503);

echo json_encode($health, JSON_PRETTY_PRINT);
?>