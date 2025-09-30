<?php
/**
 * OneBookNav - 应用程序核心类
 *
 * 应用程序的主要入口点，负责初始化、路由和请求处理
 */

class Application
{
    private $config;
    private $db;
    private $router;
    private $session;
    private $security;
    private $container;
    private static $instance;

    public function __construct($config = [])
    {
        $this->config = $config;
        $this->container = new Container();
        self::$instance = $this;

        $this->bootstrap();
    }

    /**
     * 获取应用程序实例
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * 应用程序启动
     */
    private function bootstrap()
    {
        // 设置错误处理
        $this->setupErrorHandling();

        // 加载环境变量
        $this->loadEnvironment();

        // 初始化数据库连接
        $this->initializeDatabase();

        // 初始化会话
        $this->initializeSession();

        // 初始化安全组件
        $this->initializeSecurity();

        // 初始化路由器
        $this->initializeRouter();

        // 注册服务到容器
        $this->registerServices();

        // 设置时区
        date_default_timezone_set($this->config['timezone'] ?? 'Asia/Shanghai');
    }

    /**
     * 运行应用程序
     */
    public function run()
    {
        try {
            // 安全检查
            $this->security->performSecurityChecks();

            // 处理路由
            $this->router->dispatch();

        } catch (SecurityException $e) {
            $this->handleSecurityException($e);
        } catch (NotFoundException $e) {
            $this->handle404($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * 设置错误处理
     */
    private function setupErrorHandling()
    {
        if ($this->config['debug'] ?? false) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * 加载环境变量
     */
    private function loadEnvironment()
    {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value, '"\'');
                }
            }
        }
    }

    /**
     * 初始化数据库连接
     */
    private function initializeDatabase()
    {
        $dbConfig = $this->config['database'];
        $connection = $dbConfig['connections'][$dbConfig['default']];

        try {
            if ($connection['driver'] === 'sqlite') {
                $dsn = "sqlite:" . $connection['database'];

                // 确保数据库目录存在
                $dbDir = dirname($connection['database']);
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
            } else {
                $dsn = "{$connection['driver']}:host={$connection['host']};port={$connection['port']};dbname={$connection['database']}";
            }

            $this->db = new PDO($dsn, $connection['username'] ?? null, $connection['password'] ?? null, $connection['options']);

            // SQLite 特殊设置
            if ($connection['driver'] === 'sqlite') {
                $this->db->exec("PRAGMA foreign_keys = ON");
                $this->db->exec("PRAGMA journal_mode = WAL");
                $this->db->exec("PRAGMA synchronous = NORMAL");
                $this->db->exec("PRAGMA cache_size = 64000");
                $this->db->exec("PRAGMA temp_store = MEMORY");
            }

        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * 初始化会话
     */
    private function initializeSession()
    {
        $sessionConfig = $this->config['session'];

        session_set_cookie_params([
            'lifetime' => $sessionConfig['lifetime'],
            'path' => $sessionConfig['cookie']['path'],
            'domain' => $sessionConfig['cookie']['domain'],
            'secure' => $sessionConfig['cookie']['secure'],
            'httponly' => $sessionConfig['cookie']['http_only'],
            'samesite' => $sessionConfig['cookie']['same_site']
        ]);

        session_name($sessionConfig['cookie']['name']);
        session_start();

        $this->session = new SessionManager($sessionConfig);
    }

    /**
     * 初始化安全组件
     */
    private function initializeSecurity()
    {
        $this->security = new SecurityManager($this->config['security'] ?? []);
    }

    /**
     * 初始化路由器
     */
    private function initializeRouter()
    {
        $this->router = new Router($this);
        $this->loadRoutes();
    }

    /**
     * 加载路由
     */
    private function loadRoutes()
    {
        // Web 路由
        require_once __DIR__ . '/../routes/web.php';

        // API 路由
        if (file_exists(__DIR__ . '/../routes/api.php')) {
            require_once __DIR__ . '/../routes/api.php';
        }
    }

    /**
     * 注册服务到容器
     */
    private function registerServices()
    {
        $this->container->bind('db', $this->db);
        $this->container->bind('config', $this->config);
        $this->container->bind('session', $this->session);
        $this->container->bind('security', $this->security);
        $this->container->bind('router', $this->router);

        // 注册模型
        $this->container->bind('UserModel', function() {
            return new UserModel($this->db);
        });

        $this->container->bind('CategoryModel', function() {
            return new CategoryModel($this->db);
        });

        $this->container->bind('WebsiteModel', function() {
            return new WebsiteModel($this->db);
        });

        // 注册服务类
        $this->container->bind('AuthService', function() {
            return new AuthService($this->container->get('UserModel'), $this->session);
        });

        $this->container->bind('BookmarkService', function() {
            return new BookmarkService(
                $this->container->get('WebsiteModel'),
                $this->container->get('CategoryModel')
            );
        });
    }

    /**
     * 获取服务
     */
    public function get($name)
    {
        return $this->container->get($name);
    }

    /**
     * 获取配置
     */
    public function getConfig($key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 获取数据库连接
     */
    public function getDatabase()
    {
        return $this->db;
    }

    /**
     * 获取路由器
     */
    public function getRouter()
    {
        return $this->router;
    }

    /**
     * 错误处理
     */
    public function handleError($severity, $message, $file, $line)
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * 异常处理
     */
    public function handleException($exception)
    {
        error_log("Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());

        if ($this->config['debug'] ?? false) {
            $this->renderDebugPage($exception);
        } else {
            $this->render500();
        }
    }

    /**
     * 安全异常处理
     */
    private function handleSecurityException($exception)
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Security violation',
            'message' => $exception->getMessage()
        ]);
        exit;
    }

    /**
     * 404 处理
     */
    private function handle404($exception = null)
    {
        http_response_code(404);

        if ($this->isApiRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not Found', 'message' => 'The requested resource was not found']);
        } else {
            include __DIR__ . '/../views/errors/404.php';
        }
        exit;
    }

    /**
     * 500 错误处理
     */
    private function render500()
    {
        http_response_code(500);

        if ($this->isApiRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal Server Error']);
        } else {
            include __DIR__ . '/../views/errors/500.php';
        }
        exit;
    }

    /**
     * 调试页面
     */
    private function renderDebugPage($exception)
    {
        http_response_code(500);
        include __DIR__ . '/../views/errors/debug.php';
        exit;
    }

    /**
     * 判断是否是 API 请求
     */
    private function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') === 0 ||
               strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }

    /**
     * 关闭处理
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    }
}