<?php
/* КЛИО · мини-админка (Beget, PHP). Один файл: вход, заявки, рейтинг, цены.
   Данные лежат в api/data (веб-доступ туда закрыт .htaccess). */

$DATA_DIR = dirname(__DIR__) . '/api/data';
$API_DIR  = dirname(__DIR__) . '/api';
$F_ADMIN  = $DATA_DIR . '/admin.json';
$F_LEADS  = $DATA_DIR . '/leads.jsonl';
$F_STATUS = $DATA_DIR . '/status.json';
$F_ATTEMPTS = $DATA_DIR . '/login_attempts.json';
$F_RATING = $API_DIR . '/rating.json';
$F_CONTENT = $API_DIR . '/content.json';
$IMG_DIR  = dirname(__DIR__) . '/img';

/* 8 карточек категорий на главной (порядок фиксированный, key = исходное имя файла, не меняется) */
$CARDS_DEF = [
    ['title'=>'Парикмахерский зал', 'key'=>'klio-strizhka-brashing.webp'],
    ['title'=>'Маникюр',            'key'=>'klio-manikyur-gel-lak.webp'],
    ['title'=>'Педикюр',            'key'=>'klio-pedikyur-spa.webp'],
    ['title'=>'Массаж',             'key'=>'klio-massazh-spiny.webp'],
    ['title'=>'Косметология',       'key'=>'klio-kosmetologiya-maska.webp'],
    ['title'=>'Макияж и брови',     'key'=>'klio-brovi-korrekciya.webp'],
    ['title'=>'Солярий',            'key'=>'klio-solyariy.webp'],
    ['title'=>'СПА-программы',      'key'=>'klio-spa-programmy.jpg'],
];

/* текущие карточки: дефолт + подхватываем заменённые фото и ПОРЯДОК из content.json (по key) */
function cards_current($content){
    global $CARDS_DEF;
    $byKey = [];
    foreach ($CARDS_DEF as $def) $byKey[$def['key']] = $def;
    $saved = [];
    foreach (($content['cards'] ?? []) as $c) {
        if (!empty($c['key'])) $saved[$c['key']] = $c;
    }
    $out = [];
    $used = [];
    /* сначала — в порядке, сохранённом в content.json */
    foreach (($content['cards'] ?? []) as $c) {
        $k = $c['key'] ?? '';
        if (!isset($byKey[$k]) || isset($used[$k])) continue;
        $used[$k] = true;
        $out[] = [
            'title' => $byKey[$k]['title'],
            'key'   => $k,
            'img'   => !empty($c['img']) ? $c['img'] : '/img/' . $k,
        ];
    }
    /* затем — карточки из дефолта, которых нет в сохранённых */
    foreach ($CARDS_DEF as $def) {
        if (!isset($used[$def['key']])) {
            $s = $saved[$def['key']] ?? [];
            $out[] = [
                'title' => $def['title'],
                'key'   => $def['key'],
                'img'   => !empty($s['img']) ? $s['img'] : '/img/' . $def['key'],
            ];
        }
    }
    return $out;
}

/* загрузка картинки в img/: проверка, сжатие GD до 1000px, очистка старых версий по префиксу.
   Возвращает [true, имя_файла] или [false, текст_ошибки]. */
function save_image_upload($f, $nameBase, $IMG_DIR){
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return [false, 'Файл не загружен. Выберите фото и попробуйте ещё раз.'];
    if ($f['size'] > 8 * 1024 * 1024)
        return [false, 'Файл больше 8 МБ. Уменьшите фото (например, отправьте себе в мессенджере и сохраните сжатым).'];
    $fi = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime = $fi ? finfo_file($fi, $f['tmp_name']) : mime_content_type($f['tmp_name']);
    if ($fi) finfo_close($fi);
    $ext = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'][$mime] ?? null;
    if (!$ext) return [false, 'Нужен файл JPG, PNG или WEBP.'];
    $name = $nameBase . '-' . date('YmdHis') . '.' . $ext;
    $dest = $IMG_DIR . '/' . $name;
    $saved = false;
    $sz = @getimagesize($f['tmp_name']);
    if ($sz && $sz[0] * $sz[1] <= 25000000 && function_exists('imagecreatetruecolor')) {
        $src = null;
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($f['tmp_name']);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $nw = min($w, 1000); $nh = max(1, (int)round($h * $nw / $w));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($ext === 'png') { $white = imagecolorallocate($dst, 255, 255, 255); imagefill($dst, 0, 0, $white); }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if ($ext === 'jpg') $saved = @imagejpeg($dst, $dest, 84);
            elseif ($ext === 'webp') $saved = @imagewebp($dst, $dest, 84);
            else $saved = @imagepng($dst, $dest, 6);
            imagedestroy($dst); imagedestroy($src);
        }
    }
    if (!$saved && !move_uploaded_file($f['tmp_name'], $dest))
        return [false, 'Не удалось сохранить файл на сервере. Сообщите разработчику.'];
    @chmod($dest, 0644);
    /* удаляем предыдущие загруженные версии этой карточки */
    foreach (glob($IMG_DIR . '/' . $nameBase . '-*.*') ?: [] as $old) {
        if (basename($old) !== $name) @unlink($old);
    }
    return [true, $name];
}

/* ---------- сессия ---------- */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('KLIOADM');
session_set_cookie_params(['lifetime'=>0,'path'=>'/admin/','httponly'=>true,'samesite'=>'Strict','secure'=>$secure]);
session_start();
/* страницы админки не кэшируем: после замены фото «Назад» в браузере не должно
   показывать устаревшую копию страницы со старыми изображениями */
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* ---------- помощники ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jread($f, $def){
    if (!file_exists($f)) return $def;
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : $def;
}
function jwrite($f, $data){
    return file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function csrf_token(){
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(){ echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(){
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '_')) { http_response_code(403); exit('CSRF'); }
}
function flash($msg, $ok = true){ $_SESSION['flash'] = [$msg, $ok]; }
function is_auth(){ return !empty($_SESSION['auth']); }
function client_ip(){ return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }
function safe_back($b){
    $b = (string)$b;
    return (strpos($b, 'index.php') === 0) ? $b : 'index.php?p=leads';
}

/* анти-подбор: не более 5 неудачных попыток за 15 минут с одного IP */
function throttle_blocked(){
    global $F_ATTEMPTS;
    $a = jread($F_ATTEMPTS, []);
    $ip = client_ip(); $now = time();
    $list = array_filter($a[$ip] ?? [], function($t) use ($now){ return $now - $t < 900; });
    return count($list) >= 5;
}
function throttle_fail(){
    global $F_ATTEMPTS;
    $a = jread($F_ATTEMPTS, []);
    $ip = client_ip(); $now = time();
    $list = array_filter($a[$ip] ?? [], function($t) use ($now){ return $now - $t < 900; });
    $list[] = $now;
    $a[$ip] = array_values($list);
    jwrite($F_ATTEMPTS, $a);
}
function throttle_clear(){
    global $F_ATTEMPTS;
    $a = jread($F_ATTEMPTS, []);
    unset($a[client_ip()]);
    jwrite($F_ATTEMPTS, $a);
}

/* ---------- данные заявок ---------- */
$STATUSES = ['new'=>'Новая','called'=>'Перезвонили','booked'=>'Записан','done'=>'Готово','cancel'=>'Отмена'];

function load_leads(){
    global $F_LEADS;
    if (!file_exists($F_LEADS)) return [];
    $lines = file($F_LEADS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (count($lines) > 2000) $lines = array_slice($lines, -2000);
    $out = [];
    foreach ($lines as $ln) { $j = json_decode($ln, true); if (is_array($j)) $out[] = $j; }
    return array_reverse($out); /* новые сверху */
}
function lead_status($id, $map){ return $map[$id]['s'] ?? 'new'; }

/* ---------- конфиг админа ---------- */
$admin = jread($F_ADMIN, ['password_hash'=>'','setup_token'=>'']);
$need_setup = empty($admin['password_hash']);

/* ---------- POST-действия ---------- */
$action = $_POST['action'] ?? '';

if ($action === 'setup' && $need_setup) {
    csrf_check();
    if (throttle_blocked()) { flash('Слишком много попыток. Подождите 15 минут.', false); }
    else {
        $tok = trim((string)($_POST['token'] ?? ''));
        $p1  = (string)($_POST['p1'] ?? '');
        $p2  = (string)($_POST['p2'] ?? '');
        if (!hash_equals((string)($admin['setup_token'] ?? ''), $tok)) { throttle_fail(); flash('Неверный код настройки.', false); }
        elseif (strlen($p1) < 8) { flash('Пароль должен быть не короче 8 символов.', false); }
        elseif ($p1 !== $p2) { flash('Пароли не совпадают.', false); }
        else {
            $admin['password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
            unset($admin['setup_token']);
            jwrite($F_ADMIN, $admin);
            throttle_clear();
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            flash('Пароль создан. Добро пожаловать в админку!');
            header('Location: index.php'); exit;
        }
    }
    header('Location: index.php'); exit;
}

if ($action === 'login' && !$need_setup) {
    csrf_check();
    if (throttle_blocked()) { flash('Слишком много попыток. Подождите 15 минут.', false); }
    else {
        $p = (string)($_POST['password'] ?? '');
        if (password_verify($p, (string)$admin['password_hash'])) {
            throttle_clear();
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: index.php'); exit;
        }
        throttle_fail();
        flash('Неверный пароль.', false);
    }
    header('Location: index.php'); exit;
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php'); exit;
}

/* действия ниже — только для авторизованных */
if (is_auth() && !$need_setup && $action !== '') {
    csrf_check();

    if ($action === 'lead_status') {
        $id = (string)($_POST['id'] ?? '');
        $st = (string)($_POST['st'] ?? '');
        if (preg_match('/^klio-[\w-]+$/', $id) && isset($STATUSES[$st])) {
            $map = jread($F_STATUS, []);
            $map[$id] = ['s'=>$st, 't'=>time()];
            jwrite($F_STATUS, $map);
            flash('Статус обновлён: «' . $STATUSES[$st] . '»');
        }
        header('Location: ' . safe_back($_POST['back'] ?? '')); exit;
    }

    if ($action === 'lead_delete') {
        $id = (string)($_POST['id'] ?? '');
        if (preg_match('/^klio-[\w-]+$/', $id) && file_exists($F_LEADS)) {
            $lines = file($F_LEADS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $keep = [];
            foreach ($lines as $ln) {
                $j = json_decode($ln, true);
                if (!is_array($j) || ($j['id'] ?? '') !== $id) $keep[] = $ln;
            }
            file_put_contents($F_LEADS, implode("\n", $keep) . ($keep ? "\n" : ''), LOCK_EX);
            $map = jread($F_STATUS, []);
            unset($map[$id]);
            jwrite($F_STATUS, $map);
            flash('Заявка удалена.');
        }
        header('Location: ' . safe_back($_POST['back'] ?? '')); exit;
    }

    /* ================= ИНТЕГРАЦИЯ DIKIDI → Google Calendar → CRM (ТЗ №4) ================= */
    if (strpos($action, 'gcal_') === 0) {
        require_once $API_DIR . '/gcal-lib.php';
        $cfg = gcal_cfg();

        if ($action === 'gcal_save_keys') {
            $cid = trim((string)($_POST['client_id'] ?? ''));
            $csec = trim((string)($_POST['client_secret'] ?? ''));
            if ($cid === '' || $csec === '') { flash('Укажите Client ID и Client Secret из Google Cloud Console.', false); header('Location: index.php?p=gcal'); exit; }
            $cfg['client_id'] = mb_substr($cid, 0, 200);
            $cfg['client_secret_enc'] = gcal_enc($csec);
            gcal_cfg($cfg);
            flash('Ключи сохранены. Теперь подключите Google-аккаунт.');
            header('Location: index.php?p=gcal'); exit;
        }

        if ($action === 'gcal_oauth_start') {
            if (($cfg['client_id'] ?? '') === '' || ($cfg['client_secret_enc'] ?? '') === '') {
                flash('Сначала сохраните Client ID и Client Secret.', false);
                header('Location: index.php?p=gcal'); exit;
            }
            $cfg['oauth_state'] = bin2hex(random_bytes(16));
            gcal_cfg($cfg);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $redirect = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'myclio.ru') . '/api/gcal-oauth.php';
            $url = rtrim((string)$cfg['auth_url'], '?') . '?' . http_build_query([
                'client_id'     => $cfg['client_id'],
                'redirect_uri'  => $redirect,
                'response_type' => 'code',
                'scope'         => 'https://www.googleapis.com/auth/calendar.readonly', /* ТЗ §8: минимальные права — только чтение */
                'access_type'   => 'offline',
                'prompt'        => 'consent',
                'state'         => $cfg['oauth_state'],
            ]);
            header('Location: ' . $url); exit;
        }

        if ($action === 'gcal_disconnect') {
            $cfg['refresh_token_enc'] = '';
            $cfg['access'] = ['token'=>'', 'exp'=>0];
            $cfg['oauth_state'] = '';
            $cfg['sync_enabled'] = false;
            gcal_cfg($cfg);
            flash('Google-аккаунт отключён, синхронизация остановлена. Сопоставления календарей сохранены.');
            header('Location: index.php?p=gcal'); exit;
        }

        if ($action === 'gcal_refresh_cals') {
            $res = gcal_list_calendars($cfg);
            $cfg = $res[1];
            $list = $res[0];
            if ($list === null) { gcal_cfg($cfg); flash('Не удалось получить список календарей — проверьте подключение аккаунта.', false); header('Location: index.php?p=gcal'); exit; }
            $added = 0;
            foreach ($list as $c) {
                $id = (string)$c['id'];
                if (!isset($cfg['calendars'][$id])) {
                    $cfg['calendars'][$id] = ['name'=>(string)$c['name'], 'spec'=>'', 'enabled'=>false, 'syncToken'=>'', 'first_done'=>false];
                    $added++;
                } else {
                    $cfg['calendars'][$id]['name'] = (string)$c['name'];
                }
            }
            gcal_cfg($cfg);
            flash($added ? "Найдены новые календари: $added. Сопоставьте их специалистам и включите нужные." : 'Список календарей актуален, новых нет.');
            header('Location: index.php?p=gcal'); exit;
        }

        if ($action === 'gcal_save_cals') {
            $specs = $_POST['cal_spec'] ?? [];
            $enab  = $_POST['cal_en'] ?? [];
            $meta  = jread($DATA_DIR . '/manage-meta.json', []);
            $valid = array_map('strval', (array)($meta['specialists'] ?? []));
            foreach ((array)$cfg['calendars'] as $id => $cal) {
                $sp = (string)($specs[$id] ?? '');
                $cfg['calendars'][$id]['spec'] = in_array($sp, $valid, true) ? $sp : '';
                $cfg['calendars'][$id]['enabled'] = !empty($enab[$id]);
            }
            gcal_cfg($cfg);
            flash('Сопоставления календарей сохранены.');
            header('Location: index.php?p=gcal'); exit;
        }

        if ($action === 'gcal_toggle') {
            $cfg['sync_enabled'] = !empty($_POST['on']);
            gcal_cfg($cfg);
            flash($cfg['sync_enabled'] ? 'Синхронизация включена. Проверьте, что настроен запуск раз в минуту (ниже на странице).' : 'Синхронизация выключена.');
            header('Location: index.php?p=gcal'); exit;
        }

        if ($action === 'gcal_now') {
            $st = gcal_sync_run(true);
            if ($st['ok']) {
                flash('Синхронизация выполнена: событий ' . $st['events'] . ', новых ' . $st['created'] .
                      ', обновлено ' . $st['updated'] . ', отменено ' . $st['canceled'] .
                      ($st['conflicts'] ? ', конфликтов ' . $st['conflicts'] : '') .
                      ($st['notices'] ? ', требуют проверки ' . $st['notices'] : '') . '.');
            } else {
                flash('Синхронизация не выполнена: ' . implode('; ', $st['errors']), false);
            }
            header('Location: index.php?p=gcal'); exit;
        }

        if ($action === 'gcal_clear_errors') {
            $cfg['errors'] = [];
            gcal_cfg($cfg);
            flash('Журнал ошибок очищен.');
            header('Location: index.php?p=gcal'); exit;
        }
    }

    if ($action === 'save_rating') {
        $rating = trim((string)($_POST['rating'] ?? ''));
        $count  = (int)($_POST['count'] ?? 0);
        if (preg_match('/^\d[,.]\d$/', $rating) && $count >= 0 && $count < 1000000) {
            $rating = str_replace('.', ',', $rating);
            jwrite($F_RATING, ['rating'=>$rating, 'count'=>$count, 'updated'=>date('Y-m-d')]);
            flash("Рейтинг сохранён: $rating · $count. На сайте и в приложении появится при следующем открытии страницы.");
        } else flash('Проверьте формат: оценка вида «5,0», число оценок — целое.', false);
        header('Location: index.php?p=content'); exit;
    }

    if ($action === 'save_content') {
        $content = jread($F_CONTENT, ['prices'=>[], 'certs'=>[]]);
        foreach (['prices','certs'] as $sec) {
            $cats = $_POST[$sec . '_cat'] ?? [];
            $names = $_POST[$sec . '_name'] ?? [];
            $prices = $_POST[$sec . '_price'] ?? [];
            $strikes = $_POST[$sec . '_strike'] ?? [];
            $oids = $_POST[$sec . '_oid'] ?? [];
            $out = [];
            foreach ($cats as $ci => $catName) {
                /* oid = исходный индекс категории (после перестановки/удаления в браузере отличается от ci) */
                $oid = isset($oids[$ci]) ? (int)$oids[$ci] : $ci;
                $old = ($oid >= 0 && isset($content[$sec][$oid])) ? $content[$sec][$oid] : [];
                $rows = [];
                $nmList = $names[$ci] ?? [];
                if (is_array($nmList)) ksort($nmList);
                foreach ($nmList as $ri => $nm) {
                    $nm = mb_substr(trim((string)$nm), 0, 80);
                    $pr = mb_substr(trim((string)($prices[$ci][$ri] ?? '')), 0, 40);
                    if ($nm === '' && $pr === '') continue;
                    $row = [$nm, $pr];
                    if (!empty($strikes[$ci][$ri]) && (string)$strikes[$ci][$ri] !== '0') $row[] = 'strike';
                    $rows[] = $row;
                }
                $catName = mb_substr(trim((string)$catName), 0, 80);
                if ($catName === '') $catName = (string)($old['cat'] ?? '');
                if ($catName === '' && !$rows) continue; /* пустую новую категорию не добавляем */
                if ($catName === '') $catName = 'Без названия';
                $item = ['cat' => $catName, 'rows' => $rows];
                if (isset($old['hint'])) $item['hint'] = $old['hint'];
                if (isset($old['img']))  $item['img']  = $old['img'];
                $out[] = $item;
            }
            if ($out) $content[$sec] = $out;
        }
        $content['updated'] = date('Y-m-d');
        jwrite($F_CONTENT, $content);
        flash('Цены сохранены. На сайте и в приложении появятся при следующем открытии страницы.');
        header('Location: index.php?p=content'); exit;
    }

    /* замена фото одной карточки категории на главной */
    if ($action === 'save_card') {
        $i = (int)($_POST['card_i'] ?? -1);
        $content = jread($F_CONTENT, ['prices'=>[], 'certs'=>[]]);
        $cards = cards_current($content);
        if ($i < 0 || $i >= count($cards)) {
            flash('Неизвестная карточка.', false);
            header('Location: index.php?p=cards'); exit;
        }
        $keySafe = preg_replace('/[^a-z0-9]+/i', '-', pathinfo((string)$cards[$i]['key'], PATHINFO_FILENAME));
        [$ok, $res] = save_image_upload($_FILES['img'] ?? null, 'klio-card-' . $keySafe, $IMG_DIR);
        if (!$ok) {
            flash($res, false);
            header('Location: index.php?p=cards'); exit;
        }
        $cards[$i]['img'] = '/img/' . $res;
        $content['cards'] = $cards;
        $content['updated'] = date('Y-m-d');
        jwrite($F_CONTENT, $content);
        flash('Фото «' . $cards[$i]['title'] . '» обновлено. На сайте и в приложении появится при следующем открытии страницы.');
        header('Location: index.php?p=cards'); exit;
    }

    /* перестановка карточек категорий на главной (выше/ниже) */
    if ($action === 'move_card') {
        $i = (int)($_POST['card_i'] ?? -1);
        $dir = (int)($_POST['dir'] ?? 0);
        $content = jread($F_CONTENT, ['prices'=>[], 'certs'=>[]]);
        $cards = cards_current($content);
        $j = $i + $dir;
        if ($i >= 0 && $i < count($cards) && $j >= 0 && $j < count($cards)) {
            $t = $cards[$i]; $cards[$i] = $cards[$j]; $cards[$j] = $t;
            $content['cards'] = $cards;
            $content['updated'] = date('Y-m-d');
            jwrite($F_CONTENT, $content);
            flash('Порядок карточек обновлён: «' . $cards[$j]['title'] . '» теперь на месте №' . ($j + 1) . '. На сайте и в приложении — при следующем открытии страницы.');
        }
        header('Location: index.php?p=cards'); exit;
    }

    /* замена фото карточки цен (раздел «Цены на услуги» на сайте) */
    if ($action === 'save_pcard') {
        $i = (int)($_POST['pcard_i'] ?? -1);
        $content = jread($F_CONTENT, ['prices'=>[], 'certs'=>[]]);
        if (!isset($content['prices'][$i])) {
            flash('Неизвестная карточка цен.', false);
            header('Location: index.php?p=cards'); exit;
        }
        [$ok, $res] = save_image_upload($_FILES['img'] ?? null, 'klio-pcard-' . $i, $IMG_DIR);
        if (!$ok) {
            flash($res, false);
            header('Location: index.php?p=cards'); exit;
        }
        $content['prices'][$i]['img'] = $res;
        $content['updated'] = date('Y-m-d');
        jwrite($F_CONTENT, $content);
        flash('Фото карточки цен «' . ($content['prices'][$i]['cat'] ?? '') . '» обновлено. На сайте появится при следующем открытии страницы.');
        header('Location: index.php?p=cards'); exit;
    }

    if ($action === 'password') {
        $old = (string)($_POST['old'] ?? '');
        $p1  = (string)($_POST['p1'] ?? '');
        $p2  = (string)($_POST['p2'] ?? '');
        if (!password_verify($old, (string)$admin['password_hash'])) flash('Текущий пароль неверен.', false);
        elseif (strlen($p1) < 8) flash('Новый пароль должен быть не короче 8 символов.', false);
        elseif ($p1 !== $p2) flash('Новые пароли не совпадают.', false);
        else {
            $admin['password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
            jwrite($F_ADMIN, $admin);
            flash('Пароль изменён.');
        }
        header('Location: index.php?p=password'); exit;
    }
}

/* ---------- CSV-экспорт ---------- */
if (is_auth() && !$need_setup && ($_GET['export'] ?? '') === 'csv') {
    $leads = load_leads();
    $map = jread($F_STATUS, []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="klio-leads-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; /* BOM для Excel */
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Дата','Тип','Имя','Телефон','Детали','Статус','Источник (UTM)','Страница'], ';');
    foreach ($leads as $l) {
        $det = [];
        foreach (($l['fields'] ?? []) as $k => $v) {
            if ($k === 'Имя' || $k === 'Телефон') continue;
            $det[] = "$k: $v";
        }
        $utm = [];
        foreach (($l['utm'] ?? []) as $k => $v) $utm[] = "$k=$v";
        $st = $STATUSES[lead_status($l['id'] ?? '', $map)] ?? 'Новая';
        fputcsv($out, [$l['date'] ?? '', $l['type'] ?? '', $l['name'] ?? '', $l['phone'] ?? '', implode(' | ', $det), $st, implode(' ', $utm), $l['page'] ?? ''], ';');
    }
    fclose($out);
    exit;
}

/* ---------- вьюха ---------- */
$page = $_GET['p'] ?? 'dashboard';
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function head_html($title){
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         '<meta name="robots" content="noindex,nofollow">',
         '<title>', e($title), ' · КЛИО админка</title>',
         '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
         '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Marcellus&display=swap" rel="stylesheet">',
         '<style>',
         ':root{--red:#7d2a35;--red2:#9c3a46;--acc:#d4af37;--bg:#f3ede2;--card:#fff;--line:#e7ddcb;--text:#2e2721;--mut:#8a7d6e}',
         '*{box-sizing:border-box;margin:0;padding:0}',
         'body{font-family:Manrope,system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:15px;line-height:1.5}',
         'a{color:var(--red);text-decoration:none} a:hover{text-decoration:underline}',
         '.wrap{display:flex;min-height:100vh}',
         '.side{width:230px;background:linear-gradient(180deg,var(--red),#5c1f29);color:#fff;padding:26px 18px;flex:none;display:flex;flex-direction:column;gap:6px;position:sticky;top:0;height:100vh}',
         '.side .logo{font-family:Marcellus,serif;font-size:24px;letter-spacing:.12em;color:var(--acc);margin-bottom:4px}',
         '.side .sub{font-size:12px;opacity:.75;margin-bottom:18px}',
         '.side a.nav{display:block;color:#fff;padding:10px 14px;border-radius:12px;font-weight:600;font-size:14px}',
         '.side a.nav:hover{background:rgba(255,255,255,.12);text-decoration:none}',
         '.side a.nav.on{background:var(--acc);color:var(--red)}',
         '.side .out{margin-top:auto;font-size:13px;opacity:.8}',
         '.side .out button{margin-top:8px;width:100%;background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:10px;padding:9px;cursor:pointer;font-family:inherit;font-weight:600}',
         '.side #sideTgl{background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:10px;width:100%;padding:7px 0;cursor:pointer;font-size:15px;margin-bottom:12px;font-family:inherit}',
         '.side a.nav .ic{display:inline-block;width:22px;margin-right:6px;text-align:center}',
         '@media(min-width:821px){.side{transition:width .18s ease}html.sidemin .side{width:60px;padding:26px 8px}html.sidemin .side .lbl,html.sidemin .side .sub{display:none}html.sidemin .side .logo{font-size:15px;letter-spacing:.04em;text-align:center;white-space:nowrap}html.sidemin .side a.nav{padding:10px 0;text-align:center;font-size:16px}html.sidemin .side a.nav .ic{margin-right:0;width:auto}html.sidemin .side .out button{font-size:0;padding:9px 0}html.sidemin .side .out button::before{content:"⏻";font-size:15px}}',
         '@media(max-width:820px){.side #sideTgl{display:none}}',
         '.main{flex:1;padding:28px 26px 60px;max-width:1080px}',
         'h1{font-family:Marcellus,serif;font-weight:400;font-size:26px;margin-bottom:18px}',
         'h1 small{display:block;font-family:Manrope;font-size:13px;color:var(--mut);margin-top:4px}',
         '.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:26px}',
         '.stat{background:var(--card);border-radius:16px;padding:16px 18px;box-shadow:0 6px 24px rgba(70,20,28,.07)}',
         '.stat b{display:block;font-size:28px;color:var(--red);line-height:1.1}',
         '.stat span{font-size:12.5px;color:var(--mut)}',
         '.panel{background:var(--card);border-radius:18px;padding:20px;box-shadow:0 6px 24px rgba(70,20,28,.07);margin-bottom:18px}',
         '.panel h2{font-size:17px;margin-bottom:14px}',
         '.flash{padding:13px 18px;border-radius:14px;margin-bottom:18px;font-weight:600}',
         '.flash.ok{background:#e7f4e4;color:#2d6a2d;border:1px solid #bfe0b8}',
         '.flash.err{background:#fbe9e7;color:#a33;border:1px solid #f0c4bd}',
         '.btn{display:inline-block;background:linear-gradient(120deg,var(--red),var(--red2));color:#fff;border:0;border-radius:12px;padding:10px 18px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer}',
         '.btn:hover{filter:brightness(1.07)}',
         '.btn.gold{background:linear-gradient(120deg,#c19a2e,var(--acc))}',
         '.btn.ghost{background:#efe7d8;color:var(--red)}',
         '.btn.sm{padding:6px 12px;font-size:13px;border-radius:10px}',
         'input[type=text],input[type=password],input[type=number],select,textarea{width:100%;border:1.5px solid var(--line);border-radius:12px;padding:10px 13px;font-family:inherit;font-size:14px;background:#fff;color:var(--text)}',
         'input:focus,select:focus,textarea:focus{outline:none;border-color:var(--acc)}',
         'label{display:block;font-size:13px;font-weight:700;color:var(--mut);margin:12px 0 5px}',
         '.lead{background:var(--card);border-radius:16px;padding:16px 18px;box-shadow:0 4px 18px rgba(70,20,28,.06);margin-bottom:12px}',
         '.lead .top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline}',
         '.lead .nm{font-weight:800;font-size:16px}',
         '.lead .ph{font-weight:700;color:var(--red)}',
         '.lead .dt{font-size:12.5px;color:var(--mut)}',
         '.lead .det{font-size:13.5px;color:var(--text);margin-top:6px}',
         '.lead .utm{font-size:12px;color:var(--mut);margin-top:4px}',
         '.badge{display:inline-block;font-size:12px;font-weight:800;padding:3px 11px;border-radius:999px;white-space:nowrap}',
         '.b-new{background:#fdeaea;color:#a33}.b-called{background:#fff4d6;color:#8a6d00}.b-booked{background:#e3ecfd;color:#2c4f9e}.b-done{background:#e7f4e4;color:#2d6a2d}.b-cancel{background:#eee;color:#888}',
         '.stbar{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}',
         '.stbar .btn{padding:5px 11px;font-size:12px;border-radius:9px}',
         '.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}',
         '.chip{border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:7px 15px;font-size:13px;font-weight:700;color:var(--text);cursor:pointer}',
         '.chip.on{background:var(--red);border-color:var(--red);color:#fff}',
         'table.ed{width:100%;border-collapse:collapse;margin:6px 0 10px}',
         'table.ed td{padding:4px 6px 4px 0;vertical-align:middle}',
         'table.ed input{font-size:13.5px;padding:8px 10px}',
         '.catbox{border:1.5px solid var(--line);border-radius:16px;padding:14px 16px;margin-bottom:14px;background:#fffdf8}',
         '.catbox h3{font-size:15px;color:var(--red);margin-bottom:8px}',
         '.catbar{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}',
         '.catbar h3{margin-bottom:0}',
         '.catbtns{display:flex;gap:6px;align-items:center}',
         '.catbtns .btn.sm{padding:6px 11px;font-size:14px;line-height:1.1}',
         '.posbadge{background:var(--acc);color:var(--red);font-weight:800;font-size:13px;border-radius:9px;padding:4px 10px}',
         '.center{min-height:100vh;display:grid;place-items:center;padding:20px}',
         '.login{background:var(--card);border-radius:22px;padding:34px 30px;box-shadow:0 10px 40px rgba(70,20,28,.12);width:100%;max-width:400px}',
         '.login .logo{font-family:Marcellus,serif;font-size:30px;letter-spacing:.12em;color:var(--red);text-align:center;margin-bottom:4px}',
         '.login .sub{text-align:center;font-size:13px;color:var(--mut);margin-bottom:18px}',
         /* сетка карточек «Фото категорий» */
         '.cardgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}',
         '.carditem{background:#fffdf8;border:1.5px solid var(--line);border-radius:16px;padding:12px;display:flex;flex-direction:column;gap:10px}',
         '.carditem img{width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:12px;border:1px solid var(--line);background:#eee5d5}',
         '.carditem b{font-size:14.5px;color:var(--red)}',
         '.carditem .btn{width:100%;text-align:center}',
         '.carditem .pick{font-size:12px;color:var(--mut);min-height:15px;word-break:break-all}',
         /* телефон: крупные поля и кнопки, таблицы цен — карточками */
         '@media(max-width:820px){.wrap{flex-direction:column}.side{width:100%;height:auto;position:static;flex-direction:row;flex-wrap:wrap;align-items:center;padding:12px 14px}.side .sub{display:none}.side .logo{margin:0 10px 0 0;font-size:20px}.side a.nav{padding:9px 12px;font-size:13.5px}.side .out{margin:0 0 0 auto}.side .out button{width:auto;padding:9px 14px;margin:0}.main{padding:18px 14px 50px}',
         'input[type=text],input[type=password],input[type=number],select,textarea,table.ed input{font-size:16px;padding:13px 14px}',
         '.btn{padding:13px 20px;font-size:16px}.btn.sm{padding:10px 15px;font-size:14px}',
         '.panel{padding:16px 14px}h1{font-size:23px}',
         'table.ed,table.ed tbody,table.ed tr,table.ed td{display:block;width:100%}',
         'table.ed tr:first-child{display:none}',
         'table.ed tr.row{border-top:1.5px solid var(--line);padding:10px 0 8px}',
         'table.ed td{padding:3px 0}',
         'table.ed td.stk{display:flex;align-items:center;gap:10px;font-size:14.5px;color:var(--mut)}',
         'table.ed td.stk::before{content:"старая цена (зачеркнуть)"}',
         'table.ed td.stk input{width:22px;height:22px;flex:none;padding:2px}',
         'table.ed td:last-child{text-align:right}',
         'table.ed td .btn.sm{padding:10px 16px;font-size:15px}',
         '.cardgrid{grid-template-columns:repeat(2,1fr);gap:10px}',
         '.stbar .btn{padding:10px 14px;font-size:14px}}',
         '@media(max-width:420px){.cardgrid{grid-template-columns:1fr}}',
         '</style><script>try{if(localStorage.klio_admin_side==="1")document.documentElement.classList.add("sidemin")}catch(e){}</script></head><body>';
}

function login_view($need_setup, $f){
    head_html($need_setup ? 'Первичная настройка' : 'Вход');
    echo '<div class="center"><form class="login" method="post">';
    csrf_field();
    echo '<div class="logo">КЛИО</div><div class="sub">панель управления салоном</div>';
    if ($f) echo '<div class="flash ' . ($f[1] ? 'ok' : 'err') . '" style="margin-bottom:14px">' . e($f[0]) . '</div>';
    if ($need_setup) {
        echo '<input type="hidden" name="action" value="setup">',
             '<label>Код настройки (из инструкции)</label><input type="text" name="token" required autocomplete="off">',
             '<label>Придумайте пароль (от 8 символов)</label><input type="password" name="p1" required autocomplete="new-password">',
             '<label>Пароль ещё раз</label><input type="password" name="p2" required autocomplete="new-password">',
             '<button class="btn" style="width:100%;margin-top:18px">Создать пароль и войти</button>';
    } else {
        echo '<input type="hidden" name="action" value="login">',
             '<label>Пароль</label><input type="password" name="password" required autofocus autocomplete="current-password">',
             '<button class="btn" style="width:100%;margin-top:18px">Войти</button>';
    }
    echo '</form></div></body></html>';
    exit;
}

/* неавторизованным — только форма входа/настройки */
if ($need_setup) login_view(true, $flash);
if (!is_auth()) login_view(false, $flash);

$nav = ['dashboard'=>'Главная','salon'=>'Салон','leads'=>'Заявки','content'=>'Рейтинг и цены','cards'=>'Фото категорий','gcal'=>'DIKIDI','password'=>'Пароль'];
$navIc = ['dashboard'=>'⌂','salon'=>'📅','leads'=>'🔔','content'=>'★','cards'=>'🖼','gcal'=>'🔗','password'=>'🔑'];
if (!isset($nav[$page])) $page = 'dashboard';
head_html($nav[$page]);
echo '<div class="wrap"><aside class="side">',
     '<div><div class="logo">КЛИО</div><div class="sub">панель управления</div></div>',
     '<button id="sideTgl" type="button" title="Свернуть / развернуть меню">☰</button>';
foreach ($nav as $k => $v) echo '<a class="nav' . ($page === $k ? ' on' : '') . '" href="index.php?p=' . $k . '" title="' . e($v) . '"><span class="ic">' . $navIc[$k] . '</span><span class="lbl">' . e($v) . '</span></a>';
echo '<div class="out"><form method="post"><input type="hidden" name="action" value="logout"><button>Выйти</button></form></div>',
     '</aside><main class="main">';
if ($flash) echo '<div class="flash ' . ($flash[1] ? 'ok' : 'err') . '">' . e($flash[0]) . '</div>';

/* ================= ГЛАВНАЯ ================= */
if ($page === 'dashboard') {
    $leads = load_leads();
    $map = jread($F_STATUS, []);
    $today = date('d.m.Y');
    $weekAgo = time() - 7 * 86400;
    $cntToday = 0; $cntWeek = 0; $cntNew = 0; $cntWork = 0;
    foreach ($leads as $l) {
        $ts = (int)($l['ts'] ?? 0);
        $st = lead_status($l['id'] ?? '', $map);
        if (strpos((string)($l['date'] ?? ''), $today) === 0) $cntToday++;
        if ($ts >= $weekAgo) $cntWeek++;
        if ($st === 'new') $cntNew++;
        if ($st === 'called' || $st === 'booked') $cntWork++;
    }
    $rating = jread($F_RATING, ['rating'=>'—','count'=>0]);
    echo '<h1>Главная<small>сводка по заявкам и рейтингу</small></h1>',
         '<div class="cards">',
         '<div class="stat"><b>' . $cntToday . '</b><span>заявок сегодня</span></div>',
         '<div class="stat"><b>' . $cntWeek . '</b><span>за последние 7 дней</span></div>',
         '<div class="stat"><b>' . $cntNew . '</b><span>новых (ждут звонка)</span></div>',
         '<div class="stat"><b>' . e($rating['rating']) . ' · ' . (int)$rating['count'] . '</b><span>рейтинг на Яндекс.Картах</span></div>',
         '</div>';
    echo '<div class="panel"><h2>Последние заявки</h2>';
    if (!$leads) echo '<p style="color:var(--mut)">Заявок пока нет. Как только клиент оставит заявку на сайте или в приложении — она появится здесь.</p>';
    foreach (array_slice($leads, 0, 5) as $l) {
        $st = lead_status($l['id'] ?? '', $map);
        echo '<div style="display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--line);flex-wrap:wrap">',
             '<span><b>' . e($l['name'] ?? '') . '</b> · <span style="color:var(--red);font-weight:700">' . e($l['phone'] ?? '') . '</span><br>',
             '<small style="color:var(--mut)">' . e($l['date'] ?? '') . ' · ' . e($l['type'] ?? '') . '</small></span>',
             '<span class="badge b-' . e($st) . '">' . e($STATUSES[$st]) . '</span></div>';
    }
    if ($leads) echo '<div style="margin-top:14px"><a class="btn ghost sm" href="index.php?p=leads">Все заявки →</a></div>';
    echo '</div>',
         '<div class="panel"><h2>Быстрые действия</h2>',
         '<a class="btn sm" href="index.php?p=content" style="margin-right:8px">Обновить рейтинг / цены</a>',
         '<a class="btn gold sm" href="index.php?p=leads&export=csv">Скачать заявки (CSV)</a></div>';
}

/* ================= ЗАЯВКИ ================= */
if ($page === 'leads') {
    $leads = load_leads();
    $map = jread($F_STATUS, []);
    $fst = $_GET['st'] ?? '';
    $days = (int)($_GET['days'] ?? 0);
    $minTs = $days > 0 ? time() - $days * 86400 : 0;
    $back = 'index.php?p=leads' . ($fst ? "&st=$fst" : '') . ($days ? "&days=$days" : '');

    echo '<h1>Заявки<small>всего: ' . count($leads) . '</small></h1>';
    echo '<div class="filters">';
    echo '<a class="chip' . ($fst === '' ? ' on' : '') . '" href="index.php?p=leads">Все</a>';
    foreach ($STATUSES as $k => $v) echo '<a class="chip' . ($fst === $k ? ' on' : '') . '" href="index.php?p=leads&st=' . $k . '">' . e($v) . '</a>';
    echo '<span style="flex:1"></span>',
         '<a class="chip' . ($days === 7 ? ' on' : '') . '" href="index.php?p=leads&days=7">7 дней</a>',
         '<a class="chip' . ($days === 30 ? ' on' : '') . '" href="index.php?p=leads&days=30">30 дней</a>',
         '<a class="btn gold sm" href="index.php?p=leads&export=csv">CSV</a>',
         '</div>';

    $shown = 0;
    foreach ($leads as $l) {
        $st = lead_status($l['id'] ?? '', $map);
        if ($fst && $st !== $fst) continue;
        if ($minTs && (int)($l['ts'] ?? 0) < $minTs) continue;
        $shown++;
        $det = [];
        foreach (($l['fields'] ?? []) as $k => $v) {
            if ($k === 'Имя' || $k === 'Телефон') continue;
            $det[] = e($k) . ': ' . e($v);
        }
        echo '<div class="lead"><div class="top">',
             '<span><span class="nm">' . e($l['name'] ?? 'Без имени') . '</span> · <a class="ph" href="tel:+' . e($l['phone'] ?? '') . '">+' . e($l['phone'] ?? '') . '</a></span>',
             '<span class="badge b-' . e($st) . '">' . e($STATUSES[$st]) . '</span></div>',
             '<div class="dt">' . e($l['date'] ?? '') . ' · ' . e($l['type'] ?? 'заявка') . ' · ' . e($l['page'] === 'app' ? 'приложение' : 'сайт') . '</div>';
        if ($det) echo '<div class="det">' . implode('<br>', $det) . '</div>';
        if (!empty($l['utm'])) {
            $u = [];
            foreach ($l['utm'] as $k => $v) $u[] = e($k) . '=' . e($v);
            echo '<div class="utm">источник: ' . implode(' · ', $u) . '</div>';
        }
        echo '<div class="stbar">';
        foreach ($STATUSES as $k => $v) {
            if ($k === $st) continue;
            echo '<form method="post" style="display:inline">'; csrf_field();
            echo '<input type="hidden" name="action" value="lead_status"><input type="hidden" name="id" value="' . e($l['id'] ?? '') . '"><input type="hidden" name="st" value="' . $k . '"><input type="hidden" name="back" value="' . e($back) . '">',
                 '<button class="btn ghost">' . e($v) . '</button></form>';
        }
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Удалить заявку безвозвратно?\')">'; csrf_field();
        echo '<input type="hidden" name="action" value="lead_delete"><input type="hidden" name="id" value="' . e($l['id'] ?? '') . '"><input type="hidden" name="back" value="' . e($back) . '">',
             '<button class="btn" style="background:#c9c0ae;color:#5c5545">✕</button></form>',
             '</div></div>';
    }
    if (!$shown) echo '<div class="panel"><p style="color:var(--mut)">По этому фильтру заявок нет.</p></div>';
}

/* ================= РЕЙТИНГ И ЦЕНЫ ================= */
if ($page === 'content') {
    $rating = jread($F_RATING, ['rating'=>'5,0','count'=>0]);
    $content = jread($F_CONTENT, ['prices'=>[], 'certs'=>[]]);

    echo '<h1>Рейтинг и цены<small>после сохранения сайт и приложение обновятся при следующем открытии страницы</small></h1>';

    echo '<div class="panel"><h2>Рейтинг Яндекс.Карт</h2>',
         '<form method="post">'; csrf_field();
    echo '<input type="hidden" name="action" value="save_rating">',
         '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">',
         '<div style="width:110px"><label>Оценка</label><input type="text" name="rating" value="' . e($rating['rating']) . '" placeholder="5,0"></div>',
         '<div style="width:140px"><label>Число оценок</label><input type="number" name="count" value="' . (int)$rating['count'] . '" min="0"></div>',
         '<button class="btn">Сохранить рейтинг</button></div>',
         '<p style="font-size:12.5px;color:var(--mut);margin-top:10px">Берётся со страницы КЛИО на Яндекс.Картах. Поменяли здесь — поменялось на сайте и в приложении.</p>',
         '</form></div>';

    echo '<form method="post" onsubmit="return catsReindexAll()">'; csrf_field();
    echo '<input type="hidden" name="action" value="save_content">';
    foreach (['prices' => 'Цены на услуги', 'certs' => 'Абонементы'] as $sec => $secTitle) {
        echo '<div class="panel" data-sec="' . $sec . '"><h2>' . $secTitle . '</h2>';
        foreach (($content[$sec] ?? []) as $ci => $cat) {
            echo '<div class="catbox">',
                 '<div class="catbar"><h3>Категория ' . ($ci + 1) . '</h3><span class="catbtns">',
                 '<button type="button" class="btn ghost sm" onclick="catMove(this,-1)" title="Поднять выше">↑</button>',
                 '<button type="button" class="btn ghost sm" onclick="catMove(this,1)" title="Опустить ниже">↓</button>',
                 '<button type="button" class="btn ghost sm" onclick="catDel(this)" title="Удалить категорию">✕</button>',
                 '</span></div>',
                 '<input type="hidden" name="' . $sec . '_oid[' . $ci . ']" value="' . $ci . '">',
                 '<label>Название категории</label>',
                 '<input type="text" name="' . $sec . '_cat[' . $ci . ']" value="' . e($cat['cat'] ?? '') . '">',
                 '<table class="ed"><tr><td style="width:55%"><label>Название позиции</label></td><td><label>Цена</label></td><td style="width:44px;text-align:center"><label>старая</label></td><td style="width:36px"></td></tr>';
            foreach (($cat['rows'] ?? []) as $ri => $row) {
                $isStrike = ($row[2] ?? '') === 'strike';
                echo '<tr class="row" data-ri="' . $ri . '">',
                     '<td><input type="text" name="' . $sec . '_name[' . $ci . '][' . $ri . ']" value="' . e($row[0] ?? '') . '" placeholder="Название позиции"></td>',
                     '<td><input type="text" name="' . $sec . '_price[' . $ci . '][' . $ri . ']" value="' . e($row[1] ?? '') . '" placeholder="например: от 1 200 ₽"></td>',
                     '<td class="stk" style="text-align:center" title="Зачёркнутая (старая цена «Вместо»)"><input type="hidden" name="' . $sec . '_strike[' . $ci . '][' . $ri . ']" value="0"><input type="checkbox" name="' . $sec . '_strike[' . $ci . '][' . $ri . ']" value="1"' . ($isStrike ? ' checked' : '') . '></td>',
                     '<td><button type="button" class="btn ghost sm" onclick="this.closest(\'tr\').remove()">✕</button></td>',
                     '</tr>';
            }
            echo '</table>',
                 '<button type="button" class="btn ghost sm" onclick="addRow(this,\'' . $sec . '\')">+ позиция</button>',
                 '</div>';
        }
        echo '<button type="button" class="btn ghost" style="width:100%;padding:12px" onclick="catAdd(this,\'' . $sec . '\')">+ Добавить категорию</button>',
             '</div>';
    }
    echo '<div class="panel"><button class="btn" style="font-size:16px;padding:13px 26px">Сохранить все цены</button>',
         '<p style="font-size:12.5px;color:var(--mut);margin-top:10px">Стрелки ↑↓ — порядок категорий на сайте, ✕ — удалить категорию, «+ Добавить категорию» — новая. Всё применяется после кнопки «Сохранить все цены». Галочка «старая» — строка показывается зачёркнутой. Пустые строки удаляются. Фото карточек цен меняется в разделе «Фото категорий».</p></div>',
         '</form>';

    echo '<script>',
         /* индекс категории из имени поля (после перестановки не совпадает с data-ri) */
         'function catIdx(box){var m=(box.querySelector("input[name*=\'_cat\']")||{}).name.match(/\[(\d+)\]/);return m?+m[1]:0;}',
         'function catsReindex(panel){',
         'var boxes=panel.querySelectorAll(":scope > .catbox");',
         'boxes.forEach(function(box,idx){',
         'box.querySelector(".catbar h3").textContent="Категория "+(idx+1);',
         'box.querySelectorAll("input,textarea,select").forEach(function(inp){',
         'if(inp.name)inp.name=inp.name.replace(/\\[(\\d+)\\]($|\\[)/,"["+idx+"]$2");});});}',
         'function catsReindexAll(){document.querySelectorAll(".panel[data-sec]").forEach(catsReindex);return true;}',
         'function catMove(btn,dir){var box=btn.closest(".catbox"),panel=box.parentElement;',
         'var sib=dir<0?box.previousElementSibling:box.nextElementSibling;',
         'while(sib&&!sib.classList.contains("catbox"))sib=dir<0?sib.previousElementSibling:sib.nextElementSibling;',
         'if(!sib)return;',
         'if(dir<0)panel.insertBefore(box,sib);else panel.insertBefore(sib,box);',
         'catsReindex(panel);}',
         'function catDel(btn){if(!confirm("Удалить категорию целиком? Она пропадёт с сайта после кнопки «Сохранить все цены»."))return;',
         'var box=btn.closest(".catbox"),panel=box.parentElement;panel.removeChild(box);catsReindex(panel);}',
         'function catAdd(btn,sec){var panel=btn.parentElement,idx=panel.querySelectorAll(":scope > .catbox").length;',
         'var div=document.createElement("div");div.className="catbox";',
         'div.innerHTML=\'<div class="catbar"><h3>Категория \'+(idx+1)+\'</h3><span class="catbtns">\'',
         '+\'<button type="button" class="btn ghost sm" onclick="catMove(this,-1)" title="Поднять выше">↑</button>\'',
         '+\'<button type="button" class="btn ghost sm" onclick="catMove(this,1)" title="Опустить ниже">↓</button>\'',
         '+\'<button type="button" class="btn ghost sm" onclick="catDel(this)" title="Удалить категорию">✕</button></span></div>\'',
         '+\'<input type="hidden" name="\'+sec+\'_oid[\'+idx+\']" value="-1">\'',
         '+\'<label>Название категории</label><input type="text" name="\'+sec+\'_cat[\'+idx+\']" value="" placeholder="например: Детские услуги">\'',
         '+\'<table class="ed"><tr><td style="width:55%"><label>Название позиции</label></td><td><label>Цена</label></td><td style="width:44px;text-align:center"><label>старая</label></td><td style="width:36px"></td></tr></table>\'',
         '+\'<button type="button" class="btn ghost sm" onclick="addRow(this,\\\'\'+sec+\'\\\')">+ позиция</button>\';',
         'panel.insertBefore(div,btn);catsReindex(panel);',
         'addRow(div.querySelector("button[onclick^=\'addRow\']"),sec);',
         'div.scrollIntoView({block:"center",behavior:"smooth"});}',
         'function addRow(btn,sec){',
         'var box=btn.closest(".catbox"),ci=catIdx(box);',
         'var t=btn.previousElementSibling,tb=t.tBodies?t.tBodies[0]:t,ri=0,r=document.createElement("tr");',
         'tb.querySelectorAll("tr.row").forEach(function(tr){ri=Math.max(ri,+(tr.getAttribute("data-ri")||0)+1);});',
         'r.className=\'row\';r.setAttribute("data-ri",ri);',
         'r.innerHTML=\'<td><input type="text" name="\'+sec+\'_name[\'+ci+\'][\'+ri+\']" placeholder="Название позиции"></td><td><input type="text" name="\'+sec+\'_price[\'+ci+\'][\'+ri+\']" placeholder="например: от 1 200 ₽"></td><td class="stk" style="text-align:center"><input type="hidden" name="\'+sec+\'_strike[\'+ci+\'][\'+ri+\']" value="0"><input type="checkbox" name="\'+sec+\'_strike[\'+ci+\'][\'+ri+\']" value="1"></td><td><button type="button" class="btn ghost sm" onclick="this.closest(\\\'tr\\\').remove()">✕</button></td>\';',
         'tb.appendChild(r);}</script>';
}

/* ================= ФОТО КАТЕГОРИЙ ================= */
if ($page === 'cards') {
    $content = jread($F_CONTENT, ['prices'=>[], 'certs'=>[]]);
    $cards = cards_current($content);
    $nCards = count($cards);

    echo '<h1>Фото категорий<small>карточки на главной странице сайта и в приложении — после замены обновятся при следующем открытии страницы</small></h1>';
    echo '<div class="panel"><h2>Карточки на главной: порядок и фото</h2>',
         '<p style="font-size:13px;color:var(--mut);margin-top:0">Стрелки ↑↓ меняют порядок карточек на главной странице (и в приложении). Номер слева — текущая позиция на сайте.</p>',
         '<div class="cardgrid">';
    foreach ($cards as $i => $c) {
        echo '<div class="carditem">',
             '<div class="catbar"><span class="posbadge">№' . ($i + 1) . '</span><span class="catbtns">';
        if ($i > 0) {
            echo '<form method="post" style="display:inline">'; csrf_field();
            echo '<input type="hidden" name="action" value="move_card"><input type="hidden" name="card_i" value="' . $i . '"><input type="hidden" name="dir" value="-1">',
                 '<button class="btn ghost sm" title="Поднять выше">↑</button></form>';
        }
        if ($i < $nCards - 1) {
            echo '<form method="post" style="display:inline">'; csrf_field();
            echo '<input type="hidden" name="action" value="move_card"><input type="hidden" name="card_i" value="' . $i . '"><input type="hidden" name="dir" value="1">',
                 '<button class="btn ghost sm" title="Опустить ниже">↓</button></form>';
        }
        echo '</span></div>',
             '<form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">'; csrf_field();
        echo '<input type="hidden" name="action" value="save_card"><input type="hidden" name="card_i" value="' . $i . '">',
             '<img src="' . e($c['img']) . '" alt="' . e($c['title']) . '" id="prev' . $i . '">',
             '<b>' . e($c['title']) . '</b>',
             '<label class="btn ghost">Выбрать фото…<input type="file" name="img" accept="image/jpeg,image/png,image/webp" hidden onchange="cardPick(this,' . $i . ')"></label>',
             '<div class="pick" id="pick' . $i . '"></div>',
             '<button class="btn">Заменить фото</button>',
             '</form></div>';
    }
    echo '</div></div>';

    /* фото карточек цен */
    echo '<div class="panel"><h2>Фото карточек цен</h2>',
         '<p style="font-size:13px;color:var(--mut);margin-top:0">Эти фото стоят на карточках в разделе «Цены на услуги». Порядок карточек цен меняется стрелками ↑↓ в разделе «Рейтинг и цены».</p>',
         '<div class="cardgrid">';
    foreach (($content['prices'] ?? []) as $pi => $pc) {
        $pimg = !empty($pc['img']) ? '/img/' . $pc['img'] : '';
        echo '<div class="carditem">',
             '<div class="catbar"><span class="posbadge">№' . ($pi + 1) . '</span></div>',
             '<form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">'; csrf_field();
        echo '<input type="hidden" name="action" value="save_pcard"><input type="hidden" name="pcard_i" value="' . $pi . '">',
             ($pimg ? '<img src="' . e($pimg) . '" alt="' . e($pc['cat'] ?? '') . '" id="prevP' . $pi . '">' : '<img src="/img/klio-logo-180.jpg" alt="нет фото" id="prevP' . $pi . '">'),
             '<b>' . e($pc['cat'] ?? ('Категория ' . ($pi + 1))) . '</b>',
             '<label class="btn ghost">Выбрать фото…<input type="file" name="img" accept="image/jpeg,image/png,image/webp" hidden onchange="cardPickP(this,' . $pi . ')"></label>',
             '<div class="pick" id="pickP' . $pi . '"></div>',
             '<button class="btn">Заменить фото</button>',
             '</form></div>';
    }
    echo '</div></div>',
         '<div class="panel"><p style="font-size:13.5px;color:var(--mut)">Подходят фото JPG, PNG или WEBP до 8 МБ. Красивее всего смотрятся вертикальные фото (как сейчас). Нажмите «Выбрать фото…», проверьте превью и затем «Заменить фото».</p></div>';

    echo '<script>function cardPick(inp,i){',
         'var p=document.getElementById("pick"+i);',
         'if(!inp.files||!inp.files[0]){p.textContent="";return;}',
         'p.textContent="Выбрано: "+inp.files[0].name;',
         'var r=new FileReader();r.onload=function(e){document.getElementById("prev"+i).src=e.target.result;};r.readAsDataURL(inp.files[0]);',
         '}',
         'function cardPickP(inp,i){',
         'var p=document.getElementById("pickP"+i);',
         'if(!inp.files||!inp.files[0]){p.textContent="";return;}',
         'p.textContent="Выбрано: "+inp.files[0].name;',
         'var r=new FileReader();r.onload=function(e){document.getElementById("prevP"+i).src=e.target.result;};r.readAsDataURL(inp.files[0]);',
         '}</script>';
}

/* ================= САЛОН (календарь, записи, баланс) ================= */
if ($page === 'salon') {
    /* выдаём модулю персональный токен на 12 часов */
    $F_TOKENS = $DATA_DIR . '/manage-tokens.json';
    $tokens = jread($F_TOKENS, []);
    $nowT = time();
    foreach ($tokens as $k => $v) if (($v['exp'] ?? 0) < $nowT) unset($tokens[$k]);
    $tok = bin2hex(random_bytes(20));
    $tokens[$tok] = ['name'=>'', 'exp'=>$nowT + 12*3600, 'src'=>'panel'];
    jwrite($F_TOKENS, $tokens);
    echo '<style>.main{max-width:100%}</style>',
         '<h1>Управление салоном<small>Календарь записей, расчёты, баланс — всё синхронизировано с приложением команды</small></h1>',
         '<div id="klioManage" data-token="' . e($tok) . '"></div>',
         '<link rel="stylesheet" href="/manage/manage.css?v=13">',
         '<link rel="stylesheet" href="/manage/finance.css?v=1">',
         '<script src="/manage/finance.js?v=1"></script>',
         '<script src="/manage/manage.js?v=finance-1"></script>';
}

/* ================= ИНТЕГРАЦИЯ DIKIDI → Google Calendar → CRM (ТЗ №4, §8) ================= */
if ($page === 'gcal') {
    require_once $API_DIR . '/gcal-lib.php';
    $cfg = gcal_cfg();
    if ((string)($cfg['sync_token'] ?? '') === '') { $cfg['sync_token'] = bin2hex(random_bytes(24)); gcal_cfg($cfg); }
    $connected = ((string)($cfg['refresh_token_enc'] ?? '') !== '');
    $meta = jread($DATA_DIR . '/manage-meta.json', []);
    $specList = array_map('strval', (array)($meta['specialists'] ?? []));
    $scheme = 'https';
    $redirect = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'myclio.ru') . '/api/gcal-oauth.php';
    $cronUrl  = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'myclio.ru') . '/api/gcal-sync.php?token=' . $cfg['sync_token'];
    $okFlash = (isset($_GET['ok']) && $_GET['ok'] === '1');
    $errFlash = (string)($_GET['err'] ?? '');

    echo '<style>
      .gk-step{background:#fffdf8;border:1px solid var(--line2,#e5ddcd);border-radius:14px;padding:18px 20px;margin:14px 0}
      .gk-step h3{margin:0 0 10px;font-size:15px}
      .gk-code{display:block;background:#f5efe2;border-radius:10px;padding:10px 14px;font:13px/1.5 ui-monospace,monospace;
               word-break:break-all;margin:8px 0;user-select:all}
      .gk-status{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12.5px;font-weight:700}
      .gk-on{background:#e9f2dc;color:#4e7a2a}.gk-off{background:#fbe3d7;color:#a4442f}
      .gk-table{width:100%;border-collapse:collapse;font-size:14px}
      .gk-table td,.gk-table th{padding:8px 6px;border-bottom:1px solid #eee4d2;text-align:left;vertical-align:middle}
      .gk-err{max-height:220px;overflow:auto;background:#fdf6f3;border:1px solid #f0d5cb;border-radius:10px;padding:10px 14px;font-size:12.5px}
      .gk-err div{padding:3px 0;border-bottom:1px dashed #f0d5cb}
    </style>',
    '<h1>Интеграция DIKIDI<small>Автоматический перенос записей: DIKIDI → Google Календарь → наша CRM. Только в одну сторону, данные из CRM в DIKIDI не уходят.</small></h1>';

    if ($okFlash) echo '<div class="flash ok">Google-аккаунт подключён. Обновите список календарей ниже.</div>';
    if ($errFlash !== '') echo '<div class="flash err">' . e($errFlash) . '</div>';

    /* статус */
    echo '<div class="gk-step"><h3>Статус</h3>';
    echo '<span class="gk-status ' . ($connected ? 'gk-on' : 'gk-off') . '">' . ($connected ? 'Аккаунт подключён' : 'Аккаунт не подключён') . '</span> ';
    echo '<span class="gk-status ' . (!empty($cfg['sync_enabled']) ? 'gk-on' : 'gk-off') . '">Синхронизация ' . (!empty($cfg['sync_enabled']) ? 'включена' : 'выключена') . '</span>',
         '<div style="margin-top:10px;font-size:13px;color:var(--mut,#8a8578)">',
         'Последний запуск: ' . (!empty($cfg['last_sync']) ? date('d.m.Y H:i:s', (int)$cfg['last_sync']) : 'ещё не было'),
         ' · последний успешный: ' . (!empty($cfg['last_ok']) ? date('d.m.Y H:i:s', (int)$cfg['last_ok']) : '—'),
         '</div></div>';

    /* шаг 1: ключи */
    echo '<div class="gk-step"><h3>Шаг 1. Ключи Google Cloud (OAuth 2.0)</h3>',
         '<div style="font-size:13px;margin-bottom:10px">Создайте OAuth-клиент в Google Cloud Console (инструкция прилагается). ',
         'При создании укажите разрешённый URI перенаправления:</div>',
         '<span class="gk-code">' . e($redirect) . '</span>',
         '<form method="post">'; csrf_field();
    echo '<input type="hidden" name="action" value="gcal_save_keys">',
         '<label>Client ID</label><input name="client_id" value="' . e((string)$cfg['client_id']) . '" autocomplete="off" style="max-width:520px">',
         '<label>Client Secret' . ($cfg['client_secret_enc'] ? ' (сохранён — введите, только если меняется)' : '') . '</label>',
         '<input name="client_secret" type="password" value="" autocomplete="off" style="max-width:520px">',
         '<div><button class="btn" style="margin-top:14px">Сохранить ключи</button></div></form></div>';

    /* шаг 2: подключение */
    echo '<div class="gk-step"><h3>Шаг 2. Подключение Google-аккаунта</h3>',
         '<div style="font-size:13px;margin-bottom:10px">Подключите аккаунт, к календарям которого привязан экспорт DIKIDI. ',
         'Запрашивается доступ <b>только на чтение календарей</b> (минимальные права, ТЗ §8).</div>';
    if ($connected) {
        echo '<form method="post" onsubmit="return confirm(\'Отключить аккаунт? Синхронизация остановится.\')">'; csrf_field();
        echo '<input type="hidden" name="action" value="gcal_disconnect"><button class="btn" style="background:#b3543f">Отключить аккаунт</button></form>';
    } else {
        echo '<form method="post">'; csrf_field();
        echo '<input type="hidden" name="action" value="gcal_oauth_start"><button class="btn">Подключить Google-аккаунт</button></form>';
    }
    echo '</div>';

    /* шаг 3: календари */
    if ($connected) {
        echo '<div class="gk-step"><h3>Шаг 3. Календари и специалисты</h3>',
             '<div style="font-size:13px;margin-bottom:10px">В DIKIDI включён экспорт в Google Календарь — каждый сотрудник виден отдельным календарём. ',
             'Сопоставьте каждому календарю специалиста из CRM и включите нужные.</div>',
             '<form method="post" style="margin-bottom:12px">'; csrf_field();
        echo '<input type="hidden" name="action" value="gcal_refresh_cals"><button class="btn">Обновить список календарей</button></form>';
        if (!empty($cfg['calendars'])) {
            echo '<form method="post">'; csrf_field();
            echo '<input type="hidden" name="action" value="gcal_save_cals"><table class="gk-table"><tr><th></th><th>Календарь Google</th><th>Специалист в CRM</th><th>Последняя синхр.</th></tr>';
            foreach ($cfg['calendars'] as $id => $cal) {
                echo '<tr><td><input type="checkbox" name="cal_en[' . e($id) . ']" value="1"' . (!empty($cal['enabled']) ? ' checked' : '') . '></td>',
                     '<td>' . e((string)($cal['name'] ?? $id)) . '<div style="font-size:11px;color:var(--mut,#8a8578)">' . e($id) . '</div></td>',
                     '<td><select name="cal_spec[' . e($id) . ']"><option value="">— не сопоставлен —</option>';
                foreach ($specList as $sp) echo '<option value="' . e($sp) . '"' . (($cal['spec'] ?? '') === $sp ? ' selected' : '') . '>' . e($sp) . '</option>';
                echo '</select></td>',
                     '<td style="font-size:12px;color:var(--mut,#8a8578)">' . (!empty($cal['last_sync']) ? date('d.m H:i', (int)$cal['last_sync']) : '—') . '</td></tr>';
            }
            echo '</table><button class="btn" style="margin-top:12px">Сохранить сопоставления</button></form>';
        } else {
            echo '<div style="font-size:13px;color:var(--mut,#8a8578)">Список пуст — нажмите «Обновить список календарей».</div>';
        }
        echo '</div>';
    }

    /* шаг 4: запуск */
    if ($connected) {
        echo '<div class="gk-step"><h3>Шаг 4. Запуск синхронизации</h3>',
             '<div style="font-size:13px;margin-bottom:10px">Сервер опрашивает календари раз в 60 секунд (ТЗ §5) — независимо от того, открыто ли приложение. ',
             'Для этого на хостинге настраивается задание cron на URL:</div>',
             '<span class="gk-code">' . e($cronUrl) . '</span>',
             '<form method="post" style="display:inline">'; csrf_field();
        echo '<input type="hidden" name="action" value="gcal_toggle"><input type="hidden" name="on" value="' . (!empty($cfg['sync_enabled']) ? '0' : '1') . '">',
             '<button class="btn">' . (!empty($cfg['sync_enabled']) ? 'Выключить' : 'Включить') . ' синхронизацию</button></form> ',
             '<form method="post" style="display:inline">'; csrf_field();
        echo '<input type="hidden" name="action" value="gcal_now"><button class="btn">Синхронизировать сейчас</button></form></div>';
    }

    /* ошибки */
    if (!empty($cfg['errors'])) {
        echo '<div class="gk-step"><h3>Журнал ошибок интеграции</h3><div class="gk-err">';
        foreach (array_reverse((array)$cfg['errors']) as $er)
            echo '<div><b>' . e(date('d.m H:i', (int)($er['t'] ?? 0))) . '</b> — ' . e((string)($er['m'] ?? '')) . '</div>';
        echo '</div><form method="post" style="margin-top:10px">'; csrf_field();
        echo '<input type="hidden" name="action" value="gcal_clear_errors"><button class="btn">Очистить журнал</button></form></div>';
    }
}

/* ================= ПАРОЛЬ ================= */
if ($page === 'password') {
    echo '<h1>Смена пароля</h1><div class="panel" style="max-width:460px"><form method="post">'; csrf_field();
    echo '<input type="hidden" name="action" value="password">',
         '<label>Текущий пароль</label><input type="password" name="old" required autocomplete="current-password">',
         '<label>Новый пароль (от 8 символов)</label><input type="password" name="p1" required autocomplete="new-password">',
         '<label>Новый пароль ещё раз</label><input type="password" name="p2" required autocomplete="new-password">',
         '<button class="btn" style="margin-top:16px">Сменить пароль</button></form></div>';
}

echo '</main></div><script>(function(){var b=document.getElementById("sideTgl");if(b)b.onclick=function(){var d=document.documentElement;d.classList.toggle("sidemin");try{localStorage.setItem("klio_admin_side",d.classList.contains("sidemin")?"1":"0")}catch(e){}};})();</script></body></html>';
