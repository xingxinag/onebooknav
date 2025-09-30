<?php

namespace App\Core;

use App\Services\ConfigService;
use App\Services\DatabaseService;
use Exception;

/**
 * OneBookNav 核心应用类
 *
 * 实现"统一核心，多态适配"架构的应用程序主类
 */
class Application
{
    private static $instance;
    private Container $container;
    private array $middleware = [];
    private bool $initialized = false;

    public function __construct()
    {
        if (self::$instance !== null) {
            throw new Exception('Application instance already exists');
        }

        self::$instance = $this;
        $this->container = Container::getInstance();
        $this->initialize();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化应用程序
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // 设置错误处理
        $this->setupErrorHandling();

        // 初始化配置
        $this->initializeConfig();

        // 初始化数据库
        $this->initializeDatabase();

        // 注册中间件
        $this->registerMiddleware();

        // 启动会话
        $this->startSession();

        $this->initialized = true;
    }

    /**
     * 设置错误处理
     */
    private function setupErrorHandling(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * 初始化配置
     */
    private function initializeConfig(): void
    {
        $config = ConfigService::getInstance();

        // 设置时区
        date_default_timezone_set($config->get('app.timezone', 'Asia/Shanghai'));

        // 设置字符编码
        mb_internal_encoding('UTF-8');

        // 设置错误报告级别
        if ($config->get('app.debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }
    }

    /**
     * 初始化数据库
     */
    private function initializeDatabase(): void
    {
        try {
            DatabaseService::getInstance();
        } catch (Exception $e) {
            // 如果数据库连接失败，记录错误但不中断应用
            error_log('Database initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * 注册中间件
     */
    private function registerMiddleware(): void
    {
        $this->middleware = [
            'security' => \App\Middleware\SecurityMiddleware::class,
            'auth' => \App\Middleware\AuthMiddleware::class,
            'csrf' => \App\Middleware\CSRFMiddleware::class,
            'rate_limit' => \App\Middleware\RateLimitMiddleware::class,
            'cors' => \App\Middleware\CORSMiddleware::class,
        ];
    }

    /**
     * 启动会话
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $config = ConfigService::getInstance();

            // 设置会话参数
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $config->get('app.https', false) ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', $config->get('session.lifetime', 86400));

            // 启动会话
            session_start();
        }
    }

    /**
     * 获取容器实例
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * 运行应用程序
     */
    public function run(Request $request, Response $response): void
    {
        try {
            // 执行中间件
            $this->runMiddleware($request, $response);

            // 处理请求（这将由路由器处理）
            echo "Application running...";

        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * 执行中间件
     */
    private function runMiddleware(Request $request, Response $response): void
    {
        foreach ($this->middleware as $name => $class) {
            if (class_exists($class)) {
                $middleware = new $class();
                if (method_exists($middleware, 'handle')) {
                    $middleware->handle($request, $response);
                }
            }
        }
    }

    /**
     * 获取配置实例
     */
    public function config(): ConfigService
    {
        return $this->container->get('config');
    }

    /**
     * 获取数据库实例
     */
    public function database(): DatabaseService
    {
        return $this->container->get('database');
    }

    /**
     * 错误处理器
     */
    public function handleError($severity, $message, $file, $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $error = [
            'type' => 'Error',
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'time' => date('Y-m-d H:i:s')
        ];

        $this->logError($error);

        if ($this->config()->get('app.debug', false)) {
            echo json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return true;
    }

    /**
     * 异常处理器
     */
    public function handleException($exception): void
    {
        $error = [
            'type' => 'Exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'time' => date('Y-m-d H:i:s')
        ];

        $this->logError($error);

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        if ($this->config()->get('app.debug', false)) {
            echo json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 关闭处理器
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }

    /**
     * 记录错误日志
     */
    private function logError(array $error): void
    {
        $logFile = DATA_PATH . '/logs/error.log';
        $logEntry = sprintf(
            "[%s] %s: %s in %s:%d\n",
            $error['time'],
            $error['type'],
            $error['message'],
            $error['file'] ?? 'unknown',
            $error['line'] ?? 0
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * 检查应用程序是否已安装
     */
    public function isInstalled(): bool
    {
        return $this->config()->get('app.installed', false);
    }

    /**
     * 获取应用版本
     */
    public function getVersion(): string
    {
        return APP_VERSION;
    }

    /**
     * 获取应用名称
     */
    public function getName(): string
    {
        return $this->config()->get('app.name', APP_NAME);
    }

    /**
     * 检查调试模式
     */
    public function isDebug(): bool
    {
        return $this->config()->get('app.debug', false);
    }

    /**
     * 获取应用环境
     */
    public function getEnvironment(): string
    {
        return $this->config()->get('app.env', 'production');
    }

    /**
     * 清理临时文件
     */
    public function cleanup(): void
    {
        $tempDir = DATA_PATH . '/temp';
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && time() - filemtime($file) > 3600) { // 1小时前的文件
                    unlink($file);
                }
            }
        }
    }

    /**
     * 应用程序析构
     */
    public function __destruct()
    {
        // 清理临时文件
        $this->cleanup();

        // 关闭数据库连接
        if ($this->container->has('database')) {
            $database = $this->container->get('database');
            if (method_exists($database, 'close')) {
                $database->close();
            }
        }
    }
}