<?php
/* КЛИО · DIKIDI: проверка API-ключа и диагностика (только чтение, ничего не пишет).
   /api/dikidi-sync.php?token=<hook_token>&action=ping      — состояние конфига
   /api/dikidi-sync.php?token=<hook_token>&action=test      — пробуем API по кандидатным базовым URL
   /api/dikidi-sync.php?token=<hook_token>&action=records&from=YYYY-MM-DD&to=YYYY-MM-DD — читаем записи
   Ключ хранится в data/dikidi.json (закрыт .htaccess), в ответах не светится. */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '0');

$DATA = dirname(__FILE__) . '/data';
function out($a, $c = 200){ http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function jread($f, $d){ if (!file_exists($f)) return $d; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $d; }

$cfg = jread($DATA . '/dikidi.json', []);
$TOK = (string)($cfg['hook_token'] ?? '');
if ($TOK === '' || !hash_equals($TOK, (string)($_GET['token'] ?? ''))) out(['ok'=>false, 'err'=>'forbidden'], 403);

$action = (string)($_GET['action'] ?? 'ping');
$key = (string)($cfg['key'] ?? '');
$cid = (string)($cfg['company_id'] ?? '');

if ($action === 'ping') {
    out(['ok'=>true, 'php'=>PHP_VERSION,
         'cfg'=>['has_key'=>($key !== ''), 'company_id'=>$cid, 'status'=>($cfg['status'] ?? '?')]]);
}
if ($key === '' || $cid === '') out(['ok'=>false, 'err'=>'no_key_or_company'], 500);

function dk_get($url){
    if (!function_exists('curl_init')) return ['http'=>0, 'err'=>'no_curl'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $j = json_decode((string)$body, true);
    return ['http'=>$code, 'err'=>$err,
            'json'=>is_array($j) ? $j : null,
            'raw'=>mb_substr((string)$body, 0, 1500)];
}

$BASES = ['https://dikidi.net/api/v1', 'https://api.dikidi.net/api/v1', 'https://api.dikidi.net/v1'];

if ($action === 'test') {
    $res = [];
    foreach ($BASES as $b) {
        $res[] = ['try'=>$b . '/company/',
                  'r'=>dk_get($b . '/company/?' . http_build_query(['access_token'=>$key, 'company_id'=>$cid]))];
    }
    out(['ok'=>true, 'test'=>$res]);
}

if ($action === 'records') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-d');
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? (string)$_GET['to']   : date('Y-m-d', strtotime('+7 days'));
    $res = [];
    foreach ($BASES as $b) {
        $res[] = ['try'=>$b . '/records/',
                  'r'=>dk_get($b . '/records/?' . http_build_query(['access_token'=>$key, 'company_id'=>$cid, 'date_from'=>$from, 'date_to'=>$to]))];
    }
    out(['ok'=>true, 'from'=>$from, 'to'=>$to, 'test'=>$res]);
}

out(['ok'=>false, 'err'=>'unknown_action'], 400);
