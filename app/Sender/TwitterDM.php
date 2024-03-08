<?php namespace App\Sender;

use Abraham\TwitterOAuth\TwitterOAuth;
use App\Account;

/**
 * Send a formatted Twitter direct message based on the Database entries
 */
class TwitterDM extends BaseSender
{
    /**
     * @param int $receiverId
     * @param array $messageData
     * @param Account|null $senderAccount
     * @return void
     */
    public function send(int $receiverId, array $messageData, Account $senderAccount = null): void
    {
        //Let's see if we can send a DM
        $twitterAccessToken = unserialize($senderAccount->twitter_access_token);
        $twitter = new TwitterOAuth(TWITTER_API_KEY, TWITTER_API_SECRET, $twitterAccessToken['oauth_token'], $twitterAccessToken['oauth_token_secret']);
        $data = [
            'event' => [
                'type' => 'message_create',
                'message_create' => [
                    'target' => [
                        'recipient_id' => $receiverId
                    ],
                    'message_data' => [
                        'text' => $messageData['text']
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
