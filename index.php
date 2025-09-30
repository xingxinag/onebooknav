<?php
/**
 * OneBookNav - 主入口文件
 *
 * 实现"统一核心，多态适配"架构
 * 融合 BookNav 和 OneNav 功能的现代化导航系统
 *
 * 支持三种部署方式：
 * 1. PHP 原生部署
 * 2. Docker 容器化部署
 * 3. Cloudflare Workers 部署
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定义项目根目录
define('ROOT_PATH', __DIR__);
define('APP_PATH', ROOT_PATH . '/app');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('DATA_PATH', ROOT_PATH . '/data');
define('PUBLIC_PATH', ROOT_PATH . '/public');

// 加载自动加载器
require_once ROOT_PATH . '/vendor/autoload.php';
require_once APP_PATH . '/bootstrap.php';

use App\Core\Application;
use App\Core\Router;
use App\Core\Request;
use App\Core\Response;
use App\Services\DatabaseService;
use App\Services\AuthService;
use App\Services\ConfigService;

try {
    // 初始化应用程序
    $app = new Application();

    // 加载配置
    $config = ConfigService::getInstance();

    // 初始化数据库
    $database = DatabaseService::getInstance();

    // 创建请求和响应对象
    $request = new Request();
    $response = new Response();

    // 检查是否需要安装
    if (!$config->get('app.installed', false) && $request->getPath() !== '/install') {
        $response->redirect('/install');
        exit;
    }

    // 初始化路由器
    $router = new Router();

    // 注册路由
    registerRoutes($router);

    // 处理请求
    $router->dispatch($request, $response);

} catch (Exception $e) {
    // 错误处理
    handleError($e);
}

/**
 * 注册所有路由
 */
function registerRoutes(Router $router)
{
    // 首页路由
    $router->get('/', 'App\Controllers\HomeController@index');
    $router->get('/home', 'App\Controllers\HomeController@index');

    // 认证路由
    $router->get('/login', 'App\Controllers\AuthController@showLogin');
    $router->post('/login', 'App\Controllers\AuthController@login');
    $router->get('/register', 'App\Controllers\AuthController@showRegister');
    $router->post('/register', 'App\Controllers\AuthController@register');
    $router->post('/logout', 'App\Controllers\AuthController@logout');

    // 用户管理路由
    $router->group('/user', function(Router $router) {
        $router->get('/profile', 'App\Controllers\UserController@profile');
        $router->post('/profile', 'App\Controllers\UserController@updateProfile');
        $router->get('/settings', 'App\Controllers\UserController@settings');
        $router->post('/settings', 'App\Controllers\UserController@updateSettings');
    });

    // 书签管理路由
    $router->group('/bookmarks', function(Router $router) {
        $router->get('/', 'App\Controllers\BookmarkController@index');
        $router->get('/category/{id}', 'App\Controllers\BookmarkController@category');
        $router->post('/add', 'App\Controllers\BookmarkController@add');
        $router->post('/edit/{id}', 'App\Controllers\BookmarkController@edit');
        $router->delete('/delete/{id}', 'App\Controllers\BookmarkController@delete');
        $router->post('/sort', 'App\Controllers\BookmarkController@sort');
        $router->post('/batch', 'App\Controllers\BookmarkController@batch');
    });

    // 分类管理路由
    $router->group('/categories', function(Router $router) {
        $router->get('/', 'App\Controllers\CategoryController@index');
        $router->post('/add', 'App\Controllers\CategoryController@add');
        $router->post('/edit/{id}', 'App\Controllers\CategoryController@edit');
        $router->delete('/delete/{id}', 'App\Controllers\CategoryController@delete');
        $router->post('/sort', 'App\Controllers\CategoryController@sort');
    });

    // API 路由
    $router->group('/api', function(Router $router) {
        // 认证API
        $router->post('/auth/login', 'App\Controllers\Api\AuthController@login');
        $router->post('/auth/logout', 'App\Controllers\Api\AuthController@logout');
        $router->get('/auth/user', 'App\Controllers\Api\AuthController@user');

        // 书签API
        $router->get('/bookmarks', 'App\Controllers\Api\BookmarkController@index');
        $router->post('/bookmarks', 'App\Controllers\Api\BookmarkController@store');
        $router->get('/bookmarks/{id}', 'App\Controllers\Api\BookmarkController@show');
        $router->put('/bookmarks/{id}', 'App\Controllers\Api\BookmarkController@update');
        $router->delete('/bookmarks/{id}', 'App\Controllers\Api\BookmarkController@delete');

        // 分类API
        $router->get('/categories', 'App\Controllers\Api\CategoryController@index');
        $router->post('/categories', 'App\Controllers\Api\CategoryController@store');
        $router->get('/categories/{id}', 'App\Controllers\Api\CategoryController@show');
        $router->put('/categories/{id}', 'App\Controllers\Api\CategoryController@update');
        $router->delete('/categories/{id}', 'App\Controllers\Api\CategoryController@delete');

        // 搜索API
        $router->get('/search', 'App\Controllers\Api\SearchController@search');
        $router->get('/search/suggestions', 'App\Controllers\Api\SearchController@suggestions');
    });

    // 管理员路由
    $router->group('/admin', function(Router $router) {
        $router->get('/', 'App\Controllers\AdminController@dashboard');
        $router->get('/users', 'App\Controllers\AdminController@users');
        $router->get('/settings', 'App\Controllers\AdminController@settings');
        $router->post('/settings', 'App\Controllers\AdminController@updateSettings');

        // 数据迁移路由
        $router->get('/migration', 'App\Controllers\AdminController@migration');
        $router->post('/migration/booknav', 'App\Controllers\AdminController@migrateBookNav');
        $router->post('/migration/onenav', 'App\Controllers\AdminController@migrateOneNav');
        $router->post('/migration/import', 'App\Controllers\AdminController@importFile');

        // 备份管理路由
        $router->get('/backup', 'App\Controllers\AdminController@backup');
        $router->post('/backup/create', 'App\Controllers\AdminController@createBackup');
        $router->post('/backup/restore', 'App\Controllers\AdminController@restoreBackup');
        $router->get('/backup/download/{id}', 'App\Controllers\AdminController@downloadBackup');
        $router->delete('/backup/delete/{id}', 'App\Controllers\AdminController@deleteBackup');

        // 系统工具路由
        $router->get('/tools', 'App\Controllers\AdminController@tools');
        $router->post('/tools/check-links', 'App\Controllers\AdminController@checkLinks');
        $router->post('/tools/optimize-db', 'App\Controllers\AdminController@optimizeDatabase');
        $router->post('/tools/clear-cache', 'App\Controllers\AdminController@clearCache');
    });

    // 安装路由
    $router->get('/install', 'App\Controllers\InstallController@index');
    $router->post('/install', 'App\Controllers\InstallController@install');
    $router->post('/install/check', 'App\Controllers\InstallController@checkRequirements');

    // 静态文件路由
    $router->get('/assets/{file}', 'App\Controllers\AssetController@serve');
    $router->get('/uploads/{file}', 'App\Controllers\AssetController@uploads');

    // 主题路由
    $router->get('/themes/{theme}/{file}', 'App\Controllers\ThemeController@serve');
}

/**
 * 错误处理
 */
function handleError($exception)
{
    // 记录错误日志
    error_log($exception->getMessage());
    error_log($exception->getTraceAsString());

    // 设置响应头
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    // 开发环境显示详细错误信息
    if (ConfigService::getInstance()->get('app.debug', false)) {
        $response = [
            'error' => true,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
    } else {
        $response = [
            'error' => true,
            'message' => '服务器内部错误'
        ];
    }

    // 如果是 AJAX 请求，返回 JSON
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } else {
        // 显示错误页面
        include ROOT_PATH . '/templates/error.php';
    }
}

/**
 * 获取客户端 IP 地址
 */
function getClientIP()
{
    $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * 生成 CSRF Token
 */
function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证 CSRF Token
 */
function verifyCSRFToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 应用启动时的全局设置
 */
function initializeApplication()
{
    // 设置时区
    date_default_timezone_set(ConfigService::getInstance()->get('app.timezone', 'Asia/Shanghai'));

    // 启动会话
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 设置字符编码
    mb_internal_encoding('UTF-8');

    // 设置最大执行时间
    set_time_limit(ConfigService::getInstance()->get('app.max_execution_time', 30));

    // 设置内存限制
    ini_set('memory_limit', ConfigService::getInstance()->get('app.memory_limit', '128M'));
}

// 初始化应用
initializeApplication();