<?php
/**
 * 书签组件模板
 *
 * 用于渲染单个书签项目，支持网格和列表视图
 */

// 默认数据
$bookmark = $bookmark ?? [];
$view = $view ?? 'grid';
$showActions = $showActions ?? true;
$showMeta = $showMeta ?? true;
$target = $target ?? '_blank';

// 数据验证
if (empty($bookmark) || !isset($bookmark['id'])) {
    return '';
}

// 数据处理
$id = (int)$bookmark['id'];
$title = htmlspecialchars($bookmark['title'] ?? '未命名书签');
$url = htmlspecialchars($bookmark['url'] ?? '#');
$description = htmlspecialchars($bookmark['description'] ?? '');
$favicon = htmlspecialchars($bookmark['favicon_url'] ?: '/assets/images/default-favicon.png');
$categoryName = htmlspecialchars($bookmark['category_name'] ?? '未分类');
$categoryIcon = htmlspecialchars($bookmark['category_icon'] ?? 'fas fa-folder');
$categoryColor = htmlspecialchars($bookmark['category_color'] ?? '#667eea');
$clicks = (int)($bookmark['clicks'] ?? 0);
$isPrivate = !empty($bookmark['is_private']);
$isFeatured = !empty($bookmark['is_featured']);
$createdAt = $bookmark['created_at'] ?? '';
$tags = $bookmark['tags'] ?? [];

// 权限检查
$canEdit = $bookmark['can_edit'] ?? false;
$canDelete = $bookmark['can_delete'] ?? false;

// CSS 类
$cardClass = 'bookmark-card';
if ($view === 'list') {
    $cardClass .= ' bookmark-card-list';
}
if ($isPrivate) {
    $cardClass .= ' bookmark-private';
}
if ($isFeatured) {
    $cardClass .= ' bookmark-featured';
}
?>

<div class="<?= $cardClass ?>"
     data-bookmark-id="<?= $id ?>"
     data-url="<?= $url ?>"
     data-title="<?= $title ?>"
     draggable="<?= $canEdit ? 'true' : 'false' ?>">

    <!-- 状态标识 -->
    <div class="bookmark-status">
        <?php if ($isFeatured): ?>
            <span class="bookmark-badge bookmark-badge-featured" title="推荐书签">
                <i class="fas fa-star"></i>
            </span>
        <?php endif; ?>

        <?php if ($isPrivate): ?>
            <span class="bookmark-badge bookmark-badge-private" title="私有书签">
                <i class="fas fa-lock"></i>
            </span>
        <?php endif; ?>
    </div>

    <!-- 书签图标 -->
    <div class="bookmark-favicon-container">
        <img src="<?= $favicon ?>"
             alt=""
             class="bookmark-favicon"
             loading="lazy"
             onerror="this.src='/assets/images/default-favicon.png'">
    </div>

    <!-- 书签内容 -->
    <div class="bookmark-content">
        <h4 class="bookmark-title">
            <a href="<?= $url ?>"
               target="<?= $target ?>"
               rel="noopener noreferrer"
               onclick="app.recordClick(<?= $id ?>, '<?= $url ?>')"
               title="<?= $title ?>">
                <?= $title ?>
            </a>
        </h4>

        <?php if ($description && $view === 'grid'): ?>
            <p class="bookmark-description" title="<?= $description ?>">
                <?= mb_substr($description, 0, 100) ?><?= mb_strlen($description) > 100 ? '...' : '' ?>
            </p>
        <?php elseif ($description && $view === 'list'): ?>
            <p class="bookmark-description" title="<?= $description ?>">
                <?= $description ?>
            </p>
        <?php endif; ?>

        <!-- 标签 -->
        <?php if (!empty($tags) && $view === 'grid'): ?>
            <div class="bookmark-tags">
                <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                    <span class="tag tag-sm" style="background-color: <?= htmlspecialchars($tag['color'] ?? '#e2e8f0') ?>">
                        <?= htmlspecialchars($tag['name']) ?>
                    </span>
                <?php endforeach; ?>
                <?php if (count($tags) > 3): ?>
                    <span class="tag tag-sm tag-more" title="还有 <?= count($tags) - 3 ?> 个标签">
                        +<?= count($tags) - 3 ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- 元信息 -->
        <?php if ($showMeta): ?>
            <div class="bookmark-meta">
                <span class="bookmark-category" style="color: <?= $categoryColor ?>">
                    <i class="<?= $categoryIcon ?>"></i>
                    <?= $categoryName ?>
                </span>

                <?php if ($clicks > 0): ?>
                    <span class="bookmark-clicks">
                        <i class="fas fa-mouse-pointer"></i>
                        <?= number_format($clicks) ?> 次点击
                    </span>
                <?php endif; ?>

                <?php if ($createdAt): ?>
                    <span class="bookmark-date">
                        <i class="fas fa-calendar"></i>
                        <?= date('Y-m-d', strtotime($createdAt)) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 操作按钮 -->
    <?php if ($showActions): ?>
        <div class="bookmark-actions">
            <!-- 收藏按钮 -->
            <button class="btn btn-sm btn-ghost bookmark-action"
                    onclick="app.toggleFavorite(<?= $id ?>)"
                    title="收藏/取消收藏">
                <i class="fas fa-heart <?= !empty($bookmark['is_favorited']) ? 'text-error' : '' ?>"></i>
            </button>

            <!-- 复制链接 -->
            <button class="btn btn-sm btn-ghost bookmark-action"
                    onclick="app.copyToClipboard('<?= $url ?>')"
                    title="复制链接">
                <i class="fas fa-copy"></i>
            </button>

            <!-- 新窗口打开 -->
            <button class="btn btn-sm btn-ghost bookmark-action"
                    onclick="app.openInNewTab('<?= $url ?>')"
                    title="新窗口打开">
                <i class="fas fa-external-link-alt"></i>
            </button>

            <!-- 编辑按钮 -->
            <?php if ($canEdit): ?>
                <button class="btn btn-sm btn-ghost bookmark-action"
                        onclick="app.editBookmark(<?= $id ?>)"
                        title="编辑书签">
                    <i class="fas fa-edit"></i>
                </button>
            <?php endif; ?>

            <!-- 更多操作 -->
            <div class="dropdown">
                <button class="btn btn-sm btn-ghost bookmark-action dropdown-toggle"
                        data-bs-toggle="dropdown"
                        title="更多操作">
                    <i class="fas fa-ellipsis-h"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="<?= $url ?>" target="_blank">
                            <i class="fas fa-external-link-alt me-2"></i>打开链接
                        </a>
                    </li>
                    <li>
                        <button class="dropdown-item" onclick="app.copyToClipboard('<?= $url ?>')">
                            <i class="fas fa-copy me-2"></i>复制链接
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" onclick="app.shareBookmark(<?= $id ?>)">
                            <i class="fas fa-share me-2"></i>分享书签
                        </button>
                    </li>
                    <?php if ($canEdit): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button class="dropdown-item" onclick="app.editBookmark(<?= $id ?>)">
                                <i class="fas fa-edit me-2"></i>编辑
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item" onclick="app.checkBookmarkLink(<?= $id ?>)">
                                <i class="fas fa-link me-2"></i>检查链接
                            </button>
                        </li>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button class="dropdown-item text-error" onclick="app.deleteBookmark(<?= $id ?>)">
                                <i class="fas fa-trash me-2"></i>删除
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- 拖拽手柄 -->
    <?php if ($canEdit): ?>
        <div class="bookmark-drag-handle" title="拖拽排序">
            <i class="fas fa-grip-vertical"></i>
        </div>
    <?php endif; ?>
</div>

<!-- 书签组件样式 -->
<style>
.bookmark-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem;
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: inherit;
    transition: var(--transition);
    cursor: pointer;
}

.bookmark-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
    border-color: var(--primary-color);
}

.bookmark-card.dragging {
    opacity: 0.5;
    transform: rotate(5deg);
}

.bookmark-card-list {
    flex-direction: row;
    align-items: center;
}

.bookmark-card.bookmark-featured {
    border-color: var(--warning-color);
    background: linear-gradient(135deg, var(--card-background) 0%, rgba(237, 137, 54, 0.05) 100%);
}

.bookmark-card.bookmark-private {
    border-color: var(--info-color);
    background: linear-gradient(135deg, var(--card-background) 0%, rgba(66, 153, 225, 0.05) 100%);
}

.bookmark-status {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    display: flex;
    gap: 0.25rem;
    z-index: 2;
}

.bookmark-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    font-size: 0.75rem;
    color: white;
}

.bookmark-badge-featured {
    background-color: var(--warning-color);
}

.bookmark-badge-private {
    background-color: var(--info-color);
}

.bookmark-favicon-container {
    flex-shrink: 0;
}

.bookmark-favicon {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--background-color);
}

.bookmark-content {
    flex: 1;
    min-width: 0;
}

.bookmark-title {
    margin: 0 0 0.5rem 0;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.bookmark-title a {
    color: var(--text-primary);
    text-decoration: none;
    transition: var(--transition);
}

.bookmark-title a:hover {
    color: var(--primary-color);
}

.bookmark-description {
    margin: 0 0 0.75rem 0;
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.5;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.bookmark-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    margin-bottom: 0.75rem;
}

.tag-sm {
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
}

.tag-more {
    background-color: var(--text-muted) !important;
    color: white;
}

.bookmark-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.75rem;
    color: var(--text-muted);
}

.bookmark-meta > span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.bookmark-actions {
    display: flex;
    gap: 0.25rem;
    opacity: 0;
    transition: var(--transition);
    flex-shrink: 0;
}

.bookmark-card:hover .bookmark-actions {
    opacity: 1;
}

.bookmark-action {
    width: 32px;
    height: 32px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bookmark-drag-handle {
    position: absolute;
    left: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    cursor: grab;
    opacity: 0;
    transition: var(--transition);
}

.bookmark-card:hover .bookmark-drag-handle {
    opacity: 1;
}

.bookmark-drag-handle:active {
    cursor: grabbing;
}

/* 列表视图样式 */
.bookmark-card-list .bookmark-favicon {
    width: 24px;
    height: 24px;
}

.bookmark-card-list .bookmark-title {
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.bookmark-card-list .bookmark-description {
    font-size: 0.75rem;
    -webkit-line-clamp: 1;
}

.bookmark-card-list .bookmark-tags {
    margin-bottom: 0.5rem;
}

.bookmark-card-list .bookmark-meta {
    gap: 0.75rem;
}

/* 网格视图响应式 */
@media (max-width: 768px) {
    .bookmark-card {
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
        padding: 1rem;
    }

    .bookmark-actions {
        opacity: 1;
        align-self: flex-end;
        margin-top: 0.5rem;
    }

    .bookmark-favicon {
        width: 28px;
        height: 28px;
    }

    .bookmark-meta {
        gap: 0.5rem;
        flex-direction: column;
        align-items: flex-start;
    }

    .bookmark-meta > span {
        font-size: 0.75rem;
    }
}

/* 拖拽状态 */
.drag-placeholder {
    background: var(--primary-color);
    opacity: 0.2;
    border: 2px dashed var(--primary-color);
    border-radius: var(--radius-lg);
    margin: 0.5rem 0;
}

/* 加载状态 */
.bookmark-card.loading {
    pointer-events: none;
    opacity: 0.6;
}

.bookmark-card.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 20px;
    height: 20px;
    border: 2px solid var(--border-color);
    border-top-color: var(--primary-color);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

/* 选中状态 */
.bookmark-card.selected {
    border-color: var(--primary-color);
    background: rgba(102, 126, 234, 0.05);
}

/* 检查失败状态 */
.bookmark-card.link-broken {
    border-color: var(--error-color);
    background: rgba(245, 101, 101, 0.05);
}

.bookmark-card.link-broken .bookmark-title a {
    text-decoration: line-through;
    color: var(--error-color);
}
</style>