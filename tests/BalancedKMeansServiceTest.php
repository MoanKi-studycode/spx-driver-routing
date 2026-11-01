<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Clustering\BalancedKMeansService;
use PHPUnit\Framework\TestCase;

/**
 * Class BalancedKMeansServiceTest
 * 
 * Kiểm thử thuật toán phân cụm Balanced K-Means có cân bằng tải,
 * tính tất định (Deterministic) và loại bỏ cụm rỗng theo Mục 8.1.
 */
class BalancedKMeansServiceTest extends TestCase
{
    private BalancedKMeansService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BalancedKMeansService();
    }

    /**
     * 1. Trường hợp đầu vào rỗng (0 đơn hoặc 0 tài xế)
     */
    public function testEmptyInputsReturnEmptyResult(): void
    {
        $depot = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $drivers = [
            ['id' => 1, 'ho_ten' => 'Nguyễn Văn A', 'suc_chua_con_lai' => 20],
        ];

        $this->assertSame([], $this->service->cluster([], $drivers, $depot));
        $this->assertSame([], $this->service->cluster([['id' => 1, 'vi_do' => 12.25, 'kinh_do' => 109.19]], [], $depot));
    }

    /**
     * 2. Yêu cầu trọng tâm Mục 8.1: 32 đơn chia cho 3 tài xế đảm bảo:
     * - Không cụm nào vượt quá 20 đơn
     * - Các cụm phân bổ đều tải (xấp xỉ 10-11 đơn/cụm)
     * - Tổng số đơn sau phân cụm đủ 32 đơn (không bị mất đơn hay trùng đơn)
     */
    public function testBalancedClustering32OrdersAnd3Drivers(): void
    {
        $depot = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];

        // 3 tài xế khả dụng, mỗi người sức chứa còn 20
        $drivers = [
            ['id' => 1, 'ho_ten' => 'Tài xế Bắc NT', 'phuong_uu_tien_id' => 1, 'suc_chua_con_lai' => 20, 'mau_sac_cum' => '#ef4444'],
            ['id' => 2, 'ho_ten' => 'Tài xế Tây NT', 'phuong_uu_tien_id' => 2, 'suc_chua_con_lai' => 20, 'mau_sac_cum' => '#2563eb'],
            ['id' => 3, 'ho_ten' => 'Tài xế Trung tâm NT', 'phuong_uu_tien_id' => 3, 'suc_chua_con_lai' => 20, 'mau_sac_cum' => '#16a34a'],
        ];

        // Tạo 32 đơn hàng mẫu rải rác ở 3 khu vực tại Nha Trang
        $orders = [];
        for ($i = 1; $i <= 12; $i++) {
            // Khu vực Bắc Nha Trang
            $orders[] = ['id' => $i, 'ma_van_don' => "SPX_BAC_{$i}", 'vi_do' => 12.2700 + ($i * 0.001), 'kinh_do' => 109.1950 + ($i * 0.0005), 'phuong_id' => 1];
        }
        for ($i = 13; $i <= 22; $i++) {
            // Khu vực Tây Nha Trang
            $orders[] = ['id' => $i, 'ma_van_don' => "SPX_TAY_{$i}", 'vi_do' => 12.2520 + (($i - 12) * 0.0008), 'kinh_do' => 109.1600 + (($i - 12) * 0.001), 'phuong_id' => 2];
        }
        for ($i = 23; $i <= 32; $i++) {
            // Khu vực Nam / Trung tâm Nha Trang
            $orders[] = ['id' => $i, 'ma_van_don' => "SPX_NAM_{$i}", 'vi_do' => 12.2350 + (($i - 22) * 0.0006), 'kinh_do' => 109.1900 + (($i - 22) * 0.0008), 'phuong_id' => 3];
        }

        $clusters = $this->service->cluster($orders, $drivers, $depot);

        // Số cụm thực tế tạo ra phải là 3
        $this->assertCount(3, $clusters);

        $assignedOrderIds = [];
        foreach ($clusters as $cluster) {
            $clusterOrderCount = count($cluster['don_hang']);

            // Không cụm nào vượt quá 20 đơn
            $this->assertLessThanOrEqual(20, $clusterOrderCount);
            // Cân bằng tải: mỗi cụm xấp xỉ 10-12 đơn
            $this->assertGreaterThanOrEqual(9, $clusterOrderCount);

            // Kiểm tra cấu trúc cụm
            $this->assertArrayHasKey('tai_xe', $cluster);
            $this->assertArrayHasKey('tam_cum', $cluster);
            $this->assertArrayHasKey('don_hang', $cluster);

            foreach ($cluster['don_hang'] as $order) {
                $assignedOrderIds[] = $order['id'];
            }
        }

        // Toàn bộ 32 đơn đều được phân công
        $this->assertCount(32, $assignedOrderIds);
        // Không có đơn nào bị trùng lặp giữa các cụm
        $this->assertCount(32, array_unique($assignedOrderIds));
    }

    /**
     * 3. Tính tất định (Deterministic): Chạy 2 lần cùng dữ liệu phải ra kết quả giống hệt nhau 100%
     */
    public function testDeterministicClustering(): void
    {
        $depot = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $drivers = [
            ['id' => 1, 'ho_ten' => 'Tài xế 1', 'suc_chua_con_lai' => 20],
            ['id' => 2, 'ho_ten' => 'Tài xế 2', 'suc_chua_con_lai' => 20],
        ];

        $orders = [
            ['id' => 10, 'vi_do' => 12.260, 'kinh_do' => 109.190],
            ['id' => 20, 'vi_do' => 12.250, 'kinh_do' => 109.180],
            ['id' => 30, 'vi_do' => 12.270, 'kinh_do' => 109.200],
            ['id' => 40, 'vi_do' => 12.240, 'kinh_do' => 109.170],
        ];

        $run1 = $this->service->cluster($orders, $drivers, $depot);
        $run2 = $this->service->cluster($orders, $drivers, $depot);

        $this->assertSame($run1, $run2, 'Hai lần phân cụm cùng đầu vào phải cho ra kết quả giống hệt nhau');
    }

    /**
     * 4. Tính gắn kết địa lý (Geographical Cohesion): Các điểm gần nhau phải vào cùng 1 cụm
     */
    public function testGeographicalCohesion(): void
    {
        $depot = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $drivers = [
            ['id' => 1, 'ho_ten' => 'TX Bắc', 'suc_chua_con_lai' => 20],
            ['id' => 2, 'ho_ten' => 'TX Nam', 'suc_chua_con_lai' => 20],
        ];

        // 2 nhóm đơn hàng cách xa nhau: nhóm Bắc (12.28) và nhóm Nam (12.22)
        $orders = [
            ['id' => 1, 'vi_do' => 12.280, 'kinh_do' => 109.195],
            ['id' => 2, 'vi_do' => 12.282, 'kinh_do' => 109.196],
            ['id' => 3, 'vi_do' => 12.220, 'kinh_do' => 109.185],
            ['id' => 4, 'vi_do' => 12.222, 'kinh_do' => 109.186],
        ];

        $clusters = $this->service->cluster($orders, $drivers, $depot);

        $this->assertCount(2, $clusters);

        // Đơn 1 và 2 phải nằm cùng cụm với nhau, đơn 3 và 4 cùng cụm với nhau
        $c1OrderIds = array_column($clusters[0]['don_hang'], 'id');
        $c2OrderIds = array_column($clusters[1]['don_hang'], 'id');

        $groupedTogether = (
            (in_array(1, $c1OrderIds, true) && in_array(2, $c1OrderIds, true) && in_array(3, $c2OrderIds, true) && in_array(4, $c2OrderIds, true)) ||
            (in_array(3, $c1OrderIds, true) && in_array(4, $c1OrderIds, true) && in_array(1, $c2OrderIds, true) && in_array(2, $c2OrderIds, true))
        );

        $this->assertTrue($groupedTogether, 'Các đơn hàng gần nhau về mặt địa lý phải được gán vào cùng một cụm');
    }

    public function testClusterRespectsIndividualDriverCapacity(): void
    {
        $depot = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $drivers = [
            ['id' => 1, 'ho_ten' => 'TX A', 'suc_chua_con_lai' => 7],
            ['id' => 2, 'ho_ten' => 'TX B', 'suc_chua_con_lai' => 7],
            ['id' => 3, 'ho_ten' => 'TX C', 'suc_chua_con_lai' => 6],
            ['id' => 4, 'ho_ten' => 'TX D', 'suc_chua_con_lai' => 6],
        ];

        $orders = [];
        $base = 2;
        for ($j = 1; $j <= 25; $j++) {
            $orders[] = [
                'id' => $j,
                'vi_do' => 12.2488 + (($j % 5) * 0.01) + (($base + $j) % 3) * 0.001,
                'kinh_do' => 109.1905 + (($j % 6) * 0.012) + (($base * 7 + $j) % 5) * 0.001,
            ];
        }

        $clusters = $this->service->cluster($orders, $drivers, $depot);

        $this->assertCount(4, $clusters);

        foreach ($clusters as $cluster) {
            $driverCapacity = (int) ($cluster['tai_xe']['suc_chua_con_lai'] ?? BalancedKMeansService::MAX_CAPACITY);
            $this->assertTrue(
                count($cluster['don_hang']) <= $driverCapacity,
                'Mỗi cụm phải không vượt quá sức chứa cá nhân của tài xế gắn với cụm đó.'
            );
        }
    }
}
