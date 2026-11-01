<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Routing\DieuPhoiLoTrinhService;
use PHPUnit\Framework\TestCase;

/**
 * Class DieuPhoiLoTrinhServiceTest
 * 
 * Kiểm thử service điều phối tối ưu lộ trình kết hợp NN và 2-Opt.
 */
class DieuPhoiLoTrinhServiceTest extends TestCase
{
    private DieuPhoiLoTrinhService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DieuPhoiLoTrinhService();
    }

    /**
     * 1. Kiểm tra trường hợp danh sách rỗng n = 0
     */
    public function testEmptyOrdersReturnsZeroStats(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $result = $this->service->plan($start, []);

        $this->assertSame([], $result['thu_tu']);
        $this->assertSame(0.0, $result['km_baseline']);
        $this->assertSame(0.0, $result['km_nn']);
        $this->assertSame(0.0, $result['km_toi_uu']);
        $this->assertSame(0.0, $result['ty_le_tiet_kiem_phan_tram']);
        $this->assertGreaterThanOrEqual(0.0, $result['thoi_gian_chay_ms']);
    }

    /**
     * 2. Kiểm tra trường hợp 1 đơn hàng n = 1
     */
    public function testSingleOrderPlan(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $order = ['id' => 10, 'ma_van_don' => 'SPX001', 'vi_do' => 12.2795, 'kinh_do' => 109.1985];

        $result = $this->service->plan($start, [$order]);

        $this->assertCount(1, $result['thu_tu']);
        $this->assertSame(1, $result['thu_tu'][0]['thu_tu_giao']);
        $this->assertGreaterThan(0.0, $result['km_toi_uu']);
        $this->assertSame($result['km_baseline'], $result['km_toi_uu']);
    }

    /**
     * 3. Kiểm tra cụm nhiều đơn hàng thực tế tại Nha Trang
     */
    public function testRealisticClusterOptimization(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];

        // Tập đơn hàng bị đảo lộn thứ tự có chủ ý để tạo ra Baseline kém tối ưu
        $orders = [
            ['id' => 1, 'ma_van_don' => 'SPX01', 'vi_do' => 12.2850, 'kinh_do' => 109.2050],
            ['id' => 2, 'ma_van_don' => 'SPX02', 'vi_do' => 12.2350, 'kinh_do' => 109.1850],
            ['id' => 3, 'ma_van_don' => 'SPX03', 'vi_do' => 12.2800, 'kinh_do' => 109.2000],
            ['id' => 4, 'ma_van_don' => 'SPX04', 'vi_do' => 12.2400, 'kinh_do' => 109.1800],
            ['id' => 5, 'ma_van_don' => 'SPX05', 'vi_do' => 12.2750, 'kinh_do' => 109.1950],
        ];

        $result = $this->service->plan($start, $orders);

        // Kiểm tra đầy đủ các key theo hợp đồng dữ liệu
        $this->assertArrayHasKey('thu_tu', $result);
        $this->assertArrayHasKey('km_baseline', $result);
        $this->assertArrayHasKey('km_nn', $result);
        $this->assertArrayHasKey('km_toi_uu', $result);
        $this->assertArrayHasKey('ty_le_tiet_kiem_phan_tram', $result);
        $this->assertArrayHasKey('thoi_gian_chay_ms', $result);

        // Số lượng đơn hàng phải khớp
        $this->assertCount(count($orders), $result['thu_tu']);

        // Thứ tự giao phải được đánh số 1..n liên tục
        $deliveryOrders = array_column($result['thu_tu'], 'thu_tu_giao');
        $this->assertSame([1, 2, 3, 4, 5], $deliveryOrders);

        // Quãng đường sau 2-Opt phải luôn <= NN
        $this->assertLessThanOrEqual($result['km_nn'] + 0.05, $result['km_toi_uu']);

        // Quãng đường tối ưu phải ngắn hơn nhiều so với Baseline bị xáo trộn
        $this->assertLessThan($result['km_baseline'], $result['km_toi_uu']);
        $this->assertGreaterThan(0.0, $result['ty_le_tiet_kiem_phan_tram']);
    }

    public function testBaselineUsesThuTuFileThenIdOrder(): void
    {
        $start = ['vi_do' => 12.2488, 'kinh_do' => 109.1905];
        $orders = [
            ['id' => 10, 'thu_tu_file' => 3, 'ma_van_don' => 'SPX10', 'vi_do' => 12.2850, 'kinh_do' => 109.2050],
            ['id' => 2, 'thu_tu_file' => 1, 'ma_van_don' => 'SPX02', 'vi_do' => 12.2350, 'kinh_do' => 109.1850],
            ['id' => 7, 'thu_tu_file' => 2, 'ma_van_don' => 'SPX07', 'vi_do' => 12.2800, 'kinh_do' => 109.2000],
        ];

        $sortedOrders = $orders;
        usort($sortedOrders, static function (array $a, array $b): int {
            $fileDiff = ((int) ($a['thu_tu_file'] ?? 0)) <=> ((int) ($b['thu_tu_file'] ?? 0));
            if ($fileDiff !== 0) {
                return $fileDiff;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $baselineRoute = array_merge([$start], $sortedOrders);
        $expectedBaseline = (new \App\Services\Routing\TwoOptSolver())->calculateTotalDistance($baselineRoute);

        $result = $this->service->plan($start, $orders);

        $this->assertSame(round($expectedBaseline, 2), $result['km_baseline']);
    }
}

