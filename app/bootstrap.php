<?php
declare(strict_types=1);

const SERVICES = [
    'Готовим к проверкам МЧС России под ключ',
    'Аудит в области пожарной безопасности',
    'Пожарная сигнализация', 'Оповещение и эвакуация', 'Пожаротушение',
    'Обслуживание и ремонт', 'Нужна консультация',
];

final class HttpError extends RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

function config(): array {
    static $config;
    if ($config !== null) return $config;
    $path = getenv('ADM_CONFIG_FILE') ?: __DIR__ . '/config.php';
    if (!is_file($path)) throw new HttpError(503, 'Сервер ещё не настроен. Заполните app/config.php по инструкции установки.');
    $config = require $path;
    if (!is_array($config)) throw new RuntimeException('Invalid configuration');
    date_default_timezone_set($config['timezone'] ?? 'Asia/Novosibirsk');
    return $config;
}

function https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function local_dev(): bool {
    return !empty(config()['development']) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function boot_session(): void {
    if (!https() && !local_dev()) throw new HttpError(426, 'Для входа и отправки заявок включите HTTPS на домене.');
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_name('ADMSESSID');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function post_guard(array $data): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new HttpError(405, 'Используйте POST.');
    $origin = parse_url($_SERVER['HTTP_ORIGIN'] ?? '');
    $expected = parse_url((https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
    if (!$origin || !$expected || ($origin['host'] ?? '') !== ($expected['host'] ?? '') ||
        ($origin['scheme'] ?? '') !== ($expected['scheme'] ?? '') || ($origin['port'] ?? null) !== ($expected['port'] ?? null)) {
        throw new HttpError(403, 'Обновите страницу и повторите действие.');
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf'] ?? '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) throw new HttpError(403, 'Сессия формы устарела. Обновите страницу.');
}
function read_json(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 18000) throw new HttpError(413, 'Слишком большой запрос.');
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) throw new HttpError(415, 'Ожидается JSON.');
    $raw = file_get_contents('php://input', false, null, 0, 18001);
    if (strlen($raw ?: '') > 18000) throw new HttpError(413, 'Слишком большой запрос.');
    try { $data = json_decode($raw ?: '', true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new HttpError(400, 'Не удалось прочитать форму.'); }
    if (!is_array($data) || !str_starts_with(ltrim($raw ?: ''), '{')) throw new HttpError(400, 'Неверный формат формы.');
    return $data;
}
function database(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $db = config()['db'];
    if (($db['driver'] ?? '') === 'sqlite' && !empty(config()['development'])) {
        $pdo = new PDO('sqlite:' . $db['path']);
        $pdo->exec('PRAGMA busy_timeout=5000');
    } else {
        if (empty($db['database']) || empty($db['username'])) throw new HttpError(503, 'Заполните подключение MySQL в app/config.php.');
        $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . (int)($db['port'] ?? 3306) . ';dbname=' . $db['database'] . ';charset=utf8mb4', $db['username'], $db['password']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $tables = [
        'adm_admin' => 'id INTEGER PRIMARY KEY, login VARCHAR(64) NOT NULL, password_hash VARCHAR(255) NOT NULL, auth_version INTEGER NOT NULL DEFAULT 1',
        'adm_settings' => 'k VARCHAR(64) PRIMARY KEY, v TEXT NOT NULL',
        'adm_leads' => "id VARCHAR(32) PRIMARY KEY, request_key VARCHAR(64) NOT NULL UNIQUE, payload_hash VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, phone VARCHAR(25) NOT NULL, service VARCHAR(120) NOT NULL, message TEXT NOT NULL, created_at VARCHAR(19) NOT NULL, mail_status VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, attempt_at VARCHAR(19), last_error VARCHAR(40) NOT NULL DEFAULT '', processing_status VARCHAR(20) NOT NULL DEFAULT 'new'",
        'adm_traffic' => 'id VARCHAR(32) PRIMARY KEY, visitor VARCHAR(64) NOT NULL, day VARCHAR(10) NOT NULL, created_at VARCHAR(19) NOT NULL, referrer VARCHAR(180) NOT NULL, device VARCHAR(20) NOT NULL, path VARCHAR(180) NOT NULL',
        'adm_limits' => 'k VARCHAR(64) PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL',
    ];
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    foreach ($tables as $name => $definition) $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($definition)" . $suffix);
    // Portable index creation, once per installation, with a fixed application namespace.
    $indexMarker = $pdo->query("SELECT v FROM adm_settings WHERE k='schema_v1'")->fetchColumn();
    if (!$indexMarker) {
        foreach (['CREATE INDEX adm_traffic_day ON adm_traffic(day)', 'CREATE INDEX adm_leads_created ON adm_leads(created_at)'] as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {
                if (!str_contains(strtolower($e->getMessage()), 'exist') && !str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e;
            }
        }
        try { $pdo->exec("INSERT INTO adm_settings(k,v) VALUES('schema_v1','1')"); } catch (PDOException) { /* A parallel request may have completed initialization. */ }
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement { $s = database()->prepare($sql); $s->execute($params); return $s; }
function installed(): bool { return (bool)query('SELECT id FROM adm_admin WHERE id=1')->fetchColumn(); }
function utc_now(): string { return gmdate('Y-m-d H:i:s'); }
function master_key(): string {
    $dir = config()['storage'];
    $public = realpath(dirname(__DIR__) . '/public');
    if ($public && str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', $public) . '/')) throw new RuntimeException('Storage must be private');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Storage unavailable');
    $path = $dir . '/master.key';
    if (!is_file($path)) {
        $fp = @fopen($path, 'x');
        if ($fp) { fwrite($fp, random_bytes(32)); fclose($fp); @chmod($path, 0600); }
    }
    $key = file_get_contents($path);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('Encryption key unavailable');
    return $key;
}
function seal(string $value): string {
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', master_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Encryption failed');
    return base64_encode($iv . $tag . $cipher);
}
function unseal(string $value): string {
    $data = base64_decode($value, true);
    if ($data === false || strlen($data) < 28) throw new RuntimeException('Invalid encrypted value');
    $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', master_key(), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
    if ($plain === false) throw new RuntimeException('Unable to decrypt');
    return $plain;
}
function client_hash(string $scope): string { return hash_hmac('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''), master_key()); }
function rate_limit(string $scope, int $max, int $seconds): void {
    // Changing User-Agent must not reset login or form-abuse limits.
    $key = hash_hmac('sha256', 'rate|' . $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), master_key()); $now = time();
    try { query('INSERT INTO adm_limits(k,hits,reset_at) VALUES(?,0,?)', [$key, $now + $seconds]); } catch (PDOException $e) {
        if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) throw $e;
    }
    query('UPDATE adm_limits SET hits=0, reset_at=? WHERE k=? AND reset_at<?', [$now + $seconds, $key, $now]);
    $s = query('UPDATE adm_limits SET hits=hits+1 WHERE k=? AND hits<?', [$key, $max]);
    if (!$s->rowCount()) throw new HttpError(429, 'Слишком много попыток. Повторите позже.');
}
function admin(): array {
    if (empty($_SESSION['admin']) || time() - ($_SESSION['seen'] ?? 0) > 1800 || time() - ($_SESSION['login_at'] ?? 0) > 43200) throw new HttpError(401, 'Войдите в панель администратора.');
    $row = query('SELECT * FROM adm_admin WHERE id=1')->fetch();
    if (!$row || $row['auth_version'] != ($_SESSION['auth_version'] ?? 0)) throw new HttpError(401, 'Сессия завершена. Войдите снова.');
    $_SESSION['seen'] = time();
    return $row;
}
function login_session(array $row): void {
    session_regenerate_id(true);
    $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'admin' => 1, 'auth_version' => (int)$row['auth_version'], 'login_at' => time(), 'seen' => time()];
}
function setting(string $key, mixed $default = null): mixed {
    $value = query('SELECT v FROM adm_settings WHERE k=?', [$key])->fetchColumn();
    return $value === false ? $default : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
}
function save_setting(string $key, mixed $value): void {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (database()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') query('INSERT INTO adm_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=?', [$key, $json, $json]);
    else query('INSERT INTO adm_settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$key, $json]);
}
function mail_settings(): array {
    return setting('mail', ['enabled' => false, 'host' => '', 'port' => 465, 'encryption' => 'ssl', 'username' => '', 'password_cipher' => '', 'from_email' => '', 'from_name' => 'АДМ-СЕРВИС', 'to_email' => '']);
}
function save_mail_settings(array $d): array {
    $old = mail_settings();
    $s = [];
    foreach (['host' => 253, 'username' => 254, 'from_email' => 254, 'to_email' => 254, 'from_name' => 100] as $k => $max) {
        $v = $d[$k] ?? '';
        if (!is_string($v) || strlen($v) > $max || preg_match('/[\r\n\x00]/', $v)) throw new HttpError(422, 'Проверьте поля почтовых настроек.');
        $s[$k] = trim($v);
    }
    $s['enabled'] = ($d['enabled'] ?? false) === true;
    $s['port'] = filter_var($d['port'] ?? 0, FILTER_VALIDATE_INT);
    $s['encryption'] = $d['encryption'] ?? '';
    if (!$s['port'] || $s['port'] < 1 || $s['port'] > 65535 || !in_array($s['encryption'], ['ssl', 'tls'], true)) throw new HttpError(422, 'Укажите порт и защищённое соединение TLS или STARTTLS.');
    if ($s['host'] && !preg_match('/^[a-zA-Z0-9.-]+$/D', $s['host'])) throw new HttpError(422, 'Укажите имя SMTP-сервера без протокола и пути.');
    foreach (['from_email', 'to_email'] as $key) if ($s[$key] !== '' && !filter_var($s[$key], FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'Проверьте адрес отправителя и получателя.');
    $password = $d['password'] ?? '';
    if (!is_string($password) || strlen($password) > 1024 || str_contains($password, "\0")) throw new HttpError(422, 'Проверьте пароль SMTP.');
    $s['password_cipher'] = $password !== '' ? seal($password) : $old['password_cipher'];
    if (($d['clear_password'] ?? false) === true) $s['password_cipher'] = '';
    if ($s['enabled'] && (!$s['host'] || !$s['username'] || !$s['password_cipher'] || !$s['from_email'] || !$s['to_email'])) throw new HttpError(422, 'Для включения отправки заполните все почтовые настройки и пароль.');
    save_setting('mail', $s);
    return public_mail_settings($s);
}
function public_mail_settings(array $s): array { $s['password_saved'] = !empty($s['password_cipher']); unset($s['password_cipher']); return $s; }
function smtp_send(string $subject, string $body): void {
    $s = mail_settings();
    if (!$s['enabled']) throw new RuntimeException('mail_unconfigured');
    // These maintained, vendored classes avoid a custom SMTP implementation.
    require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
    require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';
    require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP(); $mailer->Host = $s['host']; $mailer->Port = $s['port'];
    $loopbackHosts = config()['smtp_loopback_hosts'] ?? [];
    if (is_array($loopbackHosts) && in_array(strtolower($s['host']), $loopbackHosts, true)) {
        // Route this hosting's mail server locally while checking its real TLS identity.
        $mailer->Host = '127.0.0.1';
        $mailer->SMTPOptions = ['ssl' => ['peer_name' => $s['host'], 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
    }
    $mailer->SMTPAuth = true; $mailer->Username = $s['username']; $mailer->Password = unseal($s['password_cipher']);
    $mailer->SMTPSecure = $s['encryption'];
    // Retain only a fixed connection error category, never the SMTP transcript.
    $connectionHint = '';
    $mailer->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;
    $mailer->Debugoutput = static function (string $line, int $level) use (&$connectionHint): void {
        if ($level !== PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION) return;
        $line = strtolower($line);
        foreach (['certificate verify failed' => 'certificate', 'peer certificate' => 'certificate', 'getaddrinfo' => 'dns', 'php_network_getaddresses' => 'dns', 'timed out' => 'timeout', 'connection refused' => 'refused', 'permission denied' => 'permission', 'network is unreachable' => 'network'] as $text => $category) {
            if (str_contains($line, $text)) $connectionHint = $category;
        }
    };
    $mailer->Timeout = 8; $mailer->getSMTPInstance()->Timelimit = 10; $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($s['from_email'], $s['from_name']); $mailer->addAddress($s['to_email']);
    $mailer->Subject = $subject; $mailer->Body = $body; $mailer->isHTML(false);
    try {
        if (!$mailer->send()) throw new RuntimeException('mail_failed');
    } catch (Throwable $error) {
        if ($connectionHint !== '') throw new RuntimeException('mail_connection_' . $connectionHint);
        throw $error;
    }
}
function validate_lead(array $d): array {
    $out = [];
    foreach (['name' => 100, 'phone' => 25, 'service' => 120, 'message' => 2000] as $key => $max) {
        if (!is_string($d[$key] ?? '')) throw new HttpError(422, 'Проверьте поля заявки.');
        $out[$key] = trim($d[$key] ?? '');
        if (preg_match_all('/./us', $out[$key]) > $max || str_contains($out[$key], "\0")) throw new HttpError(422, 'Слишком длинное или некорректное поле.');
    }
    if (!$out['name'] || preg_match('/[\r\n]/', $out['name'])) throw new HttpError(422, 'Укажите имя.');
    if (!preg_match('/^[+0-9 ()-]{10,25}$/D', $out['phone']) || strlen(preg_replace('/\D/', '', $out['phone'])) < 10 || strlen(preg_replace('/\D/', '', $out['phone'])) > 15) throw new HttpError(422, 'Проверьте телефон.');
    if (!in_array($out['service'], SERVICES, true)) throw new HttpError(422, 'Выберите услугу из списка.');
    if (!in_array($d['consent'] ?? null, [true, 'on'], true)) throw new HttpError(422, 'Подтвердите согласие на обработку обращения.');
    return $out;
}
function deliver_lead(string $id): string {
    $s = mail_settings();
    if (!$s['enabled']) { query("UPDATE adm_leads SET mail_status='unconfigured' WHERE id=? AND mail_status<>'sent'", [$id]); return 'unconfigured'; }
    $now = utc_now();
    $claim = query("UPDATE adm_leads SET mail_status='sending', attempts=attempts+1, attempt_at=?, last_error='' WHERE id=? AND mail_status IN ('pending','failed','unconfigured','unknown')", [$now, $id]);
    if (!$claim->rowCount()) return (string)query('SELECT mail_status FROM adm_leads WHERE id=?', [$id])->fetchColumn();
    $lead = query('SELECT * FROM adm_leads WHERE id=?', [$id])->fetch();
    try {
        smtp_send('Заявка АДМ-СЕРВИС · ' . substr($id, 0, 8), "Имя: {$lead['name']}\nТелефон: {$lead['phone']}\nУслуга: {$lead['service']}\n\n{$lead['message']}\n\nНомер: $id\nВремя UTC: {$lead['created_at']}");
        $status = 'sent'; $error = '';
    } catch (Throwable) { $status = 'failed'; $error = 'smtp_failed'; }
    query('UPDATE adm_leads SET mail_status=?, last_error=? WHERE id=?', [$status, $error, $id]);
    return $status;
}
function track_visit(array $d): void {
    if (!installed() || !empty($_SESSION['admin']) || ($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') return;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawler|spider|headless/i', $ua)) return;
    rate_limit('track', 40, 60);
    $path = is_string($d['path'] ?? null) ? strtok($d['path'], '?#') : '/';
    if (!str_starts_with($path ?: '', '/') || strlen($path ?: '') > 180 || str_contains($path, 'admin')) return;
    $ref = is_string($d['referrer'] ?? null) ? (parse_url($d['referrer'], PHP_URL_HOST) ?: '') : '';
    if (strtolower($ref) === strtolower($_SERVER['SERVER_NAME'] ?? '')) $ref = '';
    $ref = substr(preg_replace('/[^a-zA-Z0-9.-]/', '', $ref), 0, 180);
    $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : (preg_match('/mobile|android|iphone/i', $ua) ? 'mobile' : 'desktop');
    query('INSERT INTO adm_traffic(id,visitor,day,created_at,referrer,device,path) VALUES(?,?,?,?,?,?,?)', [bin2hex(random_bytes(16)), client_hash('visitor'), date('Y-m-d'), utc_now(), $ref ?: 'Прямой переход', $device, $path]);
    // Ninety days of detailed analytics; no raw IPs, full user agents or query strings.
    if (setting('cleanup_day', '') !== date('Y-m-d')) {
        query('DELETE FROM adm_traffic WHERE day<?', [date('Y-m-d', strtotime('-90 days'))]);
        query('DELETE FROM adm_limits WHERE reset_at<?', [time() - 86400]);
        save_setting('cleanup_day', date('Y-m-d'));
    }
}
function dashboard(int $days): array {
    $days = in_array($days, [7, 30, 90], true) ? $days : 30;
    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $since = (new DateTimeImmutable($start, new DateTimeZone(date_default_timezone_get())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $totals = query('SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM adm_traffic WHERE day>=?', [$start])->fetch();
    $series = [];
    for ($n = $days - 1; $n >= 0; $n--) $series[date('Y-m-d', strtotime("-$n days"))] = ['visitors' => 0, 'views' => 0];
    foreach (query('SELECT day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM adm_traffic WHERE day>=? GROUP BY day ORDER BY day', [$start])->fetchAll() as $row) $series[$row['day']] = ['views' => (int)$row['views'], 'visitors' => (int)$row['visitors']];
    return ['days' => $days, 'timezone' => date_default_timezone_get(), 'views' => (int)$totals['views'], 'visitors' => (int)$totals['visitors'],
        'leads' => (int)query('SELECT COUNT(*) FROM adm_leads WHERE created_at>=?', [$since])->fetchColumn(),
        'mail_pending' => (int)query("SELECT COUNT(*) FROM adm_leads WHERE mail_status<>'sent'")->fetchColumn(),
        'series' => $series,
        'sources' => query('SELECT referrer AS label, COUNT(*) AS value FROM adm_traffic WHERE day>=? GROUP BY referrer ORDER BY value DESC LIMIT 10', [$start])->fetchAll(),
        'devices' => query('SELECT device AS label, COUNT(*) AS value FROM adm_traffic WHERE day>=? GROUP BY device ORDER BY value DESC', [$start])->fetchAll()];
}
