<?php

require_once __DIR__ . '/XuiConfigDiagnostic.php';

if (!defined('XSYNC_BATCH_SIZE')) {
    define('XSYNC_BATCH_SIZE', 40);
}
if (!defined('XSYNC_DISCOVER_BATCH')) {
    define('XSYNC_DISCOVER_BATCH', 40);
}
if (!defined('XSYNC_EMPTY_CONFIRM_DELAY')) {
    define('XSYNC_EMPTY_CONFIRM_DELAY', 45);
}
if (!defined('XSYNC_API_TIMEOUT')) {
    define('XSYNC_API_TIMEOUT', 8);
}
if (!defined('XSYNC_STEP_RETRIES')) {
    define('XSYNC_STEP_RETRIES', 3);
}
if (!defined('XSYNC_TIME_BUDGET')) {
    define('XSYNC_TIME_BUDGET', 20);
}
if (!defined('XSYNC_BACKOFF_CAP')) {
    define('XSYNC_BACKOFF_CAP', 3600);
}
if (!defined('XSYNC_SLEEP_ENABLED')) {
    define('XSYNC_SLEEP_ENABLED', true);
}

function &xsync_ctx()
{
    static $ctx = array('run_id' => null, 'panel' => null, 'version' => null, 'deadline' => null);
    return $ctx;
}

function xsync_pdo()
{
    if (function_exists('getDatabaseConnection')) {
        $pdo = getDatabaseConnection();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    return ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null;
}

function xsync_log_file()
{
    return xcd_log_dir() . DIRECTORY_SEPARATOR . 'xui-inbound-sync-' . date('Y-m-d') . '.jsonl';
}

function xsync_run_id()
{
    $ctx = &xsync_ctx();
    if ($ctx['run_id'] === null) {
        try {
            $ctx['run_id'] = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $ctx['run_id'] = substr(md5(uniqid('', true)), 0, 12);
        }
    }
    return $ctx['run_id'];
}

function xsync_log($event, $data = array(), $level = 'info')
{
    $ctx = &xsync_ctx();
    $now = microtime(true);
    $record = array(
        'ts' => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000)),
        'run_id' => xsync_run_id(),
        'level' => $level,
        'event' => (string) $event,
        'panel' => $ctx['panel'],
        'reference_version' => $ctx['version'],
        'pid' => function_exists('getmypid') ? getmypid() : null,
        'data' => $data,
    );
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $line = json_encode($record, $flags);
    if (!is_string($line)) {
        return false;
    }
    if (strlen($line) > XCD_MAX_LINE_BYTES) {
        $record['data'] = array('truncated' => true, 'original_bytes' => strlen($line));
        $line = json_encode($record, $flags);
    }
    return xcd_append($line . "\n", xsync_log_file());
}

function xsync_reset_run()
{
    $ctx = &xsync_ctx();
    $ctx = array('run_id' => null, 'panel' => null, 'version' => null, 'deadline' => null);
}

function xsync_ids($ids)
{
    $out = array();
    foreach (is_array($ids) ? $ids : array() as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $out[(int) $id] = (int) $id;
        }
    }
    $out = array_values($out);
    sort($out);
    return $out;
}

function xsync_decode_ids($json)
{
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? xsync_ids($decoded) : array();
}

function xsync_ensure_tables($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS xui_sync_panel (
        panel_name VARCHAR(191) NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        reference_email VARCHAR(300) NULL,
        baseline_ids TEXT NULL,
        ref_ids TEXT NULL,
        ref_version INT UNSIGNED NOT NULL DEFAULT 0,
        ref_validated_at BIGINT NOT NULL DEFAULT 0,
        pending_empty_at BIGINT NULL,
        last_check_at BIGINT NOT NULL DEFAULT 0,
        last_status VARCHAR(80) NULL,
        last_error VARCHAR(500) NULL,
        migration_rev INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hasRev = $pdo->query("SHOW COLUMNS FROM xui_sync_panel LIKE 'migration_rev'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasRev) {
        $pdo->exec('ALTER TABLE xui_sync_panel ADD COLUMN migration_rev INT UNSIGNED NOT NULL DEFAULT 0');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS xui_sync_service (
        svc_key CHAR(40) NOT NULL PRIMARY KEY,
        panel_name VARCHAR(191) NOT NULL,
        email VARCHAR(300) NOT NULL,
        id_invoice VARCHAR(200) NULL,
        state VARCHAR(20) NOT NULL DEFAULT 'unclassified',
        managed_ids TEXT NULL,
        synced_version INT UNSIGNED NOT NULL DEFAULT 0,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at BIGINT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        ownership_source VARCHAR(40) NULL,
        review_reason VARCHAR(255) NULL,
        updated_at BIGINT NOT NULL DEFAULT 0,
        KEY idx_xsync_queue (panel_name, state, next_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function xsync_svc_key($panelName, $email)
{
    return sha1((string) $panelName . '|' . (string) $email);
}

function xsync_panel_state($pdo, $panelName)
{
    $stmt = $pdo->prepare('SELECT * FROM xui_sync_panel WHERE panel_name = ?');
    $stmt->execute(array($panelName));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function xsync_is_enabled($panelName)
{
    $pdo = xsync_pdo();
    if (!$pdo) {
        return false;
    }
    try {
        xsync_ensure_tables($pdo);
        $row = xsync_panel_state($pdo, $panelName);
        return is_array($row) && (int) $row['enabled'] === 1;
    } catch (\Throwable $e) {
        xcd_report('xsync_is_enabled failed: ' . $e->getMessage());
        return false;
    }
}

function xsync_enable_panel($panelName, $referenceEmail, $referenceIds, $previousDefaultRaw)
{
    $pdo = xsync_pdo();
    if (!$pdo) {
        return false;
    }
    xsync_ensure_tables($pdo);
    xcd_register_secret($referenceEmail);
    $ctx = &xsync_ctx();
    $ctx['panel'] = $panelName;
    $ids = xsync_ids($referenceIds);
    $existing = xsync_panel_state($pdo, $panelName);
    $now = time();
    if (!is_array($existing)) {
        $baseline = xsync_ids(xcd_parse_ids($previousDefaultRaw));
        $stmt = $pdo->prepare('INSERT INTO xui_sync_panel (panel_name, enabled, reference_email, baseline_ids, ref_ids, ref_version, ref_validated_at, last_status, updated_at) VALUES (?, 1, ?, ?, ?, 1, ?, ?, ?)');
        $stmt->execute(array($panelName, $referenceEmail, json_encode($baseline), json_encode($ids), $now, 'enabled', $now));
        $ctx['version'] = 1;
        xsync_log('sync.enabled', array('reference_fp' => xcd_fp($referenceEmail, 10), 'baseline_ids' => $baseline, 'reference_ids' => $ids, 'new_installation' => true));
        return true;
    }
    $version = (int) $existing['ref_version'];
    $currentIds = xsync_decode_ids($existing['ref_ids']);
    $emailChanged = (string) $existing['reference_email'] !== (string) $referenceEmail;
    $idsChanged = ($currentIds !== $ids) || (int) $existing['enabled'] !== 1 || $emailChanged;
    if ($idsChanged) {
        $version++;
    }
    $stmt = $pdo->prepare('UPDATE xui_sync_panel SET enabled = 1, reference_email = ?, ref_ids = ?, ref_version = ?, ref_validated_at = ?, pending_empty_at = NULL, last_status = ?, last_error = NULL, updated_at = ? WHERE panel_name = ?');
    $stmt->execute(array($referenceEmail, json_encode($ids), $version, $now, 're-enabled', $now, $panelName));
    $ctx['version'] = $version;
    xsync_log('sync.enabled', array('reference_fp' => xcd_fp($referenceEmail, 10), 'reference_ids' => $ids, 'new_installation' => false, 'reference_changed' => $emailChanged, 'version_bumped' => $idsChanged));
    return true;
}

function xsync_record_creation($panelName, $email, $ids, $invoiceId = null)
{
    $pdo = xsync_pdo();
    if (!$pdo) {
        return false;
    }
    try {
        xsync_ensure_tables($pdo);
        $state = xsync_panel_state($pdo, $panelName);
        if (!is_array($state) || (int) $state['enabled'] !== 1) {
            return false;
        }
        xcd_register_secret($email);
        $ctx = &xsync_ctx();
        $ctx['panel'] = $panelName;
        $ctx['version'] = (int) $state['ref_version'];
        $ids = xsync_ids($ids);
        $current = xsync_decode_ids($state['ref_ids']);
        $version = ($ids === $current) ? (int) $state['ref_version'] : 0;
        $stmt = $pdo->prepare("INSERT INTO xui_sync_service (svc_key, panel_name, email, id_invoice, state, managed_ids, synced_version, ownership_source, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, ?, 'creation_record', ?) ON DUPLICATE KEY UPDATE managed_ids = VALUES(managed_ids), synced_version = VALUES(synced_version), state = 'pending', ownership_source = 'creation_record', review_reason = NULL, updated_at = VALUES(updated_at)");
        $stmt->execute(array(xsync_svc_key($panelName, $email), $panelName, $email, $invoiceId, json_encode($ids), $version, time()));
        xsync_log('service.creation_recorded', array('service_fp' => xcd_fp($email, 10), 'managed_ids' => $ids, 'synced_version' => $version));
        return true;
    } catch (\Throwable $e) {
        xcd_report('xsync_record_creation failed: ' . $e->getMessage());
        return false;
    }
}

function xsync_error_kind_from_response($resp)
{
    $status = is_array($resp) ? (int) ($resp['status'] ?? 0) : 0;
    if ($status === 401 || $status === 403) {
        return 'auth';
    }
    if ($status === 0) {
        return 'transport';
    }
    if ($status >= 500) {
        return 'http_5xx';
    }
    if ($status < 200 || $status >= 300) {
        return 'http_' . $status;
    }
    return null;
}

function xsync_fetch_client($panel, $email)
{
    $started = microtime(true);
    $resp = xui_api_token_request($panel, 'GET', '/panel/api/clients/get/' . rawurlencode($email), null, XSYNC_API_TIMEOUT, 'xsync_fetch_client', $email);
    $result = array('ok' => false, 'kind' => null, 'ids' => null, 'status' => is_array($resp) ? (int) ($resp['status'] ?? 0) : 0, 'ms' => (int) round((microtime(true) - $started) * 1000), 'msg' => null);
    $kind = xsync_error_kind_from_response($resp);
    if ($kind !== null) {
        $result['kind'] = $kind;
        $result['msg'] = !empty($resp['error']) ? xcd_sanitize_text($resp['error'], 160) : null;
        return $result;
    }
    $body = is_string($resp['body'] ?? null) ? $resp['body'] : null;
    $decoded = $body !== null ? json_decode($body, true) : null;
    if (!is_array($decoded)) {
        $result['kind'] = 'malformed';
        return $result;
    }
    if (($decoded['success'] ?? null) !== true) {
        $result['kind'] = 'rejected';
        $result['msg'] = isset($decoded['msg']) ? xcd_sanitize_text($decoded['msg'], 160) : null;
        return $result;
    }
    $obj = $decoded['obj'] ?? null;
    if (!is_array($obj)) {
        $result['kind'] = 'malformed';
        return $result;
    }
    $client = is_array($obj['client'] ?? null) ? $obj['client'] : $obj;
    $returnedEmail = $client['email'] ?? null;
    if (!is_string($returnedEmail) || strcasecmp($returnedEmail, (string) $email) !== 0) {
        $result['kind'] = 'identity_mismatch';
        return $result;
    }
    if (!array_key_exists('inboundIds', $obj) || !is_array($obj['inboundIds'])) {
        $result['kind'] = 'incomplete';
        return $result;
    }
    foreach ($obj['inboundIds'] as $id) {
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            $result['kind'] = 'malformed';
            return $result;
        }
        if ((int) $id <= 0) {
            $result['kind'] = 'malformed';
            return $result;
        }
    }
    $result['ok'] = true;
    $result['ids'] = xsync_ids($obj['inboundIds']);
    return $result;
}

function xsync_time_left()
{
    $ctx = &xsync_ctx();
    if ($ctx['deadline'] === null) {
        return true;
    }
    if (microtime(true) >= $ctx['deadline']) {
        return false;
    }
    if (function_exists('rx_cron_time_up') && rx_cron_time_up()) {
        return false;
    }
    return true;
}

function xsync_pause($seconds)
{
    if (XSYNC_SLEEP_ENABLED && $seconds > 0) {
        usleep((int) ($seconds * 1000000));
    }
}

function xsync_fetch_client_retry($panel, $email)
{
    $result = null;
    for ($attempt = 1; $attempt <= XSYNC_STEP_RETRIES; $attempt++) {
        $result = xsync_fetch_client($panel, $email);
        if ($result['ok']) {
            return $result;
        }
        $retryable = in_array($result['kind'], array('transport', 'http_5xx'), true);
        if (!$retryable || $attempt === XSYNC_STEP_RETRIES || !xsync_time_left()) {
            break;
        }
        xsync_pause($attempt);
    }
    return $result;
}

function xsync_mutate($panel, $email, $action, $ids)
{
    $started = microtime(true);
    $resp = xui_api_token_request($panel, 'POST', '/panel/api/clients/' . rawurlencode($email) . '/' . $action, array('inboundIds' => array_values($ids)), XSYNC_API_TIMEOUT, 'xsync_' . $action, $email);
    $body = is_array($resp) && is_string($resp['body'] ?? null) ? json_decode($resp['body'], true) : null;
    return array(
        'status' => is_array($resp) ? (int) ($resp['status'] ?? 0) : 0,
        'success_flag' => is_array($body) ? ($body['success'] ?? null) : null,
        'msg' => (is_array($body) && isset($body['msg'])) ? xcd_sanitize_text($body['msg'], 240) : ((is_array($resp) && !empty($resp['error'])) ? xcd_sanitize_text($resp['error'], 240) : null),
        'ms' => (int) round((microtime(true) - $started) * 1000),
    );
}

function xsync_update_service($pdo, $svcKey, $fields)
{
    $fields['updated_at'] = time();
    $sets = array();
    $values = array();
    foreach ($fields as $column => $value) {
        $sets[] = '`' . $column . '` = ?';
        $values[] = $value;
    }
    $values[] = $svcKey;
    $stmt = $pdo->prepare('UPDATE xui_sync_service SET ' . implode(', ', $sets) . ' WHERE svc_key = ?');
    $stmt->execute($values);
}

function xsync_backoff($attempts)
{
    return min(XSYNC_BACKOFF_CAP, 60 * (1 << min(6, max(0, (int) $attempts))));
}

function xsync_fail_service($pdo, $svc, $reason, $extra = array())
{
    $attempts = (int) $svc['attempts'] + 1;
    $next = time() + xsync_backoff($attempts);
    xsync_update_service($pdo, $svc['svc_key'], array(
        'state' => 'error',
        'attempts' => $attempts,
        'next_attempt_at' => $next,
        'last_error' => xcd_sanitize_text($reason, 400),
    ));
    xsync_log('service.failed', array_merge(array(
        'service_fp' => xcd_fp($svc['email'], 10),
        'invoice' => $svc['id_invoice'],
        'reason' => xcd_sanitize_text($reason, 200),
        'attempts' => $attempts,
        'next_attempt_in_s' => $next - time(),
    ), $extra), 'warn');
    return 'failed';
}

function xsync_current_desired($pdo, $panelName)
{
    $state = xsync_panel_state($pdo, $panelName);
    if (!is_array($state) || (int) $state['enabled'] !== 1 || $state['ref_ids'] === null) {
        return null;
    }
    return array('version' => (int) $state['ref_version'], 'ids' => xsync_decode_ids($state['ref_ids']), 'reference_email' => (string) $state['reference_email'], 'baseline' => xsync_decode_ids($state['baseline_ids']));
}

function xsync_product_explicit($pdo, $panelName, $productName)
{
    if ($productName === null || $productName === '') {
        return array('explicit' => false, 'rows' => 0);
    }
    $stmt = $pdo->prepare('SELECT Location, inbounds FROM product WHERE name_product = ?');
    $stmt->execute(array($productName));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matched = 0;
    $explicit = false;
    foreach (is_array($rows) ? $rows : array() as $row) {
        $locations = array_map('trim', explode(',', (string) ($row['Location'] ?? '')));
        if (!in_array($panelName, $locations, true) && !in_array('/all', $locations, true)) {
            continue;
        }
        $matched++;
        $raw = $row['inbounds'] ?? null;
        if ($raw !== null && trim((string) $raw) !== '') {
            $explicit = true;
        }
    }
    return array('explicit' => $explicit, 'rows' => $matched);
}

function xsync_classify_service($pdo, $panel, $svc, $desired)
{
    $email = $svc['email'];
    $panelName = $svc['panel_name'];
    if (strcasecmp($email, $desired['reference_email']) === 0) {
        xsync_update_service($pdo, $svc['svc_key'], array('state' => 'excluded', 'review_reason' => 'reference_client'));
        xsync_log('service.classified', array('service_fp' => xcd_fp($email, 10), 'invoice' => $svc['id_invoice'], 'result' => 'excluded', 'reason' => 'reference_client'));
        return 'excluded';
    }
    $product = xsync_product_explicit($pdo, $panelName, $svc['product_name'] ?? null);
    if ($product['explicit']) {
        xsync_update_service($pdo, $svc['svc_key'], array('state' => 'excluded', 'review_reason' => 'product_explicit_inbounds'));
        xsync_log('service.classified', array('service_fp' => xcd_fp($email, 10), 'invoice' => $svc['id_invoice'], 'result' => 'excluded', 'reason' => 'product_explicit_inbounds'));
        return 'excluded';
    }
    $read = xsync_fetch_client_retry($panel, $email);
    if (!$read['ok']) {
        return xsync_fail_service($pdo, $svc, 'classification_read_' . $read['kind'], array('http_status' => $read['status']));
    }
    $actual = $read['ids'];
    $baseline = $desired['baseline'];
    $managed = xsync_ids(array_intersect($actual, $baseline));
    $unowned = array_values(array_diff($actual, $managed));
    $source = ($actual === $baseline && !empty($actual)) ? 'migration_exact_baseline' : 'migration_baseline_overlap';
    xsync_update_service($pdo, $svc['svc_key'], array('state' => 'pending', 'managed_ids' => json_encode($managed), 'ownership_source' => $source, 'review_reason' => null, 'attempts' => 0, 'next_attempt_at' => 0, 'last_error' => null));
    xsync_log('service.classified', array('service_fp' => xcd_fp($email, 10), 'invoice' => $svc['id_invoice'], 'result' => 'managed', 'ownership_source' => $source, 'actual_ids' => $actual, 'baseline_ids' => $baseline, 'managed_ids' => $managed, 'unowned_ids_preserved' => $unowned, 'product_rows_matched' => $product['rows']));
    return 'managed';
}

function xsync_reconcile_service($pdo, $panel, $svc, $desired)
{
    $email = $svc['email'];
    $panelName = $svc['panel_name'];
    $version = $desired['version'];
    $target = $desired['ids'];
    $managed = xsync_decode_ids($svc['managed_ids']);
    $svcLog = array('service_fp' => xcd_fp($email, 10), 'invoice' => $svc['id_invoice']);

    $read = xsync_fetch_client_retry($panel, $email);
    if (!$read['ok']) {
        return xsync_fail_service($pdo, $svc, 'read_' . $read['kind'], array('http_status' => $read['status']));
    }
    $actual = $read['ids'];
    $toAdd = array_values(array_diff($target, $actual));
    $toRemove = array_values(array_intersect(array_diff($managed, $target), $actual));
    xsync_log('service.plan', array_merge($svcLog, array(
        'previous_managed_ids' => $managed,
        'actual_ids' => $actual,
        'desired_ids' => $target,
        'to_add' => $toAdd,
        'to_remove' => $toRemove,
    )));

    $attachedByUs = array();
    $addPending = $toAdd;
    for ($try = 1; !empty($addPending) && $try <= XSYNC_STEP_RETRIES; $try++) {
        $latest = xsync_current_desired($pdo, $panelName);
        if ($latest === null || $latest['version'] !== $version) {
            xsync_log('service.superseded', array_merge($svcLog, array('phase' => 'attach', 'newer_version' => $latest['version'] ?? null)));
            return 'superseded';
        }
        $outcome = xsync_mutate($panel, $email, 'attach', $addPending);
        $verify = xsync_fetch_client_retry($panel, $email);
        if (!$verify['ok']) {
            xsync_log('service.attach', array_merge($svcLog, array('try' => $try, 'requested' => $addPending, 'api' => $outcome, 'verify' => 'read_' . $verify['kind'])), 'warn');
            return xsync_fail_service($pdo, $svc, 'verify_after_attach_' . $verify['kind'], array('requested' => $addPending));
        }
        $actual = $verify['ids'];
        $done = array_values(array_intersect($addPending, $actual));
        $attachedByUs = array_values(array_unique(array_merge($attachedByUs, $done)));
        $addPending = array_values(array_diff($addPending, $actual));
        xsync_log('service.attach', array_merge($svcLog, array('try' => $try, 'requested' => array_values(array_merge($done, $addPending)), 'api' => $outcome, 'verified_present' => $done, 'remaining' => $addPending, 'actual_after' => $actual)), empty($addPending) ? 'info' : 'warn');
        if (!empty($addPending) && $try < XSYNC_STEP_RETRIES && xsync_time_left()) {
            xsync_pause($try);
        } elseif (!empty($addPending)) {
            break;
        }
    }
    $managedNow = xsync_ids(array_intersect(array_merge($managed, $attachedByUs), $actual));
    if (!empty($addPending)) {
        xsync_update_service($pdo, $svc['svc_key'], array('managed_ids' => json_encode($managedNow)));
        xsync_log('service.removals_deferred', array_merge($svcLog, array('reason' => 'attach_incomplete', 'failed_adds' => $addPending, 'deferred_removals' => $toRemove)), 'warn');
        return xsync_fail_service($pdo, $svc, 'attach_incomplete', array('failed_adds' => $addPending));
    }
    if (!empty($attachedByUs)) {
        xsync_update_service($pdo, $svc['svc_key'], array('managed_ids' => json_encode($managedNow)));
    }

    $latest = xsync_current_desired($pdo, $panelName);
    if ($latest === null || $latest['version'] !== $version) {
        xsync_log('service.superseded', array_merge($svcLog, array('phase' => 'before_detach', 'newer_version' => $latest['version'] ?? null)));
        return 'superseded';
    }
    $removePending = array_values(array_intersect(array_diff($managedNow, $target), $actual));
    $removedOk = array();
    for ($try = 1; !empty($removePending) && $try <= XSYNC_STEP_RETRIES; $try++) {
        $outcome = xsync_mutate($panel, $email, 'detach', $removePending);
        $verify = xsync_fetch_client_retry($panel, $email);
        if (!$verify['ok']) {
            xsync_log('service.detach', array_merge($svcLog, array('try' => $try, 'requested' => $removePending, 'api' => $outcome, 'verify' => 'read_' . $verify['kind'])), 'warn');
            return xsync_fail_service($pdo, $svc, 'verify_after_detach_' . $verify['kind'], array('requested' => $removePending));
        }
        $actual = $verify['ids'];
        $gone = array_values(array_diff($removePending, $actual));
        $removedOk = array_values(array_unique(array_merge($removedOk, $gone)));
        $removePending = array_values(array_intersect($removePending, $actual));
        xsync_log('service.detach', array_merge($svcLog, array('try' => $try, 'api' => $outcome, 'verified_absent' => $gone, 'remaining' => $removePending, 'actual_after' => $actual)), empty($removePending) ? 'info' : 'warn');
        if (!empty($removePending) && $try < XSYNC_STEP_RETRIES && xsync_time_left()) {
            xsync_pause($try);
        } elseif (!empty($removePending)) {
            break;
        }
    }
    $managedFinal = xsync_ids(array_intersect($managedNow, $actual));
    if (!empty($removePending)) {
        xsync_update_service($pdo, $svc['svc_key'], array('managed_ids' => json_encode($managedFinal)));
        return xsync_fail_service($pdo, $svc, 'detach_incomplete', array('failed_removals' => $removePending));
    }
    $missing = array_values(array_diff($target, $actual));
    $leftover = array_values(array_intersect(array_diff($managedFinal, $target), $actual));
    if (!empty($missing) || !empty($leftover)) {
        return xsync_fail_service($pdo, $svc, 'final_verification_mismatch', array('missing' => $missing, 'leftover' => $leftover));
    }
    xsync_update_service($pdo, $svc['svc_key'], array(
        'state' => 'synced',
        'managed_ids' => json_encode($managedFinal),
        'synced_version' => $version,
        'attempts' => 0,
        'next_attempt_at' => 0,
        'last_error' => null,
    ));
    xsync_log('service.synced', array_merge($svcLog, array('managed_ids' => $managedFinal, 'final_ids' => $actual, 'added' => $attachedByUs, 'removed' => $removedOk, 'synced_version' => $version)));
    return 'synced';
}

function xsync_check_reference($pdo, $panel, $state)
{
    $panelName = $state['panel_name'];
    $email = (string) $state['reference_email'];
    $now = time();
    $read = xsync_fetch_client_retry($panel, $email);
    $touch = $pdo->prepare('UPDATE xui_sync_panel SET last_check_at = ?, last_status = ?, last_error = ?, updated_at = ? WHERE panel_name = ?');
    if (!$read['ok']) {
        $touch->execute(array($now, 'reference_unavailable', 'reference_' . $read['kind'], $now, $panelName));
        xsync_log('reference.unavailable', array('reference_fp' => xcd_fp($email, 10), 'kind' => $read['kind'], 'http_status' => $read['status'], 'message' => $read['msg'], 'action' => 'no_changes_applied'), 'warn');
        return array('changed' => false, 'valid' => false);
    }
    $ids = $read['ids'];
    $snapshot = xsync_decode_ids($state['ref_ids']);
    $version = (int) $state['ref_version'];
    $ctx = &xsync_ctx();
    if ($ids === $snapshot) {
        $stmt = $pdo->prepare('UPDATE xui_sync_panel SET last_check_at = ?, last_status = ?, last_error = NULL, pending_empty_at = NULL, ref_validated_at = ?, updated_at = ? WHERE panel_name = ?');
        $stmt->execute(array($now, 'reference_unchanged', $now, $now, $panelName));
        xsync_log('reference.unchanged', array('reference_ids' => $ids));
        return array('changed' => false, 'valid' => true);
    }
    if (empty($ids)) {
        $pendingSince = $state['pending_empty_at'] !== null ? (int) $state['pending_empty_at'] : 0;
        if ($pendingSince === 0) {
            $stmt = $pdo->prepare('UPDATE xui_sync_panel SET pending_empty_at = ?, last_check_at = ?, last_status = ?, last_error = NULL, updated_at = ? WHERE panel_name = ?');
            $stmt->execute(array($now, $now, 'empty_reference_awaiting_confirmation', $now, $panelName));
            xsync_log('reference.empty_first_read', array('previous_ids' => $snapshot, 'action' => 'awaiting_second_read'), 'warn');
            return array('changed' => false, 'valid' => true);
        }
        if (($now - $pendingSince) < XSYNC_EMPTY_CONFIRM_DELAY) {
            $touch->execute(array($now, 'empty_reference_awaiting_confirmation', null, $now, $panelName));
            xsync_log('reference.empty_confirmation_too_soon', array('waited_s' => $now - $pendingSince, 'required_s' => XSYNC_EMPTY_CONFIRM_DELAY), 'warn');
            return array('changed' => false, 'valid' => true);
        }
    }
    $version++;
    $stmt = $pdo->prepare('UPDATE xui_sync_panel SET ref_ids = ?, ref_version = ?, ref_validated_at = ?, pending_empty_at = NULL, last_check_at = ?, last_status = ?, last_error = NULL, updated_at = ? WHERE panel_name = ?');
    $stmt->execute(array(json_encode($ids), $version, $now, $now, 'reference_changed', $now, $panelName));
    $ctx['version'] = $version;
    $defaultUpdated = false;
    if (!empty($ids)) {
        $csv = implode(',', $ids);
        if (function_exists('update')) {
            update('marzban_panel', 'inboundid', $csv, 'name_panel', $panelName);
        } else {
            $upd = $pdo->prepare('UPDATE marzban_panel SET inboundid = ? WHERE name_panel = ?');
            $upd->execute(array($csv, $panelName));
        }
        $defaultUpdated = true;
    }
    xsync_log('reference.changed', array(
        'previous_ids' => $snapshot,
        'new_ids' => $ids,
        'added' => array_values(array_diff($ids, $snapshot)),
        'removed' => array_values(array_diff($snapshot, $ids)),
        'previous_version' => $version - 1,
        'new_version' => $version,
        'new_service_default_updated' => $defaultUpdated,
        'new_service_default_note' => $defaultUpdated ? null : 'empty reference cannot be a creation default; default left unchanged',
    ), 'warn');
    return array('changed' => true, 'valid' => true);
}

function xsync_migrate_services($pdo, $panelName, $state)
{
    $requeuedReview = 0;
    if ((int) ($state['migration_rev'] ?? 0) < 2) {
        $stmt = $pdo->prepare("UPDATE xui_sync_service SET state = 'unclassified', review_reason = NULL, attempts = 0, next_attempt_at = 0, updated_at = ? WHERE panel_name = ? AND state = 'review'");
        $stmt->execute(array(time(), $panelName));
        $requeuedReview = $stmt->rowCount();
        $mark = $pdo->prepare('UPDATE xui_sync_panel SET migration_rev = 2 WHERE panel_name = ?');
        $mark->execute(array($panelName));
        xsync_log('migration.review_requeued', array('services' => $requeuedReview));
    }
    $stmt = $pdo->prepare("UPDATE xui_sync_service SET state = 'unclassified', review_reason = NULL, updated_at = ? WHERE panel_name = ? AND state = 'excluded' AND review_reason = 'reference_client' AND LOWER(email) <> LOWER(?)");
    $stmt->execute(array(time(), $panelName, (string) $state['reference_email']));
    if ($stmt->rowCount() > 0) {
        xsync_log('migration.former_reference_requeued', array('services' => $stmt->rowCount()));
    }
    return $requeuedReview;
}

function xsync_migrate_disabled_panels($pdo)
{
    $stmt = $pdo->query("SELECT * FROM xui_sync_panel WHERE enabled = 0 AND reference_email IS NOT NULL AND reference_email <> '' AND last_check_at < " . (time() - 600));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ctx = &xsync_ctx();
    foreach (is_array($rows) ? $rows : array() as $row) {
        $panelName = $row['panel_name'];
        $ctx['panel'] = $panelName;
        $ctx['version'] = (int) $row['ref_version'];
        $panel = xsync_load_panel($panelName);
        $now = time();
        $touch = $pdo->prepare('UPDATE xui_sync_panel SET last_check_at = ?, last_status = ?, updated_at = ? WHERE panel_name = ?');
        if (!is_array($panel) || ($panel['type'] ?? '') !== 'x-ui_single' || !xui_panel_uses_token($panel)) {
            $touch->execute(array($now, 'disabled_unsupported_panel', $now, $panelName));
            xsync_log('migration.disabled_panel_skipped', array('reason' => 'panel_missing_or_not_token_mode'), 'warn');
            continue;
        }
        xcd_register_secret($row['reference_email']);
        $read = xsync_fetch_client_retry($panel, (string) $row['reference_email']);
        if (!$read['ok'] || empty($read['ids'])) {
            $touch->execute(array($now, 'disabled_reference_unvalidated', $now, $panelName));
            xsync_log('migration.disabled_panel_skipped', array('reason' => $read['ok'] ? 'reference_has_no_inbounds' : 'reference_' . $read['kind'], 'reference_fp' => xcd_fp($row['reference_email'], 10)), 'warn');
            continue;
        }
        xsync_enable_panel($panelName, (string) $row['reference_email'], $read['ids'], '');
        $csv = implode(',', $read['ids']);
        if (function_exists('update')) {
            update('marzban_panel', 'inboundid', $csv, 'name_panel', $panelName);
        } else {
            $upd = $pdo->prepare('UPDATE marzban_panel SET inboundid = ? WHERE name_panel = ?');
            $upd->execute(array($csv, $panelName));
        }
        $ctx['version'] = (int) xsync_panel_state($pdo, $panelName)['ref_version'];
        xsync_log('migration.disabled_panel_reenabled', array('reference_ids' => $read['ids'], 'new_service_default_updated' => true));
    }
}

function xsync_discover($pdo, $panelName, $referenceEmail)
{
    $statuses = array('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'disabled', 'disablebyadmin');
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT i.id_invoice, i.username FROM invoice i WHERE i.Service_location = ? AND i.Status IN ($marks) AND i.username IS NOT NULL AND i.username <> '' AND NOT EXISTS (SELECT 1 FROM xui_sync_service s WHERE s.svc_key = SHA1(CONCAT(i.Service_location, '|', i.username))) ORDER BY i.id_invoice LIMIT " . (int) XSYNC_DISCOVER_BATCH;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(array($panelName), $statuses));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare("INSERT IGNORE INTO xui_sync_service (svc_key, panel_name, email, id_invoice, state, managed_ids, updated_at) VALUES (?, ?, ?, ?, 'unclassified', '[]', ?)");
    $count = 0;
    foreach (is_array($rows) ? $rows : array() as $row) {
        xcd_register_secret($row['username']);
        $insert->execute(array(xsync_svc_key($panelName, $row['username']), $panelName, $row['username'], $row['id_invoice'], time()));
        $count += $insert->rowCount();
    }
    if ($count > 0) {
        xsync_log('discover.queued', array('services_added' => $count));
    }
    return $count;
}

function xsync_lock_name($pdo, $panelName)
{
    return 'xsync_' . substr(sha1((string) ($GLOBALS['dbname'] ?? '') . '|' . $panelName), 0, 40);
}

function xsync_lock($pdo, $panelName)
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute(array(xsync_lock_name($pdo, $panelName)));
    return (int) $stmt->fetchColumn() === 1;
}

function xsync_unlock($pdo, $panelName)
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute(array(xsync_lock_name($pdo, $panelName)));
    $stmt->fetchColumn();
}

function xsync_load_panel($panelName)
{
    if (function_exists('select')) {
        $panel = select('marzban_panel', '*', 'name_panel', $panelName, 'select');
        return is_array($panel) ? $panel : null;
    }
    $pdo = xsync_pdo();
    $stmt = $pdo->prepare('SELECT * FROM marzban_panel WHERE name_panel = ?');
    $stmt->execute(array($panelName));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function xsync_run_panel($panelName)
{
    $pdo = xsync_pdo();
    $ctx = &xsync_ctx();
    $ctx['panel'] = $panelName;
    if (!xsync_lock($pdo, $panelName)) {
        xsync_log('run.skipped', array('reason' => 'another_run_holds_panel_lock'));
        return array('status' => 'locked');
    }
    $summary = array('status' => 'ok', 'synced' => 0, 'failed' => 0, 'review' => 0, 'managed' => 0, 'excluded' => 0, 'superseded' => 0);
    try {
        $state = xsync_panel_state($pdo, $panelName);
        $panel = xsync_load_panel($panelName);
        if (!is_array($state) || (int) $state['enabled'] !== 1 || !is_array($panel)) {
            xsync_log('run.skipped', array('reason' => 'not_enabled_or_panel_missing'));
            return array('status' => 'disabled');
        }
        $ctx['version'] = (int) $state['ref_version'];
        if (($panel['type'] ?? '') !== 'x-ui_single' || !xui_panel_uses_token($panel)) {
            $stmt = $pdo->prepare('UPDATE xui_sync_panel SET last_status = ?, last_check_at = ?, updated_at = ? WHERE panel_name = ?');
            $stmt->execute(array('unsupported_panel_mode', time(), time(), $panelName));
            xsync_log('run.skipped', array('reason' => 'panel_not_in_token_mode'), 'warn');
            return array('status' => 'unsupported');
        }
        xcd_register_secret($state['reference_email']);
        xsync_log('run.start', array('reference_fp' => xcd_fp($state['reference_email'], 10), 'reference_ids' => xsync_decode_ids($state['ref_ids'])));
        xsync_check_reference($pdo, $panel, $state);
        xsync_migrate_services($pdo, $panelName, $state);
        xsync_discover($pdo, $panelName, $state['reference_email']);
        $limit = (int) XSYNC_BATCH_SIZE;
        $stmt = $pdo->prepare("SELECT s.*, (SELECT i.name_product FROM invoice i WHERE i.id_invoice = s.id_invoice LIMIT 1) AS product_name FROM xui_sync_service s WHERE s.panel_name = ? AND s.next_attempt_at <= ? AND (s.state IN ('unclassified','pending','error') OR (s.state = 'synced' AND s.synced_version < ?)) ORDER BY s.next_attempt_at ASC, s.updated_at ASC LIMIT " . $limit);
        $latestState = xsync_panel_state($pdo, $panelName);
        $stmt->execute(array($panelName, time(), (int) $latestState['ref_version']));
        $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach (is_array($batch) ? $batch : array() as $svc) {
            if (!xsync_time_left()) {
                xsync_log('run.time_budget_reached', array('remaining_in_batch' => true), 'warn');
                break;
            }
            xcd_register_secret($svc['email']);
            $desired = xsync_current_desired($pdo, $panelName);
            if ($desired === null) {
                break;
            }
            $ctx['version'] = $desired['version'];
            try {
                if ($svc['state'] === 'unclassified') {
                    $outcome = xsync_classify_service($pdo, $panel, $svc, $desired);
                    if ($outcome === 'managed') {
                        $summary['managed']++;
                        $fresh = $pdo->prepare('SELECT * FROM xui_sync_service WHERE svc_key = ?');
                        $fresh->execute(array($svc['svc_key']));
                        $svc = $fresh->fetch(PDO::FETCH_ASSOC);
                        $outcome = xsync_reconcile_service($pdo, $panel, $svc, $desired);
                    }
                } else {
                    if (strcasecmp($svc['email'], $desired['reference_email']) === 0) {
                        xsync_update_service($pdo, $svc['svc_key'], array('state' => 'excluded', 'review_reason' => 'reference_client'));
                        $outcome = 'excluded';
                    } else {
                        $outcome = xsync_reconcile_service($pdo, $panel, $svc, $desired);
                    }
                }
            } catch (\Throwable $e) {
                $outcome = xsync_fail_service($pdo, $svc, 'exception: ' . $e->getMessage());
            }
            if (isset($summary[$outcome])) {
                $summary[$outcome]++;
            }
        }
    } catch (\Throwable $e) {
        $summary['status'] = 'exception';
        xsync_log('run.exception', array('message' => xcd_sanitize_text($e->getMessage(), 300)), 'error');
    } finally {
        xsync_unlock($pdo, $panelName);
    }
    $counts = $pdo->prepare("SELECT state, COUNT(*) AS c FROM xui_sync_service WHERE panel_name = ? GROUP BY state");
    $counts->execute(array($panelName));
    $byState = array();
    foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byState[$row['state']] = (int) $row['c'];
    }
    $summary['services_by_state'] = $byState;
    xsync_log('run.end', $summary);
    return $summary;
}

function xsync_run_all()
{
    $pdo = xsync_pdo();
    if (!$pdo) {
        return array();
    }
    xsync_reset_run();
    xsync_ensure_tables($pdo);
    $ctx = &xsync_ctx();
    $ctx['deadline'] = microtime(true) + XSYNC_TIME_BUDGET;
    try {
        xsync_migrate_disabled_panels($pdo);
    } catch (\Throwable $e) {
        xsync_log('migration.exception', array('message' => xcd_sanitize_text($e->getMessage(), 300)), 'error');
    }
    $ctx['panel'] = null;
    $ctx['version'] = null;
    $stmt = $pdo->query('SELECT panel_name FROM xui_sync_panel WHERE enabled = 1 ORDER BY panel_name');
    $results = array();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $panelName) {
        if (!xsync_time_left()) {
            break;
        }
        $results[$panelName] = xsync_run_panel($panelName);
    }
    return $results;
}
