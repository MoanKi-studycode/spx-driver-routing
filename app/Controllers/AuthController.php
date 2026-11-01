<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Repositories\NguoiDungRepository;
use PDOException;

/**
 * Xử lý đăng nhập, đăng xuất và quản lý phiên làm việc theo vai trò và bưu cục.
 */
class AuthController
{
    private const CSRF_SESSION_KEY = '_csrf_token';

    /**
     * Hiển thị trang đăng nhập.
     */
    public function showLogin(): void
    {
        $this->ensureSession();

        $role = $_SESSION['vai_tro'] ?? null;
        if ($role === 'tai_xe' || $role === 'dieu_phoi') {
            $this->redirect($role === 'tai_xe' ? '/tai-xe' : '/dieu-phoi');
        }

        $title = 'Đăng nhập';
        $csrfToken = $this->csrfToken();
        $loginError = null;
        $statusCode = 200;

        $this->renderLogin($title, $csrfToken, $loginError, $statusCode);
    }

    /**
     * Xử lý xác thực đăng nhập.
     */
    public function login(): void
    {
        $this->ensureSession();

        $csrfToken = $this->csrfToken();
        if (!$this->hasValidCsrfToken()) {
            $this->renderLogin('Đăng nhập', $csrfToken, 'Phiên gửi biểu mẫu không hợp lệ. Vui lòng thử lại.', 419);
            return;
        }

        $username = trim((string) ($_POST['ten_dang_nhap'] ?? ''));
        $password = (string) ($_POST['mat_khau'] ?? '');
        if ($username === '' || $password === '' || strlen($username) > 50) {
            $this->renderLogin('Đăng nhập', $csrfToken, 'Vui lòng nhập tên đăng nhập và mật khẩu.', 422);
            return;
        }

        try {
            $repository = new NguoiDungRepository(Database::getConnection());
            $user = $repository->findByUsername($username);
        } catch (PDOException $exception) {
            error_log('Không thể truy vấn người dùng khi đăng nhập: ' . $exception->getMessage());
            $this->renderLogin('Đăng nhập', $csrfToken, 'Dịch vụ đăng nhập hiện không khả dụng. Vui lòng thử lại sau.', 503);
            return;
        }

        if (
            !is_array($user)
            || !isset($user['mat_khau_bam'])
            || !password_verify($password, (string) $user['mat_khau_bam'])
            || (int) ($user['dang_hoat_dong'] ?? 0) !== 1
        ) {
            $this->renderLogin('Đăng nhập', $csrfToken, 'Tên đăng nhập hoặc mật khẩu không chính xác.', 401);
            return;
        }

        $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
        $branchId = filter_var($user['buu_cuc_id'] ?? null, FILTER_VALIDATE_INT);
        $role = $user['vai_tro'] ?? null;
        if (
            $userId === false
            || $userId < 1
            || $branchId === false
            || $branchId < 1
            || !in_array($role, ['tai_xe', 'dieu_phoi'], true)
        ) {
            error_log('Bản ghi người dùng thiếu thông tin định danh hoặc vai trò hợp lệ.');
            $this->renderLogin('Đăng nhập', $csrfToken, 'Tài khoản chưa được cấu hình hợp lệ. Vui lòng liên hệ quản trị viên.', 403);
            return;
        }

        if (!session_regenerate_id(true)) {
            error_log('Không thể tái tạo Session ID sau khi đăng nhập.');
            $this->renderLogin('Đăng nhập', $csrfToken, 'Không thể tạo phiên đăng nhập an toàn. Vui lòng thử lại.', 500);
            return;
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['vai_tro'] = $role;
        $_SESSION['buu_cuc_id'] = $branchId;
        $_SESSION['ho_ten'] = (string) ($user['ho_ten'] ?? '');
        $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));

        $this->redirect($role === 'tai_xe' ? '/tai-xe' : '/dieu-phoi');
    }

    /**
     * Đăng xuất khỏi hệ thống.
     */
    public function logout(): void
    {
        $this->ensureSession();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $parameters['path'],
                    'domain' => $parameters['domain'],
                    'secure' => $parameters['secure'],
                    'httponly' => $parameters['httponly'],
                    'samesite' => $parameters['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
        $this->redirect('/login');
    }

    private function renderLogin(string $title, string $csrfToken, ?string $loginError, int $statusCode): void
    {
        http_response_code($statusCode);
        require dirname(__DIR__) . '/Views/layouts/header.php';
        require dirname(__DIR__) . '/Views/layouts/navbar.php';
        require dirname(__DIR__) . '/Views/auth/login.php';
        require dirname(__DIR__) . '/Views/layouts/footer.php';
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !session_start()) {
            throw new \RuntimeException('Không thể khởi tạo phiên làm việc.');
        }
    }

    private function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::CSRF_SESSION_KEY];
    }

    private function hasValidCsrfToken(): bool
    {
        $submittedToken = $_POST['_csrf_token'] ?? null;
        $sessionToken = $_SESSION[self::CSRF_SESSION_KEY] ?? null;

        return is_string($submittedToken)
            && is_string($sessionToken)
            && $submittedToken !== ''
            && hash_equals($sessionToken, $submittedToken);
    }

    private function redirect(string $path): void
    {
        header('Location: ' . BASE_URL . $path, true, 303);
        exit;
    }
}
