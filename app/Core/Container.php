<?php

namespace App\Core;

use Exception;

/**
 * 依赖注入容器
 *
 * 实现简单的服务容器，用于管理应用程序依赖
 */
class Container
{
    private static $instance;
    private array $bindings = [];
    private array $instances = [];

    private function __construct()
    {
        // 私有构造函数，实现单例模式
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 绑定服务到容器
     */
    public function bind(string $name, $resolver): void
    {
        $this->bindings[$name] = $resolver;
    }

    /**
     * 绑定单例服务到容器
     */
    public function singleton(string $name, $resolver): void
    {
        $this->bind($name, function() use ($resolver, $name) {
            if (!isset($this->instances[$name])) {
                $this->instances[$name] = is_callable($resolver) ? $resolver() : $resolver;
            }
            return $this->instances[$name];
        });
    }

    /**
     * 从容器中获取服务
     */
    public function get(string $name)
    {
        if (!$this->has($name)) {
            throw new Exception("Service '{$name}' not found in container");
        }

        $resolver = $this->bindings[$name];

        if (is_callable($resolver)) {
            return $resolver();
        }

        return $resolver;
    }

    /**
     * 检查容器中是否存在服务
     */
    public function has(string $name): bool
    {
        return isset($this->bindings[$name]);
    }

    /**
     * 移除服务绑定
     */
    public function remove(string $name): void
    {
        unset($this->bindings[$name]);
        unset($this->instances[$name]);
    }

    /**
     * 获取所有绑定的服务名称
     */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }

    /**
     * 解析类依赖并实例化
     */
    public function resolve(string $class)
    {
        if ($this->has($class)) {
            return $this->get($class);
        }

        if (!class_exists($class)) {
            throw new Exception("Class '{$class}' not found");
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new Exception("Class '{$class}' is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class;
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new Exception("Cannot resolve parameter '{$parameter->getName()}' for class '{$class}'");
                }
            } else {
                $typeName = $type->getName();
                $dependencies[] = $this->resolve($typeName);
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * 注册一个实例到容器
     */
    public function instance(string $name, $instance): void
    {
        $this->instances[$name] = $instance;
        $this->bind($name, function() use ($instance) {
            return $instance;
        });
    }

    /**
     * 清空容器
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
    }
}