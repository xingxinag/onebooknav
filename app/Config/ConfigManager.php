<?php

namespace App\Config;

/**
 * OneBookNav - 配置管理器
 *
 * 处理配置验证、热重载、默认值生成等功能
 */
class ConfigManager
{
    private array $config = [];
    private array $validationRules = [];
    private array $errors = [];
    private string $configPath;
    private string $envPath;

    public function __construct(string $configPath = null, string $envPath = null)
    {
        $this->configPath = $configPath ?? dirname(__DIR__, 2) . '/config';
        $this->envPath = $envPath ?? dirname(__DIR__, 2) . '/.env';
        $this->loadValidationRules();
    }

    /**
     * 加载验证规则
     */
    private function loadValidationRules(): void
    {
        $validationFile = $this->configPath . '/validation.php';
        if (file_exists($validationFile)) {
            $this->validationRules = require $validationFile;
        }
    }

    /**
     * 验证配置
     */
    public function validateConfig(array $config = null): bool
    {
        $config = $config ?? $_ENV;
        $this->errors = [];

        foreach ($this->validationRules['validation_rules'] ?? [] as $key => $rule) {
            $value = $config[$key] ?? null;

            // 检查必需字段
            if ($rule['required'] && empty($value)) {
                $this->errors[$key][] = "配置项 {$key} 是必需的";
                continue;
            }

            // 如果有依赖条件，检查是否满足
            if (isset($rule['depends_on'])) {
                if (!$this->checkDependency($config, $rule['depends_on'])) {
                    continue; // 依赖条件不满足，跳过验证
                }
            }

            // 应用默认值
            if (empty($value) && isset($rule['default'])) {
                $defaultValue = is_callable($rule['default'])
                    ? $rule['default']($config)
                    : $rule['default'];
                $value = $defaultValue;
                $config[$key] = $value;
            }

            // 类型验证
            if (!empty($value) && !$this->validateType($value, $rule['type'])) {
                $this->errors[$key][] = "配置项 {$key} 类型错误，期望 {$rule['type']}";
            }

            // 自定义规则验证
            if (!empty($value) && isset($rule['rules'])) {
                foreach ($rule['rules'] as $ruleString) {
                    if (!$this->validateRule($value, $ruleString)) {
                        $this->errors[$key][] = "配置项 {$key} 验证失败: {$ruleString}";
                    }
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * 检查依赖条件
     */
    private function checkDependency(array $config, array $dependencies): bool
    {
        foreach ($dependencies as $depKey => $depValues) {
            $configValue = $config[$depKey] ?? null;
            if (!in_array($configValue, (array)$depValues)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 验证类型
     */
    private function validateType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_numeric($value) && (int)$value == $value;
            case 'boolean':
                return is_bool($value) || in_array(strtolower($value), ['true', 'false', '1', '0']);
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            case 'array':
                return is_array($value) || is_string($value);
            default:
                return true;
        }
    }

    /**
     * 验证规则
     */
    private function validateRule($value, string $rule): bool
    {
        if (strpos($rule, ':') !== false) {
            [$ruleName, $parameter] = explode(':', $rule, 2);
        } else {
            $ruleName = $rule;
            $parameter = null;
        }

        switch ($ruleName) {
            case 'min':
                return strlen($value) >= (int)$parameter;
            case 'max':
                return strlen($value) <= (int)$parameter;
            case 'between':
                [$min, $max] = explode(',', $parameter);
                $numValue = (int)$value;
                return $numValue >= (int)$min && $numValue <= (int)$max;
            case 'in':
                $allowedValues = explode(',', $parameter);
                return in_array($value, $allowedValues);
            case 'ip':
                return filter_var($value, FILTER_VALIDATE_IP) !== false;
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            default:
                // 检查自定义验证器
                if (isset($this->validationRules['custom_validators'][$ruleName])) {
                    $validator = $this->validationRules['custom_validators'][$ruleName];
                    return $validator($value);
                }
                return true;
        }
    }

    /**
     * 获取验证错误
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 生成默认配置
     */
    public function generateDefaultConfig(string $env = 'production'): array
    {
        $config = [];
        $rules = $this->validationRules['validation_rules'] ?? [];

        foreach ($rules as $key => $rule) {
            if (isset($rule['default'])) {
                $config[$key] = is_callable($rule['default'])
                    ? $rule['default']([])
                    : $rule['default'];
            }
        }

        // 应用环境特定配置
        if (isset($this->validationRules['environment_specific'][$env])) {
            $config = array_merge($config, $this->validationRules['environment_specific'][$env]);
        }

        return $config;
    }

    /**
     * 生成 .env 文件
     */
    public function generateEnvFile(array $config, string $filePath = null): bool
    {
        $filePath = $filePath ?? $this->envPath;
        $content = "# OneBookNav Environment Configuration\n";
        $content .= "# Generated on " . date('Y-m-d H:i:s') . "\n\n";

        $groups = $this->validationRules['config_groups'] ?? [];

        foreach ($groups as $groupKey => $group) {
            $content .= "# {$group['name']}\n";
            $content .= "# {$group['description']}\n";

            foreach ($group['keys'] as $key) {
                $value = $config[$key] ?? '';
                $rule = $this->validationRules['validation_rules'][$key] ?? [];

                // 添加注释
                if (isset($rule['description'])) {
                    $content .= "# {$rule['description']}\n";
                }

                // 格式化值
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $value = implode(',', $value);
                }

                $content .= "{$key}={$value}\n";
            }
            $content .= "\n";
        }

        return file_put_contents($filePath, $content) !== false;
    }

    /**
     * 热重载配置
     */
    public function reloadConfig(): bool
    {
        try {
            // 重新加载环境变量
            if (file_exists($this->envPath)) {
                $this->loadEnvFile($this->envPath);
            }

            // 重新加载验证规则
            $this->loadValidationRules();

            // 清除配置缓存
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            return true;
        } catch (\Exception $e) {
            $this->errors['reload'][] = "配置重载失败: " . $e->getMessage();
            return false;
        }
    }

    /**
     * 加载 .env 文件
     */
    private function loadEnvFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // 跳过注释行
            if (strpos($line, '#') === 0) {
                continue;
            }

            // 解析键值对
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // 处理引号
                if (preg_match('/^"(.*)"$/', $value, $matches)) {
                    $value = $matches[1];
                } elseif (preg_match('/^\'(.*)\'$/', $value, $matches)) {
                    $value = $matches[1];
                }

                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * 迁移配置
     */
    public function migrateConfig(array $oldConfig, string $sourceType = 'v1.0'): array
    {
        $newConfig = [];
        $mapping = $this->validationRules['migration_mapping'][$sourceType] ?? [];

        foreach ($oldConfig as $oldKey => $value) {
            if (isset($mapping[$oldKey])) {
                $newKey = $mapping[$oldKey];

                if (is_callable($newKey)) {
                    // 自定义映射函数
                    $result = $newKey($value);
                    if (is_array($result)) {
                        $newConfig = array_merge($newConfig, $result);
                    }
                } else {
                    // 简单映射
                    $newConfig[$newKey] = $value;
                }
            }
        }

        return $newConfig;
    }

    /**
     * 检查配置完整性
     */
    public function checkConfigIntegrity(): array
    {
        $report = [
            'status' => 'ok',
            'missing_required' => [],
            'deprecated' => [],
            'suggestions' => []
        ];

        $rules = $this->validationRules['validation_rules'] ?? [];

        foreach ($rules as $key => $rule) {
            $value = $_ENV[$key] ?? null;

            if ($rule['required'] && empty($value)) {
                $report['missing_required'][] = $key;
                $report['status'] = 'error';
            }
        }

        // 检查是否有建议的优化
        if ($_ENV['APP_ENV'] === 'production') {
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                $report['suggestions'][] = '生产环境应关闭调试模式 (APP_DEBUG=false)';
            }
            if (($_ENV['CACHE_DRIVER'] ?? 'file') === 'array') {
                $report['suggestions'][] = '生产环境建议使用 Redis 或 Memcached 缓存';
            }
        }

        return $report;
    }

    /**
     * 获取配置组
     */
    public function getConfigGroups(): array
    {
        return $this->validationRules['config_groups'] ?? [];
    }

    /**
     * 获取配置项信息
     */
    public function getConfigInfo(string $key): array
    {
        return $this->validationRules['validation_rules'][$key] ?? [];
    }

    /**
     * 生成配置文档
     */
    public function generateConfigDocs(): string
    {
        $docs = "# OneBookNav 配置文档\n\n";
        $groups = $this->validationRules['config_groups'] ?? [];
        $rules = $this->validationRules['validation_rules'] ?? [];

        foreach ($groups as $groupKey => $group) {
            $docs .= "## {$group['name']}\n\n";
            $docs .= "{$group['description']}\n\n";
            $docs .= "| 配置项 | 类型 | 默认值 | 描述 | 必需 |\n";
            $docs .= "|--------|------|--------|------|------|\n";

            foreach ($group['keys'] as $key) {
                $rule = $rules[$key] ?? [];
                $type = $rule['type'] ?? 'string';
                $default = isset($rule['default']) ?
                    (is_callable($rule['default']) ? '动态' : $rule['default']) :
                    '';
                $description = $rule['description'] ?? '';
                $required = ($rule['required'] ?? false) ? '是' : '否';

                $docs .= "| `{$key}` | {$type} | `{$default}` | {$description} | {$required} |\n";
            }
            $docs .= "\n";
        }

        return $docs;
    }
}