<?php

declare(strict_types=1);

namespace App\Services\ShopeeApi;

/**
 * Interface ShopeeOrderClientInterface
 * Định nghĩa giao diện kết nối lấy đơn hàng từ Shopee / Mock API.
 */
interface ShopeeOrderClientInterface
{
    /**
     * Lấy lô đơn hàng mới nhất
     *
     * @return array
     */
    public function fetchOrders(): array;
}
?>