# -*- coding: utf-8 -*-
"""
OneBookNav Flask应用工厂
融合BookNav和OneNav的优点，创建功能强大的导航应用
"""
import os
import logging
from flask import Flask, request, session, g
from flask_sqlalchemy import SQLAlchemy
from flask_migrate import Migrate
from flask_login import LoginManager, current_user
from flask_wtf.csrf import CSRFProtect
from flask_mail import Mail
from flask_caching import Cache
from flask_admin import Admin
from werkzeug.middleware.proxy_fix import ProxyFix

# 创建扩展实例
db = SQLAlchemy()
migrate = Migrate()
login_manager = LoginManager()
csrf = CSRFProtect()
mail = Mail()
cache = Cache()
admin = Admin()


def create_app(config_name='default'):
    """应用工厂函数"""
    app = Flask(__name__)

    # 加载配置
    from config import config
    app.config.from_object(config[config_name])
    config[config_name].init_app(app)

    # 代理修复（用于Nginx等反向代理）
    app.wsgi_app = ProxyFix(app.wsgi_app, x_for=1, x_proto=1, x_host=1, x_prefix=1)

    # 初始化扩展
    init_extensions(app)

    # 注册蓝图
    register_blueprints(app)

    # 注册错误处理器
    register_error_handlers(app)

    # 注册上下文处理器
    register_context_processors(app)

    # 注册请求处理器
    register_request_handlers(app)

    # 注册CLI命令
    register_cli_commands(app)

    # 配置日志
    configure_logging(app)

    return app


def init_extensions(app):
    """初始化Flask扩展"""

    # 数据库
    db.init_app(app)

    # 数据库迁移
    migrate.init_app(app, db)

    # 登录管理
    login_manager.init_app(app)
    login_manager.login_view = 'auth.login'
    login_manager.login_message = '请登录以访问此页面。'
    login_manager.login_message_category = 'info'
    login_manager.session_protection = 'strong'

    # CSRF保护
    csrf.init_app(app)

    # 邮件
    mail.init_app(app)

    # 缓存
    cache.init_app(app)

    # 管理后台
    admin.init_app(app)
    admin.name = app.config.get('SITE_NAME', 'OneBookNav')
    admin.template_mode = 'bootstrap4'

    # 配置用户加载器
    @login_manager.user_loader
    def load_user(user_id):
        from app.models import User
        return User.query.get(int(user_id))


def register_blueprints(app):
    """注册蓝图"""

    # 主页面蓝图
    from app.main import bp as main_bp
    app.register_blueprint(main_bp)

    # 认证蓝图
    from app.auth import bp as auth_bp
    app.register_blueprint(auth_bp, url_prefix='/auth')

    # 管理蓝图
    from app.admin import bp as admin_bp
    app.register_blueprint(admin_bp, url_prefix='/admin')

    # API蓝图
    from app.api import bp as api_bp
    app.register_blueprint(api_bp, url_prefix='/api')


def register_error_handlers(app):
    """注册错误处理器"""

    @app.errorhandler(400)
    def bad_request(error):
        from flask import render_template
        return render_template('errors/400.html'), 400

    @app.errorhandler(403)
    def forbidden(error):
        from flask import render_template
        return render_template('errors/403.html'), 403

    @app.errorhandler(404)
    def not_found(error):
        from flask import render_template
        return render_template('errors/404.html'), 404

    @app.errorhandler(500)
    def internal_error(error):
        from flask import render_template
        db.session.rollback()
        return render_template('errors/500.html'), 500

    @app.errorhandler(413)
    def request_entity_too_large(error):
        from flask import render_template, flash
        flash('上传文件过大，请选择小于16MB的文件。', 'error')
        return render_template('errors/413.html'), 413


def register_context_processors(app):
    """注册上下文处理器"""

    @app.context_processor
    def inject_global_vars():
        """注入全局模板变量"""
        from app.models import SiteSettings
        from app.utils.theme import get_current_theme, get_available_themes

        # 获取网站设置
        settings = SiteSettings.get_settings()

        return {
            'site_settings': settings,
            'current_theme': get_current_theme(),
            'available_themes': get_available_themes(),
            'app_config': app.config
        }

    @app.context_processor
    def inject_user_vars():
        """注入用户相关变量"""
        if current_user.is_authenticated:
            return {
                'unread_notifications': current_user.get_unread_notifications_count() if hasattr(current_user, 'get_unread_notifications_count') else 0
            }
        return {}


def register_request_handlers(app):
    """注册请求处理器"""

    @app.before_request
    def before_request():
        """请求前处理"""
        # 记录用户最后活动时间
        if current_user.is_authenticated:
            current_user.update_last_seen()
            db.session.commit()

        # 设置当前用户到g对象
        g.user = current_user

        # 主题处理
        theme = request.args.get('theme')
        if theme and theme in app.config.get('THEMES', {}):
            session['theme'] = theme

    @app.after_request
    def after_request(response):
        """请求后处理"""
        # 安全头设置
        response.headers['X-Content-Type-Options'] = 'nosniff'
        response.headers['X-Frame-Options'] = 'DENY'
        response.headers['X-XSS-Protection'] = '1; mode=block'

        # 缓存控制
        if request.endpoint and request.endpoint.startswith('static'):
            response.cache_control.max_age = 31536000  # 1年

        return response


def register_cli_commands(app):
    """注册CLI命令"""

    @app.cli.command()
    def init_themes():
        """初始化主题"""
        from app.utils.theme import init_default_themes
        init_default_themes()
        print("Themes initialized successfully!")

    @app.cli.command()
    def update_search_index():
        """更新搜索索引"""
        from app.utils.search import update_search_index
        update_search_index()
        print("Search index updated successfully!")

    @app.cli.command()
    def cleanup_temp_files():
        """清理临时文件"""
        from app.utils.cleanup import cleanup_temp_files
        cleanup_temp_files()
        print("Temporary files cleaned up successfully!")


def configure_logging(app):
    """配置日志"""
    if not app.debug and not app.testing:
        # 生产环境日志配置
        if not os.path.exists('logs'):
            os.mkdir('logs')

        # 文件日志处理器
        from logging.handlers import RotatingFileHandler
        file_handler = RotatingFileHandler(
            'logs/onebooknav.log',
            maxBytes=10240000,  # 10MB
            backupCount=10
        )
        file_handler.setFormatter(logging.Formatter(
            '%(asctime)s %(levelname)s: %(message)s [in %(pathname)s:%(lineno)d]'
        ))
        file_handler.setLevel(logging.INFO)
        app.logger.addHandler(file_handler)

        app.logger.setLevel(logging.INFO)
        app.logger.info('OneBookNav startup')

    elif app.debug:
        # 开发环境日志配置
        app.logger.setLevel(logging.DEBUG)
        app.logger.info('OneBookNav development mode startup')