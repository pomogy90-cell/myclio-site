<?php
/* КЛИО · OAuth-callback от Google для интеграции DIKIDI (ТЗ №4).
   Redirect URI для Google Cloud Console: https://myclio.ru/api/gcal-oauth.php
   Старт потока — кнопка «Подключить Google-аккаунт» в админке (действие gcal_oauth_start).
   Здесь сессия не проверяется: CSRF-защита потока — одноразовый state,
   хранящийся на сервере в api/data/gcal.json (веб-доступ туда закрыт .htaccess). */

require_once __DIR__ . '/gcal-lib.php';

function gcal_oauth_back($params){
    header('Location: /admin/?p=gcal' . ($params !== '' ? '&' . $params : ''));
    exit;
}

if (isset($_GET['error'])) gcal_oauth_back('err=' . urlencode('Google отклонил доступ: ' . (string)$_GET['error']));
if (!isset($_GET['code'])) gcal_oauth_back('err=' . urlencode('Пустой ответ Google — повторите подключение из админки.'));

$cfg = gcal_cfg();
$state = (string)($_GET['state'] ?? '');
if ($state === '' || !hash_equals((string)($cfg['oauth_state'] ?? '_'), $state))
    gcal_oauth_back('err=' . urlencode('Неверный state — повторите подключение из админки.'));
$cfg['oauth_state'] = ''; /* state одноразовый */

$code = (string)$_GET['code'];
$secret = gcal_dec((string)($cfg['client_secret_enc'] ?? ''));
if ($secret === '' || ($cfg['client_id'] ?? '') === '') gcal_oauth_back('err=' . urlencode('Сначала сохраните Client ID и Client Secret.'));

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirect = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'myclio.ru') . '/api/gcal-oauth.php';

$r = gcal_http('POST', rtrim((string)$cfg['oauth_base'], '/') . '/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'code'          => $code,
        'client_id'     => $cfg['client_id'],
        'client_secret' => $secret,
        'redirect_uri'  => $redirect,
        'grant_type'    => 'authorization_code',
    ]));
if ($r['code'] !== 200) {
    $cfg = gcal_err($cfg, 'OAuth callback: HTTP ' . $r['code'] . ' ' . mb_substr((string)$r['body'], 0, 300));
    gcal_oauth_back('err=' . urlencode('Не удалось обменять код на токен (HTTP ' . $r['code'] . ').'));
}
$tok = $r['json'];
if (empty($tok['refresh_token'])) {
    /* Google отдаёт refresh_token только при prompt=consent — без него работать не будет */
    $cfg = gcal_err($cfg, 'Google не вернул refresh_token — нужен повторный коннект с prompt=consent');
    gcal_oauth_back('err=' . urlencode('Google не вернул refresh_token. Повторите подключение.'));
}
$cfg['refresh_token_enc'] = gcal_enc((string)$tok['refresh_token']);
$cfg['access'] = ['token'=>(string)($tok['access_token'] ?? ''), 'exp'=>time() + (int)($tok['expires_in'] ?? 3600) - 30];
gcal_cfg($cfg);
gcal_oauth_back('ok=1');
