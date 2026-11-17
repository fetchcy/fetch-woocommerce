// e2e: mock Fetch API + WordPress Playground (WooCommerce + this plugin).
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { startPlayground, wpFetch } from './playground.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(here, '../..');
const WP = 'http://127.0.0.1:9400';
const MOCK = 'http://127.0.0.1:9099';
const results = [];
const ok = (label, cond, extra) => { results.push([cond ? 'PASS' : 'FAIL', label]); console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${!cond && extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
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
const pg = REUSE ? null : startPlayground(here, plugin);
const cleanup = () => { try { pg?.kill('SIGTERM'); } catch {} try { mock?.kill(); } catch {} };
process.on('exit', cleanup);
setTimeout(() => { console.log('E2E HARD TIMEOUT'); for (const r of results) console.log(r.join(' ')); cleanup(); process.exit(3); }, 30 * 60 * 1000).unref();

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

  // --- 1.2.0: Mirror this shop 1:1 ---------------------------------------------------
  const listingBatches = () => mockLog().requests.filter((r) => r.method === 'POST' && r.path.endsWith('/listings/batch'));
  const listingScans = () => mockLog().requests.filter((r) => r.method === 'GET' && r.path.endsWith('/listings'));
  const mockListings = async () => Object.fromEntries((await (await fetch(`${MOCK}/__listings`)).json()).map((l) => [l.listingId, l]));
  const seedListings = (listings) => fetch(`${MOCK}/__seed-listings`, { method: 'POST', body: JSON.stringify({ listings }) });
  const control = (what, body) => fetch(`${MOCK}/__${what}`, { method: 'POST', body: JSON.stringify(body) });
  const sameSet = (a, b) => a.length === b.length && [...a].sort().join() === [...b].sort().join();
  const FOREIGN = ['portal-1', 'other-1', 'ext-1', 'back-1'];
  await seedListings([
    { listingId: 'portal-1', name: 'Portal-only Candle', price: '4.00' }, // made in the merchant portal: no ref
    { listingId: 'other-1', ref: 'OTHER-SHOP-1', name: 'Other Shop Item', price: '8.00' }, // a ref this shop does not have
    { listingId: 'ext-1', ref: 'EXT-1', name: 'Gift card', price: '20.00' }, // an external product: excluded by the plugin
    { listingId: 'back-1', ref: 'BACK-1', name: 'Returning Lamp', price: '17.85' }, // the shop sells it again later
    { listingId: 'off-1', name: 'Already off', price: '1.00', isActive: false }, // already off: left alone
  ]);
  let b0 = listingBatches().length;
  let s0 = listingScans().length;
  const mOff = await step('mirror_off');
  let L = await mockListings();
  ok('mirror off (default): a full sync neither scans nor switches anything off', mOff.mode === 'add_update' && listingBatches().length === b0 && listingScans().length === s0 && FOREIGN.every((id) => L[id].isActive === true) && !mOff.log.some((m) => /Mirror/.test(m)), { mode: mOff.mode, log: mOff.log });

  const activeNow = Object.values(L).filter((l) => l.isActive).length;
  const mPrev = await step('mirror_preview');
  ok('preview: "4 of N Fetch products would be switched off", on the settings page', mPrev.preview?.status === 'preview' && mPrev.preview.off === 4 && mPrev.preview.active === activeNow && JSON.stringify(mPrev.html_count) === JSON.stringify([4, activeNow]), { preview: mPrev.preview, html: mPrev.html_count, activeNow });
  ok('preview lists the names that would be switched off', sameSet(mPrev.html_samples, ['Portal-only Candle', 'Other Shop Item', 'Gift card', 'Returning Lamp']), mPrev.html_samples);
  ok('preview changes nothing: no switch-off, mirror mode not saved', listingBatches().length === b0 && mPrev.mode === 'add_update' && /^Preview: 4 of \d+ Fetch products would be switched off\. Nothing was changed\.$/.test(mPrev.notice || ''), mPrev);
  ok('settings: catalogue mode select, help text, preview in its own form', JSON.stringify(mPrev.html_modes) === '["add_update","mirror"]' && mPrev.html_help && mPrev.html_preview_form, mPrev);

  const countBefore = Object.keys(L).length;
  b0 = listingBatches().length;
  const mOn = await step('mirror_on');
  const onRows = listingBatches().slice(b0).flatMap((r) => r.body.items);
  L = await mockListings();
  ok('mirror on: switches off exactly what this shop does not sell (no ref, foreign ref, excluded product)', mOn.mode === 'mirror' && sameSet(onRows.map((r) => r.listingId), FOREIGN) && FOREIGN.every((id) => L[id].isActive === false), { mode: mOn.mode, onRows });
  ok('switch-off rows: by listingId, current price, isActive false', onRows.length > 0 && onRows.every((r) => Object.keys(r).sort().join() === 'isActive,listingId,price' && r.isActive === false) && onRows.find((r) => r.listingId === 'other-1')?.price === '8.00' && L['other-1'].price === '8.00', onRows);
  ok('the shop\'s own products stay on; listings already off are not touched', L['lst-CBL-001']?.isActive === true && Object.values(L).filter((l) => /^Big Pack/.test(l.name) && l.isActive).length === 209 && !onRows.some((r) => r.listingId === 'off-1'), L['lst-CBL-001']);
  ok('nothing is deleted on Fetch', Object.keys(L).length === countBefore, { before: countBefore, after: Object.keys(L).length });
  ok('mirror run logged: scanned, kept, switched off, errors', mOn.log.some((m) => /^Mirror finished: scanned \d+ Fetch products, kept \d+, switched off 4, 0 errors\.$/.test(m)) && mOn.pending === null, mOn.log);

  b0 = listingBatches().length;
  const mBack = await step('mirror_comeback');
  L = await mockListings();
  ok('a product that comes back is switched on again by the normal sync, and the mirror keeps it', mBack.synced === 'synced' && L['back-1'].isActive === true && !listingBatches().slice(b0).some((r) => r.body.items.some((i) => i.listingId === 'back-1')) && mBack.log.some((m) => /^Mirror finished: .*switched off 0, 0 errors\.$/.test(m)), { synced: mBack.synced, back: L['back-1'], log: mBack.log });

  // 300 old listings: the store now spans two scan pages of 500, and the run would switch off more than half.
  await seedListings(Array.from({ length: 300 }, (_, i) => ({ listingId: `bulk-${String(i).padStart(3, '0')}`, name: `Old item ${i}`, price: '2.00' })));
  await control('fail-listings-page', { page: 2 });
  b0 = listingBatches().length;
  s0 = listingScans().length;
  const mFail = await step('mirror_sync');
  await control('fail-listings-page', { page: 0 });
  ok('a scan page failing (page 2 of 2) aborts the mirror: nothing switched off', listingScans().length - s0 === 2 && listingBatches().length === b0 && mFail.pending === null && mFail.log.some((m) => /^Mirror aborted, nothing was switched off: .*page 2: HTTP 500/.test(m)), { scans: listingScans().length - s0, log: mFail.log });

  await control('fail-puts', { fail: true });
  s0 = listingScans().length;
  const mPush = await step('mirror_sync');
  await control('fail-puts', { fail: false });
  ok('a full sync that could not reach Fetch skips the mirror: no scan, nothing switched off', listingScans().length === s0 && listingBatches().length === b0 && mPush.log.some((m) => /^Mirror skipped/.test(m)), mPush.log);

  const mGuard = await step('mirror_sync');
  ok('over 50%: paused, nothing switched off, logged', listingBatches().length === b0 && mGuard.pending?.off === 300 && mGuard.log.some((m) => /^Mirror paused: it would switch off 300 of \d+ active Fetch products, more than half/.test(m)), { pending: mGuard.pending, log: mGuard.log });
  ok('admin notice asks to confirm, with "Apply anyway"', mGuard.notice_shown && mGuard.apply_button && mGuard.notice_text?.[0] === 300 && mGuard.notice_text[1] === mGuard.pending.active, mGuard);

  const mApply = await step('mirror_apply');
  const applyBatches = listingBatches().slice(b0);
  L = await mockListings();
  ok('"Apply anyway" queues one confirmed run for 300', JSON.stringify(mApply.scheduled) === '[{"up_to":300}]' && mApply.pending_after_confirm === null && /^Confirmed\./.test(mApply.notice || ''), mApply);
  ok('it switches off all 300 in batches of at most 200 (200 + 100)', JSON.stringify(applyBatches.map((r) => r.body.items.length)) === '[200,100]' && Object.values(L).filter((l) => /^bulk-/.test(l.listingId) && !l.isActive).length === 300, applyBatches.map((r) => r.body.items.length));
  ok('each switch-off request has its own idempotency key', applyBatches.every((r) => r.idem) && new Set(applyBatches.map((r) => r.idem)).size === applyBatches.length, applyBatches.map((r) => r.idem));
  ok('notice cleared and the run logged', !mApply.notice_shown && mApply.pending === null && mApply.log.some((m) => /^Mirror finished: .*switched off 300, 0 errors\.$/.test(m)) && mApply.mode_after === 'add_update', mApply.log);
  ok('mirror requests pass the live rules (≤200 rows, ≤500 KB, price on every row, limit ≤500)', mockLog().problems.length === 0 && listingScans().every((r) => r.query.limit === '500'), mockLog().problems);

  // --- 1.2.1: multilingual shops (Polylang) sync the default language only ---------------
  const act = await step('lang_activate');
  ok('Polylang activated', act.active === true && act.error === null, act);
  await step('lang_setup', { admin: '1' });
  let p0 = productPuts().length;
  const lp = await step('lang_products');
  const lpRows = productPuts().slice(p0).flatMap((r) => r.body.products);
  const TRANSLATED = ['CCAF 011-el', 'CCAF 011-ru', 'TMUG-BLK-el'];
  const rowsFor = (rows, ref) => rows.filter((x) => x.ref === ref);
  ok('Polylang set up: English default, products translatable, older products have no language', lp.default === 'en' && sameSet(lp.languages, ['en', 'el', 'ru']) && lp.translated === true && lp.no_language === false, lp);
  ok('saving originals and translations sends only the English products and variations', ['CCAF 011', 'TEA-1', 'TMUG-BLK', 'TMUG-WHT'].every((ref) => rowsFor(lpRows, ref).length === 1) && !lpRows.some((x) => TRANSLATED.includes(x.ref)) && lpRows.every((x) => !/[α-ωА-я]/i.test(x.name)), lpRows.map((x) => `${x.ref} ${x.name}`));
  ok('translations queued before they had a language are skipped at flush, never marked synced', lp.queued.includes(lp.ids.cup_el) && JSON.stringify(lp.translation_status) === '["",""]', { queued: lp.queued, status: lp.translation_status });
  ok('a translation sharing the SKU does not overwrite the original (TEA-1: Green Tea, 5.00 + 10%)', rowsFor(lpRows, 'TEA-1')[0]?.name === 'Green Tea' && rowsFor(lpRows, 'TEA-1')[0]?.price === '5.50' && rowsFor(lpRows, 'TMUG-WHT')[0]?.name === 'Travel Mug – White', { tea: rowsFor(lpRows, 'TEA-1'), white: rowsFor(lpRows, 'TMUG-WHT') });
  ok('editing a translation\'s price or stock queues nothing', Array.isArray(lp.pending_after_translation_edit) && lp.pending_after_translation_edit.length === 0, lp.pending_after_translation_edit);

  // Listings an older plugin version pushed from the translations.
  await seedListings([
    { listingId: 'ml-el', ref: 'CCAF 011-el', name: 'Κούπα καφέ', price: '11.00' },
    { listingId: 'ml-ru', ref: 'CCAF 011-ru', name: 'Кофейная чашка', price: '11.00' },
    { listingId: 'ml-mug-el', ref: 'TMUG-BLK-el', name: 'Κούπα ταξιδιού – Black', price: '8.80' },
  ]);
  b0 = listingBatches().length;
  const lprev = await step('lang_preview', { admin: '1' });
  ok('preview in wp-admin with Polylang filtering on Greek: only the 3 translation listings would go', lprev.is_admin === true && lprev.preview?.off === 3 && sameSet(lprev.preview.samples, ['Κούπα καφέ', 'Кофейная чашка', 'Κούπα ταξιδιού – Black']) && listingBatches().length === b0, lprev);

  p0 = productPuts().length;
  const lm = await step('lang_mirror');
  const lmOff = listingBatches().slice(b0).flatMap((r) => r.body.items);
  const lmRows = productPuts().slice(p0).flatMap((r) => r.body.products);
  L = await mockListings();
  ok('mirror: listings from translations are switched off, the originals are kept', sameSet(lmOff.map((r) => r.listingId), ['ml-el', 'ml-ru', 'ml-mug-el']) && ['lst-CCAF 011', 'lst-TEA-1', 'lst-TMUG-BLK', 'lst-TMUG-WHT', 'lst-CBL-001'].every((id) => L[id]?.isActive === true) && Object.values(L).filter((l) => /^Big Pack/.test(l.name) && l.isActive).length === 209 && lm.log.some((m) => /^Mirror finished: .*switched off 3, 0 errors\.$/.test(m)), { off: lmOff, log: lm.log });
  ok('full sync sends no translation, and a shared SKU once', !lmRows.some((x) => TRANSLATED.includes(x.ref)) && rowsFor(lmRows, 'TEA-1').length === 1 && rowsFor(lmRows, 'TEA-1')[0].name === 'Green Tea' && rowsFor(lmRows, 'CBL-001').length === 1, { tea: rowsFor(lmRows, 'TEA-1'), n: lmRows.length });

  await seedOrder({ ...order, orderId: 'ord-ml', orderNumber: '238410', items: [
    { listingId: 'lst-TEA-1', ref: 'TEA-1', name: 'Green Tea', variantName: null, quantity: 2, unitPrice: '5.50', totalPrice: '11.00', vatRate: '19' },
    { listingId: 'lst-CCAF 011', ref: 'CCAF 011', name: 'Coffee Cup', variantName: null, quantity: 1, unitPrice: '11.00', totalPrice: '11.00', vatRate: '19' },
  ], subtotal: '22.00', total: '24.45' });
  await step('poll_only');
  const lo = await step('lang_order_info', { order: 'ord-ml' });
  ok('order refs resolve to the English originals, even with the SKU and ref copied onto a translation', lo.items?.length === 2 && lo.items.some((i) => i.product_id === lo.ids.tea && i.qty === 2) && lo.items.some((i) => i.product_id === lo.ids.cup && i.qty === 1), lo);
  ok('stock reduced on the originals, translations untouched (8 → 6, 7 → 6)', JSON.stringify(lo.stock) === JSON.stringify({ tea: 6, tea_el: 8, cup: 6, cup_el: 7 }), lo.stock);
  await setStatus('ord-ml', 'DELIVERED');

  p0 = productPuts().length;
  const ld = await step('lang_delete');
  const ldRows = productPuts().slice(p0).flatMap((r) => r.body.products);
  L = await mockListings();
  ok('trashing or deleting translations (incl. one sharing the SKU, and a variation) switches nothing off', ld.pending.length === 0 && ld.deactivations.length === 0 && ldRows.length === 0 && ['lst-CCAF 011', 'lst-TEA-1', 'lst-TMUG-WHT'].every((id) => L[id]?.isActive === true), { ld, ldRows });

  const lr = await step('lang_readout');
  ok('settings: default-language help line and "Synced language: English (default)"', lr.help === true && lr.readout === 'Synced language: English (default)' && lr.deactivated === true, lr);
  const la = await step('lang_after');
  ok('without a multilingual plugin: help line stays, no language readout', la.help === true && la.readout === false, la);
  ok('multilingual requests pass the live rules', mockLog().problems.length === 0, mockLog().problems);

  // --- 1.3.0: brand, specs, descriptions and SEO -----------------------------------------
  const cs = await step('content_setup');
  ok('global attributes Brands (pa_brands) and Width (pa_dimensions) created', cs.attributes?.pa_brands > 0 && cs.attributes?.pa_dimensions > 0, cs);
  p0 = productPuts().length;
  const ct = await step('content');
  const hobRows = productPuts().slice(p0).flatMap((r) => r.body.products).filter((x) => x.ref === 'HOB-65');
  const hob = hobRows[0] || {};
  ok('brand from the global "Brands" attribute (pa_brands)', hob.brand === 'HAIER' && ct.hob_status === 'synced', { brand: hob.brand, status: ct.hob_status });
  ok('specs: visible attributes with their labels, in the shop\'s order, hidden ones left out', JSON.stringify(hob.specs) === JSON.stringify([{ name: 'Brands', value: 'HAIER' }, { name: 'Type', value: 'Induction' }, { name: 'Number of Cooking Zones', value: '4' }, { name: 'Width', value: '65 cm' }]), hob.specs);
  ok('description keeps paragraphs; short description sent', hob.description === 'A 65 cm induction hob.\n\nTouch controls & timer;\ncut-out 56 x 48 cm.' && hob.shortDescription === 'Four zones, Varycook.', { d: hob.description, s: hob.shortDescription });
  ok('seo: Yoast title and Rank Math description (plugins absent: raw values)', JSON.stringify(hob.seo) === JSON.stringify({ title: 'Haier induction hob | Mock Shop', description: 'A 65 cm Haier induction hob with four zones.' }), hob.seo);
  ok('settings: "Brand attribute" select lists Automatic and the global attributes', JSON.stringify(ct.brand_select) === JSON.stringify(['', 'pa_brands', 'pa_dimensions']), ct.brand_select);
  ok('changing the brand attribute saves it and queues a full resync', ct.brand_saved === 'pa_dimensions' && ct.bulk_scheduled === true && /brand attribute changed/.test(ct.brand_notice || ''), ct);
  ok('the chosen brand attribute is used', hobRows.at(-1)?.brand === '65 cm' && hobRows.length === 2, hobRows.map((x) => x.brand));
  ok('an attribute the shop does not have saves as Automatic', ct.unknown_saved_as === '', ct.unknown_saved_as);
  ok('content payloads pass the live rules', mockLog().problems.length === 0, mockLog().problems);

  // --- 1.4.0: several Fetch stores from one site --------------------------------------------
  // The key sees three stores; the shop connects two: store-1 (all products, the default 10%
  // markup) and store-2 (only Dogs, 20%). store-3 is never connected.
  const reqs = () => mockLog().requests;
  const rowsTo = (store, from = 0) => reqs().slice(from).filter((r) => r.method === 'PUT' && r.path === `/api/v1/partner/stores/${store}/products`).flatMap((r) => r.body.products);
  const lastTo = (rows, ref) => rows.filter((x) => x.ref === ref).at(-1);
  const touched3 = (from = 0) => reqs().slice(from).filter((r) => r.path.includes('/stores/store-3/'));
  const listingsIn = async (store) => Object.fromEntries((await (await fetch(`${MOCK}/__listings?store=${store}`)).json()).map((l) => [l.listingId, l]));

  let r0 = reqs().length;
  const ms = await step('multi_setup');
  ok('multi: "Connect & load stores" lists every store the key can access', JSON.stringify(ms.listed) === '["store-1","store-2","store-3"]' && /3 stores found/.test(ms.connect_notice || ''), { listed: ms.listed, notice: ms.connect_notice });
  ok('multi: each store has a "Connect" checkbox; the previously chosen store is ticked', JSON.stringify(ms.choices) === '[["store-1",true],["store-2",false],["store-3",false]]', ms.choices);
  ok('multi: two stores connected, each with its own markup and category filter; the first stays primary', JSON.stringify(Object.keys(ms.stores)) === '["store-1","store-2"]' && ms.stores['store-1'].markup === 10 && ms.stores['store-2'].markup === 20 && ms.stores['store-2'].categories[0] === ms.ids.dogs && ms.stores['store-2'].name === 'Branch B' && ms.primary === 'store-1', ms.stores);
  ok('multi: connecting a second store starts its full sync only (job carries its store)', JSON.stringify(ms.bulk) === '[{"page":1,"store":"store-2"}]' && /being sent to the Fetch stores/.test(ms.save_notice || ''), { bulk: ms.bulk, notice: ms.save_notice });
  let b2 = rowsTo('store-2', r0);
  ok('multi: store-2\'s full sync sends only its categories (a Dogs subcategory), at its 20% markup', JSON.stringify([...new Set(b2.map((x) => x.ref))]) === '["DOG-KIB"]' && lastTo(b2, 'DOG-KIB')?.price === '24.00' && lastTo(b2, 'DOG-KIB')?.stockCount === 10, b2.map((x) => `${x.ref} ${x.price}`));
  ok('multi: the first store keeps getting everything at the default 10%', lastTo(rowsTo('store-1', r0), 'DOG-KIB')?.price === '22.00' && lastTo(rowsTo('store-1', r0), 'CAT-TOY')?.price === '5.50', rowsTo('store-1', r0).map((x) => `${x.ref} ${x.price}`));
  ok('multi: nothing reaches the store that is not connected', touched3(0).length === 0, touched3(0));
  ok('multi: store-2 log lines carry its name', ms.log.some((m) => /^\[Branch B\] Full product sync finished\.$/.test(m)) && ms.log.some((m) => /^\[Branch B\] Synced \d+ product\(s\) to Fetch/.test(m)), ms.log);

  r0 = reqs().length;
  const mp = await step('multi_products');
  ok('multi: a new product is queued once per store that takes it', JSON.stringify(mp.pending['store-1']) === JSON.stringify([mp.ids.bowl, mp.ids.bed]) && JSON.stringify(mp.pending['store-2']) === JSON.stringify([mp.ids.bowl]), mp.pending);
  ok('multi: one flush job per store (the first store keeps the 1.3.0 arguments)', mp.flush_jobs.length === 2 && mp.flush_jobs.some((a) => JSON.stringify(a) === '[]') && mp.flush_jobs.some((a) => a.store === 'store-2'), mp.flush_jobs);
  const mp1 = rowsTo('store-1', r0);
  const mp2 = rowsTo('store-2', r0);
  ok('multi: right products and prices reach each store (Dog Bowl 8 → 8.80 / 9.60, Cat Bed only to store-1)', lastTo(mp1, 'DOG-BOWL')?.price === '8.80' && lastTo(mp1, 'CAT-BED')?.price === '33.00' && lastTo(mp2, 'DOG-BOWL')?.price === '9.60' && !mp2.some((x) => x.ref === 'CAT-BED'), { mp1: mp1.map((x) => `${x.ref} ${x.price}`), mp2: mp2.map((x) => `${x.ref} ${x.price}`) });
  ok('multi: same ref in both stores; Fetch product id kept per store', mp.meta.bowl[0] === 'prod-DOG-BOWL' && mp.meta.bowl[1] === 'prod-DOG-BOWL' && mp.meta.bowl[2] === 'DOG-BOWL' && mp.meta.bed[0] === 'prod-CAT-BED' && mp.meta.bed[1] === '', mp.meta);
  ok('multi: settings show each store\'s own fields and a status row (last full sync, products sent, last order, errors)', JSON.stringify(mp.sections) === '["Mock Shop","Branch B"]' && JSON.stringify(mp.store_markups) === '["","20"]' && /Last full sync: (?!not yet)/.test(mp.status['store-2'] || '') && /Products sent: 2 /.test(mp.status['store-2'] || '') && /Last order: not yet/.test(mp.status['store-2'] || '') && /Products with a sync error: 0/.test(mp.status['store-2'] || ''), { sections: mp.sections, markups: mp.store_markups, status: mp.status });
  ok('multi: still nothing reaches store-3', touched3(0).length === 0, touched3(0));

  // An order from store B (store-2), and one from store-3 that the shop does not serve.
  const orderB = { ...order, orderId: 'ord-b1', orderNumber: 'B-1001', storeId: 'store-2', items: [{ listingId: 'lst-DOG-BOWL', ref: 'DOG-BOWL', name: 'Dog Bowl', variantName: null, quantity: 2, unitPrice: '9.60', totalPrice: '19.20', vatRate: '19' }], subtotal: '19.20', total: '21.65' };
  await seedOrder(orderB);
  await seedOrder({ ...orderB, orderId: 'ord-c2', orderNumber: 'C-2001', storeId: 'store-3' });
  r0 = reqs().length;
  const mo = await step('multi_order');
  const accB = reqs().slice(r0).filter((r) => /\/orders\/ord-b1\/accept$/.test(r.path));
  ok('multi: the poll imports store-2\'s order, tagged with store-2', mo.found === true && JSON.stringify(mo.store_meta) === '["store-2","Branch B"]' && mo.notes.some((n) => /^Fetch order B-1001 for Branch B imported/.test(n)), mo);
  ok('multi: each connected store is polled, store-3 is not', reqs().slice(r0).some((r) => r.path === '/api/v1/partner/stores/store-1/orders') && reqs().slice(r0).some((r) => r.path === '/api/v1/partner/stores/store-2/orders') && touched3(r0).length === 0 && mo.store3_order === false, reqs().slice(r0).map((r) => r.path));
  ok('multi: order box shows the store; orders list has a "Fetch store" column', /Fetch store:<\/strong> <span class="fetch-wc-store">Branch B<\/span>/.test(mo.box) && JSON.stringify(mo.columns) === '["order_number","order_status","fetch_store","order_total"]' && /Branch B/.test(mo.column), { columns: mo.columns, column: mo.column });
  ok('multi: Accept goes to the order\'s own store (store-2)', mo.accept_error === null && accB.length === 1 && accB[0].path === '/api/v1/partner/stores/store-2/orders/ord-b1/accept' && accB[0].body.prepMinutes === 12 && mo.status[0] === 'processing' && mo.status[1] === 'ACCEPTED', { accB, status: mo.status });
  const ms1 = rowsTo('store-1', r0);
  const ms2 = rowsTo('store-2', r0);
  ok('multi: stock is shared: store-2\'s order reduces Woo stock once (10 → 8), and 8 goes to both stores', mo.stock === 8 && mo.pending["store-1"].includes(mp.ids.bowl) && mo.pending['store-2'].includes(mp.ids.bowl) && lastTo(ms1, 'DOG-BOWL')?.stockCount === 8 && lastTo(ms2, 'DOG-BOWL')?.stockCount === 8, { stock: mo.stock, pending: mo.pending, ms1: lastTo(ms1, 'DOG-BOWL'), ms2: lastTo(ms2, 'DOG-BOWL') });

  // Webhooks: one URL and secret for the key; routed by the payload's storeId.
  r = await postWebhook('order.created', 'evt_b2', { ...orderB, orderId: 'ord-b2', orderNumber: 'B-1002' });
  const wb = await step('order_info', { order: 'ord-b2' });
  ok('multi: a webhook for store-2 is imported as store-2\'s order', r.status === 200 && wb.count === 1 && wb.order?.notes.some((n) => /for Branch B imported/.test(n)), { http: r.status, wb });
  r = await postWebhook('order.created', 'evt_c1', { ...orderB, orderId: 'ord-c1', orderNumber: 'C-2002', storeId: 'store-3' });
  const rb = await r.json();
  const wc = await step('order_info', { order: 'ord-c1' });
  ok('multi: a webhook for a store that is not connected is acknowledged and ignored', r.status === 200 && rb.ignored === 'store not connected' && wc.count === 0, { http: r.status, rb, count: wc.count });
  await setStatus('ord-b1', 'DELIVERED');
  await setStatus('ord-b2', 'DELIVERED');

  // The webhook order's stock change is queued for both stores; send it while both answer.
  r0 = reqs().length;
  await step('multi_flush', { store: 'store-1' });
  const fl2 = await step('multi_flush', { store: 'store-2' });
  ok('multi: the webhook order from store-2 (2 bowls, 8 → 6) is shared stock too: 6 reaches both stores', lastTo(rowsTo('store-1', r0), 'DOG-BOWL')?.stockCount === 6 && lastTo(rowsTo('store-2', r0), 'DOG-BOWL')?.stockCount === 6 && fl2.pending.length === 0 && fl2.pending_2.length === 0, { s1: lastTo(rowsTo('store-1', r0), 'DOG-BOWL'), s2: lastTo(rowsTo('store-2', r0), 'DOG-BOWL'), fl2 });

  // One store's API error does not block the other.
  await control('fail-puts', { fail: true, store: 'store-1' });
  r0 = reqs().length;
  const mi = await step('multi_isolation');
  await control('fail-puts', { fail: false });
  ok('multi: store-1 unreachable: its change stays queued, with its error on its status row', JSON.stringify(mi.pending['store-1']) === JSON.stringify([ms.ids.kibble]) && /HTTP 503/.test(mi.status['store-1'].last_error || '') && mi.meta[0] === 'error', mi);
  ok('multi: store-2 is not blocked: the new price (25 → 30.00) arrives, its status has no error', lastTo(rowsTo('store-2', r0), 'DOG-KIB')?.price === '30.00' && mi.pending['store-2'].length === 0 && !mi.status['store-2'].last_error && mi.meta[1] === 'synced', { rows: rowsTo('store-2', r0), status: mi.status['store-2'] });
  ok('multi: the ignored store is logged once (not per order)', mi.ignored_logged === 1, mi.ignored_logged);
  ok('multi: errors are logged with the store\'s name', mi.log.some((m) => /^\[Mock Shop\] Product sync failed for \d+ product\(s\): HTTP 503/.test(m)), mi.log);
  r0 = reqs().length;
  const mf = await step('multi_flush', { store: 'store-1' });
  ok('multi: store-1 catches up once Fetch answers (27.50)', lastTo(rowsTo('store-1', r0), 'DOG-KIB')?.price === '27.50' && mf.pending.length === 0, rowsTo('store-1', r0));

  // Mirror on store A (store-1) only.
  await seedListings([{ listingId: 'a-only', ref: 'A-ONLY', name: 'Store A extra', price: '3.00' }]);
  await fetch(`${MOCK}/__seed-listings`, { method: 'POST', body: JSON.stringify({ store: 'store-2', listings: [{ listingId: 'b-only', ref: 'B-ONLY', name: 'Store B extra', price: '3.00' }] }) });
  r0 = reqs().length;
  const mm = await step('multi_mirror');
  const mmReqs = reqs().slice(r0);
  const LA = await listingsIn('store-1');
  const LB = await listingsIn('store-2');
  ok('multi: catalogue mode per store: store-1 mirrors, store-2 and the default do not', JSON.stringify(mm.modes) === '[true,false,false]' && JSON.stringify(mm.preview_buttons) === '["store-1"]' && mm.preview_store === 'store-1' && /^\[Mock Shop\] Preview: /.test(mm.preview_notice || ''), mm);
  ok('multi: mirror on store-1 switches off its own extra listing', LA['a-only']?.isActive === false && mmReqs.some((x) => x.path === '/api/v1/partner/stores/store-1/listings/batch' && x.body.items.some((i) => i.listingId === 'a-only')) && mm.log.some((m) => /^\[Mock Shop\] Mirror finished: .*switched off [1-9]\d*, 0 errors\.$/.test(m)), { a: LA['a-only'], log: mm.log });
  ok('multi: …and never touches store-2 (no scan, no switch-off; its extra listing stays on)', LB['b-only']?.isActive === true && !mmReqs.some((x) => x.path.startsWith('/api/v1/partner/stores/store-2/listings')) && !mm.log.some((m) => /^\[Branch B\] Mirror/.test(m)), { b: LB['b-only'], paths: [...new Set(mmReqs.map((x) => x.path))] });
  ok('multi: store-2\'s full sync keeps its category filter (Cat products never sent there)', !rowsTo('store-2', 0).some((x) => /^CAT-/.test(x.ref)) && touched3(0).length === 0, [...new Set(rowsTo('store-2', 0).map((x) => x.ref))]);
  ok('multi: store-1 back on the default mode', mm.mode_after === false, mm.mode_after);

  const md = await step('multi_disconnect');
  ok('multi: unticking a store disconnects it and drops its queue', JSON.stringify(md.stores) === '["store-1"]' && md.pending_after === 'gone', md);
  ok('multi: one store again: no per-store fields, no "Fetch store" column; the status row stays', md.per_store_fields === false && JSON.stringify(md.columns) === '["order_number","order_status"]' && /Last order: /.test(md.status['store-1'] || ''), md);
  ok('multi: an order keeps showing the store it came from after that store is disconnected', md.order_b_store === 'Branch B', md.order_b_store);
  ok('multi-store requests pass the live rules', mockLog().problems.length === 0, mockLog().problems);

  const srv = await step('server');
  ok('server select offers Production, Staging, Custom URL', JSON.stringify(srv.options) === '["production","staging","custom"]' && srv.help === true, srv);
  ok('existing "custom" server kept, its URL field shown', srv.custom_url_kept === 'http://127.0.0.1:9099' && srv.custom_shown === true, srv);
  ok('switching to Staging uses api-stg and clears the cached token', srv.staging_url === 'https://api-stg.fetch.com.cy' && srv.token_before === true && srv.token_after === false, srv);
  ok('Staging selected hides the custom URL field', srv.staging_selected === true && srv.custom_hidden === true, srv);
  ok('unknown server value saves as Production', srv.garbage_saved_as === 'production' && srv.production_url === 'https://api.fetch.com.cy', srv);

  const fc = await step('fresh_connect');
  ok('fresh connection: "Connect & load stores" connects the first store; ticking it in the form keeps it', fc.before[0] === false && JSON.stringify(fc.after) === '[true,"store-1",["store-1"]]' && JSON.stringify(fc.final) === '[true,"store-1",["store-1"]]', fc);
} catch (e) {
  console.error('E2E ERROR', e);
  results.push(['FAIL', 'runner error: ' + e.message]);
} finally {
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  cleanup();
  process.exit(failed ? 1 : 0);
}
