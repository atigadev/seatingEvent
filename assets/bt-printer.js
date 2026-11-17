/*
 * BtPrinter — koneksi printer thermal BLE langsung dari Chrome/Edge (Web Bluetooth).
 * - pair()       : buka dialog pilih perangkat (harus dari klik pengguna)
 * - reconnect()  : sambung ulang otomatis ke printer yang pernah dipilih
 * - print(b64)   : kirim data ESC/POS (base64) ke printer
 * Syarat: halaman dibuka via http://localhost atau https.
 */
window.BtPrinter = (function () {
  'use strict';

  // Service UUID yang umum dipakai printer thermal BLE
  const SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fff0-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
    '6e400001-b5a3-f393-e0a9-e50e24dcca9e',
  ];
  const CHUNK = 20;          // aman untuk MTU BLE terkecil
  const STORE_KEY = 'btPrinterId';

  const supported = !!(navigator.bluetooth && window.isSecureContext);
  let device = null;
  let ch = null;
  let connecting = null;
  let retryTimer = null;
  const listeners = [];

  function status() {
    return {
      supported,
      connected: !!(device && device.gatt.connected && ch),
      connecting: !!connecting,
      name: device ? (device.name || 'Printer') : '',
    };
  }
  function emit() { const s = status(); listeners.forEach((fn) => fn(s)); }
  function onChange(fn) { listeners.push(fn); fn(status()); }

  async function findWritable(server) {
    let services = [];
    try { services = await server.getPrimaryServices(); } catch (_) {}
    for (const s of services) {
      let chars = [];
      try { chars = await s.getCharacteristics(); } catch (_) { continue; }
      for (const c of chars) {
        if (c.properties.write || c.properties.writeWithoutResponse) return c;
      }
    }
    return null;
  }

  function onDisconnected() {
    ch = null;
    emit();
    scheduleRetry(3000);
  }

  function scheduleRetry(ms) {
    clearTimeout(retryTimer);
    retryTimer = setTimeout(() => { reconnect().catch(() => scheduleRetry(5000)); }, ms);
  }

  async function connect(dev) {
    if (device !== dev) {
      if (device) device.removeEventListener('gattserverdisconnected', onDisconnected);
      device = dev;
      device.addEventListener('gattserverdisconnected', onDisconnected);
    }
    connecting = (async () => {
      emit();
      const server = await dev.gatt.connect();
      const c = await findWritable(server);
      if (!c) {
        dev.gatt.disconnect();
        throw new Error('Printer tidak menyediakan layanan cetak BLE yang dikenali.');
      }
      ch = c;
      try { localStorage.setItem(STORE_KEY, dev.id); } catch (_) {}
    })();
    try { await connecting; } finally { connecting = null; emit(); }
  }

  /** Dialog pilih printer — wajib dipanggil dari event klik */
  async function pair() {
    if (!supported) throw new Error('Browser tidak mendukung Web Bluetooth (pakai Chrome/Edge via localhost/https).');
    const dev = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES });
    await connect(dev);
    return status();
  }

  /** Sambung ulang ke printer terakhir tanpa dialog (jika browser mengizinkan) */
  async function reconnect() {
    if (!supported) return status();
    if (status().connected) return status();
    if (connecting) { await connecting; return status(); }

    let dev = device;
    if (!dev && navigator.bluetooth.getDevices) {
      let savedId = null;
      try { savedId = localStorage.getItem(STORE_KEY); } catch (_) {}
      const devices = await navigator.bluetooth.getDevices();
      dev = devices.find((d) => d.id === savedId) || devices[0] || null;
    }
    if (!dev) return status();

    try {
      await connect(dev);
    } catch (err) {
      // perangkat mungkin belum terlihat — tunggu iklan BLE lalu coba lagi
      if (dev.watchAdvertisements) {
        await new Promise((resolve) => {
          const done = () => { dev.removeEventListener('advertisementreceived', done); resolve(); };
          dev.addEventListener('advertisementreceived', done);
          dev.watchAdvertisements().catch(resolve);
          setTimeout(done, 8000);
        });
        await connect(dev);
      } else {
        throw err;
      }
    }
    return status();
  }

  function b64ToBytes(b64) {
    const bin = atob(b64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  async function write(bytes) {
    if (!status().connected) await reconnect();
    if (!status().connected) throw new Error('Printer Bluetooth belum terhubung.');
    const withResponse = ch.properties.write && ch.writeValueWithResponse;
    for (let i = 0; i < bytes.length; i += CHUNK) {
      const part = bytes.slice(i, i + CHUNK);
      if (withResponse) {
        await ch.writeValueWithResponse(part);
      } else {
        await ch.writeValueWithoutResponse(part);
        await new Promise((r) => setTimeout(r, 8)); // beri jeda agar buffer printer tidak penuh
      }
    }
  }

  async function print(b64) { await write(b64ToBytes(b64)); }

  async function forget() {
    try { localStorage.removeItem(STORE_KEY); } catch (_) {}
    if (device) {
      device.removeEventListener('gattserverdisconnected', onDisconnected);
      if (device.gatt.connected) device.gatt.disconnect();
      if (device.forget) await device.forget().catch(() => {});
    }
    device = null; ch = null; clearTimeout(retryTimer); emit();
  }

  return { supported, status, onChange, pair, reconnect, print, forget };
})();
