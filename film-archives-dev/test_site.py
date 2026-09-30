"""End-to-end tests for the PHP site, run against PHP's built-in server.

    python3 film-archives-dev/test_site.py          # tests both URL styles
"""
import hashlib, hmac, json, os, re, shutil, socket, subprocess, sys, tempfile, time
import requests

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(os.path.dirname(HERE), 'film-archives')
PASSWORD = 'correct-horse-battery'
FAILS = []


def check(cond, msg):
    print(('  ok   ' if cond else '  FAIL ') + msg)
    if not cond:
        FAILS.append(msg)


def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p


def run(pretty, fpm=False):
    root = tempfile.mkdtemp(prefix='fa-php-')
    shutil.copytree(SRC, root, dirs_exist_ok=True)
    port = free_port()
    env = dict(os.environ, FA_WEBROOT=root, PRETTY='1' if pretty else '0', FPM='1' if fpm else '0')
    srv = subprocess.Popen(['php', '-d', 'upload_max_filesize=8M', '-d', 'session.save_path=/nonexistent-session-dir', '-d', 'post_max_size=10M',
                            '-S', f'127.0.0.1:{port}', os.path.join(HERE, 'router.php')],
                           cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    base = f'http://127.0.0.1:{port}'
    time.sleep(0.8)
    P = '' if pretty else '/index.php'
    print(f"\n== {'pretty URLs' if pretty else '/index.php/ URLs'}{' (PHP-FPM style)' if fpm else ''} ==")
    try:
        s = requests.Session()
        g = lambda path, **kw: s.get(base + P + path, allow_redirects=False, **kw)
        post = lambda path, **kw: s.post(base + P + path, allow_redirects=False, **kw)
        csrf = lambda html: re.search(r'name="csrf" value="([^"]+)"', html).group(1)

        r = s.get(base + '/')
        check(r.status_code == 200 and 'The archive is empty' in r.text, 'home page renders on first visit')
        check(f'href="{P}/licence"' in r.text, 'links use the expected URL style')

        r = g('/admin')
        check('set your password' in r.text, 'first admin visit asks to set a password')
        r = post('/admin/setup', data={'password': 'short', 'password2': 'short'})
        check(r.status_code == 400, 'short password rejected')
        r = post('/admin/setup', data={'password': PASSWORD, 'password2': PASSWORD})
        check(r.status_code == 303, 'password set and logged in')

        r = g('/admin')
        check(r.status_code == 200 and 'Dashboard' in r.text, 'dashboard loads')
        check('Stripe is not set up' in r.text, 'dashboard warns that Stripe is missing')
        token = csrf(r.text)

        r = post('/admin/settings', data={'csrf': 'wrong', 'site_name': 'X'})
        check(r.status_code == 403, 'form with bad CSRF token rejected')

        r = post('/admin/settings', data={'csrf': token, 'site_name': 'Rafael Film Archives', 'tagline': 'Old footage',
                                          'contact_email': 'a@b.de', 'license_text': 'One production.',
                                          'imprint_text': 'Rafael\nBerlin', 'currency': 'eur',
                                          'stripe_secret_key': '', 'stripe_webhook_secret': 'whsec_test',
                                          'download_ttl_hours': '72', 'max_downloads': '2'})
        check(r.status_code == 303, 'settings saved')
        check('Rafael Film Archives' in s.get(base + '/').text, 'site name updated')

        # A tiny real PNG for the thumbnail.
        png = bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000'
                            '1f15c4890000000d49444154789c6360f8cf0000030101001816e2d60000000049454e44ae426082')
        master = b'MASTER' * 1000
        files = {'master': ('berlin 1928 (4K).mov', master, 'video/quicktime'),
                 'thumbnail': ('thumb.png', png, 'image/png'),
                 'preview': ('preview.mp4', b'\x00' * 5000, 'video/mp4')}
        data = {'csrf': token, 'title': 'Berlin Street Life 1928', 'description': 'Trams.\n\nPotsdamer Platz.',
                'year': '1928', 'location': 'Berlin', 'tags': 'Street Scene, trams', 'price': '149,00', 'status': 'published'}
        r = post('/admin/clips', data=data, files=files)
        check(r.status_code == 303 and r.headers['Location'].endswith('/clips/berlin-street-life-1928'), 'clip uploaded (comma price accepted)')

        bad = dict(files, thumbnail=('x.png', b'<svg onload=alert(1)>', 'image/png'))
        r = post('/admin/clips', data=dict(data, title='Bad'), files=bad)
        check(r.status_code == 400 and 'thumbnail must be' in r.text, 'fake image rejected')

        r = post('/admin/clips', data=dict(data, title='Huge'), files={'master': ('big.mov', b'0' * (12 * 1024 * 1024))})
        check(r.status_code == 413 and 'larger than this server allows' in r.text, 'oversized upload explained')

        # Large master via FTP inbox
        with open(os.path.join(root, 'data', 'inbox', 'Hamburg Harbour 1936.mxf'), 'wb') as f:
            f.write(b'BIG' * 1000)
        r = g('/admin/clips/new')
        check('Hamburg Harbour 1936.mxf' in r.text, 'FTP inbox file offered in upload form')
        r = post('/admin/clips', data=dict(data, title='Harbour at Dawn', year='1936', tags='harbour, ships', price='89',
                                           inbox_file='Hamburg Harbour 1936.mxf'))
        check(r.status_code == 303, 'clip created from FTP inbox file')
        check(not os.path.exists(os.path.join(root, 'data', 'inbox', 'Hamburg Harbour 1936.mxf')), 'inbox file moved into masters')

        # Draft is hidden publicly
        r = post('/admin/clips', data=dict(data, title='Secret Draft', status='draft', tags=''),
                 files={'master': ('d.mov', b'd')})
        check(r.status_code == 303, 'draft clip created')

        # Public side, new anonymous session
        pub = requests.Session()
        home = pub.get(base + '/').text
        check('Berlin Street Life 1928' in home and '€149.00' in home, 'clip listed with price')
        check('Secret Draft' not in home, 'draft hidden from catalogue')
        check(pub.get(base + P + '/clips/secret-draft').status_code == 404, 'draft page hidden from public')
        check('Harbour at Dawn' in pub.get(base + P + '/', params={'tag': 'ships'}).text, 'tag filter')
        check('Harbour at Dawn' not in pub.get(base + P + '/', params={'decade': '1920'}).text, 'decade filter')
        check('Berlin' in pub.get(base + P + '/', params={'q': 'Potsdamer'}).text, 'search in description')

        page = pub.get(base + P + '/clips/berlin-street-life-1928').text
        css = re.search(r'href="([^"]+styles\.css[^"]*)"', page).group(1)
        check(css.startswith('/assets/') and pub.get(base + css).status_code == 200, 'stylesheet link works on clip pages')
        link = re.search(r'class="brand" href="([^"]+)"', page).group(1)
        check(link in ('/', '/index.php/'), 'home link correct on clip pages')
        thumb = re.search(r'<img src="([^"]+)"', home).group(1)
        r = pub.get(base + thumb)
        check(r.status_code == 200 and r.headers['Content-Type'] == 'image/png', 'thumbnail served')
        prev = re.search(r'<source src="([^"]+)"', page).group(1)
        r = pub.get(base + prev, headers={'Range': 'bytes=0-99'})
        check(r.status_code == 206 and len(r.content) == 100, 'preview supports Range requests (iPhone video)')
        check('Purchases are temporarily unavailable' in page, 'public cannot buy while Stripe is not set up')

        for path in ['/data/film-archives.sqlite', '/app/db.php', '/data/masters/']:
            check(pub.get(base + path).status_code in (403, 404), f'{path} blocked from the web')
        check('MASTER' not in pub.get(base + P + '/media/thumbs/..%2Fmasters%2Fx').text, 'no path traversal via media route')

        # Seller test purchase (demo mode)
        r = post('/clips/berlin-street-life-1928/buy', data={'email': 'buyer@studio.com'})
        check(r.status_code == 400, 'licence agreement required')
        r = post('/clips/berlin-street-life-1928/buy', data={'email': 'buyer@studio.com', 'agree': '1'})
        check(r.status_code == 303 and '/orders/' in r.headers['Location'], 'demo purchase by seller')
        order_url = r.headers['Location']
        page = pub.get(base + order_url).text
        dl = re.search(r'href="([^"]*/download/[^"]+)"', page).group(1)
        r = pub.get(base + dl)
        check(r.status_code == 200 and r.content == master, 'buyer downloads the exact master')
        check("filename*=UTF-8''berlin%201928%20%284K%29.mov" in r.headers.get('Content-Disposition', ''), 'original file name kept')
        pub.get(base + dl)
        r = pub.get(base + dl, allow_redirects=False)
        check(r.status_code == 302, 'download limit enforced')
        check(pub.get(base + P + '/download/' + 'x' * 32).status_code == 404, 'unknown download token rejected')

        # Webhook
        order_id = order_url.rsplit('/', 1)[1]
        body = json.dumps({'type': 'checkout.session.completed',
                           'data': {'object': {'payment_status': 'paid', 'metadata': {'order_id': order_id}}}})
        r = pub.post(base + P + '/webhooks/stripe', data=body, headers={'Stripe-Signature': 't=1,v1=bad'})
        check(r.status_code == 400, 'webhook with bad signature rejected')
        t = int(time.time())
        sig = hmac.new(b'whsec_test', f'{t}.{body}'.encode(), hashlib.sha256).hexdigest()
        r = pub.post(base + P + '/webhooks/stripe', data=body, headers={'Stripe-Signature': f't={t},v1={sig}'})
        check(r.status_code == 200, 'webhook with valid signature accepted')

        # Sold clip can't be deleted; unsold can
        dash = g('/admin').text
        token = csrf(dash)
        ids = re.findall(r'/admin/clips/([0-9a-f-]{36})/edit', dash)
        berlin = [i for i in ids if 'value="Berlin Street Life 1928"' in g(f'/admin/clips/{i}/edit').text][0]
        check(post(f'/admin/clips/{berlin}/delete', data={'csrf': token}).status_code == 409, 'sold clip cannot be deleted')
        draft = [i for i in ids if 'value="Secret Draft"' in g(f'/admin/clips/{i}/edit').text][0]
        check(post(f'/admin/clips/{draft}/delete', data={'csrf': token}).status_code == 303, 'unsold clip deleted')

        # Edit keeps master when none uploaded
        r = post(f'/admin/clips/{berlin}', data=dict(data, csrf=token, price='199'))
        check(r.status_code == 303 and '€199.00' in pub.get(base + '/').text, 'edit updates price and keeps master')

        # Password change keeps this device logged in, and the new password works
        r = post('/admin/settings', data={'csrf': token, 'new_password': PASSWORD + '2', 'currency': 'eur',
                                          'download_ttl_hours': '72', 'max_downloads': '2', 'stripe_secret_key': '',
                                          'stripe_webhook_secret': 'whsec_test'})
        check(r.status_code == 303 and g('/admin').status_code == 200, 'still logged in after changing password')
        check(requests.post(base + P + '/admin/login', data={'password': PASSWORD}, allow_redirects=False).status_code == 401,
              'old password no longer works')
        r = post('/admin/settings', data={'csrf': csrf(g('/admin').text), 'new_password': PASSWORD, 'currency': 'eur',
                                          'download_ttl_hours': '72', 'max_downloads': '2', 'stripe_secret_key': '',
                                          'stripe_webhook_secret': 'whsec_test'})
        token = csrf(g('/admin').text)

        # Logout and wrong password
        post('/admin/logout', data={'csrf': token})
        check('Seller login' in s.get(base + P + '/admin').text, 'logout works')
        check(post('/admin/login', data={'password': 'nope'}).status_code == 401, 'wrong password rejected')
        check(post('/admin/login', data={'password': PASSWORD}).status_code == 303, 'correct password logs in')
    finally:
        srv.terminate()
        err = srv.stderr.read().decode()
        errors = [l for l in err.splitlines() if re.search(r'PHP (Warning|Fatal|Parse|Deprecated|Notice)', l)
                  and 'exceeds the limit' not in l]  # the deliberate oversized-upload test
        check(not errors, 'no PHP warnings/errors' + ('' if not errors else ':\n    ' + '\n    '.join(errors[:10])))
        shutil.rmtree(root, ignore_errors=True)


for pretty, fpm in ((True, False), (False, False), (True, True)):
    run(pretty, fpm)
print(f"\n{'ALL PASSED' if not FAILS else str(len(FAILS)) + ' FAILED'}")
sys.exit(1 if FAILS else 0)
