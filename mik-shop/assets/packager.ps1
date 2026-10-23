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

$maximum = [long]$Plan.max_bytes
if ($maximum -lt 1048576 -or $maximum -gt 1073741824) { throw 'Invalid maximum ZIP size in the plan.' }
$budget = [long][Math]::Floor($maximum * 0.98) - 4096
$createdAll = New-Object System.Collections.Generic.List[string]
foreach ($package in $Plan.packages) {
    Write-Host ('Scanning actual files for: ' + $package.filename)
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
            $missingAll.Add($package.filename + ': ' + $relative)
            continue
        }
        if (($info.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw ('Links are not supported: ' + $relative) }
        $actual = $info.FullName.Substring($root.Length + 1).Replace('\','/')
        if (-not $groups.ContainsKey($actual)) {
            $groups[$actual] = [pscustomobject]@{ Info=$info; Relative=$actual; Size=[long]$info.Length; Ticks=$info.LastWriteTimeUtc.Ticks; Aliases=@{}; Hash=''; Cost=[long]0 }
        }
        if ($relative -cne $actual) {
            $groups[$actual].Aliases[$relative] = $actual
            $replacedAll.Add($package.filename + ': ' + $relative + ' -> ' + $actual)
        }
        $scanned++
        if (($scanned % 100) -eq 0) { Write-Progress -Activity 'Scanning FULL' -Status ($package.filename + ': ' + $scanned + ' paths') }
    }
    Write-Progress -Activity 'Scanning FULL' -Completed
    $items = @($groups.Values | Sort-Object -Property Relative)
    $inventory = New-Object Text.StringBuilder
    [void]$inventory.AppendLine(([string]$Plan.job_id + '|' + $package.number + '|' + $maximum + '|' + $package.token))
    $actualBytes = [long]0
    for ($i=0; $i -lt $items.Count; $i++) {
        $item = $items[$i]
        Write-Progress -Activity 'Reading files and checksums' -Status ($package.filename + ': ' + ($i+1) + ' / ' + $items.Count) -PercentComplete ([int](100.0 * ($i+1) / [Math]::Max(1,$items.Count)))
        $item.Hash = (Get-FileHash -LiteralPath $item.Info.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        $now = Get-Item -LiteralPath $item.Info.FullName
        if ($now.Length -ne $item.Size -or $now.LastWriteTimeUtc.Ticks -ne $item.Ticks) { throw ('File changed during collection: ' + $item.Relative + '. Run the BAT again after editing has finished.') }
        $item.Cost = $item.Size + [long][Math]::Ceiling($item.Size / 16384.0)*5 + 512 + 6*[Text.Encoding]::UTF8.GetByteCount($item.Relative)
        [void]$inventory.AppendLine(($item.Relative + '|' + $item.Size + '|' + $item.Hash))
        foreach ($alias in @($item.Aliases.Keys | Sort-Object)) {
            $item.Cost += 512 + 6*[Text.Encoding]::UTF8.GetByteCount($alias)
            [void]$inventory.AppendLine(($alias + '->' + $item.Relative))
        }
        $actualBytes += $item.Size
    }
    foreach ($absent in @($missing | Sort-Object)) { [void]$inventory.AppendLine(('missing:' + $absent)) }
    Write-Progress -Activity 'Reading files and checksums' -Completed
    $hasher = [Security.Cryptography.SHA256]::Create()
    try { $setId = [BitConverter]::ToString($hasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($inventory.ToString()))).Replace('-','').ToLowerInvariant() } finally { $hasher.Dispose() }
    $chunks = New-Object System.Collections.Generic.List[object]
    $chunk = New-Object System.Collections.Generic.List[object]
    $weight = [long]0
    foreach ($item in $items) {
        if ($chunk.Count -gt 0 -and (($weight + $item.Cost) -gt $budget -or $chunk.Count -ge 18000)) {
            $chunks.Add($chunk)
            $chunk = New-Object System.Collections.Generic.List[object]
            $weight = 0
        }
        $chunk.Add($item)
        $weight += $item.Cost
    }
    if ($chunk.Count -gt 0 -or $chunks.Count -eq 0) { $chunks.Add($chunk) }
    Write-Host ('Actual files: ' + $items.Count + '; size: ' + [Math]::Round($actualBytes/1048576.0,2) + ' MB; ZIP parts: ' + $chunks.Count + '; maximum each: ' + ($maximum/1048576) + ' MB')
    $retry = $true
    while ($retry) {
        $retry = $false
        if ($chunks.Count -gt 10000) { throw 'Too many ZIP parts for one logical package.' }
        $temporary = New-Object System.Collections.Generic.List[string]
        for ($partIndex=0; $partIndex -lt $chunks.Count; $partIndex++) {
            $partItems = $chunks[$partIndex]
            $filename = [IO.Path]::GetFileNameWithoutExtension($package.filename) + '-part' + ($partIndex+1).ToString('D4') + '-of' + $chunks.Count.ToString('D4') + '.zip'
            $partial = Join-Path $output ($filename + '.partial')
            if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial }
            $temporary.Add($partial)
            $manifest = @{ version=3; job_id=$Plan.job_id; package_no=$package.number; token=$package.token; part_no=($partIndex+1); parts_total=$chunks.Count; part_set=$setId; files=@{}; aliases=@{}; missing=@() }
            $manifest.missing_count = $missing.Count
            $zip = [IO.Compression.ZipFile]::Open($partial,[IO.Compression.ZipArchiveMode]::Create)
            $manifestBytes = 0
            try {
                $written = 0
                foreach ($item in $partItems) {
                    Write-Progress -Activity ('Packing ' + $filename) -Status (($written+1).ToString() + ' / ' + $partItems.Count + ' files') -PercentComplete ([int](100.0 * ($written+1) / [Math]::Max(1,$partItems.Count)))
                    $now = Get-Item -LiteralPath $item.Info.FullName
                    if ($now.Length -ne $item.Size -or $now.LastWriteTimeUtc.Ticks -ne $item.Ticks) { throw ('File changed after collection: ' + $item.Relative + '. Run the BAT again.') }
                    $manifest.files[$item.Relative] = @{ sha256=$item.Hash; size=$item.Size }
                    foreach ($alias in $item.Aliases.Keys) { $manifest.aliases[$alias] = $item.Relative }
                    [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip,$item.Info.FullName,$item.Relative,[IO.Compression.CompressionLevel]::Optimal) | Out-Null
                    $written++
                }
                $json = $manifest | ConvertTo-Json -Depth 12 -Compress
                $manifestBytes = [Text.Encoding]::UTF8.GetByteCount($json)
                $entry = $zip.CreateEntry('manifest.json')
                $writer = New-Object IO.StreamWriter($entry.Open(),(New-Object Text.UTF8Encoding($false)))
                try { $writer.Write($json) } finally { $writer.Dispose() }
            } finally { $zip.Dispose(); Write-Progress -Activity ('Packing ' + $filename) -Completed }
            $zipBytes = (Get-Item -LiteralPath $partial).Length
            if ($zipBytes -gt $maximum -or $manifestBytes -gt 8388608) {
                foreach ($tmp in $temporary) { if (Test-Path -LiteralPath $tmp) { Remove-Item -LiteralPath $tmp } }
                if ($partItems.Count -le 1) { throw ('One individual file cannot fit in a ZIP of ' + ($maximum/1048576) + ' MB: ' + $partItems[0].Relative + '. Its compressed size is ' + [Math]::Round($zipBytes/1048576.0,2) + ' MB. Reduce this file or choose a larger per-ZIP limit.') }
                $left = New-Object System.Collections.Generic.List[object]
                $right = New-Object System.Collections.Generic.List[object]
                $half = [int][Math]::Floor($partItems.Count/2.0)
                for ($k=0; $k -lt $partItems.Count; $k++) { if ($k -lt $half) { $left.Add($partItems[$k]) } else { $right.Add($partItems[$k]) } }
                $chunks.RemoveAt($partIndex); $chunks.Insert($partIndex,$right); $chunks.Insert($partIndex,$left)
                Write-Host ('Splitting the large part further. New part count: ' + $chunks.Count)
                $retry = $true
                break
            }
        }
        if (-not $retry) {
            foreach ($tmp in $temporary) {
                $final = $tmp.Substring(0,$tmp.Length - '.partial'.Length)
                Move-Item -LiteralPath $tmp -Destination $final -Force
                $createdAll.Add([IO.Path]::GetFileName($final))
                Write-Host ('Created: ' + [IO.Path]::GetFileName($final) + ' (' + [Math]::Round((Get-Item -LiteralPath $final).Length/1048576.0,2) + ' MB)')
            }
        }
    }
}
Set-Content -LiteralPath (Join-Path $output 'missing-files.txt') -Value ($missingAll -join "`r`n") -Encoding UTF8
Set-Content -LiteralPath (Join-Path $output 'replaced-files.txt') -Value ($replacedAll -join "`r`n") -Encoding UTF8
Set-Content -LiteralPath (Join-Path $output 'upload-order.txt') -Value ($createdAll -join "`r`n") -Encoding UTF8
Write-Host ('Ready. Upload ZIP parts from: ' + $output)
Write-Host ('ZIP files: ' + $createdAll.Count + '. See upload-order.txt. Upload all parts of each package.')
Write-Host ('Extension replacements: ' + $replacedAll.Count + '; missing files: ' + $missingAll.Count)
