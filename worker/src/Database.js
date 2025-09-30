/**
 * OneBookNav Cloudflare Workers 数据库管理
 * D1 数据库操作封装
 */

export class Database {
    constructor(d1Database) {
        this.db = d1Database;
    }

    /**
     * 执行查询
     */
    async query(sql, params = []) {
        try {
            const stmt = this.db.prepare(sql);
            if (params.length > 0) {
                return await stmt.bind(...params).all();
            }
            return await stmt.all();
        } catch (error) {
            console.error('Database query error:', error);
            throw new Error('数据库查询失败');
        }
    }

    /**
     * 执行单条查询
     */
    async queryOne(sql, params = []) {
        try {
            const stmt = this.db.prepare(sql);
            if (params.length > 0) {
                return await stmt.bind(...params).first();
            }
            return await stmt.first();
        } catch (error) {
            console.error('Database queryOne error:', error);
            throw new Error('数据库查询失败');
        }
    }

    /**
     * 执行更新/插入/删除
     */
    async execute(sql, params = []) {
        try {
            const stmt = this.db.prepare(sql);
            if (params.length > 0) {
                return await stmt.bind(...params).run();
            }
            return await stmt.run();
        } catch (error) {
            console.error('Database execute error:', error);
            throw new Error('数据库操作失败');
        }
    }

    /**
     * 批量执行
     */
    async batch(statements) {
        try {
            const stmts = statements.map(({ sql, params = [] }) => {
                const stmt = this.db.prepare(sql);
                return params.length > 0 ? stmt.bind(...params) : stmt;
            });
            return await this.db.batch(stmts);
        } catch (error) {
            console.error('Database batch error:', error);
            throw new Error('批量数据库操作失败');
        }
    }

    /**
     * 获取用户信息
     */
    async getUser(id) {
        const sql = 'SELECT * FROM users WHERE id = ?';
        return await this.queryOne(sql, [id]);
    }

    /**
     * 根据邮箱获取用户
     */
    async getUserByEmail(email) {
        const sql = 'SELECT * FROM users WHERE email = ?';
        return await this.queryOne(sql, [email]);
    }

    /**
     * 根据用户名获取用户
     */
    async getUserByUsername(username) {
        const sql = 'SELECT * FROM users WHERE username = ?';
        return await this.queryOne(sql, [username]);
    }

    /**
     * 创建用户
     */
    async createUser(userData) {
        const { username, email, password_hash, role = 'user' } = userData;
        const sql = `
            INSERT INTO users (username, email, password_hash, role, created_at, updated_at)
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
        `;
        return await this.execute(sql, [username, email, password_hash, role]);
    }

    /**
     * 更新用户最后登录时间
     */
    async updateUserLastLogin(userId) {
        const sql = 'UPDATE users SET last_login_at = datetime(\'now\') WHERE id = ?';
        return await this.execute(sql, [userId]);
    }

    /**
     * 获取所有书签
     */
    async getAllBookmarks() {
        const sql = `
            SELECT b.*, c.name as category_name, u.username
            FROM bookmarks b
            LEFT JOIN categories c ON b.category_id = c.id
            LEFT JOIN users u ON b.user_id = u.id
            ORDER BY b.updated_at DESC
        `;
        const result = await this.query(sql);
        return result.results || [];
    }

    /**
     * 获取用户书签
     */
    async getUserBookmarks(userId, options = {}) {
        const { categoryId, search, limit = 20, offset = 0 } = options;

        let sql = `
            SELECT b.*, c.name as category_name
            FROM bookmarks b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.user_id = ?
        `;
        const params = [userId];

        if (categoryId) {
            sql += ' AND b.category_id = ?';
            params.push(categoryId);
        }

        if (search) {
            sql += ' AND (b.title LIKE ? OR b.description LIKE ? OR b.url LIKE ?)';
            const searchTerm = `%${search}%`;
            params.push(searchTerm, searchTerm, searchTerm);
        }

        sql += ' ORDER BY b.updated_at DESC LIMIT ? OFFSET ?';
        params.push(limit, offset);

        const result = await this.query(sql, params);
        return result.results || [];
    }

    /**
     * 获取书签详情
     */
    async getBookmark(id) {
        const sql = `
            SELECT b.*, c.name as category_name
            FROM bookmarks b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.id = ?
        `;
        return await this.queryOne(sql, [id]);
    }

    /**
     * 创建书签
     */
    async createBookmark(bookmarkData) {
        const {
            title, url, description = '', category_id = null,
            tags = '[]', icon_url = '', user_id
        } = bookmarkData;

        const sql = `
            INSERT INTO bookmarks (title, url, description, category_id, tags, icon_url, user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        `;
        return await this.execute(sql, [title, url, description, category_id, tags, icon_url, user_id]);
    }

    /**
     * 更新书签
     */
    async updateBookmark(id, bookmarkData) {
        const fields = [];
        const params = [];

        Object.keys(bookmarkData).forEach(key => {
            if (bookmarkData[key] !== undefined) {
                fields.push(`${key} = ?`);
                params.push(bookmarkData[key]);
            }
        });

        if (fields.length === 0) {
            throw new Error('没有要更新的字段');
        }

        fields.push('updated_at = datetime(\'now\')');
        params.push(id);

        const sql = `UPDATE bookmarks SET ${fields.join(', ')} WHERE id = ?`;
        return await this.execute(sql, params);
    }

    /**
     * 删除书签
     */
    async deleteBookmark(id) {
        const sql = 'DELETE FROM bookmarks WHERE id = ?';
        return await this.execute(sql, [id]);
    }

    /**
     * 更新书签状态（死链检测）
     */
    async updateBookmarkStatus(id, isAlive) {
        const sql = `
            UPDATE bookmarks
            SET is_alive = ?, last_checked_at = datetime('now')
            WHERE id = ?
        `;
        return await this.execute(sql, [isAlive ? 1 : 0, id]);
    }

    /**
     * 增加书签点击数
     */
    async incrementBookmarkClick(id) {
        const sql = `
            UPDATE bookmarks
            SET click_count = click_count + 1, last_clicked_at = datetime('now')
            WHERE id = ?
        `;
        return await this.execute(sql, [id]);
    }

    /**
     * 获取分类列表
     */
    async getCategories(userId = null) {
        let sql = `
            SELECT c.*, COUNT(b.id) as bookmark_count
            FROM categories c
            LEFT JOIN bookmarks b ON c.id = b.category_id
        `;
        const params = [];

        if (userId) {
            sql += ' WHERE c.user_id = ? OR c.user_id IS NULL';
            params.push(userId);
        }

        sql += ' GROUP BY c.id ORDER BY c.sort_order ASC, c.name ASC';

        const result = await this.query(sql, params);
        return result.results || [];
    }

    /**
     * 获取分类详情
     */
    async getCategory(id) {
        const sql = 'SELECT * FROM categories WHERE id = ?';
        return await this.queryOne(sql, [id]);
    }

    /**
     * 创建分类
     */
    async createCategory(categoryData) {
        const {
            name, description = '', icon = '', color = '#007bff',
            parent_id = null, user_id, sort_order = 0
        } = categoryData;

        const sql = `
            INSERT INTO categories (name, description, icon, color, parent_id, user_id, sort_order, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        `;
        return await this.execute(sql, [name, description, icon, color, parent_id, user_id, sort_order]);
    }

    /**
     * 更新分类
     */
    async updateCategory(id, categoryData) {
        const fields = [];
        const params = [];

        Object.keys(categoryData).forEach(key => {
            if (categoryData[key] !== undefined) {
                fields.push(`${key} = ?`);
                params.push(categoryData[key]);
            }
        });

        if (fields.length === 0) {
            throw new Error('没有要更新的字段');
        }

        fields.push('updated_at = datetime(\'now\')');
        params.push(id);

        const sql = `UPDATE categories SET ${fields.join(', ')} WHERE id = ?`;
        return await this.execute(sql, params);
    }

    /**
     * 删除分类
     */
    async deleteCategory(id) {
        // 先检查是否有书签使用此分类
        const bookmarkCount = await this.queryOne(
            'SELECT COUNT(*) as count FROM bookmarks WHERE category_id = ?',
            [id]
        );

        if (bookmarkCount.count > 0) {
            throw new Error('该分类下还有书签，无法删除');
        }

        const sql = 'DELETE FROM categories WHERE id = ?';
        return await this.execute(sql, [id]);
    }

    /**
     * 创建会话
     */
    async createSession(sessionData) {
        const { session_id, user_id, expires_at, data = '{}' } = sessionData;
        const sql = `
            INSERT INTO sessions (session_id, user_id, expires_at, data, created_at)
            VALUES (?, ?, ?, ?, datetime('now'))
        `;
        return await this.execute(sql, [session_id, user_id, expires_at, data]);
    }

    /**
     * 获取会话
     */
    async getSession(sessionId) {
        const sql = `
            SELECT s.*, u.username, u.email, u.role
            FROM sessions s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.session_id = ? AND s.expires_at > datetime('now')
        `;
        return await this.queryOne(sql, [sessionId]);
    }

    /**
     * 删除会话
     */
    async deleteSession(sessionId) {
        const sql = 'DELETE FROM sessions WHERE session_id = ?';
        return await this.execute(sql, [sessionId]);
    }

    /**
     * 清理过期会话
     */
    async cleanupExpiredSessions() {
        const sql = 'DELETE FROM sessions WHERE expires_at <= datetime(\'now\')';
        return await this.execute(sql);
    }

    /**
     * 导出所有数据
     */
    async exportData() {
        const tables = ['users', 'categories', 'bookmarks', 'sessions'];
        const data = {};

        for (const table of tables) {
            const result = await this.query(`SELECT * FROM ${table}`);
            data[table] = result.results || [];
        }

        return {
            exported_at: new Date().toISOString(),
            version: '1.0.0',
            tables: data
        };
    }

    /**
     * 获取统计信息
     */
    async getStats(userId = null) {
        const stats = {};

        // 总书签数
        let bookmarkSql = 'SELECT COUNT(*) as count FROM bookmarks';
        const bookmarkParams = [];
        if (userId) {
            bookmarkSql += ' WHERE user_id = ?';
            bookmarkParams.push(userId);
        }
        const bookmarkCount = await this.queryOne(bookmarkSql, bookmarkParams);
        stats.bookmarks = bookmarkCount.count;

        // 总分类数
        let categorySql = 'SELECT COUNT(*) as count FROM categories';
        const categoryParams = [];
        if (userId) {
            categorySql += ' WHERE user_id = ? OR user_id IS NULL';
            categoryParams.push(userId);
        }
        const categoryCount = await this.queryOne(categorySql, categoryParams);
        stats.categories = categoryCount.count;

        // 死链数
        let deadLinkSql = 'SELECT COUNT(*) as count FROM bookmarks WHERE is_alive = 0';
        const deadLinkParams = [];
        if (userId) {
            deadLinkSql += ' AND user_id = ?';
            deadLinkParams.push(userId);
        }
        const deadLinkCount = await this.queryOne(deadLinkSql, deadLinkParams);
        stats.deadLinks = deadLinkCount.count;

        // 最近添加的书签
        let recentSql = `
            SELECT COUNT(*) as count FROM bookmarks
            WHERE created_at > datetime('now', '-7 days')
        `;
        const recentParams = [];
        if (userId) {
            recentSql += ' AND user_id = ?';
            recentParams.push(userId);
        }
        const recentCount = await this.queryOne(recentSql, recentParams);
        stats.recentBookmarks = recentCount.count;

        return stats;
    }

    /**
     * 检查数据库连接
     */
    async checkConnection() {
        try {
            await this.queryOne('SELECT 1 as test');
            return true;
        } catch (error) {
            console.error('Database connection check failed:', error);
            return false;
        }
    }
}