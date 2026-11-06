<?php

declare(strict_types=1);

/**
 * File khởi tạo dữ liệu mẫu chuẩn 3NF (database/seed.php)
 * Chạy bằng CLI: php database/seed.php
 *
 * Bao gồm:
 * - Tự động tạo database & bảng từ database/schema.sql (đảm bảo có cột ly_do_that_bai)
 * - 2 Bưu cục SPX (Bưu cục SPX Nha Trang - Cơ sở chính & Bưu cục SPX Cam Ranh - Cơ sở mở rộng)
 * - Danh mục 5 Phường/Xã chuẩn mã hành chính gắn với từng Bưu cục
 * - 1 Điều phối viên (admin / 123456) + 3 Tài xế tại Nha Trang (taixe_bac, taixe_tay, taixe_nhatrang / 123456)
 * - 3 Cụm tuyến giao hàng mẫu (cum_tuyen_giao) kèm 40 đơn hàng mẫu có đủ 4 trạng thái:
 *   + chua_phan_cong (đơn chờ phân công + 2 đơn lỗi tọa độ/ngoài vùng)
 *   + da_phan_cong   (đơn đang chờ Shipper giao trên tuyến)
 *   + giao_thanh_cong (đơn đã giao thành công có thu hộ COD từ 0 đến 1.500.000 VNĐ)
 *   + giao_that_bai   (đơn giao thất bại kèm lý do cụ thể)
 */

$dbConfigFile = __DIR__ . '/../config/database.php';
$dbExampleFile = __DIR__ . '/../config/database.example.php';
$schemaFile = __DIR__ . '/schema.sql';
$sampleOrdersFile = __DIR__ . '/../data/don_hang_mau.json';

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
    } else {
        // Đảm bảo cột ly_do_that_bai luôn tồn tại nếu CSDL được tạo từ phiên bản cũ
        $colCheck = $pdo->query("SHOW COLUMNS FROM don_hang LIKE 'ly_do_that_bai'")->fetchColumn();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE don_hang ADD COLUMN ly_do_that_bai VARCHAR(255) NULL DEFAULT NULL AFTER trang_thai");
            echo "[OK] Đã bổ sung cột ly_do_that_bai vào bảng don_hang.\n";
        }
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
    echo "[OK] Đã tạo danh mục 5 Phường/Xã chuẩn hóa.\n";

    // 3. Thêm dữ liệu bảng nguoi_dung (1 Điều phối viên + 3 Tài xế tại Bưu cục SPX Nha Trang)
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
            'vi_do_hien_tai' => 12.2748000,
            'kinh_do_hien_tai' => 109.1995000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
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
            'vi_do_hien_tai' => 12.2552000,
            'kinh_do_hien_tai' => 109.1612000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
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
            'vi_do_hien_tai' => 12.2368000,
            'kinh_do_hien_tai' => 109.1918000,
            'thoi_gian_vi_tri' => $thoiGianHienTai,
        ],
    ];

    foreach ($danhSachNguoiDung as $nd) {
        $stmtNguoiDung->execute($nd);
    }
    echo "[OK] Đã tạo 1 Điều phối viên (admin) và 3 Tài xế (taixe_bac, taixe_tay, taixe_nhatrang).\n";

    // 4. Khởi tạo 3 Cụm tuyến giao mẫu (cum_tuyen_giao) và Đơn hàng mồi (don_hang) đủ 4 trạng thái
    $stmtCum = $pdo->prepare(
        'INSERT INTO cum_tuyen_giao (
            id, buu_cuc_id, ma_cum_tuyen, ma_lo, tai_xe_id,
            vi_do_tam_cum, kinh_do_tam_cum, vi_do_xuat_phat, kinh_do_xuat_phat,
            so_don_hang, km_baseline, km_nn, km_toi_uu,
            ty_le_tiet_kiem_phan_tram, thoi_gian_chay_ms, trang_thai
        ) VALUES (
            :id, :buu_cuc_id, :ma_cum_tuyen, :ma_lo, :tai_xe_id,
            :vi_do_tam_cum, :kinh_do_tam_cum, :vi_do_xuat_phat, :kinh_do_xuat_phat,
            :so_don_hang, :km_baseline, :km_nn, :km_toi_uu,
            :ty_le_tiet_kiem_phan_tram, :thoi_gian_chay_ms, :trang_thai
        )'
    );

    $danhSachCumMau = [
        [
            'id' => 1,
            'buu_cuc_id' => 1,
            'ma_cum_tuyen' => 'CUM_20261106_BC1_TX2_BAC',
            'ma_lo' => 'LO_20261024_SANG',
            'tai_xe_id' => 2,
            'vi_do_tam_cum' => 12.2791000,
            'kinh_do_tam_cum' => 109.1985000,
            'vi_do_xuat_phat' => 12.2488000,
            'kinh_do_xuat_phat' => 109.1905000,
            'so_don_hang' => 12,
            'km_baseline' => 24.85,
            'km_nn' => 12.90,
            'km_toi_uu' => 10.42,
            'ty_le_tiet_kiem_phan_tram' => 58.07,
            'thoi_gian_chay_ms' => 0.640,
            'trang_thai' => 'dang_giao',
        ],
        [
            'id' => 2,
            'buu_cuc_id' => 1,
            'ma_cum_tuyen' => 'CUM_20261106_BC1_TX3_TAY',
            'ma_lo' => 'LO_20261024_SANG',
            'tai_xe_id' => 3,
            'vi_do_tam_cum' => 12.2555000,
            'kinh_do_tam_cum' => 109.1618000,
            'vi_do_xuat_phat' => 12.2488000,
            'kinh_do_xuat_phat' => 109.1905000,
            'so_don_hang' => 12,
            'km_baseline' => 26.40,
            'km_nn' => 12.15,
            'km_toi_uu' => 11.18,
            'ty_le_tiet_kiem_phan_tram' => 57.65,
            'thoi_gian_chay_ms' => 0.580,
            'trang_thai' => 'dang_giao',
        ],
        [
            'id' => 3,
            'buu_cuc_id' => 1,
            'ma_cum_tuyen' => 'CUM_20261106_BC1_TX4_TRUNGTAM',
            'ma_lo' => 'LO_20261024_SANG',
            'tai_xe_id' => 4,
            'vi_do_tam_cum' => 12.2339000,
            'kinh_do_tam_cum' => 109.1903000,
            'vi_do_xuat_phat' => 12.2488000,
            'kinh_do_xuat_phat' => 109.1905000,
            'so_don_hang' => 10,
            'km_baseline' => 21.30,
            'km_nn' => 9.85,
            'km_toi_uu' => 8.64,
            'ty_le_tiet_kiem_phan_tram' => 59.44,
            'thoi_gian_chay_ms' => 0.510,
            'trang_thai' => 'dang_giao',
        ],
    ];

    foreach ($danhSachCumMau as $cum) {
        $stmtCum->execute($cum);
    }

    if (file_exists($sampleOrdersFile)) {
        $jsonPayload = json_decode((string) file_get_contents($sampleOrdersFile), true);
        $rawOrders = is_array($jsonPayload['don_hang'] ?? null) ? $jsonPayload['don_hang'] : [];

        // Mảng số tiền COD đa dạng từ 0 đến 1.500.000 VNĐ cho dữ liệu mồi
        $codPool = [
            450000, 0, 1250000, 310000, 180000, 680000, 1500000, 275000, 0, 540000,
            160000, 920000, 380000, 145000, 260000,
            1500000, 0, 890000, 420000, 195000, 1150000, 315000, 0, 640000, 165000,
            350000, 720000, 140000, 260000, 190000,
            1350000, 0, 780000, 460000, 410000, 980000, 160000, 0, 580000, 330000,
            120000, 230000, 360000, 155000, 210000,
        ];

        $lyDoThatBaiMau = [
            'Khách không nghe máy (đã gọi 3 lần)',
            'Khách hẹn giao lại ca sau / ngày mai',
            'Sai địa chỉ hoặc số điện thoại không tồn tại',
            'Khách từ chối nhận hàng',
        ];

        $stmtDonHang = $pdo->prepare(
            'INSERT INTO don_hang (
                buu_cuc_id, ma_van_don, ma_don_shopee, ma_lo, thu_tu_file,
                ten_khach_hang, so_dien_thoai_khach, dia_chi_giao_hang,
                tinh_thanh_goc, quan_huyen_goc, phuong_ten_goc,
                phuong_id, vi_do, kinh_do, tien_thu_ho,
                cum_tuyen_id, tai_xe_id, thu_tu_giao,
                trang_thai, ly_do_that_bai, ly_do_chua_phan_cong,
                thoi_gian_phan_cong, thoi_gian_hoan_tat
            ) VALUES (
                :buu_cuc_id, :ma_van_don, :ma_don_shopee, :ma_lo, :thu_tu_file,
                :ten_khach_hang, :so_dien_thoai_khach, :dia_chi_giao_hang,
                :tinh_thanh_goc, :quan_huyen_goc, :phuong_ten_goc,
                :phuong_id, :vi_do, :kinh_do, :tien_thu_ho,
                :cum_tuyen_id, :tai_xe_id, :thu_tu_giao,
                :trang_thai, :ly_do_that_bai, :ly_do_chua_phan_cong,
                :thoi_gian_phan_cong, :thoi_gian_hoan_tat
            )'
        );

        $wardMap = [
            '22339' => 1,
            '22366' => 2,
            '22327' => 3,
        ];

        $thoiGianPhanCong = date('Y-m-d H:i:s', time() - 5400);
        $thoiGianHoanTat = date('Y-m-d H:i:s', time() - 1800);

        $seededCount = 0;
        foreach (array_values($rawOrders) as $idx => $item) {
            $orderNo = $idx + 1;

            // Giữ lại các đơn số 14, 15, 29, 30, 42, 43 chưa đưa vào DB để nút "Đồng bộ Shopee" trên web có đơn mới để nạp
            if (in_array($orderNo, [14, 15, 29, 30, 42, 43], true)) {
                continue;
            }

            $maHC = (string) ($item['ma_hanh_chinh_phuong'] ?? '');
            $phuongId = $wardMap[$maHC] ?? null;
            $viDo = isset($item['vi_do']) ? (float) $item['vi_do'] : null;
            $kinhDo = isset($item['kinh_do']) ? (float) $item['kinh_do'] : null;
            $cod = (float) ($codPool[$idx] ?? ($item['tien_thu_ho'] ?? 150000));

            $cumTuyenId = null;
            $taiXeId = null;
            $thuTuGiao = null;
            $trangThai = 'chua_phan_cong';
            $lyDoThatBai = null;
            $lyDoChuaPhanCong = null;
            $tgPhanCong = null;
            $tgHoanTat = null;

            if ($orderNo >= 1 && $orderNo <= 12) {
                // Cụm 1 - Tài xế Bắc Nha Trang (id = 2): 12 đơn
                $cumTuyenId = 1;
                $taiXeId = 2;
                $thuTuGiao = $orderNo;
                $tgPhanCong = $thoiGianPhanCong;
                if ($orderNo <= 5) {
                    $trangThai = 'giao_thanh_cong';
                    $tgHoanTat = $thoiGianHoanTat;
                } elseif ($orderNo === 6) {
                    $trangThai = 'giao_that_bai';
                    $lyDoThatBai = $lyDoThatBaiMau[0];
                    $tgHoanTat = $thoiGianHoanTat;
                } else {
                    $trangThai = 'da_phan_cong';
                }
            } elseif ($orderNo >= 16 && $orderNo <= 27) {
                // Cụm 2 - Tài xế Tây Nha Trang (id = 3): 12 đơn
                $cumTuyenId = 2;
                $taiXeId = 3;
                $thuTuGiao = $orderNo - 15;
                $tgPhanCong = $thoiGianPhanCong;
                if ($thuTuGiao <= 6) {
                    $trangThai = 'giao_thanh_cong';
                    $tgHoanTat = $thoiGianHoanTat;
                } elseif ($thuTuGiao === 7) {
                    $trangThai = 'giao_that_bai';
                    $lyDoThatBai = $lyDoThatBaiMau[1];
                    $tgHoanTat = $thoiGianHoanTat;
                } else {
                    $trangThai = 'da_phan_cong';
                }
            } elseif ($orderNo >= 31 && $orderNo <= 40) {
                // Cụm 3 - Tài xế Phường Nha Trang (id = 4): 10 đơn
                $cumTuyenId = 3;
                $taiXeId = 4;
                $thuTuGiao = $orderNo - 30;
                $tgPhanCong = $thoiGianPhanCong;
                if ($thuTuGiao <= 4) {
                    $trangThai = 'giao_thanh_cong';
                    $tgHoanTat = $thoiGianHoanTat;
                } elseif ($thuTuGiao === 5) {
                    $trangThai = 'giao_that_bai';
                    $lyDoThatBai = $lyDoThatBaiMau[2];
                    $tgHoanTat = $thoiGianHoanTat;
                } else {
                    $trangThai = 'da_phan_cong';
                }
            } elseif ($orderNo === 44) {
                // Đơn lỗi tọa độ (> 25km từ Bưu cục Nha Trang)
                $trangThai = 'chua_phan_cong';
                $lyDoChuaPhanCong = 'loi_toa_do';
            } elseif ($orderNo === 45) {
                // Đơn ngoài vùng phục vụ (Cam Ranh)
                $trangThai = 'chua_phan_cong';
                $phuongId = null;
                $lyDoChuaPhanCong = 'ngoai_vung_phuc_vu';
            } else {
                // Các đơn hợp lệ đang ở trạng thái chua_phan_cong (đơn 13, 28, 41)
                $trangThai = 'chua_phan_cong';
                $lyDoChuaPhanCong = null;
            }

            $stmtDonHang->execute([
                'buu_cuc_id' => 1,
                'ma_van_don' => (string) $item['ma_van_don'],
                'ma_don_shopee' => (string) ($item['ma_don_shopee'] ?? ''),
                'ma_lo' => 'LO_20261024_SANG',
                'thu_tu_file' => $orderNo,
                'ten_khach_hang' => (string) $item['ten_khach_hang'],
                'so_dien_thoai_khach' => (string) ($item['so_dien_thoai'] ?? ''),
                'dia_chi_giao_hang' => (string) ($item['dia_chi'] ?? ''),
                'tinh_thanh_goc' => (string) ($item['tinh_thanh'] ?? 'Tỉnh Khánh Hòa'),
                'quan_huyen_goc' => (string) ($item['quan_huyen'] ?? 'Thành phố Nha Trang'),
                'phuong_ten_goc' => (string) ($item['phuong'] ?? ''),
                'phuong_id' => $phuongId,
                'vi_do' => $viDo,
                'kinh_do' => $kinhDo,
                'tien_thu_ho' => $cod,
                'cum_tuyen_id' => $cumTuyenId,
                'tai_xe_id' => $taiXeId,
                'thu_tu_giao' => $thuTuGiao,
                'trang_thai' => $trangThai,
                'ly_do_that_bai' => $lyDoThatBai,
                'ly_do_chua_phan_cong' => $lyDoChuaPhanCong,
                'thoi_gian_phan_cong' => $tgPhanCong,
                'thoi_gian_hoan_tat' => $tgHoanTat,
            ]);
            $seededCount++;
        }

        echo "[OK] Đã tạo 3 Cụm tuyến giao hàng và {$seededCount} đơn hàng mồi đủ 4 trạng thái (chua_phan_cong, da_phan_cong, giao_thanh_cong, giao_that_bai) kèm COD 0đ -> 1.500.000đ.\n";
    }

    $pdo->commit();

    echo "=== HOÀN TẤT SEED DỮ LIỆU (Mật khẩu mặc định: 123456) ===\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[LỖI SEED]: " . $e->getMessage() . "\n");
    exit(1);
}
?>
