(() => {
    'use strict';

    const app = document.querySelector('#driver-app');
    const mapElement = document.querySelector('#driver-map');
    if (!app || !mapElement || typeof L === 'undefined') {
        return;
    }

    const apiBase = app.dataset.baseUrl || '';
    const csrfToken = app.dataset.csrfToken || '';
    const mapStatus = document.querySelector('#map-status');
    const nextOrderElement = document.querySelector('#next-order');
    const googleMapsLink = document.querySelector('#open-google-maps');
    const deliverButton = document.querySelector('#deliver-next');
    const failButton = document.querySelector('#fail-next');
    const orderList = document.querySelector('#order-list');
    const gpsButton = document.querySelector('#use-gps');
    const mockButton = document.querySelector('#choose-location');

    let orders = parseJson(app.dataset.orders, []);
    let depot = parseJson(app.dataset.depot, { vi_do: 12.2488, kinh_do: 109.1905 });
    let currentLocation = null;
    let mockMode = false;
    let routeLayer = null;
    let driverMarker = null;
    let orderMarkers = [];

    orders = Array.isArray(orders)
        ? orders.filter(isMappableOrder).sort((a, b) => Number(a.thu_tu_giao || 0) - Number(b.thu_tu_giao || 0))
        : [];
    if (!isCoordinate(depot.vi_do, -90, 90) || !isCoordinate(depot.kinh_do, -180, 180)) {
        depot = { vi_do: 12.2488, kinh_do: 109.1905 };
    }

    const map = L.map(mapElement, { scrollWheelZoom: false }).setView(
        [Number(depot.vi_do), Number(depot.kinh_do)],
        12
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    L.marker([Number(depot.vi_do), Number(depot.kinh_do)])
        .addTo(map)
        .bindPopup('Bưu cục xuất phát');

    map.on('click', (event) => {
        if (!mockMode) {
            return;
        }

        mockMode = false;
        mockButton?.classList.remove('active');
        setCurrentLocation(event.latlng.lat, event.latlng.lng, 'Vị trí giả lập đã được chọn.');
    });

    gpsButton?.addEventListener('click', getBrowserLocation);
    mockButton?.addEventListener('click', () => {
        mockMode = !mockMode;
        mockButton.classList.toggle('active', mockMode);
        setStatus(mockMode ? 'Chạm vào bản đồ để chọn vị trí giả lập.' : 'Đã hủy chọn vị trí giả lập.');
    });
    deliverButton?.addEventListener('click', () => updateNextOrder('giao_thanh_cong'));
    failButton?.addEventListener('click', () => updateNextOrder('giao_that_bai'));

    renderOrders();
    void drawRoute();
    window.setTimeout(() => map.invalidateSize(), 0);

    function parseJson(value, fallback) {
        try {
            return value ? JSON.parse(value) : fallback;
        } catch {
            return fallback;
        }
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

    function popupContent(order) {
        const content = document.createElement('div');
        const trackingCode = document.createElement('strong');
        const address = document.createElement('div');
        trackingCode.textContent = String(order.ma_van_don || 'Đơn hàng');
        address.textContent = String(order.dia_chi_giao_hang || '');
        content.append(trackingCode, address);
        return content;
    }

    function renderOrders() {
        orderMarkers.forEach((marker) => marker.remove());
        orderMarkers = [];

        orders.forEach((order, index) => {
            const marker = L.marker([Number(order.vi_do), Number(order.kinh_do)])
                .addTo(map)
                .bindPopup(popupContent(order));
            orderMarkers.push(marker);
            if (index === 0) {
                marker.setZIndexOffset(1000);
            }
        });

        renderNextOrder();
        renderOrderList();
    }

    function renderNextOrder() {
        const order = orders[0];
        if (!order) {
            nextOrderElement.textContent = 'Chưa có đơn hàng đang chờ giao.';
            googleMapsLink.classList.add('disabled');
            googleMapsLink.setAttribute('aria-disabled', 'true');
            googleMapsLink.href = '#';
            deliverButton.disabled = true;
            failButton.disabled = true;
            return;
        }

        const heading = document.createElement('h2');
        heading.className = 'h5 mb-1';
        heading.textContent = String(order.ma_van_don || 'Đơn hàng');
        const customer = document.createElement('p');
        customer.className = 'text-secondary mb-0';
        customer.textContent = String(order.ten_khach_hang || '');
        nextOrderElement.replaceChildren(heading, customer);

        const destination = `${Number(order.vi_do)},${Number(order.kinh_do)}`;
        googleMapsLink.href = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
        googleMapsLink.classList.remove('disabled');
        googleMapsLink.setAttribute('aria-disabled', 'false');
        deliverButton.disabled = false;
        failButton.disabled = false;
    }

    function renderOrderList() {
        orderList.replaceChildren();
        if (orders.length === 0) {
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'list-group-item text-secondary';
            emptyMessage.textContent = 'Chưa có dữ liệu đơn hàng cho lộ trình.';
            orderList.append(emptyMessage);
            return;
        }

        orders.forEach((order) => {
            const item = document.createElement('article');
            item.className = 'list-group-item order-item';
            const code = document.createElement('h3');
            code.className = 'h6 mb-1';
            code.textContent = String(order.ma_van_don || 'Đơn hàng');
            const customer = document.createElement('p');
            customer.className = 'small text-secondary mb-1';
            customer.textContent = String(order.ten_khach_hang || '');
            const address = document.createElement('p');
            address.className = 'small mb-2';
            address.textContent = String(order.dia_chi_giao_hang || '');
            item.append(code, customer, address);

            const phone = String(order.so_dien_thoai_khach || '');
            if (/^[+0-9()\-\s]{6,20}$/.test(phone)) {
                const callLink = document.createElement('a');
                callLink.className = 'small';
                callLink.href = `tel:${phone.replace(/[\s()]/g, '')}`;
                callLink.textContent = 'Gọi khách';
                item.append(callLink);
            }

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
                    'Đã lấy vị trí GPS.'
                );
            },
            (error) => {
                gpsButton.disabled = false;
                setStatus(error.code === error.PERMISSION_DENIED
                    ? 'Bạn chưa cấp quyền truy cập vị trí.'
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
        if (driverMarker) {
            driverMarker.setLatLng([currentLocation.vi_do, currentLocation.kinh_do]);
        } else {
            driverMarker = L.marker([currentLocation.vi_do, currentLocation.kinh_do])
                .addTo(map)
                .bindPopup('Vị trí hiện tại của bạn');
        }
        map.setView([currentLocation.vi_do, currentLocation.kinh_do], 14);

        await drawRoute();
        const updated = await requestReroute();
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
            setStatus('Chưa có điểm giao hàng để vẽ lộ trình.');
            return;
        }

        const coordinates = points
            .map((point) => `${Number(point.kinh_do)},${Number(point.vi_do)}`)
            .join(';');
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 3000);

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
            routeLayer = L.polyline(latLngs, { color: '#2563eb', weight: 5, opacity: 0.8 }).addTo(map);
            map.fitBounds(routeLayer.getBounds(), { padding: [24, 24] });
            setStatus('Đã tải đường đi theo phố.');
        } catch {
            const latLngs = points.map((point) => [Number(point.vi_do), Number(point.kinh_do)]);
            routeLayer = L.polyline(latLngs, {
                color: '#2563eb',
                weight: 4,
                opacity: 0.75,
                dashArray: '8 8'
            }).addTo(map);
            map.fitBounds(routeLayer.getBounds(), { padding: [24, 24] });
            setStatus('Không lấy được đường OSRM; đang hiển thị đường nối dự phòng.');
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
                body: JSON.stringify(currentLocation)
            });
            const result = await response.json();
            if (!response.ok || result.thanh_cong !== true) {
                setStatus(result.thong_bao || 'Không thể lưu vị trí hoặc cập nhật lộ trình.');
                return false;
            }
            return true;
        } catch {
            setStatus('Không thể kết nối máy chủ để cập nhật lộ trình.');
            return false;
        }
    }

    async function updateNextOrder(status) {
        const order = orders[0];
        if (!order || !Number.isInteger(Number(order.id)) || Number(order.id) < 1) {
            setStatus('Đơn hàng không có mã hợp lệ để cập nhật.');
            return;
        }

        let failureReason = '';
        if (status === 'giao_that_bai') {
            failureReason = window.prompt('Nhập lý do giao thất bại:') || '';
            if (!failureReason.trim()) {
                return;
            }
        }

        deliverButton.disabled = true;
        failButton.disabled = true;
        try {
            const response = await fetch(
                `${apiBase}/api/tai-xe/don-hang/${Number(order.id)}/ket-qua`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
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

            orders = orders.filter((item) => Number(item.id) !== Number(order.id));
            renderOrders();
            await drawRoute();
            setStatus('Đã lưu kết quả giao hàng.');
        } catch {
            setStatus('Không thể kết nối máy chủ để cập nhật kết quả giao hàng.');
        } finally {
            deliverButton.disabled = orders.length === 0;
            failButton.disabled = orders.length === 0;
        }
    }
})();
