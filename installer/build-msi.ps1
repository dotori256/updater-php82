<#
  솔루션별 MSI 설치 파일 빌드 (WiX Toolset v3.14.1, 무료 / MS-RL)

  사용법 (installer 폴더에서):
    build-msi.bat MWAGENT          솔루션 하나 빌드
    build-msi.bat -All             solutions\ 아래 모든 솔루션 빌드
    build-msi.bat -InstallWix      WiX v3.14.1 을 tools\wix314 에 내려받기 (처음 한 번)

  결과: out\<ID>-Setup-<버전>.msi
  자세한 설명: docs\MSI_BUILD.md
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0, ValueFromRemainingArguments = $true)] [string[]] $Solution,
    [switch] $All,
    [switch] $InstallWix
)

$ErrorActionPreference = 'Stop'
$Root      = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot  = Split-Path -Parent $Root
$SolDir    = Join-Path $Root 'solutions'
$OutDir    = Join-Path $Root 'out'
$ObjDir    = Join-Path $Root 'obj'
$WixLocal  = Join-Path $Root 'tools\wix314'
$Utf8Bom   = New-Object System.Text.UTF8Encoding($true)

# WiX v3.14.1 (2024-04, 마지막 v3 보안 패치). OSMF 가 붙은 v6 이상이 아님.
$WixUrl    = 'https://github.com/wixtoolset/wix3/releases/download/wix3141rtm/wix314-binaries.zip'
$WixSha256 = '6AC824E1642D6F7277D0ED7EA09411A508F6116BA6FAE0AA5F2C7DAA2FF43D31'

# UpgradeCode 를 솔루션 ID 로부터 항상 같은 값으로 만들기 위한 고정 네임스페이스 (절대 바꾸지 말 것)
$UpgradeNamespace = [Guid]'7d3c2b5e-6a41-4f0e-9c1a-2f5b8e9d4a10'

function Fail([string]$msg) { Write-Host "오류: $msg" -ForegroundColor Red; exit 1 }

# ---------------------------------------------------------------- WiX 준비
function Install-Wix {
    if (Test-Path (Join-Path $WixLocal 'candle.exe')) { Write-Host "WiX 가 이미 있습니다: $WixLocal"; Patch-WixConfig; return }
    New-Item -ItemType Directory -Force -Path $WixLocal | Out-Null
    $zip = Join-Path $env:TEMP 'wix314-binaries.zip'
    Write-Host "WiX v3.14.1 내려받는 중... $WixUrl"
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072
    (New-Object Net.WebClient).DownloadFile($WixUrl, $zip)
    $hash = (Get-FileHash -Algorithm SHA256 $zip).Hash
    if ($hash -ne $WixSha256) { Remove-Item $zip -Force; Fail "내려받은 파일의 SHA-256 이 다릅니다 ($hash). 중단합니다." }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($zip, $WixLocal)
    Remove-Item $zip -Force
    Patch-WixConfig
    Write-Host "설치 완료: $WixLocal" -ForegroundColor Green
}

# WiX v3 는 .NET 3.5 용으로 빌드되어 있어 .NET 3.5 가 없는 Windows 10/11 에서는 실행할 때 설치 창이 뜹니다.
# 내려받은 도구의 .config 에 .NET 4 실행을 허용해 별도 설치 없이 쓰도록 합니다 (.NET 3.5 가 있으면 그대로 3.5 사용).
function Patch-WixConfig {
    foreach ($n in 'candle.exe.config', 'light.exe.config') {
        $p = Join-Path $WixLocal $n
        if (-not (Test-Path $p)) { continue }
        $xml = [IO.File]::ReadAllText($p)
        if ($xml -match 'supportedRuntime') { continue }
        $xml = $xml -replace '<configuration>', "<configuration>`r`n    <startup useLegacyV2RuntimeActivationPolicy=`"true`">`r`n        <supportedRuntime version=`"v2.0.50727`" />`r`n        <supportedRuntime version=`"v4.0`" />`r`n    </startup>"
        [IO.File]::WriteAllText($p, $xml, $Utf8Bom)
    }
}

function Find-WixBin {
    $cands = @($WixLocal)
    if ($env:WIX) { $cands += (Join-Path $env:WIX 'bin') }
    $cands += "${env:ProgramFiles(x86)}\WiX Toolset v3.14\bin"
    foreach ($c in $cands) { if ($c -and (Test-Path (Join-Path $c 'candle.exe'))) { return $c } }
    $onPath = Get-Command candle.exe -ErrorAction SilentlyContinue
    if ($onPath) { return (Split-Path -Parent $onPath.Source) }
    Fail "WiX v3.14 을 찾을 수 없습니다. 먼저 'build-msi.bat -InstallWix' 를 실행하세요."
}

# ---------------------------------------------------------------- 도우미
function Read-Ini([string]$path) {
    $d = @{}
    foreach ($raw in [IO.File]::ReadAllLines($path, [Text.Encoding]::UTF8)) {
        $line = $raw.Trim()
        if ($line.Length -eq 0 -or $line.StartsWith(';') -or $line.StartsWith('#') -or $line.StartsWith('[')) { continue }
        $i = $line.IndexOf('=')
        if ($i -lt 1) { continue }
        $d[$line.Substring(0, $i).Trim().ToLowerInvariant()] = $line.Substring($i + 1).Trim()
    }
    return $d
}

function Get-Val($ini, [string]$key, [string]$default = '') {
    $k = $key.ToLowerInvariant()
    if ($ini.ContainsKey($k) -and $ini[$k].Length -gt 0) { return $ini[$k] }
    return $default
}

# RFC 4122 UUID v5 (SHA-1) : 같은 솔루션 ID 는 언제 어디서 빌드해도 같은 UpgradeCode
function New-UuidV5([Guid]$ns, [string]$name) {
    $nsb = $ns.ToByteArray()
    # .NET Guid 바이트 순서 -> 네트워크 순서
    [Array]::Reverse($nsb, 0, 4); [Array]::Reverse($nsb, 4, 2); [Array]::Reverse($nsb, 6, 2)
    $nb = [Text.Encoding]::UTF8.GetBytes($name)
    $sha = [Security.Cryptography.SHA1]::Create()
    $h = $sha.ComputeHash([byte[]]($nsb + $nb))
    $b = New-Object byte[] 16
    [Array]::Copy($h, $b, 16)
    $b[6] = ($b[6] -band 0x0F) -bor 0x50
    $b[8] = ($b[8] -band 0x3F) -bor 0x80
    [Array]::Reverse($b, 0, 4); [Array]::Reverse($b, 4, 2); [Array]::Reverse($b, 6, 2)
    return ([Guid]::new([byte[]]$b)).ToString().ToUpperInvariant()
}

function Check-Text([string]$label, [string]$v) {
    if ($v -match '["<>$]') { Fail "$label 에 쓸 수 없는 문자(`" < > `$)가 있습니다: $v" }
    if ($v -match '[\\/:*?|]' -and $label -like '*폴더*') { Fail "$label 에 폴더 이름으로 쓸 수 없는 문자가 있습니다: $v" }
    # MSI 는 코드페이지 949(한국어)로 만듭니다. 949 로 표현 못 하는 글자는 깨지므로 미리 막음
    $enc = [Text.Encoding]::GetEncoding(949, [Text.EncoderFallback]::ExceptionFallback, [Text.DecoderFallback]::ExceptionFallback)
    try { [void]$enc.GetBytes($v) } catch { Fail "$label 에 한국어 코드페이지(949)로 쓸 수 없는 글자가 있습니다: $v" }
}

# updater.ini 의 key=value 한 줄을 바꾸거나(주석 처리된 줄 포함) 없으면 끝에 추가
function Set-IniLine([string[]]$lines, [string]$key, [string]$value) {
    $out = New-Object System.Collections.Generic.List[string]
    $done = $false
    foreach ($l in $lines) {
        if (-not $done -and $l -match ("^\s*;?\s*" + [Regex]::Escape($key) + "\s*=")) { $out.Add("$key=$value"); $done = $true }
        else { $out.Add($l) }
    }
    if (-not $done) { $out.Add("$key=$value") }
    return ,$out.ToArray()
}

# ---------------------------------------------------------------- 솔루션 하나 빌드
function Build-One([string]$id, [string]$wixBin) {
    $dir = Join-Path $SolDir $id
    $cfgPath = Join-Path $dir 'solution.ini'
    if (-not (Test-Path $cfgPath)) { Fail "$cfgPath 가 없습니다. solutions\_TEMPLATE 을 복사해 만드세요." }
    if ($id -notmatch '^[A-Za-z0-9_-]{1,40}$') { Fail "솔루션 ID 는 영문/숫자/_/- 만 쓸 수 있습니다: $id" }

    $c = Read-Ini $cfgPath
    $productName  = Get-Val $c 'ProductName' $id
    $manufacturer = Get-Val $c 'Manufacturer'
    if (-not $manufacturer) { Fail "$id : solution.ini 에 Manufacturer(회사명) 를 넣으세요." }
    $companyDir   = Get-Val $c 'CompanyFolder' $manufacturer
    $folderName   = Get-Val $c 'InstallFolderName' $id
    $shortcut     = Get-Val $c 'ShortcutName' $productName
    $shortDesc    = Get-Val $c 'ShortcutDescription' "$productName 실행 (자동 업데이트)"
    $version      = Get-Val $c 'Version' '1.0.0'
    $desktop      = if ((Get-Val $c 'DesktopShortcut' '1') -eq '0') { '0' } else { '1' }
    $upgrade      = Get-Val $c 'UpgradeCode' (New-UuidV5 $UpgradeNamespace "AutoUpdater/$($id.ToUpperInvariant())")

    foreach ($p in @(@('ProductName', $productName), @('Manufacturer', $manufacturer), @('ShortcutName', $shortcut),
                     @('ShortcutDescription', $shortDesc), @('CompanyFolder 폴더', $companyDir), @('InstallFolderName 폴더', $folderName))) {
        Check-Text $p[0] $p[1]
    }
    if ($version -notmatch '^(\d{1,3})\.(\d{1,3})\.(\d{1,5})$' -or [int]$Matches[1] -gt 255 -or [int]$Matches[2] -gt 255 -or [int]$Matches[3] -gt 65535) {
        Fail "$id : Version 은 0~255.0~255.0~65535 형식이어야 합니다 (MSI 규칙): $version"
    }
    try { [void][Guid]$upgrade } catch { Fail "$id : UpgradeCode 가 GUID 형식이 아닙니다: $upgrade" }

    # 아이콘 / 로고
    $icon = Get-Val $c 'Icon' 'icon.ico'
    $iconPath = Join-Path $dir $icon
    if (-not (Test-Path $iconPath)) { $iconPath = Join-Path $RepoRoot 'updater\app.ico'; Write-Host "  아이콘 없음 -> 기본 아이콘(updater\app.ico) 사용" }
    $logo = Get-Val $c 'Logo' 'logo.png'
    $logoPath = Join-Path $dir $logo
    if (-not (Test-Path $logoPath)) { $logoPath = '' }

    # Updater.exe : 솔루션 폴더에 있으면 그것, 없으면 저장소의 최신 런처
    $exe = Join-Path $dir 'Updater.exe'
    if (-not (Test-Path $exe)) { $exe = Join-Path $RepoRoot 'updater\Updater.exe' }
    if (-not (Test-Path $exe)) { Fail "Updater.exe 가 없습니다. updater\build.bat 로 먼저 빌드하세요." }

    # updater.ini : 관리 사이트 [설정 / 설치] 에서 받은 파일이 솔루션 폴더에 있으면 그대로 사용(권장),
    #               없으면 updater\updater.ini 를 바탕으로 solution.ini 값을 넣어 생성
    $obj = Join-Path $ObjDir $id
    New-Item -ItemType Directory -Force -Path $obj, $OutDir | Out-Null
    $iniOut = Join-Path $obj 'updater.ini'
    $siteIni = Join-Path $dir 'updater.ini'
    if (Test-Path $siteIni) {
        $lines = [IO.File]::ReadAllLines($siteIni, [Text.Encoding]::UTF8)
        Write-Host "  updater.ini: 솔루션 폴더의 파일 사용 (관리 사이트에서 받은 파일)"
    } else {
        $lines = [IO.File]::ReadAllLines((Join-Path $RepoRoot 'updater\updater.ini'), [Text.Encoding]::UTF8)
        $lines = Set-IniLine $lines 'App' $id
        foreach ($k in 'ServerUrl', 'ClientKey', 'SignKey', 'LaunchExe', 'LaunchArgs', 'AutoStart', 'ShowWindow') {
            $v = Get-Val $c $k
            if ($v) { $lines = Set-IniLine $lines $k $v }
        }
        Write-Host "  updater.ini: solution.ini 값으로 생성"
    }
    [IO.File]::WriteAllLines($iniOut, $lines, (New-Object System.Text.UTF8Encoding($false)))

    # 필수 값 확인
    $chk = Read-Ini $iniOut
    $srv = Get-Val $chk 'ServerUrl'
    if (-not $srv -or $srv -like '*example.com*') { Fail "$id : updater.ini 의 ServerUrl 이 비었거나 예시 주소입니다. solution.ini 의 ServerUrl 을 넣으세요." }
    if ((Get-Val $chk 'App') -ne $id) { Fail "$id : updater.ini 의 App= 값($(Get-Val $chk 'App'))이 솔루션 ID 와 다릅니다." }
    if (-not (Get-Val $chk 'SignKey')) { Write-Host "  경고: SignKey 가 없습니다. 서명 확인 없이 설치됩니다 (관리 사이트 [보안] 의 공개 키를 넣는 것을 권장)." -ForegroundColor Yellow }
    if ($srv -notlike 'https://*') { Write-Host "  경고: ServerUrl 이 https 가 아닙니다: $srv" -ForegroundColor Yellow }

    # WiX 변수 파일
    $regKey = "Software\$companyDir\AutoUpdater\$id"
    $vars = @"
<?xml version="1.0" encoding="utf-8"?>
<Include>
  <?define SolutionId = "$id" ?>
  <?define ProductName = "$productName" ?>
  <?define Manufacturer = "$manufacturer" ?>
  <?define ProductVersion = "$version" ?>
  <?define UpgradeCode = "{$upgrade}" ?>
  <?define CompanyFolder = "$companyDir" ?>
  <?define InstallFolderName = "$folderName" ?>
  <?define ShortcutName = "$shortcut" ?>
  <?define ShortcutDescription = "$shortDesc" ?>
  <?define RegKey = "$regKey" ?>
  <?define Codepage = "949" ?>
  <?define Language = "1042" ?>
  <?define DesktopShortcut = "$desktop" ?>
  <?define UpdaterExe = "$exe" ?>
  <?define UpdaterIni = "$iniOut" ?>
  <?define IconFile = "$iconPath" ?>
  <?define LogoFile = "$logoPath" ?>
</Include>
"@
    $varsFile = Join-Path $obj 'vars.wxi'
    [IO.File]::WriteAllText($varsFile, $vars, $Utf8Bom)

    $wixobj = Join-Path $obj 'Product.wixobj'
    $msi = Join-Path $OutDir "$id-Setup-$version.msi"
    & (Join-Path $wixBin 'candle.exe') -nologo -arch x86 "-dVarsFile=$varsFile" -out $wixobj (Join-Path $Root 'Product.wxs')
    if ($LASTEXITCODE -ne 0) { Fail "$id : candle 실패" }
    # ICE61 경고: 같은 버전 재설치 허용(AllowSameVersionUpgrades) 에 대한 안내라 숨김
    & (Join-Path $wixBin 'light.exe') -nologo -spdb -sice:ICE61 -out $msi $wixobj
    if ($LASTEXITCODE -ne 0) { Fail "$id : light 실패" }

    Write-Host ""
    Write-Host "완료: $msi" -ForegroundColor Green
    Write-Host "  제품명 $productName / 버전 $version / UpgradeCode {$upgrade}"
    Write-Host "  설치 위치 Program Files (x86)\$companyDir\$folderName"
    Write-Host "  SHA-256 $((Get-FileHash -Algorithm SHA256 $msi).Hash)"
}

# ---------------------------------------------------------------- main
if ($InstallWix) { Install-Wix; if (-not $Solution -and -not $All) { exit 0 } }

if ($All) { $Solution = Get-ChildItem $SolDir -Directory | Where-Object { $_.Name -notlike '_*' -and (Test-Path (Join-Path $_.FullName 'solution.ini')) } | ForEach-Object { $_.Name } }
if (-not $Solution) {
    Write-Host "사용법: build-msi.bat <솔루션ID> [<솔루션ID> ...] | -All | -InstallWix"
    Write-Host "솔루션 목록:"; Get-ChildItem $SolDir -Directory | Where-Object { $_.Name -notlike '_*' } | ForEach-Object { Write-Host "  $($_.Name)" }
    exit 1
}

$wixBin = Find-WixBin
Write-Host "WiX: $wixBin"
foreach ($s in $Solution) { Write-Host ""; Write-Host "=== $s ===" -ForegroundColor Cyan; Build-One $s $wixBin }
