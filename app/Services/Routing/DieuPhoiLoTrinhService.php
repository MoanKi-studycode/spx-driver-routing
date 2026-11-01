<?php

declare(strict_types=1);

namespace App\Services\Routing;

/**
 * Class DieuPhoiLoTrinhService
 * 
 * Điều phối quy trình tối ưu hóa lộ trình tổng thể:
 * 1. Đo đạc Baseline (quãng đường gốc theo thứ tự trong file/CSDL).
 * 2. Lập lộ trình tham lam ban đầu bằng Nearest Neighbor (NN).
 * 3. Tối ưu triệt tiêu các đoạn cắt chéo bằng 2-Opt đường hở.
 * 4. Đo đạc tỷ lệ tiết kiệm quãng đường và thời gian thực thi thuật toán (ms).
 */
class DieuPhoiLoTrinhService
{
    private NearestNeighborSolver $nnSolver;
    private TwoOptSolver $twoOptSolver;

    /**
     * Khởi tạo service với các bộ giải thuật toán
     */
    public function __construct(
        ?NearestNeighborSolver $nnSolver = null,
        ?TwoOptSolver $twoOptSolver = null
    ) {
        $this->nnSolver = $nnSolver ?? new NearestNeighborSolver();
        $this->twoOptSolver = $twoOptSolver ?? new TwoOptSolver();
    }

    /**
     * Lập lộ trình tối ưu và tính toán số liệu so sánh chi tiết
     *
     * @param array{vi_do: float, kinh_do: float} $start Điểm xuất phát (Bưu cục P0 hoặc GPS tài xế)
     * @param array<int, array{id?: int|string, vi_do: float, kinh_do: float, ma_van_don?: string}> $points 
     *        Danh sách các đơn hàng cần lập lộ trình trong cụm
     * @return array{
     *     thu_tu: array,
     *     km_baseline: float,
     *     km_nn: float,
     *     km_toi_uu: float,
     *     ty_le_tiet_kiem_phan_tram: float,
     *     thoi_gian_chay_ms: float
     * }
     */
    public function plan(array $start, array $points): array
    {
        $startTime = hrtime(true);
        $pointList = array_values($points);
        usort($pointList, static function (array $a, array $b): int {
            $fileDiff = ((int) ($a['thu_tu_file'] ?? 0)) <=> ((int) ($b['thu_tu_file'] ?? 0));
            if ($fileDiff !== 0) {
                return $fileDiff;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $n = count($pointList);

        // Trường hợp không có đơn hàng nào
        if ($n === 0) {
            $elapsedMs = (hrtime(true) - $startTime) / 1e6;
            return [
                'thu_tu' => [],
                'km_baseline' => 0.0,
                'km_nn' => 0.0,
                'km_toi_uu' => 0.0,
                'ty_le_tiet_kiem_phan_tram' => 0.0,
                'thoi_gian_chay_ms' => round($elapsedMs, 2),
            ];
        }

        // Trường hợp chỉ có đúng 1 đơn hàng
        if ($n === 1) {
            $singleOrder = $pointList[0];
            $singleOrder['thu_tu_giao'] = 1;
            $dist = HaversineService::roadDistance(
                (float) $start['vi_do'],
                (float) $start['kinh_do'],
                (float) $singleOrder['vi_do'],
                (float) $singleOrder['kinh_do']
            );
            $distRounded = round($dist, 2);

            $elapsedMs = (hrtime(true) - $startTime) / 1e6;
            return [
                'thu_tu' => [$singleOrder],
                'km_baseline' => $distRounded,
                'km_nn' => $distRounded,
                'km_toi_uu' => $distRounded,
                'ty_le_tiet_kiem_phan_tram' => 0.0,
                'thoi_gian_chay_ms' => round($elapsedMs, 2),
            ];
        }

        // 1. Tính quãng đường Baseline (thứ tự xuất hiện gốc)
        $baselineRouteWithStart = array_merge([$start], $pointList);
        $kmBaseline = $this->twoOptSolver->calculateTotalDistance($baselineRouteWithStart);

        // 2. Chạy thuật toán tham lam Nearest Neighbor (NN)
        $nnOrderedPoints = $this->nnSolver->solve($start, $pointList);
        $nnRouteWithStart = array_merge([$start], $nnOrderedPoints);
        $kmNN = $this->twoOptSolver->calculateTotalDistance($nnRouteWithStart);

        // 3. Chạy thuật toán cải tiến 2-Opt trên kết quả của Nearest Neighbor
        $optimizedRouteWithStart = $this->twoOptSolver->improve($nnRouteWithStart);
        $kmToiUu = $this->twoOptSolver->calculateTotalDistance($optimizedRouteWithStart);

        // 4. Lấy danh sách đơn hàng đã tối ưu (loại bỏ điểm xuất phát S ở index 0)
        $finalOrders = array_values(array_slice($optimizedRouteWithStart, 1));
        foreach ($finalOrders as $index => &$order) {
            $order['thu_tu_giao'] = $index + 1;
        }
        unset($order);

        // 5. Tính tỷ lệ tiết kiệm so với Baseline (%)
        $savingsPercent = 0.0;
        if ($kmBaseline > 1e-6) {
            $diff = $kmBaseline - $kmToiUu;
            if ($diff > 0.0) {
                $savingsPercent = ($diff / $kmBaseline) * 100.0;
            }
        }

        $elapsedMs = (hrtime(true) - $startTime) / 1e6;

        return [
            'thu_tu' => $finalOrders,
            'km_baseline' => round($kmBaseline, 2),
            'km_nn' => round($kmNN, 2),
            'km_toi_uu' => round($kmToiUu, 2),
            'ty_le_tiet_kiem_phan_tram' => round($savingsPercent, 2),
            'thoi_gian_chay_ms' => round($elapsedMs, 2),
        ];
    }
}
