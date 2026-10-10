<?php

if (!defined('RX_PF_LEASE_SECONDS')) {
    define('RX_PF_LEASE_SECONDS', 300);
}
if (!defined('RX_PF_EXPIRE_TOLERANCE')) {
    define('RX_PF_EXPIRE_TOLERANCE', 120);
}
if (!defined('RX_PF_MAX_ATTEMPTS')) {
    define('RX_PF_MAX_ATTEMPTS', 12);
}
if (!defined('RX_PF_GIB')) {
    define('RX_PF_GIB', 1073741824);
}

if (!function_exists('rx_pf_log')) {
    function rx_pf_log(string $event, string $message, array $ctx = []): void
    {
        if (function_exists('rx_log_event')) {
            try {
                rx_log_event($event, $message, $ctx);
                return;
            } catch (Throwable $e) {
            }
        }
        error_log('[' . $event . '] ' . $message . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}

if (!function_exists('rx_pf_json')) {
    function rx_pf_json($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? null : $json;
    }
}

if (!function_exists('rx_pf_decode')) {
    function rx_pf_decode($raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('rx_pf_int')) {
    function rx_pf_int($value): int
    {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return 0;
        }
        return (int) round((float) $value);
    }
}

if (!function_exists('rx_pf_operation_type')) {
    function rx_pf_operation_type($idInvoice): string
    {
        $head = explode('|', (string) $idInvoice)[0];
        return in_array($head, ['getconfigafterpay', 'getextenduser', 'getextravolumeuser', 'getextratimeuser'], true) ? $head : 'wallet';
    }
}

if (!function_exists('rx_pf_result')) {
    function rx_pf_result(string $status, string $reason = '', array $extra = []): array
    {
        return array_merge([
            'success' => in_array($status, ['completed', 'already_completed'], true),
            'completed' => $status === 'completed',
            'already_completed' => $status === 'already_completed',
            'retryable' => in_array($status, ['retryable', 'already_processing'], true),
            'requires_reconciliation' => $status === 'requires_reconciliation',
            'manual_review' => $status === 'manual_review',
            'status' => $status,
            'reason' => $reason,
            'panel_result' => null,
        ], $extra);
    }
}

if (!function_exists('rx_pf_table_ddl')) {
    function rx_pf_table_ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS payment_fulfillment (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_order VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            payment_report_id INT UNSIGNED NULL,
            operation_type VARCHAR(40) NOT NULL,
            state VARCHAR(20) NOT NULL,
            processing_token CHAR(32) NULL,
            lease_expires_at INT UNSIGNED NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            before_state MEDIUMTEXT NULL,
            target_state MEDIUMTEXT NULL,
            result_state MEDIUMTEXT NULL,
            last_error TEXT NULL,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            completed_at INT UNSIGNED NULL,
            UNIQUE KEY uniq_pf_id_order (id_order),
            KEY idx_pf_state_lease (state, lease_expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
}

if (!function_exists('rx_pf_ensure_schema')) {
    function rx_pf_ensure_schema(): bool
    {
        static $ready = false;
        global $pdo;
        if ($ready) {
            return true;
        }
        if (!($pdo instanceof PDO)) {
            return false;
        }
        try {
            $pdo->exec(rx_pf_table_ddl());
            $index = $pdo->query("SHOW INDEX FROM Payment_report WHERE Key_name = 'idx_pr_id_order'");
            if ($index !== false && $index->fetch(PDO::FETCH_ASSOC) === false) {
                $pdo->exec("ALTER TABLE Payment_report ADD INDEX idx_pr_id_order (id_order(191))");
            }
            $ready = true;
            return true;
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_SCHEMA_FAILED', $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rx_pf_wallet_ready')) {
    function rx_pf_wallet_ready(): bool
    {
        static $ready = false;
        global $pdo;
        if ($ready) {
            return true;
        }
        if (!($pdo instanceof PDO)) {
            return false;
        }
        try {
            $column = $pdo->query("SHOW COLUMNS FROM wallet_ledger LIKE 'fulfillment_key'");
            if ($column !== false && $column->fetch(PDO::FETCH_ASSOC) === false) {
                $pdo->exec("ALTER TABLE wallet_ledger ADD COLUMN fulfillment_key VARCHAR(191) NULL, ADD UNIQUE KEY uniq_wl_fulfillment_key (fulfillment_key)");
            }
            $ready = true;
            return true;
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_WALLET_SCHEMA_FAILED', $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rx_pf_clear_cache')) {
    function rx_pf_clear_cache(array $tables = ['Payment_report']): void
    {
        if (!function_exists('clearSelectCache')) {
            return;
        }
        foreach ($tables as $table) {
            try {
                clearSelectCache($table);
            } catch (Throwable $e) {
            }
        }
    }
}

if (!function_exists('rx_pf_tx')) {
    function rx_pf_tx(callable $fn)
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('pdo_unavailable');
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('nested_transaction');
        }
        $pdo->beginTransaction();
        try {
            $out = $fn($pdo);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackError) {
                }
            }
            throw $e;
        }
    }
}

if (!function_exists('rx_pf_lock_payment')) {
    function rx_pf_lock_payment(PDO $pdo, string $orderId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE id_order = ? ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('rx_pf_lock_record')) {
    function rx_pf_lock_record(PDO $pdo, string $orderId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM payment_fulfillment WHERE id_order = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('rx_pf_fetch')) {
    function rx_pf_fetch(string $orderId): ?array
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM payment_fulfillment WHERE id_order = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('rx_pf_write_claim')) {
    function rx_pf_write_claim(PDO $pdo, ?array $record, string $orderId, array $payment, string $state, string $token, int $now, bool $resetStates): void
    {
        $lease = $now + RX_PF_LEASE_SECONDS;
        $operation = rx_pf_operation_type($payment['id_invoice'] ?? '');
        $paymentId = isset($payment['id']) && is_numeric($payment['id']) ? (int) $payment['id'] : null;
        if ($record === null) {
            $stmt = $pdo->prepare(
                "INSERT INTO payment_fulfillment (id_order, payment_report_id, operation_type, state, processing_token, lease_expires_at, attempt_count, created_at, updated_at) "
                . "VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)"
            );
            $stmt->execute([$orderId, $paymentId, $operation, $state, $token, $lease, $now, $now]);
            return;
        }
        if ($resetStates) {
            $stmt = $pdo->prepare(
                "UPDATE payment_fulfillment SET payment_report_id = ?, operation_type = ?, state = ?, processing_token = ?, lease_expires_at = ?, "
                . "attempt_count = attempt_count + 1, before_state = NULL, target_state = NULL, result_state = NULL, last_error = NULL, updated_at = ? WHERE id_order = ?"
            );
            $stmt->execute([$paymentId, $operation, $state, $token, $lease, $now, $orderId]);
            return;
        }
        $stmt = $pdo->prepare(
            "UPDATE payment_fulfillment SET state = ?, processing_token = ?, lease_expires_at = ?, attempt_count = attempt_count + 1, updated_at = ? WHERE id_order = ?"
        );
        $stmt->execute([$state, $token, $lease, $now, $orderId]);
    }
}

if (!function_exists('rx_pf_new_token')) {
    function rx_pf_new_token(): string
    {
        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('rx_pf_valid_order_id')) {
    function rx_pf_valid_order_id(string $orderId): bool
    {
        return $orderId !== '' && strlen($orderId) <= 191;
    }
}

if (!function_exists('rx_pf_set_payment_status')) {
    function rx_pf_set_payment_status(PDO $pdo, string $orderId, string $status, array $fromStatuses, ?string $decNote = null): int
    {
        $placeholders = implode(', ', array_fill(0, count($fromStatuses), '?'));
        $sql = "UPDATE Payment_report SET payment_Status = ?" . ($decNote !== null ? ", dec_not_confirmed = ?" : "")
            . " WHERE id_order = ? AND payment_Status IN ($placeholders) AND COALESCE(direct_payment_done, 0) = 0";
        $params = [$status];
        if ($decNote !== null) {
            $params[] = $decNote;
        }
        $params[] = $orderId;
        foreach ($fromStatuses as $from) {
            $params[] = $from;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

if (!function_exists('rx_pf_mark_paid_done')) {
    function rx_pf_mark_paid_done(PDO $pdo, string $orderId): int
    {
        $stmt = $pdo->prepare(
            "UPDATE Payment_report SET direct_payment_done = 1, payment_Status = CASE WHEN payment_Status IN ('processing', 'reconciling') THEN 'paid' ELSE payment_Status END "
            . "WHERE id_order = ? AND (COALESCE(direct_payment_done, 0) = 0 OR payment_Status IN ('processing', 'reconciling'))"
        );
        $stmt->execute([$orderId]);
        return $stmt->rowCount();
    }
}

if (!function_exists('rx_pf_claim')) {
    function rx_pf_claim(string $orderId, array $opts = []): array
    {
        $from = array_values(array_map('strval', (array) ($opts['from'] ?? ['waiting'])));
        $to = (string) ($opts['to'] ?? 'processing');
        $decNote = array_key_exists('dec_not_confirmed', $opts) ? (string) $opts['dec_not_confirmed'] : null;
        $minAge = isset($opts['min_age']) ? (int) $opts['min_age'] : null;
        $maxAge = isset($opts['max_age']) ? (int) $opts['max_age'] : null;
        if (!rx_pf_valid_order_id($orderId)) {
            return ['status' => 'error', 'reason' => 'invalid_order_id'];
        }
        if (!rx_pf_ensure_schema()) {
            return ['status' => 'error', 'reason' => 'schema_unavailable'];
        }
        try {
            $out = rx_pf_tx(function (PDO $pdo) use ($orderId, $from, $to, $decNote, $minAge, $maxAge) {
                $now = time();
                $payment = rx_pf_lock_payment($pdo, $orderId);
                if ($payment === null) {
                    return ['status' => 'not_found'];
                }
                if ((int) ($payment['direct_payment_done'] ?? 0) === 1) {
                    return ['status' => 'already_completed', 'report' => $payment];
                }
                $record = rx_pf_lock_record($pdo, $orderId);
                if ($record !== null) {
                    $state = (string) $record['state'];
                    $leaseActive = (int) ($record['lease_expires_at'] ?? 0) > $now;
                    if ($state === 'completed') {
                        return ['status' => 'already_completed', 'report' => $payment];
                    }
                    if ($state === 'manual_review') {
                        return ['status' => 'manual_review', 'report' => $payment];
                    }
                    if ($state === 'applying' || $state === 'reconciling') {
                        return ['status' => $leaseActive ? 'busy' : 'requires_reconciliation', 'report' => $payment];
                    }
                    if ($state === 'claimed' && $leaseActive) {
                        return ['status' => 'busy', 'report' => $payment];
                    }
                }
                if (!in_array((string) ($payment['payment_Status'] ?? ''), $from, true)) {
                    return ['status' => 'not_eligible', 'reason' => 'status', 'report' => $payment];
                }
                if ($minAge !== null || $maxAge !== null) {
                    $atUpdated = (string) ($payment['at_updated'] ?? '');
                    $ts = $atUpdated === '' ? false : strtotime($atUpdated);
                    if ($ts === false) {
                        return ['status' => 'not_eligible', 'reason' => 'at_updated_invalid', 'report' => $payment];
                    }
                    $age = $now - $ts;
                    if ($minAge !== null && $age <= $minAge) {
                        return ['status' => 'not_eligible', 'reason' => 'delay_not_elapsed', 'report' => $payment];
                    }
                    if ($maxAge !== null && $age >= $maxAge) {
                        return ['status' => 'not_eligible', 'reason' => 'window_elapsed', 'report' => $payment];
                    }
                }
                $token = rx_pf_new_token();
                rx_pf_write_claim($pdo, $record, $orderId, $payment, 'claimed', $token, $now, true);
                $sql = "UPDATE Payment_report SET payment_Status = ?, at_updated = ?" . ($decNote !== null ? ", dec_not_confirmed = ?" : "")
                    . " WHERE id_order = ? AND payment_Status = ? AND COALESCE(direct_payment_done, 0) = 0";
                $params = [$to, date('Y/m/d H:i:s', $now)];
                if ($decNote !== null) {
                    $params[] = $decNote;
                }
                $params[] = $orderId;
                $params[] = (string) $payment['payment_Status'];
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('claim_status_update_failed');
                }
                $payment['payment_Status'] = $to;
                return ['status' => 'claimed', 'token' => $token, 'report' => $payment];
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_CLAIM_FAILED', $e->getMessage(), ['id_order' => $orderId]);
            return ['status' => 'error', 'reason' => 'db_error', 'error' => $e->getMessage()];
        }
        rx_pf_clear_cache();
        return $out;
    }
}

if (!function_exists('rx_pf_begin')) {
    function rx_pf_begin(string $orderId, ?string $token): array
    {
        $deny = function (array $result): array {
            return ['proceed' => false, 'result' => $result];
        };
        if (!rx_pf_valid_order_id($orderId)) {
            return $deny(rx_pf_result('failed', 'invalid_order_id'));
        }
        if (!rx_pf_ensure_schema()) {
            return $deny(rx_pf_result('retryable', 'fulfillment_schema_unavailable'));
        }
        try {
            $out = rx_pf_tx(function (PDO $pdo) use ($orderId, $token, $deny) {
                $now = time();
                $payment = rx_pf_lock_payment($pdo, $orderId);
                if ($payment === null) {
                    return $deny(rx_pf_result('failed', 'payment_not_found'));
                }
                if ((int) ($payment['direct_payment_done'] ?? 0) === 1) {
                    return $deny(rx_pf_result('already_completed', 'direct_payment_done'));
                }
                $record = rx_pf_lock_record($pdo, $orderId);
                $state = $record !== null ? (string) $record['state'] : '';
                $leaseActive = $record !== null && (int) ($record['lease_expires_at'] ?? 0) > $now;
                if ($state === 'completed') {
                    return $deny(rx_pf_result('already_completed', 'fulfillment_completed'));
                }
                if ($state === 'manual_review') {
                    return $deny(rx_pf_result('manual_review', 'fulfillment_manual_review'));
                }
                if ($token !== null && $token !== '') {
                    if ($record === null || !hash_equals((string) ($record['processing_token'] ?? ''), $token)) {
                        return $deny(rx_pf_result('already_processing', 'not_owner'));
                    }
                    if ($state !== 'claimed' && $state !== 'reconciling') {
                        return $deny(rx_pf_result('already_processing', 'state_' . $state));
                    }
                    $stmt = $pdo->prepare("UPDATE payment_fulfillment SET lease_expires_at = ?, updated_at = ? WHERE id_order = ?");
                    $stmt->execute([$now + RX_PF_LEASE_SECONDS, $now, $orderId]);
                    return ['proceed' => true, 'phase' => $state === 'claimed' ? 'fresh' : 'reconcile', 'token' => $token, 'record' => $record];
                }
                if ($leaseActive && in_array($state, ['claimed', 'applying', 'reconciling'], true)) {
                    return $deny(rx_pf_result('already_processing', 'lease_active'));
                }
                $newToken = rx_pf_new_token();
                if ($state === 'applying' || $state === 'reconciling') {
                    if ((int) ($record['attempt_count'] ?? 0) >= RX_PF_MAX_ATTEMPTS) {
                        $stmt = $pdo->prepare("UPDATE payment_fulfillment SET state = 'manual_review', last_error = ?, lease_expires_at = NULL, updated_at = ? WHERE id_order = ?");
                        $stmt->execute(['max_attempts_exceeded', $now, $orderId]);
                        return $deny(rx_pf_result('manual_review', 'max_attempts_exceeded'));
                    }
                    rx_pf_write_claim($pdo, $record, $orderId, $payment, 'reconciling', $newToken, $now, false);
                    $record['state'] = 'reconciling';
                    $record['processing_token'] = $newToken;
                    return ['proceed' => true, 'phase' => 'reconcile', 'token' => $newToken, 'record' => $record];
                }
                rx_pf_write_claim($pdo, $record, $orderId, $payment, 'claimed', $newToken, $now, true);
                return ['proceed' => true, 'phase' => 'fresh', 'token' => $newToken, 'record' => ['state' => 'claimed', 'processing_token' => $newToken]];
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_BEGIN_FAILED', $e->getMessage(), ['id_order' => $orderId]);
            return $deny(rx_pf_result('retryable', 'fulfillment_claim_failed'));
        }
        return $out;
    }
}

if (!function_exists('rx_pf_prepare')) {
    function rx_pf_prepare(string $orderId, string $token, ?array $before, ?array $target): bool
    {
        global $pdo;
        try {
            $now = time();
            $stmt = $pdo->prepare(
                "UPDATE payment_fulfillment SET state = 'applying', before_state = ?, target_state = ?, lease_expires_at = ?, updated_at = ? "
                . "WHERE id_order = ? AND processing_token = ? AND state = 'claimed'"
            );
            $stmt->execute([rx_pf_json($before), rx_pf_json($target), $now + RX_PF_LEASE_SECONDS, $now, $orderId, $token]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_PREPARE_FAILED', $e->getMessage(), ['id_order' => $orderId]);
            return false;
        }
    }
}

if (!function_exists('rx_pf_mark_applying')) {
    function rx_pf_mark_applying(string $orderId, string $token): bool
    {
        global $pdo;
        try {
            $now = time();
            $stmt = $pdo->prepare(
                "UPDATE payment_fulfillment SET state = 'applying', lease_expires_at = ?, updated_at = ? WHERE id_order = ? AND processing_token = ? AND state = 'reconciling'"
            );
            $stmt->execute([$now + RX_PF_LEASE_SECONDS, $now, $orderId, $token]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_APPLY_MARK_FAILED', $e->getMessage(), ['id_order' => $orderId]);
            return false;
        }
    }
}

if (!function_exists('rx_pf_complete')) {
    function rx_pf_complete(string $orderId, string $token, $resultState = null, array $statements = []): string
    {
        try {
            $status = rx_pf_tx(function (PDO $pdo) use ($orderId, $token, $resultState, $statements) {
                $now = time();
                $record = rx_pf_lock_record($pdo, $orderId);
                if ($record === null) {
                    return 'missing';
                }
                if ((string) $record['state'] === 'completed') {
                    return 'already_completed';
                }
                if (!hash_equals((string) ($record['processing_token'] ?? ''), $token)) {
                    return 'not_owner';
                }
                if (!in_array((string) $record['state'], ['claimed', 'applying', 'reconciling'], true)) {
                    return 'invalid_state';
                }
                $payment = rx_pf_lock_payment($pdo, $orderId);
                if ($payment === null) {
                    return 'missing';
                }
                $alreadyDone = (int) ($payment['direct_payment_done'] ?? 0) === 1;
                if (!$alreadyDone) {
                    foreach ($statements as $statement) {
                        $stmt = $pdo->prepare((string) $statement[0]);
                        $stmt->execute(array_values((array) ($statement[1] ?? [])));
                    }
                }
                $stmt = $pdo->prepare(
                    "UPDATE payment_fulfillment SET state = 'completed', result_state = ?, lease_expires_at = NULL, completed_at = ?, updated_at = ? WHERE id_order = ?"
                );
                $stmt->execute([rx_pf_json($resultState), $now, $now, $orderId]);
                rx_pf_mark_paid_done($pdo, $orderId);
                return $alreadyDone ? 'already_completed' : 'completed';
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_COMPLETE_FAILED', $e->getMessage(), ['id_order' => $orderId]);
            return 'error';
        }
        rx_pf_clear_cache(['Payment_report', 'user', 'invoice']);
        return $status;
    }
}

if (!function_exists('rx_pf_transition')) {
    function rx_pf_transition(string $orderId, string $token, array $fromStates, string $toState, string $reason, ?string $paymentStatus, bool $renewLease, bool $countAttempt = false): string
    {
        try {
            $out = rx_pf_tx(function (PDO $pdo) use ($orderId, $token, $fromStates, $toState, $reason, $paymentStatus, $renewLease, $countAttempt) {
                $now = time();
                $record = rx_pf_lock_record($pdo, $orderId);
                if ($record === null) {
                    return 'missing';
                }
                if (!hash_equals((string) ($record['processing_token'] ?? ''), $token)) {
                    return 'not_owner';
                }
                if (!in_array((string) $record['state'], $fromStates, true)) {
                    return 'state_' . (string) $record['state'];
                }
                $targetState = $toState;
                if ($countAttempt && (int) ($record['attempt_count'] ?? 0) >= RX_PF_MAX_ATTEMPTS) {
                    $targetState = 'manual_review';
                    $paymentStatus = 'manual_review';
                    $reason = 'max_attempts_exceeded: ' . $reason;
                }
                $stmt = $pdo->prepare(
                    "UPDATE payment_fulfillment SET state = ?, last_error = ?, lease_expires_at = ?, attempt_count = attempt_count + ?, updated_at = ? WHERE id_order = ?"
                );
                $stmt->execute([
                    $targetState,
                    function_exists('mb_substr') ? mb_substr($reason, 0, 2000, 'UTF-8') : substr($reason, 0, 2000),
                    $renewLease && $targetState !== 'manual_review' ? $now + RX_PF_LEASE_SECONDS : null,
                    $countAttempt ? 1 : 0,
                    $now,
                    $orderId,
                ]);
                if ($paymentStatus !== null) {
                    rx_pf_set_payment_status($pdo, $orderId, $paymentStatus, ['processing', 'reconciling']);
                }
                return $targetState;
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_TRANSITION_FAILED', $e->getMessage(), ['id_order' => $orderId, 'to' => $toState]);
            return 'error';
        }
        rx_pf_clear_cache();
        return $out;
    }
}

if (!function_exists('rx_pf_alert_admin')) {
    function rx_pf_alert_admin(string $orderId, string $reason, array $ctx = []): void
    {
        try {
            if (!function_exists('select') || !function_exists('telegram')) {
                return;
            }
            $setting = select('setting', '*');
            $channel = is_array($setting) ? trim((string) ($setting['Channel_Report'] ?? '')) : '';
            if ($channel === '') {
                return;
            }
            $topic = select('topicid', 'idreport', 'report', 'errorreport', 'select');
            $thread = is_array($topic) ? ($topic['idreport'] ?? null) : null;
            $text = "⚠️ <b>پرداخت نیازمند بررسی دستی است</b>\n"
                . "<blockquote>🛒 کد پیگیری پرداخت: <code>" . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . "</code></blockquote>\n"
                . "<blockquote>✍️ دلیل: " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</blockquote>\n";
            if (!empty($ctx['username'])) {
                $text .= "<blockquote>🪪 نام کاربری سرویس: <code>" . htmlspecialchars((string) $ctx['username'], ENT_QUOTES, 'UTF-8') . "</code></blockquote>\n";
            }
            $text .= "ℹ️ وضعیت پنل قابل تشخیص قطعی نبود؛ عملیات پنل به‌صورت خودکار تکرار نمی‌شود.";
            telegram('sendmessage', [
                'chat_id' => $channel,
                'message_thread_id' => $thread,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_ALERT_FAILED', $e->getMessage(), ['id_order' => $orderId]);
        }
    }
}

if (!function_exists('rx_pf_manual_review')) {
    function rx_pf_manual_review(string $orderId, string $token, string $reason, array $ctx = []): array
    {
        $state = rx_pf_transition($orderId, $token, ['claimed', 'applying', 'reconciling'], 'manual_review', $reason, 'manual_review', false);
        rx_pf_log('PAYMENT_FULFILLMENT_MANUAL_REVIEW', $reason, array_merge(['id_order' => $orderId, 'transition' => $state], $ctx));
        if ($state === 'manual_review') {
            rx_pf_alert_admin($orderId, $reason, $ctx);
            return rx_pf_result('manual_review', $reason);
        }
        return rx_pf_result('requires_reconciliation', $reason . ' (' . $state . ')');
    }
}

if (!function_exists('rx_pf_reconcile_later')) {
    function rx_pf_reconcile_later(string $orderId, string $token, string $reason, bool $countAttempt = false): array
    {
        $state = rx_pf_transition($orderId, $token, ['claimed', 'applying', 'reconciling'], 'reconciling', $reason, 'reconciling', true, $countAttempt);
        rx_pf_log('PAYMENT_FULFILLMENT_RECONCILE_LATER', $reason, ['id_order' => $orderId, 'transition' => $state]);
        if ($state === 'manual_review') {
            rx_pf_alert_admin($orderId, $reason);
            return rx_pf_result('manual_review', $reason);
        }
        return rx_pf_result('requires_reconciliation', $reason);
    }
}

if (!function_exists('rx_pf_defer')) {
    function rx_pf_defer(string $orderId, string $token, string $reason): array
    {
        return rx_pf_reconcile_later($orderId, $token, $reason, true);
    }
}

if (!function_exists('rx_pf_abort')) {
    function rx_pf_abort(string $orderId, string $token, string $reason, bool $retryable, array $extra = []): array
    {
        $record = null;
        try {
            $record = rx_pf_fetch($orderId);
        } catch (Throwable $e) {
            $record = null;
        }
        if (is_array($record) && (string) $record['state'] === 'reconciling') {
            return $retryable ? rx_pf_defer($orderId, $token, $reason) : rx_pf_manual_review($orderId, $token, $reason);
        }
        rx_pf_transition($orderId, $token, ['claimed'], $retryable ? 'released' : 'failed', $reason, null, false);
        return rx_pf_result($retryable ? 'retryable' : 'failed', $reason, $extra);
    }
}

if (!function_exists('rx_pf_fail')) {
    function rx_pf_fail(string $orderId, string $token, string $reason, ?string $refundOutcome = null): array
    {
        rx_pf_transition($orderId, $token, ['claimed', 'applying', 'reconciling'], 'failed', $reason, null, false);
        return rx_pf_result($refundOutcome === 'refunded' ? 'refunded' : 'failed', $reason, ['refund' => $refundOutcome]);
    }
}

if (!function_exists('rx_pf_completion_outcome')) {
    function rx_pf_completion_outcome(string $orderId, string $token, string $completion): array
    {
        if ($completion === 'already_completed') {
            return rx_pf_result('already_completed', 'finalized_elsewhere');
        }
        if ($completion === 'not_owner' || $completion === 'invalid_state' || $completion === 'missing') {
            return rx_pf_result('already_processing', 'completion_' . $completion);
        }
        return rx_pf_reconcile_later($orderId, $token, 'finalize_failed_after_panel_success');
    }
}

if (!function_exists('rx_pf_settle')) {
    function rx_pf_settle(string $orderId, ?string $token, array $result, string $releaseTo = 'waiting', string $failedTo = 'waiting'): string
    {
        try {
            $out = rx_pf_tx(function (PDO $pdo) use ($orderId, $token, $releaseTo, $failedTo) {
                $now = time();
                $payment = rx_pf_lock_payment($pdo, $orderId);
                if ($payment === null) {
                    return 'missing';
                }
                $status = (string) ($payment['payment_Status'] ?? '');
                $record = rx_pf_lock_record($pdo, $orderId);
                if ((int) ($payment['direct_payment_done'] ?? 0) === 1) {
                    if (in_array($status, ['processing', 'reconciling'], true)) {
                        rx_pf_mark_paid_done($pdo, $orderId);
                        return 'finalized';
                    }
                    return 'paid';
                }
                if ($record === null) {
                    return 'missing';
                }
                $state = (string) $record['state'];
                if ($state === 'completed') {
                    rx_pf_mark_paid_done($pdo, $orderId);
                    return 'finalized';
                }
                if ($token !== null && !hash_equals((string) ($record['processing_token'] ?? ''), $token)) {
                    return 'not_owner';
                }
                if ($state === 'claimed' || $state === 'released') {
                    if ($state === 'claimed') {
                        $stmt = $pdo->prepare("UPDATE payment_fulfillment SET state = 'released', lease_expires_at = NULL, updated_at = ? WHERE id_order = ?");
                        $stmt->execute([$now, $orderId]);
                    }
                    rx_pf_set_payment_status($pdo, $orderId, $releaseTo, ['processing', 'reconciling']);
                    return 'released';
                }
                if ($state === 'failed') {
                    $reason = (string) ($record['last_error'] ?? '');
                    rx_pf_set_payment_status($pdo, $orderId, $failedTo, ['processing', 'reconciling'], $failedTo === 'reject' ? ($reason !== '' ? $reason : 'fulfillment_failed') : null);
                    return $failedTo === 'reject' ? 'rejected' : 'released';
                }
                if ($state === 'applying') {
                    $stmt = $pdo->prepare("UPDATE payment_fulfillment SET state = 'reconciling', lease_expires_at = ?, updated_at = ? WHERE id_order = ?");
                    $stmt->execute([$now + RX_PF_LEASE_SECONDS, $now, $orderId]);
                    rx_pf_set_payment_status($pdo, $orderId, 'reconciling', ['processing']);
                    return 'reconciling';
                }
                if ($state === 'reconciling') {
                    rx_pf_set_payment_status($pdo, $orderId, 'reconciling', ['processing']);
                    return 'reconciling';
                }
                if ($state === 'manual_review') {
                    rx_pf_set_payment_status($pdo, $orderId, 'manual_review', ['processing', 'reconciling']);
                    return 'manual_review';
                }
                return 'state_' . $state;
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_SETTLE_FAILED', $e->getMessage(), ['id_order' => $orderId, 'result' => $result['status'] ?? null]);
            return 'error';
        }
        rx_pf_clear_cache();
        return $out;
    }
}

if (!function_exists('rx_pf_recover')) {
    function rx_pf_recover(string $orderId): array
    {
        if (!rx_pf_valid_order_id($orderId)) {
            return ['status' => 'error', 'reason' => 'invalid_order_id'];
        }
        if (!rx_pf_ensure_schema()) {
            return ['status' => 'error', 'reason' => 'schema_unavailable'];
        }
        $alert = null;
        try {
            $out = rx_pf_tx(function (PDO $pdo) use ($orderId, &$alert) {
                $now = time();
                $payment = rx_pf_lock_payment($pdo, $orderId);
                if ($payment === null) {
                    return ['status' => 'skip'];
                }
                $status = (string) ($payment['payment_Status'] ?? '');
                if (!in_array($status, ['processing', 'reconciling'], true)) {
                    return ['status' => 'skip'];
                }
                if ((int) ($payment['direct_payment_done'] ?? 0) === 1) {
                    rx_pf_mark_paid_done($pdo, $orderId);
                    return ['status' => 'finalized', 'report' => $payment];
                }
                $record = rx_pf_lock_record($pdo, $orderId);
                if ($record === null) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO payment_fulfillment (id_order, payment_report_id, operation_type, state, attempt_count, last_error, created_at, updated_at) VALUES (?, ?, ?, 'manual_review', 0, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $orderId,
                        isset($payment['id']) && is_numeric($payment['id']) ? (int) $payment['id'] : null,
                        rx_pf_operation_type($payment['id_invoice'] ?? ''),
                        'processing_without_fulfillment_record',
                        $now,
                        $now,
                    ]);
                    rx_pf_set_payment_status($pdo, $orderId, 'manual_review', ['processing', 'reconciling']);
                    $alert = 'processing_without_fulfillment_record';
                    return ['status' => 'manual_review'];
                }
                $state = (string) $record['state'];
                if ($state === 'completed') {
                    rx_pf_mark_paid_done($pdo, $orderId);
                    return ['status' => 'finalized', 'report' => $payment];
                }
                if ($state === 'manual_review') {
                    rx_pf_set_payment_status($pdo, $orderId, 'manual_review', ['processing', 'reconciling']);
                    return ['status' => 'manual_review'];
                }
                if ((int) ($record['lease_expires_at'] ?? 0) > $now && in_array($state, ['claimed', 'applying', 'reconciling'], true)) {
                    return ['status' => 'busy'];
                }
                if ($state === 'released') {
                    rx_pf_set_payment_status($pdo, $orderId, 'waiting', ['processing', 'reconciling']);
                    return ['status' => 'released'];
                }
                if ($state === 'failed') {
                    $reason = (string) ($record['last_error'] ?? '');
                    rx_pf_set_payment_status($pdo, $orderId, 'reject', ['processing', 'reconciling'], $reason !== '' ? $reason : 'fulfillment_failed');
                    return ['status' => 'rejected'];
                }
                $token = rx_pf_new_token();
                if ($state === 'claimed') {
                    rx_pf_write_claim($pdo, $record, $orderId, $payment, 'claimed', $token, $now, true);
                    return ['status' => 'claimed', 'token' => $token, 'report' => $payment];
                }
                if ((int) ($record['attempt_count'] ?? 0) >= RX_PF_MAX_ATTEMPTS) {
                    $stmt = $pdo->prepare("UPDATE payment_fulfillment SET state = 'manual_review', last_error = ?, lease_expires_at = NULL, updated_at = ? WHERE id_order = ?");
                    $stmt->execute(['max_attempts_exceeded', $now, $orderId]);
                    rx_pf_set_payment_status($pdo, $orderId, 'manual_review', ['processing', 'reconciling']);
                    $alert = 'max_attempts_exceeded';
                    return ['status' => 'manual_review'];
                }
                rx_pf_write_claim($pdo, $record, $orderId, $payment, 'reconciling', $token, $now, false);
                rx_pf_set_payment_status($pdo, $orderId, 'reconciling', ['processing']);
                return ['status' => 'reconcile', 'token' => $token, 'report' => $payment];
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_RECOVER_FAILED', $e->getMessage(), ['id_order' => $orderId]);
            return ['status' => 'error', 'reason' => 'db_error'];
        }
        rx_pf_clear_cache();
        if ($alert !== null) {
            rx_pf_log('PAYMENT_FULFILLMENT_MANUAL_REVIEW', $alert, ['id_order' => $orderId]);
            rx_pf_alert_admin($orderId, $alert);
        }
        return $out;
    }
}

if (!function_exists('rx_pf_run_claimed')) {
    function rx_pf_run_claimed(string $orderId, string $token, string $image, string $failedTo): array
    {
        $result = null;
        try {
            $result = DirectPayment($orderId, $image, $token);
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_DIRECT_PAYMENT_THROWABLE', $e->getMessage(), [
                'id_order' => $orderId,
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
        if (!is_array($result)) {
            $result = rx_pf_result('requires_reconciliation', 'direct_payment_invalid_result');
        }
        $settle = rx_pf_settle($orderId, $token, $result, 'waiting', $failedTo);
        return [
            'result' => $result,
            'settle' => $settle,
            'finalized' => !empty($result['completed']) || $settle === 'finalized',
        ];
    }
}

if (!function_exists('rx_pf_panel_reconcilable')) {
    function rx_pf_panel_reconcilable($panel): bool
    {
        if (!is_array($panel)) {
            return false;
        }
        if (function_exists('nmPanelNationalEnabled') && nmPanelNationalEnabled($panel)) {
            return false;
        }
        return in_array((string) ($panel['type'] ?? ''), ['marzban', 'pasarguard', 'x-ui_single', 'guard', 'remnawave', 'rebecca'], true);
    }
}

if (!function_exists('rx_pf_user_missing_message')) {
    function rx_pf_user_missing_message(string $message): bool
    {
        if ($message === '' || stripos($message, 'panel not found') !== false) {
            return false;
        }
        return (bool) preg_match('/user\s*(not\s*found|does\s*not\s*exist)|not\s*found|404/i', $message);
    }
}

if (!defined('RX_RN_USAGE_SLACK')) {
    define('RX_RN_USAGE_SLACK', 536870912);
}
if (!defined('RX_RN_TRAFFIC_TOLERANCE')) {
    define('RX_RN_TRAFFIC_TOLERANCE', 1048576);
}
if (!defined('RX_RN_FAR_FUTURE')) {
    define('RX_RN_FAR_FUTURE', 284014800);
}

if (!function_exists('rxRenewalTextDefaults')) {
    function rxRenewalTextDefaults(): array
    {
        return [
            'policy_carry_all' => 'حفظ حجم و زمان باقی‌مانده + افزودن حجم و زمان خریداری‌شده',
            'policy_reset_all' => 'ریست کامل حجم و زمان؛ فقط حجم خریداری‌شده و زمان خریداری‌شده از لحظه اجرا',
            'policy_reset_traffic_append_time' => 'ریست حجم (فقط حجم خریداری‌شده) + حفظ زمان باقی‌مانده و افزودن زمان خریداری‌شده',
            'policy_carry_traffic_reset_time' => 'حفظ حجم باقی‌مانده + افزودن حجم خریداری‌شده؛ زمان خریداری‌شده از لحظه اجرا شروع می‌شود',
            'policy_traffic_only' => 'فقط حجم: حجم باقی‌مانده + حجم خریداری‌شده؛ تاریخ انقضا تغییر نمی‌کند',
            'policy_time_only' => 'فقط زمان: زمان باقی‌مانده + زمان خریداری‌شده؛ حجم و مصرف تغییر نمی‌کند',
            'policy_short_carry_all' => 'حفظ حجم و زمان',
            'policy_short_reset_all' => 'ریست کامل حجم و زمان',
            'policy_short_reset_traffic_append_time' => 'ریست حجم، حفظ زمان',
            'policy_short_carry_traffic_reset_time' => 'حفظ حجم، ریست زمان',
            'policy_short_traffic_only' => 'فقط حجم',
            'policy_short_time_only' => 'فقط زمان',
            'policy_usage_reset_suffix' => ' (شمارنده مصرف صفر می‌شود)',
            'trigger_either' => 'اتمام حجم یا انقضای زمان (هرکدام زودتر)',
            'trigger_traffic' => 'فقط اتمام حجم',
            'trigger_expire' => 'فقط انقضای زمان',
            'trigger_date' => 'در تاریخ مشخص',
            'trigger_date_at' => 'در تاریخ {date}',
            'trigger_immediate' => 'اجرای فوری (بدون رزرو)',
            'trigger_unavailable_traffic' => 'حجم این سرویس نامحدود است و هرگز تمام نمی‌شود.',
            'trigger_unavailable_expire' => 'زمان این سرویس نامحدود است و هرگز منقضی نمی‌شود.',
            'trigger_unavailable_either' => 'حجم و زمان این سرویس هر دو نامحدود است؛ فقط فعال‌سازی در تاریخ مشخص یا اجرای فوری ممکن است.',
            'reason_admin_blocked' => '⛔️ این سرویس توسط مدیریت غیرفعال شده است و تمدید، این محدودیت را برطرف نمی‌کند. برای رفع محدودیت با پشتیبانی در ارتباط باشید؛ مبلغی کسر نشد.',
            'reason_pending_unsupported' => '⚠️ این سرویس هنوز در انتظار اولین اتصال است و این پنل امکان اعمال روش تمدید انتخاب‌شده روی مدت انتظار را ندارد. لطفاً ابتدا به سرویس متصل شوید و سپس تمدید کنید؛ مبلغی کسر نشد.',
            'reason_result_not_usable' => '⚠️ با این روش، سرویس پس از اجرا همچنان قابل استفاده نخواهد بود ({detail}). روش یا محصول دیگری انتخاب کنید؛ مبلغی کسر نشد.',
            'reason_result_detail_traffic' => 'حجم قابل استفاده باقی نمی‌ماند',
            'reason_result_detail_time' => 'سرویس منقضی باقی می‌ماند',
            'reason_invalid_purchase' => '❌ مقدار حجم یا زمان خریداری‌شده نامعتبر است.',
            'reason_unknown_method' => '❌ روش تمدید تنظیم‌شده برای این پنل نامعتبر است؛ لطفاً به مدیریت اطلاع دهید. مبلغی کسر نشد.',
            'reason_panel_unreachable' => '⚠️ اطلاعات فعلی سرویس از پنل دریافت نشد؛ پیش‌نمایش در دسترس نیست. هنگام تأیید، وضعیت سرویس دوباره از پنل خوانده می‌شود.',
            'reason_panel_state_unavailable' => '⚠️ وضعیت فعلی سرویس از پنل قابل خواندن نیست؛ لطفاً چند دقیقه بعد دوباره تلاش کنید. مبلغی کسر نشد.',
            'reason_user_missing' => '❌ این سرویس در پنل یافت نشد؛ لطفاً با پشتیبانی در ارتباط باشید.',
            'reason_renewal_in_progress' => '⏳ عملیات دیگری روی این سرویس در حال انجام است؛ چند لحظه بعد دوباره تلاش کنید. مبلغی کسر نشد.',
            'reason_queued_exists' => '❌ یک رزرو اشتراک برای این سرویس در انتظار فعال‌سازی است. مبلغی از حساب شما کسر نشد.',
            'reason_trigger_unavailable' => '❌ زمان فعال‌سازی انتخاب‌شده برای این سرویس در دسترس نیست: {detail}',
            'reason_trigger_date_required' => '❌ برای فعال‌سازی در تاریخ مشخص، ابتدا تاریخ فعال‌سازی را تعیین کنید.',
            'reason_trigger_date_invalid' => '❌ تاریخ فعال‌سازی نامعتبر است؛ تاریخ باید در آینده و حداکثر تا یک سال بعد باشد.',
            'reason_policy_invalid' => '❌ سیاست اجرای انتخاب‌شده نامعتبر است.',
            'reason_reservation_unsupported' => '❌ رزرو اشتراک برای این نوع سرویس پشتیبانی نمی‌شود.',
            'reason_generic' => '❌خطایی در تمدید سرویس رخ داده با پشتیبانی در ارتباط باشید',
            'pending_verification' => '⏳ درخواست تمدید به پنل ارسال شد اما نتیجه نهایی هنوز تأیید نشده است. وضعیت به‌صورت خودکار بررسی می‌شود و نیازی به پرداخت مجدد نیست؛ در صورت بروز مشکل، پشتیبانی پیگیری می‌کند.',
            'preview_title' => '🔎 پیش‌نمایش تمدید',
            'preview_policy' => '⚙️ روش تمدید : {label}',
            'preview_traffic_kept' => '🔋 حجم باقی‌مانده فعلی حفظ می‌شود',
            'preview_traffic_discarded' => '🗑 حجم باقی‌مانده فعلی حذف می‌شود و فقط حجم خریداری‌شده باقی می‌ماند',
            'preview_traffic_unchanged' => '📦 حجم کل و مصرف بدون تغییر می‌ماند',
            'preview_traffic_unlimited' => '♾ حجم سرویس نامحدود می‌ماند',
            'preview_time_kept' => '⏳ زمان باقی‌مانده فعلی حفظ می‌شود',
            'preview_time_discarded' => '🗑 زمان باقی‌مانده فعلی حذف می‌شود و زمان خریداری‌شده از لحظه اجرا شروع می‌شود',
            'preview_time_unchanged' => '📅 تاریخ انقضا بدون تغییر می‌ماند',
            'preview_time_unlimited' => '♾ زمان سرویس نامحدود می‌ماند',
            'preview_usage_reset' => '♻️ شمارنده مصرف : صفر می‌شود',
            'preview_usage_kept' => '♻️ شمارنده مصرف : حفظ می‌شود',
            'preview_usage_note' => 'ℹ️ صفر شدن شمارنده مصرف به معنی حذف حجم باقی‌مانده نیست؛ حجم باقی‌مانده به حجم کل جدید منتقل می‌شود.',
            'preview_current_expire' => '⏳ انقضای فعلی : {value}',
            'preview_new_expire' => '⏳ انقضای جدید : {value}',
            'preview_current_limit' => '📦 حجم کل فعلی : {value}',
            'preview_used' => '📉 حجم مصرف‌شده : {value}',
            'preview_current_remaining' => '🔋 حجم باقی‌مانده فعلی : {value}',
            'preview_purchased_time' => '➕ زمان خریداری‌شده : {value}',
            'preview_purchased_volume' => '➕ حجم خریداری‌شده : {value}',
            'preview_new_limit' => '📦 حجم کل جدید : {value}',
            'preview_new_remaining' => '🔋 حجم قابل استفاده جدید : {value}',
            'preview_pending' => 'ℹ️ سرویس در انتظار اولین اتصال است؛ مدت سرویس پس از اولین اتصال شروع می‌شود ({value}).',
            'preview_enable' => '✅ سرویس منقضی/تمام‌شده پس از تمدید دوباره فعال می‌شود',
            'reservation_title' => '📌 این خرید به‌صورت رزرو ثبت می‌شود و سرویس فعلی تا زمان فعال‌سازی تغییر نمی‌کند.',
            'reservation_trigger' => '⏱ زمان فعال‌سازی : {label}',
            'reservation_policy' => '⚙️ سیاست اجرا : {label}',
            'reservation_note' => 'ℹ️ حجم و زمان باقی‌مانده در لحظه فعال‌سازی محاسبه می‌شود؛ مصرف شما تا آن لحظه از بسته رزرو کم نمی‌شود.',
            'reservation_estimate' => '🔎 برآورد در صورت فعال‌سازی در همین لحظه:',
            'reservation_estimate_unavailable' => 'ℹ️ برآورد لحظه‌ای در دسترس نیست؛ مقادیر در زمان فعال‌سازی محاسبه می‌شود.',
            'reservation_settings_title' => '🗓 تنظیمات رزرو اشتراک',
            'reservation_settings_hint' => 'با دکمه‌های زیر زمان فعال‌سازی و سیاست اجرا را انتخاب کنید و سپس فاکتور را تأیید نمایید.',
            'reservation_btn_trigger' => '⏱ فعال‌سازی: {label}',
            'reservation_btn_policy' => '⚙️ سیاست: {label}',
            'reservation_btn_usage' => '♻️ صفر کردن شمارنده مصرف: {label}',
            'reservation_btn_date' => '📅 تعیین تاریخ فعال‌سازی',
            'reservation_yes' => 'بله',
            'reservation_no' => 'خیر',
            'reservation_date_prompt' => '📅 تعداد روز تا فعال‌سازی (مثلاً 10) یا تاریخ شمسی به شکل 1405/08/01 را ارسال کنید.',
            'reservation_date_saved' => '✅ تاریخ فعال‌سازی ثبت شد: {date}',
            'reservation_date_unset' => 'تعیین نشده',
            'status_pending' => 'در انتظار فعال‌سازی',
            'status_processing' => 'در حال اجرا',
            'status_retry' => 'خطای موقت؛ تلاش مجدد خودکار',
            'status_reconciling' => 'در حال بررسی نتیجه',
            'status_applied' => 'فعال شد',
            'status_failed' => 'نیازمند بررسی پشتیبانی',
            'status_cancelled' => 'لغو شده',
            'reservation_notice' => '📌 رزرو اشتراک: {status}\n⏱ زمان فعال‌سازی: {trigger}\n⚙️ سیاست اجرا: {policy}',
            'reservation_failed_user_tpl' => "⚠️ فعال‌سازی رزرو اشتراک سرویس {username} با مشکل مواجه شد و برای بررسی به پشتیبانی ارسال شد. بسته رزرو شما محفوظ است.",
            'reconciled_user_tpl' => '✅ تمدید سرویس {username} در پنل تأیید و اعمال شد.',
            'reservation_registered_tpl' => "✅ رزرو اشتراک ثبت شد.\n⏱ زمان فعال‌سازی: {trigger}\n⚙️ سیاست اجرا: {policy}",
            'result_line_expire' => '⏳ انقضا : {before} ← {after}',
            'result_line_limit' => '📦 حجم کل : {before} ← {after}',
            'result_line_remaining' => '🔋 حجم باقی‌مانده : {before} ← {after}',
            'unlimited' => 'نامحدود',
            'days_unit' => 'روز',
            'gb_unit' => 'گیگ',
        ];
    }
}

if (!function_exists('rxRenewalText')) {
    function rxRenewalText(string $key, array $vars = []): string
    {
        $defaults = rxRenewalTextDefaults();
        $default = (string) ($defaults[$key] ?? $key);
        $text = $default;
        if (function_exists('faoxima_textbot_get')) {
            try {
                $text = (string) faoxima_textbot_get('dyn_renewal_' . $key, $default);
            } catch (Throwable $e) {
                $text = $default;
            }
        }
        $text = str_replace('\n', "\n", $text);
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

if (!function_exists('rx_rn_panel_number')) {
    function rx_rn_panel_number(array $data, string $key, bool $nullIsZero)
    {
        if (!array_key_exists($key, $data)) {
            return false;
        }
        $value = $data[$key];
        if ($value === null) {
            return $nullIsZero ? 0 : false;
        }
        if (is_bool($value) || !is_numeric($value)) {
            return false;
        }
        return (int) round((float) $value);
    }
}

if (!function_exists('rx_rn_panel_type')) {
    function rx_rn_panel_type(string $namePanel): string
    {
        if ($namePanel === '' || !function_exists('select')) {
            return '';
        }
        try {
            $row = select('marzban_panel', 'type', 'name_panel', $namePanel, 'select');
        } catch (Throwable $e) {
            return '';
        }
        return is_array($row) ? (string) ($row['type'] ?? '') : '';
    }
}

if (!function_exists('rx_pf_snapshot_from_datauser')) {
    function rx_pf_snapshot_from_datauser($data, string $panelType = ''): array
    {
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'invalid_panel_response'];
        }
        if (($data['status'] ?? '') === 'Unsuccessful') {
            $msg = $data['msg'] ?? '';
            $msg = is_scalar($msg) ? (string) $msg : (string) json_encode($msg, JSON_UNESCAPED_UNICODE);
            if (rx_pf_user_missing_message($msg)) {
                return ['ok' => true, 'exists' => false, 'error' => $msg];
            }
            return ['ok' => false, 'error' => $msg !== '' ? $msg : 'panel_unsuccessful'];
        }
        if (!isset($data['username']) || (string) $data['username'] === '') {
            return ['ok' => false, 'error' => 'panel_response_without_username'];
        }
        $limit = rx_rn_panel_number($data, 'data_limit', true);
        $used = rx_rn_panel_number($data, 'used_traffic', false);
        $expire = rx_rn_panel_number($data, 'expire', true);
        if ($limit === false || $used === false || $expire === false || $limit < 0 || $expire < 0) {
            return ['ok' => false, 'error' => 'malformed_panel_values'];
        }
        if (in_array($panelType, ['remnawave', 'guard'], true) && $expire > time() + RX_RN_FAR_FUTURE) {
            $expire = 0;
        }
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $duration = null;
        if (isset($data['on_hold_expire_duration']) && is_numeric($data['on_hold_expire_duration']) && (int) $data['on_hold_expire_duration'] > 0) {
            $duration = (int) $data['on_hold_expire_duration'];
        }
        return [
            'ok' => true,
            'exists' => true,
            'username' => (string) $data['username'],
            'data_limit' => $limit,
            'expire' => $expire,
            'used_traffic' => max(0, $used),
            'status' => $status,
            'pending' => $status === 'on_hold',
            'pending_duration' => $duration,
            'raw' => $data,
        ];
    }
}

if (!function_exists('rx_pf_panel_snapshot')) {
    function rx_pf_panel_snapshot($managePanel, string $namePanel, string $username, string $panelType = ''): array
    {
        if (!is_object($managePanel)) {
            return ['ok' => false, 'error' => 'manage_panel_unavailable'];
        }
        try {
            $data = $managePanel->DataUser($namePanel, $username);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return rx_pf_snapshot_from_datauser($data, $panelType !== '' ? $panelType : rx_rn_panel_type($namePanel));
    }
}

if (!function_exists('rx_pf_limits')) {
    function rx_pf_limits(array $snapshot): array
    {
        return [
            'data_limit' => rx_pf_int($snapshot['data_limit'] ?? 0),
            'expire' => rx_pf_int($snapshot['expire'] ?? 0),
            'used_traffic' => rx_pf_int($snapshot['used_traffic'] ?? 0),
        ];
    }
}

if (!function_exists('rx_pf_state_matches')) {
    function rx_pf_state_matches(array $snapshot, array $expected): bool
    {
        if (!empty($expected['rx_renewal_plan'])) {
            return !empty(rxRenewalVerify($snapshot, $expected)['ok']);
        }
        if (array_key_exists('data_limit', $expected) && rx_pf_int($snapshot['data_limit'] ?? 0) !== rx_pf_int($expected['data_limit'])) {
            return false;
        }
        if (array_key_exists('expire', $expected)) {
            $current = rx_pf_int($snapshot['expire'] ?? 0);
            $wanted = rx_pf_int($expected['expire']);
            if ($wanted <= 0 || $current <= 0) {
                return $wanted <= 0 && $current <= 0;
            }
            if (abs($current - $wanted) > RX_PF_EXPIRE_TOLERANCE) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('rx_pf_classify')) {
    function rx_pf_classify(array $snapshot, ?array $before, ?array $target): string
    {
        if (is_array($target) && !empty($target['rx_renewal_plan'])) {
            return rxRenewalClassify($snapshot, $target);
        }
        if (empty($snapshot['ok'])) {
            return 'unknown';
        }
        if (empty($snapshot['exists']) || !is_array($before) || !is_array($target)) {
            return 'ambiguous';
        }
        $dims = array_values(array_intersect(['data_limit', 'expire'], array_keys($target)));
        if ($dims === []) {
            return 'ambiguous';
        }
        $wanted = array_intersect_key($target, array_flip($dims));
        $original = array_intersect_key($before, array_flip($dims));
        if (count($original) !== count($dims)) {
            return 'ambiguous';
        }
        $isTarget = rx_pf_state_matches($snapshot, $wanted);
        $isBefore = rx_pf_state_matches($snapshot, $original);
        if ($isTarget && !$isBefore) {
            return 'target';
        }
        if ($isBefore && !$isTarget) {
            return 'before';
        }
        return 'ambiguous';
    }
}

if (!function_exists('rxRenewalPolicies')) {
    function rxRenewalPolicies(): array
    {
        return [
            'carry_all' => ['traffic' => 'carry', 'time' => 'append'],
            'reset_all' => ['traffic' => 'reset', 'time' => 'reset'],
            'reset_traffic_append_time' => ['traffic' => 'reset', 'time' => 'append'],
            'carry_traffic_reset_time' => ['traffic' => 'carry', 'time' => 'reset'],
            'traffic_only' => ['traffic' => 'carry', 'time' => 'keep'],
            'time_only' => ['traffic' => 'keep', 'time' => 'append'],
        ];
    }
}

if (!function_exists('rxRenewalPolicyAllowsUsageReset')) {
    function rxRenewalPolicyAllowsUsageReset(string $policy): bool
    {
        $policies = rxRenewalPolicies();
        return isset($policies[$policy]) && $policies[$policy]['traffic'] === 'carry';
    }
}

if (!function_exists('rxRenewalModes')) {
    function rxRenewalModes(): array
    {
        return [
            'ریست حجم و زمان' => ['key' => 'reset_all', 'policy' => 'reset_all', 'reset_usage' => true, 'queued' => false],
            'اضافه شدن زمان و حجم به ماه بعد' => ['key' => 'carry_all', 'policy' => 'carry_all', 'reset_usage' => false, 'queued' => false],
            'ریست زمان و اضافه کردن حجم قبلی' => ['key' => 'carry_traffic_reset_time', 'policy' => 'carry_traffic_reset_time', 'reset_usage' => false, 'queued' => false],
            'ریست شدن حجم و اضافه شدن زمان' => ['key' => 'reset_traffic_append_time', 'policy' => 'reset_traffic_append_time', 'reset_usage' => true, 'queued' => false],
            'اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده' => ['key' => 'carry_all_reset_usage', 'policy' => 'carry_all', 'reset_usage' => true, 'queued' => false],
            'رزرو اشتراک' => ['key' => 'queued', 'policy' => null, 'reset_usage' => false, 'queued' => true],
        ];
    }
}

if (!function_exists('rxRenewalDefaultMethod')) {
    function rxRenewalDefaultMethod(): string
    {
        return 'ریست حجم و زمان';
    }
}

if (!function_exists('rxRenewalMode')) {
    function rxRenewalMode($method): ?array
    {
        $method = trim((string) $method);
        if ($method === '') {
            $method = rxRenewalDefaultMethod();
        }
        $modes = rxRenewalModes();
        if (!isset($modes[$method])) {
            return null;
        }
        return array_merge($modes[$method], ['method' => $method]);
    }
}

if (!function_exists('rxRenewalPolicyLabel')) {
    function rxRenewalPolicyLabel(string $policy, bool $resetUsage = false, bool $short = false): string
    {
        $label = rxRenewalText(($short ? 'policy_short_' : 'policy_') . $policy);
        if ($resetUsage && rxRenewalPolicyAllowsUsageReset($policy)) {
            $label .= rxRenewalText('policy_usage_reset_suffix');
        }
        return $label;
    }
}

if (!function_exists('rxRenewalModeLabel')) {
    function rxRenewalModeLabel(string $key): string
    {
        foreach (rxRenewalModes() as $mode) {
            if ($mode['key'] === $key && $mode['policy'] !== null) {
                return rxRenewalPolicyLabel($mode['policy'], !empty($mode['reset_usage']));
            }
        }
        return isset(rxRenewalPolicies()[$key]) ? rxRenewalPolicyLabel($key) : $key;
    }
}

if (!function_exists('rxRenewalIsCustomCode')) {
    function rxRenewalIsCustomCode($code): bool
    {
        return in_array(trim((string) $code), ['customvolume', 'custom_volume'], true);
    }
}

if (!function_exists('rxRenewalNormalizeProductCode')) {
    function rxRenewalNormalizeProductCode($code): string
    {
        return rxRenewalIsCustomCode($code) ? 'custom_volume' : trim((string) $code);
    }
}

if (!function_exists('rxRenewalPendingSupported')) {
    function rxRenewalPendingSupported($panelType): bool
    {
        return in_array((string) $panelType, ['marzban', 'pasarguard', 'rebecca', 'x-ui_single'], true);
    }
}

if (!function_exists('rxRenewalCompute')) {
    function rxRenewalCompute(int $now, string $policy, bool $resetUsageOption, array $before, $purchasedVolumeGb, $purchasedDays, array $ctx = []): array
    {
        $fail = function (string $reason, array $extra = []) use ($policy) {
            return array_merge([
                'ok' => false,
                'reason' => $reason,
                'policy' => $policy,
                'queued' => false,
                'manual_review' => false,
                'destructive' => false,
                'rx_renewal_plan' => 1,
            ], $extra);
        };
        $policies = rxRenewalPolicies();
        if (!isset($policies[$policy])) {
            return $fail('unknown_policy');
        }
        if (!is_numeric($purchasedVolumeGb) || !is_numeric($purchasedDays) || (float) $purchasedVolumeGb < 0 || (float) $purchasedDays < 0) {
            return $fail('invalid_purchase');
        }
        foreach (['data_limit', 'expire', 'used_traffic'] as $field) {
            if (!array_key_exists($field, $before) || $before[$field] === null || is_bool($before[$field]) || !is_numeric($before[$field])) {
                return $fail('panel_state_unavailable');
            }
        }
        $trafficMode = $policies[$policy]['traffic'];
        $timeMode = $policies[$policy]['time'];
        $volume = (float) $purchasedVolumeGb;
        $days = (float) $purchasedDays;
        $purchasedBytes = (int) round($volume * RX_PF_GIB);
        $purchasedSeconds = (int) round($days * 86400);
        $oldLimit = max(0, (int) round((float) $before['data_limit']));
        $oldExpire = max(0, (int) round((float) $before['expire']));
        $used = max(0, (int) round((float) $before['used_traffic']));
        $status = strtolower(trim((string) ($before['status'] ?? '')));
        $pending = !empty($before['pending']) || $status === 'on_hold';
        $pendingDuration = isset($before['pending_duration']) && is_numeric($before['pending_duration']) && (int) $before['pending_duration'] > 0 ? (int) $before['pending_duration'] : null;
        $unlimitedTraffic = $oldLimit <= 0;
        $unlimitedTime = !$pending && $oldExpire <= 0;
        $remaining = $unlimitedTraffic ? null : max(0, $oldLimit - $used);
        if ($status === 'disabled' || !empty($ctx['admin_blocked'])) {
            return $fail('admin_blocked');
        }

        $resetUsage = false;
        if ($trafficMode === 'keep') {
            $targetLimit = $oldLimit;
            $targetUsed = $used;
        } elseif ($volume == 0) {
            $resetUsage = $trafficMode === 'reset' || $resetUsageOption;
            $targetLimit = 0;
            $targetUsed = $resetUsage ? 0 : $used;
        } elseif ($trafficMode === 'reset') {
            $resetUsage = true;
            $targetLimit = $purchasedBytes;
            $targetUsed = 0;
        } elseif ($unlimitedTraffic) {
            $resetUsage = $resetUsageOption;
            $targetLimit = 0;
            $targetUsed = $resetUsage ? 0 : $used;
        } elseif ($resetUsageOption) {
            $resetUsage = true;
            $targetLimit = (int) $remaining + $purchasedBytes;
            $targetUsed = 0;
        } else {
            $targetLimit = $used + (int) $remaining + $purchasedBytes;
            $targetUsed = $used;
        }

        $targetPending = false;
        $targetPendingDuration = null;
        if ($timeMode === 'keep') {
            $targetExpire = $oldExpire;
            $targetPending = $pending;
            $targetPendingDuration = $pendingDuration;
        } elseif ($days == 0) {
            $targetExpire = 0;
        } elseif ($pending) {
            if (empty($ctx['pending_supported'])) {
                return $fail('pending_unsupported');
            }
            if ($timeMode === 'append' && $pendingDuration === null) {
                return $fail('pending_unsupported');
            }
            $targetPending = true;
            $targetExpire = 0;
            $targetPendingDuration = $timeMode === 'reset' ? $purchasedSeconds : (int) $pendingDuration + $purchasedSeconds;
        } elseif ($timeMode === 'reset') {
            $targetExpire = $now + $purchasedSeconds;
        } elseif ($unlimitedTime) {
            $targetExpire = 0;
        } else {
            $targetExpire = max($now, $oldExpire) + $purchasedSeconds;
        }

        $trafficUsable = $targetLimit <= 0 || ($targetLimit - $targetUsed) > 0;
        $timeUsable = $targetPending || $targetExpire <= 0 || $targetExpire > $now;
        if (!$trafficUsable || !$timeUsable) {
            return $fail('result_not_usable', ['unusable' => !$trafficUsable ? 'traffic' : 'time']);
        }
        if ($trafficMode === 'carry' && $targetLimit > 0 && ($targetLimit - $targetUsed) < (int) $remaining + $purchasedBytes) {
            return $fail('invariant_traffic_discarded');
        }
        if ($timeMode === 'append' && !$targetPending && $targetExpire > 0 && $oldExpire > $now && $targetExpire < $oldExpire + $purchasedSeconds) {
            return $fail('invariant_time_discarded');
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'rx_renewal_plan' => 1,
            'policy' => $policy,
            'method' => (string) ($ctx['method'] ?? ''),
            'effective_method' => $policy,
            'label' => rxRenewalPolicyLabel($policy, $resetUsage && $trafficMode === 'carry'),
            'traffic_mode' => $trafficMode,
            'time_mode' => $timeMode,
            'reset_usage_option' => $resetUsageOption,
            'queued' => false,
            'reset_usage' => $resetUsage,
            'destructive' => false,
            'manual_review' => false,
            'now' => $now,
            'before_status' => $status,
            'before_expire' => $oldExpire,
            'before_data_limit' => $oldLimit,
            'before_used_traffic' => $used,
            'before_remaining' => $remaining,
            'before_pending' => $pending,
            'before_pending_duration' => $pendingDuration,
            'purchased_days' => $days,
            'purchased_volume_gb' => $volume,
            'purchased_volume_bytes' => $purchasedBytes,
            'unlimited_expire' => $unlimitedTime,
            'unlimited_volume' => $unlimitedTraffic,
            'data_limit' => $targetLimit,
            'target_used_traffic' => $targetUsed,
            'target_remaining' => $targetLimit > 0 ? $targetLimit - $targetUsed : null,
            'expire' => $targetExpire,
            'target_pending' => $targetPending,
            'target_pending_duration' => $targetPendingDuration,
            'traffic_changed' => $targetLimit !== $oldLimit || $resetUsage,
            'time_changed' => $targetExpire !== $oldExpire || $targetPending !== $pending || $targetPendingDuration !== $pendingDuration,
            'enable' => !$targetPending && in_array($status, ['expired', 'limited'], true),
            'expect_active' => true,
        ];
    }
}

if (!function_exists('rxRenewalReplan')) {
    function rxRenewalReplan(array $plan, array $before, int $now, array $ctx = []): array
    {
        return rxRenewalCompute(
            $now,
            (string) ($plan['policy'] ?? ''),
            !empty($plan['reset_usage_option']),
            $before,
            $plan['purchased_volume_gb'] ?? (((int) ($plan['purchased_volume_bytes'] ?? 0)) / RX_PF_GIB),
            $plan['purchased_days'] ?? 0,
            array_merge(['method' => (string) ($plan['method'] ?? '')], $ctx)
        );
    }
}

if (!function_exists('rxBuildRenewalTarget')) {
    function rxBuildRenewalTarget(int $now, $method, array $before, $purchasedVolumeGb, $purchasedDays, array $ctx = []): array
    {
        $mode = rxRenewalMode($method);
        if ($mode === null) {
            return ['ok' => false, 'reason' => 'unknown_renewal_method', 'method' => (string) $method, 'manual_review' => true, 'queued' => false, 'destructive' => false];
        }
        if (!empty($mode['queued'])) {
            $limit = rx_pf_int($before['data_limit'] ?? 0);
            return [
                'ok' => true,
                'reason' => 'ok',
                'method' => $mode['method'],
                'effective_method' => 'queued',
                'queued' => true,
                'reset_usage' => false,
                'before_expire' => rx_pf_int($before['expire'] ?? 0),
                'before_data_limit' => $limit,
                'before_used_traffic' => rx_pf_int($before['used_traffic'] ?? 0),
                'before_remaining' => $limit > 0 ? max(0, $limit - rx_pf_int($before['used_traffic'] ?? 0)) : null,
            ];
        }
        return rxRenewalCompute($now, (string) $mode['policy'], !empty($mode['reset_usage']), $before, $purchasedVolumeGb, $purchasedDays, array_merge(['method' => $mode['method']], $ctx));
    }
}

if (!function_exists('rxRenewalVerify')) {
    function rxRenewalVerify(array $after, array $plan): array
    {
        $checks = [];
        if (empty($after['ok']) && array_key_exists('ok', $after)) {
            return ['ok' => false, 'checks' => ['readable' => false], 'reason' => 'unreadable'];
        }
        if (array_key_exists('exists', $after) && empty($after['exists'])) {
            return ['ok' => false, 'checks' => ['exists' => false], 'reason' => 'missing'];
        }
        $targetLimit = rx_pf_int($plan['data_limit'] ?? 0);
        $afterLimit = rx_pf_int($after['data_limit'] ?? 0);
        $checks['traffic'] = $targetLimit <= 0 ? $afterLimit <= 0 : ($afterLimit > 0 && abs($afterLimit - $targetLimit) <= RX_RN_TRAFFIC_TOLERANCE);
        $afterUsed = rx_pf_int($after['used_traffic'] ?? 0);
        $beforeUsed = rx_pf_int($plan['before_used_traffic'] ?? 0);
        if (!empty($plan['reset_usage'])) {
            $checks['usage'] = $afterUsed <= RX_RN_USAGE_SLACK || $afterUsed + RX_RN_USAGE_SLACK <= $beforeUsed;
        } else {
            $checks['usage'] = $afterUsed + RX_RN_USAGE_SLACK >= $beforeUsed;
        }
        $afterStatus = strtolower(trim((string) ($after['status'] ?? '')));
        $afterPending = !empty($after['pending']) || $afterStatus === 'on_hold';
        if (!empty($plan['target_pending'])) {
            $wantedDuration = rx_pf_int($plan['target_pending_duration'] ?? 0);
            $afterDuration = isset($after['pending_duration']) ? rx_pf_int($after['pending_duration']) : null;
            $checks['time'] = $afterPending && $afterDuration !== null && abs($afterDuration - $wantedDuration) <= RX_PF_EXPIRE_TOLERANCE;
        } else {
            $targetExpire = rx_pf_int($plan['expire'] ?? 0);
            $afterExpire = rx_pf_int($after['expire'] ?? 0);
            $checks['time'] = $targetExpire <= 0
                ? ($afterExpire <= 0 && !$afterPending)
                : ($afterExpire > 0 && abs($afterExpire - $targetExpire) <= RX_PF_EXPIRE_TOLERANCE);
        }
        if (!empty($plan['expect_active'])) {
            $checks['status'] = $afterStatus === 'active' || (!empty($plan['target_pending']) && $afterPending);
        }
        $ok = !in_array(false, $checks, true);
        $failed = array_keys(array_filter($checks, function ($v) {
            return $v === false;
        }));
        return ['ok' => $ok, 'checks' => $checks, 'reason' => $ok ? 'ok' : 'mismatch_' . implode('_', $failed)];
    }
}

if (!function_exists('rxRenewalClassify')) {
    function rxRenewalClassify(array $snapshot, array $plan): string
    {
        if (empty($snapshot['ok'])) {
            return 'unknown';
        }
        if (empty($snapshot['exists'])) {
            return 'ambiguous';
        }
        if (!empty(rxRenewalVerify($snapshot, $plan)['ok'])) {
            return 'target';
        }
        $limitMatches = function ($value, $wanted) {
            $value = rx_pf_int($value);
            $wanted = rx_pf_int($wanted);
            return $wanted <= 0 ? $value <= 0 : ($value > 0 && abs($value - $wanted) <= RX_RN_TRAFFIC_TOLERANCE);
        };
        $expireMatches = function ($value, $wanted) {
            $value = rx_pf_int($value);
            $wanted = rx_pf_int($wanted);
            return ($wanted <= 0 || $value <= 0) ? ($wanted <= 0 && $value <= 0) : abs($value - $wanted) <= RX_PF_EXPIRE_TOLERANCE;
        };
        $afterPending = !empty($snapshot['pending']);
        $limitOk = $limitMatches($snapshot['data_limit'] ?? 0, $plan['before_data_limit'] ?? 0) || $limitMatches($snapshot['data_limit'] ?? 0, $plan['data_limit'] ?? 0);
        $expireBefore = !empty($plan['before_pending']) ? $afterPending : $expireMatches($snapshot['expire'] ?? 0, $plan['before_expire'] ?? 0);
        $expireTarget = !empty($plan['target_pending']) ? $afterPending : $expireMatches($snapshot['expire'] ?? 0, $plan['expire'] ?? 0);
        if (!$limitOk || !($expireBefore || $expireTarget)) {
            return 'ambiguous';
        }
        return 'before';
    }
}

if (!function_exists('rxRenewalFormatExpire')) {
    function rxRenewalFormatExpire($timestamp): string
    {
        $timestamp = rx_pf_int($timestamp);
        if ($timestamp <= 0) {
            return rxRenewalText('unlimited');
        }
        return function_exists('jdate') ? (string) jdate('Y/m/d H:i', $timestamp) : date('Y/m/d H:i', $timestamp);
    }
}

if (!function_exists('rxRenewalFormatBytes')) {
    function rxRenewalFormatBytes($bytes, bool $zeroIsUnlimited = true): string
    {
        if ($bytes === null) {
            return rxRenewalText('unlimited');
        }
        $bytes = rx_pf_int($bytes);
        if ($bytes <= 0 && $zeroIsUnlimited) {
            return rxRenewalText('unlimited');
        }
        $gb = max(0, $bytes) / RX_PF_GIB;
        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.') . ' ' . rxRenewalText('gb_unit');
    }
}

if (!function_exists('rxRenewalFormatDays')) {
    function rxRenewalFormatDays($days): string
    {
        $days = (float) $days;
        if ($days <= 0) {
            return rxRenewalText('unlimited');
        }
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.') . ' ' . rxRenewalText('days_unit');
    }
}

if (!function_exists('rxRenewalReasonText')) {
    function rxRenewalReasonText(string $reason, array $plan = []): string
    {
        switch ($reason) {
            case 'admin_blocked':
                return rxRenewalText('reason_admin_blocked');
            case 'pending_unsupported':
                return rxRenewalText('reason_pending_unsupported');
            case 'result_not_usable':
                return rxRenewalText('reason_result_not_usable', ['detail' => rxRenewalText(($plan['unusable'] ?? '') === 'traffic' ? 'reason_result_detail_traffic' : 'reason_result_detail_time')]);
            case 'invalid_purchase':
                return rxRenewalText('reason_invalid_purchase');
            case 'unknown_renewal_method':
            case 'unsupported_renewal_method':
            case 'unknown_policy':
                return rxRenewalText('reason_unknown_method');
            case 'panel_state_unavailable':
            case 'panel_read_failed':
                return rxRenewalText('reason_panel_state_unavailable');
            case 'user_missing':
                return rxRenewalText('reason_user_missing');
            case 'renewal_in_progress':
                return rxRenewalText('reason_renewal_in_progress');
            case 'queued_renewal_exists':
                return rxRenewalText('reason_queued_exists');
            case 'trigger_unavailable':
                return rxRenewalText('reason_trigger_unavailable', ['detail' => (string) ($plan['detail'] ?? '')]);
            case 'trigger_date_required':
                return rxRenewalText('reason_trigger_date_required');
            case 'trigger_date_invalid':
                return rxRenewalText('reason_trigger_date_invalid');
            case 'policy_invalid':
                return rxRenewalText('reason_policy_invalid');
            case 'reservation_unsupported':
                return rxRenewalText('reason_reservation_unsupported');
        }
        return rxRenewalText('reason_generic');
    }
}

if (!function_exists('rxRenewalFailureKnown')) {
    function rxRenewalFailureKnown($extend): bool
    {
        $code = is_array($extend) ? (string) ($extend['code'] ?? '') : '';
        return in_array($code, ['admin_blocked', 'pending_unsupported', 'result_not_usable', 'invalid_purchase', 'unknown_renewal_method', 'unsupported_renewal_method', 'unknown_policy', 'panel_state_unavailable', 'panel_read_failed', 'user_missing', 'renewal_in_progress', 'queued_renewal_exists', 'trigger_unavailable', 'trigger_date_required', 'trigger_date_invalid', 'policy_invalid', 'reservation_unsupported'], true);
    }
}

if (!function_exists('rxRenewalFailureText')) {
    function rxRenewalFailureText($extend, string $default = ''): string
    {
        $code = is_array($extend) ? (string) ($extend['code'] ?? '') : '';
        if (rxRenewalFailureKnown($extend)) {
            return rxRenewalReasonText($code, is_array($extend['plan'] ?? null) ? $extend['plan'] : (array) $extend);
        }
        return $default !== '' ? $default : rxRenewalText('reason_generic');
    }
}

if (!function_exists('rxRenewalIsPending')) {
    function rxRenewalIsPending($extend): bool
    {
        return is_array($extend) && !empty($extend['pending_verification']);
    }
}

if (!function_exists('rxRenewalPlanLines')) {
    function rxRenewalPlanLines(array $plan): array
    {
        $lines = [];
        $lines[] = rxRenewalText('preview_policy', ['label' => (string) ($plan['label'] ?? '')]);
        $trafficMode = (string) ($plan['traffic_mode'] ?? '');
        $timeMode = (string) ($plan['time_mode'] ?? '');
        if ($trafficMode === 'keep') {
            $lines[] = rxRenewalText('preview_traffic_unchanged');
        } elseif (rx_pf_int($plan['data_limit'] ?? 0) <= 0) {
            $lines[] = rxRenewalText('preview_traffic_unlimited');
        } else {
            $lines[] = rxRenewalText($trafficMode === 'reset' ? 'preview_traffic_discarded' : 'preview_traffic_kept');
        }
        if ($timeMode === 'keep') {
            $lines[] = rxRenewalText('preview_time_unchanged');
        } elseif (empty($plan['target_pending']) && rx_pf_int($plan['expire'] ?? 0) <= 0) {
            $lines[] = rxRenewalText('preview_time_unlimited');
        } else {
            $lines[] = rxRenewalText($timeMode === 'reset' ? 'preview_time_discarded' : 'preview_time_kept');
        }
        $lines[] = rxRenewalText(!empty($plan['reset_usage']) ? 'preview_usage_reset' : 'preview_usage_kept');
        if (!empty($plan['reset_usage']) && $trafficMode === 'carry') {
            $lines[] = rxRenewalText('preview_usage_note');
        }
        $lines[] = rxRenewalText('preview_current_expire', ['value' => !empty($plan['before_pending']) ? rxRenewalFormatDays(((int) ($plan['before_pending_duration'] ?? 0)) / 86400) : rxRenewalFormatExpire($plan['before_expire'] ?? 0)]);
        if (!empty($plan['target_pending'])) {
            $lines[] = rxRenewalText('preview_pending', ['value' => rxRenewalFormatDays(((int) ($plan['target_pending_duration'] ?? 0)) / 86400)]);
        } else {
            $lines[] = rxRenewalText('preview_new_expire', ['value' => rxRenewalFormatExpire($plan['expire'] ?? 0)]);
        }
        $lines[] = rxRenewalText('preview_current_limit', ['value' => rxRenewalFormatBytes($plan['before_data_limit'] ?? 0)]);
        $lines[] = rxRenewalText('preview_used', ['value' => rxRenewalFormatBytes($plan['before_used_traffic'] ?? 0, false)]);
        $lines[] = rxRenewalText('preview_current_remaining', ['value' => rxRenewalFormatBytes($plan['before_remaining'] ?? null, false)]);
        $lines[] = rxRenewalText('preview_purchased_time', ['value' => rxRenewalFormatDays($plan['purchased_days'] ?? 0)]);
        $lines[] = rxRenewalText('preview_purchased_volume', ['value' => rxRenewalFormatBytes($plan['purchased_volume_bytes'] ?? 0)]);
        $lines[] = rxRenewalText('preview_new_limit', ['value' => rxRenewalFormatBytes($plan['data_limit'] ?? 0)]);
        $lines[] = rxRenewalText('preview_new_remaining', ['value' => rxRenewalFormatBytes($plan['target_remaining'] ?? null)]);
        if (!empty($plan['enable'])) {
            $lines[] = rxRenewalText('preview_enable');
        }
        return $lines;
    }
}

if (!function_exists('rxRenewalPreviewText')) {
    function rxRenewalPreviewText(array $plan): string
    {
        if (empty($plan['ok'])) {
            return rxRenewalReasonText((string) ($plan['reason'] ?? ''), $plan);
        }
        if (!empty($plan['queued'])) {
            return rxRenewalText('reservation_title') . "\n"
                . rxRenewalText('preview_current_expire', ['value' => rxRenewalFormatExpire($plan['before_expire'] ?? 0)]) . "\n"
                . rxRenewalText('preview_current_remaining', ['value' => rxRenewalFormatBytes($plan['before_remaining'] ?? null, false)]);
        }
        return rxRenewalText('preview_title') . "\n" . implode("\n", rxRenewalPlanLines($plan));
    }
}

if (!function_exists('rxRenewalContext')) {
    function rxRenewalContext($panel, string $username): array
    {
        $type = is_array($panel) ? (string) ($panel['type'] ?? '') : '';
        $adminBlocked = false;
        global $pdo;
        if ($pdo instanceof PDO && is_array($panel) && $username !== '') {
            try {
                $stmt = $pdo->prepare("SELECT Status FROM invoice WHERE username = ? AND Service_location = ? ORDER BY time_sell DESC LIMIT 1");
                $stmt->execute([$username, (string) ($panel['name_panel'] ?? '')]);
                $status = $stmt->fetchColumn();
                $adminBlocked = $status === 'disablebyadmin';
            } catch (Throwable $e) {
                $adminBlocked = false;
            }
        }
        return ['pending_supported' => rxRenewalPendingSupported($type), 'admin_blocked' => $adminBlocked, 'panel_type' => $type];
    }
}

if (!function_exists('rxRenewalPlanForSnapshot')) {
    function rxRenewalPlanForSnapshot(array $snapshot, $panel, string $username, string $policy, bool $resetUsage, $volumeGb, $days, string $method = '', ?int $now = null): array
    {
        $ctx = rxRenewalContext($panel, $username);
        $ctx['method'] = $method;
        return rxRenewalCompute($now ?? time(), $policy, $resetUsage, $snapshot, $volumeGb, $days, $ctx);
    }
}

if (!function_exists('rxRenewalPreviewForService')) {
    function rxRenewalPreviewForService($managePanel, $panel, string $username, $purchasedVolumeGb, $purchasedDays, array $opts = []): array
    {
        if (!is_array($panel) || ($panel['type'] ?? '') === 'Manualsale' || (function_exists('nmPanelNationalEnabled') && nmPanelNationalEnabled($panel))) {
            return ['ok' => true, 'blocked' => false, 'plan' => null, 'text' => '', 'mode' => 'delivery'];
        }
        $mode = rxRenewalMode($panel['Methodextend'] ?? '');
        if ($mode === null) {
            $plan = ['ok' => false, 'reason' => 'unknown_renewal_method'];
            return ['ok' => false, 'blocked' => true, 'plan' => $plan, 'reason' => 'unknown_renewal_method', 'text' => rxRenewalReasonText('unknown_renewal_method'), 'mode' => 'immediate'];
        }
        $snapshot = is_array($opts['snapshot'] ?? null) ? $opts['snapshot'] : rx_pf_panel_snapshot($managePanel, (string) ($panel['name_panel'] ?? ''), $username, (string) ($panel['type'] ?? ''));
        if (!empty($snapshot['ok']) && empty($snapshot['exists'])) {
            return ['ok' => false, 'blocked' => true, 'plan' => null, 'reason' => 'user_missing', 'text' => rxRenewalReasonText('user_missing'), 'mode' => !empty($mode['queued']) ? 'reservation' : 'immediate'];
        }
        $snapshotOk = !empty($snapshot['ok']);
        if (!empty($mode['queued'])) {
            return rxReservationPreview($panel, $username, $snapshotOk ? $snapshot : null, $purchasedVolumeGb, $purchasedDays, $opts);
        }
        if (!$snapshotOk) {
            return ['ok' => false, 'blocked' => false, 'plan' => null, 'reason' => 'panel_unreachable', 'text' => rxRenewalText('reason_panel_unreachable'), 'mode' => 'immediate'];
        }
        $plan = rxRenewalPlanForSnapshot($snapshot, $panel, $username, (string) $mode['policy'], !empty($mode['reset_usage']), $purchasedVolumeGb, $purchasedDays, (string) $mode['method']);
        return [
            'ok' => !empty($plan['ok']),
            'blocked' => empty($plan['ok']),
            'plan' => $plan,
            'reason' => (string) ($plan['reason'] ?? ''),
            'text' => rxRenewalPreviewText($plan),
            'mode' => 'immediate',
        ];
    }
}

if (!function_exists('rxRenewalResultText')) {
    function rxRenewalResultText(array $before, array $after): string
    {
        $beforeLimit = rx_pf_int($before['data_limit'] ?? 0);
        $afterLimit = rx_pf_int($after['data_limit'] ?? 0);
        $beforeRemaining = $beforeLimit > 0 ? max(0, $beforeLimit - rx_pf_int($before['used_traffic'] ?? 0)) : null;
        $afterRemaining = $afterLimit > 0 ? max(0, $afterLimit - rx_pf_int($after['used_traffic'] ?? 0)) : null;
        return rxRenewalText('result_line_expire', ['before' => rxRenewalFormatExpire($before['expire'] ?? 0), 'after' => rxRenewalFormatExpire($after['expire'] ?? 0)]) . "\n"
            . rxRenewalText('result_line_limit', ['before' => rxRenewalFormatBytes($beforeLimit), 'after' => rxRenewalFormatBytes($afterLimit)]) . "\n"
            . rxRenewalText('result_line_remaining', ['before' => rxRenewalFormatBytes($beforeRemaining, false), 'after' => rxRenewalFormatBytes($afterRemaining, false)]);
    }
}

if (!function_exists('rxRenewalOutcomeText')) {
    function rxRenewalOutcomeText($extend): string
    {
        if (!is_array($extend) || !is_array($extend['rx_renewal'] ?? null)) {
            return rxRenewalIsPending($extend) ? rxRenewalText('pending_verification') : '';
        }
        $renewal = $extend['rx_renewal'];
        if (!empty($renewal['verified']) && is_array($renewal['before'] ?? null) && is_array($renewal['after'] ?? null)) {
            return rxRenewalResultText($renewal['before'], $renewal['after']);
        }
        return rxRenewalText('pending_verification');
    }
}

if (!function_exists('rxRenewalDecorateSuccess')) {
    function rxRenewalDecorateSuccess(string $successText, $extend, string $username, string $panelName): string
    {
        if (rxRenewalIsPending($extend)) {
            return rxRenewalText('pending_verification') . "\n\n▫️نام سرویس : " . $username;
        }
        if (!is_array($extend) || !is_array($extend['rx_renewal'] ?? null)) {
            return $successText;
        }
        $renewal = $extend['rx_renewal'];
        if (!empty($renewal['verified']) && is_array($renewal['before'] ?? null) && is_array($renewal['after'] ?? null)) {
            return rtrim($successText) . "\n\n" . rxRenewalResultText($renewal['before'], $renewal['after']);
        }
        rx_pf_log('RENEWAL_RESULT_UNVERIFIED', 'Panel state after renewal does not match the target', [
            'username' => $username,
            'panel' => $panelName,
            'operation_id' => $renewal['operation_id'] ?? null,
            'target' => $renewal['target'] ?? null,
            'after' => $renewal['after'] ?? null,
        ]);
        return rxRenewalText('pending_verification') . "\n\n▫️نام سرویس : " . $username;
    }
}

if (!function_exists('rx_pf_extra_volume_target')) {
    function rx_pf_extra_volume_target(array $before, $volumeGb): array
    {
        $volume = (float) $volumeGb;
        return ['data_limit' => $volume == 0 ? 0 : rx_pf_int($before['data_limit'] ?? 0) + (int) round($volume * RX_PF_GIB)];
    }
}

if (!function_exists('rx_pf_extra_time_target')) {
    function rx_pf_extra_time_target(array $before, $days, int $now): array
    {
        $timeOld = rx_pf_int($before['expire'] ?? 0);
        $timeOld = ($now - $timeOld > 0) ? $now : $timeOld;
        $days = (float) $days;
        return ['expire' => $days == 0 ? 0 : $timeOld + (int) round($days * 86400)];
    }
}

if (!function_exists('rx_pf_adopt_created')) {
    function rx_pf_adopt_created(array $raw): array
    {
        $output = $raw;
        $output['status'] = 'successful';
        if (empty($output['configs']) && !empty($output['links'])) {
            $output['configs'] = is_array($output['links']) ? $output['links'] : explode("\n", (string) $output['links']);
        }
        return $output;
    }
}

if (!function_exists('rx_pf_wallet_credit')) {
    function rx_pf_wallet_credit(string $orderId, string $token, $userId, int $amount, string $category, string $description): string
    {
        if ($amount <= 0 || trim((string) $userId) === '') {
            return 'invalid';
        }
        if (!rx_pf_wallet_ready()) {
            return 'error';
        }
        try {
            $status = rx_pf_tx(function (PDO $pdo) use ($orderId, $token, $userId, $amount, $category, $description) {
                $now = time();
                $record = rx_pf_lock_record($pdo, $orderId);
                if ($record === null) {
                    return 'missing';
                }
                if ((string) $record['state'] === 'completed') {
                    return 'already_completed';
                }
                if (!hash_equals((string) ($record['processing_token'] ?? ''), $token)) {
                    return 'not_owner';
                }
                if (!in_array((string) $record['state'], ['claimed', 'reconciling'], true)) {
                    return 'invalid_state';
                }
                $payment = rx_pf_lock_payment($pdo, $orderId);
                if ($payment === null) {
                    return 'missing';
                }
                if ((int) ($payment['direct_payment_done'] ?? 0) === 1) {
                    $stmt = $pdo->prepare("UPDATE payment_fulfillment SET state = 'completed', lease_expires_at = NULL, completed_at = ?, updated_at = ? WHERE id_order = ?");
                    $stmt->execute([$now, $now, $orderId]);
                    return 'already_completed';
                }
                $credit = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
                $credit->execute([$amount, (string) $userId]);
                if ($credit->rowCount() < 1) {
                    throw new RuntimeException('wallet_user_not_found');
                }
                $balanceStmt = $pdo->prepare("SELECT Balance FROM user WHERE id = ? LIMIT 1");
                $balanceStmt->execute([(string) $userId]);
                $balanceAfter = $balanceStmt->fetchColumn();
                $ledger = $pdo->prepare(
                    "INSERT INTO wallet_ledger (id_user, direction, amount, balance_after, category, description, id_order, fulfillment_key) VALUES (?, 'credit', ?, ?, ?, ?, ?, ?)"
                );
                $ledger->execute([(string) $userId, $amount, $balanceAfter === false ? null : (int) $balanceAfter, $category, $description, $orderId, $orderId]);
                $paid = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', direct_payment_done = 1 WHERE id_order = ? AND COALESCE(direct_payment_done, 0) = 0");
                $paid->execute([$orderId]);
                if ($paid->rowCount() < 1) {
                    throw new RuntimeException('wallet_payment_finalize_failed');
                }
                $stmt = $pdo->prepare("UPDATE payment_fulfillment SET state = 'completed', result_state = ?, lease_expires_at = NULL, completed_at = ?, updated_at = ? WHERE id_order = ?");
                $stmt->execute([rx_pf_json(['credited' => $amount, 'balance_after' => $balanceAfter === false ? null : (int) $balanceAfter]), $now, $now, $orderId]);
                return 'credited';
            });
        } catch (Throwable $e) {
            rx_pf_log('PAYMENT_FULFILLMENT_WALLET_CREDIT_FAILED', $e->getMessage(), ['id_order' => $orderId, 'id_user' => (string) $userId, 'amount' => $amount]);
            return 'error';
        }
        rx_pf_clear_cache(['Payment_report', 'user']);
        if (function_exists('clearSelectCacheRow')) {
            try {
                clearSelectCacheRow('user', 'id', $userId);
            } catch (Throwable $e) {
            }
        }
        return $status;
    }
}
