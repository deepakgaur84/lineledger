<?php

namespace App\Livewire\Attributes;

use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Livewire\Concerns\ShowsEditLock;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use Attribute;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportAttributes\Attribute as LivewireAttribute;
use ReflectionMethod;

/**
 * Put on a show-page method that changes the record (void, post, delete, …)
 * of a component using {@see ShowsEditLock}:
 *
 *     #[GuardsEditLock]
 *     public function void(InvoicePoster $poster): void
 *
 * The call is refused with a toast while another member holds the record's
 * edit lock. When it runs, the lock's version is replaced afterwards —
 * whether the method returned or threw — so an edit page that loaded the
 * record before this change can't save over it.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class GuardsEditLock extends LivewireAttribute
{
    private ?Model $written = null;

    /**
     * @param  array<int|string, mixed>  $params
     */
    public function call(array $params, Closure $returnEarly): ?Closure
    {
        // editLockRecord() is protected on the component (a public method would
        // be callable from the browser), so reach it through reflection.
        $record = method_exists($this->component, 'editLockRecord')
            ? (new ReflectionMethod($this->component, 'editLockRecord'))->invoke($this->component)
            : null;

        if (! $record instanceof Model || ! $record->exists) {
            return null;
        }

        $user = auth()->user();
        $locks = app(EditLockManager::class);

        try {
            $locks->assertWritable($record, $user instanceof User ? $user : null);
        } catch (RecordEditLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $returnEarly();

            return null;
        }

        $this->written = $record;

        return function (): void {
            $this->markWritten();
        };
    }

    public function exception(mixed $e, mixed $stopPropagation): void
    {
        $this->markWritten();
    }

    private function markWritten(): void
    {
        if ($this->written === null) {
            return;
        }

        app(EditLockManager::class)->touch($this->written);
        $this->written = null;
    }
}
