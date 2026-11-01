<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$role = $_SESSION['vai_tro'] ?? null;
?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= $escape($baseUrl) ?>/">SPX Route</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navigation" aria-controls="main-navigation" aria-expanded="false" aria-label="Mở menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="main-navigation">
            <div class="navbar-nav me-auto">
                <?php if ($role === 'tai_xe'): ?>
                    <a class="nav-link" href="<?= $escape($baseUrl) ?>/tai-xe">Lộ trình của tôi</a>
                <?php elseif ($role === 'dieu_phoi'): ?>
                    <a class="nav-link" href="<?= $escape($baseUrl) ?>/dieu-phoi">Điều phối</a>
                    <a class="nav-link" href="<?= $escape($baseUrl) ?>/thong-ke">Thống kê</a>
                <?php endif; ?>
            </div>
            <div class="navbar-nav align-items-lg-center gap-lg-2">
                <?php if (is_string($_SESSION['ho_ten'] ?? null) && $_SESSION['ho_ten'] !== ''): ?>
                    <span class="navbar-text"><?= $escape($_SESSION['ho_ten']) ?></span>
                <?php endif; ?>
                <?php if ($role === 'tai_xe' || $role === 'dieu_phoi'): ?>
                    <a class="btn btn-sm btn-outline-light" href="<?= $escape($baseUrl) ?>/logout">Đăng xuất</a>
                <?php else: ?>
                    <a class="nav-link" href="<?= $escape($baseUrl) ?>/login">Đăng nhập</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
