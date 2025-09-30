# -*- coding: utf-8 -*-
"""
OneBookNav 认证路由
处理用户登录、注册、注销等认证功能的路由
"""
from flask import render_template, redirect, url_for, flash, request, current_app, session
from flask_login import login_user, logout_user, current_user, login_required
from werkzeug.urls import url_parse

from app.auth import bp
from app.auth.forms import LoginForm, RegistrationForm, ForgotPasswordForm, ResetPasswordForm
from app.models import User, InvitationCode, UserRole, SiteSettings
from app import db


@bp.route('/login', methods=['GET', 'POST'])
def login():
    """用户登录"""
    if current_user.is_authenticated:
        return redirect(url_for('main.index'))

    form = LoginForm()
    if form.validate_on_submit():
        # 查找用户（支持用户名或邮箱登录）
        user = User.query.filter(
            (User.username == form.username.data) | (User.email == form.username.data)
        ).first()

        if user and user.check_password(form.password.data):
            if not user.is_active:
                flash('您的账户已被禁用，请联系管理员。', 'error')
                return redirect(url_for('auth.login'))

            # 登录用户
            login_user(user, remember=form.remember_me.data)

            # 记录登录时间
            user.update_last_seen()
            db.session.commit()

            # 重定向到原始页面或首页
            next_page = request.args.get('next')
            if not next_page or url_parse(next_page).netloc != '':
                next_page = url_for('main.index')

            flash(f'欢迎回来，{user.username}！', 'success')
            return redirect(next_page)
        else:
            flash('用户名/邮箱或密码错误。', 'error')

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/auth/login.html',
        title='登录',
        form=form
    )


@bp.route('/logout')
@login_required
def logout():
    """用户注销"""
    username = current_user.username
    logout_user()
    flash(f'再见，{username}！', 'info')
    return redirect(url_for('main.index'))


@bp.route('/register', methods=['GET', 'POST'])
def register():
    """用户注册"""
    if current_user.is_authenticated:
        return redirect(url_for('main.index'))

    # 检查是否允许注册
    registration_enabled = SiteSettings.get_value('registration_enabled', True)
    if not registration_enabled:
        flash('当前不允许注册新用户。', 'error')
        return redirect(url_for('auth.login'))

    form = RegistrationForm()
    if form.validate_on_submit():
        # 验证邀请码（如果需要）
        invitation_code = None
        if form.invitation_code.data:
            invitation_code = InvitationCode.query.filter_by(
                code=form.invitation_code.data
            ).first()

            if not invitation_code or not invitation_code.is_valid():
                flash('邀请码无效或已过期。', 'error')
                return render_template(
                    f'themes/{session.get("theme", current_app.config.get("DEFAULT_THEME", "default"))}/auth/register.html',
                    title='注册',
                    form=form
                )

        # 创建新用户
        user = User(
            username=form.username.data,
            email=form.email.data,
            role=UserRole.USER,
            is_active=True,
            email_confirmed=False  # 如果需要邮箱验证
        )
        user.set_password(form.password.data)

        # 如果有邀请码，标记为已使用
        if invitation_code:
            invitation_code.use(user)

        db.session.add(user)
        db.session.commit()

        flash('注册成功！请登录。', 'success')
        return redirect(url_for('auth.login'))

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/auth/register.html',
        title='注册',
        form=form
    )


@bp.route('/forgot-password', methods=['GET', 'POST'])
def forgot_password():
    """忘记密码"""
    if current_user.is_authenticated:
        return redirect(url_for('main.index'))

    form = ForgotPasswordForm()
    if form.validate_on_submit():
        user = User.query.filter_by(email=form.email.data).first()
        if user:
            # TODO: 发送重置密码邮件
            flash('密码重置邮件已发送到您的邮箱。', 'info')
        else:
            flash('该邮箱地址不存在。', 'error')

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/auth/forgot_password.html',
        title='忘记密码',
        form=form
    )


@bp.route('/reset-password/<token>', methods=['GET', 'POST'])
def reset_password(token):
    """重置密码"""
    if current_user.is_authenticated:
        return redirect(url_for('main.index'))

    # TODO: 验证重置密码令牌
    form = ResetPasswordForm()
    if form.validate_on_submit():
        # TODO: 重置密码逻辑
        flash('密码重置成功！', 'success')
        return redirect(url_for('auth.login'))

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/auth/reset_password.html',
        title='重置密码',
        form=form
    )


@bp.route('/profile')
@login_required
def profile():
    """用户资料"""
    # 获取用户统计信息
    user_stats = {
        'categories_count': current_user.categories.count(),
        'websites_count': current_user.websites.count(),
        'total_clicks': db.session.query(
            db.func.sum(Website.click_count)
        ).filter_by(user_id=current_user.id).scalar() or 0
    }

    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/auth/profile.html',
        title='个人资料',
        user_stats=user_stats
    )


@bp.route('/settings')
@login_required
def settings():
    """用户设置"""
    # 获取当前主题
    current_theme = session.get('theme', current_app.config.get('DEFAULT_THEME', 'default'))

    return render_template(
        f'themes/{current_theme}/auth/settings.html',
        title='用户设置'
    )


@bp.route('/check-username')
def check_username():
    """检查用户名是否可用（AJAX）"""
    username = request.args.get('username', '').strip()
    if not username:
        return {'available': False, 'message': '用户名不能为空'}

    # 检查用户名是否已存在
    user = User.query.filter_by(username=username).first()
    if user:
        return {'available': False, 'message': '用户名已被使用'}

    return {'available': True, 'message': '用户名可用'}


@bp.route('/check-email')
def check_email():
    """检查邮箱是否可用（AJAX）"""
    email = request.args.get('email', '').strip()
    if not email:
        return {'available': False, 'message': '邮箱不能为空'}

    # 检查邮箱是否已存在
    user = User.query.filter_by(email=email).first()
    if user:
        return {'available': False, 'message': '邮箱已被使用'}

    return {'available': True, 'message': '邮箱可用'}


@bp.route('/verify-invitation-code')
def verify_invitation_code():
    """验证邀请码（AJAX）"""
    code = request.args.get('code', '').strip()
    if not code:
        return {'valid': False, 'message': '邀请码不能为空'}

    invitation_code = InvitationCode.query.filter_by(code=code).first()
    if not invitation_code:
        return {'valid': False, 'message': '邀请码不存在'}

    if not invitation_code.is_valid():
        return {'valid': False, 'message': '邀请码无效或已过期'}

    return {'valid': True, 'message': '邀请码有效'}