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
$baseOutput = Join-Path $BaseDirectory ('mik-packages-' + $Plan.job_id)
$output = Join-Path $baseOutput ('build-' + [DateTime]::Now.ToString('yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0,8))
[IO.Directory]::CreateDirectory($output) | Out-Null
$imageExtensions = @('jpg','jpeg','jpe','png','webp','gif','avif','bmp','dib','tif','tiff','ico','heic','heif')
if ($Plan.image_extensions) { $imageExtensions = @($Plan.image_extensions) }
$sourceCache = [Collections.Generic.Dictionary[string,object]]::new([StringComparer]::OrdinalIgnoreCase)
$missingAll = New-Object System.Collections.Generic.List[string]
$replacedAll = New-Object System.Collections.Generic.List[string]

function Resolve-MikAsset([string]$Relative) {
    $source = [IO.Path]::GetFullPath((Join-Path $root ($Relative.Replace('/', [IO.Path]::DirectorySeparatorChar))))
    if (-not $source.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Path is outside FULL.' }
    if ($sourceCache.ContainsKey($source)) { return $sourceCache[$source] }
    $found = $null
    if (Test-Path -LiteralPath $source -PathType Leaf) {
        $found = Get-Item -LiteralPath $source
    } else {
        $extension = [IO.Path]::GetExtension($source).TrimStart('.').ToLowerInvariant()
        if ($imageExtensions -contains $extension) {
            $directory = [IO.Path]::GetDirectoryName($source)
            $stem = [IO.Path]::GetFileNameWithoutExtension($source)
            foreach ($alternateExtension in $imageExtensions) {
                $candidate = Join-Path $directory ($stem + '.' + $alternateExtension)
                if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                    $found = Get-Item -LiteralPath $candidate
                    break
                }
            }
        }
    }
    $sourceCache[$source] = $found
    return $found
}

# The site's size is a planning estimate. Keep exactly one ZIP per selected package.
$reference = [long]$Plan.max_bytes
$createdAll = New-Object System.Collections.Generic.List[string]
foreach ($package in $Plan.packages) {
    $filename = [string]$package.filename
    if ([IO.Path]::GetFileName($filename) -ne $filename -or $filename -notmatch '\.zip$') { throw 'Unsafe package filename in the plan.' }
    Write-Host ('Collecting ' + $filename + '. Planned reference: ' + [Math]::Round($reference/1048576.0,2) + ' MB; actual size may be larger.')
    $groups = [Collections.Generic.Dictionary[string,object]]::new([StringComparer]::OrdinalIgnoreCase)
    $missing = New-Object System.Collections.Generic.List[string]
    $scanned = 0
    foreach ($file in $package.files) {
        $relative = [string]$file.path
        $extension = [IO.Path]::GetExtension($relative).TrimStart('.').ToLowerInvariant()
        if ($relative -match '(^|[/\\])\.\.([/\\]|$)' -or $relative -match '^[/\\]|:' -or (($imageExtensions -notcontains $extension) -and $extension -ne 'pdf')) { throw ('Unsafe path: ' + $relative) }
        $info = Resolve-MikAsset $relative
        if ($null -eq $info) {
            $missing.Add($relative)
            $missingAll.Add($filename + ': ' + $relative)
            continue
        }
        if (($info.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw ('Links are not supported: ' + $relative) }
        $actual = $info.FullName.Substring($root.Length + 1).Replace('\','/')
        if (-not $groups.ContainsKey($actual)) {
            $groups[$actual] = [pscustomobject]@{ Info=$info; Relative=$actual; Size=[long]$info.Length; Ticks=$info.LastWriteTimeUtc.Ticks; Aliases=@{} }
        }
        if ($relative -cne $actual) {
            $groups[$actual].Aliases[$relative] = $actual
            $replacedAll.Add($filename + ': ' + $relative + ' -> ' + $actual)
        }
        $scanned++
        if (($scanned % 100) -eq 0) { Write-Progress -Activity 'Scanning FULL' -Status ($filename + ': ' + $scanned + ' paths') }
    }
    Write-Progress -Activity 'Scanning FULL' -Completed
    $items = @($groups.Values | Sort-Object -Property Relative)
    $final = Join-Path $output $filename
    $partial = $final + '.partial'
    $manifest = @{ version=2; size_mode='estimate'; job_id=$Plan.job_id; package_no=$package.number; token=$package.token; files=@{}; aliases=@{}; missing_count=$missing.Count }
    $zip = [IO.Compression.ZipFile]::Open($partial,[IO.Compression.ZipArchiveMode]::Create)
    $complete = $false
    try {
        for ($i=0; $i -lt $items.Count; $i++) {
            $item = $items[$i]
            Write-Progress -Activity ('Packing ' + $filename) -Status (($i+1).ToString() + ' / ' + $items.Count + ' files') -PercentComplete ([int](100.0 * ($i+1) / [Math]::Max(1,$items.Count)))
            $now = Get-Item -LiteralPath $item.Info.FullName
            if ($now.Length -ne $item.Size -or $now.LastWriteTimeUtc.Ticks -ne $item.Ticks) { throw ('File changed during collection: ' + $item.Relative + '. Run the BAT again after editing has finished.') }
            $hash = (Get-FileHash -LiteralPath $item.Info.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip,$item.Info.FullName,$item.Relative,[IO.Compression.CompressionLevel]::Optimal) | Out-Null
            $now = Get-Item -LiteralPath $item.Info.FullName
            if ($now.Length -ne $item.Size -or $now.LastWriteTimeUtc.Ticks -ne $item.Ticks) { throw ('File changed while packing: ' + $item.Relative + '. Run the BAT again.') }
            $manifest.files[$item.Relative] = @{ sha256=$hash; size=$item.Size }
            foreach ($alias in $item.Aliases.Keys) { $manifest.aliases[$alias] = $item.Relative }
        }
        $entry = $zip.CreateEntry('manifest.json')
        $writer = New-Object IO.StreamWriter($entry.Open(),(New-Object Text.UTF8Encoding($false)))
        try { $writer.Write(($manifest | ConvertTo-Json -Depth 12 -Compress)) } finally { $writer.Dispose() }
        $complete = $true
    } finally {
        $zip.Dispose()
        Write-Progress -Activity ('Packing ' + $filename) -Completed
        if (-not $complete -and (Test-Path -LiteralPath $partial)) { Remove-Item -LiteralPath $partial }
    }
    Move-Item -LiteralPath $partial -Destination $final -Force
    $createdAll.Add($filename)
    Write-Host ('Created: ' + $filename + ' (' + [Math]::Round((Get-Item -LiteralPath $final).Length/1048576.0,2) + ' MB). Package contents and filename match the site plan.')
}
Set-Content -LiteralPath (Join-Path $output 'missing-files.txt') -Value ($missingAll -join "`r`n") -Encoding UTF8
Set-Content -LiteralPath (Join-Path $output 'replaced-files.txt') -Value ($replacedAll -join "`r`n") -Encoding UTF8
Set-Content -LiteralPath (Join-Path $output 'upload-order.txt') -Value ($createdAll -join "`r`n") -Encoding UTF8
Write-Host ('Ready. Upload packages from: ' + $output)
Write-Host ('ZIP files: ' + $createdAll.Count + '; selected packages in the plan: ' + @($Plan.packages).Count)
Write-Host ('Extension replacements: ' + $replacedAll.Count + '; missing files: ' + $missingAll.Count)
