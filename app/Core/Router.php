<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Router
 * Quản lý định tuyến HTTP request và phân phối controller/action.
 * Tự động cắt bỏ BASE_URL và hỗ trợ dynamic route parameters ({id}).
 */
class Router
{
    /**
     * @var array Danh sách các route đã đăng ký [METHOD => [path => handler]]
     */
    protected array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    /**
     * Đăng ký route GET
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function get(string $path, callable|array $handler): void
    {
        $this->routes['GET'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Đăng ký route POST
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Chuẩn hóa đường dẫn: luôn bắt đầu bằng '/' và loại bỏ slash thừa ở cuối
     *
     * @param string $path
     * @return string
     */
    protected function normalizePath(string $path): string
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? '/' : '/' . $trimmed;
    }

    /**
     * Làm sạch URI và cắt bỏ BASE_URL
     *
     * @param string $uri
     * @return string
     */
    public function cleanUri(string $uri): string
    {
        // 1. Tách bỏ Query String (phần sau dấu ?)
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // 2. Cắt BASE_URL nếu đã định nghĩa trong config
        if (defined('BASE_URL') && BASE_URL !== '') {
            $baseUrl = rtrim(BASE_URL, '/');
            if ($baseUrl !== '' && str_starts_with($path, $baseUrl)) {
                $path = substr($path, strlen($baseUrl));
            }
        }

        // Dự phòng: cắt bỏ đường dẫn thư mục cha nếu chạy trực tiếp qua XAMPP subfolder
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = str_replace('\\', '/', $scriptDir);
        if ($scriptDir !== '/' && $scriptDir !== '.' && $scriptDir !== '' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        return $this->normalizePath($path);
    }

    /**
     * Điều phối request hiện tại dựa trên URI và method.
     * Cắt BASE_URL khỏi đường dẫn trước khi so khớp route.
     *
     * @param string $method
     * @param string $uri
     * @return void
     */
    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $cleanPath = $this->cleanUri($uri);

        $methodRoutes = $this->routes[$method] ?? [];

        // 1. So khớp chính xác (Exact Match)
        if (isset($methodRoutes[$cleanPath])) {
            $this->executeHandler($methodRoutes[$cleanPath], []);
            return;
        }

        // 2. So khớp động với tham số {param} (Regex Match)
        foreach ($methodRoutes as $routePath => $handler) {
            if (!str_contains($routePath, '{')) {
                continue;
            }

            // Chuyển {id} thành (?P<id>[^/]+)
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $routePath);
            $regex = '#^' . $pattern . '$#';

            if (preg_match($regex, $cleanPath, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        // Tự động ép kiểu int nếu là số nguyên
                        $params[$key] = ctype_digit($value) ? (int) $value : $value;
                    }
                }

                $this->executeHandler($handler, array_values($params));
                return;
            }
        }

        // 3. Không tìm thấy route -> Trả về lỗi 404
        $this->handleNotFound($cleanPath);
    }

    /**
     * Thực thi handler của route
     *
     * @param callable|array $handler
     * @param array $params
     * @return void
     */
    protected function executeHandler(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $action] = $handler;
            if (is_string($class) && class_exists($class)) {
                $instance = new $class();
                call_user_func_array([$instance, $action], $params);
                return;
            }
        }

        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }

        $this->handleNotFound('');
    }

    /**
     * Xử lý khi không tìm thấy route (404)
     *
     * @param string $path
     * @return void
     */
    protected function handleNotFound(string $path): void
    {
        http_response_code(404);

        if (str_starts_with($path, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'thanh_cong' => false,
                'thong_bao' => 'Endpoint API không tồn tại (404 Not Found)',
                'du_lieu' => ['path' => $path],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>404 Not Found</title></head>';
        echo '<body style="font-family:sans-serif; text-align:center; padding:50px;">';
        echo '<h1>404 - Không tìm thấy trang</h1>';
        echo '<p>Đường dẫn <code>' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</code> không tồn tại.</p>';
        echo '<a href="' . (defined('BASE_URL') ? BASE_URL : '/') . '">Về trang chủ</a>';
        echo '</body></html>';
        exit;
    }
}
