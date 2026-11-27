<?php
/**
 * A stand-in for the parts of api.intents.fi and dashboard.intents.fi that Connect touches, for
 * connect-smoke.sh. Run with PHP's built-in server:
 *
 *   php -S 127.0.0.1:8790 connect-mock.php
 *
 * The real dashboard signs the merchant in and asks for consent; here GET /connect approves at
 * once and sends the browser back to return_url with a code. A code from `mode=live` yields live
 * keys. Every request is appended to $CONNECT_MOCK_LOG (one JSON line each, with the
 * X-Intents-Affiliate header as `affiliate`) for the smokes to check. affiliate-smoke.sh also uses
 * its `GET /v0/ref/resolve` (`oak-studio` is Oak Studio, `down-for-test` answers 503, anything else
 * is unknown) and `POST /v0/invoices`.
 * Never point a real store at this.
 */
declare(strict_types=1);

$log = getenv('CONNECT_MOCK_LOG') ?: sys_get_temp_dir() . '/intentsfi-connect-mock.log';
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$affiliate = $_SERVER['HTTP_X_INTENTS_AFFILIATE'] ?? null;
file_put_contents($log, json_encode(['method' => $method, 'path' => $path, 'auth' => $auth, 'affiliate' => $affiliate, 'query' => $_GET, 'body' => $body]) . "\n", FILE_APPEND);

$json = static function (int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};
$key = static fn (string $kind, string $mode, string $seed): string => $kind . '_' . $mode . '_' . substr(hash('sha256', $seed), 0, 24);

if ($method === 'GET' && $path === '/connect') {
    $mode = ($_GET['mode'] ?? '') === 'live' ? 'live' : 'test';
    $code = $mode . 'code' . bin2hex(random_bytes(12));
    // Remember what the code is bound to, as the API does.
    file_put_contents(sys_get_temp_dir() . '/intentsfi-connect-mock-' . $code, json_encode($_GET));
    $back = $_GET['return_url'] . (str_contains($_GET['return_url'], '?') ? '&' : '?')
        . http_build_query(['code' => $code, 'state' => $_GET['state']]);
    header('Location: ' . $back, true, 302);
    return;
}
if ($method === 'POST' && $path === '/v0/connect/token') {
    $file = sys_get_temp_dir() . '/intentsfi-connect-mock-' . basename((string) ($body['code'] ?? ''));
    $req = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    @unlink($file);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) ($body['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
    if (!is_array($req) || $req['code_challenge'] !== $challenge || $req['site_url'] !== ($body['site_url'] ?? null)) {
        $json(400, ['error' => ['code' => 'CONNECT_CODE_INVALID', 'message' => 'unknown code, wrong verifier or site']]);
        return;
    }
    $mode = $req['mode'] === 'live' ? 'live' : 'test';
    $json(200, [
        'secret_key' => $key('ik', $mode, $body['code']),
        'publishable_key' => $key('pk', $mode, $body['code']),
        'mode' => $mode,
        'account' => ['id' => 'acc_mock', 'name' => 'Mock Goods'],
        'merchant' => ['id' => 'mer_mock', 'name' => 'Mock Goods', 'status' => 'active'],
        'connection' => ['id' => 'con_' . $mode, 'platform' => $req['platform'], 'site' => (string) parse_url($req['site_url'], PHP_URL_HOST), 'mode' => $mode],
    ]);
    return;
}
if ($method === 'POST' && $path === '/v0/webhooks') {
    $json(201, ['id' => 'whk_mock', 'url' => $body['url'] ?? '', 'secret' => 'whsec_' . substr(hash('sha256', $auth), 0, 32)]);
    return;
}
if ($method === 'POST' && $path === '/v0/connect/revoke') {
    $json(200, ['revoked' => true, 'connectionId' => 'con_mock']);
    return;
}
if ($method === 'GET' && $path === '/v0/merchant') {
    $merchant = ['id' => 'mer_mock', 'name' => 'Mock Goods', 'status' => 'active', 'mode' => str_contains($auth, 'ik_live_') ? 'live' : 'test'];
    if (getenv('CONNECT_MOCK_REFERRED_BY')) {
        $merchant['affiliate'] = ['displayName' => getenv('CONNECT_MOCK_REFERRED_BY')];
    }
    $json(200, $merchant);
    return;
}
if ($method === 'GET' && $path === '/v0/ref/resolve') {
    $code = (string) ($_GET['code'] ?? '');
    if ($code === 'down-for-test') {
        $json(503, ['error' => ['code' => 'UNAVAILABLE', 'message' => 'try later']]);
        return;
    }
    $json(200, $code === 'oak-studio' ? ['valid' => true, 'displayName' => 'Oak Studio'] : ['valid' => false, 'displayName' => null]);
    return;
}
if ($method === 'POST' && $path === '/v0/invoices') {
    $id = 'inv_mock_' . substr(hash('sha256', (string) ($body['reference'] ?? '')), 0, 16);
    $json(201, [
        'id' => $id,
        'url' => 'https://pay.intents.fi/i/' . $id,
        'status' => 'pending',
        'mode' => 'test',
        'amount' => $body['amount'] ?? '',
        'currency' => $body['currency'] ?? '',
        'reference' => $body['reference'] ?? '',
        'metadata' => $body['metadata'] ?? [],
    ]);
    return;
}
$json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => $method . ' ' . $path]]);
