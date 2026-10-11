$root = Split-Path -Parent $MyInvocation.MyCommand.Path

$prepare = Join-Path (Split-Path $root -Parent) 'bin\prepare-race-tenant.php'
$prepared = & php $prepare
if ($LASTEXITCODE -ne 0) {
    throw "race tenant was not provisioned`n$prepared"
}
$script:TenantSchema = ("$prepared" | Select-Object -Last 1).ToString().Trim()
if ($script:TenantSchema -notmatch '^tenant_[0-9a-f]{32}$') {
    throw "race tenant schema is missing ($script:TenantSchema)"
}

function Invoke-Psql {
    param([string]$Path, [switch]$AllowFail)
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $sql = "SET search_path TO $script:TenantSchema, pg_catalog, extensions;`n" + (Get-Content -Raw $Path)
    $output = $sql | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 2>&1 | Out-String
    $code = $LASTEXITCODE
    $ErrorActionPreference = $previous
    if (-not $AllowFail -and $code -ne 0) {
        throw "psql failed for $Path`n$output"
    }
    return @{ Code = $code; Output = $output }
}

function Invoke-Exec {
    param([Parameter(Mandatory = $true)][string]$Command)
    $sql = "SET search_path TO $script:TenantSchema, pg_catalog, extensions; $Command"
    docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c $sql | Out-Null
}

function Invoke-Value {
    param([Parameter(Mandatory = $true)][string]$Command)
    $sql = "SET search_path TO $script:TenantSchema, pg_catalog, extensions; $Command"
    $lines = @(docker exec perfect-consign-postgres psql -U stall -d consign -t -A -c $sql)
    return [string]($lines | Select-Object -Last 1)
}

$runRace = {
    param($Path, $Schema)
    $sql = "SET search_path TO $Schema, pg_catalog, extensions;`n" + (Get-Content -Raw $Path)
    $sql | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
}

Invoke-Exec -Command "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d101', 'race', 'Race', 'Lane');" | Out-Null

$deleteJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_delete.sql'), $script:TenantSchema
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

Invoke-Exec -Command "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d022', '01900000-0000-7000-8000-000000000001', '2028-01-01', '2028-12-31', 'open');" | Out-Null
$keyJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_key_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$keyWatch = [Diagnostics.Stopwatch]::StartNew()
$key = Invoke-Psql -Path (Join-Path $root 'prove_race_key_b.sql')
$keyWatch.Stop()
Wait-Job $keyJob | Out-Null
Receive-Job $keyJob | Out-Null
if ($keyWatch.ElapsedMilliseconds -lt 4500) {
    throw "second payout key did not wait ($($keyWatch.ElapsedMilliseconds) ms)`n$($key.Output)"
}

Invoke-Exec -Command @"
INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d201', 'race-rate', 'Race', 'rate');
INSERT INTO booths (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d301', 'R1', 'Race booth');
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES ('01900000-0000-7000-8000-00000000d401', '01900000-0000-7000-8000-00000000d301', '01900000-0000-7000-8000-00000000d201', '2029-01-01');
INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d301', '2029-03-01', 15000);
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d023', '01900000-0000-7000-8000-000000000001', '2029-01-01', '2029-12-31', 'open');
"@ | Out-Null
$rateJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_rate_hold.sql'), $script:TenantSchema
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
Invoke-Exec -Command @"
DELETE FROM booth_rent_rates WHERE booth_id = '01900000-0000-7000-8000-00000000d301';
DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-00000000d401';
DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d301';
DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d201';
DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d023';
"@ | Out-Null

Invoke-Exec -Command @"
INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d202', 'race-end', 'Race', 'end');
INSERT INTO booths (id, code, name) VALUES ('01900000-0000-7000-8000-00000000d302', 'R2', 'Race booth 2');
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES ('01900000-0000-7000-8000-00000000d402', '01900000-0000-7000-8000-00000000d302', '01900000-0000-7000-8000-00000000d202', '2029-01-01');
INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES ('01900000-0000-7000-8000-00000000d302', '2029-03-01', 15000);
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d026', '01900000-0000-7000-8000-000000000001', '2029-01-01', '2029-12-31', 'open');
"@ | Out-Null
$endJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_end_hold.sql'), $script:TenantSchema
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
Invoke-Exec -Command @"
DELETE FROM booth_rent_rates WHERE booth_id = '01900000-0000-7000-8000-00000000d302';
DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-00000000d402';
DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d302';
DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d202';
DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d026';
"@ | Out-Null

Invoke-Exec -Command "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d024', '01900000-0000-7000-8000-000000000001', '2030-01-01', '2030-12-31', 'open');" | Out-Null
$periodJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_period_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$periodWatch = [Diagnostics.Stopwatch]::StartNew()
$period = Invoke-Psql -Path (Join-Path $root 'prove_race_period_line.sql')
$periodWatch.Stop()
Wait-Job $periodJob | Out-Null
if ($periodWatch.ElapsedMilliseconds -lt 4500) {
    throw "journal did not wait for the period close ($($periodWatch.ElapsedMilliseconds) ms)`n$($period.Output)"
}
Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d024';" | Out-Null

$currencyJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_currency_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$currencyWatch = [Diagnostics.Stopwatch]::StartNew()
$currency = Invoke-Psql -Path (Join-Path $root 'prove_race_currency_line.sql')
$currencyWatch.Stop()
Wait-Job $currencyJob | Out-Null
if ($currencyWatch.ElapsedMilliseconds -lt 4500) {
    throw "journal did not wait for the currency change ($($currencyWatch.ElapsedMilliseconds) ms)`n$($currency.Output)"
}
$bookCurrency = Invoke-Value -Command "SELECT currency FROM ledger_books WHERE id = '01900000-0000-7000-8000-000000000001';"
if ($bookCurrency.Trim() -ne 'USD') {
    $journalCount = Invoke-Value -Command "SELECT count(*) FROM journals;"
    if ($journalCount.Trim() -ne '0') {
        throw "currency change committed under a journal"
    }
    Invoke-Exec -Command "UPDATE ledger_books SET currency = 'USD' WHERE id = '01900000-0000-7000-8000-000000000001';" | Out-Null
}

Invoke-Exec -Command "INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable) VALUES ('01900000-0000-7000-8000-00000000d011', '01900000-0000-7000-8000-000000000001', '9988', 'Race account', 'asset', 'debit', true);" | Out-Null
$accountJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_account_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$accountWatch = [Diagnostics.Stopwatch]::StartNew()
$account = Invoke-Psql -Path (Join-Path $root 'prove_race_account_line.sql')
$accountWatch.Stop()
Wait-Job $accountJob | Out-Null
if ($accountWatch.ElapsedMilliseconds -lt 4500) {
    throw "line did not wait for the account change ($($accountWatch.ElapsedMilliseconds) ms)`n$($account.Output)"
}
Invoke-Exec -Command "DELETE FROM accounts WHERE id = '01900000-0000-7000-8000-00000000d011';" | Out-Null

Invoke-Exec -Command "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d029', '01900000-0000-7000-8000-000000000001', '2033-01-01', '2033-12-31', 'open');" | Out-Null
$numberJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_number_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$numberWatch = [Diagnostics.Stopwatch]::StartNew()
$number = Invoke-Psql -Path (Join-Path $root 'prove_race_number_b.sql')
$numberWatch.Stop()
Wait-Job $numberJob | Out-Null
if ($numberWatch.ElapsedMilliseconds -lt 4500) {
    throw "second journal did not wait for the number counter ($($numberWatch.ElapsedMilliseconds) ms)`n$($number.Output)"
}
Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d029';" | Out-Null
Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d022';" | Out-Null

Invoke-Exec -Command "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d030', '01900000-0000-7000-8000-000000000001', '2034-01-01', '2034-12-31', 'open');" | Out-Null
$quietJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_quiet_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$quietWatch = [Diagnostics.Stopwatch]::StartNew()
$quiet = Invoke-Psql -Path (Join-Path $root 'prove_race_quiet_line.sql')
$quietWatch.Stop()
Wait-Job $quietJob | Out-Null
if ($quietWatch.ElapsedMilliseconds -lt 4500) {
    throw "journal did not wait for the quiet close ($($quietWatch.ElapsedMilliseconds) ms)`n$($quiet.Output)"
}
Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d030';" | Out-Null

Invoke-Exec -Command @"
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES
    ('01900000-0000-7000-8000-00000000d035', '01900000-0000-7000-8000-000000000001', '2035-01-01', '2035-12-31', 'open'),
    ('01900000-0000-7000-8000-00000000d036', '01900000-0000-7000-8000-000000000001', '2036-01-01', '2036-12-31', 'open');
"@ | Out-Null
$orderJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_order_hold.sql'), $script:TenantSchema
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
Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id IN ('01900000-0000-7000-8000-00000000d035', '01900000-0000-7000-8000-00000000d036');" | Out-Null

Invoke-Exec -Command "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d037', '01900000-0000-7000-8000-000000000001', '2037-01-01', '2037-12-31', 'open');" | Out-Null
$openingJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_opening_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$openingWatch = [Diagnostics.Stopwatch]::StartNew()
$opening = Invoke-Psql -Path (Join-Path $root 'prove_race_opening_b.sql')
$openingWatch.Stop()
Wait-Job $openingJob | Out-Null
if ($openingWatch.ElapsedMilliseconds -lt 4500) {
    throw "second opening did not wait ($($openingWatch.ElapsedMilliseconds) ms)`n$($opening.Output)"
}
Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d037';" | Out-Null

$brandJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_brand_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$brandWatch = [Diagnostics.Stopwatch]::StartNew()
$brand = Invoke-Psql -Path (Join-Path $root 'prove_race_brand_b.sql')
$brandWatch.Stop()
Wait-Job $brandJob | Out-Null
Receive-Job $brandJob | Out-Null
if ($brandWatch.ElapsedMilliseconds -lt 4500) {
    throw "second brand code did not wait ($($brandWatch.ElapsedMilliseconds) ms)`n$($brand.Output)"
}
if ($brand.Code -ne 0) {
    throw "brand code race failed`n$($brand.Output)"
}

Invoke-Exec -Command "DELETE FROM items WHERE vendor_id IN (SELECT id FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'); DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'; INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d0c0', 'race-item', 'Race', 'Item');" | Out-Null
$itemJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_item_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$itemWatch = [Diagnostics.Stopwatch]::StartNew()
$item = Invoke-Psql -Path (Join-Path $root 'prove_race_item_b.sql')
$itemWatch.Stop()
Wait-Job $itemJob | Out-Null
Receive-Job $itemJob | Out-Null
Invoke-Exec -Command "DELETE FROM items WHERE vendor_id IN (SELECT id FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item');" | Out-Null
if ($itemWatch.ElapsedMilliseconds -lt 4500) {
    throw "second item tag did not wait ($($itemWatch.ElapsedMilliseconds) ms)`n$($item.Output)"
}
if ($item.Code -ne 0) {
    throw "item tag race failed`n$($item.Output)"
}

$barcodeJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_barcode_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$barcodeWatch = [Diagnostics.Stopwatch]::StartNew()
$barcode = Invoke-Psql -Path (Join-Path $root 'prove_race_barcode_b.sql')
$barcodeWatch.Stop()
Wait-Job $barcodeJob | Out-Null
Receive-Job $barcodeJob | Out-Null
Invoke-Exec -Command "DELETE FROM items WHERE vendor_id IN (SELECT id FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item'); DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0c0' OR code = 'race-item';" | Out-Null
if ($barcodeWatch.ElapsedMilliseconds -lt 4500) {
    throw "second item barcode did not wait ($($barcodeWatch.ElapsedMilliseconds) ms)`n$($barcode.Output)"
}
if ($barcode.Code -ne 0) {
    throw "item barcode race failed`n$($barcode.Output)"
}

$waitJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_wait_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$waitWatch = [Diagnostics.Stopwatch]::StartNew()
$wait = Invoke-Psql -Path (Join-Path $root 'prove_race_wait_b.sql')
$waitWatch.Stop()
Wait-Job $waitJob | Out-Null
Receive-Job $waitJob | Out-Null
if ($waitWatch.ElapsedMilliseconds -lt 4500) {
    throw "second wait-list email did not wait ($($waitWatch.ElapsedMilliseconds) ms)`n$($wait.Output)"
}
if ($wait.Code -ne 0) {
    throw "wait-list email race failed`n$($wait.Output)"
}

$placeJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_place_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$placeWatch = [Diagnostics.Stopwatch]::StartNew()
$place = Invoke-Psql -Path (Join-Path $root 'prove_race_place_wait.sql') -AllowFail
$placeWatch.Stop()
Wait-Job $placeJob | Out-Null
Receive-Job $placeJob | Out-Null
Invoke-Exec -Command "DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d501';" | Out-Null
if ($placeWatch.ElapsedMilliseconds -lt 4500) {
    throw "overlapping booth did not wait for the placement ($($placeWatch.ElapsedMilliseconds) ms)`n$($place.Output)"
}
if ($place.Code -eq 0 -or $place.Output -notmatch 'a booth cannot overlap another booth') {
    throw "overlapping booth was not refused`n$($place.Output)"
}

$floorJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_floor_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$floorWatch = [Diagnostics.Stopwatch]::StartNew()
$floor = Invoke-Psql -Path (Join-Path $root 'prove_race_floor_wait.sql') -AllowFail
$floorWatch.Stop()
Wait-Job $floorJob | Out-Null
Receive-Job $floorJob | Out-Null
Invoke-Exec -Command "UPDATE mall SET floor_width_ft = 120;" | Out-Null
Invoke-Exec -Command "DELETE FROM booths WHERE id = '01900000-0000-7000-8000-00000000d503';" | Out-Null
if ($floorWatch.ElapsedMilliseconds -lt 4500) {
    throw "booth did not wait for the floor shrink ($($floorWatch.ElapsedMilliseconds) ms)`n$($floor.Output)"
}
if ($floor.Code -eq 0 -or $floor.Output -notmatch 'a booth cannot extend past the floor') {
    throw "a booth past the shrunk floor was not refused`n$($floor.Output)"
}

Invoke-Exec -Command "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d022';" | Out-Null

Invoke-Exec -Command "DELETE FROM platform.tenants WHERE slug = 'race-tenant';" | Out-Null
$tenantJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_tenant_race_hold.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$tenantWatch = [Diagnostics.Stopwatch]::StartNew()
$tenant = Invoke-Psql -Path (Join-Path $root 'prove_tenant_race_wait.sql') -AllowFail
$tenantWatch.Stop()
Wait-Job $tenantJob | Out-Null
Invoke-Exec -Command "DELETE FROM platform.tenants WHERE slug = 'race-tenant';" | Out-Null
if ($tenantWatch.ElapsedMilliseconds -lt 4500) {
    throw "second tenant slug did not wait ($($tenantWatch.ElapsedMilliseconds) ms)`n$($tenant.Output)"
}
if ($tenant.Code -eq 0 -or $tenant.Output -notmatch 'tenants_slug_key') {
    throw "duplicate tenant slug was not refused`n$($tenant.Output)"
}

$registerJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_register_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$registerWatch = [Diagnostics.Stopwatch]::StartNew()
$registerRace = Invoke-Psql -Path (Join-Path $root 'prove_race_register_b.sql')
$registerWatch.Stop()
Wait-Job $registerJob | Out-Null
Receive-Job $registerJob | Out-Null
if ($registerWatch.ElapsedMilliseconds -lt 4500) {
    throw "second open session did not wait ($($registerWatch.ElapsedMilliseconds) ms)`n$($registerRace.Output)"
}
if ($registerRace.Code -ne 0) {
    throw "second open session failed after the first rolled back`n$($registerRace.Output)"
}

Invoke-Exec -Command @"
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-00000000d0e4', '01900000-0000-7000-8000-000000000001', '2038-01-01', '2038-12-31', 'open');
INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d0e0', 'race-sale', 'Race', 'Sale');
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps) VALUES ('01900000-0000-7000-8000-00000000d0e3', 'Race sale', '01900000-0000-7000-8000-00000000d0e0', 'Race item', 100, 'Race sale tag', DATE '2038-03-01', 0);
"@ | Out-Null
$saleJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_sale_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$saleWatch = [Diagnostics.Stopwatch]::StartNew()
$saleRace = Invoke-Psql -Path (Join-Path $root 'prove_race_sale_b.sql')
$saleWatch.Stop()
Wait-Job $saleJob | Out-Null
Receive-Job $saleJob | Out-Null
Invoke-Exec -Command "DELETE FROM items WHERE id = '01900000-0000-7000-8000-00000000d0e3'; DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0e0'; DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-00000000d0e4';" | Out-Null
if ($saleWatch.ElapsedMilliseconds -lt 4500) {
    throw "second sale of the item did not wait ($($saleWatch.ElapsedMilliseconds) ms)`n$($saleRace.Output)"
}
if ($saleRace.Code -ne 0) {
    throw "second sale failed after the first rolled back`n$($saleRace.Output)"
}

Invoke-Exec -Command @"
INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-00000000d0f0', 'race-ticket', 'Race', 'Ticket');
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps) VALUES ('01900000-0000-7000-8000-00000000d0f3', 'Race ticket', '01900000-0000-7000-8000-00000000d0f0', 'Race ticket item', 100, 'Race ticket tag', DATE '2039-03-01', 0);
"@ | Out-Null
$ticketJob = Start-Job -ScriptBlock $runRace -ArgumentList (Join-Path $root 'prove_race_ticket_a.sql'), $script:TenantSchema
Start-Sleep -Seconds 1
$ticketWatch = [Diagnostics.Stopwatch]::StartNew()
$ticketRace = Invoke-Psql -Path (Join-Path $root 'prove_race_ticket_b.sql')
$ticketWatch.Stop()
Wait-Job $ticketJob | Out-Null
Receive-Job $ticketJob | Out-Null
Invoke-Exec -Command "DELETE FROM items WHERE id = '01900000-0000-7000-8000-00000000d0f3'; DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d0f0';" | Out-Null
if ($ticketWatch.ElapsedMilliseconds -lt 4500) {
    throw "second ticket for the item did not wait ($($ticketWatch.ElapsedMilliseconds) ms)`n$($ticketRace.Output)"
}
if ($ticketRace.Code -ne 0) {
    throw "second ticket failed after the first rolled back`n$($ticketRace.Output)"
}

$left = Invoke-Value -Command @"
SELECT
    (SELECT count(*) FROM journals WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM vendors WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM booths WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM accounting_periods WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM brands WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM items WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM wait_list WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM register_sessions WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%')
    || ',' || (SELECT count(*) FROM tickets WHERE id::text LIKE '01900000-0000-7000-8000-00000000d%');
"@
if ($left.Trim() -ne '0,0,0,0,0,0,0,0,0') {
    throw "race proof left rows: $left"
}
Write-Output "RACE_OK line_ms=$($lineWatch.ElapsedMilliseconds) key_ms=$($keyWatch.ElapsedMilliseconds) rate_ms=$($rateWatch.ElapsedMilliseconds) end_ms=$($endWatch.ElapsedMilliseconds) period_ms=$($periodWatch.ElapsedMilliseconds) currency_ms=$($currencyWatch.ElapsedMilliseconds) account_ms=$($accountWatch.ElapsedMilliseconds) number_ms=$($numberWatch.ElapsedMilliseconds) quiet_ms=$($quietWatch.ElapsedMilliseconds) order_ms=$($orderWatch.ElapsedMilliseconds) opening_ms=$($openingWatch.ElapsedMilliseconds) brand_ms=$($brandWatch.ElapsedMilliseconds) item_ms=$($itemWatch.ElapsedMilliseconds) barcode_ms=$($barcodeWatch.ElapsedMilliseconds) wait_ms=$($waitWatch.ElapsedMilliseconds) place_ms=$($placeWatch.ElapsedMilliseconds) floor_ms=$($floorWatch.ElapsedMilliseconds) tenant_ms=$($tenantWatch.ElapsedMilliseconds) register_ms=$($registerWatch.ElapsedMilliseconds) sale_ms=$($saleWatch.ElapsedMilliseconds) ticket_ms=$($ticketWatch.ElapsedMilliseconds)"
