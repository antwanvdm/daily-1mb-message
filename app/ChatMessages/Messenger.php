<?php

namespace App\ChatMessages;

/**
 * The numbers match the data in the database.
 * In the current state '3' will never be in the DB as these are filtered out
 */
enum Messenger: int
{
    case Self = 0;
    case Sender = 1;
    case Group = 2;
    case Auto = 3;
}
