<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['username'])) {
    header('Location: authentication-login.php');
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Drag & Drop Demo</title>
  <style>
    :root{color-scheme:light dark;--bg:#eef3f8;--panel:#fff;--text:#18324a;--muted:#667b8f;--line:#d8e3ed;--blue:#1769d2;--shadow:0 10px 28px rgba(25,55,85,.09)}
    @media(prefers-color-scheme:dark){:root{--bg:#0d1826;--panel:#162538;--text:#e8f3ff;--muted:#9db0c2;--line:#29415a;--shadow:0 12px 30px rgba(0,0,0,.25)}}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,"Noto Sans Thai",sans-serif}.wrap{max-width:1050px;margin:auto;padding:28px 18px}.head{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}.head h1{margin:0;font-size:1.25rem}.head p{margin:5px 0 0;color:var(--muted);font-size:.86rem}.actions{display:flex;gap:8px}.actions a,.actions button{border:1px solid var(--line);border-radius:9px;padding:9px 13px;background:var(--panel);color:var(--text);text-decoration:none;cursor:pointer}.actions button{background:var(--blue);border-color:var(--blue);color:#fff}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.card{position:relative;min-height:155px;padding:17px;border:1px solid var(--line);border-radius:14px;background:var(--panel);box-shadow:var(--shadow);touch-action:none;user-select:none;cursor:grab;transition:transform .15s,border-color .15s,opacity .15s}.card:active{cursor:grabbing}.card.dragging{opacity:.35;transform:scale(.97)}.card.over{border-color:var(--blue);box-shadow:0 0 0 3px rgba(23,105,210,.16)}.handle{position:absolute;right:13px;top:12px;color:var(--muted);font-size:1.15rem}.icon{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:#e8f2ff;color:var(--blue);font-size:1.1rem}.card h2{margin:12px 0 4px;font-size:.95rem}.card p{margin:0;color:var(--muted);font-size:.76rem}.status{margin-top:16px;color:var(--muted);font-size:.76rem}.status.saved{color:#168958}@media(max-width:700px){.head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr 1fr}}@media(max-width:440px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<main class="wrap">
  <header class="head">
    <div><h1>ตัวอย่าง Drag & Drop Dashboard</h1><p>กดค้างที่การ์ดแล้วลากไปวางตำแหน่งใหม่ ระบบจะจำลำดับอัตโนมัติ</p></div>
    <div class="actions"><a href="index.php?page=energy">กลับ Dashboard</a><button type="button" id="reset">คืนค่าเริ่มต้น</button></div>
  </header>
  <section class="grid" id="dashboard">
    <article class="card" data-id="power"><span class="handle">⠿</span><span class="icon">⚡</span><h2>กำลังไฟรวม</h2><p>125.4 kW</p></article>
    <article class="card" data-id="energy"><span class="handle">⠿</span><span class="icon">▥</span><h2>พลังงานวันนี้</h2><p>842.7 kWh</p></article>
    <article class="card" data-id="chiller"><span class="handle">⠿</span><span class="icon">❄</span><h2>Chiller</h2><p>3 เครื่องกำลังทำงาน</p></article>
    <article class="card" data-id="temperature"><span class="handle">⠿</span><span class="icon">℃</span><h2>อุณหภูมิน้ำเย็น</h2><p>7.2 °C</p></article>
    <article class="card" data-id="cop"><span class="handle">⠿</span><span class="icon">↗</span><h2>System COP</h2><p>4.86</p></article>
    <article class="card" data-id="alarm"><span class="handle">⠿</span><span class="icon">!</span><h2>Alarm</h2><p>ไม่มี Alarm</p></article>
  </section>
  <div class="status" id="status">พร้อมจัดเรียง</div>
</main>
<script>
(() => {
  const board = document.getElementById('dashboard');
  const status = document.getElementById('status');
  const key = 'mdbiot-drag-drop-demo-v1';
  let dragged = null;

  function save() {
    localStorage.setItem(key, JSON.stringify([...board.children].map(card => card.dataset.id)));
    status.textContent = 'บันทึกลำดับแล้ว';
    status.classList.add('saved');
  }
  function restore() {
    try {
      const order = JSON.parse(localStorage.getItem(key) || '[]');
      order.forEach(id => { const card = board.querySelector(`[data-id="${id}"]`); if (card) board.appendChild(card); });
    } catch (_) {}
  }
  function insertAtPoint(x, y) {
    const target = document.elementsFromPoint(x, y)
      .map(element => element.closest('.card'))
      .find(card => card && card !== dragged);
    board.querySelectorAll('.card').forEach(card => card.classList.remove('over'));
    if (!target || target === dragged || !dragged) return;
    target.classList.add('over');
    const box = target.getBoundingClientRect();
    const before = y < box.top + box.height / 2 || (Math.abs(y - (box.top + box.height / 2)) < box.height / 3 && x < box.left + box.width / 2);
    board.insertBefore(dragged, before ? target : target.nextSibling);
  }
  board.querySelectorAll('.card').forEach(card => {
    card.draggable = true;
    card.addEventListener('dragstart', () => { dragged = card; requestAnimationFrame(() => card.classList.add('dragging')); });
    card.addEventListener('dragend', () => { card.classList.remove('dragging'); board.querySelectorAll('.card').forEach(item => item.classList.remove('over')); dragged = null; save(); });
    card.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse') return;
      dragged = card; card.setPointerCapture(event.pointerId); card.classList.add('dragging');
    });
    card.addEventListener('pointermove', event => { if (dragged === card && event.pointerType !== 'mouse') insertAtPoint(event.clientX, event.clientY); });
    card.addEventListener('pointerup', event => { if (dragged !== card || event.pointerType === 'mouse') return; card.classList.remove('dragging'); board.querySelectorAll('.card').forEach(item => item.classList.remove('over')); dragged = null; save(); });
  });
  board.addEventListener('dragover', event => { event.preventDefault(); insertAtPoint(event.clientX, event.clientY); });
  document.getElementById('reset').addEventListener('click', () => { localStorage.removeItem(key); location.reload(); });
  restore();
})();
</script>
</body>
</html>
