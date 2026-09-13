# Bulk Import — how it works, and the FC logic it depends on

`/bulk-import` (route name `tools.bulk-import`) — a standalone, repeatable
CSV importer. Distinct from the one-time QuickBooks migration wizard at
`/import-from-quickbooks`: that wizard calls `Contact::create()` directly
and has no currency support at all. Everything here instead calls the same
`Save*` actions the real UI/API use, so an imported record gets identical
validation and business rules to one entered by hand — including currency.

## Architecture

- **`app/Services/BulkImport/ImporterDefinition.php`** — the contract every
  entity type implements: `csvColumns()`, `validate()`, `summarize()`,
  `commit()`.
- **`app/Services/BulkImport/Importers/`** — one class per entity type.
  `AbstractContactImporter` holds the shared Vendor/Customer logic;
  `VendorImporter`/`CustomerImporter` are thin subclasses differing only in
  which Contact role gets set.
- **`app/Services/BulkImport/BulkImportRegistry.php`** — the list of
  available importers. **Adding a new entity type means adding one class
  here — the page itself needs no changes.**
- **`resources/views/pages/tools/⚡bulk-import.blade.php`** — the page.
  Upload → validate (nothing created yet) → preview table (per-row
  valid/invalid + why) → confirm → commit. Invalid rows are always
  skipped, never partially imported.

## Currently shipped: Vendors, Customers

Both are flat entities — no reference resolution, no nested line items.
That's *why* these two shipped first; see "Not yet built" below for what a
Bills/Payments importer would additionally need.

## The FC (foreign currency) logic — read this before adding Bills/Payments

This is the part that caused a real, hours-long production incident before
these rules were understood, so it's worth having in one place.

### 1. Vendor/Customer currency is the foundation everything else inherits

A `Contact.currency_code` is nullable; `null` means home currency. **Once a
contact has any posted transaction, it can never be changed again** —
`Contact::canChangeCurrency()` returns `false`, and `SaveContact` silently
ignores any later attempt to change it (no error — the value just doesn't
change, which is exactly how a real vendor was accidentally left in the
wrong currency for months in production).

**Practical rule: currency must be set at the same time a vendor/customer
is created via this importer — never assume it can be fixed afterward.**

### 2. Bill/Payment currency is resolved once, at creation, from the contact

`SaveBill`/`SaveBillPayment`'s `resolveCurrencyCode()`:

```
explicit currency_code in the request  →  use that
otherwise                              →  the contact's own currency_code
```

A Bill Payment resolves this from *its own* contact, not copied from the
Bill it's paying — in practice these normally match, but they're two
independent lookups, not one inherited value.

### 3. The exchange *rate* is a separate decision, resolved later, at posting

`BillPoster::lockRate()` / `BillPaymentPoster::lockPaymentRate()`:

```
if fx_rate is already set on the record  →  use it, lock it in, never re-derive
otherwise                                →  live-fetch from Frankfurter (ECB rates) for that date
```

**This is why a bulk importer of historical data must be able to supply an
explicit `fx_rate` per row** — letting posting auto-fetch "today's rate"
for a transaction from six months ago silently misstates what was actually
paid. `currency_code` and `fx_rate` are now both accepted on the
`Store*Request`s for Contact, Bill, and BillPayment (added alongside this
importer) specifically to make this possible.

### 4. Two different gain/loss accounts, for two different events

- **Realized Gain/Loss** — fires *at payment time*, when a bill's locked
  rate differs from the payment's own locked rate. A closed, completed
  transaction.
- **Unrealized Gain/Loss** — fires only during *period-end revaluation*,
  for balances still open. A paper adjustment on something not yet
  settled. Not something a bulk importer of already-completed
  transactions needs to worry about.

### The bug this all comes from, in one sentence

A vendor was created before its currency was set, several bills/payments
were posted against it while it still had no currency (silently treated as
home-currency), and *then* the vendor's currency was corrected — which
`canChangeCurrency()` correctly refused to let retroactively fix the
already-posted transactions, because by then they existed. Recovering
required finding the underlying `BillPaymentPoster` posting bug (a missing
foreign-amount field on the bank line, now fixed), then reposting every
affected transaction by hand.

## Not yet built: Items, Bills, Payments

- **Items** need at minimum one reference resolution (`income_account_id`
  is required) — a CSV import needs to resolve an account *name or code*
  to an ID, and decide what happens when it doesn't match anything.
- **Bills** additionally need: resolving a vendor name to `contact_id`,
  and a convention for representing multiple line items in a flat CSV
  (e.g. repeated header fields across rows sharing a "Bill Reference"
  column, grouped into one `commit()` call).
- **Payments** additionally need: resolving which bill(s) a payment
  applies to, and validating the application amounts don't exceed the
  payment total (`StoreBillPaymentRequest`'s own `withValidator()` already
  has this exact check — reuse it, don't reimplement).

None of this needs a new architecture — `ImporterDefinition` already
supports arbitrarily complex `validate()`/`commit()` logic per entity
type. It's real, additional work, not a redesign.

## Testing this

No automated tests exist yet for the bulk importer itself. Before
extending it, worth adding feature tests mirroring the existing
`tests/Feature/Api/V1/*LifecycleTest.php` pattern — create a vendor with a
foreign currency via the importer, assert it matches what the equivalent
API call would produce, and a test asserting that a contact created via
the importer *without* a currency, later resupplied via a second import
row, does *not* silently change (matching `canChangeCurrency()`'s locked
behavior) once it has a posted transaction.
