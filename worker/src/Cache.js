/**
 * OneBookNav Cloudflare Workers 缓存管理
 * KV存储缓存系统
 */

export class Cache {
    constructor(cacheKV) {
        this.kv = cacheKV;
        this.defaultTTL = 3600; // 1小时
    }

    /**
     * 获取缓存
     */
    async get(key) {
        try {
            const data = await this.kv.get(key);
            if (!data) return null;

            const cached = JSON.parse(data);

            // 检查是否过期
            if (cached.expires && new Date(cached.expires) < new Date()) {
                await this.delete(key);
                return null;
            }

            return cached.data;

        } catch (error) {
            console.error('Cache get error:', error);
            return null;
        }
    }

    /**
     * 设置缓存
     */
    async set(key, value, ttl = null) {
        try {
            const expires = ttl ? new Date(Date.now() + ttl * 1000) : null;
            const cached = {
                data: value,
                expires: expires ? expires.toISOString() : null,
                created_at: new Date().toISOString()
            };

            const options = {};
            if (ttl) {
                options.expirationTtl = ttl;
            } else if (this.defaultTTL) {
                options.expirationTtl = this.defaultTTL;
            }

            await this.kv.put(key, JSON.stringify(cached), options);
            return true;

        } catch (error) {
            console.error('Cache set error:', error);
            return false;
        }
    }

    /**
     * 删除缓存
     */
    async delete(key) {
        try {
            await this.kv.delete(key);
            return true;
        } catch (error) {
            console.error('Cache delete error:', error);
            return false;
        }
    }

    /**
     * 批量删除缓存（按前缀）
     */
    async deleteByPrefix(prefix) {
        try {
            const keys = await this.listKeys(prefix);
            const deletePromises = keys.map(key => this.delete(key.name));
            await Promise.all(deletePromises);
            return true;
        } catch (error) {
            console.error('Cache deleteByPrefix error:', error);
            return false;
        }
    }

    /**
     * 获取或设置缓存
     */
    async getOrSet(key, fn, ttl = null) {
        let value = await this.get(key);
        if (value !== null) {
            return value;
        }

        value = await fn();
        if (value !== undefined) {
            await this.set(key, value, ttl);
        }

        return value;
    }

    /**
     * 递增计数器
     */
    async increment(key, delta = 1, ttl = null) {
        try {
            const current = await this.get(key) || 0;
            const newValue = current + delta;
            await this.set(key, newValue, ttl);
            return newValue;
        } catch (error) {
            console.error('Cache increment error:', error);
            return delta;
        }
    }

    /**
     * 检查键是否存在
     */
    async exists(key) {
        const value = await this.get(key);
        return value !== null;
    }

    /**
     * 获取多个缓存
     */
    async getMultiple(keys) {
        try {
            const promises = keys.map(key => this.get(key));
            const results = await Promise.all(promises);

            const data = {};
            keys.forEach((key, index) => {
                data[key] = results[index];
            });

            return data;
        } catch (error) {
            console.error('Cache getMultiple error:', error);
            return {};
        }
    }

    /**
     * 设置多个缓存
     */
    async setMultiple(data, ttl = null) {
        try {
            const promises = Object.entries(data).map(([key, value]) =>
                this.set(key, value, ttl)
            );
            const results = await Promise.all(promises);
            return results.every(result => result === true);
        } catch (error) {
            console.error('Cache setMultiple error:', error);
            return false;
        }
    }

    /**
     * 列出键
     */
    async listKeys(prefix = '') {
        try {
            const result = await this.kv.list({ prefix });
            return result.keys || [];
        } catch (error) {
            console.error('Cache listKeys error:', error);
            return [];
        }
    }

    /**
     * 清理过期缓存
     */
    async cleanup() {
        try {
            const keys = await this.listKeys();
            const cleanupPromises = [];

            for (const keyInfo of keys) {
                cleanupPromises.push(this.checkAndCleanupKey(keyInfo.name));
            }

            await Promise.all(cleanupPromises);
            return true;
        } catch (error) {
            console.error('Cache cleanup error:', error);
            return false;
        }
    }

    /**
     * 检查并清理单个键
     */
    async checkAndCleanupKey(key) {
        try {
            const value = await this.get(key);
            // get方法会自动清理过期的键
            return true;
        } catch (error) {
            console.error('Cache checkAndCleanupKey error:', error);
            return false;
        }
    }

    /**
     * 获取缓存统计信息
     */
    async getStats() {
        try {
            const keys = await this.listKeys();
            const stats = {
                totalKeys: keys.length,
                keysByPrefix: {},
                estimatedSize: 0
            };

            // 按前缀分组统计
            keys.forEach(keyInfo => {
                const prefix = keyInfo.name.split(':')[0];
                if (!stats.keysByPrefix[prefix]) {
                    stats.keysByPrefix[prefix] = 0;
                }
                stats.keysByPrefix[prefix]++;
                stats.estimatedSize += keyInfo.name.length;
            });

            return stats;
        } catch (error) {
            console.error('Cache getStats error:', error);
            return { totalKeys: 0, keysByPrefix: {}, estimatedSize: 0 };
        }
    }

    /**
     * 缓存书签列表
     */
    async cacheBookmarks(userId, categoryId, bookmarks, ttl = 3600) {
        const key = this.getBookmarksCacheKey(userId, categoryId);
        return await this.set(key, bookmarks, ttl);
    }

    /**
     * 获取缓存的书签列表
     */
    async getCachedBookmarks(userId, categoryId) {
        const key = this.getBookmarksCacheKey(userId, categoryId);
        return await this.get(key);
    }

    /**
     * 清理书签缓存
     */
    async clearBookmarksCache(userId) {
        return await this.deleteByPrefix(`bookmarks:${userId}`);
    }

    /**
     * 缓存分类列表
     */
    async cacheCategories(userId, categories, ttl = 3600) {
        const key = this.getCategoriesCacheKey(userId);
        return await this.set(key, categories, ttl);
    }

    /**
     * 获取缓存的分类列表
     */
    async getCachedCategories(userId) {
        const key = this.getCategoriesCacheKey(userId);
        return await this.get(key);
    }

    /**
     * 清理分类缓存
     */
    async clearCategoriesCache(userId) {
        const key = this.getCategoriesCacheKey(userId);
        return await this.delete(key);
    }

    /**
     * 缓存搜索结果
     */
    async cacheSearchResults(query, results, ttl = 1800) {
        const key = this.getSearchCacheKey(query);
        return await this.set(key, results, ttl);
    }

    /**
     * 获取缓存的搜索结果
     */
    async getCachedSearchResults(query) {
        const key = this.getSearchCacheKey(query);
        return await this.get(key);
    }

    /**
     * 缓存用户统计
     */
    async cacheUserStats(userId, stats, ttl = 1800) {
        const key = this.getUserStatsCacheKey(userId);
        return await this.set(key, stats, ttl);
    }

    /**
     * 获取缓存的用户统计
     */
    async getCachedUserStats(userId) {
        const key = this.getUserStatsCacheKey(userId);
        return await this.get(key);
    }

    /**
     * 缓存网站图标
     */
    async cacheFavicon(url, iconData, ttl = 86400) {
        const key = this.getFaviconCacheKey(url);
        return await this.set(key, iconData, ttl);
    }

    /**
     * 获取缓存的网站图标
     */
    async getCachedFavicon(url) {
        const key = this.getFaviconCacheKey(url);
        return await this.get(key);
    }

    /**
     * 缓存死链检查结果
     */
    async cacheDeadLinkCheck(url, isAlive, ttl = 3600) {
        const key = this.getDeadLinkCacheKey(url);
        return await this.set(key, { isAlive, checkedAt: new Date().toISOString() }, ttl);
    }

    /**
     * 获取缓存的死链检查结果
     */
    async getCachedDeadLinkCheck(url) {
        const key = this.getDeadLinkCacheKey(url);
        return await this.get(key);
    }

    // 缓存键生成方法

    getBookmarksCacheKey(userId, categoryId = null) {
        return `bookmarks:${userId}${categoryId ? ':' + categoryId : ''}`;
    }

    getCategoriesCacheKey(userId) {
        return `categories:${userId}`;
    }

    getSearchCacheKey(query) {
        const hash = this.hashString(query);
        return `search:${hash}`;
    }

    getUserStatsCacheKey(userId) {
        return `stats:${userId}`;
    }

    getFaviconCacheKey(url) {
        const hash = this.hashString(url);
        return `favicon:${hash}`;
    }

    getDeadLinkCacheKey(url) {
        const hash = this.hashString(url);
        return `deadlink:${hash}`;
    }

    /**
     * 简单的字符串哈希函数
     */
    hashString(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // 转换为32位整数
        }
        return Math.abs(hash).toString(36);
    }

    /**
     * 格式化缓存键
     */
    formatKey(key) {
        // 确保键符合KV存储的要求
        return key.replace(/[^a-zA-Z0-9:_-]/g, '_').substring(0, 512);
    }
}