<?php
/* КЛИО · временная интеграция DIKIDI → Google Calendar → CRM (ТЗ №4, версия 27.11-В13).
   Отдельный модуль: после получения партнёрского API DIKIDI источник Google Calendar
   заменяется прямым подключением без переработки CRM (дедуп по календарь+событие сохраняется).
   Хранилище — api/data (веб-доступ закрыт .htaccess). Секреты Google — AES-256-GCM,
   ключ шифрования в api/data/.gcal-key (создаётся автоматически, только сервер). */

$GCAL_DATA = dirname(__FILE__) . '/data';
$GCAL_CFG  = $GCAL_DATA . '/gcal.json';      /* конфиг интеграции */
$GCAL_MAP  = $GCAL_DATA . '/gcal-map.json';  /* связь календарь+событие → запись CRM (анти-дубли) */
$GCAL_RAW  = $GCAL_DATA . '/gcal-raw.jsonl'; /* сырой журнал событий для настройки парсера (кольцевой) */
$GCAL_LOCK = $GCAL_DATA . '/gcal.lock';      /* защита от параллельных запусков синхронизации */
$GCAL_KEY  = $GCAL_DATA . '/.gcal-key';      /* ключ шифрования токенов */

function gcal_jread($f, $def){ if (!file_exists($f)) return $def; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $def; }
function gcal_jwrite($f, $d){ file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); }

/* ----- конфиг ----- */
function gcal_default(){
    return [
        'client_id' => '', 'client_secret_enc' => '',
        'refresh_token_enc' => '', 'access' => ['token'=>'', 'exp'=>0],
        'api_base'  => 'https://www.googleapis.com/calendar/v3',
        'oauth_base' => 'https://oauth2.googleapis.com',
        'auth_url'  => 'https://accounts.google.com/o/oauth2/v2/auth',
        'sync_enabled' => false,
        'sync_token' => '',        /* секрет cron-URL генерируется при сохранении ключей */
        'calendars' => [],         /* calId => {name, spec, enabled, syncToken, first_done} */
        'oauth_state' => '',
        'last_sync' => 0, 'last_ok' => 0,
        'errors' => [],            /* [{t, m}] — журнал ошибок подключения (кольцевой, 50) */
    ];
}
function gcal_cfg($save = null){
    global $GCAL_CFG;
    if ($save !== null) { gcal_jwrite($GCAL_CFG, $save); return $save; }
    $c = gcal_jread($GCAL_CFG, []);
    return array_merge(gcal_default(), $c);
}
function gcal_err($cfg, $msg){
    $cfg['errors'][] = ['t'=>time(), 'm'=>(string)$msg];
    if (count($cfg['errors']) > 50) $cfg['errors'] = array_slice($cfg['errors'], -50);
    return $cfg;
}

/* ----- шифрование секретов (AES-256-GCM) ----- */
function gcal_key(){
    global $GCAL_KEY;
    if (file_exists($GCAL_KEY)) return hex2bin(trim((string)file_get_contents($GCAL_KEY)));
    $k = random_bytes(32);
    file_put_contents($GCAL_KEY, bin2hex($k), LOCK_EX);
    @chmod($GCAL_KEY, 0600);
    return $k;
}
function gcal_enc($s){
    if ($s === '') return '';
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($s, 'aes-256-gcm', gcal_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}
function gcal_dec($s){
    if ($s === '') return '';
    $raw = base64_decode($s, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $ct = substr($raw, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', gcal_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}

/* ----- HTTP с ретраями (Google API; при сбое — автовосстановление на следующем запуске) ----- */
function gcal_http_once($method, $url, $headers, $body){
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_HTTPHEADER=>$headers];
    if ($method === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = (string)$body; }
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    return ['code'=>$code, 'body'=>(string)$res, 'err'=>$err];
}
function gcal_http($method, $url, $headers, $body = null){
    $r = gcal_http_once($method, $url, $headers, $body);
    for ($i = 0; $i < 2; $i++) {
        if ($r['err'] === '' && $r['code'] > 0 && $r['code'] < 500) break; /* фатальных ошибок нет */
        usleep(500000 * ($i + 1));
        $r = gcal_http_once($method, $url, $headers, $body);
    }
    $j = json_decode($r['body'], true);
    $r['json'] = is_array($j) ? $j : [];
    return $r;
}

/* ----- OAuth: актуальный access_token (кеш + refresh) ----- */
function gcal_access_token(&$cfg){
    if (!empty($cfg['access']['token']) && (int)($cfg['access']['exp'] ?? 0) > time() + 120)
        return $cfg['access']['token'];
    $rt = gcal_dec((string)($cfg['refresh_token_enc'] ?? ''));
    if ($rt === '') { $cfg = gcal_err($cfg, 'Google-аккаунт не подключён (нет refresh-токена).'); return null; }
    $r = gcal_http('POST', $cfg['oauth_base'] . '/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $cfg['client_id'],
            'client_secret' => gcal_dec((string)$cfg['client_secret_enc']),
            'refresh_token' => $rt,
            'grant_type' => 'refresh_token',
        ]));
    if (empty($r['json']['access_token'])) {
        $e = (string)($r['json']['error'] ?? $r['err'] ?: ('HTTP ' . $r['code']));
        $cfg = gcal_err($cfg, 'Не удалось обновить токен Google: ' . $e .
            ($e === 'invalid_grant' ? ' — доступ отозван, переподключите аккаунт в настройках.' : ''));
        return null;
    }
    $cfg['access'] = ['token'=>$r['json']['access_token'], 'exp'=>time() + (int)($r['json']['expires_in'] ?? 3600)];
    return $cfg['access']['token'];
}

/* ----- Google Calendar API ----- */
function gcal_list_calendars($cfg){
    $tok = gcal_access_token($cfg);
    if (!$tok) return [null, $cfg];
    $out = []; $page = '';
    do {
        $url = $cfg['api_base'] . '/users/me/calendarList?maxResults=250' . ($page !== '' ? '&pageToken=' . urlencode($page) : '');
        $r = gcal_http('GET', $url, ['Authorization: Bearer ' . $tok]);
        if ($r['code'] !== 200) { $cfg = gcal_err($cfg, 'Список календарей не получен: HTTP ' . $r['code'] . ' ' . $r['err']); return [null, $cfg]; }
        foreach (($r['json']['items'] ?? []) as $c) $out[] = ['id'=>(string)($c['id'] ?? ''), 'name'=>(string)($c['summary'] ?? ''), 'primary'=>!empty($c['primary'])];
        $page = (string)($r['json']['nextPageToken'] ?? '');
    } while ($page !== '');
    return [$out, $cfg];
}
/* выборка событий календаря: инкрементально (syncToken) либо полная (timeMin). Возвращает [events, newSyncToken, cfg, fatal] */
function gcal_events_page_all($cfg, $calId, $cal){
    $tok = gcal_access_token($cfg);
    if (!$tok) return [null, null, $cfg, 'no token'];
    $events = []; $page = ''; $newToken = null;
    $syncToken = (string)($cal['syncToken'] ?? '');
    do {
        $q = ['singleEvents'=>'true', 'showDeleted'=>'true', 'maxResults'=>'250'];
        if ($syncToken !== '') $q['syncToken'] = $syncToken;
        else $q['timeMin'] = (new DateTime('today', new DateTimeZone('Europe/Moscow')))->format('c'); /* первичный импорт — с начала текущего дня */
        if ($page !== '') $q['pageToken'] = $page;
        $url = $cfg['api_base'] . '/calendars/' . rawurlencode($calId) . '/events?' . http_build_query($q);
        $r = gcal_http('GET', $url, ['Authorization: Bearer ' . $tok]);
        if ($r['code'] === 410) { /* syncToken протух — Google требует полную ресинхронизацию */
            return ['__410__', null, $cfg, null];
        }
        if ($r['code'] !== 200) {
            $cfg = gcal_err($cfg, 'Календарь «' . ($cal['name'] ?? $calId) . '»: HTTP ' . $r['code'] . ' ' . $r['err']);
            return [null, null, $cfg, 'HTTP ' . $r['code']];
        }
        foreach (($r['json']['items'] ?? []) as $ev) $events[] = $ev;
        $page = (string)($r['json']['nextPageToken'] ?? '');
        $newToken = (string)($r['json']['nextSyncToken'] ?? '');
    } while ($page !== '');
    return [$events, $newToken, $cfg, null];
}

/* ================= ХРАНИЛИЩЕ ЗАПИСЕЙ CRM (формат идентичен api/manage.php) ================= */
function gcal_book_load(){
    global $GCAL_DATA;
    $b = gcal_jread($GCAL_DATA . '/bookings.json', []);
    if (!isset($b['version'])) $b = ['version'=>0, 'seq'=>0, 'items'=>[]];
    return $b;
}
function gcal_book_save($b){ global $GCAL_DATA; gcal_jwrite($GCAL_DATA . '/bookings.json', $b); }

function gcal_book_info($it){
    $t = strtotime((string)($it['date'] ?? ''));
    $d = $t ? date('d.m.Y', $t) : (string)($it['date'] ?? '');
    return trim($d . ' ' . ($it['start'] ?? '') . '–' . ($it['end'] ?? '') . ' · ' . ($it['service'] ?? '') . ' · ' . ($it['spec'] ?? ''));
}
function gcal_book_snap($it){
    $s = [];
    foreach (['date','start','end','client','phone','dir','service','services','spec','comment','done','amount','pay','reward','state','src','needinfo','price_src'] as $k)
        $s[$k] = $it[$k] ?? null;
    return $s;
}
/* журнал «История действий» — тот же формат, что у manage.php (hist_log),
   события попадают и в полный журнал, и в кольцевой буфер для опроса/пушей */
function gcal_hist($type, $oid, $title, $info, $extra = [], $silent = false){
    global $GCAL_DATA;
    $F_HIST = $GCAL_DATA . '/manage-history.jsonl';
    $F_EV   = $GCAL_DATA . '/manage-events.json';
    $ev = array_merge([
        't'=>time(), 'user'=>'DIKIDI', 'src'=>'dikidi',
        'type'=>(string)$type, 'obj'=>'booking', 'oid'=>(string)$oid,
        'title'=>(string)$title, 'info'=>(string)$info,
    ], $extra);
    $buf = gcal_jread($F_EV, ['seq'=>0, 'items'=>[]]);
    $buf['seq'] = (int)($buf['seq'] ?? 0) + 1;
    $ev['seq'] = $buf['seq'];
    @file_put_contents($F_HIST, json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $buf['items'][] = $ev;
    if (count($buf['items']) > 300) $buf['items'] = array_slice($buf['items'], -300);
    gcal_jwrite($F_EV, $buf);
    /* push команде — при первичном импорте и служебных проходах не шлём (ТЗ: без массовых уведомлений) */
    if (!$silent) {
        require_once dirname(__FILE__) . '/fcm-push.php';
        if (fcm_ready()) fcm_broadcast($ev);
    }
    return $ev;
}

/* ================= ПАРСЕР СОБЫТИЯ GOOGLE (формат экспорта DIKIDI) ================= */
function gcal_norm_phone($s){
    $d = preg_replace('/\D/', '', (string)$s);
    if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) return '+7' . substr($d, 1);
    if (strlen($d) === 10) return '+7' . $d;
    return $d !== '' ? '+' . $d : '';
}
function gcal_parse_event($ev){
    $sum  = trim((string)($ev['summary'] ?? ''));
    $desc = (string)($ev['description'] ?? '');
    $out = ['client'=>'', 'phone'=>'', 'service'=>'', 'comment'=>'', 'price'=>null,
            'needinfo'=>[], 'summary'=>$sum];

    /* 1) размеченные строки «Метка: значение» в описании (основной формат DIKIDI) */
    $labels = [];
    foreach (preg_split('/\r\n|\r|\n/', $desc) as $ln) {
        if (preg_match('/^\s*(Клиент|Телефон|Тел|Услуга|Услуги|Стоимость|Цена|Комментарий|Примечание|Мастер|Специалист)\s*[:\-–]\s*(.+)$/ui', trim($ln), $m))
            $labels[mb_strtolower($m[1])] = trim($m[2]);
    }
    $pick = function($keys) use ($labels){ foreach ($keys as $k) if (!empty($labels[$k])) return $labels[$k]; return ''; };
    $out['client']  = mb_substr($pick(['клиент']), 0, 80);
    $out['phone']   = gcal_norm_phone($pick(['телефон','тел']));
    $out['service'] = mb_substr($pick(['услуга','услуги']), 0, 120);
    $out['comment'] = mb_substr($pick(['комментарий','примечание']), 0, 500);
    $pr = $pick(['стоимость','цена']);
    if ($pr !== '' && preg_match('/(\d[\d\s]*\d)/u', $pr, $m)) $out['price'] = (int)preg_replace('/\D/', '', $m[1]);

    /* 2) телефон — из любого места описания/заголовка, если метка не нашлась */
    if ($out['phone'] === '' && preg_match('/(\+?7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-()]*\d{2}[\s\-()]*\d{2}/', $desc . ' ' . $sum, $m))
        $out['phone'] = gcal_norm_phone($m[0]);

    /* 3) цена — из текста, если метки нет */
    if ($out['price'] === null && preg_match('/(\d[\d\s]*\d)\s*(?:₽|руб(?:\.|лей|а)?)/ui', $desc . ' ' . $sum, $m))
        $out['price'] = (int)preg_replace('/\D/', '', $m[1]);

    /* 4) заголовок: «Услуга — Клиент» / «Услуга, Клиент» / «Клиент (Услуга)» — эвристика */
    if ($sum !== '') {
        $parts = preg_split('/\s*[—–\-\/,•]\s*/u', $sum);
        $parts = array_values(array_filter(array_map('trim', $parts), function($p){ return $p !== ''; }));
        if ($out['service'] === '' && $parts) {
            $out['service'] = mb_substr($parts[0], 0, 120);
            if ($out['client'] === '' && count($parts) > 1) $out['client'] = mb_substr($parts[1], 0, 80);
        } elseif ($out['client'] === '' && count($parts) > 1) {
            /* услуга есть из меток — имя ищем в частях заголовка, отличных от услуги */
            foreach ($parts as $p) {
                if (mb_strtolower($p) !== mb_strtolower($out['service']) && !preg_match('/\d/', $p)) { $out['client'] = mb_substr($p, 0, 80); break; }
            }
        }
    }
    if ($out['service'] === '') $out['service'] = 'Запись DIKIDI';
    if ($out['client'] === '') $out['needinfo'][] = 'client';
    if ($out['phone'] === '')  $out['needinfo'][] = 'phone';
    return $out;
}
/* сопоставление услуги с прайсом content.json → направление (категория) */
function gcal_match_service($name, $content){
    $n = mb_strtolower(trim($name));
    if ($n === '') return ['', ''];
    foreach (($content['prices'] ?? []) as $cat) {
        foreach (($cat['rows'] ?? []) as $row) {
            $r = mb_strtolower(trim((string)($row[0] ?? '')));
            if ($r === '') continue;
            if ($r === $n || mb_strpos($n, $r) !== false || mb_strpos($r, $n) !== false)
                return [(string)($cat['cat'] ?? ''), (string)$row[0]];
        }
    }
    return ['', $name];
}
/* сопоставление специалиста по тексту (имя календаря/события → справочник CRM) */
function gcal_match_spec($text, $specs){
    $t = mb_strtolower(' ' . $text . ' ');
    foreach ($specs as $s) {
        $parts = preg_split('/\s+/u', trim($s));
        $ok = true;
        foreach ($parts as $p) { if (mb_strlen($p) >= 3 && mb_strpos($t, mb_strtolower($p)) === false) { $ok = false; break; } }
        if ($ok && $t !== '  ') return $s;
    }
    return '';
}

/* дата/время события Google → поля CRM (московское время) */
function gcal_event_times($ev){
    $msk = new DateTimeZone('Europe/Moscow');
    $s = (string)($ev['start']['dateTime'] ?? '');
    $e = (string)($ev['end']['dateTime'] ?? '');
    if ($s === '' || $e === '') return null; /* целодневные/без времени — пропускаем */
    try {
        $ds = new DateTime($s); $ds->setTimezone($msk);
        $de = new DateTime($e); $de->setTimezone($msk);
    } catch (Exception $ex) { return null; }
    if ($de <= $ds) return null;
    return ['date'=>$ds->format('Y-m-d'), 'start'=>$ds->format('H:i'), 'end'=>$de->format('H:i')];
}

/* сырой журнал для настройки парсера на реальных событиях (ТЗ §3) — кольцевой, 200 записей */
function gcal_raw_log($calId, $ev){
    global $GCAL_RAW;
    $row = ['t'=>time(), 'cal'=>$calId, 'id'=>(string)($ev['id'] ?? ''),
            'summary'=>(string)($ev['summary'] ?? ''),
            'description'=>mb_substr((string)($ev['description'] ?? ''), 0, 2000),
            'status'=>(string)($ev['status'] ?? '')];
    @file_put_contents($GCAL_RAW, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $lines = @file($GCAL_RAW, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (count($lines) > 200) file_put_contents($GCAL_RAW, implode("\n", array_slice($lines, -200)) . "\n", LOCK_EX);
}

/* конфликт расписания: активная запись того же специалиста с пересечением по времени */
function gcal_conflict_find($b, $date, $start, $end, $spec, $excludeId){
    if ($spec === '') return null;
    $s = strtotime("$date $start"); $e = strtotime("$date $end");
    foreach (($b['items'] ?? []) as $it) {
        if (!empty($it['deleted']) || !empty($it['state'])) continue;
        if (($it['id'] ?? '') === $excludeId) continue;
        if (($it['date'] ?? '') !== $date) continue;
        if (($it['spec'] ?? '') !== $spec) continue;
        $is = strtotime(($it['date'] ?? '') . ' ' . ($it['start'] ?? ''));
        $ie = strtotime(($it['date'] ?? '') . ' ' . ($it['end'] ?? ''));
        if ($is < $e && $ie > $s) return $it;
    }
    return null;
}


/* ================= ЧАСТЬ 3. Движок синхронизации ================= */

/* карта дедупликации: {calId: {evId: {b: 'b12', upd: '...'}}} — переживает обнуление CRM,
   защищает от дублей и при переходе на прямой API DIKIDI (ТЗ §10) */
function gcal_map_load(){
    global $GCAL_MAP;
    $m = gcal_jread($GCAL_MAP, []);
    return is_array($m) ? $m : [];
}
function gcal_map_save($m){ global $GCAL_MAP; gcal_jwrite($GCAL_MAP, $m); }
function gcal_map_get($m, $calId, $evId){ return $m[$calId][$evId] ?? null; }
function gcal_map_set(&$m, $calId, $evId, $bid, $upd){ $m[$calId][$evId] = ['b'=>$bid, 'upd'=>$upd]; }

/* запись «закрыта» в финансовом смысле: есть оплата/вознаграждение —
   такие DIKIDI-изменения не перезаписывают финансы (ТЗ §6) */
function gcal_book_closed($it){
    if (($it['reward'] ?? null) !== null) return true;
    if (($it['amount'] ?? null) !== null && !empty($it['pay'])) return true;
    return false;
}

/* общие поля расписания из разобранного события */
function gcal_apply_sched(&$it, $t){
    $it['date']  = $t['date'];
    $it['start'] = $t['start'];
    $it['end']   = $t['end'];
}

/* поиск индекса записи по id */
function gcal_book_idx($b, $bid){
    foreach (($b['items'] ?? []) as $i => $x) if (($x['id'] ?? '') === $bid) return $i;
    return null;
}

/* текстовые строки push/info по шаблону ТЗ §7 */
function gcal_ev_lines($it){
    $d = strtotime(($it['date'] ?? '') . ' ' . ($it['start'] ?? '00:00'));
    $svc = (isset($it['services']) && is_array($it['services']) && $it['services']) ? implode(' + ', $it['services']) : (string)($it['service'] ?? '');
    return "Клиент: " . (($it['client'] ?? '') !== '' ? $it['client'] : '—')
         . "\nУслуга: " . ($svc !== '' ? $svc : '—')
         . "\nСпециалист: " . (($it['spec'] ?? '') !== '' ? $it['spec'] : '—')
         . "\nДата и время: " . date('d.m.Y', $d) . ' ' . ($it['start'] ?? '') . '–' . ($it['end'] ?? '');
}

/* СОЗДАНИЕ записи из события календаря */
function gcal_apply_create(&$b, &$map, $calId, $cal, $ev, $p, $t, $content, $specs, $silent, &$st){
    $now = time();
    $evId = (string)($ev['id'] ?? '');
    $upd  = (string)($ev['updated'] ?? '');

    /* специалист: сопоставление календаря из настроек приоритетнее угадывания по тексту */
    $spec = (string)($cal['spec'] ?? '');
    if ($spec === '') $spec = gcal_match_spec(($ev['summary'] ?? '') . ' ' . ($ev['description'] ?? ''), $specs);
    if ($spec === '') $spec = (string)($specs[0] ?? '');

    /* услуга: сверка с прайсом из content.json */
    $service = (string)$p['service'];
    $dir = '';
    $price_src = '';
    $m = gcal_match_service($service, $content);
    if ($m[0] !== '') { $dir = $m[0]; $service = $m[1]; $price_src = 'pricelist'; }
    elseif (($p['price'] ?? null) !== null) { $price_src = 'dikidi'; }

    $need = $p['needinfo'] ?? [];
    if ($spec === '' && !in_array('spec', $need, true)) $need[] = 'spec';

    $b['version']++;
    $b['seq']++;
    $it = [
        'id'=>'b' . $b['seq'], 'date'=>$t['date'], 'start'=>$t['start'], 'end'=>$t['end'],
        'client'=>(string)$p['client'], 'phone'=>(string)$p['phone'], 'dir'=>$dir,
        'service'=>$service, 'services'=>[$service], 'spec'=>$spec,
        'comment'=>(string)($p['comment'] ?? ''),
        'done'=>0, 'amount'=>null, 'pay'=>null, 'reward'=>null, 'state'=>'',
        'cash_at'=>null,
        'history'=>[['t'=>$now, 'u'=>'DIKIDI', 'm'=>'Создана запись (синхронизация DIKIDI → Google Calendar)']],
        'v'=>$b['version'],
        'src'=>'dikidi', 'gcal_cal'=>$calId, 'gcal_ev'=>$evId, 'gcal_upd'=>$upd,
        'needinfo'=>$need, 'price_src'=>$price_src,
    ];
    /* цена из DIKIDI — справочно в комментарий, НЕ в amount (ТЗ §6: деньги не регистрируем) */
    if (($p['price'] ?? null) !== null && $price_src === 'dikidi') {
        $it['comment'] = trim(($it['comment'] !== '' ? $it['comment'] . "\n" : '') . 'Стоимость в DIKIDI: ' . $p['price'] . ' ₽');
    }
    $b['items'][] = $it;
    gcal_map_set($map, $calId, $evId, $it['id'], $upd);
    $st['created']++;

    /* конфликт расписания: обе записи сохраняются, админу — уведомление (ТЗ §6) */
    $conf = gcal_conflict_find($b, $t['date'], $t['start'], $t['end'], $spec, $it['id']);
    if ($conf) {
        $st['conflicts']++;
        gcal_hist('gcal_conflict', $it['id'], 'Конфликт расписания (DIKIDI)',
            "Новая запись из DIKIDI пересекается с записью " . ($conf['id'] ?? '') .
            " (" . ($conf['client'] ?? '') . ", " . ($conf['start'] ?? '') . '–' . ($conf['end'] ?? '') . ").\nОбе записи сохранены — проверьте вручную.",
            ['client'=>$it['client'], 'phone'=>$it['phone'], 'spec'=>$spec, 'service'=>$it['service'], 'conflict_with'=>($conf['id'] ?? '')],
            $silent);
    }

    gcal_hist('create', $it['id'], 'Новая запись из DIKIDI', gcal_ev_lines($it),
        ['client'=>$it['client'], 'phone'=>$it['phone'], 'spec'=>$spec, 'service'=>$it['service']],
        $silent);
}

/* ОБНОВЛЕНИЕ существующей записи: расписание — всегда, контакты — только пустые,
   финансы (done/amount/pay/reward/cash_at/state) — НИКОГДА (ТЗ §6) */
function gcal_apply_update(&$b, &$map, $calId, $ev, $p, $t, $content, $specs, $silent, &$st){
    $evId = (string)($ev['id'] ?? '');
    $upd  = (string)($ev['updated'] ?? '');
    $idx  = gcal_book_idx($b, (string)$map[$calId][$evId]['b']);
    if ($idx === null) { /* карта есть, записи нет (удалена вручную) — пересоздаём */
        $st['recreated']++;
        gcal_apply_create($b, $map, $calId, ['spec'=>''], $ev, $p, $t, $content, $specs, $silent, $st);
        return;
    }
    $it = &$b['items'][$idx];
    if (!empty($it['deleted'])) { $st['skipped']++; return; }

    $hist = [];
    $dRus = function($d){ $x = strtotime($d); return $x ? date('d.m.Y', $x) : $d; };

    /* перенос времени — фиксируем и для push */
    $moved = ($it['date'] !== $t['date'] || $it['start'] !== $t['start'] || $it['end'] !== $t['end']);
    if ($moved) $hist[] = 'Перенесено время (DIKIDI): ' . $dRus($t['date']) . ' ' . $t['start'] . '–' . $t['end'];
    gcal_apply_sched($it, $t);

    /* контактные поля: заполняем только если пустые (ручное уточнение в CRM не затираем, ТЗ §4) */
    $need = is_array($it['needinfo'] ?? null) ? $it['needinfo'] : [];
    if (($it['client'] ?? '') === '' && ($p['client'] ?? '') !== '') {
        $it['client'] = $p['client']; $hist[] = 'Указан клиент: ' . $p['client'];
        $need = array_values(array_diff($need, ['client']));
    }
    if (($it['phone'] ?? '') === '' && ($p['phone'] ?? '') !== '') {
        $it['phone'] = $p['phone']; $hist[] = 'Указан телефон';
        $need = array_values(array_diff($need, ['phone']));
    }
    if (($it['comment'] ?? '') === '' && ($p['comment'] ?? '') !== '') { $it['comment'] = $p['comment']; $hist[] = 'Указан комментарий'; }
    $it['needinfo'] = $need;

    /* специалист следует за календарём только если запись ещё не трогали вручную с другим спецом —
       правило: меняем, только если текущий spec пустой */
    if (($it['spec'] ?? '') === '') {
        $specsAll = $specs;
        $sp = gcal_match_spec(($ev['summary'] ?? '') . ' ' . ($ev['description'] ?? ''), $specsAll);
        if ($sp !== '') { $it['spec'] = $sp; $hist[] = 'Указан специалист: ' . $sp; }
    }

    $it['gcal_upd'] = $upd;
    if ($hist) {
        $now = time();
        $b['version']++;
        $it['v'] = $b['version'];
        foreach ($hist as $m) $it['history'][] = ['t'=>$now, 'u'=>'DIKIDI', 'm'=>$m];
        if (count($it['history']) > 200) $it['history'] = array_slice($it['history'], -200);
        gcal_map_set($map, $calId, $evId, $it['id'], $upd);
        $st['updated']++;
        if ($moved) {
            gcal_hist('update', $it['id'], 'Запись из DIKIDI перенесена', gcal_ev_lines($it),
                ['client'=>$it['client'], 'phone'=>$it['phone'], 'spec'=>$it['spec'], 'service'=>$it['service']],
                $silent);
        } else {
            gcal_hist('update', $it['id'], 'Запись из DIKIDI обновлена', gcal_ev_lines($it),
                ['client'=>$it['client'], 'phone'=>$it['phone'], 'spec'=>$it['spec'], 'service'=>$it['service']],
                true); /* без push на незначимых правках — только журнал */
        }
    } else {
        gcal_map_set($map, $calId, $evId, $it['id'], $upd);
        $st['unchanged']++;
    }
}

/* ОТМЕНА записи: закрытые финансово записи не трогаем — уведомляем (ТЗ §6) */
function gcal_apply_cancel(&$b, &$map, $calId, $ev, $silent, &$st){
    $evId = (string)($ev['id'] ?? '');
    $upd  = (string)($ev['updated'] ?? '');
    $entry = gcal_map_get($map, $calId, $evId);
    if (!$entry) { $st['skipped']++; return; } /* такой записи у нас нет */
    $idx = gcal_book_idx($b, (string)$entry['b']);
    if ($idx === null) { $st['skipped']++; return; }
    $it = &$b['items'][$idx];
    if (!empty($it['deleted']) || ($it['state'] ?? '') === 'canceled') { $st['skipped']++; return; }

    if (gcal_book_closed($it)) {
        /* запись закрыта: деньги зафиксированы — НЕ отменяем, просим проверить вручную */
        $st['notices']++;
        gcal_hist('gcal_notice', $it['id'], 'DIKIDI: отмена закрытой записи',
            "В DIKIDI отменена запись, по которой в CRM уже закрыт расчёт.\nЗапись НЕ отменена автоматически — проверьте вручную.\n" . gcal_ev_lines($it),
            ['client'=>$it['client'], 'phone'=>$it['phone'], 'spec'=>$it['spec'], 'service'=>$it['service']],
            $silent);
        $it['gcal_upd'] = $upd;
        gcal_map_set($map, $calId, $evId, $it['id'], $upd);
        return;
    }

    $now = time();
    $b['version']++;
    $it['state'] = 'canceled';
    $it['v'] = $b['version'];
    $it['gcal_upd'] = $upd;
    $it['history'][] = ['t'=>$now, 'u'=>'DIKIDI', 'm'=>'Запись отменена (синхронизация DIKIDI)'];
    if (count($it['history']) > 200) $it['history'] = array_slice($it['history'], -200);
    gcal_map_set($map, $calId, $evId, $it['id'], $upd);
    $st['canceled']++;
    gcal_hist('cancel', $it['id'], 'Запись из DIKIDI отменена', gcal_ev_lines($it),
        ['client'=>$it['client'], 'phone'=>$it['phone'], 'spec'=>$it['spec'], 'service'=>$it['service']],
        $silent);
}

/* ГЛАВНЫЙ ПРОГОН: вызывается из cron/api каждые 60 секунд (ТЗ §5).
   $force=true — кнопка «Синхронизировать сейчас» (игнорирует флаг sync_enabled). */
function gcal_sync_run($force = false){
    global $GCAL_LOCK;
    $st = ['ok'=>true, 'calendars'=>0, 'events'=>0, 'created'=>0, 'updated'=>0, 'canceled'=>0,
           'unchanged'=>0, 'skipped'=>0, 'conflicts'=>0, 'notices'=>0, 'recreated'=>0, 'errors'=>[]];

    /* защита от параллельных запусков: неблокирующий flock */
    $lf = @fopen($GCAL_LOCK, 'c');
    if (!$lf) { $st['ok'] = false; $st['errors'][] = 'no_lock_file'; return $st; }
    if (!flock($lf, LOCK_EX | LOCK_NB)) { fclose($lf); $st['ok'] = false; $st['errors'][] = 'already_running'; return $st; }

    $cfg = gcal_cfg();
    if (empty($cfg['sync_enabled']) && !$force) { flock($lf, LOCK_UN); fclose($lf); $st['ok'] = false; $st['errors'][] = 'sync_disabled'; return $st; }
    if ((string)($cfg['refresh_token_enc'] ?? '') === '') { flock($lf, LOCK_UN); fclose($lf); $st['ok'] = false; $st['errors'][] = 'not_connected'; return $st; }

    /* справочники CRM для сопоставления */
    global $GCAL_DATA;
    $content = gcal_jread($GCAL_DATA . '/content.json', []);
    $meta    = gcal_jread($GCAL_DATA . '/manage-meta.json', []);
    $specs   = array_values(array_filter(array_map('strval', (array)($meta['specialists'] ?? []))));

    $b   = gcal_book_load();
    $map = gcal_map_load();
    $bookDirty = false;

    foreach ((array)($cfg['calendars'] ?? []) as $calId => $cal) {
        if (empty($cal['enabled'])) continue;
        /* первичный импорт календаря — БЕЗ массовых push (ТЗ §5): silent до первого завершения */
        $silent = empty($cal['first_done']);
        $st['calendars']++;

        $res = gcal_events_page_all($cfg, $calId, $cal);
        $cfg = $res[2]; /* сохраняем обновлённый access-токен и накопленные ошибки */
        if ($res[0] === '__410__') {
            /* syncToken протух: полный пересинх с timeMin=сегодня (автовосстановление, ТЗ §5) */
            $cal['syncToken'] = '';
            $res = gcal_events_page_all($cfg, $calId, $cal);
            $cfg = $res[2];
        }
        $events = $res[0]; $newTok = $res[1]; $fatal = $res[3];
        if ($fatal !== null || !is_array($events)) {
            $st['errors'][] = 'cal ' . $calId . ': ' . $fatal;
            continue;
        }

        foreach ($events as $ev) {
            $st['events']++;
            gcal_raw_log($calId, $ev);
            $evId = (string)($ev['id'] ?? '');
            if ($evId === '') { $st['skipped']++; continue; }
            $status = (string)($ev['status'] ?? 'confirmed');

            if ($status === 'cancelled') {
                gcal_apply_cancel($b, $map, $calId, $ev, $silent, $st);
                $bookDirty = true;
                continue;
            }
            $t = gcal_event_times($ev);
            if (!$t) { $st['skipped']++; continue; } /* целодневные/битые — пропускаем */
            $p = gcal_parse_event($ev);

            $known = gcal_map_get($map, $calId, $evId);
            if (!$known) gcal_apply_create($b, $map, $calId, $cal, $ev, $p, $t, $content, $specs, $silent, $st);
            elseif ((string)($known['upd'] ?? '') !== (string)($ev['updated'] ?? '')) gcal_apply_update($b, $map, $calId, $ev, $p, $t, $content, $specs, $silent, $st);
            else $st['unchanged']++;
            $bookDirty = true;
        }

        /* фиксируем syncToken и конец первичного импорта */
        $cfg['calendars'][$calId]['syncToken']  = ($newTok !== null && $newTok !== '') ? (string)$newTok : (string)($cal['syncToken'] ?? '');
        $cfg['calendars'][$calId]['first_done'] = true;
        $cfg['calendars'][$calId]['last_sync']  = time();
        gcal_cfg($cfg); /* сохраняем после каждого календаря: прогресс не теряется при сбое на следующем */
    }

    if ($bookDirty) gcal_book_save($b);
    gcal_map_save($map);

    $cfg['last_sync'] = time();
    if (!$st['errors']) $cfg['last_ok'] = time();
    gcal_cfg($cfg);

    flock($lf, LOCK_UN);
    fclose($lf);
    return $st;
}
