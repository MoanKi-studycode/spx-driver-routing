<?php

declare(strict_types=1);

/**
 * View: Màn hình Thống kê & Thực nghiệm Thuật toán (/thong-ke)
 * Tuân thủ sử dụng thẻ đầy đủ <?php ... ?> và escape dữ liệu bằng htmlspecialchars().
 */

$baseUrl = defined('BASE_URL') ? BASE_URL : '/dinh-tuyen-spx/public';

$layoutDir = __DIR__ . '/../layouts';
$hasHeaderLayout = is_file($layoutDir . '/header.php');
$hasNavbarLayout = is_file($layoutDir . '/navbar.php');
$hasFooterLayout = is_file($layoutDir . '/footer.php');

if ($hasHeaderLayout) {
    require $layoutDir . '/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string) ($pageTitle ?? 'Thống Kê & Thực Nghiệm - SPX Express'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
            <a href="<?php echo htmlspecialchars($baseUrl . '/dieu-phoi', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-diagram-3-fill me-1"></i>Bản đồ Điều phối
            </a>
            <a href="<?php echo htmlspecialchars($baseUrl . '/thong-ke', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-light fw-semibold text-danger">
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

<div class="container-fluid py-3 px-3">
    <!-- 1. Header thông tin Bưu cục & Nút Chạy Benchmark -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-lg-7">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-danger px-2 py-1">
                            <i class="bi bi-bar-chart-steps me-1"></i><?php echo htmlspecialchars((string) ($buuCuc['ma_buu_cuc'] ?? 'SPX_KH_NT01'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h5 class="mb-0 fw-bold text-dark">
                            Thống Kê &amp; Thực Nghiệm Thuật Toán: <?php echo htmlspecialchars((string) ($buuCuc['ten_buu_cuc'] ?? '') . ' (' . ($buuCuc['ten_quan_huyen'] ?? '') . ' - ' . ($buuCuc['ten_tinh'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                    </div>
                    <div class="small text-secondary">
                        Đánh giá định lượng hiệu quả <strong>Balanced K-Means + Nearest Neighbor (NN) + 2-Opt</strong> so với lộ trình gốc <strong>Baseline</strong> (Hệ số đường bộ: <?php echo htmlspecialchars((string) (defined('HE_SO_DUONG_BO') ? HE_SO_DUONG_BO : 1.3), ENT_QUOTES, 'UTF-8'); ?>).
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                        <form method="GET" action="<?php echo htmlspecialchars($baseUrl . '/thong-ke', ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center gap-1">
                            <label for="select-buu-cuc-tk" class="small text-muted text-nowrap mb-0">Cơ sở:</label>
                            <select id="select-buu-cuc-tk" name="buu_cuc_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($danhSachBuuCuc as $bcItem) { ?>
                                    <option value="<?php echo htmlspecialchars((string) $bcItem['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo ((int) $bcItem['id'] === (int) $buuCuc['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $bcItem['ten_buu_cuc'] . ' (' . (string) $bcItem['ten_quan_huyen'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>

                        <button type="button" id="btn-run-benchmark" class="btn btn-primary fw-bold shadow-sm">
                            <i class="bi bi-cpu-fill me-1"></i>Chạy Benchmark thực nghiệm (10, 20, 30, 40 đơn)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="alert-thong-ke" class="alert d-none alert-dismissible fade show shadow-sm mb-3" role="alert">
        <span id="alert-thong-ke-text"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button>
    </div>

    <!-- 2. Thẻ chỉ số tổng hợp của các cụm tuyến thực tế -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">Tổng số Cụm / Đơn đã phân công</div>
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

    <!-- 3. Hai Biểu đồ Chart.js: Bar Chart so sánh km (Baseline vs NN vs 2-Opt) & Line Chart thời gian thực thi (ms) -->
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

    <!-- 4. Bảng Số liệu Thực nghiệm Benchmark (10, 20, 30, 40 đơn) phục vụ Báo cáo Đồ án -->
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
                                    <th class="text-center">Quy mô ($N$ đơn)</th>
                                    <th class="text-center">Số cụm ($K$)</th>
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
                                        <td class="text-center fw-bold"><?php echo htmlspecialchars((string) $row['so_don'], ENT_QUOTES, 'UTF-8'); ?> đơn</td>
                                        <td class="text-center"><?php echo htmlspecialchars((string) $row['so_cum'], ENT_QUOTES, 'UTF-8'); ?> cụm</td>
                                        <td class="text-end text-muted"><?php echo htmlspecialchars(number_format((float) $row['km_baseline'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars(number_format((float) $row['km_nn'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo htmlspecialchars(number_format((float) $row['km_toi_uu'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end">
                                            <span class="badge bg-success-subtle text-success border">
                                                -<?php echo htmlspecialchars(number_format((float) $row['ty_le_tiet_kiem_baseline'], 2), ENT_QUOTES, 'UTF-8'); ?>%
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold text-primary">
                                            <?php echo htmlspecialchars(number_format((float) $row['thoi_gian_chay_ms'], 3), ENT_QUOTES, 'UTF-8'); ?> ms
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. Bảng Số liệu Chi tiết từng Cụm tuyến trong CSDL hiện tại -->
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark">
                        <i class="bi bi-card-checklist text-success me-1"></i>Số Liệu Chi Tiết Các Cụm Tuyến Đã Lập Tại Bưu Cục
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
                                                <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars((string) $cum['ma_cum_tuyen'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            </td>
                                            <td class="text-center"><?php echo htmlspecialchars((string) $cum['so_don_hang'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-end text-muted"><?php echo htmlspecialchars(number_format((float) $cum['km_baseline'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars(number_format((float) $cum['km_nn'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-end fw-bold text-success"><?php echo htmlspecialchars(number_format((float) $cum['km_toi_uu'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-end">
                                                <span class="badge bg-success-subtle text-success border">
                                                    -<?php echo htmlspecialchars(number_format((float) $cum['ty_le_tiet_kiem_phan_tram'], 2), ENT_QUOTES, 'UTF-8'); ?>%
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo htmlspecialchars(number_format((float) $cum['thoi_gian_chay_ms'], 3), ENT_QUOTES, 'UTF-8'); ?></td>
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

<!-- Truyền dữ liệu thống kê xuống file JS bieu_do.js -->
<script>
window.SPX_STATS_CONFIG = <?php echo json_encode([
    'baseUrl' => $baseUrl,
    'buuCuc' => $buuCuc,
    'tongHop' => $tongHop,
    'danhSachCum' => $danhSachCum,
    'benchmark' => $benchmarkData,
], JSON_UNESCAPED_UNICODE); ?>;
</script>

<?php
if ($hasFooterLayout) {
    require $layoutDir . '/footer.php';
} else {
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?php echo htmlspecialchars($baseUrl . '/assets/js/bieu_do.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
<?php
}
?>
