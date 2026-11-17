// Upgrade e2e: WordPress Playground runs the plugin as released in 1.3.0 (from git),
// connected to one store and syncing; then its files are replaced with this checkout's,
// as a plugin update does, and the shop must still be connected and syncing, with
// no reconfiguration and no duplicate products on Fetch.
// UPGRADE_FROM=<git ref> picks the old version; the default is the commit that made 1.3.0.
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { startPlayground, wpFetch } from './playground.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '../..');
const WP = 'http://127.0.0.1:9400';
const MOCK = 'http://127.0.0.1:9099';
const results = [];
const ok = (label, cond, extra) => { results.push([cond ? 'PASS' : 'FAIL', label]); console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${!cond && extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const mockLog = () => JSON.parse(fs.readFileSync(path.join(here, 'mock-requests.json'), 'utf8'));
const listings = async () => (await fetch(`${MOCK}/__listings`)).json();

// -S also matches the commit that later removed the line, so take the oldest: the one that added it.
const from = process.env.UPGRADE_FROM || execFileSync('git', ['-C', repo, 'log', '--reverse', '--format=%H', '-S', "define( 'FETCH_WC_VERSION', '1.3.0' )", '--', 'fetch-for-woocommerce.php']).toString().trim().split('\n')[0];
const plugin = fs.mkdtempSync(path.join(os.tmpdir(), 'fetch-wc-upgrade-'));
execFileSync('sh', ['-c', `git -C "${repo}" archive "${from}" | tar -x -C "${plugin}"`]);
console.log(`old version from ${from} in ${plugin}`);

const mock = spawn('node', [path.join(here, 'mock-fetch.mjs')], { stdio: 'inherit' });
const pg = startPlayground(here, plugin);
const cleanup = () => { try { pg.kill('SIGTERM'); } catch {} try { mock.kill(); } catch {} try { fs.rmSync(plugin, { recursive: true, force: true }); } catch {} };
process.on('exit', cleanup);
setTimeout(() => { console.log('UPGRADE E2E HARD TIMEOUT'); cleanup(); process.exit(3); }, 20 * 60 * 1000).unref();

async function step(name, params = {}) {
  const r = await wpFetch(`${WP}/e2e/e2e-driver.php?${new URLSearchParams({ step: name, ...params })}`);
  const t = await r.text();
  try { return JSON.parse(t); } catch { throw new Error(`step ${name} returned non-JSON (${r.status}): ${t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 1500)}`); }
}

try {
  for (let i = 0; i < 150; i++) {
    try { const r = await wpFetch(`${WP}/e2e/e2e-driver.php?step=ping`); if (r.status === 200 && (await r.json()).ready === true) break; } catch {}
    await sleep(3000);
  }

  // --- 1.3.0: one store, a 10% markup, products synced, an order imported, a full sync queued.
  const v13 = await step('version');
  ok('1.3.0 is running', v13.version === '1.3.0', v13.version);
  const cfg = await step('configure');
  ok('1.3.0: connected to store-1', cfg.stores?.code === 200 && cfg.webhook_register?.body?.signingSecret === 'whsec_test', cfg);
  const prod = await step('create_products');
  ok('1.3.0: products synced', prod.simple_meta?.pid === 'prod-CBL-001' && prod.simple_meta.status === 'synced', prod.simple_meta);
  const mk = await step('markup');
  ok('1.3.0: 10% markup saved and resent', mk.saved === 10, mk);
  await fetch(`${MOCK}/__seed-order`, { method: 'POST', body: JSON.stringify({
    orderId: 'ord-1', orderNumber: '238400', storeId: 'store-1', status: 'DRAFT', placedAt: new Date().toISOString(), deliveryType: 'delivery', currency: 'EUR',
    customer: { name: 'Maria Georgiou', phone: '+35799123456', address: { line1: 'Makariou 12', city: 'Nicosia', postalCode: '1065', notes: '' } },
    items: [{ listingId: 'lst-CBL-001', ref: 'CBL-001', name: 'USB-C Cable 1m', variantName: null, quantity: 1, unitPrice: '10.45', totalPrice: '10.45', vatRate: '19' }],
    subtotal: '10.45', shippingFee: '2.45', total: '12.90',
  }) });
  const polled = await step('poll');
  ok('1.3.0: order imported', polled.found === true && polled.count_after_second_poll === 1, polled);
  const queued = await step('upgrade_queue_bulk');
  ok('1.3.0: a full sync is queued when the update lands', JSON.stringify(queued.bulk) === '[{"page":1}]', queued);
  const before = await listings();
  const vBefore = await step('version');
  const settingsBefore = vBefore.settings;

  // --- The update: the same files a release ZIP carries, replaced in place.
  for (const f of ['fetch-for-woocommerce.php', 'uninstall.php', 'includes']) {
    fs.rmSync(path.join(plugin, f), { recursive: true, force: true });
    fs.cpSync(path.join(repo, f), path.join(plugin, f), { recursive: true });
  }
  const v14 = await step('version');
  ok('1.4.0 is running', v14.version === '1.4.0', v14.version);
  ok('the update changes no setting by itself', JSON.stringify(Object.fromEntries(Object.entries(v14.settings).filter(([k]) => k !== 'stores'))) === JSON.stringify(Object.fromEntries(Object.entries(settingsBefore).filter(([k]) => k !== 'stores'))) && v14.settings.stores === null, { before: settingsBefore, after: v14.settings });

  const p0 = mockLog().requests.length;
  const up = await step('upgrade_check');
  const after = mockLog().requests.slice(p0);
  const puts = after.filter((r) => r.method === 'PUT' && r.path.endsWith('/products'));
  const rows = puts.flatMap((r) => r.body.products);
  ok('1.4.0: still connected to the same store with the same markup, nothing reconfigured', up.connected === true && JSON.stringify(up.stores) === '{"store-1":["Mock Shop",10]}', up);
  ok('1.4.0: product data from 1.3.0 is read as is (Fetch id, frozen ref, result)', JSON.stringify(up.meta) === '["prod-CBL-001","CBL-001","synced"]', up.meta);
  ok('1.4.0: the full sync 1.3.0 queued runs for that store and goes on with the same job arguments', up.legacy_bulk_next === true, up);
  const leftByV13 = vBefore.pending;
  ok('1.4.0: the queue 1.3.0 left is kept exactly as it was (same option, nothing migrated or lost)', leftByV13.length > 0 && JSON.stringify(v14.pending) === JSON.stringify(leftByV13) && JSON.stringify(up.pending_before_change) === JSON.stringify(leftByV13), { leftByV13, after: v14.pending, beforeChange: up.pending_before_change });
  ok('1.4.0: a stock change syncs to the store as before (same queue, same job, 10% markup)', up.pending.includes(prod.simple_id) && up.pending.every((id) => id === prod.simple_id || leftByV13.includes(id)) && JSON.stringify(up.pending_after_flush) === '[]' && up.flush_scheduled === true && up.status_after === 'synced' && rows.some((x) => x.ref === 'CBL-001' && x.stockCount === 3 && x.price === '10.45'), { up, rows: rows.filter((x) => x.ref === 'CBL-001') });
  ok('1.4.0: every request goes to store-1', after.filter((r) => r.path.startsWith('/api/v1/partner/stores/')).every((r) => r.path.startsWith('/api/v1/partner/stores/store-1/')), [...new Set(after.map((r) => r.path))]);
  ok('1.4.0: the settings page shows the store ticked, with its status', up.ticked === true && /Last full sync: /.test(up.status_cell?.['store-1'] || ''), up);
  ok('1.4.0: saving the new form unchanged keeps the store and resends nothing', up.save_notice === 'Settings saved.' && JSON.stringify(up.after_save) === '[true,"store-1",["store-1"],10,false]', up);
  ok('1.4.0: an order imported by 1.3.0 is accepted against its store', up.accept_error === null && after.some((r) => r.path === '/api/v1/partner/stores/store-1/orders/ord-1/accept'), up.accept_error);
  ok('1.4.0: polling does not import that order twice', up.orders === 1, up.orders);
  const afterListings = await listings();
  const refs = afterListings.map((l) => l.ref);
  ok('1.4.0: a full sync after the update creates no duplicate products on Fetch', afterListings.length === before.length && new Set(refs).size === refs.length && rows.every((x) => before.some((l) => l.ref === x.ref)), { before: before.length, after: afterListings.length });
  ok('requests pass the live rules', mockLog().problems.length === 0, mockLog().problems);
} catch (e) {
  console.error('UPGRADE E2E ERROR', e);
  results.push(['FAIL', 'runner error: ' + e.message]);
} finally {
  const failed = results.filter((r) => r[0] === 'FAIL').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  cleanup();
  process.exit(failed ? 1 : 0);
}
