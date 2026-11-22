# 서버 재설치 가이드 (백업 → 재설치 → 복원 → 점검)

NAS 를 초기화하거나 교체할 때, 또는 Web Station 을 다시 설치할 때 이 순서대로 따라 하면 됩니다.
처음 설치하는 방법은 [SYNOLOGY.md](../SYNOLOGY.md) 에 있고, 이 문서는 **이미 운영 중인 서버를 그대로 옮기는 것**에 초점을 맞췄습니다.

기준: Synology DSM 7.2, Web Station + **Apache HTTP Server 2.4** 백엔드, **PHP 8.2** 프로필, 설치 위치 `web/updates`.
우분투 서버로 옮기는 방법은 맨 뒤 [부록 A](#부록-a-우분투-서버로-옮기기) 에 있습니다.

> **가장 중요한 원칙 3가지**
> 1. **`config.php` 는 새 버전 파일로 덮어쓰지 않습니다.** 백업해 둔 운영용 `config.php` 를 그대로 다시 씁니다.
> 2. **접속 주소(도메인·포트·경로)를 바꾸지 않습니다.** 각 PC 의 `updater.ini` 에 `ServerUrl` 이 박혀 있어서, 주소가 바뀌면 모든 PC 의 ini 를 손으로 고쳐야 합니다.
> 3. **서명 키 파일(비밀 키)은 서버 밖에 있습니다.** NAS 를 날려도 키 파일과 키 암호만 있으면 PC 쪽은 아무것도 바꿀 필요가 없습니다.

---

## 목차

0. [한눈에 보는 순서](#0-한눈에-보는-순서)
1. [재설치 전: 기록하고 백업하기](#1-재설치-전-기록하고-백업하기)
2. [재설치: 패키지와 PHP 프로필](#2-재설치-패키지와-php-프로필)
3. [코드 올리기와 데이터 복원](#3-코드-올리기와-데이터-복원)
4. [권한, .htaccess, .user.ini 확인](#4-권한-htaccess-user-ini-확인)
5. [웹 서비스·포털·HTTPS 다시 연결](#5-웹-서비스포털https-다시-연결)
6. [Google OTP 재설정](#6-google-otp-재설정)
7. [배포 전자서명 재설정](#7-배포-전자서명-재설정)
8. [런처(Updater.exe) 접속 확인](#8-런처updaterexe-접속-확인)
9. [최종 점검 체크리스트](#9-최종-점검-체크리스트)
10. [상황별 복구 방법](#10-상황별-복구-방법)
- [부록 A. 우분투 서버로 옮기기](#부록-a-우분투-서버로-옮기기)
- [부록 B. 서버 폴더 구조와 파일 역할](#부록-b-서버-폴더-구조와-파일-역할)

---

## 0. 한눈에 보는 순서

| 단계 | 할 일 | 소요 |
|---|---|---|
| 1 | 설정값 기록, `web/updates` 폴더 통째 백업, 서명 키 파일 위치 확인 | 10분 |
| 2 | Web Station · Apache 2.4 · PHP 8.2 설치, PHP 프로필 생성 | 10분 |
| 3 | 최신 코드 올리기 → 백업한 `config.php`, `data/`, `files/`, `manifests/` 복원 | 10분 |
| 4 | `http` 쓰기 권한, 숨김 파일(.htaccess, .user.ini) 확인 | 5분 |
| 5 | 웹 서비스(Apache) · 포털 · 인증서를 **예전과 같은 주소**로 연결 | 10분 |
| 6 | 로그인 → OTP 가 그대로 되는지 확인 (안 되면 재등록) | 5분 |
| 7 | 보안 메뉴에서 서명 키 지문이 예전과 같은지 확인 (없으면 키 파일로 재등록) | 5분 |
| 8 | 테스트 PC 1대에서 Updater.exe 실행 → PC 현황 확인 → 나머지 PC | 10분 |
| 9 | 체크리스트로 마무리 | 5분 |

재설치 중에는 PC 런처가 서버에 연결하지 못합니다. 런처는 서버에 연결할 수 없으면 설치된 버전으로 그냥 실행하므로(솔루션 설정의 "오프라인 실행" 허용 시) 업무는 멈추지 않지만, **가능하면 업무 시간 외에 작업**하세요.

---

## 1. 재설치 전: 기록하고 백업하기

### 1-1. 미리 적어둘 것 (메모장에)

관리 사이트와 NAS 에서 아래 값을 확인해서 적어 둡니다. 복원 후 같은지 비교하는 기준이 됩니다.

| 항목 | 어디서 확인 | 왜 필요한가 |
|---|---|---|
| **접속 주소** (예: `https://updates.회사.synology.me/` 또는 `http://192.168.0.10:8088/`) | 브라우저 주소창, PC 의 `updater.ini` `ServerUrl` | 새 서버도 **똑같은 주소**로 만들어야 PC 를 안 건드림 |
| 웹 포털 방식과 포트 (포트 기반 8088/8443, 이름 기반 80/443) | Web Station › 웹 포털 | 같은 포트로 다시 만들기 |
| 문서 루트 (`web/updates`) | Web Station › 웹 서비스 | 같은 경로로 |
| PHP 프로필 이름과 확장 목록 | Web Station › 스크립트 언어 설정 | 같게 다시 만들기 |
| **서명 키 지문** (예: `A1B2:C3D4:E5F6:7890`) | 관리 사이트 › **보안** › 배포 전자서명 › "등록된 키" | 복원 후 같은 키인지 확인 |
| `SignKey=` 값 한 줄 | 보안 메뉴의 회색 박스 | 비상시 PC ini 와 대조 |
| 솔루션 목록과 각 솔루션의 **현재 버전·빌드 번호** | 대시보드, 각 솔루션 배포 탭 | 복원이 최신 상태인지 확인 (→ [10-4](#10-4-오래된-백업으로-복원한-경우-빌드-번호-문제)) |
| 각 PC 의 설치 빌드 | 솔루션 › **PC 현황** | 위와 같음 |
| 공유기 포트포워딩, DDNS, 방화벽 규칙 | 공유기 관리 화면, 제어판 › 외부 액세스 / 보안 | NAS 초기화 시 다시 설정 |
| DB 종류 | `config.php` 의 `DB_DSN` (기본 SQLite) | MySQL 이면 덤프 따로 필요 |

> `ADMIN_PASSWORD`, `CLIENT_KEY` 는 `config.php` 안에 있으므로 `config.php` 를 백업하면 같이 보관됩니다. 혹시 `config.php` 를 잃어버리면 `CLIENT_KEY` 는 아무 PC 의 `updater.ini` 의 `ClientKey=` 줄에서 다시 찾을 수 있습니다.

### 1-2. 서버에서 백업할 것

**가장 쉬운 방법: `web/updates` 폴더 전체를 백업**하세요. 아래 표는 그 안에서 꼭 살아 있어야 하는 것들입니다.

| 경로 | 내용 | 없어지면 |
|---|---|---|
| `config.php` | 관리자 비밀번호, `OTP_MODE`, `CLIENT_KEY`, `DB_DSN`, `TIMEZONE` 등 | 설정을 처음부터 다시. `CLIENT_KEY` 가 바뀌면 PC 보고가 거부됨 |
| `data/apps/<솔루션ID>/state.json` | 솔루션 설정, 현재 파일 목록, **빌드 번호**, 현재 버전의 서명 | 솔루션이 관리 사이트에서 사라짐 |
| `data/apps/<솔루션ID>/history.json` | 배포 이력 (롤백 가능 범위, 최근 50개) | 롤백 불가 |
| `data/signkey.json` | 배포 전자서명 **공개 키** | 키 파일로 재등록 필요 (→ 7장) |
| `data/otp.json` | Google OTP 시크릿 | OTP 재등록 필요 (→ 6장) |
| `data/updater.db` | PC 현황, 업데이트 이력 (SQLite) | PC 목록이 비어 보임. PC 가 다음에 접속하면 다시 채워짐 |
| `data/audit.log`, `data/audit.log.1` | 감사 로그 (로그인, 배포, 키 변경 기록) | 기록만 사라짐 |
| `files/` | 배포 파일 본문 (SHA-256 이름) | **PC 가 업데이트 파일을 받지 못함** |
| `manifests/<솔루션ID>.json` | PC 가 읽는 공개 Manifest (서명 포함) | PC 가 "서버에 연결할 수 없음/등록되지 않은 솔루션" |
| `tools/Updater.exe` | [기존 PC 런처 교체] 에 쓰는 런처 | 새 코드에 들어 있는 것으로 대체됨 (직접 바꿔 넣었다면 백업) |

백업하지 않아도 되는 것: `data/login_guard.json` (로그인 잠금 기록), `data/.lock` (잠금용 빈 파일). 있어도 문제없습니다.

> **`data/otp.json`, `data/signkey.json`, `config.php` 는 보안상 민감한 파일입니다.** 백업본을 메일·메신저로 보내거나 공개된 공유 폴더에 두지 마세요.

**백업 방법 (아래 중 하나)**

- **Hyper Backup** (권장): 백업 작업에 `web/updates` 폴더를 넣고 지금 백업 실행. 대상은 USB 외장 디스크나 다른 NAS.
- **File Station 내려받기**: `web/updates` 폴더를 우클릭 › **다운로드** → zip 으로 PC 에 저장.
  - 내려받기 전에 File Station 우측 상단 **설정 › 숨김 파일 표시** 를 켜고 `.htaccess`, `.user.ini` 가 보이는지 확인하세요.
- **MySQL 을 쓰는 경우** (`DB_DSN` 이 `mysql:` 로 시작): 폴더 백업과 별도로 DB 를 덤프합니다.
  phpMyAdmin › `updater` DB › 내보내기, 또는 SSH 로 `mysqldump -u updater -p updater > updater.sql`

**백업이 제대로 됐는지 확인**: 백업본(또는 zip)을 열어 `config.php`, `data/apps/` 안의 솔루션 폴더들, `data/signkey.json`, `files/` 안의 파일 개수, `manifests/*.json` 이 있는지 눈으로 봅니다.

### 1-3. 서버 밖에 있어야 하는 것 (지금 확인)

NAS 를 지우기 **전에** 아래가 손에 있는지 확인하세요. 이것들은 백업 폴더에 들어 있지 않습니다.

| 항목 | 확인 방법 |
|---|---|
| **서명 키 파일** `sign-key-XXXXXXXX.json` (비밀 키, 암호로 잠김) | USB/다른 PC 에 있는지. 파일 이름의 8자리가 1-1 에 적은 지문 앞부분과 같은지 |
| **서명 키 암호** | 기억하거나 안전한 곳에 적어 둔 것 |
| 휴대폰의 Google Authenticator 계정 | 앱에 이 사이트 항목이 있는지 |
| 관리자 비밀번호 | `config.php` 에도 있지만 기억해 두기 |
| 최신 서버 코드 | 저장소 `dotori256/updater-php82` main, 또는 `auto-updater.zip` |

> 서명 키 파일을 잃어버린 상태라면 **재설치를 미루고** 먼저 [10-3](#10-3-서명-키-파일비밀-키을-잃어버린-경우) 의 키 교체를 지금 서버에서 끝내세요. 서버가 살아 있을 때 교체하는 편이 훨씬 쉽습니다.

---

## 2. 재설치: 패키지와 PHP 프로필

NAS 를 초기화했거나 새 NAS 라면 DSM 기본 설정(관리자 계정, 네트워크, **NTP 시간 동기화**)부터 끝냅니다.
**제어판 › 지역 옵션 › 시간 › NTP 서버와 동기화** 를 반드시 켜세요. 시계가 1분 이상 틀리면 OTP 코드가 맞지 않습니다.

### 2-1. 패키지 설치

**패키지 센터** 에서 설치:

| 패키지 | 비고 |
|---|---|
| Web Station | |
| PHP 8.2 | |
| **Apache HTTP Server 2.4** | 꼭 필요. Nginx 는 `.htaccess` 를 무시해서 `data/` 의 DB·OTP·키 파일이 외부에 노출됩니다 |
| (MySQL 을 쓰던 경우) MariaDB 10 | |
| (선택) 텍스트 편집기 | `config.php` 를 NAS 에서 고칠 때 |

### 2-2. PHP 프로필 만들기

**Web Station › 스크립트 언어 설정 › PHP › 생성** — 예전 프로필과 같게 만듭니다.

- 프로필 이름: `updater-php82`
- PHP 버전: **PHP 8.2**
- **확장**: `pdo_sqlite`, `sqlite3` (필수), **`openssl` (전자서명에 필수)**, `curl`, `mbstring`, `zip`. MySQL 이면 `pdo_mysql` 도.
- **코어**:

| 항목 | 값 |
|---|---|
| `upload_max_filesize` | `512M` |
| `post_max_size` | `520M` |
| `max_execution_time` | `300` |
| `max_input_time` | `300` |
| `memory_limit` | `256M` |

> `openssl` 을 빠뜨리면 서명 검증이 안 돼서 **서명을 쓰는 서버에서는 배포·롤백이 모두 막힙니다.** 꼭 체크하세요.

---

## 3. 코드 올리기와 데이터 복원

순서가 중요합니다. **먼저 새 코드를 놓고, 그 위에 운영 데이터를 덮습니다.**

### 3-1. 최신 코드 올리기

1. File Station 에서 `web` 공유 폴더를 엽니다.
2. 최신 `auto-updater.zip` (또는 저장소 main 을 zip 으로 받은 것) 을 업로드 → 우클릭 › **압축 풀기 › 여기에 풀기**.
   - 압축 풀기를 써야 `.htaccess`, `.user.ini` 숨김 파일이 빠지지 않습니다.
3. 풀린 `server` 폴더를 `web/updates` 로 옮깁니다. (이름이 예전과 같아야 합니다)

### 3-2. 운영 데이터 복원

백업본에서 아래만 `web/updates` 안으로 복사합니다. **같은 이름이 있으면 백업본으로 덮어씁니다.**

| 복원할 것 | 비고 |
|---|---|
| `config.php` | 새 코드에 들어 있는 기본 `config.php`(`change-me`) 를 **백업본으로 교체** |
| `data/` 안의 내용 전체 | `apps/`, `signkey.json`, `otp.json`, `updater.db`, `audit.log*` |
| `files/` 안의 내용 전체 | 파일 수가 많을 수 있음. 복사가 끝까지 됐는지 개수 확인 |
| `manifests/` 안의 `*.json` 전체 | |
| (바꿔 넣었던 경우) `tools/Updater.exe` | |

- 각 폴더의 `.htaccess`, `index.html` 은 새 코드의 것을 그대로 두세요. (내용은 같습니다)
- **Hyper Backup 으로 복원**한다면 `web/updates` 전체를 원래 위치로 복원한 뒤, 새 코드 파일(`*.php`, `views/`, `api/`)만 위에 덮어써도 됩니다. 이때도 **`config.php` 는 덮어쓰지 마세요.**

### 3-3. 새 버전 코드로 바꾸면서 config.php 에 새 항목이 생긴 경우

새 코드의 `config.php` 에 예전에 없던 `define(...)` 줄이 생겼다면, 백업한 `config.php` 를 그대로 둔 채 **그 줄만 골라서 추가**합니다. 파일을 통째로 바꾸지 마세요.
(현재 버전 기준 항목: `ADMIN_PASSWORD`, `OTP_MODE`, `SITE_TITLE`, `DB_DSN`, `DB_USER`, `DB_PASS`, `CLIENT_KEY`, `TIMEZONE`, `INACTIVE_DAYS`)

### 3-4. MySQL 을 쓰던 경우

1. MariaDB 10 에서 DB `updater` 와 계정 `updater` 를 예전과 같은 이름·비밀번호로 만듭니다.
2. phpMyAdmin › 가져오기, 또는 `mysql -u updater -p updater < updater.sql` 로 덤프를 넣습니다.
3. `config.php` 의 `DB_DSN`, `DB_USER`, `DB_PASS` 가 맞는지 확인합니다.

### 3-5. 복원 후 구조

```
web/updates/
├─ .htaccess   .user.ini
├─ index.php   api.php   lib.php   config.php(복원본)   launcher_api.php   otp.php   sign.php
├─ api/v1/launcher/   check.php  report.php
├─ views/        (.htaccess 포함)
├─ tools/        Updater.exe
├─ files/        .htaccess  index.html  + 64자리 이름의 파일들(복원)
├─ manifests/    .htaccess  index.html  + 솔루션ID.json (복원)
└─ data/         .htaccess  index.html  + apps/  signkey.json  otp.json  updater.db  audit.log (복원)
```

---

## 4. 권한, .htaccess, .user.ini 확인

### 4-1. 쓰기 권한

웹서버는 `http` 계정으로 실행됩니다. 복원한 파일은 내 계정 소유로 들어가므로 **복원이 끝난 뒤에** 권한을 다시 줍니다.

1. File Station › `web/updates` 우클릭 › **속성 › 권한 › 생성**
2. 사용자 또는 그룹: **`http`**, 유형: 허용, 권한: **읽기 + 쓰기**
3. **"이 폴더, 하위 폴더 및 파일에 적용"** 체크 → 확인

최소 권한으로 하려면 `data`, `files`, `manifests` 세 폴더에만 쓰기를 주고 나머지는 읽기만 줍니다.

> 권한을 준 **다음에** 복원 파일을 추가로 복사했다면 다시 한 번 "하위 폴더 및 파일에 적용" 으로 권한을 주세요. `state.json` 이나 `otp.json` 이 쓰기 불가면 배포·OTP 등록이 저장되지 않습니다.

### 4-2. 숨김 파일이 다 있는지

File Station **설정 › 숨김 파일 표시** 를 켜고 아래 파일이 있는지 봅니다.

| 파일 | 하는 일 |
|---|---|
| `updates/.htaccess` | 목록 보기 차단, `config.php` · `lib.php` · `otp.php` · `launcher_api.php` · `.user.ini` 직접 접근 차단, (mod_php 일 때) 업로드 한도 |
| `updates/.user.ini` | PHP-FPM 방식에서 업로드 한도 (Web Station 은 PHP 프로필 값이 우선) |
| `data/.htaccess` | **data 폴더 전체 외부 접근 차단** (DB, OTP, 키, 로그 보호) |
| `views/.htaccess` | 화면 템플릿 직접 접근 차단 |
| `files/.htaccess` | 업로드 파일이 서버에서 실행되지 않게 (PHP 끄기, 다운로드 전용) |
| `manifests/.htaccess` | PHP 실행 끄기, 캐시 금지 (PC 가 항상 최신 Manifest 를 받도록) |

하나라도 없으면 새 zip 에서 다시 꺼내 넣으세요. PC 에서 폴더를 끌어다 놓으면 이 파일들이 잘 빠집니다.

---

## 5. 웹 서비스·포털·HTTPS 다시 연결

**1-1 에 적은 주소와 똑같이** 만듭니다.

### 5-1. 웹 서비스

**Web Station › 웹 서비스 › 생성 › 기본 스크립트 언어 웹 사이트**
- 이름: `updater`
- 문서 루트: **`web/updates`**
- HTTP 백엔드 서버: **Apache HTTP Server 2.4**
- PHP: **`updater-php82`**

### 5-2. 웹 포털

- 포트 기반이었다면 **같은 포트** (예: HTTP `8088`, HTTPS `8443`)
- 이름 기반이었다면 **같은 호스트 이름** (예: `updates.회사.synology.me`), 포트 80/443
- 서비스: `updater`
- 기본 서버 방식(`http://NAS주소/updates/`) 이었다면 웹 포털 › 기본 서버 › 편집 에서 백엔드를 Apache 2.4, PHP 를 `updater-php82` 로

### 5-3. 외부 접속과 인증서 (외부 PC 가 있을 때)

NAS 를 초기화했다면 아래도 다시 해야 합니다.

1. **DDNS**: 제어판 › 외부 액세스 › DDNS 에서 **예전과 같은 호스트 이름** 등록 (Synology 계정으로 로그인하면 같은 이름을 다시 쓸 수 있습니다)
2. **인증서**: 제어판 › 보안 › 인증서 › 추가 › Let's Encrypt → 같은 도메인으로 발급
3. 인증서 › **설정** 에서 이 웹 포털에 인증서 지정
4. **방화벽**: 제어판 › 보안 › 방화벽에 사용하는 포트 허용
5. **공유기 포트포워딩**: NAS 내부 IP 가 바뀌었다면 포워딩 대상을 새 IP 로 (DHCP 고정 할당 권장)

> **HTTPS 는 전자서명을 쓰려면 필수입니다.** 브라우저가 http 접속에서는 서명 기능(키 만들기·서명)을 막습니다. 사내망 전용이라도 서명을 쓴다면 HTTPS 로 접속하세요.

---

## 6. Google OTP 재설정

브라우저로 접속해서 로그인합니다.

### 6-1. `data/otp.json` 을 복원한 경우 (일반적인 경우)

- 비밀번호 + 휴대폰 앱의 OTP 6자리로 **그대로 로그인됩니다.** 따로 할 일이 없습니다.
- 코드가 계속 틀린다면 **NAS 시계**를 확인하세요. 보안 메뉴에 "NAS 현재 시각" 이 나옵니다. 제어판 › 지역 옵션 › 시간 › NTP 동기화.
- 같은 IP 에서 5번 틀리면 10분 동안 잠깁니다. 기다렸다가 다시 하세요.

### 6-2. `otp.json` 이 없거나 새로 등록하고 싶은 경우

1. `config.php` 의 `OTP_MODE` 가 `'password+otp'` 인지 확인 (등록 전에는 비밀번호만으로 로그인됩니다)
2. 비밀번호로 로그인 → 상단 **보안**
3. 휴대폰 Google Authenticator 앱에서 **예전 항목을 지우고**, **+ › QR 코드 스캔** 으로 새 QR 을 찍습니다
4. 앱의 6자리 입력 → **[등록]**
5. 로그아웃 후 비밀번호 + OTP 로 다시 로그인해 확인

> `OTP_MODE` 를 `'otp'` (OTP 만) 로 쓰고 있었다면, `otp.json` 이 없을 때 잠기지 않도록 **등록이 끝날 때까지 잠시 `'password+otp'` 로 두었다가** 등록 후 되돌리세요.

---

## 7. 배포 전자서명 재설정

상단 **보안 › 배포 전자서명** 을 엽니다.

### 7-1. `data/signkey.json` 을 복원한 경우

1. "사용 중" 표시와 **등록된 키 지문이 1-1 에 적어 둔 값과 같은지** 확인합니다.
2. 아래 `SignKey=` 값도 적어 둔 값과 같은지 봅니다.
3. "⚠ 현재 버전에 서명이 없는 솔루션" 경고가 **없으면** 끝입니다.
   - 경고가 있으면 해당 솔루션의 **배포 탭 › [지금 서명]** 을 누르고 키 파일 + 키 암호로 서명합니다.
4. 서명 확인을 위해 아무 솔루션에서 테스트 배포를 한 번 해 봐도 좋습니다. 키 파일을 고르고 키 암호를 넣었을 때 정상 반영되면 됩니다.

### 7-2. `signkey.json` 이 없어졌지만 키 파일은 있는 경우

PC 쪽은 아무것도 바꿀 필요가 없습니다. **같은 키를 다시 등록**만 하면 됩니다.

1. 보안 › **[가지고 있는 키 파일로 등록]** → 보관 중인 `sign-key-XXXXXXXX.json` 선택 → 키 암호 입력
2. 화면에 나온 **지문이 예전 값과 같은지 확인** 후 등록 (OTP 재확인 코드를 묻습니다)
3. 서명이 없는 솔루션 경고가 나오면 각 솔루션에서 **[지금 서명]**

> **절대 [서명 키 만들기]로 새 키를 만들지 마세요.** 새 키로 서명한 배포는 기존 PC 들이 모두 거부합니다(PC 에는 옛 공개 키가 있음). 새 키가 꼭 필요하면 [10-3](#10-3-서명-키-파일비밀-키을-잃어버린-경우) 절차를 따르세요.

### 7-3. 서명을 쓰지 않던 서버

"사용 안 함" 이 맞습니다. 이 경우 배포·롤백 때 OTP 재확인을 묻습니다.
나중에 서명을 켜려면 [서명 키 만들기] → 보안 › **기존 PC 런처 교체 › [새 런처 배포]** 로 PC 에 공개 키(`updater.signkey`)를 보냅니다.

### 참고: 서명이 PC 에서 확인되는 방식

- 서버는 공개 키(`data/signkey.json`)만 갖고, 배포할 때 브라우저가 키 파일로 서명합니다. 비밀 키는 서버로 가지 않습니다.
- 각 PC 의 Updater.exe 는 `updater.ini` 의 `SignKey=` 와 bin 폴더의 `updater.signkey` 파일에 있는 공개 키로 `manifests/<ID>.json` 의 서명을 확인합니다. 맞지 않으면 설치하지 않습니다.
- 그래서 `manifests/` 와 `data/apps/*/state.json` 을 함께 복원해야 서명이 살아 있는 Manifest 가 됩니다.

---

## 8. 런처(Updater.exe) 접속 확인

### 8-1. 브라우저로 먼저 확인

PC 브라우저에서 (1-1 에 적은 주소 기준):

| 주소 | 기대 결과 |
|---|---|
| `접속주소/manifests/MWAGENT.json` | JSON 이 보임. `build` 가 1-1 에 적은 빌드와 같고, 서명 사용 시 `"signed"`, `"signature"` 가 있음 |
| `접속주소/data/index.html` | **403 또는 404** (열리면 안 됨!) |
| `접속주소/data/updater.db` | **403 또는 404** |
| `접속주소/config.php` | **403** (빈 화면이나 내용이 보이면 안 됨) |
| `접속주소/files/` | 403 (목록이 보이면 안 됨) |

`data/` 가 열린다면 **즉시** 웹 서비스의 HTTP 백엔드가 Apache 2.4 인지, `data/.htaccess` 가 있는지 확인하세요.

### 8-2. 테스트 PC 1대 → 전체

PC 가 적으므로 **테스트 PC 1대로 먼저 확인한 뒤** 나머지로 넓힙니다.

1. 테스트 PC 에서 바탕화면 바로가기(Updater.exe) 를 실행합니다.
2. "최신 버전입니다" 또는 업데이트 진행 후 프로그램이 정상 실행되는지 봅니다.
3. 관리 사이트 › 솔루션 › **PC 현황** 에 그 PC 가 방금 시각으로 **최신** 으로 나오는지 확인합니다.
4. 문제가 없으면 나머지 PC 는 평소처럼 쓰면서 다음 실행 때 자동으로 확인됩니다. PC 현황에서 모두 접속했는지 하루 정도 지켜봅니다.

### 8-3. 런처 오류별 원인

| 런처 메시지 | 원인 | 해결 |
|---|---|---|
| 서버에 연결할 수 없습니다 | 주소·포트·인증서가 예전과 다름, 방화벽/포트포워딩 | 8-1 의 Manifest 주소가 PC 에서 열리는지. 5장 다시 확인 |
| 인증 실패 (ClientKey 확인) | 복원한 `config.php` 의 `CLIENT_KEY` 와 PC 의 `ClientKey` 가 다름 | PC 의 `updater.ini` 값으로 `CLIENT_KEY` 를 맞추기 (PC 를 바꾸지 말고 서버를 맞춤) |
| 등록되지 않은 솔루션 | `data/apps/<ID>` 또는 `manifests/<ID>.json` 복원 누락 | 3-2 다시 |
| 서명이 맞지 않음 / 서명 없음 | `signkey.json` 이 다른 키이거나, 현재 버전 서명이 없음 | 7장. 지문 확인 후 [지금 서명] |
| 서버 버전(빌드 N)이 설치된 버전보다 낮아 설치하지 않습니다 | 오래된 백업으로 복원 | [10-4](#10-4-오래된-백업으로-복원한-경우-빌드-번호-문제) |
| 파일 다운로드 실패 / 해시 불일치 | `files/` 복원 누락 또는 복사 중단 | `files/` 를 다시 복사. 없으면 해당 버전을 다시 배포 |

런처 로그는 각 PC 의 `bin\.update\updater.log` 에 있습니다.

---

## 9. 최종 점검 체크리스트

관리 사이트 상단 **설치 점검** (`?page=check`) 에서 모든 항목이 **정상** 인지 먼저 확인한 뒤, 아래를 하나씩 체크하세요.

**서버 환경**
- [ ] 설치 점검: PHP 8.2, PDO, pdo_sqlite(또는 pdo_mysql), DB 연결 정상
- [ ] 설치 점검: **openssl 확장 (전자서명)** 정상
- [ ] 설치 점검: **HTTPS 접속** 정상 (서명 사용 시 필수)
- [ ] 설치 점검: files / manifests / data 쓰기 권한 정상
- [ ] 설치 점검: 업로드 한도 512M, 실행 시간 300초
- [ ] 설치 점검: **data/ 외부 접근 차단** 정상
- [ ] 설치 점검: 시간대 `Asia/Seoul`, 현재 시각이 맞음
- [ ] 웹서버 항목에 Apache 가 표시됨

**보안**
- [ ] `config.php` 가 복원본이다 (`ADMIN_PASSWORD` 가 `change-me` 가 아님)
- [ ] 브라우저에서 `data/index.html`, `config.php` 가 403/404
- [ ] 비밀번호 + OTP 로 로그인됨
- [ ] 보안 메뉴의 서명 키 지문이 예전과 같음
- [ ] "서명이 없는 솔루션" 경고 없음
- [ ] 감사 로그(보안 메뉴 또는 `data/audit.log`)에 예전 기록 + 오늘 로그인이 보임

**데이터**
- [ ] 대시보드에 모든 솔루션이 있음
- [ ] 각 솔루션의 현재 버전·빌드가 1-1 기록과 같음
- [ ] 배포 탭의 이력이 보이고, 롤백 목록이 있음
- [ ] PC 현황에 예전 PC 들이 보임

**PC**
- [ ] `접속주소/manifests/<ID>.json` 이 PC 에서 열림
- [ ] 테스트 PC 에서 Updater.exe 정상 → PC 현황에 "최신"
- [ ] (하루 뒤) 나머지 PC 도 PC 현황에서 최근 접속으로 표시

**백업 재개**
- [ ] Hyper Backup 작업에 `web/updates` 가 다시 들어 있고 일정이 켜져 있음
- [ ] 서명 키 파일이 두 곳 이상에 보관되어 있음 (NAS 에는 두지 않음)

---

## 10. 상황별 복구 방법

### 10-1. `config.php` 를 잃어버린 경우

1. 새 코드의 `config.php` 를 열어 `ADMIN_PASSWORD` 를 새로 정합니다.
2. `CLIENT_KEY` 는 아무 PC 의 `bin\updater.ini` 에서 `ClientKey=` 값을 그대로 가져옵니다. (ini 에 그 줄이 없으면 비워 둠)
3. `OTP_MODE`, `TIMEZONE`, `DB_DSN` 은 예전 값으로 (모르면 기본값).

### 10-2. `data/otp.json` 이 없거나 휴대폰을 잃어버린 경우

`data/otp.json` 을 삭제(또는 없는 상태)하면 비밀번호만으로 로그인됩니다. 6-2 절차로 다시 등록하세요.

### 10-3. 서명 키 파일(비밀 키)을 잃어버린 경우

비밀 키는 서버에 없으므로 복구할 수 없습니다. 새 키로 바꿔야 하고, **PC 들은 옛 키만 믿기 때문에 PC 쪽 공개 키도 바꿔야 합니다.**

**A. 서버가 아직 살아 있고 키 암호·키 파일 중 하나라도 쓸 수 있는 동안** (권장)
보안 메뉴의 키 교체 순서를 따르면 PC 를 건드리지 않고 바꿀 수 있습니다.
1. [새 키로 교체] → 새 키 파일 받기. 화면에 `SignKey=옛키,새키` 한 줄이 나옴
2. 그 한 줄만 담은 `updater.signkey` 파일을 각 솔루션 bin 바로 아래로 **지금(옛) 키로 서명해** 배포
3. PC 들이 받은 것을 PC 현황에서 확인한 뒤 [다른 키 파일로 교체] 로 새 키 등록

**B. 옛 키를 전혀 쓸 수 없는 경우** (PC 직접 작업 필요)
1. 보안 › [전자서명 해제] (OTP 재확인) → [서명 키 만들기] 로 새 키 생성, 새 키 파일 두 곳 이상 백업
2. 화면의 새 `SignKey=` 값을 복사
3. **각 PC** 의 bin 폴더에서
   - `updater.signkey` 파일이 있으면 그 안의 `SignKey=` 를 새 값으로 바꾸고
   - `updater.ini` 에 `SignKey=` 줄이 있으면 그것도 새 값으로 바꿉니다
4. 각 솔루션 배포 탭에서 [지금 서명] → 테스트 PC 에서 Updater.exe 실행해 확인

### 10-4. 오래된 백업으로 복원한 경우 (빌드 번호 문제)

런처는 **설치된 빌드보다 낮은 빌드는 설치하지 않습니다** (옛 버전 되돌리기 공격 차단). 예를 들어 PC 는 빌드 12 인데 백업의 `state.json` 이 빌드 9 라면, 그 PC 는 서버 버전을 거부합니다.

1. 각 솔루션 **PC 현황** 에서 PC 들의 가장 높은 빌드를 확인합니다.
2. 서버의 현재 빌드가 그보다 낮다면, **최신 파일을 다시 배포**합니다. 배포할 때마다 빌드가 1씩 오르므로, PC 의 최고 빌드보다 높아질 때까지 배포합니다. (설정만 바꾸는 저장은 빌드가 오르지 않습니다)
3. 마지막 배포의 내용은 PC 에 깔린 최신 파일과 같게 맞추세요.

### 10-5. `files/` 일부가 빠진 경우

Manifest 는 있는데 파일 본문이 없으면 그 파일이 필요한 PC 만 다운로드에 실패합니다(설치된 버전은 그대로 유지됨).
`files/` 를 백업에서 다시 복사하거나, 없으면 해당 솔루션에 **같은 파일을 다시 배포**하세요. 롤백 목록 중 파일이 없는 버전은 "이 버전의 파일 일부가 서버에 없어 되돌릴 수 없습니다" 로 나옵니다.

### 10-6. `manifests/` 만 빠진 경우

`data/apps/` 가 있다면 해당 솔루션을 한 번 다시 배포(또는 [지금 서명])하면 Manifest 가 다시 만들어집니다. 하지만 백업에서 복원하는 것이 가장 간단합니다.

### 10-7. `data/updater.db` 만 빠진 경우

처음 접속할 때 테이블이 자동으로 다시 만들어집니다. PC 현황은 각 PC 가 다음에 Updater.exe 를 실행할 때 다시 채워집니다. 예전 업데이트 이력만 사라집니다.

---

## 부록 A. 우분투 서버로 옮기기

Ubuntu 22.04 / 24.04 LTS, Apache 2.4 + PHP 8.2 기준입니다. 순서는 본문과 같습니다: **기록·백업 → 설치 → 코드 → 데이터 복원 → 권한 → 주소/HTTPS → OTP·서명 확인 → 런처 확인**.

### A-1. 패키지 설치

Ubuntu 기본 저장소의 PHP 는 22.04 = 8.1, 24.04 = 8.3 입니다. 둘 다 동작하지만 NAS 와 같은 8.2 로 맞추려면 PPA 를 씁니다.

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y apache2 libapache2-mod-php8.2 \
  php8.2 php8.2-sqlite3 php8.2-mbstring php8.2-curl php8.2-zip
# MySQL/MariaDB 를 쓰는 경우
# sudo apt install -y mariadb-server php8.2-mysql
```

`openssl`, `pdo` 는 PHP 에 기본으로 들어 있습니다. 확인:

```bash
php8.2 -m | grep -Ei 'pdo_sqlite|sqlite3|openssl|mbstring'
```

시간 동기화 (OTP 에 필수):

```bash
sudo timedatectl set-timezone Asia/Seoul
sudo timedatectl set-ntp true
timedatectl   # "System clock synchronized: yes" 확인
```

### A-2. 코드와 데이터 배치

```bash
sudo mkdir -p /var/www/updates
# 새 코드의 server/ 내용을 복사 (숨김 파일 포함)
sudo cp -a server/. /var/www/updates/
# 백업본 복원 (config.php 는 백업본으로, 나머지는 데이터 폴더만)
sudo cp -a 백업/updates/config.php   /var/www/updates/config.php
sudo cp -a 백업/updates/data/.       /var/www/updates/data/
sudo cp -a 백업/updates/files/.      /var/www/updates/files/
sudo cp -a 백업/updates/manifests/.  /var/www/updates/manifests/
ls -la /var/www/updates/.htaccess /var/www/updates/data/.htaccess   # 숨김 파일 확인
```

### A-3. 권한

```bash
sudo chown -R root:www-data /var/www/updates
sudo find /var/www/updates -type d -exec chmod 750 {} \;
sudo find /var/www/updates -type f -exec chmod 640 {} \;
# 쓰기가 필요한 세 폴더만 www-data 소유
sudo chown -R www-data:www-data /var/www/updates/{data,files,manifests}
sudo chmod -R u+rwX /var/www/updates/{data,files,manifests}
```

### A-4. Apache 설정 (`.htaccess` 를 반드시 켜야 함)

`/etc/apache2/sites-available/updates.conf`:

```apache
<VirtualHost *:80>
    ServerName updates.회사도메인.com
    DocumentRoot /var/www/updates

    <Directory /var/www/updates>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/updates_error.log
    CustomLog ${APACHE_LOG_DIR}/updates_access.log combined
</VirtualHost>
```

```bash
sudo a2enmod php8.2 headers
sudo a2ensite updates
sudo a2dissite 000-default     # 이 서버 전용이면
sudo apachectl configtest && sudo systemctl reload apache2
```

- **`AllowOverride All` 이 없으면 `.htaccess` 가 무시되어 `data/` 가 노출됩니다.** 반드시 넣으세요.
- `mod_php` 방식이므로 업로드 한도는 `updates/.htaccess` 의 `<IfModule mod_php.c>` 값(512M/300초)이 적용됩니다. PHP-FPM 을 쓴다면 `.user.ini` 가 적용됩니다. 확실히 하려면 `/etc/php/8.2/apache2/php.ini` 에도 같은 값을 넣으세요.
- NAS 에서 `http://IP:8088/` 처럼 포트 기반으로 쓰던 주소를 유지하려면 `/etc/apache2/ports.conf` 에 `Listen 8088` 을 추가하고 `<VirtualHost *:8088>` 로 만듭니다.
- **경로 기반**(`https://도메인/updates/`) 이었다면 `DocumentRoot` 를 `/var/www` 로 두고 `/var/www/updates` 에 설치하면 같은 주소가 됩니다.

### A-5. HTTPS (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d updates.회사도메인.com
sudo ufw allow 'Apache Full'   # ufw 를 쓰는 경우
```

`certbot` 이 443 가상호스트와 자동 갱신을 만들어 줍니다. 443 쪽 VirtualHost 에도 `AllowOverride All` 이 있는지 확인하세요 (`/etc/apache2/sites-available/updates-le-ssl.conf`).

### A-6. 주소가 바뀌는 경우 (NAS → 우분투)

가능하면 **DNS 만 새 서버로 돌려서 같은 주소를 유지**하세요. `회사.synology.me` 같은 Synology DDNS 주소는 우분투에서 쓸 수 없으므로, 나중에 옮길 계획이 있다면 **지금부터 자체 도메인**(예: `updates.회사.com`)을 쓰는 것이 좋습니다.

주소를 꼭 바꿔야 한다면:
1. **권장: 각 PC 의 `bin\updater.ini` 에서 `ServerUrl=` 을 직접 새 주소로 바꿉니다.** PC 가 5대 이하라면 원격 지원으로 처리하는 편이 빠르고 안전합니다.
2. 옛 서버가 살아 있는 동안 `ServerUrl` 을 바꾼 `updater.ini` 를 솔루션 파일로 배포하는 방법도 기술적으로는 됩니다. 하지만 **모든 PC 의 ini 가 그 파일 하나로 덮어써지고**(PC 마다 다른 `LaunchExe`, `ShowWindow`, `SignKey` 줄 등이 사라짐), 새 주소가 잘못되면 모든 PC 가 한꺼번에 서버를 잃습니다. 꼭 쓰려면 테스트 PC 의 ini 를 기준으로 만들고, 새 서버가 완전히 준비된 다음에 하세요.
3. 바꾼 PC 부터 차례로 새 서버의 PC 현황에서 확인합니다.

### A-7. MySQL 로 바꾸고 싶다면

SQLite 그대로 옮기는 것을 권장합니다(파일 하나). MySQL 로 바꾸면 `updater.db` 의 내용은 자동으로 옮겨지지 않고 빈 테이블로 시작합니다. PC 현황은 PC 가 다시 접속하면 채워지지만 예전 업데이트 이력은 사라집니다.

### A-8. 우분투 점검

본문 9장 체크리스트를 그대로 씁니다. 추가로:

```bash
curl -I https://updates.회사도메인.com/data/index.html     # 403 이어야 함
curl -I https://updates.회사도메인.com/config.php          # 403 이어야 함
curl -s https://updates.회사도메인.com/manifests/MWAGENT.json | head -c 300
sudo tail -n 50 /var/log/apache2/updates_error.log
```

백업은 `/var/www/updates` 를 cron + `tar` 로 외부 저장소에 보내면 됩니다.

```bash
# 예: 매일 03:00, 14일치 보관
0 3 * * * tar czf /backup/updates-$(date +\%F).tgz -C /var/www updates && find /backup -name 'updates-*.tgz' -mtime +14 -delete
```

---

## 부록 B. 서버 폴더 구조와 파일 역할

| 경로 | 역할 | 재설치 시 |
|---|---|---|
| `index.php` | 관리 사이트 (로그인, 화면) | 새 코드 |
| `api.php` | 관리 사이트 API (배포, 롤백, 서명, 키 등록) | 새 코드 |
| `lib.php` | 공통 함수, 경로 상수 (`files/`, `manifests/`, `data/`, `data/apps/`) | 새 코드 |
| `otp.php` | Google OTP, 로그인 잠금, OTP 재확인, 감사 로그 | 새 코드 |
| `sign.php` | 배포 전자서명 확인, 런처 일괄 교체 | 새 코드 |
| `launcher_api.php`, `api/v1/launcher/*.php` | 런처용 API (확인·보고) | 새 코드 |
| `views/` | 화면 템플릿 | 새 코드 |
| `tools/Updater.exe` | 런처 일괄 교체용 런처 | 새 코드 (직접 바꿨다면 백업본) |
| `.htaccess`, `.user.ini`, 각 폴더 `.htaccess` | 접근 차단, 업로드 한도 | 새 코드 (빠지지 않았는지 확인) |
| **`config.php`** | 운영 설정 | **백업본 (덮어쓰기 금지)** |
| **`data/`** | 솔루션 상태·이력, OTP, 서명 공개 키, DB, 감사 로그 | **백업본** |
| **`files/`** | 배포 파일 본문 | **백업본** |
| **`manifests/`** | PC 용 공개 Manifest | **백업본** |
