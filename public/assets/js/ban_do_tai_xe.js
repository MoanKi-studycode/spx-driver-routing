(() => {
    'use strict';

    const app = document.querySelector('#driver-app');
    const mapElement = document.querySelector('#driver-map');
    if (!app || !mapElement || typeof L === 'undefined') {
        return;
    }

    const apiBase = app.dataset.baseUrl || '';
    const csrfToken = app.dataset.csrfToken || '';
    const clusterColor = /^#[\da-f]{6}$/i.test(app.dataset.clusterColor || '')
        ? app.dataset.clusterColor
        : '#EE4D2D';
    const mapStatus = document.querySelector('#map-status');
    const nextOrderElement = document.querySelector('#next-order');
    const nextOrderSeqBadge = document.querySelector('#next-order-seq-badge');
    const googleMapsLink = document.querySelector('#open-google-maps');
    const deliverButton = document.querySelector('#deliver-next');
    const failButton = document.querySelector('#fail-next');
    const orderList = document.querySelector('#order-list');
    const gpsButton = document.querySelector('#use-gps');
    const gpsToggle = document.querySelector('#toggle-gps');
    const mockButton = document.querySelector('#choose-location');
    const mapToggleButton = document.querySelector('#toggle-map');
    const mapContainer = document.querySelector('.driver-map-container');
    const failureDialog = document.querySelector('#failure-reason-dialog');
    const failureForm = document.querySelector('#failure-reason-form');
    const failureChoice = document.querySelector('#failure-reason-choice');
    const failureNote = document.querySelector('#failure-reason-note');
    const failureNoteLabel = document.querySelector('#failure-reason-note-label');
    const cancelFailureButton = document.querySelector('#cancel-failure-reason');

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
    let gpsWatchId = null;
    let lastGpsRerouteAt = 0;
    let gpsReroutePending = false;
    let isUpdatingResult = false;
    let isFailureDialogOpen = false;

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
        html: '<div style="background:#EE4D2D;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:13px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.35);">P0</div>',
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
    gpsToggle?.addEventListener('change', () => {
        if (gpsToggle.checked) {
            startGpsWatch();
        } else {
            stopGpsWatch();
        }
    });
    mapToggleButton?.addEventListener('click', () => {
        const collapsed = mapContainer?.classList.toggle('is-collapsed') || false;
        mapToggleButton.setAttribute('aria-expanded', String(!collapsed));
        mapToggleButton.textContent = collapsed ? 'Mở bản đồ' : 'Thu gọn bản đồ';
        if (!collapsed) {
            window.setTimeout(() => map.invalidateSize(), 0);
        }
    });
    failureChoice?.addEventListener('change', () => {
        const needsNote = failureChoice.value === 'other';
        failureNote?.classList.toggle('d-none', !needsNote);
        failureNoteLabel?.classList.toggle('d-none', !needsNote);
        if (failureNote) {
            failureNote.required = needsNote;
            if (!needsNote) {
                failureNote.value = '';
            }
        }
    });
    failureNote?.addEventListener('input', () => failureNote.setCustomValidity(''));
    cancelFailureButton?.addEventListener('click', () => failureDialog?.close());
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
        const target = evt.target instanceof Element ? evt.target : null;
        if (!target) {
            return;
        }
        const actionBtn = target.closest('[data-order-action]');
        if (actionBtn) {
            const orderId = Number(actionBtn.getAttribute('data-order-id'));
            const action = actionBtn.getAttribute('data-order-action');
            if (orderId > 0 && (action === 'giao_thanh_cong' || action === 'giao_that_bai')) {
                void updateOrderResult(orderId, action);
            }
            return;
        }

        const focusCard = target.closest('[data-focus-order-id]');
        if (focusCard && !target.closest('a, button')) {
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
    window.addEventListener('pagehide', stopGpsWatch);

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
            html: '<div style="background:#10B981;color:#fff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:15px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);">🚚</div>',
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
            const bgColor = isNextStop ? '#EE4D2D' : clusterColor;
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
            const badgeColor = isSuccess ? '#10B981' : '#EF4444';
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
            '<p class="small fw-bold text-danger mb-0">' +
            (Number(order.tien_thu_ho || 0) > 0
                ? 'Cần thu COD: ' + formatCurrency(order.tien_thu_ho)
                : 'Đơn 0đ - Không thu tiền') +
            '</p>';

        const destination = `${Number(order.vi_do)},${Number(order.kinh_do)}`;
        googleMapsLink.href = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
        googleMapsLink.classList.remove('disabled');
        googleMapsLink.setAttribute('aria-disabled', 'false');
        deliverButton.disabled = isUpdatingResult;
        failButton.disabled = isUpdatingResult;
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
                  '<button type="button" class="btn btn-sm btn-success px-2" data-order-action="giao_thanh_cong" data-order-id="' + Number(order.id) + '"' + (isUpdatingResult ? ' disabled' : '') + '><i class="bi bi-check2 me-1"></i>Đã giao</button>' +
                  '<button type="button" class="btn btn-sm btn-outline-danger px-2" data-order-action="giao_that_bai" data-order-id="' + Number(order.id) + '"' + (isUpdatingResult ? ' disabled' : '') + '><i class="bi bi-x me-1"></i>Thất bại</button>' +
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
                    ? '<span class="badge rounded-pill" style="background:' + (stopRank === 1 ? '#EE4D2D' : escapeHtml(clusterColor)) + ';">#' + escapeHtml(stopRank) + '</span>'
                    : '') +
                '<h3 class="h6 fw-bold mb-0">' + escapeHtml(order.ma_van_don || 'Đơn hàng') + '</h3>' +
                '</div>' +
                '<div class="small fw-semibold text-dark">' + escapeHtml(order.ten_khach_hang || '') + ' &bull; <span class="text-danger">' +
                (Number(order.tien_thu_ho || 0) > 0 ? 'COD ' + formatCurrency(order.tien_thu_ho) : 'Đơn 0đ - Không thu tiền') +
                '</span></div>' +
                '<div class="small text-secondary">' + escapeHtml(order.dia_chi_giao_hang || '') + '</div>' +
                failReasonHtml +
                actionsHtml +
                '</div>' +
                '<div class="text-end">' + statusBadge + '</div>' +
                '</div>';

            orderList.append(item);
        });
    }

    function startGpsWatch() {
        if (!navigator.geolocation) {
            setStatus('Trình duyệt không hỗ trợ định vị GPS.');
            if (gpsToggle) {
                gpsToggle.checked = false;
            }
            return;
        }
        if (gpsWatchId !== null) {
            return;
        }

        setStatus('Đang bật cập nhật GPS liên tục.');
        gpsWatchId = navigator.geolocation.watchPosition(
            (position) => {
                if (!isCoordinate(position.coords.latitude, -90, 90)
                    || !isCoordinate(position.coords.longitude, -180, 180)) {
                    setStatus('GPS trả về tọa độ không hợp lệ.');
                    return;
                }

                currentLocation = {
                    vi_do: Number(position.coords.latitude),
                    kinh_do: Number(position.coords.longitude)
                };
                renderDriverMarker();
                const now = Date.now();
                if (now - lastGpsRerouteAt < 15000 || gpsReroutePending) {
                    return;
                }
                lastGpsRerouteAt = now;
                gpsReroutePending = true;
                void requestReroute().then(async (updated) => {
                    await drawRoute();
                    if (updated && gpsToggle && gpsToggle.checked) {
                        setStatus('GPS đang cập nhật vị trí và lộ trình tự động.');
                    }
                }).finally(() => {
                    gpsReroutePending = false;
                });
            },
            (error) => {
                setStatus(error.code === error.PERMISSION_DENIED
                    ? 'Bạn chưa cấp quyền truy cập vị trí. Hãy cho phép GPS trong trình duyệt.'
                    : 'Không thể cập nhật GPS liên tục. Vui lòng thử lại.');
                if (gpsToggle) {
                    gpsToggle.checked = false;
                }
                stopGpsWatch();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 }
        );
    }

    function stopGpsWatch() {
        if (gpsWatchId !== null && navigator.geolocation) {
            navigator.geolocation.clearWatch(gpsWatchId);
            gpsWatchId = null;
        }
        if (gpsToggle) {
            gpsToggle.checked = false;
        }
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

    function requestFailureReason() {
        if (!failureDialog || !failureForm || !failureChoice || !failureNote || typeof failureDialog.showModal !== 'function') {
            setStatus('Trình duyệt không hỗ trợ hộp thoại ghi nhận lý do giao thất bại.');
            return Promise.resolve(null);
        }

        failureForm.reset();
        failureNote.setCustomValidity('');
        failureChoice.dispatchEvent(new Event('change'));
        return new Promise((resolve) => {
            failureDialog.returnValue = '';
            const handleSubmit = (event) => {
                event.preventDefault();
                let reason = failureChoice.value;
                if (reason === 'other') {
                    const note = failureNote.value.trim();
                    reason = note === '' ? '' : 'Lý do khác: ' + note;
                }
                if (reason === '' || new TextEncoder().encode(reason).length > 255) {
                    failureNote.setCustomValidity(reason === ''
                        ? 'Vui lòng nhập ghi chú cho lý do khác.'
                        : 'Lý do vượt quá giới hạn lưu trữ 255 byte.');
                    failureNote.reportValidity();
                    return;
                }
                failureDialog.close(reason);
            };
            failureDialog.addEventListener('close', () => {
                const reason = failureDialog.returnValue || null;
                failureForm.removeEventListener('submit', handleSubmit);
                failureForm.reset();
                failureNote.setCustomValidity('');
                failureChoice.dispatchEvent(new Event('change'));
                resolve(reason);
            }, { once: true });
            failureForm.addEventListener('submit', handleSubmit);
            failureDialog.showModal();
            failureChoice.focus();
        });
    }

    async function updateOrderResult(orderId, status) {
        if (!Number.isInteger(orderId) || orderId < 1) {
            setStatus('Đơn hàng không có mã hợp lệ để cập nhật.');
            return;
        }
        if (isUpdatingResult || isFailureDialogOpen) {
            return;
        }

        let failureReason = '';
        if (status === 'giao_that_bai') {
            isFailureDialogOpen = true;
            try {
                failureReason = await requestFailureReason() || '';
            } finally {
                isFailureDialogOpen = false;
            }
            if (failureReason === '') {
                return;
            }
        } else {
            const order = allOrders.find((item) => Number(item.id) === orderId);
            const cod = Number(order?.tien_thu_ho || 0);
            if (cod > 0 && !window.confirm('Xác nhận đã thu COD ' + formatCurrency(cod) + ' cho đơn này?')) {
                return;
            }
        }

        isUpdatingResult = true;
        setOrderActionButtonsDisabled(true);
        deliverButton.disabled = true;
        failButton.disabled = true;
        setStatus('Đang gửi kết quả giao hàng...');
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
            const rerouted = currentLocation ? await requestReroute() : true;
            await drawRoute();
            if (orders[0] && isMappableOrder(orders[0])) {
                map.flyTo([Number(orders[0].vi_do), Number(orders[0].kinh_do)], 16, { duration: 0.8 });
            }
            setStatus(status === 'giao_thanh_cong'
                ? (rerouted
                    ? 'Đã xác nhận GIAO THÀNH CÔNG và đồng bộ về trung tâm điều phối.'
                    : 'Đã giao thành công, nhưng chưa thể đồng bộ thứ tự tuyến mới.')
                : (rerouted
                    ? 'Đã ghi nhận GIAO THẤT BẠI và đồng bộ về trung tâm điều phối.'
                    : 'Đã ghi nhận giao thất bại, nhưng chưa thể đồng bộ thứ tự tuyến mới.'));
        } catch {
            setStatus('Không thể kết nối máy chủ để cập nhật kết quả giao hàng.');
        } finally {
            isUpdatingResult = false;
            renderOrders();
            setOrderActionButtonsDisabled(false);
        }
    }

    function setOrderActionButtonsDisabled(disabled) {
        orderList?.querySelectorAll('[data-order-action]').forEach((button) => {
            button.disabled = disabled;
        });
    }
})();
