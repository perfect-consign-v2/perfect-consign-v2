<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sql = $root . DIRECTORY_SEPARATOR . 'sql';
$raceLock = holdRaceLock();
$prepared = phpCommand([$root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'prepare-race-tenant.php'], null);
if ($prepared['code'] !== 0) {
    fwrite(STDERR, "race tenant was not provisioned\n" . $prepared['output']);
    exit(1);
}
$lines = preg_split("/\\r\\n|\\n|\\r/", trim($prepared['output'])) ?: [];
$schema = (string) ($lines[count($lines) - 1] ?? '');
if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
    fwrite(STDERR, "race tenant schema is missing ($schema)\n");
    exit(1);
}

execSql($schema, "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d101', 'race', 'Race', 'Lane')");
race($schema, $sql, 'prove_race_delete.sql', 'prove_race_line.sql', true, 'vendor payable subledger ref must be a vendor id', false);

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d022', '01900000-0000-7000-8000-000000000001', '2028-01-01', '2028-12-31', 'open')");
race($schema, $sql, 'prove_race_key_a.sql', 'prove_race_key_b.sql', false, null, true);
race($schema, $sql, 'prove_race_payout_a.sql', 'prove_race_payout_b.sql', false, null, true);

execSql($schema, "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d201', 'race-rate', 'Race', 'rate'); INSERT INTO booths (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d301', 'R1', 'Race booth'); INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES ('01900000-0000-7000-8000-00000000d401', '01900000-0000-7000-8000-00000000d301', '01900000-0000-7000-8000-00000000d201', '2029-03-01'); INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d401', 15000); INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d301', 15000); INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d023', '01900000-0000-7000-8000-000000000001', '2029-01-01', '2029-12-31', 'open')");
race($schema, $sql, 'prove_race_rate_hold.sql', 'prove_race_rate_charge.sql', true, 'stored rate', false);
execSql($schema, "DELETE FROM assignment_rent WHERE assignment_id = '01900000-0000-7000-8000-00000000d401'; DELETE FROM booth_rent_rates WHERE booth_id = '01900000-0000-7000-8000-00000000d301'; DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-00000000d401'; DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d301'; DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d201'; DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d023'");

execSql($schema, "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d202', 'race-end', 'Race', 'end'); INSERT INTO booths (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d302', 'R2', 'Race booth 2'); INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES ('01900000-0000-7000-8000-00000000d402', '01900000-0000-7000-8000-00000000d302', '01900000-0000-7000-8000-00000000d202', '2029-01-01'); INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d402', 15000); INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d302', 15000); INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d026', '01900000-0000-7000-8000-000000000001', '2029-01-01', '2029-12-31', 'open')");
race($schema, $sql, 'prove_race_end_hold.sql', 'prove_race_end_charge.sql', true, 'assignment that covers', false);
execSql($schema, "DELETE FROM assignment_rent WHERE assignment_id = '01900000-0000-7000-8000-00000000d402'; DELETE FROM booth_rent_rates WHERE booth_id = '01900000-0000-7000-8000-00000000d302'; DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-00000000d402'; DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d302'; DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d202'; DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d026'");

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d024', '01900000-0000-7000-8000-000000000001', '2030-01-01', '2030-12-31', 'open')");
race($schema, $sql, 'prove_race_period_hold.sql', 'prove_race_period_line.sql', false, null, true);
execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d024'");

race($schema, $sql, 'prove_race_currency_hold.sql', 'prove_race_currency_line.sql', false, null, true);
$currency = trim(value($schema, "SELECT currency FROM ledger_books WHERE id = '01900000-0000-7000-8000-000000000001'"));
if ($currency !== 'USD') {
    $journals = trim(value($schema, 'SELECT count(*) FROM journals'));
    if ($journals !== '0') {
        fail('currency change committed under a journal');
    }
    execSql($schema, "UPDATE ledger_books SET currency = 'USD' WHERE id = '01900000-0000-7000-8000-000000000001'");
}

execSql($schema, "INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable) VALUES ('01900000-0000-7000-8000-00000000d011', '01900000-0000-7000-8000-000000000001', '9988', 'Race account', 'asset', 'debit', true)");
race($schema, $sql, 'prove_race_account_hold.sql', 'prove_race_account_line.sql', false, null, true);
execSql($schema, "DELETE FROM accounts WHERE id = '01900000-0000-7000-8000-00000000d011'");

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d029', '01900000-0000-7000-8000-000000000001', '2033-01-01', '2033-12-31', 'open')");
race($schema, $sql, 'prove_race_number_a.sql', 'prove_race_number_b.sql', false, null, true);
execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d029'");
execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d022'");

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d030', '01900000-0000-7000-8000-000000000001', '2034-01-01', '2034-12-31', 'open')");
race($schema, $sql, 'prove_race_quiet_hold.sql', 'prove_race_quiet_line.sql', false, null, true);
execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d030'");

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d035', '01900000-0000-7000-8000-000000000001', '2035-01-01', '2035-12-31', 'open'), ('01900000-0000-7000-8000-00000000d036', '01900000-0000-7000-8000-000000000001', '2036-01-01', '2036-12-31', 'open')");
race($schema, $sql, 'prove_race_order_hold.sql', 'prove_race_order_wait.sql', true, 'an earlier period is still open', false);
execSql($schema, "DELETE FROM accounting_periods WHERE id IN ('01900000-0000-7000-8000-00000000d035', '01900000-0000-7000-8000-00000000d036')");

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d037', '01900000-0000-7000-8000-000000000001', '2037-01-01', '2037-12-31', 'open')");
race($schema, $sql, 'prove_race_opening_a.sql', 'prove_race_opening_b.sql', false, null, true);
execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d037'");

race($schema, $sql, 'prove_race_brand_a.sql', 'prove_race_brand_b.sql', false, null, true);

execSql($schema, "DELETE FROM items WHERE vendor_id IN (SELECT id FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'); DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'; INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d0c0', 'race-item', 'Race', 'Item')");
race($schema, $sql, 'prove_race_item_a.sql', 'prove_race_item_b.sql', false, null, true);
execSql($schema, "DELETE FROM items WHERE vendor_id IN (SELECT id FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item')");

race($schema, $sql, 'prove_race_barcode_a.sql', 'prove_race_barcode_b.sql', false, null, true);
execSql($schema, "DELETE FROM items WHERE vendor_id IN (SELECT id FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'); DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'");

race($schema, $sql, 'prove_race_wait_a.sql', 'prove_race_wait_b.sql', false, null, true);

race($schema, $sql, 'prove_race_place_hold.sql', 'prove_race_place_wait.sql', true, 'a booth cannot overlap another booth', false);
execSql($schema, "DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d501'");

race($schema, $sql, 'prove_race_floor_hold.sql', 'prove_race_floor_wait.sql', true, 'a booth cannot extend past the floor', false);
execSql($schema, 'UPDATE mall SET floor_width_ft = 120');
execSql($schema, "DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d503'");

execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d022'");
execSql($schema, "DELETE FROM platform.tenants WHERE slug = 'race-tenant'");
race($schema, $sql, 'prove_tenant_race_hold.sql', 'prove_tenant_race_wait.sql', true, 'tenants_slug_key', false);
execSql($schema, "DELETE FROM platform.tenants WHERE slug = 'race-tenant'");

race($schema, $sql, 'prove_race_register_a.sql', 'prove_race_register_b.sql', false, null, true);

execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d0e4', '01900000-0000-7000-8000-000000000001', '2038-01-01', '2038-12-31', 'open'); INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d0e0', 'race-sale', 'Race', 'Sale'); INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps) VALUES ('01900000-0000-7000-8000-00000000d0e3', 'Race sale', '01900000-0000-7000-8000-00000000d0e0', 'Race piece', 100, 'Race sale tag', DATE '2038-03-01', 0)");
race($schema, $sql, 'prove_race_sale_a.sql', 'prove_race_sale_b.sql', false, null, true);
execSql($schema, "DELETE FROM items WHERE id = '01900000-0000-7000-8000-00000000d0e3'; DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0e0'; DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d0e4'");

execSql($schema, "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d0f0', 'race-ticket', 'Race', 'Ticket'); INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps) VALUES ('01900000-0000-7000-8000-00000000d0f3', 'Race ticket', '01900000-0000-7000-8000-00000000d0f0', 'Race ticket piece', 100, 'Race ticket tag', DATE '2039-03-01', 0)");
race($schema, $sql, 'prove_race_ticket_a.sql', 'prove_race_ticket_b.sql', false, null, true);
execSql($schema, "DELETE FROM items WHERE id = '01900000-0000-7000-8000-00000000d0f3'; DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0f0'");

execSql($schema, "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-0000000009b0', 'race-cola', 'Cola', 'Race')");
execSql($schema, "INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity) VALUES ('01900000-0000-7000-8000-0000000009b1', 'Race cola', '01900000-0000-7000-8000-0000000009b0', 'Race cola', 1000, 'BC-RACE-COLA', DATE '2094-05-01', 0, 1)");
execSql($schema, "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-0000000009b2', '01900000-0000-7000-8000-000000000001', '2094-05-01', '2094-05-31', 'open')");
race($schema, $sql, 'prove_race_on_hand_a.sql', 'prove_race_on_hand_b.sql', false, null, true);
execSql($schema, "DELETE FROM items WHERE id = '01900000-0000-7000-8000-0000000009b1'");
execSql($schema, "DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-0000000009b0'");
execSql($schema, "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-0000000009b2'");

$left = trim(value($schema, "SELECT (SELECT count(*) FROM journals WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM vendors WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM booths WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM accounting_periods WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM brands WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM items WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM wait_list WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM register_sessions WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%') || ',' || (SELECT count(*) FROM tickets WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')"));
if ($left !== '0,0,0,0,0,0,0,0,0') {
    fail('race proof left rows: ' . $left);
}

fwrite(STDOUT, "ok race\n");

function race(
    string $schema,
    string $directory,
    string $holdFile,
    string $waitFile,
    bool $allowFail,
    ?string $needle,
    bool $mustSucceed,
): void {
    $hold = startSql(wrapped($schema, readRace($directory, $holdFile)));
    sleep(1);
    $started = hrtime(true);
    $wait = runSql(wrapped($schema, readRace($directory, $waitFile)), true);
    $elapsed = (int) ((hrtime(true) - $started) / 1000000);
    finishSql($hold);
    if ($elapsed < 4500) {
        fail($waitFile . ' did not wait (' . $elapsed . " ms)\n" . $wait['output']);
    }
    if (!$allowFail && $wait['code'] !== 0) {
        fail($waitFile . " failed\n" . $wait['output']);
    }
    if ($mustSucceed && $wait['code'] !== 0) {
        fail($waitFile . " failed after the hold rolled back\n" . $wait['output']);
    }
    if ($needle !== null && ($wait['code'] === 0 || !str_contains($wait['output'], $needle))) {
        fail($waitFile . " was not refused\n" . $wait['output']);
    }
}

function execSql(string $schema, string $sql): void
{
    $result = runSql(wrapped($schema, $sql), false);
    if ($result['code'] !== 0) {
        fail($result['output']);
    }
}

function value(string $schema, string $sql): string
{
    $result = runSql(wrapped($schema, $sql), false);
    if ($result['code'] !== 0) {
        fail($result['output']);
    }
    $lines = preg_split("/\\r\\n|\\n|\\r/", trim($result['output'])) ?: [];

    return (string) ($lines[count($lines) - 1] ?? '');
}

function wrapped(string $schema, string $sql): string
{
    return 'SET search_path TO ' . $schema . ', pg_catalog, extensions;' . "\n" . $sql;
}

function readRace(string $directory, string $file): string
{
    $sql = file_get_contents($directory . DIRECTORY_SEPARATOR . $file);
    if ($sql === false) {
        fail('could not read ' . $file);
    }

    return $sql;
}

/**
 * @return array{process: resource}
 */
function startSql(string $sql): array
{
    $sink = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        sessionCommand(),
        [0 => ['pipe', 'r'], 1 => ['file', $sink, 'w'], 2 => ['file', $sink, 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fail('could not start a race session');
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);

    return ['process' => $process];
}

/**
 * @param array{process: resource} $started
 */
function finishSql(array $started): void
{
    proc_close($started['process']);
}

/**
 * @return array{code: int, output: string}
 */
function runSql(string $sql, bool $allowFail): array
{
    $process = proc_open(sessionCommand(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fail('could not start a race session');
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $text = (is_string($output) ? $output : '') . (is_string($error) ? $error : '');
    if (!$allowFail && $code !== 0) {
        fail($text);
    }

    return ['code' => $code, 'output' => $text];
}

/**
 * @return list<string>
 */
function sessionCommand(): array
{
    return [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'prove-race-session.php'];
}

/**
 * @param list<string> $arguments
 * @return array{code: int, output: string}
 */
function phpCommand(array $arguments, ?string $stdin): array
{
    $process = proc_open(array_merge(['php'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['code' => 1, 'output' => 'could not start php'];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'code' => proc_close($process),
        'output' => (is_string($output) ? $output : '') . (is_string($error) ? $error : ''),
    ];
}

function holdRaceLock(): PDO
{
    require_once __DIR__ . '/load-env.php';
    loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    $host = raceSetting('STALL_DB_HOST', '127.0.0.1');
    $port = raceSetting('STALL_DB_PORT', '5436');
    $name = raceSetting('STALL_DB_NAME', 'consign');
    $user = raceSetting('STALL_DB_USER', 'stall');
    $password = raceSetting('STALL_DB_PASSWORD', 'stall');
    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $locked = $pdo->query('SELECT pg_try_advisory_lock(814202614)');
    $value = $locked === false ? false : $locked->fetchColumn();
    if ($value !== true && $value !== 't' && $value !== 1 && $value !== '1') {
        fail('a race proof is already running');
    }

    return $pdo;
}

function raceSetting(string $name, string $fallback): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        return $fallback;
    }

    return $value;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
