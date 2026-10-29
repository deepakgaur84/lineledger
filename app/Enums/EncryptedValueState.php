<?php

namespace App\Enums;

use App\Support\Encryption\EncryptedColumns;

/**
 * What a raw value in a column with an `encrypted` cast turned out to be, as
 * {@see EncryptedColumns::read()} classifies it.
 */
enum EncryptedValueState: string
{
    /** The column is null. */
    case Empty = 'empty';

    /** Ciphertext that this instance's APP_KEY (or one of APP_PREVIOUS_KEYS) opens. */
    case Readable = 'readable';

    /**
     * Not ciphertext at all. The cast only ever writes ciphertext, so the value
     * was written past it: a restore made before restores encrypted these
     * columns stored the plaintext this way.
     */
    case Unencrypted = 'unencrypted';

    /** Ciphertext that none of this instance's keys open (another APP_KEY, or corrupt). */
    case Unreadable = 'unreadable';
}
