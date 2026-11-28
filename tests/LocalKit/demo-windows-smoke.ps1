# Synthetic, filesystem-only smoke for the one-script launch decision; no database process is started.
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../../bin/demo-windows.ps1') -TestLibraryOnly
$temp = Join-Path ([IO.Path]::GetTempPath()) ('kandoo-launch-' + [guid]::NewGuid().ToString('N'))
$mysql = Join-Path $temp 'mysql'
New-Item -ItemType Directory -Force -Path (Join-Path $mysql 'bin') | Out-Null
New-Item -ItemType File -Path (Join-Path $mysql 'bin/mysqld.exe') | Out-Null
New-Item -ItemType File -Path (Join-Path $mysql 'bin/my.ini') | Out-Null
$script:portCalls = 0; $script:startCalls = 0; $script:mode = 'running'
function Test-DemoDatabasePort {
    $script:portCalls++
    if ($script:mode -eq 'running') { return $true }
    if ($script:mode -eq 'starts') { return $script:portCalls -ge 3 }
    return $false
}
function Start-Process {
    param($FilePath, $ArgumentList, $WorkingDirectory, $WindowStyle)
    $script:startCalls++
    if ($FilePath -ne (Join-Path $mysql 'bin/mysqld.exe') -or $WorkingDirectory -ne $mysql) { throw 'Unexpected executable or working directory' }
    if ($ArgumentList.Count -ne 2 -or $ArgumentList[0] -notmatch '^--defaults-file=' -or $ArgumentList[1] -ne '--standalone') { throw 'Unexpected MariaDB arguments' }
}
function Start-Sleep { param($Milliseconds) }
try {
    Start-DemoMariaDbIfNeeded $temp
    if ($script:startCalls -ne 0) { throw 'Running instance must not be restarted' }
    $script:mode = 'starts'; $script:portCalls = 0
    Start-DemoMariaDbIfNeeded $temp
    if ($script:startCalls -ne 1) { throw 'Expected one startup attempt' }
    $script:mode = 'never'; $script:portCalls = 0
    try { Start-DemoMariaDbIfNeeded $temp; throw 'Expected bounded startup failure' }
    catch { if ($_.Exception.Message -notlike 'MariaDB did not start*') { throw } }
    Remove-Item -LiteralPath (Join-Path $mysql 'bin/my.ini')
    try { Start-DemoMariaDbIfNeeded $temp; throw 'Expected missing config failure' }
    catch { if ($_.Exception.Message -notlike 'MariaDB is not listening*') { throw } }
    if ($script:startCalls -ne 2) { throw 'Missing config must not start a process' }
    Write-Output 'PASS: synthetic local kit decision smoke; no MariaDB process was launched.'
} finally { Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue }
