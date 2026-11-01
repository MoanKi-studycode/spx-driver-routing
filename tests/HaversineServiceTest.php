<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Routing\HaversineService;
use PHPUnit\Framework\TestCase;

/**
 * Class HaversineServiceTest
 * 
 * Kiểm thử tính toán khoảng cách Haversine giữa Bưu cục P0 và các mốc tại TP. Nha Trang
 * cũng như các đặc tính toán học (đối xứng, bất đẳng thức tam giác, bán kính phục vụ).
 */
class HaversineServiceTest extends TestCase
{
    /**
     * Tọa độ Bưu cục SPX Nha Trang (P0)
     */
    private const DEPOT_P0 = [
        'lat' => 12.2488000,
        'lng' => 109.1905000,
    ];

    /**
     * Tọa độ Tâm Phường Bắc Nha Trang
     */
    private const PHUONG_BAC_NHA_TRANG = [
        'lat' => 12.2795000,
        'lng' => 109.1985000,
    ];

    /**
     * Tọa độ Tâm Phường Tây Nha Trang
     */
    private const PHUONG_TAY_NHA_TRANG = [
        'lat' => 12.2555000,
        'lng' => 109.1615000,
    ];

    /**
     * Tọa độ Bưu cục SPX Cam Ranh (Cơ sở mở rộng)
     */
    private const BUU_CUC_CAM_RANH = [
        'lat' => 11.9214000,
        'lng' => 109.1591000,
    ];

    /**
     * 1. Kiểm tra hai điểm trùng tọa độ thì khoảng cách phải bằng 0.0 km
     */
    public function testDistanceWithIdenticalCoordinates(): void
    {
        $d = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng']
        );

        $this->assertSame(0.0, $d);
    }

    /**
     * 2. Kiểm tra khoảng cách thực tế giữa Bưu cục P0 và Phường Bắc Nha Trang (~3.52 km)
     */
    public function testDistanceBetweenDepotAndNorthNhaTrang(): void
    {
        $d = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng']
        );

        // Khoảng cách trắc địa đường chim bay xấp xỉ 3.52 km (dung sai ± 0.05 km)
        $this->assertEqualsWithDelta(3.52, $d, 0.05, 'Khoảng cách từ P0 tới Bắc Nha Trang phải xấp xỉ 3.52 km');
    }

    /**
     * 3. Kiểm tra khoảng cách giữa Bưu cục P0 và Phường Tây Nha Trang (~3.23 km)
     */
    public function testDistanceBetweenDepotAndWestNhaTrang(): void
    {
        $d = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_TAY_NHA_TRANG['lat'],
            self::PHUONG_TAY_NHA_TRANG['lng']
        );

        // Khoảng cách trắc địa đường chim bay xấp xỉ 3.23 km (dung sai ± 0.05 km)
        $this->assertEqualsWithDelta(3.23, $d, 0.05, 'Khoảng cách từ P0 tới Tây Nha Trang phải xấp xỉ 3.23 km');
    }

    /**
     * 4. Kiểm tra tính đối xứng (Symmetry): distance(A, B) == distance(B, A)
     */
    public function testDistanceSymmetry(): void
    {
        $dAB = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng']
        );

        $dBA = HaversineService::distance(
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng'],
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng']
        );

        $this->assertEqualsWithDelta($dAB, $dBA, 1e-9, 'Khoảng cách giữa 2 điểm phải có tính chất đối xứng');
    }

    /**
     * 5. Kiểm tra bất đẳng thức tam giác: distance(A, C) <= distance(A, B) + distance(B, C)
     */
    public function testTriangleInequality(): void
    {
        $dAB = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng']
        );

        $dBC = HaversineService::distance(
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng'],
            self::PHUONG_TAY_NHA_TRANG['lat'],
            self::PHUONG_TAY_NHA_TRANG['lng']
        );

        $dAC = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_TAY_NHA_TRANG['lat'],
            self::PHUONG_TAY_NHA_TRANG['lng']
        );

        $this->assertLessThanOrEqual($dAB + $dBC + 1e-9, $dAC, 'Bất đẳng thức tam giác phải được thỏa mãn');
    }

    /**
     * 6. Kiểm tra tính toán khoảng cách đường bộ ước tính (hệ số uốn lượn 1.3)
     */
    public function testRoadDistanceWithFactor(): void
    {
        $direct = HaversineService::distance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng']
        );

        $road = HaversineService::roadDistance(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng']
        );

        $expectedRoad = $direct * 1.3;
        $this->assertEqualsWithDelta($expectedRoad, $road, 1e-6, 'Khoảng cách đường bộ phải bằng Haversine nhân với 1.3');
    }

    /**
     * 7. Kiểm tra bán kính phục vụ 25 km của Bưu cục P0
     */
    public function testServiceRadiusValidation(): void
    {
        $radius = 25.0; // 25 km

        // Phường Bắc Nha Trang nằm trong bán kính phục vụ của Bưu cục Nha Trang
        $inRange = HaversineService::isWithinRadius(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::PHUONG_BAC_NHA_TRANG['lat'],
            self::PHUONG_BAC_NHA_TRANG['lng'],
            $radius
        );
        $this->assertTrue($inRange, 'Phường Bắc Nha Trang phải nằm trong bán kính 25 km của Bưu cục Nha Trang');

        // Bưu cục Cam Ranh cách Bưu cục Nha Trang ~36.4 km (> 25 km) nên phải nằm ngoài bán kính phục vụ
        $outOfRange = HaversineService::isWithinRadius(
            self::DEPOT_P0['lat'],
            self::DEPOT_P0['lng'],
            self::BUU_CUC_CAM_RANH['lat'],
            self::BUU_CUC_CAM_RANH['lng'],
            $radius
        );
        $this->assertFalse($outOfRange, 'Bưu cục Cam Ranh phải nằm ngoài bán kính 25 km của Bưu cục Nha Trang');
    }
}
