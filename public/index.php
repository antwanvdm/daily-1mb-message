<?php
require_once '../settings.php';
require_once '../vendor/autoload.php';

if (!isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) || $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] !== TELEGRAM_SECRET_HEADER) {
    die("Nothing much to see here");
}

ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
header('Connection: close');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

$telegramBot = new \App\Sender\TelegramBotMessage();
$telegramBot->handleUpdate();
