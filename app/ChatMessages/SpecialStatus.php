<?php

namespace App\ChatMessages;

/**
 * The numbers match the data in the database.
 */
enum SpecialStatus: int
{
    case None = 0;
    case Tuesday = 1;
    case Wednesday = 2;
}
