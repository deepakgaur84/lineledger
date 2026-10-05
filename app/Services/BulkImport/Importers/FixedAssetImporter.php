<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Assets\SaveAsset;
use App\Enums\AccountSubtype;
use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Services\Assets\DepreciationSchedule;
use App\Services\BulkImport\HasImportNotes;
use App\Services\BulkImport\ImportDates;
use App\Services\BulkImport\ImporterDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Fixed assets — one row, one asset, created through the real SaveAsset action
 * so an imported asset gets exactly the validation and depreciation handling one
 * entered on the form does.
 *
 * Register-only, like entering an asset by hand: this never posts to the
 * general ledger. The asset's cost is expected to be in the ledger already (from
 * the bill, cheque or journal entry that bought it). To load a register together
 * with its accumulated depreciation as opening balances, use the Opening
 * balances importer instead.
 *
 * Blank fields fall back to the row's asset category the same way the asset form
 * fills them: the three accounts, the useful life, and the depreciation method
 * and rate. A rate is only accepted for the declining-balance method — a rate on
 * a straight-line or 100% row is far more likely a mislabelled row than intent, so
 * it is refused rather than dropped. Declining balance with no rate anywhere uses
 * the suggested 20%, and the preview says so.
 *
 * Turning on auto-depreciation for an asset with an old in-service date makes the
 * generator draft every month since (up to 60 per run) that is not locked. The
 * preview shows exactly how many, so it never comes as a surprise; if the ledger
 * already carries that depreciation, lock the period first or leave
 * auto_depreciate off.
 */
class FixedAssetImporter implements HasImportNotes, ImporterDefinition
{
    public function key(): string
    {
        return 'fixed-assets';
    }

    public function label(): string
    {
        return 'Fixed Assets';
    }

    public function csvColumns(): array
    {
        return [
            'asset_no' => 'Optional. Left blank, the asset is numbered automatically. Given, it must be unique.',
            'name' => 'Required.',
            'category_name' => "Optional. An existing asset category's name (case-insensitive). Any blank field below falls back to that category's default, exactly as on the asset form.",
            'asset_account_code' => 'Required unless the category supplies one. The code of an existing fixed-asset account, e.g. 1500 — not the account name.',
            'accum_depreciation_account_code' => 'Required for auto_depreciate, unless the category supplies one. The code of an existing account.',
            'depreciation_expense_account_code' => 'Required for auto_depreciate, unless the category supplies one. The code of an existing account.',
            'acquired_date' => ImportDates::required(),
            'in_service_date' => 'Optional. Same date format as acquired_date. Depreciation starts in this month. Left blank with auto_depreciate on, it defaults to acquired_date.',
            'cost' => 'Required. Plain decimal, e.g. 4500.00 — not cents. Must be greater than 0.',
            'salvage_value' => 'Optional. Plain decimal; defaults to 0 and cannot exceed the cost.',
            'depreciation_method' => 'Optional. straight_line, declining_balance (also WDV or reducing balance) or immediate (also 100%). Left blank, the category\'s method is used, else straight_line.',
            'useful_life_months' => 'Needed for straight_line depreciation. Optional for declining_balance, where the final year of the life takes whatever balance is left. Ignored for immediate.',
            'depreciation_rate' => 'declining_balance or straight_line only — refused for immediate. For declining_balance: 1 to 100 (a trailing % is fine), up to 3 decimals; left blank it uses the category\'s rate, else 20. For straight_line: optional — jurisdictions like NZ track straight-line depreciation by rate, so giving one here computes and stores useful_life_months from it (100 divided by years = rate) unless useful_life_months is also given directly, in which case both are stored exactly as given and neither is recalculated from the other.',
            'materiality_limit' => 'declining_balance with no useful life only: depreciation ends once the balance left would be at or below this amount. Plain decimal. Left blank it is 5% of cost.',
            'auto_depreciate' => "Optional, 'yes'/'no'; defaults to no. Yes drafts monthly depreciation journal entries — see the preview for how many months that would create.",
            'serial_number' => 'Optional.',
            'location' => 'Optional.',
            'description' => 'Optional.',
        ];
    }

    /**
     * Each sentence restates a rule resolve() enforces — read from it, not from
     * memory — so the screen can never promise something the validator refuses.
     * The per-column help above is accurate too, but it is one line among
     * eighteen; these are the four things that actually get rows rejected.
     */
    public function importNotes(): array
    {
        return [
            __('Category: category_name must match an existing, active asset category (capital letters don\'t matter). Create it first under Settings → Lists → Asset categories — an account name such as "Office Equipment" only works if a category with that name exists.'),
            __('Accounts: give account codes (e.g. 1500), not names. A blank code falls back to the category\'s default. The asset account is always needed, from the row or the category; the two depreciation accounts only when auto_depreciate is yes.'),
            __('Method: straight_line spreads the cost over useful_life_months — give a depreciation_rate instead and the life is worked out from it. declining_balance uses a rate; blank takes the category\'s, else 20. 100% (immediate) takes neither: leave the rate blank (a rate on that row is rejected); any useful life is ignored.'),
            __('auto_depreciate = yes drafts monthly depreciation entries, so it needs both depreciation accounts as above. The in-service date defaults to the acquired date if left blank.'),
        ];
    }

    public function validate(array $row, Company $company): array
    {
        return $this->resolve($row, $company)['errors'];
    }

    public function summarize(array $row, Company $company): array
    {
        $resolved = $this->resolve($row, $company);

        if ($resolved['errors'] !== []) {
            return ['Name' => (string) ($row['name'] ?? '')];
        }

        /** @var Asset $probe */
        $probe = $resolved['probe'];
        $data = $resolved['data'];
        $method = $resolved['method'];

        $summary = [
            'Name' => (string) $data['name'],
            'Asset #' => filled($data['asset_no']) ? (string) $data['asset_no'] : __('(auto-numbered)'),
            'Cost' => number_format($data['cost_cents'] / 100, 2),
            'Depreciation' => $this->describeDepreciation($method, $probe, $resolved['rate_defaulted']),
            'Auto-depreciation' => $data['auto_depreciate'] ? __('On') : __('Off (register only)'),
        ];

        if ($resolved['category'] !== null) {
            $summary['Category'] = $resolved['category']->name;
        }

        if ($data['auto_depreciate']) {
            $months = $this->draftMonths($probe, $company);
            $count = count($months);

            $summary['Draft entries to generate'] = $count === 0
                ? __('None yet')
                : trans_choice(':n month (:from – :to)|:n months (:from – :to)', $count, [
                    'n' => $count,
                    'from' => $months[0]->format('M Y'),
                    'to' => $months[$count - 1]->format('M Y'),
                ]);

            if ($count > 1) {
                $summary['⚠ Back-fills drafts'] = __('This drafts :n months of depreciation. If the ledger already carries depreciation for them, lock the period first or turn auto_depreciate off. Generation is capped at 60 months per run.', ['n' => $count]);
            }
        }

        if (($duplicateOf = $this->findLikelyDuplicate($data, $company)) !== null) {
            $summary['⚠ Possible duplicate'] = __('Matches existing asset :no (:name)', [
                'no' => $duplicateOf->asset_no,
                'name' => $duplicateOf->name,
            ]);
        }

        return $summary;
    }

    public function commit(array $row, Company $company): void
    {
        $resolved = $this->resolve($row, $company);

        if ($resolved['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $resolved['errors']));
        }

        app(SaveAsset::class)->handle($resolved['data']);
    }

    /**
     * Everything validate(), summarize() and commit() need, worked out once so
     * the three can never disagree: the row's errors, and — only when there are
     * none — the SaveAsset payload, the effective method, and an unsaved Asset
     * used to preview the schedule.
     *
     * @param  array<string, ?string>  $row
     * @return array{errors: list<string>, data: array<string, mixed>, method: DepreciationMethod, rate_defaulted: bool, category: ?AssetCategory, probe: ?Asset}
     */
    private function resolve(array $row, Company $company): array
    {
        $errors = [];
        $get = fn (string $column): string => trim((string) ($row[$column] ?? ''));

        // ─── Plain format checks ────────────────────────────────────────────
        $validator = Validator::make([
            'name' => $get('name'),
            'asset_no' => $get('asset_no'),
            'acquired_date' => $get('acquired_date'),
            'in_service_date' => $get('in_service_date'),
            'cost' => $get('cost'),
            'salvage_value' => $get('salvage_value'),
            'useful_life_months' => $get('useful_life_months'),
            'materiality_limit' => $get('materiality_limit'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'asset_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('assets', 'asset_no')->where('company_id', $company->id),
            ],
            'acquired_date' => ['required', 'date'],
            'in_service_date' => ['nullable', 'date'],
            'cost' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'materiality_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $autoText = mb_strtolower($get('auto_depreciate'));
        if (! in_array($autoText, ['', 'yes', 'no', 'true', 'false', '1', '0'], true)) {
            $errors[] = __('auto_depreciate must be yes or no.');
        }
        $auto = in_array($autoText, ['yes', 'true', '1'], true);

        // ─── Category ───────────────────────────────────────────────────────
        $category = null;
        if ($get('category_name') !== '') {
            $category = AssetCategory::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($get('category_name'))])
                ->first();

            if ($category === null) {
                $errors[] = __("Asset category ':name' not found — create it under Settings → Lists → Asset categories first.", ['name' => $get('category_name')]);
            }
        }

        // ─── Method ─────────────────────────────────────────────────────────
        $explicitMethod = null;
        if ($get('depreciation_method') !== '') {
            $explicitMethod = DepreciationMethod::fromLoose($get('depreciation_method'));

            if ($explicitMethod === null) {
                $errors[] = __("Depreciation method ':value' is not recognised — use straight_line, declining_balance (WDV) or immediate (100%).", ['value' => $get('depreciation_method')]);
            }
        }
        $method = $explicitMethod ?? $category?->defaultDepreciationMethod() ?? DepreciationMethod::StraightLine;

        // ─── Rate ───────────────────────────────────────────────────────────
        $rate = null;
        $rateDefaulted = false;
        $rateText = trim(str_replace('%', '', $get('depreciation_rate')));

        if ($rateText !== '') {
            $inWindow = preg_match('/^(\d+(\.\d{1,3})?|\.\d{1,3})$/', $rateText) === 1
                && (float) $rateText >= DepreciationMethod::MIN_RATE
                && (float) $rateText <= DepreciationMethod::MAX_RATE;

            if (! $inWindow) {
                $errors[] = __('depreciation_rate must be a number from :min to :max (percent a year), with at most 3 decimals.', ['min' => DepreciationMethod::MIN_RATE, 'max' => DepreciationMethod::MAX_RATE]);
            } elseif (! $method->canHaveRate()) {
                $errors[] = __('depreciation_rate does not apply to the immediate method — set a different depreciation_method, or leave the rate blank.');
            } else {
                $rate = $rateText;
            }
        } elseif ($method->usesRate()) {
            $categoryRate = $category?->defaultDepreciationMethod() === DepreciationMethod::DecliningBalance
                ? $category->default_depreciation_rate
                : null;

            $rate = $categoryRate !== null
                ? rtrim(rtrim((string) $categoryRate, '0'), '.')
                : (string) DepreciationMethod::DEFAULT_RATE;

            // Only the suggested 20% counts as "defaulted"; a rate the category supplies is a
            // deliberate choice, and the preview should not call it a guess.
            $rateDefaulted = $categoryRate === null;
        }

        // ─── Useful life (like the form, a blank one comes from the category) ─
        $life = $get('useful_life_months') !== '' ? (int) $get('useful_life_months') : $category?->default_useful_life_months;

        // A straight-line rate with no useful_life_months given directly (on the row
        // itself — a category's own default life is not enough to skip this) computes
        // the life from the rate, the same arithmetic the asset form's own Rate mode
        // uses. Given explicitly, useful_life_months always wins over this and the
        // rate is stored exactly as given, without recalculating either from the other.
        if ($method === DepreciationMethod::StraightLine && $rate !== null && $get('useful_life_months') === '') {
            $life = (int) max(1, min(1200, round(1200 / (float) $rate)));
        }

        $effectiveLife = $method->usesUsefulLife() ? $life : null;

        // ─── Accounts (a code that is given must be right; a blank one falls back) ─
        $assetAccountId = $this->accountId($get('asset_account_code'), $company, true, 'asset_account_code', $errors);
        $accumAccountId = $this->accountId($get('accum_depreciation_account_code'), $company, false, 'accum_depreciation_account_code', $errors);
        $expenseAccountId = $this->accountId($get('depreciation_expense_account_code'), $company, false, 'depreciation_expense_account_code', $errors);

        if ($get('asset_account_code') === '') {
            $assetAccountId = $category?->default_asset_account_id;
        }
        if ($get('accum_depreciation_account_code') === '') {
            $accumAccountId = $category?->default_accumulated_depreciation_account_id;
        }
        if ($get('depreciation_expense_account_code') === '') {
            $expenseAccountId = $category?->default_depreciation_expense_account_id;
        }

        if ($assetAccountId === null && $get('asset_account_code') === '') {
            $errors[] = __('asset_account_code is required — or choose a category that has a default asset account.');
        }

        // ─── Cross-field checks ─────────────────────────────────────────────
        $costCents = is_numeric($get('cost')) ? (int) round((float) $get('cost') * 100) : 0;
        $salvageCents = is_numeric($get('salvage_value')) ? (int) round((float) $get('salvage_value') * 100) : 0;

        if ($salvageCents > $costCents && $costCents > 0) {
            $errors[] = __('salvage_value cannot exceed the cost.');
        }

        if ($auto) {
            if ($accumAccountId === null || $expenseAccountId === null) {
                $errors[] = __('auto_depreciate needs both depreciation accounts (accum_depreciation_account_code and depreciation_expense_account_code) — or a category that supplies them.');
            }

            if ($method === DepreciationMethod::StraightLine && ($effectiveLife ?? 0) < 1) {
                $errors[] = __('Straight-line auto_depreciate needs useful_life_months (or a category default).');
            }

            if ($costCents > 0 && $costCents - $salvageCents <= 0 && $salvageCents <= $costCents) {
                $errors[] = __('auto_depreciate has nothing to depreciate: salvage_value equals the cost.');
            }
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'data' => [], 'method' => $method, 'rate_defaulted' => $rateDefaulted, 'category' => $category, 'probe' => null];
        }

        // ─── The payload ────────────────────────────────────────────────────
        $acquired = CarbonImmutable::parse($get('acquired_date'))->toDateString();
        $inService = $get('in_service_date') !== ''
            ? CarbonImmutable::parse($get('in_service_date'))->toDateString()
            : ($auto ? $acquired : null);

        $materialityCents = is_numeric($get('materiality_limit')) ? (int) round((float) $get('materiality_limit') * 100) : null;

        $data = [
            'asset_no' => $get('asset_no') !== '' ? $get('asset_no') : null,
            'name' => $get('name'),
            'description' => $get('description') !== '' ? $get('description') : null,
            'asset_category_id' => $category?->id,
            'asset_account_id' => $assetAccountId,
            'accumulated_depreciation_account_id' => $accumAccountId,
            'depreciation_expense_account_id' => $expenseAccountId,
            'serial_number' => $get('serial_number') !== '' ? $get('serial_number') : null,
            'location' => $get('location') !== '' ? $get('location') : null,
            'acquired_date' => $acquired,
            'in_service_date' => $inService,
            'cost_cents' => $costCents,
            'salvage_value_cents' => $salvageCents,
            'useful_life_months' => $life,
            'depreciation_method' => $method->value,
            'depreciation_rate' => $rate,
            'materiality_limit_cents' => $materialityCents,
            'auto_depreciate' => $auto,
        ];

        $probe = new Asset([
            'cost_cents' => $costCents,
            'salvage_value_cents' => $salvageCents,
            'in_service_date' => $inService,
            'useful_life_months' => $effectiveLife,
            'depreciation_method' => $method->value,
            'depreciation_rate' => $rate,
            'materiality_limit_cents' => $method->usesMateriality() ? $materialityCents : null,
        ]);

        return ['errors' => [], 'data' => $data, 'method' => $method, 'rate_defaulted' => $rateDefaulted, 'category' => $category, 'probe' => $probe];
    }

    private function accountId(string $code, Company $company, bool $fixedAssetOnly, string $column, array &$errors): ?int
    {
        if ($code === '') {
            return null;
        }

        $query = Account::query()->where('company_id', $company->id)->where('code', $code);

        if ($fixedAssetOnly) {
            $query->where('subtype', AccountSubtype::FixedAsset->value);
        }

        $id = $query->value('id');

        if ($id === null) {
            $errors[] = $fixedAssetOnly
                ? __("Account ':code' in :column is not a fixed-asset account, or does not exist.", ['code' => $code, 'column' => $column])
                : __("Account ':code' in :column does not exist.", ['code' => $code, 'column' => $column]);
        }

        return $id !== null ? (int) $id : null;
    }

    /**
     * The months the generator would draft for this asset right now — the same
     * rules it applies: only months that have fully ended, and never one at or
     * before the company's lock date.
     *
     * @return list<CarbonImmutable>
     */
    private function draftMonths(Asset $probe, Company $company): array
    {
        $today = $company->currentDateTime()->startOfDay();
        $months = [];

        foreach (DepreciationSchedule::for($probe) as $row) {
            $period = $row['period'];

            if ($period->endOfMonth()->startOfDay()->greaterThanOrEqualTo($today)) {
                break;
            }

            if ($company->isLockedFor($period->endOfMonth()->startOfDay())) {
                continue;
            }

            if ((int) $row['amount_cents'] === 0) {
                continue;
            }

            $months[] = $period;
        }

        return $months;
    }

    private function describeDepreciation(DepreciationMethod $method, Asset $probe, bool $rateDefaulted): string
    {
        return match ($method) {
            DepreciationMethod::StraightLine => $probe->useful_life_months
                ? __('Straight-line, :n months', ['n' => $probe->useful_life_months])
                : __('Straight-line (no useful life yet)'),
            DepreciationMethod::DecliningBalance => __('Written-down value, :rate% a year', ['rate' => rtrim(rtrim((string) $probe->depreciation_rate, '0'), '.')])
                .($rateDefaulted ? ' '.__('(rate defaulted)') : '')
                .', '.($probe->useful_life_months
                    ? __('final year of the :n-month life takes the balance', ['n' => $probe->useful_life_months])
                    : __('ends once the balance left is within :amount', ['amount' => number_format($probe->materialityLimitCents() / 100, 2)])),
            DepreciationMethod::Immediate => __('100% on purchase — written off in the in-service month'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findLikelyDuplicate(array $data, Company $company): ?Asset
    {
        return Asset::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $data['name'])])
            ->where('acquired_date', $data['acquired_date'])
            ->where('cost_cents', $data['cost_cents'])
            ->first();
    }
}
