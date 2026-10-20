<?php require __DIR__ . '/private/bootstrap.php'; ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0e3834">
<title>هوية — دخول بمفتاح المرور</title>
<link rel="stylesheet" href="assets/app.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="top"><a class="brand" href="./"><span class="brand-mark">هـ</span> هوية<span class="brand-en">HAWIYA</span></a><span class="secure">اتصالك. هويتك. أمانك.</span></header>
<main>
<section id="auth" class="auth-layout" hidden>
<aside class="intro">
<span class="eyebrow">دخول أبسط، حماية أقوى</span>
<h1>هويتك معك.<br><em>كلمة السر؟</em><br>خليها للماضي.</h1>
<p>أنشئ حسابك، واربط مفتاح مرور بجهازك. في المرة القادمة، خطوة واحدة من هاتفك تكفي للدخول.</p>
<div class="orb" aria-hidden="true"><svg viewBox="0 0 120 120" fill="none"><path d="M28 60c0-44 64-44 64 0v13M39 70V59c0-29 42-29 42 0v22M50 87V59c0-14 20-14 20 0v32M60 60v42M27 75v7M39 86v10M92 86v6M39 30c13-10 30-10 43 0"/></svg></div>
<div class="privacy"><span>✓</span><div><strong>بياناتك الحيوية تظل على جهازك</strong><small>نحفظ مفتاح التحقق العام فقط. لا تُرفع بصمة إصبعك أو بيانات وجهك.</small></div></div>
</aside>
<section class="card entry">
<div class="tabs" role="tablist" aria-label="الحساب"><button id="login-tab" role="tab" aria-selected="true">تسجيل الدخول</button><button id="register-tab" role="tab" aria-selected="false">حساب جديد</button></div>
<div id="login-panel"><span class="eyebrow dark">أهلًا بعودتك</span><h2>دخولك، بلمسة.</h2><p class="muted">استخدم مفتاح المرور المحفوظ على هاتفك أو جهازك.</p><button id="login" class="primary wide">الدخول بالبصمة أو Face ID <span>←</span></button><div class="note">طريقة التحقق يحددها جهازك، وقد تشمل بصمة الإصبع أو الوجه أو رمز قفل الجهاز.</div><button id="show-recover" class="text-button">فقدت الوصول إلى مفتاح المرور؟</button></div>
<form id="register-panel" hidden><span class="eyebrow dark">خطوتك الأولى</span><h2>حساب جديد. بلا كلمة سر.</h2><label>الاسم الكامل<input name="name" autocomplete="name" minlength="2" maxlength="100" required placeholder="اسمك كما تحب أن يظهر"></label><label>اسم المستخدم<input name="username" dir="ltr" autocomplete="username webauthn" pattern="[a-zA-Z0-9_.\-]{3,60}" minlength="3" maxlength="60" required placeholder="your.name"><small>حروف إنجليزية وأرقام، ويمكن استخدام . _ -</small></label><label>اسم هذا المفتاح<input name="label" maxlength="80" required value="هاتفي الشخصي"></label><button class="primary wide">إنشاء الحساب وربط مفتاح المرور <span>←</span></button><p class="note">سيطلب جهازك تأكيد هويتك وحفظ مفتاح المرور. لا يتم إنشاء الحساب قبل نجاح التحقق.</p></form>
<form id="recovery-panel" hidden><span class="eyebrow dark">استعادة الوصول</span><h2>ارجع لحسابك بأمان.</h2><label>اسم المستخدم<input name="username" dir="ltr" autocomplete="username" required maxlength="60"></label><label>رمز الاسترداد<input name="code" dir="ltr" autocomplete="off" required maxlength="40" placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX"></label><button class="primary wide">استخدام رمز الاسترداد</button><p class="note">كل رمز يُستخدم مرة واحدة. بعد الدخول، أضف مفتاحًا جديدًا واحذف مفتاح الجهاز المفقود.</p><button type="button" id="back-login" class="text-button">العودة لتسجيل الدخول</button></form>
<div id="compatibility" class="compatibility">جارٍ فحص دعم الجهاز…</div>
</section>
</section>
<section id="dashboard" hidden>
<div class="welcome"><div><span class="eyebrow dark">مساحتك الآمنة</span><h1 id="greeting"></h1><p class="muted">إدارة حسابك ومفاتيح المرور من مكان واحد.</p></div><button id="logout" class="secondary">تسجيل الخروج ↗</button></div>
<div class="dashboard-grid"><section class="card profile"><div class="avatar" id="avatar"></div><span class="pill">حساب بمفتاح مرور</span><h2 id="profile-name"></h2><p id="profile-username" class="muted" dir="ltr"></p><dl><div><dt>تاريخ الانضمام</dt><dd id="joined"></dd></div><div><dt>طريقة التحقق</dt><dd>عبر جهازك</dd></div><div><dt>تخزين البصمة</dt><dd>داخل الجهاز فقط</dd></div></dl><p class="note">لا يستقبل الموقع صورة إصبع أو بيانات وجه، ولا يمكنه تحديد أي إصبع استُخدم.</p></section><section class="card keys"><div class="section-head"><div><span class="eyebrow dark">التحكم في الوصول</span><h2>مفاتيح المرور</h2></div><span id="key-count" class="count"></span></div><p class="muted">أضف مفتاحًا احتياطيًا حتى لا تفقد الوصول إلى حسابك.</p><div id="keys"></div><form id="add-key"><label>اسم المفتاح الجديد<input name="label" maxlength="80" required placeholder="مثل: آيفون العمل"></label><button class="primary">+ إضافة مفتاح مرور</button></form><div class="recovery-box"><div><h3>رموز الاسترداد</h3><p class="muted">احتفظ بها في مكان آمن خارج هذا الجهاز.</p></div><button id="new-codes" class="secondary">إصدار رموز جديدة</button></div></section></div>
</section>
<div id="loading" class="initial"><span class="spinner"></span> جارٍ تجهيز حسابك…</div>
</main>
<footer><span>هوية © 2026</span><span>مبني على معيار WebAuthn / FIDO2</span></footer>
<div id="toast" role="status" aria-live="polite" hidden></div>
<dialog id="codes-dialog"><div class="dialog-inner"><span class="eyebrow dark">احفظها الآن</span><h2>مفاتيح العودة لحسابك.</h2><p class="muted">هذه الرموز لن تُعرض مرة أخرى. كل رمز يُستخدم مرة واحدة. الرموز الجديدة تلغي أي رموز سابقة.</p><div id="codes" dir="ltr"></div><button id="download-codes" class="secondary wide">تنزيل الرموز كملف نصي</button><label class="check"><input type="checkbox" id="codes-saved"> احتفظت بالرموز في مكان آمن</label><button id="close-codes" class="primary wide" disabled>متابعة إلى حسابي</button></div></dialog>
</body>
</html>
