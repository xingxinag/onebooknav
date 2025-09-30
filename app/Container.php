<?php
/**
 * OneBookNav - 依赖注入容器
 *
 * 简单的依赖注入容器，用于管理应用程序的服务和依赖
 */

class Container
{
    private $bindings = [];
    private $instances = [];

    /**
     * 绑定服务到容器
     */
    public function bind($name, $resolver)
    {
        $this->bindings[$name] = $resolver;
    }

    /**
     * 绑定单例服务
     */
    public function singleton($name, $resolver)
    {
        $this->bind($name, function() use ($resolver, $name) {
            if (!isset($this->instances[$name])) {
                $this->instances[$name] = is_callable($resolver) ? $resolver() : $resolver;
            }
            return $this->instances[$name];
        });
    }

    /**
     * 获取服务
     */
    public function get($name)
    {
        if (!isset($this->bindings[$name])) {
            throw new Exception("Service {$name} not found in container");
        }

        $resolver = $this->bindings[$name];

        if (is_callable($resolver)) {
            return $resolver();
        }

        return $resolver;
    }

    /**
     * 检查服务是否存在
     */
    public function has($name)
    {
        return isset($this->bindings[$name]);
    }

    /**
     * 解析类的依赖
     */
    public function resolve($className)
    {
        $reflectionClass = new ReflectionClass($className);

        $constructor = $reflectionClass->getConstructor();

        if ($constructor === null) {
            return new $className;
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new Exception("Cannot resolve parameter {$parameter->getName()} for class {$className}");
                }
            } else {
                $typeName = $type->getName();

                if ($this->has($typeName)) {
                    $dependencies[] = $this->get($typeName);
                } else {
                    $dependencies[] = $this->resolve($typeName);
                }
            }
        }

        return $reflectionClass->newInstanceArgs($dependencies);
    }
}