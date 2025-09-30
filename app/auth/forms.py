# -*- coding: utf-8 -*-
"""
OneBookNav 认证表单
处理用户登录、注册等表单定义
"""
from flask_wtf import FlaskForm
from wtforms import StringField, PasswordField, BooleanField, SubmitField, TextAreaField
from wtforms.validators import DataRequired, Length, Email, EqualTo, ValidationError, Optional
from wtforms.widgets import TextArea

from app.models import User


class LoginForm(FlaskForm):
    """登录表单"""
    username = StringField(
        '用户名/邮箱',
        validators=[DataRequired(), Length(min=3, max=80)],
        render_kw={'placeholder': '请输入用户名或邮箱', 'class': 'form-control'}
    )
    password = PasswordField(
        '密码',
        validators=[DataRequired()],
        render_kw={'placeholder': '请输入密码', 'class': 'form-control'}
    )
    remember_me = BooleanField(
        '记住我',
        render_kw={'class': 'form-check-input'}
    )
    submit = SubmitField('登录', render_kw={'class': 'btn btn-primary w-100'})


class RegistrationForm(FlaskForm):
    """注册表单"""
    username = StringField(
        '用户名',
        validators=[DataRequired(), Length(min=3, max=20)],
        render_kw={'placeholder': '请输入用户名(3-20个字符)', 'class': 'form-control'}
    )
    email = StringField(
        '邮箱',
        validators=[DataRequired(), Email()],
        render_kw={'placeholder': '请输入邮箱地址', 'class': 'form-control'}
    )
    password = PasswordField(
        '密码',
        validators=[DataRequired(), Length(min=6)],
        render_kw={'placeholder': '请输入密码(至少6位)', 'class': 'form-control'}
    )
    password2 = PasswordField(
        '确认密码',
        validators=[DataRequired(), EqualTo('password', message='两次输入的密码不一致')],
        render_kw={'placeholder': '请再次输入密码', 'class': 'form-control'}
    )
    invitation_code = StringField(
        '邀请码',
        validators=[Optional(), Length(max=20)],
        render_kw={'placeholder': '请输入邀请码(可选)', 'class': 'form-control'}
    )
    submit = SubmitField('注册', render_kw={'class': 'btn btn-primary w-100'})

    def validate_username(self, username):
        """验证用户名唯一性"""
        user = User.query.filter_by(username=username.data).first()
        if user is not None:
            raise ValidationError('用户名已被使用，请选择其他用户名。')

        # 检查用户名格式
        import re
        if not re.match(r'^[a-zA-Z0-9_]+$', username.data):
            raise ValidationError('用户名只能包含字母、数字和下划线。')

    def validate_email(self, email):
        """验证邮箱唯一性"""
        user = User.query.filter_by(email=email.data).first()
        if user is not None:
            raise ValidationError('邮箱已被使用，请选择其他邮箱。')


class ForgotPasswordForm(FlaskForm):
    """忘记密码表单"""
    email = StringField(
        '邮箱',
        validators=[DataRequired(), Email()],
        render_kw={'placeholder': '请输入注册时的邮箱地址', 'class': 'form-control'}
    )
    submit = SubmitField('发送重置邮件', render_kw={'class': 'btn btn-primary w-100'})


class ResetPasswordForm(FlaskForm):
    """重置密码表单"""
    password = PasswordField(
        '新密码',
        validators=[DataRequired(), Length(min=6)],
        render_kw={'placeholder': '请输入新密码(至少6位)', 'class': 'form-control'}
    )
    password2 = PasswordField(
        '确认新密码',
        validators=[DataRequired(), EqualTo('password', message='两次输入的密码不一致')],
        render_kw={'placeholder': '请再次输入新密码', 'class': 'form-control'}
    )
    submit = SubmitField('重置密码', render_kw={'class': 'btn btn-primary w-100'})


class ChangePasswordForm(FlaskForm):
    """修改密码表单"""
    old_password = PasswordField(
        '当前密码',
        validators=[DataRequired()],
        render_kw={'placeholder': '请输入当前密码', 'class': 'form-control'}
    )
    password = PasswordField(
        '新密码',
        validators=[DataRequired(), Length(min=6)],
        render_kw={'placeholder': '请输入新密码(至少6位)', 'class': 'form-control'}
    )
    password2 = PasswordField(
        '确认新密码',
        validators=[DataRequired(), EqualTo('password', message='两次输入的密码不一致')],
        render_kw={'placeholder': '请再次输入新密码', 'class': 'form-control'}
    )
    submit = SubmitField('修改密码', render_kw={'class': 'btn btn-primary'})


class ProfileForm(FlaskForm):
    """个人资料表单"""
    nickname = StringField(
        '昵称',
        validators=[Optional(), Length(max=100)],
        render_kw={'placeholder': '请输入昵称', 'class': 'form-control'}
    )
    bio = TextAreaField(
        '个人简介',
        validators=[Optional(), Length(max=500)],
        render_kw={
            'placeholder': '请输入个人简介',
            'class': 'form-control',
            'rows': 3
        }
    )
    theme = StringField(
        '默认主题',
        validators=[Optional(), Length(max=50)],
        render_kw={'class': 'form-select'}
    )
    language = StringField(
        '语言',
        validators=[Optional(), Length(max=10)],
        render_kw={'class': 'form-select'}
    )
    timezone = StringField(
        '时区',
        validators=[Optional(), Length(max=50)],
        render_kw={'class': 'form-select'}
    )
    submit = SubmitField('保存设置', render_kw={'class': 'btn btn-primary'})


class UserSettingsForm(FlaskForm):
    """用户设置表单"""
    email_notifications = BooleanField(
        '邮件通知',
        render_kw={'class': 'form-check-input'}
    )
    public_profile = BooleanField(
        '公开个人资料',
        render_kw={'class': 'form-check-input'}
    )
    show_statistics = BooleanField(
        '显示统计信息',
        render_kw={'class': 'form-check-input'}
    )
    auto_check_links = BooleanField(
        '自动检查链接',
        render_kw={'class': 'form-check-input'}
    )
    submit = SubmitField('保存设置', render_kw={'class': 'btn btn-primary'})