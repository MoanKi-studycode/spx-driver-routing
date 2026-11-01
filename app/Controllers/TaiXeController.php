<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Repositories\CumTuyenGiaoRepository;
use App\Repositories\DonHangRepository;
use PDOException;

/**
 * Quản lý màn hình tài xế, vị trí GPS và kết quả giao hàng.
 */
class TaiXeController
{
    private const CSRF_SESSION_KEY = '_csrf_token';

    /**
     * Hiển thị lộ trình và các đơn hàng của tài xế đang đăng nhập.
     */
    public function index(): void
    {
        $this->ensureSession();
        if (!$this->isDriver()) {
            $this->redirect('/login');
        }

        $driverId = (int) $_SESSION['user_id'];
        $cluster = null;
        $orders = [];
        $pageWarning = null;

        try {
            $clusterRepository = new CumTuyenGiaoRepository(Database::getConnection());
            $cluster = $clusterRepository->getActiveClusterByDriver($driverId);
            if (is_array($cluster) && is_array($cluster['don_hang'] ?? null)) {
                $orders = $cluster['don_hang'];
            } elseif (is_array($cluster)) {
                $pageWarning = 'Kho dữ liệu chưa cung cấp danh sách đơn của lộ trình này. Vui lòng tích hợp truy vấn đơn hàng của tài xế.';
            }
        } catch (PDOException $exception) {
            error_log('Không thể tải lộ trình tài xế: ' . $exception->getMessage());
            $pageWarning = 'Không thể tải dữ liệu lộ trình do dịch vụ cơ sở dữ liệu hiện không khả dụng.';
            http_response_code(503);
        }

        $title = 'Lộ trình giao hàng';
        $csrfToken = $this->csrfToken();
        $pageScript = BASE_URL . '/assets/js/ban_do_tai_xe.js';

        require dirname(__DIR__) . '/Views/layouts/header.php';
        require dirname(__DIR__) . '/Views/layouts/navbar.php';
        require dirname(__DIR__) . '/Views/tai_xe/index.php';
        require dirname(__DIR__) . '/Views/layouts/footer.php';
    }

    /**
     * Kiểm tra dữ liệu vị trí và quyền sở hữu lộ trình trước khi tái định tuyến.
     */
    public function capNhatLoTrinh(): void
    {
        $this->ensureSession();

        if (!$this->isDriver()) {
            $this->json(false, 'Bạn cần đăng nhập bằng tài khoản tài xế.', [], 401);
            return;
        }

        $request = $this->requestData();
        if ($request === null) {
            $this->json(false, 'Dữ liệu JSON gửi lên không hợp lệ.', [], 400);
            return;
        }

        if (!$this->hasValidCsrfToken($request)) {
            $this->json(false, 'CSRF token không hợp lệ hoặc đã hết hạn.', [], 419);
            return;
        }

        $latitude = $this->coordinate($request['vi_do'] ?? null, -90, 90);
        $longitude = $this->coordinate($request['kinh_do'] ?? null, -180, 180);
        if ($latitude === null || $longitude === null) {
            $this->json(false, 'Tọa độ GPS không hợp lệ.', [], 422);
            return;
        }

        try {
            $connection = Database::getConnection();
            $driverId = (int) $_SESSION['user_id'];
            $clusterRepository = new CumTuyenGiaoRepository($connection);
            $cluster = $clusterRepository->getActiveClusterByDriver($driverId);
        } catch (PDOException $exception) {
            error_log('Không thể xác minh lộ trình tài xế: ' . $exception->getMessage());
            $this->json(false, 'Dịch vụ dữ liệu hiện không khả dụng.', [], 503);
            return;
        }

        if (!is_array($cluster)) {
            $this->json(false, 'Tài xế hiện không có lộ trình đang giao.', [], 409);
            return;
        }

        $this->json(
            false,
            'Chưa thể tái định tuyến an toàn: Repository hiện chưa cung cấp danh sách đơn còn lại, tọa độ bưu cục và bán kính phục vụ. Không có dữ liệu nào được thay đổi.',
            [],
            503
        );
    }

    /**
     * Cập nhật trạng thái giao hàng của một đơn thuộc tài xế hiện tại.
     */
    public function capNhatKetQua(int $orderId): void
    {
        $this->ensureSession();

        if (!$this->isDriver()) {
            $this->json(false, 'Bạn cần đăng nhập bằng tài khoản tài xế.', [], 401);
            return;
        }

        if ($orderId < 1) {
            $this->json(false, 'Mã đơn hàng không hợp lệ.', [], 422);
            return;
        }

        $request = $this->requestData();
        if ($request === null) {
            $this->json(false, 'Dữ liệu JSON gửi lên không hợp lệ.', [], 400);
            return;
        }

        if (!$this->hasValidCsrfToken($request)) {
            $this->json(false, 'CSRF token không hợp lệ hoặc đã hết hạn.', [], 419);
            return;
        }

        $status = $request['trang_thai'] ?? $request['ket_qua'] ?? null;
        if (!in_array($status, ['giao_thanh_cong', 'giao_that_bai'], true)) {
            $this->json(false, 'Kết quả giao hàng không hợp lệ.', [], 422);
            return;
        }

        $failureReason = null;
        if ($status === 'giao_that_bai') {
            $failureReason = trim((string) ($request['ly_do_that_bai'] ?? ''));
            if ($failureReason === '' || strlen($failureReason) > 255) {
                $this->json(false, 'Vui lòng nhập lý do giao thất bại (tối đa 255 ký tự).', [], 422);
                return;
            }
        }

        try {
            $repository = new DonHangRepository(Database::getConnection());
            $updated = $repository->updateDeliveryResult(
                $orderId,
                (int) $_SESSION['user_id'],
                $status,
                $failureReason
            );
        } catch (PDOException $exception) {
            error_log('Không thể cập nhật kết quả giao hàng: ' . $exception->getMessage());
            $this->json(false, 'Không thể lưu kết quả giao hàng do dịch vụ dữ liệu hiện không khả dụng.', [], 503);
            return;
        }

        if (!$updated) {
            $this->json(false, 'Đơn không thuộc tài xế này, đã được xử lý trước đó hoặc không còn hợp lệ.', [], 409);
            return;
        }

        $this->json(true, 'Đã cập nhật kết quả giao hàng.', [
            'id' => $orderId,
            'trang_thai' => $status,
        ]);
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !session_start()) {
            throw new \RuntimeException('Không thể khởi tạo phiên làm việc.');
        }
    }

    private function isDriver(): bool
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

        return ($_SESSION['vai_tro'] ?? null) === 'tai_xe'
            && $userId !== false
            && $userId > 0;
    }

    private function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::CSRF_SESSION_KEY];
    }

    private function hasValidCsrfToken(array $request): bool
    {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $request['_csrf_token'] ?? null;
        $sessionToken = $_SESSION[self::CSRF_SESSION_KEY] ?? null;

        return is_string($submittedToken)
            && is_string($sessionToken)
            && $submittedToken !== ''
            && hash_equals($sessionToken, $submittedToken);
    }

    private function requestData(): ?array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            return $_POST;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;
        if (!is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return $coordinate;
    }

    private function json(bool $success, string $message, array $data = [], int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode(
            [
                'thanh_cong' => $success,
                'thong_bao' => $message,
                'du_lieu' => $data,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private function redirect(string $path): void
    {
        header('Location: ' . BASE_URL . $path, true, 303);
        exit;
    }
}
