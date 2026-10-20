<?php
declare(strict_types=1);
ini_set('display_errors', '0');
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('انسخ private/config.example.php إلى private/config.php واضبط الاتصال ثم استورد database/schema.sql.');
}
$config = require $configFile;
$origin = rtrim($config['origin'], '/');
$originParts = parse_url($origin);
if (!$originParts || isset($originParts['path']) || isset($originParts['query']) || isset($originParts['fragment']) || isset($originParts['user']) || !in_array($originParts['scheme'] ?? '', ['http', 'https'], true)) {
    http_response_code(503);
    exit('origin يجب أن يتضمن البروتوكول والنطاق والمنفذ إن وجد فقط.');
}
if (parse_url($origin, PHP_URL_HOST) !== $config['rp_id'] || (parse_url($origin, PHP_URL_SCHEME) !== 'https' && $config['rp_id'] !== 'localhost')) {
    http_response_code(503);
    exit('إعداد origin أو rp_id غير صحيح. استخدم HTTPS أو localhost للتطوير.');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
session_set_cookie_params(['httponly' => true, 'secure' => str_starts_with($origin, 'https://'), 'samesite' => 'Strict', 'path' => '/']);
ini_set('session.use_strict_mode', '1');
session_start();
if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
spl_autoload_register(function ($class) {
    $prefix = 'lbuchs\\WebAuthn\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/webauthn/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }
});
function db(): PDO {
    global $config;
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4', $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function query(string $sql, array $args = []): PDOStatement {
    $q = db()->prepare($sql);
    $q->execute($args);
    return $q;
}
function fail(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function user(): array {
    $u = query('SELECT id, username, display_name, created_at FROM users WHERE id=?', [$_SESSION['uid'] ?? ''])->fetch();
    if (!$u) fail('سجّل الدخول أولًا.', 401);
    return $u;
}
function fresh(): void {
    if (time() - ($_SESSION['verified'] ?? 0) > 300) fail('أعد تسجيل الدخول قبل تعديل مفاتيح المرور.', 403);
}
function b64(string $value): string {
    if (strlen($value) > 200000 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) throw new InvalidArgumentException('Invalid encoding');
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) throw new InvalidArgumentException('Invalid encoding');
    return $decoded;
}
function checkClient(string $raw, string $type): void {
    global $origin;
    $d = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (($d['origin'] ?? '') !== $origin || ($d['type'] ?? '') !== $type || !empty($d['crossOrigin'])) throw new RuntimeException('Invalid client origin');
}
function pending(string $type): array {
    $p = $_SESSION['pending'] ?? null;
    unset($_SESSION['pending']);
    if (!$p || $p['type'] !== $type || time() > $p['expires']) throw new RuntimeException('Expired or reused challenge');
    return $p;
}
function rate(string $action): void {
    $key = hash('sha256', $action . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $now = time();
    query('INSERT INTO rate_limits (bucket,attempts,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(expires_at < ?,1,attempts+1), expires_at=IF(expires_at < ?,?,expires_at)', [$key, $now + 900, $now, $now, $now + 900]);
    if ((int) query('SELECT attempts FROM rate_limits WHERE bucket=?', [$key])->fetchColumn() > 30) fail('محاولات كثيرة. انتظر 15 دقيقة.', 429);
    if (random_int(1, 100) === 1) query('DELETE FROM rate_limits WHERE expires_at < ?', [$now]);
}
function recovery(string $uid): array {
    query('DELETE FROM recovery_codes WHERE user_id=?', [$uid]);
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $code = strtoupper(bin2hex(random_bytes(12)));
        query('INSERT INTO recovery_codes (user_id,code_hash) VALUES (?,?)', [$uid, hash('sha256', $code)]);
        $codes[] = implode('-', str_split($code, 6));
    }
    return $codes;
}
