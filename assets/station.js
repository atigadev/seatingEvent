/*
 * Stasiun cetak untuk perangkat ini (mode Print Agent).
 * Setiap tablet/kiosk memilih stasiun = printer terdekat. Disimpan di perangkat;
 * bisa juga lewat alamat: index.php?station=pintu-utara / seating.php?station=pintu-utara
 */
window.Station = (function () {
  'use strict';
  const KEY = 'seatStation';
  const slug = (s) => String(s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30);

  function get() {
    const q = new URLSearchParams(location.search).get('station');
    if (q !== null) { set(q); return slug(q); }
    try { return slug(localStorage.getItem(KEY)); } catch (_) { return ''; }
  }
  function set(v) { try { localStorage.setItem(KEY, slug(v)); } catch (_) {} }

  /** Isi <select> stasiun dari daftar server: [{station, online}] */
  function fill(sel, list) {
    if (!sel) return;
    const cur = get();
    const items = (list || []).slice();
    if (cur && !items.some((s) => s.station === cur)) items.push({ station: cur, online: false });
    sel.innerHTML = '<option value="">🖨 Stasiun: otomatis</option>' + items.map((s) =>
      `<option value="${s.station}">🖨 ${s.station}${s.online ? '' : ' (offline)'}</option>`).join('');
    sel.value = cur;
    sel.hidden = false;
  }

  function bind(sel, onChange) {
    if (!sel) return;
    sel.addEventListener('change', () => { set(sel.value); if (onChange) onChange(sel.value); });
  }

  return { get, set, fill, bind, slug };
})();
