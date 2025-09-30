/**
 * OneBookNav - 现代化交互脚本
 *
 * 实现"终极.txt"要求的现代化用户交互
 * 融合 BookNav 和 OneNav 的最佳交互功能
 */

class OneBookNav {
    constructor() {
        this.config = {
            apiBase: '/api',
            debounceDelay: 300,
            animationDuration: 200,
            toastDuration: 5000
        };

        this.init();
    }

    /**
     * 初始化应用
     */
    init() {
        this.setupEventListeners();
        this.initializeComponents();
        this.loadUserPreferences();
        this.setupServiceWorker();

        console.log('OneBookNav initialized successfully');
    }

    /**
     * 设置事件监听器
     */
    setupEventListeners() {
        // DOM 加载完成
        document.addEventListener('DOMContentLoaded', () => {
            this.handleDOMReady();
        });

        // 搜索功能
        this.setupSearch();

        // 侧边栏控制
        this.setupSidebar();

        // 模态框控制
        this.setupModals();

        // 拖拽排序
        this.setupDragSort();

        // 右键菜单
        this.setupContextMenu();

        // 键盘快捷键
        this.setupKeyboardShortcuts();

        // 主题切换
        this.setupThemeToggle();

        // 表单处理
        this.setupForms();

        // 懒加载
        this.setupLazyLoading();
    }

    /**
     * DOM 准备就绪处理
     */
    handleDOMReady() {
        // 初始化工具提示
        this.initTooltips();

        // 初始化图标
        this.initIcons();

        // 应用动画
        this.applyAnimations();

        // 检查网络状态
        this.checkNetworkStatus();
    }

    /**
     * 设置搜索功能
     */
    setupSearch() {
        const searchInput = document.getElementById('search-input');
        const searchResults = document.getElementById('search-results');

        if (!searchInput) return;

        let searchTimeout;

        // 实时搜索
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);

            searchTimeout = setTimeout(() => {
                this.performSearch(e.target.value.trim());
            }, this.config.debounceDelay);
        });

        // 搜索快捷键
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.clearSearch();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                this.handleSearchSubmit(e.target.value.trim());
            }
        });

        // 点击外部关闭搜索结果
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults?.contains(e.target)) {
                this.hideSearchResults();
            }
        });
    }

    /**
     * 执行搜索
     */
    async performSearch(query) {
        const searchResults = document.getElementById('search-results');

        if (!query || query.length < 2) {
            this.hideSearchResults();
            return;
        }

        try {
            this.showSearchLoading();

            const response = await this.apiRequest('/search', {
                method: 'POST',
                body: JSON.stringify({
                    keyword: query,
                    use_ai: this.getPreference('use_ai_search', false)
                })
            });

            if (response.success) {
                this.displaySearchResults(response.data);
            } else {
                this.showSearchError(response.message);
            }
        } catch (error) {
            console.error('Search error:', error);
            this.showSearchError('搜索失败，请稍后重试');
        }
    }

    /**
     * 显示搜索结果
     */
    displaySearchResults(results) {
        const searchResults = document.getElementById('search-results');
        if (!searchResults) return;

        if (results.data && results.data.length > 0) {
            const html = results.data.map(bookmark => this.renderBookmarkItem(bookmark)).join('');
            searchResults.innerHTML = `
                <div class="search-results-container">
                    <div class="search-results-header">
                        <span>找到 ${results.total} 个结果</span>
                    </div>
                    <div class="search-results-list">${html}</div>
                </div>
            `;
        } else {
            searchResults.innerHTML = `
                <div class="search-no-results">
                    <i class="fas fa-search"></i>
                    <p>未找到相关书签</p>
                </div>
            `;
        }

        this.showSearchResults();
    }

    /**
     * 渲染书签项目
     */
    renderBookmarkItem(bookmark) {
        return `
            <div class="search-result-item" data-bookmark-id="${bookmark.id}">
                <img src="${bookmark.favicon_url || '/assets/images/default-favicon.png'}"
                     alt="" class="search-result-favicon" loading="lazy">
                <div class="search-result-content">
                    <h4 class="search-result-title">${this.escapeHtml(bookmark.title)}</h4>
                    <p class="search-result-description">${this.escapeHtml(bookmark.description || '')}</p>
                    <div class="search-result-meta">
                        <span class="search-result-category">${bookmark.category_name}</span>
                        <span class="search-result-clicks">${bookmark.clicks} 次点击</span>
                    </div>
                </div>
                <div class="search-result-actions">
                    <button class="btn btn-sm btn-ghost" onclick="app.openBookmark('${bookmark.url}')">
                        <i class="fas fa-external-link-alt"></i>
                    </button>
                    <button class="btn btn-sm btn-ghost" onclick="app.toggleFavorite(${bookmark.id})">
                        <i class="fas fa-heart"></i>
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * 设置侧边栏
     */
    setupSidebar() {
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');

        if (!sidebarToggle || !sidebar) return;

        sidebarToggle.addEventListener('click', () => {
            this.toggleSidebar();
        });

        // 移动端点击遮罩关闭侧边栏
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        overlay.addEventListener('click', () => {
            this.closeSidebar();
        });
        document.body.appendChild(overlay);
    }

    /**
     * 切换侧边栏
     */
    toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        const overlay = document.querySelector('.sidebar-overlay');

        if (window.innerWidth <= 768) {
            // 移动端模式
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        } else {
            // 桌面端模式
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            this.setPreference('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        }
    }

    /**
     * 设置模态框
     */
    setupModals() {
        // 所有模态框触发器
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-modal-target]');
            if (trigger) {
                e.preventDefault();
                const modalId = trigger.getAttribute('data-modal-target');
                this.openModal(modalId);
            }

            // 关闭按钮
            const closeBtn = e.target.closest('[data-modal-close]');
            if (closeBtn) {
                e.preventDefault();
                this.closeModal(closeBtn.closest('.modal'));
            }
        });

        // ESC 键关闭模态框
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeAllModals();
            }
        });

        // 点击背景关闭模态框
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                this.closeModal(e.target);
            }
        });
    }

    /**
     * 打开模态框
     */
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // 自动聚焦第一个输入框
        const firstInput = modal.querySelector('input, textarea, select');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }

        // 触发事件
        this.emit('modal:open', { modalId, modal });
    }

    /**
     * 关闭模态框
     */
    closeModal(modal) {
        if (!modal) return;

        modal.classList.remove('active');
        document.body.style.overflow = '';

        // 触发事件
        this.emit('modal:close', { modal });
    }

    /**
     * 设置拖拽排序
     */
    setupDragSort() {
        const sortableContainers = document.querySelectorAll('[data-sortable]');

        sortableContainers.forEach(container => {
            this.initSortable(container);
        });
    }

    /**
     * 初始化可排序容器
     */
    initSortable(container) {
        let draggedElement = null;
        let placeholder = null;

        container.addEventListener('dragstart', (e) => {
            if (!e.target.hasAttribute('draggable')) return;

            draggedElement = e.target;
            e.target.classList.add('dragging');

            // 创建占位符
            placeholder = document.createElement('div');
            placeholder.className = 'drag-placeholder';
            placeholder.style.height = e.target.offsetHeight + 'px';

            e.dataTransfer.effectAllowed = 'move';
        });

        container.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            if (!draggedElement) return;

            const afterElement = this.getDragAfterElement(container, e.clientY);

            if (afterElement == null) {
                container.appendChild(placeholder);
            } else {
                container.insertBefore(placeholder, afterElement);
            }
        });

        container.addEventListener('drop', (e) => {
            e.preventDefault();

            if (!draggedElement || !placeholder) return;

            // 替换占位符
            placeholder.parentNode.replaceChild(draggedElement, placeholder);

            // 保存新顺序
            this.saveSortOrder(container);

            // 清理
            this.cleanupDrag();
        });

        container.addEventListener('dragend', () => {
            this.cleanupDrag();
        });
    }

    /**
     * 获取拖拽后的位置
     */
    getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('[draggable]:not(.dragging)')];

        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;

            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    /**
     * 设置右键菜单
     */
    setupContextMenu() {
        let contextMenu = document.getElementById('context-menu');

        if (!contextMenu) {
            contextMenu = this.createContextMenu();
        }

        // 书签右键菜单
        document.addEventListener('contextmenu', (e) => {
            const bookmarkCard = e.target.closest('.bookmark-card');

            if (bookmarkCard) {
                e.preventDefault();
                this.showContextMenu(e, bookmarkCard);
            }
        });

        // 点击其他地方关闭菜单
        document.addEventListener('click', () => {
            this.hideContextMenu();
        });
    }

    /**
     * 创建右键菜单
     */
    createContextMenu() {
        const menu = document.createElement('div');
        menu.id = 'context-menu';
        menu.className = 'context-menu';
        menu.innerHTML = `
            <div class="context-menu-item" data-action="open">
                <i class="fas fa-external-link-alt"></i> 打开链接
            </div>
            <div class="context-menu-item" data-action="open-new-tab">
                <i class="fas fa-external-link-alt"></i> 新标签页打开
            </div>
            <div class="context-menu-divider"></div>
            <div class="context-menu-item" data-action="edit">
                <i class="fas fa-edit"></i> 编辑
            </div>
            <div class="context-menu-item" data-action="favorite">
                <i class="fas fa-heart"></i> 收藏
            </div>
            <div class="context-menu-item" data-action="copy-url">
                <i class="fas fa-copy"></i> 复制链接
            </div>
            <div class="context-menu-divider"></div>
            <div class="context-menu-item text-error" data-action="delete">
                <i class="fas fa-trash"></i> 删除
            </div>
        `;

        // 菜单点击事件
        menu.addEventListener('click', (e) => {
            const item = e.target.closest('.context-menu-item');
            if (item) {
                const action = item.getAttribute('data-action');
                this.handleContextMenuAction(action);
            }
        });

        document.body.appendChild(menu);
        return menu;
    }

    /**
     * 设置键盘快捷键
     */
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + K - 搜索
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.focusSearch();
            }

            // Ctrl/Cmd + N - 新建书签
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                this.openModal('add-bookmark-modal');
            }

            // Ctrl/Cmd + B - 切换侧边栏
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                this.toggleSidebar();
            }

            // F2 - 编辑模式
            if (e.key === 'F2') {
                e.preventDefault();
                this.toggleEditMode();
            }
        });
    }

    /**
     * 设置主题切换
     */
    setupThemeToggle() {
        const themeToggle = document.getElementById('theme-toggle');

        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                this.toggleTheme();
            });
        }

        // 自动检测系统主题
        if (window.matchMedia) {
            const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            mediaQuery.addListener(() => {
                if (!this.getPreference('theme')) {
                    this.applyTheme(mediaQuery.matches ? 'dark' : 'light');
                }
            });
        }
    }

    /**
     * 切换主题
     */
    toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';

        this.applyTheme(newTheme);
        this.setPreference('theme', newTheme);
    }

    /**
     * 应用主题
     */
    applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);

        // 更新主题切换按钮图标
        const themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) {
            const icon = themeToggle.querySelector('i');
            if (icon) {
                icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        // 触发事件
        this.emit('theme:change', { theme });
    }

    /**
     * 设置表单处理
     */
    setupForms() {
        document.addEventListener('submit', async (e) => {
            const form = e.target;

            if (form.hasAttribute('data-ajax')) {
                e.preventDefault();
                await this.handleAjaxForm(form);
            }
        });

        // 实时验证
        document.addEventListener('input', (e) => {
            if (e.target.hasAttribute('data-validate')) {
                this.validateField(e.target);
            }
        });
    }

    /**
     * 处理 AJAX 表单
     */
    async handleAjaxForm(form) {
        const formData = new FormData(form);
        const action = form.getAttribute('action') || form.getAttribute('data-action');
        const method = form.getAttribute('method') || 'POST';

        try {
            this.setFormLoading(form, true);

            const response = await this.apiRequest(action, {
                method: method,
                body: formData
            });

            if (response.success) {
                this.showToast('操作成功', 'success');

                // 触发成功事件
                this.emit('form:success', { form, response });

                // 关闭模态框
                const modal = form.closest('.modal');
                if (modal) {
                    this.closeModal(modal);
                }

                // 重置表单
                form.reset();

                // 刷新页面数据
                this.refreshPageData();
            } else {
                this.showFormErrors(form, response.errors || [response.message]);
            }
        } catch (error) {
            console.error('Form submission error:', error);
            this.showToast('提交失败，请稍后重试', 'error');
        } finally {
            this.setFormLoading(form, false);
        }
    }

    /**
     * 设置懒加载
     */
    setupLazyLoading() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        imageObserver.unobserve(img);
                    }
                });
            });

            // 观察所有懒加载图片
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }

    /**
     * API 请求
     */
    async apiRequest(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : this.config.apiBase + endpoint;

        const defaultOptions = {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': this.getCsrfToken()
            }
        };

        if (options.body && !(options.body instanceof FormData)) {
            defaultOptions.headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, { ...defaultOptions, ...options });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
    }

    /**
     * 显示通知
     */
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="toast-icon fas ${this.getToastIcon(type)}"></i>
                <span class="toast-message">${this.escapeHtml(message)}</span>
                <button class="toast-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        // 关闭按钮
        toast.querySelector('.toast-close').addEventListener('click', () => {
            this.hideToast(toast);
        });

        // 添加到容器
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        container.appendChild(toast);

        // 动画显示
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        // 自动关闭
        setTimeout(() => {
            this.hideToast(toast);
        }, this.config.toastDuration);
    }

    /**
     * 隐藏通知
     */
    hideToast(toast) {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    /**
     * 获取通知图标
     */
    getToastIcon(type) {
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        return icons[type] || icons.info;
    }

    /**
     * 工具方法
     */

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
    }

    getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    getPreference(key, defaultValue = null) {
        try {
            const value = localStorage.getItem(`onebooknav_${key}`);
            return value ? JSON.parse(value) : defaultValue;
        } catch {
            return defaultValue;
        }
    }

    setPreference(key, value) {
        try {
            localStorage.setItem(`onebooknav_${key}`, JSON.stringify(value));
        } catch (error) {
            console.warn('Failed to save preference:', error);
        }
    }

    emit(eventName, data = {}) {
        const event = new CustomEvent(eventName, { detail: data });
        document.dispatchEvent(event);
    }

    // 加载用户偏好设置
    loadUserPreferences() {
        const theme = this.getPreference('theme');
        if (theme) {
            this.applyTheme(theme);
        }

        const sidebarCollapsed = this.getPreference('sidebar_collapsed');
        if (sidebarCollapsed && window.innerWidth > 768) {
            document.getElementById('sidebar')?.classList.add('collapsed');
            document.getElementById('main-content')?.classList.add('expanded');
        }
    }

    // 设置 Service Worker
    setupServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('Service Worker registered:', registration);
                })
                .catch(error => {
                    console.log('Service Worker registration failed:', error);
                });
        }
    }

    // 检查网络状态
    checkNetworkStatus() {
        const updateOnlineStatus = () => {
            const isOnline = navigator.onLine;
            document.body.classList.toggle('offline', !isOnline);

            if (!isOnline) {
                this.showToast('网络连接已断开', 'warning');
            }
        };

        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();
    }
}

// 全局实例
window.app = new OneBookNav();

// 导出模块（如果使用模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = OneBookNav;
}