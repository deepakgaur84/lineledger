<?php

namespace App\Livewire\Attributes;

use Attribute;
use Illuminate\Support\Arr;
use Livewire\Features\SupportAttributes\Attribute as LivewireAttribute;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

/**
 * #[Locked] for a public array of rows the server builds, except for the
 * columns the browser is meant to change — an apply table's Apply cell, a
 * picker's tick box:
 *
 *     #[LockedRows(except: 'apply')]
 *     public array $applyTable = [];
 *
 * Everything else in a row (which document it names, its open balance, its
 * amount) is the server's, so an update that rewrites it, or adds or drops a
 * row, is refused the way #[Locked] refuses one. Both shapes the browser sends
 * are covered: one cell ("applyTable.2.apply"), and the whole array, which it
 * sends when every row changed in one round trip and which must then match the
 * server's copy in everything but the editable columns.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class LockedRows extends LivewireAttribute
{
    /** @var list<string> */
    private array $editable;

    /**
     * @param  string|list<string>  $except  the columns the browser may change
     */
    public function __construct(string|array $except)
    {
        $this->editable = is_array($except) ? $except : [$except];
    }

    /**
     * Runs before Livewire applies an update to the property or anything under it.
     *
     * @throws CannotUpdateLockedPropertyException
     */
    public function update(string $fullPath, mixed $newValue): void
    {
        $property = (string) $this->getName();
        $rows = (array) $this->getValue();
        $segments = $fullPath === $property ? [] : explode('.', substr($fullPath, strlen($property) + 1));

        $allowed = match (count($segments)) {
            0 => is_array($newValue)
                && array_keys($newValue) === array_keys($rows)
                && collect($rows)->every(fn (mixed $row, int|string $index): bool => $this->differsOnlyInEditable($row, $newValue[$index])),
            1 => array_key_exists($segments[0], $rows)
                && $this->differsOnlyInEditable($rows[$segments[0]], $newValue),
            2 => array_key_exists($segments[0], $rows)
                && in_array($segments[1], $this->editable, true)
                && ($newValue === null || is_scalar($newValue)),
            default => false,
        };

        if (! $allowed) {
            throw new CannotUpdateLockedPropertyException($fullPath);
        }
    }

    private function differsOnlyInEditable(mixed $row, mixed $proposed): bool
    {
        if (! is_array($row) || ! is_array($proposed)) {
            return false;
        }

        $fixed = Arr::except($row, $this->editable);
        $incoming = Arr::except($proposed, $this->editable);
        ksort($fixed);
        ksort($incoming);

        return $fixed === $incoming
            && collect(Arr::only($proposed, $this->editable))->every(fn (mixed $cell): bool => $cell === null || is_scalar($cell));
    }
}
