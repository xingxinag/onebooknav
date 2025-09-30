# -*- coding: utf-8 -*-
"""
OneBookNav 管理表单
后台管理功能的表单定义
"""
from flask_wtf import FlaskForm
from wtforms import StringField, TextAreaField, SelectField, BooleanField, SubmitField, IntegerField, FloatField
from wtforms.validators import DataRequired, Length, Email, Optional, NumberRange, URL


class CategoryForm(FlaskForm):
    """分类表单"""
    name = StringField(
        '分类名称',
        validators=[DataRequired(), Length(max=100)],
        render_kw={'class': 'form-control'}
    )
    description = TextAreaField(
        '描述',
        validators=[Optional(), Length(max=500)],
        render_kw={'class': 'form-control', 'rows': 3}
    )
    icon = StringField(
        '图标',
        validators=[Optional(), Length(max=255)],
        render_kw={'class': 'form-control', 'placeholder': 'Font Awesome图标类名，如：fas fa-folder'}
    )
    color = StringField(
        '颜色',
        validators=[Optional(), Length(max=7)],
        render_kw={'class': 'form-control', 'type': 'color'}
    )
    parent_id = SelectField(
        '父分类',
        choices=[],
        validators=[Optional()],
        render_kw={'class': 'form-select'}
    )
    sort_order = IntegerField(
        '排序',
        validators=[Optional(), NumberRange(min=0)],
        render_kw={'class': 'form-control'}
    )
    is_visible = BooleanField(
        '显示',
        render_kw={'class': 'form-check-input'}
    )
    is_public = BooleanField(
        '公开',
        render_kw={'class': 'form-check-input'}
    )
    submit = SubmitField('保存', render_kw={'class': 'btn btn-primary'})


class WebsiteForm(FlaskForm):
    """网站表单"""
    title = StringField(
        '网站标题',
        validators=[DataRequired(), Length(max=200)],
        render_kw={'class': 'form-control'}
    )
    url = StringField(
        '网站地址',
        validators=[DataRequired(), URL(), Length(max=500)],
        render_kw={'class': 'form-control', 'placeholder': 'https://example.com'}
    )
    description = TextAreaField(
        '描述',
        validators=[Optional(), Length(max=1000)],
        render_kw={'class': 'form-control', 'rows': 3}
    )
    icon = StringField(
        '图标',
        validators=[Optional(), Length(max=255)],
        render_kw={'class': 'form-control', 'placeholder': '图标URL或留空自动获取'}
    )
    keywords = StringField(
        '关键词',
        validators=[Optional(), Length(max=500)],
        render_kw={'class': 'form-control', 'placeholder': '多个关键词用逗号分隔'}
    )
    category_id = SelectField(
        '分类',
        choices=[],
        validators=[DataRequired()],
        render_kw={'class': 'form-select'}
    )
    sort_order = IntegerField(
        '排序',
        validators=[Optional(), NumberRange(min=0)],
        render_kw={'class': 'form-control'}
    )
    is_active = BooleanField(
        '启用',
        render_kw={'class': 'form-check-input'}
    )
    is_public = BooleanField(
        '公开',
        render_kw={'class': 'form-check-input'}
    )
    is_featured = BooleanField(
        '推荐',
        render_kw={'class': 'form-check-input'}
    )
    submit = SubmitField('保存', render_kw={'class': 'btn btn-primary'})


class UserForm(FlaskForm):
    """用户表单"""
    username = StringField(
        '用户名',
        validators=[DataRequired(), Length(min=3, max=80)],
        render_kw={'class': 'form-control'}
    )
    email = StringField(
        '邮箱',
        validators=[DataRequired(), Email()],
        render_kw={'class': 'form-control'}
    )
    nickname = StringField(
        '昵称',
        validators=[Optional(), Length(max=100)],
        render_kw={'class': 'form-control'}
    )
    role = SelectField(
        '角色',
        choices=[
            ('user', '普通用户'),
            ('admin', '管理员'),
            ('superadmin', '超级管理员')
        ],
        validators=[DataRequired()],
        render_kw={'class': 'form-select'}
    )
    is_active = BooleanField(
        '启用',
        render_kw={'class': 'form-check-input'}
    )
    email_confirmed = BooleanField(
        '邮箱已验证',
        render_kw={'class': 'form-check-input'}
    )
    submit = SubmitField('保存', render_kw={'class': 'btn btn-primary'})


class SiteSettingsForm(FlaskForm):
    """网站设置表单"""
    site_name = StringField(
        '网站名称',
        validators=[DataRequired(), Length(max=100)],
        render_kw={'class': 'form-control'}
    )
    site_description = TextAreaField(
        '网站描述',
        validators=[Optional(), Length(max=500)],
        render_kw={'class': 'form-control', 'rows': 3}
    )
    default_theme = SelectField(
        '默认主题',
        choices=[],
        validators=[DataRequired()],
        render_kw={'class': 'form-select'}
    )
    registration_enabled = BooleanField(
        '允许注册',
        render_kw={'class': 'form-check-input'}
    )
    link_check_enabled = BooleanField(
        '启用链接检查',
        render_kw={'class': 'form-check-input'}
    )
    backup_enabled = BooleanField(
        '启用自动备份',
        render_kw={'class': 'form-check-input'}
    )
    submit = SubmitField('保存设置', render_kw={'class': 'btn btn-primary'})


class InvitationCodeForm(FlaskForm):
    """邀请码表单"""
    count = IntegerField(
        '生成数量',
        validators=[DataRequired(), NumberRange(min=1, max=100)],
        default=1,
        render_kw={'class': 'form-control'}
    )
    expires_days = IntegerField(
        '有效期(天)',
        validators=[Optional(), NumberRange(min=1, max=365)],
        render_kw={'class': 'form-control', 'placeholder': '留空为永久有效'}
    )
    submit = SubmitField('生成邀请码', render_kw={'class': 'btn btn-primary'})