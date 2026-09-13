<?php

namespace App\Livewire\Concerns;

use App\Models\Contact;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\JournalPoster;
use App\Support\Accounting\ControlAccountRoles;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * Host side of the per-line `<x-line-contact-combo>` picker: search, pick,
 * inline-create and clear the customer / vendor a line belongs to, for lines
 * coded to an Accounts Receivable / Accounts Payable control account.
 *
 * That contact is what {@see ChequePoster} and
 * {@see JournalPoster} stamp onto the GL leg, and
 * `journal_lines.contact_id` is the whole AR/AP sub-ledger — the aging reports,
 * contact statements and cached contact balances read nothing else. So the
 * picker is required rather than advisory, and the role is enforced both ways:
 * a customer on AR, a vendor on AP.
 *
 * The host declares `public array $lines` whose rows carry `account_id`,
 * `contact_id`, `contact_query`, `contact_creating` and `new_contact_name`, and
 * implements {@see lineHasAmount()} — the two forms using this measure "this row
 * carries money" differently (debit/credit vs a single amount). A host whose
 * `updatedLines()` sees an account change should call
 * {@see resetLineContactState()} when the new account no longer needs a contact.
 */
trait ManagesLineContacts
{
    /**
     * Whether a line carries an amount. Blank rows are skipped by the contact
     * rules — the save path drops them anyway.
     *
     * @param  array<string, mixed>  $line
     */
    abstract protected function lineHasAmount(array $line): bool;

    /**
     * Map of account id => required contact role ('customer' for AR, 'vendor' for AP).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function contactRequiringAccounts(): array
    {
        return ControlAccountRoles::map($this->company->id);
    }

    /**
     * The role a line's account requires, or null when it needs no contact.
     */
    public function lineContactRole(int $index): ?string
    {
        return $this->contactRequiringAccounts[(int) ($this->lines[$index]['account_id'] ?? 0)] ?? null;
    }

    /**
     * Contacts matching a line's current search, scoped to the role its account requires.
     *
     * @return Collection<int, Contact>
     */
    public function lineContactOptions(int $index): Collection
    {
        $role = $this->lineContactRole($index);

        if ($role === null) {
            return collect();
        }

        $query = Contact::query()
            ->where(ControlAccountRoles::roleColumn($role), true)
            ->where('is_active', true);

        $search = trim((string) ($this->lines[$index]['contact_query'] ?? ''));

        if ($search !== '') {
            $query->where('display_name', 'like', '%'.$search.'%');
        }

        return $query->orderBy('display_name')->limit(50)->get(['id', 'display_name']);
    }

    public function lineContactName(int $index): ?string
    {
        $id = $this->lines[$index]['contact_id'] ?? null;

        return $id ? Contact::query()->where('id', $id)->value('display_name') : null;
    }

    public function selectLineContact(int $index, int $id): void
    {
        $this->lines[$index]['contact_id'] = $id;
        $this->lines[$index]['contact_creating'] = false;
        $this->lines[$index]['new_contact_name'] = '';
        $this->lines[$index]['contact_query'] = '';
        $this->resetErrorBag(["lines.{$index}.contact_id", "lines.{$index}.new_contact_name"]);
    }

    public function startNewLineContact(int $index): void
    {
        $this->lines[$index]['new_contact_name'] = trim((string) ($this->lines[$index]['contact_query'] ?? ''));
        $this->lines[$index]['contact_creating'] = true;
        $this->lines[$index]['contact_id'] = null;
        $this->lines[$index]['contact_query'] = '';
        $this->resetErrorBag(["lines.{$index}.contact_id", "lines.{$index}.new_contact_name"]);
    }

    public function clearLineContact(int $index): void
    {
        $this->resetLineContactState($index);
        $this->resetErrorBag(["lines.{$index}.contact_id", "lines.{$index}.new_contact_name"]);
    }

    /**
     * Blank a line's whole picker state. Hosts call this from updatedLines()
     * when a line's account changes to one that no longer needs a contact, so a
     * stale customer can't ride along on an expense line.
     */
    protected function resetLineContactState(int $index): void
    {
        $this->lines[$index]['contact_id'] = null;
        $this->lines[$index]['contact_creating'] = false;
        $this->lines[$index]['new_contact_name'] = '';
        $this->lines[$index]['contact_query'] = '';
    }

    /**
     * The four picker keys, for a host's emptyLine() / line hydration.
     *
     * @return array<string, mixed>
     */
    protected function emptyLineContactState(?int $contactId = null): array
    {
        return [
            'contact_id' => $contactId,
            'contact_query' => '',
            'contact_creating' => false,
            'new_contact_name' => '',
        ];
    }

    /**
     * Create any "add new" contacts typed into the line pickers before validation runs.
     */
    protected function resolveNewLineContacts(): void
    {
        foreach ($this->lines as $i => $line) {
            if (empty($line['contact_creating'])) {
                continue;
            }

            $role = $this->lineContactRole($i);

            if ($role === null || ! $this->lineHasAmount($line)) {
                continue;
            }

            $this->validate(
                ["lines.{$i}.new_contact_name" => ['required', 'string', 'max:255']],
                attributes: ["lines.{$i}.new_contact_name" => $role === ControlAccountRoles::CUSTOMER ? __('customer name') : __('vendor name')],
            );

            $contact = Contact::create([
                'display_name' => trim((string) $line['new_contact_name']),
                'is_customer' => $role === ControlAccountRoles::CUSTOMER,
                'is_vendor' => $role === ControlAccountRoles::VENDOR,
                'is_active' => true,
            ]);

            $this->selectLineContact($i, (int) $contact->id);
        }
    }

    /**
     * Require a customer on Accounts Receivable lines and a vendor on Accounts Payable lines.
     */
    protected function validateLineContacts(): void
    {
        $errors = [];

        foreach ($this->lines as $i => $line) {
            if (! $this->lineHasAmount($line)) {
                continue;
            }

            $role = $this->lineContactRole($i);

            if ($role === null) {
                continue;
            }

            $contactId = $line['contact_id'] ?? null;

            if (! $contactId) {
                $errors["lines.{$i}.contact_id"] = $role === ControlAccountRoles::CUSTOMER
                    ? __('Select a customer for the Accounts Receivable line.')
                    : __('Select a vendor for the Accounts Payable line.');

                continue;
            }

            $exists = Contact::query()
                ->whereKey($contactId)
                ->where(ControlAccountRoles::roleColumn($role), true)
                ->exists();

            if (! $exists) {
                $errors["lines.{$i}.contact_id"] = $role === ControlAccountRoles::CUSTOMER
                    ? __('Select a valid customer for the Accounts Receivable line.')
                    : __('Select a valid vendor for the Accounts Payable line.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
