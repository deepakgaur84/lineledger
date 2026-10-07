# LineLedger Fork — Patches Index

Fork: `github.com/deepakgaur84/lineledger`
Base: `github.com/lineledger/lineledger`

This is the master list of every change made in this fork, organized by
feature/fix, with the exact files touched. For the bulk importer
specifically, see its own `README-BULK-IMPORT.md` in
`app/Services/BulkImport/` — not duplicated here.

All application-code changes are staged in `.fork-patches/` (mirroring
real repo paths) and applied automatically by
`.github/workflows/reapply-fork-patches.yml` on every push, including
upstream syncs. The two workflow files themselves (see §8) are the
exception — GitHub blocks the automation's own token from touching
`.github/workflows/*`, so those are committed directly to their real path.

This is a hard rule, not a preference: `.fork-patches/` is meant to be a
complete, standalone record of every change this fork has ever made —
new features, fixes, schema changes, anything — so that re-deriving the
whole fork from a bare upstream clone plus this folder is always
possible, and so a future collision on any of it gets the 3-way-merge
protection in §7 rather than a raw git conflict. It briefly slipped:
several deliveries (Bill Payments/Receipts, then the whole depreciation-
methods feature — §10) went straight to their real paths only, so
`.fork-patches/` genuinely fell out of sync with what the app was
actually running. An audit against upstream (diffing every file that
differs from `upstream/main`, then checking each one has a
`.fork-patches/` counterpart) found the drift and backfilled it — worth
re-running that same audit occasionally to catch it early if it ever
happens again.

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
- `lineledger-backup.sh` — DSM Task Scheduler script that ties the rest of
  this section together on a schedule. Committed at the root of
  `.fork-patches/` (so it lands at the repo root, alongside this file, not
  nested into any app path — it's an operational script, not application
  code) rather than kept purely NAS-side, so it's versioned and can't be
  silently lost. Run via Control Panel > Task Scheduler > Create >
  Scheduled Task > User-defined script, not cron directly — gives a GUI
  for timing, and DSM emails its own stdout/stderr on failure,
  independent of LineLedger's own `SchedulerFailureAlert`. What it does,
  in order:
  1. Runs `backups:export-all` (blocking, so it's safe to immediately move
     the resulting files afterward — no queue-timing guesswork)
  2. Moves every finished ZIP off the app's own storage volume to a
     separate NAS share (different physical protection than the docker
     volume it started on), renamed from LineLedger's own
     `backups/<company_id>/<id>-<timestamp>.zip` to
     `<timestamp>-<company-slug>.zip` at the destination — the numeric
     backup id is dropped entirely, replaced by the company's slug
     (looked up directly from the `companies` table), and reordered so
     files sort chronologically by filename
  3. Cleans up the now-empty per-company subfolders left behind in the
     source directory (cosmetic — nothing depends on them existing)
  4. Prunes anything older than its retention window from the offsite
     folder only, never the source
  - **`COMPOSE_DIR`, `SOURCE_DIR`, and `DEST_DIR` at the top of the script
    are this NAS's own paths** — adjust all three to match wherever
    LineLedger's compose stack and the intended offsite share actually
    live on a different setup. `RETENTION_DAYS` is likewise a local
    choice, not a fixed requirement (currently 90, changed down from the
    365 originally used at launch).

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

Started from two independent race conditions, both from the same root
cause: a normal commit is often followed within seconds by
`reapply-fork-patches`' own bot commit, and neither workflow had any
concurrency control. Grew into several more fixes as each one surfaced a
further, previously-invisible gap — documented here roughly in the order
they were found, since each built on the last.

- `.github/workflows/reapply-fork-patches.yml` — added
  `concurrency: { group: reapply-fork-patches-${{ github.ref }},
  cancel-in-progress: false }`. Without this, two overlapping runs raced
  each other's checkout/commit/push and once fully blanked a file
  (`BillPaymentPoster.php` briefly became a single empty line on `main`).
- `.github/workflows/docker.yml` — same concurrency guard, same reasoning.
- **GitHub never triggers `docker.yml` for `reapply-fork-patches`' own
  commits.** A commit pushed using the default `GITHUB_TOKEN` doesn't fire
  other workflows' `on: push` — a deliberate anti-loop safeguard, but it
  meant every fix that only reached `main` via the bot's commit (rather
  than a direct human push) silently never got its own image build, and
  `:edge` stayed on whatever a later, unrelated human commit happened to
  build next. Fixed by adding a step to `reapply-fork-patches.yml` that
  explicitly triggers `docker.yml` via the GitHub API
  (`workflow_dispatch`) after a successful push — that trigger path isn't
  subject to the same restriction.
- **Queuing alone doesn't guarantee builds finish in commit order.**
  `cancel-in-progress: false` stops GitHub cancelling an *in-progress*
  run, but doesn't guarantee finish order once several pile up in the
  queue — confirmed the hard way: on a night with many rapid commits, an
  older commit's build sat queued for over an hour, then finally ran and
  pushed `:edge` a full hour *after* a newer commit's build had already
  published — silently overwriting the correct image with stale content.
  Fixed in `docker.yml` with an explicit freshness check right before
  publishing: re-fetch `main` and compare its current tip against the
  commit this build actually compiled; skip the push outright if `main`
  has moved on since. A hard guarantee independent of GitHub's scheduling
  behaviour, rather than an ordering assumption that turned out not to
  hold.
- **`git diff --quiet` (no args) is blind to brand-new files.** It only
  compares already-tracked paths, so a genuinely new file the copy step
  had just placed at a path that had never existed before was silently
  never committed — only updates to already-existing files ever worked.
  Every "Class ... not found" incident where a new importer/interface
  file existed in `.fork-patches` but not yet at its real path traced
  back to this. Fixed by staging everything first (`git add -A`) and
  diffing the *staged* index against `HEAD` instead, which correctly
  surfaces additions as well as modifications.
- **Auto-merge on collision, with a backup branch as the fallback.** If
  an upstream sync also touches a file we've patched, the reapply step
  now attempts a real 3-way merge (`git merge-file`) — base = the file's
  content at the commit where we last customized it, ours =
  `.fork-patches`, theirs = the incoming real-path content — before
  falling back to the old "our version always wins" behaviour. A clean
  merge (our change and theirs touched different parts of the file)
  combines both automatically, updating `.fork-patches` to match so the
  next sync doesn't re-collide with the same already-resolved change. A
  genuine textual overlap still falls back to keeping our version and
  failing the run loudly for manual review, exactly as before. Before
  attempting any merge, a `Backup-DD.MM.YYYY` branch is created from the
  current tip — auto-suffixed with the run id if one already exists for
  today — which the branch-protection ruleset (§ below) automatically
  locks against deletion and force-push, so there's always an exact,
  protected rollback point if an auto-merge produces something broken.
  New, untested-in-anger logic — worth watching the first real collisions
  closely rather than assuming it's correct from design review alone.
- Both `docker.yml` and `reapply-fork-patches.yml` must be committed
  directly to their real path (`.github/workflows/`), never through
  `.fork-patches` — GitHub blocks the bot's own token from modifying
  workflow files.

## 8. Multi-currency prerequisites for Vendors/Customers/Bills/Payments

`currency_code` was previously unreachable via the API on *any* Contact,
Bill, or BillPayment endpoint — even though the underlying `Save*` actions
already supported it internally. `fx_rate` was similarly unreachable, and
additionally was never persisted by the `Save*` actions even when
supplied. Both gaps are now closed — see
`app/Services/BulkImport/README-BULK-IMPORT.md` §"The FC logic" for the
full reasoning and the production incident that surfaced this.

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

Standalone, repeatable CSV importer — separate from the one-time
QuickBooks migration wizard, calls the real `Save*` actions directly so
an imported record gets identical validation and currency handling to
one entered by hand. Grown considerably since first built: now covers
both flat, one-row-one-record entities (Vendors, Customers, Item
Categories, Items, Fixed Assets) and multi-line documents (Bills,
Invoices, Vendor Credits, Credit Memos, Bill Payments, Receipts, Journal
Entries) via a second interface, `GroupedImporterDefinition`, added
specifically for the latter.

**Full details, architecture, the FC reasoning, and the grouped-importer
design: see `app/Services/BulkImport/README-BULK-IMPORT.md`.** Files:

- `app/Services/BulkImport/ImporterDefinition.php` (new) — flat, one-row
  entity types
- `app/Services/BulkImport/GroupedImporterDefinition.php` (new) —
  multi-line document types; deliberately NOT an extension of
  `ImporterDefinition` (see the file's own docblock for why that
  matters — a real bug was caught in production from an earlier version
  that did extend it)
- `app/Services/BulkImport/BulkImportRegistry.php` (new)
- `app/Services/BulkImport/Importers/AbstractContactImporter.php` (new)
- `app/Services/BulkImport/Importers/VendorImporter.php` (new)
- `app/Services/BulkImport/Importers/CustomerImporter.php` (new)
- `app/Services/BulkImport/Importers/ItemCategoryImporter.php` (new)
- `app/Services/BulkImport/Importers/ItemImporter.php` (new)
- `app/Services/BulkImport/Importers/BillImporter.php` (new)
- `app/Services/BulkImport/Importers/InvoiceImporter.php` (new)
- `app/Services/BulkImport/Importers/VendorCreditImporter.php` (new)
- `app/Services/BulkImport/Importers/CreditMemoImporter.php` (new)
- `app/Services/BulkImport/Importers/BillPaymentImporter.php` (new) —
  one row is one *application* to a bill, not one line item; the
  payment's own total is the sum of its rows' `application_amount`
  rather than a separate column
- `app/Services/BulkImport/Importers/CustomerReceiptImporter.php` (new)
  — the AR-side mirror of Bill Payments. One real, deliberate
  asymmetry confirmed against each one's own API request: Bill
  Payments requires the bill still be open; Receipts has no equivalent
  check on the invoice, mirrored exactly rather than "fixed"
- `app/Services/BulkImport/Importers/FixedAssetImporter.php` (new) —
  register-only (never posts to the ledger); blank fields fall back to
  the row's asset category exactly as the asset form does; a
  back-dated `in_service_date` with `auto_depreciate: yes` back-fills
  every month since, and the preview says exactly how many before
  committing
- `app/Services/BulkImport/Importers/JournalEntryImporter.php` (new) —
  one row is one line; the balance check (total debits = total
  credits) compares in cents, not decimal dollars, to avoid a false
  imbalance from ordinary floating-point rounding on a large file
- `resources/views/pages/tools/⚡bulk-import.blade.php` (new) — also
  links out to `/banking/import` for Cheques/Deposits/Transfers, which
  are deliberately NOT importers here (see the README for why the
  existing bank-statement importer is the better tool for those)
- `routes/web.php` — one route added (`tools.bulk-import`)

---

---

## 10. Fixed asset depreciation methods

Book depreciation was straight-line only. Assets can now use straight-line,
written-down value (declining balance / WDV), or an immediate 100%-on-
purchase write-off — chosen per asset, with the category system able to
default new assets onto WDV at a given rate.

- `app/Enums/DepreciationMethod.php` (new) — the three methods, their
  `label()`, and which of a useful life / an annual rate / a materiality
  limit each one actually uses. `MIN_RATE`/`MAX_RATE` (1–100%) and
  `DEFAULT_RATE` (20%, the one suggested whenever WDV is picked with no
  rate typed) live here as the single source both the form, the API and
  the importers read from, so they can't drift apart.
- `database/migrations/2026_09_28_100000_add_depreciation_method_to_assets.php`
  (new) — `depreciation_method` (defaults every existing asset to
  `straight_line`, so nothing already in production changes behaviour),
  `depreciation_rate`, `materiality_limit_cents`.
- `database/migrations/2026_09_28_110000_add_depreciation_defaults_to_asset_categories.php`
  (new) — the same `depreciation_method`/`depreciation_rate` pair on
  `asset_categories`, so a category can hand new assets a starting method
  and rate the same way it already hands them a default useful life.
- `app/Services/Assets/DepreciationSchedule.php` — was pure straight-line
  math; now dispatches on the asset's method. Declining balance runs in
  annual 12-month blocks (each block charges the rate on the book value
  at the START of that block, spread evenly across its months — this
  makes year-end book values match a standard annual reducing-balance
  calculation exactly, and is why WDV ignores the useful life by
  default). How WDV ends depends on what the asset has:
  - **A useful life given** — its final year is the tail: it overrides
    the normal charge and takes whatever balance is left, so the asset
    ends exactly when its life does, even if that's mid-year.
  - **No useful life** — the asset's materiality limit ends it instead:
    once a year's normal charge would leave a balance at or below the
    limit, that year takes everything. Defaults to 5% of cost
    (`Asset::defaultMaterialityCents()`) when none is set.
  - Immediate is a single row: the whole depreciable base in the
    in-service month.
  - A rate must be 1–100%; nothing outside that window produces a
    schedule at all. **100% WDV is deliberately not the same as
    Immediate** — investigated and confirmed with real numbers before
    building: a 100% annual rate on a *monthly* schedule spreads the
    base across the first twelve months (this class's own "annual-step"
    convention) or, under a plain rate-÷-12 convention, never finishes
    within a year at all. Only an unusual "effective-monthly" convention
    collapses it into month one — which is why Immediate exists as its
    own, third method rather than a WDV rate of 100.
- `app/Models/Asset.php` — `depreciationMethod()` (falls back to
  straight-line if the column is somehow absent),
  `hasDepreciationConfig()`/`hasDepreciationSchedule()` (replace the old
  flat "has a useful life ≥ 1" check — `isAutoDepreciable()` now asks
  each method what it actually needs), `materialityLimitCents()`, and the
  shared `defaultMaterialityCents()` the category form also calls so the
  same 5% math can't drift between the two places it's used.
- `app/Actions/Assets/SaveAsset.php` / `SaveAssetCategory.php` — a method
  only ever keeps the parameters it uses; anything the caller didn't send
  falls back to what the record already has, so an older API client that
  has never heard of methods can't silently reset one on update.
- `app/Http/Requests/Api/V1/{Store,Update}AssetRequest.php` and the
  `AssetCategory` equivalents, `AssetResource.php`,
  `AssetCategoryResource.php`, `resources/api/openapi.yaml` — the new
  fields validated (rate `Rule::requiredIf` declining balance, 1–100
  window, 3 decimals), returned, and documented.
- `resources/views/pages/assets/⚡form.blade.php` — a method picker
  drives which fields show (useful life; rate + optional life +
  materiality limit; or nothing, for immediate). The materiality field
  pre-fills at 5% of cost and keeps following the cost live until the
  user types their own limit — clearing the field hands it back to the
  default.
- `resources/views/pages/assets/⚡show.blade.php` — the depreciation
  card used to appear only when a useful life existed; now appears for
  any method that has what it needs, with a plain-language note on how
  that asset's schedule actually ends.
- `resources/views/pages/settings/lists/⚡asset-categories.blade.php` —
  a category's default method and rate, with the same "offer 20% unless
  something's already typed" behaviour as the asset form.
- `app/Services/BulkImport/Importers/FixedAssetImporter.php` (new) — one
  row, one asset, through the real `SaveAsset` action. Register-only,
  like entering an asset by hand: it never posts to the ledger (the
  asset's cost is expected to already be there, from whatever bought
  it). Blank fields fall back to the row's category exactly as the form
  does. `auto_depreciate: yes` on a back-dated `in_service_date` back-
  fills every month since — the preview says exactly how many, and warns
  above one month, so it's never a surprise; if the ledger already
  carries that depreciation, lock the period first.
- `app/Services/Migration/Importers/FixedAssetsImporter.php` (the
  QuickBooks-migration / Opening-Balances importer, shared code — see
  §9's own note on this pattern) — learned the same
  `depreciation_method`/`depreciation_rate` columns, sharing the exact
  same validation. A CSV with neither column still imports, as
  straight-line, so nothing already relying on the old template breaks.
- Full test coverage: `tests/Unit/Assets/DepreciationMethodScheduleTest.php`
  (the schedule math itself — every branch above, plus a sweep across
  cost/salvage/rate/life combinations asserting the schedule always
  totals the depreciable base exactly and never goes negative),
  `tests/Feature/Assets/DepreciationMethodGenerationTest.php` (drafts
  through the real generator), `AssetDepreciationMethodFormTest.php`,
  `AssetCategoryDepreciationDefaultsTest.php` (Livewire, both pages),
  `tests/Feature/Api/V1/AssetDepreciationMethodTest.php` +
  `AssetCategoryDepreciationDefaultsTest.php`,
  `tests/Feature/BulkImport/FixedAssetImporterTest.php`,
  `tests/Feature/Migration/FixedAssetsImporterMethodsTest.php`.

**A second mistake worth recording, from delivering this feature itself**:
it was originally handed over as two separate `.fork-patches` zips
(category defaults, then everything else), built minutes apart. Two of
the 15 core files — `DepreciationMethod.php` and `openapi.yaml` — were
genuinely present in both, at different states, and whichever commit
landed last silently won. The immediate cause was a git branch reset
between building the two zips, which wiped uncommitted edits to files
that already existed (new files survive that; edits to existing ones
don't) — but the deeper lesson is the one worth keeping: never split one
feature's `.fork-patches` delivery across multiple zips when any file
could plausibly appear in more than one. A single combined zip removes
the ambiguity entirely, rather than relying on remembering commit order.

**A mistake worth recording**: an early version of the WDV tail used a
flat "under $12/year" cutoff, invented before the actual rule was
specified. The real rule is either the useful-life tail or a materiality
limit (above) — the $12 version never shipped, but it's a reminder to
confirm a business rule before assuming a sensible-looking default is
the intended one.

## 11. Fixed asset reconciliation report, disposal link, and a dashboard insight fix

Three separate pieces, delivered together in one commit.

**Fixed Asset Reconciliation report** — compares the asset register
(the `assets` table plus actually-posted `AssetDepreciationEntry` rows,
not the theoretical schedule) against what the general ledger itself
says, at the start and end of a period. Built directly against a
reference layout from Xero's own equivalent report. Grouping defaults to
by account; by category is also offered, using each category's own
default accounts for the GL side (so an individual asset drifting onto a
different account than its category's default correctly shows up as a
difference, rather than being silently absorbed).

A real, confirmed finding from reading the code directly, not assumed:
Accumulated Depreciation accounts are seeded as Asset-type (not a
dedicated contra-asset type), and `normal_balance` is derived purely from
account *type* — so `ReportCalculator::balanceAsOf()` returns it as a
**negative** number even though it always carries a credit balance. The
report negates it before display to match both the Register side's own
positive convention and the reference layout.

A disposed asset drops out of the Register side once its disposal date
has passed — deliberately, since LineLedger's disposal is a register-only
status flag with no journal entry of its own, so a disposal with nothing
removed from the GL now correctly surfaces as a difference, which is
exactly the gap this report exists to catch.

- `resources/views/pages/reports/⚡fixed-asset-reconciliation.blade.php`
  (new) — the report itself; CSV and PDF export built in from the start,
  XLSX added in a follow-up once the nested opening/closing/per-group
  layout had been worked out properly rather than rushed
- `app/Services/Reporting/XlsxExporter.php` — `fixedAssetReconciliation()`
  method added. Uses `$this->text()`, not `Cell::fromValue()`, for the
  group labels specifically because they're user-controlled (an account
  or category name) — this file's own docs flag `Cell::fromValue()` on an
  unsanitized string as a real formula-injection risk (CWE-1236), and a
  category literally named `=cmd|...` is covered by its own test
- `app/Support/Reporting/RenderableReports.php` and `ReportCatalog.php` —
  registered under Accountant & Taxes, alongside Trial Balance
- `routes/web.php` — one route added (`reports.fixed-asset-reconciliation`)
- `tests/Feature/Reports/FixedAssetReconciliationTest.php` (new) — the
  Accum Dep sign negation is checked against the *raw* GL balance
  directly (confirming it is genuinely negative before the report flips
  it), not just the already-correct final output

**Disposal link** — a button on a *credit* line to a fixed-asset-type
account on the journal entry page (mirroring the existing "Create asset
record" button already there for *debit* lines), opening that asset
pre-filled as Disposed, dated to the journal entry. Only offered when
exactly one in-service asset unambiguously uses that account — with more
than one, there is no way to tell which one the entry is for, so nothing
is offered rather than guessing and disposing of the wrong asset.

- `resources/views/pages/journal/⚡show.blade.php` — new
  `disposableAssetIds()` computed method and the button; the first time
  this fork has customized this file
- `resources/views/pages/assets/⚡form.blade.php` — a `dispose_date`
  query-param prefill on the edit branch, arriving pre-formatted from the
  journal page's own link; never overwrites an asset that is already
  disposed, even from a stale link
- `tests/Feature/Journal/JournalEntryAssetDisposalLinkTest.php` (new) —
  covers the ambiguity rule (zero, one, and multiple matching assets),
  debit vs. credit direction, matching on either of an asset's two
  accounts (cost or accumulated depreciation), and the already-disposed
  guard

**Dashboard insight CTA fix** — `UnmatchedBankLinesDetector`'s "Open
reconcile" button pointed at `banking.reconcile`, the traditional
month-end bank-reconciliation screen. Confirmed directly: that page
never references `BankStatementLine` anywhere in it. The lines this
insight actually counts (`Unmatched`/`Suggested` statuses) are reviewed
and matched on `banking.review` instead — a real bug a user hit directly
(an insight claiming 15 pending lines, with the linked screen showing
none). Fixed to `banking.review`, relabeled "Review transactions", with
both references in the in-app docs page updated to match.

- `app/Services/Insights/Detectors/UnmatchedBankLinesDetector.php` —
  route and label fixed
- `resources/views/pages/docs/⚡insights.blade.php` — both mentions
  updated
- `tests/Feature/Insights/InsightDetectorsTest.php` — a CTA-route test
  added; the existing test for this detector only ever covered the
  counting logic, never where the button actually sent anyone, which is
  exactly why this went unnoticed until a user hit it directly

**Two mistakes worth recording from delivering this one**:
- `JournalEntryImporter::resolveContact()` shipped with the exact same
  PHPStan error as `FixedAssetImporter::accountId()` from earlier the
  same night (an `@param list<string> $errors` hint conflicting with
  `__()` being typed `string|array` by Larastan) — a lesson already
  learned once that night and not checked for the second time it was
  needed.
- `fixedAssetReconciliation()` landed in `XlsxExporter.php` with two
  blank lines separating it from the preceding method instead of one,
  tripping Pint's `class_attributes_separation` rule — caught by CI, not
  by review beforehand, since there was no PHP runtime available to run
  Pint directly against the change before delivering it.

## 12. Straight-line depreciation rate (a real, storable fact — not just a
    one-time conversion), its fallout across three more places, and the new
    Depreciation Schedule report

Found by a user directly testing the asset creation screen: entering a
depreciation rate for Straight-line converted it to a useful life and then
discarded the rate itself — nothing stored it, and it never appeared again.
In jurisdictions like NZ, Straight-line depreciation is commonly quoted and
tracked as a rate, not just a life in months, so this was a genuine loss of
a fact the user had typed in, not merely a UI inconvenience.

**Root cause, confirmed by reading the code directly**: `SaveAsset`'s own
normalization used `$method->usesRate()` — true only for declining balance
— to decide whether to keep a submitted `depreciation_rate`, discarding it
outright for every other method regardless of what was actually sent.

- `app/Enums/DepreciationMethod.php` — new `canHaveRate()`, distinct from
  `usesRate()` (which still means "required," not "ever storable"): true
  for straight_line and declining_balance, false for immediate.
- `app/Actions/Assets/SaveAsset.php` — uses `canHaveRate()` for
  persistence instead.
- `resources/views/pages/assets/⚡form.blade.php` — the existing Rate/
  Months toggle for straight-line now also writes into `depreciation_rate`
  (the same field declining balance already uses — no new column needed)
  alongside computing the useful life; validated but not required when
  given; an existing asset with a stored rate now opens in Rate mode
  showing it, rather than Months mode hiding it.

**The same stale `usesRate()` check turned out to be sitting in three more
places, found by searching for the same pattern once the first instance
was understood** — all genuinely the same bug via a different door, not a
scope expansion for its own sake:

- `app/Services/BulkImport/Importers/FixedAssetImporter.php` and
  `app/Services/Migration/Importers/FixedAssetsImporter.php` — both
  rejected a straight-line rate outright on import ("only applies to the
  declining_balance method"). Now accepted: a rate with no
  `useful_life_months` given directly on the row computes the life from
  it (100 ÷ years = rate, the same arithmetic the form's own Rate mode
  uses); given both, neither is recalculated from the other, and the row
  always wins over a category's own default life. A new category created
  from such a row inherits the same computed life as its own default, not
  null.
- `app/Actions/Assets/SaveAssetCategory.php` and
  `resources/views/pages/settings/lists/⚡asset-categories.blade.php` —
  the rate field was hidden entirely for straight-line on the category
  settings page, and the save action discarded it the same way `SaveAsset`
  once did. Fixed the same way, with one distinction: a category's rate is
  never *required* for either method (a category is only a default
  template; `SaveAssetCategory` already turns a blank declining-balance
  rate into the suggested 20% at the action level) — a mistake briefly
  introduced while fixing this (making it required for declining balance)
  and corrected before it went anywhere.

**Depreciation Schedule report** (new) — the fixed-asset register as a
proper, exportable report, built directly against a reference Xero
"Depreciation Schedule" export the user provided: one row per asset,
grouped by category, the same roll-forward shape (Opening + Purchases −
Disposals − Depreciation = Closing, checked by hand against several of the
reference's own rows) and per-category/grand totals. A column picker
(`flux:menu.checkbox` + `keep-open`, mirroring the pattern already
established on the invoice show page's own "Columns" dropdown, not a new
UI invention) lets the eleven columns asked for by default be supplemented
with six more (Category, Useful life, Cost, Opening/Closing Accum Dep,
Status).

Two of the reference report's own columns — Sale Price and Dep Recovered —
are deliberately not offered as options at all, not merely hidden by
default: LineLedger tracks no disposal-proceeds or gain/loss-on-disposal
data whatsoever (disposal today is a register-only status flag, per §11),
so those two could only ever show blank. "Disposals" here is the asset's
own net book value at its disposal date — the roll-forward's write-off
amount — not a sale price.

- `resources/views/pages/reports/⚡depreciation-schedule.blade.php` (new)
- `resources/views/pdf/reports/depreciation-schedule.blade.php` (new) —
  CSV and PDF only; no XLSX yet, consistent with §11's own export scope
- `routes/web.php`, `RenderableReports.php`, `ReportCatalog.php` — route
  and catalog registration

**Two mistakes caught before delivery, not after, while building the
report**:
- A stray leftover line in the table header's markup that would have
  rendered garbage on screen.
- Grand totals computed via `array_merge(...[])` when the register was
  empty — throws in modern PHP (`array_merge` requires at least one
  argument) rather than producing zero totals. Replaced with a plain
  accumulation loop; a dedicated test now exercises the empty-register
  case specifically.

**Also found and fixed in passing**: two genuinely stale, pre-existing
test assertions that would have failed against this work — one in
`FixedAssetsImporterMethodsTest.php` (two "bad" cases, a straight-line
rate and a blank-method-defaulting-to-straight-line rate, that were
correctly rejected before this fix and are now correctly accepted) and
one in `AssetCategoryDepreciationDefaultsTest.php` ("only shows the rate
field for declining balance," no longer true now that straight-line shows
it too). Both corrected alongside the fix that made them stale, not left
to fail in CI.

## 13. Bulk Import: Validate button stuck greyed out after choosing a CSV

Reported while testing the Fixed Assets importer: a CSV is chosen, the file
input shows its name, no error appears — and Validate stays greyed out.

**Cause.** The button was
`:disabled="! $upload" wire:loading.attr="disabled" wire:target="upload"`.
In Livewire 4.4, `wire:loading` with the `.attr` modifier *captures the
attribute's value when loading starts and puts that value back when loading
ends*. The button is already disabled at that moment (`$upload` is null until
the upload lands). The sequence that follows is, per Livewire's own request
pipeline (`effect` → `morph` → `morphed`, with server-dispatched events such as
`upload:finished` fired in the last phase): the server's morph enables the
button, and *then* `livewire-upload-finish` fires and the directive "restores"
`disabled`. Nothing re-renders afterwards, so it stays greyed out for good.
Livewire 4.3.4 simply removed the attribute at the end, which is why the page
worked when it was first built and tested.

Worth recording what this is *not*: it is not a 4.4.7 regression. The loading,
upload, morph and dispatch code is byte-identical between 4.4.5 and 4.4.7
(only the navigate plugin differs), and the behaviour change landed between
4.3.4 and 4.4.5 — i.e. with the 1.1.0 upstream sync, not the October bump.
It is also not the CSV's content: the button's state depends only on whether
the server holds the upload, never on what is in the file.

**Fix.** `resources/views/pages/tools/⚡bulk-import.blade.php` — the directive
is gone. It was redundant anyway: `:disabled="! $upload"` already covers the
whole of the first upload, because `$upload` is only set once the upload has
finished. The one thing it also covered, validating the *previous* file while a
replacement is still uploading, is a window of milliseconds for a CSV and
harmless (it validates the file the server actually holds). A search of every
view found this was the only place in the app combining the two.

- `tests/Feature/BulkImport/BulkImportPageTest.php` (new) — the first tests this
  page has had: a CSV is held server-side and validated for the Fixed Assets
  importer; switching importer drops the staged upload (by design, so a file is
  never validated as the wrong kind); and a **static guard** that scans every
  Blade view for a `wire:loading.attr` sharing a line with a server-rendered
  `disabled`. The client-side half of this bug cannot be reproduced in PHP, so
  the guard is the only automated protection against reintroducing it.

**How this was diagnosed, honestly.** From Livewire's JavaScript source
(v4.3.4, v4.4.5, v4.4.7), not by reproducing it in a browser. The ordering and
the version difference are verified against that source; the symptom matching
it exactly is strong evidence rather than a reproduction.

**Found while diagnosing, not fixed here — date formats.** All eight row-based
importers validate dates with Laravel's `date` rule and parse them with
`Carbon::parse`, both of which read a slash date as *US month/day*. So
`27/09/2026` fails as "not a valid date", and — worse — an ambiguous one like
`05/09/2026` is silently read as 9 May rather than 5 September. Excel on a
d/m/Y locale rewrites ISO dates into exactly this form when it re-saves a CSV,
so this is easy to hit from a spreadsheet. The importers' own column help says
"any unambiguous date works", which is not true for d/m/Y. Unfixed because it
is a cross-cutting design decision (strict ISO, a company date-format setting,
or accepting d/m/Y for certain jurisdictions), not a one-line change.

## 14. Asset form: the Rate box turning into the useful-life number

Reported straight after §12 shipped: on the asset form, with Straight-line
selected and the toggle on **Rate**, typing `67` left the box showing `18` —
the useful life in months (`1200 ÷ 67`, rounded) — while the "= 18 months
useful life" text beside it was correct. This is very likely what the original
"I enter a rate and it converts to useful life" complaint (§12) was describing
as well: §12 fixed a real persistence bug (the rate was never saved), but the
box visibly changing under the user's hands is a separate bug, and it survived
that fix.

**Cause.** The Months input and the Rate input are two branches of one `@if`,
rendered at the same position with no `wire:key`. Livewire's morph keys elements
only by `wire:key`/`wire:id`, so flipping the toggle *reuses the Months `<input>`*
and patches its attributes into the Rate input. But Livewire's `wire:model`
directive runs **once per element**, and its `x-model` `get()`/`set()` close over
the property name at that moment (and it registers no cleanup). The reused box
therefore keeps a live binding to `useful_life_months`. Typing 67 correctly set
`straight_line_rate` and computed the life (hence the right helper text), then
the leftover binding re-read `useful_life_months` (18) and wrote it into the box.
Nothing in the PHP can put `18` in `straight_line_rate`: the only server code
that writes it is the toggle's back-fill, which derives a rate *from* the life
(from 18 months it would give `66.667`, from 67 it would give `17.91`) — never
`18` itself. That is what pointed at the browser layer in the first place.

**Fix.** `resources/views/pages/assets/⚡form.blade.php` — every input in the
depreciation block that appears or disappears with the method or the life mode
now sits in its own `wire:key` wrapper (`life-rate`, `life-months`,
`life-optional`, `depreciation-rate`, `materiality-limit`, plus the toggle's own
`life-mode-toggle`). The wrappers are `display: contents`, so the grid layout is
unchanged, and the key is on an element we control rather than relying on which
inner element Flux forwards attributes to. Different keys mean the morph replaces
the subtree instead of patching it, so a fresh input gets a fresh binding. Every
`data-test` hook (31) and `wire:model` binding (26) is identical before and after.

- `tests/Feature/Assets/AssetDepreciationMethodFormTest.php` — two tests. One pins
  that each branch renders under its own key and never another's, across
  straight-line (both modes), declining balance and immediate. The other is the
  server half of the exact sequence reported (toggle to Rate, type 67): the rate
  stays `67`, the life is `18`, the stored `depreciation_rate` is `67`.

**Verification, honestly.** Diagnosed from Livewire's JavaScript source (v4.4.7)
and from the one coincidence that `18` is precisely the months value; not
reproduced in a browser. The DOM reuse itself cannot be exercised from PHP, so the
key test guards the fix rather than proving the cause. The category settings page
has the same *shape* of conditional input but no sibling that could be reused
for the common (non-Canadian) case, and was not changed.

## 15. Bulk Import: say it on the screen — dates, and the Fixed Assets rules

Prompted by a real file: a Fixed Assets CSV saved from Excel with dates like
`27/09/2026`, a `100%` row that also carried a rate, and a category name that
was really an account name. None of those was a bug; each was a rule the screen
had stated badly or buried, and the instruction was to make the screen say it —
"otherwise there will always be confusions" — with the date example `09-Apr-26`.

**What was actually wrong with the existing help.** Most of it was already
accurate: `category_name` says "an existing asset category's name",
`asset_account_code` says "the code … not the account name". But each was one
line in a list of eighteen columns. The date line was worse than buried — every
required date column said "Any unambiguous date works, e.g. 01-Apr-2026 or
2026-04-01", which is only true for dates that cannot be read two ways, and a
slash date can.

**Dates, verified rather than remembered** (PHP 8.3, running the same checks the
importers do — Laravel's `date` rule, then `Carbon::parse`): `09-Apr-26` is valid
and is 9 April 2026; `27/09/2026` is rejected; `05/09/2026` is silently read as
**9 May** and `09/04/2026` as **4 September**; dashes and dots (`09-04-2026`) are
day-first, so only slashes are month-first. That silent case is the dangerous
one — it is a *valid* date, so it passes validation — which is why text on the
screen alone was not enough.

- `app/Services/BulkImport/ImportDates.php` (new) — one source for the wording and
  the example, and for recognising date columns by name. Every required date
  column's help is now `ImportDates::required()`; `in_service_date`, which had no
  format hint at all, gained one.
- `app/Services/BulkImport/HasImportNotes.php` (new) — an optional interface so an
  importer can put a few plain sentences on the screen. Separate from
  `ImporterDefinition` so no existing importer changes or can break.
- `app/Services/BulkImport/SlashDateWarnings.php` (new) — after validation, flags
  each slash date PHP will read as a different day (or refuse), giving what PHP
  will read, what was probably meant, and the exact text to write instead. Silent
  when both readings are the same day or only the month-first one exists. Advisory
  only — nothing is blocked or rewritten.
- The eight importers with a date column (`Bill`, `BillPayment`, `CreditMemo`,
  `CustomerReceipt`, `FixedAsset`, `Invoice`, `JournalEntry`, `VendorCredit`) use
  the shared wording. `FixedAssetImporter` also implements `HasImportNotes`: four
  sentences, each restating a rule its own `resolve()` enforces (the category must
  exist and is not an account; codes not names, with the asset account always
  needed and the depreciation accounts only for auto-depreciate; the method/rate
  rules, including that a rate on a 100% row is rejected; what auto-depreciate
  drags in).
- `resources/views/pages/tools/⚡bulk-import.blade.php` — a "Before you upload"
  callout (the date guidance, including the Excel `dd-mmm-yy` tip, plus the
  importer's notes) shown only when there is something to say, and a "Check these
  dates" warning at the top of the preview.

**Tests.** `tests/Unit/BulkImport/SlashDateWarningsTest.php` includes a check that
every suggestion round-trips to the day that was meant, for every day-first date
from 1950 to 2100 (it exists because a two-digit year pivots at 70, so `15/06/1969`
must be suggested with four digits). `tests/Feature/BulkImport/ImporterHelpTextTest.php`
fails if any required date column omits the example, if "unambiguous" ever returns,
or if the example stops parsing to the day it claims. `BulkImportPageTest.php` gained
five page tests, including a file with one misread date, one rejected, and one fine.

**What was and wasn't executed.** This round had a real PHP runtime, which earlier
rounds did not: the date behaviour above, `SlashDateWarnings` (28 cases and the
110,000-date round-trip check), the quoting of every new `{{ }}` expression, and
`php -l` on every file were all run; so was Laravel Pint, at the exact version CI
uses (v1.32.1), over every PHP file touched. What could not be run is Pest and the
Livewire page, because there are no Composer dependencies in that environment — those
tests were written against the code but have not executed, and PHPStan was not run.
Running things earned its keep immediately: a test of mine asserted that over 100,000
dates produced a warning, and executing the loop showed it produces 53,340, so that
assertion would have failed in CI.

**Not done, on purpose.** Importing d/m/Y files by detecting the format per file.
The bank statement import's `DateFormatGuesser` could be reused, but a column whose
every day is 12 or under cannot be decided from the values, so it would still need a
confirmation step. Only Fixed Assets has importer notes; any other importer can opt in
by implementing `HasImportNotes`.

## 16. A blank Class / Location / Fund made saves fail with a 500

Reported on a real entry (JE-000015, six small purchases reclassed from an expense account
into Office Equipment): Post, and also Save draft, gave a blank "500 Server Error" modal.

**Found from the Laravel log**, not guessed. Three identical errors, one per attempt:
`SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '' for column 'class_id' at
row 1 … insert into journal_lines`, raised from `SaveJournalEntry.php`. The bound values on
the failing line were the giveaway: `contact_id`, `tax_code_id`, `location_id` and `fund_id`
were all `NULL`, but `class_id` was an empty string — the credit line, whose Class was left
on "—".

**Cause.** A blank select ("—") submits `''`. The forms pass it straight to the save action
as `$line['class_id'] ?? null`, and `??` catches a *missing* value, not an empty string.
Validation lets it through too (`nullable|integer` skips an empty string entirely), so `''`
reached the insert and MySQL in strict mode refused it for an integer column. The journal
form already knew the trick for one field — `tax_code_id` is built with `?: null`, which is
why the tax code left on "—" was fine and the class was not. Draft and Post share
`SaveJournalEntry`, which is why both died. It is an **upstream bug**: their current `main`
has the same code, unnoticed there because a SQLite test database quietly accepts `''` in an
integer column. This repo's suite runs on MySQL, so the tests below fail for real without
the fix. Nothing was half-saved: the whole save is one `DB::transaction`.

**Fix, in two layers.**

1. *The reported path, explicitly.* `app/Actions/Accounting/SaveJournalEntry.php` and
   `SaveRecurringJournalEntry.php` normalise their optional ids through an `optionalId()`
   helper copied from `SaveJournalEntryTemplate`, where upstream had already fixed this exact
   problem — in one of three sibling actions.
2. *Everything else, once.* `app/Providers/BlankTrackingIdsServiceProvider.php` (new, fork-owned)
   listens to `eloquent.saving: *` and turns an empty-string `class_id`, `location_id` or
   `fund_id` into `NULL` before any model is saved. It is registered first in
   `bootstrap/providers.php`, the only upstream file this layer touches.

**Why a listener instead of editing every action.** The first survey found thirteen more
actions with the same `$line['x'] ?? null` write (`SaveBill`, `SaveExpense`, `SaveVendorCredit`,
`SavePurchaseOrder`, `SaveInvoice`, `SaveCreditMemo`, `SaveEstimate`, `SaveSalesOrder`,
`SaveSalesReceipt`, `SaveInvoiceTemplate`, `SaveStockAdjustment`, `SaveDeposit`, `SaveCheque`).
A wider one found six more using `$data[...]` that the first regex could not match
(`SaveBudget`, `SaveDonation`, `SaveGrant`, `SaveEmployeePayrollProfile`, `SaveTimeEntry`,
`SaveOwnTimeEntry`), and twenty-three models carry these columns, including some no action grep
found at all (`PayRunLineEarning`, `RecurringDocumentLine`, `JournalEntryTemplateLine`). Editing
nineteen upstream files would have been nineteen future merge points and would still have left
every path the regex missed broken. Every one of those paths ends in saving a model, so the guard
sits there: complete today and for code not yet written, for one line of upstream churn.

**Verified against Laravel 13.34.0's source, because a global hook can break things quietly.**
`Model::save()` fires `saving` through `until` — halt mode — under the name
`eloquent.saving: <Class>`; a wildcard listener receives `[$model]` and its return value is passed
straight back; and in halt mode the first non-null return ends the chain and skips every listener
after it. So the listener returns nothing at all, and a test proves a listener registered after it
still runs.

**Deliberately narrow.** Only those three names (every migration declares them `foreignId`, 30 of
30, so a blank can never be a legitimate string there); only the exact empty string (a real id,
null, and every other attribute — a blank memo stays a blank memo — are untouched); and it never
blocks or fails a save.

**What it does not cover.** Writes that skip model events — `Model::query()->update(...)`,
`insert()`, `upsert()`, `DB::table(...)` — none of which the survey found writing these columns from
user input. And other optional ids (`tax_code_id`, `contact_id`, `item_id`, …): there is no evidence
they fail (the journal form already guards the tax code), so they were left alone rather than
widening a global hook on a hunch.

- `tests/Feature/Journal/JournalBlankOptionalIdsTest.php` — the exact failing entry through the form
  for both Save draft and Post, blank ids through the journal action, real ids kept, and recurring.
- `tests/Feature/Support/BlankTrackingIdsGuardTest.php` — a blank saved straight onto a model and on
  update; a scan of `app/Models` proving every model with these columns (23 today) is covered, so a
  new one is caught the day it is written; real ids and other attributes untouched; and the
  does-not-halt-other-listeners rule.

**Verification, honestly.** `php -l` and Laravel Pint (CI's exact version, v1.32.1) pass on every
touched file. The listener's own logic was executed under real PHP against stubbed models — scope,
real ids kept, and the return-null rule included. Laravel's semantics were read from its source, not
recalled. Pest and the Livewire form were not run (no Composer dependencies in the environment this
was written in), and MySQL's strict-mode rejection comes from the production log, not a local
reproduction.

## 17. Upstream sync that merges through conflicts, with a backup before every merge

**The problem.** "Sync fork" refused: *"This branch has conflicts that must be resolved"*,
two commits behind. The button cannot be told to resolve anything — it works only when git
can merge with no human, and it stops dead the moment upstream edits a line of a file we also
patch. The real conflict was one hunk in `app/Enums/Country.php`: upstream rewrote the lines of
a docblock to explain a BC/Yukon timezone fix, and we had appended a paragraph about the Global
jurisdiction directly beneath them. The two edits touch, so git calls it a conflict — but
nothing in it actually disagrees. Everything functional in that file merged cleanly.

**What the workflow does now** (`.github/workflows/reapply-fork-patches.yml`, committed by hand
like every workflow here — the Actions token cannot push changes to that directory). On every
run, in this order:

1. **Check for a merge.** Does upstream have commits we lack, or did the triggering push also
   change a file we patch? Read-only.
2. **Back up main — only if so, immediately before merging** — to `Backup-DD.MM.YYYY` (suffixed
   with the run id for a second one the same day). A run that merges nothing, such as a plain
   patch upload or a daily check with nothing new, makes none. If the backup fails every later
   step is skipped, so nothing is ever merged without one. (An earlier version of this change took
   one on every run; it was changed because that left a branch a day even when nothing happened.)
3. **Sync with upstream.** If we are behind, upstream is merged *here*. Triggers are every push
   to main, a daily check at 03:17 UTC so upstream fixes arrive on their own, and a manual run.
4. **Reapply patches**, commit, push, and dispatch the docker build and the tests — unchanged.

**Per-file policy when upstream and we both changed a file** (everything outside a conflicting
hunk always merges normally, so upstream's other changes to the file are never lost):

| Case | What happens | Flagged red? |
|---|---|---|
| A file we patch, merges cleanly | Both sides kept; `.fork-patches` updated to match | no |
| A file we patch, conflicting hunk is *only comments* and one side merely added lines | Keep both — upstream's wording plus our lines (or the reverse) | no |
| A file we patch, any other conflicting hunk | **Our side of that hunk only**; upstream's side of it is dropped | **yes** |
| A merged file that fails `php -l` | Falls back to our whole copy | **yes** |
| A file upstream deleted that we patch | Our copy kept | **yes** |
| A file we do **not** patch | Upstream's version (every intentional customization lives in `.fork-patches`) | **yes** |
| A workflow file | Ours kept; upstream's diff is written to the run summary to apply by hand | **yes** |

"Flagged" means the run ends red *after* the push — the app is already merged, deployed and
tested, so this is purely a notification that a human decision was made. The run's Summary page
lists every file with what happened and whether it needs a look.

**Two design decisions worth knowing about.**

*A true merge base.* The existing collision merge uses "the real file at the last commit that
touched our patch" as its base. That is wrong whenever the real file was *already patched* at that
moment (as `Country.php` was when its patch copy was restored after being deleted by mistake — see
Ongoing maintenance): the base then contains our own
changes, so the merge treats them as unchanged by us and can drop them. The sync step uses the real
`git merge-base` with upstream instead, takes *our* side from `.fork-patches` (the source of truth,
which can be ahead of the real file while a patch waits to be applied), and writes the result to both
the real path and `.fork-patches` — so the copy step that follows is a no-op for those files rather
than overwriting upstream's changes with a stale copy. (The old collision merge is untouched, and
still handles a human "Sync fork" push that did succeed.)

*Workflow files never sync.* The Actions token is refused outright if a push changes anything under
`.github/workflows/`, so a merge carrying such a change would fail every night. The sync step keeps
our copy of any workflow file, flags it, and prints upstream's diff in the run summary. If GitHub
still refuses a push for that reason, the commit step now stops immediately with an explanation
(retrying can never fix it) and main is left unchanged.

**What was run to check it.** The real workflow file was executed step by step against a local
origin, with the real upstream and your real fork: the actual conflict resolved to upstream's new
wording plus our Global paragraph (0 of our 57 added lines and 0 of upstream's 7 missing from
`Country.php`; 13 and 3 in `Company.php`), the result linted, the patch store matched the real files
byte for byte, and `source-map-js` arrived at 1.2.2. Nine further scenarios were built from synthetic
upstream histories rooted at the real merge base: a real code conflict, a non-patched file, an upstream
workflow edit, an upstream deletion, a merge that would not lint, nothing to sync (no backup is made), an ordinary
human patch upload (no backup, and a regression check on the pre-existing flow), a patched-file collision with
nothing to sync (backed up, because the copy step is about to merge), and a push rejected mid-run (the merge
commit survives the rebase). The backup is checked to be exactly main as it was before the merge. 45 assertions,
all passing. Two scenarios were initially wrong — one never produced a conflict
(git merged it cleanly, which is the workflow being right and my test being wrong), the other truncated its own
input — and were fixed in the test, not the workflow.

**What was not, and the known costs.**
- GitHub's server-side workflow-permission rule can't be exercised from outside GitHub. The design keeps the
  merge's tree free of workflow changes; whether GitHub also objects to workflow changes in the *history* being
  pushed is unverified. The failure mode is loud and harmless (above), and the fallback is a personal access token
  with the `workflow` scope.
- **Backup branches accumulate:** one per *merge* — an upstream sync, or a patched-file collision — not one per run,
  so a quiet week makes none. The ruleset deliberately blocks deleting them, so pruning means temporarily relaxing
  it under Settings → Rules.
- GitHub pauses scheduled workflows after 60 days with no activity on a repository.
- The first real run merged the two pending upstream commits, including the `Country.php` conflict, 16 seconds after the
  workflow was committed. That fixed the `source-map-js` advisory; the `security` check was still red on a separate
  `shell-quote` advisory, which §19 explains and fixes at the fork level.

## 18. Depreciation Schedule PDF: landscape, and a table that fits the page

**The problem.** The PDF ran off the right edge: the last column ("Closing Value") was cut to `CL` / `1,1…`, and asset names and
method labels wrapped onto four lines each. Eleven columns at the shared report layout's 11px type and 8px cell padding need about
1,200px; a portrait A4 page offers about 700. The old test only asserted that `exportPdf()` returned a file, so nothing noticed.

**The fix**, all in this report's own view (`pdf/reports/depreciation-schedule.blade.php`) — not the shared
`_layout.blade.php`, which is an upstream file used by about twenty reports:

- `@page { size: A4 landscape; }`. dompdf merges an `@page` rule from the view over the layout's, so the layout's margin is kept
  and only this report changes. Because every route (download, email attachment) renders this same view, they all follow.
- A tighter table: 9px type and 4px/5px padding (8px and 3px/4px past 12 columns), numbers never wrap, rows are not split across a
  page break, and the header repeats on each page.
- **Which columns may wrap is decided from the data, not column names.** A column whose longest cell is 14 characters or fewer is an
  id, a date or a number and never breaks; longer cells (name, method, category) wrap. This was added after a render showed a date
  splitting as `2026-09-` / `30`. Totals rows count, so a long "Total <category>" label makes its column wrappable.

**What was run.** dompdf 3.1.6 — the exact version pinned in `composer.lock`, with its dependencies fetched at the locked commits —
with barryvdh/laravel-dompdf's real default options, on your actual report data. The current view was rendered first and
reproduced your problem exactly (A4 portrait, text running to 599 pt on a 595 pt page). The fixed view then rendered at 842 x 595 pt
with text ending at 816 pt. Stress cases: all 17 columns, all 17 with very long names, 45 assets across two categories, and an empty
report. All landscape, none clipped, header on every page, zero mid-token date breaks; 45 assets drop from 4 pages to 2.

**What was not.** The three Pest tests added to `DepreciationScheduleTest.php` (page size read from the PDF's own `/MediaBox`; all 17
columns; the wrap rules) were **not run** — there is no Laravel app or database where this was built. Their assertion strings and the
`/MediaBox` regex were checked against the rendered output instead. Blade itself was not run either: a small purpose-built compiler
covering the 15 constructs these two templates use stood in for it. The first push to main will run the real tests.

**Known limits.** Seventeen columns render at 8px (about 6 pt): legible, but small. The page is A4 only, matching every other PDF in
the app (there is no per-company paper setting).

## 19. The `security` check: a `shell-quote` override

**The problem.** After the upstream sync fixed `source-map-js`, `npm audit --audit-level=high` still failed on a critical advisory in
`shell-quote` (versions 1.8.4 to 1.10.0, GHSA-pqg4-j6r4-53mv). The lockfile had 1.9.0, pulled in by `concurrently@10.0.5`, which pins
`shell-quote` to **exactly** 1.9.0. Fixed releases (1.11.0, 1.12.0) existed, but npm could not use them without breaking that pin, so its
only suggested fix was a breaking *downgrade* of `concurrently`. An earlier note here said the fix was upstream's to make; the cause is
an exact pin, which an npm `overrides` entry is the standard answer to.

**The fix.** `"overrides": { "shell-quote": "^1.11.0" }` in `package.json`, and `shell-quote`'s lockfile entry moved to 1.12.0 — three
lines in each file. A regenerated lockfile was rejected: it touched 26 packages, 25 of them unrelated platform binaries whose versions had
not changed (a difference between the npm that built it and the one used here). Instead the one entry was edited into the committed
lockfile, after checking the serializer reproduces that file byte for byte (4-space indent).

**What was run.** `npm ci` (which fails if the two files disagree, and is what CI and the Docker build run): succeeded.
`npm audit --audit-level=high`: 0 vulnerabilities, exit 0. `npm ls`: `shell-quote@1.12.0 overridden` under `concurrently`.
`concurrently` was run with it and executed two commands correctly. **Not run:** the Docker build and the vite build, and `composer dev`
on the NAS. `concurrently` is used only by that composer `dev` script, with fixed arguments, so real exposure was low; the cost of leaving
it was a permanently red check that would hide the next genuine finding.

**Upkeep.** `package.json` and `package-lock.json` are now patched files. Future upstream edits to them are 3-way merged by the sync
step (§17); a conflicting hunk keeps our side and is flagged. To retire the override, once `npm view concurrently dependencies` shows a
`shell-quote` at 1.11.0 or later: delete both files from `.fork-patches` and take upstream's copies.

## Ongoing maintenance — now partially automated, still worth watching

`reapply-fork-patches` (see §7) originally just *overwrote* on every
sync — if upstream improved a file we'd also patched, our version always
won and upstream's change was silently discarded, forever, unless a human
noticed and manually re-merged it. That's exactly what happened with
`BillPaymentPoster.php`: upstream added real new ledger-integrity
methods, our own automation blanked the file mid-race, then restored our
older, feature-incomplete version.

It now attempts a real 3-way merge first (`git merge-file`) whenever a
sync touches a file we've also patched, combining both changes
automatically when they don't genuinely overlap, and only falling back to
"our version wins, flagged for manual review" when there's a real
textual conflict it can't reconcile. A `Backup-DD.MM.YYYY` branch is
created immediately before any merge — an upstream sync, or a collision where a
push also changed a patched file — and only then (§17), protected against
deletion/force-push by the branch-protection ruleset, as a rollback point if an
auto-merge ever produces something broken. The upstream merge itself now happens
inside this workflow rather than via the "Sync fork" button — also §17.

This has since been exercised against a real upstream sync, not just
designed on paper: a sync landed a genuine conflict on three separate
patched files (`routes/web.php`, `app/Services/Reporting/
ReportCalculator.php`, `resources/css/app.css`) at once, and all three
auto-merged cleanly — upstream's changes and this fork's own both kept,
`.fork-patches` updated to match, nothing flagged for manual review.
Still worth a periodic sanity check regardless: diff upstream's current
version of a patched file against what's staged in `.fork-patches`, to
catch anything the automation's own judgement might have gotten wrong on
a messier conflict than that first real one happened to be.

**A separate, unrelated mistake worth recording — not the merge system's
fault, a plain human one**: `.fork-patches/app/Enums/Country.php` (the
Global-jurisdiction file, §1) was accidentally deleted outright, in a
commit meant to clean up an unrelated stray duplicate sitting under
`.github/.fork-patches/...` by mistake — the two paths differ only by
that one `.github/` segment, and the wrong one was deleted first. The
real-path file was never touched throughout (so nothing user-facing
broke), but `.fork-patches` lost its own tracked copy of a real,
load-bearing customization for about a day and a half before the gap was
caught and restored. The actual stray duplicate was identified and
deleted correctly on a second attempt, once the two paths were made
unmistakable (a direct link to the exact file, with its full breadcrumb
spelled out) rather than described in prose alone.

## NAS-side infrastructure (not repo files)

Documented in full in the earlier session's `CHANGES.md` (image pinned to
this fork's `:edge` tag; `storage`/`data` converted from named Docker
volumes to bind mounts for host visibility; `chown 1000:1000` needed
after any fresh bind-mount creation; `pull_policy: always`, not
`missing`, since `:edge` is a floating tag). Not repeated here — nothing
in that area has changed since.

**Backup/recovery, added since:** GitHub's own "Sync fork" button offers
a "Discard commits" option alongside the normal "Update branch" one —
easy to click by mistake, and it resets `main` to match upstream exactly,
discarding every commit this fork has that upstream doesn't (there's no
GitHub-native way to disable just that option while keeping `main`
normally writable — confirmed directly against GitHub's own branch
protection docs, which tie it to fully locking the branch instead, too
restrictive for how actively this fork is developed). Two independent
safety nets instead:
- A repo ruleset matching branches named `*backup*`/`*Backup*` blocks
  deletion and force-push on any branch following that naming pattern
  (created manually, e.g. `Backup-18.09.2026`, or automatically by
  `reapply-fork-patches` before an auto-merge attempt — see §7).
- **Gitea**, running as a Docker container on the NAS, mirrors this repo
  from GitHub and auto-syncs every 8 hours — a complete, independent,
  browsable copy that doesn't depend on GitHub at all. Chosen over the
  plain Synology Git Server package specifically because it's Docker-based
  (fits the existing homelab) and gives a full web GUI rather than a bare
  git-over-SSH server.
