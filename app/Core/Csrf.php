<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Csrf
 * Quản lý tạo và xác thực mã chống tấn công CSRF (Cross-Site Request Forgery).
 */
class Csrf
{
    protected const SESSION_KEY = '_csrf_token';

    /**
     * @var string|null Lưu tạm trong bộ nhớ phòng trường hợp CLI không ghi được session file
     */
    protected static ?string $memoryToken = null;

    /**
     * Tạo hoặc lấy CSRF token hiện tại trong session
     *
     * @return string
     */
    public static function getToken(): string
    {
        Session::start();

        if (!empty($_SESSION[self::SESSION_KEY])) {
            return (string) $_SESSION[self::SESSION_KEY];
        }

        if (self::$memoryToken !== null) {
            return self::$memoryToken;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        self::$memoryToken = $token;

        return $token;
    }

    /**
     * Xác thực CSRF token từ header HTTP_X_CSRF_TOKEN hoặc từ POST / body
     *
     * @param string|null $token Nếu truyền null, hàm tự tìm trong headers hoặc $_POST
     * @return bool
     */
    public static function validate(?string $token = null): bool
    {
        Session::start();
        $stored = self::getToken();

        if ($token === null) {
            // 1. Kiểm tra header X-CSRF-Token (thường dùng cho fetch/axios)
            if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
            } elseif (!empty($_POST['_csrf_token'])) {
                // 2. Kiểm tra input hidden form
                $token = $_POST['_csrf_token'];
            } else {
                // 3. Kiểm tra trong raw JSON body nếu có
                $input = json_decode((string) @file_get_contents('php://input'), true);
                if (is_array($input) && !empty($input['_csrf_token'])) {
                    $token = (string) $input['_csrf_token'];
                }
            }
        }

        if (empty($token) || !is_string($token)) {
            return false;
        }

        return hash_equals($stored, $token);
    }
}
