const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

process.env.DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'fa-test-'));
process.env.ADMIN_PASSWORD = 'secret-pass';
process.env.STRIPE_WEBHOOK_SECRET = 'whsec_test';
delete process.env.STRIPE_SECRET_KEY;

const app = require('../server');
const { orders } = require('../lib/db');
let server, base, cookie;

before(async () => {
  server = app.listen(0);
  await new Promise(r => server.once('listening', r));
  base = `http://127.0.0.1:${server.address().port}`;
});
after(() => { server.close(); fs.rmSync(process.env.DATA_DIR, { recursive: true, force: true }); });

const req = (p, opts = {}) => fetch(base + p, { redirect: 'manual', ...opts, headers: { ...(cookie && { cookie }), ...opts.headers } });
const form = obj => ({ method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(obj).toString() });

test('admin pages require login', async () => {
  const res = await req('/admin/clips/new');
  assert.equal(res.status, 302);
  assert.match(res.headers.get('location'), /^\/admin\/login/);
});

test('wrong password is rejected, right one logs in', async () => {
  assert.equal((await req('/admin/login', form({ password: 'nope' }))).status, 401);
  const res = await req('/admin/login', form({ password: 'secret-pass', next: '/admin' }));
  assert.equal(res.status, 303);
  cookie = res.headers.get('set-cookie').split(';')[0];
  assert.equal((await req('/admin')).status, 200);
});

let slug, orderId;
test('seller uploads a clip; master is not publicly exposed', async () => {
  const fd = new FormData();
  fd.append('title', 'Berlin Street Life 1928');
  fd.append('description', 'Trams and pedestrians on Potsdamer Platz.');
  fd.append('year', '1928');
  fd.append('tags', 'Street Scene, trams');
  fd.append('price', '149.00');
  fd.append('status', 'published');
  fd.append('master', new Blob([Buffer.from('MASTER-BYTES')]), 'berlin_1928_4k.mov');
  fd.append('thumbnail', new Blob([Buffer.from('fakejpg')]), 'thumb.jpg');
  const res = await req('/admin/clips', { method: 'POST', body: fd });
  assert.equal(res.status, 303);
  slug = res.headers.get('location').split('/').pop();
  assert.equal(slug, 'berlin-street-life-1928');

  cookie = null; // browse as a buyer
  const home = await (await req('/')).text();
  assert.match(home, /Berlin Street Life 1928/);
  assert.match(home, /€149\.00/);
  assert.doesNotMatch(home, /masters/);
  assert.match(await (await req('/?tag=trams')).text(), /Berlin Street Life/);
  assert.match(await (await req('/?decade=1920')).text(), /Berlin Street Life/);
  assert.doesNotMatch(await (await req('/?decade=1950')).text(), /Berlin Street Life/);
});

test('rejects unsafe thumbnail types', async () => {
  cookie = (await req('/admin/login', form({ password: 'secret-pass' }))).headers.get('set-cookie').split(';')[0];
  const fd = new FormData();
  fd.append('title', 'Bad');
  fd.append('price', '10');
  fd.append('master', new Blob(['x']), 'a.mov');
  fd.append('thumbnail', new Blob(['<svg onload=alert(1)>']), 'x.svg');
  const res = await req('/admin/clips', { method: 'POST', body: fd });
  assert.equal(res.status, 400);
  assert.match(await res.text(), /Thumbnail must be/);
  cookie = null;
});

test('buyer purchases (demo mode) and downloads the master', async () => {
  const buy = await req(`/clips/${slug}/buy`, form({ email: 'buyer@studio.com', agree: '1' }));
  assert.equal(buy.status, 303);
  const orderUrl = buy.headers.get('location');
  orderId = orderUrl.split('/').pop();
  const page = await (await req(orderUrl)).text();
  const link = page.match(/href="(\/download\/[^"]+)"/)[1];
  const dl = await req(link);
  assert.equal(dl.status, 200);
  assert.equal(await dl.text(), 'MASTER-BYTES');
  assert.match(dl.headers.get('content-disposition'), /berlin_1928_4k\.mov/);
  assert.equal((await req('/download/not-a-real-token')).status, 404);
});

test('purchase requires licence agreement', async () => {
  const before = orders.all().length;
  await req(`/clips/${slug}/buy`, form({ email: 'buyer@studio.com' }));
  assert.equal(orders.all().length, before);
});

test('stripe webhook verifies signatures and marks orders paid', async () => {
  const { clip_id } = orders.get(orderId);
  const pending = orders.create({ clip_id, email: 'x@y.z', amount_cents: 100, currency: 'eur' });
  const body = JSON.stringify({ type: 'checkout.session.completed',
    data: { object: { payment_status: 'paid', metadata: { order_id: pending.id } } } });
  const post = sig => req('/webhooks/stripe', { method: 'POST', headers: { 'content-type': 'application/json', 'stripe-signature': sig }, body });

  assert.equal((await post('t=1,v1=bad')).status, 400);
  assert.equal(orders.get(pending.id).status, 'pending');

  const t = Math.floor(Date.now() / 1000);
  const v1 = crypto.createHmac('sha256', 'whsec_test').update(`${t}.${body}`).digest('hex');
  assert.equal((await post(`t=${t},v1=${v1}`)).status, 200);
  assert.equal(orders.get(pending.id).status, 'paid');
});

test('sold clips cannot be deleted', async () => {
  cookie = (await req('/admin/login', form({ password: 'secret-pass' }))).headers.get('set-cookie').split(';')[0];
  const { clip_id } = orders.get(orderId);
  const res = await req(`/admin/clips/${clip_id}/delete`, { method: 'POST' });
  assert.equal(res.status, 409);
});

test('login cookie works over plain HTTP but is Secure behind HTTPS', async () => {
  const plain = await req('/admin/login', form({ password: 'secret-pass' }));
  assert.doesNotMatch(plain.headers.get('set-cookie'), /Secure/);
  const https = await req('/admin/login', { ...form({ password: 'secret-pass' }), headers: { 'content-type': 'application/x-www-form-urlencoded', 'x-forwarded-proto': 'https' } });
  assert.match(https.headers.get('set-cookie'), /Secure/);
});
