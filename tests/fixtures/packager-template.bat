@echo off
setlocal DisableDelayedExpansion
set "MIK_SELF=%~f0"
powershell.exe -NoLogo -NoProfile -EncodedCommand JABzAGUAbABmAD0AJABlAG4AdgA6AE0ASQBLAF8AUwBFAEwARgA7ACAAJAByAGEAdwA9AFsASQBPAC4ARgBpAGwAZQBdADoAOgBSAGUAYQBkAEEAbABsAFQAZQB4AHQAKAAkAHMAZQBsAGYAKQA7ACAAJABwAGEAcgB0AHMAPQAkAHIAYQB3ACAALQBzAHAAbABpAHQAIAAiACgAPwBtACkAXgA6ADoATQBJAEsAXwBEAEEAVABBAFwAcgA/ACQAIgAsADIAOwAgACQAYgB5AHQAZQBzAD0AWwBDAG8AbgB2AGUAcgB0AF0AOgA6AEYAcgBvAG0AQgBhAHMAZQA2ADQAUwB0AHIAaQBuAGcAKAAkAHAAYQByAHQAcwBbADEAXQAuAFQAcgBpAG0AKAApACkAOwAgACQAbQBzAD0ATgBlAHcALQBPAGIAagBlAGMAdAAgAEkATwAuAE0AZQBtAG8AcgB5AFMAdAByAGUAYQBtACgALAAkAGIAeQB0AGUAcwApADsAIAAkAGcAegA9AE4AZQB3AC0ATwBiAGoAZQBjAHQAIABJAE8ALgBDAG8AbQBwAHIAZQBzAHMAaQBvAG4ALgBHAHoAaQBwAFMAdAByAGUAYQBtACgAJABtAHMALABbAEkATwAuAEMAbwBtAHAAcgBlAHMAcwBpAG8AbgAuAEMAbwBtAHAAcgBlAHMAcwBpAG8AbgBNAG8AZABlAF0AOgA6AEQAZQBjAG8AbQBwAHIAZQBzAHMAKQA7ACAAJAByAGUAYQBkAGUAcgA9AE4AZQB3AC0ATwBiAGoAZQBjAHQAIABJAE8ALgBTAHQAcgBlAGEAbQBSAGUAYQBkAGUAcgAoACQAZwB6ACwAWwBUAGUAeAB0AC4ARQBuAGMAbwBkAGkAbgBnAF0AOgA6AFUAVABGADgAKQA7ACAAJABwAGwAYQBuAD0AJAByAGUAYQBkAGUAcgAuAFIAZQBhAGQAVABvAEUAbgBkACgAKQAgAHwAIABDAG8AbgB2AGUAcgB0AEYAcgBvAG0ALQBKAHMAbwBuADsAIAAkAHMAYwByAGkAcAB0AD0AJAByAGEAdwAgAC0AcwBwAGwAaQB0ACAAIgAoAD8AbQApAF4AOgA6AE0ASQBLAF8AUABTAFwAcgA/ACQAIgAsADIAOwAgACQAYgBvAGQAeQA9ACgAJABzAGMAcgBpAHAAdABbADEAXQAgAC0AcwBwAGwAaQB0ACAAIgAoAD8AbQApAF4AOgA6AE0ASQBLAF8ARABBAFQAQQBcAHIAPwAkACIALAAyACkAWwAwAF0AOwAgACYAIAAoAFsAUwBjAHIAaQBwAHQAQgBsAG8AYwBrAF0AOgA6AEMAcgBlAGEAdABlACgAJABiAG8AZAB5ACkAKQAgACQAcABsAGEAbgAgACgAWwBJAE8ALgBQAGEAdABoAF0AOgA6AEcAZQB0AEQAaQByAGUAYwB0AG8AcgB5AE4AYQBtAGUAKAAkAHMAZQBsAGYAKQApAA==
set "MIK_EXIT=%ERRORLEVEL%"
rem pause skipped in automated test
exit /b %MIK_EXIT%
::MIK_PS
param($Plan, [string]$BaseDirectory)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$root = Join-Path $BaseDirectory 'full'
if (-not (Test-Path -LiteralPath $root -PathType Container)) {
    $root = Read-Host 'Enter the path to the extracted FULL folder'
}
$root = [IO.Path]::GetFullPath($root).TrimEnd('\', '/')
if (-not (Test-Path -LiteralPath $root -PathType Container)) { throw 'FULL folder not found.' }
$output = Join-Path $BaseDirectory ('mik-packages-' + $Plan.job_id)
[IO.Directory]::CreateDirectory($output) | Out-Null
$missingAll = New-Object System.Collections.Generic.List[string]
foreach ($package in $Plan.packages) {
    $final = Join-Path $output $package.filename
    $partial = $final + '.partial'
    if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial }
    $manifest = @{ version=1; job_id=$Plan.job_id; package_no=$package.number; token=$package.token; files=@{}; missing=@() }
    $sum = [long]0
    $zip = [IO.Compression.ZipFile]::Open($partial, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $package.files) {
            $relative = [string]$file.path
            if ($relative -match '(^|[/\\])\.\.([/\\]|$)' -or $relative -match '^[/\\]|:' -or $relative -notmatch '\.(jpg|jpeg|png|webp|gif|pdf)$') { throw ('Unsafe path: ' + $relative) }
            $source = [IO.Path]::GetFullPath((Join-Path $root ($relative.Replace('/', [IO.Path]::DirectorySeparatorChar))))
            if (-not $source.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Path is outside FULL.' }
            if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
                $manifest.missing += $relative
                $missingAll.Add($package.filename + ': ' + $relative)
                continue
            }
            $info = Get-Item -LiteralPath $source
            if (($info.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw ('Links are not supported: ' + $relative) }
            $sum += $info.Length
            if ($sum -gt $Plan.max_bytes) { throw ('Actual files exceed selected MB limit in ' + $package.filename + '. Generate a plan with a larger limit.') }
            $hash = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()
            $manifest.files[$relative] = @{ sha256=$hash; size=$info.Length }
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $source, $relative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
        $entry = $zip.CreateEntry('manifest.json')
        $writer = New-Object IO.StreamWriter($entry.Open(), (New-Object Text.UTF8Encoding($false)))
        $writer.Write(($manifest | ConvertTo-Json -Depth 12 -Compress))
        $writer.Dispose()
    } finally { $zip.Dispose() }
    if ((Get-Item -LiteralPath $partial).Length -gt $Plan.max_bytes) { Remove-Item -LiteralPath $partial; throw ('ZIP exceeds MB limit: ' + $package.filename) }
    Move-Item -LiteralPath $partial -Destination $final -Force
    Write-Host ('Created: ' + $package.filename + '; missing files: ' + $manifest.missing.Count)
}
$missingAll | Set-Content -LiteralPath (Join-Path $output 'missing-files.txt') -Encoding UTF8
Write-Host ('Ready. Upload packages from: ' + $output)
Write-Host ('Missing files: ' + $missingAll.Count + '. See missing-files.txt. Products can still be imported.')

::MIK_DATA
H4sIALqI2GoC/2WNTQ6CMBCFr0JmDVgUlHoVY0gLg45YaKBEhHB3B8KCxMxq3vd+Jng1OqMCrp6UvgdGDZn+OuxYiEScJpczq1bllXqs4m2Cujca28XAqKQ31sogv2CoCqQMhBBROJIFxq6psF5YSYPrW4QtslVZ5Z47eiDDM+EH9RruaFx6o5NIYzH73r+/s5iHtij37mMy3/l+/A7U8NoAAAA=
