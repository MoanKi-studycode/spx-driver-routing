<?php

declare(strict_types=1);

/**
 * View: Màn hình Thống kê Vận hành & Thực nghiệm Thuật toán (/thong-ke)
 * Chia thành 2 Tab độc lập theo Mục 3.C:
 * - Tab 1 (Mặc định): Quản Trị Vận Hành Bưu Cục & Đối soát COD cuối ngày
 * - Tab 2: Đánh Giá & Thực Nghiệm Thuật Toán (Algorithm Benchmark)
 * Tuân thủ sử dụng thẻ đầy đủ <?php ... ?> và escape dữ liệu bằng htmlspecialchars().
 */

$baseUrl = defined('BASE_URL') ? BASE_URL : '/dinh-tuyen-spx/public';

$buuCuc = isset($buuCuc) && is_array($buuCuc) ? $buuCuc : [];
$danhSachBuuCuc = isset($danhSachBuuCuc) && is_array($danhSachBuuCuc) ? $danhSachBuuCuc : [];
$danhSachCum = isset($danhSachCum) && is_array($danhSachCum) ? $danhSachCum : [];
$tongHop = isset($tongHop) && is_array($tongHop) ? $tongHop : [];
$thongKeTrangThai = isset($thongKeTrangThai) && is_array($thongKeTrangThai) ? $thongKeTrangThai : [];
$thongKeTaiXeCod = isset($thongKeTaiXeCod) && is_array($thongKeTaiXeCod) ? $thongKeTaiXeCod : [];
$thongKeTheoPhuong = isset($thongKeTheoPhuong) && is_array($thongKeTheoPhuong) ? $thongKeTheoPhuong : [];
$benchmarkData = isset($benchmarkData) && is_array($benchmarkData) ? $benchmarkData : [];
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';

$title = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? $pageTitle
    : 'Thống Kê Vận Hành & Thực Nghiệm - SPX Express';
$pageScript = $baseUrl . '/assets/js/bieu_do.js';

$tongDonHomNay = (int) ($thongKeTrangThai['tong_so_don'] ?? ($tongHop['tong_so_don'] ?? 0));
$soDonChuaPhanCong = (int) ($thongKeTrangThai['chua_phan_cong'] ?? 0);
$soDonDangGiao = (int) ($thongKeTrangThai['da_phan_cong'] ?? ($tongHop['tong_don_dang_cho'] ?? 0));
$soDonThanhCong = (int) ($thongKeTrangThai['giao_thanh_cong'] ?? ($tongHop['tong_don_da_giao'] ?? 0));
$soDonThatBai = (int) ($thongKeTrangThai['giao_that_bai'] ?? ($tongHop['tong_don_that_bai'] ?? 0));
$soDonDaXuLy = (int) ($thongKeTrangThai['so_don_da_xu_ly'] ?? ($soDonThanhCong + $soDonThatBai));
$tyLeSla = (float) ($thongKeTrangThai['ty_le_thanh_cong_sla'] ?? ($soDonDaXuLy > 0 ? round(($soDonThanhCong / $soDonDaXuLy) * 100, 2) : 0.0));
$codDaThu = (float) ($thongKeTrangThai['cod_da_thu'] ?? 0.0);
$codPhaiThuTrenTuyen = (float) ($thongKeTrangThai['tong_cod_phai_thu_tren_tuyen'] ?? 0.0);

$statsConfigJson = json_encode([
    'baseUrl' => $baseUrl,
    'buuCuc' => $buuCuc,
    'tongHop' => $tongHop,
    'thongKeTrangThai' => $thongKeTrangThai,
    'thongKeTaiXeCod' => $thongKeTaiXeCod,
    'thongKeTheoPhuong' => $thongKeTheoPhuong,
    'danhSachCum' => $danhSachCum,
    'benchmark' => $benchmarkData,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if (!is_string($statsConfigJson)) {
    $statsConfigJson = '{}';
}

require dirname(__DIR__) . '/layouts/header.php';
require dirname(__DIR__) . '/layouts/navbar.php';
?>

<div id="spx-stats-config" class="d-none" data-config="<?php echo htmlspecialchars($statsConfigJson, ENT_QUOTES, 'UTF-8'); ?>"></div>

<div class="container-fluid py-3 px-3">
    <!-- 1. Header thông tin Bưu cục & Bộ chuyển đổi Tab -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-lg-7">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-danger px-2 py-1">
                            <i class="bi bi-bar-chart-steps me-1"></i><?php echo htmlspecialchars((string) ($buuCuc['ma_buu_cuc'] ?? 'SPX_KH_NT01'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h5 class="mb-0 fw-bold text-dark">
                            Trung Tâm Báo Cáo &amp; Thống Kê: <?php echo htmlspecialchars((string) ($buuCuc['ten_buu_cuc'] ?? '') . ' (' . (string) ($buuCuc['ten_quan_huyen'] ?? '') . ' - ' . (string) ($buuCuc['ten_tinh'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                    </div>
                    <div class="small text-secondary">
                        Tích hợp <strong>Bảng Điều Hành Vận Hành &amp; Đối Soát Tiền Thu Hộ COD Cuối Ca</strong> song song với <strong>Đánh Giá Thực Nghiệm Thuật Toán (Balanced K-Means + NN + 2-Opt)</strong>.
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                        <form method="GET" action="<?php echo htmlspecialchars($baseUrl . '/thong-ke', ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center gap-1">
                            <label for="select-buu-cuc-tk" class="small text-muted text-nowrap mb-0">Cơ sở:</label>
                            <select id="select-buu-cuc-tk" name="buu_cuc_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($danhSachBuuCuc as $bcItem) { ?>
                                    <option value="<?php echo htmlspecialchars((string) ($bcItem['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo ((int) ($bcItem['id'] ?? 0) === (int) ($buuCuc['id'] ?? 0)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($bcItem['ten_buu_cuc'] ?? '') . ' (' . (string) ($bcItem['ten_quan_huyen'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>

                        <button type="button" id="btn-refresh-stats" class="btn btn-sm btn-outline-primary fw-semibold">
                            <i class="bi bi-arrow-clockwise me-1"></i>Cập nhật số liệu mới nhất
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thanh 2 Tab độc lập: Tab 1 (Vận hành Bưu cục) & Tab 2 (Thực nghiệm Thuật toán) -->
        <div class="card-footer bg-white pt-2 pb-0 border-top">
            <ul class="nav nav-tabs card-header-tabs" id="statsMainTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="tab-btn-van-hanh" data-bs-toggle="tab"
                            data-bs-target="#tab-pane-van-hanh" type="button" role="tab">
                        <i class="bi bi-speedometer2 text-danger me-1"></i>Tab 1 — Quản Trị Vận Hành Bưu Cục &amp; Đối Soát COD (Operational Dashboard)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="tab-btn-thuat-toan" data-bs-toggle="tab"
                            data-bs-target="#tab-pane-thuat-toan" type="button" role="tab">
                        <i class="bi bi-cpu-fill text-primary me-1"></i>Tab 2 — Đánh Giá &amp; Thực Nghiệm Thuật Toán (Algorithm Benchmark)
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <div id="alert-thong-ke" class="alert d-none alert-dismissible fade show shadow-sm mb-3" role="alert">
        <span id="alert-thong-ke-text"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button>
    </div>

    <div class="tab-content" id="statsMainTabsContent">
        <!-- ========================================================================= -->
        <!-- TAB 1 (MẶC ĐỊNH): QUẢN TRỊ VẬN HÀNH BƯU CỤC (OPERATIONAL DASHBOARD)        -->
        <!-- ========================================================================= -->
        <div class="tab-pane fade show active" id="tab-pane-van-hanh" role="tabpanel">
            <!-- 1.1: 4 Thẻ KPI Vận hành (Mục 3.C) -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">1. Tổng số đơn hàng hôm nay</div>
                            <div class="fs-4 fw-bold text-primary" id="kpi-op-tong-don">
                                <?php echo htmlspecialchars((string) $tongDonHomNay, ENT_QUOTES, 'UTF-8'); ?> đơn
                            </div>
                            <div class="small text-secondary" id="kpi-op-tong-don-sub">
                                Chờ phân công: <strong class="text-warning-emphasis"><?php echo htmlspecialchars((string) $soDonChuaPhanCong, ENT_QUOTES, 'UTF-8'); ?></strong>
                                &bull; Đang đi giao: <strong class="text-primary"><?php echo htmlspecialchars((string) $soDonDangGiao, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">2. Tỷ lệ giao hàng thành công (SLA %)</div>
                            <div class="fs-4 fw-bold text-success" id="kpi-op-sla">
                                <?php echo htmlspecialchars(number_format($tyLeSla, 1), ENT_QUOTES, 'UTF-8'); ?>%
                            </div>
                            <div class="small text-secondary" id="kpi-op-sla-sub">
                                Thành công <strong><?php echo htmlspecialchars((string) $soDonThanhCong, ENT_QUOTES, 'UTF-8'); ?></strong>
                                / <?php echo htmlspecialchars((string) $soDonDaXuLy, ENT_QUOTES, 'UTF-8'); ?> đơn đã xử lý
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">3. Tiền thu hộ COD đã thu / Phải thu</div>
                            <div class="fs-5 fw-bold text-dark" id="kpi-op-cod">
                                <span class="text-success"><?php echo htmlspecialchars(number_format($codDaThu, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?></span>
                                / <?php echo htmlspecialchars(number_format($codPhaiThuTrenTuyen, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ
                            </div>
                            <div class="small text-muted" id="kpi-op-cod-sub">
                                Phục vụ kế toán bưu cục thu tiền mặt cuối ca
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">4. Đơn giao thất bại cần xử lý lại</div>
                            <div class="fs-4 fw-bold text-danger" id="kpi-op-that-bai">
                                <?php echo htmlspecialchars((string) $soDonThatBai, ENT_QUOTES, 'UTF-8'); ?> đơn
                            </div>
                            <div class="small text-secondary" id="kpi-op-that-bai-sub">
                                Cần xác nhận hẹn giao lại hoặc hoàn kho
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 1.2: 3 Biểu đồ vận hành thực tế (Doughnut, Stacked Bar, Grouped Bar) -->
            <div class="row g-3 mb-3">
                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2">
                            <span class="fw-bold text-dark small">
                                <i class="bi bi-pie-chart-fill text-danger me-1"></i>1. Tỷ Lệ Trạng Thái Đơn Hàng Toàn Bưu Cục
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="height: 300px;">
                                <canvas id="chart-trang-thai-don"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2">
                            <span class="fw-bold text-dark small">
                                <i class="bi bi-bar-chart-steps text-primary me-1"></i>2. Tiến Độ Giao Hàng Theo Tài Xế (Stacked)
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="height: 300px;">
                                <canvas id="chart-tien-do-tai-xe"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2">
                            <span class="fw-bold text-dark small">
                                <i class="bi bi-cash-coin text-success me-1"></i>3. Đối Soát Tiền Thu Hộ COD Theo Tài Xế
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="height: 300px;">
                                <canvas id="chart-doi-soat-cod"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 1.3: Bảng Đối soát Chi tiết Cụm Tuyến & COD Cuối Ngày + Thống kê theo Phường -->
            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span class="fw-bold text-dark">
                                <i class="bi bi-receipt-cutoff text-success me-1"></i>Bảng Đối Soát Chi Tiết Cụm Tuyến &amp; Tiền Thu Hộ COD Cuối Ngày
                            </span>
                            <span class="badge bg-success-subtle text-success border">Dành cho Kế toán &amp; Admin Bưu cục</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 small" id="table-doi-soat-cod">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tài xế (Shipper) / Khu vực</th>
                                            <th class="text-center">Tổng đơn</th>
                                            <th class="text-center">Đã giao</th>
                                            <th class="text-center">Đang giao</th>
                                            <th class="text-center">Thất bại</th>
                                            <th class="text-end">Tổng COD cần thu</th>
                                            <th class="text-end">COD thực thu (Phải nộp)</th>
                                            <th class="text-center">Trạng thái ca</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-doi-soat-cod">
                                        <?php if (empty($thongKeTaiXeCod)) { ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">Chưa có dữ liệu tài xế.</td>
                                            </tr>
                                        <?php } else { ?>
                                            <?php
                                            $sumTongDon = 0;
                                            $sumThanhCong = 0;
                                            $sumDangGiao = 0;
                                            $sumThatBai = 0;
                                            $sumCodPhaiThu = 0.0;
                                            $sumCodThucThu = 0.0;
                                            ?>
                                            <?php foreach ($thongKeTaiXeCod as $txRow) { ?>
                                                <?php
                                                $tongDonTx = (int) ($txRow['tong_so_don'] ?? 0);
                                                $thanhCongTx = (int) ($txRow['so_don_thanh_cong'] ?? 0);
                                                $dangGiaoTx = (int) ($txRow['so_don_dang_giao'] ?? 0);
                                                $thatBaiTx = (int) ($txRow['so_don_that_bai'] ?? 0);
                                                $codPhaiThuTx = (float) ($txRow['tong_cod_phai_thu'] ?? 0.0);
                                                $codThucThuTx = (float) ($txRow['tong_cod_thuc_thu'] ?? 0.0);

                                                $sumTongDon += $tongDonTx;
                                                $sumThanhCong += $thanhCongTx;
                                                $sumDangGiao += $dangGiaoTx;
                                                $sumThatBai += $thatBaiTx;
                                                $sumCodPhaiThu += $codPhaiThuTx;
                                                $sumCodThucThu += $codThucThuTx;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="d-inline-block rounded-circle"
                                                                  style="width:12px;height:12px;background:<?php echo htmlspecialchars((string) ($txRow['mau_sac_cum'] ?? '#2563eb'), ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                                            <div>
                                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars((string) ($txRow['ho_ten'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                                <div class="text-muted" style="font-size:0.72rem;">
                                                                    SĐT: <?php echo htmlspecialchars((string) ($txRow['so_dien_thoai'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                                    &bull; Tuyến: <code><?php echo htmlspecialchars((string) ($txRow['ma_cum_tuyen'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></code>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="text-center fw-bold"><?php echo htmlspecialchars((string) $tongDonTx, ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center text-success fw-bold"><?php echo htmlspecialchars((string) $thanhCongTx, ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center text-primary fw-semibold"><?php echo htmlspecialchars((string) $dangGiaoTx, ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center text-danger fw-semibold"><?php echo htmlspecialchars((string) $thatBaiTx, ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-end text-secondary"><?php echo htmlspecialchars(number_format($codPhaiThuTx, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</td>
                                                    <td class="text-end fw-bold text-success"><?php echo htmlspecialchars(number_format($codThucThuTx, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</td>
                                                    <td class="text-center">
                                                        <?php if ($tongDonTx > 0 && $dangGiaoTx === 0) { ?>
                                                            <span class="badge bg-success">Đã hoàn tất ca</span>
                                                        <?php } elseif ($tongDonTx > 0) { ?>
                                                            <span class="badge bg-primary-subtle text-primary border">Đang đi giao</span>
                                                        <?php } else { ?>
                                                            <span class="badge bg-secondary-subtle text-secondary">Chưa nhận tuyến</span>
                                                        <?php } ?>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                            <tr class="table-light fw-bold">
                                                <td>TỔNG CỘNG ĐỐI SOÁT TOÀN BƯU CỤC</td>
                                                <td class="text-center"><?php echo htmlspecialchars((string) $sumTongDon, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-center text-success"><?php echo htmlspecialchars((string) $sumThanhCong, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-center text-primary"><?php echo htmlspecialchars((string) $sumDangGiao, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-center text-danger"><?php echo htmlspecialchars((string) $sumThatBai, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars(number_format($sumCodPhaiThu, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</td>
                                                <td class="text-end text-success"><?php echo htmlspecialchars(number_format($sumCodThucThu, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</td>
                                                <td class="text-center">-</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bảng Thống kê theo Phường/Xã -->
                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2">
                            <span class="fw-bold text-dark">
                                <i class="bi bi-geo-alt-fill text-danger me-1"></i>Sản Lượng &amp; Hoàn Thành Theo Phường/Xã
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 small" id="table-thong-ke-phuong">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Phường/Xã</th>
                                            <th class="text-center">Tổng đơn</th>
                                            <th class="text-center">Đã giao</th>
                                            <th class="text-end">Tỷ lệ xong</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-thong-ke-phuong">
                                        <?php foreach ($thongKeTheoPhuong as $phRow) { ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars((string) ($phRow['ten_phuong'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="text-muted" style="font-size:0.7rem;">Mã HC: <?php echo htmlspecialchars((string) ($phRow['ma_hanh_chinh'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                </td>
                                                <td class="text-center fw-bold"><?php echo htmlspecialchars((string) ($phRow['tong_so_don'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-center text-success fw-semibold"><?php echo htmlspecialchars((string) ($phRow['so_don_thanh_cong'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-info-subtle text-dark border">
                                                        <?php echo htmlspecialchars(number_format((float) ($phRow['ty_le_hoan_thanh'] ?? 0), 1), ENT_QUOTES, 'UTF-8'); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- TAB 2: ĐÁNH GIÁ & THỰC NGHIỆM THUẬT TOÁN (ALGORITHM BENCHMARK)             -->
        <!-- ========================================================================= -->
        <div class="tab-pane fade" id="tab-pane-thuat-toan" role="tabpanel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="small text-secondary">
                    <i class="bi bi-journal-code text-primary me-1"></i>Đánh giá định lượng hiệu quả <strong>Balanced K-Means + Nearest Neighbor (NN) + 2-Opt</strong> so với lộ trình gốc <strong>Baseline</strong> (Hệ số đường bộ: <?php echo htmlspecialchars((string) (defined('HE_SO_DUONG_BO') ? HE_SO_DUONG_BO : 1.3), ENT_QUOTES, 'UTF-8'); ?>).
                </div>
                <button type="button" id="btn-run-benchmark" class="btn btn-primary btn-sm fw-bold shadow-sm">
                    <i class="bi bi-cpu-fill me-1"></i>Chạy Benchmark thực nghiệm (10, 20, 30, 40 đơn)
                </button>
            </div>

            <!-- 2.1: 4 Thẻ KPI Thuật toán -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">Tổng số Cụm / Đơn trong tuyến</div>
                            <div class="fs-4 fw-bold text-primary" id="stat-so-cum-don">
                                <?php echo htmlspecialchars((string) ($tongHop['so_cum'] ?? 0) . ' cụm / ' . (string) ($tongHop['tong_so_don'] ?? 0) . ' đơn', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="small text-secondary">
                                Đã giao: <strong class="text-success"><?php echo htmlspecialchars((string) ($tongHop['tong_don_da_giao'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong> |
                                Thất bại: <strong class="text-danger"><?php echo htmlspecialchars((string) ($tongHop['tong_don_that_bai'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">Quãng đường Baseline vs NN</div>
                            <div class="fs-5 fw-bold text-dark" id="stat-km-baseline-nn">
                                <?php echo htmlspecialchars(number_format((float) ($tongHop['tong_km_baseline'] ?? 0), 2) . ' km → ' . number_format((float) ($tongHop['tong_km_nn'] ?? 0), 2) . ' km', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="small text-muted">Sau bước tham lam Nearest Neighbor</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">Quãng đường Tối ưu (Sau 2-Opt)</div>
                            <div class="fs-4 fw-bold text-success" id="stat-km-toi-uu">
                                <?php echo htmlspecialchars(number_format((float) ($tongHop['tong_km_toi_uu'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km
                            </div>
                            <div class="small text-success fw-semibold" id="stat-ty-le-tiet-kiem">
                                Giảm <?php echo htmlspecialchars(number_format((float) ($tongHop['km_tiet_kiem_baseline'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> km (-<?php echo htmlspecialchars(number_format((float) ($tongHop['ty_le_tiet_kiem_phan_tram'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?>% so với Baseline)
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">Tổng thời gian chạy thuật toán</div>
                            <div class="fs-4 fw-bold text-info" id="stat-thoi-gian-ms">
                                <?php echo htmlspecialchars(number_format((float) ($tongHop['tong_thoi_gian_chay_ms'] ?? 0), 3), ENT_QUOTES, 'UTF-8'); ?> ms
                            </div>
                            <div class="small text-muted">Tốc độ xử lý thời gian thực (&lt; 50 ms)</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2.2: 2 Biểu đồ học thuật (chart-quang-duong & chart-thoi-gian) -->
            <div class="row g-3 mb-3">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark">
                                <i class="bi bi-bar-chart-fill text-primary me-1"></i>Biểu Đồ So Sánh Quãng Đường: Baseline vs Nearest Neighbor vs 2-Opt (km)
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="height: 330px;">
                                <canvas id="chart-quang-duong"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark">
                                <i class="bi bi-graph-up-arrow text-danger me-1"></i>Biểu Đồ Thời Gian Thực Thi Thuật Toán (ms)
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="height: 330px;">
                                <canvas id="chart-thoi-gian"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2.3: 2 Bảng số liệu Benchmark & Cụm tuyến -->
            <div class="row g-3">
                <div class="col-xl-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-primary">
                                <i class="bi bi-table me-1"></i>Bảng Kết Quả Benchmark Thực Nghiệm (10, 20, 30, 40 Đơn)
                            </span>
                            <span class="badge bg-primary-subtle text-primary border">Số liệu dùng cho Báo cáo / Slide</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0 small" id="table-benchmark">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">Quy mô (N đơn)</th>
                                            <th class="text-center">Số cụm (K)</th>
                                            <th class="text-end">Baseline (km)</th>
                                            <th class="text-end">Nearest Neighbor (km)</th>
                                            <th class="text-end">2-Opt Tối ưu (km)</th>
                                            <th class="text-end">% Giảm vs Baseline</th>
                                            <th class="text-end">Thời gian (ms)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-benchmark">
                                        <?php foreach ($benchmarkData as $row) { ?>
                                            <tr>
                                                <td class="text-center fw-bold"><?php echo htmlspecialchars((string) ($row['so_don'] ?? 0), ENT_QUOTES, 'UTF-8'); ?> đơn</td>
                                                <td class="text-center"><?php echo htmlspecialchars((string) ($row['so_cum'] ?? 0), ENT_QUOTES, 'UTF-8'); ?> cụm</td>
                                                <td class="text-end text-muted"><?php echo htmlspecialchars(number_format((float) ($row['km_baseline'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars(number_format((float) ($row['km_nn'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end fw-bold text-success"><?php echo htmlspecialchars(number_format((float) ($row['km_toi_uu'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-success-subtle text-success border">
                                                        -<?php echo htmlspecialchars(number_format((float) ($row['ty_le_tiet_kiem_baseline'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?>%
                                                    </span>
                                                </td>
                                                <td class="text-end fw-semibold text-primary">
                                                    <?php echo htmlspecialchars(number_format((float) ($row['thoi_gian_chay_ms'] ?? 0), 3), ENT_QUOTES, 'UTF-8'); ?> ms
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark">
                                <i class="bi bi-card-checklist text-success me-1"></i>Số Liệu Thuật Toán Từng Cụm Tuyến Hiện Tại
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 small" id="table-thong-ke-cum">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Mã cụm / Tài xế</th>
                                            <th class="text-center">Số đơn</th>
                                            <th class="text-end">Baseline (km)</th>
                                            <th class="text-end">NN (km)</th>
                                            <th class="text-end">2-Opt (km)</th>
                                            <th class="text-end">% Tiết kiệm</th>
                                            <th class="text-end">Thời gian (ms)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-thong-ke-cum">
                                        <?php if (empty($danhSachCum)) { ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    Chưa có dữ liệu cụm tuyến trong CSDL. Biểu đồ đang hiển thị dữ liệu từ bộ Benchmark thực nghiệm.
                                                </td>
                                            </tr>
                                        <?php } else { ?>
                                            <?php foreach ($danhSachCum as $cum) { ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars((string) ($cum['ten_tai_xe'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars((string) ($cum['ma_cum_tuyen'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </td>
                                                    <td class="text-center"><?php echo htmlspecialchars((string) ($cum['so_don_hang'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-end text-muted"><?php echo htmlspecialchars(number_format((float) ($cum['km_baseline'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-end"><?php echo htmlspecialchars(number_format((float) ($cum['km_nn'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-end fw-bold text-success"><?php echo htmlspecialchars(number_format((float) ($cum['km_toi_uu'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-end">
                                                        <span class="badge bg-success-subtle text-success border">
                                                            -<?php echo htmlspecialchars(number_format((float) ($cum['ty_le_tiet_kiem_phan_tram'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-end"><?php echo htmlspecialchars(number_format((float) ($cum['thoi_gian_chay_ms'] ?? 0), 3), ENT_QUOTES, 'UTF-8'); ?></td>
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
        </div>
    </div>
</div>

<?php
require dirname(__DIR__) . '/layouts/footer.php';
?>
