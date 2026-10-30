<?php

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/*
 * Tripwire for cross-tenant reads through ids a client can set on a Volt
 * component (every ⚡*.blade.php under resources/views).
 *
 * Every public Livewire property is client-writable unless it is #[Locked].
 * CompanyScope only covers a client-held id on a tenant model, and only while
 * an organization is bound — which BindCurrentCompanyHook does on a Livewire
 * request only for a component holding `public Company $company`. Raw DB::
 * queries, withoutGlobalScope(s)() and models without BelongsToCompany (the
 * journal and document line tables, users, restores, …) get no scope at all.
 *
 * The scan reads each component's PHP class with comments dropped and splits
 * it into statements on `;`, `{` and `}` outside parentheses, so a query chain
 * keeps the closures passed into it. A "key" is a client-writable public
 * property (not #[Locked], typed as a scalar/array or untyped) named like an id
 * (`id`, `fooId`, `foo_id`, plurals) or a polymorphic type (`fooType`,
 * `foo_type`). A "company constraint" is a `company_id` column, a
 * `->company_id` check or whereBelongsTo() in the statement.
 *
 *  A. A withoutGlobalScope(s)() statement carries a company constraint, or the
 *     statement right after it does (findOrFail + abort_unless(…->company_id…)).
 *  B. In a component without `public Company $company`, no key appears in a
 *     statement that queries a model or DB:: directly. Resolve it through a
 *     relation of a model property or of the signed-in user instead, or lock it.
 *  C. In any component, no key appears in a statement that queries DB::, a
 *     model without BelongsToCompany or withoutGlobalScope(s)() unless that
 *     statement carries a company constraint.
 *
 * It is lexical: an id copied into a local first, or read in the Blade
 * template, is not followed. It catches the pattern; it does not prove safety.
 */

/**
 * Model basename => whether it carries the tenant scope.
 *
 * @return array<string, bool>
 */
function tenantKeyGuardModels(): array
{
    $models = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $models[class_basename($class)] = in_array(BelongsToCompany::class, class_uses_recursive($class), true);
        }
    }

    return $models;
}

/**
 * The component class's tokens up to its closing `?>`, without whitespace or
 * comments.
 *
 * @return list<PhpToken>
 */
function tenantKeyGuardTokens(string $source): array
{
    $tokens = [];

    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->is(T_CLOSE_TAG)) {
            break;
        }

        if (! $token->isIgnorable()) {
            $tokens[] = $token;
        }
    }

    return $tokens;
}

/**
 * Split into statements on `;`, `{` and `}` outside parentheses, brackets and
 * interpolated strings.
 *
 * @param  list<PhpToken>  $tokens
 * @return list<list<PhpToken>>
 */
function tenantKeyGuardStatements(array $tokens): array
{
    $statements = [];
    $current = [];
    $depth = 0;
    $inString = false;

    foreach ($tokens as $token) {
        $current[] = $token;

        if ($token->text === '"' || $token->is([T_START_HEREDOC, T_END_HEREDOC])) {
            $inString = ! $inString;

            continue;
        }

        if ($inString) {
            continue;
        }

        if (in_array($token->text, ['(', '['], true) || $token->is(T_ATTRIBUTE)) {
            $depth++;
        } elseif (in_array($token->text, [')', ']'], true)) {
            $depth--;
        } elseif ($depth === 0 && in_array($token->text, [';', '{', '}'], true)) {
            $statements[] = $current;
            $current = [];
        }
    }

    if ($current !== []) {
        $statements[] = $current;
    }

    return $statements;
}

/**
 * The component's client-writable key properties: name => true.
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, true>
 */
function tenantKeyGuardKeys(array $tokens): array
{
    $keys = [];
    $scalarTypes = ['int', 'string', 'array', 'float', 'bool', 'mixed', 'null'];

    foreach ($tokens as $i => $token) {
        if (! $token->is(T_PUBLIC) || ! isset($tokens[$i + 1]) || $tokens[$i + 1]->is([T_FUNCTION, T_CONST, T_STATIC])) {
            continue;
        }

        // The declared type, up to the property's variable.
        $type = [];
        $j = $i + 1;
        while (isset($tokens[$j]) && ! $tokens[$j]->is(T_VARIABLE)) {
            $type[] = strtolower(ltrim($tokens[$j]->text, '?'));
            $j++;
        }

        $name = isset($tokens[$j]) ? ltrim($tokens[$j]->text, '$') : '';
        $typeNames = array_filter(explode('|', implode('', $type)), fn (string $part): bool => $part !== '');

        if ($name === '' || array_diff($typeNames, $scalarTypes) !== []) {
            continue; // a model / object property: its key lives in the checksummed snapshot
        }

        if (! preg_match('/^(?:id|ids|type)$|(?:Id|Ids|_id|_ids|Type|_type)$/', $name)) {
            continue;
        }

        // Attribute groups written directly before `public`.
        $locked = false;
        $k = $i - 1;
        while (isset($tokens[$k]) && $tokens[$k]->text === ']') {
            $nesting = 0;
            for (; $k >= 0; $k--) {
                if ($tokens[$k]->text === ']') {
                    $nesting++;
                } elseif ($tokens[$k]->text === '[' || $tokens[$k]->is(T_ATTRIBUTE)) {
                    $nesting--;
                }

                if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                    && class_basename($tokens[$k]->text) === 'Locked') {
                    $locked = true;
                }

                if ($nesting === 0) {
                    break;
                }
            }
            $k--;
        }

        if (! $locked) {
            $keys[$name] = true;
        }
    }

    return $keys;
}

/**
 * What one statement touches.
 *
 * @param  list<PhpToken>  $statement
 * @param  array<string, bool>  $models
 * @return array{keys: list<string>, modelQuery: bool, unscopedQuery: bool, withoutScopes: bool, companyConstraint: bool}
 */
function tenantKeyGuardInspect(array $statement, array $models): array
{
    $facts = ['keys' => [], 'modelQuery' => false, 'unscopedQuery' => false, 'withoutScopes' => false, 'companyConstraint' => false];
    $objectOperators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
    // DB:: entry points that run SQL (DB::transaction() and friends do not).
    $rawDbMethods = ['table', 'select', 'selectOne', 'scalar', 'query', 'statement', 'update', 'insert', 'delete', 'raw', 'cursor', 'unprepared', 'affectingStatement'];

    foreach ($statement as $i => $token) {
        $next = $statement[$i + 1] ?? null;
        $after = $statement[$i + 2] ?? null;

        // $this->key, not $this->method()
        if ($token->is(T_VARIABLE) && $token->text === '$this' && $next?->is($objectOperators) && $after?->is(T_STRING)
            && ($statement[$i + 3] ?? null)?->text !== '(') {
            $facts['keys'][] = $after->text;
        }

        // Model::anything() / DB::table() …
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $next?->is(T_DOUBLE_COLON)
            && $after?->is(T_STRING) && $after->text !== 'class') {
            $class = class_basename($token->text);

            if (array_key_exists($class, $models)) {
                $facts['modelQuery'] = true;
                $facts['unscopedQuery'] = $facts['unscopedQuery'] || ! $models[$class];
            } elseif ($class === 'DB' && in_array($after->text, $rawDbMethods, true)) {
                $facts['modelQuery'] = true;
                $facts['unscopedQuery'] = true;
            }
        }

        if ($token->is(T_STRING) && in_array($token->text, ['withoutGlobalScope', 'withoutGlobalScopes', 'newQueryWithoutScopes'], true)) {
            $facts['withoutScopes'] = true;
        }

        if (($token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/(^|\.)company_id$/', trim($token->text, '\'"')))
            || ($token->is(T_STRING) && $token->text === 'company_id' && ($statement[$i - 1] ?? null)?->is($objectOperators))
            || ($token->is(T_STRING) && $token->text === 'whereBelongsTo')) {
            $facts['companyConstraint'] = true;
        }
    }

    return $facts;
}

/**
 * The rule violations in one component's source: rule, key (rules B and C)
 * and the offending statement.
 *
 * @param  array<string, bool>  $models
 * @return list<array{rule: string, key: ?string, statement: string}>
 */
function tenantKeyGuardCheck(string $source, array $models): array
{
    $tokens = tenantKeyGuardTokens($source);
    $keys = tenantKeyGuardKeys($tokens);
    $statements = tenantKeyGuardStatements($tokens);
    $violations = [];

    $holdsCompany = false;
    foreach ($tokens as $i => $token) {
        $nullable = ($tokens[$i + 1] ?? null)?->text === '?';
        $type = $tokens[$i + ($nullable ? 2 : 1)] ?? null;
        $variable = $tokens[$i + ($nullable ? 3 : 2)] ?? null;

        if ($token->is(T_PUBLIC) && $type !== null && class_basename($type->text) === 'Company' && $variable?->text === '$company') {
            $holdsCompany = true;

            break;
        }
    }

    foreach ($statements as $index => $statement) {
        $facts = tenantKeyGuardInspect($statement, $models);
        $snippet = mb_strimwidth(implode(' ', array_map(fn (PhpToken $t): string => $t->text, $statement)), 0, 220, '…');

        if ($facts['withoutScopes'] && ! $facts['companyConstraint']
            && ! (isset($statements[$index + 1]) && tenantKeyGuardInspect($statements[$index + 1], $models)['companyConstraint'])) {
            $violations[] = ['rule' => 'A', 'key' => null, 'statement' => $snippet];
        }

        foreach (array_unique(array_intersect($facts['keys'], array_keys($keys))) as $key) {
            if (! $holdsCompany && $facts['modelQuery']) {
                $violations[] = ['rule' => 'B', 'key' => $key, 'statement' => $snippet];
            } elseif (($facts['unscopedQuery'] || $facts['withoutScopes']) && ! $facts['companyConstraint']) {
                $violations[] = ['rule' => 'C', 'key' => $key, 'statement' => $snippet];
            }
        }
    }

    return $violations;
}

it('keeps client-writable ids in Livewire components from reaching unscoped queries', function () {
    $models = tenantKeyGuardModels();
    $explain = [
        'A' => 'withoutGlobalScopes() with no company constraint',
        'B' => 'client-writable $%s queries data in a component that binds no organization on its Livewire requests',
        'C' => 'client-writable $%s reaches a query CompanyScope does not cover',
    ];
    $violations = [];
    $scanned = 0;

    foreach (Finder::create()->files()->in(resource_path('views'))->name('⚡*.blade.php') as $file) {
        $scanned++;
        $path = str_replace(base_path().'/', '', $file->getPathname());

        foreach (tenantKeyGuardCheck($file->getContents(), $models) as $violation) {
            $violations[] = "{$path} [{$violation['rule']}] ".sprintf($explain[$violation['rule']], $violation['key']).": {$violation['statement']}";
        }
    }

    expect($scanned)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});

it('reports the original cross-tenant read patterns and passes their fixes', function (string $class, array $expected) {
    $violations = tenantKeyGuardCheck("<?php\nnew class extends Component {\n{$class}\n}; ?>\n<div></div>", tenantKeyGuardModels());

    expect(array_map(fn (array $violation): string => trim($violation['rule'].' '.$violation['key']), $violations))
        ->toBe($expected);
})->with([
    'bank register, before' => [
        'public Company $company; public ?int $account_id = null;
         protected function linesQuery() { return JournalLine::query()->where("account_id", $this->account_id)->where("is_posted", true); }',
        ['C account_id'],
    ],
    'bank register, after' => [
        'public Company $company; public ?int $account_id = null;
         public function account(): ?Account { return $this->account_id === null ? null : $this->companyAccount($this->account_id); }
         protected function companyAccount(int $id): ?Account { return Account::query()->whereBelongsTo($this->company)->find($id); }
         protected function linesQuery() { return JournalLine::query()->where("account_id", $this->account?->id)->where("is_posted", true); }',
        [],
    ],
    'audit trail, before' => [
        'public string $auditableType; public int $auditableId;
         public function entries() { return AccountingAuditLog::query()->withoutGlobalScopes()->with("actor")->where(function ($q) { $q->where("auditable_type", $this->auditableType)->where("auditable_id", $this->auditableId); })->get(); }',
        ['A', 'B auditableType', 'B auditableId'],
    ],
    'audit trail, after' => [
        'public Company $company; #[Locked] public string $auditableType; #[Locked] public int $auditableId;
         public function entries() { return AccountingAuditLog::query()->whereBelongsTo($this->company)->where(function ($q) { $q->where("auditable_type", $this->auditableType)->where("auditable_id", $this->auditableId); })->get(); }',
        [],
    ],
    'merge summary, before' => [
        'public Company $company; public ?int $mergingId = null;
         public function mergeSummary() { return DB::table("journal_lines")->where("account_id", $this->mergingId)->count(); }',
        ['C mergingId'],
    ],
    'restore page, before' => [
        'public ?int $restoreId = null;
         public function restore() { return CompanyRestore::find($this->restoreId); }',
        ['B restoreId'],
    ],
    'lookup and ownership check in the next statement' => [
        'public Company $company;
         private function owned(int $accountId) { $account = Account::withoutGlobalScopes()->findOrFail($accountId); abort_unless($account->company_id === $this->company->id, 403); return $account; }',
        [],
    ],
    'relation of a model property, no organization bound' => [
        'public ReportGroup $reportGroup; public ?int $editingLineId = null;
         public function save() { $this->reportGroup->lines()->findOrFail($this->editingLineId)->update([]); }',
        [],
    ],
]);
