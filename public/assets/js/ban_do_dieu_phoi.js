/**
 * ban_do_dieu_phoi.js
 * Quản lý bản đồ Leaflet màn hình Điều phối (/dieu-phoi):
 * - Hiển thị Bưu cục SPX (P0) kèm vòng tròn bán kính phục vụ (ban_kinh_phuc_vu_km)
 * - Hiển thị Tâm cụm K-Means (Centroid), các điểm giao hàng theo màu cụm (1..n)
 * - Bật/tắt so sánh trực quan đường Baseline (Chưa tối ưu) vs đường 2-Opt (Đã tối ưu)
 * - Tích hợp OpenStreetMap Nominatim Geocoding API & click chọn tọa độ trên bản đồ để sửa đơn lỗi
 */
(function () {
    'use strict';

    const config = window.SPX_DISPATCH_CONFIG || {};
    const baseUrl = config.baseUrl || '/dinh-tuyen-spx/public';
    const nominatimBaseUrl = (config.nominatimBaseUrl || 'https://nominatim.openstreetmap.org').replace(/\/+$/, '');
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
    }

    function renderAllMapLayers(shouldFitBounds) {
        if (!map) {
            return;
        }

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
            '<span class="badge bg-secondary mt-1">Bán kính phục vụ: ' + Number(buuCuc.ban_kinh_phuc_vu_km || 25).toFixed(1) + ' km</span>' +
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
            fillOpacity: 0.03,
            interactive: false
        }).addTo(depotLayerGroup);

        // 2. Vẽ từng cụm K-Means: Tâm cụm (Centroid), điểm giao hàng (1..n), đường Baseline & đường 2-Opt
        danhSachCum.forEach(function (cluster) {
            const color = cluster.mau_sac_cum || '#2563eb';
            const cLat = Number(cluster.vi_do_tam_cum);
            const cLng = Number(cluster.kinh_do_tam_cum);
            const startLat = Number(cluster.vi_do_xuat_phat || p0Lat);
            const startLng = Number(cluster.kinh_do_xuat_phat || p0Lng);

            if (!Number.isNaN(cLat) && !Number.isNaN(cLng)) {
                const centroidIcon = L.divIcon({
                    className: 'spx-centroid-marker',
                    html: '<div style="background:' + escapeHtml(color) + ';color:#fff;width:30px;height:30px;border-radius:6px;transform:rotate(45deg);display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><span style="transform:rotate(-45deg);font-size:14px;font-weight:bold;">★</span></div>',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                });

                const centroidMarker = L.marker([cLat, cLng], { icon: centroidIcon, zIndexOffset: 800 });
                centroidMarker.bindPopup(
                    '<div>' +
                    '<strong>Tâm cụm K-Means (Centroid)</strong><br>' +
                    'Cụm: <code>' + escapeHtml(cluster.ma_cum_tuyen) + '</code><br>' +
                    'Tài xế: <strong>' + escapeHtml(cluster.ten_tai_xe) + '</strong><br>' +
                    'Tọa độ tâm: ' + cLat.toFixed(5) + ', ' + cLng.toFixed(5) + '<br>' +
                    'Số đơn: ' + Number(cluster.so_don_hang) + ' đơn<br>' +
                    'Baseline: ' + Number(cluster.km_baseline).toFixed(2) + ' km &rarr; 2-Opt: <strong>' + Number(cluster.km_toi_uu).toFixed(2) + ' km</strong> (-' + Number(cluster.ty_le_tiet_kiem_phan_tram).toFixed(1) + '%)' +
                    '</div>'
                );
                centroidMarker.addTo(centroidLayerGroup);
                boundsPoints.push([cLat, cLng]);
            }

            // Danh sách đơn theo thứ tự 2-Opt
            const orders2Opt = Array.isArray(cluster.don_hang_toi_uu) ? cluster.don_hang_toi_uu : [];
            const coords2Opt = [[startLat, startLng]];

            orders2Opt.forEach(function (order, index) {
                const oLat = Number(order.vi_do);
                const oLng = Number(order.kinh_do);
                if (Number.isNaN(oLat) || Number.isNaN(oLng)) {
                    return;
                }
                const seq = order.thu_tu_giao || (index + 1);
                coords2Opt.push([oLat, oLng]);
                boundsPoints.push([oLat, oLng]);

                const orderIcon = L.divIcon({
                    className: 'spx-order-marker',
                    html: '<div style="background:' + escapeHtml(color) + ';color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.35);">' + escapeHtml(seq) + '</div>',
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                });

                const marker = L.marker([oLat, oLng], { icon: orderIcon });
                marker.bindPopup(
                    '<div style="min-width:220px;">' +
                    '<div class="fw-bold" style="color:' + escapeHtml(color) + ';">Điểm dừng #' + escapeHtml(seq) + ' - ' + escapeHtml(order.ma_van_don) + '</div>' +
                    '<div><strong>Khách:</strong> ' + escapeHtml(order.ten_khach_hang) + ' (' + escapeHtml(order.so_dien_thoai_khach || '') + ')</div>' +
                    '<div><strong>Địa chỉ:</strong> ' + escapeHtml(order.dia_chi_giao_hang) + '</div>' +
                    '<div><strong>Phường:</strong> ' + escapeHtml(order.ten_phuong || order.phuong_ten_goc || '') + '</div>' +
                    '<div><strong>Thu hộ COD:</strong> <span class="text-danger fw-bold">' + formatCurrency(order.tien_thu_ho) + '</span></div>' +
                    '<div><strong>Tài xế:</strong> ' + escapeHtml(cluster.ten_tai_xe) + '</div>' +
                    '</div>'
                );
                marker.addTo(orderMarkersLayerGroup);
            });

            if (coords2Opt.length > 1 && (currentRouteMode === '2opt' || currentRouteMode === 'both')) {
                L.polyline(coords2Opt, {
                    color: color,
                    weight: 4,
                    opacity: 0.9
                }).addTo(route2OptLayerGroup);
            }

            // Danh sách đơn theo thứ tự Baseline (thu_tu_file ASC)
            const ordersBaseline = Array.isArray(cluster.don_hang_baseline) ? cluster.don_hang_baseline : [];
            const coordsBaseline = [[startLat, startLng]];
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

        // 3. Vẽ các đơn lỗi có tọa độ trên bản đồ (VD: đơn vượt quá bán kính phục vụ 25km)
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
                '<strong class="text-danger">Đơn ngoại lệ: ' + escapeHtml(errOrder.ma_van_don) + '</strong><br>' +
                '<strong>Lý do:</strong> ' + escapeHtml(errOrder.ly_do_chua_phan_cong) + '<br>' +
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

            // Nếu Nominatim không tìm ra địa chỉ chi tiết hoặc không có mạng, lấy tọa độ tâm Phường đã chọn (hoặc tâm Bưu cục P0) làm gợi ý dự phòng
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
                showAlert('success', 'Đã tìm thấy tọa độ từ OpenStreetMap Nominatim API: (' + foundLat + ', ' + foundLng + ').');
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
                tempPickMarker.bindPopup('Tọa độ gợi ý Nominatim: ' + foundLat + ', ' + foundLng).openPopup();
                map.setView([Number(foundLat), Number(foundLng)], 15);
            }
        } catch (err) {
            // Dự phòng theo tâm phường nếu Nominatim bị chặn hoặc quá thời gian
            if (wardSelect && wardSelect.selectedOptions.length > 0) {
                const opt = wardSelect.selectedOptions[0];
                const wLat = opt.getAttribute('data-lat');
                const wLng = opt.getAttribute('data-lng');
                if (wLat && wLng && latInput && lngInput) {
                    latInput.value = Number(wLat).toFixed(7);
                    lngInput.value = Number(wLng).toFixed(7);
                    showAlert('info', 'Nominatim API phản hồi chậm, hệ thống đã gợi ý tọa độ tâm Phường đã chọn.');
                    return;
                }
            }
            showAlert('warning', 'Không thể kết nối Nominatim API. Bạn có thể bấm nút "Chấm bản đồ" để chọn vị trí trực tiếp.');
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
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang đồng bộ &amp; chạy K-Means + 2-Opt...';
        }

        try {
            const response = await fetch(baseUrl + '/api/dieu-phoi/phan-cum-toi-uu', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({})
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Có lỗi xảy ra khi phân cụm đơn hàng.');
                return;
            }

            showAlert('success', res.thong_bao);
            if (res.du_lieu) {
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                danhSachDonLoi = Array.isArray(res.du_lieu.danh_sach_don_loi) ? res.du_lieu.danh_sach_don_loi : danhSachDonLoi;
                refreshTablesAndKpis();
                renderAllMapLayers(true);
            }
        } catch (error) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi chạy phân cụm K-Means.');
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
            showAlert('warning', 'Vui lòng nhập tọa độ hoặc bấm nút "Nominatim" / "Chấm bản đồ".');
            return;
        }

        const originalHtml = buttonEl ? buttonEl.innerHTML : '';
        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang lưu...';
        }

        try {
            const response = await fetch(baseUrl + '/api/dieu-phoi/sua-don-loi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    don_hang_id: Number(orderId),
                    phuong_id: phuongId,
                    vi_do: Number(viDo),
                    kinh_do: Number(kinhDo),
                    dia_chi: diaChi
                })
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Không thể cập nhật đơn hàng lỗi.');
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
            }
        } catch (err) {
            showAlert('danger', 'Lỗi kết nối máy chủ khi cập nhật đơn hàng lỗi.');
        } finally {
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalHtml;
            }
        }
    }

    function refreshTablesAndKpis() {
        // Cập nhật KPI
        let totalOrders = 0;
        let totalBaseline = 0;
        let totalToiUu = 0;
        danhSachCum.forEach(function (c) {
            totalOrders += Number(c.so_don_hang || 0);
            totalBaseline += Number(c.km_baseline || 0);
            totalToiUu += Number(c.km_toi_uu || 0);
        });
        const savingPct = totalBaseline > 0 ? (((totalBaseline - totalToiUu) / totalBaseline) * 100) : 0;

        const kpiSoCum = document.getElementById('kpi-so-cum');
        const kpiSoDon = document.getElementById('kpi-so-don-phan-cum');
        const kpiQuangDuong = document.getElementById('kpi-quang-duong');
        const kpiTietKiem = document.getElementById('kpi-tiet-kiem');
        const kpiDonLoi = document.getElementById('kpi-so-don-loi');
        const badgeDonLoi = document.getElementById('badge-count-don-loi');

        if (kpiSoCum) {
            kpiSoCum.textContent = danhSachCum.length + ' cụm';
        }
        if (kpiSoDon) {
            kpiSoDon.textContent = totalOrders + ' đơn';
        }
        if (kpiQuangDuong) {
            kpiQuangDuong.textContent = totalBaseline.toFixed(2) + ' km → ' + totalToiUu.toFixed(2) + ' km';
        }
        if (kpiTietKiem) {
            kpiTietKiem.textContent = 'Tiết kiệm: ' + savingPct.toFixed(2) + '%';
        }
        if (kpiDonLoi) {
            kpiDonLoi.textContent = danhSachDonLoi.length + ' đơn';
        }
        if (badgeDonLoi) {
            badgeDonLoi.textContent = danhSachDonLoi.length + ' đơn cần xử lý';
        }

        // Render lại bảng Cụm tuyến
        const tbodyCum = document.getElementById('tbody-cum-tuyen');
        if (tbodyCum) {
            if (danhSachCum.length === 0) {
                tbodyCum.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Chưa có cụm tuyến nào.</td></tr>';
            } else {
                const maxCap = Number(buuCuc.suc_chua_moi_cum || 20);
                tbodyCum.innerHTML = danhSachCum.map(function (cum) {
                    const color = escapeHtml(cum.mau_sac_cum || '#2563eb');
                    const phuongNote = cum.ten_phuong_uu_tien ? (' &bull; Ưu tiên: ' + escapeHtml(cum.ten_phuong_uu_tien)) : '';
                    return (
                        '<tr data-cluster-id="' + Number(cum.id) + '">' +
                        '<td>' +
                        '<div class="d-flex align-items-center gap-2">' +
                        '<span class="d-inline-block rounded-circle" style="width:12px;height:12px;background-color:' + color + ';"></span>' +
                        '<div>' +
                        '<div class="fw-bold text-dark">' + escapeHtml(cum.ten_tai_xe || '') + '</div>' +
                        '<div class="text-muted" style="font-size:0.75rem;">' + escapeHtml(cum.ma_cum_tuyen) + phuongNote + '</div>' +
                        '<div class="text-secondary" style="font-size:0.72rem;">Tâm cụm: (' + Number(cum.vi_do_tam_cum).toFixed(4) + ', ' + Number(cum.kinh_do_tam_cum).toFixed(4) + ')</div>' +
                        '</div>' +
                        '</div>' +
                        '</td>' +
                        '<td class="text-center">' +
                        '<span class="badge bg-primary-subtle text-primary border">' + Number(cum.so_don_hang) + ' / ' + maxCap + '</span>' +
                        '<div class="text-muted mt-1" style="font-size:0.72rem;">Đã giao: ' + Number(cum.so_don_da_giao || 0) + '</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<div class="text-decoration-line-through text-muted" style="font-size:0.75rem;">' + Number(cum.km_baseline).toFixed(2) + ' km</div>' +
                        '<div class="fw-bold text-dark">' + Number(cum.km_toi_uu).toFixed(2) + ' km</div>' +
                        '<div class="text-muted" style="font-size:0.7rem;">NN: ' + Number(cum.km_nn).toFixed(2) + ' km</div>' +
                        '</td>' +
                        '<td class="text-end">' +
                        '<span class="badge bg-success-subtle text-success border">-' + Number(cum.ty_le_tiet_kiem_phan_tram).toFixed(1) + '%</span>' +
                        '<div class="text-muted mt-1" style="font-size:0.7rem;">' + Number(cum.thoi_gian_chay_ms).toFixed(2) + ' ms</div>' +
                        '</td>' +
                        '<td class="text-center">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary btn-focus-cluster" data-cluster-id="' + Number(cum.id) + '" title="Phóng tới cụm trên bản đồ"><i class="bi bi-geo-fill"></i></button>' +
                        '</td>' +
                        '</tr>'
                    );
                }).join('');
            }
        }

        // Render lại bảng Đơn lỗi
        const tbodyDonLoi = document.getElementById('tbody-don-loi');
        if (tbodyDonLoi) {
            if (danhSachDonLoi.length === 0) {
                tbodyDonLoi.innerHTML = '<tr><td colspan="7" class="text-center text-success py-4"><i class="bi bi-check-circle-fill me-1"></i>Tất cả đơn hàng hợp lệ đã được phân vào cụm tuyến. Không có đơn ngoại lệ tồn đọng.</td></tr>';
            } else {
                tbodyDonLoi.innerHTML = danhSachDonLoi.map(function (donLoi) {
                    const lyDo = donLoi.ly_do_chua_phan_cong || 'loi_toa_do';
                    let nhanLyDo = 'Lỗi tọa độ (> bán kính / thiếu)';
                    let mauBadge = 'bg-danger';
                    if (lyDo === 'ngoai_vung_phuc_vu') {
                        nhanLyDo = 'Ngoài vùng phục vụ (Sai phường)';
                        mauBadge = 'bg-warning text-dark';
                    } else if (lyDo === 'qua_tai_tai_xe') {
                        nhanLyDo = 'Quá tải định mức tài xế';
                        mauBadge = 'bg-secondary';
                    }

                    const wardOptions = ['<option value="">-- Chọn Phường/Xã phục vụ --</option>'].concat(
                        danhSachPhuong.map(function (ph) {
                            const selected = Number(donLoi.phuong_id || 0) === Number(ph.id) ? 'selected' : '';
                            const label = ph.ten_phuong + ' - ' + (buuCuc.ten_quan_huyen || '') + ', ' + (buuCuc.ten_tinh || '') + ' [Mã: ' + ph.ma_hanh_chinh + ']';
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
                        '<div class="text-muted mt-1" style="font-size:0.72rem;">Phường gốc trong file: <strong>' + escapeHtml(donLoi.phuong_ten_goc || 'Không rõ') + '</strong></div></td>' +
                        '<td><span class="badge ' + mauBadge + '">' + escapeHtml(nhanLyDo) + '</span></td>' +
                        '<td><select class="form-select form-select-sm select-phuong-don-loi">' + wardOptions + '</select></td>' +
                        '<td>' +
                        '<div class="input-group input-group-sm mb-1"><span class="input-group-text" style="width:42px;">Vĩ</span><input type="number" step="0.0000001" class="form-control input-vi-do" value="' + latVal + '"></div>' +
                        '<div class="input-group input-group-sm"><span class="input-group-text" style="width:42px;">Kinh</span><input type="number" step="0.0000001" class="form-control input-kinh-do" value="' + lngVal + '"></div>' +
                        '</td>' +
                        '<td class="text-center"><div class="d-grid gap-1">' +
                        '<div class="btn-group btn-group-sm" role="group">' +
                        '<button type="button" class="btn btn-outline-info text-dark btn-nominatim-geocode" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-search me-1"></i>Nominatim</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-pick-on-map" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-crosshair me-1"></i>Chấm bản đồ</button>' +
                        '</div>' +
                        '<button type="button" class="btn btn-sm btn-success fw-semibold btn-save-don-loi" data-order-id="' + Number(donLoi.id) + '"><i class="bi bi-check2-circle me-1"></i>Cập nhật &amp; Gán vào cụm</button>' +
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
                renderAllMapLayers(true);
            });
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

    document.addEventListener('DOMContentLoaded', function () {
        initMap();
        bindEvents();
    });
})();
