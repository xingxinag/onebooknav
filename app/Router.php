<?php
/**
 * OneBookNav - 路由器
 *
 * 处理 HTTP 请求路由和分发
 */

class Router
{
    private $routes = [];
    private $middleware = [];
    private $app;
    private $currentRoute;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * 添加 GET 路由
     */
    public function get($path, $handler, $middleware = [])
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * 添加 POST 路由
     */
    public function post($path, $handler, $middleware = [])
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * 添加 PUT 路由
     */
    public function put($path, $handler, $middleware = [])
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * 添加 DELETE 路由
     */
    public function delete($path, $handler, $middleware = [])
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * 添加 PATCH 路由
     */
    public function patch($path, $handler, $middleware = [])
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * 添加任何方法的路由
     */
    public function any($path, $handler, $middleware = [])
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        foreach ($methods as $method) {
            $this->addRoute($method, $path, $handler, $middleware);
        }
    }

    /**
     * 路由组
     */
    public function group($prefix, $callback, $middleware = [])
    {
        $originalPrefix = $this->currentPrefix ?? '';
        $originalMiddleware = $this->currentMiddleware ?? [];

        $this->currentPrefix = rtrim($originalPrefix . '/' . trim($prefix, '/'), '/');
        $this->currentMiddleware = array_merge($originalMiddleware, $middleware);

        $callback($this);

        $this->currentPrefix = $originalPrefix;
        $this->currentMiddleware = $originalMiddleware;
    }

    /**
     * 添加路由
     */
    private function addRoute($method, $path, $handler, $middleware = [])
    {
        $prefix = $this->currentPrefix ?? '';
        $fullPath = $prefix . '/' . trim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        $allMiddleware = array_merge($this->currentMiddleware ?? [], $middleware);

        $route = [
            'method' => $method,
            'path' => $fullPath,
            'pattern' => $this->pathToPattern($fullPath),
            'handler' => $handler,
            'middleware' => $allMiddleware,
            'params' => []
        ];

        $this->routes[] = $route;
        return $this;
    }

    /**
     * 路径转换为正则表达式
     */
    private function pathToPattern($path)
    {
        // 转义特殊字符
        $pattern = preg_quote($path, '/');

        // 处理参数 {id}, {name} 等
        $pattern = preg_replace('/\\\{([^}]+)\\\}/', '(?P<$1>[^/]+)', $pattern);

        return '/^' . $pattern . '$/';
    }

    /**
     * 分发请求
     */
    public function dispatch()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // 处理 PUT, DELETE 等方法的模拟
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        $route = $this->findRoute($method, $path);

        if (!$route) {
            throw new NotFoundException("Route not found: {$method} {$path}");
        }

        $this->currentRoute = $route;

        // 执行中间件
        $this->executeMiddleware($route['middleware'], function() use ($route) {
            $this->executeHandler($route);
        });
    }

    /**
     * 查找匹配的路由
     */
    private function findRoute($method, $path)
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $path, $matches)) {
                // 提取路由参数
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                $route['params'] = $params;
                return $route;
            }
        }

        return null;
    }

    /**
     * 执行中间件
     */
    private function executeMiddleware($middleware, $next)
    {
        if (empty($middleware)) {
            return $next();
        }

        $middlewareClass = array_shift($middleware);

        if (is_string($middlewareClass)) {
            $middlewareInstance = new $middlewareClass();
        } else {
            $middlewareInstance = $middlewareClass;
        }

        return $middlewareInstance->handle($_REQUEST, function() use ($middleware, $next) {
            return $this->executeMiddleware($middleware, $next);
        });
    }

    /**
     * 执行处理器
     */
    private function executeHandler($route)
    {
        $handler = $route['handler'];
        $params = $route['params'];

        if (is_string($handler)) {
            // 控制器@方法 格式
            if (strpos($handler, '@') !== false) {
                list($controllerClass, $method) = explode('@', $handler, 2);
                $controllerInstance = $this->resolveController($controllerClass);
                return $controllerInstance->$method($params);
            }

            // 单独的控制器类
            $controllerInstance = $this->resolveController($handler);
            return $controllerInstance->index($params);
        }

        if (is_callable($handler)) {
            return $handler($params);
        }

        if (is_array($handler) && count($handler) === 2) {
            list($controllerClass, $method) = $handler;
            $controllerInstance = $this->resolveController($controllerClass);
            return $controllerInstance->$method($params);
        }

        throw new Exception("Invalid route handler");
    }

    /**
     * 解析控制器
     */
    private function resolveController($controllerClass)
    {
        if (!class_exists($controllerClass)) {
            require_once __DIR__ . "/Controllers/{$controllerClass}.php";
        }

        return $this->app->get('container')->resolve($controllerClass);
    }

    /**
     * 生成 URL
     */
    public function url($name, $params = [])
    {
        foreach ($this->routes as $route) {
            if (isset($route['name']) && $route['name'] === $name) {
                $url = $route['path'];

                foreach ($params as $key => $value) {
                    $url = str_replace('{' . $key . '}', $value, $url);
                }

                return $url;
            }
        }

        throw new Exception("Route {$name} not found");
    }

    /**
     * 命名路由
     */
    public function name($name)
    {
        if (!empty($this->routes)) {
            $lastRoute = &$this->routes[count($this->routes) - 1];
            $lastRoute['name'] = $name;
        }

        return $this;
    }

    /**
     * 获取当前路由
     */
    public function getCurrentRoute()
    {
        return $this->currentRoute;
    }

    /**
     * 获取路由参数
     */
    public function getParam($name, $default = null)
    {
        return $this->currentRoute['params'][$name] ?? $default;
    }

    /**
     * 获取所有路由参数
     */
    public function getParams()
    {
        return $this->currentRoute['params'] ?? [];
    }

    /**
     * 重定向
     */
    public function redirect($url, $status = 302)
    {
        http_response_code($status);
        header("Location: {$url}");
        exit;
    }

    /**
     * 获取所有路由
     */
    public function getRoutes()
    {
        return $this->routes;
    }
}

/**
 * 路由未找到异常
 */
class NotFoundException extends Exception {}

/**
 * 安全异常
 */
class SecurityException extends Exception {}