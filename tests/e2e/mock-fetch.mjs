// Minimal mock of the Fetch Partner API for the e2e test. Records every request.
// Applies the live PUT /products and listings/batch rules: price on every row,
// at most 200 rows and 500 KB per request. Keeps each store's listings so
// GET /listings pages through what PUT /products and listings/batch wrote.
// One key sees three stores (a chain's branches); refs and listings are per store.
import http from 'node:http';
import fs from 'node:fs';

const PORT = Number(process.env.MOCK_PORT || 9099);
const LOG = process.env.MOCK_LOG || new URL('./mock-requests.json', import.meta.url).pathname;
const STORES = [
  { id: 'store-1', name: 'Mock Shop', city: 'Nicosia', isActive: true },
  { id: 'store-2', name: 'Branch B', city: 'Limassol', isActive: true },
  { id: 'store-3', name: 'Branch C', city: 'Larnaca', isActive: true },
];
const STORE_IDS = STORES.map((x) => x.id);
const requests = [];
const orders = new Map(); // orderId → order (its storeId says which store's; none = store-1)
const problems = [];
const stores = new Map(STORE_IDS.map((id) => [id, new Map()])); // storeId → (listingId → listing), in insertion order
const listingsOf = (store) => stores.get(store || 'store-1');
let failListingsPage = 0; // 1-based page of GET /listings to answer 500, 0 = none
let failPuts = false; // PUT /products answers 503 (Fetch unreachable): true for every store, or one store id
let clock = Date.parse('2026-01-01T00:00:00Z');
const tick = () => new Date((clock += 1000)).toISOString();
const byRef = (listings, ref) => [...listings.values()].find((l) => l.ref === ref);
function upsertListing(listings, p) {
  const l = byRef(listings, p.ref) || { listingId: `lst-${p.ref}`, productId: `prod-${p.ref}`, variantId: null, ref: p.ref, barcode: null, sku: null, categoryPath: [], salePrice: null, saleEndsAt: null, currency: 'EUR', stockCount: null, trackStock: false };
  Object.assign(l, { name: p.name, price: p.price, salePrice: p.salePrice ?? null, isActive: p.isActive !== false, updatedAt: tick() });
  if (p.stockCount !== undefined) Object.assign(l, { stockCount: p.stockCount, trackStock: true });
  listings.set(l.listingId, l);
  return l;
}

const money = (v) => typeof v === 'string' && /^\d+\.\d{2}$/.test(v);
function validateProduct(p, i) {
  const at = `products[${i}]`;
  if (!p.ref || typeof p.ref !== 'string') problems.push(`${at}.ref missing`);
  if (!p.name || typeof p.name !== 'string') problems.push(`${at}.name missing`);
  // Live Fetch stores accept SIMPLE products only through PUT /products.
  if (p.productType !== 'SIMPLE') problems.push(`${at}.productType=${p.productType} (live accepts SIMPLE only)`);
  if (p.variants || p.matrix) problems.push(`${at} carries variants/matrix`);
  if (p.price === undefined) problems.push(`${at}.price missing (required on a live store)`);
  if (p.price !== undefined && !money(p.price)) problems.push(`${at}.price not 2-decimal string: ${p.price}`);
  if (p.salePrice !== undefined && !money(p.salePrice)) problems.push(`${at}.salePrice not 2-decimal string: ${p.salePrice}`);
  if (p.stockCount !== undefined && !Number.isInteger(p.stockCount)) problems.push(`${at}.stockCount not int`);
  // Optional content fields (1.3.0), with the live UpsertProductDto limits.
  const text = (v, max) => typeof v === 'string' && v.length <= max;
  if (p.description !== undefined && !text(p.description, 5000)) problems.push(`${at}.description not a string ≤ 5000`);
  if (p.shortDescription !== undefined && !text(p.shortDescription, 5000)) problems.push(`${at}.shortDescription not a string ≤ 5000`);
  if (p.specs !== undefined) {
    if (!Array.isArray(p.specs) || p.specs.length > 100) problems.push(`${at}.specs not an array of ≤ 100`);
    else p.specs.forEach((x, j) => { if (!x || !text(x.name, 100) || !text(x.value, 1000) || !x.name || !x.value || Object.keys(x).length !== 2) problems.push(`${at}.specs[${j}] not {name ≤ 100, value ≤ 1000}`); });
  }
  if (p.seo !== undefined) {
    const s = p.seo;
    if (!s || typeof s !== 'object' || Array.isArray(s) || Object.keys(s).some((k) => k !== 'title' && k !== 'description')) problems.push(`${at}.seo not {title?, description?}`);
    else {
      if (s.title !== undefined && !text(s.title, 255)) problems.push(`${at}.seo.title not a string ≤ 255`);
      if (s.description !== undefined && !text(s.description, 1000)) problems.push(`${at}.seo.description not a string ≤ 1000`);
    }
  }
}

// No keep-alive: Playground's PHP network bridge can reuse a socket the server is
// closing after an idle gap, and that request then never arrives.
function send(res, code, body) {
  res.writeHead(code, { 'content-type': 'application/json', connection: 'close' });
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
    // Listings the shop never sent: made in the portal (no ref) or by another integration.
    if (req.method === 'POST' && p === '/__seed-listings') {
      const listings = listingsOf(body.store);
      for (const x of body.listings) listings.set(x.listingId, { productId: `prod-${x.listingId}`, variantId: null, ref: null, barcode: null, sku: null, categoryPath: [], salePrice: null, saleEndsAt: null, currency: 'EUR', stockCount: null, trackStock: false, isActive: true, ...x, updatedAt: tick() });
      return send(res, 200, { count: listings.size });
    }
    if (req.method === 'POST' && p === '/__fail-listings-page') { failListingsPage = Number(body.page) || 0; return send(res, 200, { failListingsPage }); }
    if (req.method === 'POST' && p === '/__fail-puts') { failPuts = body.fail ? (body.store || true) : false; return send(res, 200, { failPuts }); }
    if (req.method === 'GET' && p === '/__listings') return send(res, 200, [...listingsOf(url.searchParams.get('store')).values()]);
    if (req.method === 'POST' && p === '/__reset-listings') { listingsOf(body?.store).clear(); return send(res, 200, { ok: true }); }
    if (req.headers.authorization !== 'Bearer tok-1') return send(res, 401, { message: 'Unauthorized' });

    if (req.method === 'GET' && p === '/api/v1/partner/stores') return send(res, 200, { data: STORES });

    // Every other partner route is per store; a store the key cannot access is 404, as live.
    const sm = p.match(/^\/api\/v1\/partner\/stores\/([^/]+)(\/.*)$/);
    if (sm && !STORE_IDS.includes(decodeURIComponent(sm[1]))) return send(res, 404, { message: 'Store not found' });
    const STORE = sm ? decodeURIComponent(sm[1]) : null;
    const listings = STORE ? listingsOf(STORE) : null;

    if (req.method === 'PUT' && p === `/api/v1/partner/stores/${STORE}/products`) {
      if (failPuts === true || failPuts === STORE) return send(res, 503, { message: 'Service unavailable' });
      const list = body?.products || [];
      if (list.length > 200) problems.push(`PUT /products with ${list.length} products (live max 200)`);
      if (Buffer.byteLength(raw) > 500 * 1024) problems.push(`PUT /products body ${Buffer.byteLength(raw)} bytes (max 500 KB)`);
      list.forEach(validateProduct);
      fs.writeFileSync(LOG, JSON.stringify({ requests, problems }, null, 2));
      const results = list.map((x) => { const l = upsertListing(listings, x); return { ref: x.ref, productId: l.productId, listingId: l.listingId, status: 'created', matchedBy: null }; });
      return send(res, 200, { created: results.length, updated: 0, rejected: 0, results });
    }

    // Keyset-paged like live: ordered by (updatedAt, id), limit max 500, nextCursor until null.
    if (req.method === 'GET' && p === `/api/v1/partner/stores/${STORE}/listings`) {
      const limit = Number(url.searchParams.get('limit') || 200);
      if (limit > 500) problems.push(`GET /listings limit=${limit} (max 500)`);
      const cursor = url.searchParams.get('cursor');
      const after = cursor ? JSON.parse(Buffer.from(cursor, 'base64url').toString()) : null;
      const page = after ? after.page + 1 : 1;
      if (failListingsPage && page === failListingsPage) return send(res, 500, { message: 'Internal server error' });
      const sorted = [...listings.values()].sort((a, b) => a.updatedAt.localeCompare(b.updatedAt) || a.listingId.localeCompare(b.listingId));
      const rest = after ? sorted.filter((l) => l.updatedAt > after.k || (l.updatedAt === after.k && l.listingId > after.id)) : sorted;
      const data = rest.slice(0, Math.min(limit, 500));
      const last = data.at(-1);
      const nextCursor = rest.length > data.length && last ? Buffer.from(JSON.stringify({ k: last.updatedAt, id: last.listingId, page })).toString('base64url') : null;
      return send(res, 200, { data, nextCursor });
    }

    if (req.method === 'POST' && p === `/api/v1/partner/stores/${STORE}/listings/batch`) {
      const list = body?.items || [];
      if (!req.headers['idempotency-key']) problems.push('listings/batch without Idempotency-Key');
      if (list.length > 200) problems.push(`listings/batch with ${list.length} rows (live max 200)`);
      if (Buffer.byteLength(raw) > 500 * 1024) problems.push(`listings/batch body ${Buffer.byteLength(raw)} bytes (max 500 KB)`);
      const results = list.map((x, i) => {
        const at = `items[${i}]`;
        if (x.price === undefined) problems.push(`${at}.price missing (required on a live store)`);
        else if (!money(x.price)) problems.push(`${at}.price not 2-decimal string: ${x.price}`);
        const given = [x.listingId, x.ref, x.barcode].filter(Boolean);
        if (given.length !== 1) return { ref: x.ref ?? null, listingId: x.listingId ?? null, status: 'error', code: given.length ? 'ambiguous_ref' : 'ref_not_found', message: 'Provide exactly one of listingId, ref or barcode' };
        const l = x.listingId ? listings.get(x.listingId) : byRef(listings, x.ref);
        if (!l) return { ref: x.ref ?? null, listingId: x.listingId ?? null, status: 'error', code: 'ref_not_found', message: 'No listing matches this reference in this store' };
        if (x.price === undefined) return { ref: l.ref, listingId: l.listingId, status: 'error', code: 'price_required', message: 'price is required' };
        for (const k of ['price', 'salePrice', 'stockCount', 'isActive']) if (x[k] !== undefined) l[k] = x[k];
        l.updatedAt = tick();
        return { ref: l.ref, listingId: l.listingId, status: 'updated' };
      });
      fs.writeFileSync(LOG, JSON.stringify({ requests, problems }, null, 2));
      const rejected = results.filter((r) => r.status === 'error').length;
      return send(res, rejected === results.length && results.length ? 422 : rejected ? 207 : 200, { accepted: results.length - rejected, rejected, results });
    }

    const storeOf = (o) => o.storeId || 'store-1';
    if (req.method === 'GET' && p === `/api/v1/partner/stores/${STORE}/orders`) return send(res, 200, { data: [...orders.values()].filter((o) => storeOf(o) === STORE), nextCursor: null });

    const m = STORE && sm[2].match(/^\/orders\/([^/]+)(?:\/(accept|ready|reject))?$/);
    if (m) {
      const o = orders.get(decodeURIComponent(m[1]));
      // An order is only reachable through its own store.
      if (!o || storeOf(o) !== STORE) return send(res, 404, { message: 'not found' });
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
