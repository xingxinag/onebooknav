<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?php echo $theme ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo $description ?? 'OneBookNav - 个人导航网站'; ?>">
    <meta name="keywords" content="<?php echo $keywords ?? '导航,书签,网站收藏'; ?>">
    <title><?php echo $title ?? 'OneBookNav'; ?></title>

    <!-- CSS 样式 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/themes/modern.css" rel="stylesheet">
    <?php if (isset($extraCss)): ?>
        <?php foreach ($extraCss as $css): ?>
            <link href="<?php echo $css; ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">

    <!-- PWA 支持 -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2563eb">

    <!-- 预加载重要资源 -->
    <link rel="preload" href="/assets/fonts/Inter-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/js/app.js" as="script">
</head>
<body>
    <!-- 加载指示器 -->
    <div id="loader" class="loading" style="display: none;">
        <div class="spinner"></div>
    </div>

    <!-- 主要内容 -->
    <div id="app" class="app">
        <!-- 头部导航 -->
        <header class="header">
            <div class="container">
                <div class="header-content">
                    <!-- Logo -->
                    <a href="/" class="logo">
                        <i class="bi bi-compass"></i>
                        <span>OneBookNav</span>
                    </a>

                    <!-- 搜索框 -->
                    <div class="search-container">
                        <div class="search-icon">
                            <i class="bi bi-search"></i>
                        </div>
                        <input type="text"
                               id="searchBox"
                               class="search-box"
                               placeholder="搜索网站... (Ctrl+K)"
                               autocomplete="off">
                        <div id="searchSuggestions" class="search-suggestions" style="display: none;"></div>
                    </div>

                    <!-- 工具栏 -->
                    <div class="toolbar">
                        <?php if (isset($user) && $user): ?>
                            <!-- 已登录用户 -->
                            <button id="addWebsiteBtn" class="btn btn-primary" data-tooltip="添加网站">
                                <i class="bi bi-plus-lg"></i>
                                <span class="d-none d-md-inline">添加</span>
                            </button>

                            <button id="settingsBtn" class="btn btn-secondary btn-icon" data-tooltip="设置">
                                <i class="bi bi-gear"></i>
                            </button>

                            <div class="dropdown">
                                <button class="btn btn-secondary btn-icon" data-bs-toggle="dropdown" data-tooltip="用户菜单">
                                    <i class="bi bi-person"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><span class="dropdown-header"><?php echo htmlspecialchars($user['username']); ?></span></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="/admin"><i class="bi bi-speedometer2"></i> 管理后台</a></li>
                                    <li><a class="dropdown-item" href="/profile"><i class="bi bi-person-gear"></i> 个人设置</a></li>
                                    <li><a class="dropdown-item" href="/favorites"><i class="bi bi-heart"></i> 我的收藏</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="/logout"><i class="bi bi-box-arrow-right"></i> 退出登录</a></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <!-- 未登录用户 -->
                            <a href="/login" class="btn btn-primary">登录</a>
                        <?php endif; ?>

                        <!-- 主题切换 -->
                        <div id="themeToggle" class="theme-toggle" data-tooltip="切换主题"></div>
                    </div>
                </div>
            </div>
        </header>

        <!-- 主要内容区域 -->
        <main class="main">
            <div class="container">
                <?php echo $content ?? ''; ?>
            </div>
        </main>

        <!-- 页脚 -->
        <footer class="footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-info">
                        <span id="stats">加载中...</span>
                        <span>Powered by OneBookNav</span>
                    </div>
                    <div class="footer-links">
                        <a href="/about">关于</a>
                        <a href="/privacy">隐私</a>
                        <a href="/help">帮助</a>
                        <a href="https://github.com/onebooknav/onebooknav" target="_blank">GitHub</a>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <!-- 模态框容器 -->
    <div id="modalContainer"></div>

    <!-- 通知容器 -->
    <div id="notificationContainer" class="notification-container"></div>

    <!-- 上下文菜单容器 -->
    <div id="contextMenuContainer"></div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
    <?php if (isset($extraJs)): ?>
        <?php foreach ($extraJs as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- 内联脚本 -->
    <script>
        // 全局配置
        window.OneBookNavConfig = {
            apiBaseUrl: '<?php echo $config['api']['base_url'] ?? '/api'; ?>',
            theme: '<?php echo $theme ?? 'light'; ?>',
            user: <?php echo isset($user) ? json_encode($user) : 'null'; ?>,
            csrfToken: '<?php echo $csrfToken ?? ''; ?>',
            language: '<?php echo $language ?? 'zh-CN'; ?>',
            version: '<?php echo $version ?? '1.0.0'; ?>'
        };

        // 错误处理
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);
        });

        // 未处理的 Promise 拒绝
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled promise rejection:', e.reason);
        });

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化应用
            if (typeof OneBookNav !== 'undefined') {
                window.app = new OneBookNav();
            }

            // 移除加载指示器
            const loader = document.getElementById('loader');
            if (loader) {
                loader.style.display = 'none';
            }

            // 添加页面加载动画
            document.body.classList.add('loaded');
        });

        // Service Worker 注册 (PWA 支持)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('SW registered: ', registration);
                    })
                    .catch(function(registrationError) {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }
    </script>

    <!-- 页面特定脚本 -->
    <?php if (isset($pageScript)): ?>
        <script><?php echo $pageScript; ?></script>
    <?php endif; ?>
</body>
</html>

<style>
/* 页面加载动画 */
.app {
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.5s ease;
}

body.loaded .app {
    opacity: 1;
    transform: translateY(0);
}

/* 通知容器 */
.notification-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1060;
    max-width: 400px;
}

.notification {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-lg);
    padding: var(--spacing-md);
    margin-bottom: var(--spacing-sm);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    transform: translateX(100%);
    transition: transform var(--transition-normal);
}

.notification.show {
    transform: translateX(0);
}

.notification.fade-out {
    opacity: 0;
    transform: translateX(100%);
}

.notification-success {
    border-left: 4px solid var(--success-color);
}

.notification-error {
    border-left: 4px solid var(--danger-color);
}

.notification-warning {
    border-left: 4px solid var(--warning-color);
}

.notification-info {
    border-left: 4px solid var(--info-color);
}

.notification-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    margin-left: auto;
}

/* 上下文菜单 */
.context-menu {
    position: fixed;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-lg);
    padding: var(--spacing-xs) 0;
    min-width: 180px;
    z-index: 1055;
    opacity: 0;
    transform: scale(0.95);
    transition: all var(--transition-fast);
}

.context-menu.show {
    opacity: 1;
    transform: scale(1);
}

.context-menu-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    cursor: pointer;
    transition: background-color var(--transition-fast);
}

.context-menu-item:hover {
    background: var(--bg-secondary);
}

.context-menu-item i {
    width: 16px;
    text-align: center;
}

.context-menu-divider {
    height: 1px;
    background: var(--border-color);
    margin: var(--spacing-xs) 0;
}

/* 搜索建议 */
.search-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    box-shadow: var(--shadow-lg);
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
}

.search-suggestion {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    cursor: pointer;
    transition: background-color var(--transition-fast);
}

.search-suggestion:hover {
    background: var(--bg-secondary);
}

.search-suggestion img {
    width: 20px;
    height: 20px;
    border-radius: 4px;
}

/* 页脚 */
.footer {
    margin-top: var(--spacing-2xl);
    padding: var(--spacing-xl) 0;
    border-top: 1px solid var(--border-color);
    background: var(--bg-secondary);
}

.footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.footer-info {
    display: flex;
    gap: var(--spacing-lg);
}

.footer-links {
    display: flex;
    gap: var(--spacing-md);
}

.footer-links a {
    color: var(--text-secondary);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.footer-links a:hover {
    color: var(--primary-color);
}

/* 响应式适配 */
@media (max-width: 768px) {
    .footer-content {
        flex-direction: column;
        gap: var(--spacing-md);
        text-align: center;
    }

    .footer-info {
        flex-direction: column;
        gap: var(--spacing-sm);
    }

    .notification-container {
        left: 20px;
        right: 20px;
        max-width: none;
    }
}
</style>