<?php
/**
 * Modal Templates for OneBookNav
 */
?>

<!-- Login Modal -->
<div class="modal fade" id="loginModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Login</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="loginForm">
                    <div class="mb-3">
                        <label for="loginUsername" class="form-label">Username or Email</label>
                        <input type="text" class="form-control" id="loginUsername" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="loginPassword" class="form-label">Password</label>
                        <input type="password" class="form-control" id="loginPassword" name="password" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me">
                        <label class="form-check-label" for="rememberMe">Remember me</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="submitLogin()">Login</button>
                <?php if (ALLOW_REGISTRATION): ?>
                <button type="button" class="btn btn-link" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#registerModal">Register</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Register Modal -->
<?php if (ALLOW_REGISTRATION): ?>
<div class="modal fade" id="registerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Register</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="registerForm">
                    <div class="mb-3">
                        <label for="registerUsername" class="form-label">Username</label>
                        <input type="text" class="form-control" id="registerUsername" name="username" required>
                        <div class="form-text">3-50 characters, letters, numbers, underscore, and hyphen only</div>
                    </div>
                    <div class="mb-3">
                        <label for="registerEmail" class="form-label">Email (Optional)</label>
                        <input type="email" class="form-control" id="registerEmail" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="registerPassword" class="form-label">Password</label>
                        <input type="password" class="form-control" id="registerPassword" name="password" required>
                        <div class="form-text">Minimum 6 characters</div>
                    </div>
                    <div class="mb-3">
                        <label for="registerPasswordConfirm" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="registerPasswordConfirm" name="password_confirm" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="submitRegister()">Register</button>
                <button type="button" class="btn btn-link" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal">Login instead</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addCategoryForm">
                    <div class="mb-3">
                        <label for="categoryName" class="form-label">Name</label>
                        <input type="text" class="form-control" id="categoryName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="categoryParent" class="form-label">Parent Category</label>
                        <select class="form-select" id="categoryParent" name="parent_id">
                            <option value="">No parent (top level)</option>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="categoryIcon" class="form-label">Icon</label>
                        <input type="text" class="form-control" id="categoryIcon" name="icon" placeholder="fas fa-folder">
                        <div class="form-text">Font Awesome icon class (e.g., fas fa-folder, fas fa-star)</div>
                    </div>
                    <div class="mb-3">
                        <label for="categoryColor" class="form-label">Color</label>
                        <input type="color" class="form-control form-control-color" id="categoryColor" name="color" value="#007bff">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="categoryPrivate" name="is_private">
                        <label class="form-check-label" for="categoryPrivate">Private (only visible to you)</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="submitAddCategory()">Add Category</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Bookmark Modal -->
<div class="modal fade" id="addBookmarkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Bookmark</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addBookmarkForm">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="bookmarkTitle" class="form-label">Title</label>
                                <input type="text" class="form-control" id="bookmarkTitle" name="title" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="bookmarkCategory" class="form-label">Category</label>
                                <select class="form-select" id="bookmarkCategory" name="category_id" required>
                                    <!-- Options will be populated by JavaScript -->
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="bookmarkUrl" class="form-label">URL</label>
                        <input type="url" class="form-control" id="bookmarkUrl" name="url" required>
                    </div>
                    <div class="mb-3">
                        <label for="bookmarkDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="bookmarkDescription" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="bookmarkKeywords" class="form-label">Keywords</label>
                        <input type="text" class="form-control" id="bookmarkKeywords" name="keywords" placeholder="Comma-separated keywords">
                        <div class="form-text">e.g., development, tools, productivity</div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="bookmarkPrivate" name="is_private">
                        <label class="form-check-label" for="bookmarkPrivate">Private (only visible to you)</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="submitAddBookmark()">Add Bookmark</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Bookmark Modal -->
<div class="modal fade" id="editBookmarkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Bookmark</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editBookmarkForm">
                    <input type="hidden" id="editBookmarkId" name="bookmark_id">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="editBookmarkTitle" class="form-label">Title</label>
                                <input type="text" class="form-control" id="editBookmarkTitle" name="title" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="editBookmarkCategory" class="form-label">Category</label>
                                <select class="form-select" id="editBookmarkCategory" name="category_id" required>
                                    <!-- Options will be populated by JavaScript -->
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editBookmarkUrl" class="form-label">URL</label>
                        <input type="url" class="form-control" id="editBookmarkUrl" name="url" required>
                    </div>
                    <div class="mb-3">
                        <label for="editBookmarkDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="editBookmarkDescription" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="editBookmarkKeywords" class="form-label">Keywords</label>
                        <input type="text" class="form-control" id="editBookmarkKeywords" name="keywords">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="editBookmarkPrivate" name="is_private">
                        <label class="form-check-label" for="editBookmarkPrivate">Private (only visible to you)</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger me-auto" onclick="deleteBookmark()">Delete</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="submitEditBookmark()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">Profile</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">Password</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences" type="button" role="tab">Preferences</button>
                    </li>
                </ul>
                <div class="tab-content mt-3" id="settingsTabContent">
                    <!-- Profile Tab -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <form id="profileForm">
                            <div class="mb-3">
                                <label for="profileUsername" class="form-label">Username</label>
                                <input type="text" class="form-control" id="profileUsername" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="profileEmail" class="form-label">Email</label>
                                <input type="email" class="form-control" id="profileEmail" name="email">
                            </div>
                            <div class="mb-3">
                                <label for="profileAvatar" class="form-label">Avatar URL</label>
                                <input type="url" class="form-control" id="profileAvatar" name="avatar_url">
                            </div>
                        </form>
                    </div>

                    <!-- Password Tab -->
                    <div class="tab-pane fade" id="password" role="tabpanel">
                        <form id="passwordForm">
                            <div class="mb-3">
                                <label for="currentPassword" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="newPassword" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="newPassword" name="new_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                            </div>
                        </form>
                    </div>

                    <!-- Preferences Tab -->
                    <div class="tab-pane fade" id="preferences" role="tabpanel">
                        <form id="preferencesForm">
                            <div class="mb-3">
                                <label for="defaultView" class="form-label">Default View</label>
                                <select class="form-select" id="defaultView" name="default_view">
                                    <option value="grid">Grid</option>
                                    <option value="list">List</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="itemsPerPage" class="form-label">Items per Page</label>
                                <select class="form-select" id="itemsPerPage" name="items_per_page">
                                    <option value="12">12</option>
                                    <option value="24">24</option>
                                    <option value="48">48</option>
                                    <option value="96">96</option>
                                </select>
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="showDescriptions" name="show_descriptions">
                                <label class="form-check-label" for="showDescriptions">Show bookmark descriptions</label>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveSettings()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Backup & Import Modal -->
<div class="modal fade" id="backupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Backup & Import</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="backupTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="export-tab" data-bs-toggle="tab" data-bs-target="#export" type="button" role="tab">Export</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="import-tab" data-bs-toggle="tab" data-bs-target="#import" type="button" role="tab">Import</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="backups-tab" data-bs-toggle="tab" data-bs-target="#backups" type="button" role="tab">Backups</button>
                    </li>
                </ul>
                <div class="tab-content mt-3" id="backupTabContent">
                    <!-- Export Tab -->
                    <div class="tab-pane fade show active" id="export" role="tabpanel">
                        <div class="mb-3">
                            <label for="exportFormat" class="form-label">Export Format</label>
                            <select class="form-select" id="exportFormat">
                                <option value="json">JSON (OneBookNav format)</option>
                                <option value="html">HTML</option>
                                <option value="csv">CSV</option>
                                <option value="bookmarks_html">Browser Bookmarks HTML</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="exportPrivate" checked>
                            <label class="form-check-label" for="exportPrivate">Include private bookmarks</label>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="exportData()">
                            <i class="fas fa-download me-2"></i> Export Data
                        </button>
                    </div>

                    <!-- Import Tab -->
                    <div class="tab-pane fade" id="import" role="tabpanel">
                        <div class="mb-3">
                            <label for="importType" class="form-label">Import Type</label>
                            <select class="form-select" id="importType">
                                <option value="backup">OneBookNav Backup</option>
                                <option value="booknav">BookNav Database</option>
                                <option value="onenav">OneNav Database</option>
                                <option value="browser">Browser Bookmarks HTML</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="importFile" class="form-label">Select File</label>
                            <input type="file" class="form-control" id="importFile">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="importData()">
                            <i class="fas fa-upload me-2"></i> Import Data
                        </button>
                        <div id="importProgress" class="mt-3" style="display: none;">
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Backups Tab -->
                    <div class="tab-pane fade" id="backups" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6>Automatic Backups</h6>
                            <button type="button" class="btn btn-sm btn-primary" onclick="createBackup()">
                                <i class="fas fa-plus me-1"></i> Create Backup
                            </button>
                        </div>
                        <div id="backupsList">
                            <!-- Backup list will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Context Menu -->
<div id="contextMenu" class="context-menu" style="display: none;">
    <ul class="list-unstyled mb-0">
        <li><a href="#" class="context-item" data-action="open"><i class="fas fa-external-link-alt me-2"></i> Open</a></li>
        <li><a href="#" class="context-item" data-action="open-new"><i class="fas fa-external-link-alt me-2"></i> Open in New Tab</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a href="#" class="context-item" data-action="edit"><i class="fas fa-edit me-2"></i> Edit</a></li>
        <li><a href="#" class="context-item" data-action="copy"><i class="fas fa-copy me-2"></i> Copy URL</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a href="#" class="context-item text-danger" data-action="delete"><i class="fas fa-trash me-2"></i> Delete</a></li>
    </ul>
</div>