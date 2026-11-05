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
      r.ok ? resolve(r) : reject(new Error(r.error || '실패'));
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
