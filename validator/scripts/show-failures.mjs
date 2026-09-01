import SaxonJS from 'saxon-js';
import { readFileSync } from 'node:fs';

const sef = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const xml = readFileSync(process.argv[3], 'utf8');
const out = SaxonJS.transform({ stylesheetInternal: sef, sourceText: xml, destination: 'serialized' }, 'sync');

for (const m of out.principalResult.matchAll(/<svrl:failed-assert([^>]*)>[\s\S]*?<svrl:text>([\s\S]*?)<\/svrl:text>/g)) {
  const flag = /flag="([^"]*)"/.exec(m[1])?.[1] ?? '';
  const loc  = /location="([^"]*)"/.exec(m[1])?.[1] ?? '';
  console.log('[' + flag + '] ' + m[2].trim().replace(/\s+/g, ' '));
  console.log('    konum: ' + loc.replace(/\*:/g, '').slice(-90));
  console.log();
}
