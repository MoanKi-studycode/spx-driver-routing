<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class CumTuyenGiaoRepository
 * Quản lý truy vấn dữ liệu bảng `cum_tuyen_giao`.
 */
class CumTuyenGiaoRepository
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
     * Tạo mới bản ghi cụm tuyến giao
     *
     * @param array $data
     * @return int ID của cụm tuyến vừa tạo
     */
    public function create(array $data): int
    {
        $kmBaseline = (float) ($data['km_baseline'] ?? 0.0);
        $kmNn = (float) ($data['km_nn'] ?? 0.0);
        $kmToiUu = (float) ($data['km_toi_uu'] ?? 0.0);
        $tyLeTietKiem = isset($data['ty_le_tiet_kiem_phan_tram'])
            ? (float) $data['ty_le_tiet_kiem_phan_tram']
            : ($kmBaseline > 0.0 ? round((($kmBaseline - $kmToiUu) / $kmBaseline) * 100, 2) : 0.0);

        $sql = 'INSERT INTO cum_tuyen_giao (
                    buu_cuc_id, ma_cum_tuyen, ma_lo, tai_xe_id,
                    vi_do_tam_cum, kinh_do_tam_cum,
                    vi_do_xuat_phat, kinh_do_xuat_phat,
                    so_don_hang, km_baseline, km_nn, km_toi_uu,
                    ty_le_tiet_kiem_phan_tram, thoi_gian_chay_ms, trang_thai
                ) VALUES (
                    :buu_cuc_id, :ma_cum_tuyen, :ma_lo, :tai_xe_id,
                    :vi_do_tam_cum, :kinh_do_tam_cum,
                    :vi_do_xuat_phat, :kinh_do_xuat_phat,
                    :so_don_hang, :km_baseline, :km_nn, :km_toi_uu,
                    :ty_le_tiet_kiem_phan_tram, :thoi_gian_chay_ms, :trang_thai
                )';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'buu_cuc_id' => (int) ($data['buu_cuc_id'] ?? ($_SESSION['buu_cuc_id'] ?? 1)),
            'ma_cum_tuyen' => (string) $data['ma_cum_tuyen'],
            'ma_lo' => $data['ma_lo'] ?? null,
            'tai_xe_id' => (int) $data['tai_xe_id'],
            'vi_do_tam_cum' => (float) $data['vi_do_tam_cum'],
            'kinh_do_tam_cum' => (float) $data['kinh_do_tam_cum'],
            'vi_do_xuat_phat' => (float) $data['vi_do_xuat_phat'],
            'kinh_do_xuat_phat' => (float) $data['kinh_do_xuat_phat'],
            'so_don_hang' => (int) ($data['so_don_hang'] ?? 0),
            'km_baseline' => round($kmBaseline, 2),
            'km_nn' => round($kmNn, 2),
            'km_toi_uu' => round($kmToiUu, 2),
            'ty_le_tiet_kiem_phan_tram' => round($tyLeTietKiem, 2),
            'thoi_gian_chay_ms' => round((float) ($data['thoi_gian_chay_ms'] ?? 0.0), 3),
            'trang_thai' => (string) ($data['trang_thai'] ?? 'dang_giao'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Lấy thông tin cụm tuyến theo ID
     *
     * @param int $clusterId
     * @return array|null
     */
    public function getById(int $clusterId): ?array
    {
        $sql = 'SELECT
                    ctg.*,
                    nd.ho_ten AS ten_tai_xe,
                    nd.so_dien_thoai AS sdt_tai_xe,
                    nd.mau_sac_cum,
                    nd.phuong_uu_tien_id,
                    p.ten_phuong AS ten_phuong_uu_tien
                FROM cum_tuyen_giao ctg
                INNER JOIN nguoi_dung nd ON ctg.tai_xe_id = nd.id
                LEFT JOIN phuong p ON nd.phuong_uu_tien_id = p.id
                WHERE ctg.id = :id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $clusterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Lấy danh sách các cụm tuyến theo lô hoặc theo bưu cục
     *
     * @param string|null $batchCode
     * @param int|null $buuCucId
     * @return array
     */
    public function getClusters(?string $batchCode = null, ?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (isset($_SESSION['buu_cuc_id']) ? (int) $_SESSION['buu_cuc_id'] : null);

        $sql = 'SELECT
                    ctg.*,
                    nd.ho_ten AS ten_tai_xe,
                    nd.so_dien_thoai AS sdt_tai_xe,
                    nd.mau_sac_cum,
                    nd.phuong_uu_tien_id,
                    p.ten_phuong AS ten_phuong_uu_tien,
                    COALESCE(stats.so_don_da_phan_cong, 0) AS so_don_dang_cho,
                    COALESCE(stats.so_don_thanh_cong, 0) AS so_don_da_giao,
                    COALESCE(stats.so_don_that_bai, 0) AS so_don_that_bai
                FROM cum_tuyen_giao ctg
                INNER JOIN nguoi_dung nd ON ctg.tai_xe_id = nd.id
                LEFT JOIN phuong p ON nd.phuong_uu_tien_id = p.id
                LEFT JOIN (
                    SELECT
                        cum_tuyen_id,
                        SUM(CASE WHEN trang_thai = \'da_phan_cong\' THEN 1 ELSE 0 END) AS so_don_da_phan_cong,
                        SUM(CASE WHEN trang_thai = \'giao_thanh_cong\' THEN 1 ELSE 0 END) AS so_don_thanh_cong,
                        SUM(CASE WHEN trang_thai = \'giao_that_bai\' THEN 1 ELSE 0 END) AS so_don_that_bai
                    FROM don_hang
                    WHERE cum_tuyen_id IS NOT NULL
                    GROUP BY cum_tuyen_id
                ) stats ON stats.cum_tuyen_id = ctg.id
                WHERE 1 = 1';

        $params = [];
        if ($targetBuuCucId !== null) {
            $sql .= ' AND ctg.buu_cuc_id = :buu_cuc_id';
            $params['buu_cuc_id'] = $targetBuuCucId;
        }
        if ($batchCode !== null && trim($batchCode) !== '') {
            $sql .= ' AND ctg.ma_lo = :ma_lo';
            $params['ma_lo'] = trim($batchCode);
        }

        $sql .= ' ORDER BY ctg.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Lấy cụm tuyến đang hoạt động của một tài xế
     *
     * @param int $driverId
     * @return array|null
     */
    public function getActiveClusterByDriver(int $driverId): ?array
    {
        $sql = 'SELECT
                    ctg.*,
                    nd.ho_ten AS ten_tai_xe,
                    nd.so_dien_thoai AS sdt_tai_xe,
                    nd.mau_sac_cum,
                    nd.phuong_uu_tien_id,
                    p.ten_phuong AS ten_phuong_uu_tien
                FROM cum_tuyen_giao ctg
                INNER JOIN nguoi_dung nd ON ctg.tai_xe_id = nd.id
                LEFT JOIN phuong p ON nd.phuong_uu_tien_id = p.id
                WHERE ctg.tai_xe_id = :tai_xe_id
                  AND ctg.trang_thai = \'dang_giao\'
                ORDER BY ctg.id DESC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tai_xe_id' => $driverId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Cập nhật số liệu quãng đường km và tâm cụm sau khi tính lại lộ trình từ P0 (Mục 5.2 - sua-don-loi)
     *
     * @param int $clusterId
     * @param array $metrics
     * @return bool
     */
    public function updateMetrics(int $clusterId, array $metrics): bool
    {
        $existing = $this->getById($clusterId);
        if ($existing === null) {
            return false;
        }

        $kmBaseline = isset($metrics['km_baseline']) ? (float) $metrics['km_baseline'] : (float) $existing['km_baseline'];
        $kmNn = isset($metrics['km_nn']) ? (float) $metrics['km_nn'] : (float) $existing['km_nn'];
        $kmToiUu = isset($metrics['km_toi_uu']) ? (float) $metrics['km_toi_uu'] : (float) $existing['km_toi_uu'];
        $tyLeTietKiem = isset($metrics['ty_le_tiet_kiem_phan_tram'])
            ? (float) $metrics['ty_le_tiet_kiem_phan_tram']
            : ($kmBaseline > 0.0 ? round((($kmBaseline - $kmToiUu) / $kmBaseline) * 100, 2) : 0.0);

        $soDonHang = isset($metrics['so_don_hang']) ? (int) $metrics['so_don_hang'] : (int) $existing['so_don_hang'];
        $viDoTamCum = isset($metrics['vi_do_tam_cum']) ? (float) $metrics['vi_do_tam_cum'] : (float) $existing['vi_do_tam_cum'];
        $kinhDoTamCum = isset($metrics['kinh_do_tam_cum']) ? (float) $metrics['kinh_do_tam_cum'] : (float) $existing['kinh_do_tam_cum'];
        $thoiGianChayMs = isset($metrics['thoi_gian_chay_ms'])
            ? (float) $metrics['thoi_gian_chay_ms']
            : (float) $existing['thoi_gian_chay_ms'];

        $sql = 'UPDATE cum_tuyen_giao
                SET so_don_hang = :so_don_hang,
                    vi_do_tam_cum = :vi_do_tam_cum,
                    kinh_do_tam_cum = :kinh_do_tam_cum,
                    km_baseline = :km_baseline,
                    km_nn = :km_nn,
                    km_toi_uu = :km_toi_uu,
                    ty_le_tiet_kiem_phan_tram = :ty_le_tiet_kiem_phan_tram,
                    thoi_gian_chay_ms = :thoi_gian_chay_ms
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'so_don_hang' => $soDonHang,
            'vi_do_tam_cum' => $viDoTamCum,
            'kinh_do_tam_cum' => $kinhDoTamCum,
            'km_baseline' => round($kmBaseline, 2),
            'km_nn' => round($kmNn, 2),
            'km_toi_uu' => round($kmToiUu, 2),
            'ty_le_tiet_kiem_phan_tram' => round($tyLeTietKiem, 2),
            'thoi_gian_chay_ms' => round($thoiGianChayMs, 3),
            'id' => $clusterId,
        ]);
    }

    /**
     * Kiểm tra cụm đã có đơn nào giao thành công hoặc giao thất bại hay chưa (Mục 5.2 - chống sửa cụm đã bắt đầu giao)
     *
     * @param int $clusterId
     * @return bool
     */
    public function hasProcessedOrders(int $clusterId): bool
    {
        $sql = 'SELECT COUNT(*) FROM don_hang
                WHERE cum_tuyen_id = :cum_tuyen_id
                  AND trang_thai IN (\'giao_thanh_cong\', \'giao_that_bai\')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cum_tuyen_id' => $clusterId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Tự động chuyển trạng thái cụm sang `hoan_tat` nếu không còn đơn `da_phan_cong` (Mục 4.2.4)
     *
     * @param int $clusterId
     * @return bool
     */
    public function markCompletedIfAllDone(int $clusterId): bool
    {
        $sqlCheck = 'SELECT COUNT(*) FROM don_hang
                     WHERE cum_tuyen_id = :cum_tuyen_id
                       AND trang_thai = \'da_phan_cong\'';

        $stmtCheck = $this->db->prepare($sqlCheck);
        $stmtCheck->execute(['cum_tuyen_id' => $clusterId]);
        $remaining = (int) $stmtCheck->fetchColumn();

        if ($remaining > 0) {
            return false;
        }

        $sqlUpdate = 'UPDATE cum_tuyen_giao
                      SET trang_thai = \'hoan_tat\'
                      WHERE id = :id AND trang_thai = \'dang_giao\'';

        $stmtUpdate = $this->db->prepare($sqlUpdate);
        $stmtUpdate->execute(['id' => $clusterId]);

        return $stmtUpdate->rowCount() > 0;
    }

    /**
     * Chuẩn hóa kiểu dữ liệu số của bản ghi Cụm tuyến giao
     *
     * @param array $row
     * @return array
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['buu_cuc_id'] = (int) $row['buu_cuc_id'];
        $row['tai_xe_id'] = (int) $row['tai_xe_id'];
        $row['vi_do_tam_cum'] = (float) $row['vi_do_tam_cum'];
        $row['kinh_do_tam_cum'] = (float) $row['kinh_do_tam_cum'];
        $row['vi_do_xuat_phat'] = (float) $row['vi_do_xuat_phat'];
        $row['kinh_do_xuat_phat'] = (float) $row['kinh_do_xuat_phat'];
        $row['so_don_hang'] = (int) $row['so_don_hang'];
        $row['km_baseline'] = (float) $row['km_baseline'];
        $row['km_nn'] = (float) $row['km_nn'];
        $row['km_toi_uu'] = (float) $row['km_toi_uu'];
        $row['ty_le_tiet_kiem_phan_tram'] = (float) $row['ty_le_tiet_kiem_phan_tram'];
        $row['thoi_gian_chay_ms'] = (float) $row['thoi_gian_chay_ms'];

        if (isset($row['so_don_dang_cho'])) {
            $row['so_don_dang_cho'] = (int) $row['so_don_dang_cho'];
        }
        if (isset($row['so_don_da_giao'])) {
            $row['so_don_da_giao'] = (int) $row['so_don_da_giao'];
        }
        if (isset($row['so_don_that_bai'])) {
            $row['so_don_that_bai'] = (int) $row['so_don_that_bai'];
        }

        return $row;
    }
}
?>