import SaxonJS from 'saxon-js';
import { readFileSync } from 'node:fs';

const sef = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const xml = readFileSync(process.argv[3], 'utf8');
const t0 = Date.now();

const out = SaxonJS.transform({
  stylesheetInternal: sef,
  sourceText: xml,
  destination: 'serialized',
}, 'sync');

const svrl = out.principalResult;
const failed = [...svrl.matchAll(/<svrl:failed-assert[\s\S]*?<svrl:text>([\s\S]*?)<\/svrl:text>/g)];

console.log('sure          :', (Date.now() - t0) + ' ms');
console.log('SVRL uzunlugu :', svrl.length, 'bayt');
console.log('failed-assert :', failed.length);
for (const m of failed.slice(0, 8)) {
  console.log('   -', m[1].trim().replace(/\s+/g, ' ').slice(0, 95));
}
