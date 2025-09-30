# -*- coding: utf-8 -*-
"""
OneBookNav 管理路由
处理后台管理功能的路由
"""
from flask import render_template, redirect, url_for, flash, request, jsonify, current_app
from flask_login import login_required, current_user
from functools import wraps

from app.admin import bp
from app.models import User, Category, Website, Tag, SiteSettings, InvitationCode, UserRole
from app import db


def admin_required(f):
    """管理员权限装饰器"""
    @wraps(f)
    def decorated_function(*args, **kwargs):
        if not current_user.is_authenticated or not current_user.is_admin():
            flash('您需要管理员权限才能访问此页面。', 'error')
            return redirect(url_for('main.index'))
        return f(*args, **kwargs)
    return decorated_function


@bp.route('/')
@bp.route('/dashboard')
@login_required
@admin_required
def dashboard():
    """管理后台首页"""
    # 统计数据
    stats = {
        'total_users': User.query.count(),
        'total_categories': Category.query.count(),
        'total_websites': Website.query.count(),
        'total_tags': Tag.query.count(),
        'active_users': User.query.filter_by(is_active=True).count(),
        'public_websites': Website.query.filter_by(is_public=True, is_active=True).count(),
    }

    # 最近注册的用户
    recent_users = User.query.order_by(User.created_at.desc()).limit(5).all()

    # 最近添加的网站
    recent_websites = Website.query.order_by(Website.created_at.desc()).limit(5).all()

    return render_template(
        'admin/dashboard.html',
        title='管理后台',
        stats=stats,
        recent_users=recent_users,
        recent_websites=recent_websites
    )


@bp.route('/users')
@login_required
@admin_required
def users():
    """用户管理"""
    page = request.args.get('page', 1, type=int)
    per_page = 20

    users_query = User.query.order_by(User.created_at.desc())
    pagination = users_query.paginate(
        page=page,
        per_page=per_page,
        error_out=False
    )

    return render_template(
        'admin/users.html',
        title='用户管理',
        users=pagination.items,
        pagination=pagination
    )


@bp.route('/categories')
@login_required
@admin_required
def categories():
    """分类管理"""
    categories = Category.query.order_by(Category.sort_order, Category.created_at).all()

    return render_template(
        'admin/categories.html',
        title='分类管理',
        categories=categories
    )


@bp.route('/websites')
@login_required
@admin_required
def websites():
    """网站管理"""
    page = request.args.get('page', 1, type=int)
    per_page = 20

    websites_query = Website.query.order_by(Website.created_at.desc())
    pagination = websites_query.paginate(
        page=page,
        per_page=per_page,
        error_out=False
    )

    return render_template(
        'admin/websites.html',
        title='网站管理',
        websites=pagination.items,
        pagination=pagination
    )


@bp.route('/settings')
@login_required
@admin_required
def settings():
    """系统设置"""
    settings = SiteSettings.query.order_by(SiteSettings.category, SiteSettings.key).all()

    # 按分类分组
    settings_by_category = {}
    for setting in settings:
        if setting.category not in settings_by_category:
            settings_by_category[setting.category] = []
        settings_by_category[setting.category].append(setting)

    return render_template(
        'admin/settings.html',
        title='系统设置',
        settings_by_category=settings_by_category
    )


@bp.route('/invitation-codes')
@login_required
@admin_required
def invitation_codes():
    """邀请码管理"""
    codes = InvitationCode.query.order_by(InvitationCode.created_at.desc()).all()

    return render_template(
        'admin/invitation_codes.html',
        title='邀请码管理',
        codes=codes
    )


# 基础的管理API接口占位符
@bp.route('/api/users/<int:user_id>/toggle-status', methods=['POST'])
@login_required
@admin_required
def toggle_user_status(user_id):
    """切换用户状态"""
    user = User.query.get_or_404(user_id)
    user.is_active = not user.is_active
    db.session.commit()

    return jsonify({
        'success': True,
        'is_active': user.is_active,
        'message': f'用户 {user.username} 已{"启用" if user.is_active else "禁用"}'
    })