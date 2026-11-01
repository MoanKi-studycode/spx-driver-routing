<?php

declare(strict_types=1);

namespace App\Services\ShopeeApi;

use RuntimeException;

/**
 * Class MockShopeeOrderService
 * Giả lập API Shopee trả về danh sách đơn hàng từ file JSON (`data/don_hang_mau.json`).
 */
class MockShopeeOrderService implements ShopeeOrderClientInterface
{
    /**
     * @var string Đường dẫn tới file JSON mô phỏng
     */
    protected string $jsonPath;

    public function __construct(string $jsonPath = '')
    {
        $this->jsonPath = $jsonPath !== ''
            ? $jsonPath
            : __DIR__ . '/../../../data/don_hang_mau.json';
    }

    /**
     * Lấy dữ liệu lô đơn hàng từ file JSON mô phỏng
     *
     * @return array
     * @throws RuntimeException Khi file không tồn tại hoặc JSON không hợp lệ
     */
    public function fetchOrders(): array
    {
        if (!is_file($this->jsonPath) || !is_readable($this->jsonPath)) {
            throw new RuntimeException('Không tìm thấy hoặc không thể đọc file dữ liệu Shopee mẫu: ' . $this->jsonPath);
        }

        $rawContent = file_get_contents($this->jsonPath);
        if ($rawContent === false) {
            throw new RuntimeException('Lỗi đọc nội dung file dữ liệu Shopee mẫu.');
        }

        $decoded = json_decode($rawContent, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Định dạng JSON trong file đơn hàng mẫu không hợp lệ: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
?>