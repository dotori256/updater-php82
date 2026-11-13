# 시놀로지 Web Station 설치 가이드

DSM 7.2 기준입니다. 메뉴 이름은 DSM 버전이나 언어 설정에 따라 조금 다를 수 있어서 영문 이름을 괄호에 같이 적었습니다.

설치는 아래 순서로 진행합니다.

1. 패키지 설치 (Web Station, PHP 8.2, Apache 2.4)
2. PHP 프로필 만들기 (SQLite 확장, 업로드 한도)
3. 파일 올리기
4. 폴더 권한 주기
5. 웹 서비스와 포털 만들기 (Apache 백엔드)
6. 관리자 비밀번호와 Google OTP
7. 설치 점검 화면으로 확인
8. 외부 접속과 HTTPS (외부 PC에서 업데이트할 경우)
9. PC에 런처 설치

---

## 1. 패키지 설치

**패키지 센터 (Package Center)** 에서 아래 3개를 설치합니다.

| 패키지 | 용도 |
|---|---|
| **Web Station** | 웹사이트 운영 |
| **PHP 8.2** | 관리 웹과 업데이트 API 실행 (7.4 이상이면 되지만 8.2 권장) |
| **Apache HTTP Server 2.4** | `.htaccess` 보안 설정 적용에 **꼭 필요** |

> **Apache를 꼭 쓰는 이유**: 시놀로지 기본 웹서버(Nginx)는 `.htaccess` 를 읽지 않습니다. 그러면 PC 현황 DB(`data/updater.db`)와 내부 파일이 인터넷에서 그대로 내려받아질 수 있어요. 이 시스템은 Apache 백엔드로 설치하세요.

선택 사항: 설정 파일을 NAS에서 바로 고치려면 **텍스트 편집기 (Text Editor)** 패키지도 설치하면 편합니다.

---

## 2. PHP 프로필 만들기

**Web Station › 스크립트 언어 설정 (Script Language Settings) › PHP 탭 › 생성 (Create)**

**일반 (General)**
- 프로필 이름: `updater-php82`
- PHP 버전: **PHP 8.2**
- PHP 캐시 활성화: 켜도 됨
- open_basedir 사용자 지정: 기본값 그대로

**확장 (Extensions)**: 아래 항목을 체크합니다.
- `pdo_sqlite`, `sqlite3`: **필수**. PC 현황과 업데이트 이력 DB
- `openssl` (배포 전자서명에 필요, 꼭 체크), `curl`, `mbstring`, `zip`: 목록에 있으면 체크
- MySQL(MariaDB 10 패키지)을 쓰려면 `pdo_mysql` 도 체크

**코어 (Core)**: 아래 항목을 검색해서 값을 바꿉니다. 업로드할 가장 큰 파일보다 크게 잡으세요.

| 항목 | 값 |
|---|---|
| `upload_max_filesize` | `512M` |
| `post_max_size` | `520M` |
| `max_execution_time` | `300` |
| `max_input_time` | `300` |
| `memory_limit` | `256M` |

> 업로드 파일은 디스크로 바로 저장되기 때문에 `memory_limit` 를 파일 크기만큼 늘릴 필요는 없습니다.

**저장**합니다.

---

## 3. 파일 올리기

Web Station을 설치하면 **`web`** 공유 폴더가 생깁니다.

1. **File Station** 에서 `web` 폴더를 엽니다.
2. `auto-updater.zip` 을 업로드합니다.
3. zip 파일을 우클릭하고 **압축 풀기 (Extract) › 여기에 풀기** 를 선택합니다.
   - 압축 풀기를 하면 `.htaccess`, `.user.ini` 같은 숨김 파일도 같이 풀립니다. PC에서 폴더째 끌어다 놓으면 숨김 파일이 빠질 수 있으니 이 방법을 권장합니다.
4. 풀린 `auto-updater/server` 폴더를 `web` 바로 아래로 옮기고, 이름을 **`updates`** 로 바꿉니다.
5. 남은 `auto-updater` 폴더와 zip 파일은 지워도 됩니다. (런처 파일은 관리 웹에서 다시 받을 수 있어요)

최종 구조는 이렇습니다.
```
web/
└─ updates/
   ├─ .htaccess   .user.ini
   ├─ index.php   api.php   lib.php   config.php   launcher_api.php
   ├─ api/v1/launcher/   (check.php, report.php)
   ├─ views/   files/   manifests/   data/   tools/
```

> 숨김 파일이 보이지 않으면 File Station 우측 상단 **설정 › 숨김 파일 표시** 를 켜고 `.htaccess` 가 있는지 확인하세요.

---

## 4. 폴더 권한 주기

웹서버는 시놀로지 내부 계정인 **`http`** 로 실행됩니다. `http` 가 파일을 저장할 수 있어야 합니다.

1. File Station에서 `web/updates` 폴더를 우클릭하고 **속성 (Properties) › 권한 (Permission)** 을 엽니다.
2. **생성 (Create)** 을 누르고 아래처럼 설정합니다.
   - 사용자 또는 그룹: **`http`** (그룹 목록이나 "시스템 내부 사용자"에서 선택)
   - 유형: 허용
   - 권한: **읽기**와 **쓰기** 모두 체크
3. 아래쪽의 **"이 폴더, 하위 폴더 및 파일에 적용"** 을 체크하고 **확인** 을 누릅니다.

> 최소 권한만 주고 싶다면 `files`, `manifests`, `data` 세 폴더에만 쓰기 권한을 주면 됩니다. 나머지는 읽기만 있으면 됩니다.

---

## 5. 웹 서비스와 포털 만들기

### 5-1. 웹 서비스
**Web Station › 웹 서비스 (Web Service) › 생성 (Create)**

1. 유형: **기본 스크립트 언어 웹 사이트 (Native script language website)**
2. 설정
   - 이름: `updater`
   - 문서 루트 (Document root): **`web/updates`**
   - HTTP 백엔드 서버 (HTTP back-end server): **Apache HTTP Server 2.4**
   - PHP: **`updater-php82`** (2단계에서 만든 프로필)
3. **다음 › 만들기**

### 5-2. 웹 포털 (접속 주소)
웹 서비스를 만들면 바로 포털 생성 화면이 나옵니다. 나중에 만들 때는 **웹 포털 (Web Portal) › 생성** 에서 합니다.

두 가지 중 하나를 고르세요.

| 방식 | 설정 | 접속 주소 예시 |
|---|---|---|
| **포트 기반** (간단, 사내망용) | HTTP 포트 `8088`, HTTPS 포트 `8443` | `http://192.168.0.10:8088/` |
| **이름 기반** (외부 접속, 도메인 있을 때) | 호스트 이름 `updates.회사도메인.com`, 포트 80/443 | `https://updates.회사도메인.com/` |

- 포털의 **서비스** 항목에서 5-1에서 만든 `updater` 를 선택합니다.
- **HSTS** 는 HTTPS 인증서를 연결한 다음에 켜세요.

> 포털을 따로 만들지 않고 기본 서버로 `http://NAS주소/updates/` 처럼 쓸 수도 있습니다. 이 경우 **웹 포털 › 기본 서버 (Default server) › 편집** 에서 HTTP 백엔드를 **Apache 2.4**, PHP 프로필을 **updater-php82** 로 바꿔야 합니다. 다만 기본 서버를 쓰는 다른 웹사이트에도 영향이 가므로 전용 포털을 권장합니다.

### 5-3. 방화벽
**제어판 › 보안 › 방화벽** 을 사용 중이면, 위에서 정한 포트(8088/8443 또는 80/443)를 허용 규칙에 추가합니다.

---

## 6. 관리자 비밀번호와 Google OTP

`web/updates/config.php` 를 텍스트 편집기로 열고 아래 줄을 바꿉니다.

```php
define('ADMIN_PASSWORD', 'change-me');      // ← 원하는 비밀번호로
define('OTP_MODE', 'password+otp');         // 비밀번호 + Google OTP (권장). 'otp' = OTP만, 'off' = 비밀번호만
```

**Google OTP 등록**
1. NAS 시계가 맞는지 확인합니다. **제어판 › 지역 옵션 › 시간 › NTP 서버와 동기화** 를 켜두세요. 시계가 1분 이상 틀리면 코드가 맞지 않습니다.
2. 휴대폰에 **Google Authenticator** 앱을 설치합니다.
3. 관리 사이트에 비밀번호로 로그인한 뒤 상단 **보안** 메뉴로 갑니다.
4. 앱에서 **+ › QR 코드 스캔** 으로 화면의 QR 을 찍고, 앱에 나온 6자리를 입력해 **[등록]** 합니다.
5. 다음 로그인부터 비밀번호와 OTP 6자리를 함께 입력합니다.

> 휴대폰을 잃어버렸다면 File Station 에서 `web/updates/data/otp.json` 을 삭제하세요. 비밀번호만으로 로그인해서 다시 등록할 수 있습니다.
> 같은 IP 에서 5번 틀리면 10분 동안 로그인이 잠깁니다.

필요에 따라 아래 값도 바꿀 수 있습니다.

```php
define('SITE_TITLE', '통합 배포관리');       // 사이트 제목
define('TIMEZONE', 'Asia/Seoul');           // 시간대 (기본값 그대로 두면 됨)
define('CLIENT_KEY', '');                   // 런처 인증키 (아래 참고)
```

- **CLIENT_KEY**: 값을 넣으면 같은 키를 가진 런처만 업데이트 결과를 보고할 수 있습니다. 외부에 공개하는 서버라면 넣어두세요. 관리 웹에서 받는 `updater.ini` 에는 이 키가 자동으로 들어갑니다.
- **MySQL을 쓰려면** (MariaDB 10 패키지):
  ```php
  define('DB_DSN', 'mysql:host=localhost;port=3306;dbname=updater;charset=utf8mb4');
  define('DB_USER', 'updater');
  define('DB_PASS', '비밀번호');
  ```
  DB와 계정만 먼저 만들어 두면 테이블은 자동으로 생성됩니다. 몇백 대 규모까지는 기본값인 SQLite로 충분합니다.

---

## 7. 설치 점검

1. 브라우저로 5단계에서 정한 주소에 접속해서 로그인합니다.
2. 상단 메뉴의 **설치 점검** 을 엽니다.
3. 모든 항목이 **정상** 인지 확인합니다.

| 확인 필요 항목 | 해결 |
|---|---|
| pdo_sqlite 확장 | 2단계 PHP 프로필에서 `pdo_sqlite`, `sqlite3` 체크 후 저장 |
| DB 연결 | `data` 폴더 쓰기 권한(4단계)과 pdo_sqlite 확인 |
| files / manifests / data 쓰기 권한 | 4단계 `http` 권한, 특히 "하위 폴더 및 파일에 적용" 체크 |
| 업로드 한도 | 2단계 코어 설정 (`upload_max_filesize`, `post_max_size`) |
| **data/ 외부 접근 차단** | 웹 서비스의 HTTP 백엔드가 **Apache 2.4** 인지 확인. Nginx면 DB가 노출됩니다 |
| 런처 API | `api/v1/launcher` 폴더가 올라갔는지 확인 |
| HTTPS | 8단계 (사내망에서만 쓰면 무시해도 됨) |

---

## 8. 외부 접속과 HTTPS (외부 PC에서 업데이트할 경우)

사내망 PC만 쓴다면 이 단계는 건너뛰어도 됩니다.

1. **주소 만들기**: 고정 도메인이 없으면 **제어판 › 외부 액세스 (External Access) › DDNS › 추가** 에서 서비스 제공업체를 **Synology** 로 하고 `회사이름.synology.me` 를 만듭니다.
   - 이때 **"Let's Encrypt 인증서 받기"** 와 **"와일드카드 인증서"** 를 체크하면 `updates.회사이름.synology.me` 같은 하위 주소에도 인증서가 적용됩니다.
2. **공유기 포트포워딩**: 공유기 관리 화면에서 사용하는 포트(443 또는 8443)를 NAS 내부 IP로 포워딩합니다. Let's Encrypt 발급과 갱신에는 80 포트도 필요합니다.
3. **인증서 발급**: DDNS에서 발급하지 않았다면 **제어판 › 보안 › 인증서 › 추가 › Let's Encrypt** 로 도메인 인증서를 받습니다.
4. **인증서 연결**: **제어판 › 보안 › 인증서 › 설정 (Settings)** 에서 5단계에서 만든 웹 포털(서비스) 항목에 방금 받은 인증서를 지정합니다.
5. 웹 포털 설정에서 **HTTP를 HTTPS로 자동 리디렉션** 을 켭니다. (선택)
6. 런처의 `ServerUrl` 은 `https://` 주소로 씁니다. 런처는 TLS 1.2를 지원합니다.

---

## 9. PC에 런처 설치

1. 관리 웹 **대시보드 › 새 솔루션 등록** 에서 솔루션(예: `MWAGENT`)을 등록하고, **배포** 탭에서 bin 폴더를 올려 첫 버전(1.0.0)을 배포합니다.
2. **설정 / 설치** 탭에서 **Updater.exe** 와 **이 솔루션용 updater.ini** 를 받습니다. ini 에는 서버 주소, 솔루션 ID, 인증키가 이미 들어 있어요.
   - 관리 웹을 사내 IP로 열었다면 ini 의 주소도 사내 IP로 나옵니다. 외부 PC용이라면 외부 주소로 접속한 상태에서 받거나, `ServerUrl` 만 고쳐 쓰세요.
3. 각 PC의 bin 폴더(예: `C:\MWAGENT\bin`)에 두 파일을 넣고, 바탕화면 바로가기를 **Updater.exe** 로 만듭니다.
   - `C:\Program Files` 아래는 관리자 권한 없이 파일을 바꿀 수 없으니 피하세요.
4. Updater.exe 를 실행해서 업데이트가 끝나고 프로그램이 실행되는지 확인합니다.
5. 관리 웹 **PC 현황** 탭에 그 PC가 **최신** 으로 나오면 설치가 끝난 것입니다.

---

## 문제 해결

| 증상 | 원인과 해결 |
|---|---|
| 접속하면 PHP 코드가 그대로 보이거나 다운로드됨 | 웹 서비스에 PHP 프로필이 지정되지 않음 → 5-1 확인 |
| 500 Internal Server Error | `.htaccess` 설정이 거부됨. Web Station 로그를 확인하고, 그래도 안 되면 `updates/.htaccess` 의 `Options -Indexes` 줄 앞에 `#` 를 붙여 보세요 |
| 로그인 후 "DB 연결 실패" 경고 | pdo_sqlite 확장 미체크 또는 `data` 쓰기 권한 없음 → 2, 4단계 |
| 업로드 시 "파일이 너무 큽니다" | PHP 프로필 코어 설정의 업로드 한도 → 2단계 |
| 업로드 시 "HTTP 413" | 웹서버 앞단의 요청 크기 제한입니다. 파일을 나눠 올려 보고, 계속되면 알려주세요 |
| 업로드가 중간에 멈춤 | `max_execution_time`, `max_input_time` 을 300 이상으로 |
| 시각이 9시간 다르게 표시됨 | `config.php` 의 `TIMEZONE` 이 `Asia/Seoul` 인지 확인 |
| PC 런처가 "서버에 연결할 수 없습니다" | 브라우저로 `ServerUrl` + `manifests/솔루션ID.json` 이 열리는지 확인. 외부라면 포트포워딩과 방화벽 확인 |
| PC 런처가 "인증 실패 (ClientKey 확인)" | `config.php` 의 `CLIENT_KEY` 와 `updater.ini` 의 `ClientKey` 를 같게 |
| PC 런처가 "등록되지 않은 솔루션" | `updater.ini` 의 `App` 이 관리 웹의 솔루션 ID와 같은지 확인 (대소문자 포함) |

---

## 백업과 업그레이드

- **백업**: `web/updates` 폴더 전체를 **Hyper Backup** 백업 대상에 넣으세요. 모든 데이터는 `data/`, `files/`, `manifests/` 와 `config.php` 에 있습니다.
- **새 버전으로 업그레이드**: 새 zip의 `server` 폴더 내용으로 덮어쓰되, **`config.php`, `data/`, `files/`, `manifests/` 는 덮어쓰지 마세요.**
