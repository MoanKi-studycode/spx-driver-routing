CREATE DATABASE IF NOT EXISTS dinh_tuyen_spx
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dinh_tuyen_spx;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS don_hang;
DROP TABLE IF EXISTS cum_tuyen_giao;
DROP TABLE IF EXISTS nguoi_dung;
DROP TABLE IF EXISTS phuong;
DROP TABLE IF EXISTS buu_cuc;
SET FOREIGN_KEY_CHECKS = 1;

-- Bảng 1: buu_cuc (Cơ sở / Bưu cục phát hàng SPX & Cấp hành chính Tỉnh - Quận/Huyện)
CREATE TABLE buu_cuc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ma_buu_cuc VARCHAR(40) NOT NULL UNIQUE COMMENT 'VD: SPX_KH_NT01',
    ten_buu_cuc VARCHAR(120) NOT NULL COMMENT 'VD: Bưu cục SPX Nha Trang',
    ma_tinh VARCHAR(20) NOT NULL COMMENT 'Mã Tỉnh/Thành chuẩn API hành chính, VD: 56',
    ten_tinh VARCHAR(100) NOT NULL COMMENT 'VD: Tỉnh Khánh Hòa',
    ma_quan_huyen VARCHAR(20) NOT NULL COMMENT 'Mã Quận/Huyện/TP thuộc tỉnh, VD: 568',
    ten_quan_huyen VARCHAR(100) NOT NULL COMMENT 'VD: Thành phố Nha Trang',
    dia_chi VARCHAR(255) NOT NULL,
    vi_do DECIMAL(10, 7) NOT NULL COMMENT 'Vĩ độ điểm xuất phát P0 của Bưu cục',
    kinh_do DECIMAL(10, 7) NOT NULL COMMENT 'Kinh độ điểm xuất phát P0 của Bưu cục',
    ban_kinh_phuc_vu_km DECIMAL(6, 2) NOT NULL DEFAULT 25.00 COMMENT 'Bán kính kiểm tra tọa độ hợp lệ (km)',
    suc_chua_moi_cum INT NOT NULL DEFAULT 20 COMMENT 'Số đơn tối đa mỗi cụm/tài xế',
    dang_hoat_dong TINYINT(1) NOT NULL DEFAULT 1,
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_bc_vi_do CHECK (vi_do BETWEEN -90 AND 90),
    CONSTRAINT chk_bc_kinh_do CHECK (kinh_do BETWEEN -180 AND 180)
) ENGINE=InnoDB;

-- Bảng 2: phuong (Danh mục Phường/Xã thuộc vùng phục vụ của từng Bưu cục)
CREATE TABLE phuong (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL COMMENT 'Phường này do Bưu cục nào phụ trách',
    ma_hanh_chinh VARCHAR(20) NOT NULL UNIQUE COMMENT 'Mã Phường/Xã chuẩn Quốc gia/Open API, VD: 22339',
    ma_phuong VARCHAR(40) NOT NULL UNIQUE COMMENT 'Mã nội bộ, VD: KH_NT_BAC_NHA_TRANG',
    ten_phuong VARCHAR(100) NOT NULL COMMENT 'VD: Phường Bắc Nha Trang',
    cap_hanh_chinh ENUM('Phường', 'Xã', 'Thị trấn') NOT NULL DEFAULT 'Phường',
    vi_do_tam DECIMAL(10, 7) NOT NULL COMMENT 'Tâm phường để tham chiếu ghép tài xế',
    kinh_do_tam DECIMAL(10, 7) NOT NULL,
    dang_hoat_dong TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_phuong_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT uq_buu_cuc_ten_phuong UNIQUE (buu_cuc_id, ten_phuong),
    CONSTRAINT chk_ph_vi_do CHECK (vi_do_tam BETWEEN -90 AND 90),
    CONSTRAINT chk_ph_kinh_do CHECK (kinh_do_tam BETWEEN -180 AND 180),
    INDEX idx_phuong_buu_cuc (buu_cuc_id, dang_hoat_dong)
) ENGINE=InnoDB;

-- Bảng 3: nguoi_dung (Điều phối viên và Tài xế thuộc Bưu cục)
CREATE TABLE nguoi_dung (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL COMMENT 'Nhân sự thuộc Bưu cục nào',
    ten_dang_nhap VARCHAR(50) NOT NULL UNIQUE,
    mat_khau_bam VARCHAR(255) NOT NULL COMMENT 'password_hash() Bcrypt',
    ho_ten VARCHAR(100) NOT NULL,
    so_dien_thoai VARCHAR(20) DEFAULT NULL,
    vai_tro ENUM('dieu_phoi', 'tai_xe') NOT NULL DEFAULT 'tai_xe',
    phuong_uu_tien_id INT DEFAULT NULL COMMENT 'Phường quen thuộc của tài xế để ưu tiên ghép cụm',
    mau_sac_cum VARCHAR(20) NOT NULL DEFAULT '#2563eb' COMMENT 'Màu hiển thị cụm của tài xế trên bản đồ',
    dang_hoat_dong TINYINT(1) NOT NULL DEFAULT 1,
    vi_do_hien_tai DECIMAL(10, 7) DEFAULT NULL,
    kinh_do_hien_tai DECIMAL(10, 7) DEFAULT NULL,
    thoi_gian_vi_tri DATETIME DEFAULT NULL,
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_nd_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT fk_nd_phuong FOREIGN KEY (phuong_uu_tien_id) REFERENCES phuong(id) ON DELETE RESTRICT,
    CONSTRAINT chk_nd_vi_do CHECK (vi_do_hien_tai IS NULL OR vi_do_hien_tai BETWEEN -90 AND 90),
    CONSTRAINT chk_nd_kinh_do CHECK (kinh_do_hien_tai IS NULL OR kinh_do_hien_tai BETWEEN -180 AND 180),
    INDEX idx_nd_buu_cuc_vai_tro (buu_cuc_id, vai_tro, dang_hoat_dong)
) ENGINE=InnoDB;

-- Bảng 4: cum_tuyen_giao (Lưu thông tin Cụm K-Means + Kết quả tối ưu lộ trình của tài xế)
CREATE TABLE cum_tuyen_giao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL,
    ma_cum_tuyen VARCHAR(40) NOT NULL UNIQUE COMMENT 'VD: CUM_20261017_TX1',
    ma_lo VARCHAR(40) DEFAULT NULL,
    tai_xe_id INT NOT NULL,
    vi_do_tam_cum DECIMAL(10, 7) NOT NULL COMMENT 'Tâm cụm (Centroid) từ thuật toán K-Means',
    kinh_do_tam_cum DECIMAL(10, 7) NOT NULL,
    vi_do_xuat_phat DECIMAL(10, 7) NOT NULL COMMENT 'Điểm xuất phát lúc lập tuyến đầu (Bưu cục P0), không đổi khi tái định tuyến',
    kinh_do_xuat_phat DECIMAL(10, 7) NOT NULL COMMENT 'Điểm xuất phát lúc lập tuyến đầu (Bưu cục P0), không đổi khi tái định tuyến',
    so_don_hang INT NOT NULL DEFAULT 0,
    km_baseline DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Chỉ ghi lần lập tuyến đầu',
    km_nn DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Quãng đường sau Nearest Neighbor',
    km_toi_uu DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Quãng đường sau 2-Opt',
    ty_le_tiet_kiem_phan_tram DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    thoi_gian_chay_ms DECIMAL(8, 3) NOT NULL DEFAULT 0.000,
    trang_thai ENUM('dang_giao', 'hoan_tat') NOT NULL DEFAULT 'dang_giao',
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_cap_nhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ctg_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ctg_tai_xe FOREIGN KEY (tai_xe_id) REFERENCES nguoi_dung(id) ON DELETE RESTRICT,
    INDEX idx_ctg_buu_cuc (buu_cuc_id, trang_thai),
    INDEX idx_ctg_tai_xe (tai_xe_id, trang_thai)
) ENGINE=InnoDB;

-- Bảng 5: don_hang (Lưu đơn hàng và thứ tự giao trong cụm)
CREATE TABLE don_hang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL,
    ma_van_don VARCHAR(40) NOT NULL UNIQUE,
    ma_don_shopee VARCHAR(50) DEFAULT NULL,
    ma_lo VARCHAR(40) DEFAULT NULL,
    thu_tu_file INT NOT NULL DEFAULT 0 COMMENT 'Thứ tự gốc trong JSON để tính Baseline',
    ten_khach_hang VARCHAR(100) NOT NULL,
    so_dien_thoai_khach VARCHAR(20) DEFAULT NULL,
    dia_chi_giao_hang VARCHAR(255) NOT NULL,
    tinh_thanh_goc VARCHAR(100) DEFAULT NULL,
    quan_huyen_goc VARCHAR(100) DEFAULT NULL,
    phuong_ten_goc VARCHAR(100) DEFAULT NULL,
    phuong_id INT DEFAULT NULL,
    vi_do DECIMAL(10, 7) DEFAULT NULL,
    kinh_do DECIMAL(10, 7) DEFAULT NULL,
    tien_thu_ho DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    cum_tuyen_id INT DEFAULT NULL COMMENT 'Thuộc cụm K-Means nào',
    tai_xe_id INT DEFAULT NULL COMMENT 'Tài xế phụ trách cụm',
    thu_tu_giao INT DEFAULT NULL COMMENT 'Thứ tự điểm dừng 1..n sau khi chạy NN + 2-Opt',
    trang_thai ENUM('chua_phan_cong', 'da_phan_cong', 'giao_thanh_cong', 'giao_that_bai')
        NOT NULL DEFAULT 'chua_phan_cong',
    ly_do_chua_phan_cong ENUM('loi_toa_do', 'ngoai_vung_phuc_vu', 'qua_tai_tai_xe') DEFAULT NULL,
    ly_do_that_bai VARCHAR(255) DEFAULT NULL,
    thoi_gian_phan_cong DATETIME DEFAULT NULL,
    thoi_gian_hoan_tat DATETIME DEFAULT NULL,
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_cap_nhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dh_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dh_phuong FOREIGN KEY (phuong_id) REFERENCES phuong(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dh_cum_tuyen FOREIGN KEY (cum_tuyen_id) REFERENCES cum_tuyen_giao(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dh_tai_xe FOREIGN KEY (tai_xe_id) REFERENCES nguoi_dung(id) ON DELETE RESTRICT,
    CONSTRAINT chk_dh_vi_do CHECK (vi_do IS NULL OR vi_do BETWEEN -90 AND 90),
    CONSTRAINT chk_dh_kinh_do CHECK (kinh_do IS NULL OR kinh_do BETWEEN -180 AND 180),
    CONSTRAINT chk_dh_phan_cong_hop_le CHECK (
        trang_thai = 'chua_phan_cong'
        OR (cum_tuyen_id IS NOT NULL AND tai_xe_id IS NOT NULL AND phuong_id IS NOT NULL
            AND vi_do IS NOT NULL AND kinh_do IS NOT NULL AND ly_do_chua_phan_cong IS NULL)
    ),
    INDEX idx_dh_buu_cuc_trang_thai (buu_cuc_id, trang_thai),
    INDEX idx_dh_cum_thu_tu (cum_tuyen_id, trang_thai, thu_tu_giao),
    INDEX idx_dh_tai_xe (tai_xe_id, trang_thai)
) ENGINE=InnoDB;
