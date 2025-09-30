# -*- coding: utf-8 -*-
"""
OneBookNav 数据模型
融合BookNav和OneNav的优点，创建功能强大的导航应用数据模型
"""
import json
import secrets
import string
from datetime import datetime, timedelta
from enum import Enum
from typing import List, Dict, Any, Optional

from flask import current_app
from flask_sqlalchemy import SQLAlchemy
from flask_login import UserMixin
from werkzeug.security import generate_password_hash, check_password_hash
from sqlalchemy import event, func
from sqlalchemy.ext.hybrid import hybrid_property

from app import db


class UserRole(Enum):
    """用户角色枚举"""
    USER = 'user'           # 普通用户
    ADMIN = 'admin'         # 管理员
    SUPERADMIN = 'superadmin'  # 超级管理员


class LinkStatus(Enum):
    """链接状态枚举"""
    ACTIVE = 'active'       # 正常
    BROKEN = 'broken'       # 失效
    CHECKING = 'checking'   # 检查中
    UNKNOWN = 'unknown'     # 未知


# 网站标签关联表（多对多）
website_tags = db.Table(
    'website_tags',
    db.Column('website_id', db.Integer, db.ForeignKey('website.id'), primary_key=True),
    db.Column('tag_id', db.Integer, db.ForeignKey('tag.id'), primary_key=True)
)


class TimestampMixin:
    """时间戳混入类"""
    created_at = db.Column(db.DateTime, default=datetime.utcnow, nullable=False)
    updated_at = db.Column(db.DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)


class User(UserMixin, TimestampMixin, db.Model):
    """用户模型"""
    __tablename__ = 'user'

    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(80), unique=True, nullable=False, index=True)
    email = db.Column(db.String(120), unique=True, nullable=False, index=True)
    password_hash = db.Column(db.String(255), nullable=False)

    # 用户信息
    nickname = db.Column(db.String(100))
    avatar = db.Column(db.String(255))
    bio = db.Column(db.Text)

    # 状态字段
    is_active = db.Column(db.Boolean, default=True, nullable=False)
    email_confirmed = db.Column(db.Boolean, default=False, nullable=False)
    role = db.Column(db.Enum(UserRole), default=UserRole.USER, nullable=False)

    # 时间字段
    last_seen = db.Column(db.DateTime, default=datetime.utcnow)
    registered_at = db.Column(db.DateTime, default=datetime.utcnow)

    # 设置字段
    theme = db.Column(db.String(50), default='default')
    language = db.Column(db.String(10), default='zh')
    timezone = db.Column(db.String(50), default='Asia/Shanghai')

    # 关联关系
    categories = db.relationship('Category', backref='owner', lazy='dynamic', cascade='all, delete-orphan')
    websites = db.relationship('Website', backref='owner', lazy='dynamic', cascade='all, delete-orphan')
    invitation_codes = db.relationship('InvitationCode', backref='creator', lazy='dynamic')

    def set_password(self, password: str):
        """设置密码"""
        self.password_hash = generate_password_hash(password)

    def check_password(self, password: str) -> bool:
        """检查密码"""
        return check_password_hash(self.password_hash, password)

    def update_last_seen(self):
        """更新最后活动时间"""
        self.last_seen = datetime.utcnow()

    def is_admin(self) -> bool:
        """是否为管理员"""
        return self.role in (UserRole.ADMIN, UserRole.SUPERADMIN)

    def is_superadmin(self) -> bool:
        """是否为超级管理员"""
        return self.role == UserRole.SUPERADMIN

    def can_edit_category(self, category) -> bool:
        """是否可以编辑分类"""
        if self.is_admin():
            return True
        return category.user_id == self.id

    def can_edit_website(self, website) -> bool:
        """是否可以编辑网站"""
        if self.is_admin():
            return True
        return website.user_id == self.id

    def get_avatar_url(self, size: int = 64) -> str:
        """获取头像URL"""
        if self.avatar:
            return self.avatar
        # 默认头像或Gravatar
        import hashlib
        digest = hashlib.md5(self.email.lower().encode('utf-8')).hexdigest()
        return f'https://www.gravatar.com/avatar/{digest}?d=identicon&s={size}'

    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        return {
            'id': self.id,
            'username': self.username,
            'email': self.email,
            'nickname': self.nickname,
            'avatar': self.get_avatar_url(),
            'bio': self.bio,
            'role': self.role.value,
            'is_active': self.is_active,
            'email_confirmed': self.email_confirmed,
            'last_seen': self.last_seen.isoformat() if self.last_seen else None,
            'created_at': self.created_at.isoformat()
        }

    def __repr__(self):
        return f'<User {self.username}>'


class Category(TimestampMixin, db.Model):
    """分类模型"""
    __tablename__ = 'category'

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False)
    description = db.Column(db.Text)
    icon = db.Column(db.String(255))
    color = db.Column(db.String(7))  # 十六进制颜色值

    # 排序和显示
    sort_order = db.Column(db.Integer, default=0, nullable=False)
    is_visible = db.Column(db.Boolean, default=True, nullable=False)
    is_public = db.Column(db.Boolean, default=True, nullable=False)

    # 外键
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    parent_id = db.Column(db.Integer, db.ForeignKey('category.id'))

    # 关联关系
    children = db.relationship('Category', backref=db.backref('parent', remote_side=[id]), lazy='dynamic')
    websites = db.relationship('Website', backref='category', lazy='dynamic', cascade='all, delete-orphan')

    @hybrid_property
    def website_count(self):
        """网站数量"""
        return self.websites.filter_by(is_active=True).count()

    def get_all_children(self) -> List['Category']:
        """获取所有子分类（递归）"""
        children = []
        for child in self.children:
            children.append(child)
            children.extend(child.get_all_children())
        return children

    def get_path(self) -> str:
        """获取分类路径"""
        path = [self.name]
        parent = self.parent
        while parent:
            path.insert(0, parent.name)
            parent = parent.parent
        return ' / '.join(path)

    def can_be_parent_of(self, category) -> bool:
        """是否可以作为某分类的父分类"""
        if category.id == self.id:
            return False
        # 检查是否会形成循环
        parent = self.parent
        while parent:
            if parent.id == category.id:
                return False
            parent = parent.parent
        return True

    def to_dict(self, include_websites: bool = False) -> Dict[str, Any]:
        """转换为字典"""
        data = {
            'id': self.id,
            'name': self.name,
            'description': self.description,
            'icon': self.icon,
            'color': self.color,
            'sort_order': self.sort_order,
            'is_visible': self.is_visible,
            'is_public': self.is_public,
            'parent_id': self.parent_id,
            'website_count': self.website_count,
            'path': self.get_path(),
            'created_at': self.created_at.isoformat()
        }

        if include_websites:
            data['websites'] = [w.to_dict() for w in self.websites.filter_by(is_active=True).all()]

        return data

    def __repr__(self):
        return f'<Category {self.name}>'


class Website(TimestampMixin, db.Model):
    """网站模型"""
    __tablename__ = 'website'

    id = db.Column(db.Integer, primary_key=True)
    title = db.Column(db.String(200), nullable=False)
    url = db.Column(db.String(500), nullable=False)
    description = db.Column(db.Text)
    icon = db.Column(db.String(255))

    # SEO和元数据
    keywords = db.Column(db.String(500))
    meta_description = db.Column(db.Text)

    # 状态和设置
    is_active = db.Column(db.Boolean, default=True, nullable=False)
    is_public = db.Column(db.Boolean, default=True, nullable=False)
    is_featured = db.Column(db.Boolean, default=False, nullable=False)

    # 排序和统计
    sort_order = db.Column(db.Integer, default=0, nullable=False)
    click_count = db.Column(db.Integer, default=0, nullable=False)
    last_clicked_at = db.Column(db.DateTime)

    # 链接检查
    link_status = db.Column(db.Enum(LinkStatus), default=LinkStatus.UNKNOWN, nullable=False)
    last_checked_at = db.Column(db.DateTime)
    check_count = db.Column(db.Integer, default=0, nullable=False)
    response_time = db.Column(db.Float)  # 响应时间（毫秒）

    # 外键
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    category_id = db.Column(db.Integer, db.ForeignKey('category.id'), nullable=False)

    # 关联关系
    tags = db.relationship('Tag', secondary=website_tags, backref=db.backref('websites', lazy='dynamic'))

    def increment_click(self):
        """增加点击次数"""
        self.click_count += 1
        self.last_clicked_at = datetime.utcnow()

    def get_domain(self) -> str:
        """获取域名"""
        from urllib.parse import urlparse
        parsed = urlparse(self.url)
        return parsed.netloc

    def get_favicon_url(self) -> str:
        """获取网站图标URL"""
        if self.icon:
            return self.icon
        # 尝试获取网站favicon
        domain = self.get_domain()
        if domain:
            return f'https://www.google.com/s2/favicons?domain={domain}&sz=32'
        return '/static/img/default-favicon.png'

    def update_link_status(self, status: LinkStatus, response_time: Optional[float] = None):
        """更新链接状态"""
        self.link_status = status
        self.last_checked_at = datetime.utcnow()
        self.check_count += 1
        if response_time is not None:
            self.response_time = response_time

    def is_recently_checked(self, hours: int = 24) -> bool:
        """是否最近检查过"""
        if not self.last_checked_at:
            return False
        return datetime.utcnow() - self.last_checked_at < timedelta(hours=hours)

    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        return {
            'id': self.id,
            'title': self.title,
            'url': self.url,
            'description': self.description,
            'icon': self.get_favicon_url(),
            'keywords': self.keywords,
            'is_active': self.is_active,
            'is_public': self.is_public,
            'is_featured': self.is_featured,
            'sort_order': self.sort_order,
            'click_count': self.click_count,
            'last_clicked_at': self.last_clicked_at.isoformat() if self.last_clicked_at else None,
            'link_status': self.link_status.value,
            'last_checked_at': self.last_checked_at.isoformat() if self.last_checked_at else None,
            'response_time': self.response_time,
            'category_id': self.category_id,
            'category_name': self.category.name if self.category else None,
            'tags': [tag.name for tag in self.tags],
            'created_at': self.created_at.isoformat()
        }

    def __repr__(self):
        return f'<Website {self.title}>'


class Tag(TimestampMixin, db.Model):
    """标签模型"""
    __tablename__ = 'tag'

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(50), unique=True, nullable=False, index=True)
    color = db.Column(db.String(7))  # 十六进制颜色值
    description = db.Column(db.Text)

    # 统计字段
    usage_count = db.Column(db.Integer, default=0, nullable=False)

    @hybrid_property
    def website_count(self):
        """网站数量"""
        return self.websites.filter_by(is_active=True).count()

    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        return {
            'id': self.id,
            'name': self.name,
            'color': self.color,
            'description': self.description,
            'website_count': self.website_count,
            'usage_count': self.usage_count,
            'created_at': self.created_at.isoformat()
        }

    def __repr__(self):
        return f'<Tag {self.name}>'


class InvitationCode(TimestampMixin, db.Model):
    """邀请码模型"""
    __tablename__ = 'invitation_code'

    id = db.Column(db.Integer, primary_key=True)
    code = db.Column(db.String(20), unique=True, nullable=False, index=True)
    is_used = db.Column(db.Boolean, default=False, nullable=False)
    used_at = db.Column(db.DateTime)
    expires_at = db.Column(db.DateTime)

    # 外键
    creator_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    used_by_id = db.Column(db.Integer, db.ForeignKey('user.id'))

    # 关联关系
    used_by = db.relationship('User', foreign_keys=[used_by_id], backref='used_invitation_codes')

    @staticmethod
    def generate_code(length: int = 8) -> str:
        """生成邀请码"""
        alphabet = string.ascii_uppercase + string.digits
        return ''.join(secrets.choice(alphabet) for _ in range(length))

    def is_valid(self) -> bool:
        """是否有效"""
        if self.is_used:
            return False
        if self.expires_at and datetime.utcnow() > self.expires_at:
            return False
        return True

    def use(self, user: User):
        """使用邀请码"""
        if not self.is_valid():
            raise ValueError("邀请码无效或已过期")

        self.is_used = True
        self.used_at = datetime.utcnow()
        self.used_by_id = user.id

    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        return {
            'id': self.id,
            'code': self.code,
            'is_used': self.is_used,
            'used_at': self.used_at.isoformat() if self.used_at else None,
            'expires_at': self.expires_at.isoformat() if self.expires_at else None,
            'creator_id': self.creator_id,
            'used_by_id': self.used_by_id,
            'created_at': self.created_at.isoformat()
        }

    def __repr__(self):
        return f'<InvitationCode {self.code}>'


class SiteSettings(TimestampMixin, db.Model):
    """网站设置模型"""
    __tablename__ = 'site_settings'

    id = db.Column(db.Integer, primary_key=True)
    key = db.Column(db.String(100), unique=True, nullable=False, index=True)
    value = db.Column(db.Text)
    value_type = db.Column(db.String(20), default='string', nullable=False)  # string, int, bool, json
    description = db.Column(db.Text)
    category = db.Column(db.String(50), default='general')
    is_public = db.Column(db.Boolean, default=False, nullable=False)

    @classmethod
    def get_settings(cls) -> Dict[str, Any]:
        """获取所有设置"""
        settings = {}
        for setting in cls.query.all():
            settings[setting.key] = setting.get_value()
        return settings

    @classmethod
    def get_value(cls, key: str, default=None):
        """获取设置值"""
        setting = cls.query.filter_by(key=key).first()
        if setting:
            return setting.get_value()
        return default

    @classmethod
    def set_value(cls, key: str, value, value_type: str = 'string', description: str = '', category: str = 'general'):
        """设置值"""
        setting = cls.query.filter_by(key=key).first()
        if not setting:
            setting = cls(key=key, value_type=value_type, description=description, category=category)
            db.session.add(setting)

        setting.set_value(value)
        db.session.commit()

    def get_value(self):
        """获取转换后的值"""
        if self.value is None:
            return None

        if self.value_type == 'int':
            return int(self.value)
        elif self.value_type == 'bool':
            return self.value.lower() in ('true', '1', 'yes', 'on')
        elif self.value_type == 'json':
            try:
                return json.loads(self.value)
            except (json.JSONDecodeError, TypeError):
                return {}
        else:
            return self.value

    def set_value(self, value):
        """设置值"""
        if self.value_type == 'json':
            self.value = json.dumps(value, ensure_ascii=False)
        else:
            self.value = str(value)

    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        return {
            'id': self.id,
            'key': self.key,
            'value': self.get_value(),
            'value_type': self.value_type,
            'description': self.description,
            'category': self.category,
            'is_public': self.is_public,
            'created_at': self.created_at.isoformat()
        }

    def __repr__(self):
        return f'<SiteSettings {self.key}>'


class DeadlinkCheck(TimestampMixin, db.Model):
    """死链检查记录模型"""
    __tablename__ = 'deadlink_check'

    id = db.Column(db.Integer, primary_key=True)
    url = db.Column(db.String(500), nullable=False)
    status_code = db.Column(db.Integer)
    response_time = db.Column(db.Float)  # 响应时间（毫秒）
    error_message = db.Column(db.Text)
    is_accessible = db.Column(db.Boolean, nullable=False)

    # 外键
    website_id = db.Column(db.Integer, db.ForeignKey('website.id'), nullable=False)

    # 关联关系
    website = db.relationship('Website', backref='check_records')

    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        return {
            'id': self.id,
            'url': self.url,
            'status_code': self.status_code,
            'response_time': self.response_time,
            'error_message': self.error_message,
            'is_accessible': self.is_accessible,
            'website_id': self.website_id,
            'created_at': self.created_at.isoformat()
        }

    def __repr__(self):
        return f'<DeadlinkCheck {self.url}>'


# 事件监听器
@event.listens_for(Tag, 'before_delete')
def update_tag_usage_count(mapper, connection, target):
    """删除标签前更新使用计数"""
    # 这里可以添加清理逻辑
    pass


@event.listens_for(Website, 'after_insert')
def update_category_website_count(mapper, connection, target):
    """网站插入后更新分类的网站计数"""
    # SQLAlchemy会自动处理这个关系
    pass


# 初始化默认数据
def init_default_data():
    """初始化默认数据"""
    # 创建默认管理员用户
    admin = User.query.filter_by(username='admin').first()
    if not admin:
        admin = User(
            username='admin',
            email='admin@onebooknav.com',
            role=UserRole.SUPERADMIN,
            is_active=True,
            email_confirmed=True
        )
        admin.set_password('admin123')
        db.session.add(admin)

    # 创建默认分类
    if not Category.query.first():
        categories = [
            {'name': '常用工具', 'description': '日常使用的各种工具', 'icon': 'fas fa-tools', 'color': '#3498db'},
            {'name': '开发资源', 'description': '编程开发相关资源', 'icon': 'fas fa-code', 'color': '#2ecc71'},
            {'name': '学习教育', 'description': '学习和教育相关网站', 'icon': 'fas fa-graduation-cap', 'color': '#e74c3c'},
            {'name': '娱乐休闲', 'description': '娱乐和休闲网站', 'icon': 'fas fa-gamepad', 'color': '#f39c12'},
        ]

        for i, cat_data in enumerate(categories):
            category = Category(
                name=cat_data['name'],
                description=cat_data['description'],
                icon=cat_data['icon'],
                color=cat_data['color'],
                sort_order=i,
                user_id=admin.id
            )
            db.session.add(category)

    # 创建默认设置
    default_settings = [
        ('site_name', 'OneBookNav', 'string', '网站名称', 'general'),
        ('site_description', '融合BookNav和OneNav优点的导航应用', 'string', '网站描述', 'general'),
        ('default_theme', 'default', 'string', '默认主题', 'appearance'),
        ('registration_enabled', 'true', 'bool', '是否允许注册', 'user'),
        ('link_check_enabled', 'true', 'bool', '是否启用链接检查', 'system'),
        ('backup_enabled', 'true', 'bool', '是否启用自动备份', 'system'),
    ]

    for key, value, value_type, description, category in default_settings:
        if not SiteSettings.query.filter_by(key=key).first():
            setting = SiteSettings(
                key=key,
                value=value,
                value_type=value_type,
                description=description,
                category=category,
                is_public=True
            )
            db.session.add(setting)

    db.session.commit()
    print("Default data initialized successfully!")