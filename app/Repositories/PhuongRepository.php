<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class PhuongRepository
 * Quản lý truy vấn dữ liệu bảng `phuong` và liên kết với `buu_cuc`.
 */
class PhuongRepository
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
     * Lấy thông tin phường theo ID kèm thông tin Bưu cục
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
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
                WHERE p.id = :id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Lấy danh sách phường theo Bưu cục phụ trách
     *
     * @param int $buuCucId
     * @return array
     */
    public function getByBuuCuc(int $buuCucId): array
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

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Tìm phường theo buu_cuc_id và tên đã chuẩn hóa tiếng Việt đa byte (Mục 2.3 Bước 3)
     *
     * @param int $buuCucId
     * @param string $normalizedName
     * @return array|null
     */
    public function findByBuuCucAndNormalizedName(int $buuCucId, string $normalizedName): ?array
    {
        $target = self::normalizeWardName($normalizedName);
        if ($target === '') {
            return null;
        }

        $wards = $this->getByBuuCuc($buuCucId);
        foreach ($wards as $ward) {
            $wardNorm = self::normalizeWardName((string) $ward['ten_phuong']);
            if ($wardNorm === $target) {
                return $ward;
            }
        }

        return null;
    }

    /**
     * Tìm phường theo mã hành chính quốc gia
     *
     * @param string $maHanhChinh
     * @param int|null $buuCucId Nếu truyền vào sẽ lọc đúng bưu cục đang xét
     * @return array|null
     */
    public function findByMaHanhChinh(string $maHanhChinh, ?int $buuCucId = null): ?array
    {
        $code = trim($maHanhChinh);
        if ($code === '') {
            return null;
        }

        $sql = 'SELECT
                    p.id, p.buu_cuc_id, p.ma_hanh_chinh, p.ma_phuong,
                    p.ten_phuong, p.cap_hanh_chinh,
                    p.vi_do_tam, p.kinh_do_tam, p.dang_hoat_dong,
                    bc.ma_buu_cuc, bc.ten_buu_cuc,
                    bc.ma_tinh, bc.ten_tinh,
                    bc.ma_quan_huyen, bc.ten_quan_huyen
                FROM phuong p
                INNER JOIN buu_cuc bc ON p.buu_cuc_id = bc.id
                WHERE p.ma_hanh_chinh = :ma_hanh_chinh
                  AND p.dang_hoat_dong = 1';

        $params = ['ma_hanh_chinh' => $code];
        if ($buuCucId !== null) {
            $sql .= ' AND p.buu_cuc_id = :buu_cuc_id';
            $params['buu_cuc_id'] = $buuCucId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Chuẩn hóa chuỗi tên Phường/Xã bằng hàm đa byte theo công thức Mục 2.3 Bước 3
     *
     * @param string $name
     * @return string
     */
    public static function normalizeWardName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));
        return mb_strtolower((string) $collapsed, 'UTF-8');
    }

    /**
     * Chuẩn hóa kiểu dữ liệu số của bản ghi Phường
     *
     * @param array $row
     * @return array
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['buu_cuc_id'] = (int) $row['buu_cuc_id'];
        $row['vi_do_tam'] = (float) $row['vi_do_tam'];
        $row['kinh_do_tam'] = (float) $row['kinh_do_tam'];
        $row['dang_hoat_dong'] = (int) $row['dang_hoat_dong'];

        return $row;
    }
}
?>