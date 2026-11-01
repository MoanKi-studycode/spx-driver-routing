<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ShopeeApi\InboundService;
use App\Services\ShopeeApi\MockShopeeOrderService;
use PHPUnit\Framework\TestCase;

/**
 * Class InboundServiceTest
 * Kiểm thử bắt đơn trùng, đơn lỗi tọa độ theo bán kính phục vụ bưu cục,
 * đơn ngoài vùng phục vụ và chuẩn hóa tiếng Việt đa byte theo Mục 2.3 & 8.1.
 */
class InboundServiceTest extends TestCase
{
    private InboundService $inboundService;
    private array $buuCucNhaTrang;
    private array $validWardsNhaTrang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboundService = new InboundService();
        $this->buuCucNhaTrang = [
            'id' => 1,
            'ma_buu_cuc' => 'SPX_KH_NT01',
            'ten_buu_cuc' => 'Bưu cục SPX Nha Trang',
            'vi_do' => 12.2488000,
            'kinh_do' => 109.1905000,
            'ban_kinh_phuc_vu_km' => 25.00,
            'suc_chua_moi_cum' => 20,
        ];

        $this->validWardsNhaTrang = [
            [
                'id' => 1,
                'buu_cuc_id' => 1,
                'ma_hanh_chinh' => '22339',
                'ten_phuong' => 'Phường Bắc Nha Trang',
                'dang_hoat_dong' => 1,
            ],
            [
                'id' => 2,
                'buu_cuc_id' => 1,
                'ma_hanh_chinh' => '22366',
                'ten_phuong' => 'Phường Tây Nha Trang',
                'dang_hoat_dong' => 1,
            ],
            [
                'id' => 3,
                'buu_cuc_id' => 1,
                'ma_hanh_chinh' => '22327',
                'ten_phuong' => 'Phường Nha Trang',
                'dang_hoat_dong' => 1,
            ],
        ];
    }

    /**
     * Kiểm tra quy tắc validation Inbound tổng hợp theo Mục 2.3 & 8.1:
     * - Bước 1: Chống trùng ma_van_don (trong DB hoặc trùng ngay trong file JSON)
     * - Bước 2a/2b: Thiếu tọa độ hoặc ngoài biên [-90, 90]/[-180, 180] -> gán NULL cho cả hai, đánh dấu loi_toa_do
     * - Bước 2c: Tọa độ vượt quá ban_kinh_phuc_vu_km (25 km) -> giữ nguyên tọa độ, đánh dấu loi_toa_do
     * - Bước 3: Phường không thuộc bưu cục hiện tại -> đánh dấu ngoai_vung_phuc_vu
     * - Bước 3b: Chuẩn hóa tiếng Việt có dấu đa byte (mb_strtolower + gộp khoảng trắng)
     */
    public function testInboundValidationRules(): void
    {
        $payload = [
            'ma_lo' => 'LO_TEST_2026',
            'don_hang' => [
                // 1. Đơn hợp lệ khớp theo mã hành chính
                [
                    'ma_van_don' => 'SPXVN_VALID_01',
                    'ten_khach_hang' => 'Nguyễn Văn A',
                    'dia_chi' => '12 Phạm Văn Đồng, Phường Bắc Nha Trang',
                    'phuong' => 'Phường Bắc Nha Trang',
                    'ma_hanh_chinh_phuong' => '22339',
                    'vi_do' => 12.2725,
                    'kinh_do' => 109.2008,
                    'tien_thu_ho' => 150000,
                ],
                // 2. Đơn hợp lệ khớp theo tên phường tiếng Việt có dấu viết hoa/thừa khoảng trắng (không truyền mã HC)
                [
                    'ma_van_don' => 'SPXVN_VALID_02',
                    'ten_khach_hang' => 'Trần Thị B',
                    'dia_chi' => '105 Đường 23/10, Phường Tây Nha Trang',
                    'phuong' => '   PHƯỜNG   TÂY   NHA   TRANG  ',
                    'vi_do' => 12.2535,
                    'kinh_do' => 109.1585,
                    'tien_thu_ho' => 200000,
                ],
                // 3. Đơn trùng ma_van_don với đơn số 1 ngay trong cùng lô
                [
                    'ma_van_don' => 'SPXVN_VALID_01',
                    'ten_khach_hang' => 'Bản Sao Trùng',
                    'dia_chi' => '12 Phạm Văn Đồng',
                    'phuong' => 'Phường Bắc Nha Trang',
                    'ma_hanh_chinh_phuong' => '22339',
                    'vi_do' => 12.2725,
                    'kinh_do' => 109.2008,
                ],
                // 4. Đơn đã tồn tại trước đó trong CSDL
                [
                    'ma_van_don' => 'SPXVN_EXISTING_DB',
                    'ten_khach_hang' => 'Đơn Đã Tồn Tại DB',
                    'dia_chi' => '38 Trần Phú',
                    'phuong' => 'Phường Nha Trang',
                    'ma_hanh_chinh_phuong' => '22327',
                    'vi_do' => 12.2415,
                    'kinh_do' => 109.1965,
                ],
                // 5. Đơn lỗi tọa độ (Bước 2a: không phải số -> gán NULL cả vi_do và kinh_do)
                [
                    'ma_van_don' => 'SPXVN_ERR_COORD_NULL',
                    'ten_khach_hang' => 'Khách Thiếu Tọa Độ',
                    'dia_chi' => '45 Tô Hiến Thành',
                    'phuong' => 'Phường Nha Trang',
                    'ma_hanh_chinh_phuong' => '22327',
                    'vi_do' => 'abc',
                    'kinh_do' => 109.1908,
                ],
                // 6. Đơn lỗi tọa độ (Bước 2b: ngoài biên tuyệt đối [-90, 90] -> gán NULL cả hai)
                [
                    'ma_van_don' => 'SPXVN_ERR_COORD_OUT_OF_BOUNDS',
                    'ten_khach_hang' => 'Khách Sai Biên Độ',
                    'dia_chi' => '45 Tô Hiến Thành',
                    'phuong' => 'Phường Nha Trang',
                    'ma_hanh_chinh_phuong' => '22327',
                    'vi_do' => 95.1234,
                    'kinh_do' => 109.1908,
                ],
                // 7. Đơn lỗi tọa độ (Bước 2c: trong biên tuyệt đối nhưng cách P0 > 25 km -> giữ nguyên tọa độ)
                [
                    'ma_van_don' => 'SPXVN_ERR_COORD_RADIUS',
                    'ten_khach_hang' => 'Khách Vượt Bán Kính 25km',
                    'dia_chi' => '88 Cửu Long, Phường Nha Trang',
                    'phuong' => 'Phường Nha Trang',
                    'ma_hanh_chinh_phuong' => '22327',
                    'vi_do' => 11.9214,
                    'kinh_do' => 109.1591,
                ],
                // 8. Đơn ngoài vùng phục vụ (Bước 3: Phường thuộc Cam Ranh, không thuộc Bưu cục Nha Trang)
                [
                    'ma_van_don' => 'SPXVN_ERR_WARD_CAM_RANH',
                    'ten_khach_hang' => 'Khách Ngoài Vùng Phục Vụ',
                    'dia_chi' => '120 Trần Phú, Nha Trang',
                    'phuong' => 'Phường Cam Thuận',
                    'ma_hanh_chinh_phuong' => '22423',
                    'vi_do' => 12.2255,
                    'kinh_do' => 109.1975,
                ],
            ],
        ];

        $result = $this->inboundService->processBatch(
            $payload,
            $this->buuCucNhaTrang,
            $this->validWardsNhaTrang,
            ['SPXVN_EXISTING_DB']
        );

        $this->assertSame(8, $result['tong_so_don_trong_lo']);
        $this->assertSame(2, $result['so_don_hop_le']);
        $this->assertSame(2, $result['so_don_bo_qua_do_trung']);
        $this->assertSame(3, $result['so_don_loi_toa_do']);
        $this->assertSame(1, $result['so_don_ngoai_vung_phuc_vu']);
        $this->assertCount(6, $result['tat_ca_don_can_luu']);

        // Kiểm tra đơn hợp lệ số 2 đã khớp đúng Phường Tây Nha Trang (id = 2) nhờ chuẩn hóa mb_strtolower
        $this->assertSame('SPXVN_VALID_02', $result['don_hop_le'][1]['ma_van_don']);
        $this->assertSame(2, $result['don_hop_le'][1]['phuong_id']);
        $this->assertNull($result['don_hop_le'][1]['ly_do_chua_phan_cong']);

        // Kiểm tra Bước 2a & 2b: gán NULL cho cả vi_do và kinh_do
        $this->assertNull($result['don_loi_toa_do'][0]['vi_do']);
        $this->assertNull($result['don_loi_toa_do'][0]['kinh_do']);
        $this->assertSame('loi_toa_do', $result['don_loi_toa_do'][0]['ly_do_chua_phan_cong']);

        $this->assertNull($result['don_loi_toa_do'][1]['vi_do']);
        $this->assertNull($result['don_loi_toa_do'][1]['kinh_do']);
        $this->assertSame('loi_toa_do', $result['don_loi_toa_do'][1]['ly_do_chua_phan_cong']);

        // Kiểm tra Bước 2c: vượt bán kính > 25km thì giữ nguyên tọa độ để hiển thị điểm sai trên bản đồ
        $this->assertSame(11.9214, $result['don_loi_toa_do'][2]['vi_do']);
        $this->assertSame(109.1591, $result['don_loi_toa_do'][2]['kinh_do']);
        $this->assertSame('loi_toa_do', $result['don_loi_toa_do'][2]['ly_do_chua_phan_cong']);

        // Kiểm tra Bước 3: ngoài vùng phục vụ
        $this->assertSame('SPXVN_ERR_WARD_CAM_RANH', $result['don_ngoai_vung'][0]['ma_van_don']);
        $this->assertNull($result['don_ngoai_vung'][0]['phuong_id']);
        $this->assertSame('ngoai_vung_phuc_vu', $result['don_ngoai_vung'][0]['ly_do_chua_phan_cong']);
    }

    /**
     * Kiểm tra đọc file mẫu data/don_hang_mau.json qua MockShopeeOrderService và chạy qua InboundService
     */
    public function testMockShopeeOrderServiceWithSampleJson(): void
    {
        $mockService = new MockShopeeOrderService();
        $payload = $mockService->fetchOrders();

        $this->assertArrayHasKey('ma_lo', $payload);
        $this->assertArrayHasKey('don_hang', $payload);
        $this->assertCount(45, $payload['don_hang']);

        $result = $this->inboundService->processBatch(
            $payload,
            $this->buuCucNhaTrang,
            $this->validWardsNhaTrang
        );

        $this->assertSame(43, $result['so_don_hop_le']);
        $this->assertSame(1, $result['so_don_loi_toa_do']);
        $this->assertSame(1, $result['so_don_ngoai_vung_phuc_vu']);
        $this->assertSame(0, $result['so_don_bo_qua_do_trung']);
    }
}
?>