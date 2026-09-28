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
Credits, Credit Memos, Bill Payments, Receipts — the AP/AR pairs, each
posted automatically after saving (`SaveBill`/`SaveInvoice`/etc. only
create a draft; the importer also calls the matching `*Poster`). A
posting failure (e.g. a locked period) still leaves a valid, reviewable
draft behind rather than losing the row's data — reported as its own
distinct message. Vendor/customer resolution refuses to guess between two
contacts sharing a name, mirroring `FindContactTool`'s own ambiguity
handling, rather than silently picking one and posting against the wrong
contact.

Bill Payments and Receipts use the same grouped shape for a different
reason than Bills/Invoices do: one row is one *application* to a bill or
invoice, not one line item, and the payment/receipt's own total is the sum
of its rows' `application_amount` rather than a separate column — a
single payment can apply to several bills at once, one row each, all
sharing the same `import_ref`. **One real, confirmed asymmetry between
them**: `StoreBillPaymentRequest` requires the bill be open
(posted/partial) before a payment can apply to it; `StoreReceiptRequest`
has no equivalent check for invoices. Both importers mirror their own
API's validation exactly rather than "fixing" an inconsistency that isn't
theirs to fix.

**One real, confirmed schema asymmetry worth knowing**: `vendor_credits`
has no `currency_code`/`fx_rate` columns at all — confirmed directly
against the schema and the shared `add_currency_to_documents` migration,
which covers `invoices`, `bills`, and `credit_memos` but not
`vendor_credits`. A genuine, pre-existing gap in the app itself, not an
importer oversight — `VendorCreditImporter` has no currency columns at
all, while `CreditMemoImporter` (its AR-side mirror) does, matching what
each side of the app can actually do.

**Fixed Assets** — one row, one asset, through the real `SaveAsset`
action, so an imported asset gets identical validation and depreciation
handling to one entered on the form. **Register-only**: unlike every
other importer above, it never posts to the ledger — the asset's cost is
expected to already be there, from whatever bought it (a bill, a cheque,
a journal entry). To load a register *together with* its accumulated
depreciation as opening balances, use the Opening Balances importer
instead (see below), not this one.

Blank fields fall back to the row's asset category exactly the way the
asset form does: the three GL accounts, the useful life, and the
depreciation method and rate. A row can name its category by
`category_name`; a category the row names must already exist (Settings →
Lists → Asset categories) — this importer doesn't create one, unlike the
Migration wizard's own fixed-assets importer below.

The three depreciation methods (see the fork's own `README-FORK-PATCHES.md`
§10 for the full feature) are all supported: `straight_line`,
`declining_balance` (also accepts `WDV`, `reducing balance`, and similar),
and `immediate` (also accepts `100%`). A `depreciation_rate` is only valid
for declining balance — given for any other method, the row is rejected,
since a rate on a straight-line row is far more likely a mislabelled row
than genuine intent. Declining balance with no rate anywhere (not on the
row, not on its category) defaults to 20%, and the preview says so.

**`auto_depreciate: yes` on a back-dated `in_service_date` back-fills every
month since that has fully ended and isn't locked** — the preview shows
exactly how many months and their date range before you commit, and warns
above one month, so this is never a surprise. If the ledger already
carries that depreciation from elsewhere, lock the period first or leave
`auto_depreciate` off; generation is capped at 60 months per run regardless.

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
- **Journal Entries** — no existing CSV convention to mimic;
  Migration's own `GeneralLedgerReplayImporter` is a QuickBooks-specific
  full-history replay tool posting raw, already-balanced entries from a
  QB Journal report — a fundamentally different job from "add a new
  journal entry," not reusable.

None of this needs a new architecture — `ImporterDefinition` and
`GroupedImporterDefinition` between them already support arbitrarily
complex `validate()`/`commit()` logic for any shape of entity. It's real,
additional work, not a redesign.

## The Opening Balances / QuickBooks-migration fixed-assets importer

Distinct from, and older than, the two importers above:
`app/Services/Migration/Importers/FixedAssetsImporter.php`, shared by the
one-time QuickBooks migration wizard and the standalone Opening Balances
tool (via `app/Services/OpeningBalances/Importers/FixedAssetsCompanyImporter.php`,
a thin wrapper). It posts an asset's cost **and** its accumulated
depreciation to date directly via `JournalPoster`, absorbed into the
Opening Balances maintained entry's own netting — a fundamentally
different job from this file's own register-only importer above (which
never touches the ledger), so the two were never merged into one.

It learned the same `depreciation_method`/`depreciation_rate` columns as
the register-only importer, sharing the exact same validation (1–100%
window, declining-balance-only, defaults to 20% when blank) — a CSV
without either column still imports correctly, as straight-line, so
nothing relying on the old template breaks. A category a row names here
*is* created if it doesn't already exist (unlike the register-only
importer above), inheriting the row's method and rate as that new
category's own defaults.

## Testing this

**Fixed Assets has real coverage** —
`tests/Feature/BulkImport/FixedAssetImporterTest.php` (the register-only
importer: category fallbacks, every method spelling, the 1–100% rate
window, the back-fill preview and its lock-date awareness, duplicate
detection) and `tests/Feature/Migration/FixedAssetsImporterMethodsTest.php`
(the Opening-Balances/QuickBooks-wizard one: the same method/rate columns,
a CSV with neither column still importing as straight-line). Both are a
reasonable pattern to copy for any importer built here next.

**Every other importer — Vendors through Receipts — still has no
automated tests of its own.** Before extending one, worth adding feature
tests mirroring the existing `tests/Feature/Api/V1/*LifecycleTest.php`
pattern — create a vendor with a foreign currency via the importer, assert
it matches what the equivalent API call would produce, and a test
asserting that a contact created via the importer *without* a currency,
later resupplied via a second import row, does *not* silently change
(matching `canChangeCurrency()`'s locked behavior) once it has a posted
transaction.

For the grouped importers specifically, also worth covering: a document
split across multiple `import_ref`-matched rows genuinely produces one
document with the right number of lines (not one per row); a blank
document number auto-numbers correctly; a genuinely ambiguous
vendor/customer name is rejected rather than guessed; and a posting
failure leaves a valid draft rather than losing the row's data.
