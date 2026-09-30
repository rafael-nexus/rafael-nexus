const path = require('node:path');
const crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');
const { dirs } = require('./config');

const db = new DatabaseSync(path.join(dirs.data, 'film-archives.db'));
db.exec(`
  PRAGMA journal_mode = WAL;
  PRAGMA foreign_keys = ON;

  CREATE TABLE IF NOT EXISTS clips (
    id            TEXT PRIMARY KEY,
    slug          TEXT NOT NULL UNIQUE,
    title         TEXT NOT NULL,
    description   TEXT NOT NULL DEFAULT '',
    year          INTEGER,
    location      TEXT NOT NULL DEFAULT '',
    tags          TEXT NOT NULL DEFAULT '',
    source_format TEXT NOT NULL DEFAULT '',
    color         TEXT NOT NULL DEFAULT '',
    sound         TEXT NOT NULL DEFAULT '',
    duration_sec  REAL,
    resolution    TEXT NOT NULL DEFAULT '',
    price_cents   INTEGER NOT NULL,
    status        TEXT NOT NULL DEFAULT 'published',
    master_file   TEXT NOT NULL,
    master_name   TEXT NOT NULL,
    master_size   INTEGER NOT NULL,
    preview_file  TEXT,
    thumb_file    TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS orders (
    id                TEXT PRIMARY KEY,
    clip_id           TEXT NOT NULL REFERENCES clips(id) ON DELETE RESTRICT,
    email             TEXT NOT NULL,
    amount_cents      INTEGER NOT NULL,
    currency          TEXT NOT NULL,
    status            TEXT NOT NULL DEFAULT 'pending',
    stripe_session_id TEXT,
    download_token    TEXT NOT NULL UNIQUE,
    downloads         INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    paid_at           TEXT
  );

  CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
  );
`);

const DEFAULT_SETTINGS = {
  site_name: 'Film Archives',
  tagline: 'Rare archival footage, digitised and licensed for your productions.',
  license_text:
    'Each purchase grants a non-exclusive, worldwide, perpetual licence to use the clip in one production ' +
    '(film, broadcast, online video or advertising). Resale or redistribution of the raw footage is not permitted. ' +
    'Contact us for exclusive or multi-production licences.',
  imprint_text: 'Operator name\nStreet and number\nPostcode and city\nCountry\n\nEmail: contact@film-archives.com',
  contact_email: 'contact@film-archives.com',
};

const id = () => crypto.randomUUID();
const token = () => crypto.randomBytes(24).toString('base64url');

function slugify(title) {
  const base = title.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60) || 'clip';
  let slug = base;
  for (let n = 2; db.prepare('SELECT 1 FROM clips WHERE slug = ?').get(slug); n++) slug = `${base}-${n}`;
  return slug;
}

const CLIP_FIELDS = ['title', 'description', 'year', 'location', 'tags', 'source_format', 'color', 'sound',
  'duration_sec', 'resolution', 'price_cents', 'status', 'master_file', 'master_name', 'master_size',
  'preview_file', 'thumb_file'];

const clips = {
  create(data) {
    const row = { id: id(), slug: slugify(data.title) };
    for (const f of CLIP_FIELDS) row[f] = data[f] ?? null;
    const cols = Object.keys(row);
    db.prepare(`INSERT INTO clips (${cols.join(',')}) VALUES (${cols.map(c => '$' + c).join(',')})`)
      .run(Object.fromEntries(cols.map(c => ['$' + c, row[c]])));
    return clips.get(row.id);
  },
  update(clipId, data) {
    const cols = CLIP_FIELDS.filter(f => f in data);
    if (!cols.length) return clips.get(clipId);
    db.prepare(`UPDATE clips SET ${cols.map(c => `${c} = $${c}`).join(', ')}, updated_at = datetime('now') WHERE id = $id`)
      .run({ $id: clipId, ...Object.fromEntries(cols.map(c => ['$' + c, data[c] ?? null])) });
    return clips.get(clipId);
  },
  get: clipId => db.prepare('SELECT * FROM clips WHERE id = ?').get(clipId),
  bySlug: slug => db.prepare('SELECT * FROM clips WHERE slug = ?').get(slug),
  remove: clipId => db.prepare('DELETE FROM clips WHERE id = ?').run(clipId),
  hasOrders: clipId => !!db.prepare('SELECT 1 FROM orders WHERE clip_id = ? LIMIT 1').get(clipId),
  all: () => db.prepare('SELECT * FROM clips ORDER BY created_at DESC').all(),
  search({ q = '', decade = '', tag = '' } = {}) {
    const where = ["status = 'published'"];
    const params = {};
    if (q) {
      where.push("(title LIKE $q OR description LIKE $q OR tags LIKE $q OR location LIKE $q)");
      params.$q = `%${q}%`;
    }
    if (/^\d{4}$/.test(decade)) {
      where.push('year BETWEEN $d0 AND $d1');
      params.$d0 = Number(decade);
      params.$d1 = Number(decade) + 9;
    }
    if (tag) {
      where.push("(',' || REPLACE(LOWER(tags), ', ', ',') || ',') LIKE $tag");
      params.$tag = `%,${tag.toLowerCase()},%`;
    }
    return db.prepare(`SELECT * FROM clips WHERE ${where.join(' AND ')} ORDER BY created_at DESC`).all(params);
  },
  decades: () => db.prepare(
    "SELECT DISTINCT (year / 10) * 10 AS decade FROM clips WHERE status = 'published' AND year IS NOT NULL ORDER BY decade"
  ).all().map(r => r.decade),
  tags() {
    const counts = new Map();
    for (const { tags } of db.prepare("SELECT tags FROM clips WHERE status = 'published'").all()) {
      for (const t of splitTags(tags)) counts.set(t, (counts.get(t) || 0) + 1);
    }
    return [...counts].sort((a, b) => b[1] - a[1]).slice(0, 20).map(([t]) => t);
  },
};

const orders = {
  create({ clip_id, email, amount_cents, currency }) {
    const row = { $id: id(), $clip_id: clip_id, $email: email, $amount_cents: amount_cents, $currency: currency, $token: token() };
    db.prepare(`INSERT INTO orders (id, clip_id, email, amount_cents, currency, download_token)
                VALUES ($id, $clip_id, $email, $amount_cents, $currency, $token)`).run(row);
    return orders.get(row.$id);
  },
  get: orderId => db.prepare('SELECT * FROM orders WHERE id = ?').get(orderId),
  byToken: t => db.prepare('SELECT * FROM orders WHERE download_token = ?').get(t),
  setStripeSession: (orderId, sid) => db.prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?').run(sid, orderId),
  markPaid: orderId => db.prepare(
    "UPDATE orders SET status = 'paid', paid_at = datetime('now') WHERE id = ? AND status != 'paid'"
  ).run(orderId),
  countDownload: orderId => db.prepare('UPDATE orders SET downloads = downloads + 1 WHERE id = ?').run(orderId),
  all: () => db.prepare(`SELECT o.*, c.title AS clip_title, c.slug AS clip_slug
                         FROM orders o JOIN clips c ON c.id = o.clip_id ORDER BY o.created_at DESC`).all(),
  stats: () => db.prepare(`SELECT COUNT(*) AS paid_count, COALESCE(SUM(amount_cents), 0) AS revenue_cents
                           FROM orders WHERE status = 'paid'`).get(),
};

const settings = {
  all() {
    const out = { ...DEFAULT_SETTINGS };
    for (const { key, value } of db.prepare('SELECT key, value FROM settings').all()) out[key] = value;
    return out;
  },
  save(values) {
    const stmt = db.prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    for (const key of Object.keys(DEFAULT_SETTINGS)) if (typeof values[key] === 'string') stmt.run(key, values[key].trim());
  },
};

function splitTags(tags) {
  return String(tags || '').split(',').map(t => t.trim().toLowerCase()).filter(Boolean);
}

module.exports = { db, clips, orders, settings, splitTags };
