# Kế Hoạch & Checklist Nâng Cấp Hệ Thống Định Tuyến SPX Express (Dành Cho Thành Viên 2 & Thành Viên 3)

Tài liệu này quy định danh sách đầu việc, phạm vi file được phép chỉnh sửa, cấu trúc dữ liệu khớp nối và tiêu chí nghiệm thu dành cho **Thành viên 2** và **Thành viên 3** trên kho mã nguồn [dinh-tuyen-spx-open-source](https://github.com/TNHung3006/dinh-tuyen-spx-open-source), bám sát phân quyền nhóm tại Mục 10 của [docs/THIET_KE.md](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/docs/THIET_KE.md).

## 1. Nguyên Tắc Phối Hợp & Chống Xung Đột Git (Zero Merge Conflict)

| Thành viên | Phụ trách chính | Phạm vi File ĐƯỢC PHÉP sửa | Phạm vi File KHÔNG ĐƯỢC sửa |
| :--- | :--- | :--- | :--- |
| **Thành viên 2** | CSDL 3NF, Trang Điều phối & Trang Thống kê | [database/schema.sql](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/database/schema.sql), [database/seed.php](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/database/seed.php), [data/don_hang_mau.json](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/tree/master/data), [app/Repositories/](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/tree/master/app/Repositories), [app/Controllers/DieuPhoiController.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/DieuPhoiController.php), [app/Controllers/ThongKeController.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/ThongKeController.php), [app/Views/dieu_phoi/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/dieu_phoi/index.php), [app/Views/thong_ke/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/thong_ke/index.php), [public/assets/js/ban_do_dieu_phoi.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/ban_do_dieu_phoi.js), [public/assets/js/bieu_do.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/bieu_do.js) | Toàn bộ `app/Core/*`, `app/Services/Clustering/*`, `app/Services/Routing/*`, và các file của Thành viên 3 |
| **Thành viên 3** | Xác thực, Cổng Tra cứu Vận đơn, Trang Tài xế & Layout/CSS | [app/Controllers/AuthController.php](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/AuthController.php), [app/Controllers/TaiXeController.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/TaiXeController.php), [app/Views/auth/login.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/auth/login.php), [app/Views/tai_xe/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/tai_xe/index.php), [app/Views/layouts/](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/tree/master/app/Views/layouts), [public/assets/js/ban_do_tai_xe.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/ban_do_tai_xe.js), [public/assets/css/app.css](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/css/app.css) | Toàn bộ `app/Core/*`, `app/Services/*`, `app/Repositories/*`, và các file của Thành viên 2 |

## 2. Hợp Đồng Dữ Liệu Khớp Nối Giữa Thành Viên 2 & Thành Viên 3 (Cần Chốt Trong 30 Phút Đầu)

Trước khi tách nhánh làm độc lập, **Thành viên 2** thực hiện nâng cấp nhẹ CSDL và Repository rồi push lên nhánh chung để **Thành viên 3** sử dụng:

- [x] **Bổ sung cột lưu lý do giao thất bại vào bảng `don_hang`** tại [database/schema.sql](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/database/schema.sql):
  ```sql
  ALTER TABLE don_hang
  ADD COLUMN ly_do_that_bai VARCHAR(255) NULL DEFAULT NULL AFTER trang_thai;
  ```
- [x] **Chuẩn hóa hàm cập nhật kết quả giao hàng trong `DonHangRepository.php`** tại [app/Repositories/](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/tree/master/app/Repositories):
  - Hỗ trợ nhận thêm tham số `$lyDoThatBai = null` khi `trang_thai = 'giao_that_bai'` và tự động cập nhật `thoi_gian_hoan_tat = NOW()`.
- [x] **Bổ sung hàm tra cứu công khai `timTheoMaVanDon(string $maVanDon)` trong `DonHangRepository.php`**:
  - Trả về thông tin đơn hàng kèm tên bưu cục (`buu_cuc.ten_buu_cuc`), tên phường (`phuong.ten_phuong`), tên & SĐT tài xế phụ trách (`nguoi_dung.ho_ten`, `nguoi_dung.so_dien_thoai`), thứ tự điểm giao (`thu_tu_giao`), trạng thái đơn (`trang_thai`), thời gian hoàn tất (`thoi_gian_hoan_tat`) và lý do thất bại (`ly_do_that_bai`).

## 3. Checklist Chi Tiết Dành Cho Thành Viên 2 (CSDL, Điều Phối & Thống Kê)

### A. Nâng cấp CSDL, Seed Data & Tầng Repository
- [x] **Cập nhật [database/schema.sql](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/database/schema.sql) và [database/seed.php](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/database/seed.php)**:
  - Thêm cột `ly_do_that_bai` vào bảng `don_hang`.
  - Bổ sung dữ liệu mồi trong `seed.php` có đủ cả 4 trạng thái (`chua_phan_cong`, `da_phan_cong`, `giao_thanh_cong`, `giao_that_bai`) kèm số tiền `tien_thu_ho` (COD) thực tế (từ `0` đến `1.500.000 VNĐ`) để biểu đồ Thống kê lên số liệu đẹp ngay khi chạy thử.
- [x] **Bổ sung các phương thức truy vấn thống kê vận hành trong `DonHangRepository.php` và `CumTuyenGiaoRepository.php`** ([app/Repositories/](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/tree/master/app/Repositories)):
  - `thongKeTrangThaiDonHang(?int $buuCucId = null)`: Đếm số lượng đơn theo 4 trạng thái (`chua_phan_cong`, `da_phan_cong`, `giao_thanh_cong`, `giao_that_bai`).
  - `thongKeHieuSuatVaCodTheoTaiXe(?int $buuCucId = null)`: Trả về danh sách từng tài xế kèm:
    - Số đơn đang giao (`da_phan_cong`), đã giao (`giao_thanh_cong`), giao thất bại (`giao_that_bai`).
    - Tổng tiền COD phải thu (`SUM(tien_thu_ho)` của toàn bộ đơn được gán) và Tổng tiền COD thực thu (`SUM(tien_thu_ho)` của các đơn `giao_thanh_cong`).
  - `thongKeDonHangTheoPhuong(?int $buuCucId = null)`: Đếm số đơn hàng và tỷ lệ hoàn thành theo từng Phường/Xã tại Nha Trang.

### B. Tối ưu Nghiệp vụ & UI/UX Trang Điều Phối
- [x] **Bảo vệ lộ trình đang giao (Dispatch Lock) trong [app/Controllers/DieuPhoiController.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/DieuPhoiController.php)**:
  - Khi Điều phối viên bấm chạy thuật toán phân cụm tối ưu (`/api/dieu-phoi/phan-cum-toi-uu`), kiểm tra và giữ nguyên các cụm tuyến đang ở trạng thái đang đi giao thực tế (hoặc chỉ chạy phân cụm cho các đơn `chua_phan_cong`), tránh ghi đè làm mất lộ trình của Shipper đang di chuyển ngoài đường.
  - Thêm cảnh báo xác nhận (Confirm Modal) nếu Điều phối viên muốn "Reset & Phân cụm lại toàn bộ".
- [x] **Thêm tính năng Chuyển cụm / Đổi tài xế thủ công cho đơn hàng**:
  - Cho phép Điều phối viên chọn 1 đơn hàng trên bảng hoặc bấm vào Popup Marker trên [public/assets/js/ban_do_dieu_phoi.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/ban_do_dieu_phoi.js) để chuyển sang cụm tuyến của tài xế khác.
  - Sau khi chuyển cụm, gọi lại `DieuPhoiLoTrinhService` để cập nhật thứ tự giao (`thu_tu_giao`) và số km tối ưu (`km_toi_uu`) cho cụm vừa thay đổi.
- [x] **Nâng cấp công cụ Sửa đơn lỗi tọa độ (Nominatim Geocoding Preview)**:
  - Trong [public/assets/js/ban_do_dieu_phoi.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/ban_do_dieu_phoi.js), khi tìm được tọa độ từ địa chỉ qua Nominatim API, hiển thị ngay một **Draggable Marker (Marker kéo-thả được)** trên bản đồ để Điều phối viên xem trước và tinh chỉnh đúng vị trí hẻm/đường trước khi bấm "Lưu tọa độ".
- [x] **Gom gọn bố cục giao diện [app/Views/dieu_phoi/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/dieu_phoi/index.php)**:
  - Chia khu vực danh sách bên dưới/bên cạnh bản đồ thành **3 Tab Bootstrap**:
    1. *Tab Cụm Tuyến & Tài Xế* (hiển thị tiến độ từng cụm, số đơn, số km, tổng COD).
    2. *Tab Đơn Hàng Lỗi Tọa Độ* (hiển thị badge số lượng đơn lỗi màu đỏ cần xử lý).
    3. *Tab Danh Sách Tất Cả Đơn Hàng* (có bộ lọc nhanh theo trạng thái và tìm kiếm mã vận đơn).
  - Thêm lớp phủ **Loading Spinner** toàn màn hình kèm thông báo trạng thái khi đang đồng bộ Shopee hoặc đang chạy thuật toán phân cụm.

### C. Tái cấu trúc Trang Thống Kê (Đáp ứng cả Admin Bưu Cục & Hội Đồng Chấm Đồ Án)
- [x] **Chia [app/Views/thong_ke/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/thong_ke/index.php) thành 2 Tab độc lập**:
  - **Tab 1 (Mặc định) — Quản Trị Vận Hành Bưu Cục (Operational Dashboard)**:
    - Hiển thị **4 Thẻ KPI Vận hành**:
      1. *Tổng số đơn hàng hôm nay* (kèm số đơn chờ phân công).
      2. *Tỷ lệ giao hàng thành công (SLA %)* (`Số đơn thành công / Tổng số đơn đã xử lý`).
      3. *Tiền thu hộ COD đã thu / Tổng COD phải thu (VNĐ)* (phục vụ kế toán bưu cục thu tiền mặt cuối ca).
      4. *Số đơn giao thất bại cần xử lý lại*.
    - Vẽ **3 Biểu đồ vận hành thực tế** trong [public/assets/js/bieu_do.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/bieu_do.js):
      1. **Biểu đồ Tỷ lệ Trạng thái Đơn hàng (`chart-trang-thai-don` - Doughnut Chart)**: Trực quan hóa 4 trạng thái (`Chưa phân công`, `Đang giao`, `Giao thành công`, `Giao thất bại`).
      2. **Biểu đồ Tiến độ Giao hàng theo Tài xế (`chart-tien-do-tai-xe` - Stacked Bar Chart)**: Mỗi cột là 1 Shipper, xếp chồng 3 màu (`Đã giao`, `Đang giao`, `Thất bại`) để Admin biết ai đã xong ca, ai đang quá tải.
      3. **Biểu đồ Đối soát Tiền thu hộ COD theo Tài xế (`chart-doi-soat-cod` - Grouped Bar Chart)**: So sánh *Tổng COD cần thu* và *Tổng COD thực tế đã thu về* của từng Shipper.
    - Hiển thị **Bảng Đối Soát Chi Tiết Cụm Tuyến & COD Cuối Ngày** (cho phép Admin kiểm tra số tiền từng tài xế phải nộp về bưu cục).
  - **Tab 2 — Đánh Giá & Thực Nghiệm Thuật Toán (Algorithm Benchmark)**:
    - Giữ nguyên 4 thẻ KPI thuật toán (*Baseline km*, *Nearest Neighbor km*, *2-Opt km & % tiết kiệm*, *Thời gian chạy ms*), nút chạy Benchmark (10, 20, 30, 40 đơn) và 2 biểu đồ `chart-quang-duong`, `chart-thoi-gian` để trình diễn trước hội đồng bảo vệ đồ án.
- [x] **Cập nhật [app/Controllers/ThongKeController.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/ThongKeController.php)**:
  - Truyền đầy đủ dữ liệu vận hành (trạng thái đơn, tiến độ tài xế, đối soát COD) song song với dữ liệu Benchmark thuật toán xuống View và API `/api/thong-ke/so-lieu`.

## 4. Checklist Chi Tiết Dành Cho Thành Viên 3 (Ứng Dụng Tài Xế, Tra Cứu Vận Đơn & UI/UX Chung)

### A. Tối ưu Nghiệp vụ & UI/UX Trang Tài Xế Thực Địa (Mobile-First)
- [ ] **Thiết kế lại Thẻ "Đơn Hàng Tiếp Theo" (Next-Stop Sticky Card) trong [app/Views/tai_xe/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/tai_xe/index.php)**:
  - Làm nổi bật điểm dừng kế tiếp ở ngay đầu màn hình di động với cỡ chữ lớn, độ tương phản cao để nhìn rõ ngoài trời nắng.
  - Hiển thị rõ **Số tiền cần thu hộ COD (`tien_thu_ho`)** bằng badge màu cam/đỏ nổi bật (nếu `tien_thu_ho == 0` thì hiển thị badge *"Đơn 0đ - Không thu tiền"*).
  - Thêm nút bấm **Gọi điện trực tiếp cho khách** (`<a href="tel:<?= $donHang['sdt_nguoi_nhan'] ?>" class="btn btn-outline-primary">`) và nút **Mở Google Maps chỉ đường ngoài** (`https://www.google.com/maps/dir/?api=1&destination=lat,lng`).
- [ ] **Bổ sung Modal Chọn Lý Do Giao Thất Bại Bắt Buộc**:
  - Khi tài xế bấm nút *"Giao thất bại"* trên [app/Views/tai_xe/index.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/tai_xe/index.php), không gửi API ngay mà mở Modal yêu cầu chọn lý do:
    - `Khách không nghe máy (đã gọi 3 lần)`
    - `Khách hẹn giao lại ca sau / ngày mai`
    - `Sai địa chỉ hoặc số điện thoại không tồn tại`
    - `Khách từ chối nhận hàng`
    - `Lý do khác (nhập ghi chú)`
  - Cập nhật hàm xử lý trong [public/assets/js/ban_do_tai_xe.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/ban_do_tai_xe.js) và `capNhatKetQua()` trong [app/Controllers/TaiXeController.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/TaiXeController.php) để nhận và lưu `ly_do_that_bai` vào CSDL.
- [ ] **Chống bấm nhầm & Tự động chuyển điểm dừng trên Bản đồ ([public/assets/js/ban_do_tai_xe.js](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/js/ban_do_tai_xe.js))**:
  - Đặt 2 nút *"Giao thành công"* và *"Giao thất bại"* cách xa nhau với chiều cao tối thiểu `44px`, có xác nhận nhanh trước khi lưu đơn có thu hộ COD.
  - Ngay sau khi cập nhật xong kết quả 1 đơn hàng, tự động di chuyển tâm bản đồ (`map.flyTo`) sang tọa độ của **đơn hàng tiếp theo chưa giao** và vẽ lại đường chỉ dẫn OSRM từ vị trí hiện tại của tài xế đến các điểm còn lại.
  - Thêm công tắc (Toggle Switch) **"Tự động cập nhật GPS"** và nút **"Thu gọn / Mở rộng bản đồ"** trên màn hình điện thoại để tiết kiệm pin và tăng không gian cuộn danh sách đơn.

### B. Xây dựng Cổng Tra Cứu Mã Vận Đơn Công Khai Cho Khách Hàng (Public Order Tracking)
- [ ] **Tích hợp giao diện Tra cứu Vận đơn trên [app/Views/auth/login.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/auth/login.php) và [app/Controllers/AuthController.php](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Controllers/AuthController.php)**:
  - Thiết kế màn hình [app/Views/auth/login.php](https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/auth/login.php) gồm 2 khu vực (hoặc 2 Tab chuyển đổi mượt mà):
    1. **Tra cứu hành trình đơn hàng SPX (Dành cho Khách hàng — Không cần đăng nhập)**: Cho phép nhập mã vận đơn (ví dụ: `SPXVN001...`) để tra cứu trực tiếp qua tham số `GET /login?ma_van_don=...` (tận dụng route `/login` sẵn có trong `AuthController.php` để không phải sửa `public/index.php` của Thành viên 1).
    2. **Đăng nhập hệ thống nội bộ (Dành cho Điều phối viên & Shipper)**.
  - Khi khách hàng tra cứu mã vận đơn hợp lệ, hiển thị:
    - **Thanh tiến trình 4 bước (Timeline Stepper)**: *Bưu cục tiếp nhận* $\rightarrow$ *Đã phân tuyến giao hàng* $\rightarrow$ *Shipper đang đi giao (kèm Họ tên, SĐT Shipper và thứ tự điểm giao dự kiến trong ca)* $\rightarrow$ *Giao hàng thành công / Giao thất bại (kèm thời gian hoàn tất hoặc lý do thất bại)*.
    - Thông tin người nhận (đã che bớt SĐT để bảo mật, ví dụ `0905***123`), địa chỉ giao và số tiền COD cần chuẩn bị.

### C. Chuẩn hóa Giao Diện Thương Hiệu SPX Express & Layout Chung
- [ ] **Nâng cấp [public/assets/css/app.css](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/public/assets/css/app.css) và [app/Views/layouts/](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/tree/master/app/Views/layouts)**:
  - Áp dụng bộ màu chuẩn của **SPX Express**: Cam chủ đạo (`#EE4D2D`), Xanh đậm điều phối (`#1E293B`), Xanh lá hoàn tất (`#10B981`), Vàng đang giao (`#F59E0B`), Đỏ thất bại (`#EF4444`).
  - Hiển thị rõ vai trò người dùng đang đăng nhập (`Điều phối viên` hoặc `Tài xế SPX`) kèm tên bưu cục trên thanh điều hướng [app/Views/layouts/navbar.php](https://www.google.com/search?q=https://github.com/TNHung3006/dinh-tuyen-spx-open-source/blob/master/app/Views/layouts/navbar.php).
  - Bổ sung bộ bảng màu tối thiểu 10 màu tương phản cao cho các cụm tuyến trên bản đồ để không bị trùng màu khi số lượng tài xế tăng lên.

## 5. Kịch Bản Kiểm Thử Nghiệm Thu End-to-End (Trước Khi Merge Nhánh Chính)

Sau khi Thành viên 2 và Thành viên 3 hoàn thành checklist trên, cả nhóm chạy kiểm thử theo đúng 1 vòng đời đơn hàng thực tế:

- [ ] **Bước 1 (Điều phối viên - TV2)**: Đăng nhập tài khoản Điều phối $\rightarrow$ Đồng bộ đơn mẫu Shopee $\rightarrow$ Sửa 1 đơn lỗi tọa độ bằng Nominatim + kéo thả Marker Preview $\rightarrow$ Chạy thuật toán Phân cụm & Định tuyến $\rightarrow$ Thử chuyển thủ công 1 đơn từ Cụm 1 sang Cụm 2.
- [ ] **Bước 2 (Khách hàng - TV3)**: Mở trang Tra cứu vận đơn $\rightarrow$ Nhập mã vận đơn vừa phân công $\rightarrow$ Kiểm tra hiển thị đúng trạng thái *"Shipper đang đi giao"*, tên Shipper và số tiền COD cần chuẩn bị.
- [ ] **Bước 3 (Tài xế - TV3)**: Đăng nhập tài khoản Shipper trên giao diện Mobile $\rightarrow$ Kiểm tra thẻ *"Đơn hàng tiếp theo"* hiển thị đúng số tiền COD và nút gọi điện $\rightarrow$ Bấm *"Giao thành công"* cho đơn số 1 (bản đồ tự zoom sang đơn số 2) $\rightarrow$ Bấm *"Giao thất bại"* cho đơn số 2 và chọn lý do *"Khách hẹn giao lại ca sau"*.
- [ ] **Bước 4 (Admin Thống kê - TV2)**: Mở trang Thống kê $\rightarrow$ Kiểm tra **Tab 1 (Vận hành Bưu cục)** tự động tăng số đơn thành công, tăng số tiền COD thực thu của Shipper vừa giao và ghi nhận 1 đơn thất bại; chuyển sang **Tab 2 (Benchmark Thuật toán)** bấm chạy thử nghiệm 10-20-30-40 đơn để đảm bảo biểu đồ học thuật vẫn hoạt động trơn tru.
