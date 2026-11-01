<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Routing\HaversineService;
use App\Services\Routing\NearestNeighborSolver;
use PHPUnit\Framework\TestCase;

/**
 * Class NearestNeighborSolverTest
 * 
 * Kiểm thử thuật toán Nearest Neighbor đường hở (Open-Path Greedy TSP).
 */
class NearestNeighborSolverTest extends TestCase
{
    private NearestNeighborSolver $solver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->solver = new NearestNeighborSolver();
    }

    /**
     * 1. Trường hợp n = 0: Trả về mảng rỗng và quãng đường = 0.0
     */
    public function testEmptyPointsReturnsEmptyRoute(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $points = [];

        $route = $this->solver->solve($start, $points);
        $distance = $this->solver->calculateDistance($start, $route);

        $this->assertSame([], $route);
        $this->assertSame(0.0, $distance);
    }

    /**
     * 2. Trường hợp n = 1: Trả về đúng 1 đơn đó
     */
    public function testSinglePointReturnsSamePoint(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $point = ['id' => 101, 'ma_van_don' => 'SPX01', 'vi_do' => 12.2500, 'kinh_do' => 109.1910];

        $route = $this->solver->solve($start, [$point]);
        $distance = $this->solver->calculateDistance($start, $route);

        $this->assertCount(1, $route);
        $this->assertSame(101, $route[0]['id']);

        $expectedDist = HaversineService::roadDistance(
            $start['vi_do'],
            $start['kinh_do'],
            $point['vi_do'],
            $point['kinh_do']
        );
        $this->assertEqualsWithDelta($expectedDist, $distance, 1e-6);
    }

    /**
     * 3. Kiểm tra chọn đúng thứ tự gần nhất với các điểm thẳng hàng
     */
    public function testGreedySequenceOnCollinearPoints(): void
    {
        $start = ['vi_do' => 12.2400, 'kinh_do' => 109.1900];

        // 3 điểm thẳng hàng theo hướng Bắc, khoảng cách tăng dần
        $pointNear = ['id' => 1, 'vi_do' => 12.2450, 'kinh_do' => 109.1900]; // Gần nhất
        $pointMid  = ['id' => 2, 'vi_do' => 12.2550, 'kinh_do' => 109.1900]; // Trung bình
        $pointFar  = ['id' => 3, 'vi_do' => 12.2700, 'kinh_do' => 109.1900]; // Xa nhất

        // Đưa vào mảng đầu vào với thứ tự lộn xộn: [Far, Near, Mid]
        $scrambled = [$pointFar, $pointNear, $pointMid];

        $route = $this->solver->solve($start, $scrambled);

        // Thứ tự tối ưu tham lam phải là Near (1) -> Mid (2) -> Far (3)
        $routeIds = array_column($route, 'id');
        $this->assertSame([1, 2, 3], $routeIds);
    }

    /**
     * 4. Kiểm tra quy tắc chống hòa (Tie-break): Khoảng cách bằng nhau thì chọn id nhỏ hơn
     */
    public function testTieBreakPicksSmallerId(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];

        // Hai điểm đối xứng qua start (khoảng cách bằng hệt nhau)
        $pointSmallerId = ['id' => 5,  'vi_do' => 12.2498, 'kinh_do' => 109.1905]; // Cách +0.0010 vĩ độ
        $pointLargerId  = ['id' => 50, 'vi_do' => 12.2478, 'kinh_do' => 109.1905]; // Cách -0.0010 vĩ độ

        // Đưa ID lớn trước vào mảng
        $input = [$pointLargerId, $pointSmallerId];

        $route = $this->solver->solve($start, $input);

        // Phải chọn ID 5 trước vì 5 < 50 khi khoảng cách bằng nhau
        $this->assertSame(5, $route[0]['id']);
        $this->assertSame(50, $route[1]['id']);
    }

    /**
     * 5. Kiểm tra tính toán tổng khoảng cách tích lũy lộ trình
     */
    public function testTotalDistanceCalculation(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $p1 = ['id' => 1, 'vi_do' => 12.2555, 'kinh_do' => 109.1615];
        $p2 = ['id' => 2, 'vi_do' => 12.2795, 'kinh_do' => 109.1985];

        $route = [$p1, $p2];
        $totalDist = $this->solver->calculateDistance($start, $route);

        $seg1 = HaversineService::roadDistance($start['vi_do'], $start['kinh_do'], $p1['vi_do'], $p1['kinh_do']);
        $seg2 = HaversineService::roadDistance($p1['vi_do'], $p1['kinh_do'], $p2['vi_do'], $p2['kinh_do']);
        $expected = $seg1 + $seg2;

        $this->assertEqualsWithDelta($expected, $totalDist, 1e-6);
    }
}

