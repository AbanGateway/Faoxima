<?php

if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', __DIR__);
}
@chdir(__DIR__);

include('config.php');
require_once 'request.php';
date_default_timezone_set('Asia/Tehran');

function pasarguardFormatUptime($seconds)
{
    $seconds = (int) $seconds;
    if ($seconds < 60) {
        return "{$seconds} ثانیه";
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        $parts = ["{$days} روز"];
        if ($hours > 0) {
            $parts[] = "{$hours} ساعت";
        }
        return implode(' و ', $parts);
    }
    if ($hours > 0) {
        $parts = ["{$hours} ساعت"];
        if ($minutes > 0) {
            $parts[] = "{$minutes} دقیقه";
        }
        return implode(' و ', $parts);
    }
    return "{$minutes} دقیقه";
}

function findPasarGuardPanelByName($location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel) || empty($panel)) {
        return null;
    }
    return $panel;
}

function pasarguardApiKey(array $panel)
{
    return $panel['api_key'] ?? null;
}

function pasarguardAuthMode(array $panel)
{
    $mode = $panel['pasarguard_auth_mode'] ?? null;
    if ($mode === 'password') {
        return 'password';
    }
    if ($mode === 'api_key') {
        return 'api_key';
    }
    return !empty($panel['api_key']) ? 'api_key' : 'password';
}

function pasarguardTokenCacheKey($baseUrl, $username)
{
    return 'pasarguard_token:' . md5($baseUrl . '|' . $username);
}

function pasarguardTokenTtl($token, $ttlSeconds = 3000)
{
    $ttlSeconds = (int) $ttlSeconds;
    $exp = function_exists('rx_panel_token_jwt_exp') ? rx_panel_token_jwt_exp($token) : null;
    if ($exp !== null) {
        $ttlSeconds = min($ttlSeconds, $exp - time() - 60);
    }
    return $ttlSeconds;
}

function pasarguardCachedToken($baseUrl, $username)
{
    $key = pasarguardTokenCacheKey($baseUrl, $username);
    if (function_exists('rx_redis_get')) {
        $token = rx_redis_get($key);
        if ($token !== null && $token !== false && $token !== '') {
            return (string) $token;
        }
    }
    $entry = $GLOBALS['__faoxima_panel_token_cache']['pasarguard'][$key] ?? null;
    if (is_array($entry) && isset($entry['token'], $entry['expires_at']) && $entry['expires_at'] > time()) {
        return (string) $entry['token'];
    }
    unset($GLOBALS['__faoxima_panel_token_cache']['pasarguard'][$key]);
    return null;
}

function pasarguardStoreToken($baseUrl, $username, $token, $ttlSeconds = 3000)
{
    $token = (string) $token;
    if ($token === '') {
        return;
    }
    $ttlSeconds = pasarguardTokenTtl($token, $ttlSeconds);
    if ($ttlSeconds <= 0) {
        return;
    }
    $key = pasarguardTokenCacheKey($baseUrl, $username);
    if (function_exists('rx_redis_set')) {
        rx_redis_set($key, $token, $ttlSeconds);
    }
    $GLOBALS['__faoxima_panel_token_cache']['pasarguard'][$key] = [
        'token' => $token,
        'expires_at' => time() + $ttlSeconds,
    ];
}

function pasarguardClearToken($baseUrl, $username, $onlyToken = null)
{
    $key = pasarguardTokenCacheKey($baseUrl, $username);
    if ($onlyToken === null) {
        if (function_exists('rx_redis_del')) {
            rx_redis_del($key);
        }
        unset($GLOBALS['__faoxima_panel_token_cache']['pasarguard'][$key]);
        return;
    }
    if (function_exists('rx_redis_release_lock')) {
        rx_redis_release_lock($key, (string) $onlyToken);
    }
    $entry = $GLOBALS['__faoxima_panel_token_cache']['pasarguard'][$key] ?? null;
    if (is_array($entry) && ($entry['token'] ?? null) === (string) $onlyToken) {
        unset($GLOBALS['__faoxima_panel_token_cache']['pasarguard'][$key]);
    }
}

function pasarguardPanelPdo()
{
    $pdo = function_exists('getDatabaseConnection') ? getDatabaseConnection() : ($GLOBALS['pdo'] ?? null);
    return $pdo instanceof PDO ? $pdo : null;
}

function pasarguardReadPanelSession(array $panel)
{
    $name = (string) ($panel['name_panel'] ?? '');
    $pdo = $name !== '' ? pasarguardPanelPdo() : null;
    if ($pdo === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT datelogin FROM marzban_panel WHERE name_panel = ? LIMIT 1");
        $stmt->execute([$name]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('pasarguardReadPanelSession: ' . $e->getMessage());
        return null;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    return ['raw' => $raw, 'data' => $decoded];
}

function pasarguardStoredPanelToken(array $panel)
{
    $session = pasarguardReadPanelSession($panel);
    if ($session === null) {
        return null;
    }
    $data = $session['data'];
    $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    $username = (string) ($panel['username_panel'] ?? '');
    $token = (string) ($data['pasarguard_token'] ?? '');
    if ($token === '' || ($data['pasarguard_key'] ?? null) !== pasarguardTokenCacheKey($baseUrl, $username)) {
        return null;
    }
    $remaining = 3000 - (time() - (int) ($data['pasarguard_time'] ?? 0));
    $remaining = min($remaining, pasarguardTokenTtl($token, 3000));
    if ($remaining <= 0) {
        return null;
    }
    return ['token' => $token, 'ttl' => $remaining];
}

function pasarguardSavePanelToken(array $panel, $token)
{
    $name = (string) ($panel['name_panel'] ?? '');
    $pdo = $name !== '' ? pasarguardPanelPdo() : null;
    if ($pdo === null || (string) $token === '') {
        return;
    }
    $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    $username = (string) ($panel['username_panel'] ?? '');
    $payload = json_encode([
        'pasarguard_key' => pasarguardTokenCacheKey($baseUrl, $username),
        'pasarguard_token' => (string) $token,
        'pasarguard_time' => time(),
    ]);
    try {
        $stmt = $pdo->prepare("UPDATE marzban_panel SET datelogin = ? WHERE name_panel = ?");
        $stmt->execute([$payload, $name]);
    } catch (Throwable $e) {
        error_log('pasarguardSavePanelToken: ' . $e->getMessage());
    }
}

function pasarguardForgetPanelToken(array $panel, $token)
{
    $session = pasarguardReadPanelSession($panel);
    if ($session === null || ($session['data']['pasarguard_token'] ?? null) !== (string) $token) {
        return;
    }
    try {
        $stmt = pasarguardPanelPdo()->prepare("UPDATE marzban_panel SET datelogin = NULL WHERE name_panel = ? AND datelogin = ?");
        $stmt->execute([(string) $panel['name_panel'], $session['raw']]);
    } catch (Throwable $e) {
        error_log('pasarguardForgetPanelToken: ' . $e->getMessage());
    }
}

function pasarguardLogin($baseUrl, $username, $password)
{
    $normalizedUrl = rtrim((string) $baseUrl, '/');
    $req = new CurlRequest($normalizedUrl . '/api/admin/token');
    $req->setHeaders(['accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
    $response = $req->post([
        'username' => (string) $username,
        'password' => (string) $password,
        'grant_type' => 'password',
    ]);
    if (!empty($response['error'])) {
        return ['status' => false, 'msg' => $response['error']];
    }
    $httpStatus = $response['status'] ?? null;
    if ($httpStatus === null || $httpStatus >= 400) {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $message = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : "HTTP {$httpStatus}";
        return ['status' => false, 'msg' => is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message];
    }
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        return ['status' => false, 'msg' => 'Login response did not include an access token.'];
    }
    return ['status' => true, 'access_token' => (string) $decoded['access_token']];
}

function pasarguardReusableToken($panel, $rejectedToken = null)
{
    $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    $username = (string) ($panel['username_panel'] ?? '');
    $cached = pasarguardCachedToken($baseUrl, $username);
    if ($cached !== null && $cached !== $rejectedToken) {
        return $cached;
    }
    $stored = pasarguardStoredPanelToken($panel);
    if ($stored !== null && $stored['token'] !== $rejectedToken) {
        pasarguardStoreToken($baseUrl, $username, $stored['token'], $stored['ttl']);
        return $stored['token'];
    }
    return null;
}

function pasarguardGetToken($panel, $forceRefresh = false, $rejectedToken = null)
{
    $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    $username = (string) ($panel['username_panel'] ?? '');
    $password = (string) ($panel['password_panel'] ?? '');
    $mayReuse = !$forceRefresh || $rejectedToken !== null;
    if ($mayReuse) {
        $reusable = pasarguardReusableToken($panel, $rejectedToken);
        if ($reusable !== null) {
            return ['status' => true, 'access_token' => $reusable];
        }
    }
    $canLock = function_exists('rx_panel_token_lock_acquire');
    $lock = $canLock ? rx_panel_token_lock_acquire('pasarguard', $baseUrl . '|' . $username) : null;
    try {
        if ($mayReuse || ($canLock && $lock === null)) {
            $reusable = pasarguardReusableToken($panel, $rejectedToken);
            if ($reusable !== null) {
                return ['status' => true, 'access_token' => $reusable];
            }
        }
        if ($canLock && $lock === null) {
            return ['status' => false, 'msg' => 'PasarGuard authentication is busy, please try again shortly.'];
        }
        $loginResult = pasarguardLogin($baseUrl, $username, $password);
        if (empty($loginResult['status'])) {
            return $loginResult;
        }
        pasarguardStoreToken($baseUrl, $username, $loginResult['access_token']);
        pasarguardSavePanelToken($panel, $loginResult['access_token']);
        return $loginResult;
    } finally {
        if ($lock !== null && function_exists('rx_panel_token_lock_release')) {
            rx_panel_token_lock_release($lock);
        }
    }
}

function pasarguardRequest($panel, $method, $path, $data = null)
{
    $authMode = pasarguardAuthMode($panel);
    $url = rtrim($panel['url_panel'], '/') . $path;
    $headers = ['accept: application/json'];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $doRequest = function ($bearerToken, $apiKey) use ($url, $headers, $method, $data) {
        $req = new CurlRequest($url);
        $req->setHeaders($headers);
        if ($bearerToken !== null) {
            $req->setBearerToken($bearerToken);
        }
        if ($apiKey !== null) {
            $req->api_key($apiKey);
        }
        switch (strtoupper($method)) {
            case 'POST':
                return $req->post($data !== null ? json_encode($data) : []);
            case 'PUT':
                return $req->put($data !== null ? json_encode($data) : null);
            case 'DELETE':
                return $req->delete($data !== null ? json_encode($data) : null);
            default:
                return $req->get();
        }
    };

    if ($authMode === 'password') {
        $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');
        $username = (string) ($panel['username_panel'] ?? '');
        $tokenResult = pasarguardGetToken($panel);
        if (empty($tokenResult['status'])) {
            return ['status' => false, 'error' => $tokenResult['msg'] ?? 'PasarGuard login failed'];
        }
        $response = $doRequest($tokenResult['access_token'], null);
        if (($response['status'] ?? 0) == 401) {
            $rejectedToken = (string) $tokenResult['access_token'];
            pasarguardClearToken($baseUrl, $username, $rejectedToken);
            pasarguardForgetPanelToken($panel, $rejectedToken);
            $retryToken = pasarguardGetToken($panel, true, $rejectedToken);
            if (empty($retryToken['status'])) {
                return ['status' => false, 'error' => $retryToken['msg'] ?? 'PasarGuard login failed'];
            }
            $response = $doRequest($retryToken['access_token'], null);
        }
        return $response;
    }

    $apiKey = pasarguardApiKey($panel);
    return $doRequest(null, $apiKey);
}

function pasarguardGetUser($username_account, $location)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'GET', '/api/user/' . $username_account);
}

function pasarguardSubscriptionClientTypes()
{
    return ['links', 'xray', 'sing_box', 'clash_meta'];
}

function pasarguardNormalizeClientType($client_type)
{
    $clientType = strtolower(trim((string) $client_type));
    return in_array($clientType, pasarguardSubscriptionClientTypes(), true) ? $clientType : 'links';
}

function pasarguardIsJsonClientConfig($config)
{
    if (!is_array($config) || $config === [] || array_keys($config) === range(0, count($config) - 1)) {
        return false;
    }
    return isset($config['outbounds']) && is_array($config['outbounds']) && $config['outbounds'] !== [];
}

function pasarguardParseClientDocuments($clientType, $body)
{
    $body = trim((string) $body);
    if ($body === '') {
        return [];
    }
    if ($clientType === 'xray' || $clientType === 'sing_box') {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }
        $isList = array_keys($decoded) === range(0, count($decoded) - 1);
        $items = $isList ? $decoded : [$decoded];
        if ($clientType === 'sing_box' && count($items) !== 1) {
            return [];
        }
        $documents = [];
        foreach ($items as $item) {
            if (!pasarguardIsJsonClientConfig($item)) {
                return [];
            }
            $encoded = json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return [];
            }
            $documents[] = $encoded;
        }
        return $documents;
    }
    if ($clientType === 'clash_meta') {
        if ($body[0] === '{' || $body[0] === '[' || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $body)) {
            return [];
        }
        if (!preg_match('/^proxies\s*:/m', $body)) {
            return [];
        }
        return [$body];
    }
    return [];
}

function pasarguardFetchSubscription($url, $accept)
{
    $cfgTimeout = isset($GLOBALS['setting']['panel_curl_timeout']) ? (int) $GLOBALS['setting']['panel_curl_timeout'] : 0;
    $timeout = $cfgTimeout > 0 ? $cfgTimeout : 20;
    $connectTimeout = max(6, (int) round($timeout * 0.4));
    $verifyTls = defined('BOT_CURL_VERIFY_TLS') && BOT_CURL_VERIFY_TLS === true;
    $body = false;
    $httpCode = 0;
    $error = '';
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: ' . $accept]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
        if (function_exists('faoxima_apply_curl_proxy')) {
            faoxima_apply_curl_proxy($ch, 'panel');
        }
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : '';
        curl_close($ch);
        if ($errno === CURLE_OPERATION_TIMEDOUT && $attempt < 2) {
            continue;
        }
        break;
    }
    if ($error !== '' || $httpCode === 0 || $httpCode >= 500) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $message = sprintf('PasarGuard subscription fetch to host %s failed (HTTP %d%s)', $host, $httpCode, $error !== '' ? ', curl error' : '');
        if (function_exists('faoxima_dedup_error_log')) {
            faoxima_dedup_error_log('pgsub|' . $host . '|' . $httpCode . '|' . ($error !== '' ? 1 : 0), $message);
        } else {
            error_log($message);
        }
    }
    $result = ['status' => $httpCode, 'body' => is_string($body) ? $body : ''];
    if ($error !== '') {
        $result['error'] = 'curl_error';
    }
    return $result;
}

function pasarguardGetSubscriptionLinks($username_account, $location, $client_type = 'links')
{
    $requestedType = pasarguardNormalizeClientType($client_type);
    $userResponse = pasarguardGetUser($username_account, $location);
    if (!empty($userResponse['error'])) {
        return $userResponse;
    }
    if (empty($userResponse['body'])) {
        return ["error" => "Empty response from panel when resolving subscription url."];
    }
    $userData = json_decode($userResponse['body'], true);
    if (!is_array($userData) || empty($userData['subscription_url'])) {
        return ["error" => "Unable to resolve subscription url."];
    }
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $subscriptionPath = (string) $userData['subscription_url'];
    $subUrl = preg_match('#^https?://#i', $subscriptionPath)
        ? $subscriptionPath
        : rtrim($panel['url_panel'], '/') . '/' . ltrim($subscriptionPath, '/');
    $subUrl = rtrim($subUrl, '/');
    $fallbackReason = null;
    if ($requestedType !== 'links') {
        $response = pasarguardFetchSubscription(
            $subUrl . '/' . $requestedType,
            $requestedType === 'clash_meta' ? 'text/yaml, text/plain;q=0.9, */*;q=0.1' : 'application/json, text/plain;q=0.9, */*;q=0.1'
        );
        if (!empty($response['error']) || empty($response['status']) || $response['status'] >= 400) {
            $fallbackReason = 'http_' . (int) ($response['status'] ?? 0);
        } else {
            $documents = pasarguardParseClientDocuments($requestedType, $response['body'] ?? '');
            if (!empty($documents)) {
                return [
                    "status" => true,
                    "links" => $documents,
                    "client_type" => $requestedType,
                    "requested_client_type" => $requestedType,
                ];
            }
            $fallbackReason = 'invalid_format';
        }
    }
    $response = pasarguardFetchSubscription($subUrl . '/links', 'text/plain');
    if (!empty($response['error']) || empty($response['status']) || $response['status'] >= 400) {
        return ["error" => "Unable to fetch subscription links."];
    }
    $links = array_values(array_filter(array_map('trim', explode("\n", (string) $response['body'])), function ($line) {
        return $line !== '';
    }));
    $result = ["status" => true, "links" => $links, "client_type" => 'links', "requested_client_type" => $requestedType];
    if ($fallbackReason !== null) {
        $result['fallback_reason'] = $fallbackReason;
    }
    return $result;
}

function pasarguardGetUsersByStatus($location, $status)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $response = pasarguardRequest($panel, 'GET', '/api/users?status=' . rawurlencode($status) . '&limit=200');
    if (!empty($response['error'])) {
        return $response;
    }
    if (empty($response['status']) || $response['status'] >= 400) {
        return ["error" => "Unable to fetch users by status."];
    }
    $data = json_decode((string) $response['body'], true);
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    return ["status" => true, "users" => $users];
}

function pasarguardGetNodes($location)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'GET', '/api/nodes');
}

function pasarguardGetSystemStats($location)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'GET', '/api/system');
}

function pasarguardGetInbounds($location)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'GET', '/api/inbounds');
}

function pasarguardGetUserHwids($username_account, $location)
{
    $userResponse = pasarguardGetUser($username_account, $location);
    if (!empty($userResponse['error'])) {
        return $userResponse;
    }
    if (empty($userResponse['body'])) {
        return ["error" => "Empty response from panel when resolving user id."];
    }
    $userData = json_decode($userResponse['body'], true);
    if (!is_array($userData) || !isset($userData['id'])) {
        return ["error" => "Unable to resolve numeric user id for HWID lookup."];
    }
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'GET', '/api/user/' . $userData['id'] . '/hwids');
}

function pasarguardAddUser($location, $data_limit, $username_ac, $timestamp, $note = '', $data_limit_reset = 'no_reset', $name_product = false, $hwid_limit = null)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $inbounds = null;
    $product = null;
    if (!empty($panel['inbounds']) && $panel['inbounds'] != "null") {
        if ($name_product != false && $name_product != "usertest") {
            $product = select("product", "*", "name_product", $name_product, "select");
            if ($product == false || $product['inbounds'] == false) {
                $inbounds = json_decode($panel['inbounds'], true);
            } else {
                $inbounds = json_decode($product['inbounds'], true);
                $panel['proxies'] = $product['proxies'];
            }
        } else {
            $inbounds = json_decode($panel['inbounds'], true);
        }
    }
    if ($hwid_limit === null && $name_product != false && $name_product != "usertest") {
        if ($product === null) {
            $product = select("product", "*", "name_product", $name_product, "select");
        }
        if (is_array($product) && isset($product['hwid_limit'])) {
            $hwid_limit = (int) $product['hwid_limit'];
        }
    }
    $data = array(
        "proxy_settings" => json_decode($panel['proxies']),
        "data_limit" => $data_limit,
        "username" => $username_ac,
        "note" => $note,
        "data_limit_reset_strategy" => $data_limit_reset
    );
    if (isset($inbounds)) {
        // Issue #22 (feature request): merge the bot-configured groups with the
        // groups the user currently has on the panel — add-only, never remove —
        // so groups manually granted on the panel survive renewals.
        $groupIds = array_values(array_map('intval', (array) $inbounds));
        $userResponse = pasarguardGetUser($username_ac, $location);
        $httpStatus = is_array($userResponse) ? (int) ($userResponse['status'] ?? 0) : 0;
        if ($httpStatus === 200) {
            $panelUser = json_decode($userResponse['body'] ?? '', true);
            $currentGroups = is_array($panelUser) ? ($panelUser['group_ids'] ?? null) : null;
            if (is_array($currentGroups) && !empty($currentGroups)) {
                $groupIds = array_values(array_unique(array_merge(
                    array_map('intval', $currentGroups),
                    $groupIds
                )));
            }
        } elseif ($httpStatus !== 404) {
            // Live read failed for a reason other than "user not found" (e.g.
            // network or 5xx): omit group_ids entirely instead of sending the
            // default list, which could move an existing user between groups.
            return pasarguardRequest($panel, 'POST', '/api/user', $data);
        }
        $data['group_ids'] = $groupIds;
    }
    if (!empty($hwid_limit)) {
        $data['hwid_limit'] = (int) $hwid_limit;
    }
    if ($name_product == "usertest") {
        if ($panel['on_hold_test'] == "0") {
            $data["expire"] = $timestamp == 0 ? 0 : $timestamp;
        } else {
            if ($timestamp == 0) {
                $data["expire"] = 0;
            } else {
                $data["expire"] = 0;
                $data["status"] = "on_hold";
                $data["on_hold_expire_duration"] = $timestamp - time();
            }
        }
    } else {
        if ($panel['conecton'] == "offconecton") {
            $data["expire"] = $timestamp == 0 ? 0 : $timestamp;
        } else {
            if ($timestamp == 0) {
                $data["expire"] = 0;
            } else {
                $data["expire"] = 0;
                $data["status"] = "on_hold";
                $data["on_hold_expire_duration"] = $timestamp - time();
            }
        }
    }
    return pasarguardRequest($panel, 'POST', '/api/user', $data);
}

function pasarguardModifyUser($location, $username, array $data)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'PUT', '/api/user/' . $username, $data);
}

function pasarguardResetUserDataUsage($username_account, $location)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'POST', '/api/user/' . $username_account . '/reset', []);
}

function pasarguardRevokeSub($username_account, $location)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'POST', '/api/user/' . $username_account . '/revoke_sub', []);
}

function pasarguardTestConnection($baseUrl, $apiKey)
{
    $apiKey = trim((string) $apiKey);
    $normalizedUrl = rtrim((string) $baseUrl, '/');
    if ($apiKey === '') {
        return [
            'status' => false,
            'msg' => 'PasarGuard API key is missing'
        ];
    }
    $panel = [
        'url_panel' => $normalizedUrl,
        'api_key' => $apiKey,
    ];
    $response = pasarguardRequest($panel, 'GET', '/api/admin');
    if (!empty($response['error'])) {
        return [
            'status' => false,
            'msg' => $response['error']
        ];
    }
    $httpStatus = $response['status'] ?? null;
    if ($httpStatus === null || $httpStatus >= 400) {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $message = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : "HTTP {$httpStatus}";
        return [
            'status' => false,
            'msg' => is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message
        ];
    }
    return [
        'status' => true,
        'msg' => 'PasarGuard connection succeeded',
        'data' => json_decode((string) ($response['body'] ?? ''), true)
    ];
}

function pasarguardTestConnectionUserPass($baseUrl, $username, $password)
{
    $username = trim((string) $username);
    $password = trim((string) $password);
    $normalizedUrl = rtrim((string) $baseUrl, '/');
    if ($username === '' || $password === '') {
        return [
            'status' => false,
            'msg' => 'PasarGuard username or password is missing'
        ];
    }
    $loginResult = pasarguardLogin($normalizedUrl, $username, $password);
    if (empty($loginResult['status'])) {
        return [
            'status' => false,
            'msg' => $loginResult['msg'] ?? 'PasarGuard login failed'
        ];
    }
    $panel = [
        'url_panel' => $normalizedUrl,
        'username_panel' => $username,
        'password_panel' => $password,
        'pasarguard_auth_mode' => 'password',
    ];
    pasarguardStoreToken($normalizedUrl, $username, $loginResult['access_token']);
    $response = pasarguardRequest($panel, 'GET', '/api/admin');
    if (!empty($response['error'])) {
        return [
            'status' => false,
            'msg' => $response['error']
        ];
    }
    $httpStatus = $response['status'] ?? null;
    if ($httpStatus === null || $httpStatus >= 400) {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $message = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : "HTTP {$httpStatus}";
        return [
            'status' => false,
            'msg' => is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message
        ];
    }
    return [
        'status' => true,
        'msg' => 'PasarGuard connection succeeded',
        'data' => json_decode((string) ($response['body'] ?? ''), true)
    ];
}

function pasarguardTestConnectionPanel(array $panel)
{
    $panel['url_panel'] = (string) ($panel['url_panel'] ?? '');
    $panel['pasarguard_auth_mode'] = 'password';
    if (trim((string) ($panel['username_panel'] ?? '')) === '' || trim((string) ($panel['password_panel'] ?? '')) === '') {
        return [
            'status' => false,
            'msg' => 'PasarGuard username or password is missing'
        ];
    }
    $response = pasarguardRequest($panel, 'GET', '/api/admin');
    if (!empty($response['error'])) {
        return [
            'status' => false,
            'msg' => $response['error']
        ];
    }
    $httpStatus = $response['status'] ?? null;
    if ($httpStatus === null || $httpStatus >= 400) {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $message = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : "HTTP {$httpStatus}";
        return [
            'status' => false,
            'msg' => is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message
        ];
    }
    return [
        'status' => true,
        'msg' => 'PasarGuard connection succeeded',
        'data' => json_decode((string) ($response['body'] ?? ''), true)
    ];
}

function pasarguardRemoveUser($location, $username)
{
    $panel = findPasarGuardPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    return pasarguardRequest($panel, 'DELETE', '/api/user/' . $username);
}
