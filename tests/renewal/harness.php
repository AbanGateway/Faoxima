<?php

declare(strict_types=1);

if (!defined('RX_RN_VERIFY_DELAYS')) {
    define('RX_RN_VERIFY_DELAYS', '0,0,0');
}

$GLOBALS['rx_test_logs'] = [];
$GLOBALS['rx_test_messages'] = [];

function rx_test_pdo(): PDO
{
    $dsn = getenv('RX_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3399;dbname=rx_test;charset=utf8mb4';
    $user = getenv('RX_TEST_DB_USER') ?: 'root';
    $pass = getenv('RX_TEST_DB_PASS') ?: '';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

$pdo = rx_test_pdo();

function rx_log_event($event, $message, $ctx = []): void
{
    $GLOBALS['rx_test_logs'][] = ['event' => $event, 'message' => $message, 'ctx' => $ctx];
}

function select($table, $field, $whereField = null, $whereValue = null, $type = 'select')
{
    global $pdo;
    try {
        if ($whereField === null) {
            $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$whereField}` = ? LIMIT 1");
            $stmt->execute([$whereValue]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        return false;
    }
    return $row === false ? false : $row;
}

function update($table, $field, $value, $whereField = null, $whereValue = null)
{
    global $pdo;
    if ($whereField === null) {
        $stmt = $pdo->prepare("UPDATE `{$table}` SET `{$field}` = ?");
        $stmt->execute([$value]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE `{$table}` SET `{$field}` = ? WHERE `{$whereField}` = ?");
    $stmt->execute([$value, $whereValue]);
}

function telegram($method, $data = [])
{
    $GLOBALS['rx_test_messages'][] = ['method' => $method, 'data' => $data];
    return ['ok' => true];
}

function sendmessage($chatId, $text, $keyboard = null, $parse = 'HTML', $bottype = null)
{
    $GLOBALS['rx_test_messages'][] = ['method' => 'sendmessage', 'chat_id' => $chatId, 'text' => $text];
    return ['ok' => true];
}

function faoxima_textbot_get($id, $fallback = '')
{
    return $fallback;
}

function faoxima_render_text($tpl, array $vars = [])
{
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', (string) $v, (string) $tpl);
    }
    return $tpl;
}

final class RxFakePanels
{
    public static array $users = [];
    public static array $faults = [];
    public static array $calls = [];
    public static array $remnaIds = [];

    public static function key(string $panel, string $username): string
    {
        return $panel . '|' . $username;
    }

    public static function fault(string $key, string $name): bool
    {
        if (!empty(self::$faults[$key][$name])) {
            self::$faults[$key][$name]--;
            return true;
        }
        return false;
    }

    public static function status(array $s): string
    {
        $now = time();
        if (!empty($s['admin_disabled'])) {
            return 'disabled';
        }
        if (!empty($s['sticky']) && in_array($s['sticky'], ['expired', 'limited'], true)) {
            return $s['sticky'];
        }
        if ($s['pending_duration'] !== null) {
            return 'on_hold';
        }
        if ($s['limit'] > 0 && $s['used'] >= $s['limit']) {
            return 'limited';
        }
        if ($s['expire'] > 0 && $s['expire'] <= $now) {
            return 'expired';
        }
        return 'active';
    }

    public static function reset(): void
    {
        self::$users = [];
        self::$faults = [];
        self::$calls = [];
        self::$remnaIds = [];
    }
}

function rx_test_user(string $panel, string $type, string $username, array $state): void
{
    RxFakePanels::$users[RxFakePanels::key($panel, $username)] = array_merge([
        'type' => $type,
        'limit' => 0,
        'used' => 0,
        'expire' => 0,
        'pending_duration' => null,
        'admin_disabled' => false,
        'sticky' => null,
        'wg_jobs' => [],
    ], $state);
    if ($type === 'remnawave') {
        RxFakePanels::$remnaIds[count(RxFakePanels::$remnaIds) + 1] = RxFakePanels::key($panel, $username);
    }
    if ($type === 'WGDashboard') {
        rx_test_wg_sync_jobs(RxFakePanels::key($panel, $username));
    }
}

function rx_test_wg_sync_jobs(string $key): void
{
    $s =& RxFakePanels::$users[$key];
    $jobs = [];
    if ($s['expire'] > 0) {
        $jobs[] = ['Field' => 'date', 'Value' => date('Y-m-d H:i:s', $s['expire'])];
    }
    if ($s['limit'] > 0) {
        $jobs[] = ['Field' => 'total_data', 'Value' => $s['limit'] / pow(1024, 3)];
    }
    $s['wg_jobs'] = $jobs;
}

function rx_test_wg_from_jobs(string $key): void
{
    $s =& RxFakePanels::$users[$key];
    $s['expire'] = 0;
    $s['limit'] = 0;
    foreach ($s['wg_jobs'] as $job) {
        if ($job['Field'] === 'date') {
            $s['expire'] = (int) strtotime($job['Value']);
        } elseif ($job['Field'] === 'total_data') {
            $s['limit'] = (int) round($job['Value'] * pow(1024, 3));
        }
    }
}

function rx_test_state(string $panel, string $username): array
{
    return RxFakePanels::$users[RxFakePanels::key($panel, $username)];
}

function remnawave_find_user($username)
{
    foreach (RxFakePanels::$remnaIds as $id => $key) {
        if (substr($key, strpos($key, '|') + 1) === (string) $username) {
            return ['panel_user_id' => $id, 'username' => $username];
        }
    }
    return null;
}

function remnawave_iso_from_timestamp($timestamp)
{
    $ts = (int) $timestamp;
    if ($ts <= 0) {
        $ts = time() + (3650 * 86400);
    }
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

final class RxFakeRemnaManager
{
    public function updateUser(array $params, $token = null, $pdo = null)
    {
        $key = RxFakePanels::$remnaIds[(int) $params['id']] ?? null;
        if ($key === null) {
            return ['ok' => false, 'data' => null];
        }
        RxFakePanels::$calls[] = ['remnawave_update', $key, $params];
        if (RxFakePanels::fault($key, 'modify_reject')) {
            return ['ok' => false, 'data' => null];
        }
        $s =& RxFakePanels::$users[$key];
        if (array_key_exists('trafficLimitBytes', $params)) {
            $s['limit'] = (int) $params['trafficLimitBytes'];
        }
        if (array_key_exists('expireAt', $params)) {
            $s['expire'] = (int) strtotime((string) $params['expireAt']);
        }
        if (($params['status'] ?? '') === 'ACTIVE') {
            $s['sticky'] = null;
        }
        if (RxFakePanels::fault($key, 'modify_lost')) {
            return ['ok' => false, 'data' => null];
        }
        return ['ok' => true, 'data' => []];
    }
}

function remnawave_manager_for($panelName)
{
    return new RxFakeRemnaManager();
}

function guardResolveServiceIds($panel, $source)
{
    return ['status' => true, 'service_ids' => [1]];
}

function guardNormalizeExpire($timestamp)
{
    $timestamp = intval($timestamp);
    if ($timestamp <= 0) {
        return time() + (86400 * 365 * 10);
    }
    if ($timestamp <= time()) {
        return time() + 300;
    }
    return $timestamp;
}

function rx_test_wg_key(string $username): ?string
{
    foreach (RxFakePanels::$users as $key => $s) {
        if ($s['type'] === 'WGDashboard' && substr($key, strpos($key, '|') + 1) === $username) {
            return $key;
        }
    }
    return null;
}

function allowAccessPeers($panel, $username)
{
    return ['status' => true];
}

function get_userwg($username, $panel)
{
    $key = rx_test_wg_key((string) $username);
    if ($key === null) {
        return ['status' => false, 'msg' => 'not found'];
    }
    return ['id' => $username, 'jobs' => RxFakePanels::$users[$key]['wg_jobs']];
}

function deletejob($panel, $data)
{
    foreach (RxFakePanels::$users as $key => &$s) {
        if ($s['type'] !== 'WGDashboard') {
            continue;
        }
        foreach ($s['wg_jobs'] as $i => $job) {
            if ($job === $data['Job']) {
                unset($s['wg_jobs'][$i]);
                $s['wg_jobs'] = array_values($s['wg_jobs']);
                rx_test_wg_from_jobs($key);
                return ['status' => true];
            }
        }
    }
    return ['status' => true];
}

function setjob($panel, $field, $value, $id)
{
    $key = rx_test_wg_key((string) $id);
    if ($key === null) {
        return ['status' => false, 'msg' => 'peer missing'];
    }
    if (RxFakePanels::fault($key, 'modify_reject')) {
        return ['status' => false, 'msg' => 'job rejected'];
    }
    RxFakePanels::$users[$key]['wg_jobs'][] = ['Field' => $field, 'Value' => $value];
    rx_test_wg_from_jobs($key);
    return ['status' => true];
}

function rx_test_load_class(string $file, string $class): void
{
    if (class_exists($class, false)) {
        return;
    }
    $source = (string) file_get_contents($file);
    $start = strpos($source, "class {$class}");
    $end = strpos($source, "\n}\n", (int) $start);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_class_under_test_' . md5($source) . '.php';
    file_put_contents($tmp, "<?php\n" . substr($source, (int) $start, $end - $start + 3));
    require_once $tmp;
}

function rx_test_load_manage_panel(string $root): void
{
    if (class_exists('ManagePanel', false)) {
        return;
    }
    $source = (string) file_get_contents($root . '/panels.php');
    $source = preg_replace('/^require_once __DIR__ \. \'\/[^\']+\';\s*$/m', '', $source);
    $source = preg_replace('/^ini_set\(\'error_log\', \'error_log\'\);\s*$/m', '', $source);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_panels_under_test_' . md5($source) . '.php';
    file_put_contents($tmp, $source);
    require_once $tmp;
}

$rxRoot = dirname(__DIR__, 2);
require_once $rxRoot . '/re/rx/function/payment_fulfillment.php';
require_once $rxRoot . '/re/rx/function/renewal_reservation.php';
rx_test_load_manage_panel($rxRoot);

final class RxFakeManagePanel extends ManagePanel
{
    public function DataUser($name_panel, $username, $client_type = 'links')
    {
        $key = RxFakePanels::key((string) $name_panel, (string) $username);
        RxFakePanels::$calls[] = ['read', $key];
        if (!isset(RxFakePanels::$users[$key])) {
            return ['status' => 'Unsuccessful', 'msg' => 'User not found'];
        }
        if (!RxFakePanels::fault($key, 'read_skip') && RxFakePanels::fault($key, 'read_fail')) {
            return ['status' => 'Unsuccessful', 'msg' => 'connection timed out'];
        }
        $s = RxFakePanels::$users[$key];
        if (RxFakePanels::fault($key, 'malformed')) {
            return ['status' => 'active', 'username' => $username, 'data_limit' => 'n/a', 'used_traffic' => 1, 'expire' => 0];
        }
        $status = RxFakePanels::status($s);
        switch ($s['type']) {
            case 'marzban':
            case 'pasarguard':
            case 'rebecca':
                return [
                    'status' => $status,
                    'username' => $username,
                    'data_limit' => $s['limit'] > 0 ? $s['limit'] : null,
                    'expire' => $s['expire'] > 0 ? $s['expire'] : null,
                    'used_traffic' => $s['used'],
                    'on_hold_expire_duration' => $s['pending_duration'],
                ];
            case 'x-ui_single':
                return [
                    'status' => $status,
                    'username' => $username,
                    'data_limit' => $s['limit'],
                    'expire' => $s['pending_duration'] !== null ? 0 : $s['expire'],
                    'used_traffic' => $s['used'],
                    'on_hold_expire_duration' => $s['pending_duration'],
                ];
            case 'guard':
            case 'remnawave':
                return [
                    'status' => $status,
                    'username' => $username,
                    'data_limit' => $s['limit'],
                    'expire' => $s['expire'] > 0 ? $s['expire'] : time() + 3650 * 86400,
                    'used_traffic' => $s['used'],
                ];
            case 'WGDashboard':
                return [
                    'status' => $status,
                    'username' => $username,
                    'data_limit' => $s['limit'],
                    'expire' => $s['expire'],
                    'used_traffic' => $s['used'],
                ];
        }
        return ['status' => 'Unsuccessful', 'msg' => 'Panel Not Found'];
    }

    public function Modifyuser($username, $name_panel, $config = array())
    {
        $key = RxFakePanels::key((string) $name_panel, (string) $username);
        RxFakePanels::$calls[] = ['modify', $key, $config];
        if (!isset(RxFakePanels::$users[$key])) {
            return ['status' => false, 'msg' => 'User not found'];
        }
        if (RxFakePanels::fault($key, 'modify_reject')) {
            return ['status' => false, 'msg' => 'error code : 500'];
        }
        $s =& RxFakePanels::$users[$key];
        if ($s['type'] === 'x-ui_single') {
            $client = json_decode((string) $config['settings'], true)['clients'][0] ?? [];
            if (array_key_exists('totalGB', $client)) {
                $s['limit'] = (int) $client['totalGB'];
            }
            if (array_key_exists('expiryTime', $client)) {
                $ms = (int) $client['expiryTime'];
                if ($ms < 0) {
                    $s['pending_duration'] = (int) round(-$ms / 1000);
                    $s['expire'] = 0;
                } else {
                    $s['pending_duration'] = null;
                    $s['expire'] = (int) round($ms / 1000);
                }
            }
            if (!empty($client['enable'])) {
                $s['sticky'] = null;
            }
        } elseif ($s['type'] === 'guard') {
            if (array_key_exists('limit_usage', $config)) {
                $s['limit'] = (int) $config['limit_usage'];
            }
            if (array_key_exists('limit_expire', $config)) {
                $exp = (int) $config['limit_expire'];
                $s['expire'] = $exp > time() + 9 * 365 * 86400 ? 0 : $exp;
            }
        } else {
            if (array_key_exists('data_limit', $config)) {
                $s['limit'] = (int) ($config['data_limit'] ?? 0);
            }
            if (array_key_exists('expire', $config)) {
                $exp = $config['expire'];
                $s['expire'] = is_numeric($exp) ? (int) $exp : (int) strtotime((string) $exp);
            }
            if (($config['status'] ?? '') === 'on_hold') {
                $s['pending_duration'] = (int) $config['on_hold_expire_duration'];
                $s['expire'] = 0;
            } elseif (($config['status'] ?? '') === 'active') {
                $s['pending_duration'] = null;
                $s['sticky'] = null;
            }
        }
        if (RxFakePanels::fault($key, 'modify_lost')) {
            return ['status' => false, 'msg' => 'Operation timed out'];
        }
        return ['status' => true, 'data' => []];
    }

    public function ResetUserDataUsage($username, $name_panel)
    {
        $key = RxFakePanels::key((string) $name_panel, (string) $username);
        RxFakePanels::$calls[] = ['reset', $key];
        if (RxFakePanels::fault($key, 'reset_reject')) {
            return ['status' => false, 'msg' => 'reset refused'];
        }
        RxFakePanels::$users[$key]['used'] = 0;
        if (RxFakePanels::fault($key, 'reset_lost')) {
            return ['status' => false, 'msg' => 'timeout'];
        }
        return ['status' => true];
    }
}

function rx_test_schema(PDO $pdo): void
{
    foreach (['renewal_lock', 'renewal_operation', 'queued_renewal', 'invoice', 'marzban_panel', 'product', 'manualsell'] as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->exec("CREATE TABLE marzban_panel (id INT AUTO_INCREMENT PRIMARY KEY, name_panel VARCHAR(191), code_panel VARCHAR(191), type VARCHAR(50), Methodextend VARCHAR(100), inbounds TEXT NULL, version_panel VARCHAR(10) NULL, status_extend VARCHAR(30) NULL, inboundid VARCHAR(50) NULL, guard_service_ids TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE invoice (id INT AUTO_INCREMENT PRIMARY KEY, id_invoice VARCHAR(191), id_user VARCHAR(191), username VARCHAR(191), Service_location VARCHAR(191), Status VARCHAR(50), time_sell VARCHAR(50), name_product VARCHAR(191), price_product VARCHAR(50) NULL, Volume VARCHAR(50) NULL, Service_time VARCHAR(50) NULL, notifctions TEXT NULL, user_info TEXT NULL, uuid TEXT NULL, bottype VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE product (id INT AUTO_INCREMENT PRIMARY KEY, code_product VARCHAR(191), name_product VARCHAR(191), price_product VARCHAR(50), Volume_constraint VARCHAR(50), Service_time VARCHAR(50), inbounds TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE manualsell (id INT AUTO_INCREMENT PRIMARY KEY, codepanel VARCHAR(191), codeproduct VARCHAR(191), status VARCHAR(30), username VARCHAR(191) NULL, contentrecord TEXT, file_ext VARCHAR(20) NULL, group_id VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
}

final class RxTest
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    public static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n== {$name} ==\n";
    }

    public static function ok(bool $cond, string $label, $detail = null): void
    {
        if ($cond) {
            self::$pass++;
            echo "  [PASS] {$label}\n";
            return;
        }
        self::$fail++;
        $msg = self::$section . ' :: ' . $label . ($detail !== null ? ' :: ' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '');
        self::$failures[] = $msg;
        echo "  [FAIL] {$label}" . ($detail !== null ? ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '') . "\n";
    }

    public static function eq($actual, $expected, string $label): void
    {
        self::ok($actual === $expected, $label, $actual === $expected ? null : ['expected' => $expected, 'actual' => $actual]);
    }

    public static function near(int $actual, int $expected, int $tolerance, string $label): void
    {
        self::ok(abs($actual - $expected) <= $tolerance, $label, abs($actual - $expected) <= $tolerance ? null : ['expected' => $expected, 'actual' => $actual]);
    }
}
