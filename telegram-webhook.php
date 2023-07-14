<?php

require_once 'settings.php';
require_once 'vendor/autoload.php';

global $argv;
$action = $argv[1] ?? 'register';

try {
    $telegramBot = new \App\Sender\TelegramBotMessage();
    if ($action === 'register') {
        $result = $telegramBot->registerWebhook();
        echo $result->getDescription();
    } else {
        $telegramBot->deleteWebhook();
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
