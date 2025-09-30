<?php
/**
 * 首页模板
 */

// 设置页面标题和描述
$title = ($siteName ?? 'OneBookNav') . ' - 个人导航网站';
$description = '简洁优雅的个人导航网站，帮您整理和管理常用网站链接';
$keywords = '导航,书签,网站收藏,个人导航,OneBookNav';

// 引入布局模板
ob_start();
?>

<!-- 主要内容区域 -->
<div class="home-container">
    <!-- 欢迎横幅 -->
    <?php if (!isset($user) || !$user): ?>
    <div class="welcome-banner fade-in">
        <div class="welcome-content">
            <h1 class="welcome-title">
                <i class="bi bi-compass me-3"></i>
                欢迎使用 OneBookNav
            </h1>
            <p class="welcome-description">
                简洁优雅的个人导航网站，帮您整理和管理常用网站链接
            </p>
            <div class="welcome-actions">
                <a href="/register" class="btn btn-primary btn-lg">
                    <i class="bi bi-person-plus"></i>
                    开始使用
                </a>
                <a href="/demo" class="btn btn-secondary btn-lg">
                    <i class="bi bi-eye"></i>
                    查看演示
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 分类和网站展示区域 -->
    <div class="content-section">
        <!-- 快速统计 -->
        <?php if (isset($stats) && $stats): ?>
        <div class="stats-cards fade-in">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-folder"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['categories']; ?></div>
                    <div class="stat-label">分类</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-globe"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['websites']; ?></div>
                    <div class="stat-label">网站</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-eye"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['views']); ?></div>
                    <div class="stat-label">访问量</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-heart"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['favorites']; ?></div>
                    <div class="stat-label">收藏</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 搜索提示 -->
        <div id="searchHint" class="search-hint" style="display: none;">
            <div class="search-hint-content">
                <i class="bi bi-search"></i>
                <span>搜索结果将在这里显示</span>
            </div>
        </div>

        <!-- 热门网站 -->
        <?php if (isset($popularWebsites) && !empty($popularWebsites)): ?>
        <div class="popular-section fade-in">
            <h2 class="section-title">
                <i class="bi bi-fire"></i>
                热门网站
            </h2>
            <div class="popular-websites">
                <?php foreach ($popularWebsites as $website): ?>
                <div class="popular-website" onclick="openWebsite('<?php echo htmlspecialchars($website['url']); ?>', <?php echo $website['id']; ?>)">
                    <div class="popular-icon">
                        <?php if ($website['icon']): ?>
                            <img src="<?php echo htmlspecialchars($website['icon']); ?>"
                                 alt="<?php echo htmlspecialchars($website['title']); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <i class="bi bi-globe" style="display: none;"></i>
                        <?php else: ?>
                            <i class="bi bi-globe"></i>
                        <?php endif; ?>
                    </div>
                    <div class="popular-info">
                        <div class="popular-title"><?php echo htmlspecialchars($website['title']); ?></div>
                        <div class="popular-stats">
                            <span><i class="bi bi-eye"></i> <?php echo number_format($website['views']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 分类网格 -->
        <div id="categoriesContainer" class="categories-grid">
            <!-- 分类内容将通过 JavaScript 动态加载 -->
            <div class="loading-placeholder">
                <div class="spinner"></div>
                <p>正在加载分类...</p>
            </div>
        </div>

        <!-- 无内容提示 -->
        <div id="emptyState" class="empty-state-large" style="display: none;">
            <div class="empty-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <h3>还没有添加任何网站</h3>
            <p>点击右上角的"添加"按钮开始收集您喜爱的网站吧</p>
            <?php if (isset($user) && $user): ?>
            <button class="btn btn-primary btn-lg" onclick="app.showAddWebsiteModal()">
                <i class="bi bi-plus-lg"></i>
                添加第一个网站
            </button>
            <?php else: ?>
            <a href="/login" class="btn btn-primary btn-lg">
                <i class="bi bi-box-arrow-in-right"></i>
                登录后开始使用
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 最近添加 -->
    <?php if (isset($recentWebsites) && !empty($recentWebsites)): ?>
    <div class="recent-section fade-in">
        <h2 class="section-title">
            <i class="bi bi-clock"></i>
            最近添加
        </h2>
        <div class="recent-websites">
            <?php foreach ($recentWebsites as $website): ?>
            <div class="recent-website" onclick="openWebsite('<?php echo htmlspecialchars($website['url']); ?>', <?php echo $website['id']; ?>)">
                <div class="recent-icon">
                    <?php if ($website['icon']): ?>
                        <img src="<?php echo htmlspecialchars($website['icon']); ?>"
                             alt="<?php echo htmlspecialchars($website['title']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <i class="bi bi-globe" style="display: none;"></i>
                    <?php else: ?>
                        <i class="bi bi-globe"></i>
                    <?php endif; ?>
                </div>
                <div class="recent-info">
                    <div class="recent-title"><?php echo htmlspecialchars($website['title']); ?></div>
                    <div class="recent-description"><?php echo htmlspecialchars($website['description']); ?></div>
                    <div class="recent-meta">
                        <span class="recent-category"><?php echo htmlspecialchars($website['category_name']); ?></span>
                        <span class="recent-date"><?php echo date('m-d', strtotime($website['created_at'])); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* 首页特定样式 */
.home-container {
    padding: var(--spacing-xl) 0;
}

/* 欢迎横幅 */
.welcome-banner {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
    color: var(--text-inverse);
    border-radius: var(--border-radius-lg);
    padding: var(--spacing-2xl);
    text-align: center;
    margin-bottom: var(--spacing-2xl);
    box-shadow: var(--shadow-lg);
}

.welcome-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: var(--spacing-md);
    display: flex;
    align-items: center;
    justify-content: center;
}

.welcome-description {
    font-size: 1.2rem;
    margin-bottom: var(--spacing-xl);
    opacity: 0.9;
}

.welcome-actions {
    display: flex;
    gap: var(--spacing-md);
    justify-content: center;
    flex-wrap: wrap;
}

/* 统计卡片 */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-2xl);
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: var(--spacing-lg);
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-normal);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
    color: var(--text-inverse);
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

/* 搜索提示 */
.search-hint {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: var(--spacing-2xl);
    text-align: center;
    margin-bottom: var(--spacing-xl);
}

.search-hint-content {
    color: var(--text-secondary);
    font-size: 1.1rem;
}

.search-hint-content i {
    font-size: 2rem;
    margin-bottom: var(--spacing-md);
    display: block;
}

/* 热门网站 */
.popular-section,
.recent-section {
    margin-bottom: var(--spacing-2xl);
}

.section-title {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: var(--spacing-lg);
    color: var(--text-primary);
}

.popular-websites {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: var(--spacing-md);
}

.popular-website {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: var(--spacing-md);
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    cursor: pointer;
    transition: all var(--transition-normal);
}

.popular-website:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary-color);
}

.popular-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--border-radius-sm);
    overflow: hidden;
    flex-shrink: 0;
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.popular-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.popular-icon i {
    color: var(--text-secondary);
    font-size: 1.2rem;
}

.popular-title {
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: var(--spacing-xs);
}

.popular-stats {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.popular-stats i {
    margin-right: var(--spacing-xs);
}

/* 最近添加 */
.recent-websites {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--spacing-lg);
}

.recent-website {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: var(--spacing-lg);
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
    cursor: pointer;
    transition: all var(--transition-normal);
}

.recent-website:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary-color);
}

.recent-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--border-radius);
    overflow: hidden;
    flex-shrink: 0;
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.recent-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.recent-icon i {
    color: var(--text-secondary);
    font-size: 1.5rem;
}

.recent-info {
    flex: 1;
    min-width: 0;
}

.recent-title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: var(--spacing-xs);
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.recent-description {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-bottom: var(--spacing-sm);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.recent-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    color: var(--text-tertiary);
}

.recent-category {
    background: var(--bg-tertiary);
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--border-radius-sm);
}

/* 大型空状态 */
.empty-state-large {
    text-align: center;
    padding: var(--spacing-2xl);
    color: var(--text-secondary);
}

.empty-icon {
    font-size: 5rem;
    margin-bottom: var(--spacing-lg);
    opacity: 0.3;
}

.empty-state-large h3 {
    font-size: 1.5rem;
    margin-bottom: var(--spacing-md);
    color: var(--text-primary);
}

.empty-state-large p {
    font-size: 1.1rem;
    margin-bottom: var(--spacing-xl);
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

/* 加载占位符 */
.loading-placeholder {
    text-align: center;
    padding: var(--spacing-2xl);
    color: var(--text-secondary);
}

.loading-placeholder .spinner {
    margin: 0 auto var(--spacing-md);
}

/* 响应式设计 */
@media (max-width: 768px) {
    .welcome-title {
        font-size: 2rem;
        flex-direction: column;
        gap: var(--spacing-sm);
    }

    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--spacing-md);
    }

    .popular-websites,
    .recent-websites {
        grid-template-columns: 1fr;
    }

    .welcome-actions {
        flex-direction: column;
        align-items: center;
    }

    .recent-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-xs);
    }
}

@media (max-width: 480px) {
    .stats-cards {
        grid-template-columns: 1fr;
    }

    .welcome-banner {
        padding: var(--spacing-xl);
    }

    .section-title {
        font-size: 1.25rem;
    }
}
</style>

<script>
// 首页特定 JavaScript
function openWebsite(url, websiteId) {
    // 记录点击统计
    if (typeof app !== 'undefined' && app.trackClick) {
        fetch(`/api/websites/${websiteId}/click`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).catch(console.error);
    }

    // 打开网站
    window.open(url, '_blank');
}

// 首页初始化
document.addEventListener('DOMContentLoaded', function() {
    // 检查是否有搜索参数
    const urlParams = new URLSearchParams(window.location.search);
    const searchQuery = urlParams.get('q');

    if (searchQuery) {
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            searchBox.value = searchQuery;
            searchBox.dispatchEvent(new Event('input'));
        }
    }

    // 添加页面可见性变化监听
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && typeof app !== 'undefined') {
            // 页面重新可见时刷新数据
            app.refreshData && app.refreshData();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../base/layout.php';
?>