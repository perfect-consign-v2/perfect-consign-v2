$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$prepare = Join-Path (Split-Path $root -Parent) 'bin\prepare-race-tenant.php'
$prepared = & php $prepare
if ($LASTEXITCODE -ne 0) {
    throw "race tenant was not provisioned`n$prepared"
}
$script:schema = ("$prepared" | Select-Object -Last 1).ToString().Trim()
if ($script:schema -notmatch '^tenant_[0-9a-f]{32}$') {
    throw "race tenant schema is missing ($script:schema)"
}

function Invoke-Exec([string]$Command) {
    $sql = "SET search_path TO $script:schema, pg_catalog, extensions; $Command"
    docker exec perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1 -c $sql | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "setup failed"
    }
}

Invoke-Exec "INSERT INTO vendors (id, code, first_name, last_name) VALUES ('01900000-0000-7000-8000-0000000009b0', 'race-cola', 'Cola', 'Race');"
Invoke-Exec "INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity) VALUES ('01900000-0000-7000-8000-0000000009b1', 'Race cola', '01900000-0000-7000-8000-0000000009b0', 'Race cola', 1000, 'BC-RACE-COLA', DATE '2094-05-01', 0, 1);"
Invoke-Exec "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status) VALUES ('01900000-0000-7000-8000-0000000009b2', '01900000-0000-7000-8000-000000000001', '2094-05-01', '2094-05-31', 'open');"

$run = {
    param($Path, $Schema)
    $sql = "SET search_path TO $Schema, pg_catalog, extensions;`n" + (Get-Content -Raw $Path)
    $sql | docker exec -i perfect-consign-postgres psql -U stall -d consign -v ON_ERROR_STOP=1
}

$job = Start-Job -ScriptBlock $run -ArgumentList (Join-Path $root 'prove_race_on_hand_a.sql'), $script:schema
Start-Sleep -Seconds 1
$watch = [Diagnostics.Stopwatch]::StartNew()
$second = & $run (Join-Path $root 'prove_race_on_hand_b.sql') $script:schema
$watch.Stop()
Wait-Job $job | Out-Null
Receive-Job $job | Out-Null
if ($watch.ElapsedMilliseconds -lt 4500) {
    throw "the second sale did not wait ($($watch.ElapsedMilliseconds) ms)"
}

Invoke-Exec "DELETE FROM items WHERE id = '01900000-0000-7000-8000-0000000009b1';"
Invoke-Exec "DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-0000000009b0';"
Invoke-Exec "DELETE FROM accounting_periods WHERE id = '01900000-0000-7000-8000-0000000009b2';"
Write-Output "ON_HAND_RACE_OK $($watch.ElapsedMilliseconds)"
