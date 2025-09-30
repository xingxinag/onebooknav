# -*- coding: utf-8 -*-
"""
OneBookNav API蓝图
提供RESTful API接口
"""
from flask import Blueprint

bp = Blueprint('api', __name__)

from app.api import routes