<?php
/**
 * CaptchaLa server-side verification example
 * MIT License — copy / adapt / ship
 *
 * Usage from your frontend:
 *   POST /verify.php
 *   Content-Type: application/json
 *   { "token": "<token from onSuccess>", "action": "login" }
 *
 * Returns:
 *   { "ok": true, "risk_score": 12 }   (allow the user)
 *   { "ok": false, "error": "..." }    (block / re-challenge)
 *
 * The CaptchaLa API key + secret are loaded from env vars.
 * Set them on your server:
 *   export CAPTCHALA_APP_KEY="app_xxx"
 *   export CAPTCHALA_APP_SECRET="sec_xxx"
 *
 * Or replace the getenv() calls below with your config loader.
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
$VERIFY_URL = 'https://apiv1.captcha.la/v1/validate';

if ($APP_KEY === '' || $APP_SECRET === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_not_configured']);
    exit;
}

// ---- Read request ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$body  = json_decode((string)file_get_contents('php://input'), true);
$token = isset($body['token'])  ? trim((string)$body['token'])  : '';
$action = isset($body['action']) ? trim((string)$body['action']) : 'default';

if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_token']);
    exit;
}

// Real client IP — handles common proxy headers.
// In production, only trust X-Forwarded-For if you control the upstream proxy.
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

// ---- Call CaptchaLa verify endpoint --------------------------------------
// Real validate API expects `pass_token` (not `token`).
// Frontend SDK's onSuccess(res) returns res.token — that IS the pass_token.
$payload = json_encode([
    'pass_token' => $token,
    'client_ip'  => clientIp(),
], JSON_UNESCAPED_SLASHES);

$ch = curl_init($VERIFY_URL);
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
    // The verify endpoint was unreachable or returned non-2xx.
    // Decide: fail closed for high-value flows, fail open for low-value.
    // Default here is fail closed.
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
$valid = !empty($inner['valid']);
$risk  = isset($inner['risk_score']) ? (int)$inner['risk_score'] : null;

if (!$valid) {
    echo json_encode([
        'ok'    => false,
        'error' => $inner['error'] ?? 'verification_failed',
    ]);
    exit;
}

// Optional: enforce action binding.
// If you initialized Captchala with action="login" but token came back action="register",
// reject — it's a token from a different flow.
$tokenAction = (string)($inner['action'] ?? '');
if ($tokenAction !== '' && $tokenAction !== $action) {
    echo json_encode(['ok' => false, 'error' => 'action_mismatch']);
    exit;
}

// All good — token is valid, single-use, action-bound.
echo json_encode([
    'ok'         => true,
    'risk_score' => $risk,
    'offline'    => !empty($inner['offline']),
]);
