'use strict';
let csrf = '', savedCodes = [], busy = false;
const $ = id => document.getElementById(id);
const show = (id, value) => $(id).hidden = !value;
function notice(text, bad = false) {
  const el = $('toast');
  el.textContent = text;
  el.className = bad ? 'error' : 'success';
  el.hidden = false;
  clearTimeout(notice.timer);
  notice.timer = setTimeout(() => el.hidden = true, 8000);
}
async function api(action, data) {
  const response = await fetch('api.php?action=' + encodeURIComponent(action), data === undefined ? {credentials: 'same-origin'} : {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(data)});
  const text = await response.text();
  let body;
  try { body = JSON.parse(text); } catch { throw Error('تعذر الاتصال بالخادم. راجع إعداد PHP وقاعدة البيانات.'); }
  if (!response.ok || body.error) throw Error(body.error || 'تعذر إتمام الطلب.');
  return body;
}
function from64(s) {
  const raw = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - s.length % 4) % 4));
  return Uint8Array.from(raw, c => c.charCodeAt(0));
}
function to64(buffer) {
  return btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function decodeOptions(options) {
  options.challenge = from64(options.challenge);
  if (options.user) options.user.id = from64(options.user.id);
  for (const list of ['allowCredentials', 'excludeCredentials']) if (options[list]) options[list].forEach(c => c.id = from64(c.id));
  return options;
}
async function run(task) {
  if (busy) return;
  busy = true;
  document.body.classList.add('busy');
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  try { await task(); } catch (e) {
    const messages = {NotAllowedError: 'أُلغي التحقق أو انتهت المهلة. جرّب مرة أخرى.', InvalidStateError: 'يوجد مفتاح مسجل بالفعل على هذا الجهاز.', SecurityError: 'يلزم HTTPS وإعداد نطاق مطابق للموقع.', NotSupportedError: 'طريقة التحقق غير مدعومة على هذا الجهاز.'};
    notice(messages[e.name] || e.message, true);
  } finally {
    busy = false;
    document.body.classList.remove('busy');
    document.querySelectorAll('button').forEach(b => b.disabled = false);
    $('close-codes').disabled = !$('codes-saved').checked;
  }
}
function panel(name) {
  ['login', 'register', 'recovery'].forEach(p => show(p + '-panel', p === name));
  $('login-tab').setAttribute('aria-selected', String(name === 'login'));
  $('register-tab').setAttribute('aria-selected', String(name === 'register'));
}
function date(s) {
  if (!s) return 'لم يُستخدم بعد';
  return new Intl.DateTimeFormat('ar-SA', {dateStyle: 'medium', calendar: 'gregory'}).format(new Date(s.replace(' ', 'T') + 'Z'));
}
async function refresh() {
  const s = await api('session');
  csrf = s.csrf;
  show('loading', false);
  show('auth', !s.user);
  show('dashboard', !!s.user);
  if (!s.user) return;
  $('greeting').textContent = 'أهلًا، ' + s.user.display_name;
  $('profile-name').textContent = s.user.display_name;
  $('profile-username').textContent = '@' + s.user.username;
  $('avatar').textContent = Array.from(s.user.display_name)[0];
  $('joined').textContent = date(s.user.created_at);
  $('key-count').textContent = s.keys.length;
  $('keys').replaceChildren();
  for (const k of s.keys) {
    const row = document.createElement('div'); row.className = 'key-row';
    const icon = document.createElement('span'); icon.className = 'key-icon'; icon.textContent = '⌘';
    const info = document.createElement('div');
    const title = document.createElement('strong'); title.textContent = k.label;
    const sub = document.createElement('small'); sub.textContent = 'آخر استخدام: ' + date(k.last_used_at);
    info.append(title, sub);
    const del = document.createElement('button'); del.className = 'delete'; del.textContent = 'حذف';
    del.setAttribute('aria-label', 'حذف ' + k.label);
    del.addEventListener('click', () => {
      if (confirm('حذف مفتاح «' + k.label + '»؟ لن تتمكن من الدخول باستخدامه بعد الحذف.')) run(async () => {await api('delete-key', {id: k.id}); await refresh(); notice('تم حذف المفتاح.');});
    });
    row.append(icon, info, del); $('keys').append(row);
  }
}
function displayCodes(codes) {
  if (!codes.length) return;
  savedCodes = codes;
  $('codes').replaceChildren(...codes.map(code => {const el = document.createElement('code'); el.textContent = code; return el;}));
  $('codes-saved').checked = false;
  $('close-codes').disabled = true;
  $('codes-dialog').showModal();
}
function supported() {
  if (!window.isSecureContext || !window.PublicKeyCredential || !navigator.credentials) throw Error('هذا المتصفح لا يدعم مفاتيح المرور، أو أن الاتصال غير آمن. استخدم HTTPS ومتصفحًا حديثًا.');
}
async function enroll(form, adding) {
  supported();
  const data = Object.fromEntries(new FormData(form));
  const prefix = adding ? 'add' : 'register';
  const options = await api(prefix + '-options', data);
  const c = await navigator.credentials.create({publicKey: decodeOptions(options.publicKey)});
  if (!c) throw Error('لم يُنشأ مفتاح مرور.');
  const result = await api(prefix + '-finish', {rawId: to64(c.rawId), clientDataJSON: to64(c.response.clientDataJSON), attestationObject: to64(c.response.attestationObject), label: data.label});
  form.reset();
  await refresh();
  displayCodes(result.codes || []);
  notice(adding ? 'تمت إضافة المفتاح.' : 'تم إنشاء حسابك بنجاح. احفظ رموز الاسترداد.');
}
$('login-tab').onclick = () => panel('login');
$('register-tab').onclick = () => panel('register');
$('show-recover').onclick = () => panel('recovery');
$('back-login').onclick = () => panel('login');
$('register-panel').onsubmit = e => {e.preventDefault(); run(() => enroll(e.target, false));};
$('add-key').onsubmit = e => {e.preventDefault(); run(() => enroll(e.target, true));};
$('login').onclick = () => run(async () => {
  supported();
  const options = await api('login-options', {});
  const c = await navigator.credentials.get({publicKey: decodeOptions(options.publicKey)});
  if (!c) throw Error('لم يُحدَّد مفتاح مرور.');
  await api('login-finish', {rawId: to64(c.rawId), clientDataJSON: to64(c.response.clientDataJSON), authenticatorData: to64(c.response.authenticatorData), signature: to64(c.response.signature), userHandle: c.response.userHandle ? to64(c.response.userHandle) : null});
  await refresh(); notice('تم تسجيل الدخول بأمان.');
});
$('recovery-panel').onsubmit = e => {e.preventDefault(); const data = Object.fromEntries(new FormData(e.target)); run(async () => {await api('recover', data); e.target.reset(); await refresh(); notice('تم استرداد الدخول. أضف مفتاحًا جديدًا واحذف مفتاح الجهاز المفقود.');});};
$('logout').onclick = () => run(async () => {await api('logout', {}); await refresh(); panel('login'); notice('تم تسجيل الخروج.');});
$('new-codes').onclick = () => {if (confirm('إصدار رموز جديدة يلغي جميع رموز الاسترداد السابقة. متابعة؟')) run(async () => {const r = await api('recovery-codes', {}); displayCodes(r.codes);});};
$('codes-saved').onchange = e => $('close-codes').disabled = !e.target.checked;
$('codes-dialog').addEventListener('cancel', e => e.preventDefault());
$('close-codes').onclick = () => {if (!$('codes-saved').checked) return; $('codes-dialog').close(); savedCodes = []; $('codes').replaceChildren();};
$('download-codes').onclick = () => {
  const blob = new Blob(['هوية — رموز الاسترداد\nاحتفظ بها سرًا. كل رمز يستخدم مرة واحدة.\n\n' + savedCodes.join('\n')], {type: 'text/plain;charset=utf-8'});
  const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'hawiya-recovery-codes.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
};
refresh().catch(e => {show('loading', false); notice(e.message, true);});
$('compatibility').textContent = window.isSecureContext && window.PublicKeyCredential ? '✓ متصفحك يدعم مفاتيح المرور. يلزم إعداد قفل الجهاز.' : 'يلزم متصفح حديث واتصال HTTPS لدعم مفاتيح المرور.';
