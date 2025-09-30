# OneBookNav Multi-stage Dockerfile
# 支持开发和生产环境的多阶段构建

# 基础镜像
FROM php:8.1-apache-alpine AS base

# 安装系统依赖和 PHP 扩展
RUN apk add --no-cache \
    sqlite \
    sqlite-dev \
    nginx \
    supervisor \
    curl \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/cache/apk/*

# 配置 Apache
RUN a2enmod rewrite headers expires deflate

# 开发环境
FROM base AS development

# 安装开发工具
RUN apk add --no-cache \
    git \
    vim \
    htop \
    && docker-php-ext-install opcache

# 开启错误显示
RUN echo "display_errors = On" >> /usr/local/etc/php/conf.d/development.ini \
    && echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/development.ini

# 生产环境
FROM base AS production

# 安装生产优化
RUN docker-php-ext-install opcache \
    && echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=4000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=2" >> /usr/local/etc/php/conf.d/opcache.ini

# 安全配置
RUN echo "expose_php = Off" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/security.ini

# 最终镜像
FROM production AS final

# 设置工作目录
WORKDIR /var/www/html

# 复制应用代码
COPY . .

# 设置权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data \
    && chmod -R 777 /var/www/html/logs

# 复制配置文件
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 健康检查
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# 暴露端口
EXPOSE 80

# 启动 supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]