<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\BulkImport\ImportDates;
use App\Services\BulkImport\Importers\FixedAssetImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    // Livewire stages uploads on the `local` disk (config/livewire.php) — fake it
    // so these tests never write into the real storage directory.
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->assetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A fixed-assets CSV built from the real importer's own column list, so this
 * can never drift out of step with the template the page offers for download.
 *
 * @param  list<array<string, string>>  $rows
 */
function bulkImportFixedAssetCsv(array $rows): string
{
    $columns = array_keys(app(FixedAssetImporter::class)->csvColumns());

    $out = fopen('php://temp', 'w+');
    fputcsv($out, $columns, escape: '');

    foreach ($rows as $row) {
        fputcsv($out, array_map(fn (string $column): string => $row[$column] ?? '', $columns), escape: '');
    }

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);

    return (string) $csv;
}

it('holds a selected CSV on the server and validates it for the fixed assets importer', function () {
    $csv = bulkImportFixedAssetCsv([[
        'name' => 'Laptop',
        'asset_account_code' => $this->assetAccount->code,
        'acquired_date' => '2026-01-15',
        'cost' => '1000.00',
    ]]);

    $page = Livewire::test('pages::tools.bulk-import', ['company' => $this->company])
        ->set('entityType', 'fixed-assets')
        ->set('upload', UploadedFile::fake()->createWithContent('assets.csv', $csv))
        ->assertHasNoErrors();

    // This is the state the Validate button's :disabled binding reads. Once
    // the upload has landed it must be set — the button is greyed only while
    // it is null, so a client-side quirk was the only thing left to blame when
    // it stayed greyed out with a file selected.
    expect($page->instance()->upload)->not->toBeNull();

    $page->call('validateUpload')
        ->assertHasNoErrors()
        ->assertSet('validCount', 1)
        ->assertSet('invalidCount', 0);

    expect($page->instance()->previewRows[0]['status'])->toBe('valid');
});

it('drops the staged upload when the importer is switched, so a file is never validated as the wrong kind', function () {
    $csv = bulkImportFixedAssetCsv([[
        'name' => 'Laptop',
        'asset_account_code' => $this->assetAccount->code,
        'acquired_date' => '2026-01-15',
        'cost' => '1000.00',
    ]]);

    $page = Livewire::test('pages::tools.bulk-import', ['company' => $this->company])
        ->set('entityType', 'fixed-assets')
        ->set('upload', UploadedFile::fake()->createWithContent('assets.csv', $csv));

    expect($page->instance()->upload)->not->toBeNull();

    $page->set('entityType', 'vendors')
        ->assertSet('upload', null)
        ->assertSet('previewRows', null);
});

it('never pairs a loading "attr" directive with a server-rendered disabled on the same line', function () {
    // Livewire 4.4's wire:loading with the .attr modifier captures the attribute's
    // value when loading starts and re-applies it when loading ends. On an element
    // that is ALREADY disabled at that moment (a button whose :disabled binding is
    // true until an upload lands, say) that means it is re-disabled after the
    // server's morph has just enabled it — greyed out for good, with no error. This
    // cannot be caught server-side, so scan the views for the pairing instead; it is
    // what left the bulk importer's Validate button stuck after choosing a CSV.
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (preg_split('/\R/', $file->getContents()) as $index => $line) {
            if (! str_contains($line, 'wire:loading.attr')) {
                continue;
            }

            if (preg_match('/(:disabled=|\sdisabled[\s>\/=])/', $line) === 1) {
                $offenders[] = $file->getRelativePathname().':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'These lines pair a loading attr directive with a server-rendered disabled: '.implode(', ', $offenders));
});

/**
 * Three fixed assets, only the acquired date varying, uploaded to the page and
 * validated — the page state the date warnings are read from.
 *
 * @param  list<string>  $dates
 */
function bulkImportValidateAssetsWithDates(array $dates)
{
    $rows = [];

    foreach ($dates as $index => $date) {
        $rows[] = [
            'name' => 'Asset '.($index + 1),
            'asset_account_code' => test()->assetAccount->code,
            'acquired_date' => $date,
            'cost' => '1000.00',
        ];
    }

    return Livewire::test('pages::tools.bulk-import', ['company' => test()->company])
        ->set('entityType', 'fixed-assets')
        ->set('upload', UploadedFile::fake()->createWithContent('assets.csv', bulkImportFixedAssetCsv($rows)))
        ->call('validateUpload')
        ->assertHasNoErrors();
}

it('tells you what to know before uploading, with the date example, for the fixed assets importer', function () {
    Livewire::test('pages::tools.bulk-import', ['company' => $this->company])
        ->set('entityType', 'fixed-assets')
        ->assertSeeHtml('data-test="import-notes"')
        ->assertSeeHtml('data-test="import-dates-note"')
        ->assertSeeHtml('data-test="import-notes-list"')
        ->assertSee('Before you upload')
        ->assertSee(ImportDates::EXAMPLE)
        ->assertSee('Settings → Lists → Asset categories');
});

it('shows the date note for any importer with date columns, and nothing at all for one without', function () {
    $page = Livewire::test('pages::tools.bulk-import', ['company' => $this->company])
        ->set('entityType', 'bills')
        ->assertSeeHtml('data-test="import-dates-note"')
        // Bills has dates but no importer-specific notes of its own.
        ->assertDontSeeHtml('data-test="import-notes-list"');

    $page->set('entityType', 'vendors')
        ->assertDontSeeHtml('data-test="import-notes"')
        ->assertDontSeeHtml('data-test="import-dates-note"');
});

it('warns in the preview about slash dates, whether they would be rejected or silently misread', function () {
    $page = bulkImportValidateAssetsWithDates(['05/09/2026', '27/09/2026', '09-Apr-26']);

    $warnings = $page->instance()->dateWarnings;

    // Row 1 of the sheet is the header, so these are rows 2 and 3; the safely written row 4 is skipped.
    expect($warnings)->toHaveCount(2)
        ->and([$warnings[0]['row'], $warnings[0]['column'], $warnings[0]['reads_as'], $warnings[0]['suggest']])
        ->toBe([2, 'acquired_date', '9 May 2026', '05-Sep-26'])
        ->and([$warnings[1]['row'], $warnings[1]['reads_as'], $warnings[1]['suggest']])
        ->toBe([3, null, '27-Sep-26']);

    // The point of the warning: 05/09/2026 is a perfectly VALID date, so it passes validation
    // and would import as 9 May with nothing else on screen to say so. Only 27/09/2026 is rejected.
    $page->assertSet('validCount', 2)
        ->assertSet('invalidCount', 1)
        ->assertSeeHtml('data-test="import-date-warnings"')
        ->assertSee('Check these dates')
        ->assertSee('is read as 9 May 2026')
        ->assertSee('write 05-Sep-26')
        ->assertSee('write 27-Sep-26');
});

it('has no date warning for dates written the safe way', function () {
    bulkImportValidateAssetsWithDates(['09-Apr-26', '2026-04-09', '9 Apr 2026'])
        ->assertSet('dateWarnings', [])
        ->assertSet('validCount', 3)
        ->assertDontSeeHtml('data-test="import-date-warnings"');
});

it('forgets date warnings when you switch importer or start over', function () {
    $page = bulkImportValidateAssetsWithDates(['05/09/2026']);

    expect($page->instance()->dateWarnings)->toHaveCount(1);

    $page->call('startOver')->assertSet('dateWarnings', []);

    $page = bulkImportValidateAssetsWithDates(['05/09/2026']);
    $page->set('entityType', 'vendors')->assertSet('dateWarnings', []);
});
