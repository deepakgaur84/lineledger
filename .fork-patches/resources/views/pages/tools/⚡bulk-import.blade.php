<?php

use App\Models\Company;
use App\Services\BulkImport\BulkImportRegistry;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Bulk Import')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public string $entityType = 'vendors';

    public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $upload = null;

    /** @var array<int, array{row: int, status: string, data: array<string, string>, errors: array<int, string>}>|null */
    public ?array $previewRows = null;

    public int $validCount = 0;

    public int $invalidCount = 0;

    /** @var array<int, array{row: int, name: string, error: string}> */
    public array $commitFailures = [];

    public ?int $committedCount = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /** @return array<int, \App\Services\BulkImport\ImporterDefinition> */
    public function importers(): array
    {
        return BulkImportRegistry::all();
    }

    public function currentImporter(): \App\Services\BulkImport\ImporterDefinition
    {
        return BulkImportRegistry::find($this->entityType) ?? BulkImportRegistry::all()[0];
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
    }

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $importer = $this->currentImporter();
        $columns = array_keys($importer->csvColumns());

        return response()->streamDownload(function () use ($columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            fclose($out);
        }, $importer->key().'-template.csv');
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
     * Create every row that passed validation. Invalid rows are skipped
     * entirely — fix the CSV and re-upload rather than partially patch
     * a single row here.
     */
    public function commitImport(): void
    {
        if ($this->previewRows === null || $this->validCount === 0) {
            return;
        }

        $importer = $this->currentImporter();
        $created = 0;
        $failures = [];

        foreach ($this->previewRows as $entry) {
            if ($entry['status'] !== 'valid') {
                continue;
            }

            try {
                $importer->commit($entry['raw'], $this->company);
                $created++;
            } catch (\Throwable $e) {
                $failures[] = [
                    'row' => $entry['row'],
                    'name' => $entry['data']['Name'] ?? ('Row '.$entry['row']),
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
            {{ __('Import vendors and customers from a CSV file. Every row goes through the same validation and business rules as creating one by hand.') }}
        </flux:subheading>
    </div>

    <flux:card class="space-y-4">
        <flux:select wire:model.live="entityType" :label="__('What are you importing?')">
            @foreach ($this->importers() as $importer)
                <flux:select.option value="{{ $importer->key() }}">{{ $importer->label() }}</flux:select.option>
            @endforeach
        </flux:select>

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

            <flux:button wire:click="validateUpload" variant="primary" :disabled="! $upload" wire:loading.attr="disabled" wire:target="upload">
                {{ __('Validate') }}
            </flux:button>
        </flux:card>
    @endif

    @if ($previewRows !== null)
        <flux:card class="space-y-4">
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
