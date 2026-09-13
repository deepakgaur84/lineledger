<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use App\Services\EditLocks\EditLockManager;
use App\Support\EditLocks\EditLockables;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API writes to an existing record (update, delete, post/void/… actions) while
 * a member is editing that record in the web app are refused with 423 — see
 * the RecordEditLockedException renderer in bootstrap/app.php.
 *
 * Runs after route-model binding (it is not in the middleware priority list,
 * so it keeps its place after SubstituteBindings) and looks at every bound
 * parameter that is an {@see EditLockables} model, whatever the parameter is
 * called. Afterwards the lock's version is replaced regardless of the outcome,
 * so a web page that loaded the record before this write can't save over it.
 * An API key has no user, so any live lock refuses it.
 */
class RejectEditLockedRecords
{
    public function __construct(private readonly EditLockManager $locks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $records = array_values(array_filter(
            $request->route()?->parameters() ?? [],
            fn (mixed $parameter): bool => $parameter instanceof Model
                && EditLockables::supports($parameter)
                && ! $this->isWrongContactRole($request, $parameter),
        ));

        if ($records === [] || ! $this->locks->enabled()) {
            return $next($request);
        }

        foreach ($records as $record) {
            $this->locks->assertWritable($record, null);
        }

        try {
            return $next($request);
        } finally {
            foreach ($records as $record) {
                $this->locks->touch($record);
            }
        }
    }

    /**
     * customers / vendors / employees all bind the plain Contact model, and the
     * controller answers 404 for a contact without that role. Leave such a
     * request to reach that 404 — no 423 revealing the contact exists, and no
     * version bump for a write that never happens.
     */
    private function isWrongContactRole(Request $request, Model $parameter): bool
    {
        if (! $parameter instanceof Contact) {
            return false;
        }

        $flag = match (true) {
            $request->routeIs('api.v1.customers.*') => 'is_customer',
            $request->routeIs('api.v1.vendors.*') => 'is_vendor',
            $request->routeIs('api.v1.employees.*') => 'is_employee',
            default => null,
        };

        return $flag !== null && ! $parameter->getAttribute($flag);
    }
}
