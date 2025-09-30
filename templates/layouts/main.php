<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= $theme ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($description ?? '个人书签导航网站') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($keywords ?? 'bookmark,navigation,导航,书签') ?>">
    <meta name="author" content="OneBookNav">
    <meta name="csrf-token" content="<?= $csrfToken ?? '' ?>">

    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($title ?? 'OneBookNav') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description ?? '个人书签导航网站') ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($currentUrl ?? '') ?>">

    <!-- PWA Meta Tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="OneBookNav">
    <meta name="theme-color" content="#667eea">

    <title><?= htmlspecialchars($title ?? 'OneBookNav') ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">

    <!-- CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- 主题特定样式 -->
    <?php if (isset($themeStyles)): ?>
        <?php foreach ($themeStyles as $style): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($style) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- 预加载关键资源 -->
    <link rel="preload" href="/assets/js/main.js" as="script">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <!-- 自定义CSS -->
    <?php if (isset($customStyles)): ?>
        <style><?= $customStyles ?></style>
    <?php endif; ?>
</head>

<body class="<?= $bodyClass ?? '' ?>">
    <!-- 页面加载器 -->
    <div id="page-loader" class="page-loader">
        <div class="loader">
            <div class="loading"></div>
            <p>正在加载...</p>
        </div>
    </div>

    <!-- 导航栏 -->
    <nav class="navbar" id="navbar">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center w-100">
                <!-- 左侧 -->
                <div class="d-flex align-items-center">
                    <!-- 侧边栏切换按钮 -->
                    <button class="btn btn-ghost btn-sm" id="sidebar-toggle" aria-label="切换侧边栏">
                        <i class="fas fa-bars"></i>
                    </button>

                    <!-- 品牌标识 -->
                    <a href="/" class="navbar-brand ms-3">
                        <i class="fas fa-bookmark"></i>
                        OneBookNav
                    </a>
                </div>

                <!-- 中间搜索框 -->
                <div class="search-container d-none d-md-block">
                    <div class="position-relative">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text"
                               id="search-input"
                               class="search-input"
                               placeholder="搜索书签... (Ctrl+K)"
                               autocomplete="off">
                    </div>
                    <div id="search-results" class="search-results"></div>
                </div>

                <!-- 右侧 -->
                <div class="d-flex align-items-center">
                    <!-- 移动端搜索按钮 -->
                    <button class="btn btn-ghost btn-sm d-md-none" id="mobile-search-toggle" aria-label="搜索">
                        <i class="fas fa-search"></i>
                    </button>

                    <!-- 主题切换 -->
                    <button class="btn btn-ghost btn-sm" id="theme-toggle" aria-label="切换主题">
                        <i class="fas fa-moon"></i>
                    </button>

                    <!-- 用户菜单 -->
                    <?php if (isset($user) && $user): ?>
                        <div class="dropdown">
                            <button class="btn btn-ghost btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <img src="<?= htmlspecialchars($user['avatar'] ?? '/assets/images/default-avatar.png') ?>"
                                     alt="<?= htmlspecialchars($user['username']) ?>"
                                     class="user-avatar">
                                <span class="d-none d-md-inline ms-2"><?= htmlspecialchars($user['username']) ?></span>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="/profile"><i class="fas fa-user me-2"></i>个人资料</a></li>
                                <li><a class="dropdown-item" href="/settings"><i class="fas fa-cog me-2"></i>设置</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <li><a class="dropdown-item" href="/admin"><i class="fas fa-shield-alt me-2"></i>管理后台</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="/logout"><i class="fas fa-sign-out-alt me-2"></i>退出登录</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="/login" class="btn btn-primary btn-sm">
                            <i class="fas fa-sign-in-alt"></i>
                            <span class="d-none d-md-inline ms-2">登录</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- 移动端搜索框 -->
    <div id="mobile-search" class="mobile-search d-md-none">
        <div class="container-fluid">
            <div class="search-container">
                <div class="position-relative">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text"
                           id="mobile-search-input"
                           class="search-input"
                           placeholder="搜索书签..."
                           autocomplete="off">
                    <button class="btn btn-ghost btn-sm search-close" id="mobile-search-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 主布局容器 -->
    <div class="main-layout">
        <!-- 侧边栏 -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- 添加书签按钮 -->
                <div class="sidebar-header p-3">
                    <button class="btn btn-primary w-100" data-modal-target="add-bookmark-modal">
                        <i class="fas fa-plus me-2"></i>
                        <span class="sidebar-text">添加书签</span>
                    </button>
                </div>

                <!-- 导航菜单 -->
                <nav class="sidebar-nav">
                    <ul class="sidebar-menu">
                        <li class="sidebar-item">
                            <a href="/" class="sidebar-link <?= $currentPage === 'home' ? 'active' : '' ?>">
                                <i class="sidebar-icon fas fa-home"></i>
                                <span class="sidebar-text">首页</span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a href="/favorites" class="sidebar-link <?= $currentPage === 'favorites' ? 'active' : '' ?>">
                                <i class="sidebar-icon fas fa-heart"></i>
                                <span class="sidebar-text">我的收藏</span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a href="/recent" class="sidebar-link <?= $currentPage === 'recent' ? 'active' : '' ?>">
                                <i class="sidebar-icon fas fa-clock"></i>
                                <span class="sidebar-text">最近访问</span>
                            </a>
                        </li>
                    </ul>

                    <!-- 分类列表 -->
                    <?php if (isset($categories) && !empty($categories)): ?>
                        <div class="sidebar-section">
                            <h6 class="sidebar-section-title">
                                <span class="sidebar-text">分类</span>
                            </h6>
                            <ul class="sidebar-menu">
                                <?php foreach ($categories as $category): ?>
                                    <li class="sidebar-item">
                                        <a href="/category/<?= $category['id'] ?>"
                                           class="sidebar-link <?= isset($currentCategory) && $currentCategory == $category['id'] ? 'active' : '' ?>">
                                            <i class="sidebar-icon <?= htmlspecialchars($category['icon']) ?>"></i>
                                            <span class="sidebar-text"><?= htmlspecialchars($category['name']) ?></span>
                                            <span class="sidebar-count"><?= $category['website_count'] ?? 0 ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- 底部链接 -->
                    <div class="sidebar-footer">
                        <ul class="sidebar-menu">
                            <li class="sidebar-item">
                                <a href="/import" class="sidebar-link">
                                    <i class="sidebar-icon fas fa-file-import"></i>
                                    <span class="sidebar-text">导入书签</span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a href="/export" class="sidebar-link">
                                    <i class="sidebar-icon fas fa-file-export"></i>
                                    <span class="sidebar-text">导出书签</span>
                                </a>
                            </li>
                            <?php if (isset($user) && $user['role'] === 'admin'): ?>
                                <li class="sidebar-item">
                                    <a href="/admin" class="sidebar-link">
                                        <i class="sidebar-icon fas fa-cog"></i>
                                        <span class="sidebar-text">管理后台</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </nav>
            </div>
        </aside>

        <!-- 主内容区 -->
        <main class="main-content" id="main-content">
            <!-- 面包屑导航 -->
            <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
                <nav aria-label="面包屑导航" class="mb-4">
                    <ol class="breadcrumb">
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <?php if (isset($crumb['url'])): ?>
                                <li class="breadcrumb-item">
                                    <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['title']) ?></a>
                                </li>
                            <?php else: ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($crumb['title']) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>

            <!-- 页面内容 -->
            <div class="page-content">
                <?= $content ?? '' ?>
            </div>
        </main>
    </div>

    <!-- 全局模态框容器 -->
    <div id="modal-container"></div>

    <!-- 通知容器 -->
    <div id="toast-container" class="toast-container"></div>

    <!-- 右键菜单 -->
    <div id="context-menu" class="context-menu"></div>

    <!-- 侧边栏遮罩层（移动端） -->
    <div class="sidebar-overlay"></div>

    <!-- 离线提示 -->
    <div class="offline-banner" id="offline-banner">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <i class="fas fa-wifi me-2"></i>
                    <span>网络连接已断开，部分功能可能无法使用</span>
                </div>
                <button class="btn btn-sm btn-ghost" onclick="location.reload()">
                    <i class="fas fa-refresh"></i> 重试
                </button>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="/assets/js/main.js"></script>

    <!-- 额外的脚本 -->
    <?php if (isset($scripts)): ?>
        <?php foreach ($scripts as $script): ?>
            <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- 内联脚本 -->
    <?php if (isset($inlineScripts)): ?>
        <script><?= $inlineScripts ?></script>
    <?php endif; ?>

    <!-- Service Worker 注册 -->
    <script>
        // 隐藏页面加载器
        window.addEventListener('load', () => {
            const loader = document.getElementById('page-loader');
            if (loader) {
                loader.style.opacity = '0';
                setTimeout(() => {
                    loader.style.display = 'none';
                }, 300);
            }
        });

        // 传递配置到前端
        window.config = {
            baseUrl: '<?= htmlspecialchars($baseUrl ?? '') ?>',
            apiUrl: '<?= htmlspecialchars($apiUrl ?? '/api') ?>',
            user: <?= isset($user) ? json_encode($user) : 'null' ?>,
            csrfToken: '<?= $csrfToken ?? '' ?>',
            locale: '<?= $locale ?? 'zh-CN' ?>'
        };
    </script>
</body>
</html>