/*
 * Wayfinding Seat PTBI — rute dari kiosk ke meja peserta.
 * Perhitungan rute & petunjuk teks memakai Router (assets/router.js, sama persis dengan Satumap).
 * Tampilan rute (spotlight, garis digambar, panah berjalan, pin) mengikuti gaya Satumap.
 */
window.Wayfinding = (function () {
  'use strict';

  const SVG_NS = 'http://www.w3.org/2000/svg';

  // Teks petunjuk (Bahasa Indonesia, dari i18n Satumap)
  const DICT = {
    youAreHere: 'Anda di sini',
    dStart: 'Mulai dari <b>{name}</b>',
    dStartRel: 'Dari layar ini, {rel}',
    relLeft: 'berjalan ke <b>kiri</b> Anda',
    relRight: 'berjalan ke <b>kanan</b> Anda',
    relBack: '<b>balik badan</b>, lalu berjalan lurus',
    relAhead: 'berjalan <b>lurus</b> melewati sisi kiosk',
    dWalk: 'Jalan lurus ± {m} m',
    dWalkTurn: 'Jalan ± {m} m, lalu belok <b>{dir}</b>{where}',
    dTurn: 'Belok <b>{dir}</b>{where}',
    left: 'kiri',
    right: 'kanan',
    atPlace: ' di <b>{name}</b>',
    nearPlace: ' dekat <b>{name}</b>',
    dPass: 'Lewati <b>{name}</b>',
    dArrive: 'Tiba di <b>{name}</b>',
    sideLeft: ' — di sebelah <b>kiri</b> Anda',
    sideRight: ' — di sebelah <b>kanan</b> Anda',
  };
  function t(key, vars) {
    const s = DICT[key] || key;
    return vars ? s.replace(/\{(\w+)\}/g, (m, k) => (vars[k] !== undefined ? vars[k] : m)) : s;
  }

  /* ------------------------------------------------------------------
   * Pencocokan nomor kursi → meja
   * Aturan (dipisah koma / baris):  A-01  |  A-*  |  B-01..B-12
   * Bila aturan kosong: kursi "A-07" cocok dengan meja berkode "A".
   * ------------------------------------------------------------------ */
  const norm = (s) => String(s || '').toUpperCase().replace(/\s+/g, '');

  function splitNum(s) {
    const m = /^(.*?)(\d+)$/.exec(s);
    return m ? { prefix: m[1], num: parseInt(m[2], 10) } : null;
  }

  function seatMatches(rule, seat) {
    const S = norm(seat);
    if (!S) return false;
    for (const raw of String(rule || '').split(/[,;\n]+/)) {
      const tok = norm(raw);
      if (!tok) continue;
      if (tok.includes('..')) {
        const [a, b] = tok.split('..');
        const A = splitNum(a), B = splitNum(b), X = splitNum(S);
        if (A && B && X && X.prefix === A.prefix && A.prefix === B.prefix &&
            X.num >= Math.min(A.num, B.num) && X.num <= Math.max(A.num, B.num)) return true;
      } else if (tok.includes('*')) {
        const re = new RegExp('^' + tok.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
        if (re.test(S)) return true;
      } else if (tok === S) {
        return true;
      }
    }
    return false;
  }

  /*
   * Cadangan bila meja tidak punya aturan: kode meja = awalan nomor.
   *   meja "A"   ← A01, A-01, A.01, A 01, A        (kode berakhiran huruf + angka / pemisah)
   *   meja "12"  ← 12, 12-3                        (kode angka: harus persis atau diikuti pemisah, jadi "1" ≠ "12")
   *   meja "A01" ← A01                             (persis)
   */
  function codeMatches(code, S) {
    const C = norm(code);
    if (!C || !S) return false;
    if (S === C) return true;
    if (!S.startsWith(C)) return false;
    const rest = S.slice(C.length);
    if (/^[-_./]/.test(rest)) return true;
    return /[A-Z]$/.test(C) && /^\d/.test(rest);
  }

  function findTable(data, seat) {
    const placed = (data.booths || []).filter((b) => b.rect && b.spot);
    const S = norm(seat);
    const hit = placed.find((b) => seatMatches(b.seats, S));
    if (hit) return hit;
    // kode terpanjang yang cocok menang (mis. meja "AA" dipilih untuk AA01, bukan meja "A")
    let best = null;
    for (const b of placed) {
      if (norm(b.seats) || !codeMatches(b.code, S)) continue;
      if (!best || norm(b.code).length > norm(best.code).length) best = b;
    }
    return best;
  }

  /* ------------------------------------------------------------------
   * Kiosk aktif (titik "Anda di sini") — per perangkat
   * ?kiosk=kiosk-1 di URL, atau pilihan tersimpan di perangkat
   * ------------------------------------------------------------------ */
  const KIOSK_KEY = 'seatKioskId';
  function kioskId() {
    const q = new URLSearchParams(location.search).get('kiosk');
    if (q) { try { localStorage.setItem(KIOSK_KEY, q); } catch (_) {} return q; }
    try { return localStorage.getItem(KIOSK_KEY); } catch (_) { return null; }
  }
  function setKioskId(id) { try { localStorage.setItem(KIOSK_KEY, id); } catch (_) {} }
  function currentKiosk(data) {
    const id = kioskId();
    return (data.kiosks || []).find((k) => k.id === id) || (data.kiosks || [])[0] || null;
  }

  /* ------------------------------------------------------------------
   * Hitung rute
   * ------------------------------------------------------------------ */
  function plan(data, seat, kiosk) {
    const booth = findTable(data, seat);
    if (!booth) return { status: 'no-table' };
    const start = kiosk || currentKiosk(data);
    if (!start) return { status: 'no-kiosk', booth };
    const pts = Router.compute(data, booth, start);
    if (!pts) return { status: 'no-route', booth, start };
    const heading = start.heading != null && start.heading !== '' ? Number(start.heading) : null;
    const steps = Router.directions(data, pts, booth, start, { t, heading });
    const meters = Router.meters(data, Router.length(pts));
    const minutes = Math.max(1, Math.round(meters / (data.map.walkSpeed || 1.1) / 60));
    return { status: 'ok', booth, start, pts, steps, meters, minutes };
  }

  /* ------------------------------------------------------------------
   * Render peta + rute ke dalam container
   * ------------------------------------------------------------------ */
  function el(tag, attrs, ns) {
    const e = ns === null ? document.createElement(tag) : document.createElementNS(ns || SVG_NS, tag);
    for (const k in attrs || {}) e.setAttribute(k, attrs[k]);
    return e;
  }

  function render(container, data, result) {
    const W = data.map.width, H = data.map.height;
    container.innerHTML = '';
    const stage = el('div', { class: 'wf-stage' }, null);
    stage.style.width = W + 'px';
    stage.style.height = H + 'px';

    const img = el('img', { src: data.map.image, alt: 'Denah', draggable: 'false' }, null);
    img.width = W; img.height = H;
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, class: 'wf-svg' });
    const focusLyr = el('g'), routeLyr = el('g');
    svg.append(focusLyr, routeLyr);
    const movers = el('div', { class: 'wf-movers' }, null);
    stage.append(img, svg, movers);
    container.appendChild(stage);

    const booth = result.booth;
    const [x1, y1, x2, y2] = booth.rect;
    const uid = 'spot' + Math.random().toString(36).slice(2, 7);

    // spotlight: denah tetap terbaca, meja tujuan menonjol
    const mask = el('mask', { id: uid });
    mask.appendChild(el('rect', { x: 0, y: 0, width: W, height: H, fill: '#fff' }));
    mask.appendChild(el('rect', { x: x1 - 4, y: y1 - 4, width: x2 - x1 + 8, height: y2 - y1 + 8, rx: 6, fill: '#000' }));
    focusLyr.appendChild(mask);
    focusLyr.appendChild(el('rect', { x: 0, y: 0, width: W, height: H, class: 'wf-dim', mask: `url(#${uid})` }));
    focusLyr.appendChild(el('rect', { x: x1 - 3, y: y1 - 3, width: x2 - x1 + 6, height: y2 - y1 + 6, rx: 5, class: 'wf-target' }));

    let box = [x1, y1, x2, y2];
    if (result.pts) {
      const pts = result.pts;
      const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
      const line = el('path', { d, class: 'wf-route-line' });
      routeLyr.append(el('path', { d, class: 'wf-route-shadow' }), line, el('path', { d, class: 'wf-route-ants' }));

      const len = line.getTotalLength();
      const drawDur = Math.min(2.2, Math.max(0.9, len / 450));
      const walkDur = Math.max(3.5, len / 85);
      stage.style.setProperty('--len', len);
      stage.style.setProperty('--draw', drawDur + 's');
      stage.style.setProperty('--walk', walkDur + 's');

      // panah & pejalan menyusuri rute (CSS offset-path)
      const path = `path('${d}')`;
      const group = el('div', { class: 'wf-chevs' }, null);
      const count = Math.min(14, Math.max(3, Math.round(len / 60)));
      for (let i = 0; i < count; i++) {
        const c = el('div', { class: 'wf-chev' }, null);
        c.innerHTML = '<i></i>';
        c.style.offsetPath = path;
        c.style.animationDelay = `${-(walkDur / count) * i}s`;
        group.appendChild(c);
      }
      const walker = el('div', { class: 'wf-walker' }, null);
      walker.innerHTML = '<i></i>';
      walker.style.offsetPath = path;
      group.appendChild(walker);
      movers.appendChild(group);

      // label meja patokan yang disebut di petunjuk
      for (const id of result.steps.landmarks || []) {
        const b = data.booths.find((x) => x.id === id);
        if (!b || !b.rect) continue;
        const lb = el('div', { class: 'wf-lm' }, null);
        lb.style.left = (b.rect[0] + b.rect[2]) / 2 + 'px';
        lb.style.top = (b.rect[1] + b.rect[3]) / 2 + 'px';
        lb.innerHTML = `<span>${Router.esc(Router.goodCode(b.code) ? b.code : b.name)}</span>`;
        movers.appendChild(lb);
      }

      for (const p of pts) {
        box = [Math.min(box[0], p.x), Math.min(box[1], p.y), Math.max(box[2], p.x), Math.max(box[3], p.y)];
      }
    }

    // "Anda di sini"
    if (result.start) {
      const s = result.start;
      const m = el('div', { class: 'wf-start' }, null);
      m.style.left = s.x + 'px';
      m.style.top = s.y + 'px';
      const hd = s.heading != null && s.heading !== '' ? Number(s.heading) : null;
      m.innerHTML = (hd != null ? `<span class="cone" style="--hd:${hd}deg"></span>` : '') +
        `<span class="ring"></span><span class="ring r2"></span><span class="dot"></span><span class="lbl">${t('youAreHere')}</span>`;
      movers.appendChild(m);
      box = [Math.min(box[0], s.x), Math.min(box[1], s.y), Math.max(box[2], s.x), Math.max(box[3], s.y)];
    }

    // pin meja tujuan
    const pin = el('div', { class: 'wf-pin' }, null);
    pin.style.left = (x1 + x2) / 2 + 'px';
    pin.style.top = (y1 + y2) / 2 + 'px';
    pin.innerHTML = `<span class="wf-pin-head">${Router.esc(Router.goodCode(booth.code) ? booth.code : '★')}</span><span class="wf-pin-shadow"></span>`;
    movers.appendChild(pin);

    // zoom agar seluruh rute terlihat
    function fit() {
      const cw = container.clientWidth, ch = container.clientHeight;
      if (!cw || !ch) return;
      const pad = Math.max(40, Math.min(W, H) * 0.06);
      const bx = Math.max(0, box[0] - pad), by = Math.max(0, box[1] - pad);
      const bw = Math.min(W, box[2] + pad) - bx, bh = Math.min(H, box[3] + pad) - by;
      const fitAll = Math.min(cw / W, ch / H);
      const s = Math.max(fitAll, Math.min(cw / bw, ch / bh, fitAll * 4));
      let tx = (cw - bw * s) / 2 - bx * s, ty = (ch - bh * s) / 2 - by * s;
      // jangan sampai tepi denah masuk ke dalam layar bila tidak perlu
      tx = W * s <= cw ? (cw - W * s) / 2 : Math.min(0, Math.max(cw - W * s, tx));
      ty = H * s <= ch ? (ch - H * s) / 2 : Math.min(0, Math.max(ch - H * s, ty));
      stage.style.transform = `translate(${tx}px, ${ty}px) scale(${s})`;
      stage.style.setProperty('--inv', 1 / s);
    }
    fit();
    const ro = window.ResizeObserver ? new ResizeObserver(fit) : null;
    if (ro) ro.observe(container);

    // mulai animasi setelah gambar siap
    const go = () => { stage.classList.remove('wf-routing'); void stage.offsetWidth; stage.classList.add('wf-routing'); };
    if (img.complete) go(); else { img.onload = go; img.onerror = go; }

    return { replay: go, destroy: () => ro && ro.disconnect() };
  }

  /* ------------------------------------------------------------------
   * Muat denah terpublikasi (dengan cache versi)
   * ------------------------------------------------------------------ */
  let cache = null;
  async function load(url) {
    const res = await fetch(url, { cache: 'no-store' });
    const json = await res.json();
    cache = json.ok ? json.data : null;
    return cache;
  }

  return { t, seatMatches, codeMatches, findTable, plan, render, load, currentKiosk, setKioskId, kioskId, get data() { return cache; } };
})();
