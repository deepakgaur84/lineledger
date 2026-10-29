<?php

use App\Concerns\BelongsToCompany;
use Illuminate\Support\Facades\File;

/*
 * Guard rail: a string rule like 'exists:accounts,id' is checked by the
 * validator's presence verifier, a plain query that CompanyScope never sees, so
 * it accepts any organization's id. A client-supplied id for a company-scoped
 * table must use Rule::exists($table, 'id')->where('company_id', …) instead.
 */

it('scopes every exists rule on a company-scoped table to the organization', function () {
    $tenantTables = collect(File::allFiles(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class): bool => class_exists($class)
            && ! (new ReflectionClass($class))->isAbstract()
            && in_array(BelongsToCompany::class, class_uses_recursive($class), true))
        ->map(fn (string $class): string => (new $class)->getTable())
        ->unique()
        ->values()
        ->all();

    expect($tenantTables)->toContain('accounts');

    $offenders = [];

    foreach ([app_path(), resource_path('views')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                // 'exists:accounts,id', 'exists:accounts' or 'required|exists:accounts,id'.
                preg_match_all('/[\'"](?:[^\'"\n]*\|)?exists:([a-z0-9_]+)/i', $line, $matches);

                foreach ($matches[1] as $table) {
                    if (in_array($table, $tenantTables, true)) {
                        $offenders[] = $file->getRelativePathname().':'.($number + 1).' (exists:'.$table.')';
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([], 'Scope these rules to the organization with Rule::exists(...)->where(\'company_id\', ...): '.implode(', ', $offenders));
});
