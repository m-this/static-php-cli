param(
    [Parameter(Mandatory)] [string] $Exe,
    [switch] $EmbeddedApp
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Run from an empty directory with every PHP install removed from PATH, so a
# php8ts.dll lying around cannot satisfy a dependency by accident.
$work = Join-Path $env:RUNNER_TEMP ("fp-test-" + [IO.Path]::GetFileNameWithoutExtension($Exe))
Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
New-Item -ItemType Directory $work | Out-Null
$fp = Join-Path $work 'frankenphp.exe'
Copy-Item $Exe $fp
$env:PATH = ($env:PATH -split ';' | Where-Object {
    $_ -and -not (Get-ChildItem -Path $_ -Filter 'php*.dll' -ErrorAction SilentlyContinue)
}) -join ';'
Set-Location $work

Write-Host "== size"
'{0:N1} MiB ({1} bytes)' -f ((Get-Item $fp).Length / 1MB), (Get-Item $fp).Length

Write-Host "== dumpbin /dependents"
$vs = & "${env:ProgramFiles(x86)}\Microsoft Visual Studio\Installer\vswhere.exe" -latest -products * -property installationPath
$dumpbin = Get-ChildItem "$vs\VC\Tools\MSVC\*\bin\Hostx64\x64\dumpbin.exe" | Select-Object -First 1
$deps = & $dumpbin.FullName /nologo /dependents $fp
$deps | Write-Host
$dlls = $deps | ForEach-Object { $_.Trim() } | Where-Object { $_ -match '\.dll$' }
$forbidden = $dlls | Where-Object { $_ -match '^(php\d*(ts)?|vcruntime\d+|msvcp\d+)(_\w+)?\.dll$' }
if ($forbidden) {
    throw "frankenphp.exe depends on non-system DLLs: $($forbidden -join ', ')"
}
Write-Host "OK: $($dlls.Count) DLL imports, all Windows system DLLs: $($dlls -join ', ')"

Write-Host "== frankenphp version"
& $fp version
if ($LASTEXITCODE -ne 0) { throw "frankenphp version exited $LASTEXITCODE" }

$expected = ($env:EXTENSIONS -split ',' | ForEach-Object { $_.Trim().ToLower() } | Where-Object { $_ -and $_ -ne 'mbregex' }) -join ','
$script = @'
<?php
$loaded = array_map('strtolower', get_loaded_extensions());
$aliases = ['opcache' => 'zend opcache'];
$missing = [];
foreach (array_filter(explode(',', (string) getenv('FP_EXPECTED_EXTENSIONS'))) as $ext) {
    if (!in_array($aliases[$ext] ?? $ext, $loaded, true)) {
        $missing[] = $ext;
    }
}
echo 'PHP_VERSION=', PHP_VERSION, "\n";
echo 'PHP_SAPI=', PHP_SAPI, "\n";
echo 'ZTS=', PHP_ZTS ? 'yes' : 'no', "\n";
echo 'EXTENSIONS=', implode(',', get_loaded_extensions()), "\n";
echo 'MISSING=', implode(',', $missing), "\n";
'@
Set-Content -NoNewline -Path "$work\info.php" -Value $script
$env:FP_EXPECTED_EXTENSIONS = $expected

Write-Host "== php-cli"
$cli = & $fp php-cli "$work\info.php"
if ($LASTEXITCODE -ne 0) { throw "php-cli exited $LASTEXITCODE" }
$cli | Write-Host
$cliText = $cli -join "`n"
if ($cliText -notmatch "PHP_VERSION=$([regex]::Escape($env:PHP_VERSION))\.") { throw "php-cli did not report PHP $env:PHP_VERSION" }
if ($cliText -notmatch 'ZTS=yes') { throw 'php-cli did not report a ZTS build' }
if ($cliText -notmatch 'MISSING=\s*(\n|$)') { throw "extensions missing at runtime: $($cliText -split "`n" | Select-String '^MISSING=')" }

Write-Host "== php-server"
$port = 8089
$serverArgs = @('php-server', '--listen', "127.0.0.1:$port")
$url = "http://127.0.0.1:$port/"
$want = 'PHP_VERSION='
if ($EmbeddedApp) {
    $want = 'embedded app ok'
} else {
    $serverArgs += @('--root', $work)
    $url += 'info.php'
}
$server = Start-Process -FilePath $fp -ArgumentList $serverArgs -PassThru -NoNewWindow `
    -RedirectStandardOutput "$work\server.out" -RedirectStandardError "$work\server.err"
try {
    $body = $null
    foreach ($attempt in 1..30) {
        if ($server.HasExited) { break }
        try {
            $response = Invoke-WebRequest -UseBasicParsing -SkipHttpErrorCheck -TimeoutSec 5 $url
        } catch {
            Start-Sleep -Seconds 1
            continue
        }
        if ($response.StatusCode -ne 200) {
            throw "php-server answered HTTP $($response.StatusCode) on ${url}: $($response.Content)"
        }
        $body = $response.Content
        break
    }
    if ($null -eq $body) {
        Get-Content "$work\server.err" -ErrorAction SilentlyContinue | Write-Host
        throw "php-server did not answer on $url after 30 attempts"
    }
    $body | Write-Host
    if ($body -notmatch [regex]::Escape($want)) { throw "php-server answered without '$want'" }
} finally {
    if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
}

Write-Host "All FrankenPHP checks passed for $Exe"
