#!/bin/bash

# OneBookNav 安装脚本
# 支持 Ubuntu/Debian/CentOS 系统

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 日志函数
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 检查是否为root用户
check_root() {
    if [[ $EUID -eq 0 ]]; then
        log_error "请不要使用 root 用户运行此脚本"
        exit 1
    fi
}

# 检测操作系统
detect_os() {
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        OS=$NAME
        VER=$VERSION_ID
    else
        log_error "无法检测操作系统"
        exit 1
    fi
    log_info "检测到操作系统: $OS $VER"
}

# 检查系统要求
check_requirements() {
    log_info "检查系统要求..."

    # 检查 PHP 版本
    if command -v php &> /dev/null; then
        PHP_VERSION=$(php -r "echo PHP_VERSION;")
        if [[ $(echo "$PHP_VERSION >= 8.0" | bc -l) -eq 1 ]]; then
            log_success "PHP 版本: $PHP_VERSION ✓"
        else
            log_error "需要 PHP 8.0 或更高版本，当前版本: $PHP_VERSION"
            exit 1
        fi
    else
        log_error "未找到 PHP，请先安装 PHP 8.0+"
        exit 1
    fi

    # 检查必需的 PHP 扩展
    REQUIRED_EXTENSIONS=("pdo_sqlite" "mbstring" "curl" "gd" "zip" "xml" "json")
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if php -m | grep -q "$ext"; then
            log_success "PHP 扩展 $ext ✓"
        else
            log_error "缺少 PHP 扩展: $ext"
            exit 1
        fi
    done

    # 检查 Web 服务器
    if command -v apache2 &> /dev/null; then
        WEB_SERVER="apache2"
        log_success "检测到 Apache 服务器 ✓"
    elif command -v nginx &> /dev/null; then
        WEB_SERVER="nginx"
        log_success "检测到 Nginx 服务器 ✓"
    else
        log_error "未检测到支持的 Web 服务器 (Apache/Nginx)"
        exit 1
    fi
}

# 选择安装方式
choose_installation_method() {
    echo ""
    log_info "请选择安装方式:"
    echo "1) Docker 部署 (推荐)"
    echo "2) PHP 原生部署"
    echo "3) Cloudflare Workers 部署"
    echo ""
    read -p "请输入选择 (1-3): " INSTALL_METHOD

    case $INSTALL_METHOD in
        1)
            install_docker
            ;;
        2)
            install_native_php
            ;;
        3)
            install_cloudflare_workers
            ;;
        *)
            log_error "无效选择"
            exit 1
            ;;
    esac
}

# Docker 安装
install_docker() {
    log_info "开始 Docker 部署..."

    # 检查 Docker
    if ! command -v docker &> /dev/null; then
        log_info "安装 Docker..."
        curl -fsSL https://get.docker.com -o get-docker.sh
        sudo sh get-docker.sh
        sudo usermod -aG docker $USER
        rm get-docker.sh
    fi

    # 检查 Docker Compose
    if ! command -v docker-compose &> /dev/null; then
        log_info "安装 Docker Compose..."
        sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
        sudo chmod +x /usr/local/bin/docker-compose
    fi

    # 创建项目目录
    PROJECT_DIR="$HOME/onebooknav"
    mkdir -p "$PROJECT_DIR"
    cd "$PROJECT_DIR"

    # 下载项目文件
    log_info "下载 OneBookNav..."
    if command -v git &> /dev/null; then
        git clone https://github.com/your-repo/onebooknav.git .
    else
        curl -L https://github.com/your-repo/onebooknav/archive/main.zip -o onebooknav.zip
        unzip onebooknav.zip
        mv onebooknav-main/* .
        rm -rf onebooknav-main onebooknav.zip
    fi

    # 配置环境
    cp .env.new .env

    # 询问配置
    configure_environment

    # 启动服务
    log_info "启动 Docker 服务..."
    docker-compose up -d

    # 等待服务启动
    sleep 10

    # 检查服务状态
    if docker-compose ps | grep -q "Up"; then
        log_success "OneBookNav 已成功安装并启动!"
        log_info "访问地址: http://localhost:8080"
        log_info "管理员账户: admin / admin123"
    else
        log_error "服务启动失败，请检查日志: docker-compose logs"
    fi
}

# PHP 原生安装
install_native_php() {
    log_info "开始 PHP 原生部署..."

    # 获取安装目录
    read -p "请输入安装目录 [/var/www/html/onebooknav]: " INSTALL_DIR
    INSTALL_DIR=${INSTALL_DIR:-/var/www/html/onebooknav}

    # 创建目录
    sudo mkdir -p "$INSTALL_DIR"

    # 下载项目
    log_info "下载 OneBookNav..."
    if command -v git &> /dev/null; then
        sudo git clone https://github.com/your-repo/onebooknav.git "$INSTALL_DIR"
    else
        cd /tmp
        curl -L https://github.com/your-repo/onebooknav/archive/main.zip -o onebooknav.zip
        unzip onebooknav.zip
        sudo mv onebooknav-main/* "$INSTALL_DIR/"
        rm -rf onebooknav-main onebooknav.zip
    fi

    # 设置权限
    log_info "设置权限..."
    sudo chown -R www-data:www-data "$INSTALL_DIR"
    sudo chmod -R 755 "$INSTALL_DIR"
    sudo chmod -R 777 "$INSTALL_DIR/data" "$INSTALL_DIR/logs" "$INSTALL_DIR/backups"

    # 配置环境
    sudo cp "$INSTALL_DIR/.env.new" "$INSTALL_DIR/.env"

    # 配置 Web 服务器
    configure_webserver "$INSTALL_DIR"

    # 配置应用
    configure_environment

    # 初始化数据库
    cd "$INSTALL_DIR"
    sudo -u www-data php console migrate

    log_success "OneBookNav 已成功安装!"
    log_info "请访问你的网站域名进行初始配置"
}

# Cloudflare Workers 安装
install_cloudflare_workers() {
    log_info "开始 Cloudflare Workers 部署..."

    # 检查 Node.js
    if ! command -v node &> /dev/null; then
        log_info "安装 Node.js..."
        curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
        sudo apt-get install -y nodejs
    fi

    # 安装 Wrangler CLI
    if ! command -v wrangler &> /dev/null; then
        log_info "安装 Wrangler CLI..."
        npm install -g wrangler
    fi

    # 登录 Cloudflare
    log_info "请登录 Cloudflare 账户..."
    wrangler login

    # 创建项目目录
    PROJECT_DIR="$HOME/onebooknav-workers"
    mkdir -p "$PROJECT_DIR"
    cd "$PROJECT_DIR"

    # 下载项目
    if command -v git &> /dev/null; then
        git clone https://github.com/your-repo/onebooknav.git .
    else
        curl -L https://github.com/your-repo/onebooknav/archive/main.zip -o onebooknav.zip
        unzip onebooknav.zip
        mv onebooknav-main/* .
        rm -rf onebooknav-main onebooknav.zip
    fi

    # 配置 wrangler.toml
    cp wrangler.toml.example wrangler.toml

    log_info "请编辑 wrangler.toml 文件配置你的 Zone ID 等信息"
    read -p "按回车键继续..."

    # 创建资源
    log_info "创建 Cloudflare 资源..."
    wrangler kv:namespace create "ONEBOOKNAV_DATA"
    wrangler kv:namespace create "ONEBOOKNAV_CACHE"
    wrangler kv:namespace create "ONEBOOKNAV_SESSIONS"
    wrangler d1 create onebooknav
    wrangler r2 bucket create onebooknav-files

    # 设置密钥
    log_info "设置密钥..."
    wrangler secret put ADMIN_PASSWORD
    wrangler secret put JWT_SECRET

    # 部署
    log_info "部署到 Cloudflare Workers..."
    npm run build:worker
    wrangler deploy

    log_success "OneBookNav 已成功部署到 Cloudflare Workers!"
}

# 配置 Web 服务器
configure_webserver() {
    local install_dir=$1

    if [[ "$WEB_SERVER" == "apache2" ]]; then
        configure_apache "$install_dir"
    elif [[ "$WEB_SERVER" == "nginx" ]]; then
        configure_nginx "$install_dir"
    fi
}

# 配置 Apache
configure_apache() {
    local install_dir=$1

    log_info "配置 Apache..."

    # 获取域名
    read -p "请输入域名 [localhost]: " DOMAIN
    DOMAIN=${DOMAIN:-localhost}

    # 创建虚拟主机配置
    sudo tee /etc/apache2/sites-available/onebooknav.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $install_dir/public

    <Directory $install_dir/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/onebooknav_error.log
    CustomLog \${APACHE_LOG_DIR}/onebooknav_access.log combined
</VirtualHost>
EOF

    # 启用站点
    sudo a2ensite onebooknav.conf
    sudo a2enmod rewrite
    sudo systemctl reload apache2

    log_success "Apache 配置完成"
}

# 配置 Nginx
configure_nginx() {
    local install_dir=$1

    log_info "配置 Nginx..."

    # 获取域名
    read -p "请输入域名 [localhost]: " DOMAIN
    DOMAIN=${DOMAIN:-localhost}

    # 创建站点配置
    sudo tee /etc/nginx/sites-available/onebooknav > /dev/null <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $install_dir/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

    # 启用站点
    sudo ln -sf /etc/nginx/sites-available/onebooknav /etc/nginx/sites-enabled/
    sudo nginx -t && sudo systemctl reload nginx

    log_success "Nginx 配置完成"
}

# 配置环境变量
configure_environment() {
    log_info "配置应用环境..."

    # 数据库配置
    read -p "数据库类型 [sqlite]: " DB_TYPE
    DB_TYPE=${DB_TYPE:-sqlite}

    if [[ "$DB_TYPE" == "mysql" ]]; then
        read -p "MySQL 主机 [localhost]: " DB_HOST
        read -p "MySQL 端口 [3306]: " DB_PORT
        read -p "MySQL 数据库名: " DB_NAME
        read -p "MySQL 用户名: " DB_USER
        read -sp "MySQL 密码: " DB_PASS
        echo ""

        # 更新配置文件
        sed -i "s/DB_CONNECTION=sqlite/DB_CONNECTION=mysql/" .env
        sed -i "s/DB_HOST=localhost/DB_HOST=$DB_HOST/" .env
        sed -i "s/DB_PORT=3306/DB_PORT=$DB_PORT/" .env
        sed -i "s/DB_DATABASE_NAME=onebooknav/DB_DATABASE_NAME=$DB_NAME/" .env
        sed -i "s/DB_USERNAME=root/DB_USERNAME=$DB_USER/" .env
        sed -i "s/DB_PASSWORD=/DB_PASSWORD=$DB_PASS/" .env
    fi

    # 管理员配置
    read -p "管理员用户名 [admin]: " ADMIN_USER
    read -p "管理员邮箱 [admin@example.com]: " ADMIN_EMAIL
    read -sp "管理员密码 [admin123]: " ADMIN_PASS
    echo ""

    ADMIN_USER=${ADMIN_USER:-admin}
    ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}
    ADMIN_PASS=${ADMIN_PASS:-admin123}

    # 更新配置
    sed -i "s/ADMIN_USERNAME=admin/ADMIN_USERNAME=$ADMIN_USER/" .env
    sed -i "s/ADMIN_EMAIL=admin@example.com/ADMIN_EMAIL=$ADMIN_EMAIL/" .env
    sed -i "s/ADMIN_PASSWORD=admin123/ADMIN_PASSWORD=$ADMIN_PASS/" .env

    # 生成应用密钥
    APP_KEY=$(openssl rand -base64 32)
    sed -i "s/APP_KEY=/APP_KEY=$APP_KEY/" .env

    log_success "环境配置完成"
}

# 主函数
main() {
    echo "=========================================="
    echo "         OneBookNav 自动安装脚本"
    echo "=========================================="
    echo ""

    check_root
    detect_os
    check_requirements
    choose_installation_method

    echo ""
    log_success "安装完成！感谢使用 OneBookNav"
    echo ""
    log_info "下一步:"
    echo "1. 访问应用并使用管理员账户登录"
    echo "2. 修改默认密码"
    echo "3. 配置备份和安全设置"
    echo "4. 导入现有书签数据"
    echo ""
    log_info "更多帮助: https://docs.onebooknav.com"
}

# 运行主函数
main "$@"