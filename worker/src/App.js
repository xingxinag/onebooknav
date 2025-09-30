/**
 * OneBookNav Cloudflare Workers 应用核心类
 * 统一核心，多态适配 - Workers 边缘计算适配层
 */

export class OneBookNavApp {
    constructor(env, ctx) {
        this.env = env;
        this.ctx = ctx;
        this.database = null;
        this.cache = null;
    }

    /**
     * 获取书签列表
     */
    async getBookmarks(request, user) {
        try {
            const url = new URL(request.url);
            const categoryId = url.searchParams.get('category_id');
            const search = url.searchParams.get('search');
            const page = parseInt(url.searchParams.get('page')) || 1;
            const limit = parseInt(url.searchParams.get('limit')) || 20;

            // 从D1数据库获取书签
            const query = this.buildBookmarksQuery(categoryId, search, user.id);
            const offset = (page - 1) * limit;

            const stmt = this.env.ONEBOOKNAV_DB.prepare(query + ` LIMIT ${limit} OFFSET ${offset}`);
            const results = await stmt.all();

            // 获取总数
            const countStmt = this.env.ONEBOOKNAV_DB.prepare(
                this.buildBookmarksCountQuery(categoryId, search, user.id)
            );
            const countResult = await countStmt.first();

            return new Response(JSON.stringify({
                success: true,
                data: {
                    bookmarks: results.results || [],
                    pagination: {
                        page,
                        limit,
                        total: countResult.count || 0,
                        pages: Math.ceil((countResult.count || 0) / limit)
                    }
                }
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Get bookmarks error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '获取书签失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 创建书签
     */
    async createBookmark(request, user) {
        try {
            const data = await request.json();
            const { title, url, description, category_id, tags, icon_url } = data;

            // 验证必填字段
            if (!title || !url) {
                return new Response(JSON.stringify({
                    success: false,
                    error: '标题和URL是必填的'
                }), {
                    status: 400,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            // 插入书签
            const stmt = this.env.ONEBOOKNAV_DB.prepare(`
                INSERT INTO bookmarks (title, url, description, category_id, tags, icon_url, user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            `);

            const result = await stmt.bind(
                title,
                url,
                description || '',
                category_id || null,
                JSON.stringify(tags || []),
                icon_url || '',
                user.id
            ).run();

            // 更新缓存
            await this.invalidateBookmarksCache(user.id);

            return new Response(JSON.stringify({
                success: true,
                data: {
                    id: result.meta.last_row_id,
                    title,
                    url,
                    description,
                    category_id,
                    tags,
                    icon_url,
                    created_at: new Date().toISOString()
                }
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Create bookmark error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '创建书签失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 更新书签
     */
    async updateBookmark(request, user) {
        try {
            const url = new URL(request.url);
            const id = url.pathname.split('/').pop();
            const data = await request.json();

            // 检查书签是否属于当前用户
            const checkStmt = this.env.ONEBOOKNAV_DB.prepare(
                'SELECT id FROM bookmarks WHERE id = ? AND user_id = ?'
            );
            const existing = await checkStmt.bind(id, user.id).first();

            if (!existing) {
                return new Response(JSON.stringify({
                    success: false,
                    error: '书签不存在或无权限'
                }), {
                    status: 404,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            // 更新书签
            const updateFields = [];
            const values = [];

            ['title', 'url', 'description', 'category_id', 'icon_url'].forEach(field => {
                if (data[field] !== undefined) {
                    updateFields.push(`${field} = ?`);
                    values.push(data[field]);
                }
            });

            if (data.tags !== undefined) {
                updateFields.push('tags = ?');
                values.push(JSON.stringify(data.tags));
            }

            updateFields.push('updated_at = datetime(\'now\')');
            values.push(id);

            const updateStmt = this.env.ONEBOOKNAV_DB.prepare(`
                UPDATE bookmarks SET ${updateFields.join(', ')} WHERE id = ?
            `);

            await updateStmt.bind(...values).run();
            await this.invalidateBookmarksCache(user.id);

            return new Response(JSON.stringify({
                success: true,
                message: '书签更新成功'
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Update bookmark error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '更新书签失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 删除书签
     */
    async deleteBookmark(request, user) {
        try {
            const url = new URL(request.url);
            const id = url.pathname.split('/').pop();

            // 检查书签是否属于当前用户
            const checkStmt = this.env.ONEBOOKNAV_DB.prepare(
                'SELECT id FROM bookmarks WHERE id = ? AND user_id = ?'
            );
            const existing = await checkStmt.bind(id, user.id).first();

            if (!existing) {
                return new Response(JSON.stringify({
                    success: false,
                    error: '书签不存在或无权限'
                }), {
                    status: 404,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            // 删除书签
            const deleteStmt = this.env.ONEBOOKNAV_DB.prepare('DELETE FROM bookmarks WHERE id = ?');
            await deleteStmt.bind(id).run();
            await this.invalidateBookmarksCache(user.id);

            return new Response(JSON.stringify({
                success: true,
                message: '书签删除成功'
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Delete bookmark error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '删除书签失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 搜索书签
     */
    async searchBookmarks(request) {
        try {
            const url = new URL(request.url);
            const query = url.searchParams.get('q') || '';
            const page = parseInt(url.searchParams.get('page')) || 1;
            const limit = parseInt(url.searchParams.get('limit')) || 20;
            const offset = (page - 1) * limit;

            if (!query.trim()) {
                return new Response(JSON.stringify({
                    success: true,
                    data: { bookmarks: [], pagination: { page, limit, total: 0, pages: 0 } }
                }), {
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            // 全文搜索
            const searchStmt = this.env.ONEBOOKNAV_DB.prepare(`
                SELECT b.*, c.name as category_name
                FROM bookmarks b
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE b.title LIKE ? OR b.description LIKE ? OR b.url LIKE ?
                ORDER BY
                    CASE
                        WHEN b.title LIKE ? THEN 1
                        WHEN b.description LIKE ? THEN 2
                        ELSE 3
                    END,
                    b.click_count DESC,
                    b.updated_at DESC
                LIMIT ${limit} OFFSET ${offset}
            `);

            const searchTerm = `%${query}%`;
            const titleTerm = `%${query}%`;
            const results = await searchStmt.bind(
                searchTerm, searchTerm, searchTerm, titleTerm, titleTerm
            ).all();

            // 获取总数
            const countStmt = this.env.ONEBOOKNAV_DB.prepare(`
                SELECT COUNT(*) as count FROM bookmarks
                WHERE title LIKE ? OR description LIKE ? OR url LIKE ?
            `);
            const countResult = await countStmt.bind(searchTerm, searchTerm, searchTerm).first();

            return new Response(JSON.stringify({
                success: true,
                data: {
                    bookmarks: results.results || [],
                    pagination: {
                        page,
                        limit,
                        total: countResult.count || 0,
                        pages: Math.ceil((countResult.count || 0) / limit)
                    }
                }
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Search bookmarks error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '搜索失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 获取分类列表
     */
    async getCategories(request) {
        try {
            const stmt = this.env.ONEBOOKNAV_DB.prepare(`
                SELECT c.*, COUNT(b.id) as bookmark_count
                FROM categories c
                LEFT JOIN bookmarks b ON c.id = b.category_id
                GROUP BY c.id
                ORDER BY c.sort_order ASC, c.name ASC
            `);

            const results = await stmt.all();

            return new Response(JSON.stringify({
                success: true,
                data: results.results || []
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Get categories error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '获取分类失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 创建分类
     */
    async createCategory(request, user) {
        try {
            const data = await request.json();
            const { name, description, icon, color, parent_id } = data;

            if (!name) {
                return new Response(JSON.stringify({
                    success: false,
                    error: '分类名称是必填的'
                }), {
                    status: 400,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            const stmt = this.env.ONEBOOKNAV_DB.prepare(`
                INSERT INTO categories (name, description, icon, color, parent_id, user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            `);

            const result = await stmt.bind(
                name,
                description || '',
                icon || '',
                color || '#007bff',
                parent_id || null,
                user.id
            ).run();

            return new Response(JSON.stringify({
                success: true,
                data: {
                    id: result.meta.last_row_id,
                    name,
                    description,
                    icon,
                    color,
                    parent_id,
                    created_at: new Date().toISOString()
                }
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Create category error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '创建分类失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 导入书签
     */
    async importBookmarks(request, user) {
        try {
            const data = await request.json();
            const { bookmarks, type, options } = data;

            if (!Array.isArray(bookmarks)) {
                return new Response(JSON.stringify({
                    success: false,
                    error: '无效的书签数据'
                }), {
                    status: 400,
                    headers: { 'Content-Type': 'application/json' }
                });
            }

            let imported = 0;
            let skipped = 0;
            let errors = [];

            // 批量导入
            for (const bookmark of bookmarks) {
                try {
                    // 检查是否已存在
                    if (options.skipDuplicates) {
                        const checkStmt = this.env.ONEBOOKNAV_DB.prepare(
                            'SELECT id FROM bookmarks WHERE url = ? AND user_id = ?'
                        );
                        const existing = await checkStmt.bind(bookmark.url, user.id).first();
                        if (existing) {
                            skipped++;
                            continue;
                        }
                    }

                    const insertStmt = this.env.ONEBOOKNAV_DB.prepare(`
                        INSERT INTO bookmarks (title, url, description, category_id, user_id, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                    `);

                    await insertStmt.bind(
                        bookmark.title || '未命名',
                        bookmark.url,
                        bookmark.description || '',
                        bookmark.category_id || null,
                        user.id
                    ).run();

                    imported++;

                } catch (error) {
                    errors.push(`导入失败: ${bookmark.title || bookmark.url} - ${error.message}`);
                }
            }

            await this.invalidateBookmarksCache(user.id);

            return new Response(JSON.stringify({
                success: true,
                data: {
                    imported,
                    skipped,
                    errors,
                    total: bookmarks.length
                }
            }), {
                headers: { 'Content-Type': 'application/json' }
            });

        } catch (error) {
            console.error('Import bookmarks error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '导入书签失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 导出书签
     */
    async exportBookmarks(request, user) {
        try {
            const url = new URL(request.url);
            const format = url.searchParams.get('format') || 'json';

            const stmt = this.env.ONEBOOKNAV_DB.prepare(`
                SELECT b.*, c.name as category_name
                FROM bookmarks b
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE b.user_id = ?
                ORDER BY b.created_at DESC
            `);

            const results = await stmt.bind(user.id).all();
            const bookmarks = results.results || [];

            let content, contentType, filename;

            switch (format) {
                case 'html':
                    content = this.exportToHtml(bookmarks);
                    contentType = 'text/html';
                    filename = 'bookmarks.html';
                    break;
                case 'csv':
                    content = this.exportToCsv(bookmarks);
                    contentType = 'text/csv';
                    filename = 'bookmarks.csv';
                    break;
                default:
                    content = JSON.stringify(bookmarks, null, 2);
                    contentType = 'application/json';
                    filename = 'bookmarks.json';
            }

            return new Response(content, {
                headers: {
                    'Content-Type': contentType,
                    'Content-Disposition': `attachment; filename="${filename}"`
                }
            });

        } catch (error) {
            console.error('Export bookmarks error:', error);
            return new Response(JSON.stringify({
                success: false,
                error: '导出书签失败'
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }

    /**
     * 渲染首页
     */
    async renderHomePage(request) {
        const html = await this.getStaticFile('index.html');
        return new Response(html, {
            headers: { 'Content-Type': 'text/html' }
        });
    }

    /**
     * 渲染登录页
     */
    async renderLoginPage(request) {
        const html = await this.getStaticFile('login.html');
        return new Response(html, {
            headers: { 'Content-Type': 'text/html' }
        });
    }

    /**
     * 渲染管理页面
     */
    async renderAdminPage(request, user) {
        const html = await this.getStaticFile('admin.html');
        return new Response(html, {
            headers: { 'Content-Type': 'text/html' }
        });
    }

    /**
     * 渲染404页面
     */
    async renderNotFoundPage(request) {
        return new Response('Page Not Found', {
            status: 404,
            headers: { 'Content-Type': 'text/plain' }
        });
    }

    // 辅助方法

    buildBookmarksQuery(categoryId, search, userId) {
        let query = `
            SELECT b.*, c.name as category_name
            FROM bookmarks b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.user_id = ?
        `;

        if (categoryId) {
            query += ` AND b.category_id = ${categoryId}`;
        }

        if (search) {
            query += ` AND (b.title LIKE '%${search}%' OR b.description LIKE '%${search}%' OR b.url LIKE '%${search}%')`;
        }

        query += ' ORDER BY b.updated_at DESC';
        return query;
    }

    buildBookmarksCountQuery(categoryId, search, userId) {
        let query = 'SELECT COUNT(*) as count FROM bookmarks WHERE user_id = ?';

        if (categoryId) {
            query += ` AND category_id = ${categoryId}`;
        }

        if (search) {
            query += ` AND (title LIKE '%${search}%' OR description LIKE '%${search}%' OR url LIKE '%${search}%')`;
        }

        return query;
    }

    async invalidateBookmarksCache(userId) {
        // 清理相关缓存
        await this.env.ONEBOOKNAV_CACHE.delete(`bookmarks:${userId}`);
        await this.env.ONEBOOKNAV_CACHE.delete(`categories:${userId}`);
    }

    exportToHtml(bookmarks) {
        let html = `<!DOCTYPE NETSCAPE-Bookmark-file-1>
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>Bookmarks</TITLE>
<H1>Bookmarks</H1>
<DL><p>
`;
        bookmarks.forEach(bookmark => {
            html += `    <DT><A HREF="${bookmark.url}">${bookmark.title}</A>\n`;
        });
        html += '</DL><p>';
        return html;
    }

    exportToCsv(bookmarks) {
        let csv = 'Title,URL,Description,Category\n';
        bookmarks.forEach(bookmark => {
            csv += `"${bookmark.title}","${bookmark.url}","${bookmark.description || ''}","${bookmark.category_name || ''}"\n`;
        });
        return csv;
    }

    async getStaticFile(filename) {
        const object = await this.env.ONEBOOKNAV_STORAGE.get(`static/${filename}`);
        return object ? await object.text() : '<html><body>File not found</body></html>';
    }
}