// Starts WordPress Playground with WooCommerce, Polylang (inactive) and this plugin.
// By default Playground downloads WooCommerce and Polylang from wordpress.org.
// E2E_PLUGIN_CACHE=<dir> holding woocommerce.zip and polylang.zip mounts them from
// there instead, for networks where those downloads fail.
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';

/**
 * A request to Playground on a fresh connection each time. fetch() keeps sockets
 * alive, and a request sent on one Playground has just closed after an idle gap
 * never arrives, so the runner hangs. Playground auto-logs-in by redirecting any
 * request without its cookie back to itself, hence the cookie and no redirects.
 * Answers like fetch(): status, text(), json().
 */
export function wpFetch(url, opts = {}, timeoutMs = 300000) {
  return new Promise((resolve, reject) => {
    const req = http.request(url, { method: opts.method || 'GET', agent: false, headers: { ...(opts.headers || {}), ...(opts.body ? { 'content-length': Buffer.byteLength(opts.body) } : {}), cookie: 'playground_auto_login_already_happened=1', connection: 'close' } }, (res) => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (c) => (body += c));
      res.on('end', () => resolve({ status: res.statusCode, text: async () => body, json: async () => JSON.parse(body) }));
    });
    req.setTimeout(timeoutMs, () => req.destroy(new Error(`timeout after ${timeoutMs} ms: ${url}`)));
    req.on('error', reject);
    if (opts.body) req.write(opts.body);
    req.end();
  });
}

export function startPlayground(here, pluginDir) {
  const mounts = [`--mount=${pluginDir}:/wordpress/wp-content/plugins/fetch-woocommerce`, `--mount=${here}:/wordpress/e2e`];
  let blueprint = path.join(here, 'blueprint.json');
  const cache = process.env.E2E_PLUGIN_CACHE;
  if (cache) {
    const bp = JSON.parse(fs.readFileSync(blueprint, 'utf8'));
    for (const slug of ['woocommerce', 'polylang']) {
      if (!fs.existsSync(path.join(cache, slug))) execFileSync('unzip', ['-q', '-o', path.join(cache, `${slug}.zip`), '-d', cache]);
      mounts.push(`--mount=${path.join(cache, slug)}:/wordpress/wp-content/plugins/${slug}`);
    }
    bp.steps = bp.steps.flatMap((s) => {
      if (s.step !== 'installPlugin') return [s];
      return s.options?.activate ? [{ step: 'activatePlugin', pluginPath: `${s.pluginData.slug}/${s.pluginData.slug}.php` }] : [];
    });
    blueprint = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'fetch-wc-bp-')), 'blueprint.json');
    fs.writeFileSync(blueprint, JSON.stringify(bp));
  }
  const pg = spawn('npx', ['--yes', '@wp-playground/cli@latest', 'server', '--port=9400', `--blueprint=${blueprint}`, ...mounts], { stdio: ['ignore', 'pipe', 'pipe'] });
  pg.stdout.on('data', (d) => process.stdout.write('[pg] ' + d));
  pg.stderr.on('data', (d) => process.stdout.write('[pg!] ' + d));
  return pg;
}
