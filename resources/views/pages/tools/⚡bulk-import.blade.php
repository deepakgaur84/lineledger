<?php

use App\Models\Company;
use App\Services\BulkImport\BulkImportRegistry;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\BulkImport\HasImportNotes;
use App\Services\BulkImport\ImportDates;
use App\Services\BulkImport\SlashDateWarnings;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Bulk Import')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public string $entityType = 'vendors';

    public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $upload = null;

    /**
     * For a grouped importer, 'row' is a range like "2–4" and 'raw' is the
     * group's array of rows rather than a single row — see
     * validateGroupedUpload().
     *
     * @var array<int, array{row: int|string, status: string, data: array<string, string>, errors: array<int, string>, raw: mixed}>|null
     */
    public ?array $previewRows = null;

    public int $validCount = 0;

    public int $invalidCount = 0;

    /** @var array<int, array{row: int, name: string, error: string}> */
    public array $commitFailures = [];

    public ?int $committedCount = null;

    /**
     * Slash dates in the upload that PHP will read differently from how they
     * were probably written — see SlashDateWarnings. Advisory only.
     *
     * @var list<array{row: int, column: string, value: string, reads_as: ?string, meant: string, suggest: string}>
     */
    public array $dateWarnings = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /** @return array<int, \App\Services\BulkImport\ImporterDefinition|\App\Services\BulkImport\GroupedImporterDefinition> */
    public function importers(): array
    {
        return BulkImportRegistry::all();
    }

    public function currentImporter(): \App\Services\BulkImport\ImporterDefinition|GroupedImporterDefinition
    {
        return BulkImportRegistry::find($this->entityType) ?? BulkImportRegistry::all()[0];
    }

    /**
     * Importer-specific rules worth reading before uploading, if it has any.
     *
     * @return array<int, string>
     */
    public function importNotes(): array
    {
        $importer = $this->currentImporter();

        return $importer instanceof HasImportNotes ? $importer->importNotes() : [];
    }

    public function hasDateColumns(): bool
    {
        return ImportDates::columnsIn(array_keys($this->currentImporter()->csvColumns())) !== [];
    }

    public function updatedEntityType(): void
    {
        $this->resetImportState();
    }

    private function resetImportState(): void
    {
        $this->upload = null;
        $this->previewRows = null;
        $this->validCount = 0;
        $this->invalidCount = 0;
        $this->commitFailures = [];
        $this->committedCount = null;
        $this->dateWarnings = [];
    }

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $importer = $this->currentImporter();
        $columns = array_keys($importer->csvColumns());

        return response()->streamDownload(function () use ($columns, $importer): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            if ($importer instanceof GroupedImporterDefinition) {
                foreach ($importer->sampleRows() as $sample) {
                    fputcsv($out, array_map(fn (string $column): string => $sample[$column] ?? '', $columns));
                }
            }

            fclose($out);
        }, $importer->key().'-import-template.csv');
    }

    /**
     * Parse the uploaded CSV and validate every row, populating the preview
     * table. Nothing is created yet — see commitImport().
     */
    public function validateUpload(): void
    {
        $this->validate(['upload' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $importer = $this->currentImporter();
        $rows = $this->parseCsv($this->upload->getRealPath(), array_keys($importer->csvColumns()));

        // Before the flat/grouped split, so every importer gets it. Slash dates that are
        // valid both ways pass validation and import as a different day, which nothing
        // else on this screen would reveal.
        $this->dateWarnings = SlashDateWarnings::find($rows, ImportDates::columnsIn(array_keys($importer->csvColumns())));

        if ($importer instanceof GroupedImporterDefinition) {
            $this->validateGroupedUpload($importer, $rows);

            return;
        }

        $preview = [];
        $validCount = 0;
        $invalidCount = 0;
        $seenInThisUpload = [];

        foreach ($rows as $i => $row) {
            $errors = $importer->validate($row, $this->company);
            $isValid = $errors === [];
            $isValid ? $validCount++ : $invalidCount++;

            $data = $isValid ? $importer->summarize($row, $this->company) : [];

            if ($isValid && isset($data['Name']) && $data['Name'] !== '') {
                $key = mb_strtolower(trim($data['Name']));

                // Distinct from summarize()'s own against-the-database check —
                // this catches the same name appearing twice within THIS
                // upload (e.g. the same vendor accidentally listed on two
                // rows), which no database lookup would ever see since
                // neither row exists yet at validation time.
                if (isset($seenInThisUpload[$key]) && ! isset($data['⚠ Possible duplicate'])) {
                    $data['⚠ Possible duplicate'] = __('Also appears on row :row of this file', ['row' => $seenInThisUpload[$key]]);
                }

                $seenInThisUpload[$key] ??= $i + 2;
            }

            $preview[] = [
                'row' => $i + 2, // +1 for zero-index, +1 for the header row
                'status' => $isValid ? 'valid' : 'invalid',
                'data' => $data,
                'errors' => $errors,
                'raw' => $row,
            ];
        }

        $this->previewRows = $preview;
        $this->validCount = $validCount;
        $this->invalidCount = $invalidCount;
        $this->commitFailures = [];
        $this->committedCount = null;
    }

    /**
     * The grouped-importer counterpart of the loop above — rows sharing a
     * groupKey() become one preview entry instead of one entry per row. A
     * row with no group key (blank bill_no, say) is never silently merged
     * into a neighbouring group; it's its own singleton group, which
     * validateGroup() then rejects for that specific, attributable reason.
     *
     * @param  array<int, array<string, ?string>>  $rows
     */
    private function validateGroupedUpload(GroupedImporterDefinition $importer, array $rows): void
    {
        /** @var array<int, array{key: ?string, rowNumbers: array<int, int>, rows: array<int, array<string, ?string>>}> $groups */
        $groups = [];
        $groupIndexByKey = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // +1 for zero-index, +1 for the header row
            $key = $importer->groupKey($row);

            if ($key === null) {
                $groups[] = ['key' => null, 'rowNumbers' => [$rowNumber], 'rows' => [$row]];

                continue;
            }

            if (! isset($groupIndexByKey[$key])) {
                $groupIndexByKey[$key] = count($groups);
                $groups[] = ['key' => $key, 'rowNumbers' => [], 'rows' => []];
            }

            $idx = $groupIndexByKey[$key];
            $groups[$idx]['rowNumbers'][] = $rowNumber;
            $groups[$idx]['rows'][] = $row;
        }

        $preview = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($groups as $group) {
            if ($group['key'] === null) {
                $errors = [__('This row has no value to group it into a document — every row needs one (e.g. a bill number).')];
            } else {
                $errors = $importer->validateGroup($group['rows'], $this->company);
            }

            $isValid = $errors === [];
            $isValid ? $validCount++ : $invalidCount++;

            $preview[] = [
                'row' => $this->formatRowRange($group['rowNumbers']),
                'status' => $isValid ? 'valid' : 'invalid',
                'data' => $isValid ? $importer->summarizeGroup($group['rows'], $this->company) : [],
                'errors' => $errors,
                'raw' => $group['rows'],
            ];
        }

        $this->previewRows = $preview;
        $this->validCount = $validCount;
        $this->invalidCount = $invalidCount;
        $this->commitFailures = [];
        $this->committedCount = null;
    }

    /** @param  array<int, int>  $rowNumbers */
    private function formatRowRange(array $rowNumbers): string
    {
        return count($rowNumbers) === 1
            ? (string) $rowNumbers[0]
            : min($rowNumbers).'–'.max($rowNumbers);
    }

    /**
     * Create every row (or, for a grouped importer, every group) that
     * passed validation. Invalid entries are skipped entirely — fix the
     * CSV and re-upload rather than partially patch a single row here.
     */
    public function commitImport(): void
    {
        if ($this->previewRows === null || $this->validCount === 0) {
            return;
        }

        $importer = $this->currentImporter();
        $isGrouped = $importer instanceof GroupedImporterDefinition;
        $created = 0;
        $failures = [];

        foreach ($this->previewRows as $entry) {
            if ($entry['status'] !== 'valid') {
                continue;
            }

            try {
                if ($isGrouped) {
                    $importer->commitGroup($entry['raw'], $this->company);
                } else {
                    $importer->commit($entry['raw'], $this->company);
                }
                $created++;
            } catch (\Throwable $e) {
                $failures[] = [
                    'row' => $entry['row'],
                    // The first summary field is always the record's own
                    // identifying label by convention (Name, Bill, ...) —
                    // generic on purpose, so this doesn't need updating
                    // every time a new importer uses a different label.
                    'name' => $entry['data'] !== [] ? reset($entry['data']) : __('Row :row', ['row' => $entry['row']]),
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->committedCount = $created;
        $this->commitFailures = $failures;
        $this->previewRows = null;
        $this->upload = null;
    }

    public function startOver(): void
    {
        $this->resetImportState();
    }

    /**
     * @param  array<int, string>  $expectedColumns
     * @return array<int, array<string, ?string>>
     */
    private function parseCsv(string $path, array $expectedColumns): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if ($header === false) {
                return [];
            }

            // Strip a UTF-8 BOM from the first header cell, if present —
            // common when a CSV is saved from Excel.
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
            $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);

            $rows = [];

            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                if ($cells === [null]) {
                    continue; // blank line
                }

                $byHeader = array_combine(
                    array_slice($header, 0, count($cells)),
                    array_map(fn ($c) => $c === null ? null : trim((string) $c), array_slice($cells, 0, count($header))),
                );

                $row = [];
                foreach ($expectedColumns as $column) {
                    $row[$column] = $byHeader[$column] ?? null;
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-6 p-6">
    <div>
        <flux:heading size="xl">{{ __('Bulk Import') }}</flux:heading>
        <flux:subheading>
            {{ __('Import records from a CSV file. Every row goes through the same validation and business rules as creating one by hand.') }}
        </flux:subheading>
    </div>

    <flux:card class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="sm">{{ __('Importing bank transactions?') }}</flux:heading>
            <flux:subheading>
                {{ __('Cheques, deposits, and transfers are better handled from your actual bank statement — it matches against your books, pays open bills, splits out tax, and pairs transfers automatically.') }}
            </flux:subheading>
        </div>
        <flux:button :href="route('banking.import', ['company' => $company->slug])" wire:navigate variant="ghost" icon:trailing="arrow-up-right">
            {{ __('Import a bank statement') }}
        </flux:button>
    </flux:card>

    <flux:card class="space-y-4">
        <flux:select wire:model.live="entityType" :label="__('What are you importing?')">
            @foreach ($this->importers() as $importer)
                <flux:select.option value="{{ $importer->key() }}">{{ $importer->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @php
            $importNotes = $this->importNotes();
        @endphp

        @if ($this->hasDateColumns() || $importNotes !== [])
            <flux:callout icon="information-circle" variant="secondary" data-test="import-notes">
                <flux:callout.heading>{{ __('Before you upload') }}</flux:callout.heading>
                <flux:callout.text>
                    @if ($this->hasDateColumns())
                        <div class="space-y-1" data-test="import-dates-note">
                            <p>
                                <strong>{{ __('Dates') }}</strong> —
                                {{ __('write them like :example (day, month as three letters, year) or :iso.', ['example' => ImportDates::EXAMPLE, 'iso' => ImportDates::EXAMPLE_ISO]) }}
                                {{ __('Don\'t use slashes: 09/04/2026 is read as month/day/year, so 27/09/2026 is rejected and 05/09/2026 quietly becomes 9 May.') }}
                            </p>
                            <p>{{ __('Excel often rewrites dates into slashes when it saves a CSV — format the date columns as dd-mmm-yy first (Format Cells → Custom). Two-digit years 00–69 mean 2000–2069; 70–99 mean 1970–1999.') }}</p>
                        </div>
                    @endif

                    @if ($importNotes !== [])
                        <ul class="mt-2 list-inside list-disc space-y-1" data-test="import-notes-list">
                            @foreach ($importNotes as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    @endif
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="rounded-lg border border-border bg-muted/30 p-4 text-sm">
            <p class="mb-2 font-medium">{{ __('Expected columns') }}</p>
            <ul class="space-y-1">
                @foreach ($this->currentImporter()->csvColumns() as $column => $description)
                    <li><code class="font-mono text-xs">{{ $column }}</code> — {{ $description }}</li>
                @endforeach
            </ul>
            <flux:button wire:click="downloadTemplate" variant="ghost" size="sm" icon="arrow-down-tray" class="mt-3">
                {{ __('Download a blank template') }}
            </flux:button>
        </div>
    </flux:card>

    @if ($previewRows === null && $committedCount === null)
        <flux:card class="space-y-4">
            <flux:input type="file" wire:model="upload" :label="__('CSV file')" accept=".csv,text/csv" />
            @error('upload') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

            <p wire:loading wire:target="upload" class="text-muted-foreground text-sm">
                {{ __('Uploading...') }}
            </p>

            {{--
                Deliberately no "attr" loading modifier on this button. The :disabled binding
                below already keeps it disabled for the whole of the first upload ($upload is only
                set once the upload has finished), so that modifier added nothing — and in
                Livewire 4.4 it actively breaks the button: it captures the attribute's value when
                loading starts (here, already disabled) and RE-APPLIES it when the upload
                finishes, which happens after the server's morph has just removed it. The button
                then stays greyed out with a file selected and no error. (Livewire 4.3 simply
                removed the attribute afterwards, which is why this used to work.)
            --}}
            <flux:button wire:click="validateUpload" variant="primary" :disabled="! $upload">
                {{ __('Validate') }}
            </flux:button>
        </flux:card>
    @endif

    @if ($previewRows !== null)
        <flux:card class="space-y-4">
            @if ($dateWarnings !== [])
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="import-date-warnings">
                    <flux:callout.heading>{{ __('Check these dates') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Slash dates are read as month/day/year, which may not be what you meant. Nothing has been imported yet.') }}
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach (array_slice($dateWarnings, 0, 10) as $warning)
                                <li>
                                    @if ($warning['reads_as'] !== null)
                                        {{ __('Row :row, :column: ":value" is read as :reads_as. If you meant :meant, write :suggest.', ['row' => $warning['row'], 'column' => $warning['column'], 'value' => $warning['value'], 'reads_as' => $warning['reads_as'], 'meant' => $warning['meant'], 'suggest' => $warning['suggest']]) }}
                                    @else
                                        {{ __('Row :row, :column: ":value" can\'t be read as a date. If you meant :meant, write :suggest.', ['row' => $warning['row'], 'column' => $warning['column'], 'value' => $warning['value'], 'meant' => $warning['meant'], 'suggest' => $warning['suggest']]) }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if (count($dateWarnings) > 10)
                            <p class="mt-2">{{ __('…and :count more.', ['count' => count($dateWarnings) - 10]) }}</p>
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex items-center gap-4">
                <flux:badge color="green">{{ __(':count valid', ['count' => $validCount]) }}</flux:badge>
                @if ($invalidCount > 0)
                    <flux:badge color="red">{{ __(':count invalid', ['count' => $invalidCount]) }}</flux:badge>
                @endif
            </div>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Row') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Details') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($previewRows as $entry)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $entry['row'] }}</td>
                                <td class="px-4 py-2">
                                    @if ($entry['status'] === 'valid')
                                        <flux:badge color="green" size="sm">{{ __('Valid') }}</flux:badge>
                                    @else
                                        <flux:badge color="red" size="sm">{{ __('Invalid') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if ($entry['status'] === 'valid')
                                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                                            @foreach ($entry['data'] as $label => $value)
                                                <span><span class="text-muted-foreground">{{ $label }}:</span> {{ $value }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <ul class="list-inside list-disc text-red-600">
                                            @foreach ($entry['errors'] as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex gap-3">
                <flux:button wire:click="commitImport" variant="primary" :disabled="$validCount === 0">
                    {{ __('Import :count valid row(s)', ['count' => $validCount]) }}
                </flux:button>
                <flux:button wire:click="startOver" variant="ghost">{{ __('Start over') }}</flux:button>
            </div>

            @if ($invalidCount > 0)
                <flux:text class="text-muted-foreground text-sm">
                    {{ __('Invalid rows will be skipped. Fix them in your CSV and re-upload to include them.') }}
                </flux:text>
            @endif
        </flux:card>
    @endif

    @if ($committedCount !== null)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Import complete') }}</flux:heading>
            <flux:badge color="green">{{ __(':count created', ['count' => $committedCount]) }}</flux:badge>
            @if (count($commitFailures) > 0)
                <flux:badge color="red">{{ __(':count failed', ['count' => count($commitFailures)]) }}</flux:badge>
                <ul class="list-inside list-disc text-red-600 text-sm">
                    @foreach ($commitFailures as $failure)
                        <li>{{ __('Row :row (:name): :error', ['row' => $failure['row'], 'name' => $failure['name'], 'error' => $failure['error']]) }}</li>
                    @endforeach
                </ul>
            @endif
            <flux:button wire:click="startOver" variant="primary">{{ __('Import more') }}</flux:button>
        </flux:card>
    @endif
</div>
