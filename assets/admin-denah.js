/*
 * Editor Denah & Wayfinding (admin).
 * Format data = Satumap (map, kiosks, nodes, edges, booths) + `seats` (aturan nomor kursi per meja).
 * Alur: draf disimpan otomatis → "Publikasikan ke Kiosk" (versi lama dicadangkan, bisa dipulihkan).
 */
(function () {
  'use strict';

  const API = 'api/venue-admin.php';
  const CSRF = document.querySelector('meta[name=csrf-token]').content;
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

  let D = null;              // data draf
  let tool = 'select';
  let sel = null;            // { kind: node|edge|booth|kiosk, id }
  let z = 1;                 // zoom
  let last = null;           // titik terakhir saat menggambar lorong
  let hover = null;          // posisi kursor (koordinat peta)
  let drag = null;           // operasi seret yang sedang berjalan
  let draftRect = null;      // persegi meja yang sedang digambar
  let calib = null;          // { a, b } kalibrasi skala
  let placing = null;        // id meja yang sedang ditempatkan dari daftar
  let testResult = null;     // hasil uji rute
  let dirty = false;
  const undo = [];

  const svg = $('#svg'), stage = $('#stage'), img = $('#map-img'), cw = $('#cw'), panel = $('#panel');

  /* ================================================================
   * Server
   * ================================================================ */
  async function api(action, opt = {}) {
    const url = API + '?action=' + action + (opt.query || '');
    const init = { method: opt.method || 'GET', headers: { 'X-CSRF-Token': CSRF }, cache: 'no-store' };
    if (opt.json) { init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opt.json); }
    if (opt.form) { init.method = 'POST'; init.body = opt.form; }
    const res = await fetch(url, init);
    const out = await res.json().catch(() => ({ ok: false, error: 'Respons server tidak valid.' }));
    if (!out.ok) throw new Error(out.error || 'Gagal.');
    return out;
  }

  let saveTimer = null;
  function scheduleSave() {
    dirty = true;
    status('Menyimpan draf…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(async () => {
      try {
        const r = await api('save', { json: D });
        status(`Draf tersimpan ${r.savedAt} · belum dipublikasikan`, 'warn');
      } catch (e) { status('Gagal menyimpan: ' + e.message, 'err'); }
    }, 900);
  }

  function status(text, kind) {
    const s = $('#save-status');
    s.textContent = text;
    s.style.color = kind === 'err' ? '#ffb3b5' : kind === 'warn' ? '#ffd58a' : kind === 'ok' ? '#8ff0b8' : '';
  }

  function toast(msg, err) {
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'toast' + (err ? ' err' : '');
    t.hidden = false;
    clearTimeout(toast.tm);
    toast.tm = setTimeout(() => { t.hidden = true; }, err ? 5000 : 2500);
  }

  /* ================================================================
   * Perubahan data + undo
   * ================================================================ */
  function commit() {
    undo.push(JSON.stringify(D));
    if (undo.length > 80) undo.shift();
  }
  function changed() { testResult = null; scheduleSave(); renderAll(); }
  function doUndo() {
    if (!undo.length) return toast('Tidak ada yang bisa dibatalkan');
    D = JSON.parse(undo.pop());
    sel = null; last = null;
    changed();
  }

  function nextId(prefix, existing) {
    let n = 1;
    const set = new Set(existing);
    while (set.has(prefix + n)) n++;
    return prefix + n;
  }
  const nodeIds = () => Object.keys(D.nodes);

  /* ================================================================
   * Geometri
   * ================================================================ */
  function nearestEdge(p) {
    if (!D.edges.length) return null;
    return Router.nearestEdge(Router.buildGraph(D), p);
  }

  // Titik masuk meja: titik lorong terdekat dari tengah meja (seperti Satumap)
  function autoSpot(b) {
    const [x1, y1, x2, y2] = b.rect;
    const c = { x: (x1 + x2) / 2, y: (y1 + y2) / 2 };
    const ne = nearestEdge(c);
    b.spot = ne ? [Math.round(ne.q.x), Math.round(ne.q.y)] : [Math.round(c.x), Math.round(y2 + 10)];
  }

  function splitEdge(index, p) {
    const [a, b, ow] = D.edges[index];
    const id = nextId('n', nodeIds());
    D.nodes[id] = { x: Math.round(p.x), y: Math.round(p.y) };
    D.edges.splice(index, 1, ow ? [a, id, 1] : [a, id], ow ? [id, b, 1] : [id, b]);
    return id;
  }

  function hasEdge(a, b) { return D.edges.some((e) => (e[0] === a && e[1] === b) || (e[0] === b && e[1] === a)); }

  // Snap: ke titik lorong, ke lorong, lalu lurus horizontal/vertikal dari titik sebelumnya (tahan Alt untuk bebas)
  function snap(p, from, e, exclude) {
    const R = 10 / z;
    let best = null;
    for (const [id, n] of Object.entries(D.nodes)) {
      if (id === exclude) continue;
      const d = dist(p, n);
      if (d <= R && (!best || d < best.d)) best = { d, id };
    }
    if (best) return { x: D.nodes[best.id].x, y: D.nodes[best.id].y, node: best.id };

    const ne = nearestEdge(p);
    if (ne && ne.d <= R * 0.8 && !(exclude && (ne.e[0] === exclude || ne.e[1] === exclude))) {
      return { x: ne.q.x, y: ne.q.y, edge: ne.i };
    }
    let q = { x: p.x, y: p.y };
    if (from && !(e && e.altKey)) {
      const dx = p.x - from.x, dy = p.y - from.y;
      const tan = Math.tan((8 * Math.PI) / 180);
      if (Math.abs(dy) <= Math.abs(dx) * tan) q.y = from.y;
      else if (Math.abs(dx) <= Math.abs(dy) * tan) q.x = from.x;
    }
    return q;
  }

  function scaleAll(sx, sy) {
    for (const n of Object.values(D.nodes)) { n.x = Math.round(n.x * sx); n.y = Math.round(n.y * sy); }
    for (const k of D.kiosks) { k.x = Math.round(k.x * sx); k.y = Math.round(k.y * sy); }
    for (const b of D.booths) {
      if (b.rect) b.rect = [b.rect[0] * sx, b.rect[1] * sy, b.rect[2] * sx, b.rect[3] * sy].map(Math.round);
      if (b.spot) b.spot = [Math.round(b.spot[0] * sx), Math.round(b.spot[1] * sy)];
    }
    D.map.pxPerMeter = +(D.map.pxPerMeter * (sx + sy) / 2).toFixed(3);
  }

  /* ================================================================
   * Zoom & koordinat
   * ================================================================ */
  function pt(e) {
    const r = svg.getBoundingClientRect();
    return { x: (e.clientX - r.left) / z, y: (e.clientY - r.top) / z };
  }

  function setZoom(nz, anchor) {
    if (!D.map.width) return;
    nz = Math.max(0.1, Math.min(6, nz));
    const r = cw.getBoundingClientRect();
    const ax = anchor ? anchor.clientX - r.left : cw.clientWidth / 2;
    const ay = anchor ? anchor.clientY - r.top : cw.clientHeight / 2;
    const mx = (cw.scrollLeft + ax - 24) / z, my = (cw.scrollTop + ay - 24) / z;
    z = nz;
    applySize();
    cw.scrollLeft = mx * z + 24 - ax;
    cw.scrollTop = my * z + 24 - ay;
    renderMap();
  }
  function fitZoom() {
    if (!D.map.width) return;
    z = Math.max(0.1, Math.min((cw.clientWidth - 48) / D.map.width, (cw.clientHeight - 48) / D.map.height));
    applySize();
    renderMap();
  }
  function applySize() {
    stage.style.width = D.map.width * z + 'px';
    stage.style.height = D.map.height * z + 'px';
    $('#zoom-lbl').textContent = Math.round(z * 100) + '%';
  }

  /* ================================================================
   * Render peta (SVG)
   * ================================================================ */
  function el(tag, attrs, parent) {
    const e = document.createElementNS(SVG_NS, tag);
    for (const k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  }

  function renderMap() {
    const has = !!D.map.image;
    stage.hidden = !has;
    $('#empty-canvas').hidden = has;
    if (!has) return;
    if (img.getAttribute('src') !== D.map.image) img.src = D.map.image;
    svg.setAttribute('viewBox', `0 0 ${D.map.width} ${D.map.height}`);
    svg.innerHTML = '';
    const u = 1 / z; // 1 piksel layar dalam satuan peta

    // defs: panah lorong satu arah
    const defs = el('defs', {}, svg);
    const mk = el('marker', { id: 'arrow', viewBox: '0 0 10 10', refX: 5, refY: 5, markerWidth: 4, markerHeight: 4, orient: 'auto' }, defs);
    el('path', { d: 'M0 0 L10 5 L0 10 z', fill: '#bf5af2' }, mk);

    // meja
    const gB = el('g', {}, svg);
    for (const b of D.booths) {
      if (!b.rect) continue;
      const [x1, y1, x2, y2] = b.rect;
      const isSel = sel && sel.kind === 'booth' && sel.id === b.id;
      const g = el('g', { class: 'e-booth' + (isSel ? ' sel' : '') + (!b.spot ? ' pending' : ''), 'data-kind': 'booth', 'data-id': b.id }, gB);
      el('rect', { class: 'body', x: x1, y: y1, width: x2 - x1, height: y2 - y1, rx: 3 * u, 'stroke-width': 2 * u }, g);
      const fs = Math.max(9 * u, Math.min((y2 - y1) * 0.5, (x2 - x1) / Math.max(2, String(b.code || '').length) * 1.4, 26 * u));
      el('text', { x: (x1 + x2) / 2, y: (y1 + y2) / 2, 'font-size': fs, 'text-anchor': 'middle', 'dominant-baseline': 'central', 'stroke-width': 3 * u }, g).textContent = b.code || '?';
      if (b.spot) {
        el('line', { class: 'e-spotline', x1: (x1 + x2) / 2, y1: (y1 + y2) / 2, x2: b.spot[0], y2: b.spot[1], 'stroke-width': 1.5 * u, 'pointer-events': 'none' }, gB);
      }
      if (isSel) {
        el('rect', { class: 'e-handle', x: x2 - 6 * u, y: y2 - 6 * u, width: 12 * u, height: 12 * u, 'stroke-width': u, 'data-kind': 'handle', 'data-id': b.id }, gB);
      }
    }

    // lorong
    const gE = el('g', {}, svg);
    D.edges.forEach((e, i) => {
      const a = D.nodes[e[0]], b = D.nodes[e[1]];
      if (!a || !b) return;
      const isSel = sel && sel.kind === 'edge' && sel.id === i;
      const attrs = { class: 'e-edge' + (e[2] ? ' oneway' : '') + (isSel ? ' sel' : ''), x1: a.x, y1: a.y, x2: b.x, y2: b.y, 'stroke-width': 4 * u };
      el('line', attrs, gE);
      if (e[2]) {
        const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
        el('line', { x1: mx - (b.x - a.x) * 0.01, y1: my - (b.y - a.y) * 0.01, x2: mx, y2: my, stroke: '#bf5af2', 'stroke-width': 4 * u, 'marker-end': 'url(#arrow)' }, gE);
      }
      el('line', { class: 'e-hit', x1: a.x, y1: a.y, x2: b.x, y2: b.y, 'stroke-width': 12 * u, 'data-kind': 'edge', 'data-id': i }, gE);
    });

    // titik masuk meja
    for (const b of D.booths) {
      if (!b.rect || !b.spot) continue;
      el('circle', { class: 'e-spot', cx: b.spot[0], cy: b.spot[1], r: 4.5 * u, 'stroke-width': 1.5 * u, 'data-kind': 'spot', 'data-id': b.id }, svg);
    }

    // titik lorong
    for (const [id, n] of Object.entries(D.nodes)) {
      const cls = 'e-node' + (n.name ? ' named' : '') + ((sel && sel.kind === 'node' && sel.id === id) ? ' sel' : '') + (last === id ? ' last' : '');
      el('circle', { class: cls, cx: n.x, cy: n.y, r: (n.name ? 6 : 4.5) * u, 'stroke-width': 1.5 * u, 'data-kind': 'node', 'data-id': id }, svg);
      if (n.name) el('text', { class: 'e-label', x: n.x + 9 * u, y: n.y - 8 * u, 'font-size': 12 * u, 'stroke-width': 3 * u }, svg).textContent = n.name;
    }

    // kiosk
    for (const k of D.kiosks) {
      const isSel = sel && sel.kind === 'kiosk' && sel.id === k.id;
      const g = el('g', { class: 'e-kiosk' + (isSel ? ' sel' : ''), 'data-kind': 'kiosk', 'data-id': k.id }, svg);
      if (k.heading != null && k.heading !== '') {
        const r = ((Number(k.heading) - 90) * Math.PI) / 180;
        el('line', { class: 'e-heading', x1: k.x, y1: k.y, x2: k.x + Math.cos(r) * 28 * u, y2: k.y + Math.sin(r) * 28 * u, 'stroke-width': 4 * u }, g);
      }
      el('circle', { cx: k.x, cy: k.y, r: 9 * u, 'stroke-width': 3 * u }, g);
      el('text', { x: k.x + 13 * u, y: k.y + 4 * u, 'font-size': 13 * u, 'stroke-width': 3 * u }, g).textContent = k.name || k.id;
    }

    // pratinjau menggambar
    if (tool === 'aisle' && last && hover && D.nodes[last]) {
      const s = snap(hover, D.nodes[last], hover.e, last);
      el('line', { class: 'e-preview', x1: D.nodes[last].x, y1: D.nodes[last].y, x2: s.x, y2: s.y, 'stroke-width': 3 * u }, svg);
    }
    if (draftRect) {
      const { x0, y0, x1, y1 } = draftRect;
      el('rect', { class: 'e-draft-rect', x: Math.min(x0, x1), y: Math.min(y0, y1), width: Math.abs(x1 - x0), height: Math.abs(y1 - y0), 'stroke-width': 2 * u }, svg);
    }
    if (calib && calib.a) {
      const b = calib.b || hover;
      if (b) el('line', { class: 'e-calib', x1: calib.a.x, y1: calib.a.y, x2: b.x, y2: b.y, 'stroke-width': 3 * u }, svg);
    }
    if (testResult && testResult.pts) {
      const d = testResult.pts.map((p, i) => `${i ? 'L' : 'M'}${p.x} ${p.y}`).join(' ');
      el('path', { class: 'e-route', d, 'stroke-width': 6 * u }, svg);
    }
  }

  /* ================================================================
   * Interaksi kanvas
   * ================================================================ */
  const HINTS = {
    select: 'Klik untuk memilih · seret untuk memindah · seret area kosong untuk menggeser denah · scroll = zoom',
    aisle: 'Klik untuk menambah titik lorong (otomatis tersambung) · klik titik/lorong yang ada untuk menyambung · Esc / klik kanan = selesai · tahan Alt = tanpa lurus otomatis',
    table: placing ? 'Seret persegi di posisi meja yang dipilih dari daftar' : 'Seret persegi untuk membuat meja / area duduk',
    kiosk: 'Klik posisi kiosk (titik "Anda di sini")',
    calibrate: 'Klik ujung awal lalu ujung akhir sebuah benda yang ukurannya diketahui (mis. lebar ruangan)',
    delete: 'Klik titik, lorong, meja, atau kiosk untuk menghapus',
  };

  function setTool(t) {
    tool = t; last = null; draftRect = null; calib = t === 'calibrate' ? {} : null;
    if (t !== 'table') placing = null;
    document.querySelectorAll('[data-tool]').forEach((b) => b.classList.toggle('on', b.dataset.tool === t));
    cw.className = 'canvas-wrap tool-' + t;
    $('#hint').textContent = HINTS[t] || '';
    renderAll();
  }

  function hitOf(e) {
    const h = e.target.closest('[data-kind]');
    return h ? { kind: h.dataset.kind, id: h.dataset.kind === 'edge' ? Number(h.dataset.id) : h.dataset.id } : null;
  }

  function select(kind, id) { sel = kind ? { kind, id } : null; renderAll(); }

  function deleteItem(h) {
    commit();
    if (h.kind === 'node') {
      delete D.nodes[h.id];
      D.edges = D.edges.filter((e) => e[0] !== h.id && e[1] !== h.id);
      if (last === h.id) last = null;
    } else if (h.kind === 'edge') {
      D.edges.splice(h.id, 1);
    } else if (h.kind === 'booth' || h.kind === 'spot' || h.kind === 'handle') {
      const b = D.booths.find((x) => x.id === h.id);
      if (!b || !confirm(`Hapus meja "${b.code || b.id}" dari denah?`)) { undo.pop(); return; }
      D.booths = D.booths.filter((x) => x.id !== h.id);
    } else if (h.kind === 'kiosk') {
      D.kiosks = D.kiosks.filter((k) => k.id !== h.id);
    }
    sel = null;
    changed();
  }

  svg.addEventListener('contextmenu', (e) => { if (tool === 'aisle') { e.preventDefault(); last = null; renderMap(); } });

  svg.addEventListener('pointerdown', (e) => {
    if (e.button === 2) return;
    const p = pt(e);
    const h = hitOf(e);
    svg.setPointerCapture(e.pointerId);

    if (tool === 'delete') { if (h) deleteItem(h); return; }

    if (tool === 'aisle') {
      commit();
      const from = last ? D.nodes[last] : null;
      let id;
      if (h && h.kind === 'node') {
        id = h.id;
      } else {
        const s = snap(p, from, e, last);
        if (s.node) id = s.node;
        else if (s.edge !== undefined) id = splitEdge(s.edge, s);
        else { id = nextId('n', nodeIds()); D.nodes[id] = { x: Math.round(s.x), y: Math.round(s.y) }; }
      }
      if (last && last !== id && !hasEdge(last, id)) D.edges.push([last, id]);
      else if (last === id) undo.pop();
      last = id;
      changed();
      return;
    }

    if (tool === 'kiosk') {
      if (h && h.kind === 'kiosk') { select('kiosk', h.id); return; }
      commit();
      const id = nextId('kiosk-', D.kiosks.map((k) => k.id));
      D.kiosks.push({ id, name: 'Kiosk ' + id.split('-')[1], x: Math.round(p.x), y: Math.round(p.y) });
      sel = { kind: 'kiosk', id };
      changed();
      return;
    }

    if (tool === 'calibrate') {
      if (!calib.a || calib.b) calib = { a: p };
      else {
        calib.b = p;
        renderMap();
        const px = dist(calib.a, calib.b);
        const v = prompt(`Panjang garis ini ${Math.round(px)} piksel.\nBerapa meter ukuran sebenarnya?`, '10');
        const m = parseFloat(String(v || '').replace(',', '.'));
        if (m > 0) {
          commit();
          D.map.pxPerMeter = +(px / m).toFixed(3);
          toast(`Skala diatur: ${D.map.pxPerMeter} piksel per meter`);
          setTool('select');
          changed();
          return;
        }
        calib = {};
      }
      renderMap();
      return;
    }

    if (tool === 'table') {
      if (h && (h.kind === 'booth' || h.kind === 'handle')) { setTool('select'); select('booth', h.id); return; }
      draftRect = { x0: p.x, y0: p.y, x1: p.x, y1: p.y };
      return;
    }

    // tool select
    if (h) {
      const selKind = h.kind === 'spot' || h.kind === 'handle' ? 'booth' : h.kind;
      sel = { kind: selKind, id: h.id };
      if (h.kind !== 'edge') drag = { h, start: p, moved: false };
      renderAll();
    } else {
      drag = { pan: true, cx: e.clientX, cy: e.clientY, sl: cw.scrollLeft, st: cw.scrollTop, moved: false };
      svg.classList.add('panning');
    }
  });

  svg.addEventListener('pointermove', (e) => {
    const p = pt(e);
    hover = { x: p.x, y: p.y, e: { altKey: e.altKey } };

    if (drag) {
      if (drag.pan) {
        const dx = e.clientX - drag.cx, dy = e.clientY - drag.cy;
        if (Math.abs(dx) + Math.abs(dy) > 3) drag.moved = true;
        cw.scrollLeft = drag.sl - dx; cw.scrollTop = drag.st - dy;
        return;
      }
      if (!drag.moved) {
        if (dist(p, drag.start) * z < 3) return;
        drag.moved = true;
        commit();
      }
      const { kind, id } = drag.h;
      if (kind === 'node') {
        const s = snap(p, null, e, id);
        D.nodes[id].x = Math.round(s.node ? p.x : s.x);
        D.nodes[id].y = Math.round(s.node ? p.y : s.y);
      } else if (kind === 'kiosk') {
        const k = D.kiosks.find((x) => x.id === id);
        k.x = Math.round(p.x); k.y = Math.round(p.y);
      } else if (kind === 'booth') {
        const b = D.booths.find((x) => x.id === id);
        if (!drag.orig) drag.orig = { rect: b.rect.slice(), spot: b.spot ? b.spot.slice() : null };
        const dx = p.x - drag.start.x, dy = p.y - drag.start.y;
        b.rect = drag.orig.rect.map((v, i) => Math.round(v + (i % 2 ? dy : dx)));
        if (drag.orig.spot) b.spot = [Math.round(drag.orig.spot[0] + dx), Math.round(drag.orig.spot[1] + dy)];
      } else if (kind === 'handle') {
        const b = D.booths.find((x) => x.id === id);
        b.rect[2] = Math.round(Math.max(b.rect[0] + 6, p.x));
        b.rect[3] = Math.round(Math.max(b.rect[1] + 6, p.y));
      } else if (kind === 'spot') {
        const b = D.booths.find((x) => x.id === id);
        const ne = nearestEdge(p);
        const q = ne && ne.d * z < 14 ? ne.q : p; // menempel ke lorong bila dekat
        b.spot = [Math.round(q.x), Math.round(q.y)];
      }
      renderMap();
      return;
    }

    if (draftRect) { draftRect.x1 = p.x; draftRect.y1 = p.y; renderMap(); return; }
    if ((tool === 'aisle' && last) || (tool === 'calibrate' && calib && calib.a && !calib.b)) renderMap();
  });

  svg.addEventListener('pointerup', () => {
    if (drag) {
      svg.classList.remove('panning');
      if (drag.pan && !drag.moved) select(null);
      else if (drag.moved) {
        if (drag.h && drag.h.kind === 'booth' && !drag.orig?.spot) autoSpot(D.booths.find((x) => x.id === drag.h.id));
        if (drag.h && drag.h.kind === 'handle') autoSpot(D.booths.find((x) => x.id === drag.h.id));
        changed();
      }
      drag = null;
      return;
    }
    if (draftRect) {
      const { x0, y0, x1, y1 } = draftRect;
      draftRect = null;
      if (Math.abs(x1 - x0) * z < 8 || Math.abs(y1 - y0) * z < 8) { renderMap(); return; }
      commit();
      const rect = [Math.min(x0, x1), Math.min(y0, y1), Math.max(x0, x1), Math.max(y0, y1)].map(Math.round);
      let b = placing ? D.booths.find((x) => x.id === placing) : null;
      if (b) {
        b.rect = rect;
      } else {
        b = { id: nextId('meja-', D.booths.map((x) => x.id)), code: nextCode(), name: '', seats: '', rect };
        D.booths.push(b);
      }
      autoSpot(b);
      placing = nextUnplaced(b.id);
      sel = { kind: 'booth', id: b.id };
      if (!placing) $('#hint').textContent = HINTS.table;
      changed();
    }
  });

  svg.addEventListener('dblclick', () => { if (tool === 'aisle') { last = null; renderMap(); } });

  cw.addEventListener('wheel', (e) => {
    if (!D.map.image) return;
    e.preventDefault();
    setZoom(z * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e);
  }, { passive: false });

  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select')) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); doUndo(); return; }
    if (e.key === 'Escape') { last = null; draftRect = null; placing = null; if (tool === 'calibrate') calib = {}; else sel = null; renderAll(); return; }
    if ((e.key === 'Delete' || e.key === 'Backspace') && sel) { e.preventDefault(); deleteItem(sel); return; }
    const map = { v: 'select', l: 'aisle', m: 'table', k: 'kiosk', d: 'delete' };
    if (map[e.key.toLowerCase()] && !e.ctrlKey && !e.metaKey) setTool(map[e.key.toLowerCase()]);
  });

  function nextCode() {
    const used = new Set(D.booths.map((b) => String(b.code || '').toUpperCase()));
    for (let i = 0; i < 26 * 27; i++) {
      const c = i < 26 ? String.fromCharCode(65 + i) : String.fromCharCode(64 + Math.floor(i / 26)) + String.fromCharCode(65 + (i % 26));
      if (!used.has(c)) return c;
    }
    return 'M' + (D.booths.length + 1);
  }
  function nextUnplaced(exceptId) {
    const b = D.booths.find((x) => !x.rect && x.id !== exceptId);
    return b ? b.id : null;
  }

  /* ================================================================
   * Upload gambar (PDF → gambar di browser, gambar besar diperkecil)
   * ================================================================ */
  const MAX_SIDE = 4000;

  function loadScript(src) {
    return new Promise((ok, fail) => {
      const s = document.createElement('script');
      s.src = src; s.onload = ok; s.onerror = () => fail(new Error('Gagal memuat ' + src));
      document.head.appendChild(s);
    });
  }

  async function pdfToCanvas(file) {
    if (!window.pdfjsLib) {
      await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js');
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
    const pdf = await window.pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
    const page = await pdf.getPage(1);
    const v1 = page.getViewport({ scale: 1 });
    const scale = Math.min(MAX_SIDE / Math.max(v1.width, v1.height), 6);
    const vp = page.getViewport({ scale });
    const c = document.createElement('canvas');
    c.width = Math.round(vp.width); c.height = Math.round(vp.height);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
    return c;
  }

  async function imageToCanvas(file) {
    const url = URL.createObjectURL(file);
    try {
      const im = await new Promise((ok, fail) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => fail(new Error('Gambar tidak bisa dibaca.')); i.src = url; });
      const s = Math.min(1, MAX_SIDE / Math.max(im.naturalWidth, im.naturalHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(im.naturalWidth * s); c.height = Math.round(im.naturalHeight * s);
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(im, 0, 0, c.width, c.height);
      return c;
    } finally { URL.revokeObjectURL(url); }
  }

  async function uploadImage(file) {
    if (!file) return;
    try {
      status('Memproses gambar…');
      const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
      const canvas = isPdf ? await pdfToCanvas(file) : await imageToCanvas(file);
      const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.9));
      const form = new FormData();
      form.append('image', blob, 'denah.jpg');
      status('Mengunggah denah…');
      const r = await api('upload', { form });

      commit();
      const old = D.map;
      const hasData = Object.keys(D.nodes).length || D.booths.some((b) => b.rect) || D.kiosks.length;
      if (old.width && hasData && (old.width !== r.width || old.height !== r.height)) {
        const sx = r.width / old.width, sy = r.height / old.height;
        if (Math.abs(sx - sy) / sx > 0.03) {
          toast('Perbandingan ukuran gambar baru berbeda — periksa ulang posisi lorong & meja.', true);
        }
        scaleAll(sx, sy);
      }
      D.map.image = r.url; D.map.width = r.width; D.map.height = r.height;
      changed();
      fitZoom();
      toast('Denah diunggah (' + r.width + '×' + r.height + ')');
    } catch (e) {
      status('Gagal: ' + e.message, 'err');
      toast(e.message, true);
    }
  }

  /* ================================================================
   * Impor / ekspor JSON (kompatibel Satumap)
   * ================================================================ */
  async function importJson(file) {
    try {
      const data = JSON.parse(await file.text());
      for (const k of ['map', 'kiosks', 'nodes', 'edges', 'booths']) if (!data[k]) throw new Error(`Bagian '${k}' tidak ada.`);
      if (!confirm('Ganti seluruh draf dengan data dari file ini?')) return;
      commit();
      const keepImage = D.map.image && /^uploads\/venue\//.test(data.map.image || '') ? data.map.image : '';
      D = {
        map: { width: data.map.width, height: data.map.height, image: keepImage, pxPerMeter: data.map.pxPerMeter || 10, walkSpeed: data.map.walkSpeed || 1.1 },
        categories: data.categories || {},
        kiosks: data.kiosks,
        nodes: data.nodes,
        edges: data.edges,
        booths: data.booths.map((b) => Object.assign({ seats: '' }, b)),
        meta: null,
      };
      sel = null; last = null;
      changed();
      if (!D.map.image) {
        dialog(`<h3>Data diimpor</h3>
          <p>${Object.keys(D.nodes).length} titik lorong, ${D.booths.length} meja/booth, ${D.kiosks.length} kiosk.</p>
          <p>Sekarang unggah <b>gambar denah</b> yang sama (ukuran asli ${D.map.width}×${D.map.height}).
             Bila ukurannya berbeda, posisi akan diskalakan otomatis.</p>`,
          [['Pilih gambar…', () => $('#file-image').click(), 'primary'], ['Nanti', null]]);
      } else fitZoom();
    } catch (e) { toast('Impor gagal: ' + e.message, true); }
  }

  function exportJson() {
    const blob = new Blob([JSON.stringify(D, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'denah-seat-' + new Date().toISOString().slice(0, 10) + '.json';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  /* ================================================================
   * Pemeriksaan data
   * ================================================================ */
  function issues() {
    const out = [];
    if (!D.map.image) out.push(['err', 'Belum ada gambar denah.']);
    if (!D.kiosks.length) out.push(['err', 'Belum ada kiosk (titik "Anda di sini").']);
    if (!D.edges.length) out.push(['err', 'Belum ada lorong — rute tidak bisa dihitung.']);
    const unplaced = D.booths.filter((b) => !b.rect);
    if (unplaced.length) out.push(['warn', `${unplaced.length} meja belum ditempatkan di denah.`]);
    const noRule = D.booths.filter((b) => b.rect && !String(b.seats || '').trim());
    if (noRule.length) out.push(['warn', `${noRule.length} meja tanpa aturan kursi (memakai kode meja sebagai awalan, mis. kursi "${noRule[0].code}-01").`]);
    if (D.edges.length && D.kiosks.length) {
      const bad = [];
      for (const b of D.booths) {
        if (!b.rect || !b.spot) continue;
        if (D.kiosks.some((k) => !Router.compute(D, b, k))) bad.push(b.code || b.id);
      }
      if (bad.length) out.push(['err', `Tidak ada rute ke: ${bad.slice(0, 8).join(', ')}${bad.length > 8 ? '…' : ''} (periksa sambungan lorong / arah satu-arah).`]);
    }
    const nodeDeg = {};
    for (const e of D.edges) { nodeDeg[e[0]] = (nodeDeg[e[0]] || 0) + 1; nodeDeg[e[1]] = (nodeDeg[e[1]] || 0) + 1; }
    const lonely = Object.keys(D.nodes).filter((id) => !nodeDeg[id]);
    if (lonely.length) out.push(['warn', `${lonely.length} titik lorong tidak tersambung (bisa dihapus).`]);
    if (D.map.pxPerMeter === 10) out.push(['warn', 'Skala masih bawaan (10 px/m) — lakukan kalibrasi agar jarak meter akurat.']);
    return out;
  }

  /* ================================================================
   * Panel
   * ================================================================ */
  function field(label, f, value, type = 'text', extra = '') {
    return `<label class="f">${label}</label><input type="${type}" data-f="${f}" value="${esc(value)}" ${extra}>`;
  }

  function selCard() {
    if (!sel) return '';
    if (sel.kind === 'booth') {
      const b = D.booths.find((x) => x.id === sel.id);
      if (!b) return '';
      return `<div class="card"><h3>Meja / Area <span class="badge">${esc(b.id)}</span></h3>
        ${field('Kode meja (tampil di pin & petunjuk)', 'b.code', b.code || '', 'text', 'maxlength="12"')}
        ${field('Nama / keterangan', 'b.name', b.name || '', 'text', 'placeholder="mis. Meja VIP Kementerian"')}
        <label class="f">Nomor kursi di meja ini</label>
        <textarea data-f="b.seats" placeholder="A-*&#10;atau: A-01..A-10, A-15">${esc(b.seats || '')}</textarea>
        <p class="small">Pisahkan dengan koma / baris. <code>A-*</code> = semua kursi berawalan A-, <code>B-01..B-12</code> = rentang, atau nomor persis.
          Kosong = kursi berawalan <b>${esc(b.code || '?')}-</b>.</p>
        <div class="row">
          <button type="button" data-act="respot">↺ Titik masuk otomatis</button>
          <button type="button" data-act="test-booth">Uji rute ke meja ini</button>
          <button type="button" class="danger" data-act="del-sel">Hapus</button>
        </div>
        ${b.rect ? '' : '<p class="small" style="color:#ffd58a">Belum ditempatkan — pilih alat Meja lalu seret persegi di denah.</p>'}
      </div>`;
    }
    if (sel.kind === 'kiosk') {
      const k = D.kiosks.find((x) => x.id === sel.id);
      if (!k) return '';
      const h = k.heading == null || k.heading === '' ? '' : String(k.heading);
      const opts = [['', 'Tidak ditentukan'], ['0', '↑ Atas denah'], ['90', '→ Kanan denah'], ['180', '↓ Bawah denah'], ['270', '← Kiri denah']];
      const custom = h !== '' && !opts.some((o) => o[0] === h);
      return `<div class="card"><h3>Kiosk <span class="badge">${esc(k.id)}</span></h3>
        ${field('Nama lokasi', 'k.name', k.name || '', 'text', 'placeholder="mis. Pintu Masuk Utama"')}
        <label class="f">Arah hadap pengguna (saat menatap layar kiosk)</label>
        <select data-f="k.heading">${opts.map((o) => `<option value="${o[0]}" ${o[0] === h ? 'selected' : ''}>${o[1]}</option>`).join('')}
          ${custom ? `<option value="${esc(h)}" selected>${esc(h)}°</option>` : ''}</select>
        <p class="small">Dengan arah hadap, langkah pertama berbunyi "Dari layar ini, berjalan ke kiri Anda" (seperti Satumap).</p>
        <p class="small">Tiap perangkat kiosk memilih lokasinya lewat menu bawah layar kiosk, atau buka <code>index.php?kiosk=${esc(k.id)}</code>.</p>
        <div class="row"><button type="button" class="danger" data-act="del-sel">Hapus kiosk</button></div>
      </div>`;
    }
    if (sel.kind === 'node') {
      const n = D.nodes[sel.id];
      if (!n) return '';
      return `<div class="card"><h3>Titik lorong <span class="badge">${esc(sel.id)}</span></h3>
        ${field('Nama patokan (opsional)', 'n.name', n.name || '', 'text', 'placeholder="mis. Pintu Utama, Panggung"')}
        <p class="small">Titik bernama disebut di petunjuk: "Belok kiri di <b>Panggung</b>" / "Lewati <b>Pintu Utama</b>".</p>
        <div class="row"><button type="button" class="danger" data-act="del-sel">Hapus titik</button></div>
      </div>`;
    }
    if (sel.kind === 'edge') {
      const e = D.edges[sel.id];
      if (!e) return '';
      return `<div class="card"><h3>Lorong ${esc(e[0])} — ${esc(e[1])}</h3>
        <label class="f">Arah</label>
        <div class="row">
          <button type="button" data-act="dir" data-dir="both" class="${!e[2] ? 'on' : ''}">⇄ Dua arah</button>
          <button type="button" data-act="dir" data-dir="ab" class="${e[2] ? 'on' : ''}">→ Satu arah</button>
          <button type="button" data-act="dir" data-dir="ba">⇆ Balik arah</button>
        </div>
        <p class="small">Lorong satu arah (ungu) hanya boleh dilalui searah panah.</p>
        <div class="row"><button type="button" class="danger" data-act="del-sel">Hapus lorong</button></div>
      </div>`;
    }
    return '';
  }

  function renderPanel() {
    const placed = D.booths.filter((b) => b.rect).length;
    const iss = issues();
    const kioskOpts = D.kiosks.map((k) => `<option value="${esc(k.id)}">${esc(k.name || k.id)}</option>`).join('');

    panel.innerHTML = `
      <div class="card">
        <h3>Denah ${D.map.image ? `<span class="badge ok">${D.map.width}×${D.map.height}</span>` : ''}</h3>
        <div class="drop" id="drop">
          ${D.map.image ? 'Ganti gambar denah' : '<b>Unggah denah seating area</b>'}<br>
          <span class="small">Klik atau seret file ke sini · JPG / PNG / PDF</span>
        </div>
        <div class="row" style="margin-top:8px">
          <button type="button" data-act="import">Impor JSON (Satumap)</button>
          <button type="button" data-act="export" ${D.map.width ? '' : 'disabled'}>Ekspor JSON</button>
        </div>
        <div class="row" style="margin-top:4px">
          <div style="flex:1">${field('Skala (piksel per meter)', 'm.pxPerMeter', D.map.pxPerMeter, 'number', 'step="0.01" min="0.1"')}</div>
          <div style="flex:1">${field('Kecepatan jalan (m/detik)', 'm.walkSpeed', D.map.walkSpeed, 'number', 'step="0.1" min="0.3"')}</div>
        </div>
      </div>

      ${selCard()}

      <div class="card">
        <h3>Meja / Area <span class="badge">${placed}/${D.booths.length} ditempatkan</span></h3>
        <div class="row">
          <button type="button" data-act="bulk-toggle">+ Tambah banyak</button>
          <button type="button" data-act="respot-all" ${placed ? '' : 'disabled'}>↺ Titik masuk semua meja</button>
        </div>
        <div id="bulk" hidden>
          <label class="f">Satu meja per baris: <code>kode; nama; aturan kursi</code></label>
          <textarea id="bulk-text" placeholder="A; Meja VIP; A-*&#10;B; Kementerian; B-01..B-20&#10;C"></textarea>
          <div class="row" style="margin-top:6px"><button type="button" class="primary" data-act="bulk-add">Tambahkan</button></div>
        </div>
        <div class="list">
          ${D.booths.map((b) => `<div class="it ${sel && sel.kind === 'booth' && sel.id === b.id ? 'sel' : ''}" data-act="pick-booth" data-id="${esc(b.id)}">
              <span class="c">${esc(b.code || '?')}</span><span class="n">${esc(b.name || '')}</span>
              <span class="r">${esc(b.seats || '')}</span>
              ${b.rect ? '' : `<button type="button" data-act="place" data-id="${esc(b.id)}">Tempatkan</button>`}
            </div>`).join('') || '<p class="small">Belum ada meja. Pilih alat <b>▭ Meja</b> lalu seret di denah, atau gunakan "Tambah banyak".</p>'}
        </div>
      </div>

      <div class="card">
        <h3>Uji Rute</h3>
        <div class="row">
          <input type="text" id="test-seat" placeholder="Nomor kursi, mis. A-01" style="flex:1" value="${esc(testResult ? testResult.seat : '')}">
          ${D.kiosks.length > 1 ? `<select id="test-kiosk" style="width:auto">${kioskOpts}</select>` : ''}
          <button type="button" class="primary" data-act="test">Uji</button>
        </div>
        <div id="test-out">${testOut()}</div>
      </div>

      <div class="card">
        <h3>Pemeriksaan ${iss.length ? `<span class="badge ${iss.some((i) => i[0] === 'err') ? 'warn' : ''}">${iss.length}</span>` : '<span class="badge ok">siap</span>'}</h3>
        ${iss.length ? `<ul class="issues">${iss.map((i) => `<li class="${i[0]}">${esc(i[1])}</li>`).join('')}</ul>` : '<p class="small">Semua meja yang ditempatkan dapat dijangkau dari setiap kiosk.</p>'}
      </div>

      <div class="card small">
        <b>Cara pakai</b> (logika Satumap):
        <ol class="steps">
          <li>Unggah denah, lalu <b>kalibrasi skala</b> dengan alat 📏.</li>
          <li>Gambar <b>lorong</b> di tengah jalur jalan — rute hanya lewat lorong.</li>
          <li>Gambar <b>meja / area</b> dan isi aturan nomor kursinya. Titik merah = titik masuk dari lorong.</li>
          <li>Tempatkan <b>kiosk</b> (titik "Anda di sini") dan arah hadapnya.</li>
          <li>Uji rute, lalu <b>Publikasikan ke Kiosk</b>.</li>
        </ol>
      </div>`;

    const tk = $('#test-kiosk');
    if (tk && testResult && testResult.start) tk.value = testResult.start.id;
    bindDrop();
  }

  function testOut() {
    if (!testResult) return '';
    const r = testResult;
    if (r.status === 'no-table') return `<p class="small" style="color:#ffb3b5">Tidak ada meja dengan aturan yang cocok untuk kursi "${esc(r.seat)}".</p>`;
    if (r.status === 'no-kiosk') return '<p class="small" style="color:#ffb3b5">Belum ada kiosk.</p>';
    if (r.status === 'no-route') return `<p class="small" style="color:#ffb3b5">Meja <b>${esc(r.booth.code)}</b> ditemukan, tetapi tidak ada rute dari ${esc(r.start.name)}. Periksa sambungan lorong.</p>`;
    return `<p>Kursi <b>${esc(r.seat)}</b> → meja <b>${esc(r.booth.code)}</b>${r.booth.name ? ' · ' + esc(r.booth.name) : ''}</p>
      <div class="stat"><div><b>± ${r.meters} m</b><br><span class="small">jarak</span></div><div><b>± ${r.minutes} menit</b><br><span class="small">jalan kaki</span></div></div>
      <ol class="steps">${r.steps.map((s) => `<li>${s.text}</li>`).join('')}</ol>`;
  }

  function bindDrop() {
    const drop = $('#drop');
    drop.onclick = () => $('#file-image').click();
    drop.ondragover = (e) => { e.preventDefault(); drop.classList.add('over'); };
    drop.ondragleave = () => drop.classList.remove('over');
    drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('over'); uploadImage(e.dataTransfer.files[0]); };
  }

  // input panel → data
  panel.addEventListener('focusin', (e) => { if (e.target.dataset.f) { commit(); e.target.dataset.committed = '1'; } });
  panel.addEventListener('input', (e) => {
    const f = e.target.dataset.f;
    if (!f) return;
    const v = e.target.value;
    const [scope, key] = f.split('.');
    if (scope === 'b') { const b = D.booths.find((x) => x.id === sel.id); b[key] = key === 'code' ? v.trim() : v; }
    if (scope === 'k') {
      const k = D.kiosks.find((x) => x.id === sel.id);
      if (key === 'heading') { if (v === '') delete k.heading; else k.heading = Number(v); } else k[key] = v;
    }
    if (scope === 'n') { const n = D.nodes[sel.id]; if (v.trim()) n.name = v; else delete n.name; }
    if (scope === 'm') { const num = parseFloat(v); if (num > 0) D.map[key] = num; }
    testResult = null;
    scheduleSave();
    renderMap();
    if (e.target.tagName === 'SELECT') renderAll();
  });
  panel.addEventListener('change', (e) => { if (e.target.dataset.f) renderAll(); });

  panel.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    if (act === 'pick-booth') {
      if (e.target.closest('button')) return;
      sel = { kind: 'booth', id: b.dataset.id };
      const bo = D.booths.find((x) => x.id === b.dataset.id);
      if (bo && bo.rect) scrollToPoint((bo.rect[0] + bo.rect[2]) / 2, (bo.rect[1] + bo.rect[3]) / 2);
      renderAll();
    } else if (act === 'place') {
      setTool('table'); placing = b.dataset.id; sel = { kind: 'booth', id: placing };
      $('#hint').textContent = HINTS.table; renderAll();
    } else if (act === 'respot') {
      commit(); autoSpot(D.booths.find((x) => x.id === sel.id)); changed();
    } else if (act === 'respot-all') {
      commit(); D.booths.forEach((x) => x.rect && autoSpot(x)); changed(); toast('Titik masuk semua meja dihitung ulang');
    } else if (act === 'del-sel') {
      deleteItem(sel);
    } else if (act === 'dir') {
      commit();
      const ed = D.edges[sel.id];
      if (b.dataset.dir === 'both') D.edges[sel.id] = [ed[0], ed[1]];
      else if (b.dataset.dir === 'ab') D.edges[sel.id] = [ed[0], ed[1], 1];
      else D.edges[sel.id] = [ed[1], ed[0], 1];
      changed();
    } else if (act === 'bulk-toggle') {
      $('#bulk').hidden = !$('#bulk').hidden;
    } else if (act === 'bulk-add') {
      const lines = $('#bulk-text').value.split('\n').map((l) => l.trim()).filter(Boolean);
      if (!lines.length) return;
      commit();
      for (const l of lines) {
        const [code, name = '', seats = ''] = l.split(/\s*[;\t]\s*/);
        D.booths.push({ id: nextId('meja-', D.booths.map((x) => x.id)), code: (code || nextCode()).trim(), name: name.trim(), seats: seats.trim() });
      }
      placing = nextUnplaced();
      if (placing) setTool('table');
      if (placing) placing = nextUnplaced();
      changed();
      toast(`${lines.length} meja ditambahkan — seret persegi di denah untuk menempatkannya satu per satu`);
    } else if (act === 'test' || act === 'test-booth') {
      runTest(act === 'test-booth');
    } else if (act === 'import') {
      $('#file-json').click();
    } else if (act === 'export') {
      exportJson();
    }
  });
  panel.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.id === 'test-seat') runTest(); });

  function runTest(forBooth) {
    let seat = ($('#test-seat') || {}).value || '';
    if (forBooth && sel && sel.kind === 'booth') {
      const b = D.booths.find((x) => x.id === sel.id);
      const first = String(b.seats || '').split(/[,;\n]/)[0].trim().replace('*', '01').split('..')[0];
      seat = first || (b.code + '-01');
    }
    if (!seat.trim()) return toast('Isi nomor kursi untuk diuji', true);
    const kid = ($('#test-kiosk') || {}).value;
    const kiosk = D.kiosks.find((k) => k.id === kid) || D.kiosks[0];
    testResult = Object.assign({ seat: seat.trim() }, Wayfinding.plan(D, seat, kiosk));
    if (testResult.booth) sel = { kind: 'booth', id: testResult.booth.id };
    renderAll();
  }

  function scrollToPoint(x, y) {
    cw.scrollLeft = x * z + 24 - cw.clientWidth / 2;
    cw.scrollTop = y * z + 24 - cw.clientHeight / 2;
  }

  /* ================================================================
   * Publikasi & riwayat
   * ================================================================ */
  const dlg = $('#dlg');
  function dialog(html, buttons) {
    dlg.innerHTML = html + `<div class="row">${buttons.map((b, i) => `<button type="button" data-i="${i}" class="${b[2] || ''}">${b[0]}</button>`).join('')}</div>`;
    dlg.querySelectorAll('[data-i]').forEach((btn) => btn.onclick = () => { dlg.close(); const fn = buttons[btn.dataset.i][1]; if (fn) fn(); });
    dlg.showModal();
  }

  function openPublish() {
    const iss = issues();
    const errs = iss.filter((i) => i[0] === 'err');
    dialog(`<h3>Publikasikan ke kiosk?</h3>
      <p>${D.booths.filter((b) => b.rect).length} meja · ${D.edges.length} lorong · ${D.kiosks.length} kiosk.
         Semua kiosk memuat denah baru dalam ±3 detik. Versi yang sedang tayang dicadangkan otomatis.</p>
      ${iss.length ? `<ul class="issues">${iss.map((i) => `<li class="${i[0]}">${esc(i[1])}</li>`).join('')}</ul>` : ''}
      ${errs.length ? '<p class="small">Masih ada masalah di atas — tetap bisa dipublikasikan, tetapi sebagian rute mungkin tidak tampil.</p>' : ''}`,
      [['Batal', null], ['Publikasikan', publish, 'primary']]);
  }

  async function publish() {
    try {
      clearTimeout(saveTimer);
      const r = await api('publish', { json: D });
      D.meta = r.meta;
      dirty = false;
      status('Dipublikasikan ' + r.publishedAt, 'ok');
      toast('Denah dipublikasikan ke kiosk');
    } catch (e) { toast(e.message, true); }
  }

  async function openHistory() {
    try {
      const r = await api('backups');
      if (!r.items.length) return dialog('<h3>Riwayat</h3><p>Belum ada riwayat. Riwayat dibuat setiap kali denah dipublikasikan ulang.</p>', [['Tutup', null]]);
      dialog(`<h3>Riwayat publikasi</h3><p class="small">Memulihkan akan mengganti draf (belum langsung tayang).</p>
        <div class="list">${r.items.map((it) => `<div class="it" data-file="${esc(it.file)}"><span class="n">${esc(it.time)}</span><span class="badge">${it.tables} meja</span><button type="button" data-restore="${esc(it.file)}">Pulihkan</button></div>`).join('')}</div>`,
        [['Tutup', null]]);
      dlg.querySelectorAll('[data-restore]').forEach((b) => b.onclick = async () => {
        if (!confirm('Pulihkan versi ini ke draf?')) return;
        try {
          const res = await api('restore', { method: 'POST', query: '&file=' + encodeURIComponent(b.dataset.restore) });
          commit();
          D = res.data; sel = null;
          dlg.close();
          changed(); fitZoom();
          toast('Versi dipulihkan ke draf — publikasikan bila sudah sesuai');
        } catch (e) { toast(e.message, true); }
      });
    } catch (e) { toast(e.message, true); }
  }

  /* ================================================================
   * Tombol header & toolbar
   * ================================================================ */
  document.querySelector('.ed-head').addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    if (b.dataset.act === 'undo') doUndo();
    if (b.dataset.act === 'publish') openPublish();
    if (b.dataset.act === 'history') openHistory();
  });
  $('#tools').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.tool) {
      if (!D.map.image && b.dataset.tool !== 'select') return toast('Unggah gambar denah terlebih dahulu', true);
      setTool(b.dataset.tool);
    }
    if (b.dataset.act === 'zoom-in') setZoom(z * 1.25);
    if (b.dataset.act === 'zoom-out') setZoom(z / 1.25);
    if (b.dataset.act === 'zoom-fit') fitZoom();
  });
  $('#file-image').addEventListener('change', (e) => { uploadImage(e.target.files[0]); e.target.value = ''; });
  $('#file-json').addEventListener('change', (e) => { if (e.target.files[0]) importJson(e.target.files[0]); e.target.value = ''; });
  window.addEventListener('beforeunload', (e) => { if (saveTimer && dirty) { e.preventDefault(); e.returnValue = ''; } });

  function renderAll() { renderMap(); renderPanel(); }

  /* ================================================================
   * Mulai
   * ================================================================ */
  (async function init() {
    try {
      const r = await api('draft');
      D = r.data;
      if (Array.isArray(D.nodes)) D.nodes = {};
      D.booths.forEach((b) => { if (b.seats === undefined) b.seats = ''; });
      status(r.live ? 'Draf dimuat · versi tayang ' + new Date(r.live * 1000).toLocaleString('id-ID') : 'Draf dimuat · belum pernah dipublikasikan', r.live ? '' : 'warn');
      setTool('select');
      if (D.map.image) { applySize(); requestAnimationFrame(fitZoom); }
    } catch (e) {
      status(e.message, 'err');
      if (/login/i.test(e.message)) location.href = 'settings.php?next=admin-denah.php';
    }
  })();
})();
