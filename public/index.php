<?php

/**
 * OneBookNav - 统一书签导航系统
 *
 * 实现"终极.txt"要求的现代化导航网站
 * 融合 BookNav 和 OneNav 的所有功能，实现 1+1>2 的效果
 */

// 定义根路径常量
define('ROOT_PATH', dirname(__DIR__));
define('DATA_PATH', ROOT_PATH . '/data');
define('PUBLIC_PATH', __DIR__);

// 自动加载器
require_once ROOT_PATH . '/app/bootstrap.php';

// 启动应用
$app = new App\Core\Application();
$app->run();