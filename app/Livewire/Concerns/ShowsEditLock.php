<?php

namespace App\Livewire\Concerns;

use App\Livewire\Attributes\GuardsEditLock;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use App\Support\EditLocks\EditLockables;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Edit lock awareness for a record's show page: a "Jane Doe is editing this
 * invoice" banner (<x-edit-lock.banner :lock="$this->editLockBanner" />) with
 * an Owner/Admin take-over, and — via the {@see GuardsEditLock}
 * attribute on each mutating method (void, post, delete, …) — refusing those
 * actions while someone else is editing.
 *
 *     protected function editLockRecord(): ?Model
 *     {
 *         return $this->invoice;
 *     }
 */
trait ShowsEditLock
{
    #[Locked]
    public ?string $editLockEditUrl = null;

    abstract protected function editLockRecord(): ?Model;

    public function mountShowsEditLock(): void
    {
        $route = request()->route();
        $name = $route?->getName();

        if ($route === null || $name === null || ! str_ends_with($name, '.show')) {
            return;
        }

        $edit = substr($name, 0, -strlen('.show')).'.edit';

        if (! Route::has($edit)) {
            return;
        }

        try {
            $this->editLockEditUrl = route($edit, $route->parameters());
        } catch (UrlGenerationException) {
            $this->editLockEditUrl = null;
        }
    }

    /**
     * Who else is editing this record right now, for the banner — null when
     * nobody (or only the viewer) is.
     *
     * @return array{name: string, noun: string, since: string, lastActive: string, canTakeOver: bool}|null
     */
    #[Computed]
    public function editLockBanner(): ?array
    {
        $record = $this->editLockRecord();
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        if ($record === null || ! $record->exists) {
            return null;
        }

        $locks = app(EditLockManager::class);
        $holder = $locks->holderOtherThan($record, $user);

        if ($holder === null) {
            return null;
        }

        return [
            'name' => $holder->name(),
            'noun' => EditLockables::noun($record),
            'since' => $holder->acquiredAt->diffForHumans(),
            'lastActive' => $holder->lastActiveAt->diffForHumans(),
            'canTakeOver' => $user !== null && $locks->canTakeOver($user, $record),
        ];
    }

    /**
     * Owner/Admin: take the lock from whoever is editing and open the editor.
     */
    public function takeOverEditLock(): void
    {
        $record = $this->editLockRecord();
        $user = auth()->user();

        if ($record === null || ! $record->exists || ! $user instanceof User) {
            return;
        }

        try {
            app(EditLockManager::class)->acquire($record, $user, takeOver: true);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        if ($this->editLockEditUrl !== null) {
            $this->redirect($this->editLockEditUrl, navigate: true);

            return;
        }

        unset($this->editLockBanner);
    }
}
