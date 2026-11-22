# Kandoo local demo, Windows PowerShell 5.1+. Start MariaDB in XAMPP first.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
if (!(Test-Path -LiteralPath $php)) { throw 'PHP XAMPP not found at C:\xampp\php\php.exe.' }
$demo = Join-Path $root '.kandoo-demo'
$config = Join-Path $demo 'config.php'
$setup = Join-Path $PSScriptRoot 'demo-setup.php'
if (!(Test-Path -LiteralPath $config)) {
    if (Test-Path -LiteralPath $demo) { throw 'Incomplete demo directory exists. Do not overwrite it; consult the guide.' }
    New-Item -ItemType Directory -Path $demo | Out-Null
    $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $demo /inheritance:r /grant:r "*${sid}:(OI)(CI)F" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Cannot protect the demo directory; stopping.' }
    $ip = Read-Host 'Private IPv4 address of this computer (10.x / 172.16-31.x / 192.168.x)'
    $env:KANDOO_DEMO_DB_ROOT_PASSWORD = Read-Host 'XAMPP MariaDB root password (input hidden)' -AsSecureString | ForEach-Object {
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($_)
        try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
    }
    $env:KANDOO_DEMO_ADMIN_PASSWORD = Read-Host 'New admin password, 12+ characters (input hidden)' -AsSecureString | ForEach-Object {
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($_)
        try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
    }
    try { & $php $setup setup $ip 8080; if ($LASTEXITCODE -ne 0) { throw 'Setup failed; see guide. No database was deleted.' } }
    finally { Remove-Item Env:KANDOO_DEMO_DB_ROOT_PASSWORD, Env:KANDOO_DEMO_ADMIN_PASSWORD -ErrorAction SilentlyContinue }
}
& $php $setup check
if ($LASTEXITCODE -ne 0) { throw 'Self-test failed; server not started.' }
$address = & $php $setup address
if ($LASTEXITCODE -ne 0 -or !$address) { throw 'Cannot determine demo address.' }
$env:KANDOO_CONFIG = $config
$env:KANDOO_BLOCK_REAL_SMS = '1'
Write-Host "Local demo running at http://$address/ (Ctrl+C to stop). Admin is owner computer only."
# PHP's built-in access log includes paths/tokens. Discard it, never write it to a log file.
try { & $php -S $address -t (Join-Path $root 'public') (Join-Path $root 'public\index.php') 2>$null }
finally { Remove-Item Env:KANDOO_CONFIG, Env:KANDOO_BLOCK_REAL_SMS -ErrorAction SilentlyContinue }
