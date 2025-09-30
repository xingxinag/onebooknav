<?php
/**
 * OneBookNav - 基础控制器
 *
 * 所有控制器的基类，提供共同的功能和方法
 */

abstract class BaseController
{
    protected $app;
    protected $db;
    protected $config;
    protected $session;
    protected $auth;
    protected $request;
    protected $response;

    public function __construct()
    {
        $this->app = Application::getInstance();
        $this->db = $this->app->getDatabase();
        $this->config = $this->app->getConfig();
        $this->session = $this->app->get('session');
        $this->auth = $this->app->get('AuthService');
        $this->request = new Request();
        $this->response = new Response();

        // 控制器初始化
        $this->initialize();
    }

    /**
     * 控制器初始化方法，子类可以重写
     */
    protected function initialize()
    {
        // 子类可以重写此方法进行初始化
    }

    /**
     * 渲染视图
     */
    protected function view($template, $data = [])
    {
        $viewRenderer = new ViewRenderer($this->config);
        return $viewRenderer->render($template, $data);
    }

    /**
     * 返回 JSON 响应
     */
    protected function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 返回成功的 JSON 响应
     */
    protected function success($data = null, $message = 'Success')
    {
        $response = [
            'success' => true,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $this->json($response);
    }

    /**
     * 返回错误的 JSON 响应
     */
    protected function error($message, $status = 400, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return $this->json($response, $status);
    }

    /**
     * 重定向
     */
    protected function redirect($url, $status = 302)
    {
        http_response_code($status);
        header("Location: {$url}");
        exit;
    }

    /**
     * 重定向并带上成功消息
     */
    protected function redirectWithSuccess($url, $message)
    {
        $this->session->flash('success', $message);
        return $this->redirect($url);
    }

    /**
     * 重定向并带上错误消息
     */
    protected function redirectWithError($url, $message)
    {
        $this->session->flash('error', $message);
        return $this->redirect($url);
    }

    /**
     * 重定向回上一页
     */
    protected function redirectBack($fallback = '/')
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        return $this->redirect($referer);
    }

    /**
     * 验证请求数据
     */
    protected function validate($rules, $data = null)
    {
        if ($data === null) {
            $data = $this->request->all();
        }

        $validator = new Validator($data, $rules);
        return $validator->validate();
    }

    /**
     * 检查用户是否已登录
     */
    protected function requireAuth()
    {
        if (!$this->auth->check()) {
            if ($this->isApiRequest()) {
                return $this->error('Authentication required', 401);
            } else {
                return $this->redirect('/login');
            }
        }
    }

    /**
     * 检查用户权限
     */
    protected function requireRole($role)
    {
        $this->requireAuth();

        if (!$this->auth->hasRole($role)) {
            if ($this->isApiRequest()) {
                return $this->error('Insufficient permissions', 403);
            } else {
                return $this->redirect('/403');
            }
        }
    }

    /**
     * 检查是否是 API 请求
     */
    protected function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') === 0 ||
               strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }

    /**
     * 检查是否是 AJAX 请求
     */
    protected function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * 检查 CSRF 令牌
     */
    protected function verifyCsrfToken()
    {
        $token = $this->request->input('csrf_token') ?: $this->request->header('X-CSRF-TOKEN');

        if (!$this->session->verifyCsrfToken($token)) {
            if ($this->isApiRequest()) {
                return $this->error('CSRF token mismatch', 419);
            } else {
                return $this->redirectWithError('/', 'CSRF token mismatch');
            }
        }
    }

    /**
     * 生成 CSRF 令牌
     */
    protected function getCsrfToken()
    {
        return $this->session->getCsrfToken();
    }

    /**
     * 获取分页参数
     */
    protected function getPaginationParams()
    {
        $page = max(1, (int)$this->request->input('page', 1));
        $perPage = min(100, max(1, (int)$this->request->input('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset
        ];
    }

    /**
     * 创建分页响应
     */
    protected function paginate($items, $total, $page, $perPage)
    {
        $totalPages = ceil($total / $perPage);

        return [
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
                'next_page' => $page < $totalPages ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null
            ]
        ];
    }

    /**
     * 记录审计日志
     */
    protected function auditLog($action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null)
    {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $this->auth->id(),
            $action,
            $tableName,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    /**
     * 处理文件上传
     */
    protected function handleFileUpload($fieldName, $allowedTypes = null, $maxSize = null)
    {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $file = $_FILES[$fieldName];
        $uploadConfig = $this->config['upload'];

        // 检查文件大小
        $maxSize = $maxSize ?: $uploadConfig['max_size'] * 1024;
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds maximum allowed size');
        }

        // 检查文件类型
        $allowedTypes = $allowedTypes ?: $uploadConfig['allowed_types'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExtension, $allowedTypes)) {
            throw new Exception('File type not allowed');
        }

        // 生成唯一文件名
        $filename = uniqid() . '.' . $fileExtension;
        $uploadPath = $uploadConfig['path'] . '/' . $filename;

        // 确保上传目录存在
        if (!is_dir($uploadConfig['path'])) {
            mkdir($uploadConfig['path'], 0755, true);
        }

        // 移动文件
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to upload file');
        }

        return [
            'filename' => $filename,
            'original_name' => $file['name'],
            'path' => $uploadPath,
            'url' => $uploadConfig['url'] . '/' . $filename,
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }

    /**
     * 获取客户端 IP
     */
    protected function getClientIp()
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}