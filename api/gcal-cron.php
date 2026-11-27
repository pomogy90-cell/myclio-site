<?php
/* КЛИО · псевдо-cron для синхронизации DIKIDI → Google Calendar → CRM.
   Раздел Crontab в панели Timeweb на тестовом периоде недоступен, поэтому запуск
   делается «по-вордпрессовски»: любой запрос к API (штатное приложение сотрудников
   опрашивает сервер каждые ~45 сек в фоне, админка — во время работы) при
   необходимости запускает api/gcal-sync.php отдельным неблокирующим HTTP-вызовом
   к самому себе. Срабатывает не чаще раза в 55 секунд и только когда
   синхронизация включена и Google-аккаунт подключён. Всё молча: любые ошибки
   здесь не должны влиять на работу вызывающего запроса. */

function gcal_maybe_spawn(){
    $data = __DIR__ . '/data';
    $cfgF = $data . '/gcal.json';
    if (!is_file($cfgF)) return;
    $cfg = json_decode((string)@file_get_contents($cfgF), true);
    if (!is_array($cfg)) return;
    if (empty($cfg['sync_enabled'])) return;                      /* выключено в админке */
    if ((string)($cfg['refresh_token_enc'] ?? '') === '') return; /* Google не подключён */
    $token = (string)($cfg['sync_token'] ?? '');
    if ($token === '') return;

    $now = time();
    if ($now - (int)($cfg['last_sync'] ?? 0) < 55) return;        /* синхронизация свежая */

    /* троттлинг: параллельные запросы не должны порождать лавину запусков */
    $lock = $data . '/gcal-spawn.lock';
    $mt = @filemtime($lock);
    if ($mt && ($now - $mt) < 55) return;
    @touch($lock);

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? 'myclio.ru');
    $host = $hostHeader;
    $port = $https ? 443 : 80;
    if (strpos($hostHeader, ':') !== false) { /* нестандартный порт (тестовый стенд) */
        [$host, $p] = explode(':', $hostHeader, 2);
        if (preg_match('/^\d{1,5}$/', $p)) $port = (int)$p; /* ctype_digit нет в минимальных сборках PHP */
    }
    $path = '/api/gcal-sync.php?token=' . rawurlencode($token);

    /* неблокирующий вызов самого себя; gcal-sync.php работает с
       ignore_user_abort(true), поэтому закрытие сокета ему не мешает */
    $fp = @fsockopen(($https ? 'ssl://' : '') . $host, $port, $e, $es, 2);
    if (!$fp) return;
    @fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUser-Agent: KlioCron/1.0\r\nConnection: close\r\n\r\n");
    @fclose($fp);
}
