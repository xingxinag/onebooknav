/**
 * OneBookNav Enhanced - Cloudflare Workers Edition (Fixed)
 * 修复了认证和API问题的完整版本
 */

// 响应工具函数
function jsonResponse(data, status = 200, headers = {}) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization',
      ...headers
    }
  });
}

function htmlResponse(html, status = 200) {
  return new Response(html, {
    status,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Access-Control-Allow-Origin': '*'
    }
  });
}

// 简单可靠的密码处理
async function hashPassword(password) {
  // 使用简单的SHA-256哈希
  const encoder = new TextEncoder();
  const data = encoder.encode(password);
  const hash = await crypto.subtle.digest('SHA-256', data);
  return Array.from(new Uint8Array(hash))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}

async function verifyPassword(inputPassword, storedPassword) {
  if (!inputPassword || !storedPassword) {
    console.log('Password verification failed: missing input or stored password');
    return false;
  }

  // 简单粗暴：支持明文密码比较（兼容调试）
  if (inputPassword === storedPassword) {
    console.log('Password verified: direct plaintext match');
    return true;
  }

  // 支持SHA-256哈希比较
  const hashedInput = await hashPassword(inputPassword);
  if (hashedInput === storedPassword) {
    console.log('Password verified: SHA-256 hash match');
    return true;
  }

  console.log('Password verification failed:', { inputPassword, storedPassword, hashedInput });
  return false;
}

// 会话令牌管理
function generateToken(userId, username, role = 'user') {
  const payload = {
    userId,
    username,
    role,
    iat: Math.floor(Date.now() / 1000),
    exp: Math.floor(Date.now() / 1000) + (24 * 60 * 60) // 24小时有效
  };
  return btoa(JSON.stringify(payload));
}

function verifyToken(token) {
  try {
    if (!token) return null;
    const payload = JSON.parse(atob(token));
    if (payload.exp < Math.floor(Date.now() / 1000)) return null;
    return payload;
  } catch {
    return null;
  }
}

// 获取当前用户
async function getCurrentUser(request, env) {
  const authHeader = request.headers.get('Authorization');
  if (!authHeader) return null;

  const token = authHeader.replace('Bearer ', '');
  const payload = verifyToken(token);
  if (!payload) return null;

  try {
    const user = await env.DB.prepare(
      'SELECT id, username, email, role, is_active FROM users WHERE id = ? AND is_active = 1'
    ).bind(payload.userId).first();

    return user;
  } catch (error) {
    console.error('Get current user error:', error);
    return null;
  }
}

// 数据库初始化
async function initializeDatabase(env) {
  const statements = [
    // 用户表
    `CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT UNIQUE NOT NULL,
      email TEXT UNIQUE,
      password_hash TEXT NOT NULL,
      role TEXT DEFAULT 'user' CHECK (role IN ('user', 'admin', 'superadmin')),
      is_active INTEGER DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      last_login DATETIME
    )`,

    // 分类表
    `CREATE TABLE IF NOT EXISTS categories (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      parent_id INTEGER,
      user_id INTEGER NOT NULL,
      icon TEXT DEFAULT 'fas fa-folder',
      color TEXT DEFAULT '#007bff',
      weight INTEGER DEFAULT 0,
      is_private INTEGER DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (parent_id) REFERENCES categories(id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    )`,

    // 书签表
    `CREATE TABLE IF NOT EXISTS bookmarks (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      url TEXT NOT NULL,
      backup_url TEXT,
      description TEXT,
      keywords TEXT,
      tags TEXT,
      icon_url TEXT,
      category_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      weight INTEGER DEFAULT 0,
      sort_order INTEGER DEFAULT 0,
      click_count INTEGER DEFAULT 0,
      is_private INTEGER DEFAULT 0,
      is_featured INTEGER DEFAULT 0,
      last_checked DATETIME,
      status_code INTEGER,
      is_working INTEGER DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (category_id) REFERENCES categories(id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    )`,

    // 邀请码表
    `CREATE TABLE IF NOT EXISTS invite_codes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT UNIQUE NOT NULL,
      created_by INTEGER NOT NULL,
      used_by INTEGER,
      expires_at DATETIME,
      max_uses INTEGER DEFAULT 1,
      used_count INTEGER DEFAULT 0,
      is_active INTEGER DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      used_at DATETIME,
      FOREIGN KEY (created_by) REFERENCES users(id),
      FOREIGN KEY (used_by) REFERENCES users(id)
    )`,

    // 点击日志表
    `CREATE TABLE IF NOT EXISTS click_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      bookmark_id INTEGER NOT NULL,
      user_id INTEGER,
      ip_address TEXT,
      user_agent TEXT,
      clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    )`,

    // 系统设置表
    `CREATE TABLE IF NOT EXISTS settings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      key TEXT UNIQUE NOT NULL,
      value TEXT,
      type TEXT DEFAULT 'string',
      description TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`
  ];

  try {
    for (const statement of statements) {
      await env.DB.prepare(statement).run();
    }

    // 检查是否存在默认管理员用户
    const adminExists = await env.DB.prepare(
      'SELECT id FROM users WHERE username = ? OR role = ?'
    ).bind('admin', 'superadmin').first();

    if (!adminExists) {
      // 创建默认管理员用户
      const defaultPassword = env.DEFAULT_ADMIN_PASSWORD || 'admin679';

      console.log('Creating admin user...');
      console.log('Username: admin');
      console.log('Password:', defaultPassword);

      // 先尝试明文密码存储（便于调试）
      await env.DB.prepare(`
        INSERT INTO users (username, email, password_hash, role)
        VALUES (?, ?, ?, ?)
      `).bind('admin', 'admin@example.com', defaultPassword, 'superadmin').run();

      console.log('Admin user created successfully');

      // 创建默认分类
      const categoryResult = await env.DB.prepare(`
        INSERT INTO categories (name, user_id, icon, color, weight)
        VALUES (?, ?, ?, ?, ?)
      `).bind('默认分类', 1, 'fas fa-star', '#ffd700', 1).run();

      // 创建示例书签
      await env.DB.prepare(`
        INSERT INTO bookmarks (title, url, description, category_id, user_id, weight)
        VALUES (?, ?, ?, ?, ?, ?)
      `).bind('GitHub', 'https://github.com', '全球最大的代码托管平台',
              categoryResult.meta.last_row_id, 1, 1).run();
    }

  } catch (error) {
    console.error('Database initialization error:', error);
    throw error;
  }
}

// 主页面HTML
function getHomePage() {
  return `<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneBookNav Enhanced</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .bookmark-card { transition: all 0.3s ease; cursor: pointer; }
        .bookmark-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .category-header { background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); }
        .search-container { backdrop-filter: blur(15px); background: rgba(255,255,255,0.1); }
        .nav-container { backdrop-filter: blur(15px); background: rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark nav-container sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#"><i class="fas fa-bookmark me-2"></i>OneBookNav Enhanced</a>
            <div class="navbar-nav ms-auto">
                <button class="btn btn-outline-light me-2" onclick="showLoginModal()">
                    <i class="fas fa-sign-in-alt me-1"></i>登录
                </button>
                <button class="btn btn-light" onclick="showAddModal()">
                    <i class="fas fa-plus me-1"></i>添加书签
                </button>
            </div>
        </div>
    </nav>

    <!-- 搜索区域 -->
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="search-container rounded-3 p-4 mb-4">
                    <div class="input-group input-group-lg">
                        <input type="text" class="form-control" id="searchInput" placeholder="智能搜索您的书签...">
                        <button class="btn btn-primary" type="button" onclick="performSearch()">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 主内容区域 -->
    <div class="container">
        <div id="bookmarkContainer"></div>
    </div>

    <!-- 登录模态框 -->
    <div class="modal fade" id="loginModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">用户登录</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="loginForm">
                        <div class="mb-3">
                            <label class="form-label">用户名</label>
                            <input type="text" class="form-control" id="loginUsername" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">密码</label>
                            <input type="password" class="form-control" id="loginPassword" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="performLogin()">登录</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 添加书签模态框 -->
    <div class="modal fade" id="addModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">添加新书签</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addForm">
                        <div class="mb-3">
                            <label class="form-label">标题 *</label>
                            <input type="text" class="form-control" id="addTitle" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL *</label>
                            <input type="url" class="form-control" id="addUrl" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">备用URL</label>
                            <input type="url" class="form-control" id="addBackupUrl">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea class="form-control" id="addDescription" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">标签 (逗号分隔)</label>
                            <input type="text" class="form-control" id="addTags" placeholder="标签1, 标签2, 标签3">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">分类</label>
                            <select class="form-select" id="addCategory" required>
                                <option value="">选择分类...</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="addBookmark()">添加</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentUser = null;
        let authToken = localStorage.getItem('authToken');

        // 页面初始化
        document.addEventListener('DOMContentLoaded', function() {
            checkAuth();
            loadBookmarks();
            loadCategories();
        });

        // 检查认证状态
        async function checkAuth() {
            if (authToken) {
                try {
                    const response = await fetch('/api/auth/me', {
                        headers: { 'Authorization': 'Bearer ' + authToken }
                    });
                    if (response.ok) {
                        currentUser = await response.json();
                        updateUI();
                    } else {
                        localStorage.removeItem('authToken');
                        authToken = null;
                    }
                } catch (error) {
                    console.error('Auth check failed:', error);
                }
            }
        }

        // 更新UI状态
        function updateUI() {
            const navButtons = document.querySelector('.navbar-nav');
            if (currentUser) {
                navButtons.innerHTML = \`
                    <span class="navbar-text me-3">欢迎, \${currentUser.username}</span>
                    <button class="btn btn-outline-light me-2" onclick="logout()">登出</button>
                    <button class="btn btn-light" onclick="showAddModal()">
                        <i class="fas fa-plus me-1"></i>添加书签
                    </button>
                \`;
            }
        }

        // 登录
        async function performLogin() {
            const username = document.getElementById('loginUsername').value;
            const password = document.getElementById('loginPassword').value;

            try {
                const response = await fetch('/api/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });

                const result = await response.json();
                if (result.success) {
                    authToken = result.token;
                    localStorage.setItem('authToken', authToken);
                    currentUser = result.user;
                    updateUI();
                    bootstrap.Modal.getInstance(document.getElementById('loginModal')).hide();
                    loadBookmarks();
                } else {
                    alert('登录失败: ' + result.error);
                }
            } catch (error) {
                console.error('Login error:', error);
                alert('登录请求失败');
            }
        }

        // 登出
        function logout() {
            localStorage.removeItem('authToken');
            authToken = null;
            currentUser = null;
            location.reload();
        }

        // 显示登录模态框
        function showLoginModal() {
            new bootstrap.Modal(document.getElementById('loginModal')).show();
        }

        // 显示添加书签模态框
        function showAddModal() {
            if (!currentUser) {
                showLoginModal();
                return;
            }
            new bootstrap.Modal(document.getElementById('addModal')).show();
        }

        // 加载书签
        async function loadBookmarks(searchQuery = '') {
            try {
                let url = '/api/bookmarks';
                if (searchQuery) {
                    url += '?search=' + encodeURIComponent(searchQuery);
                }

                const headers = {};
                if (authToken) {
                    headers['Authorization'] = 'Bearer ' + authToken;
                }

                const response = await fetch(url, { headers });
                const result = await response.json();

                if (result.success) {
                    displayBookmarks(result.data);
                }
            } catch (error) {
                console.error('Load bookmarks error:', error);
            }
        }

        // 显示书签
        function displayBookmarks(bookmarks) {
            const container = document.getElementById('bookmarkContainer');

            if (!bookmarks || bookmarks.length === 0) {
                container.innerHTML = '<div class="text-center text-white mt-5"><h4>暂无书签</h4></div>';
                return;
            }

            // 按分类分组
            const categories = {};
            bookmarks.forEach(bookmark => {
                const categoryName = bookmark.category_name || '未分类';
                if (!categories[categoryName]) {
                    categories[categoryName] = [];
                }
                categories[categoryName].push(bookmark);
            });

            let html = '';
            Object.entries(categories).forEach(([categoryName, bookmarks]) => {
                html += \`
                    <div class="mb-4">
                        <div class="category-header rounded-3 p-3 mb-3">
                            <h5 class="text-white mb-0">
                                <i class="fas fa-folder me-2"></i>\${categoryName}
                                <span class="badge bg-light text-dark ms-2">\${bookmarks.length}</span>
                            </h5>
                        </div>
                        <div class="row">
                \`;

                bookmarks.forEach(bookmark => {
                    html += \`
                        <div class="col-md-4 col-lg-3 mb-3">
                            <div class="card bookmark-card h-100" onclick="openBookmark('\${bookmark.url}', \${bookmark.id})">
                                <div class="card-body">
                                    <h6 class="card-title">\${bookmark.title}</h6>
                                    <p class="card-text text-muted small">\${bookmark.description || ''}</p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="fas fa-mouse-pointer me-1"></i>\${bookmark.click_count || 0}
                                        </small>
                                        \${bookmark.backup_url ? '<small class="text-warning"><i class="fas fa-backup me-1"></i>备用</small>' : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    \`;
                });

                html += '</div></div>';
            });

            container.innerHTML = html;
        }

        // 打开书签
        async function openBookmark(url, bookmarkId) {
            // 记录点击
            if (authToken && bookmarkId) {
                try {
                    await fetch('/api/bookmarks/click', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + authToken
                        },
                        body: JSON.stringify({ bookmark_id: bookmarkId })
                    });
                } catch (error) {
                    console.error('Click tracking error:', error);
                }
            }

            window.open(url, '_blank');
        }

        // 搜索
        function performSearch() {
            const query = document.getElementById('searchInput').value.trim();
            loadBookmarks(query);
        }

        // 回车搜索
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });

        // 加载分类到下拉框
        async function loadCategories() {
            if (!authToken) return;

            try {
                const response = await fetch('/api/categories', {
                    headers: { 'Authorization': 'Bearer ' + authToken }
                });
                const result = await response.json();

                if (result.success) {
                    const select = document.getElementById('addCategory');
                    select.innerHTML = '<option value="">选择分类...</option>';

                    result.data.forEach(category => {
                        select.innerHTML += \`<option value="\${category.id}">\${category.name}</option>\`;
                    });
                }
            } catch (error) {
                console.error('Load categories error:', error);
            }
        }

        // 添加书签
        async function addBookmark() {
            if (!authToken) {
                showLoginModal();
                return;
            }

            const title = document.getElementById('addTitle').value.trim();
            const url = document.getElementById('addUrl').value.trim();
            const backupUrl = document.getElementById('addBackupUrl').value.trim();
            const description = document.getElementById('addDescription').value.trim();
            const tags = document.getElementById('addTags').value.trim();
            const categoryId = document.getElementById('addCategory').value;

            if (!title || !url || !categoryId) {
                alert('请填写必填字段');
                return;
            }

            try {
                const response = await fetch('/api/bookmarks', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + authToken
                    },
                    body: JSON.stringify({
                        title, url, backup_url: backupUrl, description, tags, category_id: categoryId
                    })
                });

                const result = await response.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();
                    document.getElementById('addForm').reset();
                    loadBookmarks();
                } else {
                    alert('添加失败: ' + result.error);
                }
            } catch (error) {
                console.error('Add bookmark error:', error);
                alert('添加请求失败');
            }
        }
    </script>
</body>
</html>`;
}

// 认证处理
async function handleAuth(request, env, path) {
  const method = request.method;

  if (path === '/api/auth/login') {
    if (method !== 'POST') {
      return jsonResponse({ success: false, error: 'Method not allowed' }, 405);
    }

    try {
      const { username, password } = await request.json();

      if (!username || !password) {
        return jsonResponse({ success: false, error: 'Username and password required' }, 400);
      }

      // 查找用户
      const user = await env.DB.prepare(
        'SELECT id, username, email, password_hash, role, is_active FROM users WHERE username = ? AND is_active = 1'
      ).bind(username).first();

      if (!user) {
        return jsonResponse({ success: false, error: 'Invalid credentials' }, 401);
      }

      // 验证密码
      console.log('Login attempt:');
      console.log('- Username:', username);
      console.log('- Password:', password);
      console.log('User found:');
      console.log('- ID:', user.id);
      console.log('- Username:', user.username);
      console.log('- Stored password:', user.password_hash);

      const isValid = await verifyPassword(password, user.password_hash);
      console.log('Password verification result:', isValid);

      if (!isValid) {
        console.log('Login failed: Invalid credentials');
        return jsonResponse({
          success: false,
          error: 'Invalid credentials',
          debug: {
            username_provided: username,
            password_provided: password,
            stored_password: user.password_hash,
            verification_result: isValid
          }
        }, 401);
      }

      // 更新最后登录时间
      await env.DB.prepare(
        'UPDATE users SET last_login = datetime("now") WHERE id = ?'
      ).bind(user.id).run();

      // 生成令牌
      const token = generateToken(user.id, user.username, user.role);

      return jsonResponse({
        success: true,
        token,
        user: {
          id: user.id,
          username: user.username,
          email: user.email,
          role: user.role
        }
      });

    } catch (error) {
      console.error('Login error:', error);
      return jsonResponse({ success: false, error: 'Login failed' }, 500);
    }
  }

  if (path === '/api/auth/me') {
    const user = await getCurrentUser(request, env);
    if (!user) {
      return jsonResponse({ success: false, error: 'Not authenticated' }, 401);
    }

    return jsonResponse({
      success: true,
      user: {
        id: user.id,
        username: user.username,
        email: user.email,
        role: user.role
      }
    });
  }

  return jsonResponse({ success: false, error: 'Not found' }, 404);
}

// 书签处理
async function handleBookmarks(request, env) {
  const method = request.method;
  const url = new URL(request.url);
  const searchQuery = url.searchParams.get('search');

  if (method === 'GET') {
    try {
      let sql = `
        SELECT b.*, c.name as category_name, c.color as category_color
        FROM bookmarks b
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE (b.is_private = 0 OR b.user_id = ?)
      `;

      const user = await getCurrentUser(request, env);
      const userId = user ? user.id : 0;

      if (searchQuery && searchQuery.trim()) {
        sql += ` AND (
          b.title LIKE ? OR
          b.description LIKE ? OR
          b.keywords LIKE ? OR
          b.tags LIKE ? OR
          c.name LIKE ?
        )`;
      }

      sql += ` ORDER BY c.weight DESC, b.weight DESC, b.created_at DESC`;

      let params = [userId];
      if (searchQuery && searchQuery.trim()) {
        const searchTerm = `%${searchQuery.trim()}%`;
        params.push(searchTerm, searchTerm, searchTerm, searchTerm, searchTerm);
      }

      const result = await env.DB.prepare(sql).bind(...params).all();

      return jsonResponse({
        success: true,
        data: result.results || []
      });

    } catch (error) {
      console.error('Get bookmarks error:', error);
      return jsonResponse({ success: false, error: 'Failed to fetch bookmarks' }, 500);
    }
  }

  if (method === 'POST') {
    const user = await getCurrentUser(request, env);
    if (!user) {
      return jsonResponse({ success: false, error: 'Authentication required' }, 401);
    }

    try {
      const { title, url, backup_url, description, keywords, tags, category_id, is_private } = await request.json();

      if (!title || !url || !category_id) {
        return jsonResponse({ success: false, error: 'Title, URL and category are required' }, 400);
      }

      // 验证分类是否存在且属于用户
      const category = await env.DB.prepare(
        'SELECT id FROM categories WHERE id = ? AND (user_id = ? OR is_private = 0)'
      ).bind(category_id, user.id).first();

      if (!category) {
        return jsonResponse({ success: false, error: 'Invalid category' }, 400);
      }

      const result = await env.DB.prepare(`
        INSERT INTO bookmarks (
          title, url, backup_url, description, keywords, tags,
          category_id, user_id, is_private, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now"), datetime("now"))
      `).bind(
        title, url, backup_url || null, description || null,
        keywords || null, tags || null, category_id, user.id,
        is_private ? 1 : 0
      ).run();

      return jsonResponse({
        success: true,
        message: 'Bookmark added successfully',
        bookmark_id: result.meta.last_row_id
      });

    } catch (error) {
      console.error('Add bookmark error:', error);
      return jsonResponse({ success: false, error: 'Failed to add bookmark' }, 500);
    }
  }

  return jsonResponse({ success: false, error: 'Method not allowed' }, 405);
}

// 分类处理
async function handleCategories(request, env) {
  const user = await getCurrentUser(request, env);
  if (!user) {
    return jsonResponse({ success: false, error: 'Authentication required' }, 401);
  }

  const method = request.method;

  if (method === 'GET') {
    try {
      const result = await env.DB.prepare(`
        SELECT c.*, COUNT(b.id) as bookmark_count
        FROM categories c
        LEFT JOIN bookmarks b ON c.id = b.category_id AND (b.is_private = 0 OR b.user_id = ?)
        WHERE c.is_private = 0 OR c.user_id = ?
        GROUP BY c.id
        ORDER BY c.weight DESC, c.name ASC
      `).bind(user.id, user.id).all();

      return jsonResponse({
        success: true,
        data: result.results || []
      });

    } catch (error) {
      console.error('Get categories error:', error);
      return jsonResponse({ success: false, error: 'Failed to fetch categories' }, 500);
    }
  }

  return jsonResponse({ success: false, error: 'Method not allowed' }, 405);
}

// 点击追踪
async function handleClick(request, env) {
  const user = await getCurrentUser(request, env);
  if (!user) {
    return jsonResponse({ success: false, error: 'Authentication required' }, 401);
  }

  try {
    const { bookmark_id } = await request.json();

    if (!bookmark_id) {
      return jsonResponse({ success: false, error: 'Bookmark ID required' }, 400);
    }

    // 更新点击计数
    await env.DB.prepare(
      'UPDATE bookmarks SET click_count = click_count + 1 WHERE id = ?'
    ).bind(bookmark_id).run();

    // 记录点击日志
    const clientIP = request.headers.get('CF-Connecting-IP') || 'unknown';
    const userAgent = request.headers.get('User-Agent') || 'unknown';

    await env.DB.prepare(`
      INSERT INTO click_logs (bookmark_id, user_id, ip_address, user_agent, clicked_at)
      VALUES (?, ?, ?, ?, datetime("now"))
    `).bind(bookmark_id, user.id, clientIP, userAgent).run();

    return jsonResponse({ success: true, message: 'Click recorded' });

  } catch (error) {
    console.error('Click tracking error:', error);
    return jsonResponse({ success: false, error: 'Failed to record click' }, 500);
  }
}

// 主处理函数
export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const path = url.pathname;

    // 处理 CORS 预检请求
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type, Authorization',
        }
      });
    }

    try {
      // 初始化数据库
      await initializeDatabase(env);

      // 路由处理
      if (path === '/' || path === '/index.html') {
        return htmlResponse(getHomePage());
      }

      if (path.startsWith('/api/auth/')) {
        return handleAuth(request, env, path);
      }

      if (path === '/api/bookmarks') {
        return handleBookmarks(request, env);
      }

      if (path === '/api/bookmarks/click') {
        return handleClick(request, env);
      }

      if (path === '/api/categories') {
        return handleCategories(request, env);
      }

      // 404 处理
      return jsonResponse({ success: false, error: 'Not found' }, 404);

    } catch (error) {
      console.error('Request handling error:', error);
      return jsonResponse({
        success: false,
        error: 'Internal server error',
        details: error.message
      }, 500);
    }
  }
};