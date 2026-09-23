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
Categories, Items) and multi-line documents (Bills, Invoices, Vendor
Credits, Credit Memos) via a second interface,
`GroupedImporterDefinition`, added specifically for the latter.

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
- `resources/views/pages/tools/⚡bulk-import.blade.php` (new) — also
  links out to `/banking/import` for Cheques/Deposits/Transfers, which
  are deliberately NOT importers here (see the README for why the
  existing bank-statement importer is the better tool for those)
- `routes/web.php` — one route added (`tools.bulk-import`)

---

---

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
created before any merge attempt, protected against deletion/force-push
by the branch-protection ruleset, as a rollback point if an auto-merge
ever produces something broken.

This is new, and has not yet been exercised against a real, messy
upstream collision — worth treating the first few times this actually
fires (a failed run, or a commit message mentioning "some files need
manual review") as things to check by hand rather than trust blindly,
until it's proven itself over a few real syncs. When it does fire clean,
still worth a periodic sanity check: diff upstream's current version of
a patched file against what's staged in `.fork-patches`, to catch
anything the automation's own judgement might have gotten wrong.

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
