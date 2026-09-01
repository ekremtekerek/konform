/**
 * Resmi EN 16931 kural setini indirir ve Saxon-JS icin derler.
 *
 * Kural seti bir yapi artefaktidir; depoya girmez, bu betikle uretilir.
 * Boylece hangi surumun kullanildigi tek bir yerden gorulur ve guncelleme
 * tek komuttur.
 *
 * Calistir: npm run build:rules
 */

import { mkdir, writeFile, rm, readdir, copyFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createWriteStream } from 'node:fs';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const VERSION = process.env.EN16931_VERSION ?? '1.3.16';
const RELEASE = `validation-${VERSION}`;
const ASSET = `en16931-cii-${VERSION}.zip`;
const URL = `https://github.com/ConnectingEurope/eInvoicing-EN16931/releases/download/${RELEASE}/${ASSET}`;

const work = join(root, '.rules-build');
const out = join(root, 'rules');

console.log(`EN 16931 ${VERSION} (CII) indiriliyor...`);

await rm(work, { recursive: true, force: true });
await mkdir(work, { recursive: true });
await mkdir(out, { recursive: true });

const response = await fetch(URL);

if (!response.ok) {
  console.error(`Indirilemedi: ${response.status} ${URL}`);
  process.exit(1);
}

const zipPath = join(work, ASSET);
await pipeline(Readable.fromWeb(response.body), createWriteStream(zipPath));

console.log('Ayikliniyor...');

// Node 22+ ile gelen yerlesik zip cozumu yok; sistem araclarina dusuyoruz.
try {
  execFileSync('unzip', ['-o', '-q', zipPath, '-d', join(work, 'x')], { stdio: 'inherit' });
} catch {
  execFileSync('tar', ['-xf', zipPath, '-C', join(work, 'x')], { stdio: 'inherit' });
}

const xslt = join(work, 'x', 'xslt', `EN16931-CII-validation.xslt`);

if (!existsSync(xslt)) {
  console.error(`Beklenen XSLT bulunamadi: ${xslt}`);
  console.error('Arsiv icerigi:', await readdir(join(work, 'x')));
  process.exit(1);
}

console.log('SEF derleniyor (Saxon-JS)...');

execFileSync(
  process.execPath,
  [
    join(root, 'node_modules', 'xslt3', 'xslt3.js'),
    `-xsl:${xslt}`,
    `-export:${join(out, 'en16931-cii.sef.json')}`,
    '-nogo',
  ],
  { stdio: 'inherit' },
);

// Oz-test icin resmi bir ornek saklanir; kural setiyle AYNI surumden gelmeli,
// aksi halde test yanlis bir taban aleyhine calisir.
const example = join(work, 'x', 'examples', 'CII_business_example_01.xml');

if (!existsSync(example)) {
  console.error(`Ornek bulunamadi: ${example}`);
  process.exit(1);
}

await copyFile(example, join(out, 'example-cii.xml'));

await writeFile(join(out, 'VERSION'), `${VERSION}\n`, 'utf8');
await rm(work, { recursive: true, force: true });

console.log(`Hazir: rules/en16931-cii.sef.json  (EN 16931 ${VERSION})`);
