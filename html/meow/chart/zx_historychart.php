<script src="./js/highcharts/dist/highcharts.js"></script>
<script src="./js/highcharts/dist/modules/exporting.js"></script>
<script src="./js/highcharts/dist/modules/export-data.js"></script>
<script src="./js/highcharts/dist/modules/accessibility.js"></script>

<div id="container" style="min-height:500px"></div>
<div id="chartError" style="display:none;color:red;padding:10px;"></div>

<?php

// รับ GET/POST และ sanitize ทันที
$input = array_merge($_GET ?? [], $_POST ?? []);
foreach ($input as $k => $v) {
    if (isset($v) && $v !== 'undefined') {
        $$k = htmlspecialchars(strip_tags((string)$v), ENT_QUOTES, 'UTF-8');
    }
}
$data_all = file_get_contents('../data/mcf_default.json');
$data_all = json_decode($data_all,true);
$deviceSN_f = isset($data_all['deviceSN']) ? $data_all['deviceSN']: "";

$device_SN = $deviceSN_f;
$meter_SN  = isset($meterSN) ? $meterSN : '';
$param     = isset($para)    ? $para    : '';
$dt_start  = isset($start)   ? $start   : '';
$dt_end    = isset($end)     ? $end     : '';
$tick_ms   = isset($tickMs) && ctype_digit((string)$tickMs) ? (int)$tickMs : (240 * 60 * 1000);
$is_live   = isset($live) && $live == '1';

// ส่งค่าไป JS ผ่าน json_encode เพื่อความปลอดภัย (ไม่ต้องกังวล XSS)
$js_meterSN = json_encode($meter_SN);
$js_param   = json_encode($param);
$js_start   = json_encode($dt_start ?: null);
$js_end     = json_encode($dt_end   ?: null);
// ชื่อไฟล์ตาม dtStart-dtEnd ถ้ามี ไม่งั้นใช้วันนี้
$export_start = $dt_start ? date('Ymd', strtotime($dt_start)) : date('Ymd');
$export_end   = $dt_end   ? date('Ymd', strtotime($dt_end))   : date('Ymd');
$js_export    = json_encode($device_SN . '_' . $export_start . '_' . $export_end);
$js_live      = json_encode($is_live);
?>

<script>
(function () {

    // ── Config ───────────────────────────────────────────────
    const meterSN = <?php echo $js_meterSN; ?>;
    const para    = <?php echo $js_param;   ?>;
    const dtStart = <?php echo $js_start;   ?>;
    const dtEnd   = <?php echo $js_end;     ?>;
    const isLive  = <?php echo $js_live;    ?>;

    // ── Guard: ต้องมี meterSN และ para ──────────────────────
    if (!meterSN || !para) {
        showError('ไม่พบ meterSN หรือ para');
        return;
    }

    // ค่าคงที่ระหว่าง live refresh: ความยาวหน้าต่างเวลาเดิม (เลื่อนตามเวลาปัจจุบันทุกรอบ)
    const initialExtremes = getXExtremes();
    const windowMs = initialExtremes.endTs - initialExtremes.startTs;
    const LIVE_REFRESH_MS = 15000;   // อ่านข้อมูลใหม่ทุก 15s (api.php สแกนไฟล์ log ทั้งหมด ไม่ควรถี่กว่านี้)

    let chartInstance  = null;
    let refreshInFlight = false;

    // ── Loading state ────────────────────────────────────────
    document.getElementById('container').innerHTML =
        '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;">กำลังโหลดข้อมูล...</div>';

    // ── Fetch ────────────────────────────────────────────────
    loadChart(initialExtremes.startTs, initialExtremes.endTs, /*isFirstLoad*/ true);

    if (isLive) {
        setInterval(() => {
            const now = Date.now();
            loadChart(now - windowMs, now, false);
        }, LIVE_REFRESH_MS);
    }

    function loadChart(startTs, endTs, isFirstLoad) {
        if (refreshInFlight) return;
        refreshInFlight = true;

        fetch(buildApiUrl(startTs, endTs))
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(series => {
                series = processSeries(series, startTs, endTs);
                if (isFirstLoad || !chartInstance) {
                    renderChart(series, startTs, endTs);
                } else {
                    updateChart(series, startTs, endTs);
                }
            })
            .catch(err => {
                console.error('Chart fetch error:', err);
                if (isFirstLoad) showError('โหลดข้อมูลไม่สำเร็จ: ' + err.message);
                // ระหว่าง live refresh ถ้าพลาดรอบเดียวไม่ต้องล้างกราฟเดิม รอรอบถัดไป
            })
            .finally(() => {
                refreshInFlight = false;
            });
    }

    // ── แปลง raw series จาก API → ตัดตาม range + เติมเส้นประช่วงไม่มีข้อมูลจริง ──
    function processSeries(series, startTs, endTs) {

        if (!Array.isArray(series) || series.length === 0) {
            // ไม่มี series เลย — วาดเส้นที่ 0 ตลอดช่วง (โหมด live ให้เป็นเส้นทึบตามที่ขอ)
            return [{ name: 'No Data', data: [[startTs, 0], [endTs, 0]], unit: '', dashStyle: isLive ? 'Solid' : 'Dash' }];
        }

        const getTs = pt => Array.isArray(pt) ? pt[0] : pt?.x;

        series.forEach(s => {
            s.tooltip = { valueSuffix: ' ' + (s.unit || '') };

            if (!Array.isArray(s.data)) return;

            // แยก point นอก / ใน range
            const before = s.data.filter(pt => getTs(pt) < startTs);
            const inside = s.data.filter(pt => { const ts = getTs(pt); return ts >= startTs && ts <= endTs; });
            const after  = s.data.filter(pt => getTs(pt) > endTs);

            const getVal = pt => Array.isArray(pt) ? pt[1] : pt?.y ?? 0;

            // ค่าเริ่มต้น: จาก point แรกใน range หรือ point ล่าสุดก่อน range หรือ 0
            const firstVal = inside.length  ? getVal(inside[0])
                           : before.length  ? getVal(before[before.length - 1])
                           : after.length   ? getVal(after[0])
                           : 0;

            // ค่าสิ้นสุด: จาก point สุดท้ายใน range หรือ point แรกหลัง range หรือ firstVal
            const lastVal  = inside.length  ? getVal(inside[inside.length - 1])
                           : after.length   ? getVal(after[0])
                           : firstVal;

            const result = [];

            // เติม flat line ตั้งแต่ startTs ถึง point แรก (ไม่มีข้อมูลจริง)
            const leadPadded = !inside.length || getTs(inside[0]) > startTs;
            if (leadPadded) {
                result.push([startTs, firstVal]);
            }

            result.push(...inside);

            // เติม flat line จาก point สุดท้ายถึง endTs (ไม่มีข้อมูลจริง)
            const lastPtTs = inside.length ? getTs(inside[inside.length - 1]) : startTs;
            const tailPadded = lastPtTs < endTs;
            if (tailPadded) {
                result.push([endTs, lastVal]);
            }

            s.data = result;

            // ช่วงที่ไม่มีข้อมูลจริง (เติมเอง) ให้แสดงเป็นเส้นประ ส่วนช่วงที่มีข้อมูลจริงเป็นเส้นทึบ
            // (โหมด live: เส้นทึบตลอดเสมอ ไม่ใช้เส้นประ ตามที่ขอ)
            if (isLive) {
                s.dashStyle = 'Solid';
            } else if (!inside.length) {
                s.dashStyle = 'Dash';
            } else {
                const zones = [];
                const firstRealTs = getTs(inside[0]);
                const lastRealTs  = getTs(inside[inside.length - 1]);

                if (leadPadded) zones.push({ value: firstRealTs, dashStyle: 'Dash' });
                if (leadPadded || tailPadded) zones.push({ value: lastRealTs, dashStyle: 'Solid' });
                if (tailPadded) zones.push({ dashStyle: 'Dash' });

                if (zones.length) {
                    s.zones    = zones;
                    s.zoneAxis = 'x';
                }
            }
        });

        return series;
    }

    // ── อัปเดตกราฟที่มีอยู่แล้วแบบไม่ redraw ทั้งก้อน (ใช้ตอน live refresh) ──
    function updateChart(series, startTs, endTs) {
        chartInstance.xAxis[0].setExtremes(startTs, endTs, false);
        series.forEach((s, i) => {
            if (chartInstance.series[i]) {
                chartInstance.series[i].setData(s.data, false);
            }
        });
        chartInstance.redraw();
    }

    // ── Functions ────────────────────────────────────────────
    function buildApiUrl(startTs, endTs) {
        let url = 'api.php?mode=stockchart'
            + '&meterSN=' + encodeURIComponent(meterSN)
            + '&para='    + encodeURIComponent(para)
            + '&start_ts=' + Math.floor(startTs / 1000)
            + '&end_ts=' + Math.floor(endTs / 1000);
        return url;
    }

    function parseDate(str) {
        if (!str) return null;
        const ts = new Date(str).getTime();
        return isNaN(ts) ? null : ts;
    }

    function getXExtremes() {
        const now = Date.now();
        const startTs = parseDate(dtStart)
            ?? new Date(new Date().getFullYear(), new Date().getMonth(), new Date().getDate(), 0, 0, 0, 0).getTime();
        const endTs = Math.min(parseDate(dtEnd) ?? now, now);
        return { startTs, endTs };
    }

    const deviceSN = <?php echo json_encode($device_SN); ?>;

    // เพิ่มแถว device_SN บนสุดของ CSV
    Highcharts.wrap(Highcharts.Chart.prototype, 'getCSV', function (proceed) {
        const csv = proceed.apply(this, Array.prototype.slice.call(arguments, 1));
        return 'Device,' + deviceSN + '\n' + csv;
    });

    // ตั้ง sheet name = device_SN สำหรับ XLS (ใช้ PHP echo โดยตรงเพื่อหลีกเลี่ยงปัญหา JS template literal กับ XML comment)
    Highcharts.Chart.prototype.downloadXLS = function () {
        const table = this.getTable(true);
        const xml   = [
            '<html xmlns:o="urn:schemas-microsoft-com:office:office"',
            ' xmlns:x="urn:schemas-microsoft-com:office:excel"',
            ' xmlns="urn:schemas-microsoft-com:office:html">',
            '<head>',
            '<!--[if gte mso 9]><xml>',
            '<x:ExcelWorkbook><x:ExcelWorksheets>',
            '<x:ExcelWorksheet>',
            '<x:Name><?php echo htmlspecialchars($device_SN, ENT_XML1, 'UTF-8'); ?></x:Name>',
            '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>',
            '</x:ExcelWorksheet>',
            '</x:ExcelWorksheets></x:ExcelWorkbook>',
            '</xml><![endif]-->',
            '<meta http-equiv="content-type" content="text/plain; charset=UTF-8"/>',
            '</head>',
            '<body>' + table + '</body>',
            '</html>'
        ].join('');
        Highcharts.downloadURL(
            'data:application/vnd.ms-excel;base64,' + btoa(unescape(encodeURIComponent(xml))),
            this.getFilename() + '.xls'
        );
    };

    function renderChart(series, startTs, endTs) {

        document.getElementById('container').innerHTML = '';

        Highcharts.setOptions({
            time: { timezone: 'Asia/Bangkok' }
        });

        const liveBadge = isLive
            ? '<span style="display:inline-flex;align-items:center;gap:5px;margin-left:10px;font-size:12px;' +
              'font-weight:700;color:#16985b;vertical-align:middle;">' +
              '<span style="width:7px;height:7px;border-radius:50%;background:#20b56d;display:inline-block;' +
              'box-shadow:0 0 0 4px rgba(32,181,109,.13);' +
              'animation:zxLiveDot 1.4s infinite;"></span>LIVE</span>' +
              '<style>@keyframes zxLiveDot{0%,100%{opacity:1}50%{opacity:.35}}</style>'
            : '';

        chartInstance = Highcharts.chart('container', {

            chart: {
                type: 'line',
                zoomType: 'x'
            },

            plotOptions: {
                line: {
                    marker: {
                        enabled: false,
                        symbol: 'circle',
                        radius: 4,
                        states: {
                            hover: {
                                enabled:   true,
                                radius:    7,
                                lineWidth: 2,
                                lineColor: '#ffffff'
                            }
                        }
                    },
                    states: {
                        hover: {
                            lineWidthPlus: 1
                        }
                    }
                }
            },

            title: {
                useHTML: true,
                text: '<h3 class="header-title" style="display:inline-block;">MDB Graph</h3>' + liveBadge
            },

            xAxis: {
                type:         'datetime',
                tickInterval: <?php echo $tick_ms ?>,
                min:          startTs,
                max:          endTs,
                labels: {
                    <?php if ($tick_ms <= 5 * 60 * 1000): ?>
                    formatter: function() {
                        const d = new Date(this.value);
                        return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
                    },
                    step: 1,
                    <?php else: ?>
                    format: '{value:%d/%m/%y <br/> %H:%M}',
                    <?php endif; ?>
                }
            },

            yAxis: {
                title: { text: null }
            },

            tooltip: {
                shared:      true,
                useHTML:     true,
                formatter: function () {
                    let html = `<div style="font-size:12px">
                        <b>${Highcharts.dateFormat('%d/%m/%Y %H:%M', this.x)}</b><hr/>`;
                    this.points.forEach(p => {
                        html += `<div style="display:flex;justify-content:space-between;gap:10px">
                            <span><i style="display:inline-block;width:9px;height:9px;margin-right:6px;border-radius:50%;background:${p.color};box-shadow:0 0 0 2px ${p.color}22"></i>${p.series.name}</span>
                            <b>${p.y} ${p.series.userOptions.unit || ''}</b>
                        </div>`;
                    });
                    return html + '</div>';
                }
            },

            legend: {
                enabled: true,
                useHTML: true,
                symbolWidth: 0,
                symbolPadding: 0,
                itemDistance: 18,
                labelFormatter: function () {
                    const unit = this.userOptions.unit || '';
                    return '<span style="display:inline-flex;align-items:center;gap:7px;color:#40556d;font-size:12px;font-weight:600;">' +
                        '<i style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + this.color + ';box-shadow:0 0 0 3px ' + this.color + '22;"></i>' +
                        '<span>' + this.name + '</span>' +
                        (unit ? '<b style="padding:2px 6px;border-radius:5px;background:#eef3f8;color:#718198;font-size:10px;">' + unit + '</b>' : '') +
                        '</span>';
                }
            },

            exporting: {
                enabled:  true,
                filename: <?php echo $js_export; ?>,
                csv: {
                    dateFormat: '%d/%m/%Y %H:%M:%S'
                },
                chartOptions: {
                    title: {
                        text: <?php echo json_encode($device_SN); ?>
                    }
                },
                buttons: {
                    contextButton: {
                        menuItems: [
                            'viewFullscreen', 'separator',
                            'downloadPNG', 'downloadJPEG', 'downloadSVG', 'downloadPDF',
                            'separator',
                            'downloadCSV', 'downloadXLS'
                        ]
                    }
                }
            },

            credits: false,
            series:  series
        });
    }

    function showError(msg) {
        document.getElementById('container').style.display   = 'none';
        const el = document.getElementById('chartError');
        el.style.display = 'block';
        el.textContent   = msg;
    }

}());
</script>

<style>
    .header-title {
        color: #004aad;
        font-weight: bold;
        margin: 0;
    }
</style>
