<?php
declare(strict_types=1);
require __DIR__ . '/private/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $action = $_GET['action'] ?? '';
    if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = isset($_SESSION['uid']) ? user() : null;
        $keys = $u ? query('SELECT id,label,created_at,last_used_at FROM credentials WHERE user_id=? ORDER BY id DESC', [$u['id']])->fetchAll() : [];
        echo json_encode(['csrf' => $_SESSION['csrf'], 'user' => $u, 'keys' => $keys], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('الطريقة غير مسموحة.', 405);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') || ($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin) fail('تعذر التحقق من الطلب. حدّث الصفحة.', 403);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 300000) fail('الطلب أكبر من الحد المسموح.', 413);
    $in = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $wa = new lbuchs\WebAuthn\WebAuthn($config['app_name'], $config['rp_id'], ['none'], true);
    if ($action === 'register-options' || $action === 'add-options') {
        rate('enroll');
        $adding = $action === 'add-options';
        if ($adding) {
            $u = user();
            fresh();
            if ((int) query('SELECT COUNT(*) FROM credentials WHERE user_id=?', [$u['id']])->fetchColumn() >= 10) fail('الحد الأقصى 10 مفاتيح.');
        } else {
            if (isset($_SESSION['uid'])) fail('سجّل الخروج لإنشاء حساب آخر.');
            $username = strtolower(trim((string) ($in['username'] ?? '')));
            $name = trim((string) ($in['name'] ?? ''));
            if (!preg_match('/^[a-z0-9_.-]{3,60}$/D', $username) || mb_strlen($name) < 2 || mb_strlen($name) > 100) fail('الاسم مطلوب، واسم المستخدم من 3 إلى 60 حرفًا إنجليزيًا أو رقمًا أو . _ -');
            if (query('SELECT id FROM users WHERE username=?', [$username])->fetch()) fail('اسم المستخدم مستخدم بالفعل.', 409);
            $u = ['id' => bin2hex(random_bytes(32)), 'username' => $username, 'display_name' => $name];
        }
        $ids = $adding ? query('SELECT credential_id FROM credentials WHERE user_id=?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN) : [];
        $args = $wa->getCreateArgs(hex2bin($u['id']), $u['username'], $u['display_name'], 120, true, true, false, $ids);
        $args->publicKey->attestation = 'none';
        $_SESSION['pending'] = ['type' => $adding ? 'add' : 'register', 'u' => $u, 'challenge' => $wa->getChallenge()->getBinaryString(), 'expires' => time() + 180];
        echo json_encode($args);
        exit;
    }
    if ($action === 'register-finish' || $action === 'add-finish') {
        rate('verify');
        $adding = $action === 'add-finish';
        $p = pending($adding ? 'add' : 'register');
        if ($adding && user()['id'] !== $p['u']['id']) fail('الحساب غير مطابق.', 403);
        if ($adding) fresh();
        $client = b64($in['clientDataJSON'] ?? '');
        checkClient($client, 'webauthn.create');
        $data = $wa->processCreate($client, b64($in['attestationObject'] ?? ''), $p['challenge'], true, true);
        if (!hash_equals($data->credentialId, b64($in['rawId'] ?? ''))) throw new RuntimeException('Credential mismatch');
        $label = trim((string) ($in['label'] ?? 'هاتفي'));
        if ($label === '' || mb_strlen($label) > 80) fail('اسم المفتاح مطلوب وبحد أقصى 80 حرفًا.');
        db()->beginTransaction();
        if (!$adding) query('INSERT INTO users (id,username,display_name) VALUES (?,?,?)', [$p['u']['id'], $p['u']['username'], $p['u']['display_name']]);
        query('INSERT INTO credentials (user_id,credential_hash,credential_id,public_key,sign_count,label) VALUES (?,?,?,?,?,?)', [$p['u']['id'], hash('sha256', $data->credentialId), $data->credentialId, $data->credentialPublicKey, $data->signatureCounter ?? 0, $label]);
        $codes = !$adding ? recovery($p['u']['id']) : [];
        db()->commit();
        session_regenerate_id(true);
        $_SESSION['uid'] = $p['u']['id'];
        $_SESSION['verified'] = time();
        echo json_encode(['ok' => true, 'codes' => $codes]);
        exit;
    }
    if ($action === 'login-options') {
        rate('login');
        $args = $wa->getGetArgs([], 120, true, true, true, true, true, true);
        $_SESSION['pending'] = ['type' => 'login', 'challenge' => $wa->getChallenge()->getBinaryString(), 'expires' => time() + 180];
        echo json_encode($args);
        exit;
    }
    if ($action === 'login-finish') {
        rate('verify');
        $p = pending('login');
        $id = b64($in['rawId'] ?? '');
        db()->beginTransaction();
        $key = query('SELECT * FROM credentials WHERE credential_hash=? FOR UPDATE', [hash('sha256', $id)])->fetch();
        if (!$key || !hash_equals($key['credential_id'], $id)) throw new RuntimeException('Unknown credential');
        if (!isset($in['userHandle']) || !hash_equals(hex2bin($key['user_id']), b64($in['userHandle']))) throw new RuntimeException('Invalid user handle');
        $client = b64($in['clientDataJSON'] ?? '');
        checkClient($client, 'webauthn.get');
        $wa->processGet($client, b64($in['authenticatorData'] ?? ''), b64($in['signature'] ?? ''), $key['public_key'], $p['challenge'], (int) $key['sign_count'], true, true);
        query('UPDATE credentials SET sign_count=?,last_used_at=CURRENT_TIMESTAMP WHERE id=?', [$wa->getSignatureCounter() ?? 0, $key['id']]);
        db()->commit();
        session_regenerate_id(true);
        $_SESSION['uid'] = $key['user_id'];
        $_SESSION['verified'] = time();
    } elseif ($action === 'recover') {
        rate('recovery');
        $code = strtoupper(preg_replace('/[\s-]/', '', (string) ($in['code'] ?? '')));
        if (!preg_match('/^[A-F0-9]{24}$/D', $code)) fail('بيانات الاسترداد غير صحيحة.');
        db()->beginTransaction();
        $r = query('SELECT r.id,r.user_id FROM recovery_codes r JOIN users u ON u.id=r.user_id WHERE u.username=? AND r.code_hash=? FOR UPDATE', [strtolower(trim($in['username'] ?? '')), hash('sha256', $code)])->fetch();
        if (!$r) throw new RuntimeException('Invalid recovery');
        query('DELETE FROM recovery_codes WHERE id=?', [$r['id']]);
        db()->commit();
        session_regenerate_id(true);
        $_SESSION['uid'] = $r['user_id'];
        $_SESSION['verified'] = time();
    } elseif ($action === 'delete-key') {
        $u = user();
        fresh();
        db()->beginTransaction();
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$u['id']]);
        if ((int) query('SELECT COUNT(*) FROM credentials WHERE user_id=?', [$u['id']])->fetchColumn() < 2) {
            db()->rollBack();
            fail('أضف مفتاحًا آخر قبل حذف آخر مفتاح.');
        }
        query('DELETE FROM credentials WHERE id=? AND user_id=?', [(int) ($in['id'] ?? 0), $u['id']]);
        db()->commit();
    } elseif ($action === 'recovery-codes') {
        $u = user();
        fresh();
        db()->beginTransaction();
        $codes = recovery($u['id']);
        db()->commit();
        echo json_encode(['ok' => true, 'codes' => $codes]);
        exit;
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        fail('الطلب غير معروف.', 404);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
    error_log('Passkey portal: ' . $e->getMessage());
    if ($e instanceof PDOException) fail('تعذر إتمام العملية. تحقق من إعداد قاعدة البيانات أو جرّب اسم مستخدم آخر.', 503);
    fail('تعذر التحقق. قد يكون الطلب انتهى أو المفتاح غير مطابق. أعد المحاولة.');
}
