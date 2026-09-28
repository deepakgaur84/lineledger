<?php

namespace App\Services\Migration\Importers;

use App\Enums\AssetStatus;
use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\JournalEntry;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports fixed asset register entries. Each row creates one Asset record
 * and contributes to a single journal entry that:
 *
 *   DR   Asset Cost account                cost_cents
 *   CR   Accumulated Depreciation         accumulated_depreciation_to_date_cents
 *   CR   Opening Balance Equity           (cost - accum_dep) net
 *
 * Asset categories are upserted by name.
 *
 * Each row may also carry a depreciation_method (straight_line, declining_balance/WDV or
 * immediate/100% — blank means straight_line) and, for declining balance, an annual
 * depreciation_rate from 1 to 100 (blank means 20). A rate on any other method is refused.
 * The method and rate become the defaults of a category this import creates.
 */
class FixedAssetsImporter implements Importer
{
    public function __construct(
        protected CsvParser $parser,
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
    ) {}

    public function templateHeaders(): array
    {
        return [
            'asset_no', 'name', 'category_name',
            'asset_account_code', 'accum_depreciation_account_code', 'depreciation_expense_account_code',
            'acquired_date', 'in_service_date',
            'cost', 'salvage_value', 'useful_life_months',
            'depreciation_method', 'depreciation_rate',
            'accumulated_depreciation_to_date',
            'serial_number', 'location', 'description',
        ];
    }

    public function templateExampleRows(): array
    {
        return [[
            'asset_no' => 'FA-001',
            'name' => 'Delivery Truck',
            'category_name' => 'Vehicles',
            'asset_account_code' => '1500',
            'accum_depreciation_account_code' => '1510',
            'depreciation_expense_account_code' => '6900',
            'acquired_date' => '2024-01-15',
            'in_service_date' => '2024-01-15',
            'cost' => '45000.00',
            'salvage_value' => '5000.00',
            'useful_life_months' => '60',
            'depreciation_method' => 'straight_line',
            'depreciation_rate' => '',
            'accumulated_depreciation_to_date' => '18000.00',
            'serial_number' => 'VIN1234567890',
            'location' => 'Main yard',
            'description' => '2024 Ford F-150',
        ]];
    }

    public function preview(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, true);
    }

    public function commit(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, false);
    }

    protected function run(string $csvPath, ImportContext $ctx, bool $dryRun): ImportResult
    {
        $rows = $this->parser->parse($csvPath, ['asset_no', 'name', 'asset_account_code', 'acquired_date', 'cost'], $this->templateHeaders());
        $errors = [];
        $preview = [];
        $createdIds = [];

        $obe = app(OpeningBalanceAccountResolver::class)->resolve((int) $ctx->company->id);

        if (! $obe) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: [],
                errors: [['row' => 0, 'message' => "Missing 'Opening Balance Equity' account."]],
            );
        }

        $accountByCode = Account::withoutGlobalScopes()
            ->where('company_id', $ctx->company->id)
            ->pluck('id', 'code');

        $accepted = [];
        $totalCostCents = 0;
        $totalAccumDepCents = 0;

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $assetNo = $row['asset_no'];
            $name = $row['name'];

            if (! $assetNo || ! $name || ! $row['asset_account_code'] || ! $row['acquired_date']) {
                $errors[] = ['row' => $rowNum, 'message' => 'asset_no, name, asset_account_code and acquired_date are required.'];

                continue;
            }

            $costCents = CsvParser::parseCents($row['cost']) ?? 0;
            $salvageCents = CsvParser::parseCents($row['salvage_value']) ?? 0;
            $accumDepCents = CsvParser::parseCents($row['accumulated_depreciation_to_date']) ?? 0;

            if ($costCents <= 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'cost must be greater than zero.'];

                continue;
            }

            $assetAccountId = $accountByCode[$row['asset_account_code']] ?? null;
            if (! $assetAccountId) {
                $errors[] = ['row' => $rowNum, 'message' => "Asset account code '{$row['asset_account_code']}' not found."];

                continue;
            }

            $accumAccountId = $row['accum_depreciation_account_code']
                ? ($accountByCode[$row['accum_depreciation_account_code']] ?? null)
                : null;

            if ($accumDepCents > 0 && ! $accumAccountId) {
                $errors[] = ['row' => $rowNum, 'message' => 'accumulated_depreciation > 0 but accum_depreciation_account_code is missing or unknown.'];

                continue;
            }

            $depExpenseId = $row['depreciation_expense_account_code']
                ? ($accountByCode[$row['depreciation_expense_account_code']] ?? null)
                : null;

            $methodText = trim((string) ($row['depreciation_method'] ?? ''));
            $method = DepreciationMethod::StraightLine;

            if ($methodText !== '') {
                $parsedMethod = DepreciationMethod::fromLoose($methodText);

                if ($parsedMethod === null) {
                    $errors[] = ['row' => $rowNum, 'message' => "depreciation_method '{$methodText}' is not recognised — use straight_line, declining_balance (WDV) or immediate (100%)."];

                    continue;
                }

                $method = $parsedMethod;
            }

            $rateText = trim(str_replace('%', '', (string) ($row['depreciation_rate'] ?? '')));
            $rate = null;

            if ($rateText !== '') {
                $inWindow = preg_match('/^(\d+(\.\d{1,3})?|\.\d{1,3})$/', $rateText) === 1
                    && (float) $rateText >= DepreciationMethod::MIN_RATE
                    && (float) $rateText <= DepreciationMethod::MAX_RATE;

                if (! $inWindow) {
                    $errors[] = ['row' => $rowNum, 'message' => 'depreciation_rate must be a number from '.DepreciationMethod::MIN_RATE.' to '.DepreciationMethod::MAX_RATE.' (percent a year), with at most 3 decimals.'];

                    continue;
                }

                if (! $method->usesRate()) {
                    $errors[] = ['row' => $rowNum, 'message' => 'depreciation_rate only applies to the declining_balance method — set depreciation_method to WDV, or leave the rate blank.'];

                    continue;
                }

                $rate = $rateText;
            } elseif ($method->usesRate()) {
                $rate = (string) DepreciationMethod::DEFAULT_RATE;
            }

            $accepted[] = [
                'row' => $row,
                'cost' => $costCents,
                'salvage' => $salvageCents,
                'accum' => $accumDepCents,
                'asset_account_id' => (int) $assetAccountId,
                'accum_account_id' => $accumAccountId ? (int) $accumAccountId : null,
                'dep_expense_id' => $depExpenseId ? (int) $depExpenseId : null,
                'method' => $method,
                'rate' => $rate,
            ];

            $preview[] = [
                'row' => $rowNum,
                'asset_no' => $assetNo,
                'name' => $name,
                'cost' => CsvParser::centsLabel($costCents),
                'accumulated_depreciation' => CsvParser::centsLabel($accumDepCents),
                'net_book_value' => CsvParser::centsLabel($costCents - $accumDepCents),
            ];

            $totalCostCents += $costCents;
            $totalAccumDepCents += $accumDepCents;
        }

        if ($dryRun || $errors !== [] || $accepted === []) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: $preview,
                errors: $errors,
                createdIds: $createdIds,
                summary: [
                    'rows' => count($rows),
                    'accepted' => count($accepted),
                    'total_cost_cents' => $totalCostCents,
                    'total_accumulated_depreciation_cents' => $totalAccumDepCents,
                ],
            );
        }

        try {
            DB::transaction(function () use ($accepted, $ctx, $obe, &$createdIds): void {
                $entry = JournalEntry::withoutGlobalScopes()->create([
                    'company_id' => $ctx->company->id,
                    'entry_no' => $this->entryNumbers->next($ctx->company),
                    'entry_date' => $ctx->conversionDate->toDateString(),
                    'memo' => 'Opening fixed asset register — carried over from QuickBooks',
                ]);

                $order = 0;
                $netBookCents = 0;
                $accumByAccount = [];

                foreach ($accepted as $a) {
                    $row = $a['row'];
                    $costCents = $a['cost'];
                    $accumCents = $a['accum'];

                    $category = $this->resolveCategory($ctx->company->id, $row['category_name'], $a['asset_account_id'], $a['accum_account_id'], $a['dep_expense_id'], $row['useful_life_months'], $a['method'], $a['rate']);

                    $asset = Asset::withoutGlobalScopes()->create([
                        'company_id' => $ctx->company->id,
                        'asset_no' => $row['asset_no'],
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'asset_category_id' => $category?->id,
                        'asset_account_id' => $a['asset_account_id'],
                        'accumulated_depreciation_account_id' => $a['accum_account_id'],
                        'depreciation_expense_account_id' => $a['dep_expense_id'],
                        'serial_number' => $row['serial_number'],
                        'location' => $row['location'],
                        'acquired_date' => CarbonImmutable::parse($row['acquired_date']),
                        'in_service_date' => $row['in_service_date'] ? CarbonImmutable::parse($row['in_service_date']) : null,
                        'cost_cents' => $costCents,
                        'salvage_value_cents' => $a['salvage'],
                        'useful_life_months' => $a['method']->usesUsefulLife() && $row['useful_life_months'] ? (int) $row['useful_life_months'] : null,
                        'depreciation_method' => $a['method']->value,
                        'depreciation_rate' => $a['rate'],
                        'status' => AssetStatus::InService,
                        'is_active' => true,
                    ]);
                    $createdIds[] = $asset->id;

                    $entry->lines()->create([
                        'account_id' => $a['asset_account_id'],
                        'debit_cents' => $costCents,
                        'credit_cents' => 0,
                        'memo' => "{$row['asset_no']} — {$row['name']} cost",
                        'line_order' => $order++,
                    ]);

                    if ($accumCents > 0 && $a['accum_account_id']) {
                        $key = $a['accum_account_id'];
                        $accumByAccount[$key] = ($accumByAccount[$key] ?? 0) + $accumCents;
                    }

                    $netBookCents += ($costCents - $accumCents);
                }

                foreach ($accumByAccount as $accountId => $cents) {
                    $entry->lines()->create([
                        'account_id' => (int) $accountId,
                        'debit_cents' => 0,
                        'credit_cents' => $cents,
                        'memo' => 'Accumulated depreciation — opening balance',
                        'line_order' => $order++,
                    ]);
                }

                if ($netBookCents !== 0) {
                    $entry->lines()->create([
                        'account_id' => $obe->id,
                        'debit_cents' => $netBookCents > 0 ? 0 : abs($netBookCents),
                        'credit_cents' => $netBookCents > 0 ? $netBookCents : 0,
                        'memo' => 'Opening balance equity — fixed assets',
                        'line_order' => $order++,
                    ]);
                }

                $entry->refresh();
                $this->journalPoster->post($entry);
            });
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
        }

        return new ImportResult(
            isDryRun: $dryRun,
            previewRows: $preview,
            errors: $errors,
            createdIds: $createdIds,
            summary: [
                'rows' => count($rows),
                'accepted' => count($accepted),
                'total_cost_cents' => $totalCostCents,
                'total_accumulated_depreciation_cents' => $totalAccumDepCents,
            ],
        );
    }

    protected function resolveCategory(int $companyId, ?string $name, int $assetAccountId, ?int $accumAccountId, ?int $depExpenseId, ?string $usefulLifeMonths, DepreciationMethod $method, ?string $rate): ?AssetCategory
    {
        if (! $name) {
            return null;
        }

        $existing = AssetCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->first();

        if ($existing) {
            return $existing;
        }

        return AssetCategory::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => $name,
            'default_asset_account_id' => $assetAccountId,
            'default_accumulated_depreciation_account_id' => $accumAccountId,
            'default_depreciation_expense_account_id' => $depExpenseId,
            'default_useful_life_months' => $usefulLifeMonths ? (int) $usefulLifeMonths : null,
            'default_depreciation_method' => $method->value,
            'default_depreciation_rate' => $method->usesRate() ? $rate : null,
            'is_active' => true,
        ]);
    }
}
