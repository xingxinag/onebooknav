<?php
// 设置页面变量
$title = '首页 - OneBookNav';
$currentPage = 'home';

// 获取统计数据
$stats = $stats ?? [
    'total_bookmarks' => 0,
    'total_clicks' => 0,
    'categories_count' => 0,
    'recent_clicks' => 0
];
?>

<!-- 页面头部 -->
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <i class="fas fa-home me-2"></i>
                书签导航
            </h1>
            <p class="page-description">
                欢迎来到 OneBookNav，您的个人书签管理中心
            </p>
        </div>

        <!-- 视图切换 -->
        <div class="view-controls d-none d-md-flex">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline active" data-view="grid" title="网格视图">
                    <i class="fas fa-th"></i>
                </button>
                <button type="button" class="btn btn-outline" data-view="list" title="列表视图">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 统计卡片 -->
<div class="stats-section mb-4">
    <div class="row">
        <div class="col-6 col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary">
                    <i class="fas fa-bookmark"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?= number_format($stats['total_bookmarks']) ?></h3>
                    <p class="stat-label">总书签数</p>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-success">
                    <i class="fas fa-mouse-pointer"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?= number_format($stats['total_clicks']) ?></h3>
                    <p class="stat-label">总点击数</p>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-info">
                    <i class="fas fa-folder"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?= number_format($stats['categories_count']) ?></h3>
                    <p class="stat-label">分类数量</p>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?= number_format($stats['recent_clicks']) ?></h3>
                    <p class="stat-label">本周点击</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 快速操作 -->
<div class="quick-actions mb-4">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-zap me-2"></i>
                快速操作
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-6 col-md-3 mb-3">
                    <button class="btn btn-primary w-100" data-modal-target="add-bookmark-modal">
                        <i class="fas fa-plus mb-2"></i>
                        <div>添加书签</div>
                    </button>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <button class="btn btn-outline w-100" data-modal-target="import-modal">
                        <i class="fas fa-file-import mb-2"></i>
                        <div>导入书签</div>
                    </button>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <button class="btn btn-outline w-100" onclick="app.checkDeadLinks()">
                        <i class="fas fa-link mb-2"></i>
                        <div>检查死链</div>
                    </button>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <button class="btn btn-outline w-100" onclick="app.exportBookmarks()">
                        <i class="fas fa-file-export mb-2"></i>
                        <div>导出备份</div>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 推荐书签 -->
<?php if (!empty($recommendedBookmarks)): ?>
<div class="recommended-section mb-4">
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-star me-2"></i>
                    为您推荐
                </h5>
                <a href="/recommended" class="btn btn-sm btn-ghost">查看更多</a>
            </div>
        </div>
        <div class="card-body">
            <div class="bookmarks-grid" data-view="grid">
                <?php foreach (array_slice($recommendedBookmarks, 0, 8) as $bookmark): ?>
                    <?= $this->renderBookmark($bookmark) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 热门书签 -->
<?php if (!empty($popularBookmarks)): ?>
<div class="popular-section mb-4">
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-fire me-2"></i>
                    热门书签
                </h5>
                <a href="/popular" class="btn btn-sm btn-ghost">查看更多</a>
            </div>
        </div>
        <div class="card-body">
            <div class="bookmarks-grid" data-view="grid">
                <?php foreach (array_slice($popularBookmarks, 0, 8) as $bookmark): ?>
                    <?= $this->renderBookmark($bookmark) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 最近添加 -->
<?php if (!empty($recentBookmarks)): ?>
<div class="recent-section mb-4">
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clock me-2"></i>
                    最近添加
                </h5>
                <a href="/recent" class="btn btn-sm btn-ghost">查看更多</a>
            </div>
        </div>
        <div class="card-body">
            <div class="bookmarks-list">
                <?php foreach (array_slice($recentBookmarks, 0, 5) as $bookmark): ?>
                    <div class="bookmark-list-item">
                        <img src="<?= htmlspecialchars($bookmark['favicon_url'] ?: '/assets/images/default-favicon.png') ?>"
                             alt=""
                             class="bookmark-favicon"
                             loading="lazy">
                        <div class="bookmark-content">
                            <h6 class="bookmark-title">
                                <a href="<?= htmlspecialchars($bookmark['url']) ?>"
                                   target="_blank"
                                   onclick="app.recordClick(<?= $bookmark['id'] ?>)">
                                    <?= htmlspecialchars($bookmark['title']) ?>
                                </a>
                            </h6>
                            <p class="bookmark-description">
                                <?= htmlspecialchars($bookmark['description'] ?: '') ?>
                            </p>
                            <div class="bookmark-meta">
                                <span class="bookmark-category">
                                    <i class="<?= htmlspecialchars($bookmark['category_icon'] ?: 'fas fa-folder') ?>"></i>
                                    <?= htmlspecialchars($bookmark['category_name']) ?>
                                </span>
                                <span class="bookmark-date">
                                    <?= date('Y-m-d', strtotime($bookmark['created_at'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="bookmark-actions">
                            <button class="btn btn-sm btn-ghost"
                                    onclick="app.toggleFavorite(<?= $bookmark['id'] ?>)"
                                    title="收藏">
                                <i class="fas fa-heart"></i>
                            </button>
                            <button class="btn btn-sm btn-ghost"
                                    onclick="app.editBookmark(<?= $bookmark['id'] ?>)"
                                    title="编辑">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 空状态 -->
<?php if (empty($recommendedBookmarks) && empty($popularBookmarks) && empty($recentBookmarks)): ?>
<div class="empty-state">
    <div class="card text-center">
        <div class="card-body py-5">
            <i class="fas fa-bookmark empty-icon"></i>
            <h3 class="empty-title">还没有书签</h3>
            <p class="empty-description">
                开始添加您的第一个书签，建立您的个人导航中心
            </p>
            <div class="empty-actions mt-4">
                <button class="btn btn-primary" data-modal-target="add-bookmark-modal">
                    <i class="fas fa-plus me-2"></i>
                    添加第一个书签
                </button>
                <button class="btn btn-outline ms-2" data-modal-target="import-modal">
                    <i class="fas fa-file-import me-2"></i>
                    导入现有书签
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 添加书签模态框 -->
<div id="add-bookmark-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">添加书签</h5>
            <button class="btn btn-ghost btn-sm" data-modal-close>
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form data-ajax action="/api/bookmarks" method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="bookmark-url">网址 *</label>
                    <input type="url"
                           id="bookmark-url"
                           name="url"
                           class="form-control"
                           placeholder="https://example.com"
                           required
                           data-validate>
                </div>

                <div class="form-group">
                    <label class="form-label" for="bookmark-title">标题 *</label>
                    <input type="text"
                           id="bookmark-title"
                           name="title"
                           class="form-control"
                           placeholder="书签标题"
                           required
                           data-validate>
                </div>

                <div class="form-group">
                    <label class="form-label" for="bookmark-description">描述</label>
                    <textarea id="bookmark-description"
                              name="description"
                              class="form-control"
                              rows="3"
                              placeholder="书签描述（可选）"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="bookmark-category">分类 *</label>
                    <select id="bookmark-category" name="category_id" class="form-control" required>
                        <option value="">请选择分类</option>
                        <?php foreach ($categories ?? [] as $category): ?>
                            <option value="<?= $category['id'] ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="bookmark-tags">标签</label>
                    <input type="text"
                           id="bookmark-tags"
                           name="tags"
                           class="form-control"
                           placeholder="输入标签，用逗号分隔">
                </div>

                <div class="form-group">
                    <div class="d-flex gap-3">
                        <label class="form-check">
                            <input type="checkbox" name="is_featured" value="1" class="form-check-input">
                            <span class="form-check-label">推荐书签</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="is_private" value="1" class="form-check-input">
                            <span class="form-check-label">私有书签</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>取消</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>
                    保存书签
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 导入书签模态框 -->
<div id="import-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">导入书签</h5>
            <button class="btn btn-ghost btn-sm" data-modal-close>
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form data-ajax action="/api/import" method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">导入来源</label>
                    <div class="import-options">
                        <label class="form-check">
                            <input type="radio" name="import_type" value="file" class="form-check-input" checked>
                            <span class="form-check-label">上传文件</span>
                        </label>
                        <label class="form-check">
                            <input type="radio" name="import_type" value="url" class="form-check-input">
                            <span class="form-check-label">网络地址</span>
                        </label>
                    </div>
                </div>

                <div class="form-group" id="file-import">
                    <label class="form-label" for="import-file">选择文件</label>
                    <input type="file"
                           id="import-file"
                           name="file"
                           class="form-control"
                           accept=".html,.json,.csv,.xml">
                    <small class="form-text">
                        支持浏览器导出的 HTML 文件、JSON 文件等格式
                    </small>
                </div>

                <div class="form-group hidden" id="url-import">
                    <label class="form-label" for="import-url">网络地址</label>
                    <input type="url"
                           id="import-url"
                           name="url"
                           class="form-control"
                           placeholder="https://example.com/bookmarks.html">
                </div>

                <div class="form-group">
                    <label class="form-label" for="import-category">导入到分类</label>
                    <select id="import-category" name="category_id" class="form-control">
                        <option value="">请选择分类</option>
                        <?php foreach ($categories ?? [] as $category): ?>
                            <option value="<?= $category['id'] ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="overwrite" value="1" class="form-check-input">
                        <span class="form-check-label">覆盖重复书签</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>取消</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>
                    开始导入
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 内联样式 -->
<style>
.stats-section .stat-card {
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: var(--transition);
}

.stats-section .stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
}

.stat-content .stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-primary);
}

.stat-content .stat-label {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0;
}

.quick-actions .btn {
    height: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.quick-actions .btn i {
    font-size: 1.5rem;
}

.bookmark-list-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 0;
    border-bottom: 1px solid var(--border-color);
}

.bookmark-list-item:last-child {
    border-bottom: none;
}

.empty-state .empty-icon {
    font-size: 4rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
}

.empty-state .empty-title {
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.empty-state .empty-description {
    color: var(--text-secondary);
    margin-bottom: 0;
}

.import-options {
    display: flex;
    gap: 2rem;
}

.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 998;
    opacity: 0;
    visibility: hidden;
    transition: var(--transition);
}

.sidebar-overlay.active {
    opacity: 1;
    visibility: visible;
}

@media (max-width: 768px) {
    .stats-section .stat-card {
        padding: 1rem;
    }

    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }

    .stat-content .stat-number {
        font-size: 1.25rem;
    }
}
</style>

<!-- 内联脚本 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 视图切换
    document.querySelectorAll('[data-view]').forEach(btn => {
        btn.addEventListener('click', function() {
            const view = this.getAttribute('data-view');
            const container = document.querySelector('.bookmarks-grid');

            if (container) {
                container.setAttribute('data-view', view);
            }

            // 更新按钮状态
            document.querySelectorAll('[data-view]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // 保存偏好
            app.setPreference('bookmarks_view', view);
        });
    });

    // 导入类型切换
    document.querySelectorAll('input[name="import_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const fileImport = document.getElementById('file-import');
            const urlImport = document.getElementById('url-import');

            if (this.value === 'file') {
                fileImport.classList.remove('hidden');
                urlImport.classList.add('hidden');
            } else {
                fileImport.classList.add('hidden');
                urlImport.classList.remove('hidden');
            }
        });
    });

    // 自动填充书签信息
    document.getElementById('bookmark-url').addEventListener('blur', function() {
        const url = this.value.trim();
        const titleField = document.getElementById('bookmark-title');

        if (url && !titleField.value) {
            app.fetchUrlInfo(url).then(info => {
                if (info.title) {
                    titleField.value = info.title;
                }
                if (info.description) {
                    document.getElementById('bookmark-description').value = info.description;
                }
            }).catch(err => {
                console.log('Failed to fetch URL info:', err);
            });
        }
    });
});
</script>