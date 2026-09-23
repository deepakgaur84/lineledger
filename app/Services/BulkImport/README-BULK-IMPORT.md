# Bulk Import — how it works, and the FC logic it depends on

`/bulk-import` (route name `tools.bulk-import`) — a standalone, repeatable
CSV importer. Distinct from the one-time QuickBooks migration wizard at
`/import-from-quickbooks`: that wizard calls `Contact::create()` directly
and has no currency support at all. Everything here instead calls the same
`Save*` actions the real UI/API use, so an imported record gets identical
validation and business rules to one entered by hand — including currency.

## Architecture

Two parallel contracts, depending on whether an entity is one row or many:

- **`app/Services/BulkImport/ImporterDefinition.php`** — flat, one-row-one-
  record entities: `csvColumns()`, `validate()`, `summarize()`, `commit()`.
- **`app/Services/BulkImport/GroupedImporterDefinition.php`** — multi-line
  documents, where several CSV rows sharing a common reference become one
  record with multiple lines (a bill, an invoice): `csvColumns()`,
  `groupKey()`, `validateGroup()`, `summarizeGroup()`, `commitGroup()`,
  `sampleRows()`. **Deliberately NOT an extension of `ImporterDefinition`**,
  even though the two share `csvColumns()` verbatim — extending it would
  also inherit `validate()`/`summarize()`/`commit()`, which operate on a
  single row and have no sensible meaning for a multi-line document. This
  was a real bug caught in production: an earlier version did extend it,
  and a grouped importer that correctly implemented only the grouped
  methods still fatally failed to load, because PHP requires every method
  an interface (or one it extends) declares, whether or not anything ever
  actually calls it for that class.
- **`app/Services/BulkImport/Importers/`** — one class per entity type.
  `AbstractContactImporter` holds the shared Vendor/Customer logic;
  `VendorImporter`/`CustomerImporter` are thin subclasses differing only in
  which Contact role gets set.
- **`app/Services/BulkImport/BulkImportRegistry.php`** — the list of
  available importers, of either kind. **Adding a new entity type means
  adding one class here — the page itself needs no changes.**
- **`resources/views/pages/tools/⚡bulk-import.blade.php`** — the page.
  Upload → validate (nothing created yet) → preview table (per-row, or
  per-document for a grouped importer, valid/invalid + why) → confirm →
  commit. Invalid rows/documents are always skipped, never partially
  imported. Checks `instanceof GroupedImporterDefinition` to decide which
  set of methods to call and whether to group rows before previewing —
  Vendors/Customers/Item Categories/Items are entirely unaffected by any
  of this, since they don't implement it.

### The import_ref / auto-numbering convention (grouped importers only)

Every grouped importer's CSV has an `import_ref` column: rows sharing the
same value become one document. It exists purely to group rows *within
this file* — it's never stored anywhere, and is deliberately separate from
the document's actual number (`bill_no`, `invoice_no`, etc.), which can be
left blank instead to have the document numbered automatically, the same
way one entered by hand would be. The two were originally the same
column; split apart after real user feedback that requiring a real
`bill_no` up front conflicted with wanting the app's own auto-numbering
for historical bills that didn't need their original number preserved.

## Currently shipped

**Flat entities:** Vendors, Customers, Item Categories, Items.
Vendors/Customers/Item Categories are flat entities — no reference
resolution (Item Categories has one *optional* self-referential parent,
resolved by name). Items is the first genuinely reference-heavy flat one:
resolves an income/expense account by its `code`, an item category by
`name`, and — for inventory-tracked items — an asset and COGS account too,
plus a one-time opening-balance stock adjustment (matching exactly what
`SaveItem` does for the Settings page). Deliberately excluded: **Bundle**
items, since they reference *other items* as components — a flat CSV row
has no clean way to represent that.

**Grouped (multi-line document) entities:** Bills, Invoices, Vendor
Credits, Credit Memos — the AP/AR pairs, each posted automatically after
saving (`SaveBill`/`SaveInvoice`/etc. only create a draft; the importer
also calls the matching `*Poster`). A posting failure (e.g. a locked
period) still leaves a valid, reviewable draft behind rather than losing
the row's data — reported as its own distinct message. Vendor/customer
resolution refuses to guess between two contacts sharing a name, mirroring
`FindContactTool`'s own ambiguity handling, rather than silently picking
one and posting against the wrong contact.

**One real, confirmed schema asymmetry worth knowing**: `vendor_credits`
has no `currency_code`/`fx_rate` columns at all — confirmed directly
against the schema and the shared `add_currency_to_documents` migration,
which covers `invoices`, `bills`, and `credit_memos` but not
`vendor_credits`. A genuine, pre-existing gap in the app itself, not an
importer oversight — `VendorCreditImporter` has no currency columns at
all, while `CreditMemoImporter` (its AR-side mirror) does, matching what
each side of the app can actually do.

**Deliberately not built as importers here: Cheques, Deposits,
Transfers.** The existing bank-statement importer (`/banking/import`,
linked directly from this page) is a strictly better tool for these — it
matches against real bank data, auto-splits tax, pays open bills directly,
and auto-pairs transfers across two accounts. A flat CSV importer for
these would need to reinvent all of that from hand-typed data instead of
the bank's own authoritative record.

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

### 2. Every document's currency is resolved once, at creation, from the contact

`SaveBill`/`SaveInvoice`/`SaveVendorCredit` (currency-supporting tables
only)/`SaveCreditMemo`/`SaveBillPayment`'s `resolveCurrencyCode()`:

```
explicit currency_code in the request  →  use that
otherwise                              →  the contact's own currency_code
```

A Bill Payment resolves this from *its own* contact, not copied from the
Bill it's paying — in practice these normally match, but they're two
independent lookups, not one inherited value.

### 3. The exchange *rate* is a separate decision, resolved later, at posting

`BillPoster`/`InvoicePoster`/`CreditMemoPoster` (confirmed directly against
each one's own code, not assumed from one and extrapolated — they're
independent classes with independent logic that happens to follow the
same pattern):

```
if fx_rate is already set on the record  →  use it, lock it in, never re-derive
otherwise                                →  live-fetch from Frankfurter (ECB rates) for that date
```

`VendorCreditPoster` has no equivalent at all — `vendor_credits` has no
`currency_code`/`fx_rate` columns, a genuine gap in the app itself (see
"Currently shipped" above).

**This is why a bulk importer of historical data must be able to supply an
explicit `fx_rate` per row** — letting posting auto-fetch "today's rate"
for a transaction from six months ago silently misstates what was actually
paid. `currency_code` and `fx_rate` are accepted on every currency-
supporting importer's CSV for exactly this reason.

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

## Not yet built

- **Bundle items** — reference other items as components; no clean flat-
  CSV representation.
- **Bill Payments / Receipts** — need resolving which document(s) a
  payment applies to, and validating the application amounts don't exceed
  the payment total (`StoreBillPaymentRequest`'s own `withValidator()`
  already has this exact check — reuse it, don't reimplement).
- **Journal Entries** — no existing CSV convention to mimic;
  Migration's own `GeneralLedgerReplayImporter` is a QuickBooks-specific
  full-history replay tool posting raw, already-balanced entries from a
  QB Journal report — a fundamentally different job from "add a new
  journal entry," not reusable.
- **Fixed Assets** — Migration's own `FixedAssetsImporter` has a genuinely
  complete, real column set worth mimicking closely (`asset_no, name,
  category_name, asset_account_code, accum_depreciation_account_code,
  depreciation_expense_account_code, acquired_date, in_service_date, cost,
  salvage_value, useful_life_months, accumulated_depreciation_to_date,
  serial_number, location, description`) — but it posts via
  `JournalPoster` directly rather than a `Save*` action, so it can't be
  reused as-is; a real importer here still needs building fresh through
  `SaveAsset`.

None of this needs a new architecture — `ImporterDefinition` and
`GroupedImporterDefinition` between them already support arbitrarily
complex `validate()`/`commit()` logic for any shape of entity. It's real,
additional work, not a redesign.

## Testing this

No automated tests exist yet for the bulk importer itself — for either
kind, flat or grouped. Before extending it, worth adding feature tests
mirroring the existing `tests/Feature/Api/V1/*LifecycleTest.php` pattern —
create a vendor with a foreign currency via the importer, assert it
matches what the equivalent API call would produce, and a test asserting
that a contact created via the importer *without* a currency, later
resupplied via a second import row, does *not* silently change (matching
`canChangeCurrency()`'s locked behavior) once it has a posted transaction.

For the grouped importers specifically, also worth covering: a document
split across multiple `import_ref`-matched rows genuinely produces one
document with the right number of lines (not one per row); a blank
document number auto-numbers correctly; a genuinely ambiguous
vendor/customer name is rejected rather than guessed; and a posting
failure leaves a valid draft rather than losing the row's data.
