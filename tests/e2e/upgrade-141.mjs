// Upgrade e2e 1.4.0 -> 1.4.1: WordPress Playground runs the plugin as released in 1.4.0 (from git),
// connected and syncing; then its files are replaced with this checkout's, as a plugin update does.
// "Exclude backorder items" must come up ON, nothing else may change, and it must then apply.
// UPGRADE_FROM=<git ref> picks the old version; the default is the commit that made 1.4.0.
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
const from = process.env.UPGRADE_FROM || execFileSync('git', ['-C', repo, 'log', '--reverse', '--format=%H', '-S', "define( 'FETCH_WC_VERSION', '1.4.0' )", '--', 'fetch-for-woocommerce.php']).toString().trim().split('\n')[0];
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
const putRows = (from) => mockLog().requests.slice(from).filter((r) => r.method === 'PUT' && r.path.endsWith('/products')).flatMap((r) => r.body.products);

try {
  for (let i = 0; i < 150; i++) {
    try { const r = await wpFetch(`${WP}/e2e/e2e-driver.php?step=ping`); if (r.status === 200 && (await r.json()).ready === true) break; } catch {}
    await sleep(3000);
  }

  // --- 1.4.0: connected to store-1 with a 10% markup, products synced, one product in stock.
  const v140 = await step('version');
  ok('1.4.0 is running', v140.version === '1.4.0', v140.version);
  const cfg = await step('configure');
  ok('1.4.0: connected to store-1', cfg.stores?.code === 200, cfg);
  const prod = await step('create_products');
  ok('1.4.0: products synced', prod.simple_meta?.status === 'synced', prod.simple_meta);
  await step('markup');
  const vBefore = await step('version');
  const listingsBefore = await listings();

  // --- The update.
  for (const f of ['fetch-for-woocommerce.php', 'uninstall.php', 'includes']) {
    fs.rmSync(path.join(plugin, f), { recursive: true, force: true });
    fs.cpSync(path.join(repo, f), path.join(plugin, f), { recursive: true });
  }
  const v141 = await step('version');
  const strip = (o) => JSON.stringify(Object.fromEntries(Object.entries(o).filter(([k]) => k !== 'exclude_backorders')));
  ok('1.4.1 is running', v141.version === '1.4.1', v141.version);
  ok('upgrade: "Exclude backorder items" defaults ON, and nothing else in the settings changed', v141.settings.exclude_backorders === 'yes' && strip(v141.settings) === strip(vBefore.settings), { before: vBefore.settings, after: v141.settings });
  ok('upgrade: the saved option itself was not rewritten (no migration)', v141.raw_has_key === false, v141);

  const p0 = mockLog().requests.length;
  const up = await step('upgrade_141');
  const rows = putRows(p0);
  ok('upgrade: still connected, same store, same markup', up.connected === true && JSON.stringify(up.stores) === '{"store-1":["Mock Shop",10]}', up);
  ok('upgrade: the settings form shows the box ticked; saving it untouched resends nothing and keeps it on', up.checkbox_ticked === true && up.save_notice === 'Settings saved.' && up.after_save === true && up.bulk_after_save === false, up);
  ok('upgrade: a product in stock syncs as before (stock 3 at the 10% markup)', rows.some((x) => x.ref === 'CBL-001' && x.stockCount === 3 && x.price === '10.45'), rows);
  ok('upgrade: a product on backorder that Fetch already has is sent at stockCount 0, still active', rows.some((x) => x.ref === 'CBL-001-BO' && x.stockCount === 0 && x.isActive === true), rows);
  ok('upgrade: a new backorder product is not created on Fetch (no request row, no Fetch id)', !rows.some((x) => x.ref === 'CBL-NEWBO') && up.new_bo_pid === '', { rows, up });
  const p1 = mockLog().requests.length;
  const back = await step('upgrade_141_return');
  ok('upgrade: when stock returns it is created and syncs normally (stock 5)', putRows(p1).some((x) => x.ref === 'CBL-NEWBO' && x.stockCount === 5) && !!back.new_bo_pid_after, { back });
  const afterListings = await listings();
  const refs = afterListings.map((l) => l.ref);
  ok('upgrade: no duplicates on Fetch, every earlier listing is still there', new Set(refs).size === refs.length && listingsBefore.every((l) => refs.includes(l.ref)), { before: listingsBefore.length, after: afterListings.length });
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
