$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$base = Join-Path $env:RUNNER_TEMP 'mik-packer-test'
[IO.Directory]::CreateDirectory((Join-Path $base 'full/fixture')) | Out-Null
Copy-Item -LiteralPath (Join-Path $repo 'mik-shop/assets/img/slider-components.webp') -Destination (Join-Path $base 'full/fixture/image.webp') -Force
[IO.File]::WriteAllText((Join-Path $base 'full/fixture/spec.pdf'), "%PDF-1.4`n%%EOF")
$files = @(@{path='fixture/image.webp';size=150000},@{path='fixture/spec.pdf';size=20})
$plan = @{job_id=99; max_bytes=1048576; packages=@(@{number=1;filename='mik-99-0001.zip';token='fixture';files=$files})}
& (Join-Path $repo 'mik-shop/assets/packager.ps1') $plan $base
$archive = Join-Path $base 'mik-packages-99/mik-99-0001.zip'
if (-not (Test-Path -LiteralPath $archive)) { throw 'ZIP was not generated.' }
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::OpenRead($archive)
try {
    if ($zip.Entries.Count -ne 3) { throw 'Unexpected ZIP entries.' }
    $entry = $zip.GetEntry('manifest.json')
    $reader = New-Object IO.StreamReader($entry.Open())
    $m = $reader.ReadToEnd() | ConvertFrom-Json
    $reader.Dispose()
    if ($m.job_id -ne 99 -or $m.package_no -ne 1) { throw 'Wrong manifest identifiers.' }
    $hash = (Get-FileHash -LiteralPath (Join-Path $base 'full/fixture/image.webp') -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($m.files.'fixture/image.webp'.sha256 -ne $hash) { throw 'SHA-256 mismatch.' }
} finally { $zip.Dispose() }
Write-Host 'WINDOWS PACKAGER PASSED: ZIP entries, manifest, SHA-256, paths.'
