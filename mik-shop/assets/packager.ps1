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

foreach ($package in $Plan.packages) {
    $final = Join-Path $output $package.filename
    $partial = $final + '.partial'
    if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial }
    $manifest = @{ version=2; job_id=$Plan.job_id; package_no=$package.number; token=$package.token; files=@{}; aliases=@{}; missing=@() }
    $sum = [long]0
    $zip = [IO.Compression.ZipFile]::Open($partial, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $package.files) {
            $relative = [string]$file.path
            $extension = [IO.Path]::GetExtension($relative).TrimStart('.').ToLowerInvariant()
            if ($relative -match '(^|[/\\])\.\.([/\\]|$)' -or $relative -match '^[/\\]|:' -or (($imageExtensions -notcontains $extension) -and $extension -ne 'pdf')) { throw ('Unsafe path: ' + $relative) }
            $info = Resolve-MikAsset $relative
            if ($null -eq $info) {
                $manifest.missing += $relative
                $missingAll.Add($package.filename + ': ' + $relative)
                continue
            }
            if (($info.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw ('Links are not supported: ' + $relative) }
            $actualRelative = $info.FullName.Substring($root.Length + 1).Replace('\', '/')
            if ($relative -cne $actualRelative) {
                $manifest.aliases[$relative] = $actualRelative
                $replacedAll.Add($package.filename + ': ' + $relative + ' -> ' + $actualRelative)
            }
            if ($manifest.files.ContainsKey($actualRelative)) { continue }
            $sum += $info.Length
            if ($sum -gt $Plan.max_bytes) { throw ('Actual files exceed the per-ZIP MB limit in ' + $package.filename + '. The converted images may be larger than recorded in the old catalogue.') }
            $hash = (Get-FileHash -LiteralPath $info.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            $manifest.files[$actualRelative] = @{ sha256=$hash; size=$info.Length }
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $info.FullName, $actualRelative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
        $entry = $zip.CreateEntry('manifest.json')
        $writer = New-Object IO.StreamWriter($entry.Open(), (New-Object Text.UTF8Encoding($false)))
        try { $writer.Write(($manifest | ConvertTo-Json -Depth 12 -Compress)) } finally { $writer.Dispose() }
    } finally { $zip.Dispose() }
    if ((Get-Item -LiteralPath $partial).Length -gt $Plan.max_bytes) { Remove-Item -LiteralPath $partial; throw ('ZIP exceeds MB limit: ' + $package.filename) }
    Move-Item -LiteralPath $partial -Destination $final -Force
    Write-Host ('Created: ' + $package.filename + '; missing files: ' + $manifest.missing.Count + '; resolved aliases: ' + $manifest.aliases.Count)
}
Set-Content -LiteralPath (Join-Path $output 'missing-files.txt') -Value ($missingAll -join "`r`n") -Encoding UTF8
Set-Content -LiteralPath (Join-Path $output 'replaced-files.txt') -Value ($replacedAll -join "`r`n") -Encoding UTF8
Write-Host ('Ready. Upload packages from: ' + $output)
Write-Host ('Extension replacements: ' + $replacedAll.Count + '. See replaced-files.txt.')
Write-Host ('Missing files: ' + $missingAll.Count + '. See missing-files.txt. Products can still be imported.')
