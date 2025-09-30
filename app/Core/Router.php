<?php

namespace App\Core;

use Exception;

/**
 * 路由器类
 *
 * 实现现代化的路由管理，支持中间件、路由组等功能
 */
class Router
{
    private array $routes = [];
    private array $middleware = [];
    private array $groupStack = [];
    private Container $container;

    public function __construct()
    {
        $this->container = Container::getInstance();
    }

    /**
     * 注册 GET 路由
     */
    public function get(string $uri, $action): self
    {
        return $this->addRoute('GET', $uri, $action);
    }

    /**
     * 注册 POST 路由
     */
    public function post(string $uri, $action): self
    {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * 注册 PUT 路由
     */
    public function put(string $uri, $action): self
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * 注册 DELETE 路由
     */
    public function delete(string $uri, $action): self
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * 注册 PATCH 路由
     */
    public function patch(string $uri, $action): self
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    /**
     * 注册多种方法的路由
     */
    public function match(array $methods, string $uri, $action): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $uri, $action);
        }
        return $this;
    }

    /**
     * 注册所有方法的路由
     */
    public function any(string $uri, $action): self
    {
        return $this->match(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $uri, $action);
    }

    /**
     * 路由组
     */
    public function group($prefix, callable $callback): void
    {
        $previousGroup = end($this->groupStack) ?: [];

        if (is_string($prefix)) {
            $group = array_merge($previousGroup, ['prefix' => $prefix]);
        } else {
            $group = array_merge($previousGroup, $prefix);
        }

        $this->groupStack[] = $group;
        $callback($this);
        array_pop($this->groupStack);
    }

    /**
     * 添加中间件到路由
     */
    public function middleware($middleware): self
    {
        $lastRoute = end($this->routes);
        if ($lastRoute) {
            $key = key($this->routes);
            $this->routes[$key]['middleware'] = array_merge(
                $this->routes[$key]['middleware'] ?? [],
                is_array($middleware) ? $middleware : [$middleware]
            );
        }
        return $this;
    }

    /**
     * 添加路由名称
     */
    public function name(string $name): self
    {
        $lastRoute = end($this->routes);
        if ($lastRoute) {
            $key = key($this->routes);
            $this->routes[$key]['name'] = $name;
        }
        return $this;
    }

    /**
     * 添加路由到路由表
     */
    private function addRoute(string $method, string $uri, $action): self
    {
        $group = end($this->groupStack) ?: [];

        // 处理前缀
        if (isset($group['prefix'])) {
            $uri = '/' . trim($group['prefix'], '/') . '/' . trim($uri, '/');
            $uri = rtrim($uri, '/') ?: '/';
        }

        // 处理中间件
        $middleware = [];
        if (isset($group['middleware'])) {
            $middleware = array_merge($middleware, (array)$group['middleware']);
        }

        $route = [
            'method' => $method,
            'uri' => $uri,
            'action' => $action,
            'middleware' => $middleware,
            'parameters' => [],
            'compiled' => $this->compileRoute($uri)
        ];

        $this->routes[] = $route;
        return $this;
    }

    /**
     * 编译路由模式
     */
    private function compileRoute(string $uri): array
    {
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $uri);
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '/^' . $pattern . '$/';

        return [
            'pattern' => $pattern,
            'parameters' => $this->extractParameters($uri)
        ];
    }

    /**
     * 提取路由参数
     */
    private function extractParameters(string $uri): array
    {
        preg_match_all('/\{(\w+)\}/', $uri, $matches);
        return $matches[1] ?? [];
    }

    /**
     * 分发请求
     */
    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->getMethod();
        $uri = $request->getPath();

        $route = $this->findRoute($method, $uri);

        if (!$route) {
            $this->handleNotFound($response);
            return;
        }

        // 执行中间件
        $this->runMiddleware($route['middleware'], $request, $response);

        // 执行控制器动作
        $this->runAction($route, $request, $response);
    }

    /**
     * 查找匹配的路由
     */
    private function findRoute(string $method, string $uri): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['compiled']['pattern'], $uri, $matches)) {
                // 提取参数
                $parameters = [];
                foreach ($route['compiled']['parameters'] as $param) {
                    if (isset($matches[$param])) {
                        $parameters[$param] = $matches[$param];
                    }
                }
                $route['parameters'] = $parameters;
                return $route;
            }
        }

        return null;
    }

    /**
     * 运行中间件
     */
    private function runMiddleware(array $middleware, Request $request, Response $response): void
    {
        foreach ($middleware as $middlewareName) {
            $middlewareClass = $this->resolveMiddleware($middlewareName);
            if ($middlewareClass && method_exists($middlewareClass, 'handle')) {
                $middlewareClass->handle($request, $response);
            }
        }
    }

    /**
     * 解析中间件
     */
    private function resolveMiddleware(string $middleware)
    {
        $middlewareMap = [
            'auth' => \App\Middleware\AuthMiddleware::class,
            'guest' => \App\Middleware\GuestMiddleware::class,
            'admin' => \App\Middleware\AdminMiddleware::class,
            'csrf' => \App\Middleware\CSRFMiddleware::class,
            'throttle' => \App\Middleware\ThrottleMiddleware::class,
        ];

        $class = $middlewareMap[$middleware] ?? $middleware;

        if (class_exists($class)) {
            return new $class();
        }

        return null;
    }

    /**
     * 执行控制器动作
     */
    private function runAction(array $route, Request $request, Response $response): void
    {
        $action = $route['action'];

        if (is_callable($action)) {
            // 闭包函数
            call_user_func($action, $request, $response, $route['parameters']);
        } elseif (is_string($action) && strpos($action, '@') !== false) {
            // 控制器@方法格式
            list($controller, $method) = explode('@', $action);
            $this->callControllerMethod($controller, $method, $request, $response, $route['parameters']);
        } else {
            throw new Exception("Invalid route action: " . var_export($action, true));
        }
    }

    /**
     * 调用控制器方法
     */
    private function callControllerMethod(string $controller, string $method, Request $request, Response $response, array $parameters): void
    {
        if (!class_exists($controller)) {
            throw new Exception("Controller class '{$controller}' not found");
        }

        $instance = $this->container->resolve($controller);

        if (!method_exists($instance, $method)) {
            throw new Exception("Method '{$method}' not found in controller '{$controller}'");
        }

        // 设置请求参数
        $request->setParameters($parameters);

        // 调用控制器方法
        $result = $instance->$method($request, $response);

        // 处理返回值
        if (is_array($result) || is_object($result)) {
            $response->json($result);
        } elseif (is_string($result)) {
            $response->write($result);
        }
    }

    /**
     * 处理404错误
     */
    private function handleNotFound(Response $response): void
    {
        $response->setStatusCode(404);

        // 检查是否是API请求
        if ($this->isApiRequest()) {
            $response->json(['error' => 'Not Found', 'code' => 404]);
        } else {
            $response->write($this->render404Page());
        }
    }

    /**
     * 检查是否是API请求
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/api/') === 0 ||
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }

    /**
     * 渲染404页面
     */
    private function render404Page(): string
    {
        $template = ROOT_PATH . '/templates/errors/404.php';
        if (file_exists($template)) {
            ob_start();
            include $template;
            return ob_get_clean();
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>页面未找到 - OneBookNav</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        h1 { color: #333; }
        p { color: #666; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>404 - 页面未找到</h1>
    <p>抱歉，您访问的页面不存在。</p>
    <a href="/">返回首页</a>
</body>
</html>';
    }

    /**
     * 生成URL
     */
    public function url(string $name, array $parameters = []): string
    {
        foreach ($this->routes as $route) {
            if (isset($route['name']) && $route['name'] === $name) {
                $uri = $route['uri'];
                foreach ($parameters as $key => $value) {
                    $uri = str_replace('{' . $key . '}', $value, $uri);
                }
                return $uri;
            }
        }

        throw new Exception("Route with name '{$name}' not found");
    }

    /**
     * 获取所有路由
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}