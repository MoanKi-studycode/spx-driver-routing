<?php
declare(strict_types=1);
?>
<main class="container auth-container">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5">
            <section class="card auth-card shadow-sm">
                <div class="card-body p-4 p-md-5">
                    <p class="eyebrow mb-2">SPX ROUTE</p>
                    <h1 class="h3 mb-2">Đăng nhập hệ thống</h1>
                    <p class="text-secondary mb-4">Dành cho điều phối viên và tài xế.</p>

                    <?php if (is_string($loginError ?? null) && $loginError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" action="<?= htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . '/login', ENT_QUOTES, 'UTF-8') ?>" autocomplete="on">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
        </div>
    </div>
</main>
