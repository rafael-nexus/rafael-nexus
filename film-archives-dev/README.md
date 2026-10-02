# Film Archives — film-archives.com

A storefront for selling archival film clips, written in plain PHP so it runs on ordinary web hosting
with nothing to install. Upload the **`film-archives/`** folder's contents to your web space and open the site.

This `film-archives-dev/` folder is only for development and testing. Don't upload it.

## Requirements

- PHP 8.1 or newer with `pdo_sqlite` (standard on most hosts)
- Apache with `.htaccess` support (for pretty URLs, the HTTP→HTTPS redirect and to protect the `data` folder)
- HTTPS: `.htaccess` redirects all traffic to HTTPS. On a host without SSL, remove the redirect block at the top of `.htaccess`
- Optional: `curl` (Stripe; falls back to PHP streams) and `gd` (shrinks thumbnails)
- Thumbnails and the 15-second watermarked preview are created in the seller's browser (canvas + MediaRecorder) when a master video is chosen, so no ffmpeg is needed on the server

## Install

1. Upload **everything inside** `film-archives/` into the web folder of your domain, including the hidden
   files `.htaccess`, `.user.ini`, `app/.htaccess` and `data/.htaccess`.
2. Make sure the `data` folder is writable (permissions 755 or 775).
3. Open `http://your-domain/admin` and choose your seller password. Do this right away.
4. Fill in **Site settings**: imprint (Impressum), licence terms, contact email.
5. When you're ready to sell, add your Stripe secret key and webhook secret in Site settings.
   The webhook URL to enter in Stripe is shown there.

Until a Stripe key is set, the public can't buy anything; while logged in, you can place test
purchases to try the whole flow.

## Large master files

PHP hosts limit upload size (the upload form shows the limit). For bigger masters, upload the file by FTP into
`data/inbox/`, then choose it from the list in the upload form. It is moved into the private `masters` folder.

## Keeping uploads outside the web folder (optional)

Create `config.php` next to `index.php`:

```php
<?php
define('FA_DATA_DIR', '/path/outside/the/web/folder/film-archives-data');
```

## Tests

```bash
python3 film-archives-dev/test_site.py   # needs PHP CLI and python3-requests
```

Runs 45 end-to-end checks against PHP's built-in server, once with pretty URLs and once with
`/index.php/...` URLs. `router.php` emulates the `.htaccess` rules for the built-in server.
