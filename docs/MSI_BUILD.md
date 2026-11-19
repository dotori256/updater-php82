# 솔루션별 MSI 설치 파일 만들기

새 PC 에 솔루션을 설치할 때 쓰는 **MSI 설치 파일**을 솔루션마다 자동으로 만듭니다.
WiX 템플릿 1개(`installer/Product.wxs`)에 솔루션별 설정(`installer/solutions/<ID>/solution.ini`)을 넣어 빌드합니다.

MSI 에는 **런처만** 들어갑니다. 실제 프로그램 파일은 처음 실행할 때 Updater.exe 가 서버에서 받아옵니다.
그래서 프로그램을 새로 배포해도 MSI 는 다시 만들 필요가 없습니다.

| MSI 에 들어가는 것 | 설명 |
|---|---|
| `Updater.exe` | 저장소의 `updater/Updater.exe` (솔루션 폴더에 따로 넣으면 그것) |
| `updater.ini` | 서버 주소, 솔루션 ID, 서명 키 |
| `logo.png` | 있으면. 런처 화면 로고 |
| 아이콘 | 바로가기와 제어판 [앱 목록] 아이콘 |
| 바로가기 | 바탕화면 + 시작 메뉴 `<회사>\<이름>` (모든 사용자) |

---

## 1. 어떤 WiX 를 쓰나: WiX Toolset **v3.14.1** (무료)

회사(상업) 용도이므로 **Open Source Maintenance Fee(OSMF) 대상이 아닌 버전**을 씁니다.

| 버전 | 라이선스 | OSMF |
|---|---|---|
| v3.14.1 (2024-04, v3 마지막 보안 패치) | MS-RL | **없음** |
| v4.0.x | MS-RL | 없음 |
| v5.0.x (마지막 v5.0.2) | MS-RL | 없음 |
| **v6.0.0 이상** (v6, v7) | MS-RL + OSMF EULA | **있음**. 수익을 내는 조직은 사용료 필요 |

근거 (2026-11-19 확인):
- `wixtoolset/wix` 저장소의 **v6.0.0 태그부터** `OSMFEULA.txt` 가 들어가고 README 에 "use of the WiX Toolset requires an Open Source Maintenance Fee", "if you use this project to generate revenue, the Open Source Maintenance Fee is required" 가 들어갑니다. v4.0.6, v5.0.2 태그에는 `OSMFEULA.txt` 가 없고 LICENSE 는 MS-RL 뿐입니다.
- v3 는 별도 저장소 `wixtoolset/wix3` 이며 LICENSE 는 MS-RL 뿐입니다. 내려받는 `wix314-binaries.zip` 안의 LICENSE.TXT 도 MS-RL 입니다.
- MS-RL 은 상업적 사용을 허용합니다. WiX 소스 자체를 고쳐 배포할 때만 그 부분의 소스 공개 의무가 있고, WiX 로 **만든 MSI 에는 의무가 없습니다.**

**v3.14.1 을 고른 이유** (v4/v5 대신)
- 압축 파일 하나만 풀면 끝입니다. v4/v5 는 .NET SDK 와 `dotnet tool` 설치가 필요합니다.
- 자료와 예제가 가장 많고, 이 템플릿은 WiX 확장(커스텀 액션)을 쓰지 않아 **MSI 안에 WiX 코드가 전혀 들어가지 않습니다.** WiX 는 빌드 PC 에서만 쓰는 도구입니다.
- 주의: v3, v4 는 더 이상 업데이트되지 않습니다. 빌드 PC 에서만 쓰고 결과 MSI 에 WiX 실행 코드가 없으므로 운영 PC 의 보안 문제는 아닙니다. 나중에 옮기고 싶으면 v5.0.2 가 OSMF 없는 마지막 버전입니다.
- **v6 이상으로 올리지 마세요** (`dotnet tool install wix` 를 버전 없이 실행하면 최신 v6/v7 이 설치되니 주의).

---

## 2. 처음 한 번: 빌드 PC 준비

빌드 PC: Windows 10/11 (PowerShell 5.1 기본 포함, .NET Framework 4.x 기본 포함).

```bat
cd installer
build-msi.bat -InstallWix
```

- `https://github.com/wixtoolset/wix3/releases/download/wix3141rtm/wix314-binaries.zip` 를 받아 **SHA-256 을 확인한 뒤** `installer\tools\wix314\` 에 풉니다.
  SHA-256: `6AC824E1642D6F7277D0ED7EA09411A508F6116BA6FAE0AA5F2C7DAA2FF43D31`
- WiX v3 는 원래 .NET 3.5 용이라, .NET 3.5 가 없는 PC 에서는 실행 시 "기능 설치" 창이 뜹니다. 스크립트가 `candle.exe.config`, `light.exe.config` 에 .NET 4 실행 허용을 넣어 별도 설치 없이 동작하게 합니다.
- 인터넷이 막힌 PC: 위 zip 을 다른 PC 에서 받아 `installer\tools\wix314\` 에 풀고 `build-msi.bat -InstallWix` 를 한 번 실행(설정 패치만 함).
- WiX v3.14 설치 프로그램(`wix314.exe`)으로 이미 설치했다면 그대로 찾아 씁니다 (`WIX` 환경 변수 또는 `Program Files (x86)\WiX Toolset v3.14`).
- `tools/`, `obj/`, `out/` 는 git 에 올라가지 않습니다 (`.gitignore`).

---

## 3. MSI 빌드

```bat
cd installer
build-msi.bat MWAGENT            :: 하나
build-msi.bat MWAGENT OTHERAPP   :: 여러 개
build-msi.bat -All               :: solutions\ 아래 전부 (_TEMPLATE 제외)
```

결과: `installer\out\MWAGENT-Setup-1.0.0.msi`
끝나면 제품명, 버전, UpgradeCode, 설치 위치, **MSI 의 SHA-256** 을 보여줍니다.

> 자동화(CI 등)에서 창이 멈추지 않게 하려면 `set NOPAUSE=1` 후 실행하세요.

빌드가 멈추는 경우 (일부러 막아 둔 것)
- `ServerUrl` 이 비었거나 `example.com` 예시 주소 그대로일 때
- `updater.ini` 의 `App=` 이 솔루션 폴더 이름과 다를 때
- `Manufacturer` 가 없을 때, `Version` 이 MSI 규칙(0~255.0~255.0~65535)에 안 맞을 때
- 이름에 `"` `<` `>` `$` 나 한국어 코드페이지(949)로 못 쓰는 글자가 있을 때

경고만 하는 경우: `SignKey` 가 없을 때(서명 확인 없이 설치됨), 서버 주소가 https 가 아닐 때.

---

## 4. 새 솔루션 추가

1. 관리 사이트에서 솔루션을 먼저 등록합니다 (예: ID `NEWAPP`).
2. `installer\solutions\_TEMPLATE` 폴더를 복사해 **`installer\solutions\NEWAPP`** 으로 이름을 바꿉니다. 폴더 이름 = 솔루션 ID.
3. **관리 사이트 › 솔루션 › [설정 / 설치] › updater.ini 받기** → 받은 파일을 `solutions\NEWAPP\updater.ini` 로 넣습니다. (권장. 서버 주소·SignKey·ClientKey 가 이미 채워져 있음)
   - 넣지 않으면 `solution.ini` 의 `ServerUrl`, `SignKey`, `ClientKey` 로 `updater\updater.ini` 를 바탕으로 만들어집니다.
4. `solution.ini` 에서 `ProductName`(제품명), `Manufacturer`(회사명) 를 고칩니다.
5. 아이콘: `solutions\NEWAPP\icon.ico` (없으면 기본 런처 아이콘). 로고: `logo.png` (선택).
6. `build-msi.bat NEWAPP`
7. 테스트 PC 1대에 설치 → 바로가기로 실행해 프로그램이 받아지는지 확인 → 나머지 PC 에 설치.

`solution.ini` 항목

| 키 | 기본값 | 설명 |
|---|---|---|
| `ProductName` | 솔루션 ID | 제어판 [앱 목록] 이름 |
| `Manufacturer` | (필수) | 게시자. 설치/시작 메뉴 폴더 이름 |
| `Version` | 1.0.0 | **설치 파일** 버전 (프로그램 버전 아님) |
| `CompanyFolder` | Manufacturer | `Program Files (x86)\<여기>\...` |
| `InstallFolderName` | 솔루션 ID | `...\<회사>\<여기>` |
| `ShortcutName` | ProductName | 바로가기 이름 |
| `ShortcutDescription` | "<이름> 실행 (자동 업데이트)" | 바로가기 설명 |
| `DesktopShortcut` | 1 | 0 이면 바탕화면 바로가기 없음 |
| `Icon` / `Logo` | icon.ico / logo.png | 솔루션 폴더 기준 파일 이름 |
| `UpgradeCode` | 자동 | 비우면 솔루션 ID 로 계산 (아래) |
| `ServerUrl`, `SignKey`, `ClientKey` | | 솔루션 폴더에 updater.ini 가 없을 때만 사용 |
| `LaunchExe`, `LaunchArgs`, `AutoStart`, `ShowWindow` | | 〃 (비우면 updater.ini 기본값) |

`solution.ini` 는 **UTF-8** 로 저장하세요 (메모장 기본값이 UTF-8).

---

## 5. 설치되면 PC 에 생기는 것

```
C:\Program Files (x86)\<회사>\<솔루션ID>\      ← bin 폴더 (Users 수정 권한)
    Updater.exe, updater.ini, logo.png
    (첫 실행 후) 프로그램 파일들, .update\
바탕화면\<바로가기 이름>.lnk                    ← 모든 사용자
시작 메뉴\<회사>\<바로가기 이름>.lnk
HKLM\SOFTWARE\WOW6432Node\<회사>\AutoUpdater\<ID>  InstallFolder 등
```

- **설치 폴더 권한**: 런처는 bin 폴더 안의 파일(프로그램, `.update`, 자기 자신)을 직접 교체합니다. Program Files 는 원래 일반 사용자가 쓸 수 없으므로 **이 폴더에만** Users 수정 권한을 줍니다 (SDDL, SID 기반이라 한글 Windows 그룹 이름 문제 없음). 그래서 설치할 때만 관리자 권한이 필요하고, 이후 업데이트는 일반 사용자로 됩니다.
- PC ID 는 지금처럼 `C:\ProgramData\AutoUpdater\pcid.txt` 를 모든 솔루션이 함께 씁니다.
- 설치 후 프로그램이 자동 실행되지는 않습니다. 바로가기로 처음 실행하면 런처가 프로그램을 받습니다.

### 한 PC 에 여러 솔루션
솔루션마다 **설치 폴더, 바로가기, 레지스트리 키, UpgradeCode** 가 모두 다르므로 서로 영향을 주지 않습니다. 제어판 [앱 목록] 에도 따로 보이고 따로 제거됩니다.

### UpgradeCode
- MSI 가 "같은 제품" 인지 판단하는 값입니다. 솔루션 ID 로부터 UUID v5 로 계산하므로 **어느 PC 에서 빌드해도 같은 솔루션은 같은 값**입니다. (예: MWAGENT → `{9AA1B029-AA93-5419-B410-CF370E85A781}`)
- 같은 솔루션의 새 MSI 를 실행하면 기존 설치를 자동으로 교체합니다. 같은 버전으로 다시 만들어도 교체됩니다.
- 한 번 PC 에 배포한 뒤에는 솔루션 ID 폴더 이름, `UpgradeCode`, 스크립트의 네임스페이스 값을 바꾸지 마세요. 바꾸면 다른 제품으로 보고 두 번 설치됩니다.

---

## 6. 설치 / 업그레이드 / 제거

| 하고 싶은 것 | 방법 |
|---|---|
| 일반 설치 | MSI 더블클릭 → 관리자 확인(UAC) → 끝 |
| 조용히 설치 (원격 지원·배치) | `msiexec /i MWAGENT-Setup-1.0.0.msi /qn` |
| 설치 폴더 바꾸기 | `msiexec /i MWAGENT-Setup-1.0.0.msi INSTALLFOLDER="D:\MWAgent\bin\"` |
| 설치 로그 | `msiexec /i ... /l*v install.log` |
| 제거 | 제어판 [앱 목록] 또는 `msiexec /x MWAGENT-Setup-1.0.0.msi /qn` |

- **업그레이드**(서버 주소·아이콘 변경 등으로 MSI 를 다시 만든 경우): 새 MSI 를 실행하면 지난번 설치 폴더를 그대로 쓰고, 받아 둔 프로그램 파일과 `.update` 는 그대로 남습니다. `updater.ini` 는 새 MSI 의 값으로 바뀝니다.
- **런처 자동 교체와의 관계**: 관리 사이트에서 Updater.exe 를 배포하면 PC 의 런처가 스스로 새 버전이 됩니다. MSI 의 Updater.exe 는 처음 설치용이므로, 새 런처를 낸 뒤에는 MSI 도 다시 빌드해 두면 새 PC 가 처음부터 새 런처로 시작합니다.
- **제거**: MSI 가 넣은 파일(Updater.exe, updater.ini, logo.png), 바로가기, 레지스트리만 지웁니다. 런처가 받은 프로그램 파일과 `.update` 는 데이터 보호를 위해 남기므로 완전히 지우려면 설치 폴더를 직접 지우세요.
- **이미 운영 중인 PC**: 기존 bin 폴더는 그대로 두세요. MSI 를 설치하면 새 폴더에 따로 설치됩니다. MSI 는 새 PC 용입니다.

---

## 7. 참고 / 한계

- MSI 에 코드 서명(인증서)이 없으므로 인터넷에서 받은 MSI 를 열면 SmartScreen "알 수 없는 게시자" 경고가 뜰 수 있습니다 ([추가 정보] → [실행]). 원격 지원·USB 로 옮기면 대부분 뜨지 않습니다. 배포된 프로그램 파일은 지금처럼 Manifest 전자서명(SignKey)으로 보호됩니다.
- 패키지는 32비트(x86) MSI 입니다. 32/64비트 Windows 모두 설치되며 `Program Files (x86)` 에 들어갑니다.
- MSI 표시 언어는 한국어(코드페이지 949)입니다.
- 윈도우 시작 시 자동 실행(시작프로그램 등록)은 넣지 않았습니다. 필요하면 템플릿에 HKLM Run 레지스트리 한 줄을 추가할 수 있습니다.
- 검증: 템플릿은 WiX 3.14.1 `candle` 로 컴파일 검증했습니다 (바탕화면 바로가기 유/무, 로고 유/무). `light` 로 MSI 를 만드는 단계와 실제 설치는 Windows 에서만 가능하므로 빌드 PC 에서 처음 한 번 확인이 필요합니다.

### 제안: 관리 사이트에서 MSI 내려받기
지금은 빌드 PC 에서 MSI 를 만들어 직접 옮깁니다. 원하면 다음을 추가할 수 있습니다 (이번 범위 밖).
- [설정 / 설치] 탭에 "설치 파일(MSI) 올리기 / 받기" 칸을 두고 `data/` 밖의 보호된 위치에 솔루션별 MSI 를 보관 (로그인한 관리자만 다운로드, SHA-256 표시)
- MSI 빌드는 Windows 가 필요하므로 서버(NAS)에서 만들지 않고, 빌드 PC 에서 만든 파일을 올리는 방식
