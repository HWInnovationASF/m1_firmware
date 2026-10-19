<?php
$socDvl = json_decode($dvl_txt ?? '{}', true) ?: [];
$socDetail = json_decode($detail_rgl_txt ?? '{}', true) ?: [];
$batteryDevices = [];
foreach ($socDvl as $id => $device) {
    $model = $device['model'] ?? '';
    if (($socDetail[$model]['type'] ?? '') === 'battery') {
        $batteryDevices[$id] = $device['name'] ?? $id;
    }
}
?>

<div class="bst-wrap">
    <div class="bst-head">
        <div>
            <h3><i class="fa-solid fa-car-battery"></i> Battery SOC Comparison</h3>
            <p>เปรียบเทียบ Cell voltage ตามระดับ SOC ตั้งแต่ 100.0% ถึง 0.0%</p>
        </div>
        <span id="bstUpdated">รอโหลดข้อมูล</span>
    </div>

    <div class="bst-filter">
        <label><span>Battery</span>
            <select id="bstDevice">
                <?php foreach ($batteryDevices as $id => $name): ?>
                    <option value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($name . ' [' . $id . ']', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>เริ่มต้น</span><input type="datetime-local" id="bstStart"></label>
        <label><span>สิ้นสุด</span><input type="datetime-local" id="bstEnd"></label>
        <button type="button" id="bstSearch"><i class="fa-solid fa-magnifying-glass"></i> แสดงข้อมูล</button>
        <button type="button" id="bstExport" class="bst-export"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <label class="bst-check"><input type="checkbox" id="bstOnlyData" checked> แสดงเฉพาะ SOC ที่มีข้อมูล</label>
    </div>

    <div id="bstAlert" class="bst-alert" style="display:none"></div>

    <div class="bst-table-wrap">
        <table class="bst-table">
            <thead>
                <tr>
                    <th>SOC (%)</th>
                    <th>cell_v_min เฉลี่ย (V)</th>
                    <th>cell_v_max เฉลี่ย (V)</th>
                    <th>ส่วนต่าง (mV)</th>
                </tr>
            </thead>
            <tbody id="bstBody"><tr><td colspan="4" class="bst-empty">กำลังโหลด...</td></tr></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const device = document.getElementById('bstDevice');
    const start = document.getElementById('bstStart');
    const end = document.getElementById('bstEnd');
    const onlyData = document.getElementById('bstOnlyData');
    let rowsBySoc = new Map();

    const pad = n => String(n).padStart(2, '0');
    const localValue = date => date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate())+'T'+pad(date.getHours())+':'+pad(date.getMinutes());
    const now = new Date();
    const weekAgo = new Date(now.getTime() - 7 * 86400000);
    start.value = localValue(weekAgo);
    end.value = localValue(now);

    function renderTable() {
        const body = document.getElementById('bstBody');
        const showOnlyData = onlyData.checked;
        const levels = showOnlyData
            ? [...rowsBySoc.keys()].sort((a,b) => b-a)
            : Array.from({length:1001}, (_,i) => Number((100-i/10).toFixed(1)));

        if (!levels.length) {
            body.innerHTML = '<tr><td colspan="4" class="bst-empty">ไม่พบข้อมูลในช่วงที่เลือก</td></tr>';
            return;
        }

        body.innerHTML = levels.map(soc => {
            const row = rowsBySoc.get(Number(soc).toFixed(1));
            if (!row) {
                return `<tr class="bst-no-data"><td><b>${Number(soc).toFixed(1)}</b></td><td colspan="3">—</td></tr>`;
            }
            return `<tr>
                <td><b>${Number(row.soc).toFixed(1)}</b></td>
                <td>${Number(row.cellVMinAvg).toFixed(3)}</td>
                <td>${Number(row.cellVMaxAvg).toFixed(3)}</td>
                <td><b>${Number(row.deltaAvg).toFixed(1)}</b></td>
            </tr>`;
        }).join('');
    }

    function load() {
        if (!device.value) {
            document.getElementById('bstAlert').style.display = '';
            document.getElementById('bstAlert').textContent = 'ไม่พบอุปกรณ์ประเภท Battery';
            return;
        }
        document.getElementById('bstBody').innerHTML = '<tr><td colspan="4" class="bst-empty">กำลังโหลด...</td></tr>';
        document.getElementById('bstAlert').style.display = 'none';
        let url = `/chart/api.php?mode=batterysoc&meterSN=${encodeURIComponent(device.value)}`;
        if (start.value) url += `&start=${encodeURIComponent(start.value.replace('T',' '))}`;
        if (end.value) url += `&end=${encodeURIComponent(end.value.replace('T',' '))}`;

        fetch(url, {cache:'no-store'}).then(response => {
            if (!response.ok) throw new Error('HTTP '+response.status);
            return response.json();
        }).then(data => {
            if (data.error) throw new Error(data.error);
            const rows = Array.isArray(data.rows) ? data.rows : [];
            rowsBySoc = new Map(rows.map(row => [Number(row.soc).toFixed(1), row]));
            renderTable();
            document.getElementById('bstUpdated').textContent = 'อัปเดตล่าสุด '+new Date().toLocaleString('th-TH');
        }).catch(error => {
            document.getElementById('bstBody').innerHTML = '<tr><td colspan="4" class="bst-empty">โหลดข้อมูลไม่สำเร็จ</td></tr>';
            document.getElementById('bstAlert').style.display = '';
            document.getElementById('bstAlert').textContent = error.message;
        });
    }

    function exportExcel() {
        const showOnlyData = onlyData.checked;
        const levels = showOnlyData
            ? [...rowsBySoc.keys()].sort((a,b) => b-a)
            : Array.from({length:1001}, (_,i) => Number((100-i/10).toFixed(1)));
        if (!levels.length) {
            document.getElementById('bstAlert').style.display = '';
            document.getElementById('bstAlert').textContent = 'ไม่มีข้อมูลสำหรับ Export';
            return;
        }
        const xml = value => String(value ?? '').replace(/[<>&'"]/g, char => ({
            '<':'&lt;','>':'&gt;','&':'&amp;',"'":'&apos;','"':'&quot;'
        }[char]));
        const textCell = value => '<Cell><Data ss:Type="String">'+xml(value)+'</Data></Cell>';
        const headerCell = value => '<Cell ss:StyleID="Header"><Data ss:Type="String">'+xml(value)+'</Data></Cell>';
        const numberCell = (value, style='Decimal1') => value === '' || value === null || value === undefined
            ? '<Cell><Data ss:Type="String"></Data></Cell>'
            : '<Cell ss:StyleID="'+style+'"><Data ss:Type="Number">'+Number(value).toFixed(style==='Decimal3'?3:1)+'</Data></Cell>';
        const selectedText = device.options[device.selectedIndex]?.textContent.trim() || device.value;
        const dataRows = levels.map(soc => {
            const row = rowsBySoc.get(Number(soc).toFixed(1));
            return '<Row>'+
                numberCell(soc, 'Decimal1')+
                numberCell(row ? row.cellVMinAvg : '', 'Decimal3')+
                numberCell(row ? row.cellVMaxAvg : '', 'Decimal3')+
                numberCell(row ? row.deltaAvg : '', 'Decimal1')+
            '</Row>';
        }).join('');
        // Split the XML processing instruction for PHP installations with
        // short_open_tag enabled.
        const workbook = '<'+'?xml version="1.0" encoding="UTF-8"?>'+
            '<'+'?mso-application progid="Excel.Sheet"?>'+
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '+
            'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'+
            '<Styles><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#DDEBF7" ss:Pattern="Solid"/></Style><Style ss:ID="Decimal1"><NumberFormat ss:Format="0.0"/></Style><Style ss:ID="Decimal3"><NumberFormat ss:Format="0.000"/></Style></Styles>'+
            '<Worksheet ss:Name="Battery SOC"><Table>'+
            '<Column ss:Width="90"/><Column ss:Width="135"/><Column ss:Width="135"/><Column ss:Width="115"/>'+
            '<Row>'+textCell('Battery')+textCell(selectedText)+'</Row>'+
            '<Row>'+textCell('Start')+textCell(start.value.replace('T',' '))+'</Row>'+
            '<Row>'+textCell('End')+textCell(end.value.replace('T',' '))+'</Row>'+
            '<Row></Row>'+
            '<Row>'+headerCell('SOC (%)')+headerCell('cell_v_min Average (V)')+headerCell('cell_v_max Average (V)')+headerCell('Difference (mV)')+'</Row>'+
            dataRows+'</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>5</SplitHorizontal><TopRowBottomPane>5</TopRowBottomPane></WorksheetOptions></Worksheet></Workbook>';
        const blob = new Blob([workbook], {type:'application/vnd.ms-excel;charset=utf-8'});
        const link = document.createElement('a');
        const timestamp = new Date().toISOString().slice(0,19).replace(/[-:T]/g,'');
        link.href = URL.createObjectURL(blob);
        link.download = 'battery_soc_'+device.value+'_'+timestamp+'.xls';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }

    document.getElementById('bstSearch').addEventListener('click', load);
    document.getElementById('bstExport').addEventListener('click', exportExcel);
    onlyData.addEventListener('change', renderTable);
    device.addEventListener('change', load);
    load();
}());
</script>

<style>
.bst-wrap{--bst-blue:#1769d2;max-width:1450px;margin:0 auto;padding:4px 2px 35px;color:#24364b}.bst-head{display:flex;justify-content:space-between;align-items:flex-end;gap:15px;margin-bottom:16px}.bst-head h3{margin:0;font-weight:750}.bst-head h3 i{color:#25a56a;margin-right:6px}.bst-head p,.bst-head>span{margin:4px 0 0;color:#718096;font-size:.8rem}.bst-filter{display:grid;grid-template-columns:1.4fr 1fr 1fr auto auto auto;align-items:end;gap:10px;background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:14px;box-shadow:0 8px 24px rgba(31,55,89,.06)}.bst-filter label>span{display:block;font-size:.72rem;font-weight:700;color:#526577;margin-bottom:5px}.bst-filter select,.bst-filter input{width:100%;min-height:39px;border:1px solid #d3deea;border-radius:8px;padding:6px 9px;background:#fff}.bst-filter button{min-height:39px;border:0;border-radius:8px;background:var(--bst-blue);color:#fff;padding:0 14px;font-weight:650;white-space:nowrap}.bst-filter button.bst-export{background:#168958}.bst-filter .bst-check{display:flex;align-items:center;gap:6px;min-height:39px;font-size:.75rem;white-space:nowrap}.bst-filter .bst-check input{width:auto;min-height:auto}.bst-alert{padding:10px 13px;margin:10px 0;border:1px solid #f2cf75;border-radius:9px;background:#fff8df;color:#785c12}.bst-table-wrap{max-height:650px;overflow:auto;background:#fff;border:1px solid #e1e8f0;border-radius:14px;margin-top:13px}.bst-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.78rem}.bst-table th{position:sticky;top:0;z-index:2;background:#edf4fc;color:#36516d;padding:11px 10px;text-align:right;border-bottom:1px solid #d8e3ef;white-space:nowrap}.bst-table th:first-child,.bst-table td:first-child{text-align:center}.bst-table td{padding:9px 10px;text-align:right;border-bottom:1px solid #edf1f5;font-variant-numeric:tabular-nums}.bst-table tbody tr:hover{background:#f7faff}.bst-no-data{color:#a0aaba;background:#fafbfc}.bst-empty{text-align:center!important;padding:30px!important;color:#718096}@media(max-width:1100px){.bst-filter{grid-template-columns:1fr 1fr 1fr}.bst-filter label:first-child{grid-column:1/-1}.bst-table-wrap{max-height:580px}}@media(max-width:560px){.bst-head{align-items:flex-start;flex-direction:column}.bst-filter{grid-template-columns:1fr}.bst-filter label:first-child{grid-column:auto}}
</style>
