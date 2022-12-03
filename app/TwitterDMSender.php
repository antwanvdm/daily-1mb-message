<?php

namespace App;

use Abraham\TwitterOAuth\TwitterOAuth;
use App\ChatMessages\ChatMessage;
use App\ChatMessages\Messenger;

/**
 * Send a formatted Twitter direct message based on the Database entries
 */
class TwitterDMSender
{
    /**
     * @var array|string[]
     * @see http://web.archive.org/web/20140204231459/http://messenger.msn.com/Resource/Emoticons.aspx
     * @see https://unicode.org/emoji/charts/full-emoji-list.html
     */
    private array $emojiConverter = [
        ':P' => "\u{1F61D}",
        ':p' => "\u{1F61D}",
        ':-P' => "\u{1F61D}",
        ':-p' => "\u{1F61D}",
        ':)' => "\u{1F60A}",
        ':-)' => "\u{1F60A}",
        ':(' => "\u{1F61E}",
        ':-(' => "\u{1F61E}",
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
        ':$' => "\u{1F633}",
        ':-$' => "\u{1F633}",
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
        ':-#' => "\u{1F910}",
        '8-|' => "\u{1F913}",
        ':-*' => "\u{1FAE2}",
        ':^)' => "\u{1F62F}",
        '<:o)' => "\u{1F973}",
        '|-)' => "\u{1F634}",
        '8o|' => "\u{1F624}",
        '^o)' => "\u{1F928}",
        '+o(' => "\u{1F912}",
        '*-)' => "\u{1F914}",
        '8-)' => "\u{1F644}",
        '(Y)' => "\u{1F44D}",
        '(y)' => "\u{1F44D}",
        '(N)' => "\u{1F44E}",
        '(n)' => "\u{1F44E}",
        '(B)' => "\u{1F37A}",
        '(b)' => "\u{1F37A}",
        '(D)' => "\u{1F378}",
        '(d)' => "\u{1F378}",
        '(K)' => "\u{1F48B}",
        '(k)' => "\u{1F48B}",
        '(F)' => "\u{1F339}",
        '(f)' => "\u{1F339}",
        '(W)' => "\u{1F940}",
        '(w)' => "\u{1F940}",
        '(Z)' => "\u{1F466}",
        '(z)' => "\u{1F466}",
        '(X)' => "\u{1F467}",
        '(x)' => "\u{1F467}",
        '(so)' => "\u{26BD}",
        '(8)' => "\u{1F3B5}",
        '(T)' => "\u{1F4DE}",
        '(t)' => "\u{1F4DE}",
        '(C)' => "\u{2615}",
        '(c)' => "\u{2615}",
        ':-[' => "\u{1F987}",
        ':[' => "\u{1F987}",
        '(^)' => "\u{1F382}",
        '(G)' => "\u{1F381}",
        '(g)' => "\u{1F381}",
        '(P)' => "\u{1F4F7}",
        '(p)' => "\u{1F4F7}",
        '(~)' => "\u{1F4FD}",
        '(@)' => "\u{1F431}",
        '(&)' => "\u{1F436}",
        '(I)' => "\u{1F4A1}",
        '(i)' => "\u{1F4A1}",
        '(S)' => "\u{1F31B}",
        '(*)' => "\u{2B50}",
        '(E)' => "\u{1F4E7}",
        '(e)' => "\u{1F4E7}",
        '(O)' => "\u{1F550}",
        '(o)' => "\u{1F550}",
        '(sn)' => "\u{1F40C}",
        '(bah)' => "\u{1F411}",
        '(pl)' => "\u{1F37D}",
        '(||)' => "\u{1F963}",
        '(pi)' => "\u{1F355}",
        '(au)' => "\u{1F697}",
        '(ap)' => "\u{2708}",
        '(um)' => "\u{2602}",
        '(ip)' => "\u{1F3DD}",
        '(co)' => "\u{1F5A5}",
        '(mp)' => "\u{1F4F1}",
        '(st)' => "\u{1F327}",
        '(li)' => "\u{1F329}",
        '(mo)' => "\u{1FA99}",
        ':_' => "\u{1F612}",
        ';-' => "\u{1F611}",
        '(stop)' => "\u{1F6D1}",
        'pik' => "\u{1F346}",
    ];

    /**
     * @param ChatMessage[] $chatMessages
     * @return string
     */
    public function convertChatMessagesToTwitterMessage(array $chatMessages): string
    {
        $twitterMessage = "\u{1F525} " . $this->getIntroductionString() . PHP_EOL . PHP_EOL;
        foreach ($chatMessages as $chatMessage) {
            $senderString = $this->getMessengerString($chatMessage->messenger);
            $twitterMessage .= "Op $chatMessage->date om $chatMessage->time stuurde $senderString: $chatMessage->message" . PHP_EOL . PHP_EOL;
        }
        return str_replace(array_keys($this->emojiConverter), $this->emojiConverter, $twitterMessage);
    }

    /**
     * @return string
     */
    private function getIntroductionString(): string
    {
        return match ((int)date('N')) {
            2 => '#heroestuesday met een random selectie van 5 berichten uit onze rijke historie aan chatberichten waar Victor, Andy, Yannis of Daniel benoemd werden:',
            4 => '#throwbackthursday met een random selectie van 5 berichten uit onze rijke historie aan chatberichten die plaatsvonden op de donderdag:',
            7 => '#supersunday met een speciale zondagse selectie van 10 berichten die achter elkaar verstuurd zijn:',
            default => 'Dagelijkse random selectie van 5 berichten uit onze rijke historie aan chatberichten:',
        };
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
