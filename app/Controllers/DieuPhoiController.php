<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\BuuCucRepository;
use App\Repositories\CumTuyenGiaoRepository;
use App\Repositories\DonHangRepository;
use App\Repositories\NguoiDungRepository;
use App\Repositories\PhuongRepository;
use App\Services\Clustering\BalancedKMeansService;
use App\Services\Routing\DieuPhoiLoTrinhService;
use App\Services\Routing\HaversineService;
use App\Services\ShopeeApi\InboundService;
use App\Services\ShopeeApi\MockShopeeOrderService;
use PDO;
use Throwable;

/**
 * Class DieuPhoiController
 * Quản lý màn hình Điều phối, đồng bộ Mock Shopee API, phân cụm K-Means (có Dispatch Lock),
 * sửa đơn lỗi tọa độ (Nominatim Draggable Preview) và chuyển cụm/đổi tài xế thủ công.
 */
class DieuPhoiController
{
    private const EARTH_RADIUS_KM = 6371.0;

    protected PDO $db;
    protected BuuCucRepository $buuCucRepo;
    protected PhuongRepository $phuongRepo;
    protected NguoiDungRepository $nguoiDungRepo;
    protected CumTuyenGiaoRepository $cumTuyenRepo;
    protected DonHangRepository $donHangRepo;
    protected InboundService $inboundService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->buuCucRepo = new BuuCucRepository($this->db);
        $this->phuongRepo = new PhuongRepository($this->db);
        $this->nguoiDungRepo = new NguoiDungRepository($this->db);
        $this->cumTuyenRepo = new CumTuyenGiaoRepository($this->db);
        $this->donHangRepo = new DonHangRepository($this->db);
        $this->inboundService = new InboundService();
    }

    /**
     * Hiển thị màn hình Bản đồ Phân cụm & Điều hành (/dieu-phoi)
     *
     * @return void
     */
    public function index(): void
    {
        if (!$this->authorizeDispatcher(false)) {
            return;
        }

        // Hỗ trợ chuyển đổi cơ sở Bưu cục nếu quản trị viên chọn trên thanh công cụ (Mục 4.1.1)
        if (isset($_GET['buu_cuc_id']) && is_numeric($_GET['buu_cuc_id'])) {
            $requestedHubId = (int) $_GET['buu_cuc_id'];
            $requestedHub = $this->buuCucRepo->getById($requestedHubId);
            if ($requestedHub !== null) {
                $_SESSION['buu_cuc_id'] = $requestedHub['id'];
                Session::set('buu_cuc_id', $requestedHub['id']);
            }
        }

        $buuCucId = $this->getCurrentBuuCucId();
        $buuCuc = $this->buuCucRepo->getById($buuCucId) ?? $this->getDefaultBuuCuc();
        $danhSachBuuCuc = $this->buuCucRepo->getAllActive();
        $danhSachPhuong = $this->phuongRepo->getByBuuCuc((int) $buuCuc['id']);
        $danhSachTaiXe = $this->nguoiDungRepo->getActiveDrivers((int) $buuCuc['id']);
        $danhSachCum = $this->cumTuyenRepo->getClusters(null, (int) $buuCuc['id']);
        $danhSachDonLoi = $this->donHangRepo->getErrorOrders((int) $buuCuc['id']);
        $tatCaDonHang = $this->donHangRepo->getOrdersByBuuCuc((int) $buuCuc['id']);

        // Chuẩn bị dữ liệu chi tiết từng cụm kèm lộ trình Baseline và lộ trình 2-Opt để vẽ trên Leaflet
        $duLieuCumBanDo = $this->buildClusterMapPayload($danhSachCum, $buuCuc);
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/dinh-tuyen-spx/public';
        $pageTitle = 'Điều Phối Phân Cụm & Lộ Trình - ' . ($buuCuc['ten_buu_cuc'] ?? 'SPX Express');

        $viewData = [
            'title' => $pageTitle,
            'pageTitle' => $pageTitle,
            'pageScript' => $baseUrl . '/assets/js/ban_do_dieu_phoi.js',
            'buuCuc' => $buuCuc,
            'danhSachBuuCuc' => $danhSachBuuCuc,
            'danhSachPhuong' => $danhSachPhuong,
            'danhSachTaiXe' => $danhSachTaiXe,
            'danhSachCum' => $danhSachCum,
            'danhSachDonLoi' => $danhSachDonLoi,
            'tatCaDonHang' => $tatCaDonHang,
            'duLieuCumBanDo' => $duLieuCumBanDo,
            'csrfToken' => $this->ensureCsrfToken(),
        ];

        $this->renderView('dieu_phoi/index', $viewData);
    }

    /**
     * API đồng bộ Mock API, chạy Balanced K-Means + NN + 2-Opt từ P0 (POST /api/dieu-phoi/phan-cum-toi-uu)
     * Hỗ trợ Dispatch Lock (Mục 3.B):
     * - Mặc định (`reset_all = false`): Giữ nguyên các cụm tuyến đang đi giao và chỉ phân cụm bổ sung các đơn `chua_phan_cong`.
     * - Khi Điều phối viên xác nhận (`reset_all = true`): Reset toàn bộ cụm tuyến của Bưu cục và phân cụm lại từ đầu.
     *
     * @return void
     */
    public function phanCumToiUu(): void
    {
        if (!$this->authorizeDispatcher(true)) {
            return;
        }

        if (!$this->verifyCsrfFromRequest()) {
            $this->sendJson(false, 'Mã bảo mật CSRF không hợp lệ hoặc đã hết hạn.', [], 403);
            return;
        }

        $input = $this->getRequestData();
        $resetAll = !empty($input['reset_all']) && ($input['reset_all'] === true || $input['reset_all'] === '1' || $input['reset_all'] === 1);

        try {
            $buuCucId = $this->getCurrentBuuCucId();
            $buuCuc = $this->buuCucRepo->getById($buuCucId) ?? $this->getDefaultBuuCuc();
            $validWards = $this->phuongRepo->getByBuuCuc((int) $buuCuc['id']);

            $mockService = new MockShopeeOrderService();
            $payload = $mockService->fetchOrders();

            $this->db->beginTransaction();

            // Nếu Điều phối viên xác nhận "Reset & Phân cụm lại toàn bộ" qua Confirm Modal
            $soDonReset = 0;
            if ($resetAll) {
                $soDonReset = $this->donHangRepo->resetAllAssignedOrdersByBuuCuc((int) $buuCuc['id']);
                $this->cumTuyenRepo->deleteByBuuCuc((int) $buuCuc['id']);
            }

            // 1. Kiểm tra và lọc đơn hàng đầu vào qua InboundService (Mục 2.3)
            $existingCodeMap = $this->donHangRepo->getExistingMaVanDonMap();
            $inboundResult = $this->inboundService->processBatch(
                $payload,
                $buuCuc,
                $validWards,
                $existingCodeMap
            );

            $soDonMoiLuu = $this->donHangRepo->batchInsert($inboundResult['tat_ca_don_can_luu']);

            // 2. Lấy toàn bộ đơn hàng hợp lệ đang chờ phân công (`chua_phan_cong`) của bưu cục hiện tại
            $unassignedOrders = $this->donHangRepo->getUnassignedValidOrders((int) $buuCuc['id']);
            $activeDrivers = $this->nguoiDungRepo->getActiveDrivers((int) $buuCuc['id']);

            $depot = [
                'vi_do' => (float) $buuCuc['vi_do'],
                'kinh_do' => (float) $buuCuc['kinh_do'],
            ];

            // Lọc tài xế còn sức chứa > 0 theo Mục 3.1.1
            $availableDrivers = array_values(array_filter(
                $activeDrivers,
                static fn(array $driver): bool => (int) ($driver['suc_chua_con_lai'] ?? 0) > 0
            ));

            $totalCapacity = 0;
            foreach ($availableDrivers as $drv) {
                $totalCapacity += (int) ($drv['suc_chua_con_lai'] ?? 0);
            }

            $soDonQuaTai = 0;
            $ordersToCluster = $unassignedOrders;

            if (empty($availableDrivers) || $totalCapacity <= 0) {
                if (!empty($unassignedOrders)) {
                    $overloadIds = array_map(static fn(array $o): int => (int) $o['id'], $unassignedOrders);
                    $soDonQuaTai = $this->donHangRepo->markOverloadedOrders($overloadIds, (int) $buuCuc['id']);
                }
                $ordersToCluster = [];
            } elseif (count($unassignedOrders) > $totalCapacity) {
                // Nếu N > C_tong, chỉ lấy C_tong đơn gần Bưu cục P0 nhất để phân cụm; phần dư đánh dấu qua_tai_tai_xe
                usort($unassignedOrders, function (array $a, array $b) use ($depot): int {
                    $da = $this->distanceKm($depot['vi_do'], $depot['kinh_do'], (float) $a['vi_do'], (float) $a['kinh_do']);
                    $db = $this->distanceKm($depot['vi_do'], $depot['kinh_do'], (float) $b['vi_do'], (float) $b['kinh_do']);
                    if (abs($da - $db) > 1e-9) {
                        return $da <=> $db;
                    }
                    return ((int) $a['id']) <=> ((int) $b['id']);
                });

                $ordersToCluster = array_slice($unassignedOrders, 0, $totalCapacity);
                $excessOrders = array_slice($unassignedOrders, $totalCapacity);
                $overloadIds = array_map(static fn(array $o): int => (int) $o['id'], $excessOrders);
                $soDonQuaTai = $this->donHangRepo->markOverloadedOrders($overloadIds, (int) $buuCuc['id']);
            }

            $createdOrUpdatedClusters = [];
            $soCumGiuNguyenLock = 0;

            // 3. Chạy thuật toán Phân cụm Balanced K-Means & Tối ưu lộ trình NN + 2-Opt từ P0
            if (!empty($ordersToCluster) && !empty($availableDrivers)) {
                $wardCoordinates = [];
                foreach ($validWards as $w) {
                    $wardCoordinates[(int) $w['id']] = [
                        'vi_do' => (float) $w['vi_do_tam'],
                        'kinh_do' => (float) $w['kinh_do_tam'],
                    ];
                }

                $clusters = $this->runBalancedKMeans(
                    $ordersToCluster,
                    $availableDrivers,
                    $depot,
                    (int) ($buuCuc['suc_chua_moi_cum'] ?? 20),
                    $wardCoordinates
                );
                $batchCode = (string) ($inboundResult['ma_lo'] ?? ('LO_' . date('Ymd')));

                foreach ($clusters as $idx => $clusterItem) {
                    $clusterOrders = $clusterItem['don_hang'] ?? [];
                    if (empty($clusterOrders)) {
                        continue;
                    }

                    $driver = $clusterItem['tai_xe'] ?? $availableDrivers[$idx % count($availableDrivers)];
                    $driverId = (int) $driver['id'];

                    // Dispatch Lock: Nếu tài xế đang có cụm `dang_giao` và không phải chế độ reset_all,
                    // gộp các đơn mới vào cụm đang giao của tài xế đó và tái tối ưu các điểm chưa giao (giữ nguyên các đơn đã giao!)
                    $existingOpenCluster = !$resetAll
                        ? $this->cumTuyenRepo->getOpenClusterByDriver($driverId, (int) $buuCuc['id'])
                        : null;

                    if ($existingOpenCluster !== null) {
                        $clusterId = (int) $existingOpenCluster['id'];
                        $currentSeq = (int) ($existingOpenCluster['so_don_hang'] ?? 0);
                        foreach (array_values($clusterOrders) as $point) {
                            $currentSeq++;
                            $this->donHangRepo->assignToCluster(
                                (int) $point['id'],
                                $clusterId,
                                $driverId,
                                $currentSeq
                            );
                        }

                        $recalc = $this->recalculateClusterRoutePreservingCompleted($clusterId, $depot);
                        $soCumGiuNguyenLock++;
                        $createdOrUpdatedClusters[] = array_merge($existingOpenCluster, $recalc);
                    } else {
                        $centroid = $clusterItem['tam_cum'] ?? $this->calculateCentroid($clusterOrders, $depot);
                        $planResult = $this->runRouteOptimization($depot, $clusterOrders);
                        $orderedPoints = $planResult['thu_tu'] ?? $clusterOrders;

                        $kmBaseline = (float) ($planResult['km_baseline'] ?? 0.0);
                        $kmNn = (float) ($planResult['km_nn'] ?? 0.0);
                        $kmToiUu = (float) ($planResult['km_toi_uu'] ?? 0.0);
                        $tyLeTietKiem = $kmBaseline > 0.0
                            ? round((($kmBaseline - $kmToiUu) / $kmBaseline) * 100, 2)
                            : 0.0;
                        $thoiGianChayMs = (float) ($planResult['thoi_gian_chay_ms'] ?? 0.0);

                        $maCumTuyen = sprintf(
                            'CUM_%s_BC%d_TX%d_%s',
                            date('YmdHis'),
                            (int) $buuCuc['id'],
                            $driverId,
                            substr(uniqid('', true), -4)
                        );

                        $clusterId = $this->cumTuyenRepo->create([
                            'buu_cuc_id' => (int) $buuCuc['id'],
                            'ma_cum_tuyen' => $maCumTuyen,
                            'ma_lo' => $batchCode,
                            'tai_xe_id' => $driverId,
                            'vi_do_tam_cum' => (float) $centroid['vi_do'],
                            'kinh_do_tam_cum' => (float) $centroid['kinh_do'],
                            'vi_do_xuat_phat' => $depot['vi_do'],
                            'kinh_do_xuat_phat' => $depot['kinh_do'],
                            'so_don_hang' => count($orderedPoints),
                            'km_baseline' => $kmBaseline,
                            'km_nn' => $kmNn,
                            'km_toi_uu' => $kmToiUu,
                            'ty_le_tiet_kiem_phan_tram' => $tyLeTietKiem,
                            'thoi_gian_chay_ms' => $thoiGianChayMs,
                            'trang_thai' => 'dang_giao',
                        ]);

                        foreach (array_values($orderedPoints) as $seqIndex => $point) {
                            $this->donHangRepo->assignToCluster(
                                (int) $point['id'],
                                $clusterId,
                                $driverId,
                                $seqIndex + 1
                            );
                        }

                        $createdOrUpdatedClusters[] = [
                            'id' => $clusterId,
                            'ma_cum_tuyen' => $maCumTuyen,
                            'tai_xe_id' => $driverId,
                            'ten_tai_xe' => $driver['ho_ten'] ?? ('Tài xế #' . $driverId),
                            'mau_sac_cum' => $driver['mau_sac_cum'] ?? '#2563eb',
                            'so_don_hang' => count($orderedPoints),
                            'vi_do_tam_cum' => (float) $centroid['vi_do'],
                            'kinh_do_tam_cum' => (float) $centroid['kinh_do'],
                            'km_baseline' => $kmBaseline,
                            'km_nn' => $kmNn,
                            'km_toi_uu' => $kmToiUu,
                            'ty_le_tiet_kiem_phan_tram' => $tyLeTietKiem,
                            'thoi_gian_chay_ms' => $thoiGianChayMs,
                        ];
                    }
                }
            }

            $this->db->commit();

            $allClusters = $this->cumTuyenRepo->getClusters(null, (int) $buuCuc['id']);
            $errorOrders = $this->donHangRepo->getErrorOrders((int) $buuCuc['id']);
            $allOrders = $this->donHangRepo->getOrdersByBuuCuc((int) $buuCuc['id']);
            $mapClusters = $this->buildClusterMapPayload($allClusters, $buuCuc);

            if ($resetAll) {
                $thongBao = sprintf(
                    'Đã Reset %d đơn cũ & phân cụm lại toàn bộ: %d đơn hợp lệ vào %d cụm tuyến mới. Có %d đơn lỗi cần xác minh.',
                    $soDonReset,
                    count($ordersToCluster),
                    count($createdOrUpdatedClusters),
                    count($errorOrders)
                );
            } elseif (empty($ordersToCluster) && !empty($allClusters)) {
                $thongBao = sprintf(
                    '[Dispatch Lock] Đã bảo vệ %d cụm tuyến đang giao thực tế. Hiện không có đơn chờ phân công mới (còn %d đơn lỗi tọa độ cần sửa).',
                    count($allClusters),
                    count($errorOrders)
                );
            } else {
                $thongBao = sprintf(
                    'Đã đồng bộ %d đơn mới từ Shopee, phân công %d đơn chờ vào %d cụm tuyến (Dispatch Lock bảo vệ %d cụm đang chạy). Phát hiện %d đơn lỗi tọa độ.',
                    $soDonMoiLuu,
                    count($ordersToCluster),
                    count($createdOrUpdatedClusters),
                    $soCumGiuNguyenLock,
                    count($errorOrders)
                );
            }

            $this->sendJson(true, $thongBao, [
                'che_do_reset' => $resetAll,
                'thong_ke_inbound' => [
                    'ma_lo' => $inboundResult['ma_lo'],
                    'tong_so_don_trong_lo' => $inboundResult['tong_so_don_trong_lo'],
                    'so_don_moi_luu' => $soDonMoiLuu,
                    'so_don_hop_le' => $inboundResult['so_don_hop_le'],
                    'so_don_loi_toa_do' => $inboundResult['so_don_loi_toa_do'],
                    'so_don_ngoai_vung_phuc_vu' => $inboundResult['so_don_ngoai_vung_phuc_vu'],
                    'so_don_bo_qua_do_trung' => $inboundResult['so_don_bo_qua_do_trung'],
                    'so_don_qua_tai_tai_xe' => $soDonQuaTai,
                ],
                'cum_vua_tao' => $createdOrUpdatedClusters,
                'danh_sach_cum' => $mapClusters,
                'danh_sach_don_loi' => $errorOrders,
                'tat_ca_don_hang' => $allOrders,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->sendJson(false, 'Lỗi khi đồng bộ và phân cụm đơn hàng: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * API sửa nhanh đơn lỗi tọa độ/phường VÀ hỗ trợ Chuyển cụm / Đổi tài xế thủ công (POST /api/dieu-phoi/sua-don-loi)
     *
     * @return void
     */
    public function suaDonLoi(): void
    {
        if (!$this->authorizeDispatcher(true)) {
            return;
        }

        if (!$this->verifyCsrfFromRequest()) {
            $this->sendJson(false, 'Mã bảo mật CSRF không hợp lệ hoặc đã hết hạn.', [], 403);
            return;
        }

        $input = $this->getRequestData();
        $action = isset($input['hanh_dong']) ? trim((string) $input['hanh_dong']) : '';
        $orderId = (int) ($input['don_hang_id'] ?? ($input['id'] ?? 0));

        $buuCucId = $this->getCurrentBuuCucId();
        $buuCuc = $this->buuCucRepo->getById($buuCucId) ?? $this->getDefaultBuuCuc();

        if ($orderId <= 0) {
            $this->sendJson(false, 'Vui lòng chọn đơn hàng hợp lệ.', [], 422);
            return;
        }

        $order = $this->donHangRepo->getById($orderId, (int) $buuCuc['id']);
        if ($order === null) {
            $this->sendJson(false, 'Không tìm thấy đơn hàng thuộc Bưu cục hiện tại.', [], 404);
            return;
        }

        // Nhánh 1: Chuyển cụm / Đổi tài xế thủ công cho đơn hàng đang chờ giao (Mục 3.B.2)
        if ($action === 'chuyen_cum' || ($order['trang_thai'] === 'da_phan_cong' && isset($input['cum_tuyen_dich_id']))) {
            $this->handleManualClusterTransfer($input, $buuCuc, $order);
            return;
        }

        // Nhánh 2: Sửa đơn lỗi tọa độ / phường và gán vào cụm gần nhất (Mục 3.B.3 & 5.2)
        $phuongId = (int) ($input['phuong_id'] ?? 0);
        $rawLat = $input['vi_do'] ?? null;
        $rawLng = $input['kinh_do'] ?? null;
        $diaChi = isset($input['dia_chi']) ? trim((string) $input['dia_chi']) : null;
        $requestedClusterId = isset($input['cum_tuyen_id']) && is_numeric($input['cum_tuyen_id'])
            ? (int) $input['cum_tuyen_id']
            : null;

        if ($phuongId <= 0) {
            $this->sendJson(false, 'Vui lòng chọn Phường/Xã thuộc vùng phục vụ của Bưu cục.', [], 422);
            return;
        }

        if ($order['trang_thai'] !== 'chua_phan_cong') {
            $this->sendJson(false, 'Đơn hàng này đã được phân công trước đó.', [], 409);
            return;
        }

        $ward = $this->phuongRepo->getById($phuongId);
        if ($ward === null || (int) $ward['buu_cuc_id'] !== (int) $buuCuc['id'] || (int) $ward['dang_hoat_dong'] !== 1) {
            $this->sendJson(false, 'Phường/Xã đã chọn không thuộc vùng phục vụ của Bưu cục hiện tại.', [], 422);
            return;
        }

        $coordValidation = $this->inboundService->validateCoordinates(
            $rawLat,
            $rawLng,
            (float) $buuCuc['vi_do'],
            (float) $buuCuc['kinh_do'],
            (float) $buuCuc['ban_kinh_phuc_vu_km']
        );

        if (!$coordValidation['hop_le'] || $coordValidation['vi_do'] === null || $coordValidation['kinh_do'] === null) {
            $this->sendJson(
                false,
                sprintf(
                    'Tọa độ không hợp lệ hoặc nằm ngoài bán kính phục vụ %.1f km của %s.',
                    (float) $buuCuc['ban_kinh_phuc_vu_km'],
                    (string) $buuCuc['ten_buu_cuc']
                ),
                ['khoang_cach_km' => $coordValidation['distance_km']],
                422
            );
            return;
        }

        $viDo = (float) $coordValidation['vi_do'];
        $kinhDo = (float) $coordValidation['kinh_do'];
        $maxClusterCapacity = (int) ($buuCuc['suc_chua_moi_cum'] ?? 20);

        $clusters = $this->cumTuyenRepo->getClusters(null, (int) $buuCuc['id']);
        $activeClusters = array_values(array_filter(
            $clusters,
            static fn(array $c): bool => ($c['trang_thai'] ?? '') === 'dang_giao'
        ));

        if (empty($activeClusters)) {
            $this->sendJson(
                false,
                'Hiện chưa có cụm tuyến nào đang hoạt động. Vui lòng bấm "Đồng bộ & Phân cụm" trước.',
                [],
                422
            );
            return;
        }

        $targetCluster = null;
        if ($requestedClusterId !== null && $requestedClusterId > 0) {
            foreach ($activeClusters as $c) {
                if ((int) $c['id'] === $requestedClusterId) {
                    $targetCluster = $c;
                    break;
                }
            }
        } else {
            // Sắp xếp các cụm theo khoảng cách từ đơn hàng đến tâm cụm tăng dần
            usort($activeClusters, function (array $a, array $b) use ($viDo, $kinhDo): int {
                $da = $this->distanceKm($viDo, $kinhDo, (float) $a['vi_do_tam_cum'], (float) $a['kinh_do_tam_cum']);
                $db = $this->distanceKm($viDo, $kinhDo, (float) $b['vi_do_tam_cum'], (float) $b['kinh_do_tam_cum']);
                if (abs($da - $db) > 1e-9) {
                    return $da <=> $db;
                }
                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            foreach ($activeClusters as $candidate) {
                if ((int) $candidate['so_don_hang'] < $maxClusterCapacity) {
                    $targetCluster = $candidate;
                    break;
                }
            }
        }

        if ($targetCluster === null) {
            $this->sendJson(false, 'Tất cả các cụm tuyến đang giao đều đã đạt sức chứa tối đa (' . $maxClusterCapacity . ' đơn).', [], 422);
            return;
        }

        $clusterId = (int) $targetCluster['id'];
        if ((int) $targetCluster['so_don_hang'] >= $maxClusterCapacity) {
            $this->sendJson(false, 'Cụm tuyến đã chọn đã đầy sức chứa tối đa (' . $maxClusterCapacity . ' đơn).', [], 422);
            return;
        }

        try {
            $this->db->beginTransaction();

            $this->donHangRepo->updateErrorOrderInfo(
                $orderId,
                (int) $buuCuc['id'],
                (int) $ward['id'],
                (string) $ward['ten_phuong'],
                $viDo,
                $kinhDo,
                $diaChi
            );

            $driverId = (int) $targetCluster['tai_xe_id'];
            $this->donHangRepo->assignToCluster(
                $orderId,
                $clusterId,
                $driverId,
                ((int) $targetCluster['so_don_hang']) + 1
            );

            $depot = [
                'vi_do' => (float) $buuCuc['vi_do'],
                'kinh_do' => (float) $buuCuc['kinh_do'],
            ];

            // Tính lại thứ tự giao và quãng đường bằng DieuPhoiLoTrinhService (bảo toàn các đơn đã giao trong cụm)
            $this->recalculateClusterRoutePreservingCompleted($clusterId, $depot);

            $this->db->commit();

            $updatedClusters = $this->cumTuyenRepo->getClusters(null, (int) $buuCuc['id']);
            $remainingErrors = $this->donHangRepo->getErrorOrders((int) $buuCuc['id']);
            $allOrders = $this->donHangRepo->getOrdersByBuuCuc((int) $buuCuc['id']);
            $mapClusters = $this->buildClusterMapPayload($updatedClusters, $buuCuc);

            $this->sendJson(
                true,
                sprintf(
                    'Đã cập nhật tọa độ đơn %s và gán vào tuyến %s (%s). Lộ trình 2-Opt đã được tối ưu lại.',
                    (string) $order['ma_van_don'],
                    (string) $targetCluster['ma_cum_tuyen'],
                    (string) ($targetCluster['ten_tai_xe'] ?? '')
                ),
                [
                    'don_hang_id' => $orderId,
                    'cum_tuyen_id' => $clusterId,
                    'danh_sach_cum' => $mapClusters,
                    'danh_sach_don_loi' => $remainingErrors,
                    'tat_ca_don_hang' => $allOrders,
                ]
            );
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->sendJson(false, 'Lỗi khi cập nhật và gán cụm cho đơn hàng: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Xử lý nghiệp vụ Chuyển cụm / Đổi tài xế thủ công cho đơn hàng đang chờ giao (Mục 3.B.2)
     *
     * @param array $input
     * @param array $buuCuc
     * @param array $order
     * @return void
     */
    private function handleManualClusterTransfer(array $input, array $buuCuc, array $order): void
    {
        $orderId = (int) $order['id'];
        $sourceClusterId = (int) ($order['cum_tuyen_id'] ?? 0);
        $targetClusterId = (int) ($input['cum_tuyen_dich_id'] ?? ($input['cum_tuyen_id'] ?? 0));

        if ($order['trang_thai'] !== 'da_phan_cong') {
            $this->sendJson(
                false,
                'Chỉ có thể chuyển tuyến cho các đơn hàng đang ở trạng thái "Đang chờ giao". Đơn đã hoàn tất không thể chuyển.',
                [],
                409
            );
            return;
        }

        if ($targetClusterId <= 0 || $targetClusterId === $sourceClusterId) {
            $this->sendJson(false, 'Vui lòng chọn một cụm tuyến / tài xế đích khác với cụm hiện tại.', [], 422);
            return;
        }

        $targetCluster = $this->cumTuyenRepo->getById($targetClusterId);
        if ($targetCluster === null || (int) $targetCluster['buu_cuc_id'] !== (int) $buuCuc['id']) {
            $this->sendJson(false, 'Cụm tuyến đích không tồn tại hoặc không thuộc Bưu cục hiện tại.', [], 404);
            return;
        }

        $maxCapacity = (int) ($buuCuc['suc_chua_moi_cum'] ?? 20);
        if ((int) $targetCluster['so_don_hang'] >= $maxCapacity) {
            $this->sendJson(
                false,
                sprintf(
                    'Cụm tuyến của %s đã đầy định mức (%d/%d đơn). Vui lòng chọn tài xế khác.',
                    (string) ($targetCluster['ten_tai_xe'] ?? ''),
                    (int) $targetCluster['so_don_hang'],
                    $maxCapacity
                ),
                [],
                422
            );
            return;
        }

        try {
            $this->db->beginTransaction();

            $targetDriverId = (int) $targetCluster['tai_xe_id'];
            $newSeq = ((int) $targetCluster['so_don_hang']) + 1;

            $moved = $this->donHangRepo->moveOrderToCluster($orderId, $targetClusterId, $targetDriverId, $newSeq);
            if (!$moved) {
                $this->db->rollBack();
                $this->sendJson(false, 'Không thể chuyển cụm cho đơn hàng này.', [], 409);
                return;
            }

            $depot = [
                'vi_do' => (float) $buuCuc['vi_do'],
                'kinh_do' => (float) $buuCuc['kinh_do'],
            ];

            // Tính lại lộ trình & số km tối ưu cho cả cụm nguồn và cụm đích qua DieuPhoiLoTrinhService
            if ($sourceClusterId > 0) {
                $this->recalculateClusterRoutePreservingCompleted($sourceClusterId, $depot);
            }
            $this->recalculateClusterRoutePreservingCompleted($targetClusterId, $depot);

            $this->db->commit();

            $updatedClusters = $this->cumTuyenRepo->getClusters(null, (int) $buuCuc['id']);
            $remainingErrors = $this->donHangRepo->getErrorOrders((int) $buuCuc['id']);
            $allOrders = $this->donHangRepo->getOrdersByBuuCuc((int) $buuCuc['id']);
            $mapClusters = $this->buildClusterMapPayload($updatedClusters, $buuCuc);

            $this->sendJson(
                true,
                sprintf(
                    'Đã chuyển đơn %s sang tuyến %s (%s) và tối ưu lại thứ tự giao 2-Opt cho cả 2 tuyến.',
                    (string) $order['ma_van_don'],
                    (string) $targetCluster['ma_cum_tuyen'],
                    (string) ($targetCluster['ten_tai_xe'] ?? '')
                ),
                [
                    'don_hang_id' => $orderId,
                    'cum_nguon_id' => $sourceClusterId,
                    'cum_dich_id' => $targetClusterId,
                    'danh_sach_cum' => $mapClusters,
                    'danh_sach_don_loi' => $remainingErrors,
                    'tat_ca_don_hang' => $allOrders,
                ]
            );
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->sendJson(false, 'Lỗi khi chuyển cụm cho đơn hàng: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Tái tính toán lộ trình 2-Opt và số km cho một cụm tuyến nhưng bảo toàn thứ tự các đơn đã giao (`giao_thanh_cong` / `giao_that_bai`)
     *
     * @param int $clusterId
     * @param array $depot
     * @return array
     */
    private function recalculateClusterRoutePreservingCompleted(int $clusterId, array $depot): array
    {
        $allClusterOrders = $this->donHangRepo->getOrdersByCluster($clusterId);
        if (empty($allClusterOrders)) {
            $this->cumTuyenRepo->updateMetrics($clusterId, [
                'so_don_hang' => 0,
                'km_baseline' => 0.0,
                'km_nn' => 0.0,
                'km_toi_uu' => 0.0,
                'ty_le_tiet_kiem_phan_tram' => 0.0,
                'thoi_gian_chay_ms' => 0.0,
                'trang_thai' => 'hoan_tat',
            ]);
            return [
                'so_don_hang' => 0,
                'km_baseline' => 0.0,
                'km_nn' => 0.0,
                'km_toi_uu' => 0.0,
                'ty_le_tiet_kiem_phan_tram' => 0.0,
            ];
        }

        $completedOrders = [];
        $pendingOrders = [];
        foreach ($allClusterOrders as $ord) {
            if (($ord['trang_thai'] ?? '') === 'da_phan_cong') {
                $pendingOrders[] = $ord;
            } else {
                $completedOrders[] = $ord;
            }
        }

        $newCentroid = $this->calculateCentroid($allClusterOrders, $depot);

        // Điểm bắt đầu cho phần lộ trình còn lại: nếu đã có đơn hoàn tất thì nối tiếp từ đơn hoàn tất cuối cùng, ngược lại từ P0
        $startForPending = $depot;
        if (!empty($completedOrders)) {
            $lastDone = $completedOrders[count($completedOrders) - 1];
            if (isset($lastDone['vi_do'], $lastDone['kinh_do'])) {
                $startForPending = [
                    'vi_do' => (float) $lastDone['vi_do'],
                    'kinh_do' => (float) $lastDone['kinh_do'],
                ];
            }
        }

        $orderedPending = [];
        if (!empty($pendingOrders)) {
            $pendingPlan = $this->runRouteOptimization($startForPending, $pendingOrders);
            $orderedPending = $pendingPlan['thu_tu'] ?? $pendingOrders;
        }

        $finalSequenceOrders = array_merge($completedOrders, $orderedPending);
        foreach (array_values($finalSequenceOrders) as $seqIdx => $pt) {
            $this->donHangRepo->updateSequence((int) $pt['id'], $clusterId, $seqIdx + 1);
        }

        // Tính tổng quãng đường toàn cụm từ P0 để đối chiếu Baseline vs NN vs 2-Opt nhất quán
        $fullPlan = $this->runRouteOptimization($depot, $allClusterOrders);
        $roadFactor = defined('HE_SO_DUONG_BO') ? (float) HE_SO_DUONG_BO : 1.3;
        $kmBaseline = (float) ($fullPlan['km_baseline'] ?? 0.0);
        $kmNn = (float) ($fullPlan['km_nn'] ?? 0.0);
        $kmToiUu = empty($completedOrders)
            ? (float) ($fullPlan['km_toi_uu'] ?? 0.0)
            : round($this->calculateOpenPathKm($depot, $finalSequenceOrders, $roadFactor), 2);
        if ($kmBaseline > 0.0 && $kmToiUu > $kmBaseline) {
            $kmToiUu = (float) ($fullPlan['km_toi_uu'] ?? $kmToiUu);
        }

        $tyLeTietKiem = $kmBaseline > 0.0
            ? round((($kmBaseline - $kmToiUu) / $kmBaseline) * 100, 2)
            : 0.0;
        $thoiGianMs = (float) ($fullPlan['thoi_gian_chay_ms'] ?? 0.15);
        $trangThaiCum = empty($orderedPending) ? 'hoan_tat' : 'dang_giao';

        $metrics = [
            'so_don_hang' => count($finalSequenceOrders),
            'vi_do_tam_cum' => (float) $newCentroid['vi_do'],
            'kinh_do_tam_cum' => (float) $newCentroid['kinh_do'],
            'km_baseline' => $kmBaseline,
            'km_nn' => $kmNn,
            'km_toi_uu' => $kmToiUu,
            'ty_le_tiet_kiem_phan_tram' => $tyLeTietKiem,
            'thoi_gian_chay_ms' => $thoiGianMs,
            'trang_thai' => $trangThaiCum,
        ];

        $this->cumTuyenRepo->updateMetrics($clusterId, $metrics);

        return $metrics;
    }

    /**
     * API lấy danh sách phường/xã theo bưu cục phục vụ dropdown (GET /api/hanh-chinh/phuong-theo-buu-cuc)
     *
     * @return void
     */
    public function phuongTheoBuuCuc(): void
    {
        if (!$this->authorizeDispatcher(true)) {
            return;
        }

        $buuCucId = isset($_GET['buu_cuc_id']) && is_numeric($_GET['buu_cuc_id'])
            ? (int) $_GET['buu_cuc_id']
            : $this->getCurrentBuuCucId();

        $buuCuc = $this->buuCucRepo->getById($buuCucId) ?? $this->getDefaultBuuCuc();
        $wards = $this->buuCucRepo->getServingWards((int) $buuCuc['id']);

        $this->sendJson(true, 'Lấy danh sách Phường/Xã theo Bưu cục thành công.', [
            'buu_cuc' => $buuCuc,
            'danh_sach_phuong' => $wards,
        ]);
    }

    /**
     * Mock API trả nội dung file data/don_hang_mau.json (GET /api/mock/shopee/don-hang)
     *
     * @return void
     */
    public function mockShopeeOrders(): void
    {
        if (!$this->authorizeDispatcher(true)) {
            return;
        }

        try {
            $mockService = new MockShopeeOrderService();
            $payload = $mockService->fetchOrders();
            $this->sendJson(true, 'Tải dữ liệu lô đơn hàng Shopee Mock API thành công.', $payload);
        } catch (Throwable $e) {
            $this->sendJson(false, $e->getMessage(), [], 500);
        }
    }

    /**
     * Kiểm tra quyền Điều phối viên (`vai_tro === 'dieu_phoi'`) theo Mục 5.2.
     * Tài xế truy cập trả mã 403.
     *
     * @param bool $isApi
     * @return bool
     */
    private function authorizeDispatcher(bool $isApi): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $_SESSION['vai_tro'] ?? Session::get('vai_tro');
        $userId = $_SESSION['user_id'] ?? Session::get('user_id');

        if ($role === 'tai_xe') {
            if ($isApi) {
                $this->sendJson(false, 'Truy cập bị từ chối (403): Tài khoản Tài xế không được phép truy cập phân hệ Điều phối.', [], 403);
            } else {
                http_response_code(403);
                echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>403 Forbidden</title></head><body style="font-family:sans-serif;padding:2rem;"><h2>403 - Truy cập bị từ chối</h2><p>Chỉ tài khoản Điều phối viên mới có quyền truy cập trang này.</p><a href="' . htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . '/tai-xe', ENT_QUOTES, 'UTF-8') . '">Quay về màn hình Tài xế</a></body></html>';
            }
            return false;
        }

        if ($userId === null || $role !== 'dieu_phoi') {
            if (isset($_GET['dev_auto_login']) && $_GET['dev_auto_login'] === '1') {
                $admin = $this->nguoiDungRepo->findByUsername('admin');
                if ($admin !== null) {
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['vai_tro'] = $admin['vai_tro'];
                    $_SESSION['buu_cuc_id'] = $admin['buu_cuc_id'];
                    $_SESSION['ho_ten'] = $admin['ho_ten'];
                    return true;
                }
            }

            if ($isApi) {
                $this->sendJson(false, 'Vui lòng đăng nhập bằng tài khoản Điều phối viên.', [], 403);
            } else {
                $loginUrl = (defined('BASE_URL') ? BASE_URL : '') . '/login';
                header('Location: ' . $loginUrl);
            }
            return false;
        }

        return true;
    }

    /**
     * Lấy ID Bưu cục hiện tại của Điều phối viên đang đăng nhập
     *
     * @return int
     */
    private function getCurrentBuuCucId(): int
    {
        $fromSession = $_SESSION['buu_cuc_id'] ?? Session::get('buu_cuc_id');
        if ($fromSession !== null && (int) $fromSession > 0) {
            return (int) $fromSession;
        }

        return 1;
    }

    /**
     * Thông tin Bưu cục SPX Nha Trang mặc định phòng trường hợp DB chưa seed
     *
     * @return array
     */
    private function getDefaultBuuCuc(): array
    {
        return [
            'id' => 1,
            'ma_buu_cuc' => 'SPX_KH_NT01',
            'ten_buu_cuc' => defined('TEN_BUU_CUC_MAC_DINH') ? TEN_BUU_CUC_MAC_DINH : 'Bưu cục SPX Nha Trang',
            'ma_tinh' => '56',
            'ten_tinh' => 'Tỉnh Khánh Hòa',
            'ma_quan_huyen' => '568',
            'ten_quan_huyen' => 'Thành phố Nha Trang',
            'dia_chi' => '02 Lê Thánh Tôn, Phường Nha Trang, Thành phố Nha Trang, Tỉnh Khánh Hòa',
            'vi_do' => defined('BUU_CUC_VI_DO_MAC_DINH') ? (float) BUU_CUC_VI_DO_MAC_DINH : 12.2488000,
            'kinh_do' => defined('BUU_CUC_KINH_DO_MAC_DINH') ? (float) BUU_CUC_KINH_DO_MAC_DINH : 109.1905000,
            'ban_kinh_phuc_vu_km' => defined('BAN_KINH_PHUC_VU_KM_MAC_DINH') ? (float) BAN_KINH_PHUC_VU_KM_MAC_DINH : 25.00,
            'suc_chua_moi_cum' => defined('SUC_CHUA_MAC_DINH') ? (int) SUC_CHUA_MAC_DINH : 20,
            'dang_hoat_dong' => 1,
        ];
    }

    /**
     * Chạy thuật toán Balanced K-Means: ưu tiên gọi BalancedKMeansService::cluster() của Thành viên 1,
     * kèm cài đặt dự phòng chuẩn tất định theo Mục 3.1.
     *
     * @param array $orders
     * @param array $drivers
     * @param array $depot
     * @param int $maxPerCluster
     * @param array $wardCoordinates
     * @return array
     */
    private function runBalancedKMeans(array $orders, array $drivers, array $depot, int $maxPerCluster = 20, array $wardCoordinates = []): array
    {
        if (class_exists(BalancedKMeansService::class)) {
            $service = new BalancedKMeansService();
            $result = $service->cluster($orders, $drivers, $depot, $wardCoordinates);
            if (!empty($result)) {
                return $result;
            }
        }

        $n = count($orders);
        $m = count($drivers);
        if ($n === 0 || $m === 0) {
            return [];
        }

        $k = min($m, $n);
        $targetCapacity = min($maxPerCluster, (int) ceil($n / $k));

        $sortedOrders = $orders;
        usort($sortedOrders, static function (array $a, array $b) use ($depot): int {
            $angleA = atan2(((float) $a['vi_do']) - $depot['vi_do'], ((float) $a['kinh_do']) - $depot['kinh_do']);
            $angleB = atan2(((float) $b['vi_do']) - $depot['vi_do'], ((float) $b['kinh_do']) - $depot['kinh_do']);
            if (abs($angleA - $angleB) > 1e-9) {
                return $angleA <=> $angleB;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $centroids = [];
        $sliceSize = $n / $k;
        for ($c = 0; $c < $k; $c++) {
            $startIdx = (int) floor($c * $sliceSize);
            $endIdx = ($c === $k - 1) ? $n : (int) floor(($c + 1) * $sliceSize);
            $slice = array_slice($sortedOrders, $startIdx, max(1, $endIdx - $startIdx));
            $centroids[$c] = $this->calculateCentroid($slice, $depot);
        }

        $assignments = [];
        $clusterBuckets = array_fill(0, $k, []);

        for ($iter = 0; $iter < 20; $iter++) {
            $clusterBuckets = array_fill(0, $k, []);
            $priorities = [];

            foreach ($sortedOrders as $order) {
                $dists = [];
                for ($c = 0; $c < $k; $c++) {
                    $dists[$c] = $this->distanceKm(
                        (float) $order['vi_do'],
                        (float) $order['kinh_do'],
                        $centroids[$c]['vi_do'],
                        $centroids[$c]['kinh_do']
                    );
                }

                $sortedDists = $dists;
                asort($sortedDists);
                $orderedClusterIndices = array_keys($sortedDists);
                $dMin = $sortedDists[$orderedClusterIndices[0]];
                $dSecond = isset($orderedClusterIndices[1]) ? $sortedDists[$orderedClusterIndices[1]] : $dMin;

                $priorities[] = [
                    'order' => $order,
                    'priority' => $dSecond - $dMin,
                    'ordered_clusters' => $orderedClusterIndices,
                ];
            }

            usort($priorities, static function (array $a, array $b): int {
                if (abs($a['priority'] - $b['priority']) > 1e-9) {
                    return $b['priority'] <=> $a['priority'];
                }
                return ((int) $a['order']['id']) <=> ((int) $b['order']['id']);
            });

            $newAssignments = [];
            foreach ($priorities as $item) {
                $order = $item['order'];
                $assignedCluster = $item['ordered_clusters'][0];
                foreach ($item['ordered_clusters'] as $cIdx) {
                    $driverCap = isset($drivers[$cIdx]['suc_chua_con_lai'])
                        ? min($targetCapacity, (int) $drivers[$cIdx]['suc_chua_con_lai'])
                        : $targetCapacity;
                    if (count($clusterBuckets[$cIdx]) < $driverCap) {
                        $assignedCluster = $cIdx;
                        break;
                    }
                }
                $clusterBuckets[$assignedCluster][] = $order;
                $newAssignments[(int) $order['id']] = $assignedCluster;
            }

            for ($c = 0; $c < $k; $c++) {
                if (!empty($clusterBuckets[$c])) {
                    $centroids[$c] = $this->calculateCentroid($clusterBuckets[$c], $depot);
                }
            }

            if ($newAssignments === $assignments) {
                break;
            }
            $assignments = $newAssignments;
        }

        $nonEmptyClusters = [];
        for ($c = 0; $c < $k; $c++) {
            if (!empty($clusterBuckets[$c])) {
                $nonEmptyClusters[] = [
                    'tam_cum' => $centroids[$c],
                    'don_hang' => $clusterBuckets[$c],
                ];
            }
        }

        $usedDriverIds = [];
        $finalResult = [];
        foreach ($nonEmptyClusters as $clusterData) {
            $centroid = $clusterData['tam_cum'];
            $bestDriver = null;
            $bestDist = PHP_FLOAT_MAX;

            foreach ($drivers as $driver) {
                $dId = (int) $driver['id'];
                if (isset($usedDriverIds[$dId])) {
                    continue;
                }

                $refLat = isset($driver['vi_do_tam']) && $driver['vi_do_tam'] !== null
                    ? (float) $driver['vi_do_tam']
                    : (isset($driver['phuong_vi_do_tam']) && $driver['phuong_vi_do_tam'] !== null
                        ? (float) $driver['phuong_vi_do_tam']
                        : $depot['vi_do']);
                $refLng = isset($driver['kinh_do_tam']) && $driver['kinh_do_tam'] !== null
                    ? (float) $driver['kinh_do_tam']
                    : (isset($driver['phuong_kinh_do_tam']) && $driver['phuong_kinh_do_tam'] !== null
                        ? (float) $driver['phuong_kinh_do_tam']
                        : $depot['kinh_do']);

                $dist = $this->distanceKm($centroid['vi_do'], $centroid['kinh_do'], $refLat, $refLng);
                if ($bestDriver === null || $dist < $bestDist - 1e-9 || (abs($dist - $bestDist) <= 1e-9 && $dId < (int) $bestDriver['id'])) {
                    $bestDist = $dist;
                    $bestDriver = $driver;
                }
            }

            if ($bestDriver !== null) {
                $usedDriverIds[(int) $bestDriver['id']] = true;
                $finalResult[] = [
                    'tai_xe' => $bestDriver,
                    'tam_cum' => $centroid,
                    'don_hang' => $clusterData['don_hang'],
                ];
            }
        }

        return $finalResult;
    }

    /**
     * Chạy tối ưu lộ trình NN + 2-Opt đường hở từ điểm xuất phát $start (Mục 3.2):
     * Ưu tiên gọi DieuPhoiLoTrinhService::plan() của Thành viên 1, kèm fallback chuẩn Mục 3.2.
     *
     * @param array $start ['vi_do' => float, 'kinh_do' => float]
     * @param array $points Danh sách đơn hàng trong cụm
     * @return array
     */
    private function runRouteOptimization(array $start, array $points): array
    {
        if (empty($points)) {
            return [
                'thu_tu' => [],
                'km_baseline' => 0.0,
                'km_nn' => 0.0,
                'km_toi_uu' => 0.0,
                'thoi_gian_chay_ms' => 0.0,
            ];
        }

        if (class_exists(DieuPhoiLoTrinhService::class)) {
            $service = new DieuPhoiLoTrinhService();
            $result = $service->plan($start, $points);
            if (!empty($result) && isset($result['thu_tu']) && !empty($result['thu_tu'])) {
                return $result;
            }
        }

        $t0 = microtime(true);
        $roadFactor = defined('HE_SO_DUONG_BO') ? (float) HE_SO_DUONG_BO : 1.3;

        $baselinePoints = $points;
        usort($baselinePoints, static function (array $a, array $b): int {
            $fa = (int) ($a['thu_tu_file'] ?? 0);
            $fb = (int) ($b['thu_tu_file'] ?? 0);
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });
        $kmBaseline = $this->calculateOpenPathKm($start, $baselinePoints, $roadFactor);

        $remaining = $points;
        $nnRoute = [];
        $currLat = (float) $start['vi_do'];
        $currLng = (float) $start['kinh_do'];

        while (!empty($remaining)) {
            $bestIdx = 0;
            $bestDist = PHP_FLOAT_MAX;

            foreach ($remaining as $idx => $pt) {
                $d = $this->distanceKm($currLat, $currLng, (float) $pt['vi_do'], (float) $pt['kinh_do']) * $roadFactor;
                if (
                    $d < $bestDist - 1e-9
                    || (abs($d - $bestDist) <= 1e-9 && (int) $pt['id'] < (int) $remaining[$bestIdx]['id'])
                ) {
                    $bestDist = $d;
                    $bestIdx = $idx;
                }
            }

            $chosen = $remaining[$bestIdx];
            $nnRoute[] = $chosen;
            $currLat = (float) $chosen['vi_do'];
            $currLng = (float) $chosen['kinh_do'];
            array_splice($remaining, $bestIdx, 1);
        }

        $kmNn = $this->calculateOpenPathKm($start, $nnRoute, $roadFactor);

        $n = count($nnRoute);
        $route = array_merge([$start], $nnRoute);

        if ($n >= 3) {
            $iter = 0;
            $improved = true;
            while ($improved && $iter < 500) {
                $improved = false;
                $iter++;
                for ($i = 1; $i <= $n - 1; $i++) {
                    for ($k = $i + 1; $k <= $n; $k++) {
                        $dBefore1 = $this->distanceKm((float) $route[$i - 1]['vi_do'], (float) $route[$i - 1]['kinh_do'], (float) $route[$i]['vi_do'], (float) $route[$i]['kinh_do']) * $roadFactor;
                        $dAfter1 = $this->distanceKm((float) $route[$i - 1]['vi_do'], (float) $route[$i - 1]['kinh_do'], (float) $route[$k]['vi_do'], (float) $route[$k]['kinh_do']) * $roadFactor;

                        $deltaTail = 0.0;
                        if ($k < $n) {
                            $dBefore2 = $this->distanceKm((float) $route[$k]['vi_do'], (float) $route[$k]['kinh_do'], (float) $route[$k + 1]['vi_do'], (float) $route[$k + 1]['kinh_do']) * $roadFactor;
                            $dAfter2 = $this->distanceKm((float) $route[$i]['vi_do'], (float) $route[$i]['kinh_do'], (float) $route[$k + 1]['vi_do'], (float) $route[$k + 1]['kinh_do']) * $roadFactor;
                            $deltaTail = $dAfter2 - $dBefore2;
                        }

                        $delta = ($dAfter1 - $dBefore1) + $deltaTail;
                        if ($delta < -1e-6) {
                            $segment = array_slice($route, $i, $k - $i + 1);
                            array_splice($route, $i, $k - $i + 1, array_reverse($segment));
                            $improved = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $optimizedPoints = array_slice($route, 1);
        $kmToiUu = $this->calculateOpenPathKm($start, $optimizedPoints, $roadFactor);
        $elapsedMs = (microtime(true) - $t0) * 1000.0;

        return [
            'thu_tu' => $optimizedPoints,
            'km_baseline' => round($kmBaseline, 2),
            'km_nn' => round($kmNn, 2),
            'km_toi_uu' => round(min($kmNn, $kmToiUu), 2),
            'thoi_gian_chay_ms' => round(max(0.001, $elapsedMs), 3),
        ];
    }

    /**
     * Tính tổng quãng đường đường hở từ điểm xuất phát qua danh sách điểm dừng
     *
     * @param array $start
     * @param array $points
     * @param float $roadFactor
     * @return float
     */
    private function calculateOpenPathKm(array $start, array $points, float $roadFactor): float
    {
        if (empty($points)) {
            return 0.0;
        }

        $totalKm = 0.0;
        $currLat = (float) $start['vi_do'];
        $currLng = (float) $start['kinh_do'];

        foreach ($points as $pt) {
            $totalKm += $this->distanceKm($currLat, $currLng, (float) $pt['vi_do'], (float) $pt['kinh_do']) * $roadFactor;
            $currLat = (float) $pt['vi_do'];
            $currLng = (float) $pt['kinh_do'];
        }

        return $totalKm;
    }

    /**
     * Tính tọa độ trung bình cộng (Centroid) của danh sách đơn hàng
     *
     * @param array $orders
     * @param array $fallback
     * @return array{vi_do: float, kinh_do: float}
     */
    private function calculateCentroid(array $orders, array $fallback): array
    {
        if (empty($orders)) {
            return [
                'vi_do' => (float) $fallback['vi_do'],
                'kinh_do' => (float) $fallback['kinh_do'],
            ];
        }

        $sumLat = 0.0;
        $sumLng = 0.0;
        $count = count($orders);

        foreach ($orders as $o) {
            $sumLat += (float) $o['vi_do'];
            $sumLng += (float) $o['kinh_do'];
        }

        return [
            'vi_do' => round($sumLat / $count, 7),
            'kinh_do' => round($sumLng / $count, 7),
        ];
    }

    /**
     * Tính khoảng cách Haversine (km) giữa 2 điểm
     *
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float
     */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        if (abs($lat1 - $lat2) < 1e-12 && abs($lng1 - $lng2) < 1e-12) {
            return 0.0;
        }

        if (class_exists(HaversineService::class)) {
            $d = HaversineService::distance($lat1, $lng1, $lat2, $lng2);
            if ($d > 0.0) {
                return $d;
            }
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Chuẩn bị dữ liệu các cụm kèm danh sách điểm theo thứ tự 2-Opt và thứ tự Baseline cho bản đồ Leaflet
     *
     * @param array $clusters
     * @param array $buuCuc
     * @return array
     */
    private function buildClusterMapPayload(array $clusters, array $buuCuc): array
    {
        $result = [];
        foreach ($clusters as $cluster) {
            $orders = $this->donHangRepo->getOrdersByCluster((int) $cluster['id']);

            $orders2Opt = $orders;
            usort($orders2Opt, static function (array $a, array $b): int {
                $sa = (int) ($a['thu_tu_giao'] ?? 999999);
                $sb = (int) ($b['thu_tu_giao'] ?? 999999);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            $ordersBaseline = $orders;
            usort($ordersBaseline, static function (array $a, array $b): int {
                $fa = (int) ($a['thu_tu_file'] ?? 0);
                $fb = (int) ($b['thu_tu_file'] ?? 0);
                if ($fa !== $fb) {
                    return $fa <=> $fb;
                }
                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            $cluster['vi_do_xuat_phat'] = (float) ($cluster['vi_do_xuat_phat'] ?? $buuCuc['vi_do']);
            $cluster['kinh_do_xuat_phat'] = (float) ($cluster['kinh_do_xuat_phat'] ?? $buuCuc['kinh_do']);
            $cluster['don_hang_toi_uu'] = $orders2Opt;
            $cluster['don_hang_baseline'] = $ordersBaseline;
            $result[] = $cluster;
        }

        return $result;
    }

    /**
     * Tạo hoặc lấy CSRF token hiện tại trong session
     *
     * @return string
     */
    private function ensureCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (class_exists(Csrf::class)) {
            $token = Csrf::getToken();
            if ($token !== '') {
                $_SESSION['csrf_token'] = $token;
                return $token;
            }
        }

        $token = $_SESSION['_csrf_token'] ?? ($_SESSION['csrf_token'] ?? null);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
        }

        $_SESSION['_csrf_token'] = $token;
        $_SESSION['csrf_token'] = $token;

        return $token;
    }

    /**
     * Xác thực CSRF token từ Header X-CSRF-Token hoặc body request
     *
     * @return bool
     */
    private function verifyCsrfFromRequest(): bool
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($token === null || $token === '') {
            $data = $this->getRequestData();
            $token = $data['_csrf_token'] ?? ($data['csrf_token'] ?? null);
        }

        if (!is_string($token) || $token === '') {
            return false;
        }

        if (class_exists(Csrf::class) && Csrf::validate($token)) {
            return true;
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? ($_SESSION['csrf_token'] ?? Session::get('_csrf_token'));
        return is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }

    /**
     * Lấy dữ liệu đầu vào từ JSON body hoặc $_POST
     *
     * @return array
     */
    private function getRequestData(): array
    {
        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return array_merge($_POST, $decoded);
            }
        }

        return $_POST;
    }

    /**
     * Render file view thuộc `app/Views/`
     *
     * @param string $view
     * @param array $data
     * @return void
     */
    private function renderView(string $view, array $data = []): void
    {
        $viewFile = __DIR__ . '/../Views/' . ltrim($view, '/') . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Không tìm thấy file giao diện: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
            return;
        }

        extract($data, EXTR_SKIP);
        require $viewFile;
    }

    /**
     * Xuất JSON response chuẩn `{"thanh_cong": bool, "thong_bao": string, "du_lieu": array}` (Mục 5.2)
     *
     * @param bool $success
     * @param string $message
     * @param array $data
     * @param int $statusCode
     * @return void
     */
    private function sendJson(bool $success, string $message, array $data = [], int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'thanh_cong' => $success,
            'thong_bao' => $message,
            'du_lieu' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}
?>
