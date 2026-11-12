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
      err.stepup = r.stepup || ''; err.status = xhr.status;
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

// ---------- 중요 작업 재인증 창 ----------
// 배포/롤백/삭제 직전에 작업 내용을 보여주고 OTP(미등록 시 비밀번호)를 다시 받습니다.
// opts: {title, html(내용, 이미 escape 된 HTML), okText, danger, check(선택: 동의 체크박스 문구)}
// 성공하면 서버 응답, 취소하면 null
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function secureApi(fields, opts) {
  return new Promise((resolve, reject) => {
    const isOtp = STEPUP === 'otp';
    const dlg = document.createElement('dialog');
    dlg.className = 'stepup';
    dlg.innerHTML =
      '<form method="dialog">' +
      '<h2>' + esc(opts.title || '확인') + '</h2>' +
      '<div class="stepup-body">' + (opts.html || '') + '</div>' +
      (opts.check ? '<p><label class="stepup-check"><input type="checkbox" class="su-check"> ' + esc(opts.check) + '</label></p>' : '') +
      '<p class="stepup-lock">🔒 ' + (isOtp ? '보안을 위해 <b>Google OTP 6자리</b>를 다시 입력하세요.' : '보안을 위해 <b>관리자 비밀번호</b>를 다시 입력하세요.') + '</p>' +
      '<input class="su-code" ' + (isOtp ? 'type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="OTP 6자리"' : 'type="password" autocomplete="current-password" placeholder="비밀번호"') + '>' +
      '<p class="su-err err small-text"></p>' +
      '<div class="row" style="justify-content:flex-end">' +
      '<button type="button" class="secondary su-cancel">취소</button>' +
      '<button type="submit" class="su-ok' + (opts.danger ? ' danger' : '') + '">' + esc(opts.okText || '확인') + '</button></div>' +
      '</form>';
    document.body.appendChild(dlg);
    const code = dlg.querySelector('.su-code'), errEl = dlg.querySelector('.su-err'), ok = dlg.querySelector('.su-ok'), chk = dlg.querySelector('.su-check');
    let busy = false;
    const close = v => { dlg.close(); dlg.remove(); resolve(v); };
    dlg.querySelector('.su-cancel').onclick = () => !busy && close(null);
    dlg.addEventListener('cancel', e => { e.preventDefault(); if (!busy) close(null); });
    dlg.querySelector('form').onsubmit = async e => {
      e.preventDefault();
      if (busy) return;
      if (chk && !chk.checked) { errEl.textContent = '내용을 확인했다면 체크해 주세요.'; return; }
      const v = code.value.trim();
      if (!v) { errEl.textContent = isOtp ? 'OTP 코드를 입력하세요.' : '비밀번호를 입력하세요.'; code.focus(); return; }
      busy = true; ok.disabled = true; errEl.textContent = '';
      try {
        const r = await api(Object.assign({}, fields, {stepup: v}));
        close(r);
      } catch (err) {
        busy = false; ok.disabled = false;
        if (err.status === 401) { dlg.close(); dlg.remove(); alert(err.message); location.reload(); return; }
        if (err.stepup) { errEl.textContent = err.message; code.value = ''; code.focus(); return; }
        dlg.close(); dlg.remove(); reject(err);
      }
    };
    dlg.showModal();
    code.focus();
  });
}
