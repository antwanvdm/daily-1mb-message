<?php

namespace App;

use Exception;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Symmetric\Crypto as Symmetric;
use ParagonIE\HiddenString\HiddenString;

/**
 * Wrapper for encryption package (to handle Exceptions and have fewer lines of code in main application)
 */
class DataEncryption
{
    /**
     * @return void
     */
    public static function createKey(): void
    {
        try {
            $encKey = KeyFactory::generateEncryptionKey();
            KeyFactory::save($encKey, ENCRYPTION_KEY_PATH);
        } catch (Exception $e) {
        }
    }

    /**
     * @param string $data
     * @return string
     */
    public static function encrypt(string $data): string
    {
        try {
            $encryptionKey = KeyFactory::loadEncryptionKey(ENCRYPTION_KEY_PATH);
            $hiddenChatMessage = new HiddenString($data);
            return Symmetric::encrypt($hiddenChatMessage, $encryptionKey);
        } catch (Exception $e) {
            return $data;
        }
    }

    /**
     * @param string $data
     * @return string
     */
    public static function decrypt(string $data): string
    {
        try {
            $encryptionKey = KeyFactory::loadEncryptionKey(ENCRYPTION_KEY_PATH);
            return Symmetric::decrypt($data, $encryptionKey)->getString();
        } catch (Exception $e) {
            return $data;
        }
    }
}