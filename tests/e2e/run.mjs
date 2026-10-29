// e2e: mock Fetch API + WordPress Playground (WooCommerce + this plugin).
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(here, '../..');
const WP = 'http://127.0.0.1:9400';
const MOCK = 'http://127.0.0.1:9099';
const results = [];
const ok = (label, cond, extra) => { results.push([cond ? 'PASS' : 'FAIL', label]); console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${!cond && extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// Playground auto-logs-in by 302-redirecting any request without this cookie
// back to itself; fetch drops cookies, so without it every call loops.
const wpFetch = (url, opts = {}) => fetch(url, { ...opts, redirect: 'manual', signal: AbortSignal.timeout(120000), headers: { ...(opts.headers || {}), cookie: 'playground_auto_login_already_happened=1' } });
const mockLog = () => JSON.parse(fs.readFileSync(path.join(here, 'mock-requests.json'), 'utf8'));

const REUSE = !!process.env.E2E_REUSE; // reuse an already-running mock + Playground
const mock = REUSE ? null : spawn('node', [path.join(here, 'mock-fetch.mjs')], { stdio: 'inherit' });
const pg = REUSE ? null : spawn('npx', ['--yes', '@wp-playground/cli@latest', 'server', '--port=9400', `--blueprint=${path.join(here, 'blueprint.json')}`,
  `--mount=${plugin}:/wordpress/wp-content/plugins/fetch-woocommerce`, `--mount=${here}:/wordpress/e2e`], { stdio: ['ignore', 'pipe', 'pipe'] });
pg?.stdout.on('data', (d) => process.stdout.write('[pg] ' + d));
pg?.stderr.on('data', (d) => process.stdout.write('[pg!] ' + d));
const cleanup = () => { try { pg?.kill('SIGTERM'); } catch {} try { mock?.kill(); } catch {} };
process.on('exit', cleanup);
setTimeout(() => { console.log('E2E HARD TIMEOUT'); for (const r of results) console.log(r.join(' ')); cleanup(); process.exit(3); }, 12 * 60 * 1000).unref();

async function step(name, retry = true) {
  const r = await wpFetch(`${WP}/e2e/e2e-driver.php?step=${name}`);
  if (r.status >= 500 && retry && name === 'configure') { await sleep(3000); return step(name, false); }
  const t = await r.text();
  try { return JSON.parse(t); } catch { throw new Error(`step ${name} returned non-JSON (${r.status}): ${t.slice(t.indexOf('<body') > -1 ? t.indexOf('<body') : 0).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 1500)}`); }
}

try {
  // Wait for Playground.
  // Playground serves requests before its blueprint has finished installing
  // WooCommerce and activating the plugin; wait until both are loaded.
  for (let i = 0; i < 150; i++) {
    try { const r = await wpFetch(`${WP}/e2e/e2e-driver.php?step=ping`); if (r.status === 200 && (await r.json()).ready === true) break; } catch {}
    await sleep(3000);
  }

  const cfg = await step('configure');
  ok('plugin active with WooCommerce', cfg.active === true && !!cfg.wc, cfg);
  ok('connect: GET /partner/stores via token', cfg.stores?.code === 200 && cfg.stores.body.data[0].id === 'store-1', cfg.stores);
  ok('register webhook returns secret', cfg.webhook_register?.body?.signingSecret === 'whsec_test', cfg.webhook_register);

  const prod = await step('create_products');
  ok('product changes queued + flush scheduled', prod.pending?.length >= 2 && prod.scheduled === true, prod);
  let log = mockLog();
  const puts = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products'));
  const all = puts.flatMap((r) => r.body.products);
  const simple = all.find((p) => p.ref === 'CBL-001');
  const black = all.find((p) => p.ref === 'CASE-BLACK');
  const white = all.find((p) => p.ref === 'CASE-WHITE');
  ok('simple product sent with SKU ref, VAT-incl prices, stock', simple && simple.productType === 'SIMPLE' && simple.price === '9.50' && simple.salePrice === '7.00' && simple.stockCount === 10 && simple.isActive === true, simple);
  ok('variable product sent as one SIMPLE product per variation (live Fetch refuses VARIABLE)',
    !all.some((p) => p.productType === 'VARIABLE' || p.ref === 'CASE')
    && black?.productType === 'SIMPLE' && black.stockCount === 4 && black.price === '12.00' && /Black/.test(black.name)
    && white?.productType === 'SIMPLE' && white.stockCount === 0, { black, white });
  ok('PUT payloads pass mock validation', log.problems.length === 0, log.problems);
  ok('idempotency key sent', puts.every((r) => !!r.idem), puts.map((r) => r.idem));
  ok('result recorded on product (ref frozen, Fetch id, synced)', prod.simple_meta.ref === 'CBL-001' && prod.simple_meta.pid === 'prod-CBL-001' && prod.simple_meta.status === 'synced', prod.simple_meta);

  const before = puts.length;
  const st = await step('change_stock');
  log = mockLog();
  const puts2 = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products'));
  const last = puts2.at(-1)?.body.products.find((p) => p.ref === 'CBL-001');
  ok('stock change sends a second PUT with new stock', puts2.length > before && last?.stockCount === 6, { pending: st.pending, last });

  // Seed a DRAFT order referencing the simple product's SKU, plus one unknown line.
  const order = {
    orderId: 'ord-1', orderNumber: '238400', storeId: 'store-1', status: 'DRAFT', placedAt: new Date().toISOString(), scheduledFor: null,
    deliveryType: 'delivery', paymentMethod: 'CARD', currency: 'EUR',
    customer: { name: 'Maria Georgiou', phone: '+35799123456', address: { line1: 'Makariou 12 Flat 3', city: 'Nicosia', postalCode: '1065', notes: 'Ring twice' } },
    items: [
      { listingId: 'lst-1', ref: 'CBL-001', name: 'USB-C Cable 1m', variantName: null, quantity: 2, unitPrice: '7.00', totalPrice: '14.00', vatRate: '19' },
      { listingId: 'lst-2', ref: null, name: 'Screen protector', variantName: null, quantity: 1, unitPrice: '5.00', totalPrice: '5.00', vatRate: '19' },
    ],
    subtotal: '19.00', shippingFee: '2.45', total: '21.45',
  };
  await fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify(order) });
  const polled = await step('poll');
  const o = polled.order || {};
  ok('poll imported the Fetch order', polled.found === true, polled);
  ok('imported order is on-hold (DRAFT)', o.status === 'on-hold' && o.fetch_status === 'DRAFT', o);
  ok('line linked to WooCommerce product by SKU', o.items?.some((i) => i.product_id > 0 && i.qty === 2 && Number(i.total) === 14), o.items);
  ok('unknown line kept as a named line', o.items?.some((i) => i.product_id === 0 && i.name === 'Screen protector'), o.items);
  ok('shipping line + total match Fetch', o.shipping?.[0]?.total === '2.45' || Number(o.shipping?.[0]?.total) === 2.45 && Number(o.total) === 21.45, { shipping: o.shipping, total: o.total });
  ok('total equals Fetch total', Number(o.total) === 21.45, o.total);
  ok('customer, phone, address, note mapped', o.billing?.includes('Maria Georgiou') && o.billing.includes('+35799123456') && o.billing.includes('Makariou 12') && o.note === 'Ring twice', o);
  ok('stock reduced on import (6 → 4)', polled.stock === 4 && o.stock_reduced === true, { stock: polled.stock, reduced: o.stock_reduced });
  ok('second poll does not duplicate', polled.count_after_second_poll === 1, polled.count_after_second_poll);

  await fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify({ ...order, orderId: 'ord-2', orderNumber: '238401', items: [order.items[1]], subtotal: '5.00', total: '7.45' }) });
  const p2 = await step('poll2');
  ok('a second Fetch order becomes its own WooCommerce order', p2.ids?.[0] && p2.ids?.[1] && p2.ids[0] !== p2.ids[1] && p2.ord2_fetch_id === 'ord-2', p2);
  ok('lookup of an unknown Fetch order finds nothing', p2.missing === null, p2.missing);

  const box = await step('render_box');
  ok('order box: buttons use formaction, no nested <form>', !box.html.includes('<form') && box.html.includes('formaction=') && box.html.includes('value="accept"') && box.html.includes('value="reject"') && !box.html.includes('value="ready"'), box.html);

  const acc = await step('accept');
  log = mockLog();
  const acceptReq = log.requests.find((r) => r.path.endsWith('/orders/ord-1/accept'));
  ok('accept calls Fetch with prepMinutes', acceptReq?.body?.prepMinutes === 25, acceptReq);
  ok('accept → WooCommerce processing', acc.error === null && acc.status === 'processing' && acc.fetch_status === 'ACCEPTED', acc);
  ok('second accept is refused by Fetch and reported', typeof acc.second_accept_error === 'string' && acc.second_accept_error.includes('409'), acc.second_accept_error);

  // Signed order.cancelled webhook.
  const cancelled = { ...order, status: 'CANCELLED' };
  const body = JSON.stringify({ event: 'order.cancelled', eventId: 'evt_cancel_1', sentAt: new Date().toISOString(), data: { ...cancelled, previousStatus: 'ACCEPTED' } });
  const t = Math.floor(Date.now() / 1000);
  const sig = crypto.createHmac('sha256', 'whsec_test').update(`${t}.${body}`).digest('hex');
  const good = await wpFetch(`${WP}/wp-json/fetch/v1/webhook`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Fetch-Signature': `t=${t},v1=${sig}` }, body });
  ok('signed webhook accepted (200)', good.status === 200, await good.text());
  const after = await step('state');
  ok('webhook cancelled the WooCommerce order', after.status === 'cancelled' && after.fetch_status === 'CANCELLED', after);
  ok('stock restored on cancel (4 → 6)', after.stock === 6, after.stock);

  const replay = await wpFetch(`${WP}/wp-json/fetch/v1/webhook`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Fetch-Signature': `t=${t},v1=${sig}` }, body });
  ok('replayed event id is acknowledged as duplicate', replay.status === 200 && (await replay.json()).duplicate === true);

  const bad = await wpFetch(`${WP}/wp-json/fetch/v1/webhook`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Fetch-Signature': `t=${t},v1=${'0'.repeat(64)}` }, body: body.replace('evt_cancel_1', 'evt_x') });
  ok('badly signed webhook → 401', bad.status === 401, bad.status);
  const unsigned = await wpFetch(`${WP}/wp-json/fetch/v1/webhook`, { method: 'POST', headers: { 'content-type': 'application/json' }, body });
  ok('unsigned webhook → 401', unsigned.status === 401, unsigned.status);
} catch (e) {
  console.error('E2E ERROR', e);
  results.push(['FAIL', 'runner error: ' + e.message]);
} finally {
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  cleanup();
  process.exit(failed ? 1 : 0);
}
