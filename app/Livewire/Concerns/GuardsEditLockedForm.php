<?php

namespace App\Livewire\Concerns;

use App\Enums\EditLockState;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Support\EditLocks\EditLockables;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

use function Livewire\store;

/**
 * Edit lock for a full-page edit form. The form adds the trait and names its
 * record:
 *
 *     protected function editLockRecord(): ?Model
 *     {
 *         return $this->invoice;
 *     }
 *
 * and wraps its template just inside the root element — an `if ($editLockBlocked)`
 * Blade block showing `<x-edit-lock.blocked :lock="$this->editLockView" />`,
 * whose else branch starts with `<x-edit-lock.status :lock="$this->editLockView" />`
 * followed by the existing form (see invoices/⚡form.blade.php).
 *
 * Opening an existing record takes the lock; someone else gets the blocked
 * panel instead of the form. Every wire action (any save name, attachment
 * writes, …) is checked against the lock first — see callGuardsEditLockedForm().
 */
trait GuardsEditLockedForm
{
    use HoldsEditLock;

    #[Locked]
    public bool $editLockBlocked = false;

    #[Locked]
    public ?string $editLockPageUrl = null;

    #[Locked]
    public ?string $editLockViewUrl = null;

    /** The page's route name (e.g. invoices.create), to find the edit URL of a record created here. */
    #[Locked]
    public ?string $editLockRouteName = null;

    /** When this request started, before route binding and mount() loaded the record's data. */
    protected int $editLockBootMs = 0;

    protected bool $editLockVerifiedThisRequest = false;

    /** A wire action ran while the form had no saved record yet (it may create one). */
    protected bool $editLockCreatingThisRequest = false;

    /**
     * The record this form edits — null (or unsaved) on the create page.
     */
    abstract protected function editLockRecord(): ?Model;

    public function bootGuardsEditLockedForm(): void
    {
        // RecordRequestStart stamps the page request before route-model binding
        // loads the record; Livewire updates (and tests) fall back to now.
        $started = request()->attributes->get('request_started_ms');

        $this->editLockBootMs = is_int($started) ? $started : (int) now()->getTimestampMs();
    }

    /**
     * Runs after the form's own mount(), so its 403s and redirects come first.
     */
    public function mountGuardsEditLockedForm(): void
    {
        if (store($this)->has('redirect')) {
            return; // mount() already sent the user elsewhere (e.g. a source-linked journal entry)
        }

        $this->editLockRouteName = request()->route()?->getName();

        $record = $this->editLockRecord();
        $user = $this->editLockUser();

        if ($record === null || ! $record->exists || $user === null || ! $this->editLocks()->enabled()) {
            return;
        }

        $this->editLockPageUrl = request()->fullUrl();
        $this->editLockViewUrl = $this->editLockSiblingUrl('show');

        $result = $this->editLocks()->acquire($record, $user);

        if (! $result->acquired) {
            $this->editLockBlocked = true;

            return;
        }

        $this->rememberEditLock($record, $result);

        // Someone saved between this request loading the record and taking the
        // lock (they released just in time): the form holds stale data, so load
        // the page again.
        if ($result->changedAtMs !== null && $result->changedAtMs >= $this->editLockBootMs) {
            $this->redirect($this->editLockPageUrl, navigate: true);
        }
    }

    /**
     * Before every wire action on an existing record: take the lock if this
     * page doesn't have it yet (a record created here), otherwise confirm it
     * still holds and the record hasn't changed. Magic ($commit, $refresh, …),
     * internal (__dispatch, _startUpload, …) and lock-management calls pass.
     *
     * @param  array<int|string, mixed>  $params
     * @param  array<string, mixed>  $metadata
     */
    public function callGuardsEditLockedForm(string $methodName, array $params, Closure $returnEarly, array $metadata = []): void
    {
        if ($this->editLockExempt($methodName)) {
            return;
        }

        $record = $this->editLockRecord();
        $user = $this->editLockUser();

        if ($record === null || ! $record->exists) {
            $this->editLockCreatingThisRequest = true;

            return;
        }

        if ($user === null || ! $this->editLocks()->enabled()) {
            return;
        }

        $noun = EditLockables::noun($record);

        if ($this->editLockBlocked) {
            Flux::toast(variant: 'danger', text: __('Someone else is editing this :noun.', ['noun' => $noun]));
            $returnEarly();

            return;
        }

        if ($this->editLockToken === null) {
            // A record this page created without getting its lock (e.g. locks
            // were off, or someone opened it in the same instant).
            $result = $this->editLocks()->acquire($record, $user);

            if (! $result->acquired) {
                Flux::toast(variant: 'danger', text: __(":name is editing this :noun. Try again when they're done.", [
                    'name' => $result->holder?->name() ?? __('Someone'),
                    'noun' => $noun,
                ]));
                $returnEarly();

                return;
            }

            $this->rememberEditLock($record, $result);

            // This page never held the lock, so any recorded change came from
            // someone else and its data may be stale.
            if ($result->changedAtMs !== null) {
                $exception = EditLockLostException::changed($noun);
                $this->editLockState = $exception->state;
                Flux::toast(variant: 'danger', text: $exception->getMessage());
                $returnEarly();

                return;
            }

            $this->editLockVerifiedThisRequest = $result->token !== null;

            return;
        }

        try {
            $this->editLocks()->verify($record, $this->editLockToken, (string) $this->editLockVersion, $user);
            $this->editLockState = EditLockState::Held;
            $this->editLockVerifiedThisRequest = true;
        } catch (EditLockLostException $e) {
            $this->editLockState = $e->state;
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $returnEarly();
        }
    }

    public function dehydrateGuardsEditLockedForm(): void
    {
        $this->finishEditLockedCall();
    }

    public function exceptionGuardsEditLockedForm(mixed $e, mixed $stopPropagation): void
    {
        $this->finishEditLockedCall();
    }

    /**
     * Load the page again — "Start editing" / "Try again" / "Reload". Never
     * flips to editing in place: this component's data predates the other
     * member's saves.
     */
    public function reloadEditLock(): void
    {
        $this->redirect($this->editLockReloadUrl(), navigate: true);
    }

    /**
     * Owner/Admin: take the lock from whoever holds it, then load the page
     * again with current data.
     */
    public function takeOverEditLock(): void
    {
        $record = $this->editLockRecord();
        $user = $this->editLockUser();

        if ($record === null || ! $record->exists || $user === null) {
            return;
        }

        try {
            $this->editLocks()->acquire($record, $user, takeOver: true);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reloadEditLock();
    }

    /**
     * Everything the lock templates need.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function editLockView(): array
    {
        $record = $this->editLockRecord();
        $user = $this->editLockUser();
        $holder = null;

        if ($record !== null && $record->exists && ($this->editLockBlocked || $this->editLockState === EditLockState::HeldBy)) {
            $holder = $this->editLocks()->holderOtherThan($record, $user);
        }

        return [
            'noun' => $record !== null ? EditLockables::noun($record) : __('record'),
            'blocked' => $this->editLockBlocked,
            'state' => $this->editLockState,
            'token' => $this->editLockToken,
            'holderName' => $holder?->name(),
            'since' => $holder?->acquiredAt->diffForHumans(),
            'lastActive' => $holder?->lastActiveAt->diffForHumans(),
            'available' => $this->editLockBlocked && $holder === null,
            'canTakeOver' => $record !== null && $user !== null && $holder !== null && $this->editLocks()->canTakeOver($user, $record),
            'viewUrl' => $this->editLockViewUrl,
            'keeper' => $this->editLockToken !== null ? $this->editLockKeeper() : null,
        ];
    }

    protected function editLockExempt(string $methodName): bool
    {
        return str_starts_with($methodName, '$')
            || str_starts_with($methodName, '_')
            || in_array($methodName, [
                'callGuardsEditLockedForm',
                'syncEditLock',
                'resumeEditLock',
                'reloadEditLock',
                'takeOverEditLock',
                'releaseEditLock',
                'takeOverPendingEditLock',
            ], true);
    }

    protected function editLockReloadUrl(): string
    {
        return $this->editLockPageUrl
            ?? (string) (request()->header('referer') ?: url()->current());
    }

    /**
     * The action just run created the record (the create page stays open, e.g.
     * after posting failed): take its lock now, while this page's data is what
     * was saved, and point Reload at the record's edit page.
     */
    private function lockRecordCreatedHere(): void
    {
        $record = $this->editLockRecord();
        $user = $this->editLockUser();

        if ($record === null || ! $record->exists || $this->editLockToken !== null || $user === null || ! $this->editLocks()->enabled()) {
            return;
        }

        if (store($this)->has('redirect')) {
            return; // leaving for the edit or show page, which takes the lock itself
        }

        $this->editLockPageUrl ??= $this->editLockRouteFor($record, 'edit');
        $this->editLockViewUrl ??= $this->editLockRouteFor($record, 'show');

        $result = $this->editLocks()->acquire($record, $user);

        if (! $result->acquired) {
            $this->editLockState = EditLockState::HeldBy;

            return;
        }

        $this->rememberEditLock($record, $result);
    }

    /**
     * The URL of $record's sibling route (invoices.create → invoices.edit) with
     * the company and record filled in, when the app has one.
     */
    private function editLockRouteFor(Model $record, string $action): ?string
    {
        if ($this->editLockRouteName === null) {
            return null;
        }

        $name = preg_replace('/\.(create|edit|setup|show)$/', '.'.$action, $this->editLockRouteName);
        $route = $name !== null ? Route::getRoutes()->getByName($name) : null;

        if ($route === null) {
            return null;
        }

        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = $parameter === 'company' && property_exists($this, 'company')
                ? $this->company
                : $record->getKey();
        }

        try {
            return route($name, $parameters);
        } catch (UrlGenerationException) {
            return null;
        }
    }

    /**
     * The same record's sibling route (invoices.edit → invoices.show), when the
     * app has one. Only meaningful during the initial page request.
     */
    protected function editLockSiblingUrl(string $action): ?string
    {
        $route = request()->route();
        $name = $route?->getName();

        if ($route === null || $name === null) {
            return null;
        }

        $sibling = preg_replace('/\.(edit|setup|show)$/', '.'.$action, $name);

        if ($sibling === null || $sibling === $name || ! Route::has($sibling)) {
            return null;
        }

        try {
            return route($sibling, $route->parameters());
        } catch (UrlGenerationException) {
            return null;
        }
    }

    private function finishEditLockedCall(): void
    {
        if ($this->editLockCreatingThisRequest) {
            $this->editLockCreatingThisRequest = false;
            $this->lockRecordCreatedHere();

            return;
        }

        if (! $this->editLockVerifiedThisRequest || $this->editLockToken === null) {
            return;
        }

        $this->editLockVerifiedThisRequest = false;
        $record = $this->editLockRecord();

        if ($record === null || $record->getKey() === null) {
            return;
        }

        $this->editLocks()->afterHolderCall($record, $this->editLockToken);
    }
}
