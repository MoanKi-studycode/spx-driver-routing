<?php

declare(strict_types=1);

/**
 * View: Trung tâm Điều phối & Giám sát Giao hàng Chặng cuối Bưu cục SPX (/dieu-phoi)
 * Tuân thủ sử dụng thẻ đầy đủ <?php ... ?> và escape dữ liệu bằng htmlspecialchars().
 */

$baseUrl = defined('BASE_URL') ? BASE_URL : '/dinh-tuyen-spx/public';
$nominatimBaseUrl = defined('NOMINATIM_BASE_URL') ? NOMINATIM_BASE_URL : 'https://nominatim.openstreetmap.org';
$osrmBaseUrl = defined('OSRM_BASE_URL') ? OSRM_BASE_URL : 'https://router.project-osrm.org';

$buuCuc = isset($buuCuc) && is_array($buuCuc) ? $buuCuc : [];
$danhSachBuuCuc = isset($danhSachBuuCuc) && is_array($danhSachBuuCuc) ? $danhSachBuuCuc : [];
$danhSachPhuong = isset($danhSachPhuong) && is_array($danhSachPhuong) ? $danhSachPhuong : [];
$danhSachTaiXe = isset($danhSachTaiXe) && is_array($danhSachTaiXe) ? $danhSachTaiXe : [];
$danhSachCum = isset($danhSachCum) && is_array($danhSachCum) ? $danhSachCum : [];
$danhSachDonLoi = isset($danhSachDonLoi) && is_array($danhSachDonLoi) ? $danhSachDonLoi : [];
$tatCaDonHang = isset($tatCaDonHang) && is_array($tatCaDonHang) ? $tatCaDonHang : [];
$duLieuCumBanDo = isset($duLieuCumBanDo) && is_array($duLieuCumBanDo) ? $duLieuCumBanDo : [];
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';

$title = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? $pageTitle
    : 'Trung Tâm Điều Phối & Giám Sát Giao Hàng - SPX Express';
$pageScript = $baseUrl . '/assets/js/ban_do_dieu_phoi.js';

$tongSoDonDaPhan = 0;
$tongDonDangGiao = 0;
$tongDonDaGiao = 0;
$tongDonThatBai = 0;
$tongKmBaseline = 0.0;
$tongKmToiUu = 0.0;
$tongCodTrenTuyen = 0.0;
$tongCodDaThu = 0.0;

foreach ($danhSachCum as $cumItem) {
    $tongSoDonDaPhan += (int) ($cumItem['so_don_hang'] ?? 0);
    $tongDonDangGiao += (int) ($cumItem['so_don_dang_cho'] ?? 0);
    $tongDonDaGiao += (int) ($cumItem['so_don_da_giao'] ?? 0);
    $tongDonThatBai += (int) ($cumItem['so_don_that_bai'] ?? 0);
    $tongKmBaseline += (float) ($cumItem['km_baseline'] ?? 0.0);
    $tongKmToiUu += (float) ($cumItem['km_toi_uu'] ?? 0.0);
    $tongCodTrenTuyen += (float) ($cumItem['tong_cod_cum'] ?? 0.0);
    $tongCodDaThu += (float) ($cumItem['tong_cod_da_thu'] ?? 0.0);
}
$tyLeTietKiemTong = $tongKmBaseline > 0.0
    ? round((($tongKmBaseline - $tongKmToiUu) / $tongKmBaseline) * 100, 2)
    : 0.0;

$dispatchConfigJson = json_encode([
    'baseUrl' => $baseUrl,
    'csrfToken' => $csrfToken,
    'nominatimBaseUrl' => $nominatimBaseUrl,
    'osrmBaseUrl' => $osrmBaseUrl,
    'buuCuc' => $buuCuc,
    'danhSachPhuong' => $danhSachPhuong,
    'danhSachTaiXe' => $danhSachTaiXe,
    'danhSachCum' => $duLieuCumBanDo,
    'danhSachDonLoi' => $danhSachDonLoi,
    'tatCaDonHang' => $tatCaDonHang,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if (!is_string($dispatchConfigJson)) {
    $dispatchConfigJson = '{}';
}

require dirname(__DIR__) . '/layouts/header.php';
require dirname(__DIR__) . '/layouts/navbar.php';
?>

<input type="hidden" id="csrf-token-fallback" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
<div id="spx-dispatch-config" class="d-none" data-config="<?php echo htmlspecialchars($dispatchConfigJson, ENT_QUOTES, 'UTF-8'); ?>"></div>

<!-- Lớp phủ Loading Spinner toàn màn hình khi đang đồng bộ hoặc chạy thuật toán (Mục 3.B.4) -->
<div id="loading-overlay-dieu-phoi"
     class="d-none position-fixed top-0 start-0 w-100 h-100 d-flex flex-column justify-content-center align-items-center"
     style="background: rgba(15, 23, 42, 0.72); z-index: 2050; backdrop-filter: blur(2px);">
    <div class="card border-0 shadow-lg px-4 py-4 text-center" style="max-width: 420px; border-radius: 14px;">
        <div class="mb-3">
            <div class="spinner-border text-danger" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Đang xử lý...</span>
            </div>
        </div>
        <h6 class="fw-bold text-dark mb-1" id="loading-overlay-title">Đang xử lý điều phối lộ trình SPX...</h6>
        <div class="small text-secondary" id="loading-overlay-text">
            Hệ thống đang đồng bộ đơn hàng Shopee và chạy thuật toán Balanced K-Means + 2-Opt...
        </div>
    </div>
</div>

<div class="container-fluid py-3 px-3">
    <!-- 1. Thanh điều hành Bưu cục SPX Express -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-lg-6">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-danger px-2 py-1">
                            <i class="bi bi-building-fill-check me-1"></i><?php echo htmlspecialchars((string) ($buuCuc['ma_buu_cuc'] ?? 'SPX_KH_NT01'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h5 class="mb-0 fw-bold text-dark">
                            Trung Tâm Điều Phối Giao Hàng: <?php echo htmlspecialchars((string) ($buuCuc['ten_buu_cuc'] ?? '') . ' - ' . (string) ($buuCuc['ten_quan_huyen'] ?? '') . ', ' . (string) ($buuCuc['ten_tinh'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                    </div>
                    <div class="small text-secondary mb-2">
                        <i class="bi bi-pin-map-fill text-danger me-1"></i><strong>Kho xuất phát (P0):</strong>
                        <?php echo htmlspecialchars((string) ($buuCuc['dia_chi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <span class="ms-2 badge bg-info-subtle text-dark border">
                            Bán kính phục vụ: <?php echo htmlspecialchars(number_format((float) ($buuCuc['ban_kinh_phuc_vu_km'] ?? 25), 1), ENT_QUOTES, 'UTF-8'); ?> km
                        </span>
                        <span class="ms-1 badge bg-warning-subtle text-dark border">
                            Định mức: &le; <?php echo htmlspecialchars((string) ($buuCuc['suc_chua_moi_cum'] ?? 20), ENT_QUOTES, 'UTF-8'); ?> đơn/tài xế
                        </span>
                        <span class="ms-1 badge bg-success-subtle text-success border" title="Bảo vệ lộ trình các tài xế đang đi giao ngoài đường">
                            <i class="bi bi-shield-lock-fill me-1"></i>Dispatch Lock: Bật
                        </span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        <span class="small fw-semibold text-muted me-1">Phường/Xã phụ trách:</span>
                        <?php foreach ($danhSachPhuong as $phItem) { ?>
                            <span class="badge rounded-pill bg-light text-dark border">
                                <i class="bi bi-geo me-1 text-danger"></i><?php echo htmlspecialchars((string) ($phItem['ten_phuong'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php } ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                        <form method="GET" action="<?php echo htmlspecialchars($baseUrl . '/dieu-phoi', ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center gap-1">
                            <label for="select-buu-cuc" class="small text-muted text-nowrap mb-0">Bưu cục:</label>
                            <select id="select-buu-cuc" name="buu_cuc_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($danhSachBuuCuc as $bcItem) { ?>
                                    <option value="<?php echo htmlspecialchars((string) ($bcItem['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo ((int) ($bcItem['id'] ?? 0) === (int) ($buuCuc['id'] ?? 0)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($bcItem['ten_buu_cuc'] ?? '') . ' (' . (string) ($bcItem['ten_quan_huyen'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>

                        <a href="<?php echo htmlspecialchars($baseUrl . '/dieu-phoi?buu_cuc_id=' . (int) ($buuCuc['id'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>"
                           class="btn btn-sm btn-outline-secondary"
                           title="Tải lại tiến độ mới nhất từ các tài xế">
                            <i class="bi bi-arrow-clockwise me-1"></i>Làm mới
                        </a>

                        <button type="button" id="btn-phan-cum-toi-uu" class="btn btn-danger btn-sm fw-bold shadow-sm"
                                title="Đồng bộ đơn mới và phân công bổ sung (Giữ nguyên lộ trình các tài xế đang đi giao)">
                            <i class="bi bi-cloud-arrow-down-fill me-1"></i>Đồng bộ Shopee &amp; Chia tuyến (Bảo vệ tuyến đang giao)
                        </button>

                        <button type="button" id="btn-open-reset-modal" class="btn btn-outline-dark btn-sm"
                                data-bs-toggle="modal" data-bs-target="#modal-confirm-reset"
                                title="Xóa các tuyến hiện tại và chạy phân cụm lại toàn bộ từ đầu">
                            <i class="bi bi-arrow-repeat me-1"></i>Reset &amp; Phân cụm lại
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

    <!-- 2. Thẻ chỉ số vận hành thực tế (Operational KPI Cards) -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Tuyến giao &amp; Tài xế trực ca</div>
                    <div class="fs-4 fw-bold text-primary" id="kpi-so-cum">
                        <?php echo htmlspecialchars(count($danhSachCum) . ' cụm / ' . count($danhSachTaiXe) . ' tài xế', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="small text-secondary" id="kpi-cod-cum">
                        COD đã thu: <strong class="text-success"><?php echo htmlspecialchars(number_format($tongCodDaThu, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</strong>
                        / <?php echo htmlspecialchars(number_format($tongCodTrenTuyen, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Tiến độ giao hàng toàn Bưu cục</div>
                    <div class="fs-4 fw-bold text-dark" id="kpi-so-don-phan-cum">
                        <?php echo htmlspecialchars((string) $tongSoDonDaPhan, ENT_QUOTES, 'UTF-8'); ?> đơn trong tuyến
                    </div>
                    <div class="small" id="kpi-tien-do-chi-tiet">
                        <span class="text-primary fw-semibold">Đang giao: <?php echo htmlspecialchars((string) $tongDonDangGiao, ENT_QUOTES, 'UTF-8'); ?></span> &bull;
                        <span class="text-success fw-semibold">Đã giao: <?php echo htmlspecialchars((string) $tongDonDaGiao, ENT_QUOTES, 'UTF-8'); ?></span> &bull;
                        <span class="text-danger fw-semibold">Thất bại: <?php echo htmlspecialchars((string) $tongDonThatBai, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Tổng quãng đường lộ trình tối ưu</div>
                    <div class="fs-5 fw-bold text-success" id="kpi-quang-duong">
                        <?php echo htmlspecialchars(number_format($tongKmToiUu, 2) . ' km (Gốc: ' . number_format($tongKmBaseline, 2) . ' km)', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="small text-success fw-semibold" id="kpi-tiet-kiem">
                        Tiết kiệm quãng đường: <?php echo htmlspecialchars(number_format($tyLeTietKiemTong, 2), ENT_QUOTES, 'UTF-8'); ?>%
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Đơn lỗi tọa độ / Chờ xác minh</div>
                    <div class="fs-4 fw-bold text-danger" id="kpi-so-don-loi">
                        <?php echo htmlspecialchars((string) count($danhSachDonLoi), ENT_QUOTES, 'UTF-8'); ?> đơn
                    </div>
                    <div class="small text-muted">Hỗ trợ kéo-thả Marker Preview trên bản đồ</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Bản đồ Giám sát Tuyến Đường Đi Thực Tế (Leaflet + OSRM + Draggable Geocoding Preview + Chuyển cụm trên Popup) -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-bold text-dark">
                    <i class="bi bi-map-fill text-danger me-1"></i>Bản Đồ Giám Sát Lộ Trình Thực Tế (OSRM) &amp; Điều Phối Trực Quan
                </span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btn-fit-all-clusters">
                    <i class="bi bi-arrows-fullscreen me-1"></i>Toàn cảnh Bưu cục
                </button>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Chế độ xem lộ trình">
                <button type="button" class="btn btn-primary active" id="btn-mode-2opt" data-route-mode="2opt">
                    Tuyến đường phố tối ưu (2-Opt + OSRM)
                </button>
                <button type="button" class="btn btn-outline-secondary" id="btn-mode-baseline" data-route-mode="baseline">
                    Thứ tự đơn gốc (Baseline)
                </button>
                <button type="button" class="btn btn-outline-dark" id="btn-mode-both" data-route-mode="both">
                    So sánh cả hai
                </button>
            </div>
        </div>
        <div class="card-body p-0 position-relative">
            <div id="map-pick-banner" class="alert alert-warning py-2 px-3 m-2 d-none position-absolute top-0 start-0 end-0 shadow" style="z-index: 1000;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <i class="bi bi-arrows-move me-1 text-danger"></i>
                        <strong id="map-pick-banner-text">Hãy click lên bản đồ hoặc kéo-thả Marker xem trước tới đúng vị trí hẻm/đường...</strong>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success py-0" id="btn-Jump-to-error-row">
                            <i class="bi bi-check2 me-1"></i>Xong, cuộn xuống lưu
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-dark py-0" id="btn-cancel-pick-map">Đóng</button>
                    </div>
                </div>
            </div>
            <div id="map-dieu-phoi" style="width: 100%; height: 470px;"></div>
        </div>
        <div class="card-footer bg-white small text-muted d-flex flex-wrap justify-content-between gap-2">
            <div class="d-flex flex-wrap gap-3">
                <span><span class="badge rounded-pill" style="background:#ee4d2d;">P0</span> Bưu cục xuất phát</span>
                <span><span class="badge rounded-pill bg-primary">1..n</span> Điểm đang chờ giao (Bấm vào để chuyển cụm)</span>
                <span><span class="badge rounded-pill bg-success">&check;</span> Đã giao thành công</span>
                <span><span class="badge rounded-pill bg-secondary">&times;</span> Giao thất bại</span>
                <span><span class="badge rounded-pill bg-danger">!</span> Đơn lỗi tọa độ</span>
                <span><span class="badge rounded-pill bg-warning text-dark"><i class="bi bi-arrows-move"></i></span> Marker xem trước (Kéo-thả được)</span>
            </div>
            <div id="osrm-dispatch-status" class="text-secondary">
                Đường liền: Lộ trình đường phố thực tế (OSRM)
            </div>
        </div>
    </div>

    <!-- 4. Khu vực Quản lý Điều phối gom gọn thành 3 Tab Bootstrap (Mục 3.B.4) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white pt-2 pb-0 border-bottom">
            <ul class="nav nav-tabs card-header-tabs" id="dispatchTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="tab-btn-cum-tuyen" data-bs-toggle="tab"
                            data-bs-target="#tab-pane-cum-tuyen" type="button" role="tab">
                        <i class="bi bi-truck-front-fill text-primary me-1"></i>1. Cụm Tuyến &amp; Tài Xế
                        <span class="badge rounded-pill bg-primary ms-1" id="tab-badge-so-cum"><?php echo htmlspecialchars((string) count($danhSachCum), ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="tab-btn-don-loi" data-bs-toggle="tab"
                            data-bs-target="#tab-pane-don-loi" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>2. Đơn Hàng Lỗi Tọa Độ
                        <span class="badge rounded-pill bg-danger ms-1" id="badge-count-don-loi"><?php echo htmlspecialchars((string) count($danhSachDonLoi), ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="tab-btn-tat-ca-don" data-bs-toggle="tab"
                            data-bs-target="#tab-pane-tat-ca-don" type="button" role="tab">
                        <i class="bi bi-box-seam-fill text-success me-1"></i>3. Danh Sách Tất Cả Đơn Hàng &amp; Chuyển Cụm
                        <span class="badge rounded-pill bg-secondary ms-1" id="tab-badge-tong-don"><?php echo htmlspecialchars((string) count($tatCaDonHang), ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="tab-content" id="dispatchTabsContent">
                <!-- TAB 1: CỤM TUYẾN & TÀI XẾ -->
                <div class="tab-pane fade show active" id="tab-pane-cum-tuyen" role="tabpanel">
                    <div class="p-3 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom bg-light-subtle">
                        <div class="small text-secondary">
                            <i class="bi bi-info-circle me-1"></i>Theo dõi tiến độ giao hàng thực tế, quãng đường tối ưu 2-Opt và tổng tiền thu hộ COD của từng Shipper trong ca.
                        </div>
                        <div class="small text-muted">
                            Bấm nút <strong>"Xem trên bản đồ"</strong> để phóng tới tuyến giao hoặc lọc đơn của tài xế đó.
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small" id="table-cum-tuyen">
                            <thead class="table-light">
                                <tr>
                                    <th>Tài xế / Khu vực phụ trách</th>
                                    <th class="text-center">Tiến độ đơn hàng</th>
                                    <th class="text-end">Đối soát Thu hộ COD</th>
                                    <th class="text-end">Quãng đường (Baseline &rarr; 2-Opt)</th>
                                    <th class="text-end">Hiệu suất</th>
                                    <th class="text-center">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-cum-tuyen">
                                <?php if (empty($danhSachCum)) { ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            Chưa có cụm tuyến giao hàng nào. Hãy bấm <strong>"Đồng bộ Shopee &amp; Chia tuyến"</strong> để khởi tạo lộ trình.
                                        </td>
                                    </tr>
                                <?php } else { ?>
                                    <?php foreach ($danhSachCum as $cum) { ?>
                                        <?php
                                        $soDonTong = (int) ($cum['so_don_hang'] ?? 0);
                                        $soDaGiao = (int) ($cum['so_don_da_giao'] ?? 0);
                                        $soThatBai = (int) ($cum['so_don_that_bai'] ?? 0);
                                        $soDangCho = (int) ($cum['so_don_dang_cho'] ?? max(0, $soDonTong - $soDaGiao - $soThatBai));
                                        $trangThaiCum = (string) ($cum['trang_thai'] ?? 'dang_giao');
                                        $codCum = (float) ($cum['tong_cod_cum'] ?? 0.0);
                                        $codDaThu = (float) ($cum['tong_cod_da_thu'] ?? 0.0);
                                        ?>
                                        <tr data-cluster-id="<?php echo htmlspecialchars((string) ($cum['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="d-inline-block rounded-circle flex-shrink-0"
                                                          style="width: 14px; height: 14px; background-color: <?php echo htmlspecialchars((string) ($cum['mau_sac_cum'] ?? '#2563eb'), ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                                    <div>
                                                        <div class="fw-bold text-dark">
                                                            <?php echo htmlspecialchars((string) ($cum['ten_tai_xe'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <?php if (!empty($cum['ten_phuong_uu_tien'])) { ?>
                                                                Khu vực: <strong><?php echo htmlspecialchars((string) $cum['ten_phuong_uu_tien'], ENT_QUOTES, 'UTF-8'); ?></strong> &bull;
                                                            <?php } ?>
                                                            Mã cụm: <code><?php echo htmlspecialchars((string) ($cum['ma_cum_tuyen'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="mb-1">
                                                    <span class="badge <?php echo $trangThaiCum === 'hoan_tat' ? 'bg-success' : 'bg-primary-subtle text-primary border'; ?>">
                                                        <?php echo htmlspecialchars((string) $soDonTong, ENT_QUOTES, 'UTF-8'); ?> đơn
                                                        <?php echo $trangThaiCum === 'hoan_tat' ? '(Hoàn tất)' : ''; ?>
                                                    </span>
                                                </div>
                                                <div style="font-size: 0.75rem;">
                                                    <span class="text-success fw-semibold">&check; Đã giao: <?php echo htmlspecialchars((string) $soDaGiao, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    &bull;
                                                    <span class="text-primary fw-semibold">Đang giao: <?php echo htmlspecialchars((string) $soDangCho, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    &bull;
                                                    <span class="text-danger fw-semibold">&times; Thất bại: <?php echo htmlspecialchars((string) $soThatBai, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold text-success">
                                                    Đã thu: <?php echo htmlspecialchars(number_format($codDaThu, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ
                                                </div>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    Tổng COD: <?php echo htmlspecialchars(number_format($codCum, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold text-dark">
                                                    <?php echo htmlspecialchars(number_format((float) ($cum['km_toi_uu'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                                <div class="text-muted" style="font-size: 0.73rem;">
                                                    Baseline: <span class="text-decoration-line-through"><?php echo htmlspecialchars(number_format((float) ($cum['km_baseline'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km</span>
                                                    | NN: <?php echo htmlspecialchars(number_format((float) ($cum['km_nn'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-success-subtle text-success border">
                                                    -<?php echo htmlspecialchars(number_format((float) ($cum['ty_le_tiet_kiem_phan_tram'] ?? 0), 1), ENT_QUOTES, 'UTF-8'); ?>%
                                                </span>
                                                <div class="text-muted" style="font-size: 0.7rem;">
                                                    <?php echo htmlspecialchars(number_format((float) ($cum['thoi_gian_chay_ms'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> ms
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-focus-cluster"
                                                            data-cluster-id="<?php echo htmlspecialchars((string) ($cum['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                            title="Phóng tới tuyến này trên bản đồ">
                                                        <i class="bi bi-geo-fill me-1"></i>Bản đồ
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-outline-secondary btn-view-cluster-orders"
                                                            data-cluster-id="<?php echo htmlspecialchars((string) ($cum['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                            title="Xem danh sách đơn hàng của tuyến này">
                                                        <i class="bi bi-list-ul"></i>
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

                <!-- TAB 2: ĐƠN HÀNG LỖI TỌA ĐỘ (KÈM NOMINATIM DRAGGABLE MARKER PREVIEW) -->
                <div class="tab-pane fade" id="tab-pane-don-loi" role="tabpanel">
                    <div class="p-3 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom bg-danger-subtle">
                        <div class="small text-dark">
                            <i class="bi bi-magic text-danger me-1"></i><strong>Hướng dẫn sửa nhanh:</strong> Bấm <strong>"Tìm tọa độ (Nominatim)"</strong> để hiển thị <strong>Marker Kéo-Thả (Draggable Preview)</strong> trên bản đồ, kéo chỉnh tới đúng vị trí hẻm/đường rồi bấm <strong>"Lưu tọa độ &amp; Gán cụm"</strong>.
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0 small" id="table-don-loi">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 135px;">Mã vận đơn</th>
                                    <th style="width: 175px;">Khách hàng / COD</th>
                                    <th>Địa chỉ giao hàng (Dùng tra cứu Nominatim)</th>
                                    <th style="width: 150px;">Nguyên nhân</th>
                                    <th style="width: 240px;">Chọn Phường/Xã phục vụ</th>
                                    <th style="width: 220px;">Tọa độ (Tự cập nhật khi kéo Marker)</th>
                                    <th style="width: 230px;" class="text-center">Thao tác Xem trước &amp; Lưu</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-don-loi">
                                <?php if (empty($danhSachDonLoi)) { ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-success py-4">
                                            <i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng hợp lệ đã được phân tuyến cho tài xế. Không có đơn lỗi tồn đọng.
                                        </td>
                                    </tr>
                                <?php } else { ?>
                                    <?php foreach ($danhSachDonLoi as $donLoi) { ?>
                                        <?php
                                        $lyDo = (string) ($donLoi['ly_do_chua_phan_cong'] ?? 'loi_toa_do');
                                        $nhanLyDo = 'Sai lệch tọa độ (> 25km)';
                                        $mauBadge = 'bg-danger';
                                        if ($lyDo === 'ngoai_vung_phuc_vu') {
                                            $nhanLyDo = 'Ngoài vùng phục vụ';
                                            $mauBadge = 'bg-warning text-dark';
                                        } elseif ($lyDo === 'qua_tai_tai_xe') {
                                            $nhanLyDo = 'Quá tải định mức ca';
                                            $mauBadge = 'bg-secondary';
                                        }
                                        ?>
                                        <tr id="row-don-loi-<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-order-id="<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td class="fw-bold text-nowrap">
                                                <?php echo htmlspecialchars((string) ($donLoi['ma_van_don'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                <div class="text-muted fw-normal" style="font-size: 0.72rem;">
                                                    Shopee: <?php echo htmlspecialchars((string) ($donLoi['ma_don_shopee'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($donLoi['ten_khach_hang'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-muted"><?php echo htmlspecialchars((string) ($donLoi['so_dien_thoai_khach'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-danger fw-semibold">
                                                    COD: <?php echo htmlspecialchars(number_format((float) ($donLoi['tien_thu_ho'] ?? 0), 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text"
                                                       class="form-control form-control-sm input-dia-chi"
                                                       value="<?php echo htmlspecialchars((string) ($donLoi['dia_chi_giao_hang'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <div class="text-muted mt-1" style="font-size: 0.72rem;">
                                                    Phường khai báo gốc: <strong><?php echo htmlspecialchars((string) ($donLoi['phuong_ten_goc'] ?? 'Không rõ'), ENT_QUOTES, 'UTF-8'); ?></strong>
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
                                                        <option value="<?php echo htmlspecialchars((string) ($phOption['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-lat="<?php echo htmlspecialchars((string) ($phOption['vi_do_tam'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-lng="<?php echo htmlspecialchars((string) ($phOption['kinh_do_tam'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                            <?php echo ((int) ($donLoi['phuong_id'] ?? 0) === (int) ($phOption['id'] ?? 0)) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars((string) ($phOption['ten_phuong'] ?? '') . ' - ' . (string) ($buuCuc['ten_quan_huyen'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
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
                                                           value="<?php echo htmlspecialchars(isset($donLoi['vi_do']) && $donLoi['vi_do'] !== null ? (string) $donLoi['vi_do'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text" style="width: 42px;">Kinh</span>
                                                    <input type="number" step="0.0000001"
                                                           class="form-control input-kinh-do"
                                                           placeholder="VD: 109.1905"
                                                           value="<?php echo htmlspecialchars(isset($donLoi['kinh_do']) && $donLoi['kinh_do'] !== null ? (string) $donLoi['kinh_do'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-grid gap-1">
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button"
                                                                class="btn btn-outline-info text-dark btn-nominatim-geocode"
                                                                data-order-id="<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                title="Tra Nominatim & hiển thị Marker kéo-thả xem trước trên bản đồ">
                                                            <i class="bi bi-search me-1"></i>Nominatim Preview
                                                        </button>
                                                        <button type="button"
                                                                class="btn btn-outline-secondary btn-pick-on-map"
                                                                data-order-id="<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                title="Click hoặc kéo Marker trên bản đồ">
                                                            <i class="bi bi-crosshair me-1"></i>Chấm
                                                        </button>
                                                    </div>
                                                    <button type="button"
                                                            class="btn btn-sm btn-success fw-semibold btn-save-don-loi"
                                                            data-order-id="<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="bi bi-check2-circle me-1"></i>Lưu tọa độ &amp; Gán cụm
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

                <!-- TAB 3: DANH SÁCH TẤT CẢ ĐƠN HÀNG & CHUYỂN CỤM THỦ CÔNG -->
                <div class="tab-pane fade" id="tab-pane-tat-ca-don" role="tabpanel">
                    <div class="p-3 bg-light-subtle border-bottom">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                                    <input type="text" id="search-order-input" class="form-control"
                                           placeholder="Tìm mã vận đơn (VD: SPXVN...), tên khách, SĐT, địa chỉ...">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <select id="filter-cluster-orders" class="form-select form-select-sm">
                                    <option value="all">Tất cả các cụm tuyến / tài xế</option>
                                    <?php foreach ($danhSachCum as $cumOpt) { ?>
                                        <option value="<?php echo htmlspecialchars((string) ($cumOpt['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) ($cumOpt['ten_tai_xe'] ?? '') . ' (' . (string) ($cumOpt['ma_cum_tuyen'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select id="filter-status-orders" class="form-select form-select-sm">
                                    <option value="all">Tất cả trạng thái (4 trạng thái)</option>
                                    <option value="da_phan_cong">Đang chờ giao (Có thể đổi tài xế)</option>
                                    <option value="giao_thanh_cong">Đã giao thành công</option>
                                    <option value="giao_that_bai">Giao thất bại / Hoàn</option>
                                    <option value="chua_phan_cong">Chưa phân công</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 460px;">
                        <table class="table table-hover align-middle mb-0 small" id="table-tat-ca-don-cum">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width: 85px;" class="text-center">Thứ tự</th>
                                    <th style="width: 145px;">Mã vận đơn</th>
                                    <th style="width: 185px;">Khách hàng / SĐT</th>
                                    <th>Địa chỉ giao hàng &amp; Phường/Xã</th>
                                    <th style="width: 120px;" class="text-end">Thu hộ COD</th>
                                    <th style="width: 190px;">Trạng thái giao hàng</th>
                                    <th style="width: 250px;">Tài xế phụ trách / Đổi cụm thủ công</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-tat-ca-don-cum">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Đang tải danh sách đơn hàng...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Xác nhận Reset & Phân cụm lại toàn bộ (Mục 3.B.1 - Dispatch Lock Confirm Modal) -->
<div class="modal fade" id="modal-confirm-reset" tabindex="-1" aria-labelledby="modalConfirmResetLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning-subtle">
                <h6 class="modal-title fw-bold text-dark" id="modalConfirmResetLabel">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Xác Nhận Reset &amp; Phân Cụm Lại Toàn Bộ
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body small">
                <p class="mb-2">
                    Hiện tại chế độ <strong>Bảo vệ lộ trình đang giao (Dispatch Lock)</strong> đang bật để tránh làm xáo trộn thứ tự điểm dừng của các Shipper đang di chuyển ngoài đường.
                </p>
                <div class="alert alert-danger py-2 px-3 mb-0">
                    <strong>Cảnh báo:</strong> Nếu bạn bấm <strong>"Xác nhận Reset &amp; Chia lại toàn bộ"</strong>, hệ thống sẽ xóa các cụm tuyến hiện tại, đưa toàn bộ đơn hàng của bưu cục về trạng thái chờ và chạy lại thuật toán <strong>Balanced K-Means + 2-Opt</strong> từ đầu.
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy bỏ (Giữ nguyên tuyến)</button>
                <button type="button" class="btn btn-sm btn-danger fw-bold" id="btn-confirm-reset-cluster">
                    <i class="bi bi-arrow-repeat me-1"></i>Xác nhận Reset &amp; Chia lại toàn bộ
                </button>
            </div>
        </div>
    </div>
</div>

<?php
require dirname(__DIR__) . '/layouts/footer.php';
?>
