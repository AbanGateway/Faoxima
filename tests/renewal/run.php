<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

const GIB = 1073741824;
const DAY = 86400;

$GLOBALS['rx_case_seq'] = 0;

function rx_new_service(PDO $pdo, string $type, string $method, array $state, array $invoice = []): array
{
    $n = ++$GLOBALS['rx_case_seq'];
    $panel = "p{$n}_{$type}";
    $code = "c{$n}";
    $username = "u{$n}";
    $idInvoice = "inv{$n}";
    $pdo->prepare("INSERT INTO marzban_panel (name_panel, code_panel, type, Methodextend, inbounds, version_panel) VALUES (?, ?, ?, ?, NULL, '0')")
        ->execute([$panel, $code, $type, $method]);
    $pdo->prepare("INSERT INTO invoice (id_invoice, id_user, username, Service_location, Status, time_sell, name_product, notifctions, user_info, uuid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cached', NULL)")
        ->execute([$idInvoice, '1001', $username, $panel, $invoice['Status'] ?? 'end_of_volume', (string) time(), $invoice['name_product'] ?? 'plan', json_encode(['volume' => true, 'time' => true])]);
    rx_test_user($panel, $type, $username, $state);
    return ['panel' => $panel, 'code' => $code, 'username' => $username, 'id_invoice' => $idInvoice];
}

function rx_invoice_status(PDO $pdo, string $idInvoice): string
{
    $stmt = $pdo->prepare("SELECT Status FROM invoice WHERE id_invoice = ?");
    $stmt->execute([$idInvoice]);
    return (string) $stmt->fetchColumn();
}

function rx_op_state(PDO $pdo, string $opId): string
{
    $stmt = $pdo->prepare("SELECT state FROM renewal_operation WHERE op_id = ?");
    $stmt->execute([$opId]);
    return (string) $stmt->fetchColumn();
}

function rx_reservation_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT * FROM queued_renewal WHERE id = ?");
    $stmt->execute([$id]);
    return (array) $stmt->fetch(PDO::FETCH_ASSOC);
}

function rx_make_due(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE queued_renewal SET next_attempt_at = NULL WHERE id = ?")->execute([$id]);
}

$mp = new RxFakeManagePanel();
$now = time();

rx_test_schema($pdo);

RxTest::section('Migration of legacy reservations');
$pdo->exec("CREATE TABLE queued_renewal (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_user varchar(500) NOT NULL, username varchar(1000) NOT NULL, name_panel varchar(500) NOT NULL,
    code_panel varchar(500) NOT NULL, code_product varchar(500) NOT NULL, new_limit_gb varchar(200) NOT NULL, time_day varchar(200) NOT NULL,
    price varchar(200) NOT NULL, id_invoice varchar(500) NULL, status varchar(200) NOT NULL DEFAULT 'pending', bottype varchar(200) NULL,
    created_at varchar(200) NOT NULL, applied_at varchar(200) NULL, output TEXT NULL,
    INDEX idx_queued_renewal_username_status (username(191), status(50))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
$legacy = $pdo->prepare("INSERT INTO queued_renewal (id_user, username, name_panel, code_panel, code_product, new_limit_gb, time_day, price, id_invoice, status, created_at, output) VALUES (?, ?, 'legacyp', 'legacyc', 'prodX', ?, ?, '1000', ?, ?, '2026/01/01 00:00:00', ?)");
$legacy->execute(['7', 'legacy1', '30', '30', 'L1', 'pending', null]);
$legacy->execute(['7', 'legacy1', '20', '10', 'L1', 'pending', null]);
$legacy->execute(['8', 'legacy2', '50', '60', '', 'failed', 'panel timeout']);
$legacy->execute(['9', 'legacy3', '10', '30', 'L3', 'applied', '{}']);
RxTest::ok(rx_rn_ensure_schema(), 'schema migration runs on a legacy table');
$rows = $pdo->query("SELECT * FROM queued_renewal ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
RxTest::eq($rows[0]['trigger_type'] . '|' . $rows[0]['policy'] . '|' . $rows[0]['legacy'], 'either|reset_all|1', 'legacy pending gets exhaustion-or-expiry trigger and full reset policy');
RxTest::eq($rows[0]['service_key'], 'inv:L1', 'legacy row receives stable service identity from invoice');
RxTest::eq($rows[0]['active_key'], 'inv:L1', 'first outstanding legacy reservation owns the active slot');
RxTest::eq($rows[1]['active_key'], null, 'duplicate legacy pending row does not violate uniqueness');
RxTest::eq($rows[1]['new_limit_gb'] . '/' . $rows[1]['time_day'], '20/10', 'paid quantities preserved');
RxTest::eq($rows[2]['status'], 'failed', 'legacy failed row stays terminal (not silently reactivated)');
RxTest::eq($rows[2]['service_key'], 'pu:legacyc|legacy2', 'invoice-less legacy row keyed by panel+username');
RxTest::eq($rows[3]['status'], 'applied', 'legacy applied row unchanged');
$idx = $pdo->query("SHOW INDEX FROM queued_renewal WHERE Key_name = 'uniq_qr_active_key'")->fetchAll();
RxTest::ok(count($idx) > 0, 'unique active-key index created');
RxTest::ok(rx_rn_ensure_schema(), 'migration is idempotent');
$pdo->exec("TRUNCATE queued_renewal");

RxTest::section('Existing methods vs. specification table');
$before = ['data_limit' => 80 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 10 * DAY, 'status' => 'active'];
$expect = [
    'ریست حجم و زمان' => [30, 0, 30],
    'اضافه شدن زمان و حجم به ماه بعد' => [110, 60, 40],
    'ریست زمان و اضافه کردن حجم قبلی' => [110, 60, 30],
    'ریست شدن حجم و اضافه شدن زمان' => [30, 0, 40],
    'اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده' => [50, 0, 40],
];
foreach ($expect as $method => [$limitGb, $usedGb, $days]) {
    $plan = rxBuildRenewalTarget($now, $method, $before, 30, 30);
    RxTest::ok(!empty($plan['ok']), "{$method}: plan accepted");
    RxTest::eq($plan['data_limit'], $limitGb * GIB, "{$method}: total limit {$limitGb} GB");
    RxTest::eq($plan['target_used_traffic'], $usedGb * GIB, "{$method}: usage counter {$usedGb} GB");
    RxTest::eq($plan['expire'], $now + $days * DAY, "{$method}: remaining duration {$days} days");
}
$queued = rxBuildRenewalTarget($now, 'رزرو اشتراک', $before, 30, 30);
RxTest::ok(!empty($queued['queued']) && !isset($queued['data_limit']), 'reservation method plans no immediate change');

RxTest::section('Exhaustion, overuse, expiry');
$over = ['data_limit' => 80 * GIB, 'used_traffic' => 81 * GIB, 'expire' => $now + 5 * DAY, 'status' => 'limited'];
$p = rxBuildRenewalTarget($now, 'اضافه شدن زمان و حجم به ماه بعد', $over, 30, 30);
RxTest::ok(!empty($p['ok']), 'overuse does not trigger invariant_traffic_discarded', $p['reason'] ?? null);
RxTest::eq($p['data_limit'], 111 * GIB, 'overuse + keep counter => 111 GB limit');
RxTest::eq($p['target_remaining'], 30 * GIB, 'overuse + keep counter => 30 GB usable');
RxTest::ok(!empty($p['enable']), 'limited service flagged for explicit reactivation');
$p = rxBuildRenewalTarget($now, 'اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده', $over, 30, 30);
RxTest::eq($p['data_limit'], 30 * GIB, 'overuse + reset counter => 30 GB limit');
$exact = ['data_limit' => 80 * GIB, 'used_traffic' => 80 * GIB, 'expire' => $now + 5 * DAY, 'status' => 'limited'];
$p = rxBuildRenewalTarget($now, 'اضافه شدن زمان و حجم به ماه بعد', $exact, 30, 30);
RxTest::eq($p['target_remaining'], 30 * GIB, 'exactly exhausted => purchased 30 GB usable');
$expired = ['data_limit' => 80 * GIB, 'used_traffic' => 10 * GIB, 'expire' => $now - 3 * DAY, 'status' => 'expired'];
$p = rxBuildRenewalTarget($now, 'اضافه شدن زمان و حجم به ماه بعد', $expired, 30, 30);
RxTest::eq($p['expire'], $now + 30 * DAY, 'expired + append => starts from execution time');
RxTest::ok(!empty($p['enable']), 'expired service flagged for explicit reactivation');

RxTest::section('Unlimited and finite/unlimited transitions');
$unl = ['data_limit' => 0, 'used_traffic' => 5 * GIB, 'expire' => 0, 'status' => 'active'];
$p = rxBuildRenewalTarget($now, 'ریست حجم و زمان', $unl, 30, 30);
RxTest::eq([$p['data_limit'], $p['expire']], [30 * GIB, $now + 30 * DAY], 'finite full reset on unlimited service establishes finite limits');
$p = rxBuildRenewalTarget($now, 'اضافه شدن زمان و حجم به ماه بعد', $unl, 30, 30);
RxTest::eq([$p['data_limit'], $p['expire']], [0, 0], 'carryover preserves unlimited dimensions');
$p = rxBuildRenewalTarget($now, 'اضافه شدن زمان و حجم به ماه بعد', $before, 0, 0);
RxTest::eq([$p['data_limit'], $p['expire']], [0, 0], 'purchased unlimited dimensions stay unlimited');
$p = rxRenewalCompute($now, 'traffic_only', false, $before, 30, 30);
RxTest::eq([$p['data_limit'], $p['target_used_traffic'], $p['expire']], [110 * GIB, 60 * GIB, $before['expire']], 'traffic-only leaves expiration unchanged');
$p = rxRenewalCompute($now, 'time_only', false, $before, 30, 30);
RxTest::eq([$p['data_limit'], $p['target_used_traffic'], $p['expire'], $p['reset_usage']], [80 * GIB, 60 * GIB, $now + 40 * DAY, false], 'time-only leaves traffic limit and usage unchanged');
$p = rxRenewalCompute($now, 'traffic_only', false, $expired, 30, 30);
RxTest::eq([$p['ok'], $p['reason']], [false, 'result_not_usable'], 'traffic-only on expired service is rejected before charging');
$p = rxRenewalCompute($now, 'time_only', false, $over, 30, 30);
RxTest::eq([$p['ok'], $p['reason']], [false, 'result_not_usable'], 'time-only on exhausted service is rejected before charging');
RxTest::ok(strpos(rxRenewalPreviewText($p), 'حجم قابل استفاده باقی نمی‌ماند') !== false, 'unusable result explained to user');

RxTest::section('Malformed / unavailable panel values');
$snap = rx_pf_snapshot_from_datauser(['status' => 'active', 'username' => 'x', 'used_traffic' => 1, 'expire' => 0]);
RxTest::eq([$snap['ok'], $snap['error']], [false, 'malformed_panel_values'], 'missing data_limit is not read as unlimited');
$snap = rx_pf_snapshot_from_datauser(['status' => 'active', 'username' => 'x', 'data_limit' => 'abc', 'used_traffic' => 1, 'expire' => 0]);
RxTest::eq($snap['ok'], false, 'non-numeric data_limit rejected');
$snap = rx_pf_snapshot_from_datauser(['status' => 'active', 'username' => 'x', 'data_limit' => 1, 'used_traffic' => 1, 'expire' => false]);
RxTest::eq($snap['ok'], false, 'boolean expire rejected');
$snap = rx_pf_snapshot_from_datauser(['status' => 'active', 'username' => 'x', 'data_limit' => null, 'used_traffic' => 1, 'expire' => null]);
RxTest::eq([$snap['ok'], $snap['data_limit'], $snap['expire']], [true, 0, 0], 'explicit null keeps the panel unlimited convention');
$snap = rx_pf_snapshot_from_datauser(['status' => 'active', 'username' => 'x', 'data_limit' => 1, 'used_traffic' => 1, 'expire' => time() + 3650 * DAY], 'remnawave');
RxTest::eq($snap['expire'], 0, 'remnawave far-future expiry normalized to unlimited at adapter boundary');
$p = rxRenewalCompute($now, 'reset_all', false, ['data_limit' => 1], 30, 30);
RxTest::eq($p['reason'], 'panel_state_unavailable', 'compute refuses incomplete panel state');

RxTest::section('Pending first connection');
$pending = ['data_limit' => 50 * GIB, 'used_traffic' => 0, 'expire' => 0, 'status' => 'on_hold', 'pending' => true, 'pending_duration' => 20 * DAY];
$p = rxRenewalCompute($now, 'carry_all', false, $pending, 30, 30, ['pending_supported' => true]);
RxTest::eq([$p['target_pending'], $p['target_pending_duration'], $p['expire']], [true, 50 * DAY, 0], 'append keeps pending state and extends pending duration');
$p = rxRenewalCompute($now, 'reset_all', false, $pending, 30, 30, ['pending_supported' => true]);
RxTest::eq([$p['target_pending'], $p['target_pending_duration']], [true, 30 * DAY], 'reset sets pending duration without starting countdown');
$p = rxRenewalCompute($now, 'carry_all', false, $pending, 30, 30, ['pending_supported' => false]);
RxTest::eq($p['reason'], 'pending_unsupported', 'unsupported panel explains limitation before charging');
$p = rxRenewalCompute($now, 'carry_all', false, array_merge($pending, ['pending_duration' => null]), 30, 30, ['pending_supported' => true]);
RxTest::eq($p['reason'], 'pending_unsupported', 'unknown pending duration is not guessed');

RxTest::section('Administrative blocking');
$p = rxRenewalCompute($now, 'carry_all', false, array_merge($before, ['status' => 'disabled']), 30, 30);
RxTest::eq($p['reason'], 'admin_blocked', 'panel-disabled service refused');
$p = rxRenewalCompute($now, 'carry_all', false, $before, 30, 30, ['admin_blocked' => true]);
RxTest::eq($p['reason'], 'admin_blocked', 'locally admin-blocked invoice refused');
RxTest::ok(strpos(rxRenewalReasonText('admin_blocked'), 'مدیریت') !== false, 'admin block has actionable explanation');

RxTest::section('Verification rules');
$plan = rxRenewalCompute($now, 'reset_all', false, $before, 30, 30);
$v = rxRenewalVerify(['ok' => true, 'exists' => true, 'data_limit' => 30 * GIB, 'used_traffic' => 200 * 1048576, 'expire' => $now + 30 * DAY, 'status' => 'active'], $plan);
RxTest::ok($v['ok'], 'legitimate post-reset usage accepted');
$v = rxRenewalVerify(['ok' => true, 'exists' => true, 'data_limit' => 30 * GIB, 'used_traffic' => 0, 'expire' => $now + 30 * DAY, 'status' => 'expired'], $plan);
RxTest::eq([$v['ok'], $v['checks']['status']], [false, false], 'panel still reporting expired fails verification');
$v = rxRenewalVerify(['ok' => true, 'exists' => true, 'data_limit' => 30 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 30 * DAY, 'status' => 'active'], $plan);
RxTest::eq([$v['ok'], $v['checks']['usage']], [false, false], 'missing usage reset detected');
$keep = rxRenewalCompute($now, 'carry_all', false, $before, 30, 30);
$v = rxRenewalVerify(['ok' => true, 'exists' => true, 'data_limit' => 110 * GIB, 'used_traffic' => 0, 'expire' => $now + 40 * DAY, 'status' => 'active'], $keep);
RxTest::eq($v['ok'], false, 'unexpected counter reset detected for keep-counter policy');
RxTest::eq(rxRenewalClassify(['ok' => true, 'exists' => true, 'data_limit' => 80 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $before['expire'], 'status' => 'active'], $keep), 'before', 'classify: untouched state is before');
RxTest::eq(rxRenewalClassify(['ok' => true, 'exists' => true, 'data_limit' => 110 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 40 * DAY, 'status' => 'active'], $keep), 'target', 'classify: target state');
RxTest::eq(rxRenewalClassify(['ok' => true, 'exists' => true, 'data_limit' => 999 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 40 * DAY, 'status' => 'active'], $keep), 'ambiguous', 'classify: foreign state is ambiguous');
RxTest::eq(rxRenewalClassify(['ok' => false], $keep), 'unknown', 'classify: unreadable is unknown');
RxTest::eq(rx_pf_classify(['ok' => true, 'exists' => true, 'data_limit' => 110 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 40 * DAY, 'status' => 'active'], [], $keep), 'target', 'rx_pf_classify delegates renewal plans');

RxTest::section('Custom identifiers');
RxTest::eq([rxRenewalNormalizeProductCode('customvolume'), rxRenewalNormalizeProductCode('custom_volume'), rxRenewalNormalizeProductCode('plan1')], ['custom_volume', 'custom_volume', 'plan1'], 'custom codes normalized');

$panelTypes = ['marzban', 'pasarguard', 'x-ui_single', 'guard', 'remnawave', 'rebecca', 'WGDashboard'];
RxTest::section('Panel execution (simulated adapters): spec example per method and panel');
foreach ($panelTypes as $type) {
    foreach ($expect as $method => [$limitGb, $usedGb, $days]) {
        $svc = rx_new_service($pdo, $type, $method, ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
        $res = $mp->extend($method, 30, 30, $svc['username'], 'customvolume', $svc['code']);
        $st = rx_test_state($svc['panel'], $svc['username']);
        $ok = !empty($res['status']) && !empty($res['rx_renewal']['verified']);
        RxTest::ok($ok, "{$type} / {$method}: verified success", $ok ? null : $res);
        RxTest::eq($st['limit'], $limitGb * GIB, "{$type} / {$method}: panel limit");
        RxTest::eq($st['used'], $usedGb * GIB, "{$type} / {$method}: panel usage counter");
        RxTest::near($st['expire'], $now + $days * DAY, 120, "{$type} / {$method}: panel expiry");
        RxTest::eq(rx_invoice_status($pdo, $svc['id_invoice']), 'active', "{$type} / {$method}: invoice active only after verification");
        RxTest::eq(rx_op_state($pdo, (string) ($res['operation_id'] ?? '')), 'verified', "{$type} / {$method}: operation persisted as verified");
    }
}

RxTest::section('Explicit reactivation (no status toggle)');
foreach (['marzban', 'pasarguard', 'rebecca', 'remnawave', 'x-ui_single'] as $type) {
    $svc = rx_new_service($pdo, $type, 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 80 * GIB, 'used' => 10 * GIB, 'expire' => $now - DAY, 'sticky' => 'expired']);
    $res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
    RxTest::ok(!empty($res['status']) && RxFakePanels::status(rx_test_state($svc['panel'], $svc['username'])) === 'active', "{$type}: expired service explicitly enabled", $res);
}
$toggles = array_filter(RxFakePanels::$calls, function ($c) {
    return isset($c[2]['status']) && $c[2]['status'] === 'disabled';
});
RxTest::eq(count($toggles), 0, 'no disable/toggle call issued during renewals');
$svc = rx_new_service($pdo, 'guard', 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 80 * GIB, 'used' => 10 * GIB, 'expire' => $now - DAY, 'sticky' => 'expired']);
$res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::ok(empty($res['status']) && rxRenewalIsPending($res), 'panel still reporting expired => not a successful renewal (pending verification)', $res);
RxTest::eq(rx_invoice_status($pdo, $svc['id_invoice']), 'end_of_volume', 'invoice not marked active while panel reports expired');

RxTest::section('Administrative blocking at execution');
$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 10 * GIB, 'expire' => $now + DAY, 'admin_disabled' => true]);
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::eq([$res['status'], $res['code'], $res['no_mutation']], [false, 'admin_blocked', true], 'admin-disabled service refused without mutation');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['admin_disabled'], true, 'administrator restriction preserved');
$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 10 * GIB, 'expire' => $now + DAY], ['Status' => 'disablebyadmin']);
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::eq($res['code'] ?? null, 'admin_blocked', 'locally admin-blocked invoice refused');

RxTest::section('Pending first connection on panels');
foreach (['marzban', 'x-ui_single'] as $type) {
    $svc = rx_new_service($pdo, $type, 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 50 * GIB, 'used' => 0, 'expire' => 0, 'pending_duration' => 20 * DAY]);
    $res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
    $st = rx_test_state($svc['panel'], $svc['username']);
    RxTest::ok(!empty($res['status']), "{$type}: pending renewal verified", $res);
    RxTest::eq([$st['pending_duration'], $st['expire']], [50 * DAY, 0], "{$type}: countdown not started; pending duration extended");
}
$svc = rx_new_service($pdo, 'guard', 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 50 * GIB, 'used' => 0, 'expire' => 0, 'pending_duration' => 20 * DAY]);
$res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::eq([$res['code'] ?? null, $res['no_mutation'] ?? null], ['pending_unsupported', true], 'guard pending service refused before mutation');

RxTest::section('Product removal and custom codes');
$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 10 * GIB, 'used' => 9 * GIB, 'expire' => $now + DAY]);
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'deleted_product_code', $svc['code']);
RxTest::ok(!empty($res['status']), 'renewal does not depend on product still being on sale', $res);
foreach (['customvolume', 'custom_volume'] as $code) {
    $svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 10 * GIB, 'used' => 9 * GIB, 'expire' => $now + DAY]);
    $res = $mp->extend('ریست حجم و زمان', 15, 7, $svc['username'], $code, $svc['code']);
    RxTest::ok(!empty($res['status']) && rx_test_state($svc['panel'], $svc['username'])['limit'] === 15 * GIB, "custom renewal via '{$code}' succeeds");
}

RxTest::section('Failures, partial execution, lost responses');
$svc = rx_new_service($pdo, 'marzban', 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['modify_reject' => 1];
$res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::eq([$res['status'], $res['no_mutation'] ?? null], [false, true], 'rejected write with unchanged panel => definite failure, safe to refund');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 80 * GIB, 'panel unchanged after rejection');
RxTest::eq(rx_invoice_status($pdo, $svc['id_invoice']), 'end_of_volume', 'invoice untouched after failure');
RxTest::eq(rx_op_state($pdo, (string) $res['operation_id']), 'failed', 'operation recorded as failed');

$svc = rx_new_service($pdo, 'marzban', 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['modify_lost' => 1];
$res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::ok(!empty($res['status']) && rx_test_state($svc['panel'], $svc['username'])['limit'] === 110 * GIB, 'lost response but applied => verified, applied once', $res);

$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['reset_reject' => 1];
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::eq([$res['status'], $res['code'], $res['no_mutation']], [false, 'reset_failed', true], 'failed usage reset aborts before any limit change');

$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['reset_lost' => 1];
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::ok(!empty($res['status']), 'reset applied with lost response is detected and execution continues', $res);

$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['modify_reject' => 1];
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::ok(!empty($res['status']) && rx_test_state($svc['panel'], $svc['username'])['limit'] === 30 * GIB, 'partial (reset done, write rejected once) recovered with absolute target', $res);

$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$faults[$k] = ['modify_reject' => 2];
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::ok(rxRenewalIsPending($res) && empty($res['no_mutation']), 'persistent partial failure (usage already reset) => pending, never a refundable failure', $res);
RxTest::eq([rx_test_state($svc['panel'], $svc['username'])['used'], rx_test_state($svc['panel'], $svc['username'])['limit']], [0, 80 * GIB], 'panel left partially applied');
$pdo->prepare("UPDATE renewal_operation SET next_attempt_at = 0 WHERE op_id = ?")->execute([(string) $res['operation_id']]);
rxRenewalOpsReconcile($mp, time(), 20);
RxTest::eq(rx_op_state($pdo, (string) $res['operation_id']), 'verified', 'reconciler completes the partial renewal');
RxTest::eq([rx_test_state($svc['panel'], $svc['username'])['limit'], rx_test_state($svc['panel'], $svc['username'])['used']], [30 * GIB, 0], 'purchased allowance delivered exactly once');

$svc = rx_new_service($pdo, 'marzban', 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$faults[$k] = ['read_skip' => 1, 'read_fail' => 3];
$res = $mp->extend('اضافه شدن زمان و حجم به ماه بعد', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::ok(empty($res['status']) && rxRenewalIsPending($res), 'unverifiable outcome reported as pending verification (no refund signal)', $res);
RxTest::ok(empty($res['no_mutation']), 'pending result never claims no mutation');
RxTest::eq(rx_invoice_status($pdo, $svc['id_invoice']), 'end_of_volume', 'invoice not marked successful while unverified');
RxTest::eq(rx_op_state($pdo, (string) $res['operation_id']), 'uncertain', 'operation persisted as uncertain');
$opId = (string) $res['operation_id'];
RxFakePanels::$faults[$k] = [];
$pdo->prepare("UPDATE renewal_operation SET next_attempt_at = 0 WHERE op_id = ?")->execute([$opId]);
$rec = rxRenewalOpsReconcile($mp, time(), 20);
RxTest::eq(rx_op_state($pdo, $opId), 'verified', 'reconciler verifies the earlier write');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 110 * GIB, 'reconciliation does not add traffic again');
RxTest::eq(rx_invoice_status($pdo, $svc['id_invoice']), 'active', 'invoice marked active after confirmed fulfillment');

$svc = rx_new_service($pdo, 'marzban', 'اضافه شدن زمان و حجم به ماه بعد', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$faults[$k] = ['modify_reject' => 1];
$plan = rxBuildRenewalTarget(time(), 'اضافه شدن زمان و حجم به ماه بعد', ['data_limit' => 80 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 10 * DAY, 'status' => 'active'], 30, 30);
rx_test_user($svc['panel'], 'marzban', $svc['username'], ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
RxFakePanels::$users[$k]['limit'] = 80 * GIB;
$res = $mp->applyStoredRenewalPlan($svc['panel'], $svc['username'], $plan, ['op_id' => 'op_partial_case_000000000000000', 'source' => 'wallet']);
RxTest::eq($res['no_mutation'] ?? null, true, 'write rejected with untouched panel stays a definite failure');
$res = $mp->applyStoredRenewalPlan($svc['panel'], $svc['username'], $plan, ['op_id' => 'op_partial_case_000000000000000', 'source' => 'wallet']);
RxTest::ok(!empty($res['status']), 'retry with same operation id applies stored absolute target', $res);
$res = $mp->applyStoredRenewalPlan($svc['panel'], $svc['username'], $plan, ['op_id' => 'op_partial_case_000000000000000', 'source' => 'wallet']);
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 110 * GIB, 'repeated execution of the same plan is idempotent');

RxTest::section('Service lock coordination');
RxTest::ok(rx_rn_lock_acquire('svc:test', str_repeat('a', 32)), 'first owner acquires lock');
RxTest::ok(!rx_rn_lock_acquire('svc:test', str_repeat('b', 32)), 'second owner refused while lease active');
RxTest::ok(rx_rn_lock_acquire('svc:test', str_repeat('a', 32)), 'owner can renew its lease');
rx_rn_lock_release('svc:test', str_repeat('a', 32));
RxTest::ok(rx_rn_lock_acquire('svc:test', str_repeat('b', 32)), 'released lock can be taken');
$pdo->exec("UPDATE renewal_lock SET lease_expires_at = 1 WHERE service_key = 'svc:test'");
RxTest::ok(rx_rn_lock_acquire('svc:test', str_repeat('c', 32)), 'expired lease can be taken over');
$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
rx_rn_lock_acquire('inv:' . $svc['id_invoice'], str_repeat('d', 32));
$res = $mp->extend('ریست حجم و زمان', 30, 30, $svc['username'], 'custom_volume', $svc['code']);
RxTest::eq([$res['code'] ?? null, $res['no_mutation'] ?? null], ['renewal_in_progress', true], 'renewal refused while another operation holds the service');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 80 * GIB, 'no conflicting mutation applied');
rx_rn_lock_release('inv:' . $svc['id_invoice'], str_repeat('d', 32));

RxTest::section('Reservation registration');
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$res = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_a', $svc['code'], null, ['source' => 'wallet', 'reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all', 'reset_usage' => true, 'payment_ref' => 'pay-1']]);
RxTest::ok(!empty($res['queued']) && !empty($res['reservation_id']), 'reservation registered', $res);
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 80 * GIB, 'current service untouched by registration');
$row = rx_reservation_row($pdo, (int) $res['reservation_id']);
RxTest::eq([$row['trigger_type'], $row['policy'], (int) $row['reset_usage'], $row['payment_ref'], $row['service_key']], ['traffic', 'carry_all', 1, 'pay-1', 'inv:' . $svc['id_invoice']], 'trigger, policy, payment and service identity stored');
$again = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_a', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all', 'payment_ref' => 'pay-1']]);
RxTest::ok(!empty($again['queued']) && (int) $again['reservation_id'] === (int) $res['reservation_id'], 'same payment registers idempotently');
$dup = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_a', $svc['code'], null, ['reservation' => ['trigger' => 'expire', 'policy' => 'reset_all', 'payment_ref' => 'pay-2']]);
RxTest::eq([$dup['status'], $dup['code']], [false, 'queued_renewal_exists'], 'second outstanding reservation for the service refused');
$pdo2 = rx_test_pdo();
$caught = '';
try {
    $pdo2->prepare("INSERT INTO queued_renewal (id_user, username, name_panel, code_panel, code_product, new_limit_gb, time_day, price, status, created_at, service_key, active_key) VALUES ('1','x','p','c','q','1','1','0','pending','now',?,?)")
        ->execute(['inv:' . $svc['id_invoice'], 'inv:' . $svc['id_invoice']]);
} catch (PDOException $e) {
    $caught = (string) $e->getCode();
}
RxTest::eq($caught, '23000', 'database enforces one outstanding reservation per service atomically');
$svcU = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 0, 'used' => 1 * GIB, 'expire' => $now + 10 * DAY]);
$bad = $mp->extend('رزرو اشتراک', 30, 30, $svcU['username'], 'plan_a', $svcU['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
RxTest::eq($bad['code'] ?? null, 'trigger_unavailable', 'unlimited traffic cannot satisfy traffic-exhaustion trigger');
RxTest::ok(strpos(rxRenewalFailureText($bad), 'نامحدود') !== false, 'unavailable trigger explained');
$badDate = $mp->extend('رزرو اشتراک', 30, 30, $svcU['username'], 'plan_a', $svcU['code'], null, ['reservation' => ['trigger' => 'date', 'policy' => 'carry_all']]);
RxTest::eq($badDate['code'] ?? null, 'trigger_date_required', 'date trigger requires a date');
$imm = $mp->extend('رزرو اشتراک', 30, 30, $svcU['username'], 'plan_a', $svcU['code'], null, ['reservation' => ['trigger' => 'immediate', 'policy' => 'reset_all']]);
RxTest::ok(!empty($imm['status']) && empty($imm['queued']) && rx_test_state($svcU['panel'], $svcU['username'])['limit'] === 30 * GIB, 'immediate execution through the ordinary flow applies selected policy', $imm);
RxTest::eq((int) $pdo->query("SELECT COUNT(*) FROM queued_renewal WHERE username = '{$svcU['username']}'")->fetchColumn(), 0, 'immediate execution registers no reservation');
$t = rxReservationParseDate('10', $now);
RxTest::eq($t, $now + 10 * DAY, 'date parser accepts day offsets');
RxTest::eq(rxReservationParseDate((string) ($now + 5 * DAY), $now), $now + 5 * DAY, 'date parser accepts unix timestamps');
RxTest::eq(rxReservationParseDate('1', $now - 400 * DAY) !== null, true, 'date parser relative to supplied clock');
RxTest::eq(rxReservationParseDate('9999', $now), null, 'out-of-range date rejected');

RxTest::section('Reservation triggers');
$snapBase = ['ok' => true, 'exists' => true, 'data_limit' => 80 * GIB, 'used_traffic' => 60 * GIB, 'expire' => $now + 12 * 3600, 'status' => 'active', 'pending' => false];
RxTest::eq(rxReservationTriggered(['trigger_type' => 'either'], $snapBase, $now), false, 'less than one day remaining is NOT expiration');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'expire'], array_merge($snapBase, ['expire' => $now - 1]), $now), true, 'actual expiration triggers expire');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'traffic'], array_merge($snapBase, ['expire' => $now - 1]), $now), false, 'traffic-only trigger ignores expiration');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'traffic'], array_merge($snapBase, ['used_traffic' => 80 * GIB]), $now), true, 'exhaustion triggers traffic');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'either'], array_merge($snapBase, ['used_traffic' => 80 * GIB]), $now), true, 'either: exhaustion first');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'either'], array_merge($snapBase, ['data_limit' => 0, 'used_traffic' => 900 * GIB]), $now), false, 'unlimited traffic never exhausts');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'date', 'trigger_at' => $now + 60], $snapBase, $now), false, 'date trigger waits');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'date', 'trigger_at' => $now - 1], $snapBase, $now), true, 'date trigger fires');
RxTest::eq(rxReservationTriggered(['trigger_type' => 'expire'], array_merge($snapBase, ['expire' => 0, 'pending' => true, 'status' => 'on_hold']), $now), false, 'pending first connection is not expiration');

RxTest::section('Reservation execution: every policy, values computed at activation');
$policyExpect = [
    'carry_all' => [false, 110, 80, 30],
    'reset_all' => [false, 30, 0, 30],
    'reset_traffic_append_time' => [false, 30, 0, 30],
    'carry_traffic_reset_time' => [false, 110, 80, 30],
    'traffic_only' => [false, 110, 80, null],
    'time_only' => [false, null, null, null],
];
foreach ($policyExpect as $policy => [$reset, $limitGb, $usedGb, $days]) {
    $trigger = $policy === 'time_only' ? 'expire' : 'traffic';
    $svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
    $reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => $trigger, 'policy' => $policy]]);
    $id = (int) ($reg['reservation_id'] ?? 0);
    $pdo->prepare("UPDATE marzban_panel SET Methodextend = 'ریست حجم و زمان' WHERE code_panel = ?")->execute([$svc['code']]);
    $k = RxFakePanels::key($svc['panel'], $svc['username']);
    $out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
    RxTest::eq($out['outcome'], 'waiting', "{$policy}: not triggered while service usable");
    if ($policy === 'time_only') {
        RxFakePanels::$users[$k]['expire'] = $now - 60;
    } else {
        RxFakePanels::$users[$k]['used'] = 80 * GIB;
    }
    $activationExpire = RxFakePanels::$users[$k]['expire'];
    rx_make_due($pdo, $id);
    $out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
    $st = rx_test_state($svc['panel'], $svc['username']);
    RxTest::eq($out['outcome'], 'applied', "{$policy}: applied on trigger");
    if ($policy === 'time_only') {
        RxTest::eq([$st['limit'], $st['used']], [80 * GIB, 60 * GIB], "{$policy}: traffic untouched");
        RxTest::near($st['expire'], time() + 30 * DAY, 120, "{$policy}: expired => purchased time from activation");
    } else {
        RxTest::eq($st['limit'], $limitGb * GIB, "{$policy}: limit from activation-time remaining (consumed traffic not returned)");
        RxTest::eq($st['used'], $usedGb * GIB, "{$policy}: usage counter");
        if ($days === null) {
            RxTest::eq($st['expire'], $activationExpire, "{$policy}: expiration unchanged");
        } elseif (in_array($policy, ['carry_all', 'reset_traffic_append_time'], true)) {
            RxTest::near($st['expire'], $now + 40 * DAY, 120, "{$policy}: remaining + purchased duration");
        } else {
            RxTest::near($st['expire'], time() + 30 * DAY, 120, "{$policy}: duration from activation");
        }
    }
    $row = rx_reservation_row($pdo, $id);
    RxTest::eq([$row['status'], $row['active_key']], ['applied', null], "{$policy}: terminal state frees service slot");
    RxTest::eq((int) $pdo->query("SELECT COUNT(*) FROM queued_renewal WHERE username = '{$svc['username']}'")->fetchColumn(), 1, "{$policy}: execution did not register another reservation");
}
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all', 'reset_usage' => true]]);
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$users[$k]['used'] = 85 * GIB;
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, (int) $reg['reservation_id']), time());
$st = rx_test_state($svc['panel'], $svc['username']);
RxTest::eq([$out['outcome'], $st['limit'], $st['used']], ['applied', 30 * GIB, 0], 'carry + reset counter with overuse at activation => purchased traffic intact');

RxTest::section('Reservation lifecycle: temporary failure, uncertainty, stale leases, blocking');
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'either', 'policy' => 'reset_all']]);
$id = (int) $reg['reservation_id'];
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$faults[$k] = ['modify_reject' => 2];
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
$row = rx_reservation_row($pdo, $id);
RxTest::ok(in_array($row['status'], ['retry', 'reconciling'], true), 'temporary activation error is retryable, not permanently failed', $row['status']);
RxTest::ok((int) $row['next_attempt_at'] > time() && (int) $row['attempt_count'] === 1 && (string) $row['last_error'] !== '', 'attempts, next retry time and diagnostics persisted');
RxFakePanels::$faults[$k] = [];
rx_make_due($pdo, $id);
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
RxTest::eq($out['outcome'], 'applied', 'retry succeeds after backoff');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 30 * GIB, 'retry applied exactly once');

$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
$id = (int) $reg['reservation_id'];
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$faults[$k] = ['modify_lost' => 1, 'read_skip' => 3, 'read_fail' => 4];
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
RxTest::eq(rx_reservation_row($pdo, $id)['status'], 'reconciling', 'lost response + unreadable panel => reconciling (uncertain)');
RxFakePanels::$faults[$k] = [];
rx_make_due($pdo, $id);
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
RxTest::eq([$out['outcome'], rx_test_state($svc['panel'], $svc['username'])['limit']], ['applied', 110 * GIB], 'reconciliation confirms without adding traffic again');

$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
$id = (int) $reg['reservation_id'];
$plan = rxBuildRenewalTarget(time(), 'اضافه شدن زمان و حجم به ماه بعد', ['data_limit' => 80 * GIB, 'used_traffic' => 80 * GIB, 'expire' => $now + 10 * DAY, 'status' => 'limited'], 30, 30);
$pdo->prepare("UPDATE queued_renewal SET status = 'processing', processing_token = ?, lease_expires_at = 1, stage = 'applying', target_state = ? WHERE id = ?")
    ->execute([str_repeat('e', 32), json_encode($plan), $id]);
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$users[$k]['limit'] = 110 * GIB;
RxFakePanels::$users[$k]['sticky'] = null;
RxTest::eq(rxReservationRecoverStale(time()), 1, 'interrupted processing lease recovered');
RxTest::eq(rx_reservation_row($pdo, $id)['status'], 'reconciling', 'interrupted mid-apply => reconciling, not fresh retry');
rx_make_due($pdo, $id);
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
RxTest::eq([$out['outcome'], rx_test_state($svc['panel'], $svc['username'])['limit']], ['applied', 110 * GIB], 'completed step detected; no duplicate renewal');

$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'reset_all']]);
$id = (int) $reg['reservation_id'];
$k = RxFakePanels::key($svc['panel'], $svc['username']);
RxFakePanels::$users[$k]['admin_disabled'] = true;
rx_make_due($pdo, $id);
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
$row = rx_reservation_row($pdo, $id);
RxTest::eq([$row['status'], $row['last_error']], ['retry', 'admin_blocked'], 'panel admin-disabled at activation => waits, not applied');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['admin_disabled'], true, 'administrator restriction not silently removed');
RxFakePanels::$users[$k]['admin_disabled'] = false;
$pdo->prepare("UPDATE queued_renewal SET status = 'pending', next_attempt_at = NULL WHERE id = ?")->execute([$id]);
RxFakePanels::$users[$k]['sticky'] = null;
$pdo->prepare("UPDATE invoice SET Status = 'disablebyadmin' WHERE id_invoice = ?")->execute([$svc['id_invoice']]);
rx_make_due($pdo, $id);
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
$row = rx_reservation_row($pdo, $id);
RxTest::eq($row['status'], 'retry', 'locally admin-blocked at activation => waits (retry), restriction not removed');
RxTest::ok((int) $row['next_attempt_at'] >= time() + RX_RN_BLOCKED_RECHECK - 5, 'blocked reservation rechecked with long backoff');
RxTest::eq(rx_test_state($svc['panel'], $svc['username'])['limit'], 80 * GIB, 'blocked service not modified');

$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'reset_all']]);
$id = (int) $reg['reservation_id'];
$pdo->prepare("UPDATE queued_renewal SET attempt_count = ? WHERE id = ?")->execute([RX_RN_MAX_ATTEMPTS - 1, $id]);
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['modify_reject' => 3];
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
$row = rx_reservation_row($pdo, $id);
RxTest::eq([$row['status'], $row['active_key']], ['failed', 'inv:' . $svc['id_invoice']], 'bounded retries end in permanent failure; slot kept for intervention');

RxTest::section('Concurrent and repeated execution');
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
$id = (int) $reg['reservation_id'];
RxTest::ok(rxReservationClaim($id, 'pending', str_repeat('f', 32), time()), 'first worker claims');
RxTest::ok(!rxReservationClaim($id, 'pending', str_repeat('g', 32), time()), 'second worker cannot claim the same row');
$pdo->prepare("UPDATE queued_renewal SET status = 'pending', processing_token = NULL WHERE id = ?")->execute([$id]);
rx_rn_lock_acquire('inv:' . $svc['id_invoice'], str_repeat('h', 32));
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
RxTest::eq([$out['outcome'], rx_reservation_row($pdo, $id)['status']], ['busy', 'pending'], 'activation yields to an in-flight immediate renewal');
rx_rn_lock_release('inv:' . $svc['id_invoice'], str_repeat('h', 32));
rx_make_due($pdo, $id);
rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, $id), time());
RxTest::eq([rx_reservation_row($pdo, $id)['status'], rx_test_state($svc['panel'], $svc['username'])['limit']], ['applied', 110 * GIB], 'repeated processing applies once');

RxTest::section('Product removal and panel-setting change after purchase');
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 80 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$pdo->prepare("INSERT INTO product (code_product, name_product, price_product, Volume_constraint, Service_time, inbounds) VALUES ('sold_out', 'Plan', '100', '30', '30', NULL)")->execute();
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'sold_out', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
$pdo->exec("DELETE FROM product WHERE code_product = 'sold_out'");
$pdo->prepare("UPDATE marzban_panel SET Methodextend = 'ریست حجم و زمان' WHERE code_panel = ?")->execute([$svc['code']]);
$out = rxReservationProcessRow($mp, rx_reservation_row($pdo, (int) $reg['reservation_id']), time());
RxTest::eq([$out['outcome'], rx_test_state($svc['panel'], $svc['username'])['limit']], ['applied', 110 * GIB], 'stored policy executes after product deletion and method change');

RxTest::section('Cleanup protection across unresolved states');
rx_test_load_class(dirname(__DIR__, 2) . '/cronbot/NoticationsService.php', 'ServiceMonitor');
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 10 * GIB, 'expire' => $now + 10 * DAY], ['Status' => 'active']);
$reg = $mp->extend('رزرو اشتراک', 30, 30, $svc['username'], 'plan_x', $svc['code'], null, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
$ref = new ReflectionClass('ServiceMonitor');
$service = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty('pdo');
$prop->setAccessible(true);
$prop->setValue($service, $pdo);
$method = $ref->getMethod('hasPendingQueuedRenewal');
$method->setAccessible(true);
foreach (['pending', 'processing', 'retry', 'reconciling'] as $state) {
    $pdo->prepare("UPDATE queued_renewal SET status = ? WHERE id = ?")->execute([$state, (int) $reg['reservation_id']]);
    RxTest::ok($method->invoke($service, $svc['username']), "cleanup skips service while reservation is '{$state}'");
}
foreach (['applied', 'failed'] as $state) {
    $pdo->prepare("UPDATE queued_renewal SET status = ? WHERE id = ?")->execute([$state, (int) $reg['reservation_id']]);
    RxTest::ok(!$method->invoke($service, $svc['username']), "cleanup no longer held once '{$state}'");
}

RxTest::section('Preview and localization');
$svc = rx_new_service($pdo, 'marzban', 'ریست حجم و زمان', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
$panelRow = select('marzban_panel', '*', 'name_panel', $svc['panel']);
$pv = rxRenewalPreviewForService($mp, $panelRow, $svc['username'], 30, 30);
RxTest::ok(!$pv['blocked'] && strpos($pv['text'], 'حذف می‌شود') !== false && strpos($pv['text'], 'صفر می‌شود') !== false, 'preview states discarded remaining allowance and usage reset');
RxFakePanels::$faults[RxFakePanels::key($svc['panel'], $svc['username'])] = ['read_fail' => 1];
$pv = rxRenewalPreviewForService($mp, $panelRow, $svc['username'], 30, 30);
RxTest::eq([$pv['blocked'], $pv['reason']], [false, 'panel_unreachable'], 'temporary preview failure does not block renewal');
$svc = rx_new_service($pdo, 'marzban', 'اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
$pv = rxRenewalPreviewForService($mp, select('marzban_panel', '*', 'name_panel', $svc['panel']), $svc['username'], 30, 30);
RxTest::ok(strpos($pv['text'], 'به معنی حذف حجم باقی‌مانده نیست') !== false, 'preview explains counter reset vs. discarding allowance');
$svc = rx_new_service($pdo, 'marzban', 'رزرو اشتراک', ['limit' => 80 * GIB, 'used' => 60 * GIB, 'expire' => $now + 10 * DAY]);
$pv = rxRenewalPreviewForService($mp, select('marzban_panel', '*', 'name_panel', $svc['panel']), $svc['username'], 30, 30, ['reservation' => ['trigger' => 'traffic', 'policy' => 'carry_all']]);
RxTest::ok($pv['mode'] === 'reservation' && strpos($pv['text'], 'فقط اتمام حجم') !== false && strpos($pv['text'], 'لحظه فعال‌سازی') !== false, 'reservation preview shows trigger, policy and activation-time note');
$view = rxReservationSettingsView(['trigger' => 'date', 'trigger_at' => null, 'policy' => 'carry_all', 'reset_usage' => false], ['ok' => true, 'exists' => true, 'data_limit' => 0, 'used_traffic' => 0, 'expire' => 0, 'pending' => false]);
RxTest::ok(strpos($view['text'], 'تعیین نشده') !== false && strpos($view['keyboard'], 'rsvd') !== false && strpos($view['text'], '⛔️') !== false, 'bot settings view shows unavailable triggers and date control');
RxTest::eq(count(array_diff(array_keys(rxRenewalPolicies()), array_map(function ($k) {
    return substr($k, 7);
}, array_filter(array_keys(rxRenewalTextDefaults()), function ($k) {
    return strpos($k, 'policy_') === 0 && strpos($k, 'policy_short_') !== 0 && $k !== 'policy_usage_reset_suffix';
})))), 0, 'every policy has a localized label');

RxTest::section('Diagnostics hygiene');
$secretHits = 0;
foreach ($GLOBALS['rx_test_logs'] as $log) {
    $blob = json_encode($log);
    if (preg_match('/password|token|api_key|secret/i', (string) $blob) && !preg_match('/processing_token|"op_id"/', (string) $blob)) {
        $secretHits++;
    }
}
RxTest::eq($secretHits, 0, 'renewal diagnostics never include credentials');
$hasDiag = false;
foreach ($GLOBALS['rx_test_logs'] as $log) {
    if ($log['event'] === 'RENEWAL_APPLY_UNCERTAIN' && isset($log['ctx']['operation_id'], $log['ctx']['service_key'], $log['ctx']['panel'], $log['ctx']['policy'], $log['ctx']['before'], $log['ctx']['target'])) {
        $hasDiag = true;
    }
}
RxTest::ok($hasDiag, 'uncertain outcomes log operation/service/panel/policy and before/target');

echo "\n----\nPASS: " . RxTest::$pass . "  FAIL: " . RxTest::$fail . "\n";
foreach (RxTest::$failures as $f) {
    echo " - {$f}\n";
}
exit(RxTest::$fail === 0 ? 0 : 1);
