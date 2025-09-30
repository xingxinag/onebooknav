# -*- coding: utf-8 -*-
"""
OneBookNav 主页面表单
主要用于搜索等功能的表单定义
"""
from flask_wtf import FlaskForm
from wtforms import StringField, SelectField, SubmitField, HiddenField
from wtforms.validators import DataRequired, Length, Optional


class SearchForm(FlaskForm):
    """搜索表单"""
    q = StringField(
        '搜索关键词',
        validators=[DataRequired(), Length(min=1, max=100)],
        render_kw={'placeholder': '搜索网站、描述或关键词...', 'class': 'form-control'}
    )
    submit = SubmitField('搜索', render_kw={'class': 'btn btn-primary'})


class QuickSearchForm(FlaskForm):
    """快速搜索表单（导航栏）"""
    q = StringField(
        validators=[Optional(), Length(max=100)],
        render_kw={'placeholder': '快速搜索...', 'class': 'form-control form-control-sm'}
    )


class ThemeSwitchForm(FlaskForm):
    """主题切换表单"""
    theme = SelectField(
        '选择主题',
        choices=[],  # 将在视图中动态设置
        validators=[DataRequired()],
        render_kw={'class': 'form-select'}
    )
    submit = SubmitField('切换', render_kw={'class': 'btn btn-outline-primary btn-sm'})


class SortForm(FlaskForm):
    """排序表单"""
    sort_by = SelectField(
        '排序方式',
        choices=[
            ('created_at', '创建时间'),
            ('updated_at', '更新时间'),
            ('click_count', '点击次数'),
            ('title', '标题'),
            ('sort_order', '自定义排序')
        ],
        default='sort_order',
        render_kw={'class': 'form-select form-select-sm'}
    )
    order = SelectField(
        '排序顺序',
        choices=[
            ('asc', '升序'),
            ('desc', '降序')
        ],
        default='asc',
        render_kw={'class': 'form-select form-select-sm'}
    )


class FilterForm(FlaskForm):
    """过滤表单"""
    category = SelectField(
        '分类',
        choices=[('', '全部分类')],  # 将在视图中动态设置
        validators=[Optional()],
        render_kw={'class': 'form-select form-select-sm'}
    )
    tag = SelectField(
        '标签',
        choices=[('', '全部标签')],  # 将在视图中动态设置
        validators=[Optional()],
        render_kw={'class': 'form-select form-select-sm'}
    )
    status = SelectField(
        '状态',
        choices=[
            ('', '全部状态'),
            ('active', '正常'),
            ('broken', '失效'),
            ('unknown', '未检查')
        ],
        validators=[Optional()],
        render_kw={'class': 'form-select form-select-sm'}
    )


class WebsiteActionForm(FlaskForm):
    """网站操作表单"""
    website_id = HiddenField('网站ID', validators=[DataRequired()])
    action = HiddenField('操作', validators=[DataRequired()])
    submit = SubmitField('确认')


class BatchActionForm(FlaskForm):
    """批量操作表单"""
    website_ids = HiddenField('网站ID列表', validators=[DataRequired()])
    action = SelectField(
        '批量操作',
        choices=[
            ('', '选择操作'),
            ('delete', '删除'),
            ('move', '移动到其他分类'),
            ('add_tag', '添加标签'),
            ('remove_tag', '移除标签'),
            ('check_links', '检查链接')
        ],
        validators=[DataRequired()],
        render_kw={'class': 'form-select'}
    )
    target_category = SelectField(
        '目标分类',
        choices=[],  # 将在视图中动态设置
        validators=[Optional()],
        render_kw={'class': 'form-select'}
    )
    target_tag = StringField(
        '标签名称',
        validators=[Optional(), Length(max=50)],
        render_kw={'placeholder': '输入标签名称', 'class': 'form-control'}
    )
    submit = SubmitField('执行', render_kw={'class': 'btn btn-warning'})