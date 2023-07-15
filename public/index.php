<?php
require_once '../settings.php';
require_once '../vendor/autoload.php';

if (!isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) || $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] !== TELEGRAM_SECRET_HEADER) {
    die("Nothing much to see here");
}

$telegramBot = new \App\Sender\TelegramBotMessage();
$telegramBot->handleUpdate();
