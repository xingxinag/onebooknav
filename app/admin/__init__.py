# -*- coding: utf-8 -*-
"""
OneBookNav 管理蓝图
处理后台管理功能
"""
from flask import Blueprint

bp = Blueprint('admin', __name__)

from app.admin import routes