/**
 * OneBookNav 前端主应用
 * 现代化交互和功能实现
 */

class OneBookNav {
    constructor() {
        this.config = {
            searchDelay: 300,
            animationDuration: 250,
            apiBaseUrl: '/api'
        };

        this.state = {
            currentTheme: localStorage.getItem('theme') || 'light',
            searchQuery: '',
            selectedCategory: null,
            websites: [],
            categories: [],
            loading: false
        };

        this.debounceTimers = new Map();
        this.init();
    }

    init() {
        this.bindEvents();
        this.initComponents();
        this.loadTheme();
        this.loadData();
        this.setupKeyboardShortcuts();
    }

    bindEvents() {
        // 搜索功能
        const searchForm = document.querySelector('#searchForm');
        if (searchForm) {
            searchForm.addEventListener('submit', (e) => this.handleSearch(e));
        }

        // 主题切换
        const themeToggle = document.querySelector('#themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => this.toggleTheme());
        }

        // 网站点击统计
        document.querySelectorAll('.website-item').forEach(item => {
            item.addEventListener('click', (e) => this.trackClick(e));
        });

        // 收藏功能
        document.querySelectorAll('.favorite-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.toggleFavorite(e));
        });

        // 模态框
        document.querySelectorAll('[data-modal]').forEach(trigger => {
            trigger.addEventListener('click', (e) => this.openModal(e));
        });

        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => this.closeModal(e));
        });

        // 拖拽排序
        this.initSortable();

        // 右键菜单
        this.initContextMenu();

        // 快捷键
        this.initKeyboardShortcuts();
    }

    initComponents() {
        // 初始化工具提示
        this.initTooltips();

        // 初始化下拉菜单
        this.initDropdowns();

        // 初始化表单验证
        this.initFormValidation();

        // 初始化图片懒加载
        this.initLazyLoading();

        // 初始化无限滚动
        this.initInfiniteScroll();
    }

    // 搜索功能
    handleSearch(e) {
        e.preventDefault();
        const form = e.target;
        const keyword = form.querySelector('input[name="keyword"]').value.trim();

        if (!keyword) {
            this.showAlert('请输入搜索关键词', 'warning');
            return;
        }

        this.showLoading();

        fetch('/api/v1/search', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ keyword })
        })
        .then(response => response.json())
        .then(data => {
            this.hideLoading();
            if (data.success) {
                this.displaySearchResults(data.data);
            } else {
                this.showAlert(data.message, 'error');
            }
        })
        .catch(error => {
            this.hideLoading();
            this.showAlert('搜索出错，请稍后重试', 'error');
            console.error('Search error:', error);
        });
    }

    // 显示搜索结果
    displaySearchResults(results) {
        const container = document.querySelector('#searchResults');
        if (!container) return;

        container.innerHTML = '';

        if (results.length === 0) {
            container.innerHTML = '<p class="text-center">没有找到相关结果</p>';
            return;
        }

        results.forEach(item => {
            const element = this.createWebsiteElement(item);
            container.appendChild(element);
        });
    }

    // 创建网站元素
    createWebsiteElement(data) {
        const div = document.createElement('div');
        div.className = 'website-item';
        div.innerHTML = `
            <img src="${data.favicon_url || '/assets/images/default-favicon.png'}"
                 alt="${data.title}" class="website-icon" loading="lazy">
            <div class="website-info">
                <div class="website-title">${this.escapeHtml(data.title)}</div>
                <div class="website-description">${this.escapeHtml(data.description || '')}</div>
                <div class="website-stats">点击: ${data.clicks} | 分类: ${data.category_name}</div>
            </div>
            <div class="website-actions">
                <button class="btn btn-sm favorite-btn" data-id="${data.id}">
                    <i class="fas fa-heart"></i>
                </button>
            </div>
        `;

        div.addEventListener('click', () => {
            this.trackClick({ target: { dataset: { id: data.id } } });
            window.open(data.url, '_blank');
        });

        return div;
    }

    // 点击统计
    trackClick(e) {
        const websiteId = e.target.dataset.id || e.target.closest('[data-id]')?.dataset.id;
        if (!websiteId) return;

        fetch(`/api/v1/websites/${websiteId}/click`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).catch(error => {
            console.error('Click tracking error:', error);
        });
    }

    // 收藏功能
    toggleFavorite(e) {
        e.preventDefault();
        e.stopPropagation();

        const btn = e.target.closest('.favorite-btn');
        const websiteId = btn.dataset.id;

        const isActive = btn.classList.contains('active');
        const method = isActive ? 'DELETE' : 'POST';
        const url = `/api/v1/websites/${websiteId}/favorite`;

        fetch(url, {
            method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Authorization': `Bearer ${this.getToken()}`
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                btn.classList.toggle('active');
                const icon = btn.querySelector('i');
                icon.className = isActive ? 'fas fa-heart-o' : 'fas fa-heart';
                this.showAlert(isActive ? '已取消收藏' : '已添加到收藏', 'success');
            } else {
                this.showAlert(data.message, 'error');
            }
        })
        .catch(error => {
            this.showAlert('操作失败，请稍后重试', 'error');
            console.error('Favorite error:', error);
        });
    }

    // 主题切换
    toggleTheme() {
        const body = document.body;
        const isDark = body.classList.contains('dark-theme');

        if (isDark) {
            body.classList.remove('dark-theme');
            localStorage.setItem('theme', 'light');
        } else {
            body.classList.add('dark-theme');
            localStorage.setItem('theme', 'dark');
        }
    }

    // 加载主题
    loadTheme() {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-theme');
        }
    }

    // 模态框
    openModal(e) {
        e.preventDefault();
        const modalId = e.target.dataset.modal;
        const modal = document.querySelector(`#${modalId}`);
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    closeModal(e) {
        const modal = e.target.closest('.modal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    // 拖拽排序
    initSortable() {
        const sortableContainers = document.querySelectorAll('.sortable');
        sortableContainers.forEach(container => {
            new Sortable(container, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                onEnd: (evt) => {
                    this.updateSortOrder(evt);
                }
            });
        });
    }

    // 更新排序
    updateSortOrder(evt) {
        const items = Array.from(evt.to.children);
        const sortData = items.map((item, index) => ({
            id: item.dataset.id,
            order: index + 1
        }));

        fetch('/api/v1/websites/sort', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Authorization': `Bearer ${this.getToken()}`
            },
            body: JSON.stringify({ items: sortData })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showAlert('排序已保存', 'success');
            } else {
                this.showAlert('排序失败', 'error');
            }
        })
        .catch(error => {
            this.showAlert('排序失败', 'error');
            console.error('Sort error:', error);
        });
    }

    // 右键菜单
    initContextMenu() {
        document.addEventListener('contextmenu', (e) => {
            if (e.target.closest('.website-item')) {
                e.preventDefault();
                this.showContextMenu(e);
            }
        });

        document.addEventListener('click', () => {
            this.hideContextMenu();
        });
    }

    showContextMenu(e) {
        const menu = document.querySelector('#contextMenu');
        if (!menu) return;

        const websiteItem = e.target.closest('.website-item');
        const websiteId = websiteItem.dataset.id;

        menu.dataset.websiteId = websiteId;
        menu.style.left = e.pageX + 'px';
        menu.style.top = e.pageY + 'px';
        menu.classList.add('show');
    }

    hideContextMenu() {
        const menu = document.querySelector('#contextMenu');
        if (menu) {
            menu.classList.remove('show');
        }
    }

    // 快捷键
    initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + K: 搜索
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('#searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }

            // Ctrl/Cmd + /: 显示帮助
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                this.showHelp();
            }

            // ESC: 关闭模态框
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.modal.show');
                if (openModal) {
                    this.closeModal({ target: openModal });
                }
            }
        });
    }

    // 工具提示
    initTooltips() {
        const tooltipElements = document.querySelectorAll('[data-tooltip]');
        tooltipElements.forEach(element => {
            element.addEventListener('mouseenter', (e) => {
                this.showTooltip(e);
            });
            element.addEventListener('mouseleave', () => {
                this.hideTooltip();
            });
        });
    }

    showTooltip(e) {
        const text = e.target.dataset.tooltip;
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.textContent = text;
        tooltip.id = 'tooltip';

        document.body.appendChild(tooltip);

        const rect = e.target.getBoundingClientRect();
        tooltip.style.left = rect.left + rect.width / 2 - tooltip.offsetWidth / 2 + 'px';
        tooltip.style.top = rect.top - tooltip.offsetHeight - 10 + 'px';
    }

    hideTooltip() {
        const tooltip = document.querySelector('#tooltip');
        if (tooltip) {
            tooltip.remove();
        }
    }

    // 表单验证
    initFormValidation() {
        const forms = document.querySelectorAll('form[data-validate]');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!this.validateForm(form)) {
                    e.preventDefault();
                }
            });
        });
    }

    validateForm(form) {
        let isValid = true;
        const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');

        inputs.forEach(input => {
            if (!input.value.trim()) {
                this.showFieldError(input, '此字段为必填项');
                isValid = false;
            } else if (input.type === 'email' && !this.isValidEmail(input.value)) {
                this.showFieldError(input, '请输入有效的邮箱地址');
                isValid = false;
            } else if (input.type === 'url' && !this.isValidUrl(input.value)) {
                this.showFieldError(input, '请输入有效的URL地址');
                isValid = false;
            } else {
                this.clearFieldError(input);
            }
        });

        return isValid;
    }

    showFieldError(input, message) {
        this.clearFieldError(input);
        input.classList.add('error');

        const error = document.createElement('div');
        error.className = 'field-error';
        error.textContent = message;

        input.parentNode.insertBefore(error, input.nextSibling);
    }

    clearFieldError(input) {
        input.classList.remove('error');
        const error = input.parentNode.querySelector('.field-error');
        if (error) {
            error.remove();
        }
    }

    // 图片懒加载
    initLazyLoading() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        observer.unobserve(img);
                    }
                });
            });

            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }

    // 无限滚动
    initInfiniteScroll() {
        let loading = false;
        let page = 1;

        window.addEventListener('scroll', () => {
            if (loading) return;

            const { scrollTop, scrollHeight, clientHeight } = document.documentElement;

            if (scrollTop + clientHeight >= scrollHeight - 1000) {
                loading = true;
                this.loadMoreContent(++page).finally(() => {
                    loading = false;
                });
            }
        });
    }

    loadMoreContent(page) {
        return fetch(`/api/v1/websites?page=${page}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.length > 0) {
                    const container = document.querySelector('#websiteContainer');
                    data.data.forEach(item => {
                        const element = this.createWebsiteElement(item);
                        container.appendChild(element);
                    });
                }
            })
            .catch(error => {
                console.error('Load more error:', error);
            });
    }

    // 工具方法
    showAlert(message, type = 'info', duration = 3000) {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-floating`;
        alert.textContent = message;

        document.body.appendChild(alert);

        setTimeout(() => {
            alert.classList.add('show');
        }, 100);

        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 300);
        }, duration);
    }

    showLoading() {
        const loading = document.createElement('div');
        loading.className = 'loading-overlay';
        loading.innerHTML = '<div class="loading"></div>';
        loading.id = 'loadingOverlay';
        document.body.appendChild(loading);
    }

    hideLoading() {
        const loading = document.querySelector('#loadingOverlay');
        if (loading) {
            loading.remove();
        }
    }

    getToken() {
        return localStorage.getItem('auth_token') || '';
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }

    showHelp() {
        this.showAlert('快捷键: Ctrl+K (搜索), Ctrl+/ (帮助), ESC (关闭)', 'info', 5000);
    }
}

// 初始化应用
document.addEventListener('DOMContentLoaded', () => {
    new OneBookNav();
});

// 全局工具函数
window.OneBookNavUtils = {
    formatDate: (date) => {
        return new Date(date).toLocaleDateString('zh-CN');
    },

    formatNumber: (num) => {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    },

    copyToClipboard: (text) => {
        navigator.clipboard.writeText(text).then(() => {
            window.oneBookNav?.showAlert('已复制到剪贴板', 'success');
        });
    },

    debounce: (func, wait) => {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    throttle: (func, limit) => {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
};