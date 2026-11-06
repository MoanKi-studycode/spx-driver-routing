/**
 * ban_do_dieu_phoi.js
 * Quản lý Trung tâm Điều phối & Bản đồ Giám sát Tuyến Giao Hàng (/dieu-phoi):
 * - Vẽ lộ trình thực tế bám theo mặt đường giao thông bằng OSRM Routing API
 * - Bảo vệ lộ trình đang giao (Dispatch Lock) & Modal xác nhận Reset toàn bộ
 * - Chuyển cụm / Đổi tài xế thủ công trực tiếp từ Popup Marker trên bản đồ hoặc từ Tab Tất cả đơn hàng
 * - Nominatim Geocoding Preview với Draggable Marker (kéo-thả trên bản đồ để tinh chỉnh vị trí hẻm/đường)
 * - Lớp phủ Loading Spinner toàn màn hình và quản lý 3 Tab Bootstrap
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
    let danhSachTaiXe = Array.isArray(config.danhSachTaiXe) ? config.danhSachTaiXe : [];
    let danhSachCum = Array.isArray(config.danhSachCum) ? config.danhSachCum : [];
    let danhSachDonLoi = Array.isArray(config.danhSachDonLoi) ? config.danhSachDonLoi : [];
    let tatCaDonHang = Array.isArray(config.tatCaDonHang) ? config.tatCaDonHang : [];

    let map = null;
    let depotLayerGroup = null;
    let centroidLayerGroup = null;
    let orderMarkersLayerGroup = null;
    let route2OptLayerGroup = null;
    let routeBaselineLayerGroup = null;
    let errorMarkersLayerGroup = null;
    let tempPickMarker = null;

    let osrmGeometryCache = {};
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

    function showLoadingOverlay(title, text) {
        const overlay = document.getElementById('loading-overlay-dieu-phoi');
        const titleEl = document.getElementById('loading-overlay-title');
        const textEl = document.getElementById('loading-overlay-text');
        if (titleEl && title) {
            titleEl.textContent = title;
        }
        if (textEl && text) {
            textEl.textContent = text;
        }
        if (overlay) {
            overlay.classList.remove('d-none');
        }
    }

    function hideLoadingOverlay() {
        const overlay = document.getElementById('loading-overlay-dieu-phoi');
        if (overlay) {
            overlay.classList.add('d-none');
        }
    }

    function activateBootstrapTab(tabButtonId) {
        const tabBtn = document.getElementById(tabButtonId);
        if (!tabBtn) {
            return;
        }
        if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            const tabInstance = bootstrap.Tab.getOrCreateInstance(tabBtn);
            tabInstance.show();
        } else {
            tabBtn.click();
        }
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
            spawnDraggablePreviewMarker(pickingOrderId, lat, lng, 'Đã chấm trên bản đồ (Có thể kéo-thả để tinh chỉnh thêm)');
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

    /**
     * Tạo HTML chọn chuyển cụm nhanh bên trong Popup Marker của đơn hàng đang chờ giao
     */
    function buildPopupTransferClusterHtml(order, currentClusterId) {
        if ((order.trang_thai || 'da_phan_cong') !== 'da_phan_cong') {
            return '';
        }
        const otherClusters = danhSachCum.filter(function (c) {
            return Number(c.id) !== Number(currentClusterId);
        });
        if (otherClusters.length === 0) {
            return '';
        }

        const optionsHtml = otherClusters.map(function (c) {
            return '<option value="' + Number(c.id) + '">' +
                escapeHtml(c.ten_tai_xe) + ' (' + Number(c.so_don_hang || 0) + ' đơn)' +
                '</option>';
        }).join('');

        return (
            '<hr class="my-2">' +
            '<div class="small fw-semibold text-dark mb-1"><i class="bi bi-arrow-left-right text-primary me-1"></i>Đổi tuyến / Tài xế phụ trách:</div>' +
            '<div class="d-flex gap-1">' +
            '<select class="form-select form-select-sm select-popup-transfer-cluster" id="popup-select-cluster-' + Number(order.id) + '">' +
            optionsHtml +
            '</select>' +
            '<button type="button" class="btn btn-sm btn-primary text-nowrap btn-popup-transfer-cluster" data-order-id="' + Number(order.id) + '">' +
            'Chuyển' +
            '</button>' +
            '</div>'
        );
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

                const transferControlHtml = buildPopupTransferClusterHtml(order, cluster.id);

                marker.bindPopup(
                    '<div style="min-width:250px;">' +
                    '<div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<strong style="color:' + escapeHtml(color) + ';">' + escapeHtml(order.ma_van_don) + '</strong>' +
                    statusBadgeHtml +
                    '</div>' +
                    '<div><strong>Khách hàng:</strong> ' + escapeHtml(order.ten_khach_hang) + ' (' + escapeHtml(order.so_dien_thoai_khach || '') + ')</div>' +
                    '<div><strong>Địa chỉ:</strong> ' + escapeHtml(order.dia_chi_giao_hang) + '</div>' +
                    '<div><strong>Phường/Xã:</strong> ' + escapeHtml(order.ten_phuong || order.phuong_ten_goc || '') + '</div>' +
                    '<div><strong>Thu hộ COD:</strong> <span class="text-danger fw-bold">' + formatCurrency(order.tien_thu_ho) + '</span></div>' +
                    '<div><strong>Tài xế hiện tại:</strong> ' + escapeHtml(cluster.ten_tai_xe) + ' (Thứ tự #' + escapeHtml(order.thu_tu_giao || '-') + ')</div>' +
                    transferControlHtml +
                    '</div>'
                );
                marker.addTo(orderMarkersLayerGroup);
            });

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
                '<strong class="text-danger">Đơn lỗi tọa độ: ' + escapeHtml(errOrder.ma_van_don) + '</strong><br>' +
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
     * Hiển thị bảng Tab 3: Danh sách tất cả đơn hàng kèm bộ lọc trạng thái, tìm kiếm mã vận đơn và nút chuyển cụm thủ công
     */
    function renderAllClusterOrdersTable() {
        const tbody = document.getElementById('tbody-tat-ca-don-cum');
        if (!tbody) {
            return;
        }

        const filterClusterEl = document.getElementById('filter-cluster-orders');
        const filterStatusEl = document.getElementById('filter-status-orders');
        const searchInputEl = document.getElementById('search-order-input');

        const selectedClusterId = filterClusterEl ? filterClusterEl.value : 'all';
        const selectedStatus = filterStatusEl ? filterStatusEl.value : 'all';
        const keyword = searchInputEl ? searchInputEl.value.trim().toLowerCase() : '';

        const clusterMapById = {};
        danhSachCum.forEach(function (c) {
            clusterMapById[Number(c.id)] = c;
        });

        // Tập hợp đơn từ các cụm + đơn chưa phân công
        const flatEntries = [];
        const seenOrderIds = {};

        danhSachCum.forEach(function (cum) {
            const list = Array.isArray(cum.don_hang_toi_uu) ? cum.don_hang_toi_uu : [];
            list.forEach(function (o) {
                seenOrderIds[Number(o.id)] = true;
                flatEntries.push({
                    order: o,
                    cluster: cum
                });
            });
        });

        tatCaDonHang.forEach(function (o) {
            if (!seenOrderIds[Number(o.id)]) {
                seenOrderIds[Number(o.id)] = true;
                const cum = o.cum_tuyen_id ? (clusterMapById[Number(o.cum_tuyen_id)] || null) : null;
                flatEntries.push({
                    order: o,
                    cluster: cum
                });
            }
        });

        const filtered = flatEntries.filter(function (entry) {
            const o = entry.order;
            const cum = entry.cluster;
            const st = o.trang_thai || 'chua_phan_cong';

            if (selectedClusterId !== 'all') {
                if (!cum || Number(cum.id) !== Number(selectedClusterId)) {
                    return false;
                }
            }
            if (selectedStatus !== 'all' && st !== selectedStatus) {
                return false;
            }
            if (keyword !== '') {
                const haystack = [
                    o.ma_van_don || '',
                    o.ma_don_shopee || '',
                    o.ten_khach_hang || '',
                    o.so_dien_thoai_khach || '',
                    o.dia_chi_giao_hang || '',
                    o.ten_phuong || o.phuong_ten_goc || '',
                    cum ? (cum.ten_tai_xe || '') : ''
                ].join(' ').toLowerCase();
                if (!haystack.includes(keyword)) {
                    return false;
                }
            }
            return true;
        });

        const badgeTongDon = document.getElementById('tab-badge-tong-don');
        if (badgeTongDon) {
            badgeTongDon.textContent = String(flatEntries.length);
        }

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Không có đơn hàng nào khớp với bộ lọc/từ khóa hiện tại.</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(function (entry) {
            const o = entry.order;
            const cum = entry.cluster;
            const color = escapeHtml(cum ? (cum.mau_sac_cum || '#2563eb') : '#64748b');
            const st = o.trang_thai || 'chua_phan_cong';

            let statusHtml = '<span class="badge bg-secondary-subtle text-secondary border">Chưa phân công</span>';
            if (st === 'da_phan_cong') {
                statusHtml = '<span class="badge bg-primary-subtle text-primary border"><i class="bi bi-clock-history me-1"></i>Đang chờ giao</span>';
            } else if (st === 'giao_thanh_cong') {
                const timeNote = (o.thoi_gian_hoan_tat || o.thoi_gian_giao)
                    ? ('<div class="text-muted mt-1" style="font-size:0.7rem;">Lúc: ' + escapeHtml(o.thoi_gian_hoan_tat || o.thoi_gian_giao) + '</div>')
                    : '';
                statusHtml = '<span class="badge bg-success-subtle text-success border"><i class="bi bi-check-circle-fill me-1"></i>Đã giao thành công</span>' + timeNote;
            } else if (st === 'giao_that_bai') {
                const reasonNote = o.ly_do_that_bai
                    ? ('<div class="text-danger mt-1" style="font-size:0.72rem;">Lý do: ' + escapeHtml(o.ly_do_that_bai) + '</div>')
                    : '';
                statusHtml = '<span class="badge bg-danger-subtle text-danger border"><i class="bi bi-x-circle-fill me-1"></i>Giao thất bại</span>' + reasonNote;
            }

            let driverAndTransferHtml = '<span class="text-muted">Chưa gán tài xế</span>';
            if (cum) {
                driverAndTransferHtml =
                    '<div class="d-flex align-items-center gap-1 mb-1">' +
                    '<span class="d-inline-block rounded-circle" style="width:10px;height:10px;background:' + color + ';"></span>' +
                    '<span class="fw-semibold text-dark">' + escapeHtml(cum.ten_tai_xe) + '</span>' +
                    '</div>';

                if (st === 'da_phan_cong' && danhSachCum.length > 1) {
                    const otherOpts = danhSachCum
                        .filter(function (c) {
                            return Number(c.id) !== Number(cum.id);
                        })
                        .map(function (c) {
                            return '<option value="' + Number(c.id) + '">Chuyển sang: ' + escapeHtml(c.ten_tai_xe) + '</option>';
                        })
                        .join('');

                    driverAndTransferHtml +=
                        '<div class="d-flex gap-1 mt-1">' +
                        '<select class="form-select form-select-sm py-0 select-row-transfer-cluster" style="font-size:0.75rem;" data-order-id="' + Number(o.id) + '">' +
                        otherOpts +
                        '</select>' +
                        '<button type="button" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2 btn-row-transfer-cluster" data-order-id="' + Number(o.id) + '" title="Chuyển đơn này sang cụm tuyến đã chọn">' +
                        '<i class="bi bi-arrow-left-right"></i>' +
                        '</button>' +
                        '</div>';
                } else {
                    driverAndTransferHtml += '<div class="text-muted" style="font-size:0.7rem;">' + escapeHtml(cum.ma_cum_tuyen) + '</div>';
                }
            }

            const codDisplay = Number(o.tien_thu_ho || 0) > 0
                ? '<span class="text-danger fw-semibold">' + formatCurrency(o.tien_thu_ho) + '</span>'
                : '<span class="badge bg-light text-secondary border">0đ (Không thu)</span>';

            return (
                '<tr data-order-lat="' + escapeHtml(o.vi_do) + '" data-order-lng="' + escapeHtml(o.kinh_do) + '" class="row-dispatch-order-item">' +
                '<td class="text-center"><span class="badge rounded-pill" style="background:' + color + ';">#' + escapeHtml(o.thu_tu_giao || '-') + '</span></td>' +
                '<td class="fw-bold text-nowrap" style="cursor:pointer;">' + escapeHtml(o.ma_van_don) + '</td>' +
                '<td><div class="fw-semibold text-dark">' + escapeHtml(o.ten_khach_hang) + '</div><div class="text-muted">' + escapeHtml(o.so_dien_thoai_khach || '') + '</div></td>' +
                '<td><div>' + escapeHtml(o.dia_chi_giao_hang) + '</div><div class="text-muted" style="font-size:0.72rem;">' + escapeHtml(o.ten_phuong || o.phuong_ten_goc || '') + '</div></td>' +
                '<td class="text-end">' + codDisplay + '</td>' +
                '<td>' + statusHtml + '</td>' +
                '<td>' + driverAndTransferHtml + '</td>' +
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

    function focusClusterOnMap(clusterId, switchToOrdersTab) {
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

        if (switchToOrdersTab) {
            activateBootstrapTab('tab-btn-tat-ca-don');
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

    /**
     * Tạo Draggable Marker (Marker kéo-thả được) trên bản đồ để Điều phối viên xem trước và tinh chỉnh tọa độ (Mục 3.B.3)
     */
    function spawnDraggablePreviewMarker(orderId, lat, lng, labelNote) {
        pickingOrderId = Number(orderId);
        const row = document.getElementById('row-don-loi-' + pickingOrderId);
        const latNum = Number(lat);
        const lngNum = Number(lng);
        if (Number.isNaN(latNum) || Number.isNaN(lngNum)) {
            return;
        }

        const updateRowInputs = function (newLat, newLng) {
            if (!row) {
                return;
            }
            const latInput = row.querySelector('.input-vi-do');
            const lngInput = row.querySelector('.input-kinh-do');
            if (latInput) {
                latInput.value = Number(newLat).toFixed(7);
            }
            if (lngInput) {
                lngInput.value = Number(newLng).toFixed(7);
            }
        };

        updateRowInputs(latNum, lngNum);

        const banner = document.getElementById('map-pick-banner');
        const bannerText = document.getElementById('map-pick-banner-text');
        if (banner && bannerText) {
            bannerText.textContent = 'Đang xem trước tọa độ đơn #' + pickingOrderId + ': Bạn có thể KÉO-THẢ Marker màu vàng/cam trên bản đồ để chỉnh đúng hẻm/đường trước khi lưu!';
            banner.classList.remove('d-none');
        }

        if (!map) {
            return;
        }

        if (tempPickMarker) {
            map.removeLayer(tempPickMarker);
            tempPickMarker = null;
        }

        const previewIcon = L.divIcon({
            className: 'spx-draggable-preview-marker',
            html: '<div style="background:#f59e0b;color:#0f172a;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,0.45);cursor:grab;" title="Kéo-thả để tinh chỉnh tọa độ">✥</div>',
            iconSize: [34, 34],
            iconAnchor: [17, 17]
        });

        tempPickMarker = L.marker([latNum, lngNum], {
            draggable: true,
            icon: previewIcon,
            zIndexOffset: 1200
        }).addTo(map);

        const buildPreviewPopupHtml = function (cLat, cLng, note) {
            return (
                '<div style="min-width:220px;">' +
                '<strong class="text-warning-emphasis"><i class="bi bi-arrows-move me-1"></i>Marker Xem Trước (Kéo-thả được)</strong><br>' +
                '<div class="small text-secondary mb-1">' + escapeHtml(note || 'Hãy kéo Marker này tới đúng vị trí giao hàng.') + '</div>' +
                '<div><strong>Tọa độ:</strong> <code>' + Number(cLat).toFixed(6) + ', ' + Number(cLng).toFixed(6) + '</code></div>' +
                '</div>'
            );
        };

        tempPickMarker.bindPopup(buildPreviewPopupHtml(latNum, lngNum, labelNote)).openPopup();

        tempPickMarker.on('dragend', function (evt) {
            const pos = evt.target.getLatLng();
            if (!pos) {
                return;
            }
            updateRowInputs(pos.lat, pos.lng);
            tempPickMarker.setPopupContent(buildPreviewPopupHtml(pos.lat, pos.lng, 'Đã cập nhật tọa độ mới sau khi kéo-thả!'));
            tempPickMarker.openPopup();
            showAlert('info', 'Đã tinh chỉnh tọa độ mới cho đơn #' + pickingOrderId + ': (' + Number(pos.lat).toFixed(6) + ', ' + Number(pos.lng).toFixed(6) + '). Hãy bấm "Lưu tọa độ & Gán cụm".');
        });

        map.setView([latNum, lngNum], 16);
    }

    function startPickCoordinateMode(orderId) {
        pickingOrderId = Number(orderId);
        const row = document.getElementById('row-don-loi-' + pickingOrderId);
        let initLat = Number(buuCuc.vi_do) || 12.2488;
        let initLng = Number(buuCuc.kinh_do) || 109.1905;

        if (row) {
            const latInput = row.querySelector('.input-vi-do');
            const lngInput = row.querySelector('.input-kinh-do');
            const wardSelect = row.querySelector('.select-phuong-don-loi');
            if (latInput && lngInput && latInput.value && lngInput.value) {
                initLat = Number(latInput.value);
                initLng = Number(lngInput.value);
            } else if (wardSelect && wardSelect.selectedOptions.length > 0) {
                const opt = wardSelect.selectedOptions[0];
                if (opt.getAttribute('data-lat') && opt.getAttribute('data-lng')) {
                    initLat = Number(opt.getAttribute('data-lat'));
                    initLng = Number(opt.getAttribute('data-lng'));
                }
            }
        }

        spawnDraggablePreviewMarker(pickingOrderId, initLat, initLng, 'Click lên bản đồ hoặc kéo-thả Marker này tới đúng địa chỉ');
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

    async function geocodeAddressWithNominatim(orderId, buttonEl) {
        const row = document.getElementById('row-don-loi-' + orderId);
        if (!row) {
            return;
        }
        const addressInput = row.querySelector('.input-dia-chi');
        const wardSelect = row.querySelector('.select-phuong-don-loi');

        const rawAddress = addressInput ? addressInput.value.trim() : '';
        if (!rawAddress) {
            showAlert('warning', 'Vui lòng nhập địa chỉ giao hàng trước khi tra cứu Nominatim.');
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

            let noteMessage = '';
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
                noteMessage = 'Tọa độ gợi ý theo tâm Phường/Xã (Hãy kéo-thả Marker để chỉnh đúng hẻm/đường)';
                showAlert('info', 'Đã hiển thị Draggable Marker gợi ý trên bản đồ. Bạn có thể kéo-thả Marker để tinh chỉnh vị trí trước khi bấm "Lưu tọa độ & Gán cụm".');
            } else {
                noteMessage = 'Tìm thấy từ OpenStreetMap Nominatim (Kéo-thả Marker để tinh chỉnh nếu cần)';
                showAlert('success', 'Đã tìm thấy tọa độ từ Nominatim (' + foundLat + ', ' + foundLng + ') và hiển thị Draggable Marker xem trước trên bản đồ!');
            }

            spawnDraggablePreviewMarker(orderId, foundLat, foundLng, noteMessage);
            const mapEl = document.getElementById('map-dieu-phoi');
            if (mapEl) {
                mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } catch (err) {
            let fallbackLat = Number(buuCuc.vi_do).toFixed(7);
            let fallbackLng = Number(buuCuc.kinh_do).toFixed(7);
            if (wardSelect && wardSelect.selectedOptions.length > 0) {
                const opt = wardSelect.selectedOptions[0];
                const wLat = opt.getAttribute('data-lat');
                const wLng = opt.getAttribute('data-lng');
                if (wLat && wLng) {
                    fallbackLat = Number(wLat).toFixed(7);
                    fallbackLng = Number(wLng).toFixed(7);
                }
            }
            spawnDraggablePreviewMarker(orderId, fallbackLat, fallbackLng, 'Tọa độ gợi ý (Kéo-thả Marker để tinh chỉnh)');
            showAlert('info', 'Đã tạo Draggable Marker trên bản đồ. Hãy kéo-thả tới vị trí mong muốn rồi bấm "Lưu tọa độ & Gán cụm".');
        } finally {
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalHtml;
            }
        }
    }

    async function handleSyncAndCluster(resetAll) {
        const isReset = Boolean(resetAll);
        const btn = document.getElementById('btn-phan-cum-toi-uu');
        const originalHtml = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
        }

        if (isReset) {
            showLoadingOverlay(
                'Đang Reset & Phân Cụm Lại Toàn Bộ...',
                'Hệ thống đang làm mới các cụm tuyến và chạy lại thuật toán Balanced K-Means + Nearest Neighbor + 2-Opt từ Bưu cục P0...'
            );
        } else {
            showLoadingOverlay(
                'Đang Đồng Bộ Shopee & Chia Tuyến (Dispatch Lock)...',
                'Đang giữ nguyên lộ trình các Shipper đang đi giao và phân công các đơn chờ mới vào cụm tuyến...'
            );
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
                body: JSON.stringify({
                    _csrf_token: csrfToken,
                    reset_all: isReset ? 1 : 0
                })
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Có lỗi xảy ra khi đồng bộ và chia tuyến đơn hàng.');
                return;
            }

            showAlert('success', res.thong_bao);
            if (res.du_lieu) {
                osrmGeometryCache = {};
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                danhSachDonLoi = Array.isArray(res.du_lieu.danh_sach_don_loi) ? res.du_lieu.danh_sach_don_loi : danhSachDonLoi;
                tatCaDonHang = Array.isArray(res.du_lieu.tat_ca_don_hang) ? res.du_lieu.tat_ca_don_hang : tatCaDonHang;
                refreshTablesAndKpis();
                renderAllMapLayers(true);
                renderAllClusterOrdersTable();
            }
        } catch (error) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi đồng bộ và chia tuyến đơn hàng.');
        } finally {
            hideLoadingOverlay();
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }

    /**
     * Xử lý Chuyển cụm / Đổi tài xế thủ công cho 1 đơn hàng (Mục 3.B.2)
     */
    async function handleManualTransferCluster(orderId, targetClusterId, buttonEl) {
        if (!orderId || !targetClusterId) {
            showAlert('warning', 'Vui lòng chọn tuyến / tài xế đích để chuyển đơn hàng.');
            return;
        }

        const originalHtml = buttonEl ? buttonEl.innerHTML : '';
        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        showLoadingOverlay(
            'Đang Chuyển Cụm & Tối Ưu Lại Lộ Trình 2-Opt...',
            'Hệ thống đang chuyển đơn hàng sang tài xế mới và tính lại thứ tự giao (thu_tu_giao) + số km tối ưu (km_toi_uu)...'
        );

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
                    hanh_dong: 'chuyen_cum',
                    don_hang_id: Number(orderId),
                    cum_tuyen_dich_id: Number(targetClusterId)
                })
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Không thể chuyển cụm cho đơn hàng này.');
                return;
            }

            showAlert('success', res.thong_bao);
            if (res.du_lieu) {
                osrmGeometryCache = {};
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                danhSachDonLoi = Array.isArray(res.du_lieu.danh_sach_don_loi) ? res.du_lieu.danh_sach_don_loi : danhSachDonLoi;
                tatCaDonHang = Array.isArray(res.du_lieu.tat_ca_don_hang) ? res.du_lieu.tat_ca_don_hang : tatCaDonHang;
                refreshTablesAndKpis();
                renderAllMapLayers(false);
                renderAllClusterOrdersTable();
            }
        } catch (err) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi chuyển cụm đơn hàng.');
        } finally {
            hideLoadingOverlay();
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalHtml;
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
            showAlert('warning', 'Vui lòng nhập tọa độ hoặc bấm nút "Nominatim Preview" / "Chấm".');
            return;
        }

        const originalHtml = buttonEl ? buttonEl.innerHTML : '';
        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang lưu...';
        }

        showLoadingOverlay(
            'Đang Lưu Tọa Độ & Gán Vào Cụm Gần Nhất...',
            'Đang kiểm tra bán kính phục vụ và chạy lại thuật toán 2-Opt cho cụm tuyến nhận đơn...'
        );

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
            cancelPickCoordinateMode();

            if (res.du_lieu) {
                osrmGeometryCache = {};
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                danhSachDonLoi = Array.isArray(res.du_lieu.danh_sach_don_loi) ? res.du_lieu.danh_sach_don_loi : [];
                tatCaDonHang = Array.isArray(res.du_lieu.tat_ca_don_hang) ? res.du_lieu.tat_ca_don_hang : tatCaDonHang;
                refreshTablesAndKpis();
                renderAllMapLayers(false);
                renderAllClusterOrdersTable();
            }
        } catch (err) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi cập nhật đơn hàng.');
        } finally {
            hideLoadingOverlay();
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
        let totalCodCum = 0;
        let totalCodDaThu = 0;

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
            totalCodCum += Number(c.tong_cod_cum || 0);
            totalCodDaThu += Number(c.tong_cod_da_thu || 0);
        });
        const savingPct = totalBaseline > 0 ? (((totalBaseline - totalToiUu) / totalBaseline) * 100) : 0;

        const kpiSoCum = document.getElementById('kpi-so-cum');
        const kpiCodCum = document.getElementById('kpi-cod-cum');
        const kpiSoDon = document.getElementById('kpi-so-don-phan-cum');
        const kpiTienDo = document.getElementById('kpi-tien-do-chi-tiet');
        const kpiQuangDuong = document.getElementById('kpi-quang-duong');
        const kpiTietKiem = document.getElementById('kpi-tiet-kiem');
        const kpiDonLoi = document.getElementById('kpi-so-don-loi');
        const badgeDonLoi = document.getElementById('badge-count-don-loi');
        const tabBadgeSoCum = document.getElementById('tab-badge-so-cum');

        if (kpiSoCum) {
            kpiSoCum.textContent = danhSachCum.length + ' cụm / ' + (danhSachTaiXe.length || danhSachCum.length) + ' tài xế';
        }
        if (kpiCodCum) {
            kpiCodCum.innerHTML = 'COD đã thu: <strong class="text-success">' + formatCurrency(totalCodDaThu) + '</strong> / ' + formatCurrency(totalCodCum);
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
            badgeDonLoi.textContent = String(danhSachDonLoi.length);
        }
        if (tabBadgeSoCum) {
            tabBadgeSoCum.textContent = String(danhSachCum.length);
        }

        const filterClusterEl = document.getElementById('filter-cluster-orders');
        if (filterClusterEl) {
            const currentVal = filterClusterEl.value || 'all';
            filterClusterEl.innerHTML = ['<option value="all">Tất cả các cụm tuyến / tài xế</option>'].concat(
                danhSachCum.map(function (c) {
                    const sel = String(c.id) === String(currentVal) ? 'selected' : '';
                    return '<option value="' + Number(c.id) + '" ' + sel + '>' + escapeHtml(c.ten_tai_xe) + ' (' + escapeHtml(c.ma_cum_tuyen) + ')</option>';
                })
            ).join('');
        }

        const tbodyCum = document.getElementById('tbody-cum-tuyen');
        if (tbodyCum) {
            if (danhSachCum.length === 0) {
                tbodyCum.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Chưa có cụm tuyến giao hàng nào.</td></tr>';
            } else {
                tbodyCum.innerHTML = danhSachCum.map(function (cum) {
                    const color = escapeHtml(cum.mau_sac_cum || '#2563eb');
                    const phuongNote = cum.ten_phuong_uu_tien ? ('Khu vực: <strong>' + escapeHtml(cum.ten_phuong_uu_tien) + '</strong> &bull; ') : '';
                    const soDon = Number(cum.so_don_hang || 0);
                    const daGiao = Number(cum.so_don_da_giao || 0);
                    const thatBai = Number(cum.so_don_that_bai || 0);
                    const dangCho = cum.so_don_dang_cho !== undefined ? Number(cum.so_don_dang_cho) : Math.max(0, soDon - daGiao - thatBai);
                    const isCompleted = cum.trang_thai === 'hoan_tat';
                    const codCum = Number(cum.tong_cod_cum || 0);
                    const codDaThu = Number(cum.tong_cod_da_thu || 0);

                    return (
                        '<tr data-cluster-id="' + Number(cum.id) + '">' +
                        '<td>' +
                        '<div class="d-flex align-items-center gap-2">' +
                        '<span class="d-inline-block rounded-circle flex-shrink-0" style="width:14px;height:14px;background-color:' + color + ';"></span>' +
                        '<div>' +
                        '<div class="fw-bold text-dark">' + escapeHtml(cum.ten_tai_xe || '') + '</div>' +
                        '<div class="text-muted" style="font-size:0.75rem;">' + phuongNote + 'Mã cụm: <code>' + escapeHtml(cum.ma_cum_tuyen) + '</code></div>' +
                        '</div>' +
                        '</div>' +
                        '</td>' +
                        '<td class="text-center">' +
                        '<div class="mb-1"><span class="badge ' + (isCompleted ? 'bg-success' : 'bg-primary-subtle text-primary border') + '">' + soDon + ' đơn' + (isCompleted ? ' (Hoàn tất)' : '') + '</span></div>' +
                        '<div style="font-size:0.75rem;">' +
                        '<span class="text-success fw-semibold">&check; Đã giao: ' + daGiao + '</span> &bull; ' +
                        '<span class="text-primary fw-semibold">Đang giao: ' + dangCho + '</span> &bull; ' +
                        '<span class="text-danger fw-semibold">&times; Thất bại: ' + thatBai + '</span>' +
                        '</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<div class="fw-bold text-success">Đã thu: ' + formatCurrency(codDaThu) + '</div>' +
                        '<div class="text-muted" style="font-size:0.75rem;">Tổng COD: ' + formatCurrency(codCum) + '</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<div class="fw-bold text-dark">' + Number(cum.km_toi_uu).toFixed(2) + ' km</div>' +
                        '<div class="text-muted" style="font-size:0.73rem;">Baseline: <span class="text-decoration-line-through">' + Number(cum.km_baseline).toFixed(2) + ' km</span> | NN: ' + Number(cum.km_nn || 0).toFixed(2) + ' km</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<span class="badge bg-success-subtle text-success border">-' + Number(cum.ty_le_tiet_kiem_phan_tram).toFixed(1) + '%</span>' +
                        '<div class="text-muted" style="font-size:0.7rem;">' + Number(cum.thoi_gian_chay_ms || 0).toFixed(2) + ' ms</div>' +
                        '</td>' +
                        '<td class="text-center">' +
                        '<div class="btn-group btn-group-sm">' +
                        '<button type="button" class="btn btn-outline-primary btn-focus-cluster" data-cluster-id="' + Number(cum.id) + '" title="Phóng tới tuyến trên bản đồ"><i class="bi bi-geo-fill me-1"></i>Bản đồ</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-view-cluster-orders" data-cluster-id="' + Number(cum.id) + '" title="Xem danh sách đơn của tuyến"><i class="bi bi-list-ul"></i></button>' +
                        '</div>' +
                        '</td>' +
                        '</tr>'
                    );
                }).join('');
            }
        }

        const tbodyDonLoi = document.getElementById('tbody-don-loi');
        if (tbodyDonLoi) {
            if (danhSachDonLoi.length === 0) {
                tbodyDonLoi.innerHTML = '<tr><td colspan="7" class="text-center text-success py-4"><i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng hợp lệ đã được phân tuyến cho tài xế. Không có đơn lỗi tồn đọng.</td></tr>';
            } else {
                tbodyDonLoi.innerHTML = danhSachDonLoi.map(function (donLoi) {
                    const lyDo = donLoi.ly_do_chua_phan_cong || 'loi_toa_do';
                    let nhanLyDo = 'Sai lệch tọa độ (> 25km)';
                    let mauBadge = 'bg-danger';
                    if (lyDo === 'ngoai_vung_phuc_vu') {
                        nhanLyDo = 'Ngoài vùng phục vụ';
                        mauBadge = 'bg-warning text-dark';
                    } else if (lyDo === 'qua_tai_tai_xe') {
                        nhanLyDo = 'Quá tải định mức ca';
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
                        '<div class="text-muted mt-1" style="font-size:0.72rem;">Phường khai báo gốc: <strong>' + escapeHtml(donLoi.phuong_ten_goc || 'Không rõ') + '</strong></div></td>' +
                        '<td><span class="badge ' + mauBadge + '">' + escapeHtml(nhanLyDo) + '</span></td>' +
                        '<td><select class="form-select form-select-sm select-phuong-don-loi">' + wardOptions + '</select></td>' +
                        '<td>' +
                        '<div class="input-group input-group-sm mb-1"><span class="input-group-text" style="width:42px;">Vĩ</span><input type="number" step="0.0000001" class="form-control input-vi-do" value="' + latVal + '"></div>' +
                        '<div class="input-group input-group-sm"><span class="input-group-text" style="width:42px;">Kinh</span><input type="number" step="0.0000001" class="form-control input-kinh-do" value="' + lngVal + '"></div>' +
                        '</td>' +
                        '<td class="text-center"><div class="d-grid gap-1">' +
                        '<div class="btn-group btn-group-sm" role="group">' +
                        '<button type="button" class="btn btn-outline-info text-dark btn-nominatim-geocode" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-search me-1"></i>Nominatim Preview</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-pick-on-map" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-crosshair me-1"></i>Chấm</button>' +
                        '</div>' +
                        '<button type="button" class="btn btn-sm btn-success fw-semibold btn-save-don-loi" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-check2-circle me-1"></i>Lưu tọa độ &amp; Gán cụm</button>' +
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
            btnPhanCum.addEventListener('click', function () {
                handleSyncAndCluster(false);
            });
        }

        const btnConfirmReset = document.getElementById('btn-confirm-reset-cluster');
        if (btnConfirmReset) {
            btnConfirmReset.addEventListener('click', function () {
                const modalEl = document.getElementById('modal-confirm-reset');
                if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) {
                        inst.hide();
                    }
                }
                handleSyncAndCluster(true);
            });
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
        const searchInputEl = document.getElementById('search-order-input');

        if (filterClusterEl) {
            filterClusterEl.addEventListener('change', renderAllClusterOrdersTable);
        }
        if (filterStatusEl) {
            filterStatusEl.addEventListener('change', renderAllClusterOrdersTable);
        }
        if (searchInputEl) {
            searchInputEl.addEventListener('input', renderAllClusterOrdersTable);
        }

        const btnCancelPick = document.getElementById('btn-cancel-pick-map');
        if (btnCancelPick) {
            btnCancelPick.addEventListener('click', cancelPickCoordinateMode);
        }

        const btnJumpRow = document.getElementById('btn-Jump-to-error-row');
        if (btnJumpRow) {
            btnJumpRow.addEventListener('click', function () {
                activateBootstrapTab('tab-btn-don-loi');
                if (pickingOrderId) {
                    const row = document.getElementById('row-don-loi-' + pickingOrderId);
                    if (row) {
                        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        }

        document.addEventListener('click', function (evt) {
            const focusBtn = evt.target.closest('.btn-focus-cluster');
            if (focusBtn) {
                focusClusterOnMap(focusBtn.getAttribute('data-cluster-id'), false);
                return;
            }

            const viewClusterOrdersBtn = evt.target.closest('.btn-view-cluster-orders');
            if (viewClusterOrdersBtn) {
                focusClusterOnMap(viewClusterOrdersBtn.getAttribute('data-cluster-id'), true);
                return;
            }

            const popupTransferBtn = evt.target.closest('.btn-popup-transfer-cluster');
            if (popupTransferBtn) {
                const ordId = popupTransferBtn.getAttribute('data-order-id');
                const selEl = document.getElementById('popup-select-cluster-' + ordId);
                const targetId = selEl ? selEl.value : '';
                handleManualTransferCluster(ordId, targetId, popupTransferBtn);
                return;
            }

            const rowTransferBtn = evt.target.closest('.btn-row-transfer-cluster');
            if (rowTransferBtn) {
                const ordId = rowTransferBtn.getAttribute('data-order-id');
                const row = rowTransferBtn.closest('tr');
                const selEl = row ? row.querySelector('.select-row-transfer-cluster') : null;
                const targetId = selEl ? selEl.value : '';
                handleManualTransferCluster(ordId, targetId, rowTransferBtn);
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
                return;
            }

            // Click vào mã vận đơn trên bảng để zoom tới marker trên bản đồ
            if (!evt.target.closest('select, button, input')) {
                const orderRow = evt.target.closest('.row-dispatch-order-item');
                if (orderRow && map) {
                    const lat = Number(orderRow.getAttribute('data-order-lat'));
                    const lng = Number(orderRow.getAttribute('data-order-lng'));
                    if (!Number.isNaN(lat) && !Number.isNaN(lng) && lat !== 0 && lng !== 0) {
                        map.setView([lat, lng], 16);
                    }
                }
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
