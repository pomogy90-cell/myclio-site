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
$F_CASH    = $DATA . '/manage-cash.json';     /* касса: начальный остаток + журнал выдач (недоступна из веба) */

function out($arr, $code = 200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function jread($f, $def){ if (!file_exists($f)) return $def; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $def; }
function jwrite($f, $d){ file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); }
require_once __DIR__.'/master-catalog.php';

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
    foreach (['date','start','end','client','phone','dir','service','services','spec','spec_id','comment','done','amount','pay','reward','state','src','needinfo','price_src'] as $k)
        $s[$k] = $it[$k] ?? null;
    return $s;
}

/* ----- блокировка-хранилище записей ----- */
function book_load(){ global $F_BOOK; $b = jread($F_BOOK, []); if (!isset($b['version'])) $b = ['version'=>0,'seq'=>0,'items'=>[]]; return $b; }
function book_save($b){ global $F_BOOK; jwrite($F_BOOK, $b); }

/* ----- касса: накопительный учёт наличных -----
   Остаток = начальный остаток + поступления наличными (за вычетом вознаграждения мастера)
   + ручные внесения − выдачи.
   Поступление = активная (не отменённая/не удалённая) запись со способом оплаты «Наличные»,
   оплата которой внесена после задания начального остатка (метка cash_at ставится при сохранении).
   28.11-В16/ТЗ-5: вознаграждение мастеру выдаётся из наличных сразу и в кассу не попадает. */
function cash_default(){ return ['opening'=>0, 'opening_set'=>false, 'opening_at'=>0, 'opening_by'=>'', 'payouts'=>[], 'deposits'=>[]]; }
/* статьи расходов категории «Затраты» (27.11-В11) */
function cash_articles(){
    return ['Расходы на салон «Клио»', 'Офисные расходы', 'Представительские расходы',
            'Оплата сервисов и программного обеспечения', 'Оплата сторонних услуг',
            'Оплата связи и коммуникаций', 'Прочие расходы'];
}
function cash_load(){
    global $F_CASH;
    $c = jread($F_CASH, cash_default());
    if (!isset($c['payouts']) || !is_array($c['payouts'])) $c['payouts'] = [];
    if (!isset($c['deposits']) || !is_array($c['deposits'])) $c['deposits'] = []; /* 28.11-В17: ручные внесения */
    /* миграция формата В10: было purpose (свободный текст) → категория «Затраты»,
       статья «Прочие расходы», прежний текст назначения — в комментарий */
    $mig = false;
    foreach ($c['payouts'] as $i => $p) {
        if (isset($p['cat'])) continue;
        $cm = trim((string)($p['purpose'] ?? ''));
        if (!empty($p['comment'])) $cm = trim($cm . ($cm !== '' ? ' · ' : '') . (string)$p['comment']);
        $c['payouts'][$i]['cat'] = 'cost';
        $c['payouts'][$i]['article'] = 'Прочие расходы';
        $c['payouts'][$i]['comment'] = $cm;
        unset($c['payouts'][$i]['purpose']);
        $mig = true;
    }
    if ($mig) jwrite($F_CASH, $c);
    return $c;
}
function cash_receipts($b, $from){
    $in = 0; $rw = 0; $list = [];
    foreach (($b['items'] ?? []) as $it) {
        if (!empty($it['deleted'])) continue;
        if (!empty($it['state'])) continue;            /* отмена/неявка — деньги не в кассе */
        if (($it['pay'] ?? null) !== 'cash') continue; /* безналичные способы кассу не пополняют */
        $a = (int)($it['amount'] ?? 0); if ($a <= 0) continue;
        $ct = (int)($it['cash_at'] ?? 0);
        if (!$ct || $ct < $from) continue;             /* оплата внесена до старта учёта */
        $in += $a;
        /* 28.11-В16: вознаграждение мастеру из наличных выдаётся сразу и в кассу не попадает */
        $r = min($a, max(0, (int)($it['reward'] ?? 0)));
        $rw += $r;
        /* 28.11-В17 (ТЗ-5 п.4): поступление — отдельная строка истории кассы */
        $svc = '';
        if (!empty($it['services']) && is_array($it['services'])) {
            $svc = (string)$it['services'][0];
            if (count($it['services']) > 1) $svc .= ' +' . (count($it['services']) - 1);
        } else $svc = (string)($it['service'] ?? '');
        $list[] = ['bid'=>(string)($it['id'] ?? ''), 'at'=>$ct, 'amount'=>$a, 'reward'=>$r, 'net'=>$a - $r,
                   'svc'=>$svc, 'spec'=>(string)($it['spec'] ?? '')];
    }
    return [$in, $rw, $list];
}
function cash_public($cash, $b){
    $set = !empty($cash['opening_set']);
    $in = 0; $rw = 0; $rc = [];
    if ($set) { [$in, $rw, $rc] = cash_receipts($b, (int)($cash['opening_at'] ?? 0)); }
    $out = 0;
    foreach ($cash['payouts'] as $p) $out += (int)($p['amount'] ?? 0);
    $dep = 0;
    foreach (($cash['deposits'] ?? []) as $d) $dep += (int)($d['amount'] ?? 0);
    $opening = $set ? (int)($cash['opening'] ?? 0) : 0;
    $ps = array_values($cash['payouts']);
    if (count($ps) > 500) $ps = array_slice($ps, -500);
    /* 28.11-В17 (ТЗ-5 п.4): единая история кассы — автопоступления, ручные внесения, изъятия */
    $hist = [];
    foreach ($rc as $r) $hist[] = ['kind'=>'receipt', 't'=>$r['at'], 'amount'=>$r['net'], 'gross'=>$r['amount'],
                                   'reward'=>$r['reward'], 'bid'=>$r['bid'], 'svc'=>$r['svc'], 'spec'=>$r['spec']];
    foreach (($cash['deposits'] ?? []) as $d)
        $hist[] = ['kind'=>'deposit', 't'=>(int)($d['t'] ?? 0), 'amount'=>(int)($d['amount'] ?? 0),
                   'source'=>(string)($d['source'] ?? ''), 'comment'=>(string)($d['comment'] ?? ''), 'by'=>(string)($d['by'] ?? '')];
    foreach ($cash['payouts'] as $p)
        $hist[] = ['kind'=>'payout', 't'=>(int)($p['t'] ?? 0), 'amount'=>(int)($p['amount'] ?? 0),
                   'cat'=>(string)($p['cat'] ?? 'cost'), 'article'=>(string)($p['article'] ?? ''),
                   'receiver'=>(string)($p['receiver'] ?? ''), 'comment'=>(string)($p['comment'] ?? ''), 'by'=>(string)($p['by'] ?? '')];
    usort($hist, function($a, $b){ return ($b['t'] ?? 0) <=> ($a['t'] ?? 0); });
    if (count($hist) > 300) $hist = array_slice($hist, 0, 300);
    return ['opening_set'=>$set, 'opening'=>$opening, 'in'=>$in - $rw, 'in_full'=>$in, 'rewards'=>$rw,
            'dep'=>$dep, 'out'=>$out, 'balance'=>$opening + $in - $rw + $dep - $out, 'payouts'=>$ps, 'history'=>$hist];
}

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

/* псевдо-cron: синхронизация DIKIDI запускается сама не чаще раза в ~60 сек,
   пока кто-то работает с приложением/админкой (см. api/gcal-cron.php) */
if (is_file(__DIR__ . '/gcal-cron.php')) {
    require_once __DIR__ . '/gcal-cron.php';
    try { gcal_maybe_spawn(); } catch (Throwable $e) {}
}

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
    $m['master_profiles_view']=klio_profiles($m);
    out(['ok'=>true, 'meta'=>$m]);
}

if ($action === 'save_meta') {
    $m = jread($F_META, ['specialists'=>['Анна','Ольга','Мария','Елена'], 'v'=>1]);
    if (isset($body['master_profiles'])) {
        if (!is_array($body['master_profiles']) || count($body['master_profiles'])>300) out(['ok'=>false,'err'=>'Неверный список специалистов.'],400);
        if ((int)($body['meta_v'] ?? -1) !== (int)($m['v'] ?? 0)) out(['ok'=>false,'err'=>'Список изменился. Откройте раздел заново.'],409);
        $old=klio_profiles($m); $catalog=klio_catalog(); $profiles=[]; $names=[]; $ids=[]; $active=[];
        if (!$catalog) out(['ok'=>false,'err'=>'Прайс недоступен.'],503);
        foreach ($body['master_profiles'] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) out(['ok'=>false,'err'=>'Укажите имя специалиста.'],400);
            $name=trim($row['name']); if ($name==='' || mb_strlen($name)>40 || in_array($name,$names,true)) out(['ok'=>false,'err'=>'Имена должны быть уникальными, до 40 символов.'],400);
            $id=(string)($row['id'] ?? '');
            if ($id!=='' && !klio_find_master($old,'',$id)) out(['ok'=>false,'err'=>'Неизвестный специалист.'],400);
            if ($id==='') { $prior=klio_find_master($old,$name); $id=$prior['id'] ?? bin2hex(random_bytes(16)); }
            if (in_array($id,$ids,true)) out(['ok'=>false,'err'=>'Повтор специалиста.'],400);
            $enabled=!empty($row['active']); $services=[];
            foreach (($row['services'] ?? []) as $s) {
                if (!is_array($s)) continue;
                foreach ($catalog as $c) if (($s['dir'] ?? '')===$c['dir'] && ($s['name'] ?? '')===$c['name']) {
                    $v=['dir'=>$c['dir'],'name'=>$c['name']]; if (!in_array($v,$services,true)) $services[]=$v; break;
                }
            }
            if ($enabled && !$services) out(['ok'=>false,'err'=>'Назначьте услуги мастеру «'.$name.'» или отключите его.'],400);
            $profiles[]=['id'=>$id,'name'=>$name,'active'=>$enabled,'configured'=>true,'services'=>$services];
            $names[]=$name; $ids[]=$id; if ($enabled) $active[]=$name;
        }
        foreach ($old as $o) if (!in_array($o['id'],$ids,true)) out(['ok'=>false,'err'=>'Существующего специалиста можно отключить, но не удалять.'],400);
        $m['master_profiles']=$profiles; $m['specialists']=$active;
    } else {
        if (isset($m['master_profiles'])) out(['ok'=>false,'err'=>'Обновите приложение для редактирования специалистов.'],409);
        $list = $body['specialists'] ?? []; $out_l = [];
        if (is_array($list)) foreach ($list as $s) { $s=mb_substr(trim((string)$s),0,40); if ($s!==''&&!in_array($s,$out_l,true)) $out_l[]=$s; }
        if (!$out_l) out(['ok'=>false,'err'=>'Нужен хотя бы один специалист.'],400);
        $m['specialists']=$out_l;
    }
    $m['v']=(int)($m['v'] ?? 0)+1;
    if (file_put_contents($F_META,json_encode($m,JSON_UNESCAPED_UNICODE),LOCK_EX)===false) out(['ok'=>false,'err'=>'Не удалось сохранить список.'],500);
    out(['ok'=>true,'meta'=>$m]);
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
    $resp['cash'] = cash_public(cash_load(), $b); /* касса: остаток всегда актуальный, от периода не зависит */
    $m = jread($F_META, []);
    $mv = (int)($m['v'] ?? 1);
    if ($since === 0 || (int)($b['meta_v'] ?? 1) !== $mv || true) { /* meta лёгкая — отдаём всегда */
        if (!isset($m['specialists'])) $m = ['specialists'=>['Анна','Ольга','Мария','Елена'], 'v'=>1];
        $m['master_profiles_view']=klio_profiles($m);
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

    /* мульти-услуги: services[] — полный список, service — первая (основная) */
    $services = [];
    if (isset($body['services']) && is_array($body['services'])) {
        foreach ($body['services'] as $sv) {
            $sv = mb_substr(trim((string)$sv), 0, 120);
            if ($sv !== '' && !in_array($sv, $services, true)) $services[] = $sv;
            if (count($services) >= 10) break;
        }
    }
    if (!$services) $services = [$serv];
    if ($services[0] !== $serv) $serv = $services[0];
    $svcLine = implode(' + ', $services);
    $prev=null;
    if (!$isNew) foreach ($b['items'] as $existing) if (($existing['id'] ?? '')===$id) { $prev=$existing; break; }
    $prevServices=$prev['services'] ?? [$prev['service'] ?? ''];
    $assignmentChanged=$isNew || ($prev && (($prev['spec'] ?? '')!==$spec || ($prev['dir'] ?? '')!==$dir || $prevServices!==$services));
    $masterMeta=jread($F_META,[]);
    if ($assignmentChanged && !klio_assignment_ok($masterMeta,$spec,$dir,$services)) out(['ok'=>false,'err'=>'Специалист не оказывает выбранные услуги.'],422);
    $profile=klio_find_master(klio_profiles($masterMeta),$spec);
    $specId=$profile['id'] ?? null;

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
            'client'=>$client, 'phone'=>$phone, 'dir'=>$dir, 'service'=>$serv, 'services'=>$services, 'spec'=>$spec, 'spec_id'=>$specId, 'comment'=>$comm,
            'done'=>$done, 'amount'=>$amount, 'pay'=>$pay, 'reward'=>$reward, 'state'=>$state,
            'cash_at'=>null,
            'history'=>[['t'=>$now, 'u'=>$user, 'm'=>'Создана запись']],
            'v'=>$ver,
        ];
        /* метка поступления наличных в кассу: первая фиксация оплаты «Наличные» */
        if ($pay === 'cash' && $amount !== null && $amount > 0 && $state === '') $it['cash_at'] = $now;
        $b['items'][] = $it;
        book_save($b);
        hist_log('create', 'booking', $it['id'], 'Создана запись', book_info($it), $user, $me['src'],
            ['client'=>$client, 'phone'=>$phone, 'spec'=>$spec, 'service'=>$svcLine,
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
    $oldSvcLine = (isset($old['services']) && is_array($old['services']) && $old['services']) ? implode(' + ', $old['services']) : (string)($old['service'] ?? '');
    if ($oldSvcLine !== $svcLine) $hist[] = ($oldSvcLine === '' ? 'Указана услуга: ' : 'Изменены услуги: ') . $svcLine;
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
    $it['dir'] = $dir; $it['service'] = $serv; $it['services'] = $services; $it['spec'] = $spec;
    if ($assignmentChanged) $it['spec_id']=$specId;
    $it['done'] = $done; $it['amount'] = $amount; $it['pay'] = $pay; $it['reward'] = $reward; $it['state'] = $state;
    /* метка поступления наличных: ставится один раз при первой фиксации оплаты «Наличные»;
       записи, закрытые до появления кассы, метку не получают и в учёт не попадают */
    if ($pay === 'cash' && $amount !== null && $amount > 0 && $state === '' && empty($it['cash_at'])) $it['cash_at'] = $now;
    $it['v'] = $ver;
    foreach ($hist as $m) $it['history'][] = ['t'=>$now, 'u'=>$user, 'm'=>$m];
    if (count($it['history']) > 200) $it['history'] = array_slice($it['history'], -200);

    $b['items'][$idx] = $it;
    book_save($b);

    /* ----- типизированные события в журнал «История действий» ----- */
    $evBase = ['client'=>$client, 'phone'=>$phone, 'spec'=>$spec, 'service'=>$svcLine];
    $evInfo = book_info($it);
    $evs = []; /* [type, title, before, after, fin] */
    if ($old['date'] !== $date || $old['start'] !== $start || $old['end'] !== $end)
        $evs[] = ['reschedule', 'Перенос записи',
            ['Дата'=>$dRus($old['date']), 'Время'=>$old['start'] . '–' . $old['end']],
            ['Дата'=>$dRus($date), 'Время'=>$start . '–' . $end], 0];
    if ($oldSvcLine !== $svcLine)
        $evs[] = ['service_change', 'Изменены услуги', ['Услуги'=>$oldSvcLine], ['Услуги'=>$svcLine], 0];
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

/* ================= CASH_OPENING: однократный ввод начального остатка кассы ================= */
if ($action === 'cash_opening') {
    $amount = $body['amount'] ?? null;
    $amount = ($amount === '' || $amount === null) ? null : (int)$amount;
    if ($amount === null || $amount < 0 || $amount > 100000000) out(['ok'=>false,'err'=>'Укажите корректную сумму начального остатка.'], 400);

    $fp = fopen($F_CASH, 'c+');
    if (!$fp) out(['ok'=>false,'err'=>'Нет доступа к хранилищу кассы.'], 500);
    flock($fp, LOCK_EX);
    $cash = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($cash)) $cash = cash_default();
    if (!empty($cash['opening_set'])) {
        flock($fp, LOCK_UN); fclose($fp);
        out(['ok'=>false,'err'=>'Начальный остаток уже задан (' . number_format((int)$cash['opening'], 0, '', ' ') . ' ₽) и изменению не подлежит.'], 409);
    }
    $now = time();
    $cash['opening'] = $amount;
    $cash['opening_set'] = true;
    $cash['opening_at'] = $now;
    $cash['opening_by'] = $me['name'] !== '' ? $me['name'] : '—';
    if (!isset($cash['payouts']) || !is_array($cash['payouts'])) $cash['payouts'] = [];
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($cash, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);

    hist_log('cash_opening', 'cash', 'opening', 'Касса: задан начальный остаток',
        'Начальный остаток наличных: ' . number_format($amount, 0, '', ' ') . ' ₽',
        $me['name'] !== '' ? $me['name'] : '—', $me['src'],
        ['fin'=>1, 'after'=>['Начальный остаток'=>number_format($amount, 0, '', ' ') . ' ₽']]);
    out(['ok'=>true, 'cash'=>cash_public($cash, book_load())]);
}

/* ================= CASH_PAYOUT: изъятие наличных из кассы (затраты / прибыль) ================= */
if ($action === 'cash_payout') {
    $amount = $body['amount'] ?? null;
    $amount = ($amount === '' || $amount === null) ? null : (int)$amount;
    $cat      = in_array(($body['cat'] ?? ''), ['cost','profit'], true) ? (string)$body['cat'] : '';
    $article  = mb_substr(trim((string)($body['article'] ?? '')), 0, 120);
    $receiver = mb_substr(trim((string)($body['receiver'] ?? '')), 0, 80);
    $comment  = mb_substr(trim((string)($body['comment'] ?? '')), 0, 300);
    $op       = mb_substr(trim((string)($body['op'] ?? '')), 0, 64);
    /* совместимость с клиентом В10: purpose без cat → затраты, статья «Прочие расходы» */
    $purpose  = mb_substr(trim((string)($body['purpose'] ?? '')), 0, 160);
    if ($cat === '' && $purpose !== '') {
        $cat = 'cost'; $article = 'Прочие расходы';
        $comment = trim($purpose . ($comment !== '' ? ' · ' . $comment : ''));
    }
    if ($amount === null || $amount <= 0 || $amount > 100000000) out(['ok'=>false,'err'=>'Укажите корректную сумму изъятия.'], 400);
    if ($cat === '') out(['ok'=>false,'err'=>'Укажите категорию операции.'], 400);
    if ($cat === 'cost') {
        if (!in_array($article, cash_articles(), true)) out(['ok'=>false,'err'=>'Выберите статью расходов.'], 400);
        if ($receiver === '') out(['ok'=>false,'err'=>'Укажите получателя денежных средств.'], 400);
    } else { /* прибыль: статья и получатель не требуются */
        $article = ''; $receiver = '';
    }

    /* вся операция — под эксклюзивной блокировкой: две одновременные выдачи не создадут минус */
    $fp = fopen($F_CASH, 'c+');
    if (!$fp) out(['ok'=>false,'err'=>'Нет доступа к хранилищу кассы.'], 500);
    flock($fp, LOCK_EX);
    $cash = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($cash)) $cash = cash_default();
    if (!isset($cash['payouts']) || !is_array($cash['payouts'])) $cash['payouts'] = [];
    if (empty($cash['opening_set'])) {
        flock($fp, LOCK_UN); fclose($fp);
        out(['ok'=>false,'err'=>'Сначала задайте начальный остаток кассы.'], 400);
    }
    /* миграция старого формата выдач (В10) внутри блокировки */
    foreach ($cash['payouts'] as $i => $p) {
        if (isset($p['cat'])) continue;
        $cm = trim((string)($p['purpose'] ?? ''));
        if (!empty($p['comment'])) $cm = trim($cm . ($cm !== '' ? ' · ' : '') . (string)$p['comment']);
        $cash['payouts'][$i]['cat'] = 'cost';
        $cash['payouts'][$i]['article'] = 'Прочие расходы';
        $cash['payouts'][$i]['comment'] = $cm;
        unset($cash['payouts'][$i]['purpose']);
    }
    /* идемпотентность: повторная отправка той же операции (двойной тап, обрыв сети) не списывает деньги дважды */
    if ($op !== '') {
        foreach ($cash['payouts'] as $p) {
            if (($p['op'] ?? '') === $op) {
                $pub = cash_public($cash, book_load());
                flock($fp, LOCK_UN); fclose($fp);
                out(['ok'=>true, 'dup'=>true, 'cash'=>$pub]);
            }
        }
    }
    $b = book_load();
    $pub = cash_public($cash, $b);
    if ($amount > (int)$pub['balance']) {
        flock($fp, LOCK_UN); fclose($fp);
        out(['ok'=>false,'err'=>'Недостаточно наличных в кассе: доступно ' . number_format((int)$pub['balance'], 0, '', ' ') . ' ₽.'], 400);
    }
    $now = time();
    $maxId = 0;
    foreach ($cash['payouts'] as $p) if (preg_match('/^p(\d+)$/', (string)($p['id'] ?? ''), $m)) $maxId = max($maxId, (int)$m[1]);
    $it = ['id'=>'p' . ($maxId + 1), 'op'=>$op, 't'=>$now, 'amount'=>$amount,
           'cat'=>$cat, 'article'=>$article, 'receiver'=>$receiver, 'comment'=>$comment,
           'by'=>$me['name'] !== '' ? $me['name'] : '—'];
    $cash['payouts'][] = $it;
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($cash, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);

    $catRu = $cat === 'profit' ? 'Прибыль' : 'Затраты';
    hist_log('cash_payout', 'cash', $it['id'],
        $cat === 'profit' ? 'Касса: изъятие (прибыль)' : 'Касса: изъятие (затраты)',
        number_format($amount, 0, '', ' ') . ' ₽ · ' . ($cat === 'profit' ? 'Прибыль' : $article) . ($receiver !== '' ? ' · ' . $receiver : ''),
        $me['name'] !== '' ? $me['name'] : '—', $me['src'],
        ['fin'=>1, 'after'=>[
            'Сумма'=>number_format($amount, 0, '', ' ') . ' ₽',
            'Категория'=>$catRu,
            'Статья'=>$article !== '' ? $article : '—',
            'Получатель'=>$receiver !== '' ? $receiver : '—',
            'Комментарий'=>$comment !== '' ? $comment : '—']]);
    out(['ok'=>true, 'cash'=>cash_public($cash, $b)]);
}

/* ================= CASH_DEPOSIT: ручное внесение наличных в кассу (28.11-В17, ТЗ-5 п.3) =================
   Внесение — самостоятельная операция с наличными: НЕ выручка, НЕ вознаграждение, прибыльность не меняет. */
if ($action === 'cash_deposit') {
    $amount = $body['amount'] ?? null;
    $amount = ($amount === '' || $amount === null) ? null : (int)$amount;
    $source  = mb_substr(trim((string)($body['source'] ?? '')), 0, 160);
    $comment = mb_substr(trim((string)($body['comment'] ?? '')), 0, 300);
    $op      = mb_substr(trim((string)($body['op'] ?? '')), 0, 64);
    if ($amount === null || $amount < 1 || $amount > 100000000) out(['ok'=>false,'err'=>'Укажите сумму внесения (от 1 ₽).'], 400);
    if ($source === '') out(['ok'=>false,'err'=>'Укажите источник поступления денежных средств.'], 400);

    $fp = fopen($F_CASH, 'c+');
    if (!$fp) out(['ok'=>false,'err'=>'Нет доступа к хранилищу кассы.'], 500);
    flock($fp, LOCK_EX);
    $cash = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($cash)) $cash = cash_default();
    if (!isset($cash['payouts']) || !is_array($cash['payouts'])) $cash['payouts'] = [];
    if (!isset($cash['deposits']) || !is_array($cash['deposits'])) $cash['deposits'] = [];
    if (empty($cash['opening_set'])) {
        flock($fp, LOCK_UN); fclose($fp);
        out(['ok'=>false,'err'=>'Сначала задайте начальный остаток кассы.'], 400);
    }
    /* идемпотентность: повтор той же операции (двойной тап, обрыв сети) не вносит деньги дважды */
    if ($op !== '') {
        foreach ($cash['deposits'] as $d) {
            if (($d['op'] ?? '') === $op) {
                $pub = cash_public($cash, book_load());
                flock($fp, LOCK_UN); fclose($fp);
                out(['ok'=>true, 'dup'=>true, 'cash'=>$pub]);
            }
        }
    }
    $now = time();
    $maxId = 0;
    foreach ($cash['deposits'] as $d) if (preg_match('/^d(\d+)$/', (string)($d['id'] ?? ''), $m)) $maxId = max($maxId, (int)$m[1]);
    $it = ['id'=>'d' . ($maxId + 1), 'op'=>$op, 't'=>$now, 'amount'=>$amount,
           'source'=>$source, 'comment'=>$comment, 'by'=>$me['name'] !== '' ? $me['name'] : '—'];
    $cash['deposits'][] = $it;
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($cash, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);

    hist_log('cash_deposit', 'cash', $it['id'], 'Касса: ручное внесение наличных',
        number_format($amount, 0, '', ' ') . ' ₽ · Источник: ' . $source,
        $me['name'] !== '' ? $me['name'] : '—', $me['src'],
        ['fin'=>1, 'after'=>[
            'Сумма'=>number_format($amount, 0, '', ' ') . ' ₽',
            'Источник'=>$source,
            'Комментарий'=>$comment !== '' ? $comment : '—']]);
    out(['ok'=>true, 'cash'=>cash_public($cash, book_load())]);
}

/* ================= DELETE: удаление записи (любого статуса, со следами в журнале) ================= */
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
