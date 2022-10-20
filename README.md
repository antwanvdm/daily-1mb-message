# Daily 1MB Message

Once upon a time people used MSN Messenger as their daily chat tool.
There was no facebook, instagram, whatsapp, telegram, etc. You logged
in on your home computer and you were connected to your friends.

Everyone had the ability to configure personal names, status messages
and other configurations we know these days in tools like Slack and Teams.
With the "Messenger Plus" addon you even got more features like coloring
your name, adding custom emojis and more.

One of the cool features covered storing all your chatlogs in a folder
on your computer. All openly available to check upon on a later date and
time. Encryption was not available so if you were to be hacked, your
complete chat history would be out there as well.

Recently I found my old chatlogs when I was organising my external hard
drives and old backups. A tri down memory lane with all my Messenger chats
with my friends  between 2003 and 2008 open to read upon. Funny that almost
all of these  people are no longer part of my life, it literally was a
different era.

However, my best friend from this old era is still my best friend today.
We shared so many chats that in some months our chat file would reach the
legendary "1MB" size (hence the project name). Such an epic moment always
felt like an achievement, and reason to celebrate, to both of us.

To honor this memory I wrote this application that converts the chats into
a local database. On a daily basis I have a cron task configured that sends
5 random messages, out of the 87k+ chat messages we shared back in the days.
It sends them via a Twitter Direct Message to my best friend, so we can both
enjoy some random sentences from almost 20 years ago.

## Technical implementation
The implementation is done via PHP and a simple MySQL database. It uses the
Twitter API (v1.1), some UTF8 conversation magic and data encryption.
Packages used:
- [abraham/twitteroauth](https://github.com/abraham/twitteroauth)
- [nesbot/carbon](https://github.com/briannesbitt/Carbon)
- [voku/portable-utf8](https://github.com/voku/portable-utf8)
- [paragonie/halite](https://github.com/paragonie/halite)

The `settings.php` file looks like this. I implemented all the personal
information concerning messages & names in this file as well:
```php
<?php
//Database
const DB_HOST = '';
const DB_USER = '';
const DB_PASS = '';
const DB_NAME = '';

//Twitter
const TWITTER_API_KEY = '';
const TWITTER_API_SECRET = '';
const TWITTER_CLIENT_ID = '';
const TWITTER_CLIENT_SECRET = '';
const TWITTER_API_TOKEN = '';
const TWITTER_API_CALLBACK = 'http://localhost:8888/index.php';

//Application
const ENCRYPTION_ENABLED = true;
const ENCRYPTION_KEY_PATH = '/full/path/encryption.key';
const PERSONAL_EMAIL = '';
const PERSONAL_TWITTER_USERNAME = 'Antwanvdm';
const PERSONAL_POSSIBLE_CHAT_NAMES = ['Antwan', '(Y)Antwan'];
const CHAT_NAMES_WITH_COLONS_WITH_REPLACEMENTS = [
    ['Antwan :)', ':OMijn dag'],
    ['Antwan', 'Antwan']
];
const SENDER_ACCOUNT_DATABASE_ID = 14;
const PERSONAL_NAME = 'Antwan';
const SENDER_NAME = 'Victor';
const SENDER_TWITTER_ID = 0;
```

### Challenge: Charsets
The chatlogs are pretty old, starting in 2003. These files were not in the
UTF-8 charset, and different files had different encodings. Writing one
script to process all of them was challenging. In the end I didn't manage
to convert everything, so some characters eventually got ignored.

Remark: Out of 2366 chatlog files, only 1 failed with the following error:
```text
Warning: iconv(): Wrong encoding, conversion from "CP51932" to
"UTF-8//IGNORE" is not allowed
```

### Challenge: Parsing the date
I had to retrieve the dates for every chat. The downside was some files 
were stored with the English version of MSN, while the rest with the Dutch
version. I ended up using Carbon and tried to parse in both language to get
the expected date, which eventually worked out as planned. To deviate the
time from the actual message I ended up using a regular expression.

### Challenge: Parsing the messenger
Unfortunately the start of a chat only shows the end of the chat names
with the corresponding emails, while the actual chats only show the start
of the name without email. This means I had to trust my own name which
luckily always started with variations including my own name. Without my
past consistency it would've cost me way more time to identify the sender.

Another anoyying part is that I have to rely on the colon (`:`) to be the
separator between messenger and message. But a lot of cases have the `:`
in the messenger name as well, which have to manually replaced for the
parsing the work...

### Challenge: Separating group chats
The messenger developers decided to store the group chats in the individual
files. This means I had to count the available lines on top of a session.
Based on the count if it was bigger than 2, it's always a group chat.

### Challenge: Converting Emojis
MSN used the old skool emojis with classic :D/:-P notation. I needed to
discover how the Twitter API expects emojis. I ended up with a translation
array with old notations and the modern unicode for the same emotion.

### Challenge: Securing the data
The Daily (Tweet) message will be sent via a cronjob on a server. I added
encryption on the emails and messages. It's all old data of users who have
no idea I'm transforming old chats in a database. The biggest challenge was
choosing a 'simple' library to encrypt based on a private key. I made sure
I can choose to toggle the encryption so I'm always able to debug locally.

## Example chats
For those unfamiliar with the file format of the old MSN chatlogs, I added
an example. It probably illustrates why I had some challenges. :-)

Chatlog (December 2004/email.txt) with an individual:
```text
.--------------------------------------------------------------------.
| Start van sessie: dinsdag 2 december 2004                          |
| Deelnemers:                                                        |
|    ...Some old chat name :) (oldemail@msn.com)                     |
|    (#) Someone..      (L) (someoneelse@live.nl)                    |
.--------------------------------------------------------------------.
[17:47:01] (#) Someone.: haha nee hoor
[17:47:02] Antwan (Y) L: Huh wat?
[17:48:13] Antwan (Y) L: ja, dat is de vraag:P denk het eerlijk gezegd
           niet aangezien er volgens mij toch verder niemand is en jij
           hebt alleen les dus ja..
[17:49:04] Antwan (Y) Let's go :) // "Quote van een muziekband"; is aa
           ngemeld (Offline)
           
.--------------------------------------------------------------------.
| Start van sessie: dinsdag 23 december 2004                         |
| Deelnemers:                                                        |
|    ...Some old chat name :) (oldemail@msn.com)                     |
|    (#) Someone..      (L) (someoneelse@live.nl)                    |
.--------------------------------------------------------------------.
[16:35:30] Antwan (Y) L: More text on a different day

```

If there was a group chat in a file, the session start would look like
this:

```text
.--------------------------------------------------------------------.
| Start van sessie: dinsdag 23 december 2004                         |
| Deelnemers:                                                        |
|    ...Some old chat name :) (oldemail@msn.com)                     |
|    (#) Someone..      (L) (someoneelse@live.nl)                    |
|    ...Yes ready for my exams !!! (anotherperson@live.nl)           |
|    ...Zin om groeten te eten:-D (weeriemand@live.nl)               |
.--------------------------------------------------------------------.
```
