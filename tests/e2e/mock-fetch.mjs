// Minimal mock of the Fetch Partner API for the e2e test. Records every request.
import http from 'node:http';
import fs from 'node:fs';

const PORT = Number(process.env.MOCK_PORT || 9099);
const LOG = process.env.MOCK_LOG || new URL('./mock-requests.json', import.meta.url).pathname;
const STORE = 'store-1';
const requests = [];
const orders = new Map(); // orderId → order
const problems = [];

const money = (v) => typeof v === 'string' && /^\d+\.\d{2}$/.test(v);
function validateProduct(p, i) {
  const at = `products[${i}]`;
  if (!p.ref || typeof p.ref !== 'string') problems.push(`${at}.ref missing`);
  if (!p.name || typeof p.name !== 'string') problems.push(`${at}.name missing`);
  // Live Fetch stores accept SIMPLE products only through PUT /products.
  if (p.productType !== 'SIMPLE') problems.push(`${at}.productType=${p.productType} (live accepts SIMPLE only)`);
  if (p.variants || p.matrix) problems.push(`${at} carries variants/matrix`);
  if (p.price !== undefined && !money(p.price)) problems.push(`${at}.price not 2-decimal string: ${p.price}`);
  if (p.stockCount !== undefined && !Number.isInteger(p.stockCount)) problems.push(`${at}.stockCount not int`);
}

function send(res, code, body) {
  res.writeHead(code, { 'content-type': 'application/json' });
  res.end(JSON.stringify(body));
}

const server = http.createServer((req, res) => {
  let raw = '';
  req.on('data', (c) => (raw += c));
  req.on('end', () => {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    const body = raw ? JSON.parse(raw) : null;
    requests.push({ method: req.method, path: url.pathname, query: Object.fromEntries(url.searchParams), body, auth: req.headers.authorization || null, idem: req.headers['idempotency-key'] || null });
    fs.writeFileSync(LOG, JSON.stringify({ requests, problems }, null, 2));

    const p = url.pathname;
    if (req.method === 'POST' && p === '/api/v1/oauth/token') {
      if (body?.grant_type !== 'client_credentials' || body.client_secret !== 'secret-1') return send(res, 401, { message: 'invalid_client' });
      return send(res, 200, { access_token: 'tok-1', token_type: 'Bearer', expires_in: 3600 });
    }
    // Test control (no auth): seed an order / force a status.
    if (req.method === 'POST' && p === '/__seed-order') { orders.set(body.orderId, body); return send(res, 200, { ok: true }); }
    if (req.method === 'POST' && p === '/__set-status') { const o = orders.get(body.orderId); if (o) o.status = body.status; return send(res, 200, o || {}); }
    if (req.headers.authorization !== 'Bearer tok-1') return send(res, 401, { message: 'Unauthorized' });

    if (req.method === 'GET' && p === '/api/v1/partner/stores') return send(res, 200, { data: [{ id: STORE, name: 'Mock Shop', city: 'Nicosia', isActive: true }] });

    if (req.method === 'PUT' && p === `/api/v1/partner/stores/${STORE}/products`) {
      (body?.products || []).forEach(validateProduct);
      fs.writeFileSync(LOG, JSON.stringify({ requests, problems }, null, 2));
      const results = (body?.products || []).map((x, i) => ({ ref: x.ref, productId: `prod-${x.ref}`, status: 'created', matchedBy: null }));
      return send(res, 200, { created: results.length, updated: 0, rejected: 0, results });
    }

    if (req.method === 'GET' && p === `/api/v1/partner/stores/${STORE}/orders`) return send(res, 200, { data: [...orders.values()], nextCursor: null });

    const m = p.match(new RegExp(`^/api/v1/partner/stores/${STORE}/orders/([^/]+)(?:/(accept|ready|reject))?$`));
    if (m) {
      const o = orders.get(m[1]);
      if (!o) return send(res, 404, { message: 'not found' });
      if (req.method === 'GET' && !m[2]) return send(res, 200, o);
      const next = { accept: 'ACCEPTED', ready: 'READY_FOR_PICKUP', reject: 'REJECTED' }[m[2]];
      const allowed = { accept: ['DRAFT'], ready: ['ACCEPTED', 'PREPARING'], reject: ['DRAFT', 'ACCEPTED'] }[m[2]];
      if (!allowed.includes(o.status)) return send(res, 409, { message: `cannot ${m[2]} from ${o.status}` });
      o.status = next;
      return send(res, 200, o);
    }

    if (req.method === 'PUT' && p === '/api/v1/partner/webhook') return send(res, 200, { url: body.url, signingSecret: 'whsec_test', signatureHeader: 'X-Fetch-Signature', toleranceSeconds: 300 });

    send(res, 404, { message: `no mock for ${req.method} ${p}` });
  });
});
server.listen(PORT, '127.0.0.1', () => console.log(`mock fetch on ${PORT}`));
