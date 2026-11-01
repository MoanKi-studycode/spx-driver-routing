<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class DonHangRepository
 * Quản lý truy vấn dữ liệu bảng `don_hang`.
 */
class DonHangRepository
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
     * Kiểm tra mã vận đơn đã tồn tại trong bảng `don_hang` hay chưa (Mục 2.3 Bước 1)
     *
     * @param string $maVanDon
     * @return bool
     */
    public function existsByMaVanDon(string $maVanDon): bool
    {
        $code = trim($maVanDon);
        if ($code === '') {
            return false;
        }

        $sql = 'SELECT 1 FROM don_hang WHERE ma_van_don = :ma_van_don LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ma_van_don' => $code]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Lấy danh sách tất cả các `ma_van_don` đã tồn tại trong DB để chống trùng nhanh
     *
     * @return array<string, bool> Map [ma_van_don => true]
     */
    public function getExistingMaVanDonMap(): array
    {
        $sql = 'SELECT ma_van_don FROM don_hang';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $map = [];
        foreach ($codes as $code) {
            $map[(string) $code] = true;
        }

        return $map;
    }

    /**
     * Lưu danh sách đơn hàng đồng bộ từ Inbound
     *
     * @param array $orders
     * @return int Số đơn đã lưu thành công
     */
    public function batchInsert(array $orders): int
    {
        if (empty($orders)) {
            return 0;
        }

        $sql = 'INSERT INTO don_hang (
                    buu_cuc_id, ma_van_don, ma_don_shopee, ma_lo, thu_tu_file,
                    ten_khach_hang, so_dien_thoai_khach, dia_chi_giao_hang,
                    tinh_thanh_goc, quan_huyen_goc, phuong_ten_goc,
                    phuong_id, vi_do, kinh_do, tien_thu_ho,
                    trang_thai, ly_do_chua_phan_cong
                ) VALUES (
                    :buu_cuc_id, :ma_van_don, :ma_don_shopee, :ma_lo, :thu_tu_file,
                    :ten_khach_hang, :so_dien_thoai_khach, :dia_chi_giao_hang,
                    :tinh_thanh_goc, :quan_huyen_goc, :phuong_ten_goc,
                    :phuong_id, :vi_do, :kinh_do, :tien_thu_ho,
                    :trang_thai, :ly_do_chua_phan_cong
                )';

        $stmt = $this->db->prepare($sql);
        $inserted = 0;

        foreach ($orders as $order) {
            $maVanDon = trim((string) ($order['ma_van_don'] ?? ''));
            if ($maVanDon === '' || $this->existsByMaVanDon($maVanDon)) {
                continue;
            }

            $stmt->execute([
                'buu_cuc_id' => (int) ($order['buu_cuc_id'] ?? ($_SESSION['buu_cuc_id'] ?? 1)),
                'ma_van_don' => $maVanDon,
                'ma_don_shopee' => $order['ma_don_shopee'] ?? null,
                'ma_lo' => $order['ma_lo'] ?? null,
                'thu_tu_file' => (int) ($order['thu_tu_file'] ?? 0),
                'ten_khach_hang' => (string) ($order['ten_khach_hang'] ?? ''),
                'so_dien_thoai_khach' => $order['so_dien_thoai_khach'] ?? ($order['so_dien_thoai'] ?? null),
                'dia_chi_giao_hang' => (string) ($order['dia_chi_giao_hang'] ?? ($order['dia_chi'] ?? '')),
                'tinh_thanh_goc' => $order['tinh_thanh_goc'] ?? ($order['tinh_thanh'] ?? null),
                'quan_huyen_goc' => $order['quan_huyen_goc'] ?? ($order['quan_huyen'] ?? null),
                'phuong_ten_goc' => $order['phuong_ten_goc'] ?? ($order['phuong'] ?? null),
                'phuong_id' => isset($order['phuong_id']) && $order['phuong_id'] !== null ? (int) $order['phuong_id'] : null,
                'vi_do' => isset($order['vi_do']) && $order['vi_do'] !== null && is_numeric($order['vi_do']) ? (float) $order['vi_do'] : null,
                'kinh_do' => isset($order['kinh_do']) && $order['kinh_do'] !== null && is_numeric($order['kinh_do']) ? (float) $order['kinh_do'] : null,
                'tien_thu_ho' => (float) ($order['tien_thu_ho'] ?? 0.0),
                'trang_thai' => 'chua_phan_cong',
                'ly_do_chua_phan_cong' => $order['ly_do_chua_phan_cong'] ?? null,
            ]);

            $inserted++;
        }

        return $inserted;
    }

    /**
     * Lấy thông tin đơn hàng theo ID
     *
     * @param int $orderId
     * @param int|null $buuCucId
     * @return array|null
     */
    public function getById(int $orderId, ?int $buuCucId = null): ?array
    {
        $sql = 'SELECT
                    dh.*,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten AS ten_tai_xe, nd.mau_sac_cum,
                    ctg.ma_cum_tuyen
                FROM don_hang dh
                LEFT JOIN phuong p ON dh.phuong_id = p.id
                LEFT JOIN nguoi_dung nd ON dh.tai_xe_id = nd.id
                LEFT JOIN cum_tuyen_giao ctg ON dh.cum_tuyen_id = ctg.id
                WHERE dh.id = :id';

        $params = ['id' => $orderId];
        if ($buuCucId !== null) {
            $sql .= ' AND dh.buu_cuc_id = :buu_cuc_id';
            $params['buu_cuc_id'] = $buuCucId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Lấy danh sách đơn hàng hợp lệ chưa phân công (đủ tọa độ và phuong_id, không bị lỗi)
     * Tuân thủ Data Contract tại Mục 10.1.5
     *
     * @param int|null $buuCucId
     * @return array
     */
    public function getUnassignedValidOrders(?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (int) ($_SESSION['buu_cuc_id'] ?? 1);

        $sql = 'SELECT
                    dh.*,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong
                FROM don_hang dh
                INNER JOIN phuong p ON dh.phuong_id = p.id
                WHERE dh.buu_cuc_id = :buu_cuc_id
                  AND dh.trang_thai = \'chua_phan_cong\'
                  AND (dh.ly_do_chua_phan_cong IS NULL OR dh.ly_do_chua_phan_cong = \'qua_tai_tai_xe\')
                  AND dh.vi_do IS NOT NULL
                  AND dh.kinh_do IS NOT NULL
                  AND dh.phuong_id IS NOT NULL
                ORDER BY dh.thu_tu_file ASC, dh.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $targetBuuCucId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Lấy danh sách đơn hàng chưa phân công bị lỗi (loi_toa_do, ngoai_vung_phuc_vu, qua_tai_tai_xe)
     *
     * @param int|null $buuCucId
     * @return array
     */
    public function getErrorOrders(?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (int) ($_SESSION['buu_cuc_id'] ?? 1);

        $sql = 'SELECT
                    dh.*,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong
                FROM don_hang dh
                LEFT JOIN phuong p ON dh.phuong_id = p.id
                WHERE dh.buu_cuc_id = :buu_cuc_id
                  AND dh.trang_thai = \'chua_phan_cong\'
                ORDER BY dh.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $targetBuuCucId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Lấy danh sách đơn hàng của một cụm tuyến giao
     *
     * @param int $clusterId
     * @param string|null $status Nếu truyền vào sẽ lọc theo trạng thái (VD: 'da_phan_cong')
     * @return array
     */
    public function getOrdersByCluster(int $clusterId, ?string $status = null): array
    {
        $sql = 'SELECT
                    dh.*,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten AS ten_tai_xe, nd.mau_sac_cum
                FROM don_hang dh
                LEFT JOIN phuong p ON dh.phuong_id = p.id
                LEFT JOIN nguoi_dung nd ON dh.tai_xe_id = nd.id
                WHERE dh.cum_tuyen_id = :cum_tuyen_id';

        $params = ['cum_tuyen_id' => $clusterId];
        if ($status !== null) {
            $sql .= ' AND dh.trang_thai = :trang_thai';
            $params['trang_thai'] = $status;
        }

        $sql .= ' ORDER BY COALESCE(dh.thu_tu_giao, 999999) ASC, dh.thu_tu_file ASC, dh.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Lấy danh sách toàn bộ đơn hàng của bưu cục phục vụ hiển thị Bản đồ Điều phối
     *
     * @param int $buuCucId
     * @return array
     */
    public function getOrdersByBuuCuc(int $buuCucId): array
    {
        $sql = 'SELECT
                    dh.*,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten AS ten_tai_xe, nd.mau_sac_cum,
                    ctg.ma_cum_tuyen
                FROM don_hang dh
                LEFT JOIN phuong p ON dh.phuong_id = p.id
                LEFT JOIN nguoi_dung nd ON dh.tai_xe_id = nd.id
                LEFT JOIN cum_tuyen_giao ctg ON dh.cum_tuyen_id = ctg.id
                WHERE dh.buu_cuc_id = :buu_cuc_id
                ORDER BY dh.cum_tuyen_id ASC, COALESCE(dh.thu_tu_giao, 999999) ASC, dh.thu_tu_file ASC, dh.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $buuCucId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Cập nhật cụm, tài xế và thứ tự giao cho đơn hàng
     *
     * @param int $orderId
     * @param int $clusterId
     * @param int $driverId
     * @param int $sequence
     * @return bool
     */
    public function assignToCluster(int $orderId, int $clusterId, int $driverId, int $sequence): bool
    {
        $sql = 'UPDATE don_hang
                SET cum_tuyen_id = :cum_tuyen_id,
                    tai_xe_id = :tai_xe_id,
                    thu_tu_giao = :thu_tu_giao,
                    trang_thai = \'da_phan_cong\',
                    ly_do_chua_phan_cong = NULL,
                    thoi_gian_phan_cong = NOW()
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'cum_tuyen_id' => $clusterId,
            'tai_xe_id' => $driverId,
            'thu_tu_giao' => $sequence,
            'id' => $orderId,
        ]);
    }

    /**
     * Cập nhật tọa độ và phường cho đơn lỗi (`chua_phan_cong`) trước khi gán vào cụm (Mục 5.2 - sua-don-loi)
     *
     * @param int $orderId
     * @param int $buuCucId
     * @param int $phuongId
     * @param string $tenPhuong
     * @param float $viDo
     * @param float $kinhDo
     * @param string|null $diaChi
     * @return bool
     */
    public function updateErrorOrderInfo(
        int $orderId,
        int $buuCucId,
        int $phuongId,
        string $tenPhuong,
        float $viDo,
        float $kinhDo,
        ?string $diaChi = null
    ): bool {
        $sql = 'UPDATE don_hang
                SET phuong_id = :phuong_id,
                    phuong_ten_goc = :phuong_ten_goc,
                    vi_do = :vi_do,
                    kinh_do = :kinh_do,
                    dia_chi_giao_hang = COALESCE(:dia_chi, dia_chi_giao_hang),
                    ly_do_chua_phan_cong = NULL
                WHERE id = :id
                  AND buu_cuc_id = :buu_cuc_id
                  AND trang_thai = \'chua_phan_cong\'';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'phuong_id' => $phuongId,
            'phuong_ten_goc' => $tenPhuong,
            'vi_do' => $viDo,
            'kinh_do' => $kinhDo,
            'dia_chi' => ($diaChi !== null && trim($diaChi) !== '') ? trim($diaChi) : null,
            'id' => $orderId,
            'buu_cuc_id' => $buuCucId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Chỉ cập nhật lại `thu_tu_giao` cho đơn hàng trong cụm (dùng khi tái định tuyến hoặc tính lại cụm)
     *
     * @param int $orderId
     * @param int $clusterId
     * @param int $sequence
     * @return bool
     */
    public function updateSequence(int $orderId, int $clusterId, int $sequence): bool
    {
        $sql = 'UPDATE don_hang
                SET thu_tu_giao = :thu_tu_giao
                WHERE id = :id AND cum_tuyen_id = :cum_tuyen_id';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'thu_tu_giao' => $sequence,
            'id' => $orderId,
            'cum_tuyen_id' => $clusterId,
        ]);
    }

    /**
     * Đánh dấu lý do quá tải tài xế cho các đơn chưa được phân cụm (Mục 3.1.1)
     *
     * @param array<int> $orderIds
     * @param int $buuCucId
     * @return int
     */
    public function markOverloadedOrders(array $orderIds, int $buuCucId): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        $sql = 'UPDATE don_hang
                SET trang_thai = \'chua_phan_cong\',
                    ly_do_chua_phan_cong = \'qua_tai_tai_xe\'
                WHERE id = :id
                  AND buu_cuc_id = :buu_cuc_id
                  AND trang_thai = \'chua_phan_cong\'';

        $stmt = $this->db->prepare($sql);
        $updated = 0;
        foreach ($orderIds as $id) {
            $stmt->execute([
                'id' => (int) $id,
                'buu_cuc_id' => $buuCucId,
            ]);
            $updated += $stmt->rowCount();
        }

        return $updated;
    }

    /**
     * Cập nhật kết quả giao hàng (giao_thanh_cong / giao_that_bai) chống bấm đúp theo Mục 4.2.4
     *
     * @param int $orderId
     * @param int $driverId
     * @param string $status
     * @param string|null $failReason
     * @return bool Trả về true nếu cập nhật thành công (rowCount > 0)
     */
    public function updateDeliveryResult(int $orderId, int $driverId, string $status, ?string $failReason = null): bool
    {
        if (!in_array($status, ['giao_thanh_cong', 'giao_that_bai'], true)) {
            return false;
        }

        $sql = 'UPDATE don_hang
                SET trang_thai = :trang_thai_moi,
                    ly_do_that_bai = :ly_do,
                    thoi_gian_hoan_tat = NOW()
                WHERE id = :id
                  AND tai_xe_id = :tx
                  AND trang_thai = \'da_phan_cong\'';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'trang_thai_moi' => $status,
            'ly_do' => $status === 'giao_that_bai' ? $failReason : null,
            'id' => $orderId,
            'tx' => $driverId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Chuẩn hóa kiểu dữ liệu của bản ghi Đơn hàng
     *
     * @param array $row
     * @return array
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['buu_cuc_id'] = (int) $row['buu_cuc_id'];
        $row['thu_tu_file'] = (int) $row['thu_tu_file'];
        $row['phuong_id'] = $row['phuong_id'] !== null ? (int) $row['phuong_id'] : null;
        $row['vi_do'] = $row['vi_do'] !== null ? (float) $row['vi_do'] : null;
        $row['kinh_do'] = $row['kinh_do'] !== null ? (float) $row['kinh_do'] : null;
        $row['tien_thu_ho'] = (float) $row['tien_thu_ho'];
        $row['cum_tuyen_id'] = $row['cum_tuyen_id'] !== null ? (int) $row['cum_tuyen_id'] : null;
        $row['tai_xe_id'] = $row['tai_xe_id'] !== null ? (int) $row['tai_xe_id'] : null;
        $row['thu_tu_giao'] = $row['thu_tu_giao'] !== null ? (int) $row['thu_tu_giao'] : null;

        return $row;
    }
}
?>