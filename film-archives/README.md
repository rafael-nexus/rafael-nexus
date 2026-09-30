# Film Archives — film-archives.com

A self-hosted storefront for selling archival film clips. Upload a clip in the seller dashboard,
set a price, and buyers can preview it, pay with Stripe, and download the full-quality master.

## Features

- **Public catalogue** with search, decade filter and tags; clip pages with preview player, specs and licence terms.
- **Seller dashboard** (`/admin`): upload clips with a progress bar (multi-GB files supported), edit, hide as draft,
  delete, see orders and revenue, and edit site name, tagline, licence terms and imprint.
- **Masters stay private.** Only the thumbnail and preview are public. The master is delivered through a
  per-order download link that expires (72 h / 5 downloads by default).
- **Stripe Checkout** for payments. Orders are confirmed by a signed webhook and double-checked when the
  buyer returns, so a late webhook doesn't block the download.
- **Optional ffmpeg**: if `ffmpeg`/`ffprobe` are installed, duration and resolution are detected, and a
  poster frame plus a 30-second low-res preview are generated automatically. Without it, upload your own
  preview MP4 and thumbnail.
- No external fonts, trackers or CDNs (keeps GDPR simple); strict Content-Security-Policy.

## Run locally

```bash
cd film-archives
npm install
npm run dev          # http://localhost:3000 — seller password in dev is "admin"
npm test
```

Without `STRIPE_SECRET_KEY`, local purchases run in **demo mode** (no payment, instant download link).

## Deploy to film-archives.com

1. Host on any Node 22.13+ server or platform with a **persistent disk**, such as a VPS, Render, Railway or Fly.io.
   Serverless hosts won't work because uploads and the SQLite database are stored on disk.
2. Set the environment variables from `.env.example`. At minimum set `NODE_ENV=production`, `BASE_URL`,
   `ADMIN_PASSWORD`, `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET`.
3. In Stripe, add a webhook endpoint `https://film-archives.com/webhooks/stripe` for
   `checkout.session.completed` and `checkout.session.async_payment_succeeded`.
4. Point the domain's DNS at the server and serve HTTPS. With a reverse proxy such as nginx or Caddy,
   raise its upload limit (for example nginx `client_max_body_size 4g;`) and timeouts for large masters.
5. Install `ffmpeg` on the server if you want previews generated automatically (`apt install ffmpeg`).
6. Back up `DATA_DIR` (database and all uploaded files).
7. Sign in at `/admin`, then open **Site settings** and fill in your **Imprint** and **Licence terms**.
   An imprint (Impressum) is legally required for commercial sites in Germany.

### On a host with SSH (no root access)

```bash
node -v                       # need v22.13 or newer; if missing or older:
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
source ~/.bashrc && nvm install 22

cd ~/film-archives
npm install --omit=dev
cp .env.example .env && nano .env   # fill in the values; npm start reads .env automatically
npm start                           # quick test, stop with Ctrl+C

# keep it running and restart it after reboots
npm install -g pm2
pm2 start npm --name film-archives -- start
pm2 save
(crontab -l 2>/dev/null; echo "@reboot $(which pm2) resurrect") | crontab -
```

The site listens on `PORT` (default 3000). Ask your host to route film-archives.com to that port
with HTTPS, or set it up yourself if your plan offers a proxy or reverse-proxy option.

## Layout

```
server.js        routes: storefront, checkout, downloads, admin, Stripe webhook
lib/config.js    environment settings and data directories
lib/db.js        SQLite (built-in node:sqlite): clips, orders, settings
lib/auth.js      signed-cookie admin session, login rate limiting
lib/stripe.js    Stripe Checkout + webhook signature verification (REST, no SDK)
lib/media.js     optional ffmpeg thumbnail/preview generation
lib/views.js     HTML templates
public/          CSS, upload progress script, favicon
test/            end-to-end tests (node:test)
```
