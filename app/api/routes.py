# -*- coding: utf-8 -*-
"""
OneBookNav API路由
提供RESTful API接口
"""
from flask import jsonify, request, current_app
from flask_login import current_user, login_required
from sqlalchemy import or_, func

from app.api import bp
from app.models import Category, Website, Tag, User, SiteSettings
from app import db


@bp.route('/categories')
def get_categories():
    """获取分类列表"""
    # 权限过滤
    if current_user.is_authenticated:
        categories = Category.query.filter(
            or_(
                Category.is_public == True,
                Category.user_id == current_user.id
            )
        ).filter_by(is_visible=True).order_by(Category.sort_order).all()
    else:
        categories = Category.query.filter_by(
            is_public=True, is_visible=True
        ).order_by(Category.sort_order).all()

    return jsonify({
        'categories': [cat.to_dict() for cat in categories]
    })


@bp.route('/categories/<int:category_id>')
def get_category(category_id):
    """获取分类详情"""
    category = Category.query.get_or_404(category_id)

    # 权限检查
    if not category.is_public and (not current_user.is_authenticated or
                                  (category.user_id != current_user.id and not current_user.is_admin())):
        return jsonify({'error': '没有权限访问此分类'}), 403

    return jsonify(category.to_dict(include_websites=True))


@bp.route('/websites')
def get_websites():
    """获取网站列表"""
    page = request.args.get('page', 1, type=int)
    per_page = request.args.get('per_page', 20, type=int)
    category_id = request.args.get('category_id', type=int)
    tag_id = request.args.get('tag_id', type=int)
    search = request.args.get('search', '').strip()

    # 构建查询
    query = Website.query.filter_by(is_active=True)

    # 权限过滤
    if not current_user.is_authenticated:
        query = query.filter_by(is_public=True)
    elif not current_user.is_admin():
        query = query.filter(
            or_(
                Website.is_public == True,
                Website.user_id == current_user.id
            )
        )

    # 分类过滤
    if category_id:
        query = query.filter_by(category_id=category_id)

    # 标签过滤
    if tag_id:
        query = query.join(Website.tags).filter(Tag.id == tag_id)

    # 搜索过滤
    if search:
        query = query.filter(
            or_(
                Website.title.contains(search),
                Website.description.contains(search),
                Website.keywords.contains(search)
            )
        )

    # 分页
    pagination = query.order_by(
        Website.sort_order,
        Website.created_at.desc()
    ).paginate(
        page=page,
        per_page=min(per_page, 100),  # 限制每页最大数量
        error_out=False
    )

    return jsonify({
        'websites': [website.to_dict() for website in pagination.items],
        'pagination': {
            'page': pagination.page,
            'pages': pagination.pages,
            'per_page': pagination.per_page,
            'total': pagination.total,
            'has_next': pagination.has_next,
            'has_prev': pagination.has_prev
        }
    })


@bp.route('/websites/<int:website_id>')
def get_website(website_id):
    """获取网站详情"""
    website = Website.query.get_or_404(website_id)

    # 权限检查
    if not website.is_public and (not current_user.is_authenticated or
                                 (website.user_id != current_user.id and not current_user.is_admin())):
        return jsonify({'error': '没有权限访问此网站'}), 403

    return jsonify(website.to_dict())


@bp.route('/websites/<int:website_id>/click', methods=['POST'])
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

    return jsonify({
        'success': True,
        'click_count': website.click_count
    })


@bp.route('/tags')
def get_tags():
    """获取标签列表"""
    # 获取有网站的标签
    tags = Tag.query.join(Tag.websites).filter(
        Website.is_active == True
    ).distinct().order_by(Tag.usage_count.desc()).all()

    # 权限过滤
    if not current_user.is_authenticated:
        tags = [tag for tag in tags if any(w.is_public for w in tag.websites if w.is_active)]
    elif not current_user.is_admin():
        tags = [tag for tag in tags if any(
            w.is_public or w.user_id == current_user.id
            for w in tag.websites if w.is_active
        )]

    return jsonify({
        'tags': [tag.to_dict() for tag in tags]
    })


@bp.route('/search')
def search():
    """搜索API"""
    query = request.args.get('q', '').strip()
    page = request.args.get('page', 1, type=int)
    per_page = request.args.get('per_page', 20, type=int)

    if not query:
        return jsonify({
            'websites': [],
            'query': query,
            'pagination': {
                'page': 1,
                'pages': 0,
                'per_page': per_page,
                'total': 0,
                'has_next': False,
                'has_prev': False
            }
        })

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
        per_page=min(per_page, 100),
        error_out=False
    )

    return jsonify({
        'websites': [website.to_dict() for website in pagination.items],
        'query': query,
        'pagination': {
            'page': pagination.page,
            'pages': pagination.pages,
            'per_page': pagination.per_page,
            'total': pagination.total,
            'has_next': pagination.has_next,
            'has_prev': pagination.has_prev
        }
    })


@bp.route('/stats')
def get_stats():
    """获取统计信息"""
    # 基本统计
    stats = {
        'total_categories': Category.query.filter_by(is_public=True, is_visible=True).count(),
        'total_websites': Website.query.filter_by(is_public=True, is_active=True).count(),
        'total_tags': Tag.query.count(),
        'total_clicks': db.session.query(func.sum(Website.click_count)).scalar() or 0
    }

    # 热门网站
    popular_websites = Website.query.filter_by(
        is_public=True, is_active=True
    ).order_by(Website.click_count.desc()).limit(10).all()

    stats['popular_websites'] = [w.to_dict() for w in popular_websites]

    return jsonify(stats)


@bp.route('/settings')
def get_public_settings():
    """获取公开设置"""
    settings = SiteSettings.query.filter_by(is_public=True).all()

    return jsonify({
        setting.key: setting.get_value() for setting in settings
    })


# 用户相关API（需要登录）
@bp.route('/user/categories')
@login_required
def get_user_categories():
    """获取用户分类"""
    categories = current_user.categories.filter_by(is_visible=True).order_by(Category.sort_order).all()

    return jsonify({
        'categories': [cat.to_dict() for cat in categories]
    })


@bp.route('/user/websites')
@login_required
def get_user_websites():
    """获取用户网站"""
    page = request.args.get('page', 1, type=int)
    per_page = request.args.get('per_page', 20, type=int)

    pagination = current_user.websites.filter_by(is_active=True).order_by(
        Website.sort_order,
        Website.created_at.desc()
    ).paginate(
        page=page,
        per_page=min(per_page, 100),
        error_out=False
    )

    return jsonify({
        'websites': [website.to_dict() for website in pagination.items],
        'pagination': {
            'page': pagination.page,
            'pages': pagination.pages,
            'per_page': pagination.per_page,
            'total': pagination.total,
            'has_next': pagination.has_next,
            'has_prev': pagination.has_prev
        }
    })


@bp.route('/user/profile')
@login_required
def get_user_profile():
    """获取用户资料"""
    return jsonify(current_user.to_dict())


# 错误处理
@bp.errorhandler(404)
def api_not_found(error):
    """API 404错误"""
    return jsonify({'error': 'API接口不存在'}), 404


@bp.errorhandler(403)
def api_forbidden(error):
    """API 403错误"""
    return jsonify({'error': '没有权限访问'}), 403


@bp.errorhandler(500)
def api_internal_error(error):
    """API 500错误"""
    db.session.rollback()
    return jsonify({'error': '服务器内部错误'}), 500