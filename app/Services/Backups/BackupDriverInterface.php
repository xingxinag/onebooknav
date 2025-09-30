<?php

namespace App\Services\Backups;

/**
 * 备份驱动接口
 *
 * 定义所有备份驱动必须实现的方法
 */
interface BackupDriverInterface
{
    /**
     * 上传备份文件
     *
     * @param string $localPath 本地文件路径
     * @param string $remoteName 远程文件名
     * @return string 远程文件URL或路径
     */
    public function upload(string $localPath, string $remoteName): string;

    /**
     * 下载备份文件
     *
     * @param string $remoteName 远程文件名
     * @param string $localPath 本地保存路径
     * @return bool 下载是否成功
     */
    public function download(string $remoteName, string $localPath): bool;

    /**
     * 删除远程备份文件
     *
     * @param string $remoteName 远程文件名
     * @return bool 删除是否成功
     */
    public function delete(string $remoteName): bool;

    /**
     * 列出远程备份文件
     *
     * @return array 文件列表
     */
    public function list(): array;

    /**
     * 检查远程文件是否存在
     *
     * @param string $remoteName 远程文件名
     * @return bool 文件是否存在
     */
    public function exists(string $remoteName): bool;

    /**
     * 清理旧备份
     *
     * @param array $retention 保留策略
     * @return int 清理的文件数量
     */
    public function cleanup(array $retention): int;

    /**
     * 获取驱动名称
     *
     * @return string 驱动名称
     */
    public function getName(): string;

    /**
     * 测试连接
     *
     * @return bool 连接是否正常
     */
    public function testConnection(): bool;
}