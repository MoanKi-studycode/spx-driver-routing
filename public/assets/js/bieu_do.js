/**
 * bieu_do.js
 * Quản lý vẽ biểu đồ thống kê Chart.js trên màn hình /thong-ke:
 * - Bar chart so sánh quãng đường Baseline vs Nearest Neighbor (NN) vs 2-Opt
 * - Line chart thời gian thực thi thuật toán (ms)
 * - Nút "Chạy Benchmark thực nghiệm (10, 20, 30, 40 đơn)" gọi GET /api/thong-ke/so-lieu
 */
(function () {
    'use strict';

    const config = window.SPX_STATS_CONFIG || {};
    const baseUrl = config.baseUrl || '/dinh-tuyen-spx/public';
    const buuCuc = config.buuCuc || { id: 1 };

    let danhSachCum = Array.isArray(config.danhSachCum) ? config.danhSachCum : [];
    let benchmarkData = Array.isArray(config.benchmark) ? config.benchmark : [];

    let distanceChart = null;
    let timeChart = null;

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

    function showAlert(type, message) {
        const alertBox = document.getElementById('alert-thong-ke');
        const alertText = document.getElementById('alert-thong-ke-text');
        if (!alertBox || !alertText) {
            return;
        }
        alertBox.className = 'alert alert-' + type + ' alert-dismissible fade show shadow-sm mb-3';
        alertText.textContent = message;
    }

    function buildChartDatasets() {
        // Nếu đã có các cụm tuyến thực tế trong DB thì vẽ các cụm + có thể chuyển sang xem Benchmark;
        // Nếu chưa có cụm trong DB thì hiển thị trực tiếp 4 mốc Benchmark (10, 20, 30, 40 đơn)
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

    function renderCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }

        const data = buildChartDatasets();
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

    function updateBenchmarkTable() {
        const tbody = document.getElementById('tbody-benchmark');
        if (!tbody) {
            return;
        }
        tbody.innerHTML = benchmarkData.map(function (row) {
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

    async function handleRunBenchmark() {
        const btn = document.getElementById('btn-run-benchmark');
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang chạy Benchmark (10, 20, 30, 40 đơn)...';
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
                danhSachCum = Array.isArray(res.du_lieu.danh_sach_cum) ? res.du_lieu.danh_sach_cum : danhSachCum;
                benchmarkData = Array.isArray(res.du_lieu.benchmark) ? res.du_lieu.benchmark : benchmarkData;
                updateBenchmarkTable();
                renderCharts();
                showAlert('success', 'Đã chạy lại thực nghiệm Benchmark (10, 20, 30, 40 đơn) và cập nhật biểu đồ thành công!');
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

    document.addEventListener('DOMContentLoaded', function () {
        renderCharts();
        const btnBenchmark = document.getElementById('btn-run-benchmark');
        if (btnBenchmark) {
            btnBenchmark.addEventListener('click', handleRunBenchmark);
        }
    });
})();
