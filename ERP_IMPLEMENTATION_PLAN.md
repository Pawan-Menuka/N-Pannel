# ERP extension plan and status (inventory and sales → ERP modules)

**Proposed 18 Nov 2026. Not approved yet; no phase starts until you say "start ERP-0".** Update the status table after every phase. A fresh session should resume from this file alone, together with `IMPLEMENTATION_PLAN.md` (the finished IS-0 to IS-14 slice) and `CLAUDE.md`.

## Status

| # | Phase | Status | Branch | Notes |
|---|---|---|---|---|
| ERP-0 | Foundation: roles, safety-net tests, decisions | not started | `feature/erp-0-foundation` | |
| ERP-1 | Moving-average costing and cost of sales | not started | `feature/erp-1-costing` | |
| ERP-2 | Accounting journal core, posting rules for existing flows, reconciliation | not started | `feature/erp-2-journal` | |
| ERP-3 | Purchase orders and reorder suggestions | not started | `feature/erp-3-purchase-orders` | |
| ERP-4 | Supplier bills, supplier payments, payables | not started | `feature/erp-4-payables` | |
| ERP-5 | Returns to supplier and debit notes | not started | `feature/erp-5-supplier-returns` | |
| ERP-6 | Customer credit limits, payment terms, statements | not started | `feature/erp-6-credit-control` | |
| ERP-7 | Delivery notes and partial delivery | not started | `feature/erp-7-delivery-notes` | |
| ERP-8 | Multiple locations and stock transfers | not started; **only if D5 = yes** | `feature/erp-8-locations` | |
| ERP-9 | VAT return, financial reports, accountant export | not started | `feature/erp-9-financial-reports` | |
| ERP-10 | Period close and approvals | not started | `feature/erp-10-period-close` | |
| ERP-11 | Optional: units of measure, barcodes, offcuts, API | not started; **each part only if decided** | `feature/erp-11{a,b,c,d}-…` | |
| ERP-12 | Hardening, data migration rehearsal, staging deploy | not started | `feature/erp-12-hardening` | |

## Context

IS-0 to IS-14 built the **inventory and sales** slice as a standalone back office. The blueprint (`docs/BLUEPRINT.md`) framed the next step as a CRM (leads, follow-ups, SMS). The direction has changed: N-Pannel becomes **part of an ERP**, so inventory and sales must connect to purchasing and finance, carry real costs, and produce numbers an accountant can rely on.

**What this changes compared with the IS plan:** the IS plan excluded purchase orders, supplier balances and accounting. This plan adds them. The blueprint's advice not to build a full ledger is kept: ERP-2 builds an **accounting journal (sub-ledger)** that records every money and stock event and exports to the accountant's software. It does not build a statutory general ledger, payroll or fixed assets.

**What already exists and is reused (checked in the code on 18 Nov):**
- `stock_levels`, `stock_movements`, `goods_receipts` and `stocktakes` already carry `location_id`. One location ("Main") is seeded, so multi-location (ERP-8) is screens and transfers, not a remodel.
- `order_lines.unit_cost_cents` exists. It is filled from `variants.last_cost_cents` **when the line is saved** (`CreateCounterSale`, `SaveQuoteLines`). For quotes that is the cost when the quote was written, not when the stock left. ERP-1 fixes this.
- `SalesByProduct` already computes a rough profit from that snapshot, and `StockValuation` values stock at last cost. Both move to the new costs in ERP-1.
- `goods_receipts.supplier_reference` holds the supplier's delivery or invoice number.
- `customers.vat_number` exists. There are no credit-limit or payment-terms fields.
- `DocumentKind` has `invoice`, `receipt`, `credit_note` and `goods_receipt`. `NextDocumentNumber` takes numbers with a row lock.
- Roles are `admin`, `sales` and `stock`.

**Not in this plan:** payroll and HR, fixed assets, bank feeds and reconciliation, manufacturing beyond cutting offcuts (ERP-11c), the public website and online checkout (blueprint Releases 1 and 3), and CRM features (leads, follow-ups, SMS). Any of these can become a later plan.

## Decisions needed (client and accountant)

Each decision has a recommended default. Phases marked with a decision can't be called done until it is answered; work can start on the default.

| # | Decision | Recommended default | Blocks |
|---|---|---|---|
| D1 | Costing method | **Moving weighted average per item per location.** FIFO is more work, and rarely needed for building materials. | ERP-1 |
| D2 | Accounting depth | **Journal (sub-ledger) plus export** to the accountant's software. No statutory ledger. | ERP-2 |
| D3 | Accountant's software and export format | Ask. Generic CSV journal until known. | ERP-9 |
| D4 | Chart of accounts | A minimal default chart (below), each account's code and name editable by an admin. The accountant confirms. | ERP-2 |
| D5 | More than one branch, yard or warehouse? | Ask. If no, skip ERP-8. | ERP-8 |
| D6 | When is a sale revenue? | **When the order is confirmed and the stock leaves** (counter sale or accepted quote), not when an invoice is printed, because invoices are optional here. | ERP-2 |
| D7 | Do supplier costs on goods received include VAT, and can input VAT be claimed? | Costs are **excluding** VAT; the bill records input VAT separately when the business is VAT-registered. | ERP-4 |
| D8 | Purchase order approval | POs above a threshold (setting) need an admin's approval before they are sent. Threshold empty = no approval. | ERP-3 |
| D9 | Default customer terms and credit limits | Cash customers: no credit. Trade customers: limit and terms set per customer. Overrides by admin only, with a reason. | ERP-6 |
| D10 | Can the `sales` role see costs and profit? | **No.** Costs and profit are visible to `admin` and `accounts` only. | ERP-1, ERP-9 |
| D11 | Partial deliveries | Yes, through delivery notes. | ERP-7 |
| D12 | Units of measure, barcodes, offcuts, outside API | Ask about each. Each is a separate optional phase. | ERP-11 |

**Default chart of accounts (D4):** Cash on hand; Bank; Card clearing; Cheques in hand; Accounts receivable (customers); Inventory; Inventory in transit (only with ERP-8); Input VAT; Accounts payable (suppliers); Goods received not billed; Output VAT; Customer advances; Owner's equity / opening balances; Sales; Sales returns; Delivery and labour income; Cost of sales; Stock adjustments and write-offs; Purchase price variance; Discounts given. Payment methods map to accounts in a setting (cash → Cash on hand, bank transfer → Bank, card → Card clearing, cheque → Cheques in hand, PayHere → Bank).

## Business rules added by this plan

These go in `app/Domain` (pure PHP) or `app/Actions`, never in Filament resources, as before. All IS rules still apply.

- **Stock value travels with stock quantity.**
  - `stock_levels` gains `value_cents` (signed `bigInteger`, cents). `stock_movements` gains `unit_cost_cents`, `value_delta_cents` and `value_after_cents`.
  - Every stock change updates quantity and value **in the same statement**. Decrements stay conditional, using one `UPDATE … SET qty = qty - n, value_cents = value_cents - round(value_cents × n ÷ qty) WHERE … AND qty >= n RETURNING …`. Taking the last unit takes exactly the remaining value, so quantity 0 always means value 0.
  - Stock gains from a manual adjustment or a stocktake come in at the current average cost (or, when there is none, a cost the admin enters). Losses go out at the current average. Both post to *Stock adjustments and write-offs*.
  - Overridden oversells (negative stock) cost at the last known average. When a receipt lifts stock from zero or below, the difference between the average and the receipt cost goes to *Purchase price variance*, and the new average becomes the receipt cost.
  - Invariant: for every `(variant, location)`, the sum of `value_delta_cents` equals `stock_levels.value_cents`, and the sum of `delta` equals `qty`.
- **Cost of sales is fixed when stock leaves.** `order_lines.unit_cost_cents` is written by the action that reduces the stock, from the actual issue cost. Quote lines have no cost until accepted. A restocked return goes back at the original line's unit cost.
- **Every money or stock event posts a journal entry in the same database transaction** as the event. There are no queued listeners, so the books and the operations can't drift.
  - Journal entries are balanced (debits = credits, checked in `Domain\Accounting`), numbered `JV-YYYY-#####` from `document_sequences`, and never edited or deleted. A correction is a reversing entry linked to the original.
  - Customer and supplier lines carry `customer_id` / `supplier_id`, so control accounts reconcile to the sub-ledgers.
- **Control accounts always reconcile** (checked by tests and by a scheduled `erp:reconcile` command):
  - Inventory account = sum of `stock_levels.value_cents`.
  - Accounts receivable = money owed by customers (`Domain\Customers\Balance`).
  - Accounts payable = what is owed to suppliers.
  - Goods received not billed = value of posted, unbilled goods-received lines.
  - Trial balance = 0.
- **New document numbers**, each with its own yearly gap-free sequence: `PO-` purchase order, `BIL-` supplier bill (internal number; the supplier's own invoice number is stored separately and is unique per supplier), `PV-` supplier payment voucher, `DBN-` debit note, `DN-` delivery note, `TRF-` stock transfer, `JV-` journal entry.
- **Lock order** (extends the IS order; every action follows it, and ERP-0 adds a test):
  1. Supplier (shared) → purchase order → supplier bill.
  2. Customer (shared) → order → delivery note.
  3. Document sequences. When one transaction needs several numbers, take them in ascending `DocumentKind` value order.
  4. Stock levels in ascending `(variant_id, location_id)`.
  5. Then journal lines are inserted (no further locks).
- **Dated documents** (bills, payments, receipts of goods, journal entries) carry a business date. From ERP-10 a closed period refuses new or voided documents dated inside it.
- **Roles:** a new `accounts` role handles supplier bills, supplier payments, journal views, financial reports, the export and period close. `stock` gains purchase orders and supplier returns. `sales` gains delivery notes and statements. Only `admin` and `accounts` see costs and profit (D10).

## Phases

Hours are estimates for agent-assisted work, not measurements. ∥ marks phases that touch separate files and can run in parallel worktrees.

| # | Phase | Hours | Depends on |
|---|---|---|---|
| ERP-0 | Foundation | 5 | IS-14 |
| ERP-1 | Moving-average costing | 10 | ERP-0 |
| ERP-2 | Accounting journal core | 14 | ERP-1 |
| ERP-3 | Purchase orders | 10 | ERP-2 |
| ERP-4 | Supplier bills and payments | 12 | ERP-3 |
| ERP-5 | Returns to supplier | 6 | ERP-4 |
| ERP-6 ∥ | Customer credit control | 7 | ERP-2 (runs alongside ERP-3) |
| ERP-7 ∥ | Delivery notes | 8 | ERP-2 (runs alongside ERP-4) |
| ERP-8 | Locations and transfers (conditional) | 10 | ERP-3, ERP-7 |
| ERP-9 | VAT return, financial reports, export | 9 | ERP-5, ERP-6 |
| ERP-10 | Period close and approvals | 8 | ERP-9 |
| ERP-11 | Optional modules | 4–12 each | ERP-10 |
| ERP-12 | Hardening and deploy | 9 | all chosen phases |

**Core total (without ERP-8 and ERP-11): about 98 hours**, plus 25% contingency, which makes about 125. ERP-8 adds about 10. Long phases (ERP-2, ERP-4) stop at a midpoint for `/checkpoint`.

### What each phase delivers, and the tests it must ship

#### ERP-0: Foundation
- **Deliver:**
  - `UserRole::Accounts`, its policies and the role matrix above, applied to existing resources (costs hidden from `sales` per D10).
  - **The golden scenario test** `tests/Feature/Erp/GoldenScenarioTest.php`: the IS whole-slice scenario (receive 50, sell 12, quote and accept 30, return 2 with 1 damaged → stock 9) as a fast feature test. Every later phase extends it.
  - **Invariant helpers** in `tests/Support/Invariants.php`: `assertStockLedgerConsistent()` now; value, journal and sub-ledger checks are added by their phases. Every feature test that changes stock or money calls them at the end.
  - Audit every action that takes more than one document number in one transaction, and make them all follow the ascending-kind order.
  - Record the answers to D1–D12 in this file.
- **Tests that must exist:**
  - Role matrix: each role against each resource and action (dataset test).
  - The golden scenario passes on the unchanged code.
  - A two-number transaction (return with refund and credit note) takes its numbers in the agreed order; a concurrent pair doesn't deadlock.

#### ERP-1: Moving-average costing and cost of sales
- **Deliver:**
  - Migration: `stock_levels.value_cents`; `stock_movements.unit_cost_cents`, `value_delta_cents`, `value_after_cents`; new reasons `cost_opening` and `cost_adjust`.
  - `Domain\Stock\AverageCost` (pure): receive, issue, return and negative-stock rules, with the rounding rule above.
  - `ReserveStock`, `ReleaseStock`, `AdjustStock`, `PostGoodsReceipt`, `VoidGoodsReceipt`, `PostStocktake`, `CreateReturn` and `ReduceOrderStock` carry value. `CreateCounterSale` and `AcceptQuoteByStaff` write the issue cost to `order_lines.unit_cost_cents`. `SaveQuoteLines` stops writing a cost.
  - **Backfill** (a command, run once by deploy, idempotent): value each existing stock level at `qty × last_cost_cents` with a `cost_opening` movement. Items with stock but no known cost are listed on a "Missing costs" page, where an admin enters the cost (`cost_adjust` movement).
  - `StockValuation` uses `value_cents`. `SalesByProduct` uses the issue cost. Both hidden from `sales`.
  - The variant screen shows the average cost and the stock value (admin and accounts only).
- **Tests that must exist:**
  - `AverageCost` unit tests: 60 @ 1,000 then 40 @ 1,200 → average 1,080; issue of 30 → cost 32,400; issuing the last unit takes the remaining value exactly; odd-cent rounding never leaves value on zero quantity; a receipt into negative stock posts the variance.
  - Every stock action keeps `assertStockLedgerConsistent()` and the new value invariant true.
  - A quote saved when cost was 1,000 and accepted after a receipt at 1,200 records the cost at acceptance.
  - A voided goods receipt reverses exactly the value it added, or is refused as before.
  - A restocked return goes back at the original line's cost; a damaged one changes no value.
  - The backfill: run on the demo seeder's data, it is idempotent, and afterwards the invariants hold.
  - Two simultaneous sales of the last unit: one succeeds, and the value ends at 0.

#### ERP-2: Accounting journal core
- **Deliver:**
  - Tables: `accounts` (code, name, type, system key, active), `journal_entries` (number, date, description, `source` morph, `reversal_of_id`, posted by), `journal_lines` (account, debit cents, credit cents, `customer_id`, `supplier_id`). `DocumentKind::JournalEntry` (`JV-`).
  - The default chart (D4) as an idempotent seeder, and an account screen (admin edits names and codes; system accounts can't be deleted). Payment-method → account mapping in a settings page.
  - `Domain\Accounting\JournalEntry` (balanced or refused) and `Domain\Accounting\PostingRules`: one pure function per event, returning lines.
  - `PostJournal` action, called **inside** the existing transactions of: counter sale, quote acceptance, order cancellation (reversal), payment, refund, return (sales return, credit, restock at cost), goods receipt post and void, stock adjustment and stocktake. VAT splits use `Domain\Invoice\Tax` when the business is VAT-registered.
  - **Opening balances entry** at cut-over, generated from the sub-ledgers (inventory value, each customer's balance), with equity balancing. No history replay.
  - `erp:reconcile` command on the schedule (daily), plus a dashboard warning for admins when any check fails.
  - Read-only screens: journal list, entry view with source link, trial balance, account ledger (accounts and admin only).
- **Tests that must exist:**
  - `PostingRules` unit tests, one per event, with VAT on and off (reuse the IS VAT values 11,800,000 → 1,800,000 and 4,525,050 → 690,262).
  - An unbalanced entry is refused. Entries can't be updated or deleted (model guard and policy). A reversal mirrors the original exactly.
  - Each IS action posts exactly one entry, in its transaction: if the posting fails, the sale, payment or receipt rolls back (failure-injection test).
  - Invariant helpers gain `assertBooksReconcile()`, called by the golden scenario and every money/stock feature test.
  - Opening balances on the demo data: trial balance 0, inventory = stock value, receivables = money owed.
  - Concurrency: two simultaneous payments produce consecutive `JV-` and `RCP-` numbers with no gaps.

#### ERP-3: Purchase orders and reorder suggestions
- **Deliver:**
  - Tables: `purchase_orders` (number `PO-`, supplier, location, status, expected date, note, totals, approved by/at), `purchase_order_lines` (variant, quantity ordered, quantity received, unit cost). Variants gain `preferred_supplier_id` and `reorder_qty`. Suppliers gain `payment_terms_days` and `vat_number`.
  - Status machine in `Domain\Purchasing`: `draft → (pending_approval →) sent → partially_received → received`, plus `closed` (closed short) and `cancelled`. Approval per D8.
  - Print page and a ready-made WhatsApp/email message for the supplier, following the quote pattern.
  - Goods receipts can be created **from a PO**, pre-filled with what's outstanding. Posting updates `quantity_received` under the PO lock. Voiding reduces it. Over-receiving is refused unless overridden with a reason.
  - "Reorder" page: items at or below reorder level, with on hand, on order (open PO quantities) and a suggested quantity. Creates draft POs grouped by preferred supplier. The stock list gains an "on order" column.
  - POs post no journal entries (a commitment, not a transaction).
- **Tests that must exist:**
  - Every allowed and forbidden PO transition.
  - Receiving 60 then 40 of 100 moves the PO through partially received to received; voiding the second receipt moves it back.
  - Over-receipt refused without an override, logged with one.
  - Two receipts against the same PO line at once can't exceed the ordered quantity without an override.
  - Reorder suggestion arithmetic, including open POs, and draft POs grouped by supplier.
  - Approval: a PO over the threshold can't be sent until an admin approves it.
  - The golden scenario now starts from a PO.

#### ERP-4: Supplier bills, supplier payments and payables
- **Deliver:**
  - Tables: `supplier_bills` (number `BIL-`, supplier, supplier invoice number unique per supplier, bill date, due date from terms, subtotal, input VAT, total, status draft/posted/void), `supplier_bill_lines` (linked to goods-received lines, or extra-charge lines such as freight, with an expense account), `supplier_payments` (number `PV-`, method, amount, date, reference), `supplier_payment_allocations`.
  - **Matching:** a bill line can't bill more than was received and not yet billed. Price differences between receipt and bill post to *Purchase price variance*.
  - Posting rules: bill → Dr Goods received not billed (at received cost), Dr Input VAT, Dr/Cr variance, Dr expense for extra charges, Cr Accounts payable. Payment → Dr Accounts payable, Cr the method's account. Voids reverse; a bill with allocated payments can't be voided until they're unallocated.
  - Supplier screen: balance, bills, payments, statement print page. "Supplier aging" report (current, 1–30, 31–60, 61–90, 90+ days past due), reusing `Domain\Reports\AgeBucket`.
  - Dashboard: bills due this week (accounts and admin).
- **Tests that must exist:**
  - Billing 108,000 for receipts of 60,000 + 48,000 clears Goods received not billed exactly; billing 49,000 for the 48,000 receipt posts 1,000 variance.
  - Billing more than received is refused; billing the same goods-received line twice is refused.
  - Duplicate supplier invoice number for the same supplier is refused.
  - Part payment and allocation across two bills; balance and aging are correct; payables control account = sub-ledger.
  - Void rules, and the reversal entries.
  - Role tests: only `accounts` and `admin` post bills and payments.
  - Golden scenario extended: bill and pay 50,000 → payable 58,000.

#### ERP-5: Returns to supplier and debit notes
- **Deliver:**
  - Tables: `supplier_returns` and lines (against posted goods-received lines; quantity ≤ received − already returned), debit notes `DBN-`. Stock reason `supplier_return`; stock leaves at the current average cost.
  - If the goods were billed, the debit note reduces the payable; if not, it reduces Goods received not billed. The difference between average cost and the original price goes to variance.
  - Recording a supplier refund (money back) against a debit note.
  - Debit note print page.
- **Tests that must exist:**
  - Quantity limit, including earlier returns; stock and value leave correctly; not allowed if stock is short (unless overridden with a reason).
  - Billed vs unbilled posting paths; the payable and supplier balance after a debit note; refund posting.
  - Invariants hold after every case.

#### ERP-6 ∥: Customer credit control
- **Deliver:**
  - Customers gain `credit_limit_cents` and `payment_terms_days`. Per D9, an empty limit means no credit (the sale must be paid in full), and the walk-in customer never gets credit. Orders gain `due_on`, set at confirmation.
  - `Domain\Customers\CreditCheck`: on a counter sale with an unpaid amount and on quote acceptance, refuse if balance + new unpaid amount > limit. Admin override with a reason, written as an order event.
  - Customer statement print page for a date range: opening balance, sales, payments, returns and credits, closing balance, aging.
  - Money owed report gains due date and "overdue" aging. Dashboard: overdue customers.
- **Tests that must exist:**
  - Credit check boundaries (exactly at the limit, one cent over), the walk-in customer, a fully paid sale on a customer with no credit, and the override path.
  - Due dates from terms; overdue buckets.
  - The statement's closing balance equals `Domain\Customers\Balance` and the receivables control account for that customer.
  - Concurrent sales to the same customer can't both pass the check and exceed the limit (customer lock).

#### ERP-7 ∥: Delivery notes and partial delivery
- **Deliver:**
  - Tables: `delivery_notes` (number `DN-`, order, location, status draft/dispatched/delivered/void, vehicle, driver, receiver name, delivered at), `delivery_note_lines` (order line, quantity).
  - **The stock model doesn't change:** stock still leaves at confirmation. Delivery notes track physical dispatch. A line can't deliver more than ordered − returned − already delivered.
  - Order transitions: `dispatched` happens through a delivery note. `completed` needs everything delivered (or the existing admin override). Counter sales collected at the counter are marked delivered automatically.
  - "To deliver" list, delivery note print page with a signature box.
  - Backfill: existing completed and dispatched orders are treated as fully delivered.
- **Tests that must exist:**
  - Partial deliveries add up; over-delivery refused; returns reduce what is left to deliver.
  - Order status follows deliveries; completing with something undelivered is refused without the override.
  - Voiding a delivery note re-opens the quantities.
  - No delivery note changes stock or the journal (asserted).
  - The backfill marks existing orders correctly.

#### ERP-8: Multiple locations and stock transfers (only if D5 = yes)
- **Deliver:**
  - Location screen. The location appears on sales, receipts, POs, stocktakes and delivery notes once more than one is active. Each user gets a default location.
  - Transfers `TRF-`: `draft → sent` (stock and value leave the source at its average, posted to *Inventory in transit*) `→ received` (stock arrives at the destination at the same value). Short receipts post the difference to stock adjustments with a reason.
  - Every stock screen and report gains a location filter.
- **Tests that must exist:**
  - Transfer value is conserved; in-transit balance equals sent-but-not-received value.
  - Concurrent transfers and sales from the same source location can't oversell.
  - Lock order with two locations (ascending `variant_id, location_id`).
  - Invariants per location and in total.

#### ERP-9: VAT return, financial reports and accountant export
- **Deliver:**
  - VAT return for a period: output VAT (sales less returns) and input VAT (bills less debit notes), from the journal.
  - Gross profit by product, category, customer and period, from the cost of sales.
  - Inventory movement report: opening, in, out, closing quantity and value per item for a period. It reconciles to the Inventory account.
  - Trial balance, account ledger, and a management profit and loss and balance sheet (labelled "management view, not statutory accounts").
  - Journal export for the accountant's software (D3), with generic CSV as the fallback. Every export is written to the activity log.
  - All of these are `accounts` and `admin` only.
- **Tests that must exist:**
  - The VAT return equals the sum of VAT on the period's documents (cross-check against `Domain\Invoice\Tax`).
  - Inventory movement report closing value = Inventory account balance at the period end.
  - Gross profit for the golden scenario: sales 45,000 − returns 3,000 = 42,000; cost 32,400 − 2,160 = 30,240; gross profit 11,760.
  - Export file format (header and a known row) and that exports are audited.
  - Report access by role.

#### ERP-10: Period close and approvals
- **Deliver:**
  - `accounting_periods` (month, open/closed, closed by/at, reopen reason).
  - `EnsurePeriodOpen` called by every action that creates or voids a dated document. Closing requires `erp:reconcile` to pass. Reopening is admin only, with a reason.
  - Approvals for three cases, with thresholds in settings: discounts above a percentage, stock write-offs above a value, and voids of documents older than N days. The request waits as "pending approval" until an admin approves or rejects it.
- **Tests that must exist:**
  - A dataset test across **every** dated action proving a closed period refuses it, so a future action can't forget the check.
  - Close refused when reconciliation fails; reopen audited.
  - Each approval case: below threshold goes straight through; above waits; approve executes once; reject leaves no trace in stock or books.

#### ERP-11: Optional modules (each its own branch, only if decided in D12)
- **11a Units of measure:** buy by the bundle, sell by the piece. Purchase unit and conversion factor per variant; POs and receipts in purchase units, stock and cost in base units. Tests: conversion and rounding of cost per base unit.
- **11b Barcodes:** a barcode per variant, and scan-to-add on the sale, receipt and stocktake screens (keyboard-wedge scanners type like a keyboard, so no hardware integration). Tests: lookup, unknown code, duplicate codes refused.
- **11c Offcuts:** cutting a sheet consumes one length and creates an offcut item; value splits by length. New stock reason `cut`. Tests: value conservation and the length arithmetic in millimetres.
- **11d Outside API:** read-only API for other ERP modules (needs a new dependency such as Sanctum, so it needs your approval first). Tests: authentication, scopes, no write access.

#### ERP-12: Hardening, data migration rehearsal and deploy
- **Deliver:**
  - Browser journeys: PO → receive → bill → pay; credit sale → statement; delivery note; (transfer if ERP-8).
  - The demo seeder extended with POs, bills, payments, a supplier return and delivery notes, still through the real actions.
  - **Migration rehearsal:** restore a copy of the staging database locally, run every ERP migration and backfill, run `erp:reconcile`, and check the screens before touching staging.
  - Deploy to staging, run `erp:reconcile` there, an accounts-role walk-through, and the accountant's review of the reports and export.
- **Tests that must exist:** the browser journeys above, passing twice in a row locally and in CI.

## Quality gates for every phase (a phase isn't done until all pass)

1. `composer lint`, `composer analyse` (Larastan level 6), `composer test` **(the whole suite, not only new tests)** and `composer test:browser`.
2. New behaviour has tests at three levels: domain unit tests for the arithmetic, action feature tests for each rule and each refusal, and Filament/Livewire tests for each new screen and role.
3. Any new lock or number sequence has a concurrency test.
4. The golden scenario is extended for the phase and passes, with the invariant helpers.
5. Migrations have a working `down()`. The orchestrator runs `migrate:rollback` and `migrate` on the phase's migrations locally. Old migrations are never edited.
6. Backfills are idempotent, tested on the demo seeder's data, and run by a command, not hidden in a migration.
7. A Claude review subagent reviews every phase (all of them touch money or stock), then a browser check of the new screens.
8. The phase log is written in this file and the status table updated.

## How work is done

As in the IS plan: Claude orchestrates and Codex implements. Codex's sandbox can't run Herd PHP, Composer, npm or Docker here, so the orchestrator runs every machine-level command and sends failures back with the exact output. One feature branch per phase, PR into `Develop`, and you merge.

## Verification for the whole extension

On local Postgres, then on staging, run one scenario by hand and as the golden test (VAT off):

1. PO-1: 100 panels at Rs 1,000 from supplier A.
2. Receive 60 (GRN-1). Stock 60, average Rs 1,000.
3. Receive 40 at Rs 1,200 (the supplier raised the price; the PO line is updated). Stock 100, average Rs 1,080, value Rs 108,000.
4. Supplier bill for both receipts: Rs 108,000. Pay Rs 50,000 → payable Rs 58,000.
5. Sell 30 at Rs 1,500 to a contractor on 30-day terms → sales Rs 45,000, cost of sales Rs 32,400. Stock 70, value Rs 75,600.
6. The contractor pays Rs 20,000 → owed Rs 25,000.
7. The contractor returns 2 to stock → credit Rs 3,000, owed Rs 22,000. Stock 72, value Rs 77,760.

At the end:
- Stock list and Inventory account: **72 panels, Rs 77,760**.
- Payables and the supplier's balance: **Rs 58,000**.
- Money owed and the contractor's statement: **Rs 22,000**.
- Gross profit: **Rs 11,760**.
- Trial balance: **0**, and `erp:reconcile` passes.
- Every `PO-`, `GRN-`, `BIL-`, `PV-`, `RCP-`, `CRN-` and `JV-` number is consecutive.

## Before ERP-0: what you need to do

1. Merge PR #18 (the IS plan's staging status), so the two plan files are in step on `Develop`.
2. Ask the client: D5 (locations), D8 (PO approval), D9 (credit terms), D11 (partial deliveries), D12 (units, barcodes, offcuts).
3. Ask the accountant: D1 (costing), D3 (software), D4 (chart of accounts), D6 (when a sale is revenue), D7 (VAT on purchases), and whether a management P&L is useful.
4. Approve or change D10 (who sees costs) yourself.
