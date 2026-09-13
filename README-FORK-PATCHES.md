# LineLedger Fork — Patches Index

Fork: `github.com/deepakgaur84/lineledger`
Base: `github.com/lineledger/lineledger`

This is the master list of every change made in this fork, organized by
feature/fix, with the exact files touched. For the bulk importer
specifically, see its own `README.md` in `app/Services/BulkImport/` — not
duplicated here.

All application-code changes are staged in `.fork-patches/` (mirroring
real repo paths) and applied automatically by
`.github/workflows/reapply-fork-patches.yml` on every push, including
upstream syncs. The two workflow files themselves (see §8) are the
exception — GitHub blocks the automation's own token from touching
`.github/workflows/*`, so those are committed directly to their real path.

---

## 1. Global jurisdiction support (non-CA/US companies, e.g. New Zealand)

Upstream only supports Canada and the US as company jurisdictions. Adds a
third `Global` option — no fake tax compliance claimed, just a neutral
chart of accounts and free-text region, so a company can exist at all in
a currency/region upstream never anticipated.

- `app/Enums/Country.php` — new `Global` case (backing value `'ZZ'`, not
  a longer string — `address_country` is a 2-char DB column)
- `app/Support/Tax/TaxAuthorityCatalog.php` — `Global => []` match arm
- `resources/views/pages/welcome/⚡setup-wizard.blade.php` — currency
  dropdown now loops `Currency::selectable()` instead of two hardcoded
  options (CAD/USD only)
- `tests/Feature/Companies/CountryEnumTest.php` — updated the one test
  that hardcoded a count of 2 country options

## 2. Backup system changes

- `app/Services/Backup/CompanyExporter.php` — `expires_at` set to `null`
  instead of `addDays(7)`. Self-hosted backups are meant to be retained
  until manually deleted, not auto-expired like the hosted product.
- `tests/Feature/Backup/CompanyExportEndToEndTest.php` — updated to match
- `app/Console/Commands/ExportAllCompaniesCommand.php` (new) —
  `backups:export-all`, loops every company, exports synchronously
  (blocking), continues past one company's failure
- NAS-side only, not a repo file: `lineledger-backup.sh` — DSM Task
  Scheduler script that runs the command above, moves finished ZIPs to
  `/volume1/Backup/docker/lineledger` (renamed `<timestamp>-<slug>.zip`),
  cleans up empty per-company folders, prunes anything older than 365
  days from the offsite copy only

## 3. Employee Reimbursements Payable backfill

`ChartTemplateBuilder` only seeds this control account when `employees`
is enabled *at company creation*. Enabling it later (a pre-existing
upstream gap, not fork-specific) left the account permanently missing,
breaking reimbursement bill posting.

- `app/Actions/Payroll/EnsureEmployeeReimbursementAccount.php` (new) —
  mirrors the existing `EnsureInventoryAccounts` pattern; also promotes a
  manually-created stand-in account in place if one already exists,
  rather than duplicating it
- `app/Models/Company.php` — added `usesEmployees(): bool` accessor
- `resources/views/pages/companies/⚡edit.blade.php` — wired the backfill
  into the same save-triggered block as payroll/inventory/fundraising

## 4. Cursor on buttons

- `resources/css/app.css` — Tailwind v4's Preflight resets buttons to
  `cursor: default`; restored `cursor: pointer` globally rather than
  patching every component

## 5. Two real upstream posting bugs (found via a live data investigation)

Both confirmed against upstream's own current code, not fork-specific.

- `app/Services/Posting/BillPaymentPoster.php` — the bank/paid-from
  account's own journal line was missing the `Currency::lineMemo(...)`
  spread every *other* line in the same method correctly includes,
  meaning a foreign-currency bill payment's bank-side foreign amount was
  always recorded as zero. One-line fix.
- `app/Services/Posting/JournalPoster.php` — the generic journal-entry
  void/reversal logic copies `debit_cents`/`credit_cents`/etc. onto the
  reversal line but never copied `currency_code`/`fx_rate`/foreign
  debit-credit — meaning voiding *any* foreign-currency transaction, of
  any document type, silently lost its foreign-currency data on the
  reversal side. Fixed by mirroring the home-currency debit/credit swap
  on the foreign side too.

## 6. Transaction report: source-currency columns + running/opening/closing balance

Originally scoped for foreign-currency accounts only; generalized to a
plain home-currency running balance for any single balance-sheet account
(explicitly excludes income/expense accounts — a running balance isn't a
real concept for a flow account, discovered when it wrongly appeared on
P&L drill-downs).

- `resources/views/pages/reports/⚡transactions.blade.php` — the bulk of
  the logic: `filteredAccount()`/`foreignAccountFilter()` gates,
  opening/running/closing balance computed properties (sign-aware via
  the account's own `normal_balance`), column registry additions,
  on-screen Opening/Closing Balance rows, and the matching CSV/PDF/XLSX
  export logic (`exportRows()`/`exportCsv()`/`exportPdf()`/`exportXlsx()`)
- `resources/views/pdf/reports/transactions.blade.php` — PDF template,
  same columns and Opening/Closing rows
- `app/Services/Reporting/XlsxExporter.php` — same, for the XLSX export;
  balance-related column positions are fixed constants (not offset
  arithmetic), since balance columns and grouping can never coexist
- **Real bug fixed along the way:** `columnVisible()` (existing
  `HasColumnToggles` trait) only checks whether a column was manually
  hidden — it never verified the column exists at all in the current
  view's registry. Landing on any account without the balance columns
  registered (e.g. a P&L drill-down) still reported them "visible",
  crashing on an undefined array key. Added `columnActuallyVisible()`,
  used everywhere the old check was.

## 7. GitHub Actions workflow reliability

Two independent race conditions found and fixed, both from the same root
cause: a normal commit is often followed within seconds by
`reapply-fork-patches`' own bot commit, and neither workflow had any
concurrency control.

- `.github/workflows/reapply-fork-patches.yml` — added
  `concurrency: { group: reapply-fork-patches-${{ github.ref }},
  cancel-in-progress: false }`. Without this, two overlapping runs raced
  each other's checkout/commit/push and once fully blanked a file
  (`BillPaymentPoster.php` briefly became a single empty line on `main`).
- `.github/workflows/docker.yml` — same concurrency guard. Without it,
  two overlapping image builds could finish out of order, with the
  *older* commit's build publishing the `:edge` tag *after* the newer
  one — silently serving a stale image despite CI showing green.
- Both files must be committed directly to their real path
  (`.github/workflows/`), never through `.fork-patches` — GitHub blocks
  the bot's own token from modifying workflow files.

## 8. Multi-currency prerequisites for Vendors/Customers/Bills/Payments

`currency_code` was previously unreachable via the API on *any* Contact,
Bill, or BillPayment endpoint — even though the underlying `Save*` actions
already supported it internally. `fx_rate` was similarly unreachable, and
additionally was never persisted by the `Save*` actions even when
supplied. Both gaps are now closed — see `app/Services/BulkImport/README.md`
§"The FC logic" for the full reasoning and the production incident that
surfaced this.

- `app/Http/Requests/Api/V1/StoreContactRequest.php` — added
  `currency_code`
- `app/Http/Requests/Api/V1/UpdateContactRequest.php` — same
- `app/Http/Requests/Api/V1/StoreBillRequest.php` — added `currency_code`
  and `fx_rate`
- `app/Http/Requests/Api/V1/StoreBillPaymentRequest.php` — same
- `app/Actions/Purchasing/SaveBill.php` — persists an explicit `fx_rate`
  override onto the model (only if supplied — an update that omits it
  never blanks an already-locked rate)
- `app/Actions/Purchasing/SaveBillPayment.php` — same

## 9. Bulk Import tool

Standalone, repeatable CSV importer (Vendors/Customers so far) —
separate from the one-time QuickBooks migration wizard, calls the real
`Save*` actions directly so an imported record gets identical validation
and currency handling to one entered by hand.

**Full details, architecture, and the FC reasoning above: see
`app/Services/BulkImport/README.md`.** Files:

- `app/Services/BulkImport/ImporterDefinition.php` (new)
- `app/Services/BulkImport/BulkImportRegistry.php` (new)
- `app/Services/BulkImport/Importers/AbstractContactImporter.php` (new)
- `app/Services/BulkImport/Importers/VendorImporter.php` (new)
- `app/Services/BulkImport/Importers/CustomerImporter.php` (new)
- `resources/views/pages/tools/⚡bulk-import.blade.php` (new)
- `routes/web.php` — one route added (`tools.bulk-import`)

---

---

## Ongoing maintenance — the one thing the automation doesn't handle

`reapply-fork-patches` (see §7) removed the need to manually check for
conflicts before syncing — it's now safe to always just click "Sync
fork". But it *overwrites*, it doesn't *merge*: if upstream ever improves
one of the files listed in this doc, the automation will always restore
our version and silently discard upstream's change, forever, unless a
human notices and manually re-merges it into `.fork-patches`. This is
exactly what happened with `BillPaymentPoster.php` — upstream added real
new ledger-integrity methods, and our own automation blanked the file
mid-race, then restored our older, feature-incomplete version.

There's no automated check for this. Periodically (or if something in
one of these files starts behaving oddly after a sync), worth diffing
upstream's current version of that specific file against what's staged
in `.fork-patches`, to see if anything upstream added is worth pulling
in alongside our own changes.

## NAS-side infrastructure (not repo files)

Documented in full in the earlier session's `CHANGES.md` (image pinned to
this fork's `:edge` tag; `storage`/`data` converted from named Docker
volumes to bind mounts for host visibility; `chown 1000:1000` needed
after any fresh bind-mount creation; `pull_policy: always`, not
`missing`, since `:edge` is a floating tag). Not repeated here — nothing
in that area has changed since.
