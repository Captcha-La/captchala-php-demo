<?php
/**
 * CaptchaLa server-token issuance example
 * MIT License — copy / adapt / ship
 *
 * Usage from your frontend:
 *   GET /issue-token.php?action=register
 *   → { "ok": true, "token": "...", "expires_in": 300 }
 *
 * Then pass the returned token into Captchala.init({ serverToken: ... }).
 *
 * Why bother:
 *   - The token is bound to the visitor's IP at issuance time.
 *   - It's single-use after consumption (max_uses limit).
 *   - It expires in 5 minutes by default.
 *   - This means a token leaked from one user can't be reused by another.
 *
 * Use this for high-value flows: signup, payment, password reset.
 * Skip it for low-value flows where the per-request cost outweighs the protection.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---- Config ---------------------------------------------------------------
// In production:
//   1. Generate your own at https://dash.captcha.la
//   2. Keep app_secret out of webroot — load from env vars or a config file
//      stored outside the document root (we use /etc/captchala/demo-secrets.php here).
//   3. Never commit secrets to git or expose them as part of view-source mirrors.
$secrets = @include '/tmp/captchala-demo-secrets.php';
if (!is_array($secrets)) {
    $secrets = ['APP_KEY' => getenv('CAPTCHALA_APP_KEY'), 'APP_SECRET' => getenv('CAPTCHALA_APP_SECRET')];
}
$APP_KEY    = (string)($secrets['APP_KEY']    ?? '');
$APP_SECRET = (string)($secrets['APP_SECRET'] ?? '');
$ISSUE_URL  = 'https://apiv1.captcha.la/v1/server/challenge/issue';

if ($APP_KEY === '' || $APP_SECRET === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_not_configured']);
    exit;
}

// ---- Read request ---------------------------------------------------------
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'default';

// Whitelist actions you actually use; rejects arbitrary strings from clients.
$ALLOWED_ACTIONS = ['login', 'register', 'payment', 'password_reset', 'default'];
if (!in_array($action, $ALLOWED_ACTIONS, true)) {
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

function clientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ---- Call CaptchaLa issue endpoint ---------------------------------------
$payload = json_encode([
    'action'      => $action,
    'binding_ip'  => clientIp(),
    'ttl'         => 300,   // seconds
    'max_uses'    => 10,    // SDK retries up to ~3 times on transient errors
], JSON_UNESCAPED_SLASHES);

$ch = curl_init($ISSUE_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-App-Key: ' . $APP_KEY,
        'X-App-Secret: ' . $APP_SECRET,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'upstream_unavailable', 'http' => $http]);
    exit;
}

$data = json_decode((string)$resp, true);
if (!is_array($data) || !isset($data['code']) || $data['code'] !== 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid_response']);
    exit;
}

$inner = $data['data'] ?? [];
echo json_encode([
    'ok'         => true,
    // API returns { data: { server_token, expires_in, issued_at } }
    'token'      => $inner['server_token'] ?? $inner['token'] ?? null,
    'expires_in' => $inner['expires_in']   ?? 300,
    'app_key'    => $APP_KEY,  // safe to expose; the secret is never sent to browser
]);
