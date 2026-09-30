const config = require('./config');
const { splitTags } = require('./db');

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (cents, currency = config.currency) =>
  new Intl.NumberFormat('en-GB', { style: 'currency', currency: currency.toUpperCase() }).format(cents / 100);
const duration = s => (s == null ? '' : `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`);
const bytes = n => {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
};
const paragraphs = text => esc(text).split(/\n{2,}/).map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');

function layout({ title, site, body, admin = false, flash = '' }) {
  const full = title ? `${title} · ${site.site_name}` : site.site_name;
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(full)}</title>
<meta name="description" content="${esc(site.tagline)}">
<link rel="stylesheet" href="/static/styles.css">
<link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="/"><span class="reel" aria-hidden="true"></span>${esc(site.site_name)}</a>
    <nav>
      <a href="/">Catalogue</a>
      <a href="/licence">Licence</a>
      ${admin ? '<a href="/admin">Dashboard</a><a class="btn btn-small" href="/admin/clips/new">Upload clip</a>' : ''}
    </nav>
  </div>
  <div class="sprockets" aria-hidden="true"></div>
</header>
<main class="wrap">
${flash ? `<div class="flash">${esc(flash)}</div>` : ''}
${body}
</main>
<footer class="site-footer">
  <div class="sprockets" aria-hidden="true"></div>
  <div class="wrap footer-inner">
    <span>© ${new Date().getFullYear()} ${esc(site.site_name)}</span>
    <nav><a href="/licence">Licence terms</a><a href="/imprint">Imprint</a><a href="mailto:${esc(site.contact_email)}">Contact</a>${admin ? '' : '<a href="/admin">Seller login</a>'}</nav>
  </div>
</footer>
</body>
</html>`;
}

function clipCard(c) {
  return `<a class="card" href="/clips/${esc(c.slug)}">
  <div class="frame">
    ${c.thumb_file ? `<img src="/media/thumbs/${esc(c.thumb_file)}" alt="" loading="lazy">` : '<div class="no-thumb">No preview</div>'}
    ${c.duration_sec ? `<span class="badge">${duration(c.duration_sec)}</span>` : ''}
  </div>
  <div class="card-body">
    <h3>${esc(c.title)}</h3>
    <p class="meta">${[c.year, c.location, c.source_format].filter(Boolean).map(esc).join(' · ')}</p>
    <p class="price">${money(c.price_cents)}</p>
  </div>
</a>`;
}

function catalogue({ site, clips, decades, tags, query }) {
  const opt = (v, label, cur) => `<option value="${esc(v)}"${String(v) === String(cur) ? ' selected' : ''}>${esc(label)}</option>`;
  const filtered = query.q || query.decade || query.tag;
  return `
<section class="hero">
  <h1>${esc(site.site_name)}</h1>
  <p>${esc(site.tagline)}</p>
  <form class="search" method="get" action="/">
    <input type="search" name="q" value="${esc(query.q)}" placeholder="Search footage — places, events, subjects…" aria-label="Search">
    <select name="decade" aria-label="Decade">${opt('', 'Any decade', query.decade)}${decades.map(d => opt(d, `${d}s`, query.decade)).join('')}</select>
    <button class="btn" type="submit">Search</button>
  </form>
  ${tags.length ? `<div class="tags">${tags.map(t => `<a class="tag${t === query.tag ? ' active' : ''}" href="/?tag=${encodeURIComponent(t)}">${esc(t)}</a>`).join('')}</div>` : ''}
</section>
<section>
  <div class="section-head">
    <h2>${filtered ? `${clips.length} result${clips.length === 1 ? '' : 's'}` : 'Latest additions'}</h2>
    ${filtered ? '<a href="/">Clear filters</a>' : ''}
  </div>
  ${clips.length ? `<div class="grid">${clips.map(clipCard).join('')}</div>`
    : `<p class="empty">${filtered ? 'No clips match your search.' : 'The archive is empty — sign in and upload your first clip.'}</p>`}
</section>`;
}

function clipPage({ clip: c, canBuy, demoMode, cancelled }) {
  const rows = [
    ['Year', c.year], ['Location', c.location], ['Original format', c.source_format], ['Colour', c.color],
    ['Sound', c.sound], ['Duration', duration(c.duration_sec)], ['Master resolution', c.resolution],
    ['Master file', `${c.master_name} (${bytes(c.master_size)})`],
  ].filter(([, v]) => v);
  return `
<article class="clip">
  <div class="player">
    ${c.preview_file
      ? `<video controls playsinline preload="metadata" controlslist="nodownload" ${c.thumb_file ? `poster="/media/thumbs/${esc(c.thumb_file)}"` : ''}>
           <source src="/media/previews/${esc(c.preview_file)}">
         </video><p class="note">Low-resolution preview. The full-quality master is delivered after purchase.</p>`
      : c.thumb_file ? `<img src="/media/thumbs/${esc(c.thumb_file)}" alt="${esc(c.title)}">` : '<div class="no-thumb tall">No preview available</div>'}
  </div>
  <div class="details">
    <h1>${esc(c.title)}</h1>
    ${splitTags(c.tags).length ? `<div class="tags">${splitTags(c.tags).map(t => `<a class="tag" href="/?tag=${encodeURIComponent(t)}">${esc(t)}</a>`).join('')}</div>` : ''}
    ${c.description ? `<div class="prose">${paragraphs(c.description)}</div>` : ''}
    <dl class="specs">${rows.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>
    <div class="buy">
      <p class="price big">${money(c.price_cents)}</p>
      <p class="small">Single-production licence · <a href="/licence">terms</a></p>
      ${cancelled ? '<p class="warn">Checkout was cancelled — you have not been charged.</p>' : ''}
      ${canBuy ? `
      <form method="post" action="/clips/${esc(c.slug)}/buy">
        <label>Email for your receipt and download link
          <input type="email" name="email" required autocomplete="email" placeholder="you@studio.com">
        </label>
        <label class="check"><input type="checkbox" name="agree" value="1" required> I accept the <a href="/licence" target="_blank">licence terms</a></label>
        <button class="btn btn-wide" type="submit">Buy &amp; download</button>
      </form>
      ${demoMode ? '<p class="warn">Demo mode: Stripe is not configured, so no payment will be taken.</p>' : ''}`
      : '<p class="warn">Purchases are temporarily unavailable.</p>'}
    </div>
  </div>
</article>`;
}

function orderPage({ order, clip, expired, downloadsLeft }) {
  if (order.status !== 'paid') {
    return `<section class="panel narrow"><h1>Waiting for payment…</h1>
      <p>We haven't received confirmation of your payment for <strong>${esc(clip.title)}</strong> yet.
      This page refreshes automatically.</p><meta http-equiv="refresh" content="5"></section>`;
  }
  return `<section class="panel narrow">
  <h1>Thank you!</h1>
  <p>Your licence for <strong>${esc(clip.title)}</strong> is confirmed (${money(order.amount_cents, order.currency)}).</p>
  ${expired || downloadsLeft <= 0
    ? `<p class="warn">This download link has expired. Please contact us quoting order <code>${esc(order.id)}</code>.</p>`
    : `<a class="btn btn-wide" href="/download/${esc(order.download_token)}">Download master — ${esc(clip.master_name)} (${bytes(clip.master_size)})</a>
       <p class="small">Bookmark this page. The link works ${downloadsLeft} more time${downloadsLeft === 1 ? '' : 's'} and expires ${config.downloadTtlHours} hours after purchase.</p>`}
  <p class="small">Order reference: <code>${esc(order.id)}</code></p>
</section>`;
}

function textPage({ heading, text }) {
  return `<section class="panel narrow"><h1>${esc(heading)}</h1><div class="prose">${paragraphs(text)}</div></section>`;
}

function login({ error, next, insecure }) {
  return `<section class="panel narrow">
  <h1>Seller login</h1>
  ${insecure ? '<p class="note">This connection is not encrypted (HTTP). Enable SSL/HTTPS before using the site for real.</p>' : ''}
  ${error ? `<p class="warn">${esc(error)}</p>` : ''}
  <form method="post" action="/admin/login" class="form">
    <input type="hidden" name="next" value="${esc(next)}">
    <label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button class="btn" type="submit">Sign in</button>
  </form>
</section>`;
}

function dashboard({ clips, stats, orders, ffmpeg, stripe }) {
  return `
<div class="section-head"><h1>Dashboard</h1><div class="actions">
  <a class="btn btn-ghost" href="/admin/settings">Site settings</a>
  <form method="post" action="/admin/logout"><button class="btn btn-ghost" type="submit">Log out</button></form>
  <a class="btn" href="/admin/clips/new">Upload clip</a></div></div>
<div class="stats">
  <div><span>${clips.length}</span>clips</div>
  <div><span>${stats.paid_count}</span>sales</div>
  <div><span>${money(stats.revenue_cents)}</span>revenue</div>
</div>
${!stripe ? '<p class="warn">Stripe is not configured — purchases run in demo mode (or are disabled in production). Set STRIPE_SECRET_KEY to take real payments.</p>' : ''}
${!ffmpeg ? '<p class="note">ffmpeg not found on the server: upload a preview clip and thumbnail yourself, or install ffmpeg to generate them automatically.</p>' : ''}
<h2>Clips</h2>
${clips.length ? `<div class="table-wrap"><table>
  <thead><tr><th></th><th>Title</th><th>Year</th><th>Price</th><th>Status</th><th>Added</th><th></th></tr></thead>
  <tbody>${clips.map(c => `<tr>
    <td>${c.thumb_file ? `<img class="mini" src="/media/thumbs/${esc(c.thumb_file)}" alt="">` : ''}</td>
    <td><a href="/clips/${esc(c.slug)}">${esc(c.title)}</a></td>
    <td>${esc(c.year)}</td><td>${money(c.price_cents)}</td>
    <td><span class="status ${esc(c.status)}">${esc(c.status)}</span></td>
    <td>${esc(c.created_at.slice(0, 10))}</td>
    <td><a href="/admin/clips/${esc(c.id)}/edit">Edit</a></td></tr>`).join('')}</tbody></table></div>`
    : '<p class="empty">No clips yet. <a href="/admin/clips/new">Upload your first clip</a>.</p>'}
<h2>Recent orders</h2>
${orders.length ? `<div class="table-wrap"><table>
  <thead><tr><th>Date</th><th>Clip</th><th>Buyer</th><th>Amount</th><th>Status</th><th>Downloads</th></tr></thead>
  <tbody>${orders.slice(0, 50).map(o => `<tr>
    <td>${esc(o.created_at.slice(0, 16))}</td><td><a href="/clips/${esc(o.clip_slug)}">${esc(o.clip_title)}</a></td>
    <td>${esc(o.email)}</td><td>${money(o.amount_cents, o.currency)}</td>
    <td><span class="status ${esc(o.status)}">${esc(o.status)}</span></td><td>${o.downloads}</td></tr>`).join('')}</tbody></table></div>`
    : '<p class="empty">No orders yet.</p>'}`;
}

function clipForm({ clip = {}, error, ffmpeg }) {
  const editing = !!clip.id;
  const v = k => esc(clip[k] ?? '');
  const sel = (name, options) => `<select name="${name}">${options.map(o => `<option${clip[name] === o ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
  return `
<div class="section-head"><h1>${editing ? 'Edit clip' : 'Upload a clip'}</h1><a href="/admin">← Dashboard</a></div>
${error ? `<p class="warn">${esc(error)}</p>` : ''}
<form class="form panel" method="post" enctype="multipart/form-data" action="${editing ? `/admin/clips/${esc(clip.id)}` : '/admin/clips'}" id="clip-form">
  <fieldset>
    <legend>Files</legend>
    <label>Master file ${editing ? '(leave empty to keep current: ' + esc(clip.master_name) + ')' : '<em>required</em>'}
      <input type="file" name="master" accept="video/*,.mov,.mxf,.dpx,.zip" ${editing ? '' : 'required'}>
      <span class="hint">The full-quality file buyers download. Never shown publicly.</span></label>
    <label>Preview clip (optional)
      <input type="file" name="preview" accept="video/mp4,video/webm">
      <span class="hint">${ffmpeg ? 'Leave empty to auto-generate a 30-second low-res preview.' : 'A short, low-res or watermarked MP4 shown on the clip page.'}</span></label>
    <label>Thumbnail (optional)
      <input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp">
      <span class="hint">${ffmpeg ? 'Leave empty to grab a frame from the video automatically.' : 'JPEG/PNG/WebP still shown in the catalogue.'}</span></label>
  </fieldset>
  <fieldset>
    <legend>Details</legend>
    <label>Title <input name="title" required maxlength="140" value="${v('title')}"></label>
    <label>Description <textarea name="description" rows="5">${v('description')}</textarea></label>
    <div class="row">
      <label>Year <input name="year" type="number" min="1850" max="2100" value="${v('year')}"></label>
      <label>Location <input name="location" value="${v('location')}" placeholder="Berlin, Germany"></label>
    </div>
    <label>Tags <input name="tags" value="${v('tags')}" placeholder="street scene, trams, 1920s"><span class="hint">Comma-separated.</span></label>
    <div class="row">
      <label>Original format <input name="source_format" value="${v('source_format')}" placeholder="16mm, 35mm, Super 8…"></label>
      <label>Colour ${sel('color', ['', 'Black & white', 'Colour', 'Tinted'])}</label>
      <label>Sound ${sel('sound', ['', 'Silent', 'Sound'])}</label>
    </div>
    <div class="row">
      <label>Duration (seconds) <input name="duration_sec" type="number" step="0.1" min="0" value="${v('duration_sec')}"><span class="hint">${ffmpeg ? 'Auto-detected if empty.' : ''}</span></label>
      <label>Master resolution <input name="resolution" value="${v('resolution')}" placeholder="3840×2160"></label>
    </div>
  </fieldset>
  <fieldset>
    <legend>Sale</legend>
    <div class="row">
      <label>Price (${esc(config.currency.toUpperCase())}) <input name="price" type="number" step="0.01" min="0.50" required value="${clip.price_cents != null ? (clip.price_cents / 100).toFixed(2) : ''}"></label>
      <label>Status <select name="status">
        <option value="published"${clip.status !== 'draft' ? ' selected' : ''}>Published — visible in catalogue</option>
        <option value="draft"${clip.status === 'draft' ? ' selected' : ''}>Draft — hidden</option></select></label>
    </div>
  </fieldset>
  <div class="upload-progress" hidden><div class="bar"><span></span></div><p>Uploading… <b>0%</b></p></div>
  <button class="btn btn-wide" type="submit">${editing ? 'Save changes' : 'Upload & publish'}</button>
</form>
${editing ? `<form method="post" action="/admin/clips/${esc(clip.id)}/delete" class="danger-zone"
    data-confirm="Delete this clip permanently?">
  <button class="btn btn-danger" type="submit">Delete clip</button>
  <span class="hint">Clips that have been sold can't be deleted — set them to Draft instead.</span></form>` : ''}
<script src="/static/upload.js"></script>`;
}

function settingsForm({ site, saved }) {
  const f = (name, label, rows) => rows
    ? `<label>${label}<textarea name="${name}" rows="${rows}">${esc(site[name])}</textarea></label>`
    : `<label>${label}<input name="${name}" value="${esc(site[name])}"></label>`;
  return `
<div class="section-head"><h1>Site settings</h1><a href="/admin">← Dashboard</a></div>
${saved ? '<div class="flash">Settings saved.</div>' : ''}
<form class="form panel" method="post" action="/admin/settings">
  ${f('site_name', 'Site name')}${f('tagline', 'Tagline')}${f('contact_email', 'Contact email')}
  ${f('license_text', 'Licence terms (shown to buyers)', 8)}
  ${f('imprint_text', 'Imprint / legal notice', 8)}
  <button class="btn" type="submit">Save</button>
</form>`;
}

module.exports = { esc, layout, catalogue, clipPage, orderPage, textPage, login, dashboard, clipForm, settingsForm };
