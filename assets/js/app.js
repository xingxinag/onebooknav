/**
 * OneBookNav JavaScript Application
 * Main application logic and UI interactions
 */

class OneBookNavApp {
    constructor() {
        this.config = window.OneBookNav || {};
        this.currentUser = this.config.user;
        this.categories = this.config.categories || [];
        this.bookmarks = this.config.bookmarks || [];
        this.currentCategory = null;
        this.currentView = 'grid';
        this.searchTimeout = null;
        this.contextMenu = null;
        this.sortable = null;

        // API base URL
        this.apiBase = '/api';

        // Bind methods
        this.handleError = this.handleError.bind(this);
        this.showToast = this.showToast.bind(this);
        this.makeRequest = this.makeRequest.bind(this);
    }

    /**
     * Initialize the application
     */
    init() {
        this.setupEventListeners();
        this.loadBookmarks();
        this.initializeSortable();
        this.setupContextMenu();
        this.populateDropdowns();

        // Load user preferences
        if (this.currentUser) {
            this.loadUserPreferences();
        }

        // Initialize search
        this.initializeSearch();

        console.log('OneBookNav initialized');
    }

    /**
     * Setup global event listeners
     */
    setupEventListeners() {
        // Category navigation
        document.addEventListener('click', (e) => {
            if (e.target.matches('.category-link') || e.target.closest('.category-link')) {
                e.preventDefault();
                const link = e.target.closest('.category-link');
                const categoryId = link.dataset.categoryId;
                this.loadCategoryBookmarks(categoryId);
            }
        });

        // Bookmark interactions
        document.addEventListener('click', (e) => {
            if (e.target.matches('.bookmark-card') || e.target.closest('.bookmark-card')) {
                const card = e.target.closest('.bookmark-card');
                if (e.ctrlKey || e.metaKey) {
                    window.open(card.dataset.url, '_blank');
                } else {
                    this.openBookmark(card.dataset.url);
                }
            }
        });

        // Context menu
        document.addEventListener('contextmenu', (e) => {
            if (e.target.matches('.bookmark-card') || e.target.closest('.bookmark-card')) {
                e.preventDefault();
                const card = e.target.closest('.bookmark-card');
                this.showContextMenu(e, card);
            }
        });

        // Hide context menu on click
        document.addEventListener('click', () => {
            this.hideContextMenu();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'k':
                        e.preventDefault();
                        document.getElementById('searchInput').focus();
                        break;
                    case 'n':
                        e.preventDefault();
                        if (this.currentUser) {
                            const modal = new bootstrap.Modal(document.getElementById('addBookmarkModal'));
                            modal.show();
                        }
                        break;
                }
            }
        });

        // Window resize
        window.addEventListener('resize', () => {
            this.adjustLayout();
        });
    }

    /**
     * Initialize search functionality
     */
    initializeSearch() {
        const searchInput = document.getElementById('searchInput');
        const searchForm = document.getElementById('searchForm');

        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.performSearch(e.target.value);
                }, 300);
            });
        }

        if (searchForm) {
            searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.performSearch(searchInput.value);
            });
        }
    }

    /**
     * Perform bookmark search (with AI enhancement)
     */
    async performSearch(query) {
        if (!query.trim()) {
            this.loadBookmarks();
            return;
        }

        try {
            // Use AI search endpoint for enhanced results
            const response = await this.makeRequest(`${this.apiBase}/ai-search`, 'GET', { q: query });
            this.displayBookmarks(response.data);
            document.getElementById('currentCategoryTitle').textContent = `AI Search: "${query}"`;

            // Show search suggestions if available
            this.displaySearchSuggestions(response.suggestions || []);
        } catch (error) {
            // Fallback to regular search if AI search fails
            try {
                const fallbackResponse = await this.makeRequest(`${this.apiBase}/search`, 'GET', { q: query });
                this.displayBookmarks(fallbackResponse.data);
                document.getElementById('currentCategoryTitle').textContent = `Search: "${query}"`;
            } catch (fallbackError) {
                this.handleError(error);
            }
        }
    }

    /**
     * Load bookmarks for display
     */
    async loadBookmarks(categoryId = null) {
        try {
            let response;
            if (categoryId) {
                response = await this.makeRequest(`${this.apiBase}/bookmarks/category/${categoryId}`);
                this.currentCategory = categoryId;
            } else {
                response = await this.makeRequest(`${this.apiBase}/bookmarks`);
                this.currentCategory = null;
            }

            this.bookmarks = response.data;
            this.displayBookmarks(this.bookmarks);

            // Update title
            const categoryName = categoryId ?
                this.getCategoryName(categoryId) : 'All Bookmarks';
            document.getElementById('currentCategoryTitle').textContent = categoryName;

        } catch (error) {
            this.handleError(error);
        }
    }

    /**
     * Load bookmarks for a specific category
     */
    loadCategoryBookmarks(categoryId) {
        this.loadBookmarks(categoryId);

        // Update active category
        document.querySelectorAll('.category-link').forEach(link => {
            link.classList.remove('active');
        });

        const activeLink = document.querySelector(`[data-category-id="${categoryId}"]`);
        if (activeLink) {
            activeLink.classList.add('active');
        }
    }

    /**
     * Display bookmarks in the current view
     */
    displayBookmarks(bookmarks) {
        const container = document.getElementById('bookmarksContainer');
        const emptyState = document.getElementById('emptyState');

        if (!bookmarks || bookmarks.length === 0) {
            container.innerHTML = '';
            emptyState.style.display = 'block';
            return;
        }

        emptyState.style.display = 'none';

        if (this.currentView === 'grid') {
            this.displayGridView(bookmarks, container);
        } else {
            this.displayListView(bookmarks, container);
        }
    }

    /**
     * Display bookmarks in grid view
     */
    displayGridView(bookmarks, container) {
        const html = bookmarks.map(bookmark => `
            <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12 mb-4">
                <div class="bookmark-card" data-id="${bookmark.id}" data-url="${bookmark.url}" tabindex="0">
                    ${bookmark.is_private ? '<i class="fas fa-lock bookmark-private"></i>' : ''}
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-2">
                            <div class="bookmark-icon">
                                ${bookmark.icon_url ?
                                    `<img src="${bookmark.icon_url}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                     <i class="fas fa-link" style="display: none;"></i>` :
                                    '<i class="fas fa-link"></i>'
                                }
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="bookmark-title">${this.escapeHtml(bookmark.title)}</h6>
                                <a href="${bookmark.url}" class="bookmark-url" onclick="event.stopPropagation();">
                                    ${this.truncateUrl(bookmark.url, 25)}
                                </a>
                            </div>
                        </div>
                        ${bookmark.description ?
                            `<p class="bookmark-description">${this.escapeHtml(bookmark.description)}</p>` : ''
                        }
                        ${bookmark.keywords ?
                            `<div class="bookmark-tags">
                                ${bookmark.keywords.split(',').map(tag =>
                                    `<span class="bookmark-tag">${this.escapeHtml(tag.trim())}</span>`
                                ).join('')}
                            </div>` : ''
                        }
                        <div class="bookmark-meta">
                            <small class="text-muted">
                                <i class="fas fa-eye me-1"></i>${bookmark.click_count || 0}
                            </small>
                            <small class="text-muted">
                                ${this.formatDate(bookmark.created_at)}
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        container.innerHTML = html;
        container.className = 'row';
    }

    /**
     * Display bookmarks in list view
     */
    displayListView(bookmarks, container) {
        const html = bookmarks.map(bookmark => `
            <div class="bookmark-list-item" data-id="${bookmark.id}" data-url="${bookmark.url}" tabindex="0">
                <div class="d-flex align-items-center">
                    <div class="bookmark-icon me-3">
                        ${bookmark.icon_url ?
                            `<img src="${bookmark.icon_url}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                             <i class="fas fa-link" style="display: none;"></i>` :
                            '<i class="fas fa-link"></i>'
                        }
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="bookmark-list-title mb-1">
                                    ${this.escapeHtml(bookmark.title)}
                                    ${bookmark.is_private ? '<i class="fas fa-lock text-warning ms-2"></i>' : ''}
                                </h6>
                                <a href="${bookmark.url}" class="bookmark-list-url" onclick="event.stopPropagation();">
                                    ${bookmark.url}
                                </a>
                                ${bookmark.description ?
                                    `<p class="text-muted mt-1 mb-0">${this.escapeHtml(bookmark.description)}</p>` : ''
                                }
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">
                                    <i class="fas fa-eye me-1"></i>${bookmark.click_count || 0}
                                </small>
                                <small class="text-muted">
                                    ${this.formatDate(bookmark.created_at)}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        container.innerHTML = html;
        container.className = 'list-view';
    }

    /**
     * Toggle between grid and list view
     */
    toggleView(view) {
        this.currentView = view;
        this.displayBookmarks(this.bookmarks);

        // Update button states
        document.querySelectorAll('[onclick*="toggleView"]').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[onclick="toggleView('${view}')"]`).classList.add('active');

        // Save preference
        if (this.currentUser) {
            this.saveUserPreference('default_view', view);
        }
    }

    /**
     * Initialize sortable functionality
     */
    initializeSortable() {
        if (typeof Sortable !== 'undefined' && this.currentUser) {
            const container = document.getElementById('bookmarksContainer');
            this.sortable = Sortable.create(container, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onEnd: (evt) => {
                    this.reorderBookmarks(evt);
                }
            });
        }
    }

    /**
     * Handle bookmark reordering
     */
    async reorderBookmarks(evt) {
        const bookmarkIds = Array.from(evt.to.children).map(el => {
            const card = el.querySelector('.bookmark-card') || el.querySelector('.bookmark-list-item');
            return card ? card.dataset.id : null;
        }).filter(id => id);

        try {
            await this.makeRequest(`${this.apiBase}/bookmarks/reorder`, 'POST', {
                bookmark_ids: bookmarkIds
            });
        } catch (error) {
            this.handleError(error);
            // Revert the change
            this.loadBookmarks(this.currentCategory);
        }
    }

    /**
     * Setup context menu
     */
    setupContextMenu() {
        this.contextMenu = document.getElementById('contextMenu');

        if (this.contextMenu) {
            this.contextMenu.addEventListener('click', (e) => {
                if (e.target.matches('.context-item')) {
                    e.preventDefault();
                    const action = e.target.dataset.action;
                    const bookmarkId = this.contextMenu.dataset.bookmarkId;
                    const bookmarkUrl = this.contextMenu.dataset.bookmarkUrl;

                    this.handleContextAction(action, bookmarkId, bookmarkUrl);
                    this.hideContextMenu();
                }
            });
        }
    }

    /**
     * Show context menu
     */
    showContextMenu(event, bookmarkCard) {
        if (!this.contextMenu || !this.currentUser) return;

        const bookmarkId = bookmarkCard.dataset.id;
        const bookmarkUrl = bookmarkCard.dataset.url;

        this.contextMenu.dataset.bookmarkId = bookmarkId;
        this.contextMenu.dataset.bookmarkUrl = bookmarkUrl;

        this.contextMenu.style.display = 'block';
        this.contextMenu.style.left = event.pageX + 'px';
        this.contextMenu.style.top = event.pageY + 'px';

        // Adjust position if menu goes off screen
        const rect = this.contextMenu.getBoundingClientRect();
        if (rect.right > window.innerWidth) {
            this.contextMenu.style.left = (event.pageX - rect.width) + 'px';
        }
        if (rect.bottom > window.innerHeight) {
            this.contextMenu.style.top = (event.pageY - rect.height) + 'px';
        }
    }

    /**
     * Hide context menu
     */
    hideContextMenu() {
        if (this.contextMenu) {
            this.contextMenu.style.display = 'none';
        }
    }

    /**
     * Handle context menu actions
     */
    handleContextAction(action, bookmarkId, bookmarkUrl) {
        switch (action) {
            case 'open':
                this.openBookmark(bookmarkUrl);
                break;
            case 'open-new':
                window.open(bookmarkUrl, '_blank');
                break;
            case 'edit':
                this.editBookmark(bookmarkId);
                break;
            case 'copy':
                this.copyToClipboard(bookmarkUrl);
                break;
            case 'delete':
                this.deleteBookmark(bookmarkId);
                break;
        }
    }

    /**
     * Open bookmark and track click
     */
    async openBookmark(url) {
        // Track click count using enhanced click tracking
        const bookmarkCard = document.querySelector(`[data-url="${url}"]`);
        if (bookmarkCard && this.currentUser) {
            const bookmarkId = bookmarkCard.dataset.id;
            window.trackBookmarkClick(bookmarkId);
        }

        window.location.href = url;
    }

    /**
     * Edit bookmark
     */
    async editBookmark(bookmarkId) {
        try {
            const response = await this.makeRequest(`${this.apiBase}/bookmarks/${bookmarkId}`);
            const bookmark = response.data;

            // Populate edit form
            document.getElementById('editBookmarkId').value = bookmark.id;
            document.getElementById('editBookmarkTitle').value = bookmark.title;
            document.getElementById('editBookmarkUrl').value = bookmark.url;
            document.getElementById('editBookmarkDescription').value = bookmark.description || '';
            document.getElementById('editBookmarkKeywords').value = bookmark.keywords || '';
            document.getElementById('editBookmarkCategory').value = bookmark.category_id;
            document.getElementById('editBookmarkPrivate').checked = !!bookmark.is_private;

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('editBookmarkModal'));
            modal.show();

        } catch (error) {
            this.handleError(error);
        }
    }

    /**
     * Delete bookmark with confirmation
     */
    async deleteBookmark(bookmarkId) {
        if (!confirm('Are you sure you want to delete this bookmark?')) {
            return;
        }

        try {
            await this.makeRequest(`${this.apiBase}/bookmarks/${bookmarkId}`, 'DELETE');
            this.showToast('Bookmark deleted successfully', 'success');
            this.loadBookmarks(this.currentCategory);
        } catch (error) {
            this.handleError(error);
        }
    }

    /**
     * Copy text to clipboard
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showToast('URL copied to clipboard', 'success');
        } catch (error) {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            this.showToast('URL copied to clipboard', 'success');
        }
    }

    /**
     * Populate dropdown menus
     */
    populateDropdowns() {
        const categorySelects = document.querySelectorAll('#categoryParent, #bookmarkCategory, #editBookmarkCategory');

        categorySelects.forEach(select => {
            this.populateCategorySelect(select);
        });
    }

    /**
     * Populate category select dropdown
     */
    populateCategorySelect(select) {
        if (!select) return;

        const currentOptions = select.innerHTML;
        const isParentSelect = select.id === 'categoryParent';

        let html = isParentSelect ? '<option value="">No parent (top level)</option>' : '';

        const renderOptions = (categories, level = 0) => {
            categories.forEach(category => {
                const indent = '&nbsp;'.repeat(level * 4);
                html += `<option value="${category.id}">${indent}${this.escapeHtml(category.name)}</option>`;

                if (category.children && category.children.length > 0) {
                    renderOptions(category.children, level + 1);
                }
            });
        };

        renderOptions(this.categories);
        select.innerHTML = html;
    }

    /**
     * Load user preferences
     */
    async loadUserPreferences() {
        try {
            const response = await this.makeRequest(`${this.apiBase}/auth/user`);
            const user = response.data;

            if (user.settings) {
                const settings = JSON.parse(user.settings);

                if (settings.default_view) {
                    this.currentView = settings.default_view;
                }
            }
        } catch (error) {
            // Ignore preference loading errors
        }
    }

    /**
     * Save user preference
     */
    async saveUserPreference(key, value) {
        try {
            await this.makeRequest(`${this.apiBase}/user/preferences`, 'POST', {
                [key]: value
            });
        } catch (error) {
            // Ignore preference saving errors
        }
    }

    /**
     * Adjust layout for different screen sizes
     */
    adjustLayout() {
        const container = document.getElementById('bookmarksContainer');
        if (window.innerWidth < 768 && this.currentView === 'grid') {
            container.classList.add('mobile-grid');
        } else {
            container.classList.remove('mobile-grid');
        }
    }

    /**
     * Get category name by ID
     */
    getCategoryName(categoryId) {
        const findCategory = (categories) => {
            for (const category of categories) {
                if (category.id == categoryId) {
                    return category.name;
                }
                if (category.children) {
                    const found = findCategory(category.children);
                    if (found) return found;
                }
            }
            return null;
        };

        return findCategory(this.categories) || 'Unknown Category';
    }

    /**
     * Make API request
     */
    async makeRequest(url, method = 'GET', data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': this.config.csrfToken
            }
        };

        if (data) {
            if (method === 'GET') {
                url += '?' + new URLSearchParams(data);
            } else {
                options.body = JSON.stringify(data);
            }
        }

        const response = await fetch(url, options);

        if (!response.ok) {
            const error = await response.json().catch(() => ({
                error: `HTTP ${response.status}: ${response.statusText}`
            }));
            throw new Error(error.error || 'Request failed');
        }

        return response.json();
    }

    /**
     * Handle errors
     */
    handleError(error) {
        console.error('OneBookNav Error:', error);
        this.showToast(error.message || 'An error occurred', 'danger');
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        const toastContainer = this.getOrCreateToastContainer();

        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${this.escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        toastContainer.appendChild(toast);

        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();

        // Clean up after toast is hidden
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    /**
     * Get or create toast container
     */
    getOrCreateToastContainer() {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1055';
            document.body.appendChild(container);
        }
        return container;
    }

    /**
     * Utility functions
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    truncateUrl(url, maxLength) {
        if (url.length <= maxLength) return url;
        return url.substring(0, maxLength) + '...';
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }
}

// Global functions for onclick handlers
window.OneBookNavApp = new OneBookNavApp();

// Modal form handlers
window.submitLogin = async function() {
    const form = document.getElementById('loginForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    try {
        const response = await OneBookNavApp.makeRequest('/api/auth/login', 'POST', data);
        OneBookNavApp.showToast('Login successful', 'success');
        location.reload();
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.submitRegister = async function() {
    const form = document.getElementById('registerForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    if (data.password !== data.password_confirm) {
        OneBookNavApp.showToast('Passwords do not match', 'danger');
        return;
    }

    try {
        await OneBookNavApp.makeRequest('/api/auth/register', 'POST', data);
        OneBookNavApp.showToast('Registration successful, please login', 'success');

        // Switch to login modal
        bootstrap.Modal.getInstance(document.getElementById('registerModal')).hide();
        const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
        loginModal.show();
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.logout = async function() {
    try {
        await OneBookNavApp.makeRequest('/api/auth/logout', 'POST');
        location.reload();
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.submitAddCategory = async function() {
    const form = document.getElementById('addCategoryForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    try {
        await OneBookNavApp.makeRequest('/api/categories', 'POST', data);
        OneBookNavApp.showToast('Category created successfully', 'success');
        bootstrap.Modal.getInstance(document.getElementById('addCategoryModal')).hide();
        location.reload(); // Reload to update sidebar
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.submitAddBookmark = async function() {
    const form = document.getElementById('addBookmarkForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    try {
        await OneBookNavApp.makeRequest('/api/bookmarks', 'POST', data);
        OneBookNavApp.showToast('Bookmark added successfully', 'success');
        bootstrap.Modal.getInstance(document.getElementById('addBookmarkModal')).hide();
        form.reset();
        OneBookNavApp.loadBookmarks(OneBookNavApp.currentCategory);
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.submitEditBookmark = async function() {
    const form = document.getElementById('editBookmarkForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    const bookmarkId = data.bookmark_id;

    delete data.bookmark_id;

    try {
        await OneBookNavApp.makeRequest(`/api/bookmarks/${bookmarkId}`, 'PUT', data);
        OneBookNavApp.showToast('Bookmark updated successfully', 'success');
        bootstrap.Modal.getInstance(document.getElementById('editBookmarkModal')).hide();
        OneBookNavApp.loadBookmarks(OneBookNavApp.currentCategory);
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.toggleView = function(view) {
    OneBookNavApp.toggleView(view);
};

window.deleteBookmark = function() {
    const bookmarkId = document.getElementById('editBookmarkId').value;
    OneBookNavApp.deleteBookmark(bookmarkId);
    bootstrap.Modal.getInstance(document.getElementById('editBookmarkModal')).hide();
};

// Export and import functions
window.exportData = async function() {
    const format = document.getElementById('exportFormat').value;
    const includePrivate = document.getElementById('exportPrivate').checked;

    try {
        const url = `/api/backup/export?format=${format}&include_private=${includePrivate}`;
        window.location.href = url;
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.importData = async function() {
    const fileInput = document.getElementById('importFile');
    const importType = document.getElementById('importType').value;

    if (!fileInput.files[0]) {
        OneBookNavApp.showToast('Please select a file', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('type', importType);

    try {
        const response = await fetch('/api/backup/import', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': OneBookNavApp.config.csrfToken
            }
        });

        if (!response.ok) {
            throw new Error('Import failed');
        }

        const result = await response.json();
        OneBookNavApp.showToast(`Import successful: ${result.data.bookmarks} bookmarks, ${result.data.categories} categories`, 'success');
        location.reload();
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

window.createBackup = async function() {
    try {
        const response = await OneBookNavApp.makeRequest('/api/backup/create', 'POST');
        OneBookNavApp.showToast('Backup created successfully', 'success');
        // Refresh backup list
        loadBackupsList();
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
};

// Load backups list
async function loadBackupsList() {
    try {
        const response = await OneBookNavApp.makeRequest('/api/backup/list');
        const backups = response.data;

        const container = document.getElementById('backupsList');
        if (backups.length === 0) {
            container.innerHTML = '<p class="text-muted">No backups found</p>';
            return;
        }

        const html = backups.map(backup => `
            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                <div>
                    <strong>${backup.filename}</strong><br>
                    <small class="text-muted">${backup.size} bytes - ${backup.created_at}</small>
                </div>
                <button class="btn btn-sm btn-outline-primary" onclick="downloadBackup('${backup.filename}')">
                    <i class="fas fa-download"></i>
                </button>
            </div>
        `).join('');

        container.innerHTML = html;
    } catch (error) {
        OneBookNavApp.handleError(error);
    }
}

window.downloadBackup = function(filename) {
    window.location.href = `/api/backup/download/${filename}`;
};

// PWA installation
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;

    // Show install button if desired
    const installButton = document.getElementById('installButton');
    if (installButton) {
        installButton.style.display = 'block';
        installButton.addEventListener('click', installPWA);
    }
});

async function installPWA() {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        console.log(`User response to install prompt: ${outcome}`);
        deferredPrompt = null;
    }
}

// Enhanced Features Support

/**
 * Display search suggestions
 */
window.displaySearchSuggestions = function(suggestions) {
    const suggestionsContainer = document.getElementById('searchSuggestions');
    if (!suggestionsContainer || !suggestions.length) return;

    const html = suggestions.map(suggestion => `
        <div class="suggestion-item" onclick="performSearchFromSuggestion('${suggestion}')">
            <i class="fas fa-search me-2"></i>${suggestion}
        </div>
    `).join('');

    suggestionsContainer.innerHTML = html;
    suggestionsContainer.style.display = 'block';
};

/**
 * Perform search from suggestion
 */
window.performSearchFromSuggestion = function(query) {
    document.getElementById('searchInput').value = query;
    app.performSearch(query);
    document.getElementById('searchSuggestions').style.display = 'none';
};

/**
 * Track bookmark click
 */
window.trackBookmarkClick = async function(bookmarkId) {
    try {
        await app.makeRequest(`${app.apiBase}/click-tracking`, 'POST', { bookmark_id: bookmarkId });
    } catch (error) {
        console.warn('Failed to track bookmark click:', error);
    }
};

/**
 * Initialize drag and drop sorting
 */
window.initializeDragSort = function() {
    const bookmarksContainer = document.getElementById('bookmarksContainer');
    if (!bookmarksContainer) return;

    // Enable SortableJS for bookmark sorting
    if (window.Sortable) {
        app.sortable = Sortable.create(bookmarksContainer, {
            group: 'bookmarks',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onEnd: function(evt) {
                const bookmarkIds = Array.from(bookmarksContainer.children).map(card =>
                    card.querySelector('.bookmark-card')?.dataset.id
                ).filter(id => id);

                app.updateBookmarkOrder(bookmarkIds, app.currentCategory);
            }
        });
    }
};

/**
 * Update bookmark order via drag sort
 */
window.updateBookmarkOrder = async function(bookmarkIds, categoryId) {
    try {
        await app.makeRequest(`${app.apiBase}/drag-sort/bookmarks`, 'POST', {
            bookmark_ids: bookmarkIds,
            category_id: categoryId
        });
        app.showToast('Bookmark order updated', 'success');
    } catch (error) {
        app.handleError(error);
    }
};

/**
 * Check for dead links
 */
window.checkDeadLinks = async function() {
    try {
        const response = await app.makeRequest(`${app.apiBase}/dead-links/check`, 'POST');
        app.showToast(`Checked ${response.data.checked} bookmarks, found ${response.data.dead} dead links`, 'info');

        // Refresh bookmarks to show updated status
        app.loadBookmarks();
    } catch (error) {
        app.handleError(error);
    }
};

/**
 * Show dead links report
 */
window.showDeadLinksReport = async function() {
    try {
        const response = await app.makeRequest(`${app.apiBase}/dead-links/report`);
        const deadLinks = response.data;

        const modal = document.getElementById('deadLinksModal');
        const container = modal.querySelector('.modal-body');

        if (deadLinks.length === 0) {
            container.innerHTML = '<p class="text-success"><i class="fas fa-check"></i> No dead links found!</p>';
        } else {
            const html = deadLinks.map(link => `
                <div class="dead-link-item border-bottom pb-2 mb-2">
                    <h6>${link.title}</h6>
                    <small class="text-muted">${link.url}</small><br>
                    <small class="text-danger">Status: ${link.status_code} - Last checked: ${link.last_checked}</small>
                </div>
            `).join('');
            container.innerHTML = html;
        }

        new bootstrap.Modal(modal).show();
    } catch (error) {
        app.handleError(error);
    }
};

/**
 * Generate invite code (admin only)
 */
window.generateInviteCode = async function() {
    try {
        const expiresAt = document.getElementById('inviteExpiresAt').value;
        const maxUses = document.getElementById('inviteMaxUses').value || 1;

        const response = await app.makeRequest(`${app.apiBase}/invite-codes/generate`, 'POST', {
            expires_at: expiresAt || null,
            max_uses: parseInt(maxUses)
        });

        app.showToast('Invite code generated successfully', 'success');
        loadInviteCodes();
    } catch (error) {
        app.handleError(error);
    }
};

/**
 * Load invite codes list (admin only)
 */
window.loadInviteCodes = async function() {
    try {
        const response = await app.makeRequest(`${app.apiBase}/invite-codes`);
        const codes = response.data;

        const container = document.getElementById('inviteCodesList');
        if (codes.length === 0) {
            container.innerHTML = '<p class="text-muted">No invite codes found</p>';
            return;
        }

        const html = codes.map(code => `
            <div class="invite-code-item border rounded p-3 mb-2">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <code class="fs-5">${code.code}</code><br>
                        <small class="text-muted">
                            Uses: ${code.used_count}/${code.max_uses || '∞'} |
                            Expires: ${code.expires_at || 'Never'} |
                            Status: ${code.is_active ? 'Active' : 'Inactive'}
                        </small>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="deactivateInviteCode(${code.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `).join('');

        container.innerHTML = html;
    } catch (error) {
        app.handleError(error);
    }
};

/**
 * Deactivate invite code
 */
window.deactivateInviteCode = async function(codeId) {
    if (!confirm('Are you sure you want to deactivate this invite code?')) return;

    try {
        await app.makeRequest(`${app.apiBase}/invite-codes/${codeId}`, 'DELETE');
        app.showToast('Invite code deactivated', 'success');
        loadInviteCodes();
    } catch (error) {
        app.handleError(error);
    }
};