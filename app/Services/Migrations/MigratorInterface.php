<?php

namespace App\Services\Migrations;

/**
 * 数据迁移器接口
 *
 * 定义所有迁移器必须实现的方法
 */
interface MigratorInterface
{
    /**
     * 获取迁移器名称
     */
    public function getName(): string;

    /**
     * 获取迁移器描述
     */
    public function getDescription(): string;

    /**
     * 获取支持的格式
     */
    public function getSupportedFormats(): array;

    /**
     * 检测输入源类型
     *
     * @param mixed $input 输入数据
     * @return int 置信度 (0-100)
     */
    public function detect($input): int;

    /**
     * 验证输入数据
     *
     * @param mixed $input 输入数据
     * @return array 验证结果
     * @throws \Exception 验证失败时抛出异常
     */
    public function validate($input): array;

    /**
     * 解析输入数据
     *
     * @param mixed $input 输入数据
     * @param array $options 解析选项
     * @return array 解析后的数据
     */
    public function parse($input, array $options = []): array;
}