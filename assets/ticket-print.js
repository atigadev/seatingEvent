/*
 * Cetak gambar tiket (Desain Tiket) lewat browser — mode Kabel USB.
 * ticket = { png (base64), width_mm, page_w_mm, height_mm } dari server.
 * Ukuran halaman (@page) diatur dinamis sesuai kertas & tinggi tiket.
 */
window.TicketPrint = (function () {
  'use strict';

  function ensureNodes() {
    let box = document.getElementById('ticket-img');
    if (!box) {
      box = document.createElement('div');
      box.id = 'ticket-img';
      box.innerHTML = '<img alt="">';
      document.body.appendChild(box);
    }
    let st = document.getElementById('ticket-page-style');
    if (!st) {
      st = document.createElement('style');
      st.id = 'ticket-page-style';
      document.head.appendChild(st);
    }
    return { box, img: box.querySelector('img'), st };
  }

  function print(ticket, copies) {
    const { img, st } = ensureNodes();
    const w = Number(ticket.page_w_mm || ticket.width_mm);
    const h = Number(ticket.page_h_mm) || Math.ceil(Number(ticket.height_mm) + 1);
    const driver = ticket.page_mode === 'driver';
    // 'driver': ukuran & orientasi halaman dari kertas yang dipilih di driver printer (mis. label Brother 62×40 mm)
    //           → tanpa @page size agar Chrome tidak memutar / mengecilkan tiket.
    // 'app'   : halaman = lebar kertas × tinggi tiket (minimal selebar kertas agar tetap portrait), isi di tengah.
    const page = driver ? '@page { margin: 0; }' : `@page { size: ${w}mm ${h}mm; margin: 0; }`;
    const box = driver
      ? ` body.print-img #ticket-img { display: block !important; width: 100%; margin: 0; text-align: center; }`
      : ` body.print-img #ticket-img { display: flex !important; flex-direction: column; align-items: center; justify-content: center;` +
        ` width: ${w}mm; height: ${Math.max(1, h - 1)}mm; margin: 0; overflow: hidden; }`;
    st.textContent = page +
      `@media print { body.print-img > *:not(#ticket-img) { display: none !important; }` +
      ` body.print-img::before, body.print-img::after { display: none !important; }` +
      ` html, body.print-img { background: #fff !important; margin: 0 !important; padding: 0 !important; }` +
      box +
      ` body.print-img #ticket-img img { display: block; margin: 0 auto; width: ${ticket.width_mm}mm; height: auto; image-rendering: pixelated; } }`;
    return new Promise((resolve) => {
      const go = () => {
        document.body.classList.add('print-img');
        requestAnimationFrame(() => setTimeout(() => {
          for (let i = 0; i < Math.max(1, copies || 1); i++) window.print();
          document.body.classList.remove('print-img');
          resolve();
        }, 120));
      };
      img.onload = go;
      img.onerror = go;
      img.src = 'data:image/png;base64,' + ticket.png;
    });
  }

  return { print };
})();
