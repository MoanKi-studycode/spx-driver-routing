<?php

declare(strict_types=1);

namespace App\Services\Routing;

/**
 * Class TwoOptSolver
 * 
 * Tối ưu hóa cục bộ lộ trình đường hở (Open-Path 2-Opt)
 * Cố định điểm xuất phát S = route[0], tự do điểm giao cuối cùng route[n].
 * Triệt tiêu các đoạn cắt chéo để giảm tổng quãng đường.
 */
class TwoOptSolver
{
    /**
     * Ngưỡng cải thiện tối thiểu để thực hiện phép đảo (Delta < -10^-6 km)
     */
    private const IMPROVEMENT_THRESHOLD = -1e-6;

    /**
     * Giới hạn an toàn tối đa số vòng lặp tối ưu
     */
    private const MAX_ITERATIONS = 500;

    /**
     * Cải tiến lộ trình đường hở bằng thuật toán 2-Opt
     *
     * @param array<int, array{vi_do: float, kinh_do: float, id?: int|string}> $route 
     *        Lộ trình đầy đủ gồm [S, v1, v2, ..., vn] trong đó index 0 là điểm xuất phát S
     * @return array<int, array{vi_do: float, kinh_do: float, id?: int|string}> 
     *        Lộ trình sau khi đã tối ưu 2-Opt
     */
    public function improve(array $route): array
    {
        $totalNodes = count($route);
        // n là số đơn hàng giao (không tính điểm xuất phát index 0)
        $n = $totalNodes - 1;

        // Nếu có <= 2 đơn hàng (tổng số điểm <= 3), giữ nguyên lộ trình
        if ($n <= 2) {
            return array_values($route);
        }

        $improved = true;
        $iteration = 0;
        $optimizedRoute = array_values($route);

        while ($improved && $iteration < self::MAX_ITERATIONS) {
            $improved = false;
            $iteration++;

            for ($i = 1; $i < $n; $i++) {
                for ($k = $i + 1; $k <= $n; $k++) {
                    // T[i-1], T[i], T[k], T[k+1]
                    $prev = $optimizedRoute[$i - 1];
                    $nodeI = $optimizedRoute[$i];
                    $nodeK = $optimizedRoute[$k];

                    $dPrevK = HaversineService::roadDistance(
                        (float) $prev['vi_do'],
                        (float) $prev['kinh_do'],
                        (float) $nodeK['vi_do'],
                        (float) $nodeK['kinh_do']
                    );

                    $dPrevI = HaversineService::roadDistance(
                        (float) $prev['vi_do'],
                        (float) $prev['kinh_do'],
                        (float) $nodeI['vi_do'],
                        (float) $nodeI['kinh_do']
                    );

                    $delta = $dPrevK - $dPrevI;

                    // Nếu k < n thì có cạnh nối tiếp theo T[k+1]
                    if ($k < $n) {
                        $next = $optimizedRoute[$k + 1];

                        $dINext = HaversineService::roadDistance(
                            (float) $nodeI['vi_do'],
                            (float) $nodeI['kinh_do'],
                            (float) $next['vi_do'],
                            (float) $next['kinh_do']
                        );

                        $dKNext = HaversineService::roadDistance(
                            (float) $nodeK['vi_do'],
                            (float) $nodeK['kinh_do'],
                            (float) $next['vi_do'],
                            (float) $next['kinh_do']
                        );

                        $delta += ($dINext - $dKNext);
                    }

                    // Gặp phép đảo đầu tiên có Delta < -10^-6 km (First Improvement)
                    if ($delta < self::IMPROVEMENT_THRESHOLD) {
                        // Đảo ngược đoạn con từ index i đến index k
                        $length = $k - $i + 1;
                        $sub = array_slice($optimizedRoute, $i, $length);
                        array_splice($optimizedRoute, $i, $length, array_reverse($sub));

                        $improved = true;
                        // Nhảy ra ngoài để quay lại duyệt từ i = 1
                        break 2;
                    }
                }
            }
        }

        return $optimizedRoute;
    }

    /**
     * Tiện ích tối ưu hóa với điểm xuất phát và danh sách đơn riêng biệt
     *
     * @param array{vi_do: float, kinh_do: float} $start Điểm xuất phát S
     * @param array<int, array{vi_do: float, kinh_do: float}> $points Danh sách đơn hàng theo thứ tự ban đầu
     * @return array Danh sách đơn hàng đã được tối ưu 2-Opt (không chứa điểm S)
     */
    public function improveWithStart(array $start, array $points): array
    {
        if (count($points) <= 2) {
            return array_values($points);
        }

        $fullRoute = array_merge([$start], array_values($points));
        $optimizedFullRoute = $this->improve($fullRoute);

        // Bỏ điểm xuất phát S ở index 0, chỉ trả về danh sách các đơn hàng
        return array_values(array_slice($optimizedFullRoute, 1));
    }

    /**
     * Tính tổng chiều dài lộ trình đầy đủ gồm [S, v1, ..., vn]
     *
     * @param array<int, array{vi_do: float, kinh_do: float}> $route
     * @return float Tổng quãng đường km
     */
    public function calculateTotalDistance(array $route): float
    {
        $count = count($route);
        if ($count <= 1) {
            return 0.0;
        }

        $total = 0.0;
        for ($i = 0; $i < $count - 1; $i++) {
            $total += HaversineService::roadDistance(
                (float) $route[$i]['vi_do'],
                (float) $route[$i]['kinh_do'],
                (float) $route[$i + 1]['vi_do'],
                (float) $route[$i + 1]['kinh_do']
            );
        }

        return $total;
    }
}
