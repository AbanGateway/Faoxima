<?php

if (!defined('XCD_SCHEMA_VERSION')) {
    define('XCD_SCHEMA_VERSION', 1);
}
if (!defined('XCD_ROOT_DIR')) {
    define('XCD_ROOT_DIR', dirname(__DIR__));
}
if (!defined('XCD_MAX_FILE_BYTES')) {
    define('XCD_MAX_FILE_BYTES', 64 * 1024 * 1024);
}
if (!defined('XCD_MAX_LINE_BYTES')) {
    define('XCD_MAX_LINE_BYTES', 24 * 1024);
}
if (!defined('XCD_MAX_EVENTS_PER_TRACE')) {
    define('XCD_MAX_EVENTS_PER_TRACE', 300);
}
if (!defined('XCD_PROBE_TIMEOUT')) {
    define('XCD_PROBE_TIMEOUT', 5);
}
if (!defined('XCD_LINK_SCHEMES')) {
    define('XCD_LINK_SCHEMES', '(vless|vmess|trojan|ss|hysteria2?|hy2|tuic|wireguard|wg|vpn|awg|amneziawg|tg)');
}

function &xcd_state()
{
    static $state = null;
    if ($state === null) {
        $state = xcd_blank_state();
    }
    return $state;
}

function xcd_blank_state()
{
    return array(
        'active' => false,
        'trace_id' => null,
        'seq' => 0,
        'handler' => null,
        'panel_name' => null,
        'panel_code' => null,
        'panel_type' => null,
        'panel_mode' => null,
        'invoice' => null,
        'client_fp' => null,
        'user_fp' => null,
        'diag_http' => false,
        'probe_done' => false,
        'finalized' => false,
        'secrets' => array(),
        'counts' => array(
            'configured_inbound_count' => null,
            'attached_inbound_count' => null,
            'subscription_link_count' => null,
            'api_link_count' => null,
            'returned_link_count' => null,
            'keyboard_row_count' => null,
        ),
        'count_stage' => array(),
        'reasons' => array(),
        'configured_ids' => null,
        'attached_ids' => null,
        'sub_link_fps' => null,
        'api_link_fps' => null,
        'capped' => false,
        'report_count' => 0,
        'write_failures' => 0,
        'events_written' => 0,
        'started_at' => microtime(true),
    );
}

function xcd_reset()
{
    $state = &xcd_state();
    $state = xcd_blank_state();
}

function xcd_active()
{
    $state = &xcd_state();
    return $state['active'] === true;
}

function xcd_trace_id()
{
    $state = &xcd_state();
    return $state['trace_id'];
}

function xcd_log_dir()
{
    if (defined('XUI_DIAG_LOG_DIR') && is_string(XUI_DIAG_LOG_DIR) && XUI_DIAG_LOG_DIR !== '') {
        return rtrim(XUI_DIAG_LOG_DIR, '/\\');
    }
    return XCD_ROOT_DIR . DIRECTORY_SEPARATOR . 'logs';
}

function xcd_log_file()
{
    return xcd_log_dir() . DIRECTORY_SEPARATOR . 'xui-config-diagnostic-' . date('Y-m-d') . '.jsonl';
}

function xcd_report($message)
{
    $state = &xcd_state();
    $state['report_count']++;
    if ($state['report_count'] > 5) {
        return;
    }
    error_log('[xui-config-diagnostic] ' . xcd_sanitize_text($message, 300));
}

function xcd_key()
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $dir = xcd_log_dir();
    $path = $dir . DIRECTORY_SEPARATOR . '.xcd-fingerprint-key';
    $existing = is_file($path) ? @file_get_contents($path) : false;
    if (is_string($existing) && strlen(trim($existing)) >= 32) {
        $key = trim($existing);
        return $key;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    try {
        $fresh = bin2hex(random_bytes(32));
    } catch (\Throwable $e) {
        $fresh = hash('sha256', uniqid('xcd', true) . XCD_ROOT_DIR . mt_rand());
    }
    $handle = @fopen($path, 'xb');
    if ($handle !== false) {
        @fwrite($handle, $fresh);
        @fclose($handle);
        @chmod($path, 0600);
        $key = $fresh;
        return $key;
    }
    $existing = is_file($path) ? @file_get_contents($path) : false;
    if (is_string($existing) && strlen(trim($existing)) >= 32) {
        $key = trim($existing);
        return $key;
    }
    $key = hash('sha256', 'xcd-fallback|' . XCD_ROOT_DIR . '|' . (string) ($GLOBALS['dbname'] ?? ''));
    xcd_report('fingerprint key file unavailable, using derived fallback key');
    return $key;
}

function xcd_fp($value, $length = 12)
{
    if ($value === null) {
        return null;
    }
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value);
    }
    $value = (string) $value;
    if ($value === '') {
        return null;
    }
    return substr(hash_hmac('sha256', $value, xcd_key()), 0, $length);
}

function xcd_register_secret($value)
{
    $value = trim((string) $value);
    if ($value === '' || strlen($value) < 4) {
        return;
    }
    $state = &xcd_state();
    $state['secrets'][$value] = xcd_fp($value, 8);
}

function xcd_url_segment_whitelist()
{
    return array(
        'panel', 'api', 'clients', 'links', 'get', 'traffic', 'inbounds', 'list', 'sub', 'json', 'login',
        'hwids', 'ips', 'onlines', 'lastonline', 'server', 'status', 'add', 'update', 'delclientbyemail',
        'getclienttraffics', 'getclienttrafficsbyid', 'addclient', 'updateclient', 'resetclienttraffic',
    );
}

function xcd_sanitize_url($url)
{
    $url = (string) $url;
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return '[unparseable-url]';
    }
    $out = '';
    if (!empty($parts['scheme'])) {
        $out .= strtolower($parts['scheme']) . '://';
    }
    if (!empty($parts['host'])) {
        $out .= 'host-' . xcd_fp(strtolower($parts['host']), 8);
    }
    if (!empty($parts['port'])) {
        $out .= ':' . (int) $parts['port'];
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '') {
        $allowed = xcd_url_segment_whitelist();
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            $decoded = rawurldecode($segment);
            if (in_array(strtolower($decoded), $allowed, true)) {
                $segments[$i] = $decoded;
            } else {
                $segments[$i] = '{' . xcd_fp($decoded, 6) . '}';
            }
        }
        $out .= implode('/', $segments);
    }
    if (isset($parts['query']) && $parts['query'] !== '') {
        $out .= '?[query-omitted]';
    }
    return $out;
}

function xcd_sanitize_text($text, $max = 240)
{
    if (is_array($text) || is_object($text)) {
        $text = json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    $text = (string) $text;
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_convert_encoding')) {
        $text = (string) @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $text);
    $text = preg_replace_callback('#https?://[^\s"\'<>]+#i', function ($m) {
        return xcd_sanitize_url($m[0]);
    }, (string) $text);
    $text = preg_replace('#\b' . XCD_LINK_SCHEMES . '://\S+#i', '$1://[redacted]', (string) $text);
    $state = &xcd_state();
    foreach ($state['secrets'] as $secret => $fp) {
        $text = str_replace((string) $secret, '[fp:' . $fp . ']', (string) $text);
    }
    $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', (string) $text);
    $text = preg_replace('/(token|password|passwd|secret|cookie|authorization|apikey|api_key|session)(["\']?\s*[=:]\s*["\']?)[^\s"\',;&]+/i', '$1$2[redacted]', (string) $text);
    $text = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '[uuid]', (string) $text);
    $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)+/', '[email]', (string) $text);
    $text = preg_replace('/[A-Za-z0-9+\/_=\-]{24,}/', '[long-token]', (string) $text);
    $text = trim((string) $text);
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        $text = mb_substr($text, 0, $max, 'UTF-8') . '…';
    } elseif (!function_exists('mb_strlen') && strlen($text) > $max) {
        $text = substr($text, 0, $max) . '…';
    }
    return $text;
}

function xcd_parse_ids($raw)
{
    if (function_exists('xui_parse_inbound_ids')) {
        return xui_parse_inbound_ids($raw);
    }
    if (is_array($raw)) {
        $candidates = $raw;
    } else {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        $candidates = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    $ids = array();
    foreach ($candidates as $candidate) {
        $id = intval(trim((string) $candidate));
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function xcd_shape($raw)
{
    if ($raw === null) {
        return array('present' => false, 'type' => 'null', 'raw' => null);
    }
    if (is_array($raw)) {
        return array('present' => true, 'type' => 'array', 'raw' => xcd_sanitize_text(json_encode($raw), 120));
    }
    $string = (string) $raw;
    $json = json_decode($string, true);
    $format = $string === '' ? 'empty' : (is_array($json) ? 'json_array' : (strpos($string, ',') !== false ? 'csv' : 'scalar'));
    return array('present' => trim($string) !== '', 'type' => 'string', 'format' => $format, 'raw' => xcd_sanitize_text($string, 120));
}

function xcd_call_site($depth = 3)
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 1);
    $site = isset($trace[$depth - 1]) ? $trace[$depth - 1] : array();
    $fn = isset($trace[$depth]['function']) ? $trace[$depth]['function'] : null;
    $file = isset($site['file']) ? $site['file'] : null;
    if ($file !== null) {
        $root = str_replace('\\', '/', XCD_ROOT_DIR) . '/';
        $normalized = str_replace('\\', '/', $file);
        $file = strpos($normalized, $root) === 0 ? substr($normalized, strlen($root)) : basename($normalized);
    }
    return array('file' => $file, 'function' => $fn, 'line' => $site['line'] ?? null);
}

function xcd_begin($handler, $panel = null, $invoice = null, $client = null, $options = array())
{
    static $shutdownRegistered = false;
    $state = &xcd_state();
    if ($state['active'] && !empty($options['reuse'])) {
        return true;
    }
    if ($state['active'] && !$state['finalized']) {
        xcd_finish('superseded_by_new_trace');
    }
    $state = xcd_blank_state();
    try {
        $state['trace_id'] = bin2hex(random_bytes(8));
    } catch (\Throwable $e) {
        $state['trace_id'] = substr(md5(uniqid('', true)), 0, 16);
    }
    $state['active'] = true;
    $state['handler'] = (string) $handler;
    $state['diag_http'] = array_key_exists('diag_http', $options) ? (bool) $options['diag_http'] : true;
    if (is_array($panel)) {
        $state['panel_name'] = $panel['name_panel'] ?? null;
        $state['panel_code'] = $panel['code_panel'] ?? null;
        $state['panel_type'] = $panel['type'] ?? null;
        $state['panel_mode'] = function_exists('xui_panel_uses_token') ? (xui_panel_uses_token($panel) ? 'token' : 'legacy') : null;
    }
    if (is_array($invoice)) {
        $state['invoice'] = $invoice['id_invoice'] ?? null;
        $state['user_fp'] = xcd_fp($invoice['id_user'] ?? null, 10);
        if ($client === null) {
            $client = $invoice['username'] ?? null;
        }
    } elseif ($invoice !== null) {
        $state['invoice'] = (string) $invoice;
    }
    if ($client !== null && $client !== '') {
        xcd_register_secret($client);
        $state['client_fp'] = xcd_fp($client, 12);
    }
    if (is_array($panel)) {
        foreach (array('xui_api_token', 'password_panel', 'username_panel') as $field) {
            if (!empty($panel[$field])) {
                xcd_register_secret($panel[$field]);
            }
        }
    }
    if (!$shutdownRegistered) {
        $shutdownRegistered = true;
        register_shutdown_function('xcd_shutdown');
    }
    return true;
}

function xcd_register_subscription_secret($subscriptionUrl)
{
    $path = (string) parse_url((string) $subscriptionUrl, PHP_URL_PATH);
    $last = basename($path);
    if ($last !== '' && $last !== '/') {
        xcd_register_secret($last);
    }
}

function xcd_context_for_panel($panel)
{
    if (!xcd_active() || !is_array($panel)) {
        return;
    }
    $state = &xcd_state();
    if ($state['panel_name'] === null) {
        $state['panel_name'] = $panel['name_panel'] ?? null;
        $state['panel_code'] = $panel['code_panel'] ?? null;
        $state['panel_type'] = $panel['type'] ?? null;
        $state['panel_mode'] = function_exists('xui_panel_uses_token') ? (xui_panel_uses_token($panel) ? 'token' : 'legacy') : null;
    }
}

function xcd_event($stage, $data = array(), $level = 'info')
{
    return xcd_emit($stage, $data, $level);
}

function xcd_emit($stage, $data, $level)
{
    $state = &xcd_state();
    if (!$state['active']) {
        return false;
    }
    return xcd_write_record($stage, $data, $level, xcd_call_site(3));
}

function xcd_write_record($stage, $data, $level, $site)
{
    $state = &xcd_state();
    if ($state['events_written'] >= XCD_MAX_EVENTS_PER_TRACE) {
        if (!$state['capped']) {
            $state['capped'] = true;
            $data = array('limit' => XCD_MAX_EVENTS_PER_TRACE, 'dropped_stage' => (string) $stage);
            $stage = 'log.event_cap_reached';
            $level = 'warn';
        } else {
            return false;
        }
    }
    $state['seq']++;
    $now = microtime(true);
    $record = array(
        'ts' => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000)),
        'v' => XCD_SCHEMA_VERSION,
        'trace_id' => $state['trace_id'],
        'seq' => $state['seq'],
        'pid' => function_exists('getmypid') ? getmypid() : null,
        'level' => $level,
        'stage' => (string) $stage,
        'source' => $site,
        'handler' => $state['handler'],
        'panel' => array(
            'name' => $state['panel_name'],
            'code' => $state['panel_code'],
            'type' => $state['panel_type'],
            'api_mode' => $state['panel_mode'],
        ),
        'invoice' => $state['invoice'],
        'client_fp' => $state['client_fp'],
        'user_fp' => $state['user_fp'],
        'elapsed_ms' => (int) round(($now - $state['started_at']) * 1000),
        'data' => $data,
    );
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $line = json_encode($record, $flags);
    if (!is_string($line)) {
        $record['data'] = array('error' => 'json_encode_failed', 'json_error' => json_last_error_msg());
        $line = json_encode($record, $flags);
    }
    if (!is_string($line)) {
        xcd_report('json_encode failed for stage ' . $stage);
        return false;
    }
    if (strlen($line) > XCD_MAX_LINE_BYTES) {
        $record['data'] = array('truncated' => true, 'original_bytes' => strlen($line), 'data_keys' => is_array($data) ? array_slice(array_keys($data), 0, 30) : null);
        $line = json_encode($record, $flags);
        if (!is_string($line)) {
            return false;
        }
    }
    $ok = xcd_append($line . "\n");
    if ($ok) {
        $state['events_written']++;
    }
    return $ok;
}

function xcd_append($payload, $targetFile = null)
{
    $state = &xcd_state();
    $dir = xcd_log_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        if (!is_dir($dir)) {
            $state['write_failures']++;
            xcd_report('cannot create log directory ' . $dir);
            return false;
        }
    }
    $file = $targetFile !== null ? $targetFile : xcd_log_file();
    clearstatcache(true, $file);
    if (is_file($file) && (int) @filesize($file) > XCD_MAX_FILE_BYTES) {
        $state['write_failures']++;
        xcd_report('log file exceeded size cap ' . XCD_MAX_FILE_BYTES . ' bytes, event dropped');
        return false;
    }
    $handle = @fopen($file, 'ab');
    if ($handle === false) {
        $state['write_failures']++;
        xcd_report('cannot open log file ' . $file);
        return false;
    }
    $written = false;
    if (@flock($handle, LOCK_EX)) {
        $length = strlen($payload);
        $result = @fwrite($handle, $payload);
        @fflush($handle);
        @flock($handle, LOCK_UN);
        $written = ($result === $length);
        if (!$written) {
            xcd_report('short or failed write to log file ' . $file);
        }
    } else {
        xcd_report('cannot lock log file ' . $file);
    }
    @fclose($handle);
    if (!$written) {
        $state['write_failures']++;
    }
    return $written;
}

function xcd_count($name, $value, $stage = null)
{
    $state = &xcd_state();
    if (!$state['active'] || !array_key_exists($name, $state['counts'])) {
        return;
    }
    $state['counts'][$name] = $value === null ? null : (int) $value;
    if ($stage !== null) {
        $state['count_stage'][$name] = (string) $stage;
    }
}

function xcd_reason($key, $text)
{
    $state = &xcd_state();
    if (!$state['active']) {
        return;
    }
    $state['reasons'][(string) $key] = xcd_sanitize_text($text, 200);
}

function xcd_set_ids($kind, $ids)
{
    $state = &xcd_state();
    if (!$state['active']) {
        return;
    }
    $state[$kind . '_ids'] = $ids;
}

function xcd_set_link_fps($kind, $fps)
{
    $state = &xcd_state();
    if (!$state['active']) {
        return;
    }
    $state[$kind . '_link_fps'] = $fps;
}

function xcd_state_value($key)
{
    $state = &xcd_state();
    return $state[$key] ?? null;
}

function xcd_allowed_link_params()
{
    return array('type', 'security', 'flow', 'mode', 'headerType', 'encryption');
}

function xcd_link_info($link)
{
    $link = trim((string) $link);
    $info = array('fp' => xcd_fp($link, 12), 'length' => strlen($link), 'scheme' => null, 'host_fp' => null, 'port' => null, 'params' => array());
    if (!preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $link, $m)) {
        $info['scheme'] = null;
        $info['shape'] = xcd_text_shape($link);
        return $info;
    }
    $scheme = strtolower($m[1]);
    $info['scheme'] = $scheme;
    if ($scheme === 'vmess') {
        $payload = substr($link, 8);
        $payload = explode('#', $payload, 2)[0];
        $json = json_decode((string) @base64_decode($payload, true), true);
        if (is_array($json)) {
            $info['host_fp'] = xcd_fp(strtolower((string) ($json['add'] ?? '')), 8);
            $info['port'] = isset($json['port']) ? (int) $json['port'] : null;
            foreach (array('net' => 'type', 'tls' => 'security') as $from => $to) {
                if (isset($json[$from]) && is_scalar($json[$from])) {
                    $info['params'][$to] = xcd_sanitize_text((string) $json[$from], 20);
                }
            }
        } else {
            $info['decode'] = 'vmess_payload_not_json';
        }
        return $info;
    }
    $parts = @parse_url($link);
    if (is_array($parts)) {
        $info['host_fp'] = isset($parts['host']) ? xcd_fp(strtolower($parts['host']), 8) : null;
        $info['port'] = isset($parts['port']) ? (int) $parts['port'] : null;
        if (!empty($parts['query'])) {
            $query = array();
            parse_str($parts['query'], $query);
            foreach (xcd_allowed_link_params() as $key) {
                if (isset($query[$key]) && is_scalar($query[$key])) {
                    $info['params'][$key] = xcd_sanitize_text((string) $query[$key], 20);
                }
            }
        }
    }
    return $info;
}

function xcd_text_shape($text)
{
    $trim = ltrim((string) $text);
    if ($trim === '') {
        return 'empty';
    }
    $first = $trim[0];
    if ($first === '<') {
        return 'html_or_xml';
    }
    if ($first === '{' || $first === '[') {
        return 'json_like';
    }
    if (preg_match('/^[A-Za-z0-9+\/=_\-]+$/', $trim)) {
        return 'base64_like_token';
    }
    return 'plain_text';
}

function xcd_analyze_links($links, $limit = 20)
{
    $links = is_array($links) ? array_values($links) : array();
    $byScheme = array();
    $endpoints = array();
    $fps = array();
    $items = array();
    $fallbackRegexCount = 0;
    foreach ($links as $i => $link) {
        if (!is_string($link)) {
            $key = 'non_string:' . gettype($link);
            $byScheme[$key] = ($byScheme[$key] ?? 0) + 1;
            continue;
        }
        $info = xcd_link_info($link);
        $scheme = $info['scheme'] ?? 'no_scheme';
        $byScheme[$scheme] = ($byScheme[$scheme] ?? 0) + 1;
        if (preg_match('/^(vless|vmess|trojan):\/\//i', trim($link))) {
            $fallbackRegexCount++;
        }
        if ($info['host_fp'] !== null || $info['port'] !== null) {
            $endpoints[$info['host_fp'] . ':' . $info['port']] = true;
        }
        $fps[] = $info['fp'];
        if ($i < $limit) {
            $items[] = $info;
        }
    }
    return array(
        'count' => count($links),
        'by_scheme' => empty($byScheme) ? new stdClass() : $byScheme,
        'distinct_endpoint_count' => count($endpoints),
        'duplicate_link_count' => count($fps) - count(array_unique($fps)),
        'matching_vless_vmess_trojan_count' => $fallbackRegexCount,
        'fingerprints' => $fps,
        'items' => $items,
        'items_truncated' => count($links) > $limit,
    );
}

function xcd_entry_type($entry)
{
    if (is_string($entry)) {
        if (strpos($entry, "\n") !== false && !preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:\/\//', $entry)) {
            return 'multiline_text';
        }
        if (preg_match('/^(xuifile|xuiawgfile):\/\//i', $entry)) {
            return 'file_marker';
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):\/\//i', $entry, $m)) {
            return 'link:' . strtolower($m[1]);
        }
        return $entry === '' ? 'empty_string' : 'string_without_scheme';
    }
    return 'non_string:' . gettype($entry);
}

function xcd_entry_types($entries)
{
    $types = array();
    foreach (is_array($entries) ? $entries : array() as $entry) {
        $type = xcd_entry_type($entry);
        $types[$type] = ($types[$type] ?? 0) + 1;
    }
    return $types;
}

function xcd_analyze_subscription_body($raw)
{
    $out = array(
        'body_type' => $raw === null ? 'null' : gettype($raw),
        'raw_length' => is_string($raw) ? strlen($raw) : null,
    );
    if (!is_string($raw)) {
        return $out;
    }
    $trimmed = trim($raw);
    $out['trimmed_length'] = strlen($trimmed);
    $out['has_surrounding_whitespace'] = ($trimmed !== $raw);
    $out['body_shape'] = xcd_text_shape($raw);
    $isBase64 = function_exists('isBase64') ? (bool) isBase64($raw) : (base64_encode((string) @base64_decode($raw, true)) === $raw);
    $strictDecode = @base64_decode($trimmed, true);
    $out['is_base64_flag_untrimmed'] = $isBase64;
    $out['base64_strict_decode_ok_trimmed'] = ($strictDecode !== false && $trimmed !== '');
    $text = $raw;
    $out['decoded_used'] = false;
    if ($isBase64) {
        $decoded = base64_decode($raw);
        $out['decoded_used'] = true;
        $out['decoded_length'] = is_string($decoded) ? strlen($decoded) : null;
        $text = is_string($decoded) ? $decoded : '';
    } elseif ($strictDecode !== false && $trimmed !== '' && preg_match('#' . XCD_LINK_SCHEMES . '://#i', $strictDecode)) {
        $out['base64_would_decode_to_links_if_trimmed'] = true;
    }
    $lines = preg_split('/\R/', trim($text));
    $lines = is_array($lines) ? $lines : array();
    $out['line_count'] = count($lines);
    $valid = array();
    $rejected = array();
    $emptyLines = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            $emptyLines++;
            continue;
        }
        if (preg_match('/^' . XCD_LINK_SCHEMES . ':\/\//i', $line)) {
            $valid[] = $line;
        } else {
            $reason = 'no_known_scheme:' . xcd_text_shape($line);
            $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
        }
    }
    $out['empty_line_count'] = $emptyLines;
    $out['nonempty_line_count'] = count($lines) - $emptyLines;
    $out['valid_link_count'] = count($valid);
    $out['rejected_by_reason'] = empty($rejected) ? new stdClass() : $rejected;
    $out['links'] = xcd_analyze_links($valid);
    return $out;
}

function xcd_capture_curl($ch, $failed = false)
{
    $state = &xcd_state();
    if (!$state['active']) {
        return;
    }
    $GLOBALS['xcd_last_transfer'] = xcd_curl_snapshot($ch, $failed);
}

function xcd_curl_snapshot($ch, $failed = false)
{
    $info = @curl_getinfo($ch);
    $info = is_array($info) ? $info : array();
    return array(
        'http_status' => (int) ($info['http_code'] ?? 0),
        'content_type' => isset($info['content_type']) && $info['content_type'] !== null ? xcd_sanitize_text($info['content_type'], 80) : null,
        'size_download' => isset($info['size_download']) ? (int) $info['size_download'] : null,
        'redirect_count' => isset($info['redirect_count']) ? (int) $info['redirect_count'] : null,
        'redirected_to' => !empty($info['redirect_url']) ? xcd_sanitize_url($info['redirect_url']) : null,
        'effective_url' => !empty($info['url']) ? xcd_sanitize_url($info['url']) : null,
        'curl_errno' => (int) @curl_errno($ch),
        'curl_error' => ($failed || @curl_errno($ch)) ? xcd_sanitize_text((string) @curl_error($ch), 160) : null,
        'total_time_ms' => isset($info['total_time']) ? (int) round($info['total_time'] * 1000) : null,
    );
}

function xcd_take_transfer()
{
    $transfer = $GLOBALS['xcd_last_transfer'] ?? null;
    unset($GLOBALS['xcd_last_transfer']);
    if (!is_array($transfer) && class_exists('CurlRequest', false) && isset(CurlRequest::$lastTransfer)) {
        $transfer = CurlRequest::$lastTransfer;
    }
    return is_array($transfer) ? $transfer : null;
}

function xcd_clear_transfer()
{
    unset($GLOBALS['xcd_last_transfer']);
    if (class_exists('CurlRequest', false)) {
        CurlRequest::$lastTransfer = null;
    }
}

function xcd_handler_enter($handler, $invoice)
{
    $panel = null;
    if (is_array($invoice) && !empty($invoice['Service_location']) && function_exists('select')) {
        $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
    }
    if (!is_array($panel) || ($panel['type'] ?? '') !== 'x-ui_single') {
        return false;
    }
    xcd_begin($handler, $panel, $invoice);
    $state = &xcd_state();
    $data = array(
        'invoice_status' => $invoice['Status'] ?? null,
        'invoice_sold_at' => $invoice['time_sell'] ?? null,
        'invoice_product_name' => isset($invoice['name_product']) ? xcd_sanitize_text($invoice['name_product'], 80) : null,
        'update_id' => (is_array($GLOBALS['update'] ?? null) && isset($GLOBALS['update']['update_id'])) ? $GLOBALS['update']['update_id'] : null,
        'api_mode' => $state['panel_mode'],
        'has_api_token' => !empty($panel['xui_api_token']),
        'configured_api_mode_field' => $panel['xui_api_mode'] ?? null,
    );
    xcd_event('handler.enter', $data);
    xcd_log_configured_inbounds($panel, $invoice);
    return true;
}

function xcd_find_product_rows($invoice, $panel)
{
    $rows = array();
    $name = is_array($invoice) ? ($invoice['name_product'] ?? null) : null;
    if ($name === null || $name === '') {
        return $rows;
    }
    try {
        $pdo = function_exists('getDatabaseConnection') ? getDatabaseConnection() : ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) {
            return $rows;
        }
        $stmt = $pdo->prepare('SELECT * FROM product WHERE name_product = ?');
        $stmt->execute(array($name));
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panelName = (string) ($panel['name_panel'] ?? '');
        foreach (is_array($all) ? $all : array() as $row) {
            $location = (string) ($row['Location'] ?? '');
            $locations = array_map('trim', explode(',', $location));
            $rows[] = array(
                'row' => $row,
                'location_matches_panel' => ($panelName !== '' && in_array($panelName, $locations, true)) || $location === '/all',
            );
        }
    } catch (\Throwable $e) {
        xcd_reason('product_lookup', 'failed: ' . $e->getMessage());
    }
    return $rows;
}

function xcd_log_configured_inbounds($panel, $invoice = null)
{
    $panelRaw = $panel['inboundid'] ?? null;
    $panelIds = xcd_parse_ids($panelRaw);
    $candidates = xcd_find_product_rows($invoice, $panel);
    $matching = array();
    foreach ($candidates as $candidate) {
        if ($candidate['location_matches_panel']) {
            $matching[] = $candidate['row'];
        }
    }
    $productSummaries = array();
    foreach ($candidates as $candidate) {
        $row = $candidate['row'];
        $productSummaries[] = array(
            'code_product' => $row['code_product'] ?? null,
            'location_matches_panel' => $candidate['location_matches_panel'],
            'inbounds' => xcd_shape($row['inbounds'] ?? null),
            'inbounds_parsed' => xcd_parse_ids($row['inbounds'] ?? null),
        );
    }
    $productIds = null;
    $productRaw = null;
    $productAmbiguous = false;
    if (count($matching) >= 1) {
        $distinct = array();
        foreach ($matching as $row) {
            $distinct[json_encode(xcd_parse_ids($row['inbounds'] ?? null)) . '|' . (isset($row['inbounds']) && $row['inbounds'] !== null ? '1' : '0')] = $row;
        }
        if (count($distinct) === 1) {
            $row = reset($distinct);
            $productRaw = $row['inbounds'] ?? null;
            $productIds = $productRaw !== null ? xcd_parse_ids($productRaw) : null;
        } else {
            $productAmbiguous = true;
        }
    }
    $creationRuleSource = ($productRaw !== null) ? 'product.inbounds' : 'panel.inboundid';
    $effective = null;
    if (!$productAmbiguous) {
        $effective = ($productRaw !== null) ? $productIds : $panelIds;
    }
    xcd_set_ids('configured', $effective);
    xcd_count('configured_inbound_count', $effective === null ? null : count($effective), 'configuration');
    xcd_event('inbounds.configured', array(
        'panel_inboundid' => xcd_shape($panelRaw),
        'panel_inbound_ids_parsed' => $panelIds,
        'panel_inbound_count' => count($panelIds),
        'product_rows_with_same_name' => count($candidates),
        'product_rows_matching_panel_location' => count($matching),
        'product_rows' => array_slice($productSummaries, 0, 6),
        'product_inbounds_ambiguous' => $productAmbiguous,
        'effective_source_by_creation_rule' => $productAmbiguous ? null : $creationRuleSource,
        'effective_inbound_ids' => $effective,
        'effective_inbound_count' => $effective === null ? null : count($effective),
        'ids_used_by_config_fallback' => $panelIds,
        'config_fallback_ids_source' => 'panel.inboundid',
        'config_fallback_ids_differ_from_effective' => $effective === null ? null : ($effective !== $panelIds),
    ));
}

function xcd_probe_token($panel, $email)
{
    $state = &xcd_state();
    if (!$state['active']) {
        return;
    }
    if ($state['probe_done']) {
        return;
    }
    $state['probe_done'] = true;
    if (!$state['diag_http']) {
        xcd_event('probe.skipped', array('reason' => 'diagnostic_http_disabled_for_this_trace'));
        return;
    }
    if (!is_array($panel) || !function_exists('xui_panel_uses_token') || !xui_panel_uses_token($panel)) {
        xcd_event('probe.skipped', array('reason' => 'panel_in_legacy_mode_or_missing_token'));
        xcd_count('attached_inbound_count', null, 'client_attachment');
        return;
    }
    if ($email === null || $email === '') {
        xcd_event('probe.skipped', array('reason' => 'client_identifier_empty'), 'warn');
        return;
    }
    xcd_probe_client($panel, $email);
    xcd_probe_links($panel, $email);
}

function xcd_probe_request($panel, $pathTemplate, $path, $email)
{
    $timeout = (int) XCD_PROBE_TIMEOUT;
    $base = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    xcd_clear_transfer();
    $started = microtime(true);
    $resp = array('status' => 0, 'body' => null, 'error' => 'probe_not_executed');
    $exception = null;
    try {
        $req = new CurlRequest($base . $path);
        $req->setTimeout($timeout);
        $req->setBearerToken(function_exists('xui_panel_token') ? xui_panel_token($panel) : trim((string) ($panel['xui_api_token'] ?? '')));
        $req->setHeaders(array('Accept: application/json', 'X-Requested-With: XMLHttpRequest'));
        $resp = $req->get();
    } catch (\Throwable $e) {
        $exception = xcd_sanitize_text($e->getMessage(), 160);
    }
    $elapsed = (int) round((microtime(true) - $started) * 1000);
    $transfer = xcd_take_transfer();
    $body = is_array($resp) && isset($resp['body']) && is_string($resp['body']) ? $resp['body'] : null;
    $decoded = $body !== null ? json_decode($body, true) : null;
    return array(
        'resp' => $resp,
        'body' => $body,
        'decoded' => is_array($decoded) ? $decoded : null,
        'http' => array(
            'method' => 'GET',
            'path_template' => $pathTemplate,
            'timeout_s' => $timeout,
            'duration_ms' => $elapsed,
            'http_status' => is_array($resp) ? (int) ($resp['status'] ?? 0) : null,
            'wrapper_error' => (is_array($resp) && !empty($resp['error'])) ? xcd_sanitize_text($resp['error'], 160) : null,
            'exception' => $exception,
            'transfer' => $transfer,
            'response_bytes' => $body !== null ? strlen($body) : null,
            'json_valid' => is_array($decoded),
            'json_error' => ($body !== null && !is_array($decoded)) ? json_last_error_msg() : null,
            'body_shape' => $body !== null ? xcd_text_shape($body) : null,
        ),
    );
}

function xcd_probe_client($panel, $email)
{
    $call = xcd_probe_request($panel, '/panel/api/clients/get/{client}', '/panel/api/clients/get/' . rawurlencode($email), $email);
    $decoded = $call['decoded'];
    $success = is_array($decoded) ? ($decoded['success'] ?? null) : null;
    $obj = is_array($decoded) ? ($decoded['obj'] ?? null) : null;
    $objKeys = is_array($obj) ? array_slice(array_map('strval', array_keys($obj)), 0, 40) : null;
    $attached = null;
    $attachedKey = null;
    if (is_array($obj)) {
        foreach (array('inboundIds', 'inbound_ids', 'inboundIDs') as $key) {
            if (array_key_exists($key, $obj) && is_array($obj[$key])) {
                $attached = array_values(array_unique(array_map('intval', $obj[$key])));
                $attachedKey = $key;
                break;
            }
        }
    }
    $configured = xcd_state_value('configured_ids');
    $record = is_array($obj) && is_array($obj['client'] ?? null) ? $obj['client'] : (is_array($obj) ? $obj : array());
    $data = array(
        'source' => 'diagnostic_probe',
        'used_in_user_output' => false,
        'http' => $call['http'],
        'success_flag' => $success,
        'panel_message' => (is_array($decoded) && isset($decoded['msg'])) ? xcd_sanitize_text($decoded['msg'], 160) : null,
        'obj_type' => $obj === null ? 'null' : gettype($obj),
        'obj_keys' => $objKeys,
        'inbound_ids_key' => $attachedKey,
        'client_record_found' => is_array($obj) && !empty($record['email']),
        'client_enable' => array_key_exists('enable', $record) ? (bool) $record['enable'] : null,
        'attached_inbound_ids' => $attached,
        'attached_inbound_count' => $attached === null ? null : count($attached),
        'configured_inbound_ids' => $configured,
        'configured_minus_attached' => ($attached !== null && is_array($configured)) ? array_values(array_diff($configured, $attached)) : null,
        'attached_minus_configured' => ($attached !== null && is_array($configured)) ? array_values(array_diff($attached, $configured)) : null,
        'is_affected_service_client' => true,
    );
    $level = $attached === null ? 'warn' : 'info';
    xcd_set_ids('attached', $attached);
    xcd_count('attached_inbound_count', $attached === null ? null : count($attached), 'client_attachment');
    if ($attached === null) {
        xcd_reason('attached', 'clients/get gave no readable inbound id list (status ' . ($call['http']['http_status'] ?? 'n/a') . ')');
    } elseif (is_array($configured) && count($attached) < count($configured)) {
        xcd_reason('attached', 'client record lists ' . count($attached) . ' inbound id(s) while ' . count($configured) . ' are configured');
    }
    xcd_event('client.attached', $data, $level);
}

function xcd_extract_api_candidates($obj)
{
    $candidates = array();
    $shapes = array();
    if (is_string($obj)) {
        $shapes['string'] = 1;
        $candidates = preg_split('/\R/', trim($obj)) ?: array();
    } elseif (is_array($obj)) {
        foreach ($obj as $item) {
            if (is_string($item)) {
                $shapes['string'] = ($shapes['string'] ?? 0) + 1;
                $candidates[] = $item;
            } elseif (is_array($item)) {
                $found = false;
                foreach (array('uri', 'link', 'url') as $k) {
                    if (!empty($item[$k]) && is_string($item[$k])) {
                        $candidates[] = $item[$k];
                        $found = true;
                    }
                }
                $shapes[$found ? 'array_with_link_key' : 'array_without_link_key'] = ($shapes[$found ? 'array_with_link_key' : 'array_without_link_key'] ?? 0) + 1;
            } else {
                $shapes[gettype($item)] = ($shapes[gettype($item)] ?? 0) + 1;
            }
        }
    }
    return array('candidates' => $candidates, 'item_shapes' => $shapes);
}

function xcd_describe_api_links($decoded, $source, $http, $returned = null)
{
    $success = is_array($decoded) ? ($decoded['success'] ?? null) : null;
    $obj = is_array($decoded) ? (array_key_exists('obj', $decoded) ? $decoded['obj'] : $decoded) : null;
    $extracted = xcd_extract_api_candidates($obj);
    $candidates = array_values(array_filter(array_map(function ($c) {
        return trim((string) $c);
    }, $extracted['candidates']), function ($c) {
        return $c !== '';
    }));
    $valid = array_values(array_filter($candidates, function ($c) {
        return (bool) preg_match('/^' . XCD_LINK_SCHEMES . ':\/\//i', $c);
    }));
    $rejected = array();
    foreach ($candidates as $candidate) {
        if (!preg_match('/^' . XCD_LINK_SCHEMES . ':\/\//i', $candidate)) {
            $reason = 'no_known_scheme:' . xcd_text_shape($candidate);
            $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
        }
    }
    $analysis = xcd_analyze_links($valid);
    return array(
        'source' => $source,
        'http' => $http,
        'success_flag' => $success,
        'panel_message' => (is_array($decoded) && isset($decoded['msg'])) ? xcd_sanitize_text($decoded['msg'], 160) : null,
        'obj_type' => $obj === null ? 'null' : gettype($obj),
        'obj_item_count' => is_array($obj) ? count($obj) : (is_string($obj) ? count($candidates) : null),
        'obj_item_shapes' => $extracted['item_shapes'],
        'candidate_count' => count($candidates),
        'valid_link_count' => count($valid),
        'valid_vless_vmess_trojan_count' => $analysis['matching_vless_vmess_trojan_count'],
        'rejected_by_reason' => empty($rejected) ? new stdClass() : $rejected,
        'links' => $analysis,
        'available_before_first_link_selection' => count($valid),
        'returned_by_function' => $returned,
    );
}

function xcd_probe_links($panel, $email)
{
    $call = xcd_probe_request($panel, '/panel/api/clients/links/{client}', '/panel/api/clients/links/' . rawurlencode($email), $email);
    $data = xcd_describe_api_links($call['decoded'], 'diagnostic_probe', $call['http']);
    $data['used_in_user_output'] = false;
    $data['is_affected_service_client'] = true;
    $subFps = xcd_state_value('sub_link_fps');
    $apiFps = $data['links']['fingerprints'];
    if (is_array($subFps)) {
        $data['compare_with_subscription'] = array(
            'subscription_link_count' => count($subFps),
            'api_link_count' => count($apiFps),
            'in_both' => count(array_intersect($subFps, $apiFps)),
            'only_in_api' => count(array_diff($apiFps, $subFps)),
            'only_in_subscription' => count(array_diff($subFps, $apiFps)),
        );
    }
    xcd_set_link_fps('api', $apiFps);
    $hasBody = $call['body'] !== null;
    xcd_count('api_link_count', $hasBody && $call['decoded'] !== null && ($data['success_flag'] !== false) ? $data['valid_link_count'] : null, 'links_api');
    if (!$hasBody || $call['decoded'] === null) {
        xcd_reason('api_links', 'links endpoint gave no readable JSON (status ' . ($call['http']['http_status'] ?? 'n/a') . ')');
    } elseif ($data['success_flag'] === false) {
        xcd_reason('api_links', 'links endpoint reported success=false');
    }
    xcd_event('links_api.fetch', $data, ($hasBody && $call['decoded'] !== null) ? 'info' : 'warn');
}

function xcd_log_existing_links_api($resp, $elapsedMs, $returned, $discarded)
{
    if (!xcd_active()) {
        return;
    }
    $body = is_array($resp) && isset($resp['body']) && is_string($resp['body']) ? $resp['body'] : null;
    $decoded = $body !== null ? json_decode($body, true) : null;
    $http = array(
        'method' => 'GET',
        'path_template' => '/panel/api/clients/links/{client}',
        'duration_ms' => $elapsedMs,
        'http_status' => is_array($resp) ? (int) ($resp['status'] ?? 0) : null,
        'wrapper_error' => (is_array($resp) && !empty($resp['error'])) ? xcd_sanitize_text($resp['error'], 160) : null,
        'transfer' => xcd_take_transfer(),
        'response_bytes' => $body !== null ? strlen($body) : null,
        'json_valid' => is_array($decoded),
    );
    $data = xcd_describe_api_links(is_array($decoded) ? $decoded : null, 'existing_flow', $http, $returned);
    $data['used_in_user_output'] = true;
    $data['discarded_after_first_selection'] = $discarded;
    xcd_emit('links_api.fetch', $data, $body !== null ? 'info' : 'warn');
}

function xcd_exit_null($reason, $extra = array())
{
    if (xcd_active()) {
        $extra['reason'] = $reason;
        xcd_emit('single_link.exit', $extra, 'warn');
        xcd_reason('single_link_exit', $reason);
    }
    return null;
}

function xcd_log_send_result($kind, $index, $response)
{
    if (!xcd_active()) {
        return;
    }
    $ok = is_array($response) ? ($response['ok'] ?? null) : null;
    xcd_emit('send.result', array(
        'kind' => $kind,
        'config_index' => $index,
        'telegram_ok' => $ok,
        'error_code' => is_array($response) ? ($response['error_code'] ?? null) : null,
        'description' => (is_array($response) && isset($response['description'])) ? xcd_sanitize_text($response['description'], 160) : null,
        'response_type' => gettype($response),
    ), $ok === true ? 'info' : 'warn');
}

function xcd_final_chain()
{
    return array(
        array('configured_inbound_count', 'attached_inbound_count', 'client_attachment'),
        array('attached_inbound_count', 'subscription_link_count', 'subscription_retrieval'),
        array('attached_inbound_count', 'api_link_count', 'panel_response_generation'),
        array('subscription_link_count', 'returned_link_count', 'bot_processing'),
        array('api_link_count', 'returned_link_count', 'bot_processing'),
        array('returned_link_count', 'keyboard_row_count', 'keyboard_construction'),
    );
}

function xcd_compute_drops()
{
    $state = &xcd_state();
    $counts = $state['counts'];
    $drops = array();
    foreach (xcd_final_chain() as $pair) {
        list($from, $to, $stage) = $pair;
        if ($counts[$from] === null || $counts[$to] === null) {
            continue;
        }
        if ($counts[$to] < $counts[$from]) {
            $drops[] = array(
                'between' => $from . '->' . $to,
                'stage' => $stage,
                'from_value' => $counts[$from],
                'to_value' => $counts[$to],
                'observed_note' => $state['reasons'][$stage] ?? ($state['reasons'][$to] ?? null),
                'comparison_is_heuristic' => in_array($from, array('configured_inbound_count', 'attached_inbound_count'), true) && $to !== 'attached_inbound_count',
            );
        }
    }
    return $drops;
}

function xcd_finish($exitReason = 'completed')
{
    $state = &xcd_state();
    if (!$state['active'] || $state['finalized']) {
        return;
    }
    $state['finalized'] = true;
    $lastError = error_get_last();
    $fatal = null;
    if (is_array($lastError) && in_array($lastError['type'] ?? 0, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) {
        $fatal = array('type' => $lastError['type'], 'message' => xcd_sanitize_text($lastError['message'] ?? '', 200));
    }
    $data = array_merge($state['counts'], array(
        'count_stage_of_last_update' => $state['count_stage'],
        'drops_observed' => xcd_compute_drops(),
        'observed_notes' => $state['reasons'],
        'configured_inbound_ids' => $state['configured_ids'],
        'attached_inbound_ids' => $state['attached_ids'],
        'exit_reason' => $exitReason,
        'fatal_error_at_shutdown' => $fatal,
        'events_written' => $state['events_written'],
        'write_failures' => $state['write_failures'],
        'duration_ms' => (int) round((microtime(true) - $state['started_at']) * 1000),
        'interpretation' => 'values are observations; null means not measured; a lower later count is not by itself a confirmed cause',
    ));
    $site = array('file' => 'lib/XuiConfigDiagnostic.php', 'function' => 'xcd_finish', 'line' => null);
    xcd_write_record('request.final', $data, $fatal !== null ? 'error' : 'info', $site);
    $state['active'] = false;
}

function xcd_shutdown()
{
    xcd_finish('ended_without_explicit_finish');
}

function xcd_exit($reason, $extra = array())
{
    if (!xcd_active()) {
        return;
    }
    $extra['reason'] = $reason;
    xcd_emit('handler.exit', $extra, 'info');
}
