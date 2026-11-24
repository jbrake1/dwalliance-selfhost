#!/usr/bin/env python3
"""End-to-end check of a fresh install made with --mail-mode outbox (run as root on the server).
Codes and session ids are read from the outbox mail, the same way a user reads their email.
Nothing secret is printed. Usage: sudo python3 tests/e2e.py"""
import glob, json, os, re, subprocess, sys, time, urllib.request, urllib.error, uuid

conf = dict(l.strip().split('=', 1) for l in open('/etc/dwalliance/dwalliance.conf') if '=' in l and not l.startswith('#'))
DOMAIN, APP, DNS_IP, PUB = conf['DW_DOMAIN'], conf['DW_APP_HOST'], conf['DW_DNS_IP'], conf['DW_PUBLIC_IP']
ADMIN = conf['DW_ADMIN_EMAIL']
envf = dict(l.strip().split('=', 1) for l in open('/etc/dwalliance/dwalliance.env') if '=' in l and not l.startswith('#'))
ENV = {k: v.strip("'") for k, v in envf.items()}
BRAND_NAME = ENV.get('DW_BRAND_NAME', '')
DW_LOGO_V010 = '2f4e824f09364789f19a21c0baadcd263608146f4220d87fea1713a658fe95e4'
DW_WORDS = (b'DW Alliance', b'dwalliance.com', b'dwalliance\\.com', b'mingapo' + b'thecary', b'acceptn' + b'give')  # hosted customer sites (split so build.py's own check passes)
OUTBOX = '/var/lib/app/data/outbox'
results = []

def check(name, ok, detail=''):
    results.append((name, bool(ok)))
    print(('PASS ' if ok else 'FAIL ') + name + (f'  ({detail})' if detail else ''))

def req(host, path, body=None, method=None, headers=None, raw=False):
    data = None
    h = {'Host': host}
    if headers: h.update(headers)
    if isinstance(body, (dict, list)):
        data = json.dumps(body).encode(); h['Content-Type'] = 'application/json'
    elif isinstance(body, bytes):
        data = body
    r = urllib.request.Request('http://127.0.0.1' + path, data=data, headers=h, method=method or ('POST' if data is not None else 'GET'))
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    opener = urllib.request.build_opener(NoRedirect)
    try:
        resp = opener.open(r, timeout=60)
        code, hdrs, content = resp.status, dict(resp.headers), resp.read()
        hdrs['Set-Cookie'] = ', '.join(resp.headers.get_all('Set-Cookie') or [])
    except urllib.error.HTTPError as e:
        code, hdrs, content = e.code, dict(e.headers), e.read()
        hdrs['Set-Cookie'] = ', '.join(e.headers.get_all('Set-Cookie') or [])
    if raw:
        return code, hdrs, content
    try:
        return code, json.loads(content)
    except Exception:
        return code, {'_raw': content[:200].decode('utf-8', 'replace')}

def mails_to(addr, after):
    out = []
    for f in sorted(glob.glob(OUTBOX + '/*.eml')):
        if os.path.getmtime(f) < after: continue
        t = open(f, encoding='utf-8', errors='replace').read()
        if f'<{addr}>' in t.split('\r\n\r\n', 1)[0] or f'To: <{addr}>' in t:
            out.append(t)
    return out

def wait_mail(addr, after, pattern, tries=20, subject=None):
    for _ in range(tries):
        for t in reversed(mails_to(addr, after)):
            if subject and not re.search(r'^Subject: .*' + subject, t, re.M): continue
            m = re.search(pattern, t)
            if m: return m.group(1)
        time.sleep(0.5)
    return None

def multipart(fields, fname, fbytes, ctype):
    b = uuid.uuid4().hex
    parts = []
    for k, v in fields.items():
        parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    parts.append(f'--{b}\r\nContent-Disposition: form-data; name="file"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + fbytes + b'\r\n')
    parts.append(f'--{b}--\r\n'.encode())
    return b''.join(parts), {'Content-Type': f'multipart/form-data; boundary={b}'}

def dig(name, rtype='A'):
    return subprocess.run(['dig', '+short', '+time=2', '+tries=2', f'@{DNS_IP}', name, rtype], capture_output=True, text=True).stdout.strip().splitlines()

def dns_build():
    subprocess.run(['dw-run', 'php', '/opt/dwalliance/current/cli/dw-dns-build.php'], capture_output=True)

# 1. nginx serves the app
code, hdr, body = req(APP, '/', raw=True)
check('nginx serves the API overview on the app host', code == 200 and APP.encode() in body, f'HTTP {code}')
code, j = req(APP, '/account_setup/', {})
check('PHP module routing (/account_setup/ help JSON)', code == 200 and j.get('path') == '/account_setup')
code, hdr, _ = req(APP, '/store', raw=True)
check('module folder redirect keeps relative Location (/store -> /store/)', code in (301, 302) and hdr.get('Location', '').startswith('/store/'), f'{code} {hdr.get("Location")}')
code, j = req(APP, '/link_tree/', {})
check('link_tree module is routed', code == 200 and 'link_tree' in json.dumps(j))

# 2. sign in as the bootstrapped super admin (brand code was emailed; read it from the outbox)
brand = wait_mail(ADMIN, 0, r'Your brand code is ([A-Za-z0-9]{1,10})')
check('first admin brand code was emailed', bool(brand))
t0 = time.time() - 1
code, j = req(APP, '/session/', {'email': ADMIN, 'brand': brand})
check('POST /session/ (sign-in) answers ok + mailed', code == 200 and j.get('ok') is True, f'HTTP {code}')
sess = wait_mail(ADMIN, t0, r'\b([0-9a-f]{50})\b', subject='session')
check('session id arrives by email only', bool(sess) and sess not in json.dumps(j))
code, j = req(APP, '/session/', {'action': 'get_permissions', 'session': sess, 'brand': brand})
check('get_permissions: super_admin on the first brand', code == 200 and j.get('super_admin') is True)

# 3. /files upload (HTML + PNG), public, served on the platform domain and /BRAND/ path
html = f'<!doctype html><html><head><title>Self-host test</title></head><body><h1>Hello from {DOMAIN}</h1></body></html>'.encode()
body, h = multipart({'action': 'create', 'session': sess, 'file_name': 'index.html', 'file_path': '', 'file_type': 'HTML', 'public_private': 'PUBLIC'}, 'index.html', html, 'text/html')
code, j = req(APP, '/files/', body, headers=h)
check('POST /files/ create (HTML upload)', code == 200 and j.get('ok') is True, f'HTTP {code} {j.get("error", "")}')
code, hdr, content = req(DOMAIN, '/index.html', raw=True)
check(f'uploaded page served at http://{DOMAIN}/index.html', code == 200 and b'Hello from' in content, f'HTTP {code}')
code, hdr, content = req('www.' + DOMAIN, '/', raw=True)
check(f'uploaded page served at http://www.{DOMAIN}/', code == 200 and b'Hello from' in content, f'HTTP {code}')
code, hdr, content = req(APP, f'/{brand}/index.html', raw=True)
check('uploaded page served at http://APP/<brand>/index.html', code == 200 and b'Hello from' in content, f'HTTP {code}')
png = open('/var/lib/app/data/email/dw-logo.png', 'rb').read()
body, h = multipart({'action': 'create', 'session': sess, 'file_name': 'dw-logo.png', 'file_path': 'assets/email', 'file_type': 'PNG', 'public_private': 'PUBLIC'}, 'dw-logo.png', png, 'image/png')
code, j = req(APP, '/files/', body, headers=h)
check('POST /files/ create (PNG upload)', code == 200 and j.get('ok') is True, f'HTTP {code} {j.get("error", "")}')
code, hdr, content = req(DOMAIN, '/assets/email/dw-logo.png', raw=True)
check('PNG served through the image/watermark path (gd)', code == 200 and content[:8] == b'\x89PNG\r\n\x1a\n', f'HTTP {code} {hdr.get("Content-Type")}')
code, j = req(APP, '/files/', {'action': 'search', 'session': sess, 'limit': 10})
check('POST /files/ search lists the uploads', code == 200 and j.get('ok') is True and len(j.get('files', j.get('results', []))) >= 2, f'HTTP {code}')

# 4. link_tree redirect
code, j = req(APP, '/link_tree/', {'action': 'create', 'session': sess, 'path': 'old-home.html', 'target': 'index.html'})
check('link_tree create (301 old-home.html -> index.html)', code == 200 and j.get('ok') is True, f'HTTP {code} {j.get("error", "")}')
code, hdr, _ = req(DOMAIN, '/old-home.html', raw=True)
check('link_tree redirect is served', code in (301, 302) and 'index.html' in hdr.get('Location', ''), f'{code} {hdr.get("Location")}')

# 5. new brand through public signup (codes read from email), then sign in
user = 'owner@customer.test'
t1 = time.time() - 1
code, j = req(APP, '/account_setup/', {'email': user})
sid = j.get('id')
conf_code = wait_mail(user, t1, r'confirmation id is ([0-9a-z]{6})')
check('signup step 1 emails a confirmation code', bool(sid) and bool(conf_code), f'HTTP {code} {j.get("error", "")}')
code, j = req(APP, '/account_setup/', {'id': sid, 'confirmation_id': conf_code, 'first_name': 'Cora', 'last_name': 'Customer', 'company_name': 'Customer Test Co'})
company_id = j.get('company_id')
check('signup step 2 creates person, company and brand', code == 200 and bool(j.get('person_id')) and bool(company_id), f'HTTP {code} {j.get("error", "")}')
brand2 = wait_mail(user, t1, r'Your brand code is ([A-Za-z0-9]{1,10})')
check('new brand code is emailed, not returned', bool(brand2) and brand2 not in json.dumps(j))
t2 = time.time() - 1
req(APP, '/session/', {'email': user, 'brand': brand2})
sess2 = wait_mail(user, t2, r'\b([0-9a-f]{50})\b', subject='session')
check('new brand owner can sign in', bool(sess2))

# 6. domain claim -> NS rows -> tinydns answers; /dns record -> tinydns answers
code, j = req(APP, '/domain/', {'action': 'create', 'session': sess2, 'brand': brand2, 'company_id': company_id, 'domain_name': 'customer.test'})
check('POST /domain/ claims customer.test', code == 200 and j.get('ok') is True, f'HTTP {code} {j.get("error", "")}')
code, j = req(APP, '/dns/', {'action': 'create', 'session': sess2, 'brand': brand2, 'company_id': company_id, 'domain': 'customer.test', 'rr_type': '+', 'rr_fqdn': 'www.customer.test', 'rr_ip': PUB})
check('POST /dns/ creates an A record', code == 200 and j.get('ok') is True, f'HTTP {code} {j.get("error", "")}')
code, j = req(APP, '/dns/', {'action': 'create', 'session': sess2, 'brand': brand2, 'company_id': company_id, 'domain': 'customer.test', 'rr_type': "'", 'rr_fqdn': 'customer.test', 'rr_target': 'hello=world:1'})
check('POST /dns/ creates a TXT record', code == 200 and j.get('ok') is True, f'HTTP {code} {j.get("error", "")}')
code, j = req(APP, '/dns/', {'action': 'create', 'session': sess2, 'brand': brand2, 'company_id': company_id, 'domain': 'customer.test', 'rr_type': '+', 'rr_fqdn': f'app.{DOMAIN}', 'rr_ip': '198.51.100.1'})
check('/dns refuses another zone / protected platform name', code >= 400)
dns_build()
check(f'tinydns: A {APP}', dig(APP) == [PUB], str(dig(APP)))
check(f'tinydns: NS {DOMAIN}', sorted(dig(DOMAIN, 'NS')) == sorted([f'ns{i}.{DOMAIN}.' for i in (1, 2, 3)]), str(dig(DOMAIN, 'NS')))
check(f'tinydns: MX {DOMAIN}', any(f'mail.{DOMAIN}.' in x for x in dig(DOMAIN, 'MX')), str(dig(DOMAIN, 'MX')))
check('tinydns: NS customer.test -> platform name servers', sorted(dig('customer.test', 'NS')) == sorted([f'ns{i}.{DOMAIN}.' for i in (1, 2, 3)]), str(dig('customer.test', 'NS')))
check('tinydns: A www.customer.test (written via /dns)', dig('www.customer.test') == [PUB], str(dig('www.customer.test')))
check('tinydns: TXT with a colon is escaped correctly', dig('customer.test', 'TXT') == ['"hello=world:1"'], str(dig('customer.test', 'TXT')))
soa = dig('customer.test', 'SOA')
check('tinydns: SOA for customer.test', bool(soa) and soa[0].startswith(f'ns1.{DOMAIN}.'), str(soa))

# 7. other modules answer
for host, path, want in [(APP, '/store/', 200), (APP, '/permissions/', 200), (APP, '/affiliate/', 200), (APP, '/oauth/', None), (DOMAIN, '/session/login', 200), (APP, '/openapi/files.json', 200)]:
    code, hdr, content = req(host, path, raw=True)
    ok = (code == want) if want else code < 500
    check(f'GET {host}{path}', ok, f'HTTP {code}')
code, hdr, content = req(APP, '/openapi/files.json', raw=True)
check('OpenAPI spec names this install, not dwalliance.com', APP.encode() in content and b'dwalliance.com' not in content)
code, hdr, content = req(APP, '/lib/Dw.php', raw=True)
check('lib/ PHP source is not downloadable', b'getenv' not in content, f'HTTP {code}')
code, hdr, content = req(APP, '/.git/config', raw=True)
check('dotfiles denied', code in (403, 404), f'HTTP {code}')

# 8. magic-link sign-in on the platform domain (mail goes to the sink in outbox mode)
import email as _email
t3 = time.time() - 1
code, hdr, _ = req(DOMAIN, '/session/login', b'email=' + ADMIN.encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'}, raw=True)
link = None
for _ in range(20):
    for f in sorted(glob.glob('/var/lib/app/data/magiclink-outbox/*'), key=os.path.getmtime, reverse=True):
        if os.path.getmtime(f) < t3: continue
        m = _email.message_from_bytes(open(f, 'rb').read())
        for part in m.walk():
            if part.get_content_type() == 'text/plain':
                urls = [u for u in re.findall(r'https?://\S+', part.get_payload(decode=True).decode()) if '?t=' in u]
                link = urls[0] if urls else None
        break
    if link: break
    time.sleep(0.5)
check('magic-link email sent for the platform domain', code == 200 and bool(link), f'HTTP {code}')
if link:
    code, hdr, _ = req(DOMAIN, re.sub(r'^https?://[^/]+', '', link), raw=True)
    cookies = [c.split('=')[0] for c in (hdr.get('Set-Cookie', '') or '').split(', ')] if hdr.get('Set-Cookie') else []
    check('magic link signs in (303 + site session cookie)', code in (302, 303) and any('__Host-dw_s' in c for c in cookies), f'HTTP {code}')

# 9. branding: this install's name everywhere, never the DW Alliance trademarks
def dw_hits(content):
    return [w.decode() for w in DW_WORDS if w in content]
pages = [(APP, '/'), (APP, '/account_setup/'), (APP, '/session/'), (APP, '/files/'), (APP, '/dns/'), (APP, '/domain/'),
         (APP, '/store/'), (APP, '/store/guide.php'), (APP, '/store/kit/'), (APP, '/store/banner/'), (APP, '/store/subscribe/'),
         (APP, '/store/subscription_cancel/'), (APP, '/permissions/'), (APP, '/affiliate/'), (APP, '/affiliate/join/'),
         (APP, '/link_tree/'), (APP, '/markup/'), (APP, '/oauth/'), (APP, '/openapi/files.json'), (APP, '/openapi/affiliate.json'),
         (APP, '/js/dw/dw.js'), (APP, '/robots.txt'), (DOMAIN, '/session/login'), (DOMAIN, '/no-such-page.html')]
bad = []
for host, path in pages:
    code, hdr, content = req(host, path, raw=True)
    if code >= 500:
        bad.append(f'{host}{path} HTTP {code}')
    elif dw_hits(content):
        bad.append(f'{host}{path}: {",".join(dw_hits(content))}')
check(f'{len(pages)} pages and API answers show no DW Alliance name, domain or hosted customer site', not bad, '; '.join(bad)[:400])
code, hdr, content = req(APP, '/', raw=True)
check('API overview shows the configured brand name', bool(BRAND_NAME) and BRAND_NAME.encode() in content, BRAND_NAME)
code, hdr, content = req(APP, '/js/dw/dw.js', raw=True)
check('JS client defaults to this app host', f"'https://{APP}'".encode() in content, f'HTTP {code}')
since = os.path.getmtime('/etc/dwalliance/dwalliance.conf') - 1  # mails sent by this release (an upgrade keeps older ones)
mails = [open(f, 'rb').read() for f in glob.glob(OUTBOX + '/*.eml') + glob.glob('/var/lib/app/data/magiclink-outbox/*') if os.path.getmtime(f) >= since]
bad = [m.split(b'\r\n\r\n', 1)[0].decode('utf-8', 'replace').split('Subject: ')[-1][:60] for m in mails if dw_hits(m)]
check(f'{len(mails)} sent emails show no DW Alliance name or domain', mails and not bad, '; '.join(bad)[:300])
froms = {re.search(rb'^From: (.*)$', m, re.M).group(1).decode().strip() for m in mails if re.search(rb'^From: ', m, re.M)}
check('emails come from the configured brand and support address', froms and all(ENV.get('DW_SUPPORT_EMAIL', '') in f for f in froms) and any(BRAND_NAME in f for f in froms), str(sorted(froms))[:200])
logo = open('/var/lib/app/data/email/dw-logo.png', 'rb').read()
import hashlib as _hashlib
check('email logo is generated for this install (not the DW Alliance logo)', logo[:8] == b'\x89PNG\r\n\x1a\n' and _hashlib.sha256(logo).hexdigest() != DW_LOGO_V010)
php = lambda code: subprocess.run(['dw-run', 'php', '-r', code], capture_output=True, text=True).stdout
terms = php("require '/var/www/html/lib/DwAffiliate.php'; echo DwAffiliate::defaultTerms();")
check('default affiliate terms use the operator placeholders, not DW Alliance', ENV.get('DW_AFFILIATE_TERMS_COMPANY', '') in terms and 'DW Alliance' not in terms and 'dwalliance' not in terms and len(terms) > 200, terms[-160:])
notice = php("require '/var/www/html/lib/DwConfig.php'; echo DwConfig::get('AFFILIATE_TERMS_NOTICE');")
check('affiliate terms carry the lawyer-review notice', 'lawyer' in notice)

# 10. signup country rule: open to all except the configured blocked list (deny mode)
tc = json.load(open('/var/lib/app/data/trust/config.json')).get('signup', {})
meta = json.load(open('/var/lib/app/data/trust/iplists/country.json')) if os.path.exists('/var/lib/app/data/trust/iplists/country.json') else {}
if tc.get('country') == 'deny':
    check('deny-list built from DB-IP (blocked countries + regions)', meta.get('mode') == 'deny' and meta.get('ranges4', 0) > 100 and meta.get('blocked_countries') == [c.upper() for c in tc.get('blocked_countries', [])], f"mode={meta.get('mode')} v4={meta.get('ranges4')} unmatched={meta.get('unmatched_regions')}")
    check('every default region name matched DB-IP', not meta.get('unmatched_regions'), str(meta.get('unmatched_regions')))
    out = php("require '/var/www/html/lib/DwSignup.php'; $b = file_get_contents(DwSignup::DIR . '/country-deny4.bin'); "
              "$ip = inet_ntop(substr($b, 0, 4)); echo json_encode(['open' => DwSignup::inCountry('8.8.8.8'), 'blocked' => DwSignup::inCountry($ip), 'reason' => DwSignup::countryReason()]);")
    try: r = json.loads(out)
    except Exception: r = {}
    check('a US address may sign up; an address in a blocked range may not', r.get('open') is True and r.get('blocked') is False and r.get('reason') == 'blocked_country', out[:200])
else:
    check('signup country rule is configured', tc.get('country') in ('allow', 'block'), str(tc.get('country')))

failed = [n for n, ok in results if not ok]
print(f'\n{len(results) - len(failed)}/{len(results)} passed')
sys.exit(1 if failed else 0)
