<?php

use App\Enums\EncryptedValueState;
use App\Models\EmployeePayrollProfile;
use App\Services\Backup\BackupTableRegistry;
use App\Support\Encryption\EncryptedColumns;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Needs the container (the encrypter) and the schema (the registry checks).
uses(TestCase::class, RefreshDatabase::class);

/** A model with every flavour of encrypted cast, beside casts that are not encryption. */
function encryptedColumnsTestModel(): Model
{
    return new class extends Model
    {
        protected function casts(): array
        {
            return [
                'secret' => 'encrypted',
                'secret_array' => 'encrypted:array',
                'secret_collection' => 'encrypted:collection',
                'secret_json' => 'encrypted:json',
                'secret_object' => 'encrypted:object',
                'secret_array_object' => AsEncryptedArrayObject::class,
                'secret_typed_collection' => AsEncryptedCollection::using(Collection::class),
                'password' => 'hashed',
                'amount' => 'integer',
                'meta' => 'array',
            ];
        }
    };
}

function encryptedColumnsOtherKey(): Encrypter
{
    return new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher'));
}

it('finds every flavour of encrypted cast and nothing else', function () {
    expect(EncryptedColumns::of(encryptedColumnsTestModel()::class))->toBe([
        'secret',
        'secret_array',
        'secret_collection',
        'secret_json',
        'secret_object',
        'secret_array_object',
        'secret_typed_collection',
    ]);
});

it('classifies ciphertext, an unencrypted value, foreign ciphertext and null', function () {
    $model = EmployeePayrollProfile::class;

    expect(EncryptedColumns::read($model, Crypt::encryptString('046454286')))
        ->toBe(['state' => EncryptedValueState::Readable, 'plaintext' => '046454286'])
        // What a restore stored before it encrypted the column: the plaintext.
        ->and(EncryptedColumns::read($model, '046454286'))
        ->toBe(['state' => EncryptedValueState::Unencrypted, 'plaintext' => '046454286'])
        ->and(EncryptedColumns::read($model, encryptedColumnsOtherKey()->encryptString('046454286')))
        ->toBe(['state' => EncryptedValueState::Unreadable, 'plaintext' => null])
        // The right shape but a bad MAC is still ciphertext, never plaintext.
        ->and(EncryptedColumns::read($model, 'eyJpdiI6IjEyMyIsInZhbHVlIjoiYWJjIiwibWFjIjoiZGVmIn0='))
        ->toBe(['state' => EncryptedValueState::Unreadable, 'plaintext' => null])
        ->and(EncryptedColumns::read($model, null))
        ->toBe(['state' => EncryptedValueState::Empty, 'plaintext' => null]);
});

it('reads ciphertext from a previous APP_KEY as readable', function () {
    $previous = Encrypter::generateKey(config('app.cipher'));
    $oldCiphertext = (new Encrypter($previous, config('app.cipher')))->encryptString('046454286');

    Crypt::swap((new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher')))->previousKeys([$previous]));

    expect(EncryptedColumns::read(EmployeePayrollProfile::class, $oldCiphertext))
        ->toBe(['state' => EncryptedValueState::Readable, 'plaintext' => '046454286']);
});

it('encrypts exactly what the cast stores, for string and array flavours', function () {
    $profile = new EmployeePayrollProfile;
    $profile->setRawAttributes(['sin_encrypted' => EncryptedColumns::encrypt(EmployeePayrollProfile::class, '046454286')]);

    $model = encryptedColumnsTestModel();
    // A bundle serialised through the cast holds the decoded array.
    $model->setRawAttributes(['secret_array' => EncryptedColumns::encrypt($model::class, ['a' => 1, 'b' => ['c' => 'é/']])]);

    expect($profile->sin_encrypted)->toBe('046454286')
        ->and($model->secret_array)->toBe(['a' => 1, 'b' => ['c' => 'é/']])
        ->and(EncryptedColumns::encrypt(EmployeePayrollProfile::class, null))->toBeNull();
});

it('round-trips a stored value through read and encrypt under a different key', function () {
    $stored = Crypt::encryptString('{"a":1}');
    $plaintext = EncryptedColumns::read(EmployeePayrollProfile::class, $stored)['plaintext'];

    $target = encryptedColumnsOtherKey();
    Crypt::swap($target);

    expect($target->decryptString((string) EncryptedColumns::encrypt(EmployeePayrollProfile::class, $plaintext)))->toBe('{"a":1}');
});

it('knows the registry’s encrypted columns, each on a table the repair can scope by company', function () {
    $found = [];

    foreach (BackupTableRegistry::tables() as $entry) {
        foreach (EncryptedColumns::of($entry['model']) as $column) {
            $found[] = $entry['table'].'.'.$column;

            // EncryptedColumnRepairer filters on company_id.
            expect(Schema::hasColumn($entry['table'], 'company_id'))->toBeTrue(
                "{$entry['table']} has an encrypted column but no company_id; extend EncryptedColumnRepairer to scope it."
            );
        }
    }

    expect($found)->toContain('employee_payroll_profiles.sin_encrypted');
});
