# -*- coding: utf-8 -*-
"""
OneBookNav 认证蓝图
处理用户登录、注册、注销等认证功能
"""
from flask import Blueprint

bp = Blueprint('auth', __name__)

from app.auth import routes