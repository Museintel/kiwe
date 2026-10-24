param([Parameter(Mandatory=$true)][string]$BaseArchive,[Parameter(Mandatory=$true)][string]$TargetArchive,[Parameter(Mandatory=$true)][string]$OutputArchive,[Parameter(Mandatory=$true)][string]$OutputInventory,[switch]$Spliced)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
function Read-PackageMap([string]$PackagePath){
 $archive=[IO.Compression.ZipFile]::OpenRead($PackagePath)
 $result=[ordered]@{}
 try{foreach($entry in $archive.Entries){$name=$entry.FullName
  if($name.EndsWith('/')){continue}
  if($result.Contains($name) -or $name.StartsWith('/') -or $name.Contains('\') -or $name.Split('/').Contains('..')){throw 'Unsafe or duplicated archive member'}
  $stream=$entry.Open();$memory=New-Object IO.MemoryStream
  try{$stream.CopyTo($memory);$bytes=$memory.ToArray();$sha=[Security.Cryptography.SHA256]::Create();try{$hash=([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}}finally{$stream.Dispose();$memory.Dispose()}
  $result[$name]=@{hash=$hash;bytes=$bytes}
 }}finally{$archive.Dispose()}
 return $result
}
if((Test-Path -LiteralPath $OutputArchive) -or (Test-Path -LiteralPath $OutputInventory)){throw 'Fresh private output required'}
$baseMap=Read-PackageMap $BaseArchive
$targetMap=Read-PackageMap $TargetArchive
foreach($name in $baseMap.Keys){if(-not $targetMap.Contains($name)){throw 'Deletion needs a different reviewed transport'}}
$changed=@($targetMap.Keys | Where-Object {(-not $baseMap.Contains($_)) -or $baseMap[$_].hash -ne $targetMap[$_].hash} | Sort-Object)
if($changed.Count -lt 1){throw 'No package changes'}
$output=[IO.Compression.ZipFile]::Open($OutputArchive,[IO.Compression.ZipArchiveMode]::Create)
try{foreach($name in $changed){$entry=$output.CreateEntry($name,[IO.Compression.CompressionLevel]::Optimal);$stream=$entry.Open();try{$bytes=$targetMap[$name].bytes;if($Spliced){$baseBytes=[byte[]]@();if($baseMap.Contains($name)){$baseBytes=$baseMap[$name].bytes};$request=@{base=[Convert]::ToBase64String($baseBytes);target=[Convert]::ToBase64String($bytes)}|ConvertTo-Json -Compress;$patch=$request|node "$PSScriptRoot/release-byte-splices.cjs";if($LASTEXITCODE -ne 0){throw 'Byte-splice build refused'};$bytes=[Text.Encoding]::UTF8.GetBytes(($patch -join "`n"))};$stream.Write($bytes,0,$bytes.Length)}finally{$stream.Dispose()}}}finally{$output.Dispose()}
$deltaMap=Read-PackageMap $OutputArchive
$targetFiles=[ordered]@{}
foreach($name in $targetMap.Keys){$actual=if($deltaMap.Contains($name)){$deltaMap[$name].hash}else{$baseMap[$name].hash};if($Spliced -and $deltaMap.Contains($name)){$baseBytes=[byte[]]@();if($baseMap.Contains($name)){$baseBytes=$baseMap[$name].bytes};$request=@{base=[Convert]::ToBase64String($baseBytes);patch=([Text.Encoding]::UTF8.GetString($deltaMap[$name].bytes)|ConvertFrom-Json)}|ConvertTo-Json -Depth 12 -Compress;$rebuilt=$request|node "$PSScriptRoot/release-byte-splices.cjs";if($LASTEXITCODE -ne 0){throw 'Byte-splice reconstruction refused'};$bytes=[Convert]::FromBase64String(($rebuilt|ConvertFrom-Json).target);$sha=[Security.Cryptography.SHA256]::Create();try{$actual=([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}};if($actual -ne $targetMap[$name].hash){throw 'Exact reconstruction failed'};$targetFiles[$name]=$targetMap[$name].hash}
$result=@{passed=$true;schema='kiwe.signed-release-deduplicated-transport.v1';targetFiles=$targetFiles;transportFiles=$changed;transportSha256=(Get-FileHash -LiteralPath $OutputArchive -Algorithm SHA256).Hash.ToLowerInvariant();transportBytes=(Get-Item -LiteralPath $OutputArchive).Length;targetArchiveSha256=(Get-FileHash -LiteralPath $TargetArchive -Algorithm SHA256).Hash.ToLowerInvariant();targetArchiveBytes=(Get-Item -LiteralPath $TargetArchive).Length}
if($Spliced){$result['transport']='spliced'}
[IO.File]::WriteAllText($OutputInventory,($result|ConvertTo-Json -Depth 8 -Compress))
@{passed=$true;files=$targetFiles.Count;changedFiles=$changed;transferBytes=$result.transportBytes}|ConvertTo-Json -Depth 3 -Compress
