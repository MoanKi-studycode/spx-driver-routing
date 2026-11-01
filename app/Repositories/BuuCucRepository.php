<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class BuuCucRepository
 * Quản lý truy vấn dữ liệu bảng `buu_cuc` (Cơ sở phát hàng và cấp hành chính).
 */
class BuuCucRepository
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
     * Lấy thông tin Bưu cục theo ID
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $sql = 'SELECT
                    id, ma_buu_cuc, ten_buu_cuc,
                    ma_tinh, ten_tinh, ma_quan_huyen, ten_quan_huyen,
                    dia_chi, vi_do, kinh_do,
                    ban_kinh_phuc_vu_km, suc_chua_moi_cum,
                    dang_hoat_dong, ngay_tao
                FROM buu_cuc
                WHERE id = :id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Lấy thông tin Bưu cục theo mã bưu cục (VD: SPX_KH_NT01)
     *
     * @param string $maBuuCuc
     * @return array|null
     */
    public function getByMa(string $maBuuCuc): ?array
    {
        $sql = 'SELECT
                    id, ma_buu_cuc, ten_buu_cuc,
                    ma_tinh, ten_tinh, ma_quan_huyen, ten_quan_huyen,
                    dia_chi, vi_do, kinh_do,
                    ban_kinh_phuc_vu_km, suc_chua_moi_cum,
                    dang_hoat_dong, ngay_tao
                FROM buu_cuc
                WHERE ma_buu_cuc = :ma_buu_cuc
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ma_buu_cuc' => trim($maBuuCuc)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Lấy danh sách tất cả các bưu cục đang hoạt động
     *
     * @return array
     */
    public function getAllActive(): array
    {
        $sql = 'SELECT
                    id, ma_buu_cuc, ten_buu_cuc,
                    ma_tinh, ten_tinh, ma_quan_huyen, ten_quan_huyen,
                    dia_chi, vi_do, kinh_do,
                    ban_kinh_phuc_vu_km, suc_chua_moi_cum,
                    dang_hoat_dong, ngay_tao
                FROM buu_cuc
                WHERE dang_hoat_dong = 1
                ORDER BY id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Lấy danh sách các phường/xã thuộc vùng phục vụ của Bưu cục kèm thông tin hành chính
     *
     * @param int $buuCucId
     * @return array
     */
    public function getServingWards(int $buuCucId): array
    {
        $sql = 'SELECT
                    p.id, p.buu_cuc_id, p.ma_hanh_chinh, p.ma_phuong,
                    p.ten_phuong, p.cap_hanh_chinh,
                    p.vi_do_tam, p.kinh_do_tam, p.dang_hoat_dong,
                    bc.ma_buu_cuc, bc.ten_buu_cuc,
                    bc.ma_tinh, bc.ten_tinh,
                    bc.ma_quan_huyen, bc.ten_quan_huyen
                FROM phuong p
                INNER JOIN buu_cuc bc ON p.buu_cuc_id = bc.id
                WHERE p.buu_cuc_id = :buu_cuc_id
                  AND p.dang_hoat_dong = 1
                ORDER BY p.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $buuCucId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['buu_cuc_id'] = (int) $row['buu_cuc_id'];
            $row['vi_do_tam'] = (float) $row['vi_do_tam'];
            $row['kinh_do_tam'] = (float) $row['kinh_do_tam'];
            $row['dang_hoat_dong'] = (int) $row['dang_hoat_dong'];
            return $row;
        }, $rows);
    }

    /**
     * Chuẩn hóa kiểu dữ liệu số của bản ghi Bưu cục
     *
     * @param array $row
     * @return array
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['vi_do'] = (float) $row['vi_do'];
        $row['kinh_do'] = (float) $row['kinh_do'];
        $row['ban_kinh_phuc_vu_km'] = (float) $row['ban_kinh_phuc_vu_km'];
        $row['suc_chua_moi_cum'] = (int) $row['suc_chua_moi_cum'];
        $row['dang_hoat_dong'] = (int) $row['dang_hoat_dong'];

        return $row;
    }
}
?>