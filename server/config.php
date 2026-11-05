<?php
// ===== 통합 배포관리 설정 =====

// 관리자 비밀번호 (반드시 변경!)
define('ADMIN_PASSWORD', 'change-me');

// Google OTP (Google Authenticator 등) 로그인
//  'password+otp' : 비밀번호 + OTP 6자리 (권장, 기본값)
//  'otp'          : OTP 6자리만
//  'off'          : 비밀번호만
// OTP 등록 전에는 비밀번호로 로그인한 뒤 상단 [보안] 메뉴에서 QR 을 등록하세요.
// 휴대폰 분실 시: File Station 에서 data/otp.json 을 삭제하면 비밀번호로 로그인해 다시 등록할 수 있습니다.
define('OTP_MODE', 'password+otp');

// 사이트 제목
define('SITE_TITLE', '통합 배포관리');

// PC 현황/업데이트 이력 DB
//  - 기본: SQLite (설치 불필요, data/updater.db 파일로 저장. PHP 에 pdo_sqlite 필요)
//  - MySQL/MariaDB 사용 시 예:
//      define('DB_DSN', 'mysql:host=localhost;dbname=updater;charset=utf8mb4');
//      define('DB_USER', 'updater');
//      define('DB_PASS', '비밀번호');
//    (테이블은 처음 접속할 때 자동 생성됩니다)
define('DB_DSN', 'sqlite:' . __DIR__ . '/data/updater.db');
define('DB_USER', '');
define('DB_PASS', '');

// 런처 인증키. 비워두면 인증 없이 동작. 값을 넣으면 각 PC의 updater.ini 에 ClientKey=같은값 이 있어야 보고를 받습니다.
define('CLIENT_KEY', '');

// 시간대 (배포/업데이트 시각 표시 기준)
define('TIMEZONE', 'Asia/Seoul');

// 이 일수 이상 접속이 없는 PC 는 '장기 미접속'으로 표시
define('INACTIVE_DAYS', 30);
