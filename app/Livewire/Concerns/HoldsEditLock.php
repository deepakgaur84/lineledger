<?php

namespace App\Livewire\Concerns;

use App\Enums\EditLockState;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use App\Services\EditLocks\EditLockResult;
use App\Support\EditLocks\EditLockables;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * A component that holds at most one record's edit lock at a time — the base
 * for {@see GuardsEditLockedForm} (full-page edit forms) and, used directly, the
 * list pages whose rows open in an edit dialog:
 *
 *     public function openEdit(int $id): void
 *     {
 *         $account = Account::query()->findOrFail($id);
 *         if (! $this->acquireEditLock($account, reopen: 'openEdit', reopenArgs: [$id])) {
 *             return;
 *         }
 *         // …fill the fields (after acquiring, so they're current)…
 *     }
 *
 *     public function save(): void
 *     {
 *         if ($this->editingId !== null && ! $this->ensureEditLockForSave(Account::class, $this->editingId)) {
 *             return;
 *         }
 *         // …persist…
 *         $this->completeEditLockSave();
 *     }
 *
 * plus `wire:close="releaseEditLock"` on the <flux:modal>, the keeper
 * (<x-edit-lock.keeper>) inside it, and <x-edit-lock.takeover-modal /> once on
 * the page. Row actions that change a record (toggle active, merge, delete)
 * go through {@see guardEditLockedWrite()}.
 */
trait HoldsEditLock
{
    #[Locked]
    public ?string $editLockToken = null;

    #[Locked]
    public ?string $editLockVersion = null;

    /** @var class-string<Model>|null */
    #[Locked]
    public ?string $editLockType = null;

    #[Locked]
    public int|string|null $editLockKey = null;

    #[Locked]
    public EditLockState $editLockState = EditLockState::Held;

    /**
     * A blocked dialog an Owner/Admin may take over: which record, and which
     * opener to run again once they hold it.
     *
     * @var array{type: class-string<Model>, key: int|string, method: string, args: list<mixed>, name: string, noun: string}|null
     */
    #[Locked]
    public ?array $editLockPendingTakeover = null;

    /**
     * Take the lock on a record opened in an edit dialog. Releases whatever
     * this component held before (a different row). When someone else holds
     * it, shows who — or, for an Owner/Admin, offers to take over and then
     * re-run $reopen(...$reopenArgs).
     *
     * @param  list<mixed>  $reopenArgs
     */
    protected function acquireEditLock(Model $record, ?string $reopen = null, array $reopenArgs = []): bool
    {
        $this->editLockPendingTakeover = null;
        $user = $this->editLockUser();

        if ($user === null || ! $this->editLocks()->enabled()) {
            return true;
        }

        $this->releaseEditLock();

        $result = $this->editLocks()->acquire($record, $user);

        if ($result->acquired) {
            $this->rememberEditLock($record, $result);

            // The caller loaded $record before taking the lock; a save that
            // landed in between would otherwise show stale values in the dialog.
            if ($record->exists) {
                $record->refresh();
            }

            return true;
        }

        $noun = EditLockables::noun($record);
        $name = $result->holder?->name() ?? __('Someone');

        if ($reopen !== null && $this->editLocks()->canTakeOver($user, $record)) {
            $this->editLockPendingTakeover = [
                'type' => $record::class,
                'key' => $record->getKey(),
                'method' => $reopen,
                'args' => array_values($reopenArgs),
                'name' => $name,
                'noun' => $noun,
            ];

            Flux::modal('edit-lock-takeover')->show();
        } else {
            Flux::toast(variant: 'danger', text: __(":name is editing this :noun. Try again when they're done.", [
                'name' => $name,
                'noun' => $noun,
            ]));
        }

        return false;
    }

    /**
     * Before an edit dialog saves the record ($type, $key): false (with a toast)
     * if the lock was lost or the record changed since the dialog opened. A
     * save for a record this page doesn't hold (a crafted request that set the
     * editing id without opening the dialog) has to take the lock first.
     *
     * @param  class-string<Model>|null  $type
     */
    protected function ensureEditLockForSave(?string $type = null, int|string|null $key = null): bool
    {
        $user = $this->editLockUser();

        if ($user === null || ! $this->editLocks()->enabled()) {
            return true;
        }

        if ($type !== null && $key !== null && ($this->editLockType !== $type || (string) $this->editLockKey !== (string) $key)) {
            $record = $type::query()->find($key);

            return $record === null || $this->acquireEditLock($record);
        }

        if ($this->editLockToken === null) {
            return true;
        }

        try {
            $this->editLocks()->verify($this->editLockSubject(), $this->editLockToken, (string) $this->editLockVersion, $user);
            $this->editLockState = EditLockState::Held;

            return true;
        } catch (EditLockLostException $e) {
            $this->editLockState = $e->state;
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        }
    }

    /**
     * The keeper's heartbeat wasn't a plain "still yours": work out where the
     * lock stands. A lapsed lease is reclaimed when nothing changed meanwhile.
     */
    public function syncEditLock(bool $active = false): void
    {
        $user = $this->editLockUser();

        if ($user === null || $this->editLockToken === null || $this->editLockType === null) {
            return;
        }

        $subject = $this->editLockSubject();

        if ($this->editLocks()->inspect($subject, $this->editLockToken, (string) $this->editLockVersion, $user) === EditLockState::Idle) {
            $this->editLockState = EditLockState::Idle;

            return;
        }

        try {
            // Only real activity counts toward staying active — a keeper whose
            // heartbeats keep failing must still let an unattended page go idle.
            $this->editLocks()->verify($subject, $this->editLockToken, (string) $this->editLockVersion, $user, markActive: $active);
            $this->editLockState = EditLockState::Held;
        } catch (EditLockLostException $e) {
            $this->editLockState = $e->state;
        }
    }

    /**
     * "Continue editing" after an idle pause (or any activity once paused).
     */
    public function resumeEditLock(): void
    {
        $user = $this->editLockUser();

        if ($user === null || $this->editLockToken === null || $this->editLockType === null) {
            return;
        }

        try {
            $this->editLocks()->verify($this->editLockSubject(), $this->editLockToken, (string) $this->editLockVersion, $user);
            $this->editLockState = EditLockState::Held;
        } catch (EditLockLostException $e) {
            $this->editLockState = $e->state;
        }
    }

    /**
     * After an edit dialog saved: note the change and let the lock go.
     */
    protected function completeEditLockSave(): void
    {
        if ($this->editLockToken !== null && $this->editLockType !== null) {
            $this->editLocks()->afterHolderCall($this->editLockSubject(), $this->editLockToken);
        }

        $this->releaseEditLock();
    }

    /**
     * Let go of the held lock (dialog closed). Only ever releases this
     * component's own lease.
     */
    public function releaseEditLock(): void
    {
        $user = $this->editLockUser();

        if ($this->editLockToken !== null && $user !== null) {
            $this->editLocks()->release($this->editLockToken, $user);
        }

        $this->editLockToken = null;
        $this->editLockVersion = null;
        $this->editLockType = null;
        $this->editLockKey = null;
        $this->editLockState = EditLockState::Held;
    }

    /**
     * Owner/Admin: take over the dialog lock offered by acquireEditLock(), then
     * open the dialog again.
     */
    public function takeOverPendingEditLock(): void
    {
        $pending = $this->editLockPendingTakeover;
        $user = $this->editLockUser();
        $this->editLockPendingTakeover = null;

        Flux::modal('edit-lock-takeover')->close();

        if ($pending === null || $user === null) {
            return;
        }

        $record = $pending['type']::query()->find($pending['key']);

        if ($record === null) {
            return;
        }

        try {
            $this->editLocks()->acquire($record, $user, takeOver: true);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->{$pending['method']}(...$pending['args']);
    }

    /**
     * Run a change to a record that isn't this page's own held edit — a list
     * row action. Refused (with a toast naming who is editing) while another
     * member holds the lock; an open edit page for the record can't then save
     * over it. Returns whether the write ran.
     *
     * @param  Closure(): mixed  $write
     */
    protected function guardEditLockedWrite(Model $record, Closure $write): bool
    {
        return $this->guardEditLockedWrites([$record], $write);
    }

    /**
     * {@see guardEditLockedWrite()} for a change touching several records at
     * once (a merge guards both sides): refused if any is being edited.
     *
     * @param  list<Model>  $records
     * @param  Closure(): mixed  $write
     */
    protected function guardEditLockedWrites(array $records, Closure $write): bool
    {
        $locks = $this->editLocks();
        $user = $this->editLockUser();
        $others = [];

        foreach ($records as $record) {
            if ($this->holdsEditLockOn($record)) {
                if (! $this->ensureEditLockForSave()) {
                    return false;
                }

                continue; // this page's own held edit — no version bump
            }

            try {
                $locks->assertWritable($record, $user);
            } catch (RecordEditLockedException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());

                return false;
            }

            $others[] = $record;
        }

        try {
            $write();
        } finally {
            foreach ($others as $record) {
                $locks->touch($record);
            }
        }

        return true;
    }

    /**
     * Config for the browser keeper (<x-edit-lock.keeper>).
     *
     * @return array{heartbeatUrl: string, releaseUrl: string, csrf: string, intervalMs: int}
     */
    #[Computed]
    public function editLockKeeper(): array
    {
        return [
            'heartbeatUrl' => route('edit-locks.heartbeat'),
            'releaseUrl' => route('edit-locks.release'),
            'csrf' => app()->has('session.store') ? (string) csrf_token() : '',
            'intervalMs' => $this->editLocks()->heartbeatMs(),
        ];
    }

    protected function rememberEditLock(Model $record, EditLockResult $result): void
    {
        $this->editLockToken = $result->token;
        $this->editLockVersion = $result->version;
        $this->editLockType = $result->token !== null ? $record::class : null;
        $this->editLockKey = $result->token !== null ? $record->getKey() : null;
        $this->editLockState = EditLockState::Held;
    }

    protected function holdsEditLockOn(Model $record): bool
    {
        return $this->editLockToken !== null
            && $this->editLockType === $record::class
            && (string) $this->editLockKey === (string) $record->getKey();
    }

    /**
     * The record behind the held lock. Falls back to a bare keyed instance when
     * it has since been deleted — the lock row is addressed by type and key.
     */
    protected function editLockSubject(): Model
    {
        /** @var class-string<Model> $type */
        $type = $this->editLockType;

        $record = $type::query()->find($this->editLockKey);

        if ($record instanceof Model) {
            return $record;
        }

        $bare = new $type;
        $bare->setAttribute($bare->getKeyName(), $this->editLockKey);

        return $bare;
    }

    protected function editLocks(): EditLockManager
    {
        return app(EditLockManager::class);
    }

    protected function editLockUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
