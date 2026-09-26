$root = Split-Path -Parent $MyInvocation.MyCommand.Path

function Invoke-Psql {
    param([string]$Path, [switch]$AllowFail)
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $output = Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 2>&1 | Out-String
    $code = $LASTEXITCODE
    $ErrorActionPreference = $previous
    if (-not $AllowFail -and $code -ne 0) {
        throw "psql failed for $Path`n$output"
    }
    return @{ Code = $code; Output = $output }
}

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO vendors (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d101', 'race', 'Race');" | Out-Null

$deleteJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_delete.sql')
Start-Sleep -Seconds 1
$lineWatch = [Diagnostics.Stopwatch]::StartNew()
$line = Invoke-Psql -Path (Join-Path $root 'prove_race_line.sql') -AllowFail
$lineWatch.Stop()
Wait-Job $deleteJob | Out-Null
$deleteOut = Receive-Job $deleteJob | Out-String
if ($lineWatch.ElapsedMilliseconds -lt 4500) {
    throw "line insert did not wait for the vendor delete ($($lineWatch.ElapsedMilliseconds) ms)`n$($line.Output)"
}
if ($line.Code -eq 0 -or $line.Output -notmatch 'vendor payable subledger ref must be a vendor id') {
    throw "line insert was not refused after the delete`n$($line.Output)`n$deleteOut"
}

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d022', '01900000-0000-7000-8000-000000000001', '2028-01-01', '2028-12-31', 'open');" | Out-Null
$keyJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_key_a.sql')
Start-Sleep -Seconds 1
$keyWatch = [Diagnostics.Stopwatch]::StartNew()
$key = Invoke-Psql -Path (Join-Path $root 'prove_race_key_b.sql')
$keyWatch.Stop()
Wait-Job $keyJob | Out-Null
Receive-Job $keyJob | Out-Null
if ($keyWatch.ElapsedMilliseconds -lt 4500) {
    throw "second payout key did not wait ($($keyWatch.ElapsedMilliseconds) ms)`n$($key.Output)"
}

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c @"
INSERT INTO vendors (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d201', 'race-rate', 'Race rate');
INSERT INTO booths (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d301', 'R1', 'Race booth');
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES ('01900000-0000-7000-8000-00000000d401', '01900000-0000-7000-8000-00000000d301', '01900000-0000-7000-8000-00000000d201', '2029-01-01');
INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d301', '2029-03-01', 15000);
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d023', '01900000-0000-7000-8000-000000000001', '2029-01-01', '2029-12-31', 'open');
"@ | Out-Null
$rateJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_rate_hold.sql')
Start-Sleep -Seconds 1
$rateWatch = [Diagnostics.Stopwatch]::StartNew()
$rate = Invoke-Psql -Path (Join-Path $root 'prove_race_rate_charge.sql') -AllowFail
$rateWatch.Stop()
Wait-Job $rateJob | Out-Null
if ($rateWatch.ElapsedMilliseconds -lt 4500) {
    throw "rent charge did not wait for the rate change ($($rateWatch.ElapsedMilliseconds) ms)`n$($rate.Output)"
}
if ($rate.Code -eq 0 -or $rate.Output -notmatch 'stored rate') {
    throw "rent charge was not refused after the rate change`n$($rate.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c @"
DELETE FROM booth_rent_rates WHERE booth_id = '01900000-0000-7000-8000-00000000d301';
DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-00000000d401';
DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d301';
DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d201';
DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d023';
"@ | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c @"
INSERT INTO vendors (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d202', 'race-end', 'Race end');
INSERT INTO booths (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d302', 'R2', 'Race booth 2');
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES ('01900000-0000-7000-8000-00000000d402', '01900000-0000-7000-8000-00000000d302', '01900000-0000-7000-8000-00000000d202', '2029-01-01');
INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d302', '2029-03-01', 15000);
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d026', '01900000-0000-7000-8000-000000000001', '2029-01-01', '2029-12-31', 'open');
"@ | Out-Null
$endJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_end_hold.sql')
Start-Sleep -Seconds 1
$endWatch = [Diagnostics.Stopwatch]::StartNew()
$end = Invoke-Psql -Path (Join-Path $root 'prove_race_end_charge.sql') -AllowFail
$endWatch.Stop()
Wait-Job $endJob | Out-Null
if ($endWatch.ElapsedMilliseconds -lt 4500) {
    throw "rent charge did not wait for the assignment end ($($endWatch.ElapsedMilliseconds) ms)`n$($end.Output)"
}
if ($end.Code -eq 0 -or $end.Output -notmatch 'assignment that covers') {
    throw "rent charge was not refused after the assignment end`n$($end.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c @"
DELETE FROM booth_rent_rates WHERE booth_id = '01900000-0000-7000-8000-00000000d302';
DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-00000000d402';
DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d302';
DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d202';
DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d026';
"@ | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d024', '01900000-0000-7000-8000-000000000001', '2030-01-01', '2030-12-31', 'open');" | Out-Null
$periodJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_period_hold.sql')
Start-Sleep -Seconds 1
$periodWatch = [Diagnostics.Stopwatch]::StartNew()
$period = Invoke-Psql -Path (Join-Path $root 'prove_race_period_line.sql')
$periodWatch.Stop()
Wait-Job $periodJob | Out-Null
if ($periodWatch.ElapsedMilliseconds -lt 4500) {
    throw "journal did not wait for the period close ($($periodWatch.ElapsedMilliseconds) ms)`n$($period.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d024';" | Out-Null

$currencyJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_currency_hold.sql')
Start-Sleep -Seconds 1
$currencyWatch = [Diagnostics.Stopwatch]::StartNew()
$currency = Invoke-Psql -Path (Join-Path $root 'prove_race_currency_line.sql')
$currencyWatch.Stop()
Wait-Job $currencyJob | Out-Null
if ($currencyWatch.ElapsedMilliseconds -lt 4500) {
    throw "journal did not wait for the currency change ($($currencyWatch.ElapsedMilliseconds) ms)`n$($currency.Output)"
}
$bookCurrency = docker exec perfect-consign-postgres psql -U stall -d consign -t -A -c "SELECT currency FROM ledger_books WHERE id = '01900000-0000-7000-8000-000000000001';"
if ($bookCurrency.Trim() -ne 'USD') {
    $journalCount = docker exec perfect-consign-postgres psql -U stall -d consign -t -A -c "SELECT count(*) FROM journals;"
    if ($journalCount.Trim() -ne '0') {
        throw "currency change committed under a journal"
    }
    docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "UPDATE ledger_books SET currency = 'USD' WHERE id = '01900000-0000-7000-8000-000000000001';" | Out-Null
}

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable) VALUES ('01900000-0000-7000-8000-00000000d011', '01900000-0000-7000-8000-000000000001', '9988', 'Race account', 'asset', 'debit', true);" | Out-Null
$accountJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_account_hold.sql')
Start-Sleep -Seconds 1
$accountWatch = [Diagnostics.Stopwatch]::StartNew()
$account = Invoke-Psql -Path (Join-Path $root 'prove_race_account_line.sql')
$accountWatch.Stop()
Wait-Job $accountJob | Out-Null
if ($accountWatch.ElapsedMilliseconds -lt 4500) {
    throw "line did not wait for the account change ($($accountWatch.ElapsedMilliseconds) ms)`n$($account.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounts WHERE id = '01900000-0000-7000-8000-00000000d011';" | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d029', '01900000-0000-7000-8000-000000000001', '2033-01-01', '2033-12-31', 'open');" | Out-Null
$numberJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_number_a.sql')
Start-Sleep -Seconds 1
$numberWatch = [Diagnostics.Stopwatch]::StartNew()
$number = Invoke-Psql -Path (Join-Path $root 'prove_race_number_b.sql')
$numberWatch.Stop()
Wait-Job $numberJob | Out-Null
if ($numberWatch.ElapsedMilliseconds -lt 4500) {
    throw "second journal did not wait for the number counter ($($numberWatch.ElapsedMilliseconds) ms)`n$($number.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d029';" | Out-Null
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d022';" | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d030', '01900000-0000-7000-8000-000000000001', '2034-01-01', '2034-12-31', 'open');" | Out-Null
$quietJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_quiet_hold.sql')
Start-Sleep -Seconds 1
$quietWatch = [Diagnostics.Stopwatch]::StartNew()
$quiet = Invoke-Psql -Path (Join-Path $root 'prove_race_quiet_line.sql')
$quietWatch.Stop()
Wait-Job $quietJob | Out-Null
if ($quietWatch.ElapsedMilliseconds -lt 4500) {
    throw "journal did not wait for the quiet close ($($quietWatch.ElapsedMilliseconds) ms)`n$($quiet.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d030';" | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c @"
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES
    ('01900000-0000-7000-8000-00000000d035', '01900000-0000-7000-8000-000000000001', '2035-01-01', '2035-12-31', 'open'),
    ('01900000-0000-7000-8000-00000000d036', '01900000-0000-7000-8000-000000000001', '2036-01-01', '2036-12-31', 'open');
"@ | Out-Null
$orderJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_order_hold.sql')
Start-Sleep -Seconds 1
$orderWatch = [Diagnostics.Stopwatch]::StartNew()
$order = Invoke-Psql -Path (Join-Path $root 'prove_race_order_wait.sql') -AllowFail
$orderWatch.Stop()
Wait-Job $orderJob | Out-Null
if ($orderWatch.ElapsedMilliseconds -lt 4500) {
    throw "quiet close did not wait for the earlier period ($($orderWatch.ElapsedMilliseconds) ms)`n$($order.Output)"
}
if ($order.Code -eq 0 -or $order.Output -notmatch 'an earlier period is still open') {
    throw "quiet close was not refused while the earlier period is open`n$($order.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id IN ('01900000-0000-7000-8000-00000000d035', '01900000-0000-7000-8000-00000000d036');" | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d037', '01900000-0000-7000-8000-000000000001', '2037-01-01', '2037-12-31', 'open');" | Out-Null
$openingJob = Start-Job -ScriptBlock {
    param($Path)
    Get-Content -Raw $Path | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
} -ArgumentList (Join-Path $root 'prove_race_opening_a.sql')
Start-Sleep -Seconds 1
$openingWatch = [Diagnostics.Stopwatch]::StartNew()
$opening = Invoke-Psql -Path (Join-Path $root 'prove_race_opening_b.sql')
$openingWatch.Stop()
Wait-Job $openingJob | Out-Null
if ($openingWatch.ElapsedMilliseconds -lt 4500) {
    throw "second opening did not wait ($($openingWatch.ElapsedMilliseconds) ms)`n$($opening.Output)"
}
docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d037';" | Out-Null

docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d022';" | Out-Null
$left = docker exec perfect-consign-postgres psql -U stall -d consign -t -A -c "SELECT (SELECT count(*) FROM journals) || ',' || (SELECT count(*) FROM vendors) || ',' || (SELECT count(*) FROM booths) || ',' || (SELECT count(*) FROM accounting_periods);"
if ($left.Trim() -ne '0,0,0,0') {
    throw "race proof left rows: $left"
}
Write-Output "RACE_OK line_ms=$($lineWatch.ElapsedMilliseconds) key_ms=$($keyWatch.ElapsedMilliseconds) rate_ms=$($rateWatch.ElapsedMilliseconds) end_ms=$($endWatch.ElapsedMilliseconds) period_ms=$($periodWatch.ElapsedMilliseconds) currency_ms=$($currencyWatch.ElapsedMilliseconds) account_ms=$($accountWatch.ElapsedMilliseconds) number_ms=$($numberWatch.ElapsedMilliseconds) quiet_ms=$($quietWatch.ElapsedMilliseconds) order_ms=$($orderWatch.ElapsedMilliseconds) opening_ms=$($openingWatch.ElapsedMilliseconds)"
