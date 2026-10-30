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
use App\Services\Restore\CompanyImporter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * backup:repair-encrypted-columns (an app:upgrade step) encrypts the SINs that
 * restores made before the fix stored unencrypted, and reports the ones this
 * APP_KEY cannot decrypt. It must never touch ciphertext.
 */

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA']);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * An employee in `$company` whose SIN column holds `$raw` exactly, written past
 * the cast. Returns the profile id. Leaves no company bound, so the command
 * runs the way it does from a shell.
 */
function encryptedRepairProfile(Company $company, string $name, ?string $raw): int
{
    app()->instance('current_company', $company);

    $employee = Contact::create(['display_name' => $name, 'is_employee' => true]);
    $profile = EmployeePayrollProfile::factory()->create(['contact_id' => $employee->id]);
    DB::table('employee_payroll_profiles')->where('id', $profile->id)->update(['sin_encrypted' => $raw]);

    app()->forgetInstance('current_company');

    return $profile->id;
}

function encryptedRepairRaw(int $profileId): ?string
{
    return DB::table('employee_payroll_profiles')->where('id', $profileId)->value('sin_encrypted');
}

function encryptedRepairSin(int $profileId): ?string
{
    return EmployeePayrollProfile::withoutGlobalScopes()->findOrFail($profileId)->sinOrNull();
}

function encryptedRepairForeignCiphertext(): string
{
    return (new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher')))->encryptString('193456787');
}

it('encrypts a SIN a restore stored unencrypted and leaves everything else alone', function () {
    $good = encryptedRepairProfile($this->company, 'Good', encrypt('046454286', false));
    $restored = encryptedRepairProfile($this->company, 'Restored', '130692544');
    $foreign = encryptedRepairProfile($this->company, 'Foreign', $foreignRaw = encryptedRepairForeignCiphertext());
    $none = encryptedRepairProfile($this->company, 'None', null);
    $goodRaw = encryptedRepairRaw($good);

    expect(encryptedRepairSin($restored))->toBeNull();

    $this->artisan('backup:repair-encrypted-columns')
        ->expectsOutputToContain("Company {$this->company->slug} — encrypted 1 unencrypted value(s) in employee_payroll_profiles.sin_encrypted.")
        ->expectsOutputToContain("Company {$this->company->slug} — 1 value(s) in employee_payroll_profiles.sin_encrypted do not decrypt with this APP_KEY")
        ->expectsOutputToContain('Encrypted 1 unencrypted value(s); 1 value(s) do not decrypt with this APP_KEY.')
        ->assertSuccessful();

    expect(encryptedRepairSin($restored))->toBe('130692544')
        ->and(encryptedRepairRaw($restored))->not->toBe('130692544')
        // Ciphertext, readable or not, is byte-for-byte unchanged.
        ->and(encryptedRepairRaw($good))->toBe($goodRaw)
        ->and(encryptedRepairRaw($foreign))->toBe($foreignRaw)
        ->and(encryptedRepairRaw($none))->toBeNull();

    // Idempotent: a second run has nothing to encrypt, and still reports the
    // value it cannot recover.
    $this->artisan('backup:repair-encrypted-columns')
        ->expectsOutputToContain('Encrypted 0 unencrypted value(s); 1 value(s) do not decrypt with this APP_KEY.')
        ->doesntExpectOutputToContain('— encrypted')
        ->assertSuccessful();

    expect(encryptedRepairSin($restored))->toBe('130692544');
});

it('reports without writing on a dry run', function () {
    $restored = encryptedRepairProfile($this->company, 'Restored', '130692544');

    $this->artisan('backup:repair-encrypted-columns', ['--dry-run' => true])
        ->expectsOutputToContain("Company {$this->company->slug} — would encrypt 1 unencrypted value(s) in employee_payroll_profiles.sin_encrypted.")
        ->expectsOutputToContain('Would encrypt 1 unencrypted value(s); 0 value(s) do not decrypt with this APP_KEY.')
        ->assertSuccessful();

    expect(encryptedRepairRaw($restored))->toBe('130692544');
});

it('limits the repair to one company, by slug or id', function () {
    $other = Company::factory()->create(['address_country' => 'CA']);
    $mine = encryptedRepairProfile($this->company, 'Mine', '130692544');
    $theirs = encryptedRepairProfile($other, 'Theirs', '046454286');

    $this->artisan('backup:repair-encrypted-columns', ['company' => $this->company->slug])->assertSuccessful();

    expect(encryptedRepairSin($mine))->toBe('130692544')
        ->and(encryptedRepairRaw($theirs))->toBe('046454286');

    $this->artisan('backup:repair-encrypted-columns', ['company' => (string) $other->id])->assertSuccessful();

    expect(encryptedRepairSin($theirs))->toBe('046454286');
});

it('repairs, through app:upgrade, an organization restored before restores encrypted SINs', function () {
    Storage::fake('local');
    Storage::fake('public');

    $owner = User::factory()->create();
    Membership::create(['company_id' => $this->company->id, 'user_id' => $owner->id, 'role' => CompanyRole::Owner]);
    $profileId = encryptedRepairProfile($this->company, 'Sin Holder', null);
    app()->instance('current_company', $this->company);
    $profile = EmployeePayrollProfile::query()->findOrFail($profileId);
    $profile->setSin('046 454 286');
    $profile->save();

    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    $backup->refresh();

    $restorePath = 'restores/'.basename($backup->file_path);
    Storage::disk('local')->put($restorePath, Storage::disk('local')->get($backup->file_path));
    $restore = CompanyRestore::create([
        'requested_by_user_id' => $owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restorePath,
    ]);
    app()->forgetInstance('current_company');
    app(CompanyImporter::class)->import($restore);
    $restore->refresh();

    // What the importer used to leave behind: the plaintext SIN in the column.
    DB::table('employee_payroll_profiles')->where('company_id', $restore->company_id)->update(['sin_encrypted' => '046454286']);
    $restoredId = (int) DB::table('employee_payroll_profiles')->where('company_id', $restore->company_id)->value('id');
    expect(encryptedRepairSin($restoredId))->toBeNull();

    $this->artisan('app:upgrade')
        ->expectsOutputToContain('4. Encrypted columns')
        ->expectsOutputToContain('encrypted 1 unencrypted value(s) in employee_payroll_profiles.sin_encrypted.')
        ->assertSuccessful();

    expect(encryptedRepairSin($restoredId))->toBe('046454286');

    // And the repaired organization backs up again.
    $restoredCompany = Company::withoutGlobalScopes()->findOrFail($restore->company_id);
    app()->instance('current_company', $restoredCompany);
    $second = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($second);

    expect($second->refresh()->status)->toBe(CompanyBackupStatus::Ready);
});
