<?php

namespace App;

use Abraham\TwitterOAuth\TwitterOAuth;

/**
 * Send a formatted Twitter direct message based on the Database entries
 */
class TwitterDMSender
{
    /**
     * @var array|string[]
     * @see https://elouai.com/msn-emoticons.php
     * @see https://unicode.org/emoji/charts/full-emoji-list.html
     */
    private array $emojiConverter = [
        ':P' => "\u{1F61D}",
        ':p' => "\u{1F61D}",
        ':-P' => "\u{1F61D}",
        ':-p' => "\u{1F61D}",
        ':)' => "\u{1F60A}",
        ':-)' => "\u{1F60A}",
        ':(' => "\u{2639}",
        ':-(' => "\u{2639}",
        ':D' => "\u{1F603}",
        ':d' => "\u{1F603}",
        ':-D' => "\u{1F603}",
        ':-d' => "\u{1F603}",
        ':S' => "\u{1F615}",
        ':s' => "\u{1F615}",
        ':-S' => "\u{1F615}",
        ':-s' => "\u{1F615}",
        ':O' => "\u{1F632}",
        ':o' => "\u{1F632}",
        ':-O' => "\u{1F632}",
        ':-o' => "\u{1F632}",
        ';)' => "\u{1F609}",
        ';-)' => "\u{1F609}",
        ':|' => "\u{1F610}",
        ':-|' => "\u{1F610}",
        ':\'(' => "\u{1F62D}",
        ':$' => "\u{263A}",
        ':-$' => "\u{263A}",
        ':@' => "\u{1F621}",
        ':-@' => "\u{1F621}",
        '(L)' => "\u{2764}",
        '(l)' => "\u{2764}",
        '(H)' => "\u{1F60E}",
        '(h)' => "\u{1F60E}",
        '(A)' => "\u{1F607}",
        '(a)' => "\u{1F607}",
        '(U)' => "\u{1F494}",
        '(u)' => "\u{1F494}",
        '(6)' => "\u{1F608}",
        ':_' => "\u{1F612}",
        '^o)' => "\u{1F914}",
    ];

    /**
     * @param ChatMessage[] $chatMessages
     * @return string
     */
    public function convertChatMessagesToTwitterMessage(array $chatMessages): string
    {
        $twitterMessage = "\u{1F525} Dagelijkse random selectie van 5 berichten uit onze rijke historie aan chatberichten:" . PHP_EOL . PHP_EOL;
        foreach ($chatMessages as $chatMessage) {
            $senderString = $this->getMessengerString($chatMessage->messenger);
            $twitterMessage .= "Op {$chatMessage->date} om {$chatMessage->time} stuurde {$senderString}: {$chatMessage->message}" . PHP_EOL . PHP_EOL;
        }
        return str_replace(array_keys($this->emojiConverter), $this->emojiConverter, $twitterMessage);
    }

    /**
     * @param Messenger $messenger
     * @return string
     */
    private function getMessengerString(Messenger $messenger): string
    {
        return $messenger === Messenger::Self ? (PERSONAL_NAME . ' naar ' . SENDER_NAME) :
            ($messenger === Messenger::Sender ? (SENDER_NAME . ' naar ' . PERSONAL_NAME) : 'Iemand in een groepschat');
    }

    /**
     * @param int $receiverTwitterId
     * @param string $twitterMessage
     * @param array $twitterAccessToken
     * @return void
     */
    public function send(int $receiverTwitterId, string $twitterMessage, array $twitterAccessToken): void
    {
        //Let's see if we can send a DM
        $twitter = new TwitterOAuth(TWITTER_API_KEY, TWITTER_API_SECRET, $twitterAccessToken['oauth_token'], $twitterAccessToken['oauth_token_secret']);
        $data = [
            'event' => [
                'type' => 'message_create',
                'message_create' => [
                    'target' => [
                        'recipient_id' => $receiverTwitterId
                    ],
                    'message_data' => [
                        'text' => $twitterMessage
                    ]
                ]
            ]
        ];
        $twitter->post('direct_messages/events/new', $data, true);
        if ($twitter->getLastHttpCode() !== 200) {
            // Handle error case
        }
    }
}
