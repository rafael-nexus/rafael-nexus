const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');

const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, '..', 'data'));
const dirs = {
  data: DATA_DIR,
  tmp: path.join(DATA_DIR, 'tmp'),
  masters: path.join(DATA_DIR, 'masters'), // private: only reachable through paid download links
  thumbs: path.join(DATA_DIR, 'media', 'thumbs'),
  previews: path.join(DATA_DIR, 'media', 'previews'),
};
for (const d of Object.values(dirs)) fs.mkdirSync(d, { recursive: true });

// A session secret is required to sign admin cookies. If none is configured,
// generate one once and keep it in the data directory so restarts don't log you out.
function loadSecret() {
  if (process.env.SESSION_SECRET) return process.env.SESSION_SECRET;
  const file = path.join(DATA_DIR, '.session-secret');
  if (!fs.existsSync(file)) fs.writeFileSync(file, crypto.randomBytes(32).toString('hex'), { mode: 0o600 });
  return fs.readFileSync(file, 'utf8').trim();
}

const production = process.env.NODE_ENV === 'production';

module.exports = {
  production,
  port: Number(process.env.PORT) || 3000,
  baseUrl: (process.env.BASE_URL || `http://localhost:${Number(process.env.PORT) || 3000}`).replace(/\/$/, ''),
  adminPassword: process.env.ADMIN_PASSWORD || (production ? null : 'admin'),
  sessionSecret: loadSecret(),
  currency: (process.env.CURRENCY || 'eur').toLowerCase(),
  stripeSecretKey: process.env.STRIPE_SECRET_KEY || null,
  stripeWebhookSecret: process.env.STRIPE_WEBHOOK_SECRET || null,
  maxUploadBytes: (Number(process.env.MAX_UPLOAD_MB) || 4096) * 1024 * 1024,
  downloadTtlHours: Number(process.env.DOWNLOAD_TTL_HOURS) || 72,
  maxDownloads: Number(process.env.MAX_DOWNLOADS) || 5,
  dirs,
};
