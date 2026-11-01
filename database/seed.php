<?php

declare(strict_types=1);

/**
 * File khởi tạo dữ liệu mẫu chuẩn 3NF (database/seed.php)
 * Chạy bằng CLI: php database/seed.php
 *
 * Bao gồm:
 * - Tự động tạo database & bảng từ database/schema.sql nếu chưa tồn tại
 * - 2 Bưu cục SPX (Bưu cục SPX Nha Trang - Cơ sở chính & Bưu cục SPX Cam Ranh - Cơ sở mở rộng)
 * - Danh mục Phường/Xã chuẩn mã hành chính gắn với từng Bưu cục:
 *   + Phường Bắc Nha Trang (Mã HC: 22339)
 *   + Phường Tây Nha Trang (Mã HC: 22366)
 *   + Phường Nha Trang     (Mã HC: 22327)
 *   + Phường Cam Nghĩa     (Mã HC: 22408)
 *   + Phường Cam Thuận     (Mã HC: 22423)
 * - 1 Điều phối viên (admin / 123456)
 * - 3 Tài xế phụ trách 3 khu vực tại Nha Trang (taixe_bac, taixe_tay, taixe_nhatrang / 123456)
 *   với vị trí khởi tạo mặc định tại Bưu cục SPX Nha Trang (P0) theo đúng Mục 6.
 */

$dbConfigFile = __DIR__ . '/../config/database.php';
$dbExampleFile = __DIR__ . '/../config/database.example.php';
$schemaFile = __DIR__ . '/schema.sql';

$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'dinh_tuyen_spx',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
];

if (file_exists($dbConfigFile)) {
    $loaded = require $dbConfigFile;
    if (is_array($loaded)) {
        $config = array_merge($config, $loaded);
    }
} elseif (file_exists($dbExampleFile)) {
    $loaded = require $dbExampleFile;
    if (is_array($loaded)) {
        $config = array_merge($config, $loaded);
    }
}

$user = (string) ($config['username'] ?? $config['user'] ?? 'root');
$pass = (string) ($config['password'] ?? '');
$dbName = (string) $config['dbname'];

$serverDsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    $config['host'],
    (int) $config['port'],
    $config['charset']
);

try {
    $pdo = new PDO($serverDsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    echo "=== BẮT ĐẦU KHỞI TẠO CSDL & DỮ LIỆU MẪU (SEED) ===\n";

    // Đảm bảo database và cấu trúc bảng đã tồn tại
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '``', $dbName)
    ));
    $pdo->exec(sprintf('USE `%s`', str_replace('`', '``', $dbName)));

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'buu_cuc'")->fetchColumn();
    if (!$tableCheck && file_exists($schemaFile)) {
        $schemaSql = (string) file_get_contents($schemaFile);
        $pdo->exec($schemaSql);
        echo "[OK] Đã tự động khởi tạo cấu trúc 5 bảng chuẩn 3NF từ database/schema.sql.\n";
    }

    // Làm sạch dữ liệu cũ theo đúng thứ tự khóa ngoại
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE don_hang');
    $pdo->exec('TRUNCATE TABLE cum_tuyen_giao');
    $pdo->exec('TRUNCATE TABLE nguoi_dung');
    $pdo->exec('TRUNCATE TABLE phuong');
    $pdo->exec('TRUNCATE TABLE buu_cuc');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $pdo->beginTransaction();

    // 1. Thêm dữ liệu bảng buu_cuc (2 cơ sở tại Tỉnh Khánh Hòa)
    $stmtBuuCuc = $pdo->prepare(
        'INSERT INTO buu_cuc (
            id, ma_buu_cuc, ten_buu_cuc, ma_tinh, ten_tinh,
            ma_quan_huyen, ten_quan_huyen, dia_chi,
            vi_do, kinh_do, ban_kinh_phuc_vu_km, suc_chua_moi_cum, dang_hoat_dong
        ) VALUES (
            :id, :ma_buu_cuc, :ten_buu_cuc, :ma_tinh, :ten_tinh,
            :ma_quan_huyen, :ten_quan_huyen, :dia_chi,
            :vi_do, :kinh_do, :ban_kinh_phuc_vu_km, :suc_chua_moi_cum, :dang_hoat_dong
        )'
    );

    $danhSachBuuCuc = [
        [
            'id' => 1,
            'ma_buu_cuc' => 'SPX_KH_NT01',
            'ten_buu_cuc' => 'Bưu cục SPX Nha Trang',
            'ma_tinh' => '56',
            'ten_tinh' => 'Tỉnh Khánh Hòa',
            'ma_quan_huyen' => '568',
            'ten_quan_huyen' => 'Thành phố Nha Trang',
            'dia_chi' => '02 Lê Thánh Tôn, Phường Nha Trang, Thành phố Nha Trang, Tỉnh Khánh Hòa',
            'vi_do' => 12.2488000,
            'kinh_do' => 109.1905000,
            'ban_kinh_phuc_vu_km' => 25.00,
            'suc_chua_moi_cum' => 20,
            'dang_hoat_dong' => 1,
        ],
        [
            'id' => 2,
            'ma_buu_cuc' => 'SPX_KH_CR01',
            'ten_buu_cuc' => 'Bưu cục SPX Cam Ranh',
            'ma_tinh' => '56',
            'ten_tinh' => 'Tỉnh Khánh Hòa',
            'ma_quan_huyen' => '569',
            'ten_quan_huyen' => 'Thành phố Cam Ranh',
            'dia_chi' => '2150 Hùng Vương, Phường Cam Thuận, Thành phố Cam Ranh, Tỉnh Khánh Hòa',
            'vi_do' => 11.9214000,
            'kinh_do' => 109.1591000,
            'ban_kinh_phuc_vu_km' => 25.00,
            'suc_chua_moi_cum' => 20,
            'dang_hoat_dong' => 1,
        ],
    ];

    foreach ($danhSachBuuCuc as $bc) {
        $stmtBuuCuc->execute($bc);
    }
    echo "[OK] Đã tạo 2 Bưu cục (SPX_KH_NT01 - Nha Trang & SPX_KH_CR01 - Cam Ranh).\n";

    // 2. Thêm dữ liệu bảng phuong (3 phường khu vực Nha Trang + 2 phường khu vực Cam Ranh)
    $stmtPhuong = $pdo->prepare(
        'INSERT INTO phuong (
            id, buu_cuc_id, ma_hanh_chinh, ma_phuong, ten_phuong,
            cap_hanh_chinh, vi_do_tam, kinh_do_tam, dang_hoat_dong
        ) VALUES (
            :id, :buu_cuc_id, :ma_hanh_chinh, :ma_phuong, :ten_phuong,
            :cap_hanh_chinh, :vi_do_tam, :kinh_do_tam, :dang_hoat_dong
        )'
    );

    $danhSachPhuong = [
        // 3 Phường thuộc Bưu cục SPX Nha Trang (buu_cuc_id = 1)
        [
            'id' => 1,
            'buu_cuc_id' => 1,
            'ma_hanh_chinh' => '22339',
            'ma_phuong' => 'KH_NT_BAC_NHA_TRANG',
            'ten_phuong' => 'Phường Bắc Nha Trang',
            'cap_hanh_chinh' => 'Phường',
            'vi_do_tam' => 12.2795000,
            'kinh_do_tam' => 109.1985000,
            'dang_hoat_dong' => 1,
        ],
        [
            'id' => 2,
            'buu_cuc_id' => 1,
            'ma_hanh_chinh' => '22366',
            'ma_phuong' => 'KH_NT_TAY_NHA_TRANG',
            'ten_phuong' => 'Phường Tây Nha Trang',
            'cap_hanh_chinh' => 'Phường',
            'vi_do_tam' => 12.2555000,
            'kinh_do_tam' => 109.1615000,
            'dang_hoat_dong' => 1,
        ],
        [
            'id' => 3,
            'buu_cuc_id' => 1,
            'ma_hanh_chinh' => '22327',
            'ma_phuong' => 'KH_NT_NHA_TRANG',
            'ten_phuong' => 'Phường Nha Trang',
            'cap_hanh_chinh' => 'Phường',
            'vi_do_tam' => 12.2335000,
            'kinh_do_tam' => 109.1910000,
            'dang_hoat_dong' => 1,
        ],
        // 2 Phường thuộc Bưu cục SPX Cam Ranh (buu_cuc_id = 2) để minh họa đa cơ sở
        [
            'id' => 4,
            'buu_cuc_id' => 2,
            'ma_hanh_chinh' => '22408',
            'ma_phuong' => 'KH_CR_CAM_NGHIA',
            'ten_phuong' => 'Phường Cam Nghĩa',
            'cap_hanh_chinh' => 'Phường',
            'vi_do_tam' => 11.9850000,
            'kinh_do_tam' => 109.1820000,
            'dang_hoat_dong' => 1,
        ],
        [
            'id' => 5,
            'buu_cuc_id' => 2,
            'ma_hanh_chinh' => '22423',
            'ma_phuong' => 'KH_CR_CAM_THUAN',
            'ten_phuong' => 'Phường Cam Thuận',
            'cap_hanh_chinh' => 'Phường',
            'vi_do_tam' => 11.9180000,
            'kinh_do_tam' => 109.1560000,
            'dang_hoat_dong' => 1,
        ],
    ];

    foreach ($danhSachPhuong as $ph) {
        $stmtPhuong->execute($ph);
    }
    echo "[OK] Đã tạo danh mục 5 Phường/Xã chuẩn hóa (Bắc Nha Trang, Tây Nha Trang, Nha Trang, Cam Nghĩa, Cam Thuận).\n";

    // 3. Thêm dữ liệu bảng nguoi_dung (1 Điều phối viên + 3 Tài xế tại Bưu cục SPX Nha Trang, vị trí khởi tạo tại P0)
    $matKhauMacDinh = password_hash('123456', PASSWORD_BCRYPT);
    $thoiGianHienTai = date('Y-m-d H:i:s');

    $stmtNguoiDung = $pdo->prepare(
        'INSERT INTO nguoi_dung (
            id, buu_cuc_id, ten_dang_nhap, mat_khau_bam, ho_ten, so_dien_thoai,
            vai_tro, phuong_uu_tien_id, mau_sac_cum, dang_hoat_dong,
            vi_do_hien_tai, kinh_do_hien_tai, thoi_gian_vi_tri
        ) VALUES (
            :id, :buu_cuc_id, :ten_dang_nhap, :mat_khau_bam, :ho_ten, :so_dien_thoai,
            :vai_tro, :phuong_uu_tien_id, :mau_sac_cum, :dang_hoat_dong,
            :vi_do_hien_tai, :kinh_do_hien_tai, :thoi_gian_vi_tri
        )'
    );

    $danhSachNguoiDung = [
        // Điều phối viên Bưu cục SPX Nha Trang
        [
            'id' => 1,
            'buu_cuc_id' => 1,
            'ten_dang_nhap' => 'admin',
            'mat_khau_bam' => $matKhauMacDinh,
            'ho_ten' => 'Trần Quản Trị (Điều Phối Nha Trang)',
            'so_dien_thoai' => '0905000111',
            'vai_tro' => 'dieu_phoi',
            'phuong_uu_tien_id' => null,
            'mau_sac_cum' => '#0f172a',
            'dang_hoat_dong' => 1,
            'vi_do_hien_tai' => 12.2488000,
            'kinh_do_hien_tai' => 109.1905000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
        // Tài xế 1: Khu vực Phường Bắc Nha Trang (Màu Đỏ #ef4444) - Khởi tạo tại Bưu cục P0
        [
            'id' => 2,
            'buu_cuc_id' => 1,
            'ten_dang_nhap' => 'taixe_bac',
            'mat_khau_bam' => $matKhauMacDinh,
            'ho_ten' => 'Nguyễn Minh Khoa (Tài xế Bắc Nha Trang)',
            'so_dien_thoai' => '0905111222',
            'vai_tro' => 'tai_xe',
            'phuong_uu_tien_id' => 1,
            'mau_sac_cum' => '#ef4444',
            'dang_hoat_dong' => 1,
            'vi_do_hien_tai' => 12.2488000,
            'kinh_do_hien_tai' => 109.1905000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
        // Tài xế 2: Khu vực Phường Tây Nha Trang (Màu Xanh lá #16a34a) - Khởi tạo tại Bưu cục P0
        [
            'id' => 3,
            'buu_cuc_id' => 1,
            'ten_dang_nhap' => 'taixe_tay',
            'mat_khau_bam' => $matKhauMacDinh,
            'ho_ten' => 'Trần Quốc Bảo (Tài xế Tây Nha Trang)',
            'so_dien_thoai' => '0905333444',
            'vai_tro' => 'tai_xe',
            'phuong_uu_tien_id' => 2,
            'mau_sac_cum' => '#16a34a',
            'dang_hoat_dong' => 1,
            'vi_do_hien_tai' => 12.2488000,
            'kinh_do_hien_tai' => 109.1905000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
        // Tài xế 3: Khu vực Phường Nha Trang (Màu Xanh dương #2563eb) - Khởi tạo tại Bưu cục P0
        [
            'id' => 4,
            'buu_cuc_id' => 1,
            'ten_dang_nhap' => 'taixe_nhatrang',
            'mat_khau_bam' => $matKhauMacDinh,
            'ho_ten' => 'Lê Hoàng Nam (Tài xế Phường Nha Trang)',
            'so_dien_thoai' => '0905555666',
            'vai_tro' => 'tai_xe',
            'phuong_uu_tien_id' => 3,
            'mau_sac_cum' => '#2563eb',
            'dang_hoat_dong' => 1,
            'vi_do_hien_tai' => 12.2488000,
            'kinh_do_hien_tai' => 109.1905000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
    ];

    foreach ($danhSachNguoiDung as $nd) {
        $stmtNguoiDung->execute($nd);
    }

    $pdo->commit();

    echo "[OK] Đã tạo 1 Điều phối viên (admin) và 3 Tài xế (taixe_bac, taixe_tay, taixe_nhatrang).\n";
    echo "=== HOÀN TẤT SEED DỮ LIỆU (Mật khẩu mặc định: 123456) ===\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[LỖI SEED]: " . $e->getMessage() . "\n");
    exit(1);
}
?>