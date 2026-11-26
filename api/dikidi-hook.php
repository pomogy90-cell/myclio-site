<?php
/* КЛИО · приёмник webhook'ов DIKIDI (Callback API).
   URL для кабинета DIKIDI: https://myclio.ru/api/dikidi-hook.php?token=<hook_token>
   Что делает:
   - GET без данных        → проверка доступности ({"ok":true,"hook":"dikidi-hook",...})
   - POST/GET с записью    → создаёт/обновляет запись в книге салона (data/bookings.json),
                             пишет событие в журнал «История действий» (src=dikidi),
                             шлёт push команде (через fcm-push.php, если настроен)
   - &dry=1                → только разбор payload, без записи и без пушей (для тестов)
   Все сырые запросы складываются в data/dikidi-incoming.jsonl (закрыто .htaccess). */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '0');

function out($arr, $code = 200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function jread($f, $def){ if (!file_exists($f)) return $def; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $def; }
function jwrite($f, $d){ file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); }

/* ---------------- конфиг и доступ ---------------- */
$DATA_REAL = dirname(__FILE__) . '/data';
$F_CFG = $DATA_REAL . '/dikidi.json';      /* ключ API, company_id, hook_token (закрыт от веба) */
$cfg = jread($F_CFG, []);
$TOK = (string)($cfg['hook_token'] ?? '');
if ($TOK === '') out(['ok'=>false,'err'=>'hook_not_configured'], 503);
$tok = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals($TOK, $tok)) out(['ok'=>false,'err'=>'forbidden'], 403);

$dry = !empty($_GET['dry']);
/* selftest=1: полный прогон записи в изолированном каталоге data-dktest (после прогона удаляется).
   Продакшн-данные (bookings.json, журнал) не затрагиваются. */
$SELFTEST = !empty($_GET['selftest']);
$DATA = $SELFTEST ? dirname(__FILE__) . '/data-dktest' : $DATA_REAL;
if ($SELFTEST) {
    if (!is_dir($DATA)) @mkdir($DATA, 0750, true);
    /* selftest&cleanup=1 — просто убрать каталог после серии тестов */
    if (!empty($_GET['cleanup'])) {
        foreach (glob($DATA . '/*') ?: [] as $f) @unlink($f); @rmdir($DATA);
        out(['ok'=>true, 'mode'=>'selftest_cleanup']);
    }
    /* уборка в конце запроса, если не передан keep=1 (keep нужен для цепочки тестов) */
    if (empty($_GET['keep'])) {
        register_shutdown_function(function() use ($DATA){
            if (is_dir($DATA)) { foreach (glob($DATA . '/*') ?: [] as $f) @unlink($f); @rmdir($DATA); }
        });
    }
}

$F_BOOK = $DATA . '/bookings.json';        /* книга записей салона (формат manage.php) */
$F_HIST = $DATA . '/manage-history.jsonl'; /* append-only журнал */
$F_EV   = $DATA . '/manage-events.json';   /* кольцевой буфер событий для опроса */
$F_SEEN = $DATA . '/dikidi-seen.json';     /* карта «запись DIKIDI → наша запись» (идемпотентность) */
$F_RAW  = $DATA . '/dikidi-incoming.jsonl';/* сырьё всех обращений */
$F_LAST = $DATA . '/dikidi-last.json';     /* анти-флуд: последний принятый payload */

/* ---------------- читаем payload: JSON-тело | form-POST | GET-параметры ---------------- */
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 65536) out(['ok'=>false,'err'=>'too_big'], 413);
$payload = json_decode($raw, true);
if (!is_array($payload) || !$payload) {
    $payload = $_POST;
    if (!$payload && $raw !== '') { parse_str($raw, $payload); }
    /* вариант «data={json}» одним полем */
    if (is_array($payload) && isset($payload['data']) && is_string($payload['data'])) {
        $j = json_decode($payload['data'], true);
        if (is_array($j) && $j) $payload = $j;
    }
}
if (!is_array($payload)) $payload = [];
if (!$payload) {
    if ($q = $_GET) { unset($q['token'], $q['dry'], $q['selftest']); }
    if ($q) $payload = $q;
}

/* ---------------- ping: хук жив ---------------- */
if (!$payload) {
    $seen = jread($F_SEEN, []);
    out(['ok'=>true, 'hook'=>'dikidi-hook', 'php'=>PHP_VERSION, 'time'=>date('c'),
         'seen_records'=>count($seen),
         'raw_log_bytes'=>file_exists($F_RAW) ? filesize($F_RAW) : 0]);
}

/* ---------------- сырьё — в лог всегда (до любой обработки) ---------------- */
@file_put_contents($F_RAW, json_encode(['t'=>time(), 'ip'=>($_SERVER['REMOTE_ADDR'] ?? ''),
    'dry'=>$dry ? 1 : 0, 'payload'=>$payload], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);

/* ---------------- глубокий поиск полей (структура DIKIDI заранее неизвестна) ---------------- */
function deepFind($arr, $keys, $depth = 0){
    if (!is_array($arr) || $depth > 6) return null;
    foreach ($keys as $k) {
        foreach ($arr as $ak => $av) {
            if (is_string($ak) && mb_strtolower($ak) === $k && (is_scalar($av) || $av === null)) return $av;
        }
    }
    foreach ($arr as $av) {
        if (is_array($av)) { $r = deepFind($av, $keys, $depth + 1); if ($r !== null) return $r; }
    }
    return null;
}
/* поиск объекта с полем name (клиент/мастер могут быть вложенными объектами) */
function deepFindName($arr, $keys, $depth = 0){
    if (!is_array($arr) || $depth > 6) return null;
    foreach ($keys as $k) {
        foreach ($arr as $ak => $av) {
            if (!is_string($ak) || mb_strtolower($ak) !== $k) continue;
            if (is_scalar($av)) return $av;
            if (is_array($av)) { $n = deepFind($av, ['name','title','fio','full_name','fullname']); if ($n !== null) return $n; }
        }
    }
    foreach ($arr as $av) {
        if (is_array($av)) { $r = deepFindName($av, $keys, $depth + 1); if ($r !== null) return $r; }
    }
    return null;
}
/* услуги: либо строка, либо список объектов {name} → склеиваем */
function deepServices($arr, $depth = 0){
    if (!is_array($arr) || $depth > 6) return '';
    foreach ($arr as $ak => $av) {
        if (!is_string($ak)) continue;
        $lk = mb_strtolower($ak);
        if (in_array($lk, ['service_name','service','usluga'], true) && is_scalar($av)) return (string)$av;
        if ($lk === 'services' && is_array($av)) {
            $names = [];
            foreach ($av as $s) {
                if (is_array($s)) { $n = deepFind($s, ['name','title']); if ($n !== null && $n !== '') $names[] = (string)$n; }
                elseif (is_scalar($s) && (string)$s !== '') $names[] = (string)$s;
            }
            if ($names) return implode(' + ', $names);
        }
    }
    foreach ($arr as $av) {
        if (is_array($av)) { $r = deepServices($av, $depth + 1); if ($r !== '') return $r; }
    }
    return '';
}
function normDate($v){
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})/', $v, $m)) {
        $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return sprintf('%04d-%02d-%02d', (int)$y, (int)$m[2], (int)$m[1]);
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : '';
}
function normTime($v){
    $v = trim((string)$v);
    if (preg_match('/(\d{1,2}):(\d{2})/', $v, $m)) return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    return '';
}
function normPhone($v){
    $p = preg_replace('/\D/', '', (string)$v);
    if (strlen($p) === 11 && $p[0] === '8') $p = '7' . substr($p, 1);
    if (strlen($p) === 10) $p = '7' . $p;
    return $p;
}

/* ---------------- разбор записи ---------------- */
$rid     = deepFind($payload, ['record_id','recordid','entry_id','order_id','booking_id','appointment_id','id','number']);
$client  = deepFindName($payload, ['client_name','client_fio','client','customer','fio','clientname']);
$phone   = normPhone(deepFind($payload, ['client_phone','phone','client_phone_number','mobile','tel','telephone']));
$service = deepServices($payload);
$master  = deepFindName($payload, ['staff_name','master_name','master','staff','employee','specialist','worker']);
$date    = normDate(deepFind($payload, ['date','day','record_date','booking_date','start_date','datetime','start_time','start']));
$time    = normTime(deepFind($payload, ['time','start_time','time_from','from','start','datetime']));
$end     = normTime(deepFind($payload, ['end_time','time_to','to','end']));
$dur     = (int)(deepFind($payload, ['duration','length','minutes','duration_minutes']) ?? 0);
$note    = trim((string)(deepFind($payload, ['comment','comments','note','notes','description']) ?? ''));
$evName  = mb_strtolower(trim((string)(deepFind($payload, ['event','action','type','status','state']) ?? '')));

$isCancel = (bool)preg_match('/(cancel|delete|remove|отмен|удал)/u', $evName);
$isNoshow = (bool)preg_match('/(noshow|не приш)/u', $evName);

$client  = mb_substr(trim((string)$client), 0, 80);
$service = mb_substr(trim((string)$service), 0, 120);
$master  = mb_substr(trim((string)$master), 0, 40);
if ($client === '')  $client  = 'Клиент DIKIDI';
if ($service === '') $service = 'Услуга (DIKIDI)';
if ($master === '')  $master  = 'Не указан';

if ($date === '' || $time === '') {
    out(['ok'=>true, 'mode'=>'ignored', 'reason'=>'no_datetime',
         'hint'=>'в payload нет даты/времени — сырьё сохранено в dikidi-incoming.jsonl']);
}
if ($end === '') {
    $base = strtotime("$date $time");
    $end = date('H:i', $base + ($dur > 0 ? $dur * 60 : 3600));
}
if (strtotime("$date $end") <= strtotime("$date $time")) $end = date('H:i', strtotime("$date $time") + 3600);

$comment = mb_substr(trim('DIKIDI' . ($rid !== null && $rid !== '' ? ' №' . $rid : '') . ($note !== '' ? ' · ' . $note : '')), 0, 500);

/* ---------------- идемпотентность и анти-флуд ---------------- */
$dkey = ($rid !== null && $rid !== '') ? 'id:' . $rid
        : 'h:' . md5($client . '|' . $phone . '|' . $date . '|' . $time . '|' . $service);
$hash = md5(json_encode([$client, $phone, $service, $master, $date, $time, $end, $isCancel, $isNoshow]));

$last = jread($F_LAST, []);
if (($last['hash'] ?? '') === md5($raw) && (time() - (int)($last['t'] ?? 0)) < 5) {
    out(['ok'=>true, 'mode'=>'flood_skip']);
}
jwrite($F_LAST, ['hash'=>md5($raw), 't'=>time()]);

$seen  = jread($F_SEEN, []);
$known = isset($seen[$dkey]) && is_array($seen[$dkey]) ? $seen[$dkey] : null;
if ($known && ($known['hash'] ?? '') === $hash) {
    out(['ok'=>true, 'mode'=>'duplicate', 'booking'=>($known['booking'] ?? null)]);
}

if ($dry) {
    out(['ok'=>true, 'mode'=>'dry',
         'parsed'=>['rid'=>$rid, 'client'=>$client, 'phone'=>$phone, 'service'=>$service, 'spec'=>$master,
                    'date'=>$date, 'start'=>$time, 'end'=>$end, 'cancel'=>$isCancel, 'noshow'=>$isNoshow,
                    'comment'=>$comment],
         'known_booking'=>($known['booking'] ?? null)]);
}

/* ---------------- журнал «История действий» (формат manage.php) ---------------- */
$PENDING = [];
function dkh_hist($type, $oid, $title, $info, $extra = []){
    global $F_HIST, $F_EV, $PENDING;
    $ev = array_merge([
        't'=>time(), 'user'=>'DIKIDI', 'src'=>'dikidi', 'type'=>$type, 'obj'=>'booking',
        'oid'=>(string)$oid, 'title'=>$title, 'info'=>$info,
    ], $extra);
    $buf = jread($F_EV, ['seq'=>0,'items'=>[]]);
    $buf['seq'] = (int)($buf['seq'] ?? 0) + 1;
    $ev['seq'] = $buf['seq'];
    @file_put_contents($F_HIST, json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $buf['items'][] = $ev;
    if (count($buf['items']) > 300) $buf['items'] = array_slice($buf['items'], -300);
    jwrite($F_EV, $buf);
    $PENDING[] = $ev;
    return $ev;
}
function dkh_info($it){
    $t = strtotime((string)($it['date'] ?? ''));
    $d = $t ? date('d.m.Y', $t) : (string)($it['date'] ?? '');
    return trim($d . ' ' . ($it['start'] ?? '') . '–' . ($it['end'] ?? '') . ' · ' . ($it['service'] ?? '') . ' · ' . ($it['spec'] ?? ''));
}
function dkh_snap($it){
    $s = [];
    foreach (['date','start','end','client','phone','dir','service','spec','comment','done','amount','pay','reward','state'] as $k)
        $s[$k] = $it[$k] ?? null;
    return $s;
}

/* ---------------- запись в книгу салона ---------------- */
$b = jread($F_BOOK, []);
if (!isset($b['version'])) $b = ['version'=>0, 'seq'=>0, 'items'=>[]];
$now = time();
$mode = 'created';
$it = null;

if ($known && !empty($known['booking'])) {
    foreach ($b['items'] as $i => $x) {
        if (($x['id'] ?? '') !== $known['booking'] || !empty($x['deleted'])) continue;
        $it = $x;
        $chg = [];
        if ($it['date'] !== $date || $it['start'] !== $time || $it['end'] !== $end) $chg[] = 'time';
        if (($it['service'] ?? '') !== $service) $chg[] = 'service';
        if (($it['spec'] ?? '') !== $master)     $chg[] = 'spec';
        if (($it['client'] ?? '') !== $client)   $chg[] = 'client';
        $newState = $isCancel ? 'canceled' : ($isNoshow ? 'noshow' : '');
        if ($newState !== '' && ($it['state'] ?? '') !== $newState) $chg[] = 'state';
        if (!$chg) {
            $seen[$dkey]['hash'] = $hash; jwrite($F_SEEN, $seen);
            out(['ok'=>true, 'mode'=>'unchanged', 'booking'=>$it['id']]);
        }
        $before = dkh_snap($it);
        $b['version']++;
        $it['date']=$date; $it['start']=$time; $it['end']=$end;
        $it['service']=$service; $it['spec']=$master; $it['client']=$client;
        if ($phone !== '') $it['phone']=$phone;
        if ($newState !== '') $it['state']=$newState;
        $it['v'] = $b['version'];
        $it['history'][] = ['t'=>$now, 'u'=>'DIKIDI', 'm'=>'Обновлено из DIKIDI (' . implode(', ', $chg) . ')'];
        if (count($it['history']) > 200) $it['history'] = array_slice($it['history'], -200);
        $b['items'][$i] = $it;
        jwrite($F_BOOK, $b);

        $info = dkh_info($it);
        $base = ['client'=>$client, 'phone'=>$phone, 'spec'=>$master, 'service'=>$service, 'snap'=>dkh_snap($it)];
        if (in_array('time', $chg, true))
            dkh_hist('reschedule', $it['id'], 'Перенос записи', $info, $base + ['before'=>['Дата'=>date('d.m.Y', strtotime($before['date'])), 'Время'=>$before['start'] . '–' . $before['end']], 'after'=>['Дата'=>date('d.m.Y', strtotime($date)), 'Время'=>$time . '–' . $end]]);
        if ($newState === 'canceled') dkh_hist('cancel', $it['id'], 'Запись отменена', $info, $base);
        elseif ($newState === 'noshow') dkh_hist('noshow', $it['id'], 'Клиент не пришёл', $info, $base);
        elseif (!in_array('time', $chg, true)) dkh_hist('edit', $it['id'], 'Запись изменена (DIKIDI)', $info, $base);
        $mode = ($newState !== '') ? $newState : 'updated';
        break;
    }
}

if ($it === null) {
    $b['version']++;
    $b['seq']++;
    $it = [
        'id'=>'b' . $b['seq'], 'date'=>$date, 'start'=>$time, 'end'=>$end,
        'client'=>$client, 'phone'=>$phone, 'dir'=>'', 'service'=>$service, 'spec'=>$master,
        'comment'=>$comment,
        'done'=>0, 'amount'=>null, 'pay'=>null, 'reward'=>null,
        'state'=>$isCancel ? 'canceled' : ($isNoshow ? 'noshow' : ''),
        'history'=>[['t'=>$now, 'u'=>'DIKIDI', 'm'=>'Создана запись (онлайн-запись DIKIDI)']],
        'v'=>$b['version'],
    ];
    $b['items'][] = $it;
    jwrite($F_BOOK, $b);
    dkh_hist('create', $it['id'], 'Создана запись', dkh_info($it),
        ['client'=>$client, 'phone'=>$phone, 'spec'=>$master, 'service'=>$service,
         'after'=>dkh_snap($it), 'snap'=>dkh_snap($it)]);
    $mode = 'created';
}

$seen[$dkey] = ['booking'=>$it['id'], 'hash'=>$hash, 't'=>$now];
if (count($seen) > 5000) $seen = array_slice($seen, -5000, null, true);
jwrite($F_SEEN, $seen);

/* ---------------- push команде — после ответа DIKIDI ---------------- */
require_once __DIR__ . '/fcm-push.php';
register_shutdown_function(function() use ($PENDING, $SELFTEST){
    if (!$SELFTEST && $PENDING && fcm_ready()) {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        foreach ($PENDING as $ev) fcm_broadcast($ev);
    }
});

out(['ok'=>true, 'mode'=>$mode, 'booking'=>$it['id'], 'version'=>$b['version']] + ($SELFTEST ? ['selftest'=>true] : []));
