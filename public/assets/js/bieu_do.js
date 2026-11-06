/**
 * bieu_do.js
 * Quản lý vẽ biểu đồ thống kê Chart.js trên màn hình /thong-ke (2 Tab):
 * - Tab 1 (Quản Trị Vận Hành Bưu Cục & Đối Soát COD):
 *   1. chart-trang-thai-don (Doughnut Chart): 4 trạng thái đơn hàng
 *   2. chart-tien-do-tai-xe (Stacked Bar Chart): Đã giao / Đang giao / Thất bại theo từng Shipper
 *   3. chart-doi-soat-cod (Grouped Bar Chart): Tổng COD cần thu vs COD thực tế đã thu theo từng Shipper
 * - Tab 2 (Đánh Giá & Thực Nghiệm Thuật Toán):
 *   4. chart-quang-duong (Bar Chart): Baseline vs Nearest Neighbor (NN) vs 2-Opt
 *   5. chart-thoi-gian (Line Chart): Thời gian thực thi thuật toán (ms)
 */
(function () {
    'use strict';

    function loadStatsConfig() {
        const configEl = document.getElementById('spx-stats-config');
        if (configEl && configEl.dataset && configEl.dataset.config) {
            try {
                return JSON.parse(configEl.dataset.config);
            } catch (e) {
                // ignore parse error and fallback
            }
        }
        return window.SPX_STATS_CONFIG || {};
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatCurrency(amount) {
        const num = Number(amount || 0);
        if (isNaN(num)) {
            return '0';
        }
        return Math.round(num).toLocaleString('vi-VN');
    }

    const config = loadStatsConfig();
    const baseUrl = config.baseUrl || '/dinh-tuyen-spx/public';
    const buuCuc = config.buuCuc || { id: 1 };

    let tongHop = config.tongHop || {};
    let thongKeTrangThai = config.thongKeTrangThai || {};
    let thongKeTaiXeCod = Array.isArray(config.thongKeTaiXeCod) ? config.thongKeTaiXeCod : [];
    let thongKeTheoPhuong = Array.isArray(config.thongKeTheoPhuong) ? config.thongKeTheoPhuong : [];
    let danhSachCum = Array.isArray(config.danhSachCum) ? config.danhSachCum : [];
    let benchmarkData = Array.isArray(config.benchmark) ? config.benchmark : [];

    // Chart instances
    let statusDoughnutChart = null;
    let driverProgressChart = null;
    let driverCodChart = null;
    let distanceChart = null;
    let timeChart = null;

    function showAlert(type, message) {
        const alertBox = document.getElementById('alert-thong-ke');
        const alertText = document.getElementById('alert-thong-ke-text');
        if (!alertBox || !alertText) {
            return;
        }
        alertBox.className = 'alert alert-' + type + ' alert-dismissible fade show shadow-sm mb-3';
        alertText.textContent = message;
    }

    /**
     * Vẽ 3 biểu đồ Vận hành ở Tab 1:
     * 1. chart-trang-thai-don (Doughnut)
     * 2. chart-tien-do-tai-xe (Stacked Bar)
     * 3. chart-doi-soat-cod (Grouped Bar)
     */
    function renderOperationalCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }

        // 1. Biểu đồ Tỷ lệ Trạng thái Đơn hàng (Doughnut Chart)
        const ctxStatus = document.getElementById('chart-trang-thai-don');
        if (ctxStatus) {
            if (statusDoughnutChart) {
                statusDoughnutChart.destroy();
            }

            const chuaPhanCong = Number(thongKeTrangThai.chua_phan_cong || 0);
            const dangGiao = Number(thongKeTrangThai.da_phan_cong || 0);
            const thanhCong = Number(thongKeTrangThai.giao_thanh_cong || 0);
            const thatBai = Number(thongKeTrangThai.giao_that_bai || 0);

            statusDoughnutChart = new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: [
                        'Giao thành công (' + thanhCong + ')',
                        'Đang đi giao (' + dangGiao + ')',
                        'Giao thất bại (' + thatBai + ')',
                        'Chưa phân công (' + chuaPhanCong + ')'
                    ],
                    datasets: [
                        {
                            data: [thanhCong, dangGiao, thatBai, chuaPhanCong],
                            backgroundColor: [
                                'rgba(16, 185, 129, 0.88)', // Xanh lá hoàn tất
                                'rgba(37, 99, 235, 0.85)',  // Xanh dương đang giao
                                'rgba(239, 68, 68, 0.88)',  // Đỏ thất bại
                                'rgba(245, 158, 11, 0.85)'  // Vàng chờ phân công
                            ],
                            borderColor: [
                                '#059669',
                                '#1d4ed8',
                                '#dc2626',
                                '#d97706'
                            ],
                            borderWidth: 1.5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 12 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const total = (thanhCong + dangGiao + thatBai + chuaPhanCong) || 1;
                                    const val = Number(context.parsed || 0);
                                    const pct = ((val / total) * 100).toFixed(1);
                                    return context.label + ': ' + val + ' đơn (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Chuẩn bị nhãn Tài xế cho biểu đồ 2 & 3
        const driverLabels = thongKeTaiXeCod.map(function (tx, idx) {
            const fullName = String(tx.ho_ten || ('Tài xế #' + (idx + 1)));
            // Lấy tên gọn dễ nhìn trên trục X
            const shortName = fullName.split('(')[0].trim();
            return shortName;
        });

        // 2. Biểu đồ Tiến độ Giao hàng theo Tài xế (Stacked Bar Chart)
        const ctxProgress = document.getElementById('chart-tien-do-tai-xe');
        if (ctxProgress) {
            if (driverProgressChart) {
                driverProgressChart.destroy();
            }

            const arrThanhCong = thongKeTaiXeCod.map(function (tx) {
                return Number(tx.so_don_thanh_cong || 0);
            });
            const arrDangGiao = thongKeTaiXeCod.map(function (tx) {
                return Number(tx.so_don_dang_giao || 0);
            });
            const arrThatBai = thongKeTaiXeCod.map(function (tx) {
                return Number(tx.so_don_that_bai || 0);
            });

            driverProgressChart = new Chart(ctxProgress, {
                type: 'bar',
                data: {
                    labels: driverLabels,
                    datasets: [
                        {
                            label: 'Đã giao (Thành công)',
                            data: arrThanhCong,
                            backgroundColor: 'rgba(16, 185, 129, 0.85)',
                            borderColor: '#059669',
                            borderWidth: 1
                        },
                        {
                            label: 'Đang đi giao',
                            data: arrDangGiao,
                            backgroundColor: 'rgba(59, 130, 246, 0.85)',
                            borderColor: '#2563eb',
                            borderWidth: 1
                        },
                        {
                            label: 'Giao thất bại',
                            data: arrThatBai,
                            backgroundColor: 'rgba(239, 68, 68, 0.85)',
                            borderColor: '#dc2626',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return context.dataset.label + ': ' + Number(context.parsed.y || 0) + ' đơn';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            title: {
                                display: true,
                                text: 'Số lượng đơn hàng'
                            }
                        }
                    }
                }
            });
        }

        // 3. Biểu đồ Đối soát Tiền thu hộ COD theo Tài xế (Grouped Bar Chart)
        const ctxCod = document.getElementById('chart-doi-soat-cod');
        if (ctxCod) {
            if (driverCodChart) {
                driverCodChart.destroy();
            }

            const arrCodPhaiThu = thongKeTaiXeCod.map(function (tx) {
                return Number(tx.tong_cod_phai_thu || 0);
            });
            const arrCodThucThu = thongKeTaiXeCod.map(function (tx) {
                return Number(tx.tong_cod_thuc_thu || 0);
            });

            driverCodChart = new Chart(ctxCod, {
                type: 'bar',
                data: {
                    labels: driverLabels,
                    datasets: [
                        {
                            label: 'Tổng COD cần thu',
                            data: arrCodPhaiThu,
                            backgroundColor: 'rgba(148, 163, 184, 0.8)',
                            borderColor: '#64748b',
                            borderWidth: 1
                        },
                        {
                            label: 'COD thực thu (Đã thu về)',
                            data: arrCodThucThu,
                            backgroundColor: 'rgba(238, 77, 45, 0.85)',
                            borderColor: '#ee4d2d',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return context.dataset.label + ': ' + formatCurrency(context.parsed.y) + ' VNĐ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
                                    const num = Number(value || 0);
                                    if (num >= 1000000) {
                                        return (num / 1000000).toFixed(1) + 'M';
                                    }
                                    if (num >= 1000) {
                                        return (num / 1000).toFixed(0) + 'k';
                                    }
                                    return String(num);
                                }
                            },
                            title: {
                                display: true,
                                text: 'Số tiền COD (VNĐ)'
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * Chuẩn bị dữ liệu cho 2 biểu đồ Thuật toán ở Tab 2
     */
    function buildAlgorithmDatasets() {
        const hasClusters = danhSachCum.length > 0;
        const sourceItems = hasClusters ? danhSachCum : benchmarkData;

        const labels = sourceItems.map(function (item, idx) {
            if (hasClusters) {
                return (item.ten_tai_xe || ('Cụm #' + (idx + 1))) + ' (' + Number(item.so_don_hang) + ' đơn)';
            }
            return 'Mẫu ' + Number(item.so_don) + ' đơn (' + Number(item.so_cum) + ' cụm)';
        });

        const baselineArr = sourceItems.map(function (item) {
            return Number(item.km_baseline || 0);
        });
        const nnArr = sourceItems.map(function (item) {
            return Number(item.km_nn || 0);
        });
        const optArr = sourceItems.map(function (item) {
            return Number(item.km_toi_uu || 0);
        });

        const timeLabels = benchmarkData.map(function (b) {
            return Number(b.so_don) + ' đơn';
        });
        const timeValues = benchmarkData.map(function (b) {
            return Number(b.thoi_gian_chay_ms || 0);
        });

        return {
            labels: labels,
            baselineArr: baselineArr,
            nnArr: nnArr,
            optArr: optArr,
            timeLabels: timeLabels,
            timeValues: timeValues
        };
    }

    /**
     * Vẽ 2 biểu đồ Thuật toán ở Tab 2:
     * - chart-quang-duong (Bar Chart)
     * - chart-thoi-gian (Line Chart)
     */
    function renderAlgorithmCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }

        const data = buildAlgorithmDatasets();
        const ctxDistance = document.getElementById('chart-quang-duong');
        const ctxTime = document.getElementById('chart-thoi-gian');

        if (ctxDistance) {
            if (distanceChart) {
                distanceChart.destroy();
            }
            distanceChart = new Chart(ctxDistance, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Baseline (Thứ tự gốc JSON)',
                            data: data.baselineArr,
                            backgroundColor: 'rgba(148, 163, 184, 0.75)',
                            borderColor: 'rgb(100, 116, 139)',
                            borderWidth: 1
                        },
                        {
                            label: 'Nearest Neighbor (NN)',
                            data: data.nnArr,
                            backgroundColor: 'rgba(59, 130, 246, 0.75)',
                            borderColor: 'rgb(37, 99, 235)',
                            borderWidth: 1
                        },
                        {
                            label: '2-Opt (Đã tối ưu)',
                            data: data.optArr,
                            backgroundColor: 'rgba(22, 163, 74, 0.82)',
                            borderColor: 'rgb(21, 128, 61)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return context.dataset.label + ': ' + Number(context.parsed.y).toFixed(2) + ' km';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Quãng đường (km)'
                            }
                        }
                    }
                }
            });
        }

        if (ctxTime) {
            if (timeChart) {
                timeChart.destroy();
            }
            timeChart = new Chart(ctxTime, {
                type: 'line',
                data: {
                    labels: data.timeLabels,
                    datasets: [
                        {
                            label: 'Thời gian chạy Balanced K-Means + NN + 2-Opt (ms)',
                            data: data.timeValues,
                            borderColor: '#ee4d2d',
                            backgroundColor: 'rgba(238, 77, 45, 0.15)',
                            pointBackgroundColor: '#ee4d2d',
                            pointRadius: 5,
                            fill: true,
                            tension: 0.25
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return 'Thời gian thực thi: ' + Number(context.parsed.y).toFixed(3) + ' ms';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Thời gian thực thi (ms)'
                            }
                        }
                    }
                }
            });
        }
    }

    function renderAllCharts() {
        renderOperationalCharts();
        renderAlgorithmCharts();
    }

    /**
     * Cập nhật các thẻ KPI và bảng số liệu ở Tab 1 & Tab 2 khi tải mới qua API
     */
    function updateDashboardDOM() {
        // Cập nhật 4 thẻ KPI Tab 1
        const elTongDon = document.getElementById('kpi-op-tong-don');
        const elTongDonSub = document.getElementById('kpi-op-tong-don-sub');
        const elSla = document.getElementById('kpi-op-sla');
        const elSlaSub = document.getElementById('kpi-op-sla-sub');
        const elCod = document.getElementById('kpi-op-cod');
        const elThatBai = document.getElementById('kpi-op-that-bai');

        const tongDon = Number(thongKeTrangThai.tong_so_don || 0);
        const chuaPhanCong = Number(thongKeTrangThai.chua_phan_cong || 0);
        const dangGiao = Number(thongKeTrangThai.da_phan_cong || 0);
        const thanhCong = Number(thongKeTrangThai.giao_thanh_cong || 0);
        const thatBai = Number(thongKeTrangThai.giao_that_bai || 0);
        const daXuLy = Number(thongKeTrangThai.so_don_da_xu_ly || (thanhCong + thatBai));
        const slaPct = Number(thongKeTrangThai.ty_le_thanh_cong_sla || 0);
        const codDaThu = Number(thongKeTrangThai.cod_da_thu || 0);
        const codPhaiThu = Number(thongKeTrangThai.tong_cod_phai_thu_tren_tuyen || 0);

        if (elTongDon) {
            elTongDon.textContent = tongDon + ' đơn';
        }
        if (elTongDonSub) {
            elTongDonSub.innerHTML =
                'Chờ phân công: <strong class="text-warning-emphasis">' + chuaPhanCong + '</strong>' +
                ' &bull; Đang đi giao: <strong class="text-primary">' + dangGiao + '</strong>';
        }
        if (elSla) {
            elSla.textContent = slaPct.toFixed(1) + '%';
        }
        if (elSlaSub) {
            elSlaSub.innerHTML = 'Thành công <strong>' + thanhCong + '</strong> / ' + daXuLy + ' đơn đã xử lý';
        }
        if (elCod) {
            elCod.innerHTML = '<span class="text-success">' + formatCurrency(codDaThu) + '</span> / ' + formatCurrency(codPhaiThu) + ' đ';
        }
        if (elThatBai) {
            elThatBai.textContent = thatBai + ' đơn';
        }

        // Cập nhật bảng Đối soát COD cuối ngày
        const tbodyCod = document.getElementById('tbody-doi-soat-cod');
        if (tbodyCod && thongKeTaiXeCod.length > 0) {
            let sumTongDon = 0;
            let sumThanhCong = 0;
            let sumDangGiao = 0;
            let sumThatBai = 0;
            let sumCodPhaiThu = 0;
            let sumCodThucThu = 0;

            const rowsHtml = thongKeTaiXeCod.map(function (tx) {
                const tdTong = Number(tx.tong_so_don || 0);
                const tdTc = Number(tx.so_don_thanh_cong || 0);
                const tdDg = Number(tx.so_don_dang_giao || 0);
                const tdTb = Number(tx.so_don_that_bai || 0);
                const tdCodPt = Number(tx.tong_cod_phai_thu || 0);
                const tdCodTt = Number(tx.tong_cod_thuc_thu || 0);

                sumTongDon += tdTong;
                sumThanhCong += tdTc;
                sumDangGiao += tdDg;
                sumThatBai += tdTb;
                sumCodPhaiThu += tdCodPt;
                sumCodThucThu += tdCodTt;

                let badgeHtml = '<span class="badge bg-secondary-subtle text-secondary">Chưa nhận tuyến</span>';
                if (tdTong > 0 && tdDg === 0) {
                    badgeHtml = '<span class="badge bg-success">Đã hoàn tất ca</span>';
                } else if (tdTong > 0) {
                    badgeHtml = '<span class="badge bg-primary-subtle text-primary border">Đang đi giao</span>';
                }

                return (
                    '<tr>' +
                    '<td>' +
                        '<div class="d-flex align-items-center gap-2">' +
                            '<span class="d-inline-block rounded-circle" style="width:12px;height:12px;background:' + escapeHtml(tx.mau_sac_cum || '#2563eb') + ';"></span>' +
                            '<div>' +
                                '<div class="fw-bold text-dark">' + escapeHtml(tx.ho_ten || '') + '</div>' +
                                '<div class="text-muted" style="font-size:0.72rem;">SĐT: ' + escapeHtml(tx.so_dien_thoai || '') + ' &bull; Tuyến: <code>' + escapeHtml(tx.ma_cum_tuyen || '-') + '</code></div>' +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                    '<td class="text-center fw-bold">' + tdTong + '</td>' +
                    '<td class="text-center text-success fw-bold">' + tdTc + '</td>' +
                    '<td class="text-center text-primary fw-semibold">' + tdDg + '</td>' +
                    '<td class="text-center text-danger fw-semibold">' + tdTb + '</td>' +
                    '<td class="text-end text-secondary">' + formatCurrency(tdCodPt) + ' đ</td>' +
                    '<td class="text-end fw-bold text-success">' + formatCurrency(tdCodTt) + ' đ</td>' +
                    '<td class="text-center">' + badgeHtml + '</td>' +
                    '</tr>'
                );
            }).join('');

            const footerRowHtml =
                '<tr class="table-light fw-bold">' +
                '<td>TỔNG CỘNG ĐỐI SOÁT TOÀN BƯU CỤC</td>' +
                '<td class="text-center">' + sumTongDon + '</td>' +
                '<td class="text-center text-success">' + sumThanhCong + '</td>' +
                '<td class="text-center text-primary">' + sumDangGiao + '</td>' +
                '<td class="text-center text-danger">' + sumThatBai + '</td>' +
                '<td class="text-end">' + formatCurrency(sumCodPhaiThu) + ' đ</td>' +
                '<td class="text-end text-success">' + formatCurrency(sumCodThucThu) + ' đ</td>' +
                '<td class="text-center">-</td>' +
                '</tr>';

            tbodyCod.innerHTML = rowsHtml + footerRowHtml;
        }

        // Cập nhật bảng Thống kê theo Phường/Xã
        const tbodyPhuong = document.getElementById('tbody-thong-ke-phuong');
        if (tbodyPhuong && thongKeTheoPhuong.length > 0) {
            tbodyPhuong.innerHTML = thongKeTheoPhuong.map(function (ph) {
                return (
                    '<tr>' +
                    '<td>' +
                        '<div class="fw-semibold text-dark">' + escapeHtml(ph.ten_phuong || '') + '</div>' +
                        '<div class="text-muted" style="font-size:0.7rem;">Mã HC: ' + escapeHtml(ph.ma_hanh_chinh || '') + '</div>' +
                    '</td>' +
                    '<td class="text-center fw-bold">' + Number(ph.tong_so_don || 0) + '</td>' +
                    '<td class="text-center text-success fw-semibold">' + Number(ph.so_don_thanh_cong || 0) + '</td>' +
                    '<td class="text-end"><span class="badge bg-info-subtle text-dark border">' + Number(ph.ty_le_hoan_thanh || 0).toFixed(1) + '%</span></td>' +
                    '</tr>'
                );
            }).join('');
        }

        // Cập nhật bảng Benchmark ở Tab 2
        const tbodyBenchmark = document.getElementById('tbody-benchmark');
        if (tbodyBenchmark && benchmarkData.length > 0) {
            tbodyBenchmark.innerHTML = benchmarkData.map(function (row) {
                return (
                    '<tr>' +
                    '<td class="text-center fw-bold">' + Number(row.so_don) + ' đơn</td>' +
                    '<td class="text-center">' + Number(row.so_cum) + ' cụm</td>' +
                    '<td class="text-end text-muted">' + Number(row.km_baseline).toFixed(2) + '</td>' +
                    '<td class="text-end">' + Number(row.km_nn).toFixed(2) + '</td>' +
                    '<td class="text-end fw-bold text-success">' + Number(row.km_toi_uu).toFixed(2) + '</td>' +
                    '<td class="text-end"><span class="badge bg-success-subtle text-success border">-' + Number(row.ty_le_tiet_kiem_baseline).toFixed(2) + '%</span></td>' +
                    '<td class="text-end fw-semibold text-primary">' + Number(row.thoi_gian_chay_ms).toFixed(3) + ' ms</td>' +
                    '</tr>'
                );
            }).join('');
        }
    }

    async function fetchAndRefreshStats(triggerBtnId, successMsg) {
        const btn = document.getElementById(triggerBtnId);
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang cập nhật số liệu...';
        }

        try {
            const url = baseUrl + '/api/thong-ke/so-lieu?run_benchmark=1&buu_cuc_id=' + encodeURIComponent(buuCuc.id || 1);
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });

            const res = await response.json();
            if (!response.ok || !res.thanh_cong) {
                showAlert('danger', res.thong_bao || 'Không thể tải số liệu thống kê.');
                return;
            }

            if (res.du_lieu) {
                tongHop = res.du_lieu.tong_hop || tongHop;
                thongKeTrangThai = res.du_lieu.thong_ke_trang_thai || thongKeTrangThai;
                thongKeTaiXeCod = Array.isArray(res.du_lieu.thong_ke_tai_xe_cod) ? res.du_lieu.thong_ke_tai_xe_cod : thongKeTaiXeCod;
                thongKeTheoPhuong = Array.isArray(res.du_lieu.thong_ke_theo_phuong) ? res.du_lieu.thong_ke_theo_phuong : thongKeTheoPhuong;
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                benchmarkData = Array.isArray(res.du_lieu.benchmark) ? res.du_lieu.benchmark : benchmarkData;

                updateDashboardDOM();
                renderAllCharts();
                showAlert('success', successMsg || 'Đã cập nhật số liệu vận hành và thực nghiệm mới nhất!');
            }
        } catch (err) {
            showAlert('danger', 'Lỗi kết nối khi gọi API /api/thong-ke/so-lieu.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }

    function bootstrapStatsPage() {
        renderAllCharts();

        const btnRefresh = document.getElementById('btn-refresh-stats');
        if (btnRefresh) {
            btnRefresh.addEventListener('click', function () {
                fetchAndRefreshStats('btn-refresh-stats', 'Đã đồng bộ số liệu Vận hành Bưu cục & Đối soát COD mới nhất!');
            });
        }

        const btnBenchmark = document.getElementById('btn-run-benchmark');
        if (btnBenchmark) {
            btnBenchmark.addEventListener('click', function () {
                fetchAndRefreshStats('btn-run-benchmark', 'Đã chạy lại thực nghiệm Benchmark (10, 20, 30, 40 đơn) và cập nhật biểu đồ thành công!');
            });
        }

        // Khi người dùng chuyển đổi giữa Tab 1 và Tab 2, trigger resize biểu đồ
        const tabButtons = document.querySelectorAll('#statsMainTabs button[data-bs-toggle="tab"]');
        tabButtons.forEach(function (tabBtn) {
            tabBtn.addEventListener('shown.bs.tab', function () {
                renderAllCharts();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapStatsPage);
    } else {
        bootstrapStatsPage();
    }
})();
