# -*- coding: utf-8 -*-
"""
OneBookNav 主页面蓝图
处理主页面、分类页面、搜索等核心功能
"""
from flask import Blueprint

bp = Blueprint('main', __name__)

from app.main import routes