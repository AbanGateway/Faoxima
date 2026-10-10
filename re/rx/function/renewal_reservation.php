<?php

if (!defined('RX_RN_LOCK_SECONDS')) {
    define('RX_RN_LOCK_SECONDS', 300);
}
if (!defined('RX_RN_MAX_ATTEMPTS')) {
    define('RX_RN_MAX_ATTEMPTS', 10);
}
if (!defined('RX_RN_BLOCKED_RECHECK')) {
    define('RX_RN_BLOCKED_RECHECK', 21600);
}

if (!function_exists('rx_rn_unresolved_statuses')) {
    function rx_rn_unresolved_statuses(): array
    {
        return ['pending', 'processing', 'retry', 'reconciling'];
    }
}

if (!function_exists('rx_rn_unresolved_sql')) {
    function rx_rn_unresolved_sql(): string
    {
        return "'" . implode("','", rx_rn_unresolved_statuses()) . "'";
    }
}

if (!function_exists('rx_rn_queued_columns')) {
    function rx_rn_queued_columns(): array
    {
        return [
            'service_key' => 'VARCHAR(191) NULL',
            'active_key' => 'VARCHAR(191) NULL',
            'trigger_type' => "VARCHAR(20) NOT NULL DEFAULT 'either'",
            'trigger_at' => 'INT UNSIGNED NULL',
            'policy' => "VARCHAR(40) NOT NULL DEFAULT 'reset_all'",
            'reset_usage' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'payment_ref' => 'VARCHAR(191) NULL',
            'fulfillment' => 'MEDIUMTEXT NULL',
            'processing_token' => 'CHAR(32) NULL',
            'lease_expires_at' => 'INT UNSIGNED NULL',
            'attempt_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'next_attempt_at' => 'INT UNSIGNED NULL',
            'last_error' => 'TEXT NULL',
            'stage' => 'VARCHAR(30) NULL',
            'before_state' => 'MEDIUMTEXT NULL',
            'target_state' => 'MEDIUMTEXT NULL',
            'result_state' => 'MEDIUMTEXT NULL',
            'notified_state' => 'VARCHAR(80) NULL',
            'legacy' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'updated_at' => 'INT UNSIGNED NULL',
        ];
    }
}

if (!function_exists('rx_rn_queued_table_ddl')) {
    function rx_rn_queued_table_ddl(): string
    {
        $extra = [];
        foreach (rx_rn_queued_columns() as $name => $definition) {
            $extra[] = $name . ' ' . $definition;
        }
        return "CREATE TABLE IF NOT EXISTS queued_renewal (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(500) NOT NULL,
        username varchar(1000) NOT NULL,
        name_panel varchar(500) NOT NULL,
        code_panel varchar(500) NOT NULL,
        code_product varchar(500) NOT NULL,
        new_limit_gb varchar(200) NOT NULL,
        time_day varchar(200) NOT NULL,
        price varchar(200) NOT NULL,
        id_invoice varchar(500) NULL,
        status varchar(200) NOT NULL DEFAULT 'pending',
        bottype varchar(200) NULL,
        created_at varchar(200) NOT NULL,
        applied_at varchar(200) NULL,
        output TEXT NULL,
        " . implode(",\n        ", $extra) . ",
        INDEX idx_queued_renewal_username_status (username(191), status(50)),
        UNIQUE KEY uniq_qr_active_key (active_key),
        KEY idx_qr_status_next (status(50), next_attempt_at),
        KEY idx_qr_payment_ref (payment_ref))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci";
    }
}

if (!function_exists('rx_rn_ensure_schema')) {
    function rx_rn_ensure_schema(): bool
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
            $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_lock (
                service_key VARCHAR(191) NOT NULL PRIMARY KEY,
                owner CHAR(32) NOT NULL,
                lease_expires_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_operation (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                op_id CHAR(32) NOT NULL,
                source VARCHAR(40) NOT NULL,
                ref VARCHAR(191) NULL,
                service_key VARCHAR(191) NOT NULL,
                username VARCHAR(191) NOT NULL,
                name_panel VARCHAR(191) NOT NULL,
                id_invoice VARCHAR(191) NULL,
                method VARCHAR(100) NULL,
                policy VARCHAR(40) NULL,
                state VARCHAR(20) NOT NULL,
                stage VARCHAR(30) NOT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at INT UNSIGNED NULL,
                before_state MEDIUMTEXT NULL,
                target_state MEDIUMTEXT NULL,
                result_state MEDIUMTEXT NULL,
                last_error TEXT NULL,
                created_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uniq_ro_op_id (op_id),
                KEY idx_ro_state (state, next_attempt_at),
                KEY idx_ro_service (service_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $exists = $pdo->query("SHOW TABLES LIKE 'queued_renewal'");
            if ($exists === false || $exists->fetch(PDO::FETCH_NUM) === false) {
                $pdo->exec(rx_rn_queued_table_ddl());
                $ready = true;
                return true;
            }
            $columns = [];
            foreach ($pdo->query("SHOW COLUMNS FROM queued_renewal")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[(string) $col['Field']] = true;
            }
            $legacyMigration = !isset($columns['policy']);
            foreach (rx_rn_queued_columns() as $name => $definition) {
                if (!isset($columns[$name])) {
                    $pdo->exec("ALTER TABLE queued_renewal ADD COLUMN {$name} {$definition}");
                }
            }
            if ($legacyMigration) {
                $pdo->exec("UPDATE queued_renewal SET legacy = 1, trigger_type = 'either', policy = 'reset_all', reset_usage = 0, attempt_count = 0, "
                    . "service_key = LEFT(CASE WHEN id_invoice IS NOT NULL AND id_invoice <> '' THEN CONCAT('inv:', id_invoice) ELSE CONCAT('pu:', code_panel, '|', username) END, 191), "
                    . "updated_at = UNIX_TIMESTAMP()");
                $pdo->exec("UPDATE queued_renewal q JOIN (SELECT MIN(id) AS id FROM queued_renewal WHERE status = 'pending' GROUP BY service_key) m ON m.id = q.id SET q.active_key = q.service_key");
                $pdo->exec("UPDATE queued_renewal SET last_error = LEFT(COALESCE(output, 'legacy_failed'), 2000), notified_state = 'failed:legacy' WHERE status = 'failed'");
            }
            $indexes = [];
            foreach ($pdo->query("SHOW INDEX FROM queued_renewal")->fetchAll(PDO::FETCH_ASSOC) as $idx) {
                $indexes[(string) $idx['Key_name']] = true;
            }
            if (!isset($indexes['uniq_qr_active_key'])) {
                $pdo->exec("ALTER TABLE queued_renewal ADD UNIQUE KEY uniq_qr_active_key (active_key)");
            }
            if (!isset($indexes['idx_qr_status_next'])) {
                $pdo->exec("ALTER TABLE queued_renewal ADD KEY idx_qr_status_next (status(50), next_attempt_at)");
            }
            if (!isset($indexes['idx_qr_payment_ref'])) {
                $pdo->exec("ALTER TABLE queued_renewal ADD KEY idx_qr_payment_ref (payment_ref)");
            }
            $ready = true;
            return true;
        } catch (Throwable $e) {
            rx_pf_log('RENEWAL_SCHEMA_FAILED', $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rx_rn_service_key')) {
    function rx_rn_service_key($idInvoice, $codePanel, $username): string
    {
        $idInvoice = trim((string) $idInvoice);
        $key = $idInvoice !== '' ? 'inv:' . $idInvoice : 'pu:' . (string) $codePanel . '|' . (string) $username;
        return substr($key, 0, 191);
    }
}

if (!function_exists('rx_rn_lock_acquire')) {
    function rx_rn_lock_acquire(string $serviceKey, string $owner, int $ttl = RX_RN_LOCK_SECONDS): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return false;
        }
        try {
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO renewal_lock (service_key, owner, lease_expires_at, updated_at) VALUES (?, ?, ?, ?) "
                . "ON DUPLICATE KEY UPDATE owner = IF(lease_expires_at < VALUES(updated_at) OR owner = VALUES(owner), VALUES(owner), owner), "
                . "lease_expires_at = IF(owner = VALUES(owner), VALUES(lease_expires_at), lease_expires_at), "
                . "updated_at = IF(owner = VALUES(owner), VALUES(updated_at), updated_at)"
            );
            $stmt->execute([$serviceKey, $owner, $now + $ttl, $now]);
            $check = $pdo->prepare("SELECT owner FROM renewal_lock WHERE service_key = ? LIMIT 1");
            $check->execute([$serviceKey]);
            return hash_equals((string) $check->fetchColumn(), $owner);
        } catch (Throwable $e) {
            rx_pf_log('RENEWAL_LOCK_FAILED', $e->getMessage(), ['service_key' => $serviceKey]);
            return false;
        }
    }
}

if (!function_exists('rx_rn_lock_release')) {
    function rx_rn_lock_release(string $serviceKey, string $owner): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM renewal_lock WHERE service_key = ? AND owner = ?");
            $stmt->execute([$serviceKey, $owner]);
        } catch (Throwable $e) {
            rx_pf_log('RENEWAL_LOCK_RELEASE_FAILED', $e->getMessage(), ['service_key' => $serviceKey]);
        }
    }
}

if (!function_exists('rx_rn_trim_error')) {
    function rx_rn_trim_error($error): string
    {
        $error = is_scalar($error) ? (string) $error : (string) json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return function_exists('mb_substr') ? mb_substr($error, 0, 2000, 'UTF-8') : substr($error, 0, 2000);
    }
}

if (!function_exists('rx_rn_op_start')) {
    function rx_rn_op_start(array $op): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return false;
        }
        try {
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO renewal_operation (op_id, source, ref, service_key, username, name_panel, id_invoice, method, policy, state, stage, attempt_count, before_state, target_state, created_at, updated_at) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'applying', 'planned', 1, ?, ?, ?, ?) "
                . "ON DUPLICATE KEY UPDATE state = 'applying', stage = 'planned', attempt_count = attempt_count + 1, target_state = VALUES(target_state), updated_at = VALUES(updated_at)"
            );
            $stmt->execute([
                (string) $op['op_id'],
                (string) ($op['source'] ?? 'wallet'),
                isset($op['ref']) ? substr((string) $op['ref'], 0, 191) : null,
                (string) $op['service_key'],
                substr((string) $op['username'], 0, 191),
                substr((string) $op['name_panel'], 0, 191),
                isset($op['id_invoice']) ? (string) $op['id_invoice'] : null,
                isset($op['method']) ? substr((string) $op['method'], 0, 100) : null,
                isset($op['policy']) ? (string) $op['policy'] : null,
                rx_pf_json($op['before'] ?? null),
                rx_pf_json($op['target'] ?? null),
                $now,
                $now,
            ]);
            return true;
        } catch (Throwable $e) {
            rx_pf_log('RENEWAL_OPERATION_START_FAILED', $e->getMessage(), ['op_id' => $op['op_id'] ?? null]);
            return false;
        }
    }
}

if (!function_exists('rx_rn_op_update')) {
    function rx_rn_op_update(string $opId, array $fields): void
    {
        global $pdo;
        if (!($pdo instanceof PDO) || $fields === []) {
            return;
        }
        $allowed = ['state', 'stage', 'result_state', 'last_error', 'next_attempt_at', 'attempt_count'];
        $sets = [];
        $params = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            if ($name === 'result_state') {
                $value = rx_pf_json($value);
            } elseif ($name === 'last_error' && $value !== null) {
                $value = rx_rn_trim_error($value);
            }
            $sets[] = $name . ' = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = ?';
        $params[] = time();
        $params[] = $opId;
        try {
            $stmt = $pdo->prepare("UPDATE renewal_operation SET " . implode(', ', $sets) . " WHERE op_id = ?");
            $stmt->execute($params);
        } catch (Throwable $e) {
            rx_pf_log('RENEWAL_OPERATION_UPDATE_FAILED', $e->getMessage(), ['op_id' => $opId]);
        }
    }
}

if (!function_exists('rxReservationTriggers')) {
    function rxReservationTriggers(): array
    {
        return ['either', 'traffic', 'expire', 'date', 'immediate'];
    }
}

if (!function_exists('rxReservationTriggerLabel')) {
    function rxReservationTriggerLabel(string $trigger, $triggerAt = null): string
    {
        if ($trigger === 'date' && is_numeric($triggerAt) && (int) $triggerAt > 0) {
            return rxRenewalText('trigger_date_at', ['date' => rxRenewalFormatExpire((int) $triggerAt)]);
        }
        return rxRenewalText('trigger_' . (in_array($trigger, rxReservationTriggers(), true) ? $trigger : 'either'));
    }
}

if (!function_exists('rxReservationStatusLabel')) {
    function rxReservationStatusLabel(string $status): string
    {
        $known = ['pending', 'processing', 'retry', 'reconciling', 'applied', 'failed', 'cancelled'];
        return rxRenewalText('status_' . (in_array($status, $known, true) ? $status : 'pending'));
    }
}

if (!function_exists('rxReservationTriggerAvailability')) {
    function rxReservationTriggerAvailability(?array $snapshot): array
    {
        $out = [];
        foreach (rxReservationTriggers() as $trigger) {
            $out[$trigger] = ['available' => true, 'reason' => ''];
        }
        if (!is_array($snapshot) || empty($snapshot['ok']) || empty($snapshot['exists'])) {
            return $out;
        }
        $unlimitedTraffic = rx_pf_int($snapshot['data_limit'] ?? 0) <= 0;
        $unlimitedTime = empty($snapshot['pending']) && rx_pf_int($snapshot['expire'] ?? 0) <= 0;
        if ($unlimitedTraffic) {
            $out['traffic'] = ['available' => false, 'reason' => rxRenewalText('trigger_unavailable_traffic')];
        }
        if ($unlimitedTime) {
            $out['expire'] = ['available' => false, 'reason' => rxRenewalText('trigger_unavailable_expire')];
        }
        if ($unlimitedTraffic && $unlimitedTime) {
            $out['either'] = ['available' => false, 'reason' => rxRenewalText('trigger_unavailable_either')];
        }
        return $out;
    }
}

if (!function_exists('rxReservationTriggered')) {
    function rxReservationTriggered(array $row, array $snapshot, int $now): bool
    {
        if (empty($snapshot['ok']) || empty($snapshot['exists'])) {
            return false;
        }
        $trigger = (string) ($row['trigger_type'] ?? 'either');
        if ($trigger === 'date') {
            $at = (int) ($row['trigger_at'] ?? 0);
            return $at > 0 && $now >= $at;
        }
        $status = strtolower((string) ($snapshot['status'] ?? ''));
        $limit = rx_pf_int($snapshot['data_limit'] ?? 0);
        $used = rx_pf_int($snapshot['used_traffic'] ?? 0);
        $expire = rx_pf_int($snapshot['expire'] ?? 0);
        $pending = !empty($snapshot['pending']);
        $trafficExhausted = $limit > 0 && ($used >= $limit || $status === 'limited');
        $expired = !$pending && $expire > 0 && ($expire <= $now || $status === 'expired');
        if ($trigger === 'traffic') {
            return $trafficExhausted;
        }
        if ($trigger === 'expire') {
            return $expired;
        }
        return $trafficExhausted || $expired;
    }
}

if (!function_exists('rxReservationParseDate')) {
    function rxReservationParseDate($input, int $now): ?int
    {
        $input = trim((string) $input);
        if (function_exists('convertPersianNumbersToEnglish')) {
            $input = (string) convertPersianNumbersToEnglish($input);
        }
        $input = strtr($input, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if ($input === '') {
            return null;
        }
        if (ctype_digit($input) && (int) $input <= 366) {
            $days = (int) $input;
            if ($days < 1) {
                return null;
            }
            return $now + $days * 86400;
        }
        if (ctype_digit($input) && (int) $input > 1000000000) {
            $ts = (int) $input;
        } elseif (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $input, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if ($y < 1700) {
                if (!function_exists('jalali_to_gregorian')) {
                    return null;
                }
                $g = jalali_to_gregorian($y, $mo, $d);
                if (!is_array($g) || count($g) < 3) {
                    return null;
                }
                list($y, $mo, $d) = [(int) $g[0], (int) $g[1], (int) $g[2]];
            }
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            $ts = mktime(0, 0, 0, $mo, $d, $y);
        } else {
            return null;
        }
        if ($ts === false || $ts <= $now || $ts > $now + 366 * 86400) {
            return null;
        }
        return (int) $ts;
    }
}

if (!function_exists('rxReservationNormalizeSelection')) {
    function rxReservationNormalizeSelection($selection, ?array $snapshot, int $now): array
    {
        $selection = is_array($selection) ? $selection : [];
        $trigger = (string) ($selection['trigger'] ?? 'either');
        if (!in_array($trigger, rxReservationTriggers(), true)) {
            return ['ok' => false, 'reason' => 'trigger_unavailable', 'detail' => $trigger];
        }
        $policy = (string) ($selection['policy'] ?? 'reset_all');
        if (!isset(rxRenewalPolicies()[$policy])) {
            return ['ok' => false, 'reason' => 'policy_invalid'];
        }
        $resetUsage = !empty($selection['reset_usage']) && rxRenewalPolicyAllowsUsageReset($policy);
        $availability = rxReservationTriggerAvailability($snapshot);
        if (empty($availability[$trigger]['available'])) {
            return ['ok' => false, 'reason' => 'trigger_unavailable', 'detail' => (string) $availability[$trigger]['reason']];
        }
        $triggerAt = null;
        if ($trigger === 'date') {
            $raw = $selection['trigger_at'] ?? null;
            if ($raw === null || $raw === '' || $raw === 0) {
                return ['ok' => false, 'reason' => 'trigger_date_required'];
            }
            $triggerAt = rxReservationParseDate($raw, $now);
            if ($triggerAt === null) {
                return ['ok' => false, 'reason' => 'trigger_date_invalid'];
            }
        }
        return ['ok' => true, 'trigger' => $trigger, 'trigger_at' => $triggerAt, 'policy' => $policy, 'reset_usage' => $resetUsage];
    }
}

if (!function_exists('rxReservationOutstanding')) {
    function rxReservationOutstanding(string $serviceKey, string $username = '', string $namePanel = ''): ?array
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE (active_key = ? OR service_key = ?) AND status IN (" . rx_rn_unresolved_sql() . ") ORDER BY id ASC LIMIT 1");
            $stmt->execute([$serviceKey, $serviceKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false && $username !== '' && $namePanel !== '') {
                $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE username = ? AND name_panel = ? AND status IN (" . rx_rn_unresolved_sql() . ") ORDER BY id ASC LIMIT 1");
                $stmt->execute([$username, $namePanel]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            return $row === false ? null : $row;
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_LOOKUP_FAILED', $e->getMessage(), ['service_key' => $serviceKey]);
            return null;
        }
    }
}

if (!function_exists('rxReservationSummaryForService')) {
    function rxReservationSummaryForService(string $username, string $namePanel = '', string $idInvoice = ''): ?array
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return null;
        }
        try {
            if ($idInvoice !== '' && rx_rn_ensure_schema()) {
                $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE (service_key = ? OR (username = ? AND name_panel = ?)) AND status IN (" . rx_rn_unresolved_sql() . ") ORDER BY id ASC LIMIT 1");
                $stmt->execute(['inv:' . $idInvoice, $username, $namePanel]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE username = ? AND status IN (" . rx_rn_unresolved_sql() . ") ORDER BY id ASC LIMIT 1");
                $stmt->execute([$username]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        $status = (string) ($row['status'] ?? 'pending');
        $trigger = (string) ($row['trigger_type'] ?? 'either');
        $policy = (string) ($row['policy'] ?? 'reset_all');
        $resetUsage = !empty($row['reset_usage']);
        return [
            'id' => (int) $row['id'],
            'status' => $status,
            'status_label' => rxReservationStatusLabel($status),
            'trigger' => $trigger,
            'trigger_at' => isset($row['trigger_at']) && $row['trigger_at'] !== null ? (int) $row['trigger_at'] : null,
            'trigger_label' => rxReservationTriggerLabel($trigger, $row['trigger_at'] ?? null),
            'policy' => $policy,
            'policy_label' => isset(rxRenewalPolicies()[$policy]) ? rxRenewalPolicyLabel($policy, $resetUsage) : $policy,
            'reset_usage' => $resetUsage,
            'volume_gb' => (float) ($row['new_limit_gb'] ?? 0),
            'time_days' => (float) ($row['time_day'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'text' => rxRenewalText('reservation_notice', [
                'status' => rxReservationStatusLabel($status),
                'trigger' => rxReservationTriggerLabel($trigger, $row['trigger_at'] ?? null),
                'policy' => isset(rxRenewalPolicies()[$policy]) ? rxRenewalPolicyLabel($policy, $resetUsage) : $policy,
            ]),
        ];
    }
}

if (!function_exists('rxReservationRegister')) {
    function rxReservationRegister(array $a): array
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return ['ok' => false, 'code' => 'schema_unavailable'];
        }
        $serviceKey = (string) ($a['service_key'] ?? '');
        $paymentRef = trim((string) ($a['payment_ref'] ?? ''));
        if ($serviceKey === '') {
            return ['ok' => false, 'code' => 'service_identity_missing'];
        }
        try {
            if ($paymentRef !== '') {
                $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE payment_ref = ? LIMIT 1");
                $stmt->execute([$paymentRef]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing !== false) {
                    return ['ok' => true, 'existing' => true, 'id' => (int) $existing['id'], 'row' => $existing];
                }
            }
            $outstanding = rxReservationOutstanding($serviceKey, (string) ($a['username'] ?? ''), (string) ($a['name_panel'] ?? ''));
            if ($outstanding !== null) {
                return ['ok' => false, 'code' => 'queued_renewal_exists', 'id' => (int) $outstanding['id']];
            }
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO queued_renewal (id_user, username, name_panel, code_panel, code_product, new_limit_gb, time_day, price, id_invoice, status, bottype, created_at, "
                . "service_key, active_key, trigger_type, trigger_at, policy, reset_usage, payment_ref, fulfillment, attempt_count, legacy, updated_at) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)"
            );
            $stmt->execute([
                (string) ($a['id_user'] ?? ''),
                (string) $a['username'],
                (string) $a['name_panel'],
                (string) $a['code_panel'],
                (string) ($a['code_product'] ?? ''),
                (string) ($a['new_limit_gb'] ?? '0'),
                (string) ($a['time_day'] ?? '0'),
                (string) ($a['price'] ?? '0'),
                isset($a['id_invoice']) ? (string) $a['id_invoice'] : null,
                isset($a['bottype']) ? $a['bottype'] : null,
                date('Y/m/d H:i:s', $now),
                $serviceKey,
                $serviceKey,
                (string) $a['trigger'],
                $a['trigger_at'] ?? null,
                (string) $a['policy'],
                !empty($a['reset_usage']) ? 1 : 0,
                $paymentRef !== '' ? substr($paymentRef, 0, 191) : null,
                rx_pf_json($a['fulfillment'] ?? null),
                $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            rx_pf_log('RESERVATION_REGISTERED', 'Reservation registered', [
                'reservation_id' => $id,
                'service_key' => $serviceKey,
                'username' => (string) $a['username'],
                'panel' => (string) $a['name_panel'],
                'trigger' => (string) $a['trigger'],
                'policy' => (string) $a['policy'],
                'payment_ref' => $paymentRef,
            ]);
            return ['ok' => true, 'existing' => false, 'id' => $id];
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'code' => 'queued_renewal_exists'];
            }
            rx_pf_log('RESERVATION_REGISTER_FAILED', $e->getMessage(), ['service_key' => $serviceKey]);
            return ['ok' => false, 'code' => 'queued_renewal_insert_failed', 'msg' => $e->getMessage()];
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_REGISTER_FAILED', $e->getMessage(), ['service_key' => $serviceKey]);
            return ['ok' => false, 'code' => 'queued_renewal_insert_failed', 'msg' => $e->getMessage()];
        }
    }
}

if (!function_exists('rxReservationPreview')) {
    function rxReservationPreview($panel, string $username, ?array $snapshot, $volumeGb, $days, array $opts = []): array
    {
        $now = time();
        $selection = is_array($opts['reservation'] ?? null) ? $opts['reservation'] : [];
        $availability = rxReservationTriggerAvailability($snapshot);
        if (!isset($selection['trigger'])) {
            $selection['trigger'] = !empty($availability['either']['available']) ? 'either' : 'date';
        }
        if (!isset($selection['policy'])) {
            $selection['policy'] = 'reset_all';
        }
        $base = [
            'mode' => 'reservation',
            'availability' => $availability,
            'selection' => $selection,
            'plan' => null,
        ];
        $ctx = rxRenewalContext($panel, $username);
        if ($ctx['admin_blocked'] || (is_array($snapshot) && ($snapshot['status'] ?? '') === 'disabled')) {
            return array_merge($base, ['ok' => false, 'blocked' => true, 'reason' => 'admin_blocked', 'text' => rxRenewalReasonText('admin_blocked')]);
        }
        $serviceKey = rx_rn_service_key((string) ($opts['id_invoice'] ?? rxRenewalInvoiceId($username, (string) ($panel['name_panel'] ?? ''))), (string) ($panel['code_panel'] ?? ''), $username);
        if ((string) $selection['trigger'] !== 'immediate' && rxReservationOutstanding($serviceKey, $username, (string) ($panel['name_panel'] ?? '')) !== null) {
            return array_merge($base, ['ok' => false, 'blocked' => true, 'reason' => 'queued_renewal_exists', 'text' => rxRenewalReasonText('queued_renewal_exists')]);
        }
        $policy = isset(rxRenewalPolicies()[(string) $selection['policy']]) ? (string) $selection['policy'] : 'reset_all';
        $resetUsage = !empty($selection['reset_usage']) && rxRenewalPolicyAllowsUsageReset($policy);
        $plan = $snapshot !== null ? rxRenewalPlanForSnapshot($snapshot, $panel, $username, $policy, $resetUsage, $volumeGb, $days, 'رزرو اشتراک', $now) : null;
        if ((string) $selection['trigger'] === 'immediate') {
            if ($plan === null) {
                return array_merge($base, ['ok' => false, 'blocked' => false, 'reason' => 'panel_unreachable', 'text' => rxRenewalText('reason_panel_unreachable')]);
            }
            return array_merge($base, ['ok' => !empty($plan['ok']), 'blocked' => empty($plan['ok']), 'plan' => $plan, 'reason' => (string) ($plan['reason'] ?? ''), 'text' => rxRenewalPreviewText($plan)]);
        }
        $lines = [
            rxRenewalText('reservation_title'),
            rxRenewalText('reservation_trigger', ['label' => rxReservationTriggerLabel((string) $selection['trigger'], (string) $selection['trigger'] === 'date' ? rxReservationParseDate($selection['trigger_at'] ?? '', $now) : null)]),
            rxRenewalText('reservation_policy', ['label' => rxRenewalPolicyLabel($policy, $resetUsage)]),
            rxRenewalText('reservation_note'),
        ];
        if (empty($availability[(string) $selection['trigger']]['available'])) {
            $lines[] = rxRenewalReasonText('trigger_unavailable', ['detail' => (string) $availability[(string) $selection['trigger']]['reason']]);
        }
        if (is_array($plan) && !empty($plan['ok'])) {
            $lines[] = '';
            $lines[] = rxRenewalText('reservation_estimate');
            $lines = array_merge($lines, rxRenewalPlanLines($plan));
        } elseif (is_array($plan) && in_array((string) ($plan['reason'] ?? ''), ['pending_unsupported', 'invalid_purchase'], true)) {
            $lines[] = rxRenewalReasonText((string) $plan['reason'], $plan);
        } else {
            $lines[] = rxRenewalText('reservation_estimate_unavailable');
        }
        $hardInvalid = is_array($plan) && (string) ($plan['reason'] ?? '') === 'invalid_purchase';
        return array_merge($base, [
            'ok' => !$hardInvalid,
            'blocked' => $hardInvalid,
            'plan' => is_array($plan) && !empty($plan['ok']) ? $plan : null,
            'reason' => $hardInvalid ? 'invalid_purchase' : '',
            'text' => implode("\n", $lines),
        ]);
    }
}

if (!function_exists('rxRenewalInvoiceId')) {
    function rxRenewalInvoiceId(string $username, string $namePanel): string
    {
        global $pdo;
        if (!($pdo instanceof PDO) || $username === '') {
            return '';
        }
        try {
            $stmt = $pdo->prepare("SELECT id_invoice FROM invoice WHERE username = ? AND Service_location = ? ORDER BY time_sell DESC LIMIT 1");
            $stmt->execute([$username, $namePanel]);
            $id = $stmt->fetchColumn();
            return $id === false ? '' : (string) $id;
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('rxReservationBackoff')) {
    function rxReservationBackoff(int $attempt): int
    {
        return (int) min(3600, 60 * pow(2, max(0, $attempt - 1)));
    }
}

if (!function_exists('rxReservationClaim')) {
    function rxReservationClaim(int $id, string $fromStatus, string $token, int $now): bool
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                "UPDATE queued_renewal SET status = 'processing', processing_token = ?, lease_expires_at = ?, attempt_count = attempt_count + 1, updated_at = ? "
                . "WHERE id = ? AND status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?)"
            );
            $stmt->execute([$token, $now + RX_RN_LOCK_SECONDS, $now, $id, $fromStatus, $now]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_CLAIM_FAILED', $e->getMessage(), ['reservation_id' => $id]);
            return false;
        }
    }
}

if (!function_exists('rxReservationTransition')) {
    function rxReservationTransition(int $id, string $token, string $status, array $fields = []): bool
    {
        global $pdo;
        $allowed = ['stage', 'before_state', 'target_state', 'result_state', 'last_error', 'next_attempt_at', 'applied_at', 'output', 'active_key_clear', 'attempt_reset', 'lease'];
        $sets = ['status = ?', 'updated_at = ?'];
        $params = [$status, time()];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            if ($name === 'active_key_clear') {
                if ($value) {
                    $sets[] = 'active_key = NULL';
                }
                continue;
            }
            if ($name === 'attempt_reset') {
                if ($value) {
                    $sets[] = 'attempt_count = 0';
                }
                continue;
            }
            if ($name === 'lease') {
                $sets[] = 'lease_expires_at = ?';
                $params[] = $value;
                continue;
            }
            if (in_array($name, ['before_state', 'target_state', 'result_state'], true)) {
                $value = rx_pf_json($value);
            } elseif (in_array($name, ['last_error', 'output'], true) && $value !== null) {
                $value = rx_rn_trim_error($value);
            }
            $sets[] = $name . ' = ?';
            $params[] = $value;
        }
        if (!in_array($status, ['processing'], true) && !array_key_exists('lease', $fields)) {
            $sets[] = 'lease_expires_at = NULL';
            $sets[] = 'processing_token = NULL';
        }
        $params[] = $id;
        $params[] = $token;
        try {
            $stmt = $pdo->prepare("UPDATE queued_renewal SET " . implode(', ', $sets) . " WHERE id = ? AND processing_token = ? AND status = 'processing'");
            $stmt->execute($params);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_TRANSITION_FAILED', $e->getMessage(), ['reservation_id' => $id, 'to' => $status]);
            return false;
        }
    }
}

if (!function_exists('rxReservationRecoverStale')) {
    function rxReservationRecoverStale(int $now): int
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                "UPDATE queued_renewal SET status = CASE WHEN stage IN ('applying', 'reset_sent', 'modify_sent') THEN 'reconciling' ELSE 'retry' END, "
                . "processing_token = NULL, lease_expires_at = NULL, next_attempt_at = ?, last_error = CONCAT('lease_expired_at_stage:', COALESCE(stage, 'claimed')), updated_at = ? "
                . "WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < ?"
            );
            $stmt->execute([$now, $now, $now]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_RECOVER_FAILED', $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rxReservationDueRows')) {
    function rxReservationDueRows(int $now, int $limit = 30, int $shardWorker = 0, int $shardCount = 1): array
    {
        global $pdo;
        $shard = $shardCount > 1 ? " AND MOD(id, " . (int) $shardCount . ") = " . (int) $shardWorker . " " : "";
        try {
            $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE status IN ('pending', 'retry', 'reconciling') AND (next_attempt_at IS NULL OR next_attempt_at <= ?) $shard ORDER BY COALESCE(next_attempt_at, 0) ASC, id ASC LIMIT " . (int) $limit);
            $stmt->execute([$now]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_FETCH_FAILED', $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('rxReservationDefer')) {
    function rxReservationDefer(int $id, string $fromStatus, int $nextAt, ?string $error = null): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("UPDATE queued_renewal SET next_attempt_at = ?, last_error = COALESCE(?, last_error), updated_at = ? WHERE id = ? AND status = ?");
            $stmt->execute([$nextAt, $error !== null ? rx_rn_trim_error($error) : null, time(), $id, $fromStatus]);
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_DEFER_FAILED', $e->getMessage(), ['reservation_id' => $id]);
        }
    }
}

if (!function_exists('rxReservationFail')) {
    function rxReservationFail(array $row, string $token, string $reason, bool $permanent, int $now, array $extra = []): array
    {
        $attempts = (int) ($row['attempt_count'] ?? 0) + 1;
        if (!$permanent && $attempts >= RX_RN_MAX_ATTEMPTS) {
            $permanent = true;
            $reason = 'max_attempts_exceeded: ' . $reason;
        }
        $status = $permanent ? 'failed' : (string) ($extra['status'] ?? 'retry');
        $fields = array_merge([
            'last_error' => $reason,
            'next_attempt_at' => $permanent ? null : $now + (int) ($extra['delay'] ?? rxReservationBackoff($attempts)),
        ], array_intersect_key($extra, array_flip(['before_state', 'target_state', 'stage'])));
        rxReservationTransition((int) $row['id'], $token, $status, $fields);
        rx_pf_log($permanent ? 'RESERVATION_FAILED' : 'RESERVATION_RETRY', $reason, [
            'reservation_id' => (int) $row['id'],
            'service_key' => (string) ($row['service_key'] ?? ''),
            'username' => (string) $row['username'],
            'panel' => (string) $row['name_panel'],
            'policy' => (string) ($row['policy'] ?? ''),
            'attempt' => $attempts,
            'status' => $status,
        ]);
        return ['outcome' => $status, 'reason' => $reason, 'row' => $row];
    }
}

if (!function_exists('rxReservationProcessRow')) {
    function rxReservationProcessRow($managePanel, array $row, int $now): array
    {
        $id = (int) $row['id'];
        $from = (string) $row['status'];
        if (!in_array($from, ['pending', 'retry', 'reconciling'], true)) {
            return ['outcome' => 'skipped', 'reason' => 'not_due_state_' . $from, 'row' => $row];
        }
        $panel = function_exists('select') ? select('marzban_panel', '*', 'name_panel', (string) $row['name_panel'], 'select') : null;
        if (!is_array($panel)) {
            $panel = select('marzban_panel', '*', 'code_panel', (string) $row['code_panel'], 'select');
        }
        $panelType = is_array($panel) ? (string) ($panel['type'] ?? '') : '';
        if ($from === 'pending' && is_array($panel)) {
            $snapshot = rx_pf_panel_snapshot($managePanel, (string) $panel['name_panel'], (string) $row['username'], $panelType);
            if (empty($snapshot['ok'])) {
                rxReservationDefer($id, $from, $now + 300, 'panel_read_failed: ' . (string) ($snapshot['error'] ?? ''));
                return ['outcome' => 'waiting', 'reason' => 'panel_read_failed', 'row' => $row];
            }
            if (!empty($snapshot['exists']) && !rxReservationTriggered($row, $snapshot, $now)) {
                rxReservationDefer($id, $from, $now + 240);
                return ['outcome' => 'waiting', 'reason' => 'not_triggered', 'row' => $row];
            }
        }
        $token = rx_pf_new_token();
        if (!rxReservationClaim($id, $from, $token, $now)) {
            return ['outcome' => 'skipped', 'reason' => 'claim_lost', 'row' => $row];
        }
        $row['processing_token'] = $token;
        $serviceKey = (string) ($row['service_key'] ?? '');
        if ($serviceKey === '') {
            $serviceKey = rx_rn_service_key($row['id_invoice'] ?? '', $row['code_panel'] ?? '', $row['username'] ?? '');
        }
        if (!rx_rn_lock_acquire($serviceKey, $token)) {
            rxReservationTransition($id, $token, $from, ['next_attempt_at' => $now + 60, 'last_error' => 'service_busy']);
            return ['outcome' => 'busy', 'reason' => 'service_busy', 'row' => $row];
        }
        try {
            return rxReservationExecute($managePanel, $row, $panel, $from, $token, $serviceKey, $now);
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_EXCEPTION', $e->getMessage(), ['reservation_id' => $id, 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return rxReservationFail($row, $token, 'exception: ' . $e->getMessage(), false, $now, ['status' => 'reconciling']);
        } finally {
            rx_rn_lock_release($serviceKey, $token);
        }
    }
}

if (!function_exists('rxReservationExecute')) {
    function rxReservationExecute($managePanel, array $row, $panel, string $from, string $token, string $serviceKey, int $now): array
    {
        $id = (int) $row['id'];
        if (!is_array($panel)) {
            return rxReservationFail($row, $token, 'panel_not_found', false, $now, ['status' => $from === 'reconciling' ? 'reconciling' : 'retry']);
        }
        $panelType = (string) ($panel['type'] ?? '');
        $username = (string) $row['username'];
        $fulfillment = rx_pf_decode($row['fulfillment'] ?? null) ?? [];
        if ($panelType === 'Manualsale') {
            if ($from === 'reconciling') {
                return rxReservationFail($row, $token, 'manual_delivery_result_unverifiable', true, $now);
            }
            rxReservationTransition($id, $token, 'processing', ['stage' => 'applying', 'lease' => $now + RX_RN_LOCK_SECONDS]);
            $res = $managePanel->extend('ریست حجم و زمان', $row['new_limit_gb'], $row['time_day'], $username, (string) $row['code_product'], (string) $panel['code_panel'], null, ['lock_owner' => $token, 'service_key' => $serviceKey, 'source' => 'reservation', 'ref' => 'reservation:' . $id]);
            if (is_array($res) && !empty($res['status'])) {
                rxReservationTransition($id, $token, 'applied', ['stage' => 'done', 'applied_at' => date('Y/m/d H:i:s', $now), 'output' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 'active_key_clear' => true]);
                return ['outcome' => 'applied', 'reason' => 'ok', 'row' => $row, 'result' => $res];
            }
            return rxReservationFail($row, $token, 'manual_delivery_failed: ' . (is_array($res) ? (string) ($res['code'] ?? ($res['msg'] ?? '')) : ''), false, $now);
        }
        $snapshot = rx_pf_panel_snapshot($managePanel, (string) $panel['name_panel'], $username, $panelType);
        if (empty($snapshot['ok'])) {
            return rxReservationFail($row, $token, 'panel_read_failed: ' . (string) ($snapshot['error'] ?? ''), false, $now, ['status' => $from === 'reconciling' ? 'reconciling' : 'retry']);
        }
        if (empty($snapshot['exists'])) {
            return rxReservationFail($row, $token, 'service_missing_on_panel', true, $now);
        }
        $storedTarget = rx_pf_decode($row['target_state'] ?? null);
        $plan = null;
        if ($from === 'reconciling' && is_array($storedTarget) && !empty($storedTarget['rx_renewal_plan'])) {
            $class = rxRenewalClassify($snapshot, $storedTarget);
            if ($class === 'target') {
                rxReservationTransition($id, $token, 'applied', ['stage' => 'done', 'applied_at' => date('Y/m/d H:i:s', $now), 'result_state' => rx_pf_limits($snapshot), 'output' => 'reconciled', 'active_key_clear' => true]);
                if (method_exists($managePanel, 'rxRenewalLocalSuccess')) {
                    $managePanel->rxRenewalLocalSuccess($panel, $username, (string) ($row['id_invoice'] ?? ''));
                }
                return ['outcome' => 'applied', 'reason' => 'reconciled', 'row' => $row, 'before' => rx_pf_decode($row['before_state'] ?? null), 'after' => rx_pf_limits($snapshot)];
            }
            if ($class === 'ambiguous') {
                return rxReservationFail($row, $token, 'panel_state_ambiguous_after_interrupted_activation', true, $now);
            }
            $plan = $storedTarget;
        }
        if ($plan === null) {
            if (!rxReservationTriggered($row, $snapshot, $now)) {
                rxReservationTransition($id, $token, 'pending', ['next_attempt_at' => $now + 240, 'stage' => null, 'attempt_reset' => true]);
                return ['outcome' => 'waiting', 'reason' => 'not_triggered', 'row' => $row];
            }
            $policy = (string) ($row['policy'] ?? 'reset_all');
            $plan = rxRenewalPlanForSnapshot($snapshot, $panel, $username, $policy, !empty($row['reset_usage']), $row['new_limit_gb'], $row['time_day'], 'reservation', $now);
            if (empty($plan['ok'])) {
                $reason = (string) ($plan['reason'] ?? 'plan_refused');
                if ($reason === 'admin_blocked') {
                    return rxReservationFail($row, $token, 'admin_blocked', false, $now, ['status' => 'retry', 'delay' => RX_RN_BLOCKED_RECHECK]);
                }
                if ($reason === 'panel_state_unavailable') {
                    return rxReservationFail($row, $token, $reason, false, $now);
                }
                return rxReservationFail($row, $token, 'plan_refused: ' . $reason, true, $now);
            }
            if (!rxReservationTransition($id, $token, 'processing', ['stage' => 'applying', 'before_state' => rx_pf_limits($snapshot), 'target_state' => $plan, 'lease' => $now + RX_RN_LOCK_SECONDS])) {
                return ['outcome' => 'skipped', 'reason' => 'ownership_lost', 'row' => $row];
            }
        }
        $res = $managePanel->applyStoredRenewalPlan((string) $panel['name_panel'], $username, $plan, [
            'lock_owner' => $token,
            'service_key' => $serviceKey,
            'op_id' => $token,
            'source' => 'reservation',
            'ref' => 'reservation:' . $id,
            'method' => 'reservation:' . (string) ($row['policy'] ?? ''),
            'id_invoice' => (string) ($row['id_invoice'] ?? ''),
            'code_product' => (string) ($row['code_product'] ?? ''),
            'fulfillment' => $fulfillment,
        ]);
        if (is_array($res) && !empty($res['status'])) {
            $after = $res['rx_renewal']['after'] ?? null;
            rxReservationTransition($id, $token, 'applied', [
                'stage' => 'done',
                'applied_at' => date('Y/m/d H:i:s', $now),
                'result_state' => $after,
                'output' => json_encode(['status' => true, 'operation_id' => $token, 'after' => $after], JSON_UNESCAPED_UNICODE),
                'active_key_clear' => true,
            ]);
            rx_pf_log('RESERVATION_APPLIED', 'Reservation activated', ['reservation_id' => $id, 'service_key' => $serviceKey, 'username' => $username, 'panel' => (string) $panel['name_panel'], 'policy' => (string) ($row['policy'] ?? ''), 'target' => ['data_limit' => $plan['data_limit'] ?? null, 'expire' => $plan['expire'] ?? null], 'after' => $after]);
            return ['outcome' => 'applied', 'reason' => 'ok', 'row' => $row, 'before' => $res['rx_renewal']['before'] ?? null, 'after' => $after];
        }
        if (rxRenewalIsPending($res)) {
            return rxReservationFail($row, $token, 'result_unverified: ' . (string) ($res['msg'] ?? ''), false, $now, ['status' => 'reconciling']);
        }
        $code = is_array($res) ? (string) ($res['code'] ?? '') : '';
        $msg = is_array($res) ? (is_scalar($res['msg'] ?? null) ? (string) $res['msg'] : json_encode($res['msg'] ?? '')) : (string) $res;
        if ($code === 'admin_blocked') {
            return rxReservationFail($row, $token, 'admin_blocked', false, $now, ['status' => 'retry', 'delay' => RX_RN_BLOCKED_RECHECK]);
        }
        if (in_array($code, ['pending_unsupported', 'result_not_usable', 'invalid_purchase', 'unknown_policy'], true)) {
            return rxReservationFail($row, $token, 'plan_refused: ' . $code, true, $now);
        }
        return rxReservationFail($row, $token, 'apply_failed: ' . ($code !== '' ? $code . ' ' : '') . $msg, false, $now, ['status' => $from === 'reconciling' ? 'reconciling' : 'retry']);
    }
}

if (!function_exists('rxReservationMarkNotified')) {
    function rxReservationMarkNotified(int $id, string $notifiedState): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("UPDATE queued_renewal SET notified_state = ? WHERE id = ?");
            $stmt->execute([substr($notifiedState, 0, 80), $id]);
        } catch (Throwable $e) {
            rx_pf_log('RESERVATION_NOTIFY_MARK_FAILED', $e->getMessage(), ['reservation_id' => $id]);
        }
    }
}

if (!function_exists('rxReservationFetch')) {
    function rxReservationFetch(int $id): ?array
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('rxRenewalOpsReconcile')) {
    function rxRenewalOpsReconcile($managePanel, int $now, int $limit = 20): array
    {
        global $pdo;
        $results = [];
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return $results;
        }
        try {
            $pdo->prepare("UPDATE renewal_operation SET state = 'uncertain', stage = CONCAT(stage, ':interrupted'), updated_at = ? WHERE state = 'applying' AND source NOT IN ('payment', 'reservation') AND updated_at < ?")
                ->execute([$now, $now - RX_RN_LOCK_SECONDS]);
            $stmt = $pdo->prepare("SELECT * FROM renewal_operation WHERE state = 'uncertain' AND source NOT IN ('payment', 'reservation') AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id ASC LIMIT " . (int) $limit);
            $stmt->execute([$now]);
            $ops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rx_pf_log('RENEWAL_OPS_FETCH_FAILED', $e->getMessage());
            return $results;
        }
        foreach ($ops as $op) {
            if (function_exists('rx_cron_time_up') && rx_cron_time_up()) {
                break;
            }
            $claim = $pdo->prepare("UPDATE renewal_operation SET state = 'reconciling', next_attempt_at = ?, attempt_count = attempt_count + 1, updated_at = ? WHERE id = ? AND state = 'uncertain'");
            $claim->execute([$now + RX_RN_LOCK_SECONDS, $now, (int) $op['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $results[] = rxRenewalOpReconcileOne($managePanel, $op, $now);
        }
        return $results;
    }
}

if (!function_exists('rxRenewalOpReconcileOne')) {
    function rxRenewalOpReconcileOne($managePanel, array $op, int $now): array
    {
        $opId = (string) $op['op_id'];
        $attempts = (int) $op['attempt_count'] + 1;
        $target = rx_pf_decode($op['target_state'] ?? null);
        $back = function (string $error) use ($opId, $attempts, $now, $op) {
            if ($attempts >= RX_RN_MAX_ATTEMPTS) {
                rx_rn_op_update($opId, ['state' => 'manual_review', 'last_error' => 'max_attempts_exceeded: ' . $error]);
                rx_pf_alert_admin('renewal:' . $opId, 'تمدید نیازمند بررسی دستی است: ' . $error, ['username' => (string) $op['username']]);
                return ['op_id' => $opId, 'outcome' => 'manual_review', 'reason' => $error, 'op' => $op];
            }
            rx_rn_op_update($opId, ['state' => 'uncertain', 'last_error' => $error, 'next_attempt_at' => $now + rxReservationBackoff($attempts)]);
            return ['op_id' => $opId, 'outcome' => 'uncertain', 'reason' => $error, 'op' => $op];
        };
        if (!is_array($target) || empty($target['rx_renewal_plan'])) {
            rx_rn_op_update($opId, ['state' => 'manual_review', 'last_error' => 'target_missing']);
            return ['op_id' => $opId, 'outcome' => 'manual_review', 'reason' => 'target_missing', 'op' => $op];
        }
        $serviceKey = (string) $op['service_key'];
        if (!rx_rn_lock_acquire($serviceKey, $opId)) {
            rx_rn_op_update($opId, ['state' => 'uncertain', 'next_attempt_at' => $now + 60]);
            return ['op_id' => $opId, 'outcome' => 'busy', 'op' => $op];
        }
        try {
            $snapshot = rx_pf_panel_snapshot($managePanel, (string) $op['name_panel'], (string) $op['username']);
            $class = rxRenewalClassify($snapshot, $target);
            if ($class === 'unknown') {
                return $back('panel_read_failed: ' . (string) ($snapshot['error'] ?? ''));
            }
            if ($class === 'ambiguous') {
                rx_rn_op_update($opId, ['state' => 'manual_review', 'last_error' => 'panel_state_ambiguous']);
                rx_pf_alert_admin('renewal:' . $opId, 'وضعیت سرویس پس از تمدید نامشخص است و بررسی دستی لازم است', ['username' => (string) $op['username']]);
                return ['op_id' => $opId, 'outcome' => 'manual_review', 'reason' => 'panel_state_ambiguous', 'op' => $op];
            }
            if ($class === 'target') {
                rx_rn_op_update($opId, ['state' => 'verified', 'stage' => 'reconciled', 'result_state' => rx_pf_limits($snapshot)]);
                $panel = select('marzban_panel', '*', 'name_panel', (string) $op['name_panel'], 'select');
                if (is_array($panel) && method_exists($managePanel, 'rxRenewalLocalSuccess')) {
                    $managePanel->rxRenewalLocalSuccess($panel, (string) $op['username'], (string) ($op['id_invoice'] ?? ''));
                }
                return ['op_id' => $opId, 'outcome' => 'verified', 'op' => $op, 'after' => rx_pf_limits($snapshot)];
            }
            $res = $managePanel->applyStoredRenewalPlan((string) $op['name_panel'], (string) $op['username'], $target, [
                'lock_owner' => $opId,
                'service_key' => $serviceKey,
                'op_id' => $opId,
                'source' => (string) $op['source'],
                'ref' => (string) ($op['ref'] ?? ''),
                'method' => (string) ($op['method'] ?? ''),
                'id_invoice' => (string) ($op['id_invoice'] ?? ''),
            ]);
            if (is_array($res) && !empty($res['status'])) {
                return ['op_id' => $opId, 'outcome' => 'verified', 'op' => $op, 'after' => $res['rx_renewal']['after'] ?? null];
            }
            return $back('reapply_failed: ' . (is_array($res) ? (string) ($res['code'] ?? '') : ''));
        } finally {
            rx_rn_lock_release($serviceKey, $opId);
        }
    }
}

if (!function_exists('rxReservationDefaultSelection')) {
    function rxReservationDefaultSelection(?array $snapshot): array
    {
        $availability = rxReservationTriggerAvailability($snapshot);
        $trigger = 'immediate';
        foreach (['either', 'traffic', 'expire'] as $candidate) {
            if (!empty($availability[$candidate]['available'])) {
                $trigger = $candidate;
                break;
            }
        }
        return ['trigger' => $trigger, 'trigger_at' => null, 'policy' => 'reset_all', 'reset_usage' => false];
    }
}

if (!function_exists('rxReservationSelectionFromData')) {
    function rxReservationSelectionFromData($data, ?array $snapshot = null): array
    {
        $sel = is_array($data) && is_array($data['rsv'] ?? null) ? $data['rsv'] : rxReservationDefaultSelection($snapshot);
        $policy = isset(rxRenewalPolicies()[(string) ($sel['policy'] ?? '')]) ? (string) $sel['policy'] : 'reset_all';
        $trigger = in_array((string) ($sel['trigger'] ?? ''), rxReservationTriggers(), true) ? (string) $sel['trigger'] : 'either';
        return [
            'trigger' => $trigger,
            'trigger_at' => isset($sel['trigger_at']) && is_numeric($sel['trigger_at']) ? (int) $sel['trigger_at'] : null,
            'policy' => $policy,
            'reset_usage' => !empty($sel['reset_usage']) && rxRenewalPolicyAllowsUsageReset($policy),
        ];
    }
}

if (!function_exists('rxReservationNextTrigger')) {
    function rxReservationNextTrigger(string $current, ?array $snapshot): string
    {
        $order = rxReservationTriggers();
        $availability = rxReservationTriggerAvailability($snapshot);
        $index = array_search($current, $order, true);
        $index = $index === false ? -1 : $index;
        for ($i = 1; $i <= count($order); $i++) {
            $candidate = $order[($index + $i) % count($order)];
            if (!empty($availability[$candidate]['available'])) {
                return $candidate;
            }
        }
        return 'immediate';
    }
}

if (!function_exists('rxReservationNextPolicy')) {
    function rxReservationNextPolicy(string $current): string
    {
        $order = array_keys(rxRenewalPolicies());
        $index = array_search($current, $order, true);
        return $order[$index === false ? 0 : ($index + 1) % count($order)];
    }
}

if (!function_exists('rxReservationSettingsView')) {
    function rxReservationSettingsView(array $selection, ?array $snapshot, string $previewText = ''): array
    {
        $availability = rxReservationTriggerAvailability($snapshot);
        $trigger = (string) $selection['trigger'];
        $policy = (string) $selection['policy'];
        $resetUsage = !empty($selection['reset_usage']) && rxRenewalPolicyAllowsUsageReset($policy);
        $lines = [rxRenewalText('reservation_settings_title'), ''];
        if ($trigger === 'date' && empty($selection['trigger_at'])) {
            $lines[] = rxRenewalText('reservation_trigger', ['label' => rxRenewalText('trigger_date') . ' (' . rxRenewalText('reservation_date_unset') . ')']);
        }
        if ($previewText !== '') {
            $lines[] = $previewText;
        }
        foreach ($availability as $key => $info) {
            if (empty($info['available'])) {
                $lines[] = '⛔️ ' . rxRenewalText('trigger_' . $key) . ' : ' . (string) $info['reason'];
            }
        }
        $lines[] = '';
        $lines[] = rxRenewalText('reservation_settings_hint');
        $rows = [
            [['text' => rxRenewalText('reservation_btn_trigger', ['label' => rxRenewalText('trigger_' . $trigger)]), 'callback_data' => 'rsvt']],
            [['text' => rxRenewalText('reservation_btn_policy', ['label' => rxRenewalPolicyLabel($policy, false, true)]), 'callback_data' => 'rsvp']],
        ];
        if (rxRenewalPolicyAllowsUsageReset($policy)) {
            $rows[] = [['text' => rxRenewalText('reservation_btn_usage', ['label' => rxRenewalText($resetUsage ? 'reservation_yes' : 'reservation_no')]), 'callback_data' => 'rsvu']];
        }
        if ($trigger === 'date') {
            $rows[] = [['text' => rxRenewalText('reservation_btn_date'), 'callback_data' => 'rsvd']];
        }
        return ['text' => implode("\n", $lines), 'keyboard' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE)];
    }
}

if (!function_exists('rxReservationUiEnabled')) {
    function rxReservationUiEnabled($panel): bool
    {
        if (!is_array($panel) || ($panel['type'] ?? '') === 'Manualsale') {
            return false;
        }
        if (function_exists('nmPanelNationalEnabled') && nmPanelNationalEnabled($panel)) {
            return false;
        }
        $mode = rxRenewalMode($panel['Methodextend'] ?? '');
        return is_array($mode) && !empty($mode['queued']);
    }
}

if (!function_exists('rxReservationBuildView')) {
    function rxReservationBuildView($managePanel, array $panel, array $invoice, ?array $selection, $volumeGb, $days): array
    {
        $snapshot = rx_pf_panel_snapshot($managePanel, (string) $panel['name_panel'], (string) $invoice['username'], (string) ($panel['type'] ?? ''));
        $usable = !empty($snapshot['ok']) && !empty($snapshot['exists']) ? $snapshot : null;
        $selection = $selection ?? rxReservationDefaultSelection($usable);
        $preview = rxRenewalPreviewForService($managePanel, $panel, (string) $invoice['username'], $volumeGb, $days, [
            'reservation' => $selection,
            'snapshot' => $snapshot,
            'id_invoice' => (string) ($invoice['id_invoice'] ?? ''),
        ]);
        return [
            'selection' => $selection,
            'snapshot' => $usable,
            'preview' => $preview,
            'view' => rxReservationSettingsView($selection, $usable, (string) ($preview['text'] ?? '')),
        ];
    }
}
