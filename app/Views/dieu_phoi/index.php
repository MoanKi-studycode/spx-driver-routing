<?php

declare(strict_types=1);

/**
 * View: Màn hình Điều phối - Bản đồ Phân cụm K-Means & Điều hành Lộ trình (/dieu-phoi)
 * Tuân thủ sử dụng thẻ đầy đủ <?php ... ?> và escape dữ liệu bằng htmlspecialchars().
 */

$baseUrl = defined('BASE_URL') ? BASE_URL : '/dinh-tuyen-spx/public';
$nominatimBaseUrl = defined('NOMINATIM_BASE_URL') ? NOMINATIM_BASE_URL : 'https://nominatim.openstreetmap.org';
$osrmBaseUrl = defined('OSRM_BASE_URL') ? OSRM_BASE_URL : 'https://router.project-osrm.org';

$layoutDir = __DIR__ . '/../layouts';
$hasHeaderLayout = is_file($layoutDir . '/header.php');
$hasNavbarLayout = is_file($layoutDir . '/navbar.php');
$hasFooterLayout = is_file($layoutDir . '/footer.php');

$tongSoDonDaPhan = 0;
$tongKmBaseline = 0.0;
$tongKmToiUu = 0.0;
foreach ($danhSachCum as $cumItem) {
    $tongSoDonDaPhan += (int) ($cumItem['so_don_hang'] ?? 0);
    $tongKmBaseline += (float) ($cumItem['km_baseline'] ?? 0.0);
    $tongKmToiUu += (float) ($cumItem['km_toi_uu'] ?? 0.0);
}
$tyLeTietKiemTong = $tongKmBaseline > 0.0
    ? round((($tongKmBaseline - $tongKmToiUu) / $tongKmBaseline) * 100, 2)
    : 0.0;

if ($hasHeaderLayout) {
    require $layoutDir . '/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars((string) ($pageTitle ?? 'Điều Phối - SPX Express'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl . '/assets/css/app.css', ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="bg-light">
<?php
}

if ($hasNavbarLayout) {
    require $layoutDir . '/navbar.php';
} else {
?>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm" style="background-color: #ee4d2d;">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?php echo htmlspecialchars($baseUrl . '/dieu-phoi', ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-truck-front-fill"></i>
            <span>SPX Express - Điều Phối Chặng Cuối</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo htmlspecialchars($baseUrl . '/dieu-phoi', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-light fw-semibold text-danger">
                <i class="bi bi-diagram-3-fill me-1"></i>Bản đồ Điều phối
            </a>
            <a href="<?php echo htmlspecialchars($baseUrl . '/thong-ke', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-bar-chart-line-fill me-1"></i>Thống kê &amp; Thực nghiệm
            </a>
            <a href="<?php echo htmlspecialchars($baseUrl . '/logout', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-dark ms-2">
                <i class="bi bi-box-arrow-right me-1"></i>Đăng xuất
            </a>
        </div>
    </div>
</nav>
<?php
}
?>

<!-- Đảm bảo luôn có thẻ meta csrf-token cho JS trong trường hợp layout chưa kịp in -->
<input type="hidden" id="csrf-token-fallback" value="<?php echo htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

<div class="container-fluid py-3 px-3">
    <!-- 1. Thanh thông tin Cơ sở / Bưu cục SPX & Nút Đồng bộ Phân cụm K-Means -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-lg-7">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-danger px-2 py-1">
                            <i class="bi bi-geo-alt-fill me-1"></i><?php echo htmlspecialchars((string) ($buuCuc['ma_buu_cuc'] ?? 'SPX_KH_NT01'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h5 class="mb-0 fw-bold text-dark">
                            <?php echo htmlspecialchars((string) ($buuCuc['ten_buu_cuc'] ?? '') . ' - ' . ($buuCuc['ten_quan_huyen'] ?? '') . ', ' . ($buuCuc['ten_tinh'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                    </div>
                    <div class="small text-secondary mb-2">
                        <i class="bi bi-pin-map me-1"></i><strong>Địa chỉ Bưu cục ($P_0$):</strong>
                        <?php echo htmlspecialchars((string) ($buuCuc['dia_chi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <span class="ms-2 badge bg-secondary-subtle text-dark border">
                            Tọa độ P0: <?php echo htmlspecialchars(number_format((float) ($buuCuc['vi_do'] ?? 0), 5) . ', ' . number_format((float) ($buuCuc['kinh_do'] ?? 0), 5), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="ms-1 badge bg-info-subtle text-dark border">
                            Bán kính phục vụ: <?php echo htmlspecialchars(number_format((float) ($buuCuc['ban_kinh_phuc_vu_km'] ?? 25), 1), ENT_QUOTES, 'UTF-8'); ?> km
                        </span>
                        <span class="ms-1 badge bg-warning-subtle text-dark border">
                            Định mức: &le; <?php echo htmlspecialchars((string) ($buuCuc['suc_chua_moi_cum'] ?? 20), ENT_QUOTES, 'UTF-8'); ?> đơn/cụm
                        </span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        <span class="small fw-semibold text-muted me-1">Vùng phục vụ (Phường/Xã chuẩn 3NF):</span>
                        <?php foreach ($danhSachPhuong as $phItem) { ?>
                            <span class="badge rounded-pill bg-light text-dark border">
                                <?php echo htmlspecialchars((string) $phItem['ten_phuong'] . ' [Mã HC: ' . (string) $phItem['ma_hanh_chinh'] . ']', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php } ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                        <form method="GET" action="<?php echo htmlspecialchars($baseUrl . '/dieu-phoi', ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center gap-1">
                            <label for="select-buu-cuc" class="small text-muted text-nowrap mb-0">Cơ sở:</label>
                            <select id="select-buu-cuc" name="buu_cuc_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($danhSachBuuCuc as $bcItem) { ?>
                                    <option value="<?php echo htmlspecialchars((string) $bcItem['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo ((int) $bcItem['id'] === (int) $buuCuc['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $bcItem['ten_buu_cuc'] . ' (' . (string) $bcItem['ten_quan_huyen'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>

                        <button type="button" id="btn-phan-cum-toi-uu" class="btn btn-danger fw-bold shadow-sm">
                            <i class="bi bi-cloud-arrow-down-fill me-1"></i>Đồng bộ Mock API &amp; Phân cụm K-Means
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Thông báo kết quả xử lý động -->
    <div id="alert-dieu-phoi" class="alert d-none alert-dismissible fade show shadow-sm mb-3" role="alert">
        <span id="alert-dieu-phoi-text"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button>
    </div>

    <!-- 2. Thẻ chỉ số nhanh (KPI Cards) -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Số cụm K-Means / Tài xế trực</div>
                    <div class="fs-4 fw-bold text-primary" id="kpi-so-cum">
                        <?php echo htmlspecialchars(count($danhSachCum) . ' / ' . count($danhSachTaiXe), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Đơn đã phân cụm</div>
                    <div class="fs-4 fw-bold text-success" id="kpi-so-don-phan-cum">
                        <?php echo htmlspecialchars((string) $tongSoDonDaPhan, ENT_QUOTES, 'UTF-8'); ?> đơn
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Quãng đường Baseline &rarr; 2-Opt</div>
                    <div class="fs-5 fw-bold text-dark" id="kpi-quang-duong">
                        <?php echo htmlspecialchars(number_format($tongKmBaseline, 2) . ' km → ' . number_format($tongKmToiUu, 2) . ' km', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="small text-success fw-semibold" id="kpi-tiet-kiem">
                        Tiết kiệm: <?php echo htmlspecialchars(number_format($tyLeTietKiemTong, 2), ENT_QUOTES, 'UTF-8'); ?>%
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Đơn ngoại lệ chờ xử lý</div>
                    <div class="fs-4 fw-bold text-danger" id="kpi-so-don-loi">
                        <?php echo htmlspecialchars((string) count($danhSachDonLoi), ENT_QUOTES, 'UTF-8'); ?> đơn
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Khu vực Bản đồ Phân cụm & Bảng Danh sách Cụm tuyến -->
    <div class="row g-3 mb-3">
        <!-- Bản đồ Leaflet đa cụm -->
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="fw-bold text-dark">
                        <i class="bi bi-map-fill text-danger me-1"></i>Bản Đồ Phân Cụm K-Means &amp; Tối Ưu Lộ Trình 2-Opt
                    </div>
                    <!-- Nút bật/tắt so sánh trực quan Baseline vs 2-Opt theo Mục 4.1.3 & 8.2.2 -->
                    <div class="btn-group btn-group-sm" role="group" aria-label="Chế độ xem lộ trình">
                        <button type="button" class="btn btn-outline-secondary" id="btn-mode-baseline" data-route-mode="baseline">
                            Xem đường Baseline (Chưa tối ưu)
                        </button>
                        <button type="button" class="btn btn-primary active" id="btn-mode-2opt" data-route-mode="2opt">
                            Xem đường 2-Opt (Đã tối ưu)
                        </button>
                        <button type="button" class="btn btn-outline-dark" id="btn-mode-both" data-route-mode="both">
                            So sánh cả hai
                        </button>
                    </div>
                </div>
                <div class="card-body p-0 position-relative">
                    <div id="map-pick-banner" class="alert alert-warning py-1 px-3 m-2 d-none position-absolute top-0 start-0 end-0" style="z-index: 1000;">
                        <i class="bi bi-crosshair me-1"></i>
                        <span id="map-pick-banner-text">Hãy click vào một điểm trên bản đồ để gán tọa độ cho đơn hàng đang chọn...</span>
                        <button type="button" class="btn btn-xs btn-sm btn-outline-dark ms-2 py-0" id="btn-cancel-pick-map">Hủy</button>
                    </div>
                    <div id="map-dieu-phoi" style="width: 100%; height: 540px;"></div>
                </div>
                <div class="card-footer bg-white small text-muted d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <span class="me-3"><i class="bi bi-house-door-fill text-danger"></i> Bưu cục SPX ($P_0$)</span>
                        <span class="me-3"><i class="bi bi-star-fill text-warning"></i> Tâm cụm K-Means (Centroid)</span>
                        <span class="me-3"><i class="bi bi-circle-fill text-primary"></i> Điểm giao hàng (1..n)</span>
                        <span><i class="bi bi-exclamation-triangle-fill text-danger"></i> Điểm lỗi tọa độ</span>
                    </div>
                    <div>
                        <span class="text-secondary">Đường nét đứt: Baseline</span> |
                        <span class="fw-semibold text-dark">Đường nét liền: 2-Opt tối ưu</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bảng danh sách các Cụm tuyến K-Means -->
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark">
                        <i class="bi bi-people-fill text-primary me-1"></i>Danh Sách Cụm Tuyến Giao Hàng
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-fit-all-clusters">
                        <i class="bi bi-arrows-fullscreen me-1"></i>Toàn cảnh
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 540px;">
                        <table class="table table-hover align-middle mb-0 small" id="table-cum-tuyen">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Cụm / Tài xế</th>
                                    <th class="text-center">Số đơn</th>
                                    <th class="text-end">Baseline &rarr; 2-Opt</th>
                                    <th class="text-end">Tiết kiệm</th>
                                    <th class="text-center">Bản đồ</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-cum-tuyen">
                                <?php if (empty($danhSachCum)) { ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            Chưa có cụm tuyến nào. Hãy bấm <strong>"Đồng bộ Mock API &amp; Phân cụm K-Means"</strong> để khởi tạo lộ trình.
                                        </td>
                                    </tr>
                                <?php } else { ?>
                                    <?php foreach ($danhSachCum as $cum) { ?>
                                        <tr data-cluster-id="<?php echo htmlspecialchars((string) $cum['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="d-inline-block rounded-circle"
                                                          style="width: 12px; height: 12px; background-color: <?php echo htmlspecialchars((string) ($cum['mau_sac_cum'] ?? '#2563eb'), ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                                    <div>
                                                        <div class="fw-bold text-dark">
                                                            <?php echo htmlspecialchars((string) ($cum['ten_tai_xe'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <?php echo htmlspecialchars((string) $cum['ma_cum_tuyen'], ENT_QUOTES, 'UTF-8'); ?>
                                                            <?php if (!empty($cum['ten_phuong_uu_tien'])) { ?>
                                                                &bull; Ưu tiên: <?php echo htmlspecialchars((string) $cum['ten_phuong_uu_tien'], ENT_QUOTES, 'UTF-8'); ?>
                                                            <?php } ?>
                                                        </div>
                                                        <div class="text-secondary" style="font-size: 0.72rem;">
                                                            Tâm cụm: (<?php echo htmlspecialchars(number_format((float) $cum['vi_do_tam_cum'], 4) . ', ' . number_format((float) $cum['kinh_do_tam_cum'], 4), ENT_QUOTES, 'UTF-8'); ?>)
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary-subtle text-primary border">
                                                    <?php echo htmlspecialchars((string) $cum['so_don_hang'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) ($buuCuc['suc_chua_moi_cum'] ?? 20), ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                                <div class="text-muted mt-1" style="font-size: 0.72rem;">
                                                    Đã giao: <?php echo htmlspecialchars((string) ($cum['so_don_da_giao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="text-decoration-line-through text-muted" style="font-size: 0.75rem;">
                                                    <?php echo htmlspecialchars(number_format((float) $cum['km_baseline'], 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                                <div class="fw-bold text-dark">
                                                    <?php echo htmlspecialchars(number_format((float) $cum['km_toi_uu'], 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                                <div class="text-muted" style="font-size: 0.7rem;">
                                                    NN: <?php echo htmlspecialchars(number_format((float) $cum['km_nn'], 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-success-subtle text-success border">
                                                    -<?php echo htmlspecialchars(number_format((float) $cum['ty_le_tiet_kiem_phan_tram'], 1), ENT_QUOTES, 'UTF-8'); ?>%
                                                </span>
                                                <div class="text-muted mt-1" style="font-size: 0.7rem;">
                                                    <?php echo htmlspecialchars(number_format((float) $cum['thoi_gian_chay_ms'], 2), ENT_QUOTES, 'UTF-8'); ?> ms
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary btn-focus-cluster"
                                                        data-cluster-id="<?php echo htmlspecialchars((string) $cum['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="Phóng tới cụm trên bản đồ">
                                                    <i class="bi bi-geo-fill"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Bảng Xử lý nhanh các đơn hàng lỗi (`chua_phan_cong`: loi_toa_do, ngoai_vung_phuc_vu, qua_tai_tai_xe) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="fw-bold text-danger">
                    <i class="bi bi-wrench-adjustable-circle-fill me-1"></i>Bảng Xử Lý Đơn Hàng Ngoại Lệ (`chua_phan_cong`)
                </span>
                <span class="small text-muted ms-2">
                    Hỗ trợ chọn lại Phường/Xã chuẩn 3NF, tự động lấy tọa độ từ địa chỉ qua <strong>OpenStreetMap Nominatim API</strong> hoặc chấm trực tiếp trên bản đồ.
                </span>
            </div>
            <span class="badge bg-danger" id="badge-count-don-loi">
                <?php echo htmlspecialchars((string) count($danhSachDonLoi), ENT_QUOTES, 'UTF-8'); ?> đơn cần xử lý
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0 small" id="table-don-loi">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 130px;">Mã vận đơn</th>
                            <th style="width: 170px;">Khách hàng / COD</th>
                            <th>Địa chỉ giao hàng (Dùng để tra Nominatim)</th>
                            <th style="width: 150px;">Lý do chưa phân công</th>
                            <th style="width: 260px;">Chọn Phường/Xã thuộc Bưu cục</th>
                            <th style="width: 230px;">Tọa độ (Vĩ độ / Kinh độ)</th>
                            <th style="width: 220px;" class="text-center">Thao tác sửa &amp; Gán cụm</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-don-loi">
                        <?php if (empty($danhSachDonLoi)) { ?>
                            <tr>
                                <td colspan="7" class="text-center text-success py-4">
                                    <i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng hợp lệ đã được phân vào cụm tuyến. Không có đơn ngoại lệ tồn đọng.
                                </td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($danhSachDonLoi as $donLoi) { ?>
                                <?php
                                $lyDo = (string) ($donLoi['ly_do_chua_phan_cong'] ?? 'loi_toa_do');
                                $nhanLyDo = 'Lỗi tọa độ (> bán kính / thiếu)';
                                $mauBadge = 'bg-danger';
                                if ($lyDo === 'ngoai_vung_phuc_vu') {
                                    $nhanLyDo = 'Ngoài vùng phục vụ (Sai phường)';
                                    $mauBadge = 'bg-warning text-dark';
                                } elseif ($lyDo === 'qua_tai_tai_xe') {
                                    $nhanLyDo = 'Quá tải định mức tài xế';
                                    $mauBadge = 'bg-secondary';
                                }
                                ?>
                                <tr id="row-don-loi-<?php echo htmlspecialchars((string) $donLoi['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-order-id="<?php echo htmlspecialchars((string) $donLoi['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <td class="fw-bold text-nowrap">
                                        <?php echo htmlspecialchars((string) $donLoi['ma_van_don'], ENT_QUOTES, 'UTF-8'); ?>
                                        <div class="text-muted fw-normal" style="font-size: 0.72rem;">
                                            Shopee: <?php echo htmlspecialchars((string) ($donLoi['ma_don_shopee'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) $donLoi['ten_khach_hang'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-muted"><?php echo htmlspecialchars((string) ($donLoi['so_dien_thoai_khach'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-danger fw-semibold">
                                            COD: <?php echo htmlspecialchars(number_format((float) ($donLoi['tien_thu_ho'] ?? 0), 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text"
                                               class="form-control form-control-sm input-dia-chi"
                                               value="<?php echo htmlspecialchars((string) $donLoi['dia_chi_giao_hang'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="text-muted mt-1" style="font-size: 0.72rem;">
                                            Phường gốc trong file: <strong><?php echo htmlspecialchars((string) ($donLoi['phuong_ten_goc'] ?? 'Không rõ'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($mauBadge, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($nhanLyDo, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm select-phuong-don-loi">
                                            <option value="">-- Chọn Phường/Xã phục vụ --</option>
                                            <?php foreach ($danhSachPhuong as $phOption) { ?>
                                                <option value="<?php echo htmlspecialchars((string) $phOption['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-lat="<?php echo htmlspecialchars((string) $phOption['vi_do_tam'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-lng="<?php echo htmlspecialchars((string) $phOption['kinh_do_tam'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    <?php echo ((int) ($donLoi['phuong_id'] ?? 0) === (int) $phOption['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string) $phOption['ten_phuong'] . ' - ' . (string) $buuCuc['ten_quan_huyen'] . ', ' . (string) $buuCuc['ten_tinh'] . ' [Mã: ' . (string) $phOption['ma_hanh_chinh'] . ']', ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm mb-1">
                                            <span class="input-group-text" style="width: 42px;">Vĩ</span>
                                            <input type="number" step="0.0000001"
                                                   class="form-control input-vi-do"
                                                   placeholder="VD: 12.2488"
                                                   value="<?php echo htmlspecialchars($donLoi['vi_do'] !== null ? (string) $donLoi['vi_do'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text" style="width: 42px;">Kinh</span>
                                            <input type="number" step="0.0000001"
                                                   class="form-control input-kinh-do"
                                                   placeholder="VD: 109.1905"
                                                   value="<?php echo htmlspecialchars($donLoi['kinh_do'] !== null ? (string) $donLoi['kinh_do'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-grid gap-1">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button"
                                                        class="btn btn-outline-info text-dark btn-nominatim-geocode"
                                                        data-order-id="<?php echo htmlspecialchars((string) $donLoi['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="Tự động tìm tọa độ từ địa chỉ bằng OpenStreetMap Nominatim API">
                                                    <i class="bi bi-search me-1"></i>Nominatim
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-pick-on-map"
                                                        data-order-id="<?php echo htmlspecialchars((string) $donLoi['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="Click chọn vị trí trực tiếp trên bản đồ">
                                                    <i class="bi bi-crosshair me-1"></i>Chấm bản đồ
                                                </button>
                                            </div>
                                            <button type="button"
                                                    class="btn btn-sm btn-success fw-semibold btn-save-don-loi"
                                                    data-order-id="<?php echo htmlspecialchars((string) $donLoi['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="bi bi-check2-circle me-1"></i>Cập nhật &amp; Gán vào cụm
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Truyền dữ liệu khởi tạo xuống file JS ban_do_dieu_phoi.js -->
<script>
window.SPX_DISPATCH_CONFIG = <?php echo json_encode([
    'baseUrl' => $baseUrl,
    'csrfToken' => $csrfToken ?? '',
    'nominatimBaseUrl' => $nominatimBaseUrl,
    'osrmBaseUrl' => $osrmBaseUrl,
    'buuCuc' => $buuCuc,
    'danhSachPhuong' => $danhSachPhuong,
    'danhSachCum' => $duLieuCumBanDo,
    'danhSachDonLoi' => $danhSachDonLoi,
], JSON_UNESCAPED_UNICODE); ?>;
</script>

<?php
if ($hasFooterLayout) {
    require $layoutDir . '/footer.php';
} else {
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo htmlspecialchars($baseUrl . '/assets/js/ban_do_dieu_phoi.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
<?php
}
?>
