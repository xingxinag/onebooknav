<?php

namespace App\Core;

/**
 * HTTP 响应类
 *
 * 封装HTTP响应处理
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $content = '';
    private array $cookies = [];

    /**
     * 设置状态码
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * 获取状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 设置响应头
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 获取响应头
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * 设置多个响应头
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * 获取所有响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 设置Cookie
     */
    public function setCookie(string $name, string $value, int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = true): self
    {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly
        ];
        return $this;
    }

    /**
     * 删除Cookie
     */
    public function deleteCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->setCookie($name, '', time() - 3600, $path, $domain);
    }

    /**
     * 设置内容类型
     */
    public function setContentType(string $type, string $charset = 'utf-8'): self
    {
        return $this->setHeader('Content-Type', $type . '; charset=' . $charset);
    }

    /**
     * 输出内容
     */
    public function write(string $content): self
    {
        $this->content .= $content;
        return $this;
    }

    /**
     * 设置内容
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * 获取内容
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * 输出JSON响应
     */
    public function json($data, int $flags = JSON_UNESCAPED_UNICODE): self
    {
        $this->setContentType('application/json');
        $this->setContent(json_encode($data, $flags));
        return $this;
    }

    /**
     * 输出成功的JSON响应
     */
    public function success($data = null, string $message = '操作成功'): self
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * 输出错误的JSON响应
     */
    public function error(string $message = '操作失败', int $code = 400, $data = null): self
    {
        $this->setStatusCode($code);
        return $this->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'code' => $code
        ]);
    }

    /**
     * 输出分页JSON响应
     */
    public function paginate(array $items, int $total, int $page, int $perPage): self
    {
        return $this->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
                'has_next' => $page * $perPage < $total,
                'has_prev' => $page > 1
            ]
        ]);
    }

    /**
     * 重定向
     */
    public function redirect(string $url, int $code = 302): void
    {
        $this->setStatusCode($code);
        $this->setHeader('Location', $url);
        $this->send();
        exit;
    }

    /**
     * 重定向到上一页
     */
    public function back(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        $this->redirect($referer);
    }

    /**
     * 下载文件
     */
    public function download(string $filepath, string $filename = null): self
    {
        if (!file_exists($filepath)) {
            $this->setStatusCode(404);
            return $this->error('文件不存在');
        }

        $filename = $filename ?: basename($filepath);
        $filesize = filesize($filepath);
        $mimetype = mime_content_type($filepath) ?: 'application/octet-stream';

        $this->setHeaders([
            'Content-Type' => $mimetype,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => $filesize,
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Pragma' => 'public',
            'Expires' => '0'
        ]);

        $this->setContent(file_get_contents($filepath));
        return $this;
    }

    /**
     * 发送文件内容（不下载）
     */
    public function file(string $filepath): self
    {
        if (!file_exists($filepath)) {
            $this->setStatusCode(404);
            return $this->error('文件不存在');
        }

        $mimetype = mime_content_type($filepath) ?: 'application/octet-stream';
        $this->setContentType($mimetype, '');
        $this->setContent(file_get_contents($filepath));
        return $this;
    }

    /**
     * 渲染视图
     */
    public function view(string $template, array $data = []): self
    {
        $templateFile = ROOT_PATH . '/templates/' . ltrim($template, '/') . '.php';

        if (!file_exists($templateFile)) {
            throw new \Exception("Template file not found: {$templateFile}");
        }

        // 提取变量到当前作用域
        extract($data, EXTR_OVERWRITE);

        // 开始输出缓冲
        ob_start();
        include $templateFile;
        $content = ob_get_clean();

        $this->setContentType('text/html');
        $this->setContent($content);
        return $this;
    }

    /**
     * 缓存响应
     */
    public function cache(int $seconds): self
    {
        $this->setHeaders([
            'Cache-Control' => 'public, max-age=' . $seconds,
            'Expires' => gmdate('D, d M Y H:i:s T', time() + $seconds),
            'Last-Modified' => gmdate('D, d M Y H:i:s T', time())
        ]);
        return $this;
    }

    /**
     * 禁用缓存
     */
    public function noCache(): self
    {
        $this->setHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
        return $this;
    }

    /**
     * 设置CORS头
     */
    public function cors(array $origins = ['*'], array $methods = ['GET', 'POST', 'PUT', 'DELETE'], array $headers = ['Content-Type', 'Authorization']): self
    {
        $this->setHeaders([
            'Access-Control-Allow-Origin' => implode(', ', $origins),
            'Access-Control-Allow-Methods' => implode(', ', $methods),
            'Access-Control-Allow-Headers' => implode(', ', $headers),
            'Access-Control-Max-Age' => '3600'
        ]);
        return $this;
    }

    /**
     * 发送响应
     */
    public function send(): void
    {
        // 如果已经发送过头部，则不再发送
        if (headers_sent()) {
            echo $this->content;
            return;
        }

        // 设置状态码
        http_response_code($this->statusCode);

        // 发送响应头
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        // 设置Cookie
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            );
        }

        // 输出内容
        echo $this->content;
    }

    /**
     * 检查是否已发送
     */
    public function isSent(): bool
    {
        return headers_sent();
    }

    /**
     * 清空响应
     */
    public function clear(): self
    {
        $this->statusCode = 200;
        $this->headers = [];
        $this->content = '';
        $this->cookies = [];
        return $this;
    }

    /**
     * 输出XML响应
     */
    public function xml(string $xml): self
    {
        $this->setContentType('application/xml');
        $this->setContent($xml);
        return $this;
    }

    /**
     * 输出纯文本响应
     */
    public function text(string $text): self
    {
        $this->setContentType('text/plain');
        $this->setContent($text);
        return $this;
    }

    /**
     * 输出HTML响应
     */
    public function html(string $html): self
    {
        $this->setContentType('text/html');
        $this->setContent($html);
        return $this;
    }

    /**
     * 设置安全头
     */
    public function secure(): self
    {
        $this->setHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ]);
        return $this;
    }

    /**
     * 魔术方法：自动发送响应
     */
    public function __destruct()
    {
        if (!$this->isSent() && !empty($this->content)) {
            $this->send();
        }
    }
}