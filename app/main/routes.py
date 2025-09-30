# -*- coding: utf-8 -*-
"""
OneBookNav 主页面路由
处理主页面、分类页面、搜索等核心功能的路由
"""
from flask import render_template, request, jsonify, session, current_app, redirect, url_for, flash
from flask_login import current_user, login_required
from sqlalchemy import or_

from app.main import bp
from app.models import Category, Website, Tag, SiteSettings
from app import db


@bp.route('/')
@bp.route('/index')
def index():
    """首页"""
    # 获取网站设置
    settings = SiteSettings.get_settings()

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    # 获取分类和网站
    if current_user.is_authenticated:
        # 登录用户可以看到自己的私有内容
        categories = Category.query.filter(
            or_(
                Category.is_public == True,
                Category.user_id == current_user.id
            )
        ).filter_by(is_visible=True, parent_id=None).order_by(Category.sort_order).all()
    else:
        # 游客只能看到公开内容
        categories = Category.query.filter_by(
            is_public=True, is_visible=True, parent_id=None
        ).order_by(Category.sort_order).all()

    # 获取精选网站
    featured_websites = Website.query.filter_by(
        is_featured=True, is_active=True, is_public=True
    ).order_by(Website.sort_order).limit(8).all()

    # 获取最近添加的网站
    recent_websites = Website.query.filter_by(
        is_active=True, is_public=True
    ).order_by(Website.created_at.desc()).limit(6).all()

    return render_template(
        f'themes/{current_theme}/index.html',
        categories=categories,
        featured_websites=featured_websites,
        recent_websites=recent_websites,
        settings=settings
    )


@bp.route('/category/<int:category_id>')
def category_detail(category_id):
    """分类详情页"""
    category = Category.query.get_or_404(category_id)

    # 权限检查
    if not category.is_public and (not current_user.is_authenticated or
                                  (category.user_id != current_user.id and not current_user.is_admin())):
        flash('您没有权限访问此分类。', 'error')
        return redirect(url_for('main.index'))

    # 获取子分类
    subcategories = category.children.filter_by(is_visible=True).order_by(Category.sort_order).all()

    # 获取网站列表
    websites_query = category.websites.filter_by(is_active=True)

    if not current_user.is_authenticated or (category.user_id != current_user.id and not current_user.is_admin()):
        websites_query = websites_query.filter_by(is_public=True)

    websites = websites_query.order_by(Website.sort_order, Website.created_at.desc()).all()

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/category.html',
        category=category,
        subcategories=subcategories,
        websites=websites
    )


@bp.route('/search')
def search():
    """搜索页面"""
    query = request.args.get('q', '').strip()
    page = request.args.get('page', 1, type=int)
    per_page = 20

    if not query:
        return render_template('search.html', websites=[], query='', pagination=None)

    # 搜索网站
    websites_query = Website.query.filter(
        Website.is_active == True,
        or_(
            Website.title.contains(query),
            Website.description.contains(query),
            Website.keywords.contains(query),
            Website.url.contains(query)
        )
    )

    # 权限过滤
    if not current_user.is_authenticated:
        websites_query = websites_query.filter_by(is_public=True)
    elif not current_user.is_admin():
        websites_query = websites_query.filter(
            or_(
                Website.is_public == True,
                Website.user_id == current_user.id
            )
        )

    # 分页
    pagination = websites_query.order_by(
        Website.click_count.desc(),
        Website.created_at.desc()
    ).paginate(
        page=page,
        per_page=per_page,
        error_out=False
    )

    websites = pagination.items

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/search.html',
        websites=websites,
        query=query,
        pagination=pagination
    )


@bp.route('/website/<int:website_id>/click')
def click_website(website_id):
    """记录网站点击"""
    website = Website.query.get_or_404(website_id)

    # 权限检查
    if not website.is_public and (not current_user.is_authenticated or
                                 (website.user_id != current_user.id and not current_user.is_admin())):
        return jsonify({'error': '没有权限访问此网站'}), 403

    # 增加点击次数
    website.increment_click()
    db.session.commit()

    return redirect(website.url)


@bp.route('/tags')
def tags():
    """标签页面"""
    # 获取所有有网站的标签
    tags = Tag.query.join(Tag.websites).filter(
        Website.is_active == True
    ).distinct().order_by(Tag.usage_count.desc()).all()

    # 权限过滤（只显示公开网站的标签）
    if not current_user.is_authenticated:
        tags = [tag for tag in tags if any(w.is_public for w in tag.websites if w.is_active)]
    elif not current_user.is_admin():
        tags = [tag for tag in tags if any(
            w.is_public or w.user_id == current_user.id
            for w in tag.websites if w.is_active
        )]

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/tags.html',
        tags=tags
    )


@bp.route('/tag/<int:tag_id>')
def tag_detail(tag_id):
    """标签详情页"""
    tag = Tag.query.get_or_404(tag_id)

    # 获取该标签下的网站
    websites_query = tag.websites.filter_by(is_active=True)

    # 权限过滤
    if not current_user.is_authenticated:
        websites_query = websites_query.filter_by(is_public=True)
    elif not current_user.is_admin():
        websites_query = websites_query.filter(
            or_(
                Website.is_public == True,
                Website.user_id == current_user.id
            )
        )

    websites = websites_query.order_by(
        Website.sort_order,
        Website.created_at.desc()
    ).all()

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/tag_detail.html',
        tag=tag,
        websites=websites
    )


@bp.route('/theme/<theme_name>')
def switch_theme(theme_name):
    """切换主题"""
    available_themes = current_app.config.get('THEMES', {})

    if theme_name in available_themes:
        session['theme'] = theme_name
        flash(f'主题已切换为 {available_themes[theme_name]["name"]}', 'success')
    else:
        flash('主题不存在', 'error')

    return redirect(request.referrer or url_for('main.index'))


@bp.route('/about')
def about():
    """关于页面"""
    settings = SiteSettings.get_settings()
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/about.html',
        settings=settings
    )


@bp.route('/stats')
def stats():
    """统计页面"""
    # 基本统计
    total_categories = Category.query.filter_by(is_public=True, is_visible=True).count()
    total_websites = Website.query.filter_by(is_public=True, is_active=True).count()
    total_tags = Tag.query.count()
    total_clicks = db.session.query(db.func.sum(Website.click_count)).scalar() or 0

    # 热门网站
    popular_websites = Website.query.filter_by(
        is_public=True, is_active=True
    ).order_by(Website.click_count.desc()).limit(10).all()

    # 最近添加
    recent_websites = Website.query.filter_by(
        is_public=True, is_active=True
    ).order_by(Website.created_at.desc()).limit(10).all()

    # 热门标签
    popular_tags = Tag.query.order_by(Tag.usage_count.desc()).limit(20).all()

    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/stats.html',
        total_categories=total_categories,
        total_websites=total_websites,
        total_tags=total_tags,
        total_clicks=total_clicks,
        popular_websites=popular_websites,
        recent_websites=recent_websites,
        popular_tags=popular_tags
    )


# 错误处理
@bp.app_errorhandler(404)
def not_found_error(error):
    """404错误处理"""
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))
    return render_template(f'themes/{current_theme}/errors/404.html'), 404


@bp.app_errorhandler(500)
def internal_error(error):
    """500错误处理"""
    db.session.rollback()
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))
    return render_template(f'themes/{current_theme}/errors/500.html'), 500