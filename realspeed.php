<?php
/**
 * RealSpeed - Single-file subscription rewriter
 * PHP 8+ | cURL | SQLite (PDO)
 *
 * Features:
 * - Accepts an upstream V2Ray/Xray-style subscription URL.
 * - Creates one public RealSpeed subscription URL per upstream URL.
 * - Rewrites config names to: 🥇RealSpeed XXXXXXXX
 * - Refreshes upstream content at most once every 24 hours per entry.
 * - Reads subscription metadata (upload/download/total/expire) from
 *   common Subscription-Userinfo response headers when available.
 * - Shows a clean metadata page when the generated URL is opened in a browser.
 * - Returns plain subscription content to subscription clients.
 *
 * IMPORTANT:
 * - The generated links are persisted in SQLite, not PHP sessions.
 * - Automatic refresh is performed on the generated link's request.
 *   For true unattended daily refresh even with zero traffic, use a cron job
 *   that requests ?action=refresh-all&key=YOUR_CRON_KEY every 24h.
 */

declare(strict_types=1);

const DB_FILE = __DIR__ . '/realspeed.sqlite';
const REFRESH_INTERVAL = 86400; // 24 hours
const HTTP_TIMEOUT = 20;
const MAX_BODY_SIZE = 8 * 1024 * 1024; // 8 MB
const PUBLIC_PATH = ''; // Keep empty for root deployment; e.g. '/realspeed.php' if needed.

// Optional cron protection. Set an environment variable on the server:
// RS_CRON_KEY=your-long-random-secret
const CRON_ENV_NAME = 'RS_CRON_KEY';

ini_set('default_charset', 'UTF-8');

$db = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS subscriptions (
    id TEXT PRIMARY KEY,
    upstream_url TEXT NOT NULL,
    name TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL DEFAULT 0,
    last_http_code INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    content TEXT NOT NULL DEFAULT '',
    upload INTEGER DEFAULT NULL,
    download INTEGER DEFAULT NULL,
    total INTEGER DEFAULT NULL,
    expire INTEGER DEFAULT NULL,
    response_userinfo TEXT NOT NULL DEFAULT ''
)
SQL);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function baseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $host) ?: 'localhost';

    return $scheme . '://' . $host;
}

function publicUrl(string $id): string
{
    $path = PUBLIC_PATH;
    if ($path === '') {
        $path = $_SERVER['SCRIPT_NAME'] ?? '/';
    }
    return rtrim(baseUrl(), '/') . '/' . ltrim($path, '/') . '?id=' . rawurlencode($id);
}

function randomId(int $length = 10): string
{
    // Base32-ish, URL-safe, lowercase.
    $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    $max = strlen($alphabet) - 1;

    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

function randomConfigSuffix(int $length = 8): string
{
    return strtoupper(substr(bin2hex(random_bytes(8)), 0, $length));
}

function validateSubscriptionUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = strtolower($parts['host']);
    if ($host === 'localhost' || $host === 'localhost.localdomain') {
        return false;
    }

    // Reject obvious local/private IP targets to reduce SSRF risk.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($host, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }
    }

    return true;
}

function parseUserinfoHeader(string $header): array
{
    $result = [
        'upload' => null,
        'download' => null,
        'total' => null,
        'expire' => null,
        'raw' => $header,
    ];

    if ($header === '') {
        return $result;
    }

    // Common format:
    // upload=123; download=456; total=789; expire=1234567890
    foreach (preg_split('/\s*;\s*/', $header) as $piece) {
        if (!str_contains($piece, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $piece, 2));
        $key = strtolower($key);
        if (!ctype_digit($value)) {
            continue;
        }
        $valueInt = (int)$value;

        if (array_key_exists($key, $result)) {
            $result[$key] = $valueInt;
        }
    }

    return $result;
}

function fetchUpstream(string $url): array
{
    $headers = [];
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'RealSpeed/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain, text/base64, application/octet-stream, */*',
            'Cache-Control: no-cache',
        ],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $trimmed = trim($line);
            if ($trimmed === '' || !str_contains($trimmed, ':')) {
                return strlen($line);
            }
            [$name, $value] = explode(':', $trimmed, 2);
            $headers[strtolower(trim($name))] = trim($value);
            return strlen($line);
        },
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $downloadSize = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'code' => $httpCode,
            'body' => '',
            'content_type' => $contentType,
            'error' => $error ?: 'خطای نامشخص cURL',
            'userinfo' => '',
            'upload' => null,
            'download' => null,
            'total' => null,
            'expire' => null,
            'bytes' => $downloadSize,
        ];
    }

    if (strlen($body) > MAX_BODY_SIZE) {
        return [
            'ok' => false,
            'code' => $httpCode,
            'body' => '',
            'content_type' => $contentType,
            'error' => 'حجم پاسخ سابسکریپشن بیش از حد مجاز است.',
            'userinfo' => '',
            'upload' => null,
            'download' => null,
            'total' => null,
            'expire' => null,
            'bytes' => strlen($body),
        ];
    }

    $userinfo = '';
    foreach (['subscription-userinfo', 'subscription-user-info', 'x-subscription-userinfo'] as $key) {
        if (!empty($headers[$key])) {
            $userinfo = $headers[$key];
            break;
        }
    }

    $meta = parseUserinfoHeader($userinfo);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300 && trim($body) !== '',
        'code' => $httpCode,
        'body' => $body,
        'content_type' => $contentType,
        'error' => ($httpCode >= 200 && $httpCode < 300) ? '' : ('HTTP ' . $httpCode),
        'userinfo' => $userinfo,
        'upload' => $meta['upload'],
        'download' => $meta['download'],
        'total' => $meta['total'],
        'expire' => $meta['expire'],
        'bytes' => $downloadSize,
    ];
}

function looksLikeBase64(string $body): bool
{
    $clean = preg_replace('/\s+/', '', trim($body));
    if ($clean === '' || strlen($clean) < 12) {
        return false;
    }
    if (preg_match('/[^A-Za-z0-9+\/=]/', $clean)) {
        return false;
    }

    $decoded = base64_decode($clean, true);
    if ($decoded === false || $decoded === '') {
        return false;
    }

    // A decoded subscription usually contains protocol URLs / JSON / lines.
    return preg_match('/(?:vless|vmess|trojan|ss|ssr):\/\//i', $decoded) === 1
        || preg_match('/\{\s*"(?:v|ps|add|port)"/i', $decoded) === 1;
}

function decodeSubscription(string $body): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', trim($body));

    if (looksLikeBase64($raw)) {
        $clean = preg_replace('/\s+/', '', $raw);
        $decoded = base64_decode($clean, true);
        if ($decoded !== false && trim($decoded) !== '') {
            return [
                'raw' => $decoded,
                'encoded' => true,
            ];
        }
    }

    return [
        'raw' => $raw,
        'encoded' => false,
    ];
}

function splitSubscriptionLines(string $raw): array
{
    return preg_split('/\r\n|\r|\n/', $raw) ?: [];
}

function replaceUrlFragmentName(string $line, string $newName): string
{
    // Replace #fragment in common URI-style configs. Preserve URL encoding.
    $hashPos = strpos($line, '#');
    if ($hashPos === false) {
        return $line . '#' . rawurlencode($newName);
    }

    return substr($line, 0, $hashPos + 1) . rawurlencode($newName);
}

function rewriteVmessJson(string $line, string $newName): ?string
{
    $decoded = base64_decode($line, true);
    if ($decoded === false) {
        $decoded = base64_decode(strtr($line, '-_', '+/'), true);
    }
    if ($decoded === false || trim($decoded) === '' || !str_starts_with(ltrim($decoded), '{')) {
        return null;
    }

    $data = json_decode($decoded, true);
    if (!is_array($data)) {
        return null;
    }

    $looksVmess = isset($data['add']) || isset($data['port']) || isset($data['id']);
    if (!$looksVmess) {
        return null;
    }

    $data['ps'] = $newName;
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return null;
    }

    return base64_encode($json);
}

function rewriteSubscription(string $body): array
{
    $decoded = decodeSubscription($body);
    $raw = $decoded['raw'];
    $encodedInput = $decoded['encoded'];

    $lines = splitSubscriptionLines($raw);
    $out = [];
    $counter = 0;

    foreach ($lines as $line) {
        $original = trim($line);
        if ($original === '') {
            continue;
        }

        $counter++;
        $name = '🥇RealSpeed ' . randomConfigSuffix(8);

        // vmess subscription can be a base64-encoded JSON object line.
        if (preg_match('/^eyJ/i', $original)) {
            $rewrittenVmess = rewriteVmessJson($original, $name);
            if ($rewrittenVmess !== null) {
                $out[] = $rewrittenVmess;
                continue;
            }
        }

        // URI-style configs: add/replace URI fragment.
        if (preg_match('/^(?:vless|vmess|trojan|ss|ssr):\/\//i', $original)) {
            $out[] = replaceUrlFragmentName($original, $name);
            continue;
        }

        // Plain JSON VMess object.
        if ($original[0] === '{') {
            $obj = json_decode($original, true);
            if (is_array($obj) && (isset($obj['add']) || isset($obj['port']) || isset($obj['id']))) {
                $obj['ps'] = $name;
                $encoded = json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    $out[] = $encoded;
                    continue;
                }
            }
        }

        // Unknown line format: preserve it unchanged.
        $out[] = $original;
    }

    if (count($out) === 0) {
        return [
            'ok' => false,
            'content' => '',
            'count' => 0,
            'encoded' => false,
            'error' => 'هیچ کانفیگ قابل پردازشی در سابسکریپشن پیدا نشد.',
        ];
    }

    $result = implode("\n", $out) . "\n";

    // If upstream was base64 subscription, return base64 as well.
    if ($encodedInput) {
        $result = base64_encode($result);
    }

    return [
        'ok' => true,
        'content' => $result,
        'count' => count($out),
        'encoded' => $encodedInput,
        'error' => '',
    ];
}

function formatBytes(?int $bytes): string
{
    if ($bytes === null) {
        return 'نامشخص';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float)$bytes;
    $unit = 'B';
    foreach ($units as $u) {
        $value /= 1024;
        $unit = $u;
        if ($value < 1024) {
            break;
        }
    }

    return number_format($value, 2) . ' ' . $unit;
}

function remainingBytes(?int $total, ?int $upload, ?int $download): ?int
{
    if ($total === null || $upload === null || $download === null) {
        return null;
    }
    return max(0, $total - $upload - $download);
}

function formatExpire(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return 'نامشخص';
    }

    return date('Y/m/d H:i', $timestamp);
}

function refreshRow(PDO $db, array $row, bool $force = false): array
{
    $now = time();
    $needsRefresh = $force || ((int)$row['updated_at'] === 0) || ($now - (int)$row['updated_at'] >= REFRESH_INTERVAL);

    if (!$needsRefresh && $row['content'] !== '') {
        return $row;
    }

    $fetched = fetchUpstream($row['upstream_url']);

    if (!$fetched['ok']) {
        $stmt = $db->prepare('UPDATE subscriptions SET last_http_code = ?, last_error = ? WHERE id = ?');
        $stmt->execute([$fetched['code'], $fetched['error'], $row['id']]);

        $row['last_http_code'] = $fetched['code'];
        $row['last_error'] = $fetched['error'];
        return $row;
    }

    $rewritten = rewriteSubscription($fetched['body']);

    if (!$rewritten['ok']) {
        $stmt = $db->prepare('UPDATE subscriptions SET last_http_code = ?, last_error = ? WHERE id = ?');
        $stmt->execute([$fetched['code'], $rewritten['error'], $row['id']]);

        $row['last_http_code'] = $fetched['code'];
        $row['last_error'] = $rewritten['error'];
        return $row;
    }

    $stmt = $db->prepare(<<<SQL
UPDATE subscriptions
SET updated_at = ?,
    last_http_code = ?,
    last_error = '',
    content = ?,
    upload = ?,
    download = ?,
    total = ?,
    expire = ?,
    response_userinfo = ?
WHERE id = ?
SQL);

    $stmt->execute([
        $now,
        $fetched['code'],
        $rewritten['content'],
        $fetched['upload'],
        $fetched['download'],
        $fetched['total'],
        $fetched['expire'],
        $fetched['userinfo'],
        $row['id'],
    ]);

    $row['updated_at'] = $now;
    $row['last_http_code'] = $fetched['code'];
    $row['last_error'] = '';
    $row['content'] = $rewritten['content'];
    $row['upload'] = $fetched['upload'];
    $row['download'] = $fetched['download'];
    $row['total'] = $fetched['total'];
    $row['expire'] = $fetched['expire'];
    $row['response_userinfo'] = $fetched['userinfo'];

    return $row;
}

function findById(PDO $db, string $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function showErrorPage(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>RealSpeed</title>
        <style>
            body{margin:0;background:#0d1220;color:#f5f7fb;font-family:Tahoma,Arial,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;box-sizing:border-box}
            .card{width:min(700px,100%);background:#141c2e;border:1px solid #263450;border-radius:22px;padding:28px;box-shadow:0 18px 60px rgba(0,0,0,.35)}
            .brand{font-size:22px;font-weight:800;margin-bottom:16px}.brand span{color:#ffd166}
            .err{padding:16px;border-radius:14px;background:#2a1720;color:#ffb4c0;border:1px solid #60303b;line-height:1.9}
        </style>
    </head>
    <body><div class="card"><div class="brand">🥇RealSpeed</div><div class="err"><?= h($message) ?></div></div></body>
    </html>
    <?php
    exit;
}

function isBrowserRequest(): bool
{
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (str_contains($ua, 'mozilla') || str_contains($ua, 'chrome') || str_contains($ua, 'safari')) {
        return str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    return str_contains($accept, 'text/html');
}

/* --------------------------------------------------------------------------
 * ROUTE: Cron refresh-all
 * ----------------------------------------------------------------------- */
if (isset($_GET['action']) && $_GET['action'] === 'refresh-all') {
    $provided = (string)($_GET['key'] ?? '');
    $expected = (string)(getenv(CRON_ENV_NAME) ?: '');

    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden";
        exit;
    }

    $rows = $db->query('SELECT * FROM subscriptions ORDER BY created_at ASC')->fetchAll();
    $done = 0;

    foreach ($rows as $row) {
        $before = (int)$row['updated_at'];
        $after = refreshRow($db, $row, false);
        if ((int)$after['updated_at'] > $before) {
            $done++;
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'checked' => count($rows),
        'refreshed' => $done,
        'time' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* --------------------------------------------------------------------------
 * ROUTE: Create subscription
 * ----------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['id'])) {
    $upstream = trim((string)($_POST['sub_url'] ?? ''));

    if (!validateSubscriptionUrl($upstream)) {
        showErrorPage('لینک سابسکریپشن معتبر نیست. فقط آدرس‌های HTTP/HTTPS قابل استفاده هستند.');
    }

    $id = randomId(10);
    $now = time();
    $name = 'RealSpeed-' . strtoupper($id);

    $stmt = $db->prepare('INSERT INTO subscriptions (id, upstream_url, name, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$id, $upstream, $name, $now]);

    $row = findById($db, $id);
    if ($row === null) {
        showErrorPage('ساخت لینک انجام نشد.', 500);
    }

    $row = refreshRow($db, $row, true);

    if ($row['content'] === '') {
        showErrorPage('سابسکریپشن اصلی قابل دریافت یا پردازش نبود. ' . ($row['last_error'] ?: '')); 
    }

    $generated = publicUrl($id);

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>RealSpeed — لینک ساخته شد</title>
        <style>
            :root{color-scheme:dark}
            *{box-sizing:border-box}
            body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#172442 0,#0a0f1b 48%,#080c14 100%);color:#eef3ff;font-family:Tahoma,Arial,sans-serif;display:flex;justify-content:center;align-items:center;padding:22px}
            .wrap{width:min(760px,100%)}
            .card{background:rgba(19,28,47,.94);border:1px solid #283b5e;border-radius:26px;padding:28px;box-shadow:0 25px 80px rgba(0,0,0,.45)}
            .brand{font-size:26px;font-weight:900;margin-bottom:8px}.brand b{color:#ffd166}
            .sub{color:#9daac2;font-size:14px;margin-bottom:24px;line-height:1.8}
            .linkbox{background:#0b1322;border:1px solid #304466;border-radius:16px;padding:14px;word-break:break-all;color:#79e6c3;line-height:1.9}
            .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
            button,a.btn{border:0;border-radius:13px;padding:12px 16px;font:inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
            .primary{background:#4ecca3;color:#07120f;font-weight:800}.ghost{background:#202d47;color:#edf5ff}
            .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px}
            .item{background:#101a2c;border:1px solid #253754;border-radius:16px;padding:14px}.label{font-size:12px;color:#8e9db6;margin-bottom:7px}.value{font-size:15px;font-weight:800}
            @media(max-width:650px){.grid{grid-template-columns:1fr 1fr}}
        </style>
    </head>
    <body>
    <div class="wrap"><div class="card">
        <div class="brand">🥇RealSpeed <b><?= h(strtoupper($id)) ?></b></div>
        <div class="sub">لینک سابسکریپشن ساخته شد و نسخهٔ دریافت‌شده از منبع اصلی همین حالا پردازش شد.</div>
        <div class="linkbox" id="generatedLink"><?= h($generated) ?></div>
        <div class="actions">
            <button class="primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('generatedLink').textContent.trim());this.textContent='کپی شد ✓'">کپی لینک</button>
            <a class="btn ghost" href="<?= h($generated) ?>">مشاهده اطلاعات</a>
        </div>
        <div class="grid">
            <div class="item"><div class="label">تعداد کانفیگ</div><div class="value"><?= h((string)count(splitSubscriptionLines(decodeSubscription($row['content'])['raw']))) ?></div></div>
            <div class="item"><div class="label">آخرین بروزرسانی</div><div class="value"><?= h(date('Y/m/d H:i', (int)$row['updated_at'])) ?></div></div>
            <div class="item"><div class="label">بروزرسانی دوره‌ای</div><div class="value">هر ۲۴ ساعت</div></div>
        </div>
    </div></div>
    </body></html>
    <?php
    exit;
}

/* --------------------------------------------------------------------------
 * ROUTE: Public generated subscription
 * ----------------------------------------------------------------------- */
$id = trim((string)($_GET['id'] ?? ''));

if ($id !== '') {
    $row = findById($db, $id);

    if ($row === null) {
        showErrorPage('این لینک RealSpeed پیدا نشد.', 404);
    }

    $row = refreshRow($db, $row, false);

    if ($row['content'] === '') {
        showErrorPage('فعلاً امکان دریافت سابسکریپشن اصلی وجود ندارد. ' . ($row['last_error'] ?: ''), 502);
    }

    if (isBrowserRequest()) {
        $used = null;
        $remaining = remainingBytes(
            $row['total'] !== null ? (int)$row['total'] : null,
            $row['upload'] !== null ? (int)$row['upload'] : null,
            $row['download'] !== null ? (int)$row['download'] : null,
        );
        $total = $row['total'] !== null ? (int)$row['total'] : null;
        if ($row['upload'] !== null && $row['download'] !== null) {
            $used = (int)$row['upload'] + (int)$row['download'];
        }

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>🥇RealSpeed <?= h(strtoupper($id)) ?></title>
            <style>
                :root{color-scheme:dark}
                *{box-sizing:border-box}
                body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#1a2947,#090e17 55%,#070a11);font-family:Tahoma,Arial,sans-serif;color:#eff4ff;padding:24px}
                .wrap{max-width:900px;margin:0 auto}
                .hero,.card{background:rgba(16,24,40,.94);border:1px solid #263956;border-radius:24px;box-shadow:0 22px 70px rgba(0,0,0,.35)}
                .hero{padding:26px;margin-bottom:16px}.hero h1{margin:0;font-size:25px}.hero p{margin:9px 0 0;color:#9eabc0;font-size:13px;line-height:1.9}
                .hero strong{color:#ffd166}
                .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:16px}
                .card{padding:18px}.label{font-size:12px;color:#8e9cb3;margin-bottom:8px}.value{font-size:18px;font-weight:800}
                .wide{grid-column:1/-1}.bar{height:11px;border-radius:99px;background:#202d43;overflow:hidden;margin-top:12px}.bar>span{display:block;height:100%;width:var(--p);background:#4ecca3;border-radius:99px}
                .small{font-size:12px;color:#8f9eb7;line-height:1.9}
                .btn{display:inline-flex;text-decoration:none;background:#4ecca3;color:#08130f;font-weight:900;padding:11px 15px;border-radius:12px;margin-top:14px}
                @media(max-width:650px){.grid{grid-template-columns:1fr}}
            </style>
        </head>
        <body>
        <div class="wrap">
            <section class="hero">
                <h1>🥇RealSpeed <strong><?= h(strtoupper($id)) ?></strong></h1>
                <p>این صفحه وضعیت سابسکریپشن متصل به منبع اصلی را نشان می‌دهد. نسخهٔ عمومی لینک هر حداکثر ۲۴ ساعت یک‌بار از منبع اصلی تازه می‌شود.</p>
                <a class="btn" href="<?= h(publicUrl($id)) ?>">لینک سابسکریپشن</a>
            </section>

            <div class="grid">
                <section class="card">
                    <div class="label">حجم کل</div>
                    <div class="value"><?= h(formatBytes($total)) ?></div>
                </section>
                <section class="card">
                    <div class="label">حجم مصرف‌شده</div>
                    <div class="value"><?= h(formatBytes($used)) ?></div>
                </section>
                <section class="card">
                    <div class="label">حجم باقی‌مانده</div>
                    <div class="value"><?= h(formatBytes($remaining)) ?></div>
                </section>
                <section class="card">
                    <div class="label">انقضا</div>
                    <div class="value"><?= h(formatExpire($row['expire'] !== null ? (int)$row['expire'] : null)) ?></div>
                </section>
                <section class="card wide">
                    <div class="label">درصد مصرف</div>
                    <?php
                    $percent = 0;
                    if ($total !== null && $total > 0 && $used !== null) {
                        $percent = max(0, min(100, ($used / $total) * 100));
                    }
                    ?>
                    <div class="value"><?= number_format($percent, 1) ?>%</div>
                    <div class="bar" style="--p:<?= h(number_format($percent, 2, '.', '')) ?>%"><span></span></div>
                </section>
                <section class="card">
                    <div class="label">آخرین بروزرسانی</div>
                    <div class="value"><?= h(date('Y/m/d H:i', (int)$row['updated_at'])) ?></div>
                </section>
                <section class="card">
                    <div class="label">بروزرسانی بعدی</div>
                    <div class="value"><?= h(date('Y/m/d H:i', (int)$row['updated_at'] + REFRESH_INTERVAL)) ?></div>
                </section>
            </div>

            <section class="card">
                <div class="label">وضعیت</div>
                <div class="value">✅ فعال</div>
                <p class="small">تعداد کانفیگ‌های پردازش‌شده: <?= h((string)count(splitSubscriptionLines(decodeSubscription($row['content'])['raw']))) ?></p>
            </section>
        </div>
        </body></html>
        <?php
        exit;
    }

    // Subscription client: return the rewritten content directly.
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Subscription-Userinfo: ' . $row['response_userinfo']);
    echo $row['content'];
    exit;
}

/* --------------------------------------------------------------------------
 * HOME: Create page
 * ----------------------------------------------------------------------- */
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🥇RealSpeed Link Maker</title>
    <style>
        :root{color-scheme:dark}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#192946,#080d15 60%);display:flex;align-items:center;justify-content:center;padding:22px;font-family:Tahoma,Arial,sans-serif;color:#eef3ff}
        .box{width:min(560px,100%);background:#121b2c;border:1px solid #2a3a59;border-radius:25px;padding:28px;box-shadow:0 25px 80px rgba(0,0,0,.4)}
        h1{text-align:center;font-size:26px;margin:0 0 8px}.gold{color:#ffd166}.sub{text-align:center;color:#94a3bb;font-size:13px;line-height:1.9;margin-bottom:22px}
        label{display:block;font-size:12px;color:#a9b4c7;margin-bottom:8px}
        input{width:100%;border:1px solid #314362;background:#0b1321;color:#fff;padding:14px;border-radius:14px;outline:none;font:inherit;direction:ltr}
        input:focus{border-color:#4ecca3;box-shadow:0 0 0 3px rgba(78,204,163,.1)}
        button{width:100%;margin-top:12px;border:0;padding:14px;border-radius:14px;background:#4ecca3;color:#08130f;font-weight:900;font:inherit;cursor:pointer}
        .note{margin-top:15px;background:#0e1727;border:1px solid #243653;border-radius:14px;padding:13px;color:#8e9db6;font-size:12px;line-height:1.9}
    </style>
</head>
<body>
<div class="box">
    <h1>🥇<span class="gold">RealSpeed</span> Link Maker</h1>
    <div class="sub">لینک سابسکریپشن اصلی را وارد کنید؛ RealSpeed نسخهٔ پردازش‌شده و به‌روزشوندهٔ آن را می‌سازد.</div>
    <form method="post">
        <label for="sub_url">لینک سابسکریپشن</label>
        <input id="sub_url" type="url" name="sub_url" required placeholder="https://example.com/sub/...">
        <button type="submit">ساخت لینک RealSpeed</button>
    </form>
    <div class="note">نام کانفیگ‌ها به‌صورت خودکار به شکل <b>🥇RealSpeed XXXXXXXX</b> تغییر می‌کند و لینک عمومی به‌صورت دوره‌ای از منبع اصلی تازه می‌شود.</div>
</div>
</body>
</html>
