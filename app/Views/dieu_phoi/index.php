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

foreach ($danhSachCum as $cumItem) {
    $tongSoDonDaPhan += (int) ($cumItem['so_don_hang'] ?? 0);
    $tongDonDangGiao += (int) ($cumItem['so_don_dang_cho'] ?? 0);
    $tongDonDaGiao += (int) ($cumItem['so_don_da_giao'] ?? 0);
    $tongDonThatBai += (int) ($cumItem['so_don_that_bai'] ?? 0);
    $tongKmBaseline += (float) ($cumItem['km_baseline'] ?? 0.0);
    $tongKmToiUu += (float) ($cumItem['km_toi_uu'] ?? 0.0);
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
    'danhSachCum' => $duLieuCumBanDo,
    'danhSachDonLoi' => $danhSachDonLoi,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if (!is_string($dispatchConfigJson)) {
    $dispatchConfigJson = '{}';
}

require dirname(__DIR__) . '/layouts/header.php';
require dirname(__DIR__) . '/layouts/navbar.php';
?>

<input type="hidden" id="csrf-token-fallback" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
<div id="spx-dispatch-config" class="d-none" data-config="<?php echo htmlspecialchars($dispatchConfigJson, ENT_QUOTES, 'UTF-8'); ?>"></div>

<div class="container-fluid py-3 px-3">
    <!-- 1. Thanh điều hành Bưu cục SPX Express -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-lg-7">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-danger px-2 py-1">
                            <i class="bi bi-building-fill-check me-1"></i><?php echo htmlspecialchars((string) ($buuCuc['ma_buu_cuc'] ?? 'SPX_KH_NT01'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h5 class="mb-0 fw-bold text-dark">
                            Trung Tâm Điều Phối Giao Hàng: <?php echo htmlspecialchars((string) ($buuCuc['ten_buu_cuc'] ?? '') . ' - ' . (string) ($buuCuc['ten_quan_huyen'] ?? '') . ', ' . (string) ($buuCuc['ten_tinh'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                    </div>
                    <div class="small text-secondary mb-2">
                        <i class="bi bi-pin-map-fill text-danger me-1"></i><strong>Kho xuất phát:</strong>
                        <?php echo htmlspecialchars((string) ($buuCuc['dia_chi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <span class="ms-2 badge bg-info-subtle text-dark border">
                            Phạm vi giao: <?php echo htmlspecialchars(number_format((float) ($buuCuc['ban_kinh_phuc_vu_km'] ?? 25), 1), ENT_QUOTES, 'UTF-8'); ?> km
                        </span>
                        <span class="ms-1 badge bg-warning-subtle text-dark border">
                            Định mức tải: &le; <?php echo htmlspecialchars((string) ($buuCuc['suc_chua_moi_cum'] ?? 20), ENT_QUOTES, 'UTF-8'); ?> đơn/tài xế
                        </span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        <span class="small fw-semibold text-muted me-1">Khu vực phường/xã phụ trách:</span>
                        <?php foreach ($danhSachPhuong as $phItem) { ?>
                            <span class="badge rounded-pill bg-light text-dark border">
                                <i class="bi bi-geo me-1 text-danger"></i><?php echo htmlspecialchars((string) ($phItem['ten_phuong'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php } ?>
                    </div>
                </div>

                <div class="col-lg-5">
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
                           title="Cập nhật tiến độ giao hàng mới nhất từ các tài xế">
                            <i class="bi bi-arrow-clockwise me-1"></i>Làm mới tiến độ
                        </a>

                        <button type="button" id="btn-phan-cum-toi-uu" class="btn btn-danger fw-bold shadow-sm">
                            <i class="bi bi-cloud-arrow-down-fill me-1"></i>Đồng bộ đơn Shopee &amp; Tự động chia tuyến
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
                        <?php echo htmlspecialchars(count($danhSachCum) . ' tuyến / ' . count($danhSachTaiXe) . ' tài xế', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="small text-secondary">Phân bổ cân bằng tải tự động theo khu vực</div>
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
                    <div class="text-muted small">Đơn cần xác minh lại địa chỉ</div>
                    <div class="fs-4 fw-bold text-danger" id="kpi-so-don-loi">
                        <?php echo htmlspecialchars((string) count($danhSachDonLoi), ENT_QUOTES, 'UTF-8'); ?> đơn
                    </div>
                    <div class="small text-muted">Sai tọa độ hoặc ngoài vùng phục vụ</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Khu vực Bản đồ Giám sát Tuyến Đường Đi Thực Tế & Bảng Quản lý Tuyến Tài Xế -->
    <div class="row g-3 mb-3">
        <!-- Bản đồ Leaflet dẫn đường theo phố OSRM -->
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="fw-bold text-dark">
                        <i class="bi bi-map-fill text-danger me-1"></i>Bản Đồ Giám Sát Tuyến Giao Hàng Thực Tế &amp; Vị Trí Đơn
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Chế độ xem lộ trình">
                        <button type="button" class="btn btn-primary active" id="btn-mode-2opt" data-route-mode="2opt">
                            Tuyến đường phố tối ưu
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btn-mode-baseline" data-route-mode="baseline">
                            Thứ tự đơn gốc (Chưa tối ưu)
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
                    <div class="d-flex flex-wrap gap-3">
                        <span><span class="badge rounded-pill" style="background:#ee4d2d;">P0</span> Bưu cục xuất phát</span>
                        <span><span class="badge rounded-pill bg-primary">1..n</span> Điểm đang chờ giao</span>
                        <span><span class="badge rounded-pill bg-success">&check;</span> Đã giao thành công</span>
                        <span><span class="badge rounded-pill bg-secondary">&times;</span> Giao thất bại</span>
                        <span><span class="badge rounded-pill bg-danger">!</span> Đơn lỗi địa chỉ</span>
                    </div>
                    <div id="osrm-dispatch-status" class="text-secondary">
                        Đường liền: Tuyến đường bộ thực tế (OSRM)
                    </div>
                </div>
            </div>
        </div>

        <!-- Bảng quản lý các Tuyến giao hàng của Tài xế -->
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark">
                        <i class="bi bi-truck-front-fill text-primary me-1"></i>Danh Sách Tuyến Giao &amp; Tiến Độ Tài Xế
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-fit-all-clusters">
                        <i class="bi bi-arrows-fullscreen me-1"></i>Xem toàn bộ tuyến
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 540px;">
                        <table class="table table-hover align-middle mb-0 small" id="table-cum-tuyen">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Tài xế / Khu vực phụ trách</th>
                                    <th class="text-center">Tiến độ giao</th>
                                    <th class="text-end">Quãng đường</th>
                                    <th class="text-end">Hiệu suất</th>
                                    <th class="text-center">Xem</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-cum-tuyen">
                                <?php if (empty($danhSachCum)) { ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            Chưa có tuyến giao hàng nào. Hãy bấm <strong>"Đồng bộ đơn Shopee &amp; Tự động chia tuyến"</strong> để phân công cho tài xế.
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
                                        ?>
                                        <tr data-cluster-id="<?php echo htmlspecialchars((string) ($cum['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="d-inline-block rounded-circle flex-shrink-0"
                                                          style="width: 12px; height: 12px; background-color: <?php echo htmlspecialchars((string) ($cum['mau_sac_cum'] ?? '#2563eb'), ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                                    <div>
                                                        <div class="fw-bold text-dark">
                                                            <?php echo htmlspecialchars((string) ($cum['ten_tai_xe'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <?php if (!empty($cum['ten_phuong_uu_tien'])) { ?>
                                                                Khu vực: <strong><?php echo htmlspecialchars((string) $cum['ten_phuong_uu_tien'], ENT_QUOTES, 'UTF-8'); ?></strong> &bull;
                                                            <?php } ?>
                                                            Mã tuyến: <?php echo htmlspecialchars((string) ($cum['ma_cum_tuyen'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
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
                                                <div style="font-size: 0.73rem;">
                                                    <span class="text-success fw-semibold" title="Đã giao thành công">&check; <?php echo htmlspecialchars((string) $soDaGiao, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    &bull;
                                                    <span class="text-primary fw-semibold" title="Đang chờ giao">Chờ: <?php echo htmlspecialchars((string) $soDangCho, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php if ($soThatBai > 0) { ?>
                                                        &bull; <span class="text-danger fw-semibold" title="Giao thất bại">&times; <?php echo htmlspecialchars((string) $soThatBai, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php } ?>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold text-dark">
                                                    <?php echo htmlspecialchars(number_format((float) ($cum['km_toi_uu'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                                <div class="text-decoration-line-through text-muted" style="font-size: 0.72rem;">
                                                    Gốc: <?php echo htmlspecialchars(number_format((float) ($cum['km_baseline'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-success-subtle text-success border">
                                                    -<?php echo htmlspecialchars(number_format((float) ($cum['ty_le_tiet_kiem_phan_tram'] ?? 0), 1), ENT_QUOTES, 'UTF-8'); ?>%
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary btn-focus-cluster"
                                                        data-cluster-id="<?php echo htmlspecialchars((string) ($cum['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="Phóng tới tuyến này trên bản đồ và lọc đơn hàng">
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

    <!-- 4. Bảng Giám sát Chi tiết Trạng thái Đơn hàng theo thời gian thực (Đồng bộ với App Tài xế) -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="fw-bold text-dark">
                    <i class="bi bi-box-seam-fill text-primary me-1"></i>Bảng Giám Sát Trạng Thái Đơn Hàng Trong Các Tuyến Giao
                </span>
                <span class="small text-muted ms-2">
                    Cập nhật trực tiếp trạng thái tài xế xác nhận (<strong>Đang chờ giao</strong>, <strong>Đã giao thành công</strong>, <strong>Giao thất bại &amp; Lý do</strong>).
                </span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <select id="filter-cluster-orders" class="form-select form-select-sm" style="width: auto;">
                    <option value="all">Tất cả các tuyến / tài xế</option>
                    <?php foreach ($danhSachCum as $cumOpt) { ?>
                        <option value="<?php echo htmlspecialchars((string) ($cumOpt['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string) ($cumOpt['ten_tai_xe'] ?? '') . ' (' . (string) ($cumOpt['ma_cum_tuyen'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php } ?>
                </select>
                <select id="filter-status-orders" class="form-select form-select-sm" style="width: auto;">
                    <option value="all">Tất cả trạng thái</option>
                    <option value="da_phan_cong">Đang chờ giao</option>
                    <option value="giao_thanh_cong">Đã giao thành công</option>
                    <option value="giao_that_bai">Giao thất bại / Hoàn</option>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 380px;">
                <table class="table table-hover align-middle mb-0 small" id="table-tat-ca-don-cum">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th style="width: 90px;" class="text-center">Thứ tự giao</th>
                            <th style="width: 145px;">Mã vận đơn</th>
                            <th style="width: 190px;">Khách hàng / SĐT</th>
                            <th>Địa chỉ giao hàng &amp; Phường/Xã</th>
                            <th style="width: 120px;" class="text-end">Thu hộ COD</th>
                            <th style="width: 190px;">Tài xế phụ trách</th>
                            <th style="width: 210px;">Trạng thái giao hàng</th>
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

    <!-- 5. Bảng Xử lý nhanh các đơn hàng cần xác minh địa chỉ (Sai tọa độ / Ngoài vùng phục vụ / Quá tải) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="fw-bold text-danger">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i>Đơn Hàng Cần Xác Minh Lại Địa Chỉ / Điều Phối Bổ Sung
                </span>
                <span class="small text-muted ms-2">
                    Hỗ trợ chọn lại Phường/Xã, tự động tra tọa độ từ địa chỉ qua <strong>Bản đồ OpenStreetMap (Nominatim)</strong> hoặc chấm chọn trực tiếp trên bản đồ để gán vào tuyến gần nhất.
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
                            <th>Địa chỉ giao hàng</th>
                            <th style="width: 150px;">Nguyên nhân</th>
                            <th style="width: 260px;">Chọn Phường/Xã thuộc Bưu cục</th>
                            <th style="width: 230px;">Tọa độ định vị (Vĩ / Kinh độ)</th>
                            <th style="width: 220px;" class="text-center">Thao tác xác nhận &amp; Gán tuyến</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-don-loi">
                        <?php if (empty($danhSachDonLoi)) { ?>
                            <tr>
                                <td colspan="7" class="text-center text-success py-4">
                                    <i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng hợp lệ đã được phân tuyến cho tài xế. Không có đơn tồn đọng.
                                </td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($danhSachDonLoi as $donLoi) { ?>
                                <?php
                                $lyDo = (string) ($donLoi['ly_do_chua_phan_cong'] ?? 'loi_toa_do');
                                $nhanLyDo = 'Sai lệch tọa độ (> 25km)';
                                $mauBadge = 'bg-danger';
                                if ($lyDo === 'ngoai_vung_phuc_vu') {
                                    $nhanLyDo = 'Khác khu vực Phường/Xã';
                                    $mauBadge = 'bg-warning text-dark';
                                } elseif ($lyDo === 'qua_tai_tai_xe') {
                                    $nhanLyDo = 'Vượt định mức tải ca';
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
                                            Phường khai báo ban đầu: <strong><?php echo htmlspecialchars((string) ($donLoi['phuong_ten_goc'] ?? 'Không rõ'), ENT_QUOTES, 'UTF-8'); ?></strong>
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
                                                        title="Tự động tìm tọa độ từ địa chỉ">
                                                    <i class="bi bi-search me-1"></i>Tìm tọa độ
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-pick-on-map"
                                                        data-order-id="<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="Click chọn vị trí trực tiếp trên bản đồ">
                                                    <i class="bi bi-crosshair me-1"></i>Chấm bản đồ
                                                </button>
                                            </div>
                                            <button type="button"
                                                    class="btn btn-sm btn-success fw-semibold btn-save-don-loi"
                                                    data-order-id="<?php echo htmlspecialchars((string) ($donLoi['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="bi bi-check2-circle me-1"></i>Xác nhận &amp; Gán vào tuyến
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

<?php
require dirname(__DIR__) . '/layouts/footer.php';
?>
