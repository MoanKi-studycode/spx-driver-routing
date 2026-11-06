<?php
declare(strict_types=1);

$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$loginError = isset($loginError) && is_string($loginError) ? $loginError : null;
$trackingCode = isset($trackingCode) && is_string($trackingCode) ? $trackingCode : '';
$trackingResult = isset($trackingResult) && is_array($trackingResult) ? $trackingResult : null;
$trackingError = isset($trackingError) && is_string($trackingError) ? $trackingError : null;
$trackingStatus = (string) ($trackingResult['trang_thai'] ?? '');
$trackingStatusLabels = [
    'chua_phan_cong' => 'Bưu cục tiếp nhận - Đang chờ phân tuyến',
    'da_phan_cong' => 'Shipper đang đi giao hàng',
    'giao_thanh_cong' => 'Đã giao hàng thành công',
    'giao_that_bai' => 'Giao hàng không thành công',
];
$trackingProgressMap = [
    'chua_phan_cong' => 0,
    'da_phan_cong' => 2,
    'giao_thanh_cong' => 3,
    'giao_that_bai' => 3,
];
$trackingProgressIndex = $trackingProgressMap[$trackingStatus] ?? false;
$trackingProgressLabels = [
    'Bưu cục tiếp nhận',
    'Đã phân tuyến',
    'Shipper đang giao',
    'Hoàn tất giao hàng',
];

$maskPhone = static function (?string $phone): string {
    $digits = preg_replace('/\s+/', '', (string) $phone) ?? '';
    $len = strlen($digits);
    if ($len < 7) {
        return $digits !== '' ? '***' : '-';
    }
    return substr($digits, 0, 4) . '***' . substr($digits, -3);
};
?>
<main class="container auth-container">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5">
            <section class="card auth-card shadow-sm">
                <div class="card-body p-4 p-md-5">
                    <p class="eyebrow mb-2">SPX ROUTE</p>
                    <h1 class="h3 mb-2">Đăng nhập hệ thống</h1>
                    <p class="text-secondary mb-4">Dành cho điều phối viên và tài xế.</p>

                    <?php if (is_string($loginError) && $loginError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . '/login', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="on">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="ten_dang_nhap">Tên đăng nhập</label>
                            <input class="form-control form-control-lg" id="ten_dang_nhap" name="ten_dang_nhap" type="text" maxlength="50" autocomplete="username" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="mat_khau">Mật khẩu</label>
                            <input class="form-control form-control-lg" id="mat_khau" name="mat_khau" type="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary btn-lg w-100" type="submit">Đăng nhập</button>
                    </form>
                </div>
            </section>
            <section class="card auth-card shadow-sm mt-4" aria-labelledby="tracking-title">
                <div class="card-body p-4">
                    <p class="eyebrow mb-2">TRA CỨU CÔNG KHAI</p>
                    <h2 class="h5 mb-3" id="tracking-title">Tra cứu vận đơn</h2>
                    <form method="get" action="<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . '/login', ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label" for="ma_van_don">Mã vận đơn</label>
                        <div class="input-group">
                            <input class="form-control" id="ma_van_don" name="ma_van_don" type="text" maxlength="40" value="<?php echo htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8'); ?>" required>
                            <button class="btn btn-primary" type="submit">Tra cứu</button>
                        </div>
                    </form>
                    <?php if ($trackingError !== null): ?>
                        <div class="alert alert-warning mt-3 mb-0" role="status"><?php echo htmlspecialchars($trackingError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php elseif ($trackingResult !== null): ?>
                        <?php
                        $alertClass = $trackingStatus === 'giao_that_bai' ? 'alert-danger' : 'alert-success';
                        $codAmount = (float) ($trackingResult['tien_thu_ho'] ?? 0);
                        $customerPhone = $maskPhone((string) ($trackingResult['sdt_nguoi_nhan'] ?? $trackingResult['so_dien_thoai_khach'] ?? ''));
                        ?>
                        <div class="alert <?php echo $alertClass; ?> mt-3 mb-0" role="status">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <strong><?php echo htmlspecialchars($trackingStatusLabels[$trackingStatus] ?? 'Đã tìm thấy vận đơn', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="badge bg-dark-subtle text-dark border"><?php echo htmlspecialchars((string) ($trackingResult['ma_van_don'] ?? $trackingCode), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <?php if ($trackingProgressIndex !== false): ?>
                                <ol class="tracking-progress mt-3 mb-2" aria-label="Tiến trình vận đơn">
                                    <?php foreach ($trackingProgressLabels as $step => $label): ?>
                                        <?php
                                        $isCurrent = $step === $trackingProgressIndex;
                                        $isFailed = $isCurrent && $trackingStatus === 'giao_that_bai';
                                        $stepLabel = $isFailed ? 'Giao không thành công' : $label;
                                        ?>
                                        <li class="<?php echo $step < $trackingProgressIndex ? 'is-complete' : ($isCurrent ? ($isFailed ? 'is-failed' : 'is-current') : ''); ?>"<?php echo $isCurrent ? ' aria-current="step"' : ''; ?>>
                                            <?php echo htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>

                            <div class="small border-top pt-2 mt-2">
                                <?php if (!empty($trackingResult['ten_buu_cuc'])): ?>
                                    <div class="mb-1"><strong>Bưu cục xử lý:</strong> <?php echo htmlspecialchars((string) $trackingResult['ten_buu_cuc'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($trackingResult['ten_khach_hang'])): ?>
                                    <div class="mb-1"><strong>Người nhận:</strong> <?php echo htmlspecialchars((string) $trackingResult['ten_khach_hang'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>)</div>
                                <?php endif; ?>
                                <?php if (!empty($trackingResult['dia_chi_giao_hang'])): ?>
                                    <div class="mb-1"><strong>Địa chỉ giao:</strong> <?php echo htmlspecialchars((string) $trackingResult['dia_chi_giao_hang'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <div class="mb-1">
                                    <strong>Tiền thu hộ (COD):</strong>
                                    <?php if ($codAmount > 0): ?>
                                        <span class="fw-bold text-danger"><?php echo htmlspecialchars(number_format($codAmount, 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</span>
                                    <?php else: ?>
                                        <span class="fw-semibold text-success">Đơn 0đ - Không thu tiền</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($trackingResult['ten_tai_xe'])): ?>
                                    <div class="mb-1">
                                        <strong>Shipper phụ trách:</strong> <?php echo htmlspecialchars((string) $trackingResult['ten_tai_xe'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($trackingResult['sdt_tai_xe'])): ?>
                                            &bull; <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', (string) $trackingResult['sdt_tai_xe']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars((string) $trackingResult['sdt_tai_xe'], ENT_QUOTES, 'UTF-8'); ?></a>
                                        <?php endif; ?>
                                        <?php if ($trackingStatus === 'da_phan_cong' && !empty($trackingResult['thu_tu_giao'])): ?>
                                            <span class="text-muted">(Điểm giao dự kiến #<?php echo htmlspecialchars((string) $trackingResult['thu_tu_giao'], ENT_QUOTES, 'UTF-8'); ?> trong ca)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($trackingStatus === 'giao_thanh_cong' && !empty($trackingResult['thoi_gian_hoan_tat'])): ?>
                                    <div class="text-success fw-semibold mt-1">Thời gian hoàn tất: <?php echo htmlspecialchars((string) $trackingResult['thoi_gian_hoan_tat'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php elseif ($trackingStatus === 'giao_that_bai'): ?>
                                    <div class="text-danger fw-semibold mt-1">
                                        Lý do chưa giao được: <?php echo htmlspecialchars((string) ($trackingResult['ly_do_that_bai'] ?: 'Đang chờ bưu cục xác nhận giao lại'), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</main>
