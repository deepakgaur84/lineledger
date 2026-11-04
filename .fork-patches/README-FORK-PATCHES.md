# LineLedger Fork — Patches Index

Fork: `github.com/deepakgaur84/lineledger`
Base: `github.com/lineledger/lineledger`

This is the master list of every change made in this fork, organized by
feature, with the exact files touched. For the bulk importer
specifically, see its own `README-BULK-IMPORT.md` in
`app/Services/BulkImport/` — not duplicated here.

All application-code changes are staged in `.fork-patches/` (mirroring
real repo paths) and applied automatically by
`.github/workflows/reapply-fork-patches.yml` on every push, including
upstream syncs. The two workflow files themselves (see §6) are the
exception — GitHub blocks the automation's own token from touching
`.github/workflows/*`, so those are committed directly to their real path.

This is a hard rule, not a preference: `.fork-patches/` is meant to be a
complete, standalone record of every change this fork has ever made —
new features, schema changes, anything — so that re-deriving the
whole fork from a bare upstream clone plus this folder is always
possible, and so a future collision on any of it gets the 3-way-merge
protection in §5 rather than a raw git conflict. It briefly slipped:
several deliveries (Bill Payments/Receipts, then the whole depreciation-
methods feature — §8) went straight to their real paths only, so
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

## 3. Cursor on buttons

- `resources/css/app.css` — Tailwind v4's Preflight resets buttons to
  `cursor: default`; restored `cursor: pointer` globally rather than
  patching every component

## 4. Transaction report: source-currency columns + running/opening/closing balance

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

## 5. GitHub Actions workflow reliability

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

## 6. Multi-currency prerequisites for Vendors/Customers/Bills/Payments

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

## 7. Bulk Import tool

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

## 8. Fixed asset depreciation methods

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
  §7's own note on this pattern) — learned the same
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

## 9. Fixed asset reconciliation report, disposal link, and a dashboard insight fix

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

## 10. Straight-line depreciation rate (a real, storable fact — not just a
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
data whatsoever (disposal today is a register-only status flag, per §9),
so those two could only ever show blank. "Disposals" here is the asset's
own net book value at its disposal date — the roll-forward's write-off
amount — not a sale price.

- `resources/views/pages/reports/⚡depreciation-schedule.blade.php` (new)
- `resources/views/pdf/reports/depreciation-schedule.blade.php` (new) —
  CSV and PDF only; no XLSX yet, consistent with §9's own export scope
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

## 11. Bulk Import: say it on the screen — dates, and the Fixed Assets rules

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

## 12. Upstream sync that merges through conflicts, with a backup before every merge

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

## 13. Depreciation Schedule PDF: landscape, and a table that fits the page

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

## Ongoing maintenance — now partially automated, still worth watching

`reapply-fork-patches` (see §5) originally just *overwrote* on every
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
push also changed a patched file — and only then (§12), protected against
deletion/force-push by the branch-protection ruleset, as a rollback point if an
auto-merge ever produces something broken. The upstream merge itself now happens
inside this workflow rather than via the "Sync fork" button — also §12.

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
  `reapply-fork-patches` before an auto-merge attempt — see §5).
- **Gitea**, running as a Docker container on the NAS, mirrors this repo
  from GitHub and auto-syncs every 8 hours — a complete, independent,
  browsable copy that doesn't depend on GitHub at all. Chosen over the
  plain Synology Git Server package specifically because it's Docker-based
  (fits the existing homelab) and gives a full web GUI rather than a bare
  git-over-SSH server.

## 14. Date format (Settings → Appearance)

A per-user **Date format** setting controls how dates are *displayed*
everywhere (screens, PDFs, statements). Default is `01-Apr-26`.

- `app/Enums/DateFormat.php` — the seeded list (9 formats: `01-Apr-26`,
  `01-Apr-2026`, `2026-04-01`, `1 April 2026`, `Apr 1, 2026`, `01/04/2026`,
  `04/01/2026`, `4/1/2026`, `01.04.2026`). Add a format by adding a case +
  its `pattern()`/`description()`.
- `users.date_format` (migration `2026_10_13_000001`), cast to the enum on
  `User`, fillable, default `dd_mmm_yy`.
- `app/Support/DateDisplay.php` registers a Carbon macro, so views call
  `$date?->displayDate()`. No signed-in user (queued jobs, public pages) →
  default format. A PDF/email generated by a user uses *that user's* format.
  **English only:** when the document/app language is not English, `DateDisplay::render()`
  falls back to upstream's rule (`Locales::formatDate` with the jurisdiction's short format:
  ISO, or day-first for the UK), so French documents stay as upstream prints them.
  The pay stub is left exactly as upstream (fixed `M j, Y` / ISO) — its test asserts it.
- Views: every display `->format('n/j/Y' | 'M j, Y' | 'j M Y' | 'F j, Y')` and
  `->toFormattedDateString()` was replaced with `->displayDate()`;
  `StatementColumns` now uses `DateDisplay::format()`.
- **Deliberately unchanged:** ISO values in inputs/queries/filenames/API/CSV,
  month headings (`F Y`, `Y-m`), long day headings (`l, F j, Y`), date-times,
  and cheque printing (bank-style `n/j/Y`).
- Tests: `tests/Feature/Settings/AppearanceDateFormatTest.php`; statement and
  membership tests updated to the new default.
- **On upstream sync:** new upstream views that use a hard-coded date format
  won't follow the setting — re-run the replacement above on them.

## 15. Invoice designer (Settings → Lists → Form styles)

Form styles are now a small invoice designer: a **layout gallery** plus style
controls, applied to invoice PDFs everywhere (staff print, customer portal,
invoice emails — all go through `InvoicePdfRenderer`).

- **Layouts** (`app/Enums/InvoiceLayout.php`): Classic (the original view,
  untouched in look), Modern, Minimal, Banner, Ledger, Elegant. Classic renders
  `pdf.invoices.invoice`; the others render `pdf.invoices.designed` plus one
  stylesheet partial in `resources/views/pdf/invoices/templates/_<layout>.blade.php`.
  Add a layout = an enum case + a partial + a thumbnail branch in
  `resources/views/partials/invoice-layout-thumb.blade.php`.
- **Controls** (new `form_styles` columns, migration `2026_10_14_000001`): layout,
  accent colour, font (sans / serif / mono — dompdf-bundled DejaVu only, so no
  "?" for unusual characters), logo position + height, document title (e.g.
  TAX INVOICE), show ship-to, striped rows, footer message. Defaults reproduce the
  old look. Logo position applies to the designed layouts (Elegant always centres
  it); Classic keeps it left but honours title, ship-to, font, stripes and height.
- **`app/Support/Sales/InvoiceDesign.php`** resolves a style into render values
  (accent tint, readable text colours, long-title flag). Plain hex only —
  dompdf has no rgba().
- Designed layouts also print the company's **payment instructions** (from
  Invoice settings) as a "Payment details" block; Classic does not.
- **Page sections** (migration `2026_10_14_000002`, `form_styles.sections` JSON,
  `app/Enums/InvoiceSection.php`): below the header, the designed layouts print an
  ordered list of sections — Bill to / Ship to, Line items, Totals, Payment
  schedule, Payment details, Notes and footer — each in
  `resources/views/pdf/invoices/sections/_<name>.blade.php`, included in the order
  `InvoiceDesign::$sections` gives (visible ones only). The designer lists them
  with drag handles (Livewire `wire:sort`), up/down buttons and a visibility
  switch. The header is fixed. Classic has its own structure and ignores sections.
  A section = an enum case + a partial. `InvoiceSection::normalize()` repairs any
  stored value (unknown/repeated keys dropped, missing sections appended).
- **Preview**: the Form styles page is two panes — the list of styles on the left,
  and on the right a PDF of the selected style's *saved* version drawn on the
  company's latest invoice (or a built-in sample, `SampleInvoice`) —
  `PreviewFormStyleController`, route `lists.form-styles.preview`. The editor
  modal is also two panes: the form on the left and a **live preview of the unsaved
  settings** (layout, colours, section order…) on the right, via
  `lists.form-styles.preview-draft`, refreshing as settings change.
- **Framing**: the app sends `X-Frame-Options: DENY` everywhere (`SecurityHeaders`).
  The two preview routes set the request attribute `allow_same_origin_framing`,
  which makes the middleware send `SAMEORIGIN` / `frame-ancestors 'self'` /
  `object-src 'self'` for those responses only (Chrome's PDF viewer needs the
  last). `SecurityHeaders.php` is an upstream file with a small fork edit — keep it
  in `.fork-patches` and re-check it on upstream sync.
- Tests: `tests/Feature/Sales/InvoiceDesignerTest.php`.
- Rendered and eyeballed here with dompdf for all six layouts (and the colour,
  font, logo-position and long-title variants); the Pest suite could not be run
  in the build sandbox, so CI is the first real run.
- **On upstream sync:** the edited upstream files are `pdf/invoices/invoice.blade.php`,
  `InvoicePdfRenderer`, `FormStyle`, `SaveFormStyle`, `SecurityHeaders`, the form-styles settings page,
  the lists index description and `routes/web.php`; everything else is new.

## 16. Send attachments with an invoice email

Files attached to an invoice are **not** emailed by default. The invoice page's
"Send to client" dialog (the confirmation screen) shows a **Send attachments**
checkbox whenever the invoice has files; ticking it ticks every file, and each can
be unticked. A line under the options says exactly what will go out ("The email
includes the invoice PDF and 2 attached files."). The box resets after every send.

- `SendInvoiceToCustomer::handle(..., attachmentIds: [...])` → `InvoiceSharedNotification`
  (`$attachmentIds`). The notification reads the files from storage at delivery
  time and only attaches ids that belong to that invoice and company and still
  exist. Automated sends (recurring "post and email", reminders) never attach.
- Combined size is capped at `InvoiceSharedNotification::MAX_ATTACHMENT_BYTES`
  (15 MB); the dialog shows an error on the file list when it is exceeded.
- Tests: `tests/Feature/Sales/InvoiceSendAttachmentsTest.php`.
- New strings are not yet in `lang/fr.json` (the French catalog test reports them
  as incomplete, not failed); add them upstream-side when convenient.
- **On upstream sync:** edited upstream files are the invoice show page,
  `SendInvoiceToCustomer` and `InvoiceSharedNotification`.

## 17. Budget reports: period, PDF / print / email

- **Fiscal year naming:** a budget's `fiscal_year` is the calendar year its fiscal
  year *begins* in (unchanged). `Budget::periodLabel()` spells it out ("Apr 2027 –
  Mar 2028") and is shown in the budget form, the Budgets list and every budget
  picker (`pickerLabel()`), because the bare number is easy to misread for a
  non-January fiscal year.
- **Budget vs. Actual** moves its dates to the budget's own period when the
  default dates (fiscal year to date) don't overlap any of the budget's months, on
  load and when another budget is picked. Dates given in the URL or by a memorized
  view are never moved; a warning callout (with a "Show the budget's period" link)
  explains an all-zero Budget column instead.
- **Budget vs. Actual by Month** warns when the budget's period hasn't started (no
  actuals exist yet) instead of silently showing zeros.
- **PDF / Print / Email** for Budget vs. Actual, Budget vs. Actual by Month and
  Budget Overview: registered in `RenderableReports` (pdf only), `budgetId` and
  `metric` added to `ReportSettings::KEYS` so print/email reproduce the on-screen
  budget and metric. PDF views: `pdf/reports/budget-vs-actual.blade.php` and
  `pdf/reports/budget-grid.blade.php` (landscape, shared by the two 12-month grids).
- Tests: `tests/Feature/Budgeting/BudgetReportsPeriodAndExportTest.php`.
- **On upstream sync:** edited upstream files are the three budget report pages, the
  budget form and list, `Budget`, `RenderableReports` and `ReportSettings`.

## 18. Bank feeds: Actual Budget bridge (all banks) with Akahu as direct fallback

Bank feeds are now **pluggable providers**. The intended path is **Actual Budget → bridge → LineLedger**, so every bank
(NZ and elsewhere) arrives through one provider. Akahu stays as a direct fallback while Actual is being proven out.

**How it fits together**

- `actual` (the Actual Budget server) and `actual-bank-bridge` (our small Node service) run as two extra services in the
  same compose stack. LineLedger calls the bridge; the bridge talks to Actual with `@actual-app/api`; Actual talks to the banks.
- The bridge is **stateless**: LineLedger sends each company's Actual credentials with every request. Calls are queued so only one
  budget is open at a time. It runs `runBankSync` for the account, then reads the transactions and payee names.
- Sign convention is unchanged: money in is positive. Actual sub-transactions of a split (`is_child`) are skipped, and LineLedger skips
  any transaction with no `imported_id`.
- Registry order is Actual first, Akahu second (`BankFeedProviders`). One connection per company per provider.

**Setting it up (once)**

1. In `.env` next to the compose file set `ACTUAL_BRIDGE_TOKEN` (any long random string; the stack refuses to start without it).
   Optional: `ACTUAL_VERSION` (default `26.10.0`) and `ACTUAL_PORT` (default `5006`).
   On the LineLedger service set `ACTUAL_BRIDGE_URL=http://actual-bank-bridge:5007` and `ACTUAL_BRIDGE_TOKEN` to the same value
   (`config/bank_feeds.php`, `actual` block).
2. Redeploy with **Build images** ticked once (the bridge image is new). The GHCR package `lineledger-bank-bridge` may need to be
   made public the first time.
3. Open Actual (`http://<NAS>:5006`), create the server password and a budget, and link the banks there.
4. In LineLedger → **Bank feeds**, enter:
   - **Server address: `http://actual:5006`**
   - the Actual server password
   - the budget's **Sync ID** (Actual → Settings → Advanced)
   - the file password, only if the budget is end-to-end encrypted.

   **Use the container name `actual`, not your NAS address.** The bridge talks to Actual over the compose stack's own network, where
   the NAS address and published port are not what the bridge should be calling (and `localhost` would point at the bridge itself).
5. Pick which Actual accounts feed which LineLedger bank accounts, then sync.

**Version rule.** `bank-bridge/package.json` pins `@actual-app/api` to the same version as the Actual server image (currently
26.10.0). Change `ACTUAL_VERSION` and the bridge's pinned version together, or the API will refuse the server.

**Before linking a real account.** Sync one account first and compare Actual's `imported_id` with the Akahu transaction `_id`
already in LineLedger, so existing history is not imported twice.

**Known limits.** The merchant name can be lost on the way through Actual (payee name is used). Actual's own Akahu sync is
experimental upstream.

**Which provider for which company.** Actual's bank-sync tokens (Akahu, GoCardless, SimpleFIN, Pluggy) are stored once per
Actual *server*, not per budget file (the Akahu card shows a "global" tag and reads "Configured" in every budget). Every budget on
one Actual server can therefore see every account those tokens can see, and Actual's multi-user/OpenID mode separates logins and
budget access, not bank connections. So:
- Entities that share your Akahu login: use the Actual connection and link only the accounts that belong to each entity.
- An entity with its own Akahu login: use the direct **Akahu connection (fallback)** card in that company. Its tokens are stored
  per company. (Alternative: a second Actual server for that entity; the one bridge can serve it, because each company sends its
  own Actual address.)
- Do not link the same bank account through two providers or in two companies.
- One Actual connection per company: to try a different budget in the same company, use "Replace details and reload accounts"
  (links to the old budget's accounts stop syncing until the old Sync ID is entered again).

**Bank feeds page: unlinked accounts are hidden.** Once at least one feed account is linked to a ledger account, the Accounts list
shows only linked ones, with a note saying how many are hidden. Only "Reload accounts" (or saving connection details, which
reloads too) reveals the rest, so new accounts can be linked; there is deliberately no show/hide toggle. With nothing linked yet,
everything is shown. (Files: `⚡feeds.blade.php`, `BankFeedProvidersTest.php`.)

**Automatic sync schedule.** The `scheduler` container runs `bank-feeds:sync` for every linked, active feed account of every
company, whichever provider feeds it (Actual or Akahu). It runs every N hours: `BANK_FEEDS_SYNC_EVERY_HOURS` (default `4`, a whole number
from 1 to 24; anything else falls back to 4) counts from 00:00 UTC, so 4 runs at 00:00, 04:00, 08:00, 12:00, 16:00 and 20:00 UTC (01:00, 05:00,
09:00, 13:00, 17:00 and 21:00 in NZ summer time). A number that does not divide 24 evenly leaves a shorter last gap (5 runs at 0, 5, 10, 15, 20). The
old `BANK_FEEDS_SYNC_AT` setting is gone; delete it from `.env`, it is ignored. Unlinked feed accounts are skipped. Each run re-reads the last
`BANK_FEEDS_OVERLAP_DAYS` (default 7) days, so late changes are picked up and duplicates are skipped. Changing a value in `.env`
needs a stack redeploy (no image rebuild). Files: `app/Providers/BankFeedServiceProvider.php`, `config/bank_feeds.php`.

**Bridge retry.** Opening a budget (sign-in + download) is retried once, after 2 seconds, if Actual drops the connection
(`network-failure`, "other side closed", reset socket). The first download of a large budget can hit this; the retry
succeeds from the partly cached copy. Bank syncs are never retried.

**OpenID / multi-user in Actual: tried and dropped (29 Oct 2026).** Actual's OpenID mode (tried with Pocket ID as the provider) only
separates sign-ins and budget access. The bank-sync tokens stay server-wide, so it does not give each entity its own bank
connection, which was the reason for trying it. Actual stays on plain server-password login (which is what the bridge uses). Nothing in
this repo depends on an identity provider. If it is ever revisited, note that Actual's own dialog says enabling OpenID disables the
current password, and that turning it off again has had reported problems when a budget was created OpenID-only.

**Data model**

- `bank_feed_connections` keeps the legacy `app_token` / `user_token` columns (Akahu) and gains an encrypted `credentials` array
  (Actual) via migration `2026_10_29_000001_add_credentials_to_bank_feed_connections.php`.

**Files (all under `.fork-patches/`, mirroring the real path)**

- `app/Services/Banking/Feeds/Contracts/BankFeedProvider.php`, `DTO/FeedAccountInfo.php`, `BankFeedException.php`,
  `BankFeedProviders.php`, `Akahu/AkahuProvider.php`, `Akahu/AkahuException.php`, `ActualBudget/ActualBudgetProvider.php`
- `app/Services/Banking/Feeds/BankFeedSyncer.php`, `app/Models/BankFeedConnection.php`, `config/bank_feeds.php`
- `resources/views/pages/banking/⚡feeds.blade.php`
- `database/migrations/2026_10_29_000001_add_credentials_to_bank_feed_connections.php`
- `tests/Feature/Banking/BankFeedProvidersTest.php`, `tests/Feature/Banking/AkahuFeedSyncTest.php`
- `bank-bridge/` (`package.json`, `Dockerfile`, `.dockerignore`, `server.mjs`)
- `.github/workflows/docker.yml` — now has a second `bridge` job that builds `ghcr.io/<owner>/lineledger-bank-bridge`
  (commit workflow files directly to `.github/workflows/`, not via `.fork-patches`)
- `docker/docker-compose.yml` — reference copy; **the NAS compose** needs the services below.

**Compose additions (NAS)**

```yaml
  actual:
    image: actualbudget/actual-server:${ACTUAL_VERSION:-26.10.0}
    restart: always
    ports:
      - "${ACTUAL_PORT:-5006}:5006"
    volumes:
      - ./actual-data:/data

  actual-bank-bridge:
    image: ghcr.io/deepakgaur84/lineledger-bank-bridge:edge
    pull_policy: always
    restart: always
    environment:
      BRIDGE_TOKEN: ${ACTUAL_BRIDGE_TOKEN:?set ACTUAL_BRIDGE_TOKEN in .env}
    volumes:
      - bridge_data:/data
    depends_on:
      - actual
```

(`bridge_data` also needs declaring under top-level `volumes:`.)

**Upload rule (learned the hard way).** Put these files **only** in `.fork-patches/`. Files uploaded only to the real paths are
reverted by `reapply-fork-patches` on the next push, because the `.fork-patches` copy always wins on conflict.

**On upstream sync:** if upstream edits `BankFeedSyncer.php` or `BankFeedConnection.php`, rebuild from the new upstream file and
re-add the provider-based changes; the other files are fork-only.

## 19. Recurring schedules: "Generate now" catches up

Upstream's **Generate now** button on a recurring invoice/bill/journal schedule created one occurrence per click, so a schedule
started months ago needed one click per month. (The nightly `recurring:generate` run already caught up every missed occurrence,
up to 60 per run.) Now the button creates **every occurrence already due** in one go, each dated for its own run date:

- `RecurringDocumentGenerator::generateAllDue()` and `RecurringJournalEntryGenerator::generateAllDue()` throw like `generateOne()`
  when the schedule is paused or references something that no longer exists, then call the same `generateDue()` the nightly run
  uses (so automation modes, the "email only the most recent" rule and the 60-occurrence cap behave identically). When nothing is
  due yet they fall back to `generateOne()` and create the next occurrence early, as before.
- The two schedule pages (`pages/recurring/⚡show.blade.php`, `pages/recurring-journal/⚡show.blade.php`) call it from
  `generateNow`: exactly one result opens it for editing as before; several results stay on the schedule page with a
  "N drafts generated." toast, where they are listed.
- Docs page text updated (`pages/docs/⚡recurring.blade.php`).
- Files: `app/Services/Recurring/RecurringDocumentGenerator.php`, `app/Services/Recurring/RecurringJournalEntryGenerator.php`, the two
  `⚡show.blade.php` pages above, `⚡recurring.blade.php` (docs), `tests/Feature/Recurring/RecurringCatchUpTest.php`.
- **On upstream sync:** rebuild each patched file from the new upstream version and re-add only the lines described here.

## 20. Auto-post for recurring journals and monthly depreciation

Upstream always leaves generated recurring journal entries and monthly depreciation as **drafts** for a human to post. The fork
adds an opt-in so they can post themselves. Both default to **off** (drafts, unchanged behaviour).

- **Recurring journal entries — per schedule.** New `recurring_journal_entries.auto_post` boolean (migration
  `2026_10_30_000001_add_auto_post_to_recurring_journal_entries.php`, guarded with `Schema::hasColumn`). A "Post each entry
  automatically" switch on the memorized-journal form saves it; the schedule page shows "Posts automatically" / "Saved as drafts".
  `RecurringJournalEntryGenerator::generateStep()` posts the draft through `JournalPoster` right after creating it, so the
  nightly run, catch-up and **Generate now** all honour it.
- **Monthly depreciation — company-wide.** One draft covers every asset for the month, so the switch is per company: "Post monthly
  depreciation automatically" at the top of the Fixed assets list, stored in the company `settings` JSON
  (`settings.assets.auto_post_depreciation`, no new column, no change to `Company.php`). `DepreciationGenerator::generateDue()` posts
  each month's entry after the draft and its idempotency rows have committed, so a posting failure can never regenerate the month.
- **Locked period / unbalanced:** the entry is left as a draft (logged) and the run carries on, as invoice auto-post already does.
  Depreciation for months on or before the lock date is still skipped entirely, as before.
- Docs text updated on the Accounting and Fixed assets pages.
- Files: migration above, `app/Models/RecurringJournalEntry.php`, `app/Actions/Accounting/SaveRecurringJournalEntry.php`,
  `app/Services/Recurring/RecurringJournalEntryGenerator.php` and `RecurringDocumentGenerator.php` (also carry the §19 catch-up), `app/Services/Assets/DepreciationGenerator.php`,
  `pages/recurring-journal/⚡form.blade.php`, `pages/recurring-journal/⚡show.blade.php` (also §19), `pages/assets/⚡index.blade.php`,
  `pages/docs/⚡accounting.blade.php`, `pages/docs/⚡fixed-assets.blade.php`, `tests/Feature/Recurring/RecurringAutoPostTest.php`.
- **On upstream sync:** rebuild each patched file from the new upstream version and re-add only the lines described here.

## 21. Bank register: Source column

The Bank register now has a **Source** column telling you where each row came from:

- **Bank feed** — the row was created from a line of a bank feed sync (its statement line belongs to an import with a `bank_feed_account_id`).
- **Statement import** — created from a line of an uploaded statement file.
- **Manual** — entered by hand. If a manual entry was later matched to a feed or imported statement line, it stays "Manual" with a small
  "matched to bank feed" / "matched to statement" note, so you can tell what the bank confirmed.

`⚡register.blade.php` gains a `lineSources()` computed (one extra query per page, keyed by journal line id; no schema change) and the column
(header is not sortable). Files: `resources/views/pages/banking/⚡register.blade.php`, `tests/Feature/Banking/BankRegisterSourceTest.php`.
**On upstream sync:** rebuild the page from the new upstream version and re-add the `lineSources()` computed, the Source `<th>`/`<td>` and `colspan="8"`.

## 22. Faster CI (tests and lint)

The full Pest suite took ~40 minutes per push. Now:

- `.github/workflows/tests.yml` runs only the tests that matter for the push. `.github/scripts/select-tests.sh` takes the files changed since the
  previous commit and picks test files that mention them (class name, Livewire page alias, view name), plus the `tests/Feature/<Area>` folder
  that matches the changed path (skipped for folders over 60 files). Changed test files always run. Anything global (composer files,
  `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php`, `routes/`, `bootstrap/`) or a selection over 400 files runs everything; a push that
  touches no code runs no tests.
- **The full suite still runs**: nightly at 12:00 UTC (1am NZ), and from Actions → tests → Run workflow with "Run the whole test suite" ticked.
- xdebug coverage was switched off (nothing used it); JS unit tests run only when JS/package files changed (or on a full run).
- `.github/workflows/lint.yml`: Pint checks only the PHP files changed by the push (full Pint on "Run workflow" or when `pint.json` changes). PHPStan is
  unchanged (it analyses the whole project in about a minute and caught the baseline-count failures).
- Files: `.github/workflows/tests.yml`, `.github/workflows/lint.yml`, `.github/scripts/select-tests.sh` (these three are committed straight to `.github/`,
  not under `.fork-patches/`).
- Trade-off: a change can break a test the script did not pick; the nightly full run is the safety net.

## 23. For Review: pay invoices from a bank-feed deposit, and Equity categories

A deposit on For Review can now settle customer invoices, the same way an outflow can already pay bills.

- Picking a customer on a deposit offers the open invoice whose balance equals the amount ("Pay invoice INV-0001 (1,150.00)"), or an exact-sum set of
  that customer's open invoices. A "Receive payment…" button next to Accept opens a picker to split one deposit across several invoices (one may be
  paid in part), like "Pay bills…".
- Accept posts a customer receipt deposited straight into the bank account and applied to the chosen invoices, so the invoice is marked paid and the
  feed line is stamped as created. Invoices are re-checked at post time (still open, same customer, same currency as the bank account, amounts add up).
  A deposit with no invoice chosen still records as a plain journal entry.
- Equity accounts (capital introduced, drawings) are now offered in the Category picker on For Review and Import. The posting code already allowed
  them; only the list left them out.
- The For Review table keeps a minimum height so the Category and Customer lists are not clipped when only a row or two is waiting.
- Files: `app/Services/Banking/Import/OpenInvoiceMatcher.php`, `app/Actions/Banking/RecordStatementLine.php`,
  `resources/views/pages/banking/⚡review.blade.php`, `⚡import.blade.php`.
- Not covered: the Import wizard does not offer invoice payment (For Review does).

## 24. For Review: record suggested transfers in bulk

The "Suggested transfers" panel pairs an outflow on one bank / card account with an equal inflow on another.

- Each suggestion has a tick box. "Select all / none", "Record selected" and "Record all" (asks first) record many at once. Each pair posts on
  its own, so one that can no longer be recorded (a leg already categorized, a locked period) is skipped and reported; the rest still go through.
- Recording runs in batches of 8 per request, and the page asks for the next batch until none are left ("Recording… N left"), so a long list never
  holds one request open.
- The panel draws the first 10 suggestions ("Show more" adds 25 at a time); Select all and Record all still cover every suggestion.
- Files: `resources/views/pages/banking/⚡review.blade.php`, `tests/Feature/Banking/TransferSuggestionsTest.php`. (The matcher's speed-up is F7.)

## 25. For Review: pay an invoice as one part of a split

In the Split dialog on a deposit, each part can now be a **Category** or an **Invoice**. An invoice part lists the open invoices (the row's customer
when one is chosen, otherwise everyone's, in the bank account's currency); picking one pre-fills the amount with what is left, capped at the
invoice's balance, and a part may pay an invoice in full or in part.
On save, each invoice part becomes a posted customer receipt into Undeposited Funds applied to that invoice, and one deposit into the bank banks
those receipts together with the category parts. The bank therefore gets a single leg for the whole amount and the feed line ties to it, as for any split.
Refused with a message: an invoice part on money going out, on a foreign-currency bank account (its deposits cannot batch receipts), an invoice
that is no longer open or is paid beyond its balance. Nothing is left behind on a refusal.
Files: `app/Actions/Banking/SplitStatementLine.php`, `app/Services/Banking/Import/OpenInvoiceMatcher.php` (`assertPartsPayable`),
`resources/views/pages/banking/⚡review.blade.php`, `tests/Feature/Banking/SplitInvoicePaymentTest.php`.

## 26. For Review: pay a bill as one part of a split

The Split dialog on a payment (money going out) now also offers **Bill** per part, the payables twin of §25. Pick the vendor on the row first;
a bill part lists that vendor's open bills, pre-fills the amount (what is left of the line, up to the bill's balance) and may pay in full or in part.
On save, the bills are paid by one bill payment posted from Undeposited Funds (a home-currency clearing account), and the expense that carries the
bank leg for the whole amount debits Undeposited Funds for the same sum — so that account nets to nothing and the bank still gets one leg the feed
line ties to. Refused with a message: no vendor chosen, a foreign-currency bank account, a bill of another vendor or no longer open, an overpayment,
or a bill part on money coming in; nothing is left behind.
Files: `app/Actions/Banking/SplitStatementLine.php`, `app/Services/Banking/Import/OpenBillMatcher.php` (`assertPayableSet(..., $mustEqualTotal)`),
`resources/views/pages/banking/⚡review.blade.php`, `tests/Feature/Banking/SplitBillPaymentTest.php`.

## Fixes

Bugs in **upstream's own code** that this fork fixed. Fixes to the fork's own features are not listed here (they are part of the feature's section).
Each entry says what upstream got wrong, what the fork changed, and which files. When upstream fixes the same thing, the fork's copy can be dropped.

### F1. Employee Reimbursements Payable account missing

Upstream's `ChartTemplateBuilder` only seeds this control account when Employees is on *at company creation*. Turning Employees on later left the
account missing for good, which broke posting a reimbursement bill. The fork backfills it (and promotes a manually created stand-in account in
place instead of duplicating it), the same way upstream's `EnsureInventoryAccounts` does for inventory.
Files: `app/Actions/Payroll/EnsureEmployeeReimbursementAccount.php` (new), `app/Models/Company.php` (`usesEmployees()`),
`resources/views/pages/companies/⚡edit.blade.php`.

### F2. Foreign-currency data lost on a bill payment and on a reversal

Two posting bugs, found by investigating real data and confirmed against upstream's code.
- `BillPaymentPoster`: the bank (paid-from) line was the only line in the method without the `Currency::lineMemo(...)` spread, so a foreign-currency
  bill payment always recorded the bank side's foreign amount as zero. (The fork's copy of this file now matches upstream's.)
- `JournalPoster` (void / reversal): the reversal line copied the home debit and credit but not `currency_code`, `fx_rate` or the foreign
  debit and credit, so voiding any foreign-currency transaction silently dropped its foreign data. The fork mirrors the debit/credit swap on the
  foreign side.
Files: `app/Services/Posting/BillPaymentPoster.php`, `app/Services/Posting/JournalPoster.php`.

### F3. A blank Class / Location / Fund made saves fail with a 500

A select left on "—" submits `''`. Upstream passes it on as `$line['class_id'] ?? null` (`??` only catches a missing value), and validation
(`nullable|integer`) lets an empty string through, so MySQL in strict mode refused it for an integer column. Post and Save draft on a journal entry
both ended in a blank 500. Upstream's tests missed it because SQLite accepts `''` in an integer column. One of upstream's three sibling actions
(`SaveJournalEntryTemplate`) had already fixed it.
The fork fixes it in two layers: `SaveJournalEntry` and `SaveRecurringJournalEntry` normalise optional ids through an `optionalId()` helper, and a
small listener turns an empty-string `class_id`, `location_id` or `fund_id` into `NULL` on every model save (about 20 other save paths had the same
pattern). The listener never blocks a save and touches only those three columns.
Files: `app/Actions/Accounting/SaveJournalEntry.php`, `app/Actions/Accounting/SaveRecurringJournalEntry.php`,
`app/Providers/BlankTrackingIdsServiceProvider.php` (new, registered first in `bootstrap/providers.php`),
`tests/Feature/Journal/JournalBlankOptionalIdsTest.php`, `tests/Feature/Support/BlankTrackingIdsGuardTest.php`.

### F4. `npm audit` failing on `shell-quote`

`concurrently` pins `shell-quote` to exactly 1.9.0, which has a critical advisory (GHSA-pqg4-j6r4-53mv); npm's only suggestion was a breaking
downgrade of `concurrently`. The fork adds `"overrides": { "shell-quote": "^1.11.0" }` to `package.json` and moves the lockfile entry to 1.12.0.
To retire it, once `npm view concurrently dependencies` shows `shell-quote` at 1.11.0 or later, take upstream's copies of both files.
Files: `package.json`, `package-lock.json`.

### F5. Print on the Transactions drill-down page returned 404

The Transactions page (reached by drilling down from a report such as the balance sheet) has a Print button, but `reports.transactions` is not in
the report catalog and upstream's `PrintReportController` refuses any report that is not in the catalog. Download PDF worked; Print answered 404.
The fork lets the controller print reports on a short drill-only list (`DRILL_ONLY_REPORTS`, currently just this one) without the catalog check.
Files: `app/Http/Controllers/Reports/PrintReportController.php`, `tests/Feature/Reporting/ReportPrintDrillTest.php`.

### F6. Half-typed amount in the For Review split dialog returned a 500

The dialog turned every amount into a number with `Money::fromString` on each keystroke and on save, which throws on anything not yet complete ("6.",
"-", a calculator entry such as 1050+52.50), so the page answered 500. The fork uses `Money::tryFromString` (as the Pay bills and Receive payment
pickers do) and shows a message for an unreadable amount on save.
Files: `resources/views/pages/banking/⚡review.blade.php`, `tests/Feature/Banking/SplitAmountInputTest.php`.

### F7. Suggested transfers made the whole For Review page slow

Upstream's `TransferPairMatcher` loaded every waiting line and compared each outflow with every inflow, and it ran on every render of the page,
so with a long queue every click took many seconds (3,000 lines: about 21 s). The fork reads four columns, groups inflows by amount and builds
models only for the pairs it keeps (about 0.14 s, identical pairs), and draws only the first 10 suggestions.
Files: `app/Services/Banking/TransferPairMatcher.php`, `resources/views/pages/banking/⚡review.blade.php`,
`tests/Feature/Banking/TransferSuggestionsTest.php`. (The bulk Record selected / Record all buttons are a fork feature: §24.)

### F8. Composer security audit failed on Symfony advisories

Several Symfony components (http-foundation, http-kernel, routing, serializer, yaml, filesystem) were hit by advisories published on 2026-11-04, so the
`security` workflow's `composer audit` failed on every push. The fork updates the Symfony packages in `composer.lock` to the patched 8.1.10/8.1.11
releases (no `composer.json` change); `composer audit` is then clean. Drop the lock copy once upstream's lockfile is past those versions.
Files: `composer.lock`.

### F9. Dashboard "N bank lines are waiting" insight kept its count after the lines were cleared

The daily insight is stored once a day, so clearing For Review did not change the card until the next morning's job. For this one insight the card
now checks the live count: it disappears once fewer than 10 lines wait and shows the current numbers if the count moved. Nothing is saved.
Files: `resources/views/components/⚡daily-insight.blade.php`.
