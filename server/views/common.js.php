<?php if (!defined('ADMIN_PASSWORD')) exit; /* index.php 에서 include 하는 공용 스크립트 */ ?>
function api(fields, file, onProgress) {
  return new Promise((resolve, reject) => {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const k in fields) fd.append(k, fields[k]);
    if (file) fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php');
    if (onProgress) xhr.upload.onprogress = e => e.lengthComputable && onProgress(e.loaded / e.total);
    xhr.onload = () => {
      let r; try { r = JSON.parse(xhr.responseText); } catch (e) { return reject(new Error('서버 응답 오류 (HTTP ' + xhr.status + ')' + (xhr.status == 413 ? ' 파일이 너무 큽니다' : ''))); }
      if (r.ok) return resolve(r);
      const err = new Error(r.error || '실패');
      err.stepup = r.stepup || ''; err.status = xhr.status; err.needSign = !!r.needSign; err.payload = r.payload || ''; err.payloads = r.payloads || null;
      reject(err);
    };
    xhr.onerror = () => reject(new Error('네트워크 오류'));
    xhr.send(fd);
  });
}
function fmtSize(b) {
  if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
  if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
  if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
  return b + ' B';
}

// ---------- 전자서명 (WebCrypto, 비밀 키는 브라우저 밖으로 나가지 않음) ----------
// 키 파일: RSA 2048 비밀 키(PKCS8)를 키 암호로 잠근 JSON (PBKDF2-SHA256 → AES-GCM). 공개 키(spki)는 그대로 들어 있음
const SIGN_ITER = 310000;
const b64 = buf => btoa(String.fromCharCode(...new Uint8Array(buf)));
const unb64 = s => Uint8Array.from(atob(s), c => c.charCodeAt(0));
function cryptoReady() {
  if (window.crypto && crypto.subtle) return true;
  throw new Error('이 브라우저 접속에서는 전자서명을 쓸 수 없습니다. 관리 사이트에 https:// 로 접속하세요. (브라우저 보안 정책상 http 에서는 암호 기능이 막혀 있습니다)');
}
async function passKey(pass, salt, iter) {
  const base = await crypto.subtle.importKey('raw', new TextEncoder().encode(pass), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey({name:'PBKDF2', hash:'SHA-256', salt, iterations: iter}, base, {name:'AES-GCM', length:256}, false, ['encrypt', 'decrypt']);
}
async function fingerprintOf(spkiB64) {
  const h = new Uint8Array(await crypto.subtle.digest('SHA-256', unb64(spkiB64)));
  const hex = [...h.slice(0, 8)].map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();
  return hex.match(/.{4}/g).join(':');
}
async function signKeyCreate(pass) {
  cryptoReady();
  const kp = await crypto.subtle.generateKey({name:'RSASSA-PKCS1-v1_5', modulusLength:2048, publicExponent:new Uint8Array([1,0,1]), hash:'SHA-256'}, true, ['sign', 'verify']);
  const spki = b64(await crypto.subtle.exportKey('spki', kp.publicKey));
  const pkcs8 = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
  const salt = crypto.getRandomValues(new Uint8Array(16)), iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({name:'AES-GCM', iv}, await passKey(pass, salt, SIGN_ITER), pkcs8);
  const fp = await fingerprintOf(spki);
  const jwk = await crypto.subtle.exportKey('jwk', kp.publicKey), std = u => u.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((u.length + 3) % 4);
  return {spki, fingerprint: fp, ini: std(jwk.n) + '.' + std(jwk.e), file: {type:'updater-sign-key', v:1, alg:'RSASSA-PKCS1-v1_5/SHA-256', fingerprint: fp, created: new Date().toISOString(),
    spki, kdf:{name:'PBKDF2', hash:'SHA-256', iter:SIGN_ITER, salt:b64(salt)}, iv:b64(iv), data:b64(data)}};
}
async function readKeyFile(file) {
  let k; try { k = JSON.parse(await file.text()); } catch (e) { k = null; }
  if (!k || k.type !== 'updater-sign-key' || !k.spki || !k.data) throw new Error('서명 키 파일이 아닙니다.');
  return k;
}
async function signWithKeyFile(file, pass, payload) {
  cryptoReady();
  const k = await readKeyFile(file);
  if (SIGN_FP && k.fingerprint !== SIGN_FP) throw new Error('서버에 등록된 키(' + SIGN_FP + ')와 다른 키 파일입니다 (' + k.fingerprint + ').');
  let pkcs8;
  try { pkcs8 = await crypto.subtle.decrypt({name:'AES-GCM', iv:unb64(k.iv)}, await passKey(pass, unb64(k.kdf.salt), k.kdf.iter), unb64(k.data)); }
  catch (e) { throw new Error('키 암호가 틀렸습니다.'); }
  const priv = await crypto.subtle.importKey('pkcs8', pkcs8, {name:'RSASSA-PKCS1-v1_5', hash:'SHA-256'}, false, ['sign']);
  return b64(await crypto.subtle.sign('RSASSA-PKCS1-v1_5', priv, new TextEncoder().encode(payload)));
}
function downloadJson(name, obj) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([JSON.stringify(obj, null, 1)], {type:'application/json'}));
  a.download = name; document.body.appendChild(a); a.click(); a.remove();
}

// ---------- 중요 작업 확인 창 ----------
// 작업 내용을 보여주고 본인 확인을 받습니다.
//  - opts.publish (배포·롤백 등 PC 가 받는 내용이 바뀌는 작업): 서명 키가 등록돼 있으면 키 파일 + 키 암호로 전자서명, 아니면 OTP
//  - 그 밖(솔루션 삭제, 키 등록 등): OTP (미등록 시 비밀번호)
// opts: {title, html(내용, 이미 escape 된 HTML), okText, danger, check(선택: 동의 체크박스 문구), publish}
// 성공하면 서버 응답, 취소하면 null
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function secureApi(fields, opts) {
  return new Promise((resolve, reject) => {
    const useSign = !!opts.publish && SIGNING;
    const isOtp = STEPUP === 'otp';
    const dlg = document.createElement('dialog');
    dlg.className = 'stepup';
    const authHtml = useSign
      ? '<p class="stepup-lock">🔏 <b>전자서명</b>으로 배포합니다. 서명 키 파일을 고르고 키 암호를 입력하세요. <span class="muted small-text">(키 ' + esc(SIGN_FP) + ' · 키는 이 브라우저 안에서만 쓰이고 서버로 보내지 않습니다)</span></p>' +
        '<p><input type="file" class="su-keyfile" accept=".json,application/json"></p>' +
        '<input class="su-code" type="password" autocomplete="off" placeholder="키 암호">'
      : '<p class="stepup-lock">🔒 ' + (isOtp ? '보안을 위해 <b>Google OTP 6자리</b>를 다시 입력하세요.' : '보안을 위해 <b>관리자 비밀번호</b>를 다시 입력하세요.') + '</p>' +
        '<input class="su-code" ' + (isOtp ? 'type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="OTP 6자리"' : 'type="password" autocomplete="current-password" placeholder="비밀번호"') + '>';
    dlg.innerHTML =
      '<form method="dialog">' +
      '<h2>' + esc(opts.title || '확인') + '</h2>' +
      '<div class="stepup-body">' + (opts.html || '') + '</div>' +
      (opts.check ? '<p><label class="stepup-check"><input type="checkbox" class="su-check"> ' + esc(opts.check) + '</label></p>' : '') +
      authHtml +
      '<p class="su-err err small-text"></p>' +
      '<div class="row" style="justify-content:flex-end">' +
      '<button type="button" class="secondary su-cancel">취소</button>' +
      '<button type="submit" class="su-ok' + (opts.danger ? ' danger' : '') + '">' + esc(opts.okText || '확인') + '</button></div>' +
      '</form>';
    document.body.appendChild(dlg);
    const code = dlg.querySelector('.su-code'), errEl = dlg.querySelector('.su-err'), ok = dlg.querySelector('.su-ok'),
          chk = dlg.querySelector('.su-check'), keyFile = dlg.querySelector('.su-keyfile');
    let busy = false;
    const close = v => { dlg.close(); dlg.remove(); resolve(v); };
    dlg.querySelector('.su-cancel').onclick = () => !busy && close(null);
    dlg.addEventListener('cancel', e => { e.preventDefault(); if (!busy) close(null); });
    dlg.querySelector('form').onsubmit = async e => {
      e.preventDefault();
      if (busy) return;
      if (chk && !chk.checked) { errEl.textContent = '내용을 확인했다면 체크해 주세요.'; return; }
      if (keyFile && !keyFile.files.length) { errEl.textContent = '서명 키 파일을 선택하세요.'; return; }
      const v = code.value.trim();
      if (!v) { errEl.textContent = useSign ? '키 암호를 입력하세요.' : isOtp ? 'OTP 코드를 입력하세요.' : '비밀번호를 입력하세요.'; code.focus(); return; }
      busy = true; ok.disabled = true; errEl.textContent = useSign ? '서명 중...' : '';
      try {
        let r;
        if (useSign) {
          // 1) 서버가 이 작업으로 바뀔 내용(payload)을 알려줌 → 2) 키 파일로 서명 → 3) 서명과 함께 다시 요청
          try { r = await api(fields); }
          catch (err) {
            if (!err.needSign) throw err;
            if (err.payloads) {   // 여러 솔루션을 한 번에 (런처 일괄 업그레이드)
              const signatures = {};
              for (const id in err.payloads) { errEl.textContent = '서명 중... ' + id; signatures[id] = await signWithKeyFile(keyFile.files[0], v, err.payloads[id]); }
              r = await api(Object.assign({}, fields, {signatures: JSON.stringify(signatures)}));
            } else {
              const signature = await signWithKeyFile(keyFile.files[0], v, err.payload);
              r = await api(Object.assign({}, fields, {signature}));
            }
          }
        } else {
          r = await api(Object.assign({}, fields, {stepup: v}));
        }
        close(r);
      } catch (err) {
        busy = false; ok.disabled = false;
        if (err.status === 401) { dlg.close(); dlg.remove(); alert(err.message); location.reload(); return; }
        if (err.stepup || err.needSign || !err.status) { errEl.textContent = err.message; if (!useSign) code.value = ''; code.focus(); return; }
        dlg.close(); dlg.remove(); reject(err);
      }
    };
    dlg.showModal();
    (keyFile || code).focus();
  });
}
