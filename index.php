<?php

require_once 'settings.php';
require_once 'vendor/autoload.php';
session_start();

$done = false;
if (isset($_GET['connect'])) {
    //Build upt he URL for our application. Redirect will go to Twitter to approve application
    $twitter = new \Abraham\TwitterOAuth\TwitterOAuth(TWITTER_API_KEY, TWITTER_API_SECRET);
    $request_token = $twitter->oauth('oauth/request_token', array('oauth_callback' => TWITTER_API_CALLBACK));
    print_r($request_token);
    $_SESSION['oauth_token'] = $request_token['oauth_token'];
    $_SESSION['oauth_token_secret'] = $request_token['oauth_token_secret'];
    $url = $twitter->url('oauth/authorize', array('oauth_token' => $request_token['oauth_token']));
    header('Location: ' . $url);
} elseif (isset($_GET['oauth_token'])) {
    //Twitter will redirect back to this URL. We now have the long term access token
    $request_token = [];
    $request_token['oauth_token'] = $_SESSION['oauth_token'];
    $request_token['oauth_token_secret'] = $_SESSION['oauth_token_secret'];

    if (isset($_REQUEST['oauth_token']) && $request_token['oauth_token'] !== $_REQUEST['oauth_token']) {
        exit;
    }

    $twitter = new \Abraham\TwitterOAuth\TwitterOAuth(TWITTER_API_KEY, TWITTER_API_SECRET, $request_token['oauth_token'], $request_token['oauth_token_secret']);
    $access_token = $twitter->oauth("oauth/access_token", ["oauth_verifier" => $_REQUEST['oauth_verifier']]);

    //We will store the token in the DB & redirect the user
    $db = \App\Database::getInstance();
    $statement = $db->prepare(
        "UPDATE accounts SET `twitter_access_token` = :twitter_access_token WHERE `twitter_screen_name` = :twitter_screen_name"
    );
    $statement->execute([
        ':twitter_screen_name' => $access_token['screen_name'],
        ':twitter_access_token' => serialize($access_token)
    ]);

    header('Location: index.php?done');
} elseif (isset($_GET['done'])) {
    $done = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Daily 1MB Message | Twitter connect</title>
</head>
<body>
<h1>Daily 1MB Message | Twitter connect</h1>
<?php if ($done): ?>
    <p>You are now connected and the token is safely stored. Enjoy!</p>
<?php else: ?>
    <a href="?connect">Let's connect your Twitter account</a>
<?php endif; ?>
</body>
</html>
