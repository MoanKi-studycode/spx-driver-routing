(() => {
    'use strict';

    const app = document.querySelector('#driver-app');
    const mapElement = document.querySelector('#driver-map');
    if (!app || !mapElement || typeof L === 'undefined') {
        return;
    }

    const apiBase = app.dataset.baseUrl || '';
    const csrfToken = app.dataset.csrfToken || '';
    const clusterColor = app.dataset.clusterColor || '#2563eb';
    const mapStatus = document.querySelector('#map-status');
    const nextOrderElement = document.querySelector('#next-order');
    const nextOrderSeqBadge = document.querySelector('#next-order-seq-badge');
    const googleMapsLink = document.querySelector('#open-google-maps');
    const deliverButton = document.querySelector('#deliver-next');
    const failButton = document.querySelector('#fail-next');
    const orderList = document.querySelector('#order-list');
    const gpsButton = document.querySelector('#use-gps');
    const mockButton = document.querySelector('#choose-location');

    let allOrders = parseJson(app.dataset.allOrders, []);
    let orders = parseJson(app.dataset.orders, []);
    let depot = parseJson(app.dataset.depot, { vi_do: 12.2488, kinh_do: 109.1905, ten_buu_cuc: 'Bưu cục SPX Nha Trang' });
    let savedDriverLoc = parseJson(app.dataset.driverLocation, null);
    let currentLocation = (savedDriverLoc && isCoordinate(savedDriverLoc.vi_do, -90, 90) && isCoordinate(savedDriverLoc.kinh_do, -180, 180))
        ? { vi_do: Number(savedDriverLoc.vi_do), kinh_do: Number(savedDriverLoc.kinh_do) }
        : null;

    let currentFilter = 'all'; // 'all' | 'pending' | 'done'
    let mockMode = false;
    let routeLayer = null;
    let driverMarker = null;
    let orderMarkers = [];

    if (!Array.isArray(allOrders) || allOrders.length === 0) {
        allOrders = Array.isArray(orders) ? [...orders] : [];
    }
    syncPendingOrdersFromAll();

    if (!isCoordinate(depot.vi_do, -90, 90) || !isCoordinate(depot.kinh_do, -180, 180)) {
        depot = { vi_do: 12.2488, kinh_do: 109.1905, ten_buu_cuc: 'Bưu cục SPX Nha Trang' };
    }

    const map = L.map(mapElement, { scrollWheelZoom: true }).setView(
        [Number(depot.vi_do), Number(depot.kinh_do)],
        13
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // Marker Bưu cục xuất phát (P0) nổi bật
    const depotIcon = L.divIcon({
        className: 'spx-driver-depot-icon',
        html: '<div style="background:#ee4d2d;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:13px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.35);">P0</div>',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });
    L.marker([Number(depot.vi_do), Number(depot.kinh_do)], { icon: depotIcon, zIndexOffset: 900 })
        .addTo(map)
        .bindPopup('<strong>' + escapeHtml(depot.ten_buu_cuc || 'Bưu cục xuất phát (P0)') + '</strong>');

    if (currentLocation) {
        renderDriverMarker();
    }

    map.on('click', (event) => {
        if (!mockMode) {
            return;
        }

        mockMode = false;
        mockButton?.classList.remove('active');
        setCurrentLocation(event.latlng.lat, event.latlng.lng, 'Đã cập nhật vị trí giả lập và tái định tuyến các điểm còn lại.');
    });

    gpsButton?.addEventListener('click', getBrowserLocation);
    mockButton?.addEventListener('click', () => {
        mockMode = !mockMode;
        mockButton.classList.toggle('active', mockMode);
        setStatus(mockMode ? 'Hãy chạm vào một điểm trên bản đồ để giả lập vị trí hiện tại của tài xế.' : 'Đã hủy chọn vị trí giả lập.');
    });
    deliverButton?.addEventListener('click', () => {
        if (orders[0]) {
            void updateOrderResult(Number(orders[0].id), 'giao_thanh_cong');
        }
    });
    failButton?.addEventListener('click', () => {
        if (orders[0]) {
            void updateOrderResult(Number(orders[0].id), 'giao_that_bai');
        }
    });

    document.querySelectorAll('[data-driver-filter]').forEach((btn) => {
        btn.addEventListener('click', () => {
            currentFilter = btn.getAttribute('data-driver-filter') || 'all';
            document.querySelectorAll('[data-driver-filter]').forEach((b) => {
                b.classList.remove('active', 'btn-primary');
                if (!b.classList.contains('btn-outline-primary') && !b.classList.contains('btn-outline-success')) {
                    b.classList.add('btn-outline-primary');
                }
            });
            btn.classList.remove('btn-outline-primary', 'btn-outline-success');
            btn.classList.add('btn-primary', 'active');
            renderOrderList();
        });
    });

    orderList?.addEventListener('click', (evt) => {
        const actionBtn = evt.target.closest('[data-order-action]');
        if (actionBtn) {
            const orderId = Number(actionBtn.getAttribute('data-order-id'));
            const action = actionBtn.getAttribute('data-order-action');
            if (orderId > 0 && (action === 'giao_thanh_cong' || action === 'giao_that_bai')) {
                void updateOrderResult(orderId, action);
            }
            return;
        }

        const focusCard = evt.target.closest('[data-focus-order-id]');
        if (focusCard && !evt.target.closest('a, button')) {
            const focusId = Number(focusCard.getAttribute('data-focus-order-id'));
            const found = allOrders.find((o) => Number(o.id) === focusId);
            if (found && isMappableOrder(found)) {
                map.setView([Number(found.vi_do), Number(found.kinh_do)], 16);
            }
        }
    });

    renderOrders();
    void drawRoute();
    window.setTimeout(() => map.invalidateSize(), 0);

    function syncPendingOrdersFromAll() {
        orders = allOrders
            .filter((o) => isMappableOrder(o) && (o.trang_thai || 'da_phan_cong') === 'da_phan_cong')
            .sort((a, b) => Number(a.thu_tu_giao || 0) - Number(b.thu_tu_giao || 0));
    }

    function parseJson(value, fallback) {
        try {
            return value ? JSON.parse(value) : fallback;
        } catch {
            return fallback;
        }
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatCurrency(amount) {
        const num = Number(amount) || 0;
        return num.toLocaleString('vi-VN') + ' đ';
    }

    function isCoordinate(value, minimum, maximum) {
        const number = Number(value);
        return Number.isFinite(number) && number >= minimum && number <= maximum;
    }

    function isMappableOrder(order) {
        return order
            && typeof order === 'object'
            && isCoordinate(order.vi_do, -90, 90)
            && isCoordinate(order.kinh_do, -180, 180);
    }

    function setStatus(message) {
        if (mapStatus) {
            mapStatus.textContent = message;
        }
    }

    function renderDriverMarker() {
        if (!currentLocation) {
            return;
        }
        const driverIcon = L.divIcon({
            className: 'spx-driver-gps-icon',
            html: '<div style="background:#16a34a;color:#fff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:15px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);">🚚</div>',
            iconSize: [34, 34],
            iconAnchor: [17, 17]
        });

        if (driverMarker) {
            driverMarker.setLatLng([currentLocation.vi_do, currentLocation.kinh_do]);
        } else {
            driverMarker = L.marker([currentLocation.vi_do, currentLocation.kinh_do], { icon: driverIcon, zIndexOffset: 1200 })
                .addTo(map)
                .bindPopup('<strong>Vị trí hiện tại của bạn</strong><br>Tọa độ: ' + currentLocation.vi_do.toFixed(5) + ', ' + currentLocation.kinh_do.toFixed(5));
        }
    }

    function renderOrders() {
        orderMarkers.forEach((marker) => marker.remove());
        orderMarkers = [];

        syncPendingOrdersFromAll();

        // 1. Vẽ các điểm đang chờ giao với SỐ THỨ TỰ 1, 2, 3... rõ ràng trên bản đồ
        orders.forEach((order, index) => {
            const seqNumber = index + 1;
            const isNextStop = index === 0;
            const bgColor = isNextStop ? '#ee4d2d' : clusterColor;
            const size = isNextStop ? 32 : 26;
            const anchor = size / 2;

            const numberedIcon = L.divIcon({
                className: 'spx-driver-stop-marker',
                html: '<div style="background:' + escapeHtml(bgColor) + ';color:#fff;width:' + size + 'px;height:' + size + 'px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:' + (isNextStop ? '13px' : '11px') + ';font-weight:bold;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.4);">' + seqNumber + '</div>',
                iconSize: [size, size],
                iconAnchor: [anchor, anchor]
            });

            const marker = L.marker([Number(order.vi_do), Number(order.kinh_do)], {
                icon: numberedIcon,
                zIndexOffset: isNextStop ? 1000 : (500 - index)
            })
                .addTo(map)
                .bindPopup(
                    '<div style="min-width:210px;">' +
                    '<div class="fw-bold" style="color:' + escapeHtml(bgColor) + ';">Điểm dừng #' + seqNumber + (isNextStop ? ' (Đơn kế tiếp)' : '') + '</div>' +
                    '<div><strong>Mã vận đơn:</strong> ' + escapeHtml(order.ma_van_don) + '</div>' +
                    '<div><strong>Khách hàng:</strong> ' + escapeHtml(order.ten_khach_hang) + '</div>' +
                    '<div><strong>SĐT:</strong> ' + escapeHtml(order.so_dien_thoai_khach || '-') + '</div>' +
                    '<div><strong>Địa chỉ:</strong> ' + escapeHtml(order.dia_chi_giao_hang) + '</div>' +
                    '<div><strong>COD:</strong> <span class="text-danger fw-bold">' + formatCurrency(order.tien_thu_ho) + '</span></div>' +
                    '</div>'
                );
            orderMarkers.push(marker);
        });

        // 2. Vẽ các điểm đã giao thành công (✓) hoặc giao thất bại (✕)
        allOrders.forEach((order) => {
            const st = order.trang_thai || 'da_phan_cong';
            if (st === 'da_phan_cong' || !isMappableOrder(order)) {
                return;
            }
            const isSuccess = st === 'giao_thanh_cong';
            const badgeSymbol = isSuccess ? '✓' : '✕';
            const badgeColor = isSuccess ? '#16a34a' : '#64748b';
            const statusText = isSuccess ? 'Đã giao thành công' : ('Giao thất bại: ' + (order.ly_do_that_bai || ''));

            const doneIcon = L.divIcon({
                className: 'spx-driver-done-marker',
                html: '<div style="background:' + badgeColor + ';color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;border:2px solid #fff;opacity:0.85;box-shadow:0 1px 4px rgba(0,0,0,0.3);">' + badgeSymbol + '</div>',
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });

            const marker = L.marker([Number(order.vi_do), Number(order.kinh_do)], {
                icon: doneIcon,
                zIndexOffset: 100
            })
                .addTo(map)
                .bindPopup(
                    '<div style="min-width:200px;">' +
                    '<div class="fw-bold" style="color:' + badgeColor + ';">' + escapeHtml(statusText) + '</div>' +
                    '<div><strong>Mã vận đơn:</strong> ' + escapeHtml(order.ma_van_don) + '</div>' +
                    '<div><strong>Khách hàng:</strong> ' + escapeHtml(order.ten_khach_hang) + '</div>' +
                    '<div><strong>Địa chỉ:</strong> ' + escapeHtml(order.dia_chi_giao_hang) + '</div>' +
                    '</div>'
                );
            orderMarkers.push(marker);
        });

        updateDriverKpis();
        renderNextOrder();
        renderOrderList();
    }

    function updateDriverKpis() {
        let pendingCount = 0;
        let deliveredCount = 0;
        let failedCount = 0;
        let codPending = 0;
        let codCollected = 0;

        allOrders.forEach((o) => {
            const st = o.trang_thai || 'da_phan_cong';
            const cod = Number(o.tien_thu_ho || 0);
            if (st === 'giao_thanh_cong') {
                deliveredCount++;
                codCollected += cod;
            } else if (st === 'giao_that_bai') {
                failedCount++;
            } else {
                pendingCount++;
                codPending += cod;
            }
        });

        const elTotal = document.getElementById('drv-kpi-total');
        const elStatus = document.getElementById('drv-kpi-status-badge');
        const elPending = document.getElementById('drv-kpi-pending');
        const elCodPending = document.getElementById('drv-kpi-cod-pending');
        const elDelivered = document.getElementById('drv-kpi-delivered');
        const elCodCollected = document.getElementById('drv-kpi-cod-collected');
        const elFailed = document.getElementById('drv-kpi-failed');

        if (elTotal) elTotal.textContent = allOrders.length + ' đơn';
        if (elStatus) elStatus.textContent = (pendingCount === 0 && allOrders.length > 0) ? 'Đã hoàn tất toàn bộ tuyến' : 'Đang thực hiện giao hàng';
        if (elPending) elPending.textContent = pendingCount + ' đơn';
        if (elCodPending) elCodPending.textContent = 'COD cần thu: ' + formatCurrency(codPending);
        if (elDelivered) elDelivered.textContent = deliveredCount + ' đơn';
        if (elCodCollected) elCodCollected.textContent = 'Đã thu COD: ' + formatCurrency(codCollected);
        if (elFailed) elFailed.textContent = failedCount + ' đơn';

        const tabAll = document.getElementById('drv-tab-all');
        const tabPending = document.getElementById('drv-tab-pending');
        const tabDone = document.getElementById('drv-tab-done');
        if (tabAll) tabAll.textContent = 'Tất cả (' + allOrders.length + ')';
        if (tabPending) tabPending.textContent = 'Chờ giao (' + pendingCount + ')';
        if (tabDone) tabDone.textContent = 'Đã giao / Hoàn (' + (deliveredCount + failedCount) + ')';
    }

    function renderNextOrder() {
        const order = orders[0];
        if (!order) {
            if (nextOrderSeqBadge) {
                nextOrderSeqBadge.textContent = 'Hoàn tất';
            }
            nextOrderElement.innerHTML = '<p class="text-success fw-semibold mb-0"><i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng trong tuyến đã được xử lý xong!</p>';
            googleMapsLink.classList.add('disabled');
            googleMapsLink.setAttribute('aria-disabled', 'true');
            googleMapsLink.href = '#';
            deliverButton.disabled = true;
            failButton.disabled = true;
            return;
        }

        if (nextOrderSeqBadge) {
            nextOrderSeqBadge.textContent = 'Điểm dừng #1 (Gần nhất)';
        }

        const phone = String(order.so_dien_thoai_khach || '');
        const phoneHtml = /^[+0-9()\-\s]{6,20}$/.test(phone)
            ? ' &bull; <a href="tel:' + escapeHtml(phone.replace(/[\s()]/g, '')) + '" class="fw-semibold text-decoration-none"><i class="bi bi-telephone-fill me-1"></i>' + escapeHtml(phone) + '</a>'
            : '';

        nextOrderElement.innerHTML =
            '<h2 class="h5 fw-bold mb-1">' + escapeHtml(order.ma_van_don || 'Đơn hàng') + '</h2>' +
            '<p class="text-dark mb-1"><strong>Khách hàng:</strong> ' + escapeHtml(order.ten_khach_hang || '') + phoneHtml + '</p>' +
            '<p class="small text-secondary mb-1"><i class="bi bi-geo-alt me-1"></i>' + escapeHtml(order.dia_chi_giao_hang || '') + '</p>' +
            '<p class="small fw-bold text-danger mb-0">Thu hộ COD: ' + formatCurrency(order.tien_thu_ho) + '</p>';

        const destination = `${Number(order.vi_do)},${Number(order.kinh_do)}`;
        googleMapsLink.href = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
        googleMapsLink.classList.remove('disabled');
        googleMapsLink.setAttribute('aria-disabled', 'false');
        deliverButton.disabled = false;
        failButton.disabled = false;
    }

    function renderOrderList() {
        orderList.replaceChildren();

        // Tính thứ tự dừng hiện tại cho các đơn đang chờ
        const pendingRankMap = {};
        orders.forEach((o, idx) => {
            pendingRankMap[Number(o.id)] = idx + 1;
        });

        const filtered = allOrders.filter((o) => {
            const st = o.trang_thai || 'da_phan_cong';
            if (currentFilter === 'pending') {
                return st === 'da_phan_cong';
            }
            if (currentFilter === 'done') {
                return st === 'giao_thanh_cong' || st === 'giao_that_bai';
            }
            return true;
        });

        // Sắp xếp: đơn đang chờ giao lên trước theo thứ tự điểm dừng 1..n, sau đó đến đơn đã xử lý
        filtered.sort((a, b) => {
            const stA = (a.trang_thai || 'da_phan_cong') === 'da_phan_cong' ? 0 : 1;
            const stB = (b.trang_thai || 'da_phan_cong') === 'da_phan_cong' ? 0 : 1;
            if (stA !== stB) {
                return stA - stB;
            }
            return Number(a.thu_tu_giao || 0) - Number(b.thu_tu_giao || 0);
        });

        if (filtered.length === 0) {
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'list-group-item text-secondary text-center py-4';
            emptyMessage.textContent = 'Không có đơn hàng nào trong mục này.';
            orderList.append(emptyMessage);
            return;
        }

        filtered.forEach((order) => {
            const st = order.trang_thai || 'da_phan_cong';
            const stopRank = pendingRankMap[Number(order.id)] || order.thu_tu_giao || '-';
            const isPending = st === 'da_phan_cong';
            const isSuccess = st === 'giao_thanh_cong';

            let statusBadge = '<span class="badge bg-primary-subtle text-primary border">Chờ giao - Điểm #' + escapeHtml(stopRank) + '</span>';
            if (isSuccess) {
                statusBadge = '<span class="badge bg-success-subtle text-success border"><i class="bi bi-check-circle-fill me-1"></i>Đã giao thành công</span>';
            } else if (st === 'giao_that_bai') {
                statusBadge = '<span class="badge bg-danger-subtle text-danger border"><i class="bi bi-x-circle-fill me-1"></i>Giao thất bại</span>';
            }

            const phone = String(order.so_dien_thoai_khach || '');
            const callHtml = /^[+0-9()\-\s]{6,20}$/.test(phone)
                ? '<a class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" href="tel:' + escapeHtml(phone.replace(/[\s()]/g, '')) + '"><i class="bi bi-telephone me-1"></i>' + escapeHtml(phone) + '</a>'
                : '';

            const failReasonHtml = (st === 'giao_that_bai' && order.ly_do_that_bai)
                ? '<div class="small text-danger mt-1"><strong>Lý do hoàn:</strong> ' + escapeHtml(order.ly_do_that_bai) + '</div>'
                : '';

            const actionsHtml = isPending
                ? '<div class="d-flex flex-wrap gap-1 mt-2">' +
                  '<button type="button" class="btn btn-sm btn-success py-0 px-2" data-order-action="giao_thanh_cong" data-order-id="' + Number(order.id) + '"><i class="bi bi-check2 me-1"></i>Đã giao</button>' +
                  '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" data-order-action="giao_that_bai" data-order-id="' + Number(order.id) + '"><i class="bi bi-x me-1"></i>Thất bại</button>' +
                  callHtml +
                  '</div>'
                : '<div class="d-flex flex-wrap gap-1 mt-1">' + callHtml + '</div>';

            const item = document.createElement('article');
            item.className = 'list-group-item order-item';
            item.setAttribute('data-focus-order-id', String(Number(order.id)));
            item.style.cursor = 'pointer';
            item.innerHTML =
                '<div class="d-flex justify-content-between align-items-start gap-2">' +
                '<div>' +
                '<div class="d-flex align-items-center gap-2 mb-1">' +
                (isPending
                    ? '<span class="badge rounded-pill" style="background:' + (stopRank === 1 ? '#ee4d2d' : escapeHtml(clusterColor)) + ';">#' + escapeHtml(stopRank) + '</span>'
                    : '') +
                '<h3 class="h6 fw-bold mb-0">' + escapeHtml(order.ma_van_don || 'Đơn hàng') + '</h3>' +
                '</div>' +
                '<div class="small fw-semibold text-dark">' + escapeHtml(order.ten_khach_hang || '') + ' &bull; <span class="text-danger">' + formatCurrency(order.tien_thu_ho) + '</span></div>' +
                '<div class="small text-secondary">' + escapeHtml(order.dia_chi_giao_hang || '') + '</div>' +
                failReasonHtml +
                actionsHtml +
                '</div>' +
                '<div class="text-end">' + statusBadge + '</div>' +
                '</div>';

            orderList.append(item);
        });
    }

    function getBrowserLocation() {
        if (!navigator.geolocation) {
            setStatus('Trình duyệt không hỗ trợ định vị GPS.');
            return;
        }

        gpsButton.disabled = true;
        setStatus('Đang lấy vị trí GPS…');
        navigator.geolocation.getCurrentPosition(
            (position) => {
                gpsButton.disabled = false;
                setCurrentLocation(
                    position.coords.latitude,
                    position.coords.longitude,
                    'Đã lấy vị trí GPS và tối ưu lại thứ tự giao hàng.'
                );
            },
            (error) => {
                gpsButton.disabled = false;
                setStatus(error.code === error.PERMISSION_DENIED
                    ? 'Bạn chưa cấp quyền truy cập vị trí. Hãy dùng nút "Giả lập vị trí trên bản đồ".'
                    : 'Không thể lấy vị trí GPS. Hãy thử chọn vị trí giả lập.');
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 }
        );
    }

    async function setCurrentLocation(latitude, longitude, message) {
        if (!isCoordinate(latitude, -90, 90) || !isCoordinate(longitude, -180, 180)) {
            setStatus('Tọa độ vị trí không hợp lệ.');
            return;
        }

        currentLocation = { vi_do: Number(latitude), kinh_do: Number(longitude) };
        renderDriverMarker();
        map.setView([currentLocation.vi_do, currentLocation.kinh_do], 14);

        const updated = await requestReroute();
        await drawRoute();
        if (updated && message) {
            setStatus(message);
        }
    }

    async function drawRoute() {
        const start = currentLocation || { vi_do: Number(depot.vi_do), kinh_do: Number(depot.kinh_do) };
        const points = [start, ...orders];
        if (routeLayer) {
            routeLayer.remove();
            routeLayer = null;
        }
        if (points.length < 2) {
            setStatus('Tất cả các điểm giao trong tuyến đã hoàn tất.');
            return;
        }

        const coordinates = points
            .map((point) => `${Number(point.kinh_do)},${Number(point.vi_do)}`)
            .join(';');
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 5000);

        try {
            const response = await fetch(
                `https://router.project-osrm.org/route/v1/driving/${coordinates}?overview=full&geometries=geojson&steps=false`,
                { signal: controller.signal }
            );
            if (!response.ok) {
                throw new Error(`OSRM returned ${response.status}`);
            }

            const result = await response.json();
            const routeCoordinates = result.routes?.[0]?.geometry?.coordinates;
            if (!Array.isArray(routeCoordinates) || routeCoordinates.length === 0) {
                throw new Error('OSRM did not return route geometry');
            }

            const latLngs = routeCoordinates.map(([lng, lat]) => [lat, lng]);
            routeLayer = L.polyline(latLngs, { color: clusterColor, weight: 5, opacity: 0.85 }).addTo(map);
            map.fitBounds(routeLayer.getBounds(), { padding: [30, 30] });
            setStatus('Đang hiển thị tuyến đường đi thực tế qua OSRM (' + orders.length + ' điểm chờ giao).');
        } catch {
            const latLngs = points.map((point) => [Number(point.vi_do), Number(point.kinh_do)]);
            routeLayer = L.polyline(latLngs, {
                color: clusterColor,
                weight: 4,
                opacity: 0.75,
                dashArray: '8 8'
            }).addTo(map);
            map.fitBounds(routeLayer.getBounds(), { padding: [30, 30] });
            setStatus('OSRM tạm thời phản hồi chậm; đang hiển thị đường nối dự phòng.');
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    async function requestReroute() {
        try {
            const response = await fetch(`${apiBase}/api/tai-xe/cap-nhat-lo-trinh`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    _csrf_token: csrfToken,
                    vi_do: currentLocation.vi_do,
                    kinh_do: currentLocation.kinh_do
                })
            });
            const result = await response.json();
            if (!response.ok || result.thanh_cong !== true) {
                setStatus(result.thong_bao || 'Không thể lưu vị trí hoặc cập nhật lộ trình.');
                return false;
            }
            if (Array.isArray(result.du_lieu?.don_hang)) {
                const updatedMap = {};
                result.du_lieu.don_hang.forEach((u) => {
                    updatedMap[Number(u.id)] = Number(u.thu_tu_giao || 0);
                });
                allOrders = allOrders.map((item) => {
                    if (updatedMap[Number(item.id)] !== undefined) {
                        return { ...item, thu_tu_giao: updatedMap[Number(item.id)] };
                    }
                    return item;
                });
                renderOrders();
            }
            return true;
        } catch {
            setStatus('Không thể kết nối máy chủ để cập nhật lộ trình.');
            return false;
        }
    }

    async function updateOrderResult(orderId, status) {
        if (!Number.isInteger(orderId) || orderId < 1) {
            setStatus('Đơn hàng không có mã hợp lệ để cập nhật.');
            return;
        }

        let failureReason = '';
        if (status === 'giao_that_bai') {
            failureReason = window.prompt('Nhập lý do giao thất bại (VD: Khách hẹn giao lại chiều, Không nghe máy):') || '';
            if (!failureReason.trim()) {
                return;
            }
        }

        deliverButton.disabled = true;
        failButton.disabled = true;
        try {
            const response = await fetch(
                `${apiBase}/api/tai-xe/don-hang/${orderId}/ket-qua`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        _csrf_token: csrfToken,
                        trang_thai: status,
                        ly_do_that_bai: failureReason
                    })
                }
            );
            const result = await response.json();
            if (!response.ok || result.thanh_cong !== true) {
                setStatus(result.thong_bao || 'Không thể cập nhật kết quả giao hàng.');
                return;
            }

            allOrders = allOrders.map((item) => {
                if (Number(item.id) === orderId) {
                    return {
                        ...item,
                        trang_thai: status,
                        ly_do_that_bai: status === 'giao_that_bai' ? failureReason.trim() : null,
                        thoi_gian_giao: new Date().toISOString()
                    };
                }
                return item;
            });

            renderOrders();
            await drawRoute();
            setStatus(status === 'giao_thanh_cong'
                ? 'Đã xác nhận GIAO THÀNH CÔNG và đồng bộ về trung tâm điều phối.'
                : 'Đã ghi nhận GIAO THẤT BẠI và đồng bộ về trung tâm điều phối.');
        } catch {
            setStatus('Không thể kết nối máy chủ để cập nhật kết quả giao hàng.');
        } finally {
            deliverButton.disabled = orders.length === 0;
            failButton.disabled = orders.length === 0;
        }
    }
})();
