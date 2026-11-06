<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$cluster = isset($cluster) && is_array($cluster) ? $cluster : null;
$orders = isset($orders) && is_array($orders) ? $orders : [];
$allOrders = isset($cluster['tat_ca_don_hang']) && is_array($cluster['tat_ca_don_hang'])
    ? $cluster['tat_ca_don_hang']
    : $orders;
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$pageWarning = isset($pageWarning) && is_string($pageWarning) ? $pageWarning : null;

$depot = [
    'vi_do' => (float) ($cluster['vi_do_xuat_phat'] ?? (defined('BUU_CUC_VI_DO_MAC_DINH') ? BUU_CUC_VI_DO_MAC_DINH : 12.2488)),
    'kinh_do' => (float) ($cluster['kinh_do_xuat_phat'] ?? (defined('BUU_CUC_KINH_DO_MAC_DINH') ? BUU_CUC_KINH_DO_MAC_DINH : 109.1905)),
    'ten_buu_cuc' => (string) ($cluster['ten_buu_cuc'] ?? 'Bưu cục SPX Nha Trang'),
];

$driverLocation = null;
if (isset($cluster['tai_xe_vi_do'], $cluster['tai_xe_kinh_do']) && $cluster['tai_xe_vi_do'] !== null && $cluster['tai_xe_kinh_do'] !== null) {
    $driverLocation = [
        'vi_do' => (float) $cluster['tai_xe_vi_do'],
        'kinh_do' => (float) $cluster['tai_xe_kinh_do'],
    ];
}

$soDonDangCho = 0;
$soDonThanhCong = 0;
$soDonThatBai = 0;
$tongCodDaThu = 0.0;
$tongCodCanThu = 0.0;

foreach ($allOrders as $item) {
    if (!is_array($item)) {
        continue;
    }
    $st = (string) ($item['trang_thai'] ?? 'da_phan_cong');
    $cod = (float) ($item['tien_thu_ho'] ?? 0);
    if ($st === 'giao_thanh_cong') {
        $soDonThanhCong++;
        $tongCodDaThu += $cod;
    } elseif ($st === 'giao_that_bai') {
        $soDonThatBai++;
    } else {
        $soDonDangCho++;
        $tongCodCanThu += $cod;
    }
}

$depotJson = json_encode($depot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$orderJson = json_encode(
    array_values($orders),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
$allOrdersJson = json_encode(
    array_values($allOrders),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
$driverLocationJson = json_encode($driverLocation, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<main class="container-fluid px-3 px-lg-4 driver-page py-3"
      id="driver-app"
      data-orders="<?php echo $escape($orderJson === false ? '[]' : $orderJson); ?>"
      data-all-orders="<?php echo $escape($allOrdersJson === false ? '[]' : $allOrdersJson); ?>"
      data-depot="<?php echo $escape($depotJson === false ? '{}' : $depotJson); ?>"
      data-driver-location="<?php echo $escape($driverLocationJson === false ? 'null' : $driverLocationJson); ?>"
      data-cluster-color="<?php echo $escape($cluster['mau_sac_cum'] ?? '#2563eb'); ?>"
      data-csrf-token="<?php echo $escape($csrfToken); ?>"
      data-base-url="<?php echo $escape(defined('BASE_URL') ? BASE_URL : ''); ?>">

    <!-- 1. Header thông tin Tài xế & Tuyến giao -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-danger px-2 py-1">
                            <i class="bi bi-truck me-1"></i>ỨNG DỤNG TÀI XẾ SPX
                        </span>
                        <?php if ($cluster !== null): ?>
                            <span class="badge bg-primary-subtle text-primary border">
                                Mã tuyến: <?php echo $escape($cluster['ma_cum_tuyen'] ?? ''); ?>
                            </span>
                            <?php if (!empty($cluster['ten_phuong_uu_tien'])): ?>
                                <span class="badge bg-light text-dark border">
                                    Khu vực phụ trách: <?php echo $escape($cluster['ten_phuong_uu_tien']); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <h1 class="h4 fw-bold mb-1">
                        <?php echo $escape($_SESSION['ho_ten'] ?? ($cluster['ten_tai_xe'] ?? 'Tài xế giao hàng')); ?>
                    </h1>
                    <p class="small text-secondary mb-0">
                        <?php if ($cluster !== null): ?>
                            Xuất phát: <strong><?php echo $escape($cluster['ten_buu_cuc'] ?? 'Bưu cục SPX Nha Trang'); ?></strong>
                            &bull; Quãng đường lộ trình tối ưu: <strong><?php echo $escape(number_format((float) ($cluster['km_toi_uu'] ?? 0), 2)); ?> km</strong>
                        <?php else: ?>
                            Hiện chưa có tuyến giao hàng nào được điều phối viên phân công cho ca này.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary fw-semibold" id="use-gps" type="button">
                        <i class="bi bi-geo-alt-fill me-1"></i>Lấy vị trí hiện tại
                    </button>
                    <label class="form-check form-switch d-flex align-items-center gap-2 mb-0 px-3 py-2 border rounded bg-white">
                        <input class="form-check-input m-0" id="toggle-gps" type="checkbox" role="switch">
                        <span class="small fw-semibold">Cập nhật GPS liên tục</span>
                    </label>
                    <button class="btn btn-outline-dark" id="choose-location" type="button">
                        <i class="bi bi-crosshair me-1"></i>Giả lập vị trí trên bản đồ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if (is_string($pageWarning) && $pageWarning !== ''): ?>
        <div class="alert alert-warning" role="status"><?php echo $escape($pageWarning); ?></div>
    <?php endif; ?>

    <!-- 2. Thẻ tiến độ ca giao hàng của Tài xế -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Tổng đơn được giao</div>
                    <div class="fs-4 fw-bold text-dark" id="drv-kpi-total"><?php echo $escape((string) count($allOrders)); ?> đơn</div>
                    <div class="small text-secondary" id="drv-kpi-status-badge">
                        <?php echo ($soDonDangCho === 0 && count($allOrders) > 0) ? 'Đã hoàn tất tuyến' : 'Đang thực hiện giao'; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Đang chờ giao</div>
                    <div class="fs-4 fw-bold text-primary" id="drv-kpi-pending"><?php echo $escape((string) $soDonDangCho); ?> đơn</div>
                    <div class="small text-secondary" id="drv-kpi-cod-pending">
                        COD cần thu: <?php echo $escape(number_format($tongCodCanThu, 0, ',', '.')); ?> đ
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Đã giao thành công</div>
                    <div class="fs-4 fw-bold text-success" id="drv-kpi-delivered"><?php echo $escape((string) $soDonThanhCong); ?> đơn</div>
                    <div class="small text-success fw-semibold" id="drv-kpi-cod-collected">
                        Đã thu COD: <?php echo $escape(number_format($tongCodDaThu, 0, ',', '.')); ?> đ
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Giao thất bại / Hoàn</div>
                    <div class="fs-4 fw-bold text-danger" id="drv-kpi-failed"><?php echo $escape((string) $soDonThatBai); ?> đơn</div>
                    <div class="small text-muted">Cần báo cáo lý do khi hoàn</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- 3. Bản đồ lộ trình theo đường đi thực tế OSRM kèm số thứ tự 1..n -->
        <section class="col-12 col-lg-7" aria-label="Bản đồ lộ trình">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h2 class="h6 fw-bold mb-0">
                        <i class="bi bi-sign-turn-right-fill text-primary me-1"></i>Bản Đồ Dẫn Đường Thực Tế &amp; Thứ Tự Điểm Giao (1..n)
                    </h2>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="badge bg-light text-secondary border" id="map-status" aria-live="polite">Đang tải bản đồ lộ trình...</span>
                        <button class="btn btn-sm btn-outline-secondary" id="toggle-map" type="button" aria-expanded="true" aria-controls="driver-map">Thu gọn bản đồ</button>
                    </div>
                </div>
                <div class="card-body p-0 driver-map-container">
                    <div class="driver-map" id="driver-map" role="region" aria-label="Bản đồ tài xế"></div>
                </div>
                <div class="card-footer bg-white small text-muted d-flex flex-wrap gap-3">
                    <span><span class="badge rounded-pill" style="background:#ee4d2d;">P0</span> Bưu cục xuất phát</span>
                    <span><span class="badge rounded-pill bg-danger">1</span> Điểm giao kế tiếp</span>
                    <span><span class="badge rounded-pill bg-primary">2..n</span> Điểm chờ giao tiếp theo</span>
                    <span><span class="badge rounded-pill bg-success">&check;</span> Đã giao thành công</span>
                    <span><span class="badge rounded-pill bg-secondary">&times;</span> Giao thất bại</span>
                </div>
            </div>
        </section>

        <!-- 4. Thẻ Đơn kế tiếp & Danh sách trạng thái tất cả đơn hàng của Tài xế -->
        <aside class="col-12 col-lg-5" aria-label="Đơn hàng">
            <section class="card border-0 shadow-sm next-order-card mb-3">
                <div class="card-header bg-danger text-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold small"><i class="bi bi-lightning-charge-fill me-1"></i>ĐIỂM GIAO KẾ TIẾP (ƯU TIÊN SỐ 1)</span>
                    <span class="badge bg-white text-danger" id="next-order-seq-badge">
                        <?php echo isset($orders[0]) ? ('Điểm dừng #' . (int) ($orders[0]['thu_tu_giao'] ?? 1)) : 'Hoàn tất'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <div id="next-order">
                        <?php if (isset($orders[0]) && is_array($orders[0])): ?>
                            <h2 class="h5 fw-bold mb-1"><?php echo $escape($orders[0]['ma_van_don'] ?? 'Đơn hàng'); ?></h2>
                            <p class="text-dark mb-1">
                                <strong>Khách hàng:</strong> <?php echo $escape($orders[0]['ten_khach_hang'] ?? ''); ?>
                                <?php if (!empty($orders[0]['so_dien_thoai_khach'])): ?>
                                    &bull; <a href="tel:<?php echo $escape($orders[0]['so_dien_thoai_khach']); ?>" class="fw-semibold text-decoration-none"><?php echo $escape($orders[0]['so_dien_thoai_khach']); ?></a>
                                <?php endif; ?>
                            </p>
                            <p class="small text-secondary mb-1">
                                <i class="bi bi-geo-alt me-1"></i><?php echo $escape($orders[0]['dia_chi_giao_hang'] ?? ''); ?>
                            </p>
                            <p class="small fw-bold text-danger mb-0">
                                <?php if ((float) ($orders[0]['tien_thu_ho'] ?? 0) > 0): ?>
                                    Cần thu COD: <?php echo $escape(number_format((float) $orders[0]['tien_thu_ho'], 0, ',', '.')); ?> đ
                                <?php else: ?>
                                    Đơn 0đ - Không thu tiền
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p class="text-success fw-semibold mb-0">
                                <i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng trong tuyến đã được xử lý xong!
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-12">
                            <a class="btn btn-outline-primary btn-sm w-100 disabled" id="open-google-maps" href="#" target="_blank" rel="noopener noreferrer" aria-disabled="true">
                                <i class="bi bi-map me-1"></i>Mở chỉ đường Google Maps tới điểm này
                            </a>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-success w-100 fw-semibold" id="deliver-next" type="button" disabled>
                                <i class="bi bi-check2-circle me-1"></i>Giao thành công
                            </button>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-outline-danger w-100 fw-semibold" id="fail-next" type="button" disabled>
                                <i class="bi bi-x-circle me-1"></i>Giao thất bại
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Danh sách đầy đủ trạng thái các đơn hàng trong tuyến -->
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-white py-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h2 class="h6 fw-bold mb-0">
                            <i class="bi bi-list-check text-primary me-1"></i>Danh Sách &amp; Trạng Thái Đơn Hàng Trong Ca
                        </h2>
                    </div>
                    <div class="btn-group btn-group-sm w-100" role="group" aria-label="Bộ lọc trạng thái đơn tài xế">
                        <button type="button" class="btn btn-primary active" data-driver-filter="all" id="drv-tab-all">
                            Tất cả (<?php echo $escape((string) count($allOrders)); ?>)
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-driver-filter="pending" id="drv-tab-pending">
                            Chờ giao (<?php echo $escape((string) $soDonDangCho); ?>)
                        </button>
                        <button type="button" class="btn btn-outline-success" data-driver-filter="done" id="drv-tab-done">
                            Đã giao / Hoàn (<?php echo $escape((string) ($soDonThanhCong + $soDonThatBai)); ?>)
                        </button>
                    </div>
                </div>
                <div class="list-group list-group-flush overflow-auto" id="order-list" style="max-height: 360px;">
                    <?php if ($allOrders === []): ?>
                        <div class="list-group-item text-secondary text-center py-4">Chưa có dữ liệu đơn hàng cho lộ trình.</div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
    <dialog class="driver-dialog" id="failure-reason-dialog" aria-labelledby="failure-reason-title">
        <form id="failure-reason-form">
            <h2 class="h5" id="failure-reason-title">Lý do giao thất bại</h2>
            <label class="form-label" for="failure-reason-choice">Chọn lý do</label>
            <select class="form-select" id="failure-reason-choice" required>
                <option value="">-- Chọn lý do --</option>
                <option value="Không liên hệ được">Không liên hệ được</option>
                <option value="Hẹn giao lại">Hẹn giao lại</option>
                <option value="Sai địa chỉ/SĐT">Sai địa chỉ/SĐT</option>
                <option value="Khách từ chối">Khách từ chối</option>
                <option value="other">Lý do khác</option>
            </select>
            <label class="form-label mt-3 d-none" id="failure-reason-note-label" for="failure-reason-note">Ghi chú (tối đa 200 ký tự)</label>
            <textarea class="form-control d-none" id="failure-reason-note" maxlength="200" rows="3"></textarea>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button class="btn btn-outline-secondary" id="cancel-failure-reason" type="button">Hủy</button>
                <button class="btn btn-danger" type="submit">Xác nhận thất bại</button>
            </div>
        </form>
    </dialog>
</main>
