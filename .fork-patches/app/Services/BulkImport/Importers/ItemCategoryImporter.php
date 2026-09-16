<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\MasterData\SaveItemCategory;
use App\Models\Company;
use App\Models\ItemCategory;
use App\Services\BulkImport\ImporterDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Item Categories — a flat entity like Vendors/Customers, with one optional
 * self-referential reference to resolve: a parent category, identified by
 * name (categories have no code field to key off, unlike accounts).
 *
 * Import your top-level categories first, in their own upload, before a
 * second upload for any categories that need one of those as a parent —
 * within a single CSV, a child row naming a parent that's defined later in
 * the SAME file won't resolve, since rows are validated independently and
 * not order-aware.
 */
class ItemCategoryImporter implements ImporterDefinition
{
    public function key(): string
    {
        return 'item-categories';
    }

    public function label(): string
    {
        return 'Item Categories';
    }

    public function csvColumns(): array
    {
        return [
            'name' => 'Required.',
            'parent_name' => "Optional. Must match an existing category's name exactly. See this importer's own note on ordering if you're importing a parent and its children together.",
            'is_active' => "Optional. 'true'/'false', defaults to true.",
        ];
    }

    public function validate(array $row, Company $company): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'parent_name' => [
                'nullable', 'string',
                Rule::exists('item_categories', 'name')->where('company_id', $company->id),
            ],
            'is_active' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! in_array(mb_strtolower(trim((string) $value)), ['true', 'false', '1', '0'], true)) {
                        $fail(__('The :attribute must be true, false, 1, or 0.', ['attribute' => $attribute]));
                    }
                },
            ],
        ];

        $validator = Validator::make($row, $rules);

        return $validator->fails() ? $validator->errors()->all() : [];
    }

    public function summarize(array $row, Company $company): array
    {
        $summary = [
            'Name' => (string) ($row['name'] ?? ''),
            'Parent' => (string) ($row['parent_name'] ?? '—'),
        ];

        if (($duplicateOf = $this->findLikelyDuplicate($row, $company)) !== null) {
            $summary['⚠ Possible duplicate'] = __('Matches existing category #:id (:name)', [
                'id' => $duplicateOf->id,
                'name' => $duplicateOf->name,
            ]);
        }

        return $summary;
    }

    public function commit(array $row, Company $company): void
    {
        $parentId = filled($row['parent_name'] ?? null)
            ? ItemCategory::query()->where('company_id', $company->id)->where('name', $row['parent_name'])->value('id')
            : null;

        app(SaveItemCategory::class)->handle([
            'name' => $row['name'],
            'parent_id' => $parentId,
            'is_active' => $this->normalizedBoolean($row['is_active'] ?? null) ?? true,
        ]);
    }

    private function findLikelyDuplicate(array $row, Company $company): ?ItemCategory
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return ItemCategory::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function normalizedBoolean(?string $value): ?bool
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return in_array(mb_strtolower(trim($value)), ['true', '1'], true);
    }
}
