/**
 * ban_do_dieu_phoi.js
 * Quản lý Trung tâm Điều phối & Bản đồ Giám sát Tuyến Giao Hàng (/dieu-phoi):
 * - Vẽ lộ trình thực tế bám theo mặt đường giao thông bằng OSRM Routing API (thay vì nối thẳng chim bay)
 * - Hiển thị trạng thái thực tế của từng điểm giao hàng: Đang chờ giao (1..n), Đã giao thành công (✓), Giao thất bại (✕)
 * - Hiển thị vị trí GPS hiện tại của tài xế nếu tài xế đã cập nhật vị trí trên ứng dụng
 * - Đồng bộ Bảng giám sát toàn bộ đơn hàng theo thời gian thực với bộ lọc theo Tuyến/Tài xế và Trạng thái
 * - Hỗ trợ tra cứu tọa độ Nominatim API & chấm chọn tọa độ trên bản đồ cho đơn sai địa chỉ
 */
(function () {
    'use strict';

    function loadDispatchConfig() {
        const configEl = document.getElementById('spx-dispatch-config');
        if (configEl && configEl.dataset && configEl.dataset.config) {
            try {
                return JSON.parse(configEl.dataset.config);
            } catch (e) {
                // fallback below
            }
        }
        return window.SPX_DISPATCH_CONFIG || {};
    }

    const config = loadDispatchConfig();
    const baseUrl = config.baseUrl || '/dinh-tuyen-spx/public';
    const nominatimBaseUrl = (config.nominatimBaseUrl || 'https://nominatim.openstreetmap.org').replace(/\/+$/, '');
    const osrmBaseUrl = (config.osrmBaseUrl || 'https://router.project-osrm.org').replace(/\/+$/, '');

    let buuCuc = config.buuCuc || {
        id: 1,
        ten_buu_cuc: 'Bưu cục SPX Nha Trang',
        ten_quan_huyen: 'Thành phố Nha Trang',
        ten_tinh: 'Tỉnh Khánh Hòa',
        vi_do: 12.2488,
        kinh_do: 109.1905,
        ban_kinh_phuc_vu_km: 25.0,
        suc_chua_moi_cum: 20
    };
    let danhSachPhuong = Array.isArray(config.danhSachPhuong) ? config.danhSachPhuong : [];
    let danhSachCum = Array.isArray(config.danhSachCum) ? config.danhSachCum : [];
    let danhSachDonLoi = Array.isArray(config.danhSachDonLoi) ? config.danhSachDonLoi : [];

    let map = null;
    let depotLayerGroup = null;
    let centroidLayerGroup = null;
    let orderMarkersLayerGroup = null;
    let route2OptLayerGroup = null;
    let routeBaselineLayerGroup = null;
    let errorMarkersLayerGroup = null;
    let tempPickMarker = null;

    // Lưu cache đường đi thực tế OSRM theo từng cụm để chuyển chế độ xem mượt mà
    const osrmGeometryCache = {};
    let renderGeneration = 0;

    let currentRouteMode = '2opt'; // '2opt' | 'baseline' | 'both'
    let pickingOrderId = null;

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        const fallback = document.getElementById('csrf-token-fallback');
        if (fallback && fallback.value) {
            return fallback.value;
        }
        return config.csrfToken || '';
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

    function showAlert(type, message) {
        const alertBox = document.getElementById('alert-dieu-phoi');
        const alertText = document.getElementById('alert-dieu-phoi-text');
        if (!alertBox || !alertText) {
            return;
        }
        alertBox.className = 'alert alert-' + type + ' alert-dismissible fade show shadow-sm mb-3';
        alertText.textContent = message;
    }

    function initMap() {
        const mapContainer = document.getElementById('map-dieu-phoi');
        if (!mapContainer || typeof L === 'undefined') {
            return;
        }

        const p0Lat = Number(buuCuc.vi_do) || 12.2488;
        const p0Lng = Number(buuCuc.kinh_do) || 109.1905;

        map = L.map('map-dieu-phoi', {
            center: [p0Lat, p0Lng],
            zoom: 13
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        depotLayerGroup = L.layerGroup().addTo(map);
        routeBaselineLayerGroup = L.layerGroup().addTo(map);
        route2OptLayerGroup = L.layerGroup().addTo(map);
        centroidLayerGroup = L.layerGroup().addTo(map);
        orderMarkersLayerGroup = L.layerGroup().addTo(map);
        errorMarkersLayerGroup = L.layerGroup().addTo(map);

        map.on('click', function (evt) {
            if (!pickingOrderId) {
                return;
            }
            const lat = Number(evt.latlng.lat).toFixed(7);
            const lng = Number(evt.latlng.lng).toFixed(7);
            applyPickedCoordinatesToRow(pickingOrderId, lat, lng);
        });

        renderAllMapLayers(true);
        renderAllClusterOrdersTable();
    }

    /**
     * Lấy đường đi thực tế theo mặt đường bộ từ OSRM cho danh sách tọa độ [[lat, lng], ...]
     */
    async function fetchOsrmStreetGeometry(cacheKey, latLngPoints) {
        if (osrmGeometryCache[cacheKey]) {
            return osrmGeometryCache[cacheKey];
        }
        if (!Array.isArray(latLngPoints) || latLngPoints.length < 2) {
            return null;
        }

        const coordString = latLngPoints
            .map(function (pt) {
                return Number(pt[1]).toFixed(6) + ',' + Number(pt[0]).toFixed(6);
            })
            .join(';');

        const controller = new AbortController();
        const timer = setTimeout(function () {
            controller.abort();
        }, 5000);

        try {
            const url = osrmBaseUrl + '/route/v1/driving/' + coordString + '?overview=full&geometries=geojson&steps=false';
            const response = await fetch(url, { signal: controller.signal });
            clearTimeout(timer);

            if (!response.ok) {
                return null;
            }
            const data = await response.json();
            const rawCoords = data && data.routes && data.routes[0] && data.routes[0].geometry
                ? data.routes[0].geometry.coordinates
                : null;

            if (Array.isArray(rawCoords) && rawCoords.length > 1) {
                const streetLatLngs = rawCoords.map(function (pair) {
                    return [Number(pair[1]), Number(pair[0])];
                });
                osrmGeometryCache[cacheKey] = streetLatLngs;
                return streetLatLngs;
            }
        } catch (e) {
            clearTimeout(timer);
        }

        return null;
    }

    function renderAllMapLayers(shouldFitBounds) {
        if (!map) {
            return;
        }

        const currentGen = ++renderGeneration;

        depotLayerGroup.clearLayers();
        centroidLayerGroup.clearLayers();
        orderMarkersLayerGroup.clearLayers();
        route2OptLayerGroup.clearLayers();
        routeBaselineLayerGroup.clearLayers();
        errorMarkersLayerGroup.clearLayers();

        const boundsPoints = [];
        const p0Lat = Number(buuCuc.vi_do) || 12.2488;
        const p0Lng = Number(buuCuc.kinh_do) || 109.1905;
        const radiusMeters = (Number(buuCuc.ban_kinh_phuc_vu_km) || 25) * 1000;

        // 1. Vẽ Marker Bưu cục P0 và vòng tròn bán kính phục vụ
        const depotIcon = L.divIcon({
            className: 'spx-depot-marker',
            html: '<div style="background:#ee4d2d;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.35);">P0</div>',
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        });

        const depotMarker = L.marker([p0Lat, p0Lng], { icon: depotIcon, zIndexOffset: 1000 });
        depotMarker.bindPopup(
            '<div style="min-width:210px;">' +
            '<strong style="color:#ee4d2d;">' + escapeHtml(buuCuc.ten_buu_cuc) + ' (P0)</strong><br>' +
            '<small>' + escapeHtml(buuCuc.dia_chi || '') + '</small><br>' +
            '<span class="badge bg-secondary mt-1">Phạm vi phục vụ: ' + Number(buuCuc.ban_kinh_phuc_vu_km || 25).toFixed(1) + ' km</span>' +
            '</div>'
        );
        depotMarker.addTo(depotLayerGroup);
        boundsPoints.push([p0Lat, p0Lng]);

        L.circle([p0Lat, p0Lng], {
            radius: radiusMeters,
            color: '#ee4d2d',
            weight: 1.5,
            dashArray: '6, 6',
            fillColor: '#ee4d2d',
            fillOpacity: 0.02,
            interactive: false
        }).addTo(depotLayerGroup);

        // 2. Vẽ từng tuyến giao hàng của Tài xế
        danhSachCum.forEach(function (cluster) {
            const color = cluster.mau_sac_cum || '#2563eb';
            const cLat = Number(cluster.vi_do_tam_cum);
            const cLng = Number(cluster.kinh_do_tam_cum);
            const depotStartLat = Number(cluster.vi_do_xuat_phat || p0Lat);
            const depotStartLng = Number(cluster.kinh_do_xuat_phat || p0Lng);

            // Nếu tài xế đã cập nhật vị trí GPS thực tế thì hiển thị xe tải của tài xế trên bản đồ Điều phối
            const hasDriverGps = cluster.tai_xe_vi_do !== null && cluster.tai_xe_vi_do !== undefined
                && cluster.tai_xe_kinh_do !== null && cluster.tai_xe_kinh_do !== undefined
                && !Number.isNaN(Number(cluster.tai_xe_vi_do)) && !Number.isNaN(Number(cluster.tai_xe_kinh_do));

            if (hasDriverGps) {
                const dLat = Number(cluster.tai_xe_vi_do);
                const dLng = Number(cluster.tai_xe_kinh_do);
                const truckIcon = L.divIcon({
                    className: 'spx-driver-live-marker',
                    html: '<div style="background:' + escapeHtml(color) + ';color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.4);">🚚</div>',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                });
                L.marker([dLat, dLng], { icon: truckIcon, zIndexOffset: 950 })
                    .bindPopup(
                        '<div>' +
                        '<strong>Vị trí tài xế: ' + escapeHtml(cluster.ten_tai_xe) + '</strong><br>' +
                        'Tuyến: <code>' + escapeHtml(cluster.ma_cum_tuyen) + '</code><br>' +
                        'Tọa độ GPS: ' + dLat.toFixed(5) + ', ' + dLng.toFixed(5) +
                        '</div>'
                    )
                    .addTo(centroidLayerGroup);
            } else if (!Number.isNaN(cLat) && !Number.isNaN(cLng)) {
                const centroidIcon = L.divIcon({
                    className: 'spx-centroid-marker',
                    html: '<div style="background:' + escapeHtml(color) + ';color:#fff;width:26px;height:26px;border-radius:6px;transform:rotate(45deg);display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,0.3);"><span style="transform:rotate(-45deg);font-size:12px;font-weight:bold;">★</span></div>',
                    iconSize: [26, 26],
                    iconAnchor: [13, 13]
                });

                const centroidMarker = L.marker([cLat, cLng], { icon: centroidIcon, zIndexOffset: 750 });
                centroidMarker.bindPopup(
                    '<div>' +
                    '<strong>Tâm khu vực giao hàng</strong><br>' +
                    'Tài xế: <strong>' + escapeHtml(cluster.ten_tai_xe) + '</strong><br>' +
                    'Mã tuyến: <code>' + escapeHtml(cluster.ma_cum_tuyen) + '</code><br>' +
                    'Tiến độ: Đã giao ' + Number(cluster.so_don_da_giao || 0) + '/' + Number(cluster.so_don_hang) + ' đơn<br>' +
                    'Quãng đường tối ưu: <strong>' + Number(cluster.km_toi_uu).toFixed(2) + ' km</strong> (Tiết kiệm ' + Number(cluster.ty_le_tiet_kiem_phan_tram).toFixed(1) + '%)' +
                    '</div>'
                );
                centroidMarker.addTo(centroidLayerGroup);
            }

            // Danh sách đơn trong cụm
            const orders2Opt = Array.isArray(cluster.don_hang_toi_uu) ? cluster.don_hang_toi_uu : [];
            const routeStartLat = hasDriverGps ? Number(cluster.tai_xe_vi_do) : depotStartLat;
            const routeStartLng = hasDriverGps ? Number(cluster.tai_xe_kinh_do) : depotStartLng;
            const activeCoords2Opt = [[routeStartLat, routeStartLng]];

            let pendingStopCounter = 0;

            orders2Opt.forEach(function (order, index) {
                const oLat = Number(order.vi_do);
                const oLng = Number(order.kinh_do);
                if (Number.isNaN(oLat) || Number.isNaN(oLng)) {
                    return;
                }
                boundsPoints.push([oLat, oLng]);

                const status = order.trang_thai || 'da_phan_cong';
                const isPending = status === 'da_phan_cong';
                const isDelivered = status === 'giao_thanh_cong';
                const isFailed = status === 'giao_that_bai';

                let seqLabel = String(order.thu_tu_giao || (index + 1));
                let markerBg = color;
                let statusBadgeHtml = '<span class="badge bg-primary">Đang chờ giao (Điểm #' + escapeHtml(seqLabel) + ')</span>';

                if (isPending) {
                    pendingStopCounter++;
                    seqLabel = String(pendingStopCounter);
                    activeCoords2Opt.push([oLat, oLng]);
                } else if (isDelivered) {
                    seqLabel = '✓';
                    markerBg = '#16a34a';
                    statusBadgeHtml = '<span class="badge bg-success">Đã giao thành công</span>';
                } else if (isFailed) {
                    seqLabel = '✕';
                    markerBg = '#64748b';
                    statusBadgeHtml = '<span class="badge bg-danger">Giao thất bại: ' + escapeHtml(order.ly_do_that_bai || '') + '</span>';
                }

                const orderIcon = L.divIcon({
                    className: 'spx-order-marker',
                    html: '<div style="background:' + escapeHtml(markerBg) + ';color:#fff;width:25px;height:25px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.35);">' + escapeHtml(seqLabel) + '</div>',
                    iconSize: [25, 25],
                    iconAnchor: [12, 12]
                });

                const marker = L.marker([oLat, oLng], {
                    icon: orderIcon,
                    zIndexOffset: isPending ? 600 : 300
                });
                marker.bindPopup(
                    '<div style="min-width:230px;">' +
                    '<div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<strong style="color:' + escapeHtml(color) + ';">' + escapeHtml(order.ma_van_don) + '</strong>' +
                    statusBadgeHtml +
                    '</div>' +
                    '<div><strong>Khách hàng:</strong> ' + escapeHtml(order.ten_khach_hang) + ' (' + escapeHtml(order.so_dien_thoai_khach || '') + ')</div>' +
                    '<div><strong>Địa chỉ:</strong> ' + escapeHtml(order.dia_chi_giao_hang) + '</div>' +
                    '<div><strong>Phường/Xã:</strong> ' + escapeHtml(order.ten_phuong || order.phuong_ten_goc || '') + '</div>' +
                    '<div><strong>Thu hộ COD:</strong> <span class="text-danger fw-bold">' + formatCurrency(order.tien_thu_ho) + '</span></div>' +
                    '<div><strong>Tài xế phụ trách:</strong> ' + escapeHtml(cluster.ten_tai_xe) + '</div>' +
                    '</div>'
                );
                marker.addTo(orderMarkersLayerGroup);
            });

            // Vẽ tuyến đường tối ưu theo mặt đường giao thông thực tế (OSRM)
            if (activeCoords2Opt.length > 1 && (currentRouteMode === '2opt' || currentRouteMode === 'both')) {
                const fallbackLine = L.polyline(activeCoords2Opt, {
                    color: color,
                    weight: 3.5,
                    opacity: 0.55,
                    dashArray: '4, 6'
                }).addTo(route2OptLayerGroup);

                const cacheKey = 'cluster_' + cluster.id + '_' + activeCoords2Opt.map(function (p) {
                    return p[0].toFixed(5) + ',' + p[1].toFixed(5);
                }).join('|');

                fetchOsrmStreetGeometry(cacheKey, activeCoords2Opt).then(function (streetLatLngs) {
                    if (currentGen !== renderGeneration || !streetLatLngs) {
                        return;
                    }
                    if (route2OptLayerGroup.hasLayer(fallbackLine)) {
                        route2OptLayerGroup.removeLayer(fallbackLine);
                    }
                    L.polyline(streetLatLngs, {
                        color: color,
                        weight: 4.5,
                        opacity: 0.9
                    }).addTo(route2OptLayerGroup);
                });
            }

            // Vẽ đường thứ tự đơn gốc chưa tối ưu (Baseline) khi người dùng bật đối chiếu
            const ordersBaseline = Array.isArray(cluster.don_hang_baseline) ? cluster.don_hang_baseline : [];
            const coordsBaseline = [[depotStartLat, depotStartLng]];
            ordersBaseline.forEach(function (order) {
                const oLat = Number(order.vi_do);
                const oLng = Number(order.kinh_do);
                if (!Number.isNaN(oLat) && !Number.isNaN(oLng)) {
                    coordsBaseline.push([oLat, oLng]);
                }
            });

            if (coordsBaseline.length > 1 && (currentRouteMode === 'baseline' || currentRouteMode === 'both')) {
                L.polyline(coordsBaseline, {
                    color: currentRouteMode === 'both' ? '#64748b' : color,
                    weight: currentRouteMode === 'both' ? 2.5 : 3.5,
                    dashArray: '8, 8',
                    opacity: currentRouteMode === 'both' ? 0.75 : 0.9
                }).addTo(routeBaselineLayerGroup);
            }
        });

        // 3. Vẽ các đơn lỗi tọa độ trên bản đồ
        danhSachDonLoi.forEach(function (errOrder) {
            if (errOrder.vi_do === null || errOrder.kinh_do === null || errOrder.vi_do === '' || errOrder.kinh_do === '') {
                return;
            }
            const eLat = Number(errOrder.vi_do);
            const eLng = Number(errOrder.kinh_do);
            if (Number.isNaN(eLat) || Number.isNaN(eLng)) {
                return;
            }

            const errIcon = L.divIcon({
                className: 'spx-error-marker',
                html: '<div style="background:#dc2626;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;border:2px solid #fef08a;box-shadow:0 2px 6px rgba(0,0,0,0.4);">!</div>',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });

            const errMarker = L.marker([eLat, eLng], { icon: errIcon, zIndexOffset: 900 });
            errMarker.bindPopup(
                '<div style="min-width:210px;">' +
                '<strong class="text-danger">Đơn cần xác minh địa chỉ: ' + escapeHtml(errOrder.ma_van_don) + '</strong><br>' +
                '<strong>Khách hàng:</strong> ' + escapeHtml(errOrder.ten_khach_hang || '') + '<br>' +
                '<strong>Địa chỉ:</strong> ' + escapeHtml(errOrder.dia_chi_giao_hang) + '<br>' +
                '<strong>Tọa độ hiện tại:</strong> ' + eLat.toFixed(5) + ', ' + eLng.toFixed(5) +
                '</div>'
            );
            errMarker.addTo(errorMarkersLayerGroup);
        });

        if (shouldFitBounds && boundsPoints.length > 1) {
            map.fitBounds(boundsPoints, { padding: [35, 35], maxZoom: 15 });
        }
    }

    /**
     * Hiển thị bảng giám sát chi tiết tất cả các đơn hàng trong các tuyến giao (Đồng bộ trạng thái từ tài xế)
     */
    function renderAllClusterOrdersTable() {
        const tbody = document.getElementById('tbody-tat-ca-don-cum');
        if (!tbody) {
            return;
        }

        const filterClusterEl = document.getElementById('filter-cluster-orders');
        const filterStatusEl = document.getElementById('filter-status-orders');
        const selectedClusterId = filterClusterEl ? filterClusterEl.value : 'all';
        const selectedStatus = filterStatusEl ? filterStatusEl.value : 'all';

        const flatOrders = [];
        danhSachCum.forEach(function (cum) {
            if (selectedClusterId !== 'all' && Number(cum.id) !== Number(selectedClusterId)) {
                return;
            }
            const list = Array.isArray(cum.don_hang_toi_uu) ? cum.don_hang_toi_uu : [];
            list.forEach(function (o) {
                const st = o.trang_thai || 'da_phan_cong';
                if (selectedStatus !== 'all' && st !== selectedStatus) {
                    return;
                }
                flatOrders.push({
                    order: o,
                    cluster: cum
                });
            });
        });

        if (flatOrders.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Không có đơn hàng nào khớp với bộ lọc hiện tại.</td></tr>';
            return;
        }

        tbody.innerHTML = flatOrders.map(function (entry) {
            const o = entry.order;
            const cum = entry.cluster;
            const color = escapeHtml(cum.mau_sac_cum || '#2563eb');
            const st = o.trang_thai || 'da_phan_cong';

            let statusHtml = '<span class="badge bg-primary-subtle text-primary border"><i class="bi bi-clock-history me-1"></i>Đang chờ giao</span>';
            if (st === 'giao_thanh_cong') {
                const timeNote = o.thoi_gian_giao ? ('<div class="text-muted mt-1" style="font-size:0.7rem;">Lúc: ' + escapeHtml(o.thoi_gian_giao) + '</div>') : '';
                statusHtml = '<span class="badge bg-success-subtle text-success border"><i class="bi bi-check-circle-fill me-1"></i>Đã giao thành công</span>' + timeNote;
            } else if (st === 'giao_that_bai') {
                const reasonNote = o.ly_do_that_bai ? ('<div class="text-danger mt-1" style="font-size:0.72rem;">Lý do: ' + escapeHtml(o.ly_do_that_bai) + '</div>') : '';
                statusHtml = '<span class="badge bg-danger-subtle text-danger border"><i class="bi bi-x-circle-fill me-1"></i>Giao thất bại</span>' + reasonNote;
            }

            return (
                '<tr data-order-lat="' + escapeHtml(o.vi_do) + '" data-order-lng="' + escapeHtml(o.kinh_do) + '" style="cursor:pointer;" class="row-dispatch-order-item">' +
                '<td class="text-center"><span class="badge rounded-pill" style="background:' + color + ';">#' + escapeHtml(o.thu_tu_giao || '-') + '</span></td>' +
                '<td class="fw-bold text-nowrap">' + escapeHtml(o.ma_van_don) + '</td>' +
                '<td><div class="fw-semibold text-dark">' + escapeHtml(o.ten_khach_hang) + '</div><div class="text-muted">' + escapeHtml(o.so_dien_thoai_khach || '') + '</div></td>' +
                '<td><div>' + escapeHtml(o.dia_chi_giao_hang) + '</div><div class="text-muted" style="font-size:0.72rem;">' + escapeHtml(o.ten_phuong || o.phuong_ten_goc || '') + '</div></td>' +
                '<td class="text-end fw-semibold text-danger">' + formatCurrency(o.tien_thu_ho) + '</td>' +
                '<td><div class="d-flex align-items-center gap-1"><span class="d-inline-block rounded-circle" style="width:10px;height:10px;background:' + color + ';"></span><span class="fw-semibold">' + escapeHtml(cum.ten_tai_xe) + '</span></div><div class="text-muted" style="font-size:0.7rem;">' + escapeHtml(cum.ma_cum_tuyen) + '</div></td>' +
                '<td>' + statusHtml + '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function setRouteMode(mode) {
        currentRouteMode = mode;
        const btnBaseline = document.getElementById('btn-mode-baseline');
        const btn2Opt = document.getElementById('btn-mode-2opt');
        const btnBoth = document.getElementById('btn-mode-both');

        [btnBaseline, btn2Opt, btnBoth].forEach(function (btn) {
            if (!btn) {
                return;
            }
            btn.classList.remove('active', 'btn-primary');
            if (!btn.classList.contains('btn-outline-secondary') && !btn.classList.contains('btn-outline-dark')) {
                btn.classList.add('btn-outline-secondary');
            }
        });

        const activeBtn = mode === 'baseline' ? btnBaseline : (mode === 'both' ? btnBoth : btn2Opt);
        if (activeBtn) {
            activeBtn.classList.remove('btn-outline-secondary', 'btn-outline-dark');
            activeBtn.classList.add('btn-primary', 'active');
        }

        renderAllMapLayers(false);
    }

    function focusClusterOnMap(clusterId) {
        if (!map) {
            return;
        }
        const target = danhSachCum.find(function (c) {
            return Number(c.id) === Number(clusterId);
        });
        if (!target) {
            return;
        }

        const filterClusterEl = document.getElementById('filter-cluster-orders');
        if (filterClusterEl) {
            filterClusterEl.value = String(Number(clusterId));
            renderAllClusterOrdersTable();
        }

        const pts = [[Number(buuCuc.vi_do), Number(buuCuc.kinh_do)]];
        if (target.vi_do_tam_cum && target.kinh_do_tam_cum) {
            pts.push([Number(target.vi_do_tam_cum), Number(target.kinh_do_tam_cum)]);
        }
        if (Array.isArray(target.don_hang_toi_uu)) {
            target.don_hang_toi_uu.forEach(function (o) {
                if (o.vi_do && o.kinh_do) {
                    pts.push([Number(o.vi_do), Number(o.kinh_do)]);
                }
            });
        }
        if (pts.length > 1) {
            map.fitBounds(pts, { padding: [45, 45], maxZoom: 16 });
        }
    }

    function startPickCoordinateMode(orderId) {
        pickingOrderId = Number(orderId);
        const banner = document.getElementById('map-pick-banner');
        const bannerText = document.getElementById('map-pick-banner-text');
        if (banner && bannerText) {
            bannerText.textContent = 'Đang chọn tọa độ cho đơn ID #' + pickingOrderId + ': Hãy click trực tiếp lên vị trí mong muốn trên bản đồ...';
            banner.classList.remove('d-none');
        }
        const mapEl = document.getElementById('map-dieu-phoi');
        if (mapEl) {
            mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function cancelPickCoordinateMode() {
        pickingOrderId = null;
        const banner = document.getElementById('map-pick-banner');
        if (banner) {
            banner.classList.add('d-none');
        }
        if (tempPickMarker && map) {
            map.removeLayer(tempPickMarker);
            tempPickMarker = null;
        }
    }

    function applyPickedCoordinatesToRow(orderId, lat, lng) {
        const row = document.getElementById('row-don-loi-' + orderId);
        if (!row) {
            return;
        }
        const latInput = row.querySelector('.input-vi-do');
        const lngInput = row.querySelector('.input-kinh-do');
        if (latInput) {
            latInput.value = lat;
        }
        if (lngInput) {
            lngInput.value = lng;
        }

        if (map) {
            if (tempPickMarker) {
                map.removeLayer(tempPickMarker);
            }
            tempPickMarker = L.marker([Number(lat), Number(lng)]).addTo(map);
            tempPickMarker.bindPopup('Tọa độ vừa chọn cho đơn #' + orderId + ': ' + lat + ', ' + lng).openPopup();
        }

        cancelPickCoordinateMode();
    }

    async function geocodeAddressWithNominatim(orderId, buttonEl) {
        const row = document.getElementById('row-don-loi-' + orderId);
        if (!row) {
            return;
        }
        const addressInput = row.querySelector('.input-dia-chi');
        const wardSelect = row.querySelector('.select-phuong-don-loi');
        const latInput = row.querySelector('.input-vi-do');
        const lngInput = row.querySelector('.input-kinh-do');

        const rawAddress = addressInput ? addressInput.value.trim() : '';
        if (!rawAddress) {
            showAlert('warning', 'Vui lòng nhập địa chỉ giao hàng trước khi tìm tọa độ tự động.');
            return;
        }

        const originalHtml = buttonEl ? buttonEl.innerHTML : '';
        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang tìm...';
        }

        try {
            const controller = new AbortController();
            const timer = setTimeout(function () {
                controller.abort();
            }, 4000);

            const queryUrl = nominatimBaseUrl + '/search?format=json&limit=1&countrycodes=vn&q=' + encodeURIComponent(rawAddress);
            const response = await fetch(queryUrl, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            });
            clearTimeout(timer);

            let foundLat = null;
            let foundLng = null;

            if (response.ok) {
                const results = await response.json();
                if (Array.isArray(results) && results.length > 0 && results[0].lat && results[0].lon) {
                    foundLat = Number(results[0].lat).toFixed(7);
                    foundLng = Number(results[0].lon).toFixed(7);
                }
            }

            if (foundLat === null || foundLng === null) {
                if (wardSelect && wardSelect.selectedOptions.length > 0) {
                    const opt = wardSelect.selectedOptions[0];
                    const wLat = opt.getAttribute('data-lat');
                    const wLng = opt.getAttribute('data-lng');
                    if (wLat && wLng) {
                        foundLat = Number(wLat).toFixed(7);
                        foundLng = Number(wLng).toFixed(7);
                    }
                }
                if (foundLat === null || foundLng === null) {
                    foundLat = Number(buuCuc.vi_do).toFixed(7);
                    foundLng = Number(buuCuc.kinh_do).toFixed(7);
                }
                showAlert('info', 'Đã điền tọa độ gợi ý theo tâm Phường/Xã phục vụ (bạn có thể tinh chỉnh thêm trên bản đồ).');
            } else {
                showAlert('success', 'Đã tìm thấy tọa độ từ bản đồ OpenStreetMap: (' + foundLat + ', ' + foundLng + ').');
            }

            if (latInput) {
                latInput.value = foundLat;
            }
            if (lngInput) {
                lngInput.value = foundLng;
            }

            if (map) {
                if (tempPickMarker) {
                    map.removeLayer(tempPickMarker);
                }
                tempPickMarker = L.marker([Number(foundLat), Number(foundLng)]).addTo(map);
                tempPickMarker.bindPopup('Tọa độ gợi ý: ' + foundLat + ', ' + foundLng).openPopup();
                map.setView([Number(foundLat), Number(foundLng)], 15);
            }
        } catch (err) {
            if (wardSelect && wardSelect.selectedOptions.length > 0) {
                const opt = wardSelect.selectedOptions[0];
                const wLat = opt.getAttribute('data-lat');
                const wLng = opt.getAttribute('data-lng');
                if (wLat && wLng && latInput && lngInput) {
                    latInput.value = Number(wLat).toFixed(7);
                    lngInput.value = Number(wLng).toFixed(7);
                    showAlert('info', 'Đã gợi ý tọa độ theo tâm Phường/Xã đã chọn.');
                    return;
                }
            }
            showAlert('warning', 'Không thể kết nối dịch vụ tìm địa chỉ. Bạn có thể bấm nút "Chấm bản đồ" để chọn vị trí trực tiếp.');
        } finally {
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalHtml;
            }
        }
    }

    async function handleSyncAndCluster() {
        const btn = document.getElementById('btn-phan-cum-toi-uu');
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang đồng bộ đơn Shopee &amp; chia tuyến...';
        }

        try {
            const csrfToken = getCsrfToken();
            const response = await fetch(baseUrl + '/api/dieu-phoi/phan-cum-toi-uu', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ _csrf_token: csrfToken })
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Có lỗi xảy ra khi đồng bộ và chia tuyến đơn hàng.');
                return;
            }

            showAlert('success', res.thong_bao);
            if (res.du_lieu) {
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                danhSachDonLoi = Array.isArray(res.du_lieu.danh_sach_don_loi) ? res.du_lieu.danh_sach_don_loi : danhSachDonLoi;
                refreshTablesAndKpis();
                renderAllMapLayers(true);
                renderAllClusterOrdersTable();
            }
        } catch (error) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi đồng bộ và chia tuyến đơn hàng.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }

    async function handleSaveErrorOrder(orderId, buttonEl) {
        const row = document.getElementById('row-don-loi-' + orderId);
        if (!row) {
            return;
        }

        const wardSelect = row.querySelector('.select-phuong-don-loi');
        const latInput = row.querySelector('.input-vi-do');
        const lngInput = row.querySelector('.input-kinh-do');
        const addressInput = row.querySelector('.input-dia-chi');

        const phuongId = wardSelect ? Number(wardSelect.value) : 0;
        const viDo = latInput ? latInput.value.trim() : '';
        const kinhDo = lngInput ? lngInput.value.trim() : '';
        const diaChi = addressInput ? addressInput.value.trim() : '';

        if (!phuongId) {
            showAlert('warning', 'Vui lòng chọn Phường/Xã thuộc vùng phục vụ của Bưu cục.');
            return;
        }
        if (viDo === '' || kinhDo === '') {
            showAlert('warning', 'Vui lòng nhập tọa độ hoặc bấm nút "Tìm tọa độ" / "Chấm bản đồ".');
            return;
        }

        const originalHtml = buttonEl ? buttonEl.innerHTML : '';
        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang lưu...';
        }

        try {
            const csrfToken = getCsrfToken();
            const response = await fetch(baseUrl + '/api/dieu-phoi/sua-don-loi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    _csrf_token: csrfToken,
                    don_hang_id: Number(orderId),
                    phuong_id: phuongId,
                    vi_do: Number(viDo),
                    kinh_do: Number(kinhDo),
                    dia_chi: diaChi
                })
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Không thể cập nhật đơn hàng.');
                return;
            }

            showAlert('success', res.thong_bao);
            if (tempPickMarker && map) {
                map.removeLayer(tempPickMarker);
                tempPickMarker = null;
            }

            if (res.du_lieu) {
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                danhSachDonLoi = Array.isArray(res.du_lieu.danh_sach_don_loi) ? res.du_lieu.danh_sach_don_loi : [];
                refreshTablesAndKpis();
                renderAllMapLayers(false);
                renderAllClusterOrdersTable();
            }
        } catch (err) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi cập nhật đơn hàng.');
        } finally {
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalHtml;
            }
        }
    }

    function refreshTablesAndKpis() {
        let totalOrders = 0;
        let totalPending = 0;
        let totalDelivered = 0;
        let totalFailed = 0;
        let totalBaseline = 0;
        let totalToiUu = 0;

        danhSachCum.forEach(function (c) {
            const soDon = Number(c.so_don_hang || 0);
            const daGiao = Number(c.so_don_da_giao || 0);
            const thatBai = Number(c.so_don_that_bai || 0);
            const dangCho = c.so_don_dang_cho !== undefined ? Number(c.so_don_dang_cho) : Math.max(0, soDon - daGiao - thatBai);

            totalOrders += soDon;
            totalPending += dangCho;
            totalDelivered += daGiao;
            totalFailed += thatBai;
            totalBaseline += Number(c.km_baseline || 0);
            totalToiUu += Number(c.km_toi_uu || 0);
        });
        const savingPct = totalBaseline > 0 ? (((totalBaseline - totalToiUu) / totalBaseline) * 100) : 0;

        const kpiSoCum = document.getElementById('kpi-so-cum');
        const kpiSoDon = document.getElementById('kpi-so-don-phan-cum');
        const kpiTienDo = document.getElementById('kpi-tien-do-chi-tiet');
        const kpiQuangDuong = document.getElementById('kpi-quang-duong');
        const kpiTietKiem = document.getElementById('kpi-tiet-kiem');
        const kpiDonLoi = document.getElementById('kpi-so-don-loi');
        const badgeDonLoi = document.getElementById('badge-count-don-loi');

        if (kpiSoCum) {
            kpiSoCum.textContent = danhSachCum.length + ' tuyến hoạt động';
        }
        if (kpiSoDon) {
            kpiSoDon.textContent = totalOrders + ' đơn trong tuyến';
        }
        if (kpiTienDo) {
            kpiTienDo.innerHTML =
                '<span class="text-primary fw-semibold">Đang giao: ' + totalPending + '</span> &bull; ' +
                '<span class="text-success fw-semibold">Đã giao: ' + totalDelivered + '</span> &bull; ' +
                '<span class="text-danger fw-semibold">Thất bại: ' + totalFailed + '</span>';
        }
        if (kpiQuangDuong) {
            kpiQuangDuong.textContent = totalToiUu.toFixed(2) + ' km (Gốc: ' + totalBaseline.toFixed(2) + ' km)';
        }
        if (kpiTietKiem) {
            kpiTietKiem.textContent = 'Tiết kiệm quãng đường: ' + savingPct.toFixed(2) + '%';
        }
        if (kpiDonLoi) {
            kpiDonLoi.textContent = danhSachDonLoi.length + ' đơn';
        }
        if (badgeDonLoi) {
            badgeDonLoi.textContent = danhSachDonLoi.length + ' đơn cần xử lý';
        }

        // Cập nhật dropdown lọc tuyến
        const filterClusterEl = document.getElementById('filter-cluster-orders');
        if (filterClusterEl) {
            const currentVal = filterClusterEl.value || 'all';
            filterClusterEl.innerHTML = ['<option value="all">Tất cả các tuyến / tài xế</option>'].concat(
                danhSachCum.map(function (c) {
                    const sel = String(c.id) === String(currentVal) ? 'selected' : '';
                    return '<option value="' + Number(c.id) + '" ' + sel + '>' + escapeHtml(c.ten_tai_xe) + ' (' + escapeHtml(c.ma_cum_tuyen) + ')</option>';
                })
            ).join('');
        }

        const tbodyCum = document.getElementById('tbody-cum-tuyen');
        if (tbodyCum) {
            if (danhSachCum.length === 0) {
                tbodyCum.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Chưa có tuyến giao hàng nào.</td></tr>';
            } else {
                tbodyCum.innerHTML = danhSachCum.map(function (cum) {
                    const color = escapeHtml(cum.mau_sac_cum || '#2563eb');
                    const phuongNote = cum.ten_phuong_uu_tien ? ('Khu vực: <strong>' + escapeHtml(cum.ten_phuong_uu_tien) + '</strong> &bull; ') : '';
                    const soDon = Number(cum.so_don_hang || 0);
                    const daGiao = Number(cum.so_don_da_giao || 0);
                    const thatBai = Number(cum.so_don_that_bai || 0);
                    const dangCho = cum.so_don_dang_cho !== undefined ? Number(cum.so_don_dang_cho) : Math.max(0, soDon - daGiao - thatBai);
                    const isCompleted = cum.trang_thai === 'hoan_tat';

                    return (
                        '<tr data-cluster-id="' + Number(cum.id) + '">' +
                        '<td>' +
                        '<div class="d-flex align-items-center gap-2">' +
                        '<span class="d-inline-block rounded-circle flex-shrink-0" style="width:12px;height:12px;background-color:' + color + ';"></span>' +
                        '<div>' +
                        '<div class="fw-bold text-dark">' + escapeHtml(cum.ten_tai_xe || '') + '</div>' +
                        '<div class="text-muted" style="font-size:0.75rem;">' + phuongNote + 'Mã tuyến: ' + escapeHtml(cum.ma_cum_tuyen) + '</div>' +
                        '</div>' +
                        '</div>' +
                        '</td>' +
                        '<td class="text-center">' +
                        '<div class="mb-1"><span class="badge ' + (isCompleted ? 'bg-success' : 'bg-primary-subtle text-primary border') + '">' + soDon + ' đơn' + (isCompleted ? ' (Hoàn tất)' : '') + '</span></div>' +
                        '<div style="font-size:0.73rem;">' +
                        '<span class="text-success fw-semibold">&check; ' + daGiao + '</span> &bull; ' +
                        '<span class="text-primary fw-semibold">Chờ: ' + dangCho + '</span>' +
                        (thatBai > 0 ? (' &bull; <span class="text-danger fw-semibold">&times; ' + thatBai + '</span>') : '') +
                        '</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<div class="fw-bold text-dark">' + Number(cum.km_toi_uu).toFixed(2) + ' km</div>' +
                        '<div class="text-decoration-line-through text-muted" style="font-size:0.72rem;">Gốc: ' + Number(cum.km_baseline).toFixed(2) + ' km</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<span class="badge bg-success-subtle text-success border">-' + Number(cum.ty_le_tiet_kiem_phan_tram).toFixed(1) + '%</span>' +
                        '</td>' +
                        '<td class="text-center">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary btn-focus-cluster" data-cluster-id="' + Number(cum.id) + '" title="Phóng tới tuyến trên bản đồ"><i class="bi bi-geo-fill"></i></button>' +
                        '</td>' +
                        '</tr>'
                    );
                }).join('');
            }
        }

        const tbodyDonLoi = document.getElementById('tbody-don-loi');
        if (tbodyDonLoi) {
            if (danhSachDonLoi.length === 0) {
                tbodyDonLoi.innerHTML = '<tr><td colspan="7" class="text-center text-success py-4"><i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng hợp lệ đã được phân tuyến cho tài xế. Không có đơn tồn đọng.</td></tr>';
            } else {
                tbodyDonLoi.innerHTML = danhSachDonLoi.map(function (donLoi) {
                    const lyDo = donLoi.ly_do_chua_phan_cong || 'loi_toa_do';
                    let nhanLyDo = 'Sai lệch tọa độ (> 25km)';
                    let mauBadge = 'bg-danger';
                    if (lyDo === 'ngoai_vung_phuc_vu') {
                        nhanLyDo = 'Khác khu vực Phường/Xã';
                        mauBadge = 'bg-warning text-dark';
                    } else if (lyDo === 'qua_tai_tai_xe') {
                        nhanLyDo = 'Vượt định mức tải ca';
                        mauBadge = 'bg-secondary';
                    }

                    const wardOptions = ['<option value="">-- Chọn Phường/Xã phục vụ --</option>'].concat(
                        danhSachPhuong.map(function (ph) {
                            const selected = Number(donLoi.phuong_id || 0) === Number(ph.id) ? 'selected' : '';
                            const label = ph.ten_phuong + ' - ' + (buuCuc.ten_quan_huyen || '');
                            return '<option value="' + Number(ph.id) + '" data-lat="' + escapeHtml(ph.vi_do_tam) + '" data-lng="' + escapeHtml(ph.kinh_do_tam) + '" ' + selected + '>' + escapeHtml(label) + '</option>';
                        })
                    ).join('');

                    const latVal = (donLoi.vi_do !== null && donLoi.vi_do !== undefined) ? escapeHtml(donLoi.vi_do) : '';
                    const lngVal = (donLoi.kinh_do !== null && donLoi.kinh_do !== undefined) ? escapeHtml(donLoi.kinh_do) : '';

                    return (
                        '<tr id="row-don-loi-' + Number(donLoi.id) + '" data-order-id="' + Number(donLoi.id) + '">' +
                        '<td class="fw-bold text-nowrap">' + escapeHtml(donLoi.ma_van_don) +
                        '<div class="text-muted fw-normal" style="font-size:0.72rem;">Shopee: ' + escapeHtml(donLoi.ma_don_shopee || '-') + '</div></td>' +
                        '<td><div class="fw-semibold">' + escapeHtml(donLoi.ten_khach_hang) + '</div>' +
                        '<div class="text-muted">' + escapeHtml(donLoi.so_dien_thoai_khach || '') + '</div>' +
                        '<div class="text-danger fw-semibold">COD: ' + formatCurrency(donLoi.tien_thu_ho) + '</div></td>' +
                        '<td><input type="text" class="form-control form-control-sm input-dia-chi" value="' + escapeHtml(donLoi.dia_chi_giao_hang) + '">' +
                        '<div class="text-muted mt-1" style="font-size:0.72rem;">Phường khai báo ban đầu: <strong>' + escapeHtml(donLoi.phuong_ten_goc || 'Không rõ') + '</strong></div></td>' +
                        '<td><span class="badge ' + mauBadge + '">' + escapeHtml(nhanLyDo) + '</span></td>' +
                        '<td><select class="form-select form-select-sm select-phuong-don-loi">' + wardOptions + '</select></td>' +
                        '<td>' +
                        '<div class="input-group input-group-sm mb-1"><span class="input-group-text" style="width:42px;">Vĩ</span><input type="number" step="0.0000001" class="form-control input-vi-do" value="' + latVal + '"></div>' +
                        '<div class="input-group input-group-sm"><span class="input-group-text" style="width:42px;">Kinh</span><input type="number" step="0.0000001" class="form-control input-kinh-do" value="' + lngVal + '"></div>' +
                        '</td>' +
                        '<td class="text-center"><div class="d-grid gap-1">' +
                        '<div class="btn-group btn-group-sm" role="group">' +
                        '<button type="button" class="btn btn-outline-info text-dark btn-nominatim-geocode" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-search me-1"></i>Tìm tọa độ</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-pick-on-map" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-crosshair me-1"></i>Chấm bản đồ</button>' +
                        '</div>' +
                        '<button type="button" class="btn btn-sm btn-success fw-semibold btn-save-don-loi" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-check2-circle me-1"></i>Xác nhận &amp; Gán vào tuyến</button>' +
                        '</div></td>' +
                        '</tr>'
                    );
                }).join('');
            }
        }
    }

    function bindEvents() {
        const btnPhanCum = document.getElementById('btn-phan-cum-toi-uu');
        if (btnPhanCum) {
            btnPhanCum.addEventListener('click', handleSyncAndCluster);
        }

        const btnBaseline = document.getElementById('btn-mode-baseline');
        const btn2Opt = document.getElementById('btn-mode-2opt');
        const btnBoth = document.getElementById('btn-mode-both');

        if (btnBaseline) {
            btnBaseline.addEventListener('click', function () {
                setRouteMode('baseline');
            });
        }
        if (btn2Opt) {
            btn2Opt.addEventListener('click', function () {
                setRouteMode('2opt');
            });
        }
        if (btnBoth) {
            btnBoth.addEventListener('click', function () {
                setRouteMode('both');
            });
        }

        const btnFitAll = document.getElementById('btn-fit-all-clusters');
        if (btnFitAll) {
            btnFitAll.addEventListener('click', function () {
                const filterClusterEl = document.getElementById('filter-cluster-orders');
                if (filterClusterEl) {
                    filterClusterEl.value = 'all';
                    renderAllClusterOrdersTable();
                }
                renderAllMapLayers(true);
            });
        }

        const filterClusterEl = document.getElementById('filter-cluster-orders');
        const filterStatusEl = document.getElementById('filter-status-orders');
        if (filterClusterEl) {
            filterClusterEl.addEventListener('change', renderAllClusterOrdersTable);
        }
        if (filterStatusEl) {
            filterStatusEl.addEventListener('change', renderAllClusterOrdersTable);
        }

        const btnCancelPick = document.getElementById('btn-cancel-pick-map');
        if (btnCancelPick) {
            btnCancelPick.addEventListener('click', cancelPickCoordinateMode);
        }

        document.addEventListener('click', function (evt) {
            const focusBtn = evt.target.closest('.btn-focus-cluster');
            if (focusBtn) {
                focusClusterOnMap(focusBtn.getAttribute('data-cluster-id'));
                return;
            }

            const orderRow = evt.target.closest('.row-dispatch-order-item');
            if (orderRow && map) {
                const lat = Number(orderRow.getAttribute('data-order-lat'));
                const lng = Number(orderRow.getAttribute('data-order-lng'));
                if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                    map.setView([lat, lng], 16);
                }
                return;
            }

            const nominatimBtn = evt.target.closest('.btn-nominatim-geocode');
            if (nominatimBtn) {
                geocodeAddressWithNominatim(nominatimBtn.getAttribute('data-order-id'), nominatimBtn);
                return;
            }

            const pickBtn = evt.target.closest('.btn-pick-on-map');
            if (pickBtn) {
                startPickCoordinateMode(pickBtn.getAttribute('data-order-id'));
                return;
            }

            const saveBtn = evt.target.closest('.btn-save-don-loi');
            if (saveBtn) {
                handleSaveErrorOrder(saveBtn.getAttribute('data-order-id'), saveBtn);
            }
        });
    }

    function bootstrapDispatchPage() {
        initMap();
        bindEvents();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapDispatchPage);
    } else {
        bootstrapDispatchPage();
    }
})();
