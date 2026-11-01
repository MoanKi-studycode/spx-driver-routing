<?php

declare(strict_types=1);

namespace App\Services\Routing;

/**
 * Class HaversineService
 * 
 * Tính khoảng cách trắc địa đường chim bay (Great-Circle Distance) giữa hai điểm tọa độ (km)
 * sử dụng công thức Haversine và tính khoảng cách đường bộ ước tính qua hệ số uốn lượn.
 */
class HaversineService
{
    /**
     * Bán kính trung bình chuẩn của Trái Đất theo km (IUGG mean Earth radius)
     */
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Hệ số uốn lượn đường bộ mặc định (Road factor)
     */
    public const DEFAULT_ROAD_FACTOR = 1.3;

    /**
     * Ngưỡng sai số tọa độ trùng nhau
     */
    private const EPSILON = 1e-12;

    /**
     * Tính khoảng cách Haversine đường chim bay giữa 2 tọa độ (đơn vị: km)
     *
     * Công thức:
     * a = sin²(Δlat/2) + cos(lat1) * cos(lat2) * sin²(Δlng/2)
     * c = 2 * atan2(√a, √(1-a))
     * d = R * c
     *
     * @param float $lat1 Vĩ độ điểm 1 (độ thập phân)
     * @param float $lng1 Kinh độ điểm 1 (độ thập phân)
     * @param float $lat2 Vĩ độ điểm 2 (độ thập phân)
     * @param float $lng2 Kinh độ điểm 2 (độ thập phân)
     * @return float Khoảng cách đường chim bay tính bằng km (>= 0.0)
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        // Kiểm tra nếu 2 điểm trùng nhau hoặc chênh lệch vô cùng nhỏ
        if (abs($lat1 - $lat2) < self::EPSILON && abs($lng1 - $lng2) < self::EPSILON) {
            return 0.0;
        }

        // Chuyển đổi độ thập phân sang radian
        $lat1Rad = deg2rad($lat1);
        $lng1Rad = deg2rad($lng1);
        $lat2Rad = deg2rad($lat2);
        $lng2Rad = deg2rad($lng2);

        $dLat = $lat2Rad - $lat1Rad;
        $dLng = $lng2Rad - $lng1Rad;

        $sinHalfLat = sin($dLat / 2.0);
        $sinHalfLng = sin($dLng / 2.0);

        $a = ($sinHalfLat * $sinHalfLat) +
             cos($lat1Rad) * cos($lat2Rad) * ($sinHalfLng * $sinHalfLng);

        // Kẹp giá trị a trong khoảng [0.0, 1.0] để tránh lỗi số học dấu phẩy động
        $a = max(0.0, min(1.0, $a));

        $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Tính khoảng cách đường bộ ước tính (Road Distance) = Haversine * HE_SO_DUONG_BO
     *
     * @param float $lat1 Vĩ độ điểm 1
     * @param float $lng1 Kinh độ điểm 1
     * @param float $lat2 Vĩ độ điểm 2
     * @param float $lng2 Kinh độ điểm 2
     * @param float|null $roadFactor Hệ số đường bộ tùy chọn (mặc định lấy hằng số HE_SO_DUONG_BO hoặc 1.3)
     * @return float Khoảng cách đường bộ tính bằng km
     */
    public static function roadDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        ?float $roadFactor = null
    ): float {
        $factor = $roadFactor ?? (defined('HE_SO_DUONG_BO') ? (float) HE_SO_DUONG_BO : self::DEFAULT_ROAD_FACTOR);
        return self::distance($lat1, $lng1, $lat2, $lng2) * $factor;
    }

    /**
     * Kiểm tra xem 2 tọa độ có nằm trong bán kính phục vụ cho phép hay không
     *
     * @param float $lat1 Vĩ độ tâm
     * @param float $lng1 Kinh độ tâm
     * @param float $lat2 Vĩ độ điểm cần kiểm tra
     * @param float $lng2 Kinh độ điểm cần kiểm tra
     * @param float $radiusKm Bán kính phục vụ (km)
     * @return bool True nếu nằm trong bán kính, False nếu vượt quá
     */
    public static function isWithinRadius(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        float $radiusKm
    ): bool {
        return self::distance($lat1, $lng1, $lat2, $lng2) <= $radiusKm;
    }
}
