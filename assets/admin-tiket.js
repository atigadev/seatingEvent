/* Editor Desain Tiket — pratinjau langsung dari server (gambar yang sama dengan hasil cetak) */
(function () {
  'use strict';

  const API = 'api/ticket-admin.php';
  const CSRF = document.querySelector('meta[name=csrf-token]').content;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let T = null;          // desain yang sedang diedit
  let META = null;       // daftar kertas, field, desain bawaan
  let dirty = false;

  const TYPE_LABEL = { field: 'Data peserta', text: 'Teks bebas', counter: 'Nomor antrian', pair: 'Kiri–kanan', line: 'Garis', spacer: 'Jarak' };
  const SAMPLES = {
    long:  { id: 'AA210558', kursi: '40D', nama: 'Prof. Dr. H. Muhammad Syafii Antonio, M.Ec', jabatan: 'Rektor', instansi: 'Tazkia University' },
    short: { id: 'PTBI-0005', kursi: 'A1', nama: 'Rudi Hermawan', jabatan: 'Direktur Utama', instansi: 'PT Bank Mandiri (Persero) Tbk' },
  };

  async function api(action, body) {
    const opt = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) };
    const res = await fetch(API + '?action=' + action, Object.assign({ cache: 'no-store' }, opt));
    const j = await res.json().catch(() => ({ ok: false, error: 'Respons server tidak valid.' }));
    if (!j.ok) throw new Error(j.error || 'Gagal.');
    return j;
  }

  function toast(msg, err) {
    const t = $('toast');
    t.textContent = msg; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(toast.tm); toast.tm = setTimeout(() => { t.hidden = true; }, err ? 5000 : 2200);
  }
  function setState(text, color) { const s = $('state'); s.textContent = text; s.style.color = color || ''; }
  function markDirty() { dirty = true; setState('Belum disimpan', '#ffd58a'); schedulePreview(); }

  /* ---------------- kertas ---------------- */
  function renderPaper() {
    const p = T.paper;
    $('paper-preset').innerHTML = META.papers.map((x) => `<option value="${x.id}">${esc(x.label)}</option>`).join('');
    $('paper-preset').value = p.preset;
    $('paper-width').value = p.width_mm;
    $('paper-dpi').value = String(p.dpi);
    $('paper-hmode').value = p.height_mm > 0 ? 'fixed' : 'auto';
    $('paper-height').value = p.height_mm > 0 ? p.height_mm : 80;
    $('paper-margin').value = p.margin_mm;
    syncPaperUi();
  }
  function syncPaperUi() {
    const custom = $('paper-preset').value === 'custom';
    $('lbl-width').hidden = !custom;
    $('lbl-dpi').hidden = !custom;
    $('lbl-height').hidden = $('paper-hmode').value !== 'fixed';
    const tips = {
      thermal80: 'Untuk printer struk 80 mm (Epson TM, POS-80, dll).',
      thermal58: 'Untuk printer struk 58 mm (printer kecil / portable).',
      brother62: 'Untuk Brother QL-820NWB dengan gulungan DK-22205.',
      custom: 'Isi lebar AREA CETAK (bukan lebar kertas) dan resolusi printer.',
    };
    $('paper-hint').textContent = (tips[$('paper-preset').value] || '') +
      (META.mode === 'brother' || META.mode === 'agent' ? ' Printer Brother selalu memakai lebar 62 mm; elemen menyesuaikan otomatis.' : '');
  }
  ['paper-preset', 'paper-width', 'paper-dpi', 'paper-hmode', 'paper-height', 'paper-margin'].forEach((id) => {
    $(id).addEventListener('input', () => {
      T.paper.preset = $('paper-preset').value;
      T.paper.width_mm = parseFloat($('paper-width').value) || 72;
      T.paper.dpi = parseInt($('paper-dpi').value, 10) || 203;
      T.paper.height_mm = $('paper-hmode').value === 'fixed' ? (parseFloat($('paper-height').value) || 80) : 0;
      T.paper.margin_mm = parseFloat($('paper-margin').value) || 0;
      syncPaperUi();
      markDirty();
    });
  });

  /* ---------------- elemen ---------------- */
  function fieldSelect(key, value) {
    return `<select data-k="${key}">` + Object.entries(META.fields).map(([k, v]) =>
      `<option value="${k}" ${k === value ? 'selected' : ''}>${esc(v)}</option>`).join('') + '</select>';
  }
  const hasText = (t) => ['field', 'text', 'counter', 'pair'].includes(t);

  function elementHtml(e, i) {
    let main = '';
    if (e.type === 'field') main = fieldSelect('field', e.field);
    if (e.type === 'text') main = `<input type="text" data-k="text" value="${esc(e.text)}" placeholder="Tulis teks…">`;
    if (e.type === 'counter') main = `<input type="text" data-k="prefix" value="${esc(e.prefix)}" placeholder="Awalan, mis. No. Antrian ">`;
    if (e.type === 'pair') main = `<div style="display:flex;gap:6px">${fieldSelect('left', e.left)}${fieldSelect('right', e.right)}</div>`;
    if (e.type === 'line') main = `<select data-k="style"><option value="dashed" ${e.style === 'dashed' ? 'selected' : ''}>Garis putus-putus</option><option value="solid" ${e.style === 'solid' ? 'selected' : ''}>Garis penuh</option></select>`;
    if (e.type === 'spacer') main = `<span class="muted small">Ruang kosong</span>`;

    const text = hasText(e.type);
    const align = text && e.type !== 'pair' ? `<span class="seg">${['left', 'center', 'right'].map((a) =>
      `<button type="button" data-act="align" data-v="${a}" class="${e.align === a ? 'on' : ''}" title="${a === 'left' ? 'Rata kiri' : a === 'right' ? 'Rata kanan' : 'Tengah'}">${a === 'left' ? '⯇' : a === 'right' ? '⯈' : '≡'}</button>`).join('')}</span>` : '';

    let opts = '';
    if (text) {
      opts += `<label><input type="checkbox" data-k="upper" ${e.upper ? 'checked' : ''}> HURUF BESAR</label>`;
      opts += `<label>Jarak huruf <input type="number" data-k="spacing" value="${e.spacing}" min="0" max="20" step="0.5"> pt</label>`;
    }
    if (e.type === 'field' || e.type === 'text') {
      opts += `<label>Maks. baris <input type="number" data-k="lines" value="${e.lines}" min="1" max="6"></label>`;
      opts += `<label><input type="checkbox" data-k="fit" ${e.fit ? 'checked' : ''}> Perkecil agar 1 baris</label>`;
    }
    if (e.type === 'field') opts += `<label>Awalan <input type="text" data-k="prefix" value="${esc(e.prefix || '')}" placeholder="mis. Meja "></label>`;
    if (e.type === 'counter') opts += `<label>Jumlah digit <input type="number" data-k="digits" value="${e.digits}" min="1" max="6"></label>`;
    if (text) opts += `<label>Jarak bawah <input type="number" data-k="gap" value="${e.gap}" min="0" max="20" step="0.5"> mm</label>`;
    if (e.type === 'line') {
      opts += `<label>Tebal <input type="number" data-k="thick" value="${e.thick}" min="0.1" max="3" step="0.1"> mm</label>`;
      opts += `<label>Jarak atas/bawah <input type="number" data-k="space" value="${e.space}" min="0" max="15" step="0.5"> mm</label>`;
    }
    if (e.type === 'spacer') opts += `<label>Tinggi <input type="number" data-k="height" value="${e.height}" min="0.5" max="60" step="0.5"> mm</label>`;

    return `<div class="el ${e.hidden ? 'off' : ''}" data-i="${i}">
      <div class="el-top">
        <button type="button" data-act="up" title="Naik">▲</button>
        <button type="button" data-act="down" title="Turun">▼</button>
        <button type="button" data-act="toggle" title="${e.hidden ? 'Tampilkan' : 'Sembunyikan'}">${e.hidden ? '🙈' : '👁'}</button>
        <span class="badge">${TYPE_LABEL[e.type]}</span>
        <div class="main">${main}</div>
        ${text ? `<input class="size" type="number" data-k="size" value="${e.size}" min="5" max="160" step="0.5" title="Ukuran huruf (pt)">
                  <button type="button" data-act="bold" class="${e.bold ? 'on' : ''}" title="Tebal"><b>B</b></button>` : ''}
        ${align}
        <button type="button" data-act="del" title="Hapus">✕</button>
      </div>
      ${opts ? `<div class="el-opts">${text ? '<span>pt = ukuran huruf</span>' : ''}${opts}</div>` : ''}
    </div>`;
  }

  function renderElements() {
    $('elements').innerHTML = T.elements.map(elementHtml).join('') || '<p class="hint">Belum ada isi. Tambahkan elemen di bawah.</p>';
  }

  $('elements').addEventListener('input', (ev) => {
    const k = ev.target.dataset.k;
    const box = ev.target.closest('[data-i]');
    if (!k || !box) return;
    const e = T.elements[+box.dataset.i];
    const t = ev.target;
    e[k] = t.type === 'checkbox' ? t.checked : t.type === 'number' ? (parseFloat(t.value) || 0) : t.value;
    markDirty();
  });
  $('elements').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-act]');
    const box = ev.target.closest('[data-i]');
    if (!b || !box) return;
    const i = +box.dataset.i;
    const e = T.elements[i];
    switch (b.dataset.act) {
      case 'up': if (i > 0) [T.elements[i - 1], T.elements[i]] = [T.elements[i], T.elements[i - 1]]; break;
      case 'down': if (i < T.elements.length - 1) [T.elements[i + 1], T.elements[i]] = [T.elements[i], T.elements[i + 1]]; break;
      case 'toggle': e.hidden = !e.hidden; break;
      case 'bold': e.bold = !e.bold; break;
      case 'align': e.align = b.dataset.v; break;
      case 'del': if (!confirm('Hapus elemen "' + TYPE_LABEL[e.type] + '"?')) return; T.elements.splice(i, 1); break;
    }
    renderElements();
    markDirty();
  });

  const NEW = {
    field:   () => ({ type: 'field', field: 'nama', size: 12, bold: false, align: 'center', lines: 2, gap: 1 }),
    text:    () => ({ type: 'text', text: 'Terima kasih atas kehadiran Anda', size: 9, align: 'center', lines: 2, gap: 1 }),
    counter: () => ({ type: 'counter', prefix: 'No. Antrian ', digits: 3, size: 14, bold: true, align: 'center', gap: 1 }),
    pair:    () => ({ type: 'pair', left: 'id', right: 'datetime', size: 7, gap: 1 }),
    line:    () => ({ type: 'line', style: 'dashed', thick: 0.4, space: 2 }),
    spacer:  () => ({ type: 'spacer', height: 3 }),
  };
  document.querySelector('.add').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-add]');
    if (!b) return;
    const base = { hidden: false, size: 10, bold: false, align: 'center', upper: false, spacing: 0, lines: 1, fit: false, gap: 1 };
    T.elements.push(Object.assign(base, NEW[b.dataset.add]()));
    renderElements();
    markDirty();
    $('elements').lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  /* ---------------- pratinjau ---------------- */
  function sample() {
    const p = $('sample-preset').value;
    $('sample-fields').hidden = p !== 'custom';
    if (p !== 'custom') return SAMPLES[p];
    return { id: 'CONTOH', kursi: $('s-kursi').value, nama: $('s-nama').value, jabatan: $('s-jabatan').value, instansi: $('s-instansi').value };
  }
  let pvTimer = null, pvSeq = 0;
  function schedulePreview() { clearTimeout(pvTimer); pvTimer = setTimeout(preview, 280); }
  async function preview() {
    const seq = ++pvSeq;
    try {
      const r = await api('preview', { template: T, sample: sample() });
      if (seq !== pvSeq) return; // ada pratinjau yang lebih baru
      const img = $('preview');
      img.src = 'data:image/png;base64,' + r.png;
      img.style.width = r.width_mm + 'mm';
      const preset = META.papers.find((x) => x.id === T.paper.preset);
      const paperMm = T.paper.preset === 'custom' ? r.width_mm : preset.paper_mm;
      $('paper').style.width = Math.max(paperMm, r.width_mm) + 'mm';
      $('dims').textContent = `— kertas ${String(paperMm).replace('.', ',')} mm · tiket ${String(r.width_mm).replace('.', ',')} × ${String(r.height_mm).replace('.', ',')} mm · ${r.dpi} dpi`;
      $('overflow').hidden = !r.overflow;
    } catch (e) { toast(e.message, true); }
  }
  $('sample-preset').addEventListener('change', schedulePreview);
  ['s-kursi', 's-nama', 's-jabatan', 's-instansi'].forEach((id) => $(id).addEventListener('input', schedulePreview));

  /* ---------------- simpan / bawaan / antrian ---------------- */
  $('btn-save').addEventListener('click', async () => {
    try {
      const r = await api('save', { template: T });
      T = r.template; dirty = false;
      setState('Tersimpan ' + r.savedAt + ' — berlaku untuk cetakan berikutnya', '#8ff0b8');
      toast('Desain tiket disimpan');
      renderPaper(); renderElements(); schedulePreview();
    } catch (e) { toast(e.message, true); }
  });
  $('btn-reset').addEventListener('click', async () => {
    if (!confirm('Kembalikan ke desain tiket bawaan? Desain yang tersimpan akan diganti.')) return;
    try {
      const r = await api('reset', {});
      T = r.template; dirty = false;
      setState('Desain bawaan dipakai', '#8ff0b8');
      renderPaper(); renderElements(); schedulePreview();
    } catch (e) { toast(e.message, true); }
  });
  $('btn-counter').addEventListener('click', async () => {
    const start = Math.max(1, parseInt($('counter-value').value, 10) || 1);
    if (!confirm('Nomor antrian berikutnya menjadi ' + start + '?')) return;
    try {
      const r = await api('counter', { value: start - 1 });
      $('counter-next').textContent = r.counter + 1;
      toast('Nomor antrian diatur');
      schedulePreview();
    } catch (e) { toast(e.message, true); }
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------------- mulai ---------------- */
  (async function init() {
    try {
      META = await api('load');
      T = META.template;
      $('counter-next').textContent = META.counter + 1;
      $('counter-value').value = META.counter + 1;
      setState('Desain aktif dimuat');
      renderPaper();
      renderElements();
      preview();
    } catch (e) {
      setState(e.message, '#ffb3b5');
      if (/login/i.test(e.message)) location.href = 'settings.php?next=admin-tiket.php';
    }
  })();
})();
