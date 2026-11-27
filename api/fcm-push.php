<?php
/* КЛИО · общий модуль отправки системных push-уведомлений (FCM HTTP v1).
   Подключается из manage.php и lead.php. Конфиг — api/data/fcm.json (service account).
   Если конфига нет — тихо пропускаем отправку. */

function fcm_jread($f, $def){ if (!file_exists($f)) return $def; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $def; }

function fcm_ready(){
    $c = fcm_jread(dirname(__FILE__) . '/data/fcm.json', []);
    return !empty($c['project_id']) && !empty($c['client_email']) && !empty($c['private_key']);
}

function fcm_http_post($url, $raw, $headers){
    if (!function_exists('curl_init')) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$raw,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>5]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function fcm_access_token(){
    $dir = dirname(__FILE__) . '/data';
    $c = fcm_jread($dir . '/fcm.json', []);
    $cache = fcm_jread($dir . '/fcm-token.json', []);
    if (!empty($cache['token']) && (int)($cache['exp'] ?? 0) > time() + 120) return $cache['token'];
    $now = time();
    $b64 = function($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
    $jwt = $b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])) . '.' .
           $b64(json_encode(['iss'=>$c['client_email'], 'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
                             'aud'=>'https://oauth2.googleapis.com/token', 'iat'=>$now, 'exp'=>$now + 3600]));
    $key = openssl_pkey_get_private($c['private_key']);
    if (!$key) return null;
    $sig = '';
    if (!openssl_sign($jwt, $sig, $key, OPENSSL_ALGO_SHA256)) return null;
    $jwt .= '.' . $b64($sig);
    $r = fcm_http_post('https://oauth2.googleapis.com/token',
        'grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion=' . $jwt,
        ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode((string)$r, true);
    if (empty($j['access_token'])) return null;
    file_put_contents($dir . '/fcm-token.json', json_encode(['token'=>$j['access_token'], 'exp'=>$now + (int)($j['expires_in'] ?? 3600)]), LOCK_EX);
    return $j['access_token'];
}

/* текст пуша по событию журнала */
function fcm_push_text($ev){
    $T = ['create'=>'Создана новая запись', 'lead_new'=>'Новая заявка', 'edit'=>'Запись отредактирована',
        'reschedule'=>'Запись перенесена', 'service_change'=>'Изменена услуга', 'spec_change'=>'Изменён специалист',
        'cancel'=>'Запись отменена', 'noshow'=>'Клиент не пришёл', 'return'=>'Запись возвращена в работу',
        'done'=>'Услуга оказана', 'pay_new'=>'Внесена оплата', 'pay_change'=>'Изменена сумма оплаты',
        'paymethod'=>'Изменён способ оплаты', 'reward_new'=>'Вознаграждение выплачено', 'reward_change'=>'Изменено вознаграждение',
        'complete'=>'Запись завершена', 'delete'=>'Запись удалена', 'lead_delete'=>'Заявка удалена', 'lead_done'=>'Заявка обработана'];
    $title = $T[$ev['type'] ?? ''] ?? ($ev['title'] ?? 'КЛИО');
    $body = trim((string)($ev['info'] ?? ''));
    if (!empty($ev['user']) && $ev['user'] !== 'Сайт' && $ev['user'] !== 'DIKIDI') $body .= ($body !== '' ? "\n" : '') . 'Кто: ' . $ev['user'];
    return [$title, $body];
}

/* отправка события всем зарегистрированным устройствам команды */
function fcm_broadcast($ev){
    $dir = dirname(__FILE__) . '/data';
    if (!fcm_ready()) return false;
    $acc = fcm_access_token();
    if (!$acc) return false;
    $c = fcm_jread($dir . '/fcm.json', []);
    $devices = fcm_jread($dir . '/push-tokens.json', []);
    if (!$devices) return false;
    list($title, $body) = fcm_push_text($ev);
    $open = (($ev['obj'] ?? '') === 'lead' ? 'lead:' : '') . (string)($ev['oid'] ?? '');
    if (in_array(($ev['type'] ?? ''), ['delete','lead_delete'], true)) $open = 'hist';
    $msg = ['message' => [
        'data' => ['title'=>$title, 'body'=>$body, 'open'=>$open, 'seq'=>(string)($ev['seq'] ?? ''), 'type'=>(string)($ev['type'] ?? '')],
        'android' => ['priority'=>'HIGH'],
    ]];
    $url = 'https://fcm.googleapis.com/v1/projects/' . $c['project_id'] . '/messages:send';
    $changed = false;
    foreach ($devices as $fcm => $info) {
        $msg['message']['token'] = $fcm;
        $r = fcm_http_post($url, json_encode($msg, JSON_UNESCAPED_UNICODE),
            ['Authorization: Bearer ' . $acc, 'Content-Type: application/json; charset=utf-8']);
        if ($r && strpos((string)$r, 'UNREGISTERED') !== false) { unset($devices[$fcm]); $changed = true; }
    }
    if ($changed) file_put_contents($dir . '/push-tokens.json', json_encode($devices, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return true;
}
