<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Routing\NearestNeighborSolver;
use App\Services\Routing\TwoOptSolver;
use PHPUnit\Framework\TestCase;

/**
 * Class TwoOptSolverTest
 * 
 * Kiểm thử thuật toán 2-Opt tối ưu hóa lộ trình đường hở (Open-Path 2-Opt).
 * Đảm bảo triệt tiêu các đoạn cắt chéo và quãng đường sau 2-Opt luôn <= Nearest Neighbor.
 */
class TwoOptSolverTest extends TestCase
{
    private TwoOptSolver $twoOpt;
    private NearestNeighborSolver $nnSolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoOpt = new TwoOptSolver();
        $this->nnSolver = new NearestNeighborSolver();
    }

    /**
     * 1. Trường hợp n <= 2 đơn hàng: Giữ nguyên lộ trình ban đầu
     */
    public function testSmallRouteReturnsUnchanged(): void
    {
        $start = ['id' => 0, 'vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $p1 = ['id' => 1, 'vi_do' => 12.2555, 'kinh_do' => 109.1615];
        $p2 = ['id' => 2, 'vi_do' => 12.2795, 'kinh_do' => 109.1985];

        // Tuyến gồm Start + 2 đơn hàng
        $route = [$start, $p1, $p2];
        $result = $this->twoOpt->improve($route);

        $this->assertSame($route, $result);
    }

    /**
     * 2. Triệt tiêu đoạn cắt chéo (Untangle Crossings):
     * Cho lộ trình bị bắt chéo hình chữ X, 2-Opt phải đảo đoạn con để giảm tổng quãng đường.
     */
    public function testTwoOptUntanglesCrossedRoute(): void
    {
        // 4 điểm tạo thành hình chữ nhật nhưng lộ trình nối chéo:
        // S(12.20, 109.20) -> C(12.22, 109.22) -> B(12.22, 109.20) -> A(12.20, 109.22)
        $start = ['id' => 0, 'vi_do' => 12.2000, 'kinh_do' => 109.2000]; // Gốc dưới trái
        $nodeC = ['id' => 3, 'vi_do' => 12.2200, 'kinh_do' => 109.2200]; // Đỉnh trên phải (chéo xa)
        $nodeB = ['id' => 2, 'vi_do' => 12.2200, 'kinh_do' => 109.2000]; // Đỉnh trên trái
        $nodeA = ['id' => 1, 'vi_do' => 12.2000, 'kinh_do' => 109.2200]; // Đỉnh dưới phải

        $crossedRoute = [$start, $nodeC, $nodeB, $nodeA];
        $distanceBefore = $this->twoOpt->calculateTotalDistance($crossedRoute);

        $optimizedRoute = $this->twoOpt->improve($crossedRoute);
        $distanceAfter = $this->twoOpt->calculateTotalDistance($optimizedRoute);

        // Sau khi 2-Opt gỡ bỏ đường chéo, quãng đường phải giảm đáng kể
        $this->assertLessThan($distanceBefore, $distanceAfter);
        $this->assertSame(0, $optimizedRoute[0]['id'], 'Điểm xuất phát S phải luôn được cố định ở index 0');
    }

    /**
     * 3. Kiểm tra tính năng improveWithStart tiện ích
     */
    public function testImproveWithStartHelper(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $points = [
            ['id' => 10, 'vi_do' => 12.2600, 'kinh_do' => 109.2000],
            ['id' => 20, 'vi_do' => 12.2500, 'kinh_do' => 109.1950],
            ['id' => 30, 'vi_do' => 12.2700, 'kinh_do' => 109.2100],
            ['id' => 40, 'vi_do' => 12.2550, 'kinh_do' => 109.1920],
        ];

        $optimizedPoints = $this->twoOpt->improveWithStart($start, $points);

        $this->assertCount(4, $optimizedPoints);
        // Kiểm tra không bị lọt điểm xuất phát vào danh sách đơn trả về
        foreach ($optimizedPoints as $p) {
            $this->assertArrayHasKey('id', $p);
        }
    }

    /**
     * 4. Yêu cầu trọng tâm Mục 8.1: Đảm bảo quãng đường sau 2-Opt luôn <= Nearest Neighbor trên mọi cụm
     */
    public function testTwoOptImprovesOrEqualsNearestNeighbor(): void
    {
        $depot = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];

        // Cụm đơn hàng thực tế tại TP. Nha Trang (khu vực Vĩnh Hải, Vĩnh Phước, Lộc Thọ, Tân Lập...)
        $orders = [
            ['id' => 1, 'vi_do' => 12.2795, 'kinh_do' => 109.1985],
            ['id' => 2, 'vi_do' => 12.2555, 'kinh_do' => 109.1615],
            ['id' => 3, 'vi_do' => 12.2335, 'kinh_do' => 109.1910],
            ['id' => 4, 'vi_do' => 12.2620, 'kinh_do' => 109.1850],
            ['id' => 5, 'vi_do' => 12.2450, 'kinh_do' => 109.1950],
            ['id' => 6, 'vi_do' => 12.2710, 'kinh_do' => 109.2020],
            ['id' => 7, 'vi_do' => 12.2510, 'kinh_do' => 109.1820],
            ['id' => 8, 'vi_do' => 12.2390, 'kinh_do' => 109.1750],
        ];

        // 1. Chạy giải thuật Nearest Neighbor ban đầu
        $nnOrderedOrders = $this->nnSolver->solve($depot, $orders);
        $fullNNRoute = array_merge([$depot], $nnOrderedOrders);
        $kmNN = $this->twoOpt->calculateTotalDistance($fullNNRoute);

        // 2. Chạy giải thuật 2-Opt cải tiến trên kết quả của Nearest Neighbor
        $full2OptRoute = $this->twoOpt->improve($fullNNRoute);
        $km2Opt = $this->twoOpt->calculateTotalDistance($full2OptRoute);

        // Khẳng định: Quãng đường sau 2-Opt luôn <= Nearest Neighbor (dung sai số học 1e-6 km)
        $this->assertLessThanOrEqual(
            $kmNN + 1e-6,
            $km2Opt,
            "Quãng đường sau 2-Opt ({$km2Opt} km) phải luôn <= Nearest Neighbor ({$kmNN} km)"
        );

        // Điểm đầu phải luôn là Bưu cục depot
        $this->assertEqualsWithDelta($depot['vi_do'], $full2OptRoute[0]['vi_do'], 1e-6);
        $this->assertEqualsWithDelta($depot['kinh_do'], $full2OptRoute[0]['kinh_do'], 1e-6);

        // Số lượng đơn hàng không bị mất mát hay nhân đôi
        $this->assertCount(count($orders) + 1, $full2OptRoute);
    }
}
