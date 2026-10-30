<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Enums\McpProposalStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyBackup;
use App\Models\CompanyInvitation;
use App\Models\CompanyRestore;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\McpWriteProposal;
use App\Models\Membership;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\RowTransformer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A restore next to the organization the backup came from (a copy on the same
 * instance, the flow RoundTripTest runs) must not trip a constraint that is
 * UNIQUE across the whole instance. Each case below once went into the copy
 * verbatim and failed the restore: invitation codes, the inbound-email token,
 * Stripe PaymentIntent ids and MCP confirm tokens. API key hashes were the
 * same class (#158). RowTransformer::GLOBALLY_UNIQUE_COLUMNS lists them all.
 */

/**
 * Back up the bound company and stage the ZIP where a restore reads it.
 */
function uniqueColumnsBackup(User $owner): string
{
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    $backup->refresh();

    expect($backup->status)->toBe(CompanyBackupStatus::Ready, 'export failed: '.$backup->error_message);

    $restorePath = 'restores/'.basename($backup->file_path);
    Storage::disk('local')->put($restorePath, Storage::disk('local')->get($backup->file_path));

    return $restorePath;
}

/**
 * Restore the staged ZIP into a new company on this instance, as a worker
 * would (nothing bound), and require it to complete.
 */
function uniqueColumnsRestore(User $owner, string $restorePath): CompanyRestore
{
    $restore = CompanyRestore::create([
        'requested_by_user_id' => $owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restorePath,
    ]);

    $source = app('current_company');
    app()->forgetInstance('current_company');

    try {
        app(CompanyImporter::class)->import($restore);
    } catch (Throwable) {
        // The importer records the failure on the row; asserted below.
    } finally {
        app()->instance('current_company', $source);
    }

    $restore->refresh();

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, 'restore failed: '.$restore->error_message);

    return $restore;
}

/**
 * A card payment recorded the way RecordStripePayment records one.
 */
function uniqueColumnsStripeReceipt(Company $company, string $paymentIntentId): CustomerReceipt
{
    return CustomerReceipt::create([
        'contact_id' => Contact::factory()->customer()->create()->id,
        'receipt_no' => 'R-'.Str::random(6),
        'receipt_date' => '2026-05-01',
        'deposit_to_account_id' => Account::query()->where('code', '1000')->firstOrFail()->id,
        'reference' => $paymentIntentId,
        'amount_cents' => 1000,
        'currency_code' => $company->currency_code,
        'home_amount_cents' => 1000,
        'status' => ReceiptStatus::Draft,
        'stripe_payment_intent_id' => $paymentIntentId,
    ]);
}

function uniqueColumnsProposal(User $owner, string $tool, McpProposalStatus $status): McpWriteProposal
{
    return McpWriteProposal::create([
        'user_id' => $owner->id,
        'tool' => $tool,
        'payload' => ['contact_id' => 1],
        'preview' => "A proposed {$tool}",
        'idempotency_key' => (string) Str::ulid(),
        'status' => $status,
        'expires_at' => now()->addDay(),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->create(['email' => 'owner@unique-columns.test']);
    $this->company = Company::factory()->create(['name' => 'Unique Co']);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => CompanyRole::Owner]);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('restores an organization that has invited a teammate, without sharing its invitation links', function () {
    // Accepting only stamps accepted_at, so every organization that ever added
    // a teammate keeps one of these.
    $accepted = CompanyInvitation::factory()->accepted()->create([
        'company_id' => $this->company->id,
        'invited_by' => $this->owner->id,
        'email' => 'teammate@unique-columns.test',
    ]);
    $open = CompanyInvitation::factory()->expiresIn(3)->create([
        'company_id' => $this->company->id,
        'invited_by' => $this->owner->id,
        'email' => 'newcomer@unique-columns.test',
    ]);

    $restore = uniqueColumnsRestore($this->owner, uniqueColumnsBackup($this->owner));

    $copies = CompanyInvitation::query()->where('company_id', $restore->company_id)->get()->keyBy('email');
    $acceptedCopy = $copies['teammate@unique-columns.test'];
    $openCopy = $copies['newcomer@unique-columns.test'];

    // Nobody holds the copy's codes; the emailed link still opens the original's invitation.
    expect($acceptedCopy->code)->toHaveLength(64)->not->toBe($accepted->code)
        ->and($openCopy->code)->toHaveLength(64)->not->toBe($open->code)
        ->and(CompanyInvitation::query()->where('code', $open->code)->sole()->company_id)->toBe($this->company->id);

    // The open one comes back expired, so it can't be accepted and doesn't stop
    // the owner inviting that person again. The accepted one keeps its date.
    expect($openCopy->isExpired())->toBeTrue()
        ->and($openCopy->isPending())->toBeFalse()
        ->and($acceptedCopy->accepted_at?->toDateTimeString())->toBe($accepted->accepted_at->toDateTimeString())
        ->and($open->fresh()->isPending())->toBeTrue();
});

it('restores an organization with inbox email on, without taking over its forwarding address', function () {
    $token = Str::lower(Str::random(40));
    $this->company->forceFill(['inbound_email_token' => $token, 'inbound_email_enabled' => true])->save();

    $restore = uniqueColumnsRestore($this->owner, uniqueColumnsBackup($this->owner));

    // The copy comes back with inbox email off; turning it on mints a new address.
    $copy = Company::query()->findOrFail($restore->company_id);

    expect($copy->inbound_email_token)->toBeNull()
        ->and($copy->inbound_email_enabled)->toBeFalse()
        ->and(Company::query()->where('inbound_email_token', $token)->sole()->id)->toBe($this->company->id)
        ->and($this->company->fresh()->inbound_email_enabled)->toBeTrue();
});

it('restores an organization that took a card payment through Stripe', function () {
    $receipt = uniqueColumnsStripeReceipt($this->company, 'pi_3TestPaymentIntent');

    $restore = uniqueColumnsRestore($this->owner, uniqueColumnsBackup($this->owner));

    // The copy is restored without its Stripe connection, so the PaymentIntent
    // id is dropped. The reference still names the payment.
    $copy = CustomerReceipt::withoutGlobalScopes()->where('company_id', $restore->company_id)->sole();

    expect($copy->stripe_payment_intent_id)->toBeNull()
        ->and($copy->reference)->toBe('pi_3TestPaymentIntent')
        ->and($receipt->fresh()->stripe_payment_intent_id)->toBe('pi_3TestPaymentIntent');
});

it('restores an organization with agentic MCP proposals, without honouring their confirm tokens', function () {
    $pending = uniqueColumnsProposal($this->owner, 'invoice', McpProposalStatus::Pending);
    $confirmed = uniqueColumnsProposal($this->owner, 'bill', McpProposalStatus::Confirmed);

    $restore = uniqueColumnsRestore($this->owner, uniqueColumnsBackup($this->owner));

    $copies = McpWriteProposal::withoutGlobalScopes()->where('company_id', $restore->company_id)->get()->keyBy('tool');

    // An agent holds the original's tokens, and they confirm nothing in the copy.
    // The pending proposal comes back expired; the confirmed one keeps its history.
    expect($copies['invoice']->idempotency_key)->not->toBe($pending->idempotency_key)
        ->and($copies['bill']->idempotency_key)->not->toBe($confirmed->idempotency_key)
        ->and($copies['invoice']->status)->toBe(McpProposalStatus::Expired)
        ->and($copies['bill']->status)->toBe(McpProposalStatus::Confirmed)
        ->and($pending->fresh()->status)->toBe(McpProposalStatus::Pending);
});

it('restores one backup twice next to the original without repeating a globally unique value', function () {
    // One of everything RowTransformer::GLOBALLY_UNIQUE_COLUMNS lists.
    $this->company->forceFill([
        'stripe_account_id' => 'acct_1UniqueColumns',
        'stripe_connected_at' => now(),
        'inbound_email_token' => Str::lower(Str::random(40)),
        'inbound_email_enabled' => true,
    ])->save();
    CompanyApiKey::mint($this->company, 'Zapier', $this->owner->id, ['sales:read']);
    CompanyInvitation::factory()->expiresIn(3)->create([
        'company_id' => $this->company->id,
        'invited_by' => $this->owner->id,
    ]);
    uniqueColumnsStripeReceipt($this->company, 'pi_3TwiceRestored');
    uniqueColumnsProposal($this->owner, 'journal_entry', McpProposalStatus::Pending);

    $restorePath = uniqueColumnsBackup($this->owner);
    $first = uniqueColumnsRestore($this->owner, $restorePath);
    $second = uniqueColumnsRestore($this->owner, $restorePath);

    $companyIds = [$this->company->id, $first->company_id, $second->company_id];

    foreach (RowTransformer::GLOBALLY_UNIQUE_COLUMNS as $table => $columns) {
        $ownerColumn = $table === 'companies' ? 'id' : 'company_id';

        foreach (array_keys($columns) as $column) {
            expect(DB::table($table)->where($ownerColumn, $this->company->id)->whereNotNull($column)->exists())
                ->toBeTrue("Give {$table}.{$column} a value in this test's fixture, or it proves nothing about that column.");

            $values = DB::table($table)->whereIn($ownerColumn, $companyIds)->whereNotNull($column)->pluck($column);

            expect($values->duplicates()->all())->toBe([], "{$table}.{$column} repeats across the original and its two copies.");
        }
    }
});
