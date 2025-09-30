<?php
/**
 * OneBookNav - 引导程序
 *
 * 实现依赖注入容器和自动加载
 */

// 自动加载器
spl_autoload_register(function ($class) {
    // 将命名空间转换为文件路径
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    // 检查类是否使用了命名空间前缀
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // 获取相对类名
    $relativeClass = substr($class, $len);

    // 将命名空间分隔符替换为目录分隔符，并添加.php扩展名
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // 如果文件存在，则加载
    if (file_exists($file)) {
        require $file;
    }
});

// 定义应用常量
define('APP_VERSION', '1.0.0');
define('APP_NAME', 'OneBookNav');
define('APP_AUTHOR', 'OneBookNav Team');

// 加载环境配置
if (file_exists(ROOT_PATH . '/.env')) {
    $lines = file(ROOT_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// 创建数据目录
$directories = [
    DATA_PATH,
    DATA_PATH . '/cache',
    DATA_PATH . '/logs',
    DATA_PATH . '/uploads',
    DATA_PATH . '/backups',
    DATA_PATH . '/exports',
    DATA_PATH . '/temp'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 设置错误日志
ini_set('log_errors', 1);
ini_set('error_log', DATA_PATH . '/logs/error.log');

// 初始化依赖注入容器
use App\Core\Container;
$container = Container::getInstance();

// 注册核心服务
$container->bind('config', function() {
    return \App\Services\ConfigService::getInstance();
});

$container->bind('database', function() {
    return \App\Services\DatabaseService::getInstance();
});

$container->bind('auth', function() {
    return \App\Services\AuthService::getInstance();
});

$container->bind('cache', function() {
    return \App\Services\CacheService::getInstance();
});

$container->bind('logger', function() {
    return \App\Services\LoggerService::getInstance();
});

$container->bind('security', function() {
    return \App\Services\SecurityService::getInstance();
});

return $container;