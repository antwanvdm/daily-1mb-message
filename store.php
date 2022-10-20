<?php

require_once 'settings.php';
require_once 'vendor/autoload.php';

//Loop through chatlogs
$iterator = new RecursiveDirectoryIterator("chatlogs");
$chatLogs = [];
foreach (new RecursiveIteratorIterator($iterator) as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() === 'txt') {
        $email = str_replace('.txt', '', $file->getFilename());
        $parser = new \App\ChatlogParser($file->getPathname());
        $chatLogs[$email] = array_merge_recursive($chatLogs[$email] ?? [], $parser->getChatMessages());
    }
}

//Clean current tables & add personal user (using this code because I'm testing 123123 times)
$db = \App\Database::getInstance();
$db->query('TRUNCATE TABLE messages');
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query('TRUNCATE TABLE accounts');
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$account = new \App\Account(null, PERSONAL_EMAIL, PERSONAL_TWITTER_USERNAME);
$account->save();

//Add all records to DB
foreach ($chatLogs as $accountEmail => $chatDates) {
    $account = new \App\Account(null, $accountEmail);
    $accountId = $account->save();
    foreach ($chatDates as $date => $chatMessages) {
        foreach ($chatMessages as $chatMessage) {
            /** @var \App\ChatMessage $chatMessage */
            $chatMessage->save($accountId);
        }
    }
}
