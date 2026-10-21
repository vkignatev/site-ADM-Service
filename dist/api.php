<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    boot_session();
    database();
    $action = $_GET['action'] ?? 'status';
    $mutating = in_array($action, ['setup', 'login', 'logout', 'password', 'request', 'track', 'mail-save', 'mail-test', 'lead-retry', 'lead-status'], true);
    if ($mutating && $_SERVER['REQUEST_METHOD'] !== 'POST') throw new HttpError(405, 'Используйте POST.');
    $data = $mutating ? read_json() : [];
    if ($mutating) post_guard($data);

    switch ($action) {
        case 'status':
            json_response(['formEnabled' => installed(), 'csrf' => $_SESSION['csrf']]);
        case 'me':
            $user = null;
            try { $user = admin(); } catch (HttpError $e) { if ($e->status !== 401) throw $e; }
            json_response(['installed' => installed(), 'authenticated' => (bool)$user, 'login' => $user['login'] ?? null,
                'csrf' => $_SESSION['csrf'], 'localSetup' => local_dev()]);
        case 'setup':
            if (installed()) throw new HttpError(409, 'Администратор уже создан. Войдите в панель.');
            rate_limit('setup', 5, 900);
            $key = config()['install_key'] ?? '';
            if (!local_dev() && (strlen($key) < 32 || !is_string($data['install_key'] ?? null) || !hash_equals($key, $data['install_key']))) throw new HttpError(403, 'Неверный ключ первичной установки.');
            $login = $data['login'] ?? '';
            $password = $data['password'] ?? '';
            if (!is_string($login) || !preg_match('/^[a-zA-Z0-9._-]{3,64}$/D', $login)) throw new HttpError(422, 'Логин: 3–64 латинские буквы, цифры, точка, дефис или подчёркивание.');
            if (!is_string($password) || str_contains($password, "\0") || strlen($password) < 12 || strlen($password) > 72) throw new HttpError(422, 'Пароль должен содержать от 12 до 72 байт.');
            try { query('INSERT INTO adm_admin(id,login,password_hash,auth_version) VALUES(1,?,?,1)', [$login, password_hash($password, PASSWORD_DEFAULT)]); }
            catch (PDOException $e) { if (installed()) throw new HttpError(409, 'Администратор уже создан.'); throw $e; }
            login_session(query('SELECT * FROM adm_admin WHERE id=1')->fetch());
            json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        case 'login':
            rate_limit('login', 5, 900);
            $row = query('SELECT * FROM adm_admin WHERE id=1')->fetch();
            $login = $data['login'] ?? ''; $password = $data['password'] ?? '';
            if (!is_string($login) || !is_string($password) || strlen($password) > 72 || !$row || !hash_equals($row['login'], $login) || !password_verify($password, $row['password_hash'])) throw new HttpError(401, 'Неверный логин или пароль.');
            login_session($row);
            json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        case 'password':
            $user = admin();
            rate_limit('password', 5, 900);
            $old = $data['old_password'] ?? ''; $new = $data['new_password'] ?? '';
            if (!is_string($old) || !password_verify($old, $user['password_hash'])) throw new HttpError(422, 'Текущий пароль неверен.');
            if (!is_string($new) || str_contains($new, "\0") || strlen($new) < 12 || strlen($new) > 72) throw new HttpError(422, 'Новый пароль: от 12 до 72 байт.');
            query('UPDATE adm_admin SET password_hash=?, auth_version=auth_version+1 WHERE id=1', [password_hash($new, PASSWORD_DEFAULT)]);
            login_session(query('SELECT * FROM adm_admin WHERE id=1')->fetch());
            json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        case 'request':
            if (!installed()) throw new HttpError(503, 'Приём заявок пока не подключён. Позвоните: +7 982 412 11 59.');
            if (!empty($data['website'])) throw new HttpError(422, 'Не удалось принять форму. Позвоните: +7 982 412 11 59.');
            $lead = validate_lead($data);
            $key = $data['request_id'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9-]{32,64}$/D', $key)) throw new HttpError(422, 'Обновите страницу и заполните заявку снова.');
            $hash = hash_hmac('sha256', json_encode($lead, JSON_UNESCAPED_UNICODE), master_key());
            $existing = query('SELECT * FROM adm_leads WHERE request_key=?', [$key])->fetch();
            if ($existing) {
                if (!hash_equals($existing['payload_hash'], $hash)) throw new HttpError(409, 'Данные формы изменились. Обновите страницу.');
                json_response(['ok' => true, 'id' => $existing['id'], 'mail_status' => $existing['mail_status'], 'message' => 'Заявка уже сохранена. Повторное обращение не создано.']);
            }
            rate_limit('lead', 5, 900);
            $id = bin2hex(random_bytes(16));
            try { query('INSERT INTO adm_leads(id,request_key,payload_hash,name,phone,service,message,created_at,mail_status) VALUES(?,?,?,?,?,?,?,?,?)', [$id, $key, $hash, $lead['name'], $lead['phone'], $lead['service'], $lead['message'], utc_now(), 'pending']); }
            catch (PDOException $e) {
                $existing = query('SELECT * FROM adm_leads WHERE request_key=?', [$key])->fetch();
                if (!$existing) throw $e;
                if (!hash_equals($existing['payload_hash'], $hash)) throw new HttpError(409, 'Данные формы изменились. Обновите страницу.');
                json_response(['ok' => true, 'id' => $existing['id'], 'mail_status' => $existing['mail_status'], 'message' => 'Заявка уже сохранена.']);
            }
            session_write_close();
            $status = deliver_lead($id);
            json_response(['ok' => true, 'id' => $id, 'mail_status' => $status, 'message' => 'Заявка принята. Свяжемся с вами по указанному телефону.'], 201);
        case 'track':
            track_visit($data);
            json_response(['ok' => true]);
        case 'dashboard':
            admin();
            json_response(dashboard((int)($_GET['days'] ?? 30)));
        case 'leads':
            admin();
            // A timed-out request must not be shown indefinitely as actively sending.
            query("UPDATE adm_leads SET mail_status='unknown',last_error='delivery_unknown' WHERE mail_status='sending' AND attempt_at<?", [gmdate('Y-m-d H:i:s', time() - 120)]);
            $page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
            $filter = $_GET['status'] ?? '';
            $where = ''; $params = [];
            if (in_array($filter, ['pending', 'sent', 'failed', 'unconfigured', 'sending', 'unknown'], true)) { $where = ' WHERE mail_status=?'; $params[] = $filter; }
            $count = (int)query('SELECT COUNT(*) FROM adm_leads' . $where, $params)->fetchColumn();
            $rows = query('SELECT id,name,phone,service,created_at,mail_status,processing_status,attempts FROM adm_leads' . $where . ' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), $params)->fetchAll();
            json_response(['items' => $rows, 'total' => $count, 'page' => $page, 'pages' => max(1, (int)ceil($count / 20))]);
        case 'lead':
            admin();
            $row = query('SELECT * FROM adm_leads WHERE id=?', [$_GET['id'] ?? ''])->fetch();
            if (!$row) throw new HttpError(404, 'Заявка не найдена.');
            unset($row['request_key'], $row['payload_hash']);
            json_response($row);
        case 'lead-retry':
            admin(); rate_limit('retry', 20, 900);
            $id = $data['id'] ?? '';
            if (!is_string($id) || !query('SELECT id FROM adm_leads WHERE id=?', [$id])->fetchColumn()) throw new HttpError(404, 'Заявка не найдена.');
            session_write_close();
            json_response(['ok' => true, 'mail_status' => deliver_lead($id)]);
        case 'lead-status':
            admin();
            $status = $data['status'] ?? '';
            if (!in_array($status, ['new', 'contacted', 'done'], true) || !is_string($data['id'] ?? null)) throw new HttpError(422, 'Неверный статус заявки.');
            if (!query('SELECT id FROM adm_leads WHERE id=?', [$data['id']])->fetchColumn()) throw new HttpError(404, 'Заявка не найдена.');
            query('UPDATE adm_leads SET processing_status=? WHERE id=?', [$status, $data['id']]);
            json_response(['ok' => true]);
        case 'mail':
            admin(); json_response(public_mail_settings(mail_settings()));
        case 'mail-save':
            admin(); json_response(['ok' => true, 'settings' => save_mail_settings($data)]);
        case 'mail-test':
            admin(); rate_limit('mailtest', 5, 900);
            session_write_close();
            try { smtp_send('Тестовое письмо АДМ-СЕРВИС', 'Настройки почты сайта работают. Это проверочное письмо из панели администратора.'); }
            catch (Throwable) { throw new HttpError(502, 'Письмо не отправлено. Проверьте SMTP-сервер, порт, шифрование, логин и пароль.'); }
            json_response(['ok' => true, 'message' => 'SMTP-сервер принял тестовое письмо. Проверьте ящик получателя, включая папку «Спам».']);
        default:
            throw new HttpError(404, 'Действие не найдено.');
    }
} catch (HttpError $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], $e->status);
} catch (Throwable) {
    // Never return DSNs, SQL, SMTP messages, passwords or stack traces to the browser.
    json_response(['ok' => false, 'message' => 'Сервис временно недоступен. Заявку можно обсудить по телефону +7 982 412 11 59.'], 503);
}
