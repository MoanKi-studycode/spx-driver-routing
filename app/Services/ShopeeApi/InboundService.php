<?php

declare(strict_types=1);

namespace App\Services\ShopeeApi;

use App\Services\Routing\HaversineService;

/**
 * Class InboundService
 * Xử lý tiếp nhận, validate tọa độ theo bán kính bưu cục, chống trùng và lọc đơn hàng theo quy tắc Mục 2.3.
 */
class InboundService
{
    /**
     * Bán kính Trái Đất trung bình (km) dùng khi tính khoảng cách Haversine
     */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Xử lý và phân loại lô đơn hàng đầu vào theo bưu cục (Mục 2.3)
     *
     * @param array $payload Dữ liệu thô từ Mock API (chứa key `ma_lo`, `don_hang`)
     * @param array $buuCuc Thông tin bưu cục hiện tại (id, vi_do, kinh_do, ban_kinh_phuc_vu_km)
     * @param array $validWards Danh sách phường hợp lệ của bưu cục hiện tại
     * @param array $existingCodes Danh sách hoặc map các `ma_van_don` đã tồn tại trong CSDL
     * @return array Kết quả phân loại: đơn hợp lệ, đơn lỗi tọa độ, đơn ngoài vùng phục vụ, đơn trùng và bộ đếm
     */
    public function processBatch(
        array $payload,
        array $buuCuc,
        array $validWards,
        array $existingCodes = []
    ): array {
        $buuCucId = (int) ($buuCuc['id'] ?? 1);
        $depotLat = (float) ($buuCuc['vi_do'] ?? (defined('BUU_CUC_VI_DO_MAC_DINH') ? BUU_CUC_VI_DO_MAC_DINH : 12.2488000));
        $depotLng = (float) ($buuCuc['kinh_do'] ?? (defined('BUU_CUC_KINH_DO_MAC_DINH') ? BUU_CUC_KINH_DO_MAC_DINH : 109.1905000));
        $maxRadiusKm = (float) ($buuCuc['ban_kinh_phuc_vu_km'] ?? (defined('BAN_KINH_PHUC_VU_KM_MAC_DINH') ? BAN_KINH_PHUC_VU_KM_MAC_DINH : 25.00));

        $maLo = isset($payload['ma_lo']) && trim((string) $payload['ma_lo']) !== ''
            ? trim((string) $payload['ma_lo'])
            : 'LO_' . date('Ymd_His');

        $rawOrders = isset($payload['don_hang']) && is_array($payload['don_hang'])
            ? $payload['don_hang']
            : [];

        // Xây dựng bảng tra cứu mã vận đơn đã tồn tại để chống trùng Bước 1
        $seenTrackingCodes = $this->buildExistingCodeLookup($existingCodes);

        // Xây dựng bảng tra cứu Phường/Xã hợp lệ của Bưu cục hiện tại (Bước 3)
        $wardByCode = [];
        $wardByNormalizedName = [];
        foreach ($validWards as $ward) {
            $wardBuuCucId = isset($ward['buu_cuc_id']) ? (int) $ward['buu_cuc_id'] : $buuCucId;
            $wardActive = isset($ward['dang_hoat_dong']) ? (int) $ward['dang_hoat_dong'] : 1;
            if ($wardBuuCucId !== $buuCucId || $wardActive !== 1) {
                continue;
            }

            $code = trim((string) ($ward['ma_hanh_chinh'] ?? ''));
            if ($code !== '') {
                $wardByCode[$code] = $ward;
            }

            $normName = self::normalizeVietnamese((string) ($ward['ten_phuong'] ?? ''));
            if ($normName !== '') {
                $wardByNormalizedName[$normName] = $ward;
            }
        }

        $donHopLe = [];
        $donLoiToaDo = [];
        $donNgoaiVung = [];
        $donTrung = [];
        $tatCaDonCanLuu = [];

        foreach (array_values($rawOrders) as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $thuTuFile = isset($raw['thu_tu_file']) ? (int) $raw['thu_tu_file'] : ($index + 1);
            $maVanDon = trim((string) ($raw['ma_van_don'] ?? ''));

            // Bước 1: Kiểm tra chống trùng hoặc thiếu ma_van_don
            if ($maVanDon === '' || isset($seenTrackingCodes[$maVanDon])) {
                $donTrung[] = $raw;
                continue;
            }
            $seenTrackingCodes[$maVanDon] = true;

            // Đối chiếu Phường/Xã để chuẩn bị thông tin cho Bước 2 và Bước 3
            $matchedWard = $this->matchWard($raw, $wardByCode, $wardByNormalizedName);

            // Bước 2: Kiểm tra tọa độ theo biên tuyệt đối và bán kính phục vụ động của Bưu cục P0
            $coordCheck = $this->validateCoordinates(
                $raw['vi_do'] ?? null,
                $raw['kinh_do'] ?? null,
                $depotLat,
                $depotLng,
                $maxRadiusKm
            );

            $record = [
                'buu_cuc_id' => $buuCucId,
                'ma_van_don' => $maVanDon,
                'ma_don_shopee' => isset($raw['ma_don_shopee']) ? trim((string) $raw['ma_don_shopee']) : null,
                'ma_lo' => $maLo,
                'thu_tu_file' => $thuTuFile,
                'ten_khach_hang' => trim((string) ($raw['ten_khach_hang'] ?? 'Khách hàng Shopee')),
                'so_dien_thoai_khach' => isset($raw['so_dien_thoai_khach'])
                    ? trim((string) $raw['so_dien_thoai_khach'])
                    : (isset($raw['so_dien_thoai']) ? trim((string) $raw['so_dien_thoai']) : null),
                'dia_chi_giao_hang' => trim((string) ($raw['dia_chi_giao_hang'] ?? ($raw['dia_chi'] ?? ''))),
                'tinh_thanh_goc' => isset($raw['tinh_thanh_goc'])
                    ? trim((string) $raw['tinh_thanh_goc'])
                    : (isset($raw['tinh_thanh']) ? trim((string) $raw['tinh_thanh']) : null),
                'quan_huyen_goc' => isset($raw['quan_huyen_goc'])
                    ? trim((string) $raw['quan_huyen_goc'])
                    : (isset($raw['quan_huyen']) ? trim((string) $raw['quan_huyen']) : null),
                'phuong_ten_goc' => isset($raw['phuong_ten_goc'])
                    ? trim((string) $raw['phuong_ten_goc'])
                    : (isset($raw['phuong']) ? trim((string) $raw['phuong']) : null),
                'phuong_id' => $matchedWard !== null ? (int) $matchedWard['id'] : null,
                'vi_do' => $coordCheck['vi_do'],
                'kinh_do' => $coordCheck['kinh_do'],
                'khoang_cach_buu_cuc_km' => $coordCheck['distance_km'],
                'tien_thu_ho' => isset($raw['tien_thu_ho']) && is_numeric($raw['tien_thu_ho'])
                    ? round((float) $raw['tien_thu_ho'], 2)
                    : 0.00,
                'trang_thai' => 'chua_phan_cong',
                'ly_do_chua_phan_cong' => null,
            ];

            if (!$coordCheck['hop_le']) {
                // Bước 2: Lỗi tọa độ (thiếu/không phải số/ngoài biên [-90,90],[-180,180] -> NULL; vượt bán kính -> giữ nguyên tọa độ)
                $record['ly_do_chua_phan_cong'] = 'loi_toa_do';
                $donLoiToaDo[] = $record;
                $tatCaDonCanLuu[] = $record;
                continue;
            }

            // Bước 3: Kiểm tra Phường/Xã thuộc vùng phục vụ của Bưu cục hiện tại
            if ($matchedWard === null) {
                $record['phuong_id'] = null;
                $record['ly_do_chua_phan_cong'] = 'ngoai_vung_phuc_vu';
                $donNgoaiVung[] = $record;
                $tatCaDonCanLuu[] = $record;
                continue;
            }

            // Bước 4: Đơn hàng hợp lệ, sẵn sàng đưa vào thuật toán Phân cụm Balanced K-Means
            $record['phuong_id'] = (int) $matchedWard['id'];
            $record['ly_do_chua_phan_cong'] = null;
            $donHopLe[] = $record;
            $tatCaDonCanLuu[] = $record;
        }

        return [
            'ma_lo' => $maLo,
            'buu_cuc_id' => $buuCucId,
            'don_hop_le' => $donHopLe,
            'don_loi_toa_do' => $donLoiToaDo,
            'don_ngoai_vung' => $donNgoaiVung,
            'don_trung' => $donTrung,
            'tat_ca_don_can_luu' => $tatCaDonCanLuu,
            'tong_so_don_trong_lo' => count($rawOrders),
            'so_don_hop_le' => count($donHopLe),
            'so_don_loi_toa_do' => count($donLoiToaDo),
            'so_don_ngoai_vung_phuc_vu' => count($donNgoaiVung),
            'so_don_bo_qua_do_trung' => count($donTrung),
        ];
    }

    /**
     * Chuẩn hóa chuỗi tiếng Việt đa byte theo đúng công thức Mục 2.3 Bước 3:
     * mb_strtolower(preg_replace('/\s+/u', ' ', trim($phuong)), 'UTF-8')
     *
     * @param string $value
     * @return string
     */
    public static function normalizeVietnamese(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        return mb_strtolower((string) $collapsed, 'UTF-8');
    }

    /**
     * Kiểm tra tọa độ đơn hàng so với biên tuyệt đối và bán kính phục vụ của Bưu cục P0 (Mục 2.3 Bước 2)
     *
     * @param mixed $rawLat
     * @param mixed $rawLng
     * @param float $depotLat
     * @param float $depotLng
     * @param float $maxRadiusKm
     * @return array{hop_le: bool, vi_do: float|null, kinh_do: float|null, distance_km: float|null}
     */
    public function validateCoordinates(
        mixed $rawLat,
        mixed $rawLng,
        float $depotLat,
        float $depotLng,
        float $maxRadiusKm
    ): array {
        // (a) Thiếu vi_do hoặc kinh_do, hoặc không phải số (!is_numeric) -> gán NULL cho cả hai
        if (
            $rawLat === null || $rawLng === null
            || $rawLat === '' || $rawLng === ''
            || is_bool($rawLat) || is_bool($rawLng)
            || !is_numeric($rawLat) || !is_numeric($rawLng)
        ) {
            return [
                'hop_le' => false,
                'vi_do' => null,
                'kinh_do' => null,
                'distance_km' => null,
            ];
        }

        $lat = (float) $rawLat;
        $lng = (float) $rawLng;

        // (b) Nằm ngoài biên tuyệt đối [-90, 90] / [-180, 180] -> gán NULL cho cả hai
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return [
                'hop_le' => false,
                'vi_do' => null,
                'kinh_do' => null,
                'distance_km' => null,
            ];
        }

        // (c) Nằm trong biên tuyệt đối nhưng khoảng cách Haversine vượt quá ban_kinh_phuc_vu_km -> giữ nguyên tọa độ
        $distanceKm = $this->calculateHaversineKm($depotLat, $depotLng, $lat, $lng);
        if ($distanceKm > $maxRadiusKm) {
            return [
                'hop_le' => false,
                'vi_do' => $lat,
                'kinh_do' => $lng,
                'distance_km' => round($distanceKm, 3),
            ];
        }

        return [
            'hop_le' => true,
            'vi_do' => $lat,
            'kinh_do' => $lng,
            'distance_km' => round($distanceKm, 3),
        ];
    }

    /**
     * Đối chiếu Phường/Xã của đơn hàng với danh mục phường hợp lệ của Bưu cục (Mục 2.3 Bước 3)
     * Ưu tiên khớp theo `ma_hanh_chinh_phuong` (nếu có), sau đó theo tên chuẩn hóa `mb_strtolower`.
     *
     * @param array $rawOrder
     * @param array<string, array> $wardByCode
     * @param array<string, array> $wardByNormalizedName
     * @return array|null
     */
    private function matchWard(array $rawOrder, array $wardByCode, array $wardByNormalizedName): ?array
    {
        $maHanhChinh = trim((string) ($rawOrder['ma_hanh_chinh_phuong'] ?? ($rawOrder['ma_hanh_chinh'] ?? '')));
        if ($maHanhChinh !== '') {
            if (isset($wardByCode[$maHanhChinh])) {
                return $wardByCode[$maHanhChinh];
            }
            // Nếu JSON có truyền rõ ma_hanh_chinh_phuong nhưng mã đó không thuộc bưu cục hiện tại -> không khớp
            return null;
        }

        $rawWardName = (string) ($rawOrder['phuong'] ?? ($rawOrder['phuong_ten_goc'] ?? ''));
        $normalizedName = self::normalizeVietnamese($rawWardName);
        if ($normalizedName !== '' && isset($wardByNormalizedName[$normalizedName])) {
            return $wardByNormalizedName[$normalizedName];
        }

        return null;
    }

    /**
     * Tính khoảng cách Haversine (km) giữa 2 điểm.
     * Ưu tiên gọi HaversineService::distance() của Thành viên 1, có công thức dự phòng nếu hàm đó chưa được merge.
     *
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float
     */
    private function calculateHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        if (abs($lat1 - $lat2) < 1e-12 && abs($lng1 - $lng2) < 1e-12) {
            return 0.0;
        }

        if (class_exists(HaversineService::class)) {
            $dist = HaversineService::distance($lat1, $lng1, $lat2, $lng2);
            if ($dist > 0.0) {
                return $dist;
            }
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Chuẩn hóa danh sách mã vận đơn đã có thành mảng associative tra cứu O(1)
     *
     * @param array $existingCodes
     * @return array<string, bool>
     */
    private function buildExistingCodeLookup(array $existingCodes): array
    {
        $lookup = [];
        foreach ($existingCodes as $key => $val) {
            if (is_string($key) && is_bool($val)) {
                $code = trim($key);
                if ($code !== '' && $val) {
                    $lookup[$code] = true;
                }
            } elseif (is_string($val) || is_numeric($val)) {
                $code = trim((string) $val);
                if ($code !== '') {
                    $lookup[$code] = true;
                }
            }
        }

        return $lookup;
    }
}
?>