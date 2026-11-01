<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class ViewHelper
 * Hỗ trợ hiển thị giao diện, escape dữ liệu XSS và render layout/view, xuất JSON response.
 */
class ViewHelper
{
    /**
     * Escape chuỗi HTML chống tấn công XSS
     *
     * @param mixed $value
     * @return string
     */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Tạo đường dẫn URL đầy đủ kèm BASE_URL của dự án
     *
     * @param string $path
     * @return string
     */
    public static function url(string $path = ''): string
    {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        $sub = ltrim($path, '/');
        return $sub === '' ? ($base ?: '/') : $base . '/' . $sub;
    }

    /**
     * Trả về JSON response thống nhất theo quy định Mục 5.2
     * Định dạng: {"thanh_cong": bool, "thong_bao": string, "du_lieu": array}
     *
     * @param bool $success
     * @param string $message
     * @param array $data
     * @param int $statusCode
     * @return void
     */
    public static function json(bool $success, string $message = '', array $data = [], int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'thanh_cong' => $success,
            'thong_bao' => $message,
            'du_lieu' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Render view template kèm layout header/footer
     *
     * @param string $view Đường dẫn view (VD: 'auth/login', 'dieu_phoi/index')
     * @param array $data Dữ liệu truyền sang view
     * @param string|null $layout Thư mục layout (mặc định 'layouts', truyền null nếu không dùng layout)
     * @return void
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts'): void
    {
        // Giải nén các biến sang view
        extract($data, EXTR_SKIP);

        $viewFile = __DIR__ . '/../Views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            echo "<p style='color:red;'>[Lỗi Render]: Không tìm thấy file view <code>{$viewFile}</code></p>";
            return;
        }

        // Nếu có layout, nhúng header -> view -> footer
        if ($layout !== null) {
            $headerFile = __DIR__ . '/../Views/' . $layout . '/header.php';
            $footerFile = __DIR__ . '/../Views/' . $layout . '/footer.php';

            if (file_exists($headerFile)) {
                require $headerFile;
            }

            require $viewFile;

            if (file_exists($footerFile)) {
                require $footerFile;
            }
        } else {
            require $viewFile;
        }
    }
}
