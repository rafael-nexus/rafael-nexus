const path = require('node:path');
const crypto = require('node:crypto');
const fs = require('node:fs');
const express = require('express');
const multer = require('multer');

const config = require('./lib/config');
const { clips, orders, settings, splitTags } = require('./lib/db');
const auth = require('./lib/auth');
const stripe = require('./lib/stripe');
const media = require('./lib/media');
const views = require('./lib/views');

const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1);

app.use((req, res, next) => {
  res.set({
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'same-origin',
    'Content-Security-Policy': "default-src 'self'; img-src 'self' data:; media-src 'self'; frame-ancestors 'none'; " +
      "form-action 'self' https://checkout.stripe.com; base-uri 'none'; object-src 'none'",
  });
  next();
});

const render = (req, res, { title, body, status = 200, flash }) =>
  res.status(status).type('html').send(views.layout({ title, body, flash, site: settings.all(), admin: auth.isAdmin(req) }));

const notFound = (req, res) => render(req, res, { status: 404, title: 'Not found',
  body: views.textPage({ heading: 'Not found', text: "We couldn't find that page." }) });

// Stripe webhooks need the raw body for signature verification, so register before body parsers.
app.post('/webhooks/stripe', express.raw({ type: 'application/json', limit: '1mb' }), (req, res) => {
  const event = stripe.verifyWebhook(req.body, req.get('stripe-signature'));
  if (!event) return res.status(400).send('invalid signature');
  if (['checkout.session.completed', 'checkout.session.async_payment_succeeded'].includes(event.type)) {
    const session = event.data.object;
    const orderId = session.metadata?.order_id;
    if (orderId && session.payment_status === 'paid') orders.markPaid(orderId);
  }
  res.json({ received: true });
});

app.use(express.urlencoded({ extended: false, limit: '200kb' }));
app.use('/static', express.static(path.join(__dirname, 'public'), { maxAge: '1h' }));
app.use('/media/thumbs', express.static(config.dirs.thumbs, { maxAge: '7d', index: false }));
app.use('/media/previews', express.static(config.dirs.previews, { maxAge: '7d', index: false }));

// ---------- Public storefront ----------

app.get('/', (req, res) => {
  const query = { q: String(req.query.q || '').trim().slice(0, 100), decade: String(req.query.decade || ''), tag: String(req.query.tag || '') };
  render(req, res, { body: views.catalogue({ site: settings.all(), clips: clips.search(query), decades: clips.decades(), tags: clips.tags(), query }) });
});

const purchasesEnabled = () => stripe.enabled() || !config.production;

function publicClip(req) {
  const clip = clips.bySlug(req.params.slug);
  if (!clip || (clip.status !== 'published' && !auth.isAdmin(req))) return null;
  return clip;
}

app.get('/clips/:slug', (req, res) => {
  const clip = publicClip(req);
  if (!clip) return notFound(req, res);
  render(req, res, { title: clip.title, body: views.clipPage({
    clip, canBuy: purchasesEnabled() && clip.status === 'published', demoMode: !stripe.enabled(), cancelled: !!req.query.cancelled }) });
});

app.post('/clips/:slug/buy', async (req, res) => {
  const clip = publicClip(req);
  if (!clip || clip.status !== 'published') return notFound(req, res);
  const email = String(req.body.email || '').trim().slice(0, 254);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || req.body.agree !== '1') {
    return res.redirect(303, `/clips/${clip.slug}`);
  }
  if (!purchasesEnabled()) return res.redirect(303, `/clips/${clip.slug}`);

  const order = orders.create({ clip_id: clip.id, email, amount_cents: clip.price_cents, currency: config.currency });
  if (!stripe.enabled()) {
    orders.markPaid(order.id); // demo mode (development only)
    return res.redirect(303, `/orders/${order.id}`);
  }
  const session = await stripe.createCheckoutSession({ order, clip });
  orders.setStripeSession(order.id, session.id);
  res.redirect(303, session.url);
});

function downloadState(order) {
  const paidAt = order.paid_at ? Date.parse(order.paid_at.replace(' ', 'T') + 'Z') : 0;
  return {
    expired: Date.now() - paidAt > config.downloadTtlHours * 3600e3,
    downloadsLeft: config.maxDownloads - order.downloads,
  };
}

app.get('/orders/:id', async (req, res) => {
  let order = orders.get(req.params.id);
  if (!order) return notFound(req, res);
  // Confirm payment directly with Stripe on return, in case the webhook hasn't arrived yet.
  if (order.status !== 'paid' && stripe.enabled() && order.stripe_session_id) {
    try {
      const session = await stripe.retrieveCheckoutSession(order.stripe_session_id);
      if (session.payment_status === 'paid' && session.metadata?.order_id === order.id) {
        orders.markPaid(order.id);
        order = orders.get(order.id);
      }
    } catch (err) {
      console.error('Stripe session lookup failed:', err.message);
    }
  }
  const clip = clips.get(order.clip_id);
  render(req, res, { title: 'Your order', body: views.orderPage({ order, clip, ...downloadState(order) }) });
});

app.get('/download/:token', (req, res) => {
  const order = orders.byToken(req.params.token);
  if (!order || order.status !== 'paid') return notFound(req, res);
  const { expired, downloadsLeft } = downloadState(order);
  if (expired || downloadsLeft <= 0) return res.redirect(`/orders/${order.id}`);
  const clip = clips.get(order.clip_id);
  orders.countDownload(order.id);
  res.set('Cache-Control', 'private, no-store');
  res.download(path.join(config.dirs.masters, clip.master_file), clip.master_name);
});

app.get('/licence', (req, res) =>
  render(req, res, { title: 'Licence terms', body: views.textPage({ heading: 'Licence terms', text: settings.all().license_text }) }));
app.get('/imprint', (req, res) =>
  render(req, res, { title: 'Imprint', body: views.textPage({ heading: 'Imprint', text: settings.all().imprint_text }) }));

// ---------- Seller admin ----------

app.get('/admin/login', (req, res) => {
  if (auth.isAdmin(req)) return res.redirect('/admin');
  const error = config.adminPassword ? '' : 'ADMIN_PASSWORD is not set on the server, so login is disabled.';
  render(req, res, { title: 'Seller login', body: views.login({ error, next: req.query.next || '/admin' }) });
});

app.post('/admin/login', (req, res) => {
  const next = String(req.body.next || '').startsWith('/admin') ? req.body.next : '/admin';
  if (!auth.loginAllowed(req.ip)) {
    return render(req, res, { status: 429, title: 'Seller login', body: views.login({ error: 'Too many attempts. Try again in 15 minutes.', next }) });
  }
  if (!auth.checkPassword(req.body.password)) {
    auth.recordFailure(req.ip);
    return render(req, res, { status: 401, title: 'Seller login', body: views.login({ error: 'Wrong password.', next }) });
  }
  auth.login(res);
  res.redirect(303, next);
});

app.post('/admin/logout', (req, res) => { auth.logout(res); res.redirect(303, '/'); });

app.use('/admin', auth.requireAdmin);

app.get('/admin', async (req, res) => {
  render(req, res, { title: 'Dashboard', body: views.dashboard({
    clips: clips.all(), stats: orders.stats(), orders: orders.all(), ffmpeg: await media.ffmpegAvailable(), stripe: stripe.enabled() }) });
});

app.get('/admin/settings', (req, res) =>
  render(req, res, { title: 'Settings', body: views.settingsForm({ site: settings.all(), saved: !!req.query.saved }) }));
app.post('/admin/settings', (req, res) => { settings.save(req.body); res.redirect(303, '/admin/settings?saved=1'); });

const THUMB_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp']);
const PREVIEW_EXT = new Set(['.mp4', '.webm', '.m4v']);

const upload = multer({ dest: config.dirs.tmp, limits: { fileSize: config.maxUploadBytes, files: 3, fields: 30 } })
  .fields([{ name: 'master', maxCount: 1 }, { name: 'preview', maxCount: 1 }, { name: 'thumbnail', maxCount: 1 }]);

function handleUpload(req, res, next) {
  upload(req, res, err => {
    if (!err) return next();
    cleanupTmp(req);
    const message = err.code === 'LIMIT_FILE_SIZE' ? `File too large (max ${config.maxUploadBytes / 1048576} MB).` : `Upload failed: ${err.message}`;
    const clip = (req.params.id && clips.get(req.params.id)) || {};
    render(req, res, { status: 400, title: 'Upload failed', body: views.clipForm({ clip, error: message }) });
  });
}

function cleanupTmp(req) {
  for (const list of Object.values(req.files || {})) for (const f of list) fs.rm(f.path, { force: true }, () => {});
}

const ext = name => path.extname(name || '').toLowerCase().replace(/[^.a-z0-9]/g, '').slice(0, 10);
const newName = original => `${crypto.randomUUID()}${ext(original)}`;
const removeFile = (dir, name) => name && fs.rm(path.join(dir, name), { force: true }, () => {});

function clipFields(body) {
  const price = Math.round(Number(body.price) * 100);
  const year = parseInt(body.year, 10);
  const dur = Number(body.duration_sec);
  return {
    title: String(body.title || '').trim().slice(0, 140),
    description: String(body.description || '').trim().slice(0, 5000),
    year: Number.isFinite(year) ? year : null,
    location: String(body.location || '').trim().slice(0, 140),
    tags: splitTags(body.tags).slice(0, 20).join(', '),
    source_format: String(body.source_format || '').trim().slice(0, 60),
    color: String(body.color || '').slice(0, 30),
    sound: String(body.sound || '').slice(0, 30),
    duration_sec: body.duration_sec && Number.isFinite(dur) ? dur : null,
    resolution: String(body.resolution || '').trim().slice(0, 30),
    price_cents: price,
    status: body.status === 'draft' ? 'draft' : 'published',
  };
}

function validate(fields) {
  if (!fields.title) return 'Title is required.';
  if (!Number.isFinite(fields.price_cents) || fields.price_cents < 50) return 'Price must be at least 0.50.';
  return null;
}

// Moves uploaded files into place and fills in anything ffmpeg can derive.
async function processFiles(req, fields, existing = {}) {
  const f = name => req.files?.[name]?.[0];
  const out = {};
  const master = f('master'), preview = f('preview'), thumb = f('thumbnail');

  if (thumb && !THUMB_EXT.has(ext(thumb.originalname))) throw new Error('Thumbnail must be a JPEG, PNG or WebP image.');
  if (preview && !PREVIEW_EXT.has(ext(preview.originalname))) throw new Error('Preview must be an MP4 or WebM video.');

  if (master) {
    out.master_file = newName(master.originalname);
    out.master_name = path.basename(master.originalname).slice(0, 200);
    out.master_size = master.size;
    fs.renameSync(master.path, path.join(config.dirs.masters, out.master_file));
  }
  if (preview) {
    out.preview_file = newName(preview.originalname);
    fs.renameSync(preview.path, path.join(config.dirs.previews, out.preview_file));
  }
  if (thumb) {
    out.thumb_file = newName(thumb.originalname);
    fs.renameSync(thumb.path, path.join(config.dirs.thumbs, out.thumb_file));
  }

  if (master && await media.ffmpegAvailable()) {
    const src = path.join(config.dirs.masters, out.master_file);
    try {
      const info = await media.probe(src);
      if (fields.duration_sec == null) out.duration_sec = info.duration_sec;
      if (!fields.resolution) out.resolution = info.resolution;
      if (!thumb && !existing.thumb_file) {
        out.thumb_file = `${crypto.randomUUID()}.jpg`;
        await media.makeThumbnail(src, path.join(config.dirs.thumbs, out.thumb_file), info.duration_sec);
      }
      if (!preview && !existing.preview_file) {
        out.preview_file = `${crypto.randomUUID()}.mp4`;
        await media.makePreview(src, path.join(config.dirs.previews, out.preview_file));
      }
    } catch (err) {
      // Not every master is a video ffmpeg understands (e.g. DPX sequences in a zip) — that's fine.
      console.warn('ffmpeg processing skipped:', err.message.split('\n')[0]);
      if (out.thumb_file && !thumb) { removeFile(config.dirs.thumbs, out.thumb_file); delete out.thumb_file; }
      if (out.preview_file && !preview) { removeFile(config.dirs.previews, out.preview_file); delete out.preview_file; }
    }
  }
  return out;
}

app.get('/admin/clips/new', async (req, res) =>
  render(req, res, { title: 'Upload clip', body: views.clipForm({ ffmpeg: await media.ffmpegAvailable() }) }));

app.post('/admin/clips', handleUpload, async (req, res) => {
  const fields = clipFields(req.body);
  const error = validate(fields) || (!req.files?.master?.[0] && 'Please choose a master file.');
  const ffmpeg = await media.ffmpegAvailable();
  if (error) {
    cleanupTmp(req);
    return render(req, res, { status: 400, title: 'Upload clip', body: views.clipForm({ clip: fields, error, ffmpeg }) });
  }
  let files;
  try { files = await processFiles(req, fields); }
  catch (err) {
    cleanupTmp(req);
    return render(req, res, { status: 400, title: 'Upload clip', body: views.clipForm({ clip: fields, error: err.message, ffmpeg }) });
  }
  const clip = clips.create({ ...fields, ...files });
  res.redirect(303, `/clips/${clip.slug}`);
});

function adminClip(req, res) {
  const clip = clips.get(req.params.id);
  if (!clip) notFound(req, res);
  return clip;
}

app.get('/admin/clips/:id/edit', async (req, res) => {
  const clip = adminClip(req, res);
  if (clip) render(req, res, { title: 'Edit clip', body: views.clipForm({ clip, ffmpeg: await media.ffmpegAvailable() }) });
});

app.post('/admin/clips/:id', handleUpload, async (req, res) => {
  const clip = adminClip(req, res);
  if (!clip) return cleanupTmp(req);
  const fields = clipFields(req.body);
  const ffmpeg = await media.ffmpegAvailable();
  const error = validate(fields);
  if (error) {
    cleanupTmp(req);
    return render(req, res, { status: 400, title: 'Edit clip', body: views.clipForm({ clip: { ...clip, ...fields }, error, ffmpeg }) });
  }
  let files;
  try { files = await processFiles(req, fields, clip); }
  catch (err) {
    cleanupTmp(req);
    return render(req, res, { status: 400, title: 'Edit clip', body: views.clipForm({ clip: { ...clip, ...fields }, error: err.message, ffmpeg }) });
  }
  // Replace old files only once the new ones are safely in place.
  if (files.master_file) removeFile(config.dirs.masters, clip.master_file);
  if (files.preview_file) removeFile(config.dirs.previews, clip.preview_file);
  if (files.thumb_file) removeFile(config.dirs.thumbs, clip.thumb_file);
  const updated = clips.update(clip.id, { ...fields, ...files });
  res.redirect(303, `/clips/${updated.slug}`);
});

app.post('/admin/clips/:id/delete', async (req, res) => {
  const clip = adminClip(req, res);
  if (!clip) return;
  if (clips.hasOrders(clip.id)) {
    return render(req, res, { status: 409, title: 'Edit clip', body: views.clipForm({ clip, ffmpeg: await media.ffmpegAvailable(),
      error: 'This clip has orders, so buyers may still need to download it. Set it to Draft to hide it instead.' }) });
  }
  clips.remove(clip.id);
  removeFile(config.dirs.masters, clip.master_file);
  removeFile(config.dirs.previews, clip.preview_file);
  removeFile(config.dirs.thumbs, clip.thumb_file);
  res.redirect(303, '/admin');
});

app.use(notFound);
app.use((err, req, res, _next) => {
  console.error(err);
  render(req, res, { status: 500, title: 'Error', body: views.textPage({ heading: 'Something went wrong', text: 'Please try again in a moment.' }) });
});

if (require.main === module) {
  if (config.production && !config.adminPassword) console.warn('ADMIN_PASSWORD is not set: seller login is disabled.');
  if (!config.production && !process.env.ADMIN_PASSWORD) console.warn('Development mode: seller password is "admin".');
  app.listen(config.port, () => console.log(`Film Archives running at ${config.baseUrl}`));
}

module.exports = app;
