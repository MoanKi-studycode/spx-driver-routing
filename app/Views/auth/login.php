<?php
declare(strict_types=1);

$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$loginError = isset($loginError) && is_string($loginError) ? $loginError : null;
$trackingCode = isset($trackingCode) && is_string($trackingCode) ? $trackingCode : '';
$trackingResult = isset($trackingResult) && is_array($trackingResult) ? $trackingResult : null;
$trackingError = isset($trackingError) && is_string($trackingError) ? $trackingError : null;
$trackingStatus = (string) ($trackingResult['trang_thai'] ?? '');
$trackingStatusLabels = [
    'chua_phan_cong' => 'Tiếp nhận - đang chờ phân tuyến',
    'da_phan_cong' => 'Đang giao',
    'giao_thanh_cong' => 'Đã giao thành công',
    'giao_that_bai' => 'Giao không thành công',
];
$trackingProgressIndex = array_search(
    $trackingStatus,
    ['chua_phan_cong', 'da_phan_cong', 'giao_thanh_cong', 'giao_that_bai'],
    true
);
if ($trackingStatus === 'giao_that_bai') {
    $trackingProgressIndex = 2;
}
$trackingProgressLabels = ['Tiếp nhận', 'Đã phân tuyến / đang giao', 'Hoàn tất'];
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
                        <div class="alert alert-success mt-3 mb-0" role="status">
                            <strong><?php echo htmlspecialchars($trackingStatusLabels[$trackingStatus] ?? 'Đã tìm thấy vận đơn', ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="small mt-1">Mã vận đơn: <?php echo htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($trackingProgressIndex !== false): ?>
                                <ol class="tracking-progress mt-3 mb-0" aria-label="Tiến trình vận đơn">
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
                            <?php if (isset($trackingResult['dia_chi_giao_hang'])): ?>
                                <div class="small mt-1">Địa chỉ: <?php echo htmlspecialchars((string) $trackingResult['dia_chi_giao_hang'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php if (isset($trackingResult['tien_thu_ho'])): ?>
                                <div class="small mt-1">COD: <?php echo htmlspecialchars(number_format((float) $trackingResult['tien_thu_ho'], 0, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> đ</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</main>
