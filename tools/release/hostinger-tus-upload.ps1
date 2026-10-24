param([Parameter(Mandatory=$true)][string]$InputPath,[Parameter(Mandatory=$true)][string]$RemoteName)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
if (-not [IO.File]::Exists($InputPath)) { throw 'Upload input does not exist.' }
$assetPrefix = 'wp-content/mu-plugins/dsa/assets/'
$isAsset = $RemoteName -match '^wp-content/mu-plugins/dsa/assets/(?:js/[a-z0-9-]+\.js|css/[a-z0-9-]+\.css)$'
if ($RemoteName -notmatch '^[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}$' -and -not $isAsset) { throw 'Remote name is invalid.' }
if ($isAsset) {
  $assetRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../wp-content/mu-plugins/dsa/assets'))
  $expectedInput = [IO.Path]::GetFullPath((Join-Path $assetRoot $RemoteName.Substring($assetPrefix.Length)))
  if (-not [string]::Equals($expectedInput,[IO.Path]::GetFullPath($InputPath),[StringComparison]::OrdinalIgnoreCase)) { throw 'Asset upload must use its matching package source.' }
}
$secureLine = Read-Host 'Temporary TUS credentials' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureLine)
try { $line = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
if (-not $line) { throw 'Upload credentials were not supplied.' }
$credentials = $line | ConvertFrom-Json
if (-not $credentials.url -or -not $credentials.auth_key -or -not $credentials.rest_auth_key) { throw 'Upload credentials are incomplete.' }
$endpoint = $credentials.url.TrimEnd('/') + '/' + $RemoteName + '?override=true'
$length = (Get-Item -LiteralPath $InputPath).Length
$headers = @{
  'X-Auth' = [string]$credentials.auth_key
  'X-Auth-Rest' = [string]$credentials.rest_auth_key
  'Tus-Resumable' = '1.0.0'
  'Upload-Length' = [string]$length
  'Upload-Offset' = '0'
}
$create = Invoke-WebRequest -Uri $endpoint -Method Post -Headers $headers -UseBasicParsing
if ([int]$create.StatusCode -ne 201) { throw 'TUS create failed.' }
$headers.Remove('Upload-Length')
$uploaded = Invoke-WebRequest -Uri $endpoint -Method Patch -Headers $headers -InFile $InputPath -ContentType 'application/offset+octet-stream' -UseBasicParsing
if ([int]$uploaded.StatusCode -ne 204) { throw 'TUS upload failed.' }
$offset = @($uploaded.Headers['Upload-Offset'])[0]
if ($offset -and [long]$offset -ne $length) { throw 'TUS upload offset did not match file length.' }
Write-Output ('Uploaded ' + $RemoteName + ' (' + $length + ' bytes).')
