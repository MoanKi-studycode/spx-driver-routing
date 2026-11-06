HỆ THỐNG PHÂN CỤM VÀ TỐI ƯU TUYẾN GIAO HÀNG ĐA ĐIỂM CHO BƯU CỤC SPX EXPRESS (SHOPEE)
Đặc tả Thiết kế Rút gọn, Chuẩn hóa 3NF & Thực tế - Phục vụ Đồ án Môn học

================================================================================
1. ĐỊNH HƯỚNG ĐỀ TÀI, BÀI TOÁN THỰC TẾ VÀ PHẠM VI TỐI GIẢN
================================================================================

1.1. Mục tiêu cốt lõi (Bám sát tên đề tài & Thực tế vận hành SPX Express đa cơ sở)
Hệ thống giải quyết bài toán điều phối chặng cuối (Last-Mile Delivery) tại hệ thống Bưu cục SPX Express (mặc định demo tại Bưu cục SPX Nha Trang - Khánh Hòa, hỗ trợ mở rộng nhiều bưu cục/tỉnh thành) thông qua quy trình 2 pha thực tế với cấu trúc CSDL chuẩn hóa 3NF (5 bảng, 4 trạng thái đơn hàng):
- Pha 1 - Tại Bưu cục (Phân cụm, Lập tuyến đầu ca & Đối soát Vận hành/COD cho Điều phối viên):
  Bưu cục ($P_0$, đọc động từ bảng `buu_cuc` theo cơ sở của điều phối viên) nhận lô đơn hàng đồng bộ từ Shopee (qua Mock API JSON). Điều phối viên bấm nút đồng bộ để hệ thống tự động kiểm tra tọa độ (so với tâm và bán kính phục vụ của Bưu cục $P_0$), đối chiếu Phường/Xã thuộc vùng phục vụ của Bưu cục, áp dụng cơ chế **Khóa lộ trình đang giao (Dispatch Lock)** để bảo toàn các cụm Shipper đang chạy ngoài đường và chạy thuật toán Phân cụm K-Means có cân bằng tải (Balanced K-Means) chia tập đơn hàng chờ thành $K$ cụm địa lý tách biệt (mỗi cụm tối đa `suc_chua_moi_cum` = 20 đơn, gắn với 1 tài xế), sau đó chạy thuật toán Nearest Neighbor (NN) + 2-Opt tìm lộ trình tối ưu xuất phát từ Bưu cục $P_0$ đi qua các điểm trong từng cụm. Điều phối viên cũng có thể sửa tọa độ đơn lỗi qua **Draggable Marker Preview (Nominatim)**, chuyển cụm thủ công giữa các tài xế và đối soát tiền thu hộ COD cuối ngày.
- Pha 2 - Ngoài đường giao hàng & Tra cứu hành trình công khai (Tài xế & Khách hàng):
  + Tài xế đăng nhập trên điện thoại/trình duyệt (giao diện Mobile-First chuẩn thương hiệu SPX), xem thẻ nổi bật **Đơn hàng tiếp theo** (tiền thu hộ COD, gọi điện `tel:`, mở Google Maps), bật cập nhật GPS tự động hoặc chọn vị trí giả lập để tái định tuyến động đường hở (NN + 2-Opt) từ vị trí hiện tại $S$ qua các đơn chưa giao còn lại (vẽ đường phố thực tế qua OSRM), xác nhận giao thành công hoặc bắt buộc chọn lý do khi giao thất bại.
  + Khách hàng không cần đăng nhập có thể tra cứu công khai mã vận đơn trực tiếp tại `/login?ma_van_don=...` để xem tiến trình 4 bước, Shipper phụ trách, thứ tự giao dự kiến và số tiền COD cần chuẩn bị.

1.2. Tại sao mô hình này vừa đạt chuẩn CSDL 3NF, vừa thực tế, vừa dễ code trong 3-4 tuần?
1. Giữ trọn vẹn từ khóa "Phân cụm" (Balanced K-Means) + "Tối ưu tuyến" (NN + 2-Opt): Có tâm cụm (Centroid), có phân vùng màu sắc trên bản đồ và số liệu so sánh Baseline / NN / 2-Opt rõ ràng để bảo vệ trước hội đồng.
2. Chuẩn hóa địa giới hành chính và đa cơ sở (Multi-Hub 3NF):
   - Tách bảng `buu_cuc` (lưu Tỉnh/Thành phố, Quận/Huyện/TP trực thuộc, tọa độ $P_0$, bán kính phục vụ) và bảng `phuong` (lưu Mã hành chính quốc gia `ma_hanh_chinh`, tên phường, gắn khóa ngoại `buu_cuc_id`).
   - Giải quyết triệt để bài toán trùng tên phường giữa các quận/tỉnh khác nhau và không phải hardcode tọa độ Bưu cục hay biên tọa độ Khánh Hòa trong code PHP.
3. Tối giản cấu trúc dữ liệu nhưng đáp ứng đủ nghiệp vụ vận hành & đối soát COD thực tế:
   - Chỉ có đúng 5 bảng MySQL chuẩn 3NF (`buu_cuc`, `phuong`, `nguoi_dung`, `cum_tuyen_giao`, `don_hang`).
   - Chỉ có đúng 4 trạng thái đơn hàng (`chua_phan_cong`, `da_phan_cong`, `giao_thanh_cong`, `giao_that_bai`) kèm cột `ly_do_that_bai` lưu nguyên nhân hoàn/hẹn giao lại.
   - Tận dụng trực tiếp cột `tien_thu_ho` kết hợp trạng thái đơn hàng để thống kê & đối soát tiền thu hộ COD theo từng tài xế cuối ca (không cần tách thêm bảng sổ quỹ phức tạp), đồng thời loại bỏ khóa bi quan đa cấp dễ deadlock, khung giờ hẹn (VRPTW) và tải trọng kg/m3.

1.3. Tích hợp các API miễn phí phục vụ học thuật & thực tế
1. Shopee Mock API (Nội bộ): Đọc dữ liệu mô phỏng từ `data/don_hang_mau.json` qua `ShopeeOrderClientInterface`.
2. API Hành chính Việt Nam miễn phí (`https://provinces.open-api.vn/api/` hoặc `https://esgoo.net/api-tinhthanh/`): Dùng để chuẩn hóa mã hành chính (`ma_tinh`, `ma_quan_huyen`, `ma_hanh_chinh` cấp phường/xã) khi seed dữ liệu hoặc khi hiển thị dropdown chọn Tỉnh -> Quận/Huyện -> Phường/Xã.
3. OpenStreetMap Nominatim Geocoding API (`https://nominatim.openstreetmap.org/search?format=json&q=...`): Hỗ trợ nút "Tự động lấy tọa độ từ địa chỉ" trên màn hình Điều phối khi xử lý đơn hàng bị thiếu/sai tọa độ (`loi_toa_do`).
4. OSRM Public Routing API (`https://router.project-osrm.org/route/v1/driving/...`): Vẽ đường đi bám sát mặt phố thực tế trên bản đồ Leaflet.


================================================================================
2. ĐỊNH DẠNG JSON ĐẦU VÀO VÀ QUY TẮC ĐỒNG BỘ (INBOUND)
================================================================================

2.1. Cấu hình hệ thống trong `config/app.php` và thông số Bưu cục ($P_0$)
- Hằng số hệ thống trong `config/app.php`:
  + `BASE_URL = '/dinh-tuyen-spx/public'`
  + `HE_SO_DUONG_BO = 1.3` (nhân với khoảng cách Haversine)
  + `SUC_CHUA_MAC_DINH = 20` (đơn/cụm)
  + `OSRM_BASE_URL = 'https://router.project-osrm.org'`
  + `NOMINATIM_BASE_URL = 'https://nominatim.openstreetmap.org'`
- Tọa độ Bưu cục $P_0$, Tỉnh/Thành, Quận/Huyện và bán kính vùng phục vụ (`ban_kinh_phuc_vu_km`, mặc định `25.00` km) được đọc động từ bảng `buu_cuc` theo `buu_cuc_id` của Điều phối viên đang đăng nhập (mặc định Cơ sở 1: Bưu cục SPX Nha Trang, `vi_do = 12.2488000`, `kinh_do = 109.1905000`).

2.2. Mẫu dữ liệu đầu vào (`data/don_hang_mau.json`)
File mẫu gồm 45 đơn hàng quanh TP. Nha Trang, có đầy đủ thông tin hành chính:
```json
{
  "ma_lo": "LO_20261017_SANG",
  "ma_buu_cuc": "SPX_KH_NT01",
  "ngay": "2026-10-17",
  "don_hang": [
    {
      "ma_van_don": "SPXVN261010001",
      "ma_don_shopee": "2610123456789",
      "ten_khach_hang": "Nguyễn Văn An",
      "so_dien_thoai": "0912345678",
      "dia_chi": "12 Phạm Văn Đồng, Phường Bắc Nha Trang, TP. Nha Trang, Khánh Hòa",
      "tinh_thanh": "Tỉnh Khánh Hòa",
      "quan_huyen": "Thành phố Nha Trang",
      "phuong": "Phường Bắc Nha Trang",
      "ma_hanh_chinh_phuong": "22339",
      "vi_do": 12.2761000,
      "kinh_do": 109.2012000,
      "tien_thu_ho": 150000
    }
  ]
}
```

2.3. Quy tắc kiểm tra mỗi đơn khi đồng bộ (`InboundService`)
Đơn hàng được đồng bộ vào `buu_cuc_id` hiện tại của điều phối viên:
- Bước 1 (Chống trùng): Nếu thiếu `ma_van_don` hoặc `ma_van_don` đã tồn tại trong bảng `don_hang` -> Bỏ qua, tăng bộ đếm `so_don_bo_qua_do_trung`.
- Bước 2 (Kiểm tra tọa độ theo bán kính động của Bưu cục): Đơn bị coi là `loi_toa_do` nếu:
  (a) Thiếu `vi_do` hoặc `kinh_do`, hoặc không phải số (`!is_numeric`): gán `NULL` cho cả hai;
  (b) Nằm ngoài biên tuyệt đối `[-90, 90]` / `[-180, 180]`: gán `NULL` cho cả hai;
  (c) Nằm trong biên tuyệt đối nhưng khoảng cách Haversine từ đơn đến tâm Bưu cục $P_0$ vượt quá `buu_cuc.ban_kinh_phuc_vu_km` (ví dụ `> 25 km`):
      giữ nguyên tọa độ để điều phối viên thấy điểm sai trên bản đồ.
  Mọi trường hợp trên: lưu đơn với trạng thái `chua_phan_cong`, `ly_do_chua_phan_cong = 'loi_toa_do'`.
- Bước 3 (Kiểm tra Phường/Xã thuộc vùng phục vụ của Bưu cục hiện tại):
  Ưu tiên khớp theo `ma_hanh_chinh_phuong` (nếu có trong JSON) hoặc chuẩn hóa chuỗi tiếng Việt bằng hàm đa byte:
  `mb_strtolower(preg_replace('/\s+/u', ' ', trim($phuong)), 'UTF-8')`
  để tìm trong danh sách các phường có `buu_cuc_id` bằng bưu cục hiện tại và `dang_hoat_dong = 1`.
  Nếu không khớp -> Lưu đơn với trạng thái `chua_phan_cong`, `ly_do_chua_phan_cong = 'ngoai_vung_phuc_vu'`.
- Bước 4 (Đơn hợp lệ): Lưu đơn với `buu_cuc_id` và `phuong_id` tương ứng, trạng thái tạm là `chua_phan_cong`, `ly_do_chua_phan_cong = NULL`, sẵn sàng đưa vào thuật toán Phân cụm K-Means ngay trong cùng giao dịch.
- Xử lý sửa đơn lỗi trên giao diện Điều phối: Với các đơn bị `loi_toa_do` hoặc `ngoai_vung_phuc_vu`, điều phối viên chọn lại Phường/Xã thuộc vùng phục vụ, bấm nút **"Nominatim Preview"** để hiển thị **Draggable Marker (Marker xem trước kéo-thả được)** trên bản đồ Leaflet, kéo chỉnh đúng vị trí hẻm/đường rồi bấm **"Lưu tọa độ & Gán cụm"**.


================================================================================
3. THUẬT TOÁN PHÂN CỤM (BALANCED K-MEANS) VÀ ĐỊNH TUYẾN (NN + 2-OPT)
================================================================================

3.1. Giai đoạn 1: Phân cụm K-Means có ràng buộc sức chứa (`BalancedKMeansService`)
Đầu vào:
- Tọa độ Bưu cục $P_0$ và định mức sức chứa `S = buu_cuc.suc_chua_moi_cum` (mặc định 20 đơn).
- Tập $N$ đơn hàng hợp lệ của bưu cục đang ở trạng thái `chua_phan_cong` (có đủ tọa độ và `phuong_id`).
- Tập $M$ tài xế thuộc bưu cục đang hoạt động (`buu_cuc_id = :bc`, `vai_tro = 'tai_xe'`, `dang_hoat_dong = 1`), mỗi tài xế có sức chứa còn lại = `S - so_don_da_phan_cong_hien_tai`.

Quy trình thực hiện tất định (Deterministic - chạy lại cùng dữ liệu luôn ra cùng kết quả):
1. Xác định số cụm $K$:
   - Gọi $M_{kha\_dung}$ là số tài xế còn sức chứa $> 0$. Nếu $M_{kha\_dung} == 0$ hoặc $N == 0$, giữ các đơn ở `chua_phan_cong` (`ly_do_chua_phan_cong = 'qua_tai_tai_xe'`) và kết thúc.
   - Số đơn tối đa có thể nhận thêm là $C_{tong} = \sum \text{suc\_chua\_con\_lai}$. Nếu $N > C_{tong}$, chỉ lấy $C_{tong}$ đơn gần Bưu cục $P_0$ nhất để phân cụm; phần dư đánh dấu `ly_do_chua_phan_cong = 'qua_tai_tai_xe'`.
   - Số cụm: $K = \min(M_{kha\_dung}, N)$. Mọi tài xế đang trực đều được chia việc, tải mỗi cụm xấp xỉ $N / K$ đơn và không vượt quá 20.
2. Khởi tạo $K$ tâm cụm ban đầu ($C_1, C_2, \dots, C_K$) không dùng random:
   - Sắp xếp $N$ đơn theo góc phương vị $\theta = \text{atan2}(vi\_do - P_0.vi\_do, kinh\_do - P_0.kinh\_do)$ tăng dần quanh Bưu cục $P_0$ (nếu trùng góc thì theo `id ASC`).
   - Chia danh sách đã sắp xếp thành $K$ lát cắt đều nhau và lấy tọa độ trung bình của mỗi lát cắt làm tâm cụm khởi tạo $C_k^{(0)}$.
3. Lặp gán điểm có cân bằng tải và cập nhật tâm cụm (tối đa `MAX_ITER = 20` vòng):
   - Sức chứa mục tiêu của cụm $k$ trong lô: $S_k = \min(20, \lceil N / K \rceil)$.
   - Tại mỗi vòng lặp, với mỗi đơn $i$, tính khoảng cách Haversine đến tất cả $K$ tâm cụm. Gọi $d_{min}(i)$ là khoảng cách đến tâm gần nhất và $d_{second}(i)$ là khoảng cách đến tâm gần thứ hai.
   - Sắp xếp các đơn theo độ ưu tiên giảm dần của hiệu số $\text{uu\_tien}(i) = d_{second}(i) - d_{min}(i)$ (nếu hòa thì chọn `id ASC`).
   - Duyệt từng đơn theo thứ tự ưu tiên: gán đơn vào cụm gần nhất vẫn còn chỗ ($< S_k$).
   - Tính lại tọa độ tâm cụm mới $C_k^{(t+1)}$ bằng trung bình cộng `(vi_do, kinh_do)` của các đơn trong cụm $k$.
   - Nếu không có đơn nào đổi cụm so với vòng trước thì dừng sớm (Hội tụ).
   - Sau khi hội tụ, loại bỏ cụm không có đơn nào (không tạo bản ghi `cum_tuyen_giao`). Số cụm thực tế $K' \le K$; bước 4 chỉ ghép $K'$ cụm cho $K'$ tài xế.
4. Ghép $K'$ cụm cho $K'$ tài xế phù hợp nhất:
   - Mỗi tài xế có một Phường quen thuộc (`phuong_uu_tien_id`). Hệ thống tính khoảng cách từ tâm cụm $C_k$ đến tâm phường ưu tiên của từng tài xế (nếu tài xế không gắn phường ưu tiên thì lấy tọa độ Bưu cục $P_0$), gán cụm $C_k$ cho tài xế chưa nhận cụm có khoảng cách gần nhất (nếu hòa chọn `tai_xe.id ASC`).

3.2. Giai đoạn 2: Tối ưu lộ trình cho từng Cụm (`NearestNeighborSolver` + `TwoOptSolver`)
Hệ thống dùng chung một bộ giải lộ trình đường hở (Open-Path) xuất phát từ điểm cố định $S$ đi qua $n$ đơn hàng của cụm ($S$ là tọa độ **Bưu cục $P_0$** khi lập tuyến ban đầu tại kho, hoặc là **tọa độ GPS hiện tại của tài xế** khi tài xế bấm tính lại lộ trình ngoài đường):
- Trường hợp $n = 0$: Trả về danh sách rỗng, tổng km = 0.
- Trường hợp $n \ge 1$:
  1. Ma trận khoảng cách giữa điểm xuất phát $S$ (chỉ số 0) và $n$ đơn hàng:
     $$d(a, b) = \text{Haversine}(a, b) \times 1.3$$
  2. Baseline (Cơ sở so sánh): Đi từ $S$ lần lượt qua các đơn trong cụm theo đúng thứ tự xuất hiện trong file JSON (`thu_tu_file ASC, id ASC`).
  3. Bước dựng lộ trình tham lam (Nearest Neighbor - NN):
     Từ $S$, chọn đơn chưa thăm gần nhất (nếu chênh lệch khoảng cách $\le 10^{-9}$ km thì chọn đơn có `id` nhỏ hơn). Lặp lại từ điểm vừa chọn cho đến khi đi qua hết $n$ đơn. Thu được chuỗi $T = [S, v_1, v_2, \dots, v_n]$.
  4. Bước cải tiến cục bộ (2-Opt cho đường hở - cố định điểm đầu $S = T[0]$, tự do điểm cuối $T[n]$):
     Nếu $n \le 2$ thì giữ nguyên kết quả NN. Nếu $n \ge 3$, duyệt $i = 1 \dots n - 1$ và $k = i + 1 \dots n$. Khi đảo ngược đoạn con $T[i..k]$, độ biến thiên quãng đường là:
     $$\Delta = d(T[i-1], T[k]) - d(T[i-1], T[i]) + \begin{cases} d(T[i], T[k+1]) - d(T[k], T[k+1]) & \text{nếu } k < n \\ 0 & \text{nếu } k = n \end{cases}$$
     Gặp phép đảo đầu tiên có $\Delta < -10^{-6}$ thì đảo đoạn $T[i..k]$ và quay lại $i = 1$; dừng khi quét hết mọi cặp $(i, k)$ mà không còn $\Delta < -10^{-6}$ (giới hạn an toàn tối đa 500 vòng lặp).
  5. Lưu kết quả (CHỈ ở lần lập tuyến đầu tiên, xuất phát từ Bưu cục $P_0$):
     - Cập nhật `thu_tu_giao = 1..n` cho từng đơn trong bảng `don_hang`.
     - Lưu `buu_cuc_id`, `vi_do_tam_cum`, `kinh_do_tam_cum`, `km_baseline`, `km_nn`, `km_toi_uu`, `ty_le_tiet_kiem_phan_tram`, `thoi_gian_chay_ms` vào bảng `cum_tuyen_giao`.
     - Khi tái định tuyến từ GPS tài xế (mục 4.2): CHỈ cập nhật `thu_tu_giao`. KHÔNG ghi đè `km_baseline`, `km_nn`, `km_toi_uu`, `ty_le_tiet_kiem_phan_tram`, `vi_do_xuat_phat`, `kinh_do_xuat_phat`.


================================================================================
4. LUỒNG HOẠT ĐỘNG CỦA ĐIỀU PHỐI VIÊN, TÀI XẾ VÀ KHÁCH HÀNG
================================================================================

4.1. Luồng Điều phối viên (Tại Bưu cục)
1. Header & Thanh điều hướng hiển thị rõ vai trò `Điều phối viên` cùng thông tin cơ sở hiện tại: **"Bưu cục SPX Nha Trang - TP. Nha Trang, Tỉnh Khánh Hòa"** (có thể chuyển đổi giữa các cơ sở như Nha Trang / Cam Ranh).
2. Đồng bộ đơn Shopee & Khóa lộ trình đang giao (Dispatch Lock):
   - Nút chính **"Đồng bộ Shopee & Chia tuyến"** (`reset_all = 0`): Giữ nguyên các cụm tuyến `dang_giao` mà Shipper đang đi phát ngoài đường, chỉ đồng bộ thêm đơn mới từ Mock JSON và phân bổ các đơn hợp lệ đang chờ (`chua_phan_cong`) vào cụm còn sức chứa hoặc tạo cụm mới cho tài xế rảnh.
   - Nút phụ **"Reset & Phân cụm lại toàn bộ"** (`reset_all = 1`): Hiển thị Modal xác nhận cảnh báo trước khi làm mới các cụm tuyến của bưu cục và chạy lại toàn bộ `BalancedKMeansService` + `NearestNeighborSolver` + `TwoOptSolver` từ Bưu cục $P_0$.
3. Quan sát Bản đồ Phân cụm & Quản lý 3 Tab Điều phối:
   - Bản đồ Leaflet hiển thị Marker Bưu cục SPX ($P_0$), vòng bán kính phục vụ, **Tâm cụm K-Means (`vi_do_tam_cum`, `kinh_do_tam_cum`)**, các điểm đơn hàng đánh số thứ tự `1..n` (hoặc dấu `✓` / `✕` cho đơn đã xử lý) theo bảng màu tương phản cao của từng tài xế, kèm bộ nút chuyển đổi **"Baseline"** / **"2-Opt"** / **"So sánh cả hai"** và đường phố thực tế qua OSRM.
   - Khu vực dữ liệu phía dưới được tổ chức gọn thành **3 Tab Bootstrap**:
     + *Tab 1 - Cụm Tuyến & Tài Xế*: Tiến độ đơn (Đã giao / Đang giao / Thất bại), đối soát COD từng cụm và quãng đường `Baseline -> NN -> 2-Opt`.
     + *Tab 2 - Đơn Hàng Lỗi Tọa Độ*: Tích hợp nút **"Nominatim Preview"** sinh **Draggable Marker** trên bản đồ để kéo-thả tinh chỉnh vị trí trước khi lưu và gán vào cụm gần nhất.
     + *Tab 3 - Danh Sách Tất Cả Đơn Hàng & Chuyển Cụm*: Hỗ trợ lọc/tìm kiếm đơn và **Chuyển cụm / Đổi tài xế thủ công** (`hanh_dong = 'chuyen_cum'`) cho các đơn `da_phan_cong` (hệ thống tự động chạy lại NN + 2-Opt cập nhật thứ tự giao và số km tối ưu cho cả cụm nguồn và cụm đích).

4.2. Luồng Tài xế (Ngoài đường giao hàng - Giao diện Mobile-First)
1. Đăng nhập vào màn hình Tài xế (`/tai-xe`):
   - Thấy ngay thẻ nổi bật **"Đơn hàng tiếp theo" (Next-Stop Sticky Card)** ở đầu màn hình với cỡ chữ và độ tương phản cao, hiển thị rõ **Số tiền cần thu hộ COD** (hoặc nhãn *"Đơn 0đ - Không thu tiền"*), nút bấm gọi điện trực tiếp (`tel:`) và nút mở Google Maps chỉ đường ngoài (`https://www.google.com/maps/dir/?api=1&destination={lat},{lng}`).
   - Hỗ trợ nút **"Thu gọn / Mở rộng bản đồ"** và bộ lọc đơn (*Tất cả*, *Chờ giao*, *Đã giao / Hoàn*) để thao tác một tay thuận tiện trên điện thoại.
2. Định vị GPS thực tế, Công tắc GPS tự động & Cơ chế dự phòng 2 lớp:
   - Trang bị công tắc (Toggle Switch) **"Tự động cập nhật GPS"** (`navigator.geolocation.watchPosition` có giãn cách tối thiểu 15 giây để tiết kiệm pin) cùng nút lấy GPS tức thời.
   - **Dự phòng Lớp 1 (Tự động):** Khi chưa cấp quyền GPS hoặc mở trên máy bàn, hệ thống lấy vị trí gần nhất của tài xế trong DB; nếu chưa có thì lấy tọa độ **Bưu cục $P_0$** làm điểm xuất phát mặc định.
   - **Dự phòng Lớp 2 (Giả lập trực quan khi Demo):** Nút **"Giả lập vị trí trên bản đồ"** cho phép chạm vào bất kỳ điểm nào trên bản đồ để giả lập tài xế đang đứng tại đó.
3. Tái định tuyến động từ vị trí hiện tại (Dynamic Re-routing) & Vẽ đường phố OSRM:
   - Khi tài xế cập nhật vị trí GPS/giả lập hoặc vừa xử lý xong 1 đơn, hệ thống gửi tọa độ $S = (vi\_do, kinh\_do)$ lên `POST /api/tai-xe/cap-nhat-lo-trinh`.
   - Server kiểm tra tọa độ nằm trong bán kính phục vụ của Bưu cục, chạy lại NN + 2-Opt đường hở từ vị trí $S$ qua các đơn `da_phan_cong` còn lại của cụm, chỉ cập nhật lại `thu_tu_giao = 1..m` (không ghi đè các cột `km_*` của cụm) và lưu vị trí vào `nguoi_dung`.
   - Giao diện gọi OSRM (`AbortController` timeout 3000ms) để vẽ đường bám theo mặt phố thực tế; nếu OSRM phản hồi chậm thì tự động vẽ đường nối dự phòng Polyline.
4. Cập nhật kết quả giao hàng (`POST /api/tai-xe/don-hang/{id}/ket-qua`):
   - Hai nút **Giao thành công** và **Giao thất bại** có chiều cao tối thiểu `44px` chống bấm nhầm:
     + Khi bấm **Giao thành công** cho đơn có `tien_thu_ho > 0`, hiển thị hộp thoại xác nhận đã thu đủ tiền COD.
     + Khi bấm **Giao thất bại**, mở **Modal bắt buộc chọn lý do** (*Khách không nghe máy*, *Khách hẹn giao lại ca sau*, *Sai địa chỉ/SĐT*, *Khách từ chối nhận hàng*, hoặc *Lý do khác* kèm ghi chú) để lưu vào cột `ly_do_that_bai`.
   - Câu lệnh SQL có điều kiện chống bấm đúp và chống sửa đơn của tài xế khác:
     ```sql
     UPDATE don_hang
     SET trang_thai = :trang_thai_moi,
         ly_do_that_bai = :ly_do,
         thoi_gian_hoan_tat = NOW()
     WHERE id = :id AND tai_xe_id = :tx AND trang_thai = 'da_phan_cong';
     ```
   - Nếu `rowCount() == 0` thì trả mã 409 ("Đơn hàng đã được cập nhật trước đó hoặc không thuộc quyền phụ trách").
   - Ngay sau khi cập nhật thành công, bản đồ tự động di chuyển tâm (`map.flyTo`) sang tọa độ của **đơn hàng tiếp theo chưa giao**; nếu toàn bộ đơn trong cụm đã xử lý xong thì tự động chuyển `cum_tuyen_giao.trang_thai = 'hoan_tat'`.

4.3. Luồng Khách hàng Tra cứu Vận đơn Công khai (Public Order Tracking)
- Ngay tại màn hình `/login`, khách hàng không cần tài khoản có thể nhập mã vận đơn (VD: `SPXVN261010001`) để tra cứu qua `GET /login?ma_van_don=...`.
- Hệ thống truy vấn `DonHangRepository::timTheoMaVanDon()` và hiển thị:
  + **Thanh tiến trình 4 bước (Timeline Stepper)**: *Bưu cục tiếp nhận* $\rightarrow$ *Đã phân tuyến* $\rightarrow$ *Shipper đang giao* $\rightarrow$ *Hoàn tất giao hàng / Giao không thành công*.
  + Thông tin bưu cục xử lý, tên người nhận kèm **SĐT đã che bảo mật** (VD: `0912***101`), địa chỉ giao, số tiền COD cần chuẩn bị, thông tin Shipper phụ trách (Họ tên, SĐT gọi nhanh, thứ tự điểm dừng dự kiến trong ca) và thời gian hoàn tất hoặc lý do giao thất bại.


================================================================================
5. MÀN HÌNH GIAO DIỆN VÀ DANH SÁCH API ENDPOINTS
================================================================================

5.1. Danh sách 4 màn hình
1. Đăng nhập & Tra cứu Vận đơn Công khai (`/login`):
   - Khu vực đăng nhập nội bộ theo Session cho `dieu_phoi` và `tai_xe` (lưu `user_id`, `vai_tro`, `buu_cuc_id`, `ten_buu_cuc`).
   - Khu vực **Tra cứu vận đơn công khai** (`GET /login?ma_van_don=...`) dành cho khách hàng theo dõi hành trình đơn hàng.
2. Điều phối - Bản đồ Phân cụm & Điều hành (`/dieu-phoi`):
   - Thanh thông tin Bưu cục + 4 thẻ KPI tổng quan + Nút **"Đồng bộ Shopee & Chia tuyến"** (Dispatch Lock) và nút **"Reset & Phân cụm lại"**.
   - Bản đồ Leaflet đa cụm: vẽ Bưu cục $P_0$, các tâm cụm K-Means, điểm giao hàng theo màu cụm, lộ trình OSRM/2-Opt vs Baseline và **Draggable Marker Preview** khi sửa tọa độ.
   - 3 Tab quản lý: Cụm tuyến & đối soát COD theo tài xế; Sửa đơn lỗi kèm Nominatim API; Danh sách tất cả đơn hàng & Chuyển cụm thủ công.
3. Điều phối - Trung tâm Báo cáo Vận hành & Thực nghiệm Thuật toán (`/thong-ke`) gồm **2 Tab độc lập**:
   - **Tab 1 — Quản trị Vận hành Bưu cục & Đối soát COD (Operational Dashboard)**:
     + 4 thẻ KPI thực tế (*Tổng đơn hôm nay*, *Tỷ lệ giao thành công SLA %*, *Tiền thu hộ COD đã thu / phải thu*, *Đơn giao thất bại cần xử lý*).
     + 3 biểu đồ Chart.js: Doughnut Chart tỷ lệ 4 trạng thái đơn (`chart-trang-thai-don`), Stacked Bar Chart tiến độ theo tài xế (`chart-tien-do-tai-xe`) và Grouped Bar Chart đối soát COD cần thu vs thực thu (`chart-doi-soat-cod`).
     + Bảng Đối soát chi tiết Cụm tuyến & COD cuối ngày (phục vụ kế toán bưu cục thu tiền mặt cuối ca) và bảng sản lượng theo Phường/Xã.
   - **Tab 2 — Đánh giá & Thực nghiệm Thuật toán (Algorithm Benchmark)**:
     + 4 thẻ KPI học thuật (*Baseline km*, *Nearest Neighbor km*, *2-Opt km & % tiết kiệm*, *Thời gian chạy ms*), nút chạy Benchmark thực nghiệm ($N = 10, 20, 30, 40$ đơn) và 2 biểu đồ `chart-quang-duong`, `chart-thoi-gian`.
4. Tài xế - Lộ trình & Giao hàng Thực địa (`/tai-xe`):
   - Thẻ **Đơn hàng tiếp theo** nổi bật tiền COD, nút gọi `tel:` và mở Google Maps chỉ đường.
   - Bản đồ Leaflet định vị GPS thật (có công tắc tự động cập nhật GPS, nút thu gọn bản đồ) + nút giả lập vị trí + đường đi OSRM + tự động `flyTo` sang điểm kế tiếp.
   - Danh sách đơn hàng theo tab lọc kèm xác nhận thu COD và Modal bắt buộc chọn lý do khi giao thất bại.

5.2. Danh sách API Endpoints
- `GET  /api/mock/shopee/don-hang`          : Trả nội dung file `data/don_hang_mau.json`.
- `GET  /api/hanh-chinh/phuong-theo-buu-cuc`: Trả danh sách phường kèm thông tin Quận/Huyện, Tỉnh/Thành của bưu cục hiện tại (và hỗ trợ đồng bộ mã hành chính từ `provinces.open-api.vn`).
- `POST /api/dieu-phoi/phan-cum-toi-uu`     : Đồng bộ JSON, hỗ trợ **Dispatch Lock** mặc định (`reset_all = 0` giữ nguyên cụm đang giao, chỉ phân tuyến đơn chờ mới) hoặc làm mới toàn bộ (`reset_all = 1`), chạy Balanced K-Means + NN + 2-Opt từ Bưu cục $P_0$, lưu DB.
- `POST /api/dieu-phoi/sua-don-loi`         : Hỗ trợ 2 nghiệp vụ điều phối:
  (1) Cập nhật tọa độ/phường cho đơn `chua_phan_cong`, gán vào cụm gần nhất còn chỗ và chạy lại NN + 2-Opt từ Bưu cục $P_0$;
  (2) **Chuyển cụm thủ công** (`hanh_dong = 'chuyen_cum'`) chuyển đơn `da_phan_cong` sang cụm đích và tính lại thứ tự giao + quãng đường 2-Opt cho cả hai cụm.
- `POST /api/tai-xe/cap-nhat-lo-trinh`      : Nhận tọa độ hiện tại $(vi\_do, kinh\_do)$ của tài xế, lưu vị trí và chạy lại NN + 2-Opt đường hở qua các đơn `da_phan_cong` còn lại.
- `POST /api/tai-xe/don-hang/{id}/ket-qua`  : Cập nhật `giao_thanh_cong` hoặc `giao_that_bai` (bắt buộc kèm `ly_do_that_bai` hợp lệ).
- `GET  /api/thong-ke/so-lieu`              : Lấy đầy đủ số liệu vận hành bưu cục, đối soát COD theo tài xế/phường và kết quả Benchmark phục vụ 2 Tab biểu đồ Chart.js.

Quyền truy cập API:
- `/api/dieu-phoi/*`, `/api/thong-ke/*`, `/api/hanh-chinh/*`, `/api/mock/shopee/don-hang`: chỉ `vai_tro = 'dieu_phoi'`, tài xế gọi trả 403.
- `/api/tai-xe/*`: chỉ `vai_tro = 'tai_xe'`.
- `/api/tai-xe/cap-nhat-lo-trinh`: kiểm tra cụm thuộc tài xế đang đăng nhập; validate `vi_do, kinh_do` là số và cách Bưu cục $P_0$ không quá `ban_kinh_phuc_vu_km`.

Định dạng JSON trả về thống nhất:
`{"thanh_cong": true|false, "thong_bao": "...", "du_lieu": {...}}`


================================================================================
6. CƠ SỞ DỮ LIỆU MYSQL/MARIADB (XAMPP) (5 BẢNG CHUẨN HÓA 3NF - SNAKE_CASE TIẾNG VIỆT)
================================================================================

Thiết kế đạt chuẩn 3NF:
- Bảng `buu_cuc` quản lý thông tin Cơ sở phát hàng và cấp Tỉnh/Thành phố, Quận/Huyện/TP trực thuộc cùng tọa độ gốc $P_0$.
- Bảng `phuong` liên kết về `buu_cuc_id`, lưu Mã hành chính quốc gia (`ma_hanh_chinh` theo chuẩn Tổng cục Thống kê / Open API) và đặt ràng buộc `UNIQUE (buu_cuc_id, ten_phuong)` thay vì `ten_phuong UNIQUE` toàn cục (cho phép các quận/tỉnh khác nhau có tên phường trùng nhau như "Phường 1", "Phường Tân Lập" mà không xung đột).

```sql
CREATE DATABASE IF NOT EXISTS dinh_tuyen_spx
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dinh_tuyen_spx;

-- Bảng 1: buu_cuc (Cơ sở / Bưu cục phát hàng SPX & Cấp hành chính Tỉnh - Quận/Huyện)
CREATE TABLE buu_cuc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ma_buu_cuc VARCHAR(40) NOT NULL UNIQUE COMMENT 'VD: SPX_KH_NT01',
    ten_buu_cuc VARCHAR(120) NOT NULL COMMENT 'VD: Bưu cục SPX Nha Trang',
    ma_tinh VARCHAR(20) NOT NULL COMMENT 'Mã Tỉnh/Thành chuẩn API hành chính, VD: 56',
    ten_tinh VARCHAR(100) NOT NULL COMMENT 'VD: Tỉnh Khánh Hòa',
    ma_quan_huyen VARCHAR(20) NOT NULL COMMENT 'Mã Quận/Huyện/TP thuộc tỉnh, VD: 568',
    ten_quan_huyen VARCHAR(100) NOT NULL COMMENT 'VD: Thành phố Nha Trang',
    dia_chi VARCHAR(255) NOT NULL,
    vi_do DECIMAL(10, 7) NOT NULL COMMENT 'Vĩ độ điểm xuất phát P0 của Bưu cục',
    kinh_do DECIMAL(10, 7) NOT NULL COMMENT 'Kinh độ điểm xuất phát P0 của Bưu cục',
    ban_kinh_phuc_vu_km DECIMAL(6, 2) NOT NULL DEFAULT 25.00 COMMENT 'Bán kính kiểm tra tọa độ hợp lệ (km)',
    suc_chua_moi_cum INT NOT NULL DEFAULT 20 COMMENT 'Số đơn tối đa mỗi cụm/tài xế',
    dang_hoat_dong TINYINT(1) NOT NULL DEFAULT 1,
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_bc_vi_do CHECK (vi_do BETWEEN -90 AND 90),
    CONSTRAINT chk_bc_kinh_do CHECK (kinh_do BETWEEN -180 AND 180)
) ENGINE=InnoDB;

-- Bảng 2: phuong (Danh mục Phường/Xã thuộc vùng phục vụ của từng Bưu cục)
CREATE TABLE phuong (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL COMMENT 'Phường này do Bưu cục nào phụ trách',
    ma_hanh_chinh VARCHAR(20) NOT NULL UNIQUE COMMENT 'Mã Phường/Xã chuẩn Quốc gia/Open API, VD: 22339',
    ma_phuong VARCHAR(40) NOT NULL UNIQUE COMMENT 'Mã nội bộ, VD: KH_NT_BAC_NHA_TRANG',
    ten_phuong VARCHAR(100) NOT NULL COMMENT 'VD: Phường Bắc Nha Trang',
    cap_hanh_chinh ENUM('Phường', 'Xã', 'Thị trấn') NOT NULL DEFAULT 'Phường',
    vi_do_tam DECIMAL(10, 7) NOT NULL COMMENT 'Tâm phường để tham chiếu ghép tài xế',
    kinh_do_tam DECIMAL(10, 7) NOT NULL,
    dang_hoat_dong TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_phuong_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT uq_buu_cuc_ten_phuong UNIQUE (buu_cuc_id, ten_phuong),
    CONSTRAINT chk_ph_vi_do CHECK (vi_do_tam BETWEEN -90 AND 90),
    CONSTRAINT chk_ph_kinh_do CHECK (kinh_do_tam BETWEEN -180 AND 180),
    INDEX idx_phuong_buu_cuc (buu_cuc_id, dang_hoat_dong)
) ENGINE=InnoDB;

-- Bảng 3: nguoi_dung (Điều phối viên và Tài xế thuộc Bưu cục)
CREATE TABLE nguoi_dung (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL COMMENT 'Nhân sự thuộc Bưu cục nào',
    ten_dang_nhap VARCHAR(50) NOT NULL UNIQUE,
    mat_khau_bam VARCHAR(255) NOT NULL COMMENT 'password_hash() Bcrypt',
    ho_ten VARCHAR(100) NOT NULL,
    so_dien_thoai VARCHAR(20) DEFAULT NULL,
    vai_tro ENUM('dieu_phoi', 'tai_xe') NOT NULL DEFAULT 'tai_xe',
    phuong_uu_tien_id INT DEFAULT NULL COMMENT 'Phường quen thuộc của tài xế để ưu tiên ghép cụm',
    mau_sac_cum VARCHAR(20) NOT NULL DEFAULT '#2563eb' COMMENT 'Màu hiển thị cụm của tài xế trên bản đồ',
    dang_hoat_dong TINYINT(1) NOT NULL DEFAULT 1,
    vi_do_hien_tai DECIMAL(10, 7) DEFAULT NULL,
    kinh_do_hien_tai DECIMAL(10, 7) DEFAULT NULL,
    thoi_gian_vi_tri DATETIME DEFAULT NULL,
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_nd_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT fk_nd_phuong FOREIGN KEY (phuong_uu_tien_id) REFERENCES phuong(id) ON DELETE RESTRICT,
    CONSTRAINT chk_nd_vi_do CHECK (vi_do_hien_tai IS NULL OR vi_do_hien_tai BETWEEN -90 AND 90),
    CONSTRAINT chk_nd_kinh_do CHECK (kinh_do_hien_tai IS NULL OR kinh_do_hien_tai BETWEEN -180 AND 180),
    INDEX idx_nd_buu_cuc_vai_tro (buu_cuc_id, vai_tro, dang_hoat_dong)
) ENGINE=InnoDB;

-- Bảng 4: cum_tuyen_giao (Lưu thông tin Cụm K-Means + Kết quả tối ưu lộ trình của tài xế)
CREATE TABLE cum_tuyen_giao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL,
    ma_cum_tuyen VARCHAR(40) NOT NULL UNIQUE COMMENT 'VD: CUM_20261017_TX1',
    ma_lo VARCHAR(40) DEFAULT NULL,
    tai_xe_id INT NOT NULL,
    vi_do_tam_cum DECIMAL(10, 7) NOT NULL COMMENT 'Tâm cụm (Centroid) từ thuật toán K-Means',
    kinh_do_tam_cum DECIMAL(10, 7) NOT NULL,
    vi_do_xuat_phat DECIMAL(10, 7) NOT NULL COMMENT 'Điểm xuất phát lúc lập tuyến đầu (Bưu cục P0), không đổi khi tái định tuyến',
    kinh_do_xuat_phat DECIMAL(10, 7) NOT NULL COMMENT 'Điểm xuất phát lúc lập tuyến đầu (Bưu cục P0), không đổi khi tái định tuyến',
    so_don_hang INT NOT NULL DEFAULT 0,
    km_baseline DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Chỉ ghi lần lập tuyến đầu',
    km_nn DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Quãng đường sau Nearest Neighbor',
    km_toi_uu DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Quãng đường sau 2-Opt',
    ty_le_tiet_kiem_phan_tram DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    thoi_gian_chay_ms DECIMAL(8, 3) NOT NULL DEFAULT 0.000,
    trang_thai ENUM('dang_giao', 'hoan_tat') NOT NULL DEFAULT 'dang_giao',
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_cap_nhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ctg_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ctg_tai_xe FOREIGN KEY (tai_xe_id) REFERENCES nguoi_dung(id) ON DELETE RESTRICT,
    INDEX idx_ctg_buu_cuc (buu_cuc_id, trang_thai),
    INDEX idx_ctg_tai_xe (tai_xe_id, trang_thai)
) ENGINE=InnoDB;

-- Bảng 5: don_hang (Lưu đơn hàng và thứ tự giao trong cụm)
CREATE TABLE don_hang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buu_cuc_id INT NOT NULL,
    ma_van_don VARCHAR(40) NOT NULL UNIQUE,
    ma_don_shopee VARCHAR(50) DEFAULT NULL,
    ma_lo VARCHAR(40) DEFAULT NULL,
    thu_tu_file INT NOT NULL DEFAULT 0 COMMENT 'Thứ tự gốc trong JSON để tính Baseline',
    ten_khach_hang VARCHAR(100) NOT NULL,
    so_dien_thoai_khach VARCHAR(20) DEFAULT NULL,
    dia_chi_giao_hang VARCHAR(255) NOT NULL,
    tinh_thanh_goc VARCHAR(100) DEFAULT NULL,
    quan_huyen_goc VARCHAR(100) DEFAULT NULL,
    phuong_ten_goc VARCHAR(100) DEFAULT NULL,
    phuong_id INT DEFAULT NULL,
    vi_do DECIMAL(10, 7) DEFAULT NULL,
    kinh_do DECIMAL(10, 7) DEFAULT NULL,
    tien_thu_ho DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    cum_tuyen_id INT DEFAULT NULL COMMENT 'Thuộc cụm K-Means nào',
    tai_xe_id INT DEFAULT NULL COMMENT 'Tài xế phụ trách cụm',
    thu_tu_giao INT DEFAULT NULL COMMENT 'Thứ tự điểm dừng 1..n sau khi chạy NN + 2-Opt',
    trang_thai ENUM('chua_phan_cong', 'da_phan_cong', 'giao_thanh_cong', 'giao_that_bai')
        NOT NULL DEFAULT 'chua_phan_cong',
    ly_do_chua_phan_cong ENUM('loi_toa_do', 'ngoai_vung_phuc_vu', 'qua_tai_tai_xe') DEFAULT NULL,
    ly_do_that_bai VARCHAR(255) DEFAULT NULL,
    thoi_gian_phan_cong DATETIME DEFAULT NULL,
    thoi_gian_hoan_tat DATETIME DEFAULT NULL,
    ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_cap_nhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dh_buu_cuc FOREIGN KEY (buu_cuc_id) REFERENCES buu_cuc(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dh_phuong FOREIGN KEY (phuong_id) REFERENCES phuong(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dh_cum_tuyen FOREIGN KEY (cum_tuyen_id) REFERENCES cum_tuyen_giao(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dh_tai_xe FOREIGN KEY (tai_xe_id) REFERENCES nguoi_dung(id) ON DELETE RESTRICT,
    CONSTRAINT chk_dh_vi_do CHECK (vi_do IS NULL OR vi_do BETWEEN -90 AND 90),
    CONSTRAINT chk_dh_kinh_do CHECK (kinh_do IS NULL OR kinh_do BETWEEN -180 AND 180),
    CONSTRAINT chk_dh_phan_cong_hop_le CHECK (
        trang_thai = 'chua_phan_cong'
        OR (cum_tuyen_id IS NOT NULL AND tai_xe_id IS NOT NULL AND phuong_id IS NOT NULL
            AND vi_do IS NOT NULL AND kinh_do IS NOT NULL AND ly_do_chua_phan_cong IS NULL)
    ),
    INDEX idx_dh_buu_cuc_trang_thai (buu_cuc_id, trang_thai),
    INDEX idx_dh_cum_thu_tu (cum_tuyen_id, trang_thai, thu_tu_giao),
    INDEX idx_dh_tai_xe (tai_xe_id, trang_thai)
) ENGINE=InnoDB;
```

Dữ liệu khởi tạo (`database/seed.php`):
- Tạo sẵn **2 Bưu cục** để chứng minh khả năng mở rộng đa cơ sở / đa địa phương:
  1. `SPX_KH_NT01` - **Bưu cục SPX Nha Trang** (`Tỉnh Khánh Hòa` - `Thành phố Nha Trang`, `vi_do = 12.2488000`, `kinh_do = 109.1905000`, bán kính 25 km) kèm các phường thực tế (`Phường Bắc Nha Trang`, `Phường Tây Nha Trang`, `Phường Nha Trang` với mã hành chính chuẩn).
  2. `SPX_KH_CR01` - **Bưu cục SPX Cam Ranh** (`Tỉnh Khánh Hòa` - `Thành phố Cam Ranh`, `vi_do = 11.9214000`, `kinh_do = 109.1591000`, bán kính 25 km) kèm phường mẫu (`Phường Cam Nghĩa`, `Phường Cam Thuận`) để minh họa khi lọc phường luôn hiển thị rõ thuộc Quận/TP và Tỉnh nào.
- Tạo 1 tài khoản `dieu_phoi` (`admin`) và 3 tài khoản `tai_xe` (`taixe_bac`, `taixe_tay`, `taixe_nhatrang`, mật khẩu mặc định `123456`) thuộc Bưu cục SPX Nha Trang với mã màu cụm tương phản cao riêng biệt (`#ef4444` Đỏ, `#16a34a` Xanh lá, `#2563eb` Xanh dương).
- Nạp sẵn **3 Cụm tuyến giao hàng** và **39 đơn hàng mồi** phân bổ đủ 4 trạng thái (`chua_phan_cong`, `da_phan_cong`, `giao_thanh_cong`, `giao_that_bai` kèm lý do thất bại thực tế) với tiền thu hộ COD đa dạng từ `0đ` đến `1.500.000đ` để sẵn sàng trình diễn ngay bảng Đối soát COD; đồng thời chừa lại **6 đơn hàng mới** trong `data/don_hang_mau.json` để demo trực tiếp thao tác bấm **"Đồng bộ Shopee & Chia tuyến"**.


================================================================================
7. KIẾN TRÚC THƯ MỤC VÀ QUY TẮC BẢO MẬT CỐT LÕI
================================================================================

- Công nghệ: PHP 8.2+ thuần (MVC mỏng, một điểm vào `public/index.php`), Composer, PDO + MySQL/MariaDB (XAMPP),
  Leaflet.js + OpenStreetMap + Nominatim API + OSRM API, Chart.js, Bootstrap 5, PHPUnit.
- Môi trường chuẩn của cả nhóm: XAMPP. Cả 3 thành viên cài cùng phiên bản XAMPP có PHP >= 8.2,
  MySQL/MariaDB chạy ở cổng 3306, user root, mật khẩu rỗng. Cài thêm Composer riêng (XAMPP không kèm sẵn).
- Dự án đặt trong `C:\xampp\htdocs\dinh-tuyen-spx\`, truy cập `http://localhost/dinh-tuyen-spx/public/`.
  Cần mod_rewrite và AllowOverride All (XAMPP thường đã bật sẵn; nếu .htaccess không chạy thì kiểm tra httpd.conf).
- Bảo mật tối thiểu bắt buộc:
  + Mọi truy vấn qua PDO Prepared Statements.
  + Escape dữ liệu hiển thị bằng `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.
  + Mật khẩu băm bằng `password_hash()` / `password_verify()`, gọi `session_regenerate_id(true)` sau khi đăng nhập.
  + Kiểm tra CSRF token (`X-CSRF-Token`) cho mọi request `POST`.
  + Phân quyền chặt: Tài xế chỉ được xem và cập nhật đơn hàng có `tai_xe_id = $_SESSION['user_id']`; Điều phối viên thao tác theo `buu_cuc_id = $_SESSION['buu_cuc_id']`.
  + Phân quyền theo `vai_tro` cho mọi route và API theo bảng ở mục 5.2.

Cấu trúc thư mục dự án:
```
dinh-tuyen-spx/
  composer.json                  (PSR-4: App\ -> app/ ; require-dev: phpunit)
  phpunit.xml
  .gitignore  LICENSE  README.md  CONTRIBUTING.md
  .github/
    pull_request_template.md
    copilot-instructions.md
  config/
    app.php                      (HE_SO_DUONG_BO=1.3, SUC_CHUA_MAC_DINH=20, BASE_URL, OSRM/NOMINATIM URL)
    database.example.php         (mẫu PDO; database.php thật KHÔNG commit)
  app/
    bootstrap.php                (autoload, session, nạp config)
    Core/
      Database.php, Router.php, Session.php, Csrf.php, Validator.php, ViewHelper.php
    Repositories/
      BuuCucRepository.php, PhuongRepository.php, NguoiDungRepository.php,
      CumTuyenGiaoRepository.php, DonHangRepository.php
    Services/
      ShopeeApi/
        ShopeeOrderClientInterface.php, MockShopeeOrderService.php, InboundService.php
      Clustering/
        BalancedKMeansService.php
      Routing/
        HaversineService.php, NearestNeighborSolver.php,
        TwoOptSolver.php, DieuPhoiLoTrinhService.php
    Controllers/
      AuthController.php, DieuPhoiController.php,
      TaiXeController.php, ThongKeController.php
    Views/
      layouts/, auth/, dieu_phoi/, tai_xe/, thong_ke/
  public/
    index.php
    .htaccess
    assets/
      css/                       (app.css - Bộ màu thương hiệu SPX Express)
      js/                        (ban_do_dieu_phoi.js, ban_do_tai_xe.js, bieu_do.js)
      img/
  data/
    don_hang_mau.json            (45 đơn quanh Nha Trang kèm COD thực tế)
  database/
    schema.sql
    seed.php
  tests/
    HaversineServiceTest.php, NearestNeighborSolverTest.php,
    TwoOptSolverTest.php, DieuPhoiLoTrinhServiceTest.php,
    BalancedKMeansServiceTest.php, InboundServiceTest.php
  docs/                          (THIET_KE.md = tài liệu thiết kế hệ thống)
```


================================================================================
8. KIỂM THỬ VÀ KỊCH BẢN DEMO BẢO VỆ ĐỒ ÁN (5 PHÚT)
================================================================================

8.1. Kiểm thử đơn vị tự động (PHPUnit - 27 bài test / 117 assertions)
- `HaversineServiceTest`: Kiểm tra khoảng cách giữa Bưu cục $P_0$ và các mốc thực tế tại Nha Trang, tính đối xứng, bất đẳng thức tam giác, hệ số đường bộ `1.3` và kiểm tra bán kính vùng phục vụ.
- `NearestNeighborSolverTest` & `TwoOptSolverTest`: Kiểm tra thuật toán tham lam có quy tắc phá hòa tất định (`id ASC`) và đảm bảo quãng đường đường hở sau 2-Opt luôn $\le$ Nearest Neighbor (gỡ toàn bộ đoạn cắt chéo).
- `DieuPhoiLoTrinhServiceTest`: Kiểm tra điều phối lộ trình tổng hợp trả đủ thứ tự điểm dừng `1..n`, `km_baseline`, `km_nn`, `km_toi_uu` và `thoi_gian_chay_ms`.
- `BalancedKMeansServiceTest`: Kiểm tra 32 đơn chia cho 3 tài xế đảm bảo không cụm nào vượt sức chứa 20 đơn, gắn kết địa lý, kết quả tất định 100% và loại bỏ cụm rỗng.
- `InboundServiceTest`: Kiểm tra `MockShopeeOrderService` và quy tắc lọc đơn trùng `ma_van_don`, đơn thiếu/sai tọa độ (`loi_toa_do` vượt `ban_kinh_phuc_vu_km`), đơn sai phường (`ngoai_vung_phuc_vu`) và chuẩn hóa tiếng Việt đa byte (`mb_strtolower`).

8.2. Kịch bản Demo Khép Kín End-to-End trước Hội đồng (Ấn tượng & Thuyết phục)
1. **Bước 1 - Điều phối viên (`/dieu-phoi`)**:
   - Đăng nhập `admin / 123456` -> Bấm **"Đồng bộ Shopee & Chia tuyến"** (cơ chế *Dispatch Lock* tự động nạp thêm các đơn chờ mới mà không làm xáo trộn cụm đang giao).
   - Bật/tắt chế độ xem **"Baseline"** vs **"2-Opt"** vs **"So sánh cả hai"** trên bản đồ để hội đồng thấy lộ trình cắt chéo được gỡ mượt mà.
   - Mở Tab *Đơn hàng lỗi tọa độ*, bấm **"Nominatim Preview"**, kéo-thả **Draggable Marker** tới đúng hẻm/đường rồi bấm **"Lưu tọa độ & Gán cụm"**; thử chuyển thủ công 1 đơn giữa 2 tài xế ở Tab *Tất cả đơn hàng*.
2. **Bước 2 - Khách hàng Tra cứu Công khai (`/login?ma_van_don=...`)**:
   - Mở trang `/login` (ẩn danh / không cần đăng nhập) -> Nhập mã vận đơn vừa phân tuyến (VD: `SPXVN261010001`) -> Trình chiếu thanh tiến trình 4 bước, SĐT khách được che bảo mật (`0912***101`), tên & SĐT Shipper phụ trách và số tiền COD cần chuẩn bị.
3. **Bước 3 - Tài xế ngoài đường (`/tai-xe`)**:
   - Đăng nhập tài khoản Shipper (`taixe_bac / 123456`) trên giao diện Mobile -> Chỉ vào thẻ **"Đơn hàng tiếp theo"** nổi bật số tiền COD, nút gọi `tel:` và mở Google Maps.
   - Bấm **"Giả lập vị trí trên bản đồ"** chấm sang góc phố khác -> Lộ trình đường phố OSRM và thứ tự `1..n` tự động tái định tuyến tức thì.
   - Bấm **"Giao thành công"** đơn số 1 (xác nhận thu COD, bản đồ tự động `flyTo` sang đơn số 2) và bấm **"Giao thất bại"** đơn số 2 kèm chọn lý do bắt buộc (*"Khách hẹn giao lại ca sau"*).
4. **Bước 4 - Trung tâm Báo cáo & Thực nghiệm (`/thong-ke`)**:
   - Kiểm tra **Tab 1 (Quản trị Vận hành & Đối soát COD)**: Số đơn thành công, tiền COD thực thu phải nộp về bưu cục của Shipper và số đơn thất bại tự động cập nhật trên KPI, 3 biểu đồ Chart.js và Bảng đối soát cuối ngày.
   - Chuyển sang **Tab 2 (Thực nghiệm Thuật toán)**: Bấm chạy Benchmark ($10, 20, 30, 40$ đơn) trình chiếu biểu đồ so sánh quãng đường `Baseline / NN / 2-Opt` và thời gian thực thi dưới vài chục mili-giây.


================================================================================
9. QUY TẮC CHO AI CODING ASSISTANT (COPILOT / ANTIGRAVITY)
================================================================================

A. Khi tạo khung dự án:
- Chỉ tạo thư mục và file đúng cây thư mục ở mục 7. Không tạo thêm file nào khác.
- Mọi file PHP: bắt đầu bằng &lt;?php, declare(strict_types=1), namespace khớp đường dẫn
  (app/Core/Router.php -&gt; namespace App\Core; class Router). Tên file = tên class. Không đóng ?&gt;.
- Chỉ tạo KHUNG: class rỗng + chữ ký hàm có docblock + dòng // TODO. KHÔNG viết logic.
- File có nội dung thật:
  + composer.json (PSR-4 App\ -> app/, require-dev phpunit/phpunit), phpunit.xml (thư mục tests)
  + .gitignore: config/database.php, .env, /vendor, .idea/, .vscode/, .DS_Store, Thumbs.db
  + LICENSE (MIT, năm hiện tại, tên chủ sở hữu để placeholder), README.md (khung), CONTRIBUTING.md
  + config/app.php (hằng số mục 2.1, BASE_URL = '/dinh-tuyen-spx/public')
  + config/database.example.php: host 127.0.0.1, port 3306, user root, password rỗng,
    dbname dinh_tuyen_spx, charset utf8mb4
  + database/schema.sql (chép nguyên SQL mục 6)
  + data/don_hang_mau.json (1 đơn mẫu như mục 2.2)
  + public/.htaccess: RewriteEngine On, RewriteBase /dinh-tuyen-spx/public/, chuyển mọi URL
    không phải file/thư mục thật về index.php
  + .github/pull_request_template.md
  + .github/copilot-instructions.md: 3 dòng, yêu cầu đọc docs/THIET_KE.md trước khi sinh code
    và tuân thủ mục 7, 9, 10
- Router.php phải cắt BASE_URL khỏi đường dẫn trước khi so khớp route; mọi link/fetch trong
  View và JS dùng BASE_URL.
- Thư mục chưa có file: tạo .gitkeep.
- Chữ ký hàm (đã chốt):
  + HaversineService::distance(float $lat1, float $lng1, float $lat2, float $lng2): float
  + BalancedKMeansService::cluster(array $orders, array $drivers, array $depot): array
  + NearestNeighborSolver::solve(array $start, array $points): array
  + TwoOptSolver::improve(array $route): array
  + DieuPhoiLoTrinhService::plan(array $start, array $points): array
- KHÔNG chạy git commit hay git push. Tạo xong thì in cây thư mục để kiểm tra.

B. Quy tắc cho mọi đoạn code sinh ra sau này:
- Câu SQL chỉ nằm trong app/Repositories, luôn dùng PDO prepared statement.
- Controller không viết SQL; View in dữ liệu qua hàm escape htmlspecialchars.
- Mọi request POST kiểm tra CSRF; mọi route và API kiểm tra quyền theo mục 5.2.
- Thuật toán (Clustering, Routing) là hàm thuần: nhận mảng, trả mảng, không đụng DB hay HTML.
- Comment bằng tiếng Việt; mỗi file một class.


================================================================================
10. KẾ HOẠCH PHÂN CHIA MODULE VÀ QUY TRÌNH PHỐI HỢP CHO NHÓM 3 NGƯỜI (TRÁNH XUNG ĐỘT GIT)
================================================================================

10.1. Nguyên tắc cốt lõi để loại trừ xung đột mã nguồn (Zero Git Conflicts)
1. Quyền sở hữu file đơn nhất (Strict Code Ownership): Mỗi file chỉ do ĐÚNG 1 thành viên chịu trách nhiệm viết và chỉnh sửa. Tuyệt đối không có 2 người cùng sửa chung 1 file trong cùng một ngày.
2. Chiến lược nhánh Git chuẩn (Feature Branch Workflow):
   - Nhánh `main` được bảo vệ: chỉ dùng để phát hành các bản chạy ổn định (Milestone). Không ai được commit trực tiếp vào `main`.
   - Mỗi người làm việc trên 1 nhánh tính năng riêng:
     + [THÀNH VIÊN 1]: `feature/core-algorithms`
     + [THÀNH VIÊN 2]: `feature/database-dispatcher`
     + [THÀNH VIÊN 3]: `feature/auth-driver-ui`
   - Chỉ merge vào `main` thông qua Pull Request (PR) theo đúng thứ tự mốc ở mục 10.3 sau khi đã kiểm tra không có xung đột.
3. Cơ chế Định tuyến tập trung [THÀNH VIÊN 1 SỞ HỮU]:
   - [THÀNH VIÊN 1] khai báo sẵn toàn bộ 100% các Route trong `public/index.php` ngay từ Ngày 1. [THÀNH VIÊN 2] và [THÀNH VIÊN 3] tuyệt đối KHÔNG sửa file `public/index.php` mà chỉ viết code logic bên trong Controller của mình.
4. Quản lý kết nối CSDL Singleton [THÀNH VIÊN 1 SỞ HỮU]:
   - [THÀNH VIÊN 1] cung cấp class `App\Core\Database` với phương thức `Database::getConnection(): PDO`. Cả [THÀNH VIÊN 2] (khi viết Repositories) và [THÀNH VIÊN 3] (khi viết AuthController) đều dùng chung kết nối này, không tự ý `new PDO` phân mảnh.
5. Hợp đồng định dạng mảng (Data Contract) giữa [THÀNH VIÊN 1] và [THÀNH VIÊN 2]:
   - Tọa độ: Luôn dùng đúng 2 key: `'vi_do'` (float) và `'kinh_do'` (float).
   - Mảng `$orders` (Đầu vào phân cụm): `[['id' => int, 'ma_van_don' => string, 'vi_do' => float, 'kinh_do' => float, 'phuong_id' => int], ...]`
   - Mảng `$drivers` (Tài xế khả dụng): `[['id' => int, 'ho_ten' => string, 'phuong_uu_tien_id' => int|null, 'suc_chua_con_lai' => int, 'mau_sac_cum' => string], ...]`
   - Mảng `$depot` / `$start`: `['vi_do' => float, 'kinh_do' => float]`
   - Mảng kết quả `cluster()`: `[['tai_xe' => array, 'tam_cum' => ['vi_do' => float, 'kinh_do' => float], 'don_hang' => array], ...]`
   - Mảng kết quả `plan()`: `['thu_tu' => array, 'km_baseline' => float, 'km_nn' => float, 'km_toi_uu' => float, 'thoi_gian_chay_ms' => float]`
6. Khung Layout và CDN Frontend dùng chung [THÀNH VIÊN 3 SỞ HỮU]:
   - [THÀNH VIÊN 3] tạo khung `layouts/header.php`, `footer.php`, `navbar.php` ngay Ngày 1-2, nhúng sẵn: Bootstrap 5, Leaflet 1.9.4 CSS/JS, Chart.js 4.4.x và thẻ `<meta name="csrf-token" content="...">`.
   - [THÀNH VIÊN 2] khi làm view `/dieu-phoi` và `/thong-ke` chỉ việc kế thừa layout có sẵn này.


10.2. Bảng phân công chi tiết công việc cho từng thành viên

A. THÀNH VIÊN 1 [NGƯỜI 1]: BACKEND LEAD, THUẬT TOÁN CỐT LÕI & KIỂM THỬ TỰ ĐỘNG
- Vai trò: Kiến trúc sư khung ứng dụng, cài đặt toàn bộ thuật toán hình học/tối ưu hóa thuần (Pure PHP, không đụng DB/HTML) và viết bộ kiểm thử tự động PHPUnit.
- Danh sách file sở hữu 100%:
  + `composer.json`, `phpunit.xml`, `.gitignore`, `LICENSE`, `README.md`, `CONTRIBUTING.md`
  + `config/app.php`, `config/database.example.php`
  + `app/bootstrap.php`
  + `app/Core/Router.php`, `app/Core/Database.php`, `app/Core/Session.php`, `app/Core/Csrf.php`, `app/Core/Validator.php`, `app/Core/ViewHelper.php`
  + `app/Services/Clustering/BalancedKMeansService.php`
  + `app/Services/Routing/HaversineService.php`, `app/Services/Routing/NearestNeighborSolver.php`, `app/Services/Routing/TwoOptSolver.php`, `app/Services/Routing/DieuPhoiLoTrinhService.php`
  + `tests/HaversineServiceTest.php`, `tests/BalancedKMeansServiceTest.php`, `tests/TwoOptSolverTest.php`
  + `public/index.php`, `public/.htaccess`
- Thứ tự thực hiện:
  1. Ngày 1: Tạo khung dự án (Bước 0 - xem mục 10.3), cấu hình Composer PSR-4, viết Router (cắt BASE_URL và nạp sẵn 100% routes), Database Singleton, Csrf, Session và đẩy lên nhánh `main`.
  2. Tuần 1: Cài đặt `HaversineService` và `NearestNeighborSolver`. Viết ngay Unit Test tương ứng để test độ chính xác.
  3. Tuần 2: Cài đặt `BalancedKMeansService` (khởi tạo góc quạt, gán ưu tiên theo hiệu khoảng cách, cân tải <= 20) và `TwoOptSolver` (đường hở). Viết `DieuPhoiLoTrinhService` làm cầu nối gọi NN + 2-Opt. Chạy PHPUnit phủ xanh 100%.
  4. Tuần 3: Viết script Benchmark đo thời gian chạy (ms) và hỗ trợ [THÀNH VIÊN 2] tích hợp API thống kê.

B. THÀNH VIÊN 2 [NGƯỜI 2]: CƠ SỞ DỮ LIỆU 3NF, ĐIỀU PHỐI VIÊN, ĐỐI SOÁT COD & THỐNG KÊ (DATA & DISPATCHER)
- Vai trò: Quản trị CSDL MySQL 5 bảng chuẩn 3NF, chuẩn hóa dữ liệu đầu vào Shopee & hành chính, xây dựng toàn bộ chức năng Điều phối (Khóa lộ trình đang giao Dispatch Lock, Chuyển cụm thủ công, sửa đơn lỗi kèm Draggable Marker Nominatim) và Trung tâm Thống kê 2 Tab (Vận hành/Đối soát COD & Benchmark Thuật toán).
- Danh sách file sở hữu 100%:
  + `database/schema.sql`, `database/seed.php`
  + `data/don_hang_mau.json`
  + `app/Repositories/BuuCucRepository.php`, `app/Repositories/PhuongRepository.php`, `app/Repositories/NguoiDungRepository.php`, `app/Repositories/CumTuyenGiaoRepository.php`, `app/Repositories/DonHangRepository.php`
  + `app/Services/ShopeeApi/ShopeeOrderClientInterface.php`, `app/Services/ShopeeApi/MockShopeeOrderService.php`, `app/Services/ShopeeApi/InboundService.php`
  + `app/Controllers/DieuPhoiController.php`, `app/Controllers/ThongKeController.php`
  + `app/Views/dieu_phoi/index.php`, `app/Views/thong_ke/index.php`
  + `public/assets/js/ban_do_dieu_phoi.js`, `public/assets/js/bieu_do.js`
  + `tests/InboundServiceTest.php`
- Thứ tự thực hiện:
  1. Ngày 2: Chạy `schema.sql` trên XAMPP, hoàn thiện `seed.php` nạp 2 Bưu cục (Nha Trang & Cam Ranh), các phường chuẩn mã hành chính, 1 điều phối, 3 tài xế, dữ liệu mồi 4 trạng thái kèm COD thực tế và file `data/don_hang_mau.json` (45 đơn chuẩn).
  2. Tuần 1: Viết 5 Repositories sử dụng PDO Prepared Statements (bao gồm các hàm truy vấn tra cứu mã vận đơn công khai `timTheoMaVanDon`, thống kê trạng thái đơn & đối soát COD theo tài xế/phường) và viết `InboundService`.
  3. Tuần 2: Xây dựng `DieuPhoiController`, view `/dieu-phoi` (3 Tab Bootstrap) và `ban_do_dieu_phoi.js` (Dispatch Lock bảo vệ cụm đang giao, chuyển cụm thủ công chạy lại 2-Opt, bản đồ Leaflet + OSRM và Draggable Marker Preview khi tra cứu Nominatim API).
  4. Tuần 3: Xây dựng `ThongKeController`, view `/thong-ke` và `bieu_do.js` tách làm 2 Tab độc lập: Tab 1 Quản trị Vận hành & Đối soát COD cuối ngày (3 biểu đồ Doughnut, Stacked Bar, Grouped Bar + bảng đối soát) và Tab 2 Thực nghiệm Benchmark Thuật toán.

C. THÀNH VIÊN 3 [NGƯỜI 3]: XÁC THỰC, TRA CỨU VẬN ĐƠN CÔNG KHAI, ỨNG DỤNG TÀI XẾ & UI/UX THƯƠNG HIỆU SPX (DRIVER & TRACKING)
- Vai trò: Quản lý đăng nhập/bảo mật người dùng, xây dựng Cổng tra cứu mã vận đơn công khai cho khách hàng, chuẩn hóa bộ nhận diện thương hiệu SPX Express trên layout dùng chung và phát triển ứng dụng di động thực địa cho Tài xế (GPS tự động, giả lập vị trí, OSRM, xác nhận COD & bắt buộc chọn lý do giao thất bại).
- Danh sách file sở hữu 100%:
  + `app/Controllers/AuthController.php`
  + `app/Controllers/TaiXeController.php`
  + `app/Views/layouts/header.php`, `app/Views/layouts/footer.php`, `app/Views/layouts/navbar.php`
  + `app/Views/auth/login.php`
  + `app/Views/tai_xe/index.php`
  + `public/assets/css/app.css`
  + `public/assets/js/ban_do_tai_xe.js`
- Thứ tự thực hiện:
  1. Ngày 2: Thiết lập layout Bootstrap 5 chuẩn màu thương hiệu SPX Express (`#EE4D2D`, `#1E293B`...) hiển thị rõ vai trò & tên bưu cục trên `navbar.php`; viết `AuthController` và tích hợp Cổng tra cứu vận đơn công khai (`GET /login?ma_van_don=...` kèm thanh tiến trình 4 bước và che SĐT khách hàng) ngay trên `login.php`.
  2. Tuần 1: Dựng giao diện Mobile-First màn hình Tài xế `/tai-xe` với thẻ nổi bật "Đơn hàng tiếp theo" (hiển thị tiền thu hộ COD / đơn 0đ, nút gọi điện `tel:`, mở Google Maps chỉ đường ngoài, nút thu gọn bản đồ và bộ lọc trạng thái đơn).
  3. Tuần 2: Viết `ban_do_tai_xe.js`: tích hợp công tắc tự động cập nhật GPS (`watchPosition`), nút "Giả lập vị trí trên bản đồ", gọi API OSRM vẽ đường theo phố (dự phòng Polyline khi timeout 3s) và tự động `map.flyTo` sang điểm kế tiếp sau khi giao xong.
  4. Tuần 3: Xây dựng `TaiXeController` cho `POST /api/tai-xe/cap-nhat-lo-trinh` và `POST /api/tai-xe/don-hang/{id}/ket-qua` kết hợp Modal bắt buộc chọn lý do giao thất bại (lưu `ly_do_that_bai`), xác nhận thu COD, chặn bấm đúp và tự động chuyển cụm sang `hoan_tat`.


10.3. Thứ tự các bước tích hợp và quy trình Git chuẩn
- Bước 0 (Khởi tạo - Duy nhất [THÀNH VIÊN 1] thực hiện):
  + [THÀNH VIÊN 1] tạo repo Git, tạo toàn bộ khung thư mục và file khung theo Mục 9A, nạp sẵn 100% routes trong `public/index.php`, viết `app/Core/Database.php`, commit với thông điệp: "build: initialize project skeleton and directory structure".
  + Đẩy lên branch `main` của GitHub.
  + [THÀNH VIÊN 2] và [THÀNH VIÊN 3] clone repo về máy XAMPP cá nhân: `git clone <url>`, tạo file `config/database.php` cục bộ (file này nằm trong .gitignore nên không bao giờ bị xung đột).
- Bước 1 (Làm việc song song trên nhánh tính năng):
  + [THÀNH VIÊN 1]: `git checkout -b feature/core-algorithms`
  + [THÀNH VIÊN 2]: `git checkout -b feature/database-dispatcher`
  + [THÀNH VIÊN 3]: `git checkout -b feature/auth-driver-ui`
  + Mỗi người chỉ commit trên nhánh của mình và chỉ sửa các file trong danh sách sở hữu ở mục 10.2.
- Bước 2 (Tích hợp Mốc 1 - Cuối Tuần 1):
  + [THÀNH VIÊN 1] merge `feature/core-algorithms` vào `main`.
  + [THÀNH VIÊN 2] và [THÀNH VIÊN 3] gõ lệnh: `git checkout main && git pull origin main`, sau đó về lại nhánh mình và gõ: `git merge main` để cập nhật nền tảng mới nhất từ [THÀNH VIÊN 1].
- Bước 3 (Tích hợp Mốc 2 - Cuối Tuần 2):
  + [THÀNH VIÊN 2] merge `feature/database-dispatcher` vào `main` (Điều phối hoàn thiện).
  + [THÀNH VIÊN 3] kéo `main` về để nối API của Tài xế vào Database của [THÀNH VIÊN 2].
- Bước 4 (Tổng duyệt Demo - Cuối Tuần 3):
  + [THÀNH VIÊN 3] merge `feature/auth-driver-ui` vào `main`.
  + Cả nhóm cùng kiểm tra kịch bản Demo 5 phút theo Mục 8.2 trên cùng 1 máy chủ XAMPP để chuẩn bị bảo vệ đồ án.
