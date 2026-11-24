<?php
// Create the first super-admin brand through the app's own /account_setup API, then grant SUPER_ADMIN
// and attach the platform domain to it. Run as root by install.sh. Idempotent: if the email already
// has SUPER_ADMIN on a brand, nothing is created and that brand is reused.
//
//   php dw-bootstrap-admin.php --email you@example.com --first Ada --last Lovelace [--company "Acme LLC"]
//
// The brand code is never printed. It is emailed by the app (as on every signup) and saved to
// /etc/dwalliance/admin-brand-code (mode 600, root) so the operator can find it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once '/var/www/html/lib/Dw.php';

$opt = getopt('', ['email:', 'first:', 'last:', 'company::', 'app-host:', 'domain:', 'local-url::']);
$email = strtolower(trim((string)($opt['email'] ?? '')));
$first = trim((string)($opt['first'] ?? ''));
$last = trim((string)($opt['last'] ?? ''));
$company = trim((string)($opt['company'] ?? ''));
$appHost = trim((string)($opt['app-host'] ?? ''));
$domain = strtolower(trim((string)($opt['domain'] ?? '')));
$base = rtrim((string)($opt['local-url'] ?? 'http://127.0.0.1'), '/');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $first === '' || $last === '' || $appHost === '' || $domain === '') {
    fwrite(STDERR, "usage: --email --first --last --app-host --domain [--company]\n");
    exit(2);
}
$codeFile = '/etc/dwalliance/admin-brand-code';
$db = Dw::pdo();

function post(string $base, string $host, string $path, array $body): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Host: ' . $host, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode($raw, true);
    return [$code, is_array($j) ? $j : ['raw' => substr($raw, 0, 300)]];
}

// 1. Already bootstrapped?
$q = $db->prepare("SELECT d.brand, d.person_id FROM data_access d JOIN email e ON e.owner_id = d.person_id AND e.owner_type = 'PRSN' AND e.brand = d.brand
                   WHERE LOWER(e.email) = ? AND d.right_name = 'SUPER_ADMIN' LIMIT 1");
$q->execute([$email]);
$row = $q->fetch(PDO::FETCH_ASSOC);
$created = false;
if ($row) {
    $brand = (string)$row['brand'];
    $personId = (string)$row['person_id'];
    echo "admin: $email already has SUPER_ADMIN on a brand; reusing it\n";
} else {
    // 2. Signup through the app: step 1 (email), read the emailed confirmation code from the DB (root, local), step 2.
    [$c1, $r1] = post($base, $appHost, '/account_setup/', ['email' => $email]);
    if (empty($r1['id'])) {
        fwrite(STDERR, "account_setup step 1 failed (HTTP $c1): " . json_encode($r1) . "\n");
        exit(1);
    }
    $s = $db->prepare('SELECT confirmation_id FROM account_setup WHERE id = ?');
    $s->execute([(string)$r1['id']]);
    $confirm = (string)$s->fetchColumn();
    $step2 = ['id' => (string)$r1['id'], 'confirmation_id' => $confirm, 'first_name' => $first, 'last_name' => $last];
    if ($company !== '') { $step2['company_name'] = $company; }
    [$c2, $r2] = post($base, $appHost, '/account_setup/', $step2);
    if (empty($r2['person_id'])) {
        fwrite(STDERR, "account_setup step 2 failed (HTTP $c2): " . json_encode($r2) . "\n");
        exit(1);
    }
    $personId = (string)$r2['person_id'];
    $b = $db->prepare('SELECT brand FROM person WHERE id = ?');
    $b->execute([$personId]);
    $brand = (string)$b->fetchColumn();
    $created = true;
    echo "admin: account created through /account_setup\n";
}
if ($brand === '') { fwrite(STDERR, "could not find the new brand\n"); exit(1); }

// 3. SUPER_ADMIN on that brand (operator grant, with history), then refresh any open sessions.
$db->beginTransaction();
$has = $db->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND right_name = 'SUPER_ADMIN'");
$has->execute([$personId, $brand]);
if (!$has->fetchColumn()) {
    $id = Dw::randId();
    $db->prepare("INSERT INTO data_access (id, person_id, module_name, right_name, brand, created, created_by_person_id) VALUES (?, ?, 'LOGIN', 'SUPER_ADMIN', ?, NOW(), ?)")
        ->execute([$id, $personId, $brand, $personId]);
    Dw::history($db, 'data_access', $id, 'INSERT');
    echo "admin: SUPER_ADMIN granted\n";
}
// 4. Platform domain (apex + www) belongs to this brand, so https://<domain>/ serves its files.
$companyQ = $db->prepare('SELECT dw_company_id FROM brand WHERE code = ?');
$companyQ->execute([$brand]);
$companyId = (string)($companyQ->fetchColumn() ?: '');
foreach ([$domain, 'www.' . $domain] as $name) {
    $d = $db->prepare('SELECT code FROM domain WHERE domain_name = ?');
    $d->execute([$name]);
    $owner = $d->fetchColumn();
    if ($owner === false) {
        $id = Dw::randId();
        $db->prepare('INSERT INTO domain (id, domain_name, code, company_id, created_by, created) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$id, $name, $brand, $companyId !== '' ? $companyId : null, $personId]);
        Dw::history($db, 'domain', $id, 'INSERT');
        echo "admin: domain $name attached\n";
    } elseif ((string)$owner !== $brand) {
        echo "admin: domain $name already belongs to another brand; left alone\n";
    }
}
Dw::refreshSessionRights($db, $personId, $brand);
$db->commit();

$old = umask(077);
file_put_contents($codeFile, $brand . "\n");
umask($old);
chmod($codeFile, 0600);
echo "admin: brand code saved to $codeFile (root only)" . ($created ? " and emailed to the admin by /account_setup" : '') . "\n";
