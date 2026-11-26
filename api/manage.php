<?php
/* КЛИО · модуль «Управление салоном»: записи, расчёты, баланс, синхронизация.
   Хранилище — JSON в api/data (веб-доступ закрыт .htaccess).
   Авторизация: код админки -> токен (login), далее заголовок X-Token.
   Все записи/изменения версионируются: клиент опрашивает state?since=N. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '0'); /* JSON не должен ломаться о предупреждения */
/* CORS: штатное приложение (WebView, file://) ходит на API кросс-доменно */
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
header('Access-Control-Allow-Headers: X-Token, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$DATA = dirname(__FILE__) . '/data';
$F_ADMIN   = $DATA . '/admin.json';
$F_BOOK    = $DATA . '/bookings.json';
$F_TOKENS  = $DATA . '/manage-tokens.json';
$F_META    = $DATA . '/manage-meta.json';
$F_LEADSP  = $DATA . '/manage-leads.json';
$F_LEADS   = $DATA . '/leads.jsonl';
$F_ATT     = $DATA . '/login_attempts.json';
$F_HIST    = $DATA . '/manage-history.jsonl'; /* полный append-only журнал (только запись) */
$F_EV      = $DATA . '/manage-events.json';   /* кольцевой буфер последних событий для быстрого опроса */
$F_PUSH    = $DATA . '/push-tokens.json';     /* FCM-токены устройств команды */
$F_FCM     = $DATA . '/fcm.json';             /* service account Firebase (недоступен из веба) */
$F_FCMT    = $DATA . '/fcm-token.json';       /* кеш OAuth access_token */

function out($arr, $code = 200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function jread($f, $def){ if (!file_exists($f)) return $def; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $def; }
function jwrite($f, $d){ file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); }

/* ----- журнал «История действий»: только добавление, без API на изменение/удаление ----- */
function hist_log($type, $obj, $oid, $title, $info, $user, $src, $extra = []){
    global $F_HIST, $F_EV;
    $ev = array_merge([
        't'     => time(),
        'user'  => (string)$user,
        'src'   => (string)$src,            /* panel | app | site | dikidi */
        'type'  => (string)$type,
        'obj'   => (string)$obj,            /* booking | lead */
        'oid'   => (string)$oid,
        'title' => (string)$title,
        'info'  => (string)$info,
    ], $extra);
    $buf = jread($F_EV, ['seq'=>0, 'items'=>[]]);
    $buf['seq'] = (int)($buf['seq'] ?? 0) + 1;
    $ev['seq'] = $buf['seq'];
    @file_put_contents($F_HIST, json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $buf['items'][] = $ev;
    if (count($buf['items']) > 300) $buf['items'] = array_slice($buf['items'], -300);
    jwrite($F_EV, $buf);
    push_later($ev); /* системные пуши команде — отправятся после ответа клиенту */
    return $ev;
}

/* ----- FCM: системные push-уведомления (общий модуль api/fcm-push.php) ----- */
require_once __DIR__ . '/fcm-push.php';
/* отложенная отправка: сначала отдаём ответ клиенту, потом шлём пуши */
$PENDING_PUSH = [];
function push_later($ev){ global $PENDING_PUSH; $PENDING_PUSH[] = $ev; }
function push_flush(){
    global $PENDING_PUSH;
    if (!$PENDING_PUSH || !fcm_ready()) { $PENDING_PUSH = []; return; }
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    foreach ($PENDING_PUSH as $ev) { fcm_broadcast($ev); }
    $PENDING_PUSH = [];
}
register_shutdown_function('push_flush');
/* короткая сводка записи для событий */
function book_info($it){
    $t = strtotime((string)($it['date'] ?? ''));
    $d = $t ? date('d.m.Y', $t) : (string)($it['date'] ?? '');
    return trim($d . ' ' . ($it['start'] ?? '') . '–' . ($it['end'] ?? '') . ' · ' . ($it['service'] ?? '') . ' · ' . ($it['spec'] ?? ''));
}
/* снимок записи для журнала (все поля, включая финансы) */
function book_snap($it){
    $s = [];
    foreach (['date','start','end','client','phone','dir','service','spec','comment','done','amount','pay','reward','state'] as $k)
        $s[$k] = $it[$k] ?? null;
    return $s;
}

/* ----- блокировка-хранилище записей ----- */
function book_load(){ global $F_BOOK; $b = jread($F_BOOK, []); if (!isset($b['version'])) $b = ['version'=>0,'seq'=>0,'items'=>[]]; return $b; }
function book_save($b){ global $F_BOOK; jwrite($F_BOOK, $b); }

/* ----- токены ----- */
function tokens_load(){ global $F_TOKENS; $t = jread($F_TOKENS, []); $now = time(); $ch = false; foreach ($t as $k => $v){ if (($v['exp'] ?? 0) < $now){ unset($t[$k]); $ch = true; } } if ($ch) jwrite($F_TOKENS, $t); return $t; }
function token_check(){
    $tok = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
    if ($tok === '' || !preg_match('/^[a-f0-9]{32,64}$/', $tok)) return null;
    $t = tokens_load();
    return isset($t[$tok]) ? ['tok'=>$tok, 'name'=>(string)($t[$tok]['name'] ?? ''), 'src'=>(string)($t[$tok]['src'] ?? 'app')] : null;
}

/* ----- анти-подбор кода ----- */
function att_blocked(){ global $F_ATT; $a = jread($F_ATT, []); $ip = 'manage:' . ($_SERVER['REMOTE_ADDR'] ?? '?'); $now = time();
    $list = array_filter($a[$ip] ?? [], function($t) use ($now){ return $now - $t < 900; }); return count($list) >= 8; }
function att_fail(){ global $F_ATT; $a = jread($F_ATT, []); $ip = 'manage:' . ($_SERVER['REMOTE_ADDR'] ?? '?'); $now = time();
    $list = array_filter($a[$ip] ?? [], function($t) use ($now){ return $now - $t < 900; }); $list[] = $now; $a[$ip] = array_values($list); jwrite($F_ATT, $a); }
function att_clear(){ global $F_ATT; $a = jread($F_ATT, []); unset($a['manage:' . ($_SERVER['REMOTE_ADDR'] ?? '?')]); jwrite($F_ATT, $a); }

$PAY = ['cash','qr','sbp','term'];
$PAY_RU = ['cash'=>'Наличные','qr'=>'QR-код','sbp'=>'СБП','term'=>'Терминал'];

/* ----- статус записи (вычисляемый) ----- */
function eff_status($it){
    if (!empty($it['state'])) return $it['state']; /* canceled | noshow */
    if (isset($it['reward']) && $it['reward'] !== null) return 'done';
    if (isset($it['amount']) && $it['amount'] !== null && !empty($it['pay'])) return 'paid';
    if (!empty($it['done'])) return 'service';
    return 'planned';
}
function unfinished_reasons($it, $now){
    if (!empty($it['state'])) return [];
    $end = strtotime(($it['date'] ?? '') . ' ' . ($it['end'] ?? '00:00'));
    if (!$end || $end >= $now) return [];
    $r = [];
    if (empty($it['done'])) $r[] = 'не подтверждено оказание услуги';
    if (!isset($it['amount']) || $it['amount'] === null) $r[] = 'не указана сумма';
    if (empty($it['pay'])) $r[] = 'не указан способ оплаты';
    if (!isset($it['reward']) || $it['reward'] === null) $r[] = 'не внесено вознаграждение';
    return $r;
}
function pub_item($it, $now){ $it['status'] = eff_status($it); $it['unfinished'] = unfinished_reasons($it, $now); return $it; }

$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$action = $method === 'GET' ? (string)($_GET['action'] ?? '') : (string)($body['action'] ?? '');

/* ================= LOGIN: код -> токен ================= */
if ($action === 'login') {
    if (att_blocked()) out(['ok'=>false,'err'=>'Слишком много попыток. Подождите 15 минут.'], 429);
    $code = (string)($body['code'] ?? '');
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 40);
    $admin = jread($F_ADMIN, ['password_hash'=>'']);
    if (empty($admin['password_hash']) || !password_verify($code, (string)$admin['password_hash'])) { att_fail(); out(['ok'=>false,'err'=>'Неверный код доступа.'], 403); }
    if ($name === '') out(['ok'=>false,'err'=>'Укажите имя.'], 400);
    att_clear();
    $tok = bin2hex(random_bytes(20));
    $src = in_array(($body['src'] ?? ''), ['app','web','panel'], true) ? (string)$body['src'] : 'app';
    $t = tokens_load();
    $t[$tok] = ['name'=>$name, 'exp'=>time() + 60*86400, 'src'=>$src];
    jwrite($F_TOKENS, $t);
    out(['ok'=>true, 'token'=>$tok, 'name'=>$name]);
}

/* ================= всё ниже — по токену ================= */
$me = token_check();
if (!$me) out(['ok'=>false,'err'=>'Нужна авторизация.'], 401);

if ($action === 'whoami') out(['ok'=>true, 'name'=>$me['name']]);

if ($action === 'bind') { /* привязать/сменить имя токена (вход через админку) */
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 40);
    if ($name === '') out(['ok'=>false,'err'=>'Укажите имя.'], 400);
    $t = tokens_load();
    if (isset($t[$me['tok']])) { $t[$me['tok']]['name'] = $name; jwrite($F_TOKENS, $t); }
    out(['ok'=>true, 'name'=>$name]);
}

if ($action === 'logout') {
    $t = tokens_load(); unset($t[$me['tok']]); jwrite($F_TOKENS, $t);
    out(['ok'=>true]);
}

/* ================= PUSH: регистрация FCM-токена устройства ================= */
if ($action === 'push_register') {
    $fcm = trim((string)($body['fcm'] ?? ''));
    if ($fcm === '' || strlen($fcm) > 300 || !preg_match('/^[\w:\-]+$/', $fcm)) out(['ok'=>false,'err'=>'bad token'], 400);
    $d = jread($F_PUSH, []);
    $d[$fcm] = ['name'=>$me['name'], 't'=>time()];
    if (count($d) > 200) $d = array_slice($d, -200, null, true);
    jwrite($F_PUSH, $d);
    out(['ok'=>true]);
}
if ($action === 'push_unregister') {
    $fcm = trim((string)($body['fcm'] ?? ''));
    $d = jread($F_PUSH, []);
    unset($d[$fcm]);
    jwrite($F_PUSH, $d);
    out(['ok'=>true]);
}

/* ================= META: специалисты ================= */
if ($action === 'meta') {
    $m = jread($F_META, []);
    if (!isset($m['specialists'])) $m = ['specialists'=>['Анна','Ольга','Мария','Елена'], 'v'=>1];
    out(['ok'=>true, 'meta'=>$m]);
}

if ($action === 'save_meta') {
    $list = $body['specialists'] ?? [];
    if (!is_array($list)) $list = [];
    $out_l = [];
    foreach ($list as $s) { $s = mb_substr(trim((string)$s), 0, 40); if ($s !== '' && !in_array($s, $out_l, true)) $out_l[] = $s; }
    if (!$out_l) out(['ok'=>false,'err'=>'Нужен хотя бы один специалист.'], 400);
    $m = jread($F_META, ['v'=>0]);
    $m['specialists'] = $out_l;
    $m['v'] = (int)($m['v'] ?? 0) + 1;
    jwrite($F_META, $m);
    /* поднимем общую версию, чтобы все клиенты обновили meta */
    $b = book_load(); $b['version']++; $b['meta_v'] = $m['v']; book_save($b);
    out(['ok'=>true, 'meta'=>$m]);
}

/* ================= LEADS: заявки с сайта/приложения ================= */
function lead_row($j){
    $f = is_array($j['fields'] ?? null) ? $j['fields'] : [];
    /* желаемые дата/время: нормализуем из возможных вариантов полей */
    $wdate = ''; $wtime = '';
    foreach ($f as $k => $v) {
        if ($wdate === '' && preg_match('/(Желаемая дата|^Дата$|Желаемые дата)/u', (string)$k)) $wdate = (string)$v;
        if ($wtime === '' && preg_match('/(Желаемое время|^Время$)/u', (string)$k)) $wtime = (string)$v;
        if ($wdate === '' && preg_match('/Дата и время/u', (string)$k)) { /* старый формат одним полем */
            if (preg_match('/(\d{1,2}[.\/]\d{1,2}([.\/]\d{2,4})?)/u', (string)$v, $m)) $wdate = $m[1];
            if (preg_match('/(\d{1,2}:\d{2})/u', (string)$v, $m)) $wtime = $m[1];
            if ($wdate === '') $wdate = (string)$v;
        }
    }
    return ['id'=>$j['id'], 'ts'=>(int)($j['ts'] ?? 0), 'date'=>$j['date'] ?? '', 'name'=>$j['name'] ?? '', 'phone'=>$j['phone'] ?? '',
            'type'=>$j['type'] ?? '', 'page'=>$j['page'] ?? '', 'fields'=>$f, 'wdate'=>$wdate, 'wtime'=>$wtime];
}
if ($action === 'leads') {
    $proc = jread($F_LEADSP, []);
    $wantDone = !empty($_GET['done']); /* done=1 — обработанные заявки (для секции «Обработанные») */
    $rows = [];
    if (file_exists($F_LEADS)) {
        $lines = file($F_LEADS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -500);
        foreach ($lines as $ln) {
            $j = json_decode($ln, true);
            if (!is_array($j) || empty($j['id'])) continue;
            $p = $proc[$j['id']] ?? null;
            if ($wantDone) {
                if (!$p || empty($p['done'])) continue; /* только обработанные (удалённые не показываем — они в истории) */
                $r = lead_row($j);
                $r['done_by'] = (string)($p['by'] ?? '');
                $r['done_t'] = (int)$p['done'];
                $rows[] = $r;
            } else {
                if ($p) continue; /* обработана или удалена */
                $rows[] = lead_row($j);
            }
        }
    }
    if ($wantDone) {
        usort($rows, function($a, $b){ return ($b['done_t'] ?? 0) <=> ($a['done_t'] ?? 0); });
        $rows = array_slice($rows, 0, 50);
        out(['ok'=>true, 'leads'=>$rows]);
    }
    out(['ok'=>true, 'leads'=>array_reverse($rows)]);
}

if ($action === 'lead_done') {
    $id = (string)($body['id'] ?? '');
    if (!preg_match('/^klio-[\w-]+$/', $id)) out(['ok'=>false], 400);
    $proc = jread($F_LEADSP, []);
    $proc[$id] = ['done'=>time(), 'by'=>$me['name']];
    if (count($proc) > 3000) $proc = array_slice($proc, -3000, null, true);
    jwrite($F_LEADSP, $proc);
    $snap = lead_find($F_LEADS, $id);
    if ($snap) {
        $r = lead_row($snap);
        hist_log('lead_done', 'lead', $id, 'Заявка обработана',
            trim(($r['name'] ?: 'Клиент') . ' · ' . ($r['fields']['Услуга'] ?? $r['type'])),
            $me['name'] ?: '—', $me['src'], ['client'=>$r['name'], 'phone'=>$r['phone'], 'snap'=>$r]);
    }
    out(['ok'=>true]);
}

if ($action === 'lead_delete') {
    $id = (string)($body['id'] ?? '');
    if (!preg_match('/^klio-[\w-]+$/', $id)) out(['ok'=>false], 400);
    $snap = lead_find($F_LEADS, $id);
    $proc = jread($F_LEADSP, []);
    $proc[$id] = ['del'=>time(), 'by'=>$me['name']];
    if (count($proc) > 3000) $proc = array_slice($proc, -3000, null, true);
    jwrite($F_LEADSP, $proc);
    if ($snap) {
        $r = lead_row($snap);
        hist_log('lead_delete', 'lead', $id, 'Заявка удалена',
            trim(($r['name'] ?: 'Клиент') . ' · ' . ($r['fields']['Услуга'] ?? $r['type'])),
            $me['name'] ?: '—', $me['src'], ['client'=>$r['name'], 'phone'=>$r['phone'], 'del'=>1, 'snap'=>$r]);
    }
    out(['ok'=>true]);
}
function lead_find($f, $id){
    if (!file_exists($f)) return null;
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $j = json_decode($lines[$i], true);
        if (is_array($j) && ($j['id'] ?? '') === $id) return $j;
    }
    return null;
}

/* ================= STATE: синхронизация ================= */
if ($action === 'state') {
    $since = (int)($_GET['since'] ?? 0);
    $b = book_load();
    $now = time();
    $items = [];
    foreach ($b['items'] as $it) {
        if ((int)($it['v'] ?? 0) > $since) $items[] = !empty($it['deleted']) ? $it : pub_item($it, $now);
    }
    $resp = ['ok'=>true, 'version'=>(int)$b['version'], 'now'=>$now, 'items'=>$items];
    $m = jread($F_META, []);
    $mv = (int)($m['v'] ?? 1);
    if ($since === 0 || (int)($b['meta_v'] ?? 1) !== $mv || true) { /* meta лёгкая — отдаём всегда */
        if (!isset($m['specialists'])) $m = ['specialists'=>['Анна','Ольга','Мария','Елена'], 'v'=>1];
        $resp['meta'] = $m;
    }
    out($resp);
}

/* ================= SAVE: создание/изменение записи ================= */
if ($action === 'save') {
    $user = $me['name'] !== '' ? $me['name'] : '—';
    $b = book_load();
    $now = time();
    $id = (string)($body['id'] ?? '');
    $isNew = ($id === '');

    /* валидация полей */
    $date = (string)($body['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) out(['ok'=>false,'err'=>'Неверная дата.'], 400);
    $start = (string)($body['start'] ?? '');
    $end   = (string)($body['end'] ?? '');
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) out(['ok'=>false,'err'=>'Неверное время.'], 400);
    if (strtotime("$date $end") <= strtotime("$date $start")) out(['ok'=>false,'err'=>'Время окончания должно быть позже начала.'], 400);
    $client = mb_substr(trim((string)($body['client'] ?? '')), 0, 80);
    $phone  = mb_substr(trim((string)($body['phone'] ?? '')), 0, 30);
    $dir    = mb_substr(trim((string)($body['dir'] ?? '')), 0, 80);
    $serv   = mb_substr(trim((string)($body['service'] ?? '')), 0, 120);
    $spec   = mb_substr(trim((string)($body['spec'] ?? '')), 0, 40);
    $comm   = mb_substr(trim((string)($body['comment'] ?? '')), 0, 500);
    if ($serv === '') out(['ok'=>false,'err'=>'Укажите услугу.'], 400);
    if ($spec === '') out(['ok'=>false,'err'=>'Укажите специалиста.'], 400);

    $done   = !empty($body['done']) ? 1 : 0;
    $amount = $body['amount'] ?? null;
    $amount = ($amount === '' || $amount === null) ? null : max(0, (int)$amount);
    $pay    = in_array(($body['pay'] ?? ''), $PAY, true) ? (string)$body['pay'] : null;
    $reward = $body['reward'] ?? null;
    $reward = ($reward === '' || $reward === null) ? null : max(0, (int)$reward);
    $state  = in_array(($body['state'] ?? ''), ['canceled','noshow'], true) ? (string)$body['state'] : '';

    $b['version']++;
    $ver = $b['version'];

    if ($isNew) {
        $b['seq']++;
        $it = [
            'id'=>'b' . $b['seq'], 'date'=>$date, 'start'=>$start, 'end'=>$end,
            'client'=>$client, 'phone'=>$phone, 'dir'=>$dir, 'service'=>$serv, 'spec'=>$spec, 'comment'=>$comm,
            'done'=>$done, 'amount'=>$amount, 'pay'=>$pay, 'reward'=>$reward, 'state'=>$state,
            'history'=>[['t'=>$now, 'u'=>$user, 'm'=>'Создана запись']],
            'v'=>$ver,
        ];
        $b['items'][] = $it;
        book_save($b);
        hist_log('create', 'booking', $it['id'], 'Создана запись', book_info($it), $user, $me['src'],
            ['client'=>$client, 'phone'=>$phone, 'spec'=>$spec, 'service'=>$serv,
             'after'=>book_snap($it), 'snap'=>book_snap($it)]);
        out(['ok'=>true, 'version'=>$ver, 'item'=>pub_item($it, $now)]);
    }

    $idx = null;
    foreach ($b['items'] as $i => $x) if (($x['id'] ?? '') === $id) { $idx = $i; break; }
    if ($idx === null) out(['ok'=>false,'err'=>'Запись не найдена.'], 404);
    $old = $b['items'][$idx];

    /* история: сравниваем поля */
    $hist = [];
    $dRus = function($d){ $t = strtotime($d); return $t ? date('d.m.Y', $t) : $d; };
    if ($old['date'] !== $date || $old['start'] !== $start || $old['end'] !== $end)
        $hist[] = 'Изменено время: ' . $dRus($date) . ' ' . $start . '–' . $end;
    if (($old['service'] ?? '') !== $serv) $hist[] = 'Изменена услуга: ' . $serv;
    if (($old['spec'] ?? '') !== $spec) $hist[] = 'Изменён специалист: ' . $spec;
    if (($old['client'] ?? '') !== $client) $hist[] = 'Изменён клиент: ' . $client;
    if (($old['phone'] ?? '') !== $phone) $hist[] = 'Изменён телефон';
    if (($old['comment'] ?? '') !== $comm && $comm !== '') $hist[] = 'Изменён комментарий';
    if (empty($old['done']) && $done) $hist[] = 'Услуга оказана';
    $oldAmount = $old['amount'] ?? null;
    if ($oldAmount !== $amount && $amount !== null) $hist[] = ($oldAmount === null ? 'Внесена оплата: ' : 'Изменена оплата: ') . number_format($amount, 0, '', ' ') . ' ₽';
    if (($old['pay'] ?? null) !== $pay && $pay) $hist[] = 'Способ оплаты: ' . $PAY_RU[$pay];
    $oldReward = $old['reward'] ?? null;
    if ($oldReward !== $reward && $reward !== null) $hist[] = ($oldReward === null ? 'Внесено вознаграждение специалисту: ' : 'Изменено вознаграждение: ') . number_format($reward, 0, '', ' ') . ' ₽';
    $oldState = (string)($old['state'] ?? '');
    if ($oldState !== $state) {
        if ($state === 'canceled') $hist[] = 'Запись отменена';
        elseif ($state === 'noshow') $hist[] = 'Клиент не пришёл';
        elseif ($oldState !== '') $hist[] = 'Запись возвращена в работу';
    }

    $it = $old;
    $it['date'] = $date; $it['start'] = $start; $it['end'] = $end;
    $it['client'] = $client; $it['phone'] = $phone; $it['comment'] = $comm;
    $it['dir'] = $dir; $it['service'] = $serv; $it['spec'] = $spec;
    $it['done'] = $done; $it['amount'] = $amount; $it['pay'] = $pay; $it['reward'] = $reward; $it['state'] = $state;
    $it['v'] = $ver;
    foreach ($hist as $m) $it['history'][] = ['t'=>$now, 'u'=>$user, 'm'=>$m];
    if (count($it['history']) > 200) $it['history'] = array_slice($it['history'], -200);

    $b['items'][$idx] = $it;
    book_save($b);

    /* ----- типизированные события в журнал «История действий» ----- */
    $evBase = ['client'=>$client, 'phone'=>$phone, 'spec'=>$spec, 'service'=>$serv];
    $evInfo = book_info($it);
    $evs = []; /* [type, title, before, after, fin] */
    if ($old['date'] !== $date || $old['start'] !== $start || $old['end'] !== $end)
        $evs[] = ['reschedule', 'Перенос записи',
            ['Дата'=>$dRus($old['date']), 'Время'=>$old['start'] . '–' . $old['end']],
            ['Дата'=>$dRus($date), 'Время'=>$start . '–' . $end], 0];
    if (($old['service'] ?? '') !== $serv)
        $evs[] = ['service_change', 'Изменена услуга', ['Услуга'=>$old['service'] ?? ''], ['Услуга'=>$serv], 0];
    if (($old['spec'] ?? '') !== $spec)
        $evs[] = ['spec_change', 'Изменён специалист', ['Специалист'=>$old['spec'] ?? ''], ['Специалист'=>$spec], 0];
    $miscB = []; $miscA = [];
    if (($old['client'] ?? '') !== $client) { $miscB['Клиент'] = $old['client'] ?? ''; $miscA['Клиент'] = $client; }
    if (($old['phone'] ?? '') !== $phone)   { $miscB['Телефон'] = $old['phone'] ?? '';   $miscA['Телефон'] = $phone; }
    if (($old['comment'] ?? '') !== $comm)  { $miscB['Комментарий'] = $old['comment'] ?? ''; $miscA['Комментарий'] = $comm; }
    if ($miscB) $evs[] = ['edit', 'Запись отредактирована', $miscB, $miscA, 0];
    if (empty($old['done']) && $done) $evs[] = ['done', 'Услуга оказана', [], ['Оказана'=>'да'], 0];
    if ($oldAmount !== $amount && $amount !== null)
        $evs[] = [$oldAmount === null ? 'pay_new' : 'pay_change', $oldAmount === null ? 'Внесена оплата' : 'Изменена оплата',
            ['Сумма'=>$oldAmount === null ? '—' : number_format($oldAmount, 0, '', ' ') . ' ₽'],
            ['Сумма'=>number_format($amount, 0, '', ' ') . ' ₽'], 1];
    if (($old['pay'] ?? null) !== $pay && $pay)
        $evs[] = ['paymethod', 'Изменён способ оплаты',
            ['Способ'=>$PAY_RU[$old['pay'] ?? ''] ?? '—'], ['Способ'=>$PAY_RU[$pay]], 1];
    if ($oldReward !== $reward && $reward !== null)
        $evs[] = [$oldReward === null ? 'reward_new' : 'reward_change', $oldReward === null ? 'Внесено вознаграждение специалисту' : 'Изменено вознаграждение',
            ['Вознаграждение'=>$oldReward === null ? '—' : number_format($oldReward, 0, '', ' ') . ' ₽'],
            ['Вознаграждение'=>number_format($reward, 0, '', ' ') . ' ₽'], 1];
    if ($oldState !== $state) {
        if ($state === 'canceled') $evs[] = ['cancel', 'Запись отменена', [], [], 0];
        elseif ($state === 'noshow') $evs[] = ['noshow', 'Клиент не пришёл', [], [], 0];
        elseif ($oldState !== '') $evs[] = ['return', 'Запись возвращена в работу', [], [], 0];
    }
    if (eff_status($old) !== 'done' && eff_status($it) === 'done')
        $evs[] = ['complete', 'Запись завершена', [], [], 0];

    foreach ($evs as $e) {
        $extra = $evBase;
        if ($e[2]) $extra['before'] = $e[2];
        if ($e[3]) $extra['after']  = $e[3];
        if ($e[4]) $extra['fin'] = 1;
        hist_log($e[0], 'booking', $id, $e[1], $evInfo, $user, $me['src'], $extra);
    }

    out(['ok'=>true, 'version'=>$ver, 'item'=>pub_item($it, $now)]);
}

/* ================= DELETE: удаление записи (любого статуса, с следа́ми в журнале) ================= */
if ($action === 'delete') {
    $id = (string)($body['id'] ?? '');
    $b = book_load();
    foreach ($b['items'] as $i => $x) {
        if (($x['id'] ?? '') === $id) {
            $snap = book_snap($x);
            $info = book_info($x);
            array_splice($b['items'], $i, 1);
            $b['version']++;
            /* tombstone, чтобы клиенты убрали запись */
            $b['items'][] = ['id'=>$id, 'deleted'=>true, 'v'=>$b['version']];
            book_save($b);
            hist_log('delete', 'booking', $id, 'Запись удалена', $info, $me['name'] ?: '—', $me['src'],
                ['client'=>$snap['client'] ?? '', 'phone'=>$snap['phone'] ?? '', 'spec'=>$snap['spec'] ?? '',
                 'service'=>$snap['service'] ?? '', 'del'=>1, 'before'=>$snap, 'snap'=>$snap]);
            out(['ok'=>true, 'version'=>$b['version']]);
        }
    }
    out(['ok'=>false,'err'=>'Запись не найдена.'], 404);
}

/* ================= EVENTS: лента событий для опроса (пуши/синхронизация) ================= */
if ($action === 'events') {
    $since = (int)($_GET['since'] ?? 0);
    $buf = jread($F_EV, ['seq'=>0, 'items'=>[]]);
    $items = [];
    foreach (($buf['items'] ?? []) as $ev) if ((int)($ev['seq'] ?? 0) > $since) $items[] = $ev;
    out(['ok'=>true, 'seq'=>(int)($buf['seq'] ?? 0), 'items'=>$items]);
}

/* ================= HISTORY: журнал «История действий» (только чтение) ================= */
if ($action === 'history') {
    $q      = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $user   = trim((string)($_GET['user'] ?? ''));
    $type   = trim((string)($_GET['type'] ?? ''));
    $src    = trim((string)($_GET['src'] ?? ''));
    $spec   = trim((string)($_GET['spec'] ?? ''));
    $oid    = trim((string)($_GET['oid'] ?? ''));
    $from   = strtotime((string)($_GET['from'] ?? '')) ?: 0;
    $to     = strtotime(((string)($_GET['to'] ?? '')) . ' 23:59:59') ?: 0;
    $onlyDel = !empty($_GET['del']);
    $onlyFin = !empty($_GET['fin']);
    $tab    = trim((string)($_GET['tab'] ?? '')); /* new | del */
    $limit  = min(500, max(20, (int)($_GET['limit'] ?? 200)));

    $typesNew = ['create','lead_new'];
    $typesDel = ['delete','lead_delete'];

    $rows = [];
    if (file_exists($F_HIST)) {
        $lines = file($F_HIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($lines) - 1; $i >= 0 && count($rows) < $limit; $i--) {
            $ev = json_decode($lines[$i], true);
            if (!is_array($ev)) continue;
            $t = (int)($ev['t'] ?? 0);
            if ($from && $t < $from) continue;
            if ($to && $t > $to) continue;
            if ($user !== '' && (string)($ev['user'] ?? '') !== $user) continue;
            if ($type !== '' && (string)($ev['type'] ?? '') !== $type) continue;
            if ($src !== '' && (string)($ev['src'] ?? '') !== $src) continue;
            if ($spec !== '' && (string)($ev['spec'] ?? '') !== $spec) continue;
            if ($oid !== '' && (string)($ev['oid'] ?? '') !== $oid) continue;
            if ($onlyDel && empty($ev['del'])) continue;
            if ($onlyFin && empty($ev['fin'])) continue;
            if ($tab === 'new' && !in_array(($ev['type'] ?? ''), $typesNew, true)) continue;
            if ($tab === 'del' && !in_array(($ev['type'] ?? ''), $typesDel, true)) continue;
            if ($q !== '') {
                $hay = mb_strtolower(($ev['client'] ?? '') . ' ' . ($ev['phone'] ?? '') . ' ' .
                       ($ev['snap']['client'] ?? '') . ' ' . ($ev['snap']['phone'] ?? '') . ' ' .
                       ($ev['snap']['name'] ?? '') . ' ' . ($ev['info'] ?? ''));
                $qd = preg_replace('/\D/', '', $q);
                $ok = mb_strpos($hay, $q) !== false;
                if (!$ok && $qd !== '') $ok = strpos(preg_replace('/\D/', '', $hay), $qd) !== false;
                if (!$ok) continue;
            }
            $rows[] = $ev;
        }
    }
    /* справочники для фильтров */
    $users = []; $specs = []; $typesSeen = [];
    if (file_exists($F_HIST)) {
        $lines = file($F_HIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, -5000) as $ln) {
            $ev = json_decode($ln, true); if (!is_array($ev)) continue;
            if (!empty($ev['user'])) $users[$ev['user']] = 1;
            if (!empty($ev['spec'])) $specs[$ev['spec']] = 1;
            if (!empty($ev['type'])) $typesSeen[$ev['type']] = 1;
        }
    }
    out(['ok'=>true, 'rows'=>$rows, 'users'=>array_keys($users), 'specs'=>array_keys($specs), 'types'=>array_keys($typesSeen)]);
}

out(['ok'=>false,'err'=>'Неизвестное действие.'], 400);
