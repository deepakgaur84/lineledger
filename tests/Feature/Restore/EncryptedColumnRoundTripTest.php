<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Membership;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\Exceptions\BundleValidationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * employee_payroll_profiles.sin_encrypted carries Laravel's `encrypted` cast.
 * A backup must restore it readable on this instance or on another one with a
 * different APP_KEY, and a restored organization must back up again.
 * (D8Lifecycle-01.)
 */

/**
 * Back up `$company`. A failed export leaves its status and error on the row,
 * which is what the assertions read.
 */
function encryptedSinBackup(Company $company, User $owner): CompanyBackup
{
    app()->instance('current_company', $company);

    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);

    try {
        app(CompanyExporter::class)->export($backup);
    } catch (Throwable) {
        // status + error_message are persisted by the exporter
    }

    return $backup->refresh();
}

function encryptedSinRestore(CompanyBackup $backup, User $owner): CompanyRestore
{
    $restorePath = 'restores/'.basename($backup->file_path);
    Storage::disk('local')->put($restorePath, Storage::disk('local')->get($backup->file_path));

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restorePath,
    ]);

    app()->forgetInstance('current_company');

    try {
        app(CompanyImporter::class)->import($restore);
    } catch (Throwable) {
        // status + error_message are persisted by the importer
    }

    return $restore->refresh();
}

function encryptedSinRestoredProfile(CompanyRestore $restore): EmployeePayrollProfile
{
    return EmployeePayrollProfile::withoutGlobalScopes()
        ->where('company_id', $restore->company_id)
        ->sole();
}

/** The SIN column exactly as stored, past the cast. */
function encryptedSinRawValue(int $companyId): ?string
{
    return DB::table('employee_payroll_profiles')->where('company_id', $companyId)->value('sin_encrypted');
}

/**
 * @return array<string, mixed>
 */
function encryptedSinManifest(CompanyBackup $backup): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($backup->file_path));
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $zip->close();

    return $manifest;
}

/**
 * Rewrite the backup's manifest in place. The per-table hashes cover the
 * JSONL files only, so the bundle stays valid.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $edit
 */
function encryptedSinEditManifest(CompanyBackup $backup, callable $edit): void
{
    $manifest = $edit(encryptedSinManifest($backup));

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($backup->file_path));
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->create(['email' => 'owner-sin@acme.test']);
    $this->company = Company::factory()->create(['name' => 'Payroll SIN Co', 'address_country' => 'CA']);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => CompanyRole::Owner]);
    app()->instance('current_company', $this->company);

    $employee = Contact::create(['display_name' => 'Sin Holder', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->make(['contact_id' => $employee->id, 'company_id' => $this->company->id]);
    $this->profile->setSin('046 454 286');
    $this->profile->save();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('keeps an employee SIN readable after a backup is restored', function () {
    $backup = encryptedSinBackup($this->company, $this->owner);
    expect($backup->status)->toBe(CompanyBackupStatus::Ready);

    // The manifest says how the bundle carries the column.
    expect(encryptedSinManifest($backup)['tables']['employee_payroll_profiles']['encrypted_columns'])
        ->toBe(['sin_encrypted' => ['encoding' => 'plaintext', 'unreadable' => 0]]);

    $restore = encryptedSinRestore($backup, $this->owner);
    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message);

    $restored = encryptedSinRestoredProfile($restore);

    // T4 / RL-1 / ROE generation reads the SIN through the cast; before the fix
    // this threw DecryptException ("The payload is invalid."), because the
    // restore had stored the plaintext.
    expect($restored->sin_encrypted)->toBe('046454286')
        ->and($restored->sinOrNull())->toBe('046454286')
        ->and($restored->sin_last4)->toBe('4286')
        ->and(encryptedSinRawValue($restore->company_id))->not->toContain('046454286');
});

it('can back up a company that was itself restored from a backup, and restore that backup too', function () {
    $backup = encryptedSinBackup($this->company, $this->owner);
    $restore = encryptedSinRestore($backup, $this->owner);
    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message);

    $restoredCompany = Company::withoutGlobalScopes()->findOrFail($restore->company_id);

    $second = encryptedSinBackup($restoredCompany, $this->owner);

    expect($second->status)->toBe(CompanyBackupStatus::Ready, 'second-generation backup failed: '.$second->error_message);

    $secondRestore = encryptedSinRestore($second, $this->owner);

    expect($secondRestore->status)->toBe(CompanyRestoreStatus::Completed, (string) $secondRestore->error_message)
        ->and(encryptedSinRestoredProfile($secondRestore)->sin_encrypted)->toBe('046454286');
});

it('restores the SIN onto an instance with a different APP_KEY', function () {
    $backup = encryptedSinBackup($this->company, $this->owner);
    $sourceCiphertext = encryptedSinRawValue($this->company->id);

    // The target instance has its own key; the bundle must not need the source's.
    $target = new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher'));
    Crypt::swap($target);

    $restore = encryptedSinRestore($backup, $this->owner);
    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message);

    expect($target->decryptString((string) encryptedSinRawValue($restore->company_id)))->toBe('046454286')
        ->and(encryptedSinRestoredProfile($restore)->sin_encrypted)->toBe('046454286')
        // Proof the restore re-encrypted rather than copied: the source's
        // ciphertext does not open under the target's key.
        ->and(fn () => $target->decryptString((string) $sourceCiphertext))->toThrow(DecryptException::class);
});

it('restores a backup made before the manifest recorded how encrypted columns are carried', function () {
    $backup = encryptedSinBackup($this->company, $this->owner);

    // Such a bundle held the same plaintext, serialised through the cast.
    encryptedSinEditManifest($backup, function (array $manifest): array {
        unset($manifest['tables']['employee_payroll_profiles']['encrypted_columns']);

        return $manifest;
    });

    $restore = encryptedSinRestore($backup, $this->owner);

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message)
        ->and(encryptedSinRestoredProfile($restore)->sin_encrypted)->toBe('046454286');
});

it('backs up a SIN that no longer decrypts as blank, warns before the restore, and restores the rest', function () {
    // Ciphertext from another APP_KEY, as after a database restore under a new key.
    $foreign = (new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher')))->encryptString('046454286');
    DB::table('employee_payroll_profiles')->where('id', $this->profile->id)->update(['sin_encrypted' => $foreign]);

    $backup = encryptedSinBackup($this->company, $this->owner);

    expect($backup->status)->toBe(CompanyBackupStatus::Ready, (string) $backup->error_message)
        ->and(encryptedSinManifest($backup)['tables']['employee_payroll_profiles']['encrypted_columns']['sin_encrypted'])
        ->toBe(['encoding' => 'plaintext', 'unreadable' => 1]);

    $preview = app(BundleInspector::class)->inspect(Storage::disk('local')->path($backup->file_path), $this->owner);

    expect($preview['warnings'])->toContain(
        '1 employee SIN could not be read when this backup was made, so that employee will be restored without a SIN. Re-enter it on their payroll profile.'
    );

    $restore = encryptedSinRestore($backup, $this->owner);
    $restored = encryptedSinRestoredProfile($restore);

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message)
        ->and($restored->sin_encrypted)->toBeNull()
        ->and($restored->sin_last4)->toBe('4286');
});

it('carries a SIN that an earlier restore stored unencrypted, and restores it encrypted', function () {
    // What the importer wrote before this fix, if app:upgrade has not repaired it yet.
    DB::table('employee_payroll_profiles')->where('id', $this->profile->id)->update(['sin_encrypted' => '046454286']);

    $backup = encryptedSinBackup($this->company, $this->owner);

    expect($backup->status)->toBe(CompanyBackupStatus::Ready, (string) $backup->error_message)
        ->and(encryptedSinManifest($backup)['tables']['employee_payroll_profiles']['encrypted_columns']['sin_encrypted']['unreadable'])
        ->toBe(0);

    $restore = encryptedSinRestore($backup, $this->owner);

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message)
        ->and(encryptedSinRestoredProfile($restore)->sin_encrypted)->toBe('046454286');
});

it('refuses a bundle that carries encrypted columns in an encoding it does not know', function () {
    $backup = encryptedSinBackup($this->company, $this->owner);

    encryptedSinEditManifest($backup, function (array $manifest): array {
        $manifest['tables']['employee_payroll_profiles']['encrypted_columns']['sin_encrypted']['encoding'] = 'sealed-v9';

        return $manifest;
    });

    $message = 'This backup stores encrypted values in a format this version of LineLedger cannot read. Upgrade LineLedger, then try the restore again.';

    expect(fn () => app(BundleInspector::class)->inspect(Storage::disk('local')->path($backup->file_path), $this->owner))
        ->toThrow(BundleValidationException::class, $message);

    $companies = Company::withoutGlobalScopes()->count();
    $restore = encryptedSinRestore($backup, $this->owner);

    expect($restore->status)->toBe(CompanyRestoreStatus::Failed)
        ->and($restore->error_message)->toBe($message)
        ->and(Company::withoutGlobalScopes()->count())->toBe($companies);
});
