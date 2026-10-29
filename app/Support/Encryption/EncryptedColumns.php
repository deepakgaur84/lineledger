<?php

namespace App\Support\Encryption;

use App\Enums\EncryptedValueState;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Columns with an `encrypted` cast, read and written below Eloquent, for the
 * code that moves raw rows: the backup writer, the restore importer, and the
 * repair for organizations restored before the importer encrypted them.
 *
 * Every flavour of the cast (`encrypted`, `encrypted:array|collection|json|object`,
 * AsEncryptedArrayObject, AsEncryptedCollection) stores the same thing: the
 * encrypter's encryptString() of the attribute's plaintext storage form, which
 * is the JSON for the array flavours. Working on that string round-trips every
 * flavour exactly, without having to know which one a column uses.
 *
 * Encryption goes through Model::currentEncrypter(), the encrypter the string
 * casts use. It is the app's APP_KEY encrypter, which the class casts use too,
 * unless something calls Model::encryptUsing(); nothing in LineLedger does.
 */
final class EncryptedColumns
{
    /**
     * The columns `$modelClass` casts as encrypted.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    public static function of(string $modelClass): array
    {
        $columns = [];

        foreach ((new $modelClass)->getCasts() as $column => $cast) {
            if (self::isEncryptedCast($cast)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Classify a raw stored value and recover its plaintext where there is one:
     * the decrypted value, or for an unencrypted value the value itself.
     *
     * @param  class-string<Model>  $modelClass
     * @return array{state: EncryptedValueState, plaintext: ?string}
     */
    public static function read(string $modelClass, mixed $raw): array
    {
        if ($raw === null) {
            return ['state' => EncryptedValueState::Empty, 'plaintext' => null];
        }

        $raw = (string) $raw;

        try {
            $plaintext = $modelClass::currentEncrypter()->decrypt($raw, false);
        } catch (DecryptException) {
            return self::looksEncrypted($raw)
                ? ['state' => EncryptedValueState::Unreadable, 'plaintext' => null]
                : ['state' => EncryptedValueState::Unencrypted, 'plaintext' => $raw];
        }

        return ['state' => EncryptedValueState::Readable, 'plaintext' => (string) $plaintext];
    }

    /**
     * Encrypt a plaintext the way the cast stores it, with this instance's key.
     *
     * An array is a decoded JSON value, which is what an exporter that serialised
     * an `encrypted:array` column through the cast wrote; it is stored as its JSON.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function encrypt(string $modelClass, mixed $plaintext): ?string
    {
        if ($plaintext === null) {
            return null;
        }

        if (is_array($plaintext)) {
            $plaintext = json_encode($plaintext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (! is_scalar($plaintext)) {
            // A decoded JSONL row holds only scalars and arrays. Never put the
            // value in the message: it is the secret.
            throw new InvalidArgumentException('An encrypted column can only hold a string, a number, a boolean or an array.');
        }

        return $modelClass::currentEncrypter()->encrypt((string) $plaintext, false);
    }

    private static function isEncryptedCast(mixed $cast): bool
    {
        if (! is_string($cast)) {
            return false;
        }

        if ($cast === 'encrypted' || str_starts_with($cast, 'encrypted:')) {
            return true;
        }

        // Class casts may carry arguments: AsEncryptedCollection::class.':'.SomeCollection::class.
        $class = explode(':', $cast, 2)[0];

        return in_array($class, [AsEncryptedArrayObject::class, AsEncryptedCollection::class], true);
    }

    /**
     * Whether `$raw` has the shape of the encrypter's output: base64 of a JSON
     * object with an iv, a value and a mac.
     */
    private static function looksEncrypted(string $raw): bool
    {
        $json = base64_decode($raw, true);

        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
