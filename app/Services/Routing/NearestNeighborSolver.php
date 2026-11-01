<?php

declare(strict_types=1);

namespace App\Services\Routing;

/**
 * Class NearestNeighborSolver
 * 
 * Xây dựng lộ trình tham lam ban đầu (Nearest Neighbor) đường hở
 * xuất phát từ điểm cố định (Bưu cục P0 hoặc GPS tài xế) qua n đơn hàng.
 */
class NearestNeighborSolver
{
    /**
     * Ngưỡng sai số so sánh khoảng cách hòa (Tie-break epsilon: 10^-9 km)
     */
    private const TIE_BREAK_EPSILON = 1e-9;

    /**
     * Dựng lộ trình đường hở xuất phát từ start qua danh sách các điểm
     *
     * @param array{vi_do: float, kinh_do: float} $start Điểm xuất phát
     * @param array<int, array{id?: int|string, vi_do: float, kinh_do: float}> $points Danh sách đơn hàng
     * @return array Danh sách đơn hàng theo thứ tự tối ưu tham lam
     */
    public function solve(array $start, array $points): array
    {
        $n = count($points);
        if ($n <= 1) {
            return array_values($points);
        }

        $unvisited = array_values($points);
        $route = [];
        $currentLat = (float) $start['vi_do'];
        $currentLng = (float) $start['kinh_do'];

        while (!empty($unvisited)) {
            $bestIndex = -1;
            $bestDistance = INF;
            $bestId = null;

            foreach ($unvisited as $idx => $point) {
                $dist = HaversineService::roadDistance(
                    $currentLat,
                    $currentLng,
                    (float) $point['vi_do'],
                    (float) $point['kinh_do']
                );
                $pointId = $point['id'] ?? null;

                if ($bestIndex === -1) {
                    $bestIndex = $idx;
                    $bestDistance = $dist;
                    $bestId = $pointId;
                    continue;
                }

                // So sánh khoảng cách nhỏ hơn
                $diff = $dist - $bestDistance;
                if ($diff < -self::TIE_BREAK_EPSILON) {
                    $bestIndex = $idx;
                    $bestDistance = $dist;
                    $bestId = $pointId;
                } elseif (abs($diff) <= self::TIE_BREAK_EPSILON) {
                    // Tie-break: Nếu khoảng cách bằng nhau trong ngưỡng 10^-9 km thì chọn ID nhỏ hơn
                    if ($bestId !== null && $pointId !== null && $pointId < $bestId) {
                        $bestIndex = $idx;
                        $bestDistance = $dist;
                        $bestId = $pointId;
                    }
                }
            }

            // Chọn điểm tối ưu nhất trong lượt này
            $chosen = $unvisited[$bestIndex];
            $route[] = $chosen;
            $currentLat = (float) $chosen['vi_do'];
            $currentLng = (float) $chosen['kinh_do'];

            // Xóa điểm đã chọn khỏi danh sách chưa thăm
            array_splice($unvisited, $bestIndex, 1);
        }

        return $route;
    }

    /**
     * Tính tổng chiều dài lộ trình đường hở xuất phát từ start qua các điểm
     *
     * @param array{vi_do: float, kinh_do: float} $start Điểm xuất phát
     * @param array<int, array{vi_do: float, kinh_do: float}> $route Lộ trình các đơn hàng
     * @return float Tổng quãng đường tính bằng km
     */
    public function calculateDistance(array $start, array $route): float
    {
        if (empty($route)) {
            return 0.0;
        }

        $totalKm = 0.0;
        $currentLat = (float) $start['vi_do'];
        $currentLng = (float) $start['kinh_do'];

        foreach ($route as $point) {
            $pointLat = (float) $point['vi_do'];
            $pointLng = (float) $point['kinh_do'];

            $totalKm += HaversineService::roadDistance(
                $currentLat,
                $currentLng,
                $pointLat,
                $pointLng
            );

            $currentLat = $pointLat;
            $currentLng = $pointLng;
        }

        return $totalKm;
    }
}
