<?php
/* КЛИО · точка запуска синхронизации DIKIDI → Google Calendar → CRM (ТЗ №4, §5).
   Вызывается каждые 60 секунд планировщиком:
     HTTP:  https://myclio.ru/api/gcal-sync.php?token=СЕКРЕТ   (wget/curl из cron)
     CLI:   php /путь/к сайту/api/gcal-sync.php
   Секрет лежит в api/data/gcal.json (sync_token) — веб-доступ туда закрыт .htaccess. */

require_once __DIR__ . '/gcal-lib.php';

set_time_limit(150);
ignore_user_abort(true); /* вызов может прийти из псевдо-cron (gcal-cron.php) — закрытие сокета не должно прерывать синхронизацию */
header('Content-Type: application/json; charset=utf-8');

$cfg = gcal_cfg();

/* автогенерация секрета при первом обращении */
if ((string)($cfg['sync_token'] ?? '') === '') {
    $cfg['sync_token'] = bin2hex(random_bytes(24));
    gcal_cfg($cfg);
}

if (PHP_SAPI !== 'cli') {
    $tok = (string)($_GET['token'] ?? '');
    if ($tok === '' || !hash_equals((string)$cfg['sync_token'], $tok)) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* CLI и ?force=1 — ручной запуск (кнопка в админке вызывает движок напрямую, ТЗ §8);
   обычный HTTP-вызов из cron уважает переключатель sync_enabled. */
$force = (PHP_SAPI === 'cli') || (($_GET['force'] ?? '') === '1');
$st = gcal_sync_run($force);
echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
