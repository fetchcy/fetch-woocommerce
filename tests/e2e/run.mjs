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
const productPuts = () => mockLog().requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products'));
const setStatus = (orderId, status) => fetch(`${MOCK}/__set-status`, { method: 'POST', body: JSON.stringify({ orderId, status }) });
const seedOrder = (o) => fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify(o) });
function postWebhook(event, eventId, data) {
  const raw = JSON.stringify({ event, eventId, sentAt: new Date().toISOString(), data });
  const ts = Math.floor(Date.now() / 1000);
  const v1 = crypto.createHmac('sha256', 'whsec_test').update(`${ts}.${raw}`).digest('hex');
  return wpFetch(`${WP}/wp-json/fetch/v1/webhook`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Fetch-Signature': `t=${ts},v1=${v1}` }, body: raw });
}

const REUSE = !!process.env.E2E_REUSE; // reuse an already-running mock + Playground
const mock = REUSE ? null : spawn('node', [path.join(here, 'mock-fetch.mjs')], { stdio: 'inherit' });
const pg = REUSE ? null : spawn('npx', ['--yes', '@wp-playground/cli@latest', 'server', '--port=9400', `--blueprint=${path.join(here, 'blueprint.json')}`,
  `--mount=${plugin}:/wordpress/wp-content/plugins/fetch-woocommerce`, `--mount=${here}:/wordpress/e2e`], { stdio: ['ignore', 'pipe', 'pipe'] });
pg?.stdout.on('data', (d) => process.stdout.write('[pg] ' + d));
pg?.stderr.on('data', (d) => process.stdout.write('[pg!] ' + d));
const cleanup = () => { try { pg?.kill('SIGTERM'); } catch {} try { mock?.kill(); } catch {} };
process.on('exit', cleanup);
setTimeout(() => { console.log('E2E HARD TIMEOUT'); for (const r of results) console.log(r.join(' ')); cleanup(); process.exit(3); }, 12 * 60 * 1000).unref();

async function step(name, params = {}, retry = true) {
  const r = await wpFetch(`${WP}/e2e/e2e-driver.php?${new URLSearchParams({ step: name, ...params })}`);
  if (r.status >= 500 && retry && name === 'configure') { await sleep(3000); return step(name, params, false); }
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

  const beforeMarkup = puts2.length;
  const mk = await step('markup');
  log = mockLog();
  const puts3 = log.requests.filter((r) => r.method === 'PUT' && r.path.endsWith('/products')).slice(beforeMarkup);
  const marked = puts3.flatMap((r) => r.body.products);
  const mSimple = marked.find((p) => p.ref === 'CBL-001');
  const mBlack = marked.find((p) => p.ref === 'CASE-BLACK');
  ok('markup 10% saved through the settings form', mk.saved === 10 && /markup changed/.test(mk.notice || ''), mk);
  ok('changing the markup queues a full product resync', mk.bulk_scheduled === true, mk);
  ok('mock received marked-up price + sale price (9.50 → 10.45, 7.00 → 7.70)', mSimple?.price === '10.45' && mSimple?.salePrice === '7.70', mSimple);
  ok('variation price marked up (12.00 → 13.20)', mBlack?.price === '13.20', mBlack);
  ok('markup leaves WooCommerce prices unchanged', mk.wc_price === '9.5', mk.wc_price);
  ok('saving the same markup does not resync', mk.same_bulk_scheduled === false && mk.same_notice === 'Settings saved.', mk);
  ok('marked-up payloads pass mock validation', log.problems.length === 0, log.problems);

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
  ok('line linked to WooCommerce product by SKU, at Fetch\'s price (no markup stripped)', o.items?.some((i) => i.product_id > 0 && i.qty === 2 && Number(i.total) === 14), o.items);
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

  // --- 1.1.1: live batch limits ------------------------------------------------------
  const beforeBig = productPuts().length;
  const big = await step('many_variations');
  const bigPuts = productPuts().slice(beforeBig).filter((r) => r.body.products.some((x) => /^Big Pack/.test(x.name)));
  const bigRows = bigPuts.flatMap((r) => r.body.products).filter((x) => /^Big Pack/.test(x.name));
  ok('210 variation rows sent in several PUTs of at most 200', bigPuts.length >= 2 && bigPuts.every((r) => r.body.products.length <= 200) && new Set(bigRows.map((x) => x.ref)).size === 210, bigPuts.map((r) => r.body.products.length));
  ok('every variation recorded as synced, nothing left queued', big.variations_synced === 210 && big.pending_after?.length === 0, big);
  ok('batched payloads pass the live rules (≤200 rows, ≤500 KB, price on every row)', mockLog().problems.length === 0, mockLog().problems);

  // --- 1.1.1: deleting / trashing sends a full inactive row --------------------------
  const beforeDel = productPuts().length;
  const del = await step('delete_products');
  const delRows = productPuts().slice(beforeDel).flatMap((r) => r.body.products);
  const lastRow = (ref) => delRows.filter((x) => x.ref === ref).at(-1);
  const firstRow = (ref) => delRows.find((x) => x.ref === ref);
  const lamp = lastRow('LAMP-1');
  ok('permanently deleted product → full inactive row (price, stock, name)', del.synced === true && lamp?.isActive === false && lamp.price === firstRow('LAMP-1')?.price && lamp.stockCount === 3 && lamp.name === 'Desk Lamp' && lamp.productType === 'SIMPLE', { del, lamp });
  const cb = lastRow('CASE-BLACK');
  const cw = lastRow('CASE-WHITE');
  ok('variable product deleted via the WooCommerce API → each variation switched off as a full row, once', cb?.isActive === false && cb.price === '13.20' && cb.name === 'Phone Case – Black' && cw?.isActive === false && cw.price === '13.20' && cw.name === 'Phone Case – White' && delRows.filter((x) => x.ref === 'CASE-BLACK').length === 1, { cb, cw, queued: del.deactivations_queued });
  const lone = lastRow(del.variation_ref);
  ok('a single variation deleted on its own → its full row, switched off', !!del.variation_ref && lone?.isActive === false && lone.name === 'Big Pack – 1' && lone.price === '3.30', { ref: del.variation_ref, lone });
  const mug = lastRow('MUG-1');
  ok('trashed product → full inactive row', mug?.isActive === false && mug.price === firstRow('MUG-1')?.price && mug.stockCount === 3, mug);
  ok('deactivation queue emptied after a successful send', del.deactivations_after === 0, del);
  ok('deactivation rows pass the live rules (price on every row)', mockLog().problems.length === 0, mockLog().problems);

  // Fetch is the source of truth for the orders seeded above; keep the mock in step.
  await setStatus('ord-1', 'CANCELLED');

  // --- 1.1.1: status changes follow Fetch --------------------------------------------
  const ord2 = { ...order, orderId: 'ord-2', orderNumber: '238401', items: [order.items[1]], subtotal: '5.00', total: '7.45' };
  let r = await postWebhook('order.status_changed', 'evt_status_2', { ...ord2, status: 'ACCEPTED', previousStatus: 'DRAFT' });
  let o2 = await step('order_info', { order: 'ord-2' });
  ok('order.status_changed webhook moves the WooCommerce order (DRAFT → ACCEPTED = processing)', r.status === 200 && o2.order?.status === 'processing' && o2.order.fetch_status === 'ACCEPTED' && o2.count === 1, { http: r.status, o2 });
  await setStatus('ord-2', 'DELIVERED');
  await step('poll_only');
  o2 = await step('order_info', { order: 'ord-2' });
  ok('2-minute poll picks up a later status (DELIVERED = completed)', o2.order?.status === 'completed' && o2.order.fetch_status === 'DELIVERED' && o2.count === 1, o2);

  // --- 1.1.1: masked customer details filled in later ---------------------------------
  const maskedCustomer = { name: '******', phone: '******', address: { line1: '******', city: '******', postalCode: '******', notes: '******' } };
  const ord3 = { ...order, orderId: 'ord-3', orderNumber: '238402', items: [order.items[1]], subtotal: '5.00', total: '7.45', customer: maskedCustomer };
  await seedOrder(ord3);
  await step('poll_only');
  let o3 = await step('order_info', { order: 'ord-3' });
  ok('order first seen masked is imported without "******" and flagged', o3.order?.has_mask === false && o3.order.name === 'Fetch customer' && o3.order.masked === '1' && o3.order.phone === '', o3);
  await seedOrder({ ...ord3, status: 'ACCEPTED', customer: order.customer });
  await step('poll_only');
  o3 = await step('order_info', { order: 'ord-3' });
  ok('a later unmasked copy fills in name, phone, address and note', o3.order?.name === 'Maria Georgiou' && o3.order.phone === '+35799123456' && o3.order.address === 'Makariou 12 Flat 3 / Nicosia / 1065' && o3.order.shipping === 'Makariou 12 Flat 3' && o3.order.note === 'Ring twice' && o3.order.masked === '' && o3.order.status === 'processing' && o3.order.notes.some((n) => /Customer details completed/.test(n)), o3);
  r = await postWebhook('order.status_changed', 'evt_status_3', { ...ord3, status: 'DELIVERED', previousStatus: 'ACCEPTED' });
  o3 = await step('order_info', { order: 'ord-3' });
  ok('masked data never overwrites real details (status still follows)', r.status === 200 && o3.order?.name === 'Maria Georgiou' && o3.order.address.startsWith('Makariou 12') && o3.order.has_mask === false && o3.order.status === 'completed' && o3.count === 1, o3);
  await setStatus('ord-3', 'DELIVERED');

  // --- 1.1.1: webhook + poll for the same order → one WooCommerce order ----------------
  const ord4 = { ...order, orderId: 'ord-4', orderNumber: '238403', items: [order.items[1]], subtotal: '5.00', total: '7.45' };
  await seedOrder(ord4);
  const held = await step('hold_lock', { order: 'ord-4' });
  const t0 = Date.now();
  r = await postWebhook('order.created', 'evt_created_4', ord4);
  const waited = Date.now() - t0;
  await step('poll_only');
  let o4 = await step('order_info', { order: 'ord-4' });
  ok('while another request imports the order, webhook waits then answers 503 and poll skips it', held.held === true && r.status === 503 && waited >= 4000 && o4.count === 0, { held, http: r.status, waited, count: o4.count });
  await step('release_lock', { order: 'ord-4' });
  const [retry] = await Promise.all([postWebhook('order.created', 'evt_created_4', ord4), step('poll_only'), postWebhook('order.created', 'evt_created_4b', ord4)]);
  o4 = await step('order_info', { order: 'ord-4' });
  ok('webhook retry + poll + second delivery at once → exactly one WooCommerce order', retry.status === 200 && o4.count === 1 && o4.order?.fetch_status === 'DRAFT', { http: retry.status, o4 });
  await step('poll_only');
  r = await postWebhook('order.created', 'evt_created_4c', ord4);
  o4 = await step('order_info', { order: 'ord-4' });
  ok('a new eventId for an order already imported is deduped by Fetch orderId', r.status === 200 && o4.count === 1, o4.count);

  const srv = await step('server');
  ok('server select offers Production, Staging, Custom URL', JSON.stringify(srv.options) === '["production","staging","custom"]' && srv.help === true, srv);
  ok('existing "custom" server kept, its URL field shown', srv.custom_url_kept === 'http://127.0.0.1:9099' && srv.custom_shown === true, srv);
  ok('switching to Staging uses api-stg and clears the cached token', srv.staging_url === 'https://api-stg.fetch.com.cy' && srv.token_before === true && srv.token_after === false, srv);
  ok('Staging selected hides the custom URL field', srv.staging_selected === true && srv.custom_hidden === true, srv);
  ok('unknown server value saves as Production', srv.garbage_saved_as === 'production' && srv.production_url === 'https://api.fetch.com.cy', srv);
} catch (e) {
  console.error('E2E ERROR', e);
  results.push(['FAIL', 'runner error: ' + e.message]);
} finally {
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  cleanup();
  process.exit(failed ? 1 : 0);
}
