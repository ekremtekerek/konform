/**
 * Konform eklenti ikonunu uretir.
 *
 * Ikon fikri hibrit belgeyi anlatir - Factur-X'in tanimi tam olarak budur:
 * ust yarida insanin okudugu fatura satirlari, alt yarida makinenin okudugu
 * kodlanmis veri. Bu, urunun ne yaptigini tek bakista soyler.
 *
 * Kucuk boyutta okunakli olmasi icin sekil sayisi az ve kontrast yuksek
 * tutulmustur; WordPress.org listelerinde ikon 128px gorunur.
 *
 * Disaridan bir gorsel kutuphanesi kullanmadan PNG yazilir; Node'un yerlesik
 * zlib'i yeterlidir.
 *
 * Calistir: node bin/make-icon.mjs
 */

import { deflateSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

// WordPress.org 128 ve 256 ister, Freemius 300. Uc boyut da ayni
// vektorel tanimdan uretilir; elle olceklemek kenarlari bozar.
const UNITS = 300; // Tasarim uzayi.
const SIZES = [ 128, 256, 300 ];
const SS = 4; // Kenar yumusatma icin asiri ornekleme.

// Fiskal defter yesili. Notr griden kacinildi: renk secilmis olmali, miras
// alinmis degil.
const BG = [15, 107, 85];
const PAPER = [255, 255, 255];
const LINE = [154, 190, 178];

/**
 * Noktanin yuvarlatilmis dikdortgen icinde olup olmadigini soyler.
 *
 * @param {number} x  Nokta x.
 * @param {number} y  Nokta y.
 * @param {number} x0 Sol kenar.
 * @param {number} y0 Ust kenar.
 * @param {number} x1 Sag kenar.
 * @param {number} y1 Alt kenar.
 * @param {number} r  Kose yaricapi.
 * @returns {boolean}
 */
function inRoundRect(x, y, x0, y0, x1, y1, r) {
  if (x < x0 || x > x1 || y < y0 || y > y1) return false;
  const cx = Math.min(Math.max(x, x0 + r), x1 - r);
  const cy = Math.min(Math.max(y, y0 + r), y1 - r);
  const dx = x - cx;
  const dy = y - cy;
  return dx * dx + dy * dy <= r * r;
}

/**
 * Makine okunur seridin cubuk yerlesimini uretir.
 *
 * Desen sabittir; her uretimde ayni ikon cikmali.
 *
 * @param {number} x0 Baslangic.
 * @param {number} x1 Bitis.
 * @returns {Array<{x0: number, x1: number}>}
 */
function barcodeBars(x0, x1) {
  const widths = [7, 3, 4, 9, 3, 6, 3, 8, 4, 3, 7, 5];
  const gap = 4;
  const total = widths.reduce((a, b) => a + b, 0) + gap * (widths.length - 1);
  const scale = (x1 - x0) / total;

  const bars = [];
  let cursor = x0;

  for (const w of widths) {
    const width = w * scale;
    bars.push({ x0: cursor, x1: cursor + width });
    cursor += width + gap * scale;
  }

  return bars;
}

const doc = { x0: 78, y0: 54, x1: 222, y1: 246, r: 14 };

const textLines = [
  { x0: 100, x1: 200, y: 90 },
  { x0: 100, x1: 182, y: 114 },
  { x0: 100, x1: 192, y: 138 },
];

const bars = barcodeBars(100, 200);
const barTop = 172;
const barBottom = 220;

/**
 * Bir noktanin rengini dondurur.
 *
 * @param {number} x Nokta x.
 * @param {number} y Nokta y.
 * @returns {number[]} RGB.
 */
function sample(x, y) {
  // Kagit uzerindeki makine okunur serit.
  for (const bar of bars) {
    if (x >= bar.x0 && x <= bar.x1 && y >= barTop && y <= barBottom) {
      return BG;
    }
  }

  // Insan okunur satirlar.
  for (const line of textLines) {
    if (inRoundRect(x, y, line.x0, line.y - 5, line.x1, line.y + 5, 5)) {
      return LINE;
    }
  }

  if (inRoundRect(x, y, doc.x0, doc.y0, doc.x1, doc.y1, doc.r)) {
    return PAPER;
  }

  if (inRoundRect(x, y, 0, 0, UNITS, UNITS, 66)) {
    return BG;
  }

  return null; // Saydam.
}

/**
 * Tasarimi verilen boyutta rasterlestirir.
 *
 * Koordinatlar 300 birimlik tasarim uzayinda tanimlidir ve her boyut icin
 * yeniden ornekleme yapilir; hazir bir PNG'yi kucultmek kenarlari bozardi.
 *
 * @param {number} size Kenar uzunlugu, piksel.
 * @returns {Buffer} RGBA piksel verisi.
 */
function rasterise(size) {
  const scale = size / UNITS;
  const pixels = Buffer.alloc(size * size * 4);

  for (let py = 0; py < size; py++) {
    for (let px = 0; px < size; px++) {
      let r = 0;
      let g = 0;
      let b = 0;
      let a = 0;

      for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
          const c = sample((px + (sx + 0.5) / SS) / scale, (py + (sy + 0.5) / SS) / scale);

          if (c) {
            r += c[0];
            g += c[1];
            b += c[2];
            a += 255;
          }
        }
      }

      const alpha = a / (SS * SS);
      const i = (py * size + px) * 4;

      // Saydam bolgede renk bilesenleri anlamsizdir; sifir birakilir.
      pixels[i] = alpha > 0 ? Math.round(r / (a / 255)) : 0;
      pixels[i + 1] = alpha > 0 ? Math.round(g / (a / 255)) : 0;
      pixels[i + 2] = alpha > 0 ? Math.round(b / (a / 255)) : 0;
      pixels[i + 3] = Math.round(alpha);
    }
  }

  return pixels;
}

// --- PNG yaz --------------------------------------------------------------

const CRC_TABLE = (() => {
  const table = new Int32Array(256);

  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }

  return table;
})();

/**
 * CRC32 hesaplar.
 *
 * @param {Buffer} buf Veri.
 * @returns {number}
 */
function crc32(buf) {
  let c = 0xffffffff;
  for (const byte of buf) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

/**
 * PNG parcasi olusturur.
 *
 * @param {string} type Parca tipi.
 * @param {Buffer} data Veri.
 * @returns {Buffer}
 */
function chunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);

  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));

  return Buffer.concat([length, body, crc]);
}

/**
 * RGBA piksellerden PNG dosyasi olusturur.
 *
 * @param {Buffer} pixels RGBA veri.
 * @param {number} size   Kenar uzunlugu.
 * @returns {Buffer}
 */
function encodePng(pixels, size) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit derinligi
  ihdr[9] = 6; // RGBA

  // Her satirin basina filtre bayti (0 = None).
  const stride = size * 4 + 1;
  const raw = Buffer.alloc(size * stride);

  for (let y = 0; y < size; y++) {
    raw[y * stride] = 0;
    pixels.copy(raw, y * stride + 1, y * size * 4, (y + 1) * size * 4);
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

for (const size of SIZES) {
  const png = encodePng(rasterise(size), size);
  const name = `icon-${size}x${size}.png`;

  writeFileSync(join(here, '..', 'assets', name), png);

  console.log(`  ${name.padEnd(20)} ${String(png.length).padStart(6)} bayt`);
}

console.log('\nassets/ hazir.');
