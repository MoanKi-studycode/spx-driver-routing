<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$depot = [
    'vi_do' => (float) ($cluster['vi_do_xuat_phat'] ?? (defined('BUU_CUC_VI_DO_MAC_DINH') ? BUU_CUC_VI_DO_MAC_DINH : 12.2488)),
    'kinh_do' => (float) ($cluster['kinh_do_xuat_phat'] ?? (defined('BUU_CUC_KINH_DO_MAC_DINH') ? BUU_CUC_KINH_DO_MAC_DINH : 109.1905)),
];
$depotJson = json_encode($depot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$orderJson = json_encode(
    array_values($orders),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
?>
<main class="container driver-page py-4 py-lg-5"
      id="driver-app"
      data-orders="<?= $escape($orderJson === false ? '[]' : $orderJson) ?>"
      data-depot="<?= $escape($depotJson === false ? '{}' : $depotJson) ?>"
      data-csrf-token="<?= $escape($csrfToken) ?>"
      data-base-url="<?= $escape(defined('BASE_URL') ? BASE_URL : '') ?>">
    <header class="driver-heading d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div>
            <p class="eyebrow mb-2">ỨNG DỤNG TÀI XẾ</p>
            <h1 class="h2 mb-1">Lộ trình giao hàng</h1>
            <p class="text-secondary mb-0">
                <?= $cluster ? 'Theo dõi vị trí và cập nhật trạng thái đơn hàng trong cụm được giao.' : 'Hiện chưa có cụm tuyến đang giao được phân công.' ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" id="use-gps" type="button">Dùng vị trí GPS</button>
            <button class="btn btn-outline-primary" id="choose-location" type="button">Chọn vị trí giả lập</button>
        </div>
    </header>

    <?php if (is_string($pageWarning ?? null) && $pageWarning !== ''): ?>
        <div class="alert alert-warning" role="status"><?= $escape($pageWarning) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <section class="col-12 col-lg-8" aria-label="Bản đồ lộ trình">
            <div class="card app-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <h2 class="h6 mb-0">Bản đồ</h2>
                    <span class="small text-secondary" id="map-status" aria-live="polite">Đang chờ vị trí tài xế</span>
                </div>
                <div class="card-body p-0">
                    <div class="driver-map" id="driver-map" role="region" aria-label="Bản đồ tài xế"></div>
                </div>
            </div>
        </section>

        <aside class="col-12 col-lg-4" aria-label="Đơn hàng">
            <section class="card app-card next-order-card mb-4">
                <div class="card-body">
                    <p class="eyebrow mb-2">ĐƠN KẾ TIẾP</p>
                    <div id="next-order">
                        <?php if (isset($orders[0]) && is_array($orders[0])): ?>
                            <h2 class="h5 mb-1"><?= $escape($orders[0]['ma_van_don'] ?? 'Đơn hàng') ?></h2>
                            <p class="text-secondary mb-0"><?= $escape($orders[0]['ten_khach_hang'] ?? '') ?></p>
                        <?php else: ?>
                            <p class="text-secondary mb-0">Chưa có đơn hàng đang chờ giao.</p>
                        <?php endif; ?>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <a class="btn btn-outline-primary disabled" id="open-google-maps" href="#" target="_blank" rel="noopener noreferrer" aria-disabled="true">Mở Google Maps</a>
                        <button class="btn btn-success" id="deliver-next" type="button" disabled>Giao thành công</button>
                        <button class="btn btn-outline-danger" id="fail-next" type="button" disabled>Giao thất bại</button>
                    </div>
                </div>
            </section>

            <section class="card app-card">
                <div class="card-header">
                    <h2 class="h6 mb-0">Danh sách đơn hàng</h2>
                </div>
                <div class="list-group list-group-flush" id="order-list">
                    <?php if ($orders === []): ?>
                        <div class="list-group-item text-secondary">Chưa có dữ liệu đơn hàng cho lộ trình.</div>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            if (!is_array($order)) {
                                continue;
                            }
                            $orderId = (int) ($order['id'] ?? 0);
                            ?>
                            <article class="list-group-item order-item" data-order-id="<?= $orderId ?>">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <h3 class="h6 mb-1"><?= $escape($order['ma_van_don'] ?? 'Đơn hàng') ?></h3>
                                        <p class="small text-secondary mb-1"><?= $escape($order['ten_khach_hang'] ?? '') ?></p>
                                        <p class="small mb-2"><?= $escape($order['dia_chi_giao_hang'] ?? '') ?></p>
                                        <?php
                                        $phone = (string) ($order['so_dien_thoai_khach'] ?? '');
                                        if ($phone !== '' && preg_match('/^[+0-9()\-\s]{6,20}$/', $phone) === 1):
                                            ?>
                                            <a class="small" href="tel:<?= $escape(preg_replace('/[\s()]/', '', $phone)) ?>">Gọi khách</a>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge text-bg-light align-self-start"><?= $escape($order['trang_thai'] ?? 'da_phan_cong') ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</main>
