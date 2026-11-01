<?php

declare(strict_types=1);

namespace App\Services\Clustering;

use App\Services\Routing\HaversineService;

/**
 * Class BalancedKMeansService
 * 
 * Phân cụm đơn hàng bằng thuật toán Balanced K-Means có cân bằng tải (tối đa 20 đơn/cụm)
 * và ghép cụm cho tài xế phù hợp nhất theo địa bàn quen thuộc.
 * Thuật toán hoàn toàn tất định (Deterministic - không dùng random, chạy nhiều lần ra cùng kết quả).
 */
class BalancedKMeansService
{
    /**
     * Sức chứa tối đa của 1 cụm theo quy định hệ thống (20 đơn/cụm)
     */
    public const MAX_CAPACITY = 20;

    /**
     * Số vòng lặp tối đa của K-Means
     */
    public const MAX_ITER = 20;

    /**
     * Phân cụm các đơn hàng hợp lệ và ghép tài xế
     *
     * @param array<int, array{id: int|string, vi_do: float, kinh_do: float, phuong_id?: int}> $orders
     *        Danh sách đơn hàng cần phân cụm
     * @param array<int, array{id: int|string, ho_ten?: string, phuong_uu_tien_id?: int|null, suc_chua_con_lai?: int, mau_sac_cum?: string}> $drivers
     *        Danh sách tài xế đang hoạt động
     * @param array{vi_do: float, kinh_do: float} $depot
     *        Tọa độ Bưu cục xuất phát P0
     * @param array<int, array{vi_do: float, kinh_do: float}> $wardCoordinates
     *        Bản đồ tọa độ tâm phường [phuong_id => ['vi_do' => ..., 'kinh_do' => ...]] tùy chọn
     * @return array<int, array{tai_xe: array, tam_cum: array{vi_do: float, kinh_do: float}, don_hang: array}>
     *        Danh sách các cụm đã gán tài xế và danh sách đơn tương ứng
     */
    public function cluster(
        array $orders,
        array $drivers,
        array $depot,
        array $wardCoordinates = []
    ): array {
        $nOrders = count($orders);
        if ($nOrders === 0) {
            return [];
        }

        // Lọc danh sách tài xế còn sức chứa > 0
        $availableDrivers = array_values(array_filter($drivers, static function (array $d): bool {
            return ($d['suc_chua_con_lai'] ?? self::MAX_CAPACITY) > 0;
        }));

        $nDrivers = count($availableDrivers);
        if ($nDrivers === 0) {
            return [];
        }

        // Tính tổng sức chứa tối đa có thể nhận
        $totalCapacity = 0;
        foreach ($availableDrivers as $d) {
            $totalCapacity += (int) ($d['suc_chua_con_lai'] ?? self::MAX_CAPACITY);
        }

        // Nếu N vượt quá tổng sức chứa, ưu tiên lấy các đơn gần Bưu cục P0 nhất
        $orderList = array_values($orders);
        if ($nOrders > $totalCapacity) {
            usort($orderList, static function (array $a, array $b) use ($depot): int {
                $distA = HaversineService::distance((float) $depot['vi_do'], (float) $depot['kinh_do'], (float) $a['vi_do'], (float) $a['kinh_do']);
                $distB = HaversineService::distance((float) $depot['vi_do'], (float) $depot['kinh_do'], (float) $b['vi_do'], (float) $b['kinh_do']);
                if (abs($distA - $distB) <= 1e-9) {
                    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                }
                return $distA <=> $distB;
            });
            $orderList = array_slice($orderList, 0, $totalCapacity);
            $nOrders = count($orderList);
        }

        // Số cụm K = min(M_kha_dung, N)
        $k = min($nDrivers, $nOrders);
        if ($k <= 0) {
            return [];
        }

        // Sức chứa mục tiêu mỗi cụm: S_k = min(20, ceil(N / K))
        $targetCapacity = min(self::MAX_CAPACITY, (int) ceil($nOrders / $k));

        // 1. Khởi tạo K tâm cụm ban đầu bằng góc quét Angular Sweep (không dùng random)
        $centroids = $this->initializeCentroidsAngular($orderList, $depot, $k);

        // 2. Vòng lặp gán điểm có cân bằng tải & cập nhật tâm cụm (tối đa 20 vòng lặp)
        $clusters = [];
        $previousAssignments = [];

        for ($iter = 0; $iter < self::MAX_ITER; $iter++) {
            // Chuẩn bị danh sách đơn kèm mức độ ưu tiên
            $priorities = [];
            foreach ($orderList as $orderIdx => $order) {
                // Tính khoảng cách đến tất cả K tâm cụm
                $distances = [];
                foreach ($centroids as $cIdx => $center) {
                    $distances[$cIdx] = HaversineService::distance(
                        (float) $order['vi_do'],
                        (float) $order['kinh_do'],
                        $center['vi_do'],
                        $center['kinh_do']
                    );
                }

                asort($distances);
                $sortedClusterIndices = array_keys($distances);
                $dMin = $distances[$sortedClusterIndices[0]];
                $dSecond = count($distances) > 1 ? $distances[$sortedClusterIndices[1]] : $dMin;

                $priorityScore = $dSecond - $dMin;

                $priorities[] = [
                    'order_idx' => $orderIdx,
                    'order' => $order,
                    'priority' => $priorityScore,
                    'sorted_clusters' => $sortedClusterIndices,
                ];
            }

            // Sắp xếp đơn theo ưu tiên giảm dần (nếu hòa ưu tiên id nhỏ hơn)
            usort($priorities, static function (array $a, array $b): int {
                $diff = $b['priority'] - $a['priority'];
                if (abs($diff) > 1e-9) {
                    return $diff > 0 ? 1 : -1;
                }
                return ((int) ($a['order']['id'] ?? 0)) <=> ((int) ($b['order']['id'] ?? 0));
            });

            // Khởi tạo K cụm rỗng cho vòng này
            $currentClusters = array_fill(0, $k, []);
            $currentAssignments = [];

            // Duyệt từng đơn theo mức độ ưu tiên: gán vào cụm gần nhất còn chỗ
            foreach ($priorities as $item) {
                $order = $item['order'];
                $assigned = false;

                // Thử gán vào cụm gần nhất có số đơn < targetCapacity
                foreach ($item['sorted_clusters'] as $cIdx) {
                    if (count($currentClusters[$cIdx]) < $targetCapacity) {
                        $currentClusters[$cIdx][] = $order;
                        $currentAssignments[$order['id'] ?? $item['order_idx']] = $cIdx;
                        $assigned = true;
                        break;
                    }
                }

                // Nếu mọi cụm đều đã đạt targetCapacity, gán vào cụm gần nhất còn < MAX_CAPACITY (20)
                if (!$assigned) {
                    foreach ($item['sorted_clusters'] as $cIdx) {
                        if (count($currentClusters[$cIdx]) < self::MAX_CAPACITY) {
                            $currentClusters[$cIdx][] = $order;
                            $currentAssignments[$order['id'] ?? $item['order_idx']] = $cIdx;
                            $assigned = true;
                            break;
                        }
                    }
                }

                // Trường hợp hy hữu: gán vào cụm có ít đơn nhất
                if (!$assigned) {
                    $minClusterIdx = 0;
                    $minClusterCount = count($currentClusters[0]);
                    for ($c = 1; $c < $k; $c++) {
                        if (count($currentClusters[$c]) < $minClusterCount) {
                            $minClusterCount = count($currentClusters[$c]);
                            $minClusterIdx = $c;
                        }
                    }
                    $currentClusters[$minClusterIdx][] = $order;
                    $currentAssignments[$order['id'] ?? $item['order_idx']] = $minClusterIdx;
                }
            }

            // Kiểm tra hội tụ sớm: nếu việc phân bổ không thay đổi so với vòng trước
            if ($currentAssignments === $previousAssignments) {
                $clusters = $currentClusters;
                break;
            }

            $previousAssignments = $currentAssignments;
            $clusters = $currentClusters;

            // Tính lại tọa độ tâm cụm mới bằng trung bình cộng các đơn trong cụm
            for ($c = 0; $c < $k; $c++) {
                if (!empty($currentClusters[$c])) {
                    $sumLat = 0.0;
                    $sumLng = 0.0;
                    $cnt = count($currentClusters[$c]);
                    foreach ($currentClusters[$c] as $o) {
                        $sumLat += (float) $o['vi_do'];
                        $sumLng += (float) $o['kinh_do'];
                    }
                    $centroids[$c] = [
                        'vi_do' => $sumLat / $cnt,
                        'kinh_do' => $sumLng / $cnt,
                    ];
                }
            }
        }

        // 3. Loại bỏ các cụm rỗng không có đơn nào
        $validClusters = [];
        foreach ($clusters as $cIdx => $clusterOrders) {
            if (!empty($clusterOrders)) {
                $validClusters[] = [
                    'tam_cum' => $centroids[$cIdx],
                    'don_hang' => $clusterOrders,
                ];
            }
        }

        // 4. Ghép K' cụm cho K' tài xế phù hợp nhất
        return $this->matchClustersToDrivers($validClusters, $availableDrivers, $depot, $wardCoordinates);
    }

    /**
     * Khởi tạo K tâm cụm ban đầu bằng góc quét Angular Sweep
     *
     * @param array $orders
     * @param array $depot
     * @param int $k
     * @return array<int, array{vi_do: float, kinh_do: float}>
     */
    private function initializeCentroidsAngular(array $orders, array $depot, int $k): array
    {
        $depotLat = (float) $depot['vi_do'];
        $depotLng = (float) $depot['kinh_do'];

        $ordersWithAngle = [];
        foreach ($orders as $order) {
            $lat = (float) $order['vi_do'];
            $lng = (float) $order['kinh_do'];
            $theta = atan2($lat - $depotLat, $lng - $depotLng);

            $ordersWithAngle[] = [
                'order' => $order,
                'theta' => $theta,
                'id' => $order['id'] ?? 0,
            ];
        }

        // Sắp xếp theo góc quét tăng dần quanh P0 (nếu bằng góc thì sắp theo id ASC)
        usort($ordersWithAngle, static function (array $a, array $b): int {
            if (abs($a['theta'] - $b['theta']) > 1e-9) {
                return $a['theta'] <=> $b['theta'];
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $n = count($ordersWithAngle);
        $centroids = [];

        // Chia thành K lát cắt đều nhau
        for ($c = 0; $c < $k; $c++) {
            $startIdx = (int) floor($c * $n / $k);
            $endIdx = (int) floor(($c + 1) * $n / $k);
            $slice = array_slice($ordersWithAngle, $startIdx, max(1, $endIdx - $startIdx));

            $sumLat = 0.0;
            $sumLng = 0.0;
            $sliceCount = count($slice);

            foreach ($slice as $item) {
                $sumLat += (float) $item['order']['vi_do'];
                $sumLng += (float) $item['order']['kinh_do'];
            }

            $centroids[$c] = [
                'vi_do' => $sumLat / $sliceCount,
                'kinh_do' => $sumLng / $sliceCount,
            ];
        }

        return $centroids;
    }

    /**
     * Ghép cụm cho tài xế theo khoảng cách gần tâm phường ưu tiên nhất
     *
     * @param array $clusters
     * @param array $drivers
     * @param array $depot
     * @param array $wardCoordinates
     * @return array
     */
    private function matchClustersToDrivers(
        array $clusters,
        array $drivers,
        array $depot,
        array $wardCoordinates
    ): array {
        $result = [];
        $unassignedDrivers = array_values($drivers);
        usort($unassignedDrivers, static function (array $a, array $b): int {
            $capacityDiff = ((int) ($b['suc_chua_con_lai'] ?? self::MAX_CAPACITY)) <=> ((int) ($a['suc_chua_con_lai'] ?? self::MAX_CAPACITY));
            if ($capacityDiff !== 0) {
                return $capacityDiff;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $orderedClusters = $clusters;
        usort($orderedClusters, static function (array $a, array $b): int {
            $sizeDiff = count($b['don_hang']) <=> count($a['don_hang']);
            if ($sizeDiff !== 0) {
                return $sizeDiff;
            }

            return ((int) ($a['tam_cum']['vi_do'] * 1000000)) <=> ((int) ($b['tam_cum']['vi_do'] * 1000000));
        });

        foreach ($orderedClusters as $cluster) {
            $center = $cluster['tam_cum'];
            $clusterSize = count($cluster['don_hang']);
            $bestDriverIndex = -1;
            $bestDistance = INF;
            $bestDriverId = null;

            foreach ($unassignedDrivers as $dIdx => $driver) {
                $driverCapacity = (int) ($driver['suc_chua_con_lai'] ?? self::MAX_CAPACITY);
                if ($driverCapacity < $clusterSize) {
                    continue;
                }

                $driverTargetLat = (float) $depot['vi_do'];
                $driverTargetLng = (float) $depot['kinh_do'];

                $wardId = $driver['phuong_uu_tien_id'] ?? null;
                if ($wardId !== null && isset($wardCoordinates[$wardId])) {
                    $driverTargetLat = (float) $wardCoordinates[$wardId]['vi_do'];
                    $driverTargetLng = (float) $wardCoordinates[$wardId]['kinh_do'];
                } elseif (isset($driver['vi_do'], $driver['kinh_do']) && $driver['vi_do'] !== null) {
                    $driverTargetLat = (float) $driver['vi_do'];
                    $driverTargetLng = (float) $driver['kinh_do'];
                }

                $dist = HaversineService::distance(
                    $center['vi_do'],
                    $center['kinh_do'],
                    $driverTargetLat,
                    $driverTargetLng
                );

                $driverId = $driver['id'] ?? null;

                if ($bestDriverIndex === -1) {
                    $bestDriverIndex = $dIdx;
                    $bestDistance = $dist;
                    $bestDriverId = $driverId;
                    continue;
                }

                $diff = $dist - $bestDistance;
                if ($diff < -1e-9) {
                    $bestDriverIndex = $dIdx;
                    $bestDistance = $dist;
                    $bestDriverId = $driverId;
                } elseif (abs($diff) <= 1e-9) {
                    if ($bestDriverId !== null && $driverId !== null && $driverId < $bestDriverId) {
                        $bestDriverIndex = $dIdx;
                        $bestDistance = $dist;
                        $bestDriverId = $driverId;
                    }
                }
            }

            if ($bestDriverIndex !== -1) {
                $matchedDriver = $unassignedDrivers[$bestDriverIndex];
                array_splice($unassignedDrivers, $bestDriverIndex, 1);

                $result[] = [
                    'tai_xe' => $matchedDriver,
                    'tam_cum' => $center,
                    'don_hang' => $cluster['don_hang'],
                ];
            } else {
                $fallbackDriverIndex = 0;
                $fallbackDriver = $unassignedDrivers[$fallbackDriverIndex] ?? [];
                $result[] = [
                    'tai_xe' => $fallbackDriver,
                    'tam_cum' => $center,
                    'don_hang' => $cluster['don_hang'],
                ];

                if ($fallbackDriverIndex >= 0 && isset($unassignedDrivers[$fallbackDriverIndex])) {
                    array_splice($unassignedDrivers, $fallbackDriverIndex, 1);
                }
            }
        }

        return $result;
    }
}
