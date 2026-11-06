<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class DonHangRepository
 * Quản lý truy vấn dữ liệu bảng `don_hang` và các truy vấn thống kê vận hành/tra cứu công khai.
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
     * Tra cứu công khai đơn hàng theo Mã vận đơn (Hợp đồng dữ liệu Mục 2 giữa TV2 & TV3)
     * Trả về thông tin đơn hàng kèm tên bưu cục (`buu_cuc.ten_buu_cuc`), tên phường (`phuong.ten_phuong`),
     * tên & SĐT tài xế phụ trách (`nguoi_dung.ho_ten`, `nguoi_dung.so_dien_thoai`), thứ tự điểm giao (`thu_tu_giao`),
     * trạng thái đơn (`trang_thai`), thời gian hoàn tất (`thoi_gian_hoan_tat`) và lý do thất bại (`ly_do_that_bai`).
     *
     * @param string $maVanDon
     * @return array|null
     */
    public function timTheoMaVanDon(string $maVanDon): ?array
    {
        $code = trim($maVanDon);
        if ($code === '') {
            return null;
        }

        $sql = 'SELECT
                    dh.*,
                    dh.so_dien_thoai_khach AS sdt_nguoi_nhan,
                    bc.ma_buu_cuc,
                    bc.ten_buu_cuc,
                    bc.dia_chi AS dia_chi_buu_cuc,
                    bc.ten_quan_huyen,
                    bc.ten_tinh,
                    p.ten_phuong,
                    p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten,
                    nd.ho_ten AS ten_tai_xe,
                    nd.so_dien_thoai,
                    nd.so_dien_thoai AS sdt_tai_xe,
                    nd.mau_sac_cum,
                    ctg.ma_cum_tuyen,
                    ctg.so_don_hang AS tong_so_don_cum,
                    ctg.trang_thai AS trang_thai_cum
                FROM don_hang dh
                INNER JOIN buu_cuc bc ON dh.buu_cuc_id = bc.id
                LEFT JOIN phuong p ON dh.phuong_id = p.id
                LEFT JOIN nguoi_dung nd ON dh.tai_xe_id = nd.id
                LEFT JOIN cum_tuyen_giao ctg ON dh.cum_tuyen_id = ctg.id
                WHERE UPPER(dh.ma_van_don) = UPPER(:ma_van_don)
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ma_van_don' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $normalized = $this->normalizeRow($row);
        $normalized['tong_so_don_cum'] = isset($row['tong_so_don_cum']) && $row['tong_so_don_cum'] !== null
            ? (int) $row['tong_so_don_cum']
            : null;

        return $normalized;
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
                    dh.so_dien_thoai_khach AS sdt_nguoi_nhan,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten AS ten_tai_xe, nd.so_dien_thoai AS sdt_tai_xe, nd.mau_sac_cum,
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
                  AND (dh.ly_do_chua_phan_cong IS NOT NULL OR dh.vi_do IS NULL OR dh.kinh_do IS NULL OR dh.phuong_id IS NULL)
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
                    dh.so_dien_thoai_khach AS sdt_nguoi_nhan,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten AS ten_tai_xe, nd.so_dien_thoai AS sdt_tai_xe, nd.mau_sac_cum
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
     * Lấy danh sách toàn bộ đơn hàng của bưu cục phục vụ hiển thị Bản đồ & Bảng Điều phối
     *
     * @param int $buuCucId
     * @return array
     */
    public function getOrdersByBuuCuc(int $buuCucId): array
    {
        $sql = 'SELECT
                    dh.*,
                    dh.so_dien_thoai_khach AS sdt_nguoi_nhan,
                    p.ten_phuong, p.ma_hanh_chinh AS ma_hanh_chinh_phuong,
                    nd.ho_ten AS ten_tai_xe, nd.so_dien_thoai AS sdt_tai_xe, nd.mau_sac_cum,
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
     * Chuyển thủ công một đơn hàng đang chờ giao (`da_phan_cong`) sang cụm tuyến của tài xế khác
     *
     * @param int $orderId
     * @param int $targetClusterId
     * @param int $targetDriverId
     * @param int $newSequence
     * @return bool
     */
    public function moveOrderToCluster(int $orderId, int $targetClusterId, int $targetDriverId, int $newSequence): bool
    {
        $sql = 'UPDATE don_hang
                SET cum_tuyen_id = :cum_tuyen_id,
                    tai_xe_id = :tai_xe_id,
                    thu_tu_giao = :thu_tu_giao,
                    thoi_gian_phan_cong = NOW()
                WHERE id = :id
                  AND trang_thai = \'da_phan_cong\'';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'cum_tuyen_id' => $targetClusterId,
            'tai_xe_id' => $targetDriverId,
            'thu_tu_giao' => $newSequence,
            'id' => $orderId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reset toàn bộ các đơn hàng đã phân công/đã giao của Bưu cục về `chua_phan_cong`
     * (Chỉ gọi khi Điều phối viên xác nhận "Reset & Phân cụm lại toàn bộ")
     *
     * @param int $buuCucId
     * @return int
     */
    public function resetAllAssignedOrdersByBuuCuc(int $buuCucId): int
    {
        $sql = 'UPDATE don_hang
                SET cum_tuyen_id = NULL,
                    tai_xe_id = NULL,
                    thu_tu_giao = NULL,
                    trang_thai = \'chua_phan_cong\',
                    ly_do_that_bai = NULL,
                    ly_do_chua_phan_cong = CASE
                        WHEN vi_do IS NULL OR kinh_do IS NULL THEN \'loi_toa_do\'
                        WHEN phuong_id IS NULL THEN \'ngoai_vung_phuc_vu\'
                        ELSE NULL
                    END,
                    thoi_gian_phan_cong = NULL,
                    thoi_gian_hoan_tat = NULL
                WHERE buu_cuc_id = :buu_cuc_id
                  AND trang_thai IN (\'da_phan_cong\', \'giao_thanh_cong\', \'giao_that_bai\')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $buuCucId]);

        return $stmt->rowCount();
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
     * Cập nhật kết quả giao hàng (giao_thanh_cong / giao_that_bai) chống bấm đúp theo Mục 4.2.4,
     * đồng thời tự động chuyển trạng thái `cum_tuyen_giao` sang `hoan_tat` khi toàn bộ đơn trong cụm đã xử lý xong.
     *
     * @param int $orderId
     * @param int $driverId
     * @param string $status
     * @param string|null $lyDoThatBai
     * @return bool Trả về true nếu cập nhật thành công (rowCount > 0)
     */
    public function updateDeliveryResult(int $orderId, int $driverId, string $status, ?string $lyDoThatBai = null): bool
    {
        if (!in_array($status, ['giao_thanh_cong', 'giao_that_bai'], true)) {
            return false;
        }

        $sqlCluster = 'SELECT cum_tuyen_id FROM don_hang WHERE id = :id AND tai_xe_id = :tx LIMIT 1';
        $stmtCluster = $this->db->prepare($sqlCluster);
        $stmtCluster->execute(['id' => $orderId, 'tx' => $driverId]);
        $clusterIdRaw = $stmtCluster->fetchColumn();
        $clusterId = $clusterIdRaw !== false && $clusterIdRaw !== null ? (int) $clusterIdRaw : null;

        $cleanReason = ($status === 'giao_that_bai' && $lyDoThatBai !== null && trim($lyDoThatBai) !== '')
            ? trim($lyDoThatBai)
            : null;

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
            'ly_do' => $cleanReason,
            'id' => $orderId,
            'tx' => $driverId,
        ]);

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        // Mục 4.2.4: Nếu toàn bộ đơn trong cum_tuyen_giao đã khác da_phan_cong thì tự động chuyển sang hoan_tat
        if ($clusterId !== null && $clusterId > 0) {
            $stmtRemain = $this->db->prepare(
                'SELECT COUNT(*) FROM don_hang WHERE cum_tuyen_id = :cum_tuyen_id AND trang_thai = \'da_phan_cong\''
            );
            $stmtRemain->execute(['cum_tuyen_id' => $clusterId]);
            if ((int) $stmtRemain->fetchColumn() === 0) {
                $stmtComplete = $this->db->prepare(
                    'UPDATE cum_tuyen_giao SET trang_thai = \'hoan_tat\' WHERE id = :id AND trang_thai = \'dang_giao\''
                );
                $stmtComplete->execute(['id' => $clusterId]);
            }
        }

        return true;
    }

    /**
     * Alias tiếng Việt cho updateDeliveryResult() theo Hợp đồng dữ liệu Mục 2
     *
     * @param int $donHangId
     * @param int $taiXeId
     * @param string $trangThai
     * @param string|null $lyDoThatBai
     * @return bool
     */
    public function capNhatKetQuaGiaoHang(int $donHangId, int $taiXeId, string $trangThai, ?string $lyDoThatBai = null): bool
    {
        return $this->updateDeliveryResult($donHangId, $taiXeId, $trangThai, $lyDoThatBai);
    }

    /**
     * Thống kê số lượng đơn hàng và tiền thu hộ COD theo 4 trạng thái vận hành (Mục 3.A)
     *
     * @param int|null $buuCucId
     * @return array
     */
    public function thongKeTrangThaiDonHang(?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (int) ($_SESSION['buu_cuc_id'] ?? 1);

        $sql = 'SELECT
                    trang_thai,
                    COUNT(*) AS so_don,
                    COALESCE(SUM(tien_thu_ho), 0) AS tong_cod
                FROM don_hang
                WHERE buu_cuc_id = :buu_cuc_id
                GROUP BY trang_thai';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['buu_cuc_id' => $targetBuuCucId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'chua_phan_cong' => 0,
            'da_phan_cong' => 0,
            'giao_thanh_cong' => 0,
            'giao_that_bai' => 0,
            'tong_so_don' => 0,
            'so_don_da_xu_ly' => 0,
            'ty_le_thanh_cong_sla' => 0.0,
            'cod_chua_phan_cong' => 0.0,
            'cod_dang_giao' => 0.0,
            'cod_da_thu' => 0.0,
            'cod_that_bai' => 0.0,
            'tong_cod_toan_buu_cuc' => 0.0,
            'tong_cod_phai_thu_tren_tuyen' => 0.0,
        ];

        foreach ($rows as $r) {
            $st = (string) ($r['trang_thai'] ?? '');
            $cnt = (int) ($r['so_don'] ?? 0);
            $cod = (float) ($r['tong_cod'] ?? 0.0);

            if (array_key_exists($st, $result)) {
                $result[$st] = $cnt;
            }
            $result['tong_so_don'] += $cnt;
            $result['tong_cod_toan_buu_cuc'] += $cod;

            if ($st === 'chua_phan_cong') {
                $result['cod_chua_phan_cong'] = $cod;
            } elseif ($st === 'da_phan_cong') {
                $result['cod_dang_giao'] = $cod;
                $result['tong_cod_phai_thu_tren_tuyen'] += $cod;
            } elseif ($st === 'giao_thanh_cong') {
                $result['cod_da_thu'] = $cod;
                $result['tong_cod_phai_thu_tren_tuyen'] += $cod;
            } elseif ($st === 'giao_that_bai') {
                $result['cod_that_bai'] = $cod;
                $result['tong_cod_phai_thu_tren_tuyen'] += $cod;
            }
        }

        $daXuLy = $result['giao_thanh_cong'] + $result['giao_that_bai'];
        $result['so_don_da_xu_ly'] = $daXuLy;
        $result['ty_le_thanh_cong_sla'] = $daXuLy > 0
            ? round(($result['giao_thanh_cong'] / $daXuLy) * 100, 2)
            : 0.0;

        return $result;
    }

    /**
     * Thống kê hiệu suất giao hàng và đối soát tiền thu hộ COD theo từng Tài xế (Mục 3.A)
     *
     * @param int|null $buuCucId
     * @return array
     */
    public function thongKeHieuSuatVaCodTheoTaiXe(?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (int) ($_SESSION['buu_cuc_id'] ?? 1);

        $sql = 'SELECT
                    nd.id AS tai_xe_id,
                    nd.ten_dang_nhap,
                    nd.ho_ten,
                    nd.so_dien_thoai,
                    nd.mau_sac_cum,
                    p.ten_phuong AS ten_phuong_uu_tien,
                    COUNT(dh.id) AS tong_so_don,
                    SUM(CASE WHEN dh.trang_thai = \'da_phan_cong\' THEN 1 ELSE 0 END) AS so_don_dang_giao,
                    SUM(CASE WHEN dh.trang_thai = \'giao_thanh_cong\' THEN 1 ELSE 0 END) AS so_don_thanh_cong,
                    SUM(CASE WHEN dh.trang_thai = \'giao_that_bai\' THEN 1 ELSE 0 END) AS so_don_that_bai,
                    COALESCE(SUM(dh.tien_thu_ho), 0) AS tong_cod_phai_thu,
                    COALESCE(SUM(CASE WHEN dh.trang_thai = \'giao_thanh_cong\' THEN dh.tien_thu_ho ELSE 0 END), 0) AS tong_cod_thuc_thu,
                    COALESCE(SUM(CASE WHEN dh.trang_thai = \'da_phan_cong\' THEN dh.tien_thu_ho ELSE 0 END), 0) AS tong_cod_dang_giao,
                    COALESCE(SUM(CASE WHEN dh.trang_thai = \'giao_that_bai\' THEN dh.tien_thu_ho ELSE 0 END), 0) AS tong_cod_that_bai
                FROM nguoi_dung nd
                LEFT JOIN phuong p ON nd.phuong_uu_tien_id = p.id
                LEFT JOIN don_hang dh ON dh.tai_xe_id = nd.id AND dh.buu_cuc_id = :buu_cuc_id_join
                WHERE nd.buu_cuc_id = :buu_cuc_id
                  AND nd.vai_tro = \'tai_xe\'
                  AND nd.dang_hoat_dong = 1
                GROUP BY nd.id, nd.ten_dang_nhap, nd.ho_ten, nd.so_dien_thoai, nd.mau_sac_cum, p.ten_phuong
                ORDER BY nd.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'buu_cuc_id_join' => $targetBuuCucId,
            'buu_cuc_id' => $targetBuuCucId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lấy thêm thông tin cụm tuyến mới nhất của từng tài xế
        $sqlCluster = 'SELECT
                           tai_xe_id,
                           id AS cum_tuyen_id,
                           ma_cum_tuyen,
                           km_baseline,
                           km_nn,
                           km_toi_uu,
                           ty_le_tiet_kiem_phan_tram,
                           thoi_gian_chay_ms,
                           trang_thai AS trang_thai_cum
                       FROM cum_tuyen_giao
                       WHERE buu_cuc_id = :buu_cuc_id
                       ORDER BY id DESC';
        $stmtCluster = $this->db->prepare($sqlCluster);
        $stmtCluster->execute(['buu_cuc_id' => $targetBuuCucId]);
        $clusterRows = $stmtCluster->fetchAll(PDO::FETCH_ASSOC);

        $latestClusterByDriver = [];
        foreach ($clusterRows as $cRow) {
            $dId = (int) $cRow['tai_xe_id'];
            if (!isset($latestClusterByDriver[$dId])) {
                $latestClusterByDriver[$dId] = $cRow;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $driverId = (int) $row['tai_xe_id'];
            $tongDon = (int) ($row['tong_so_don'] ?? 0);
            $dangGiao = (int) ($row['so_don_dang_giao'] ?? 0);
            $thanhCong = (int) ($row['so_don_thanh_cong'] ?? 0);
            $thatBai = (int) ($row['so_don_that_bai'] ?? 0);
            $daXuLy = $thanhCong + $thatBai;
            $tyLeHoanThanh = $tongDon > 0 ? round(($thanhCong / $tongDon) * 100, 1) : 0.0;
            $tyLeSla = $daXuLy > 0 ? round(($thanhCong / $daXuLy) * 100, 1) : 0.0;

            $clusterInfo = $latestClusterByDriver[$driverId] ?? null;

            $result[] = [
                'tai_xe_id' => $driverId,
                'ten_dang_nhap' => (string) $row['ten_dang_nhap'],
                'ho_ten' => (string) $row['ho_ten'],
                'so_dien_thoai' => (string) ($row['so_dien_thoai'] ?? ''),
                'mau_sac_cum' => (string) ($row['mau_sac_cum'] ?? '#2563eb'),
                'ten_phuong_uu_tien' => (string) ($row['ten_phuong_uu_tien'] ?? ''),
                'cum_tuyen_id' => $clusterInfo !== null ? (int) $clusterInfo['cum_tuyen_id'] : null,
                'ma_cum_tuyen' => $clusterInfo !== null ? (string) $clusterInfo['ma_cum_tuyen'] : '-',
                'km_baseline' => $clusterInfo !== null ? (float) $clusterInfo['km_baseline'] : 0.0,
                'km_nn' => $clusterInfo !== null ? (float) $clusterInfo['km_nn'] : 0.0,
                'km_toi_uu' => $clusterInfo !== null ? (float) $clusterInfo['km_toi_uu'] : 0.0,
                'ty_le_tiet_kiem_phan_tram' => $clusterInfo !== null ? (float) $clusterInfo['ty_le_tiet_kiem_phan_tram'] : 0.0,
                'thoi_gian_chay_ms' => $clusterInfo !== null ? (float) $clusterInfo['thoi_gian_chay_ms'] : 0.0,
                'trang_thai_cum' => $clusterInfo !== null ? (string) $clusterInfo['trang_thai_cum'] : 'dang_giao',
                'tong_so_don' => $tongDon,
                'da_phan_cong' => $dangGiao,
                'giao_thanh_cong' => $thanhCong,
                'giao_that_bai' => $thatBai,
                'so_don_dang_giao' => $dangGiao,
                'so_don_thanh_cong' => $thanhCong,
                'so_don_that_bai' => $thatBai,
                'ty_le_hoan_thanh' => $tyLeHoanThanh,
                'ty_le_sla' => $tyLeSla,
                'tong_cod_phai_thu' => (float) ($row['tong_cod_phai_thu'] ?? 0.0),
                'tong_cod_thuc_thu' => (float) ($row['tong_cod_thuc_thu'] ?? 0.0),
                'tong_cod_dang_giao' => (float) ($row['tong_cod_dang_giao'] ?? 0.0),
                'tong_cod_that_bai' => (float) ($row['tong_cod_that_bai'] ?? 0.0),
                'cod_con_phai_nop' => (float) ($row['tong_cod_thuc_thu'] ?? 0.0),
            ];
        }

        return $result;
    }

    /**
     * Thống kê số lượng đơn hàng, tiến độ hoàn thành và COD theo từng Phường/Xã thuộc Bưu cục (Mục 3.A)
     *
     * @param int|null $buuCucId
     * @return array
     */
    public function thongKeDonHangTheoPhuong(?int $buuCucId = null): array
    {
        $targetBuuCucId = $buuCucId ?? (int) ($_SESSION['buu_cuc_id'] ?? 1);

        $sql = 'SELECT
                    p.id AS phuong_id,
                    p.ma_hanh_chinh,
                    p.ten_phuong,
                    COUNT(dh.id) AS tong_so_don,
                    SUM(CASE WHEN dh.trang_thai = \'chua_phan_cong\' THEN 1 ELSE 0 END) AS so_don_chua_phan_cong,
                    SUM(CASE WHEN dh.trang_thai = \'da_phan_cong\' THEN 1 ELSE 0 END) AS so_don_dang_giao,
                    SUM(CASE WHEN dh.trang_thai = \'giao_thanh_cong\' THEN 1 ELSE 0 END) AS so_don_thanh_cong,
                    SUM(CASE WHEN dh.trang_thai = \'giao_that_bai\' THEN 1 ELSE 0 END) AS so_don_that_bai,
                    COALESCE(SUM(dh.tien_thu_ho), 0) AS tong_cod_phuong,
                    COALESCE(SUM(CASE WHEN dh.trang_thai = \'giao_thanh_cong\' THEN dh.tien_thu_ho ELSE 0 END), 0) AS cod_da_thu_phuong
                FROM phuong p
                LEFT JOIN don_hang dh ON dh.phuong_id = p.id AND dh.buu_cuc_id = :buu_cuc_id_join
                WHERE p.buu_cuc_id = :buu_cuc_id
                  AND p.dang_hoat_dong = 1
                GROUP BY p.id, p.ma_hanh_chinh, p.ten_phuong
                ORDER BY p.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'buu_cuc_id_join' => $targetBuuCucId,
            'buu_cuc_id' => $targetBuuCucId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $tongDon = (int) ($r['tong_so_don'] ?? 0);
            $thanhCong = (int) ($r['so_don_thanh_cong'] ?? 0);
            $thatBai = (int) ($r['so_don_that_bai'] ?? 0);
            $daXuLy = $thanhCong + $thatBai;

            $result[] = [
                'phuong_id' => (int) $r['phuong_id'],
                'ma_hanh_chinh' => (string) $r['ma_hanh_chinh'],
                'ten_phuong' => (string) $r['ten_phuong'],
                'tong_so_don' => $tongDon,
                'so_don_chua_phan_cong' => (int) ($r['so_don_chua_phan_cong'] ?? 0),
                'so_don_dang_giao' => (int) ($r['so_don_dang_giao'] ?? 0),
                'so_don_thanh_cong' => $thanhCong,
                'so_don_that_bai' => $thatBai,
                'ty_le_hoan_thanh' => $tongDon > 0 ? round(($thanhCong / $tongDon) * 100, 1) : 0.0,
                'ty_le_sla' => $daXuLy > 0 ? round(($thanhCong / $daXuLy) * 100, 1) : 0.0,
                'tong_cod_phuong' => (float) ($r['tong_cod_phuong'] ?? 0.0),
                'cod_da_thu_phuong' => (float) ($r['cod_da_thu_phuong'] ?? 0.0),
            ];
        }

        return $result;
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

        if (!isset($row['sdt_nguoi_nhan']) && array_key_exists('so_dien_thoai_khach', $row)) {
            $row['sdt_nguoi_nhan'] = $row['so_dien_thoai_khach'];
        }

        return $row;
    }
}
?>
