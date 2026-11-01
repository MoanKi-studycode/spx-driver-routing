<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class NguoiDungRepository
 * Quản lý truy vấn dữ liệu bảng `nguoi_dung`.
 */
class NguoiDungRepository
{
    /**
     * @var PDO
     */
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Tìm người dùng theo tên đăng nhập
     *
     * @param string $username
     * @return array|null
     */
    public function findByUsername(string $username): ?array
    {
        $sql = 'SELECT
                    nd.id, nd.buu_cuc_id, nd.ten_dang_nhap, nd.mat_khau_bam,
                    nd.ho_ten, nd.so_dien_thoai, nd.vai_tro,
                    nd.phuong_uu_tien_id, nd.mau_sac_cum, nd.dang_hoat_dong,
                    nd.vi_do_hien_tai, nd.kinh_do_hien_tai, nd.thoi_gian_vi_tri,
                    bc.ma_buu_cuc, bc.ten_buu_cuc,
                    bc.ten_quan_huyen, bc.ten_tinh,
                    bc.vi_do AS buu_cuc_vi_do, bc.kinh_do AS buu_cuc_kinh_do,
                    bc.ban_kinh_phuc_vu_km, bc.suc_chua_moi_cum
                FROM nguoi_dung nd
                INNER JOIN buu_cuc bc ON nd.buu_cuc_id = bc.id
                WHERE nd.ten_dang_nhap = :ten_dang_nhap
                  AND nd.dang_hoat_dong = 1
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ten_dang_nhap' => trim($username)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Tìm người dùng theo ID
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT
                    nd.id, nd.buu_cuc_id, nd.ten_dang_nhap, nd.mat_khau_bam,
                    nd.ho_ten, nd.so_dien_thoai, nd.vai_tro,
                    nd.phuong_uu_tien_id, nd.mau_sac_cum, nd.dang_hoat_dong,
                    nd.vi_do_hien_tai, nd.kinh_do_hien_tai, nd.thoi_gian_vi_tri,
                    p.ten_phuong AS ten_phuong_uu_tien,
                    p.vi_do_tam AS phuong_vi_do_tam,
                    p.kinh_do_tam AS phuong_kinh_do_tam,
                    bc.ma_buu_cuc, bc.ten_buu_cuc,
                    bc.ten_quan_huyen, bc.ten_tinh,
                    bc.vi_do AS buu_cuc_vi_do, bc.kinh_do AS buu_cuc_kinh_do,
                    bc.ban_kinh_phuc_vu_km, bc.suc_chua_moi_cum
                FROM nguoi_dung nd
                INNER JOIN buu_cuc bc ON nd.buu_cuc_id = bc.id
                LEFT JOIN phuong p ON nd.phuong_uu_tien_id = p.id
                WHERE nd.id = :id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Lấy danh sách tài xế đang hoạt động kèm phường ưu tiên và sức chứa còn lại
     * Tuân thủ Data Contract tại Mục 10.1.5 cho thuật toán Balanced K-Means
     *
     * @param int|null $buuCucId
     * @return array
     */
    public function getActiveDrivers(?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (int) ($_SESSION['buu_cuc_id'] ?? 1);

        $sql = 'SELECT
                    nd.id, nd.buu_cuc_id, nd.ten_dang_nhap, nd.ho_ten,
                    nd.so_dien_thoai, nd.vai_tro,
                    nd.phuong_uu_tien_id, nd.mau_sac_cum, nd.dang_hoat_dong,
                    nd.vi_do_hien_tai, nd.kinh_do_hien_tai, nd.thoi_gian_vi_tri,
                    p.ten_phuong AS ten_phuong_uu_tien,
                    p.ma_hanh_chinh AS ma_hanh_chinh_phuong_uu_tien,
                    p.vi_do_tam, p.kinh_do_tam,
                    p.vi_do_tam AS phuong_vi_do_tam,
                    p.kinh_do_tam AS phuong_kinh_do_tam,
                    bc.suc_chua_moi_cum,
                    COALESCE(dh_cnt.so_don_da_phan_cong_hien_tai, 0) AS so_don_da_phan_cong_hien_tai
                FROM nguoi_dung nd
                INNER JOIN buu_cuc bc ON nd.buu_cuc_id = bc.id
                LEFT JOIN phuong p ON nd.phuong_uu_tien_id = p.id
                LEFT JOIN (
                    SELECT tai_xe_id, COUNT(*) AS so_don_da_phan_cong_hien_tai
                    FROM don_hang
                    WHERE trang_thai = \'da_phan_cong\'
                    GROUP BY tai_xe_id
                ) dh_cnt ON dh_cnt.tai_xe_id = nd.id
                WHERE nd.buu_cuc_id = :buu_cuc_id
                  AND nd.vai_tro = \'tai_xe\'
                  AND nd.dang_hoat_dong = 1
                ORDER BY nd.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $targetBuuCucId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $normalized = $this->normalizeRow($row);
            $sucChuaMoiCum = (int) ($row['suc_chua_moi_cum'] ?? (defined('SUC_CHUA_MAC_DINH') ? SUC_CHUA_MAC_DINH : 20));
            $soDonHienTai = (int) ($row['so_don_da_phan_cong_hien_tai'] ?? 0);
            $normalized['so_don_da_phan_cong_hien_tai'] = $soDonHienTai;
            $normalized['suc_chua_con_lai'] = max(0, $sucChuaMoiCum - $soDonHienTai);
            $normalized['vi_do_tam'] = $row['vi_do_tam'] !== null ? (float) $row['vi_do_tam'] : null;
            $normalized['kinh_do_tam'] = $row['kinh_do_tam'] !== null ? (float) $row['kinh_do_tam'] : null;
            return $normalized;
        }, $rows);
    }

    /**
     * Cập nhật vị trí hiện tại của tài xế
     *
     * @param int $userId
     * @param float $lat
     * @param float $lng
     * @return bool
     */
    public function updateLocation(int $userId, float $lat, float $lng): bool
    {
        $sql = 'UPDATE nguoi_dung
                SET vi_do_hien_tai = :vi_do,
                    kinh_do_hien_tai = :kinh_do,
                    thoi_gian_vi_tri = NOW()
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'vi_do' => $lat,
            'kinh_do' => $lng,
            'id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Chuẩn hóa kiểu dữ liệu bản ghi người dùng
     *
     * @param array $row
     * @return array
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['buu_cuc_id'] = (int) $row['buu_cuc_id'];
        $row['phuong_uu_tien_id'] = $row['phuong_uu_tien_id'] !== null ? (int) $row['phuong_uu_tien_id'] : null;
        $row['dang_hoat_dong'] = (int) $row['dang_hoat_dong'];
        $row['vi_do_hien_tai'] = $row['vi_do_hien_tai'] !== null ? (float) $row['vi_do_hien_tai'] : null;
        $row['kinh_do_hien_tai'] = $row['kinh_do_hien_tai'] !== null ? (float) $row['kinh_do_hien_tai'] : null;

        if (array_key_exists('phuong_vi_do_tam', $row)) {
            $row['phuong_vi_do_tam'] = $row['phuong_vi_do_tam'] !== null ? (float) $row['phuong_vi_do_tam'] : null;
        }
        if (array_key_exists('phuong_kinh_do_tam', $row)) {
            $row['phuong_kinh_do_tam'] = $row['phuong_kinh_do_tam'] !== null ? (float) $row['phuong_kinh_do_tam'] : null;
        }

        return $row;
    }
}
?>