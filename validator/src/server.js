/**
 * Konform — barındırılan EN 16931 doğrulama servisi.
 *
 * Neden ayrı bir servis: EN 16931 ve KoSIT kural setleri XSLT 2.0'a derlenir.
 * PHP'nin ext-xsl uzantısı libxslt'yi sarmalar ve XSLT 1.0'da kalır, dolayısıyla
 * resmi doğrulama eklentinin İÇİNDE çalıştırılamaz. Bu kısıt aynı zamanda
 * ürünün lisans korumasıdır: null'lanmış bir kopya doğrulama yapamaz, yani işe
 * yaramaz.
 *
 * Neden Cloudflare Worker değil: bkz. docs/adr/0003-dogrulama-calisma-ortami.md
 *
 * Bağımlılığı olmayan düz node:http kullanılır; servis tek uçlu ve tek işlidir,
 * bir çatı katmanı eklemek yalnızca saldırı yüzeyi ve bakım yükü olurdu.
 */

import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { timingSafeEqual } from 'node:crypto';
import SaxonJS from 'saxon-js';

const here = dirname(fileURLToPath(import.meta.url));

const PORT = Number(process.env.PORT ?? 8790);
const SECRET = process.env.LICENSE_SECRET ?? '';
const RULES_VERSION = process.env.RULES_VERSION ?? '1.3.16';

/** İstek gövdesi üst sınırı; doğrulama CPU yakar, açık uçlu bırakılmaz. */
const MAX_BYTES = 2 * 1024 * 1024;

/**
 * Derlenmiş kural seti. Süreç başına bir kez okunur; her istekte 5 MB JSON
 * ayrıştırmak saniyeler alırdı.
 */
const ruleset = JSON.parse(
  readFileSync(join(here, '..', 'rules', 'en16931-cii.sef.json'), 'utf8'),
);

/**
 * SVRL çıktısını yapılandırılmış bulgulara çevirir.
 *
 * Ham SVRL 10–35 KB XML'dir; istemciye onu göndermek hem bant genişliği hem de
 * eklentide ikinci bir XML ayrıştırıcı demek olurdu.
 *
 * @param {string} svrl SVRL belgesi.
 * @returns {{errors: object[], warnings: object[]}}
 */
export function parseSvrl(svrl) {
  const errors = [];
  const warnings = [];
  const pattern = /<svrl:failed-assert([^>]*)>[\s\S]*?<svrl:text>([\s\S]*?)<\/svrl:text>/g;

  for (const match of svrl.matchAll(pattern)) {
    const attributes = match[1];
    const text = match[2].trim().replace(/\s+/g, ' ');

    const flag = /flag="([^"]*)"/.exec(attributes)?.[1] ?? 'fatal';
    const location = /location="([^"]*)"/.exec(attributes)?.[1] ?? '';

    // Kural kimligi metnin basinda koseli parantez icinde gelir: [BR-IC-11]-...
    const rule = /^\[([A-Z0-9-]+)\]/.exec(text)?.[1] ?? '';

    const finding = {
      rule,
      flag,
      message: text.replace(/^\[[A-Z0-9-]+\]-?/, '').trim(),
      location: location.replace(/\*:/g, ''),
    };

    (flag === 'warning' ? warnings : errors).push(finding);
  }

  return { errors, warnings };
}

/**
 * XML'i resmi kural setine göre doğrular.
 *
 * @param {string} xml Fatura XML'i.
 * @returns {{valid: boolean, errors: object[], warnings: object[], duration_ms: number}}
 */
export function validate(xml) {
  const started = Date.now();

  let svrl;

  try {
    svrl = SaxonJS.transform(
      { stylesheetInternal: ruleset, sourceText: xml, destination: 'serialized' },
      'sync',
    ).principalResult;
  } catch (error) {
    // Bicimsiz XML burada patlar; bu da gecerli bir dogrulama sonucudur.
    return {
      valid: false,
      errors: [{ rule: '', flag: 'fatal', message: String(error?.message ?? error), location: '' }],
      warnings: [],
      duration_ms: Date.now() - started,
    };
  }

  const { errors, warnings } = parseSvrl(svrl);

  return { valid: errors.length === 0, errors, warnings, duration_ms: Date.now() - started };
}

/**
 * Yetkilendirme başlığını sabit zamanlı karşılaştırır.
 *
 * @param {string} header Authorization başlığı.
 * @returns {boolean}
 */
function isAuthorised(header) {
  if (SECRET === '') {
    return false;
  }

  const token = header?.startsWith('Bearer ') ? header.slice(7) : '';
  const a = Buffer.from(token);
  const b = Buffer.from(SECRET);

  // timingSafeEqual esit uzunluk ister; uzunluk farki da bir sizintidir,
  // bu yuzden once sabit uzunluga getirilir.
  if (a.length !== b.length) {
    return false;
  }

  return timingSafeEqual(a, b);
}

/**
 * JSON yanıtı yazar.
 *
 * @param {import('node:http').ServerResponse} response Yanıt.
 * @param {number} status Durum kodu.
 * @param {object} body   Gövde.
 * @returns {void}
 */
function json(response, status, body) {
  const payload = JSON.stringify(body);

  response.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
    'content-length': Buffer.byteLength(payload),
  });

  response.end(payload);
}

/**
 * İstek gövdesini sınır denetimiyle okur.
 *
 * @param {import('node:http').IncomingMessage} request İstek.
 * @returns {Promise<string>}
 */
function readBody(request) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];

    request.on('data', (chunk) => {
      size += chunk.length;

      if (size > MAX_BYTES) {
        reject(Object.assign(new Error('payload_too_large'), { status: 413 }));
        request.destroy();

        return;
      }

      chunks.push(chunk);
    });

    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    request.on('error', reject);
  });
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url ?? '/', 'http://localhost');

  if (url.pathname === '/health') {
    json(response, 200, { ok: true, rules_version: RULES_VERSION, syntax: 'CII' });

    return;
  }

  if (url.pathname !== '/v1/validate') {
    json(response, 404, { error: 'not_found' });

    return;
  }

  if (request.method !== 'POST') {
    json(response, 405, { error: 'method_not_allowed' });

    return;
  }

  if (!isAuthorised(request.headers.authorization ?? '')) {
    json(response, 401, { error: 'unauthorised' });

    return;
  }

  let payload;

  try {
    payload = JSON.parse(await readBody(request));
  } catch (error) {
    json(response, error?.status ?? 400, { error: error?.message ?? 'invalid_json' });

    return;
  }

  const xml = typeof payload?.xml === 'string' ? payload.xml : '';

  if (xml === '') {
    json(response, 400, { error: 'missing_xml' });

    return;
  }

  json(response, 200, { ...validate(xml), rules_version: RULES_VERSION });
});

if (process.env.NODE_ENV !== 'test') {
  server.listen(PORT, () => {
    process.stdout.write(`konform-validator listening on ${PORT}, rules ${RULES_VERSION}\n`);
  });
}

export { server };
