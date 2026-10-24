param([Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$muRoot = Join-Path $repoRoot 'wp-content/mu-plugins'
$archivePath = [System.IO.Path]::GetFullPath($OutputPath)
if (-not [System.IO.Path]::IsPathRooted($OutputPath)) { throw 'OutputPath must be absolute.' }
if ($archivePath.StartsWith($repoRoot + [System.IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Keep deployment artifacts outside the source repository.' }
& node (Join-Path $repoRoot 'tools/release/verify-package.cjs')
if ($LASTEXITCODE -ne 0) { throw 'Package verification failed.' }
$entries = @(Get-ChildItem -LiteralPath (Join-Path $muRoot 'dsa') -Recurse -Force)
if ($entries | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }) { throw 'Package cannot contain links or junctions.' }
$files = @($entries | Where-Object { -not $_.PSIsContainer }) + @(Get-Item -LiteralPath (Join-Path $muRoot 'dsa.php'), (Join-Path $muRoot 'kiwe-incident-guard.php'))
if ($files | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }) { throw 'Loaders cannot be links.' }
$archiveStream = [IO.File]::Open($archivePath,[IO.FileMode]::CreateNew)
$writer = [IO.Compression.ZipArchive]::new($archiveStream,[IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($sourceFile in ($files | Sort-Object FullName)) {
        $entryName = $sourceFile.FullName.Substring($muRoot.Length + 1).Replace('\','/')
        $entry = $writer.CreateEntry($entryName,[IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = [DateTimeOffset]::new(2026,9,6,0,0,0,[TimeSpan]::Zero)
        $sourceStream = [IO.File]::OpenRead($sourceFile.FullName)
        $destinationStream = $entry.Open()
        try { $sourceStream.CopyTo($destinationStream) } finally { $sourceStream.Dispose(); $destinationStream.Dispose() }
    }
} finally { $writer.Dispose(); $archiveStream.Dispose() }
$reader = [IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    if ($reader.Entries.Count -ne $files.Count) { throw 'Archive file count mismatch.' }
    foreach ($sourceFile in $files) {
        $entryName = $sourceFile.FullName.Substring($muRoot.Length + 1).Replace('\','/')
        $stream = $reader.GetEntry($entryName).Open()
        $hasher = [Security.Cryptography.SHA256]::Create()
        try { $actual = [BitConverter]::ToString($hasher.ComputeHash($stream)).Replace('-','') } finally { $stream.Dispose(); $hasher.Dispose() }
        if ($actual -ne (Get-FileHash -LiteralPath $sourceFile.FullName -Algorithm SHA256).Hash) { throw "Archive content mismatch: $entryName" }
    }
} finally { $reader.Dispose() }
[pscustomobject]@{archive=$archivePath;files=$files.Count;bytes=(Get-Item -LiteralPath $archivePath).Length;sha256=(Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLower()} | ConvertTo-Json -Compress
