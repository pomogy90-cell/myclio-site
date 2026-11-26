<?php
/* КЛИО · приём заявок с сайта и приложения (Beget, PHP)
   POST JSON {type, fields{Имя,Телефон,...}, utm{}, page, ts}
   → сохраняет в api/data/leads.jsonl + письмо администратору
   Ответ: {"status":"accepted","id":"..."} или {"status":"rejected","reason":"..."} */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function rej($code, $reason){ http_response_code($code); echo json_encode(['status'=>'rejected','reason'=>$reason], JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rej(405, 'method_not_allowed');

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) === 0) rej(400, 'empty');
if (strlen($raw) > 20000) rej(413, 'too_big');

$data = json_decode($raw, true);
if (!is_array($data)) rej(400, 'bad_json');

$fields = $data['fields'] ?? [];
if (!is_array($fields)) rej(400, 'bad_fields');

/* бот-ловушка: отвечаем «успехом», но ничего не сохраняем */
if (!empty($fields['__hp'])) { echo json_encode(['status'=>'accepted','id'=>'x'.bin2hex(random_bytes(4))]); exit; }

function fld($f, $k){ return isset($f[$k]) ? trim((string)$f[$k]) : ''; }
$name  = mb_substr(fld($fields, 'Имя'), 0, 80);
$phone = preg_replace('/\D/', '', fld($fields, 'Телефон'));
if (strlen($phone) === 11 && $phone[0] === '8') $phone = '7' . substr($phone, 1);

if (mb_strlen($name) < 2 || !preg_match('/^7\d{10}$/', $phone)) rej(422, 'invalid_name_or_phone');

/* rate-limit: не чаще 1 заявки в 60 сек с одного IP или на один телефон */
$dir = __DIR__ . '/data';
if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
$log = $dir . '/leads.jsonl';
$now = time();
$ip  = $_SERVER['REMOTE_ADDR'] ?? '';

if (file_exists($log)) {
    $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -300);
    foreach ($lines as $ln) {
        $j = json_decode($ln, true);
        if (!$j) continue;
        $recent = ($now - (int)($j['ts'] ?? 0)) < 60;
        if ($recent && (($j['ip'] ?? '') === $ip || ($j['phone'] ?? '') === $phone)) rej(429, 'rate_limit');
    }
}

/* собираем чистую запись */
$id   = 'klio-' . date('ymd') . '-' . bin2hex(random_bytes(3));
$type = mb_substr(trim((string)($data['type'] ?? 'Заявка')), 0, 60);
$clean = [];
foreach ($fields as $k => $v) {
    $k = (string)$k;
    if ($k === '' || $k[0] === '_') continue;
    $v = mb_substr(trim((string)$v), 0, 500);
    if ($v === '') continue; /* пустые поля (например, незаполненные дата/время) не храним */
    $clean[mb_substr($k, 0, 60)] = $v;
}
$utm = [];
if (!empty($data['utm']) && is_array($data['utm'])) {
    foreach ($data['utm'] as $k => $v) {
        $k = preg_replace('/[^a-z_]/', '', (string)$k);
        if (strpos($k, 'utm_') === 0) $utm[$k] = mb_substr(trim((string)$v), 0, 120);
    }
}

$rec = [
    'id' => $id, 'ts' => $now, 'date' => date('d.m.Y H:i'),
    'type' => $type, 'name' => $name, 'phone' => $phone,
    'fields' => $clean, 'utm' => $utm,
    'page' => mb_substr((string)($data['page'] ?? ''), 0, 120),
    'ip' => $ip,
];

$saved = @file_put_contents($log, json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
if ($saved === false) rej(500, 'save_failed'); /* данных нет — честно говорим об ошибке */

/* журнал «История действий» + лента событий для уведомлений (общий с manage.php формат) */
$wd = ''; $wt = '';
foreach ($clean as $k => $v) {
    if ($wd === '' && preg_match('/(Желаемая дата|^Дата$)/u', $k)) $wd = $v;
    if ($wt === '' && preg_match('/Желаемое время/u', $k)) $wt = $v;
}
$evInfo = trim($name . ' · ' . ($clean['Услуга'] ?? $type) . ($wd !== '' ? ' · ' . $wd : '') . ($wt !== '' ? ' ' . $wt : ''));
$ev = [
    't' => $now, 'user' => 'Сайт', 'src' => (($data['page'] ?? '') === 'app' ? 'app' : 'site'),
    'type' => 'lead_new', 'obj' => 'lead', 'oid' => $id,
    'title' => 'Новая заявка', 'info' => $evInfo,
    'client' => $name, 'phone' => $phone, 'service' => (string)($clean['Услуга'] ?? ''),
    'snap' => ['id'=>$id, 'ts'=>$now, 'date'=>$rec['date'], 'name'=>$name, 'phone'=>$phone, 'type'=>$type, 'fields'=>$clean, 'wdate'=>$wd, 'wtime'=>$wt],
];
@file_put_contents($dir . '/manage-history.jsonl', json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
$evf = $dir . '/manage-events.json';
$buf = [];
if (file_exists($evf)) { $buf = json_decode((string)file_get_contents($evf), true); if (!is_array($buf)) $buf = []; }
$buf['seq'] = (int)($buf['seq'] ?? 0) + 1;
$ev['seq'] = $buf['seq'];
$buf['items'] = array_slice(array_merge(is_array($buf['items'] ?? null) ? $buf['items'] : [], [$ev]), -300);
@file_put_contents($evf, json_encode($buf, JSON_UNESCAPED_UNICODE), LOCK_EX);

/* пуш команде о новой заявке (после ответа клиенту, если возможно) */
require_once __DIR__ . '/fcm-push.php';
$PUSH_EV = $ev;
register_shutdown_function(function() use ($PUSH_EV){
    if (!fcm_ready()) return;
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    fcm_broadcast($PUSH_EV);
});

/* письмо администратору (сбой письма заявку не отменяет — она уже сохранена) */
$to   = 'mycliomy@gmail.com';
$subj = '=?UTF-8?B?' . base64_encode("Заявка КЛИО · $type · $name") . '?=';
$body = "Новая заявка с сайта myclio.ru\n\nID: $id\nТип: $type\nВремя: " . date('d.m.Y H:i') . "\n\n";
foreach ($clean as $k => $v) $body .= "$k: $v\n";
if ($utm) { $body .= "\nИсточник (UTM):\n"; foreach ($utm as $k => $v) $body .= "$k: $v\n"; }
$body .= "\n—\nВсе заявки хранятся на хостинге: api/data/leads.jsonl\n";
$headers = "From: сайт КЛИО <noreply@myclio.ru>\r\nContent-Type: text/plain; charset=utf-8\r\n";
@mail($to, $subj, $body, $headers);

echo json_encode(['status' => 'accepted', 'id' => $id], JSON_UNESCAPED_UNICODE);
