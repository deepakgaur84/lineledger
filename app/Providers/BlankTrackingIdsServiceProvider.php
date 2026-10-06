<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Turns a blank Class / Location / Fund into NULL before any model is saved.
 *
 * Every Class, Location and Fund select has a "—" option whose value is an empty
 * string, and the forms pass whatever the select holds straight through to the
 * save action as `$line['class_id'] ?? null`. `??` only catches a *missing* value,
 * not '', and validation lets '' through too (`nullable|integer` skips an empty
 * string). So '' reached the insert, where MySQL in strict mode refuses it for an
 * integer column ("Incorrect integer value: '' for column 'class_id'") and the
 * whole save — draft or post — died with a 500.
 *
 * Twenty-odd actions and twenty-three models share that pattern, and a path that
 * writes one of these columns without going through a line-builder we have found
 * is just as exposed (recurring documents and pay-run earnings turned up that way).
 * Fixing each caller leaves every path nobody has found yet broken; fixing the one
 * thing they all end in — saving a model — covers them all, including future code.
 *
 * It is deliberately narrow:
 *  - only these three attribute names, which every migration declares as an integer
 *    foreignId, so a blank can never be a legitimate string here;
 *  - only the exact empty string — a real id, null and every other attribute
 *    (a blank memo stays a blank memo) are left alone;
 *  - it never blocks or fails a save.
 *
 * The listener MUST return null. Laravel fires `saving` in halt mode: the first
 * listener to return a non-null value ends the chain and the rest are skipped, so a
 * stray return value here would silently disable every listener registered after it.
 */
class BlankTrackingIdsServiceProvider extends ServiceProvider
{
    /** Integer foreign keys to the classifications, locations and funds tables. */
    private const COLUMNS = ['class_id', 'location_id', 'fund_id'];

    public function boot(): void
    {
        Event::listen('eloquent.saving: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model) {
                return;
            }

            $attributes = $model->getAttributes();

            foreach (self::COLUMNS as $column) {
                if (array_key_exists($column, $attributes) && $attributes[$column] === '') {
                    $model->setAttribute($column, null);
                }
            }
        });
    }
}
