<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

// BlankTrackingIdsServiceProvider: a Class / Location / Fund select left on "—" submits ''
// and MySQL in strict mode refuses '' in an integer column. This suite runs on MySQL, so
// the database tests below fail for real without the guard.

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->account = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves a blank Class, Location and Fund as NULL on a model, with no action involved', function () {
    // Straight onto the model — the guard is what makes this safe, not any caller's tidying.
    $entry = JournalEntry::create(['entry_no' => 'JE-000001', 'entry_date' => '2026-10-02', 'memo' => 'Reclass']);

    $line = $entry->lines()->create([
        'account_id' => $this->account->id, 'debit_cents' => 5000, 'credit_cents' => 0,
        'class_id' => '', 'location_id' => '', 'fund_id' => '',
    ])->fresh();

    expect([$line->class_id, $line->location_id, $line->fund_id])->toBe([null, null, null]);
});

it('does the same when an existing line is updated to blank', function () {
    $department = Classification::create(['name' => 'DG', 'is_active' => true]);
    $entry = JournalEntry::create(['entry_no' => 'JE-000001', 'entry_date' => '2026-10-02', 'memo' => 'Reclass']);

    $line = $entry->lines()->create([
        'account_id' => $this->account->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'class_id' => $department->id,
    ]);

    expect($line->fresh()->class_id)->toBe($department->id);

    $line->update(['class_id' => '']);

    expect($line->fresh()->class_id)->toBeNull();
});

it('guards every model that carries a Class, Location or Fund, including ones added later', function () {
    // Found by scanning app/Models rather than from a list, so a new model with one of these
    // columns is covered the day it is written — or this test names the gap.
    $columns = ['class_id', 'location_id', 'fund_id'];

    $classes = collect(File::files(app_path('Models')))
        ->map(fn ($file): string => 'App\\Models\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class)
            && ! (new ReflectionClass($class))->isAbstract()
            && preg_match("/'(class_id|location_id|fund_id)'/", File::get((new ReflectionClass($class))->getFileName())) === 1)
        ->values();

    $unguarded = [];

    foreach ($classes as $class) {
        $model = new $class;

        foreach ($columns as $column) {
            $model->setAttribute($column, '');
        }

        // Exactly what Model::save() fires: the event name, with the model as payload.
        Event::until('eloquent.saving: '.$class, $model);

        foreach ($columns as $column) {
            if ($model->getAttributes()[$column] !== null) {
                $unguarded[] = $class.'.'.$column;
            }
        }
    }

    // The count stops this passing by finding no models at all (there are 23 today).
    expect($unguarded)->toBe([])
        ->and($classes->count())->toBeGreaterThanOrEqual(20);
});

it('leaves real ids and every other attribute alone', function () {
    $model = new JournalLine;
    $model->setAttribute('class_id', 7);
    $model->setAttribute('location_id', '9');
    $model->setAttribute('memo', '');
    $model->setAttribute('contact_id', '');

    Event::until('eloquent.saving: '.JournalLine::class, $model);

    // Only the exact empty string in these three columns is touched.
    expect($model->getAttributes()['class_id'])->toBe(7)
        ->and($model->getAttributes()['location_id'])->toBe('9')
        ->and($model->getAttributes()['memo'])->toBe('')
        ->and($model->getAttributes()['contact_id'])->toBe('');
});

it('never stops the other saving listeners from running', function () {
    // Laravel fires `saving` in halt mode: the first listener to return anything but null ends
    // the chain. The guard returns nothing, so a listener registered after it still runs.
    $ran = false;

    Event::listen('eloquent.saving: *', function () use (&$ran): void {
        $ran = true;
    });

    JournalEntry::create(['entry_no' => 'JE-000001', 'entry_date' => '2026-10-02', 'memo' => 'Reclass']);

    expect($ran)->toBeTrue();
});
