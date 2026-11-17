// Pencarian rute & petunjuk teks — dipakai halaman pengunjung (app.js) dan admin (admin.js).
// Format data mengikuti data/map.json.
// Lorong (edges): [a, b] = dua arah, [a, b, 1] = satu arah, hanya boleh dilalui dari a ke b.
const Router = (() => {
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));

  function buildGraph(data) {
    const nodes = {};
    for (const [id, n] of Object.entries(data.nodes)) nodes[id] = { x: n.x, y: n.y, name: n.name };
    return { nodes, edges: data.edges.map((e) => e.slice()), seq: 0 };
  }

  function project(p, a, b) {
    const dx = b.x - a.x, dy = b.y - a.y;
    const L = dx * dx + dy * dy;
    let t = L ? ((p.x - a.x) * dx + (p.y - a.y) * dy) / L : 0;
    t = Math.max(0, Math.min(1, t));
    return { x: a.x + t * dx, y: a.y + t * dy, t };
  }

  // Titik lorong terdekat dari p: { q, d, i (index edge) } atau null bila belum ada lorong
  function nearestEdge(g, p) {
    let best = null;
    g.edges.forEach((e, i) => {
      const a = g.nodes[e[0]], b = g.nodes[e[1]];
      if (!a || !b) return;
      const q = project(p, a, b);
      const d = dist(p, q);
      if (!best || d < best.d) best = { i, e, q, d };
    });
    return best;
  }

  // Tempelkan titik bebas ke lorong terdekat (memecah edge bila perlu)
  function attach(g, p) {
    const best = nearestEdge(g, p);
    if (!best) return null;
    const [a, b, oneWay] = best.e;
    if (dist(best.q, g.nodes[a]) < 1) return a;
    if (dist(best.q, g.nodes[b]) < 1) return b;
    const id = '_p' + g.seq++;
    g.nodes[id] = { x: best.q.x, y: best.q.y };
    // potongan lorong mewarisi arahnya
    g.edges.splice(best.i, 1, oneWay ? [a, id, 1] : [a, id], oneWay ? [id, b, 1] : [id, b]);
    return id;
  }

  function shortest(g, s, t) {
    const adj = {};
    for (const id in g.nodes) adj[id] = [];
    for (const [a, b, oneWay] of g.edges) {
      if (!adj[a] || !adj[b]) continue;
      const w = dist(g.nodes[a], g.nodes[b]);
      adj[a].push([b, w]);
      if (!oneWay) adj[b].push([a, w]);
    }
    const D = {}, prev = {}, done = new Set();
    for (const id in g.nodes) D[id] = Infinity;
    D[s] = 0;
    for (;;) {
      let u = null;
      for (const id in D) if (!done.has(id) && (u === null || D[id] < D[u])) u = id;
      if (u === null || D[u] === Infinity || u === t) break;
      done.add(u);
      for (const [v, w] of adj[u]) if (D[u] + w < D[v]) { D[v] = D[u] + w; prev[v] = u; }
    }
    const path = [];
    for (let u = t; u !== undefined; u = prev[u]) path.unshift(u);
    return path[0] === s ? path : null;
  }

  // start: { name, x, y } (kiosk) ; booth: item dari data.booths
  function compute(data, booth, start) {
    if (!booth.rect || !booth.spot) return null; // booth belum ditempatkan di peta
    const g = buildGraph(data);
    const sId = attach(g, { x: start.x, y: start.y });
    const tId = attach(g, { x: booth.spot[0], y: booth.spot[1] });
    if (sId === null || tId === null) return null;
    const ids = shortest(g, sId, tId);
    if (!ids) return null;

    const pts = ids.map((id) => ({ ...g.nodes[id] }));
    pts[0].name = start.name;

    // Titik masuk = sisi booth terdekat dari lorong
    const last = pts[pts.length - 1];
    const [x1, y1, x2, y2] = booth.rect;
    pts.push({ x: Math.min(Math.max(last.x, x1), x2), y: Math.min(Math.max(last.y, y1), y2) });

    return pts.filter((p, i) => i === 0 || dist(p, pts[i - 1]) > 0.5);
  }

  const length = (pts) => pts.reduce((s, p, i) => s + (i ? dist(pts[i - 1], p) : 0), 0);
  const meters = (data, px) => Math.max(1, Math.round(px / data.map.pxPerMeter));

  // kode booth yang layak disebut ke pengunjung (bukan placeholder "?")
  const goodCode = (c) => !!c && !/^\?+$/.test(String(c).trim());

  // nama booth untuk petunjuk:
  //  - patokan : "B3-K01 · Dr. Chung's Food" (kode dulu, mudah dicocokkan dengan papan booth)
  //  - tujuan  : "Toyota (A)"
  function boothLabel(b, arrive = false) {
    const short = b.name && b.name.length > 26 ? b.name.slice(0, 25) + '…' : b.name;
    if (!goodCode(b.code) || !b.name || b.name === b.code) return goodCode(b.code) ? b.code : b.name;
    return arrive ? `${b.name} (${b.code})` : `${b.code} · ${short}`;
  }

  // booth terdekat (selain tujuan) dalam ±4 m dari titik belok, sebagai patokan yang terlihat
  function landmark(data, p, exclude) {
    const max = data.map.pxPerMeter * 4;
    let best = null;
    for (const b of data.booths) {
      if (!b.rect || b === exclude) continue;
      const [x1, y1, x2, y2] = b.rect;
      const d = Math.hypot(Math.max(x1 - p.x, 0, p.x - x2), Math.max(y1 - p.y, 0, p.y - y2));
      if (d <= max && (!best || d < best.d)) best = { b, d };
    }
    return best && best.b;
  }

  // arah kompas sebuah vektor: derajat searah jarum jam dari "atas peta"
  const bearing = (v) => ((Math.atan2(v.x, -v.y) * 180) / Math.PI + 360) % 360;

  /**
   * Petunjuk arah ringkas.
   *  - "jalan X m" dan "belok" digabung menjadi satu langkah;
   *  - belokan zig-zag kecil (< 2,5 m) dianggap lurus;
   *  - patokan: titik bernama (pintu, stage) lalu booth terdekat (pakai kode booth);
   *  - kiosk dengan arah hadap (heading) -> langkah pertama relatif terhadap layar.
   * opt: { t: (key, vars) => teks terjemahan, heading: derajat | null }
   * Hasil: array langkah { icon, text }, dengan properti .landmarks = id booth yang disebut.
   */
  function directions(data, pts, booth, start, opt = {}) {
    const t = opt.t || ((k, v) => (typeof I18N !== 'undefined' ? I18N.t('id', k, v) : k));
    const ppm = data.map.pxPerMeter;
    const m = (px) => Math.max(1, Math.round(px / ppm));
    const steps = [];
    const used = new Set();

    // bagian lorong = semua titik kecuali titik masuk ke booth
    const path = pts.slice(0, Math.max(2, pts.length - 1));
    const segLen = (i, j) => { let s = 0; for (let k = i; k < j; k++) s += dist(path[k], path[k + 1]); return s; };

    // 1) belokan
    let turns = [];
    for (let i = 1; i < path.length - 1; i++) {
      const a = path[i - 1], b = path[i], c = path[i + 1];
      const d1 = { x: b.x - a.x, y: b.y - a.y }, d2 = { x: c.x - b.x, y: c.y - b.y };
      const cross = d1.x * d2.y - d1.y * d2.x, dot = d1.x * d2.x + d1.y * d2.y;
      const angle = (Math.abs(Math.atan2(cross, dot)) * 180) / Math.PI;
      if (angle > 30) turns.push({ i, dir: cross > 0 ? 'right' : 'left' });
      else if (b.name) turns.push({ i, pass: true });
    }
    // 2) zig-zag kecil: dua belokan berlawanan yang berjarak < 2,5 m dianggap jalan lurus
    for (let k = 0; k < turns.length - 1; k++) {
      const a = turns[k], b = turns[k + 1];
      if (!a.pass && !b.pass && a.dir !== b.dir && segLen(a.i, b.i) < ppm * 2.5 && !path[a.i].name && !path[b.i].name) {
        turns.splice(k, 2);
        k--;
      }
    }

    // 3) langkah pertama
    const first = { x: path[1].x - path[0].x, y: path[1].y - path[0].y };
    if (opt.heading != null && Math.hypot(first.x, first.y) > 0.5) {
      const rel = ((bearing(first) - opt.heading + 540) % 360) - 180; // -180..180
      const key = Math.abs(rel) <= 45 ? 'relAhead' : Math.abs(rel) >= 135 ? 'relBack' : rel > 0 ? 'relRight' : 'relLeft';
      steps.push({ icon: 'start', text: t('dStartRel', { rel: t(key) }) });
    } else {
      steps.push({ icon: 'start', text: t('dStart', { name: esc(start.name) }) });
    }

    // 4) jalan + belok
    let from = 0;
    for (const tr of turns) {
      const walked = segLen(from, tr.i);
      const node = path[tr.i];
      if (tr.pass) {
        steps.push({ icon: 'straight', text: t('dPass', { name: esc(node.name) }) });
        from = tr.i;
        continue;
      }
      let where = '';
      if (node.name) where = t('atPlace', { name: esc(node.name) });
      else {
        const lm = landmark(data, node, booth);
        if (lm) { where = t('nearPlace', { name: esc(boothLabel(lm)) }); used.add(lm.id); }
      }
      const dir = t(tr.dir);
      steps.push({
        icon: tr.dir === 'right' ? 'kanan' : 'kiri',
        text: walked >= ppm * 1.5 ? t('dWalkTurn', { m: m(walked), dir, where }) : t('dTurn', { dir, where })
      });
      from = tr.i;
    }

    // 5) sisa jalan & tiba
    const rest = segLen(from, path.length - 1);
    if (rest >= ppm * 1.5) steps.push({ icon: 'straight', text: t('dWalk', { m: m(rest) }) });

    let side = '';
    const n = pts.length;
    if (n >= 3) {
      const a = pts[n - 3], b = pts[n - 2], c = pts[n - 1];
      const cross = (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x);
      if (Math.abs(cross) > 1) side = t(cross > 0 ? 'sideRight' : 'sideLeft');
    }
    steps.push({ icon: 'finish', text: t('dArrive', { name: esc(boothLabel(booth, true)) }) + side });
    steps.landmarks = [...used];
    return steps;
  }

  return { esc, dist, project, buildGraph, nearestEdge, compute, directions, length, meters, boothLabel, goodCode, bearing };
})();
