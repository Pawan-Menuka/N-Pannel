# Implementation plan and status

The approved plan is below the status table and was approved on 2026-11-16. Update the table after every phase. A fresh session should resume from this file alone.

## Status

| # | Phase | Status | Branch | Notes |
|---|---|---|---|---|
| IS-0 | Environment, scaffold, tooling, CI | done (PR #1 merged) | `feature/is-0-scaffold` | See IS-0 log |
| IS-1 | Data model | done locally; CI runs on the PR | `feature/is-1-data-model` | See IS-1 log |
| IS-2 | Admin panel, roles, MFA, audit | not started | | |
| IS-3 | Products and CSV import | not started | | |
| IS-4 | Stock core | not started | | |
| IS-5 | Suppliers and goods received | not started | | parallel with IS-6 |
| IS-6 | Customers | not started | | parallel with IS-5 |
| IS-7 | Order core, payments | not started | | |
| IS-8 | Counter sales | not started | | |
| IS-9 | Quotes | not started | | |
| IS-10 | Fulfilment, order screen | not started | | |
| IS-11 | Invoices and receipts | not started | | |
| IS-12 | Returns and credit notes | not started | | |
| IS-13 | Dashboard and reports | not started | | |
| IS-14 | Hardening and staging deploy | not started | | |

## How work is done

Claude orchestrates and Codex implements. Codex's sandbox **cannot run Herd PHP, Composer, npm or Docker** on this machine, so the orchestrator runs every machine-level command: package installs, migrations, Pint, Larastan, Pest and Docker. Codex only edits files. Failures go back to Codex with the exact output.

## IS-1 log

**Built (by Codex, verified 17 Nov 2026):**
- Migrations, in dependency order:
  - users (staff columns)
  - locations and districts
  - catalog: categories, products, variants
  - stock: levels, movements, stocktakes
  - suppliers and goods receipts
  - customers
  - orders, lines, events, payments, quote snapshots
  - documents: sequences, invoices, sales returns, credit notes
- Package migrations: the settings and activity_log migrations switched to `jsonb` and given `down()`.
- Enums: 20, each with a label. `DocumentKind::prefix()` gives INV / RCP / CRN / GRN.
- Models: 24, with casts, relations, attribute defaults that match the database, translatable fields (District, Category, Product), and activity log with tokens and MFA secrets excluded.
- Helpers: `Order::balanceCents()`, `Order::activeInvoice()`, `Variant::displayName()`, `Location::default()`, `Customer::walkIn()`.
- Database rules:
  - only one default location
  - only one walk-in customer
  - only one issued invoice per order
  - `token_hash` unique, nulls allowed
  - `stock_levels.qty` signed
  - a category with products can't be deleted
- Settings: Business, Tax (`vat_rate_bp`), Documents, Quotes and Bank.
- Seeders, all idempotent (`firstOrCreate`): location, districts, categories (2 of 5 active) and the walk-in customer.
  - **Admin:** from `config/seed.php`. Re-running never resets the password or re-activates the account.
  - **Local sample catalog:** 4 products and 8 variants, with stock levels at 0 and no movements.

**Checks:**
- `migrate:reset` leaves only `migrations`.
- `migrate --seed` followed by `db:seed` gives stable counts: 1 location, 25 districts, 2/5 categories active, 1 walk-in customer, 1 user, 8 variants.
- `composer lint` passed. `composer analyse` found 0 errors. `composer test` passed: 103 tests, 338 assertions.

**Review:** a subagent review found 8 defects and 2 weak tests. All were fixed in a second Codex round:
- seeders overwrote values staff had changed
- missing partial unique indexes
- factories broke on a null customer and on quote revisions
- `token_hash` had no index
- the jsonb and constraint tests were too weak

## IS-0 log

**Done:**
- Laravel 13.42.0 skeleton.
- `config.platform.php` = 8.4.27 and `require.php` = `^8.4`.
- Packages: Filament 5.11.4, the Filament settings plugin 5.11.4, spatie/laravel-translatable 6.15.0, spatie/laravel-activitylog 5.1.5, spatie/laravel-settings 3.10.2 and propaganistas/laravel-phone 6.1.0.
- Dev packages: Pest 5.3.6 with the Laravel plugin 5.0.1, PHPUnit 13.4.7, Larastan 3.15.0, Pint 1.32.3 and Boost 2.10.4. Boost installed guidelines, skills and MCP for Claude Code, Codex and Cursor.
- Front end: Vite 8 and Tailwind 4. `npm run build` passes.
- Config: `compose.yaml` (Postgres 17, with `npannel_test` created by an init script), `phpunit.xml` pointing at Postgres, `.env` / `.env.example`, timezone Asia/Colombo, Composer scripts `lint` / `analyse` / `test` / `format`, and `.gitignore`.
- Tests: Pest's `tests/Pest.php`, a `/up` health test, and the Arch suite (Domain stays free of Illuminate, no debug calls, Enums are enums).
- `.github/workflows/ci.yml`, project rules prepended to `CLAUDE.md` and `AGENTS.md`, and `docs/BLUEPRINT.md`.

**Checks:**
- Passed: Pint, Larastan level 6 (0 errors) and the Arch suite (3 tests).
- Also passed (16 Nov 2026): `docker compose up -d` (Postgres 17 healthy, both databases present), `php artisan migrate`, `composer lint`, `composer analyse` (0 errors) and `composer test` (4 tests, 5 assertions).
- CI hasn't run yet. It runs on the first PR into `Develop`.

**Deviations from the plan:**
- Pest needed PHPUnit 13, so the skeleton's `phpunit/phpunit ^12.5` was raised to `^13.2`.
- The platform pin is 8.4.27 rather than 8.4.0, because PHPUnit 13 needs PHP 8.4.1 or newer. Confirm the production PHP patch version in IS-14.

---

# N-Pannel: Inventory and Sales build plan (first slice)

## Context

The client wants **inventory and sales built first**, before the public website. `D:\GitHub\N-Pannel` is empty apart from a README, on branch `Develop`. The spec is `BLUEPRINT.md` revision 3 (`C:/Users/Asus/Documents/Codex/2026-09-30/https-www-sivilima-lk-https-www/outputs/`). In the blueprint, these features sit late: phases 4, 9–11, 18, 19 and 21. This plan pulls them forward into a standalone back office. It keeps the blueprint's stack, data model and business rules, so the website (Release 1) can be built on top later without rework.

**What you decided on 16 Nov:**
- **Inventory:** stock plus receiving. That means stock levels, adjustments, history, stocktake, low stock, a supplier list, goods-received notes with cost price, and **no purchase orders**.
- **Sales:** counter sales and payments, quotes, invoices and receipts, and returns.
- **Locations:** not sure yet. The build is for one location, but the data model is ready for more.

**This slice does not include:** the public website, the estimator, website enquiries, follow-ups, SMS, the CRM reports beyond the basics in phase IS-12, purchase orders, supplier balances, the point-of-sale till hardware (barcode scanner, cash drawer, receipt printer) and accounting.

## Stack, from the blueprint

**Unchanged from the blueprint:**
- Laravel 13, with Filament 5 as the whole user interface (admin at `/admin`), Livewire 4 and PostgreSQL 17.
- Pest 5, Larastan level 6, Pint, `spatie/laravel-activitylog`, `spatie/laravel-settings`, `spatie/laravel-translatable`, `propaganistas/laravel-phone` and Laravel Boost.
- Package versions are re-checked and pinned in IS-0. The blueprint's list is from 2 October.

**Changed for this slice:**
- **Repository:** the base branch is `Develop`, which already exists. The blueprint said `dev`. Each phase gets a feature branch with a PR into `Develop`, and **you merge**.
- **Admin language:** English only. Product names are still stored as translatable `jsonb`, with English required and Sinhala/Tamil optional, so the website can reuse them.
- **Printed documents:** quotes, invoices, receipts and credit notes print in **English** in this slice. Sinhala and Tamil versions come with the website.
- **Stock data:**
  - Stock lives in `stock_levels (variant_id, location_id, qty)`, with one seeded location called "Main", instead of `variants.stock_qty`. That is the "ready for locations" part. The location stays hidden in the user interface while there is only one.
  - The blueprint's conditional decrement still applies, just on this table: `StockLevel::where(variant, location)->where('qty','>=',$n)->decrement('qty',$n)`.
  - A variant has a `track_stock` flag. Untracked items, such as made-to-order items, are sold without a stock check.
- **Hosting:** deployment to Laravel Cloud moves to the last phase. The blueprint did it in phase 0. Everything before that runs locally.

## Business rules (all of them go in `app/Domain` or `app/Actions`, never in Filament resources)

- **Money:** integer LKR cents everywhere. `round()` happens only in the domain classes that are tested.
- **Stock:** every change writes a `stock_movements` row with the variant, location, change in quantity, quantity after, reason, a reference to its source (order, goods received, return, adjustment or stocktake), the user and the time.
  - Reasons: `goods_received`, `goods_received_void`, `counter_sale`, `quote_accepted`, `order_cancelled`, `return_restock`, `manual_adjust`, `stocktake`, `import`.
  - Overselling is refused unless staff override it with a reason. The override is logged. This is blueprint 3.8.
- **Receiving:** a goods-received note (`GRN-2026-00001`) is drafted, then posted. Posting increases stock and sets the variant's `last_cost_cents`. A posted note can't be edited, only voided. Voiding reverses the stock and is refused if the stock has already gone below what was received, unless an admin overrides it.
- **Orders:** these use the state machine from blueprint 3.7.
  - Quote: `quoted → confirmed`, with stock reduced, or `quoted → cancelled` or `quoted → expired`.
  - Counter sale: `(none) → confirmed`.
  - From `confirmed`, an order goes to `processing`, then `ready_for_collection` or `dispatched`, then `completed`. Completing is refused while a balance is owed, unless an admin overrides it with a reason.
  - Cancelling before dispatch releases the stock. Cancelling after dispatch flags the order `needs_attention`.
  - Every transition writes an `order_events` row.
  - The rows for online checkout (Release 3) are coded but nothing calls them.
- **Payments:** methods are cash, bank transfer, card (on a terminal) and cheque. These are extended from the blueprint's enum, which only had web methods.
  - Payment state (`unpaid`, `partially_paid`, `paid`, `refunded`) and the balance are recalculated in the same transaction as the payment.
  - Each confirmed payment gets a receipt number.
- **Document numbers:** `INV-`, `RCP-`, `CRN-` (credit note) and `GRN-`, each with its own sequence per year and no gaps.
  - The next number is taken from `document_sequences` with `lockForUpdate()` inside the transaction that creates the document.
  - Issued documents are never edited, only voided with a reason.
- **VAT:** applies only if `TaxSettings::vat_number` is set. Prices include VAT, and `tax = round(total × rate ÷ (100 + rate))`. Test values at a rate of 18: 11,800,000 cents contains 1,800,000, and 4,525,050 contains 690,262. The rate comes from the client's accountant.
- **Returns:**
  - A return is linked to an order and its lines. Each line can't exceed the quantity sold minus anything already returned.
  - Per line, staff choose "back to stock" or "damaged, don't restock". A refund payment is recorded.
  - If the order already has an invoice, a numbered **credit note** is issued for the returned value.
  - The credit note layout needs the accountant's approval, the same as the invoice.
- **Customers:** one record per phone number (E.164), found or created only through `ResolveCustomer`.
  - A shared "Walk-in customer" record covers sales with no name or number.
  - A customer's price tier is `retail` or `trade`. A quote or sale pre-fills the trade price where one exists, and staff can still change any line.
- **Roles:** an enum plus policies, as in blueprint 4.2.
  - `admin` can do everything.
  - `sales` handles customers, quotes, sales, payments, returns and invoices, and can see stock levels.
  - `stock` handles products, suppliers, goods received, adjustments and stocktake. This is the blueprint's `catalog` role, renamed.
  - Every model has the activity log. Sign-in requires an authenticator app, as in blueprint 3.13.

## Phases

Hours are my estimates for agent-assisted work, not measurements. Phases marked ∥ touch separate files and can run as **parallel Codex tasks in separate git worktrees**.

| # | Phase | Hours | Depends on |
|---|---|---|---|
| IS-0 | Environment, scaffold, tooling, CI | 5 | your Herd/PHP install |
| IS-1 | Data model: migrations, enums, models, factories, settings, seed | 9 | IS-0 |
| IS-2 | Admin panel, staff, roles, authenticator sign-in, audit log | 4 | IS-1 |
| IS-3 | Products: categories, products, variants, prices, CSV import | 9 | IS-2 |
| IS-4 | Stock core: ledger, adjustments, stock list, low stock, stocktake | 8 | IS-3 |
| IS-5 ∥ | Suppliers and goods received | 7 | IS-4 |
| IS-6 ∥ | Customers and `ResolveCustomer` | 5 | IS-2 |
| IS-7 | Order core: totals, state machine, payments, refunds, receipt numbers | 9 | IS-4, IS-6 |
| IS-8 | Counter sales ("New sale" screen) | 8 | IS-7 |
| IS-9 ∥ | Quotes: builder, revisions, print page, accept, expiry sweep | 9 | IS-7 |
| IS-10 | Fulfilment and the order screen: transitions, tabs, needs-attention | 5 | IS-8, IS-9 |
| IS-11 | Invoices and receipts: tax, numbering, issue, void, print pages | 11 | IS-10 |
| IS-12 | Returns and credit notes | 7 | IS-11 |
| IS-13 | Dashboard and basic reports, CSV export | 6 | IS-12 |
| IS-14 | Hardening, staff walk-through, staging deploy | 8 | all |

**Total: about 110 hours**, plus 25% contingency, which makes about 140. Long phases (IS-1, IS-11) stop at a midpoint for `/checkpoint`.

### What each phase delivers

- **IS-0:**
  - `composer create-project laravel/laravel`, then pin current stable versions.
  - Install Filament, Pest (with the Laravel plugin), Larastan, Pint, Boost and the spatie packages.
  - `compose.yaml` with Postgres 17 (`npannel`, `npannel_test`).
  - An architecture test checking that `App\Domain` uses no `Illuminate`.
  - GitHub Actions CI (Pint, Larastan, Pest against Postgres).
  - A project `CLAUDE.md` (base branch `Develop`, commands, rules), `IMPLEMENTATION_PLAN.md` (this table with a status column), and `docs/BLUEPRINT.md` copied in.
- **IS-1:**
  - Tables: `locations`, `categories`, `products`, `variants` (SKU unique, unit, size in mm, `price_cents`, `trade_price_cents`, `track_stock`, `reorder_level`, `last_cost_cents`), `stock_levels`, `stock_movements`, `stocktakes` and `stocktake_lines`, `suppliers`, `goods_receipts` and `goods_receipt_lines`, `customers`, `orders`, `order_lines`, `order_events`, `payments`, `quote_snapshots`, `document_sequences`, `invoices`, `credit_notes`, `returns` and `return_lines`.
  - Each takes the blueprint 4.3 definition where one exists. Everything else is new.
  - Seed data: the Main location, a few sample categories and products, the walk-in customer, and one admin user. The seed is idempotent.
- **IS-2:**
  - The Filament panel with required authenticator-app sign-in and recovery codes.
  - Users with a role, plus `UserResource` (admin only).
  - Policies per role, and a read-only activity log page.
- **IS-3:**
  - `CategoryResource` and `ProductResource`, with a variants relation manager, derived feet/inches and square feet shown read-only, and trade price beside retail.
  - `ImportProducts` from CSV, with a dry-run report first. This loads the client's existing product list and its opening stock.
- **IS-4:**
  - `App\Domain\Stock` and actions `AdjustStock`, `ReserveStock` (the conditional decrement) and `ReleaseStock`.
  - A stock list (variant, quantity, reorder level, last movement) with a low-stock filter, and movement history per variant.
  - Stocktake: enter counted quantities, review the differences, then post them as adjustments.
- **IS-5:**
  - `SupplierResource` (name, phone, notes).
  - `GoodsReceiptResource` with draft lines (variant, quantity, unit cost) and the `PostGoodsReceipt` and `VoidGoodsReceipt` actions.
- **IS-6:**
  - `CustomerResource`, searchable by phone and name, with type, price tier, the balance owed, and a list of orders and payments.
  - `ResolveCustomer`, plus a "find or add customer by phone" field.
- **IS-7:**
  - `Domain\Order\Totals` and `Domain\Order\StateMachine`, with a test for every allowed and every forbidden transition.
  - `RecordPayment` and `RecordRefund`, with payment-state recalculation.
  - `NextDocumentNumber` with a concurrency test.
  - Receipt numbers on confirmed payments.
- **IS-8:**
  - `CreateCounterSale`, behind a "New sale" screen: pick the customer or the walk-in, search lines by SKU or name, see the total update live, take the payment on the same screen, and override a stock shortage with a reason.
- **IS-9:**
  - `CreateQuote`, `SaveQuoteLines` (product, delivery, labour, discount and free-text lines), and `SendQuote` (snapshot, revision number, print page, ready-made WhatsApp text).
  - `AcceptQuoteByStaff`, which reduces stock, with the override.
  - `SweepExpired` with the `store:sweep` command on the schedule.
- **IS-10:**
  - `TransitionOrder`, behind a "Move to…" button that offers only the transitions the machine allows.
  - Order tabs: quotes, confirmed, in progress, done and needs attention.
  - The event timeline, and the balance on the order.
- **IS-11:**
  - `Domain\Invoice\Tax`, `IssueInvoice` (lines copied at issue) and `VoidInvoice`.
  - Print pages for invoices and receipts, using browser print / save as PDF.
  - `TaxSettings` and `BusinessSettings` pages.
- **IS-12:**
  - `CreateReturn`, with a per-line choice to restock or not, a refund, and a credit note when the order has an invoice.
  - The credit note print page.
- **IS-13:**
  - Dashboard widgets: today's sales, balances owed, low stock, and quotes expiring.
  - Reports: sales by day and by product, stock value at last cost, money owed, and goods received by supplier. Each has a date range and CSV export.
- **IS-14:**
  - Browser tests of the main journeys: receive stock, then sell, take part payment, issue an invoice, take a return, and see stock go back up.
  - A staff member records three sales without help.
  - Deploy to Laravel Cloud staging (Singapore), with a nightly backup.

## How I'll run it (orchestration)

1. **Per phase:**
   - I write a Codex spec: the exact files, the blueprint section numbers to follow, the rules above, the tests that must exist, and the commands that must pass.
   - I start it with `mcp__codex__codex` in the repo (workspace-write sandbox, network on for Composer).
   - I don't write implementation code myself.
2. **Review:**
   - I read the full diff against the spec.
   - A Claude review subagent gives a second read on correctness for the money, stock and numbering phases (IS-4, 7, 8, 11, 12).
   - Anything wrong goes back through `codex_reply`, with the exact problem and the failing output.
3. **Verify:**
   - I run `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and `vendor/bin/pest` myself, through Bash. All must exit 0.
   - Then I click through the new screens in the built-in browser.
4. **Parallel work:** IS-5 with IS-6, and IS-9 alongside the tail of IS-8. Each parallel Codex task runs in its own `git worktree` on its own branch. I merge them into the phase branch.
5. **Records and branches:**
   - After every phase I update `IMPLEMENTATION_PLAN.md`.
   - I commit to the feature branch and open a PR into `Develop` only when you say "ship". You merge.

## Tests that must exist (the important ones)

- **Stock:**
  - Two simultaneous sales of the last unit: exactly one succeeds.
  - An override writes its reason.
  - Every stock change has a movement row, and the quantities after each movement add up.
- **Receiving:** posting increases stock and sets the last cost. Voiding reverses it, and is refused when the stock is already gone.
- **Orders:** every allowed and forbidden state transition is tested. Payment state and balance are correct after a part payment, a full payment and a refund.
- **Numbering:** concurrent invoice issues produce consecutive numbers with no gaps. A void doesn't reuse its number.
- **VAT:** the two test values above.
- **Returns:** a return can't exceed the quantity sold minus earlier returns. Restocked lines raise stock, damaged lines don't, and a credit note is issued only when an invoice exists.
- **Roles:** `sales` can't post a goods-received note, `stock` can't record a payment, and a deactivated user can't sign in.

## Before IS-0: what you need to do (I can't do these for you)

1. **Install PHP 8.4 and Composer.** Neither is on this machine. Install Laravel Herd for Windows (free) from herd.laravel.com. Or, since you have scoop, run `scoop install php composer`, if you prefer that and approve it. Docker 29 and Node 20.20 are already installed.
2. **Ask the client:**
   - Does a product list or spreadsheet exist? It sets the import format.
   - Are they VAT-registered, and at what rate?
   - Can they send one real invoice?
   - Do staff sell on credit to contractors?
   - Which payment methods do they take?
   - Do they want trade prices?
   - Which items should have their stock tracked?
3. **Accountant check** on the invoice, receipt and credit note layouts before IS-11 is called done.

## Verification for the whole slice

On local Postgres, and then on staging, run one full scenario by hand and in a Pest browser test:

1. Receive 50 panels from a supplier at a cost price.
2. Sell 12 at the counter to a new customer, with a part payment.
3. Quote 30 to a contractor at trade price, then accept the quote.
4. Record the balance, issue an invoice, and print it.
5. Return 2 panels, one damaged, with a credit note.

At the end, the stock list must read 50 − 12 − 30 + 1 = **9**. Every number on the receipts, the invoice and the credit note must be consecutive. The dashboard must match.
