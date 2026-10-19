<div class="body">
    <!-- Filter -->
    <link rel="stylesheet" href="../assets/jquery-datetimepicker/2.5.20/jquery.datetimepicker.min.css">
    <div class="topbar mb-3">
        
        <div class="dt-range">
            <div class="dt-field-box">
                <svg class="dt-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <input type="text" class="dt-input cursor" id="dtStart" placeholder="Start Date...">
            </div>
            <span class="dt-arrow d-lg-none">→</span>
            <div class="dt-field-box">
                <svg class="dt-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <input type="text" class="dt-input cursor" id="dtEnd" placeholder="End Date...">
            </div>
            <button class="dt-search-btn" onclick="searchChart()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <span class="d-none d-sm-inline">Search</span>
            </button>

            <div class="dt-preset-group">
                <button class="dt-preset" onclick="setPreset(1,this)">1h</button>
                <button class="dt-preset" onclick="setPreset(6,this)">6h</button>
                <button class="dt-preset" onclick="setPreset(12,this)">12h</button>
                <button class="dt-preset" onclick="setPreset(24,this)">24h</button>
                <button class="dt-preset dt-preset-live" onclick="setRealtime(this)"><span class="live-dot"></span>Real-time</button>
            </div>
        </div>
        
    </div>
    <div class="dt-preset-bar mb-3">
        <button id="burgerBtn" class="burger-btn d-lg-none" onclick="toggleSidebar()" aria-label="Toggle MDB List">
            <span></span><span></span><span></span>
        </button>
    </div>
    <div class="d-lg-none mb-2">
        <span id="burgerTagCount" class="burger-count" style="display:none;"></span>
    </div>

    <div class="row">
        <!-- LEFT: Tree sidebar -->
        <div class="col-lg-3 db-sidebar-col" id="sidebarCol">
            <div class="card-custom sidebar">
                <div class="sidebar-header">
                    <h3 class="d-none d-lg-block">MDB List</h3>
                    <div class="input-wrapper" id="inputWrapper">
                        <div class="tag-search-area" id="tagSearchArea">
                            <input type="text" id="searchBox" placeholder="Search...">
                        </div>
                        <button type="button" id="clearAllBtn" onclick="clearAllState()" title="Clear all">✕</button>
                    </div>
                </div>
                <input type="hidden" id="selectedInput">
                <div id="tree" class="tree"></div>
            </div>
        </div>

        <!-- CENTER: iframe chart -->
        <div class="col-12 col-lg-6 db-chart-col">
            <div class="card-custom chart-container">
                <div id="chartNoData" class="chart-nodata">
                    <div class="chart-nodata-inner">
                        <img src="../assets/images/no-data-chart.svg" width="64" height="64" alt="no data">
                        <p>ยังไม่ได้เลือกข้อมูล</p>
                        <small>กรุณาเลือก Parameter จาก MDB List</small>
                    </div>
                </div>
                <iframe id="historyFrame" src="" frameborder="0" scrolling="no" style="width:100%;height:600px;display:none;border-radius:10px;"></iframe>
            </div>
        </div>

        <!-- RIGHT: Live Board -->
        <div class="col-12 col-lg-3 db-live-col">
            <div class="card-custom live-board">
                <div class="live-header">
                    <span class="live-title">Live Data</span>
                    <span class="live-pulse" id="livePulse" style="display:none;"><span></span>Live</span>
                </div>
                <div class="live-updated" id="liveLastUpdated"></div>
                <div id="liveBoardData" class="live-body">
                    <div class="live-empty">
                        <img src="../assets/images/no-data-chart.svg" width="48" height="48" alt="no data">
                        <p>ยังไม่ได้เลือกข้อมูล</p>
                        <small>กรุณาเลือก Parameter จาก MDB List</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>

    // function toggleSeries(cb){

    //     if(cb.checked){
    //         chart.showSeries("Active Power Total");
    //     }else{
    //         chart.hideSeries("Active Power Total");
    //     }
    // }
    setInterval(() => {

        $.ajax({
            url: '../../api_mdbiotx.php',
            dataType: 'json',
            success: function(data) {

                model_arr = data;

                renderLiveBoard();
            }
        });

    }, 5000);

</script>

<script>
    
    let device_arr = <?php echo  $dvl_txt;?>;
    let rgl_arr = <?php echo  $rgl_txt;?>;
    let model_arr;

    // Parameter master list: map register code (e.g. "e_p") -> its full display Name
    // and sort Order, so tree children show the friendly InfoData.Name instead of the
    // raw code, and are ordered by the configured Order.
    const param_list = <?php echo isset($param_txt) && $param_txt !== '' ? $param_txt : '[]';?>;
    const paramMap = {};
    (Array.isArray(param_list) ? param_list : []).forEach(p => {
        if (p && p.Parameter) {
            paramMap[p.Parameter] = {
                name: (p.InfoData && p.InfoData.Name) ? p.InfoData.Name : p.Parameter,
                order: (p.Order !== undefined && p.Order !== null) ? p.Order : 9999
            };
        }
    });
    // code -> full name / order, with safe fallback to the code itself
    const paramName = code => (paramMap[code] && paramMap[code].name) ? paramMap[code].name : code;
    const paramOrder = code => (paramMap[code] && paramMap[code].order !== undefined) ? paramMap[code].order : 9999;

    // Model detail (model name -> { type, abbr }) used to group the MDB List by model type.
    const detailRgl = <?php echo isset($detail_rgl_txt) && $detail_rgl_txt !== '' ? $detail_rgl_txt : '{}';?>;
    // deviceId -> its model's type (e.g. "meter", "inverter"); '' when the model has no type set.
    const deviceType = deviceId => {
        const model = device_arr[deviceId] ? device_arr[deviceId].model : null;
        return (model && detailRgl[model] && detailRgl[model].type) ? detailRgl[model].type : '';
    };

    $.ajax({
        url: '../../api_mdbiotx.php',
        dataType: 'json',
        async: false,
        success: function(data) {
            model_arr = data;
        }
    });

    // console.log(device_arr);
    // console.log(model_arr);
    // console.log(model_arrs);
    
    // Devices currently sending live data (online) build their children from the live snapshot.
    // Devices that exist in config but are absent from the live snapshot (offline — not sending
    // anything) still get listed, with children built from their model's register list, but are
    // sorted to the end so users can tell them apart from devices with real data.
    const onlineIds = new Set(Object.keys(model_arr.data));

    const onlineTreeDatas = Object.entries(model_arr.data).map(([deviceId, values]) => ({

        id: deviceId,
        // device may still be sending live data after being deleted from device config — fall back to its SN
        text: device_arr[deviceId] ? device_arr[deviceId].name : deviceId,
        offline: false,

        children: Object.keys(values)
        .filter(field => !['dt', 'error'].includes(field))
        .map(field => ({
            id: `${deviceId}|${field}`,
            text: paramName(field),
            order: paramOrder(field)
        }))
        .sort((a, b) => a.order - b.order)

    }));

    const offlineTreeDatas = Object.entries(device_arr)
        .filter(([deviceId]) => !onlineIds.has(deviceId))
        .map(([deviceId, dev]) => ({
            id: deviceId,
            text: dev.name || deviceId,
            offline: true,
            children: Object.values(rgl_arr[dev.model] || {})
                .map(reg => reg.name)
                .filter((name, idx, arr) => name && arr.indexOf(name) === idx)
                .map(name => ({
                    id: `${deviceId}|${name}`,
                    text: paramName(name),
                    order: paramOrder(name)
                }))
                .sort((a, b) => a.order - b.order)
        }));

    // Sort the MDB List by the model's type (from detail_rgl.json) so devices of the
    // same type are grouped together. Ordering:
    //   1) devices with no selectable parameters (no children) go to the end — they carry no data;
    //   2) then group by model type in a fixed priority: meter first, then inverter, then the
    //      remaining types alphabetically; models with no type set sort last within group;
    //   3) ties keep the incoming order (online before offline) since Array.sort is stable.
    const TYPE_PRIORITY = ['meter', 'inverter'];
    // lower rank sorts first; typed-but-not-prioritized share one rank and fall back to A–Z
    const typeRank = type => {
        if (!type) return TYPE_PRIORITY.length + 1;            // untyped: last
        const i = TYPE_PRIORITY.indexOf(type);
        return i !== -1 ? i : TYPE_PRIORITY.length;            // other typed: after the priority list
    };
    // ค่าจาก Template Config > MDB List > ตั้งค่า
    const listSettings = <?php $t6Site = json_decode((string)@file_get_contents(__DIR__ . '/../../config/dashboard_template.json'), true); echo json_encode(is_array($t6Site['settings']['template6'] ?? null) ? $t6Site['settings']['template6'] : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;
    const hiddenDevices = new Set(Array.isArray(listSettings.hidden_devices) ? listSettings.hidden_devices : []);
    const treeDatas = [...onlineTreeDatas, ...(listSettings.hide_offline ? [] : offlineTreeDatas)]
        .filter(device => !hiddenDevices.has(device.id))
        .sort((a, b) => {
            const aEmpty = !(a.children?.length);
            const bEmpty = !(b.children?.length);
            if (aEmpty !== bEmpty) return aEmpty ? 1 : -1;

            const aType = deviceType(a.id);
            const bType = deviceType(b.id);
            const rankDiff = typeRank(aType) - typeRank(bType);
            if (rankDiff !== 0) return rankDiff;
            return aType.localeCompare(bType);   // same rank (both "other typed") -> alphabetical
        });

    // console.log(treeDatas);

    // const result = Object.values(treeData);
    // const result2 = Object.values(treeDatas);

    // console.log(result);
    // console.log(treeDatas);
    
    

    // ================= STATE =================
    const selected = new Map();
    let activeTickMs = 0;
    let isRealtimeMode = false;
    const REALTIME_WINDOW_MIN = 15;   // sliding window shown while in Real-time mode

    // ================= TAG UI =================
    const wrapper = document.getElementById('inputWrapper');
    const searchBox = document.getElementById('searchBox');
    const hiddenInput = document.getElementById('selectedInput');

    function getTextById(id) {
        const cb = document.querySelector(`input[value="${id}"]`);
        return cb ? cb.dataset.text : null;
    }

    function toggleSidebar() {
        const col = document.getElementById('sidebarCol');
        const btn = document.getElementById('burgerBtn');
        col.classList.toggle('sidebar-open');
        btn.classList.toggle('open');
    }

    function renderTags(){
        document.getElementById('clearAllBtn').style.display = selected.size > 0 ? 'inline-block' : 'none';

        // update burger badge count
        const countEl = document.getElementById('burgerTagCount');
        if (countEl) {
            if (selected.size > 0) {
                countEl.textContent = selected.size + ' selected';
                countEl.style.display = 'inline-block';
                // set tooltip
                const lines = [...selected.values()].join('\n');
                countEl.setAttribute('data-tooltip', lines);
            } else {
                countEl.style.display = 'none';
                countEl.removeAttribute('data-tooltip');
            }
        }

        // remove old tags
        document.querySelectorAll('.tag').forEach(t => t.remove());

        selected.forEach((text, id) => {

            const tag = document.createElement('div');
            tag.className = 'tag';

            tag.innerHTML = `
                <span>${text}</span>
                <button type="button">✕</button>
            `;

            tag.querySelector('button').onclick = () => {
                selected.delete(id);

                const cb = document.querySelector(`input[value="${id}"]`);
                if(cb){
                    cb.checked = false;
                    cb.indeterminate = false;
                    updateParent(cb);
                }

                // ❗ สำคัญ: sync state ทั้งระบบ
                saveCheckboxState(); // หรือไม่ใช้เลยก็ได้ถ้าต้องการ strict

                renderTags();
                updateChart();
                renderLiveBoard(); // <- refresh Live Board
            };

            document.getElementById('tagSearchArea').insertBefore(tag, searchBox);
        });

        syncInput();
    }

    function syncInput(){
        hiddenInput.value = [...selected.keys()].join(',');
    }


    // ================= TREE =================
    // isTopLevel: top-level (device) nodes are always group nodes and never get a real
    // checkbox, even when they currently have zero children — only leaf (parameter) nodes
    // are selectable. Deciding this from hasChild alone caused a childless device to render
    // as a checkbox that silently did nothing when checked.
    function createTree(nodes, isTopLevel = false){

        const ul = document.createElement('ul');
        // console.log(nodes);
        // console.log('========================================');

        // Ensure nodes is treated as an array; convert if it's an object
        const nodesArray = Array.isArray(nodes) ? nodes : Object.values(nodes || {});

        nodesArray.forEach(node => {
            const li = document.createElement('li');
            const hasChild = node.children?.length > 0;
            const isCheckbox = !isTopLevel;
            // console.log(node.children);
            // console.log(node);



            if (hasChild) li.classList.add('has-children');
            if (node.offline) li.classList.add('device-offline');

            // Top-level (device) nodes always show the ▶ arrow for visual consistency,
            // even when they currently have no children to expand.
            const showArrow = hasChild || isTopLevel;

            li.innerHTML = `${showArrow ? '<span class="toggle">▶</span>' : '<span class="toggle"></span>'}
            <label>
                <input type="${isCheckbox ? 'checkbox' : 'hidden'}" value="${node.id}" data-text="${node.text}">
                <span class="label-text">${node.text}</span>
                ${isTopLevel ? `<span class="child-count">(${node.children?.length || 0})</span>` : ''}
                ${node.offline ? '<span class="offline-badge">ออฟไลน์</span>' : ''}
            </label>`;

            // ... rest of your code
            if(hasChild){
                // console.log(JSON.stringify(node.children));

                const child = createTree(node.children, false);
                child.style.display = "none";
                li.appendChild(child);
            }
            ul.appendChild(li);
        });

        return ul;
    }



    document.getElementById('tree')
        .appendChild(createTree(treeDatas, true));

    console.log('checkedTree =',
        localStorage.getItem('checkedTree')
    );

    console.log('meterSN =',
        localStorage.getItem('meterSN')
    );

    console.log('para =',
        localStorage.getItem('para')
    );
    
    restoreCheckboxState();
    restoreIframe();

    // เปิด sidebar อัตโนมัติบน mobile ถ้ายังไม่มีการเลือก
    if (selected.size === 0 && window.innerWidth < 768) {
        document.getElementById('sidebarCol').classList.add('sidebar-open');
        document.getElementById('burgerBtn').classList.add('open');
    }
    // ================= EXPAND =================
    document.addEventListener('click', e => {

        // อย่าไปกวนการติ๊ก checkbox ของ parameter (leaf) — ปล่อยให้ change handler จัดการ
        if (e.target.closest('input')) return;

        // กาง/หุบได้ทั้งตอนคลิกลูกศร ▶ และตอนคลิกที่ชื่ออุปกรณ์ (ทั้งแถว)
        const li = e.target.closest('li.has-children');
        if (!li) return;

        // ต้องเป็นคลิกที่แถวหัวข้อของ li ตัวนี้เอง ไม่ใช่ li ลูกที่ซ้อนลึกกว่า
        if (e.target.closest('li') !== li) return;

        const ul = li.querySelector(':scope > ul');
        if (!ul) return;

        const toggle = li.querySelector(':scope > .toggle');
        if (ul.style.display === "none") {
            ul.style.display = "block";
            if (toggle) toggle.textContent = "▼";
        } else {
            ul.style.display = "none";
            if (toggle) toggle.textContent = "▶";
        }
    });

    // ================= CHECKBOX LOGIC =================
    document.addEventListener('change', e => {

        if(e.target.type !== 'checkbox') return;

        const cb = e.target;
        const li = cb.closest('li');

        const id = cb.value;
        // const [meterSN, para] = id.split('|');
        const text = cb.dataset.text;
        const checkedData  = [
            ...document.querySelectorAll('input[type="checkbox"]:checked')
        ].map(cb => {

            const parentLi = cb.closest('ul')?.parentElement;

            const parentCheckbox = parentLi?.querySelector(
                ':scope > label input[type="checkbox"]'
            );

            return {
                parentId: parentCheckbox?.value || null,
                parentText: parentCheckbox?.dataset.text || null,
                childId: cb.value,
                childText: cb.dataset.text
            };
        });

        // console.log(checkedData);

        if(cb.checked){
            selected.set(id, text);
            // console.log(id, text);
            
        }else{
            selected.delete(id);
        }

        // child check all
        li.querySelectorAll('input[type=checkbox]')
            .forEach(child => {

                child.checked = cb.checked;
                console.log(child.dataset);
                

                if(cb.checked){
                    selected.set(child.value, child.dataset.text);
                }else{
                    selected.delete(child.value);
                }
            });

                const iframe = document.getElementById('historyFrame');
        const grouped = {};

        for (const item of checkedData) {
            const [meterSN, para] = (item.childId || "").split('|');

            if (!meterSN || !para) continue;

            if (!grouped[meterSN]) {
                grouped[meterSN] = new Set();
            }

            grouped[meterSN].add(para);
        }

        const meterSNs = Object.keys(grouped).join(',');

        const param = [...new Set(
            Object.values(grouped).flatMap(set => [...set])
        )].join(',');

        console.log("===>",param);
        localStorage.setItem('meterSN', meterSNs);
        localStorage.setItem('para', param);

        
        
        // iframe.src = `/chart/zx_historychart.php?meterSN=${encodeURIComponent(meterSNs)}&para=${encodeURIComponent(param)}`;
        // localStorage.setItem('historyFrameSrc', iframe.src);

        updateChart();
        saveCheckboxState();
        updateParent(cb);
        renderTags();
        renderLiveBoard();
    });

    // ================= PARENT STATE =================
    function updateParent(cb){

        const parentLi = cb.closest('ul')?.parentElement;
        if(!parentLi) return;

        const parent = parentLi.querySelector(':scope > label input[type=checkbox]');
        if(!parent) return;

        const children = parentLi.querySelectorAll(':scope > ul input[type=checkbox]');

        const checked = [...children].filter(c => c.checked);

        parent.indeterminate = checked.length > 0 && checked.length < children.length;
        parent.checked = checked.length === children.length;
    }

    // ================= SEARCH =================
    searchBox.addEventListener('input', function () {

        const keyword = this.value.toLowerCase();

        document.querySelectorAll('.tree .label-text').forEach(label => {
            label.innerHTML = label.textContent;
        });

        function filterNode(li) {

            const label = li.querySelector(':scope > label .label-text');
            const text = label?.textContent.toLowerCase() || '';

            const selfMatch = text.includes(keyword);

            let childMatch = false;

            const childLis = li.querySelectorAll(':scope > ul > li');

            childLis.forEach(childLi => {
                if (filterNode(childLi)) {
                    childMatch = true;
                }
            });

            const visible = !keyword || selfMatch || childMatch;

            li.style.display = visible ? '' : 'none';

            // ⭐ ถ้า Parent Match ให้แสดงลูกทั้งหมด
            if (selfMatch) {
                childLis.forEach(childLi => {
                    childLi.style.display = '';
                });

                const childUl = li.querySelector(':scope > ul');
                if (childUl) {
                    childUl.style.display = 'none';
                }
            }

            return visible;
        }

        document.querySelectorAll('.tree > ul > li').forEach(li => {
            filterNode(li);
        });

    });

    function saveCheckboxState(){
        const cleaned = [...selected.keys()];

        localStorage.setItem(
            'checkedTree',
            JSON.stringify(cleaned)
        );

        // optional debug
        console.log("saved:", cleaned);
    }

    function restoreCheckboxState(){

        let isRestoring = false;

        const checkedItems = JSON.parse(localStorage.getItem('checkedTree') || '[]');

        selected.clear();

        checkedItems.forEach(id => {
            const cb = document.querySelector(`input[value="${id}"]`);
            if(cb){
                cb.checked = true;
                selected.set(id, cb.dataset.text);
            }
        });

        renderTags();
        syncInput();

        renderLiveBoard();

    }

    function restoreIframe(){

        const meterSN     = localStorage.getItem('meterSN');
        const para        = localStorage.getItem('para');
        const start       = localStorage.getItem('dtStart')      || '';
        const end         = localStorage.getItem('dtEnd')        || '';
        const savedTick   = localStorage.getItem('activeTickMs');
        const savedPreset = localStorage.getItem('activePresetH');
        const savedLive   = localStorage.getItem('isRealtimeMode') === '1';

        if(start) $('#dtStart').val(start);
        if(end)   $('#dtEnd').val(end);

        if(savedTick)   activeTickMs = parseInt(savedTick);
        isRealtimeMode = savedLive;
        if(savedLive){
            document.querySelectorAll('.dt-preset').forEach(b => b.classList.remove('active'));
            document.querySelector('.dt-preset-live')?.classList.add('active');
        } else if(savedPreset){
            const h = parseInt(savedPreset);
            document.querySelectorAll('.dt-preset').forEach(b => {
                if(b.getAttribute('onclick').includes('setPreset('+h+',')) b.classList.add('active');
            });
        }

        if(meterSN && para){
            document.getElementById('historyFrame').src = buildIframeSrc(meterSN, para);
            showChartPlaceholder(false);
        } else {
            showChartPlaceholder(true);
        }
    }

    function getDateRange(){
        const start = document.getElementById('dtStart').value;
        const end   = document.getElementById('dtEnd').value;
        return { start, end };
    }

    function buildIframeSrc(meterSNs, param){
        const { start, end } = getDateRange();
        let src = `./chart/zx_historychart.php?meterSN=${encodeURIComponent(meterSNs)}&para=${encodeURIComponent(param)}`;
        if(start) src += `&start=${encodeURIComponent(start)}`;
        if(end)   src += `&end=${encodeURIComponent(end)}`;
        if(activeTickMs > 0) src += `&tickMs=${activeTickMs}`;
        if(isRealtimeMode) src += `&live=1`;
        src += `&_=${Date.now()}`;
        return src;
    }

    function autoResizeIframe(iframe) {
        try {
            const body = iframe.contentWindow.document.body;
            const html = iframe.contentWindow.document.documentElement;
            const h = Math.max(
                body.scrollHeight, body.offsetHeight,
                html.scrollHeight, html.offsetHeight
            );
            if (h > 100) iframe.style.height = h + 'px';
        } catch(e) {}
    }

    // tooltip click toggle สำหรับ touch device
    document.getElementById('burgerTagCount').addEventListener('click', function () {
        this.classList.toggle('show-tip');
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#burgerTagCount')) {
            document.getElementById('burgerTagCount').classList.remove('show-tip');
        }
    });

    (function setupIframeResize() {
        const iframe = document.getElementById('historyFrame');
        iframe.addEventListener('load', function () {
            autoResizeIframe(this);
            // poll สั้นๆ หลัง load เผื่อ chart render ช้า
            let tries = 0;
            const t = setInterval(() => {
                autoResizeIframe(iframe);
                if (++tries >= 10) clearInterval(t);
            }, 300);
        });
    })();

    function showChartPlaceholder(show) {
        document.getElementById('chartNoData').style.display  = show ? 'flex' : 'none';
        document.getElementById('historyFrame').style.display = show ? 'none' : 'block';
    }

    function updateChart(){

        if (!selected.size) {
            const iframe = document.getElementById('historyFrame');
            iframe.src = '';
            localStorage.removeItem('meterSN');
            localStorage.removeItem('para');
            showChartPlaceholder(true);
            return;
        }

        const grouped = {};

        for (const id of selected.keys()) {
            const [meterSN, para] = (id || "").split('|');
            if (!meterSN || !para) continue;

            if (!grouped[meterSN]) grouped[meterSN] = new Set();
            grouped[meterSN].add(para);
        }

        const meterSNs = Object.keys(grouped).join(',');

        const param = [...new Set(
            Object.values(grouped).flatMap(set => [...set])
        )].join(',');

        const iframe = document.getElementById('historyFrame');
        iframe.src = buildIframeSrc(meterSNs, param);
        showChartPlaceholder(false);

        localStorage.setItem('meterSN', meterSNs);
        localStorage.setItem('para', param);
    }

    function searchChart(){
        isRealtimeMode = false;
        localStorage.removeItem('isRealtimeMode');
        document.querySelectorAll('.dt-preset').forEach(b => b.classList.remove('active'));
        localStorage.removeItem('activePresetH');
        const { start, end } = getDateRange();
        localStorage.setItem('dtStart', start);
        localStorage.setItem('dtEnd', end);
        activeTickMs = calcTickMs(start, end);
        localStorage.setItem('activeTickMs', activeTickMs);
        updateChart();
    }

    function calcTickMs(start, end){
        if(!start || !end) return 0;
        const diffHrs = (new Date(end) - new Date(start)) / 3600000;
        if(diffHrs <=  1) return 15  * 60 * 1000;
        if(diffHrs <=  6) return 60  * 60 * 1000;
        if(diffHrs <= 12) return 120 * 60 * 1000;
        if(diffHrs <= 24) return 180 * 60 * 1000;
        return 0;
    }

    const presetTick = { 1: 15, 6: 60, 12: 120, 24: 180 }; // hours → tick minutes

    function setRealtime(btn){
        isRealtimeMode = true;
        localStorage.setItem('isRealtimeMode', '1');
        localStorage.removeItem('activePresetH');

        const now = new Date();
        const startDt = new Date(now.getTime() - REALTIME_WINDOW_MIN * 60000);
        const fmt = d => d.getFullYear()+'-'
            +String(d.getMonth()+1).padStart(2,'0')+'-'
            +String(d.getDate()).padStart(2,'0')+' '
            +String(d.getHours()).padStart(2,'0')+':'
            +String(d.getMinutes()).padStart(2,'0');

        $('#dtStart').val(fmt(startDt));
        $('#dtEnd').val(fmt(now));
        localStorage.setItem('dtStart', fmt(startDt));
        localStorage.setItem('dtEnd',   fmt(now));

        document.querySelectorAll('.dt-preset').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        activeTickMs = 60 * 1000;   // 1-minute ticks — window is short (15 min)
        localStorage.setItem('activeTickMs', activeTickMs);
        updateChart();
    }

    function setPreset(hours, btn){
        isRealtimeMode = false;
        localStorage.removeItem('isRealtimeMode');
        const now = new Date();
        const fmt = d => d.getFullYear()+'-'
            +String(d.getMonth()+1).padStart(2,'0')+'-'
            +String(d.getDate()).padStart(2,'0')+' '
            +String(d.getHours()).padStart(2,'0')+':'
            +String(d.getMinutes()).padStart(2,'0');
        let startDt, endDt;
        if(hours === 24){
            // วันนี้ 00:00 - 23:59
            startDt = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0);
            endDt   = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59);
        } else {
            startDt = new Date(now.getTime() - hours * 3600000);
            endDt   = now;
        }

        $('#dtStart').val(fmt(startDt));
        $('#dtEnd').val(fmt(endDt));
        localStorage.setItem('dtStart',       fmt(startDt));
        localStorage.setItem('dtEnd',         fmt(endDt));
        localStorage.setItem('activePresetH', hours);
        document.querySelectorAll('.dt-preset').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeTickMs = (presetTick[hours] || 15) * 60 * 1000;
        localStorage.setItem('activeTickMs', activeTickMs);
        updateChart();
    }

    function clearAllState(){
        selected.clear();

        document
            .querySelectorAll('input[type="checkbox"]')
            .forEach(cb => {
                cb.checked = false;
                cb.indeterminate = false;
            });

        localStorage.removeItem('checkedTree');
        localStorage.removeItem('meterSN');
        localStorage.removeItem('para');

        const iframe = document.getElementById('historyFrame');
        iframe.src = '';

        showChartPlaceholder(true);
        renderTags();
        renderLiveBoard();
    }

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    // แปลง Date object เป็นรูปแบบ DD/MM/YY HH:mm
    function formatDateTime(d) {
        return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${String(d.getFullYear()).slice(-2)} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
    }

    // dt ของแต่ละมิเตอร์เป็น unix timestamp (วินาที)
    function formatEpochDt(ts) {
        if (!ts) return '-';
        const d = new Date(Number(ts) * 1000);
        return isNaN(d.getTime()) ? '-' : formatDateTime(d);
    }

    // dt รวม (ชั้นเดียวกับ device_SN) เป็น string "YYYY-MM-DD HH:mm:ss"
    function formatDtString(str) {
        if (!str) return '-';
        const d = new Date(String(str).replace(' ', 'T'));
        return isNaN(d.getTime()) ? '-' : formatDateTime(d);
    }

    function renderLiveBoard() {

        const container = document.getElementById('liveBoardData');

        const lastUpdatedEl = document.getElementById('liveLastUpdated');
        if (lastUpdatedEl) {
            lastUpdatedEl.textContent = 'Last Updated: ' + formatDtString(model_arr?.dt);
        }

        if (!selected.size) {
            container.innerHTML = '<div class="live-empty"><img src="../assets/images/no-data-live.svg" width="48" height="48" alt="no data"><p>ยังไม่ได้เลือกข้อมูล</p><small>กรุณาเลือก Parameter จาก MDB List</small></div>';
            document.getElementById('livePulse').style.display = 'none';
            return;
        }

        document.getElementById('livePulse').style.display = 'flex';

        // group by meterSN
        const groups = {};
        selected.forEach((text, id) => {
            const [meterSN, para] = id.split('|');
            if (!meterSN || !para) return;
            if (!groups[meterSN]) groups[meterSN] = [];
            groups[meterSN].push({ para, text });
        });

        let html = '';

        Object.entries(groups).forEach(([meterSN, items]) => {

            const deviceName = device_arr[meterSN]?.name || meterSN;
            const models     = device_arr[meterSN]?.model;
            const units      = rgl_arr[models] || {};
            const dt         = formatEpochDt(model_arr?.data?.[meterSN]?.dt);

                html += `<div class="live-device">
                        <div class="live-device-name">${deviceName}</div>`;

            items.forEach(({ para, text }) => {
                const value = model_arr?.data?.[meterSN]?.[para] ?? '-';
                const unit  = Object.values(units).find(i => i.name === para)?.unit || '';
                const displayName = text || paramName(para);
                html += `
                    <div class="live-row">
                        <span class="live-param">${escapeLiveHtml(displayName)}</span>
                        <span class="live-val">${value}<span class="live-unit">${unit}</span></span>
                    </div>`;
            });

            html += `</div>`;
        });
        container.innerHTML = html;
    }

    function escapeLiveHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
    }

    function loadBatteryBalanceSummaries(batteryIds) {
        if (!batteryIds.length) return;

        const { start, end } = getDateRange();
        if(!selected.size){
            if(window.Swal) Swal.fire({icon:'info',title:'กรุณาเลือกข้อมูล',text:'เลือก Device และ Parameter จาก MDB List ก่อนดูย้อนหลัง'});
            return;
        }
        if(!start || !end || Number.isNaN(new Date(start).getTime()) || Number.isNaN(new Date(end).getTime())){
            if(window.Swal) Swal.fire({icon:'warning',title:'ช่วงเวลาไม่ครบ',text:'กรุณาเลือกวันและเวลาเริ่มต้น–สิ้นสุด'});
            return;
        }
        if(new Date(start).getTime() >= new Date(end).getTime()){
            if(window.Swal) Swal.fire({icon:'warning',title:'ช่วงเวลาไม่ถูกต้อง',text:'เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น'});
            return;
        }
        const key = [batteryIds.slice().sort().join(','), start || '', end || ''].join('|');
        const now = Date.now();

        const render = payload => {
            batteryIds.forEach(id => {
                const target = document.querySelector(`[data-battery-summary="${CSS.escape(id)}"]`);
                if (!target) return;
                const summary = payload?.summaries?.[id];
                if (!summary) {
                    if (target.dataset.summarySignature === 'empty') return;
                    target.innerHTML = '<div class="battery-summary-empty">ไม่พบข้อมูล Cell voltage ในช่วงที่เลือก</div>';
                    target.dataset.summarySignature = 'empty';
                    return;
                }

                const time = formatEpochDt(summary.timestamp);
                const signature = [
                    summary.timestamp,
                    summary.soc,
                    summary.deltaMv,
                    summary.recommendations?.charge?.soc,
                    summary.recommendations?.charge?.deltaMv,
                    summary.recommendations?.discharge?.soc,
                    summary.recommendations?.discharge?.deltaMv
                ].join('|');
                if (target.dataset.summarySignature === signature) return;
                const recommendationCard = (item, title, icon) => {
                    if (!item) {
                        return `<div class="battery-setting-card unavailable"><span>${icon} ${title}</span><small>ข้อมูลไม่เพียงพอ</small></div>`;
                    }
                    return `<div class="battery-setting-card">
                        <div class="battery-setting-title"><span>${icon} ${title}</span><b style="color:${escapeLiveHtml(item.color)}">${escapeLiveHtml(item.level)}</b></div>
                        <strong>${Number(item.soc).toFixed(1)}%</strong>
                        <small>Cell delta ${Number(item.deltaMv).toFixed(1)} mV · ${Number(item.power).toFixed(2)} kW</small>
                    </div>`;
                };
                const chargeCard = recommendationCard(summary.recommendations?.charge, 'Charge limit', '↑');
                const dischargeCard = recommendationCard(summary.recommendations?.discharge, 'Discharge limit', '↓');
                target.innerHTML = `
                    <div class="battery-summary-head">
                        <span>Cell balance ที่ดีที่สุด</span>
                        <b style="color:${escapeLiveHtml(summary.color)}">${escapeLiveHtml(summary.level)}</b>
                    </div>
                    <div class="battery-summary-main">
                        <div><small>ดีที่สุดที่</small><strong>SOC ${Number(summary.soc).toFixed(1)}%</strong></div>
                        <div><small>Cell delta</small><strong style="color:${escapeLiveHtml(summary.color)}">${Number(summary.deltaMv).toFixed(1)} mV</strong></div>
                    </div>
                    <div class="battery-summary-detail">
                        <span>Max ${Number(summary.cellMax).toFixed(3)} V</span>
                        <span>Min ${Number(summary.cellMin).toFixed(3)} V</span>
                        <span>${escapeLiveHtml(time)}</span>
                    </div>
                    <div class="battery-setting-label">ค่าที่แนะนำจากช่วง Cell balance ระดับดี</div>
                    <div class="battery-setting-grid">${chargeCard}${dischargeCard}</div>
                    <div class="battery-summary-advice">${escapeLiveHtml(summary.advice)}</div>`;
                target.dataset.summarySignature = signature;
            });
        };

        // Keep the calculated recommendation fixed while live values refresh.
        // Recalculate only when the selected battery or date range changes.
        if (batterySummaryCache.key === key && batterySummaryCache.data) {
            render(batterySummaryCache.data);
            return;
        }
        if (batterySummaryCache.key === key && batterySummaryCache.promise) {
            batterySummaryCache.promise.then(render);
            return;
        }

        let url = `/chart/api.php?mode=batterysummary&meterSN=${encodeURIComponent(batteryIds.join(','))}`;
        if (start) url += `&start=${encodeURIComponent(start)}`;
        if (end) url += `&end=${encodeURIComponent(end)}`;

        const request = fetch(url, { cache: 'no-store' })
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                batterySummaryCache = { key, data, time: Date.now(), promise: null };
                return data;
            })
            .catch(() => {
                batterySummaryCache.promise = null;
                return { summaries: {} };
            });

        batterySummaryCache = { key, data: null, time: now, promise: request };
        request.then(render);
    }

</script>

<style>
    
    .body{
        font-family:Arial,sans-serif;
        padding:12px;
    }

    @media (max-width: 991px) {
        .body { padding: 10px; }
        .row { --bs-gutter-x: 0.5rem; }
        .col-12, .col-lg-3, .col-lg-6 { padding-left: 4px; padding-right: 4px; }
        .topbar { padding: 6px 8px; gap: 6px; }
        .dt-field-box { padding: 5px 8px; }
        .dt-input { font-size: 13px; }
        .dt-search-btn { padding: 6px 10px; font-size: 13px; }
        .card-custom { border-radius: 8px; }
        .sidebar-header { padding: 10px 10px 8px; }
        .live-body { padding: 8px 10px; }
        .live-header { padding: 10px 12px 8px; }
        .chart-nodata-inner p { font-size: 15px; }
        .chart-nodata-inner small { font-size: 13px; }
    }

    @media (max-width: 480px) {
        .body { padding: 6px; }
        .dt-input { font-size: 12px; }
        .dt-field-box { padding: 5px 6px; }
    }
    .card-custom{
        background:white;
        border-radius:10px;
        border:1px solid #ddd;
        height:100%;
    }

    .sidebar{
        height:550px;
        display:flex;
        flex-direction:column;
        padding:0;
        overflow:hidden;
    }

    .sidebar-header{
        padding:15px 15px 10px;
        background:#fff;
        border-bottom:1px solid #e5e7eb;
        flex-shrink:0;
    }

    .sidebar-header h3{
        margin:0 0 10px;
    }

    .sidebar .tree{
        flex:1;
        overflow-y:auto;
        padding:10px 15px 15px;
        margin-top:0;
    }

    /* ===== CHART CONTAINER ===== */
    .chart-container{
        min-height:550px;
        height:auto;
        padding:0;
        overflow:visible;
    }

    /* ===== LIVE BOARD ===== */
    .live-board{
        height:550px;
        display:flex;
        flex-direction:column;
        overflow:hidden;
        padding:0;
    }
    .battery-balance-summary{margin-top:12px;padding:12px;border:1px solid #dce8e2;border-radius:12px;background:linear-gradient(145deg,#f7fcf9,#eef8f3)}
    .battery-summary-loading,.battery-summary-empty{padding:8px 2px;text-align:center;color:#718096;font-size:.78rem}
    .battery-summary-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;font-size:.76rem;color:#526577}
    .battery-summary-head span{font-weight:700;color:#294b3b}.battery-summary-head b{padding:3px 8px;border-radius:12px;background:#fff}
    .battery-summary-main{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .battery-summary-main>div{padding:9px;background:#fff;border:1px solid #e2ece7;border-radius:9px}
    .battery-summary-main small{display:block;color:#7a8b82;font-size:.68rem;margin-bottom:2px}
    .battery-summary-main strong{display:block;color:#173f2d;font-size:1rem}
    .battery-summary-detail{display:flex;flex-wrap:wrap;gap:5px 10px;margin-top:9px;color:#6b7d74;font-size:.68rem}
    .battery-setting-label{margin:11px 0 6px;color:#526577;font-size:.68rem;font-weight:700}
    .battery-setting-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}
    .battery-setting-card{padding:9px;border:1px solid #dbe7e1;border-radius:9px;background:#fff}
    .battery-setting-title{display:flex;align-items:center;justify-content:space-between;gap:4px;color:#53665c;font-size:.68rem}
    .battery-setting-title b{font-size:.62rem}.battery-setting-card>strong{display:block;margin:3px 0 1px;color:#173f2d;font-size:1.12rem}
    .battery-setting-card>small{display:block;color:#7a8b82;font-size:.62rem}.battery-setting-card.unavailable{opacity:.6}
    .battery-summary-advice{margin-top:8px;padding-top:8px;border-top:1px solid #dce8e2;color:#40594d;font-size:.72rem}

    .live-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:14px 16px 12px;
        border-bottom:1px solid #f1f5f9;
        flex-shrink:0;
    }

    .live-title{
        font-weight:600;
        font-size:15px;
        color:#64748b;
        letter-spacing:.3px;
        text-transform:uppercase;
    }

    .live-pulse{
        display:flex;
        align-items:center;
        gap:5px;
        font-size:12px;
        font-weight:500;
        color:#22c55e;
    }

    .live-pulse span{
        width:6px;
        height:6px;
        border-radius:50%;
        background:#22c55e;
        animation:liveblink 1.8s infinite;
        display:inline-block;
    }

    @keyframes liveblink{
        0%,100%{ opacity:1; }
        50%{ opacity:.3; }
    }

    .live-updated{
        font-size:11px;
        font-weight:500;
        color:#94a3b8;
        text-align:right;
        padding:8px 16px 10px;
        border-bottom:1px solid #f1f5f9;
        flex-shrink:0;
    }

    .live-body{
        flex:1;
        overflow-y:auto;
        padding:10px 14px;
    }

    .live-empty{
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        gap:8px;
        height:100%;
        /* color:#cbd5e1; */
        min-height:160px;
        text-align:center;
    }
    .live-empty p{
        margin:0;
        font-size:14px;
        font-weight:500;
        color:#9ca3af;
    }
    .live-empty small{
        font-size:12px;
        color:#cbd5e1;
    }

    .live-device{
        margin-bottom:18px;
    }

    .live-device-name{
        font-size:12px;
        font-weight:600;
        color:#000;
        text-transform:uppercase;
        letter-spacing:.5px;
        margin-bottom:8px;
        padding-left:2px;
    }

    .live-row{
        display:flex;
        align-items:baseline;
        justify-content:space-between;
        padding:8px 0;
        border-bottom:1px solid #f1f5f9;
    }

    .live-row:last-of-type{
        border-bottom:none;
    }

    .live-param{
        font-size:14px;
        color:#0d47a1;
        flex:1;
    }

    .live-val{
        font-size:22px;
        font-weight:700;
        color:#0d47a1;
        line-height:1;
        letter-spacing:-.3px;
    }

    .live-unit{
        font-size:13px;
        font-weight:400;
        color:#0d47a1;
        margin-left:4px;
    }

    .live-ts{
        font-size:12px;
        color:#cbd5e1;
        text-align:right;
        padding-top:4px;
        margin-bottom:4px;
    }

    /* ===== TOPBAR ===== */
    .topbar {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* mobile: รวมกล่องเดียว */
    .dt-range {
        display: flex;
        align-items: center;
        gap: 4px;
        flex: 1;
        flex-wrap: wrap;
    }
    /* preset group: แถวแรก = inputs+search, แถวสอง = preset (mobile) */
    .dt-range .dt-preset-group {
        flex-basis: 100%;
        border-radius: 8px;
    }

    @media (min-width: 992px) {
        .dt-range .dt-preset-group {
            flex-basis: auto;
            flex-shrink: 0;
            width: auto;
        }
        .dt-range .dt-preset-group .dt-preset {
            flex: none;
            padding: 6px 16px;
        }
    }

    .dt-field-box {
        display: flex;
        align-items: center;
        gap: 7px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 6px 12px;
        flex: 1;
        min-width: 0;
        transition: border-color .15s, box-shadow .15s;
    }

    .dt-field-box:focus-within {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,.08);
    }

    .dt-cal-icon { color: #9ca3af; flex-shrink: 0; }

    .dt-input {
        border: none;
        outline: none;
        font-size: 14px;
        color: #374151;
        background: transparent;
        width: 0;
        flex: 1;
        min-width: 0;
    }

    .dt-input::placeholder { color: #9ca3af; }

    .dt-preset-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .dt-preset-label {
        font-size: 12px;
        color: #9ca3af;
        font-weight: 500;
        white-space: nowrap;
        letter-spacing: .3px;
        text-transform: uppercase;
    }
    .dt-preset-group {
        display: flex;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .dt-preset {
        flex: 1;
        padding: 6px 0;
        font-size: 13px;
        font-weight: 600;
        border: none;
        border-right: 1px solid #e5e7eb;
        background: transparent;
        color: #6b7280;
        cursor: pointer;
        transition: background .15s, color .15s;
        line-height: 1.4;
        letter-spacing: .2px;
        text-align: center;
        white-space: nowrap;
    }
    .dt-preset:last-child { border-right: none; }
    .dt-preset:hover { background: #f3f4f6; color: #374151; }
    .dt-preset.active {
        background: #2563eb;
        color: #fff;
    }

    .dt-preset-live { display: inline-flex; align-items: center; justify-content: center; gap: 5px; }
    .dt-preset-live .live-dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: #ef4444; display: inline-block; flex-shrink: 0;
        animation: liveDotPulse 1.4s infinite;
    }
    .dt-preset-live.active .live-dot { background: #fff; }
    @keyframes liveDotPulse {
        0%, 100% { opacity: 1; }
        50%      { opacity: .35; }
    }

    /* desktop: แยกกล่อง ใช้ gap ของ topbar */
    @media (min-width: 992px) {
        .dt-range { gap: 8px; max-width: none; }
        .dt-field-box { max-width: 200px; }
    }

    .dt-arrow {
        color: #d1d5db;
        font-size: 12px;
        flex-shrink: 0;
        user-select: none;
    }

    .dt-search-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        border: none;
        background: #2563eb;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        padding: 7px 14px;
        border-radius: 8px;
        cursor: pointer;
        flex-shrink: 0;
        transition: background .15s;
    }

    .dt-search-btn:hover { background: #1d4ed8; }

    /* ===== BURGER BUTTON ===== */
    .burger-btn {
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 5px;
        width: 36px;
        height: 36px;
        background: #f1f5f9;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        cursor: pointer;
        padding: 6px 7px;
        flex-shrink: 0;
    }

    .burger-btn span {
        display: block;
        height: 2px;
        background: #475569;
        border-radius: 2px;
        transition: all .25s ease;
    }

    .burger-btn.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .burger-btn.open span:nth-child(2) { opacity: 0; }
    .burger-btn.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    .burger-count {
        background: #2563eb;
        color: #fff;
        border-radius: 12px;
        padding: 2px 8px;
        font-size: 12px;
        font-weight: 600;
        flex-shrink: 0;
        position: relative;
        cursor: pointer;
    }

    .burger-count[data-tooltip]::after {
        content: attr(data-tooltip);
        position: absolute;
        left: 0;
        top: calc(100% + 6px);
        background: #1e293b;
        color: #f8fafc;
        font-size: 12px;
        font-weight: 400;
        line-height: 1.6;
        white-space: pre;
        padding: 8px 12px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
        pointer-events: none;
        opacity: 0;
        transform: translateY(-4px);
        transition: opacity .15s, transform .15s;
        z-index: 999;
        min-width: 120px;
    }

    .burger-count[data-tooltip]:hover::after,
    .burger-count[data-tooltip].show-tip::after {
        opacity: 1;
        transform: translateY(0);
    }

    @media (max-width: 576px) {
        .dt-range { max-width: 100%; }
        .dt-search-btn { padding: 7px 10px; }
    }

    /* ===== RESPONSIVE HEIGHT ===== */
    @media (max-width: 991px) {
        #sidebarCol {
            display: none;
            width: 100%;
            margin-bottom: 8px;
        }

        #sidebarCol.sidebar-open {
            display: block;
        }

        .sidebar {
            height: auto;
            max-height: 340px;
            overflow: hidden;
        }

        .live-board {
            max-height: 400px;
            margin-top: 8px;
        }

        .col-12 { margin-bottom: 8px; }
        .col-12:last-child { margin-bottom: 0; }
    }

    @media (max-width: 991px) and (orientation: landscape) {
        .sidebar        { max-height: 260px; }
        .live-board     { max-height: 300px; }
        .chart-container{ min-height: 280px; }
        #historyFrame   { height: 280px !important; }
    }

    .tree ul{
        list-style:none;
        padding-left:20px;
    }

    .tree li{
        margin:8px 0;
    }

    .chart-nodata{
        /* flex:1; */
        display:flex;
        align-items:center;
        justify-content:center;
        min-height:480px;
        width:100%;
    }

    .chart-nodata-inner{
        text-align:center;
        color:#9ca3af;
    }

    .chart-nodata-inner p{
        margin:14px 0 6px;
        font-size:17px;
        font-weight:600;
        color:#6b7280;
    }

    .chart-nodata-inner small{
        font-size:14px;
        color:#9ca3af;
    }

    .badge-tag{
        background:#d9ebff;
        color:#0066cc;
        padding:5px 10px;
        border-radius:5px;
    }
    

    /* ===== INPUT + TAG STYLE ===== */
    .input-wrapper{
        border:1px solid #ccc;
        border-radius:6px;
        padding:5px;
        display:flex;
        align-items:center;
        gap:5px;
        min-height:42px;
    }

    .tag-search-area{
        flex:1;
        display:flex;
        flex-wrap:wrap;
        gap:5px;
        align-items:center;
    }

    .tag{
        display:flex;
        align-items:center;
        gap:5px;
        background:#eaeaea;
        border-radius:20px;
        padding:3px 8px;
        font-size:13px;
    }

    .tag button{
        border:none;
        background:none;
        color:red;
        cursor:pointer;
        font-weight:bold;
    }

    #searchBox{
        border:none;
        outline:none;
        flex:1 1 100%;
        min-width:0;
        border-radius:8px;
        /* background-color: #f5f5f5; */
        /* padding: 5px 15px 5px 15px; */
    }


    /* ===== SEARCH HIGHLIGHT ===== */
    .highlight{
        background:yellow;
    }

    
    /* ===== MODERN TREE ===== */

    .tree{
        margin-top:10px;
        border:1px solid #e5e7eb;
        background:#fff;
        padding:10px;
    }

    .tree ul{
        list-style:none;
        margin:0;
        padding-left:24px;
        position:relative;
    }

    .tree ul::before{
        display:none;
    }

    .tree li{
        position:relative;
        border-radius:6px;
        transition:.2s;
    }

    .tree li::before,
    .tree li:last-child::after{
        display:none;
    }

    .tree li:hover{
        background:#f8fafc;
    }

    .tree input[type="checkbox"]{
        accent-color:#2563eb;
        margin-right:6px;
    }

    .tree li.has-children{
        cursor:pointer;
    }

    .tree li.has-children > label .label-text{
        font-weight:500;
    }

    .tree li.device-offline > label .label-text{
        color:#9ca3af;
    }

    .tree .child-count{
        margin-left:4px;
        font-size:12px;
        color:#9ca3af;
    }

    .tree .offline-badge{
        margin-left:6px;
        font-size:11px;
        color:#9ca3af;
        border:1px solid #d1d5db;
        border-radius:4px;
        padding:0 4px;
    }
    /* ===== INPUT ===== */

    .input-wrapper{
        border:1px solid #d1d5db;
        border-radius:8px;
        padding:6px;
        display:flex;
        align-items:center;
        gap:6px;
        min-height:42px;
        background:#fff;
    }

    .input-wrapper:focus-within{
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .tag{
        display:flex;
        align-items:center;
        gap:5px;
        background:#eff6ff;
        color:#2563eb;
        border-radius:16px;
        padding:4px 10px;
        font-size:13px;
    }

    .tag button{
        border:none;
        background:none;
        color:#ef4444;
        cursor:pointer;
        padding:0;
    }

    #searchBox{
        border:none;
        outline:none;
        flex:1 1 100%;
        min-width:0;
    }
    /* ===== SEARCH ===== */

    .highlight{
        background:#fde68a;
        border-radius:2px;
        padding:0 2px;
    }

    #clearAllBtn {
        border: none;
        background: none;
        color: #9ca3af;       /* สีเทาปกติ */
        cursor: pointer;
        font-size: 14px;
        padding: 0 4px;
        line-height: 1;
        transition: color .2s;
    }

    #clearAllBtn:hover {
        color: #ef4444;       /* สีแดงตอน hover */
    }

    .cursor {
        cursor: pointer;
    }

    .xdsoft_ok_wrap {
        display: flex;
        justify-content: flex-end;
        padding: 6px 8px;
        border-top: 1px solid #e6e6e6;
        background: #fff;
        clear: both;
    }
    .xdsoft_ok_button {
        background: #1e5fbf;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 6px 18px;
        font-weight: bold;
        font-size: 12px;
        cursor: pointer;
    }
    .xdsoft_ok_button:hover {
        background: #17509e;
    }

    /* Modern dashboard surface */
    .body{max-width:1560px;margin:0 auto;padding:4px 2px 26px;font-family:Inter,"Segoe UI",Arial,sans-serif;color:#17233d}
    .db-hero{position:relative;display:flex;align-items:center;justify-content:space-between;gap:20px;overflow:hidden;margin-bottom:16px;padding:22px 25px;border-radius:18px;color:#fff;background:linear-gradient(125deg,#0758b7 0%,#1479df 62%,#26a7e8 100%);box-shadow:0 12px 30px rgba(11,91,177,.2)}
    .db-hero:after{content:"";position:absolute;right:-55px;bottom:-95px;width:230px;height:230px;border:38px solid rgba(255,255,255,.09);border-radius:50%}.db-hero-main{z-index:1;display:flex;align-items:center;gap:15px}.db-hero-icon{display:grid;place-items:center;flex:0 0 52px;width:52px;height:52px;border:1px solid rgba(255,255,255,.25);border-radius:15px;background:rgba(255,255,255,.15);font-size:23px}.db-hero h1{margin:0 0 4px;font-size:24px;font-weight:800;letter-spacing:-.3px}.db-hero p{margin:0;color:rgba(255,255,255,.82);font-size:13px}.db-hero-status{z-index:1;display:flex;align-items:center;gap:10px;min-width:174px;padding:9px 12px;border:1px solid rgba(255,255,255,.25);border-radius:12px;background:rgba(0,34,78,.2)}.db-status-dot{flex:0 0 9px;width:9px;height:9px;border-radius:50%;background:#67efae;box-shadow:0 0 0 5px rgba(103,239,174,.14);animation:dbStatusPulse 1.8s infinite}.db-hero-status strong,.db-hero-status small{display:block}.db-hero-status strong{font-size:12px}.db-hero-status small{margin-top:2px;color:rgba(255,255,255,.72);font-size:10px}@keyframes dbStatusPulse{50%{opacity:.48}}
    .topbar{margin-bottom:16px!important;padding:13px;border:1px solid #dce6f0;border-radius:15px;background:#fff;box-shadow:0 5px 18px rgba(32,61,96,.055)}.dt-range{gap:8px}.dt-field-box{min-height:42px;border:1.5px solid #c8d5e2;border-radius:10px;background:#fbfdff}.dt-field-box:hover{border-color:#91a9c1}.dt-field-box:focus-within{border-color:#1672cf;background:#fff;box-shadow:0 0 0 3px rgba(22,114,207,.12)}.dt-search-btn{min-height:42px;border-radius:10px;background:linear-gradient(135deg,#1268c7,#0879df);box-shadow:0 5px 12px rgba(18,104,199,.18)}.dt-preset-group{min-height:42px;border-color:#cbd7e3;border-radius:10px}.dt-preset{color:#58708a;font-weight:700}.dt-preset:hover{background:#edf5fd;color:#1268c7}.dt-preset.active{background:#1268c7;color:#fff}.dt-preset-live{color:#168958}.dt-preset-live .live-dot{background:#20b56d}.dt-preset-live.active{background:linear-gradient(135deg,#15985b,#20b56d);color:#fff}.dt-preset-live.active .live-dot{background:#d9ffeb;box-shadow:0 0 0 4px rgba(217,255,235,.18)}
    .dt-preset-bar:empty{display:none}.dt-preset-bar{margin-bottom:10px!important}.body>.row{--bs-gutter-x:16px;--bs-gutter-y:16px;align-items:stretch}.card-custom{border:1px solid #dce6f0;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(32,61,96,.065)}.sidebar,.live-board{height:590px}.chart-container{min-height:590px;border-color:#d7e3ee;background:linear-gradient(180deg,#fff,#fbfdff);overflow:hidden}.sidebar-header{padding:16px;border-bottom:1px solid #e7edf4;background:linear-gradient(180deg,#fff,#f9fbfd)}.sidebar-header h3{margin:0 0 12px;color:#203b59;font-size:15px;font-weight:800}.input-wrapper{min-height:42px;border:1.5px solid #c8d5e2!important;border-radius:10px!important;background:#fff!important;box-shadow:inset 0 1px 2px rgba(28,52,81,.035)}.input-wrapper:focus-within{border-color:#1672cf!important;box-shadow:0 0 0 3px rgba(22,114,207,.1)}.sidebar .tree{padding:12px}.tree>ul{padding-left:0}.tree li{margin:4px 0}.tree li>label{border-radius:8px;transition:background .15s}.tree li>label:hover{background:#edf5fd}.live-header{padding:16px;border-bottom:1px solid #e7edf4;background:linear-gradient(180deg,#fff,#f9fbfd)}.live-title{color:#203b59;font-size:14px;font-weight:800}.live-pulse{padding:5px 9px;border:1px solid #bce8cf;border-radius:999px;background:#eaf9f1;color:#138a52;font-weight:800}.live-pulse span{background:#20b56d;box-shadow:0 0 0 4px rgba(32,181,109,.12)}.live-updated{padding:7px 16px;color:#8190a3;background:#fbfdff}.live-body{padding:12px}.live-device{margin-bottom:13px;padding:11px;border:1px solid #e2eaf2;border-radius:11px;background:#fbfdff}.live-device-name{color:#49627e}.live-row{padding:9px 2px}.live-param{color:#5c7088;font-size:12px}.live-val{color:#1268c7;font-size:20px}.live-unit{color:#71869c;font-size:11px}.chart-nodata{min-height:560px}.chart-nodata-inner{padding:28px;border:1px dashed #cbd9e7;border-radius:15px;background:#f8fbfe}.chart-nodata-inner img,.live-empty img{opacity:.72}
    @media(min-width:992px){.sidebar,.live-board{height:620px}.chart-container{min-height:620px}.chart-nodata{min-height:590px}.sidebar .tree{padding:12px 20px 18px}.tree>ul>li{margin:0;padding:8px 2px;border-bottom:1px solid #e8eef5;border-radius:0;background:transparent}.tree>ul>li:last-child{border-bottom:0}.tree>ul>li:hover{background:#f7fafd}.tree>ul>li>.toggle{display:inline-grid;place-items:center;width:20px;height:20px;color:#3974aa}.tree>ul>li>label{display:inline-flex;max-width:calc(100% - 28px);align-items:center;min-height:26px;padding:2px 4px;font-size:13px}.tree>ul>li>ul{margin-top:5px;padding:4px 4px 3px 24px}.tree>ul>li>ul li{margin:2px 0;padding:5px 3px}.tree>ul>li>ul label{display:inline-flex;align-items:center;min-height:25px;font-size:12px}.sidebar-header{padding:16px 20px}.input-wrapper{min-height:44px;padding:6px 9px!important}#searchBox{min-height:29px;font-size:13px}}
    @media(max-width:991px){.body{padding:2px 0 20px}.db-hero{padding:18px}.db-hero h1{font-size:20px}.db-hero-status{min-width:155px}.topbar{padding:10px}.sidebar{height:auto}.live-board{height:auto;max-height:420px}.chart-container{min-height:390px}.chart-nodata{min-height:370px}.body>.row{--bs-gutter-x:8px;--bs-gutter-y:8px}}
    @media(max-width:620px){.db-hero{align-items:flex-start;flex-direction:column;gap:13px}.db-hero-main{align-items:flex-start}.db-hero-icon{flex-basis:44px;width:44px;height:44px}.db-hero h1{font-size:18px}.db-hero p{font-size:11px;line-height:1.45}.db-hero-status{width:100%}.topbar{border-radius:12px}.dt-range .dt-preset-group{overflow-x:auto}.dt-range .dt-preset-group .dt-preset{min-width:52px}.card-custom{border-radius:12px}}

</style>

<script src="../assets/jquery-datetimepicker/2.5.20/jquery.datetimepicker.full.min.js"></script>
<script>
    // Capture this page's own jQuery instance (with datetimepicker attached) in a
    // closure. index.php loads jQuery again further down for Bootstrap, which
    // resets window.$/window.jQuery and would otherwise break the picker.
    (function(pj){
        pj.datetimepicker.setLocale('en');

        function pad2(n){ return n < 10 ? '0' + n : '' + n; }
        function fmtYmdHi(d){
            return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
                + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
        }

        function addOkButton(box, inputSelector){
            if(pj(box).find('.xdsoft_ok_button').length) return;
            pj('<div class="xdsoft_ok_wrap"><button type="button" class="xdsoft_ok_button">OK</button></div>')
                .appendTo(box)
                .find('.xdsoft_ok_button')
                .on('mousedown click', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    var $input = pj(inputSelector);
                    var selected = $input.datetimepicker('getValue');
                    if(selected){
                        $input.val(fmtYmdHi(selected));
                    }
                    $input.datetimepicker('hide');
                });
        }

        pj('#dtStart').datetimepicker({
            format: 'Y-m-d H:i',
            step: 5,
            closeOnDateSelect: false,
            onGenerate: function(){ addOkButton(this, '#dtStart'); },
            onSelectDate: function(dp, $input) {
                const val = $input.val();
                pj('#dtEnd').datetimepicker('setOptions', { minDate: val.split(' ')[0] });
            }
        });

        pj('#dtEnd').datetimepicker({
            format: 'Y-m-d H:i',
            step: 5,
            closeOnDateSelect: false,
            onGenerate: function(){ addOkButton(this, '#dtEnd'); }
        });
    })(jQuery);
</script>
