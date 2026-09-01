/**
 * Dogrulama servisinin oz-testi.
 *
 * CI'da her degisiklikten sonra calisir. Amac servisi degil, KURAL SETINI ve
 * Saxon-JS entegrasyonunu dogrulamak: resmi ornek temiz gecmeli, bozuk girdi
 * hata olarak raporlanmali.
 *
 * Calistir: npm test
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

process.env.NODE_ENV = 'test';

const here = dirname(fileURLToPath(import.meta.url));
const { validate } = await import('../src/server.js');

let failures = 0;

/**
 * Bir beklentiyi dogrular.
 *
 * @param {string}  name      Test adi.
 * @param {boolean} condition Kosul.
 * @param {string}  detail    Ayrinti.
 */
function check(name, condition, detail = '') {
  if (condition) {
    console.log(`  ok    ${name}`);
  } else {
    console.error(`  FAIL  ${name}${detail ? ' — ' + detail : ''}`);
    failures += 1;
  }
}

console.log('EN 16931 dogrulama oz-testi');

const example = readFileSync(join(here, '..', 'rules', 'example-cii.xml'), 'utf8');
const valid = validate(example);

check('resmi ornek gecerli', valid.valid, `${valid.errors.length} hata`);
check('resmi ornek hatasiz', valid.errors.length === 0, JSON.stringify(valid.errors.slice(0, 2)));
check('sure olculuyor', typeof valid.duration_ms === 'number');

const broken = validate('<not-xml');
check('bozuk XML gecersiz', broken.valid === false);
check('bozuk XML hata veriyor', broken.errors.length > 0);

// Zorunlu alani cikarinca kural tetiklenmeli.
const stripped = example.replace(/<ram:SellerTradeParty>[\s\S]*?<\/ram:SellerTradeParty>/, '');
const missing = validate(stripped);
check('eksik satici yakalaniyor', missing.valid === false, `${missing.errors.length} hata`);

if (failures > 0) {
  console.error(`\n${failures} kontrol basarisiz.`);
  process.exit(1);
}

console.log('\nTum kontroller gecti.');
