# Implementation plan and status

The approved plan is below the status table and was approved on 2026-11-16. Update the table after every phase. A fresh session should resume from this file alone.

## Status

| # | Phase | Status | Branch | Notes |
|---|---|---|---|---|
| IS-0 | Environment, scaffold, tooling, CI | done (PR #1 merged) | `feature/is-0-scaffold` | See IS-0 log |
| IS-1 | Data model | done (PR #2 merged) | `feature/is-1-data-model` | See IS-1 log |
| IS-2 | Admin panel, roles, MFA, audit | done (PR #3 merged) | `feature/is-2-admin-panel` | See IS-2 log |
| IS-3 | Products and CSV import | done (PR #4 merged) | `feature/is-3-products` | See IS-3 log |
| IS-4 | Stock core | done (PR #5 merged) | `feature/is-4-stock` | See IS-4 log |
| IS-5 | Suppliers and goods received | done (PR #6 merged) | `feature/is-5-purchasing` | See IS-5 log |
| IS-6 | Customers | done (PR #7 merged) | `feature/is-6-customers` | See IS-6 log |
| IS-7 | Order core, payments | done (PR #8 merged) | `feature/is-7-orders` | See IS-7 log |
| IS-8 | Counter sales | done (PR #9 merged) | `feature/is-8-counter-sales` | See IS-8 log |
| IS-9 | Quotes | done (PR #10 merged) | `feature/is-9-quotes` | See IS-9 log |
| IS-10 | Fulfilment, order screen | done (PR #11 merged) | `feature/is-10-fulfilment` | See IS-10 log |
| IS-11 | Invoices and receipts | done locally, pending accountant layout approval; CI runs on the PR | `feature/is-11-invoices` | See IS-11 log |
| IS-12 | Returns and credit notes | not started | | |
| IS-13 | Dashboard and reports | not started | | |
| IS-14 | Hardening and staging deploy | not started | | |

## How work is done

Claude orchestrates and Codex implements. Codex's sandbox **cannot run Herd PHP, Composer, npm or Docker** on this machine, so the orchestrator runs every machine-level command: package installs, migrations, Pint, Larastan, Pest and Docker. Codex only edits files. Failures go back to Codex with the exact output.

## IS-11 log

**Built (by Codex in two parts, verified 18 Nov 2026):**
- **Tax rules:**
  - Prices are VAT-inclusive only, as the plan says. `prices_include_vat` stays on and isn't shown on the settings page.
  - An invoice is a **tax invoice** only when both a VAT number and a rate are set at the moment it's issued. Issuing is refused while only one of them is set or the rate is out of range, and the Tax settings page refuses that combination too.
- **Domain** (pure PHP):
  - `Invoice/Tax::inclusive()`: round-half-up of total × bp ÷ (10000 + bp), in integers, with an overflow guard. It gives the blueprint values (11,800,000 → 1,800,000 and 4,525,050 → 690,262 at 1800 bp).
  - `VatRate`: converts a percent to basis points and back with no floats (18 → 1800, 12.5 → 1250; "18.555" is refused).
  - `TaxRegistration`: VAT number and rate must be both set or both empty.
  - `InvoiceEligibility` and `InvoiceMessage` (the WhatsApp text).
- **Actions** (`app/Actions/Invoices`):
  - **`IssueInvoice`:**
    - Allowed for an order that is confirmed through completed, has no active invoice, and has at least one line. Checked before the Gate and again under the lock.
    - Lock order: customer (shared) → order → `INV-` number, all in the same transaction, so a rollback leaves no gap.
    - Copies the customer details at issue, and blanks every personal field for an anonymised customer.
    - Copies the lines **without cost prices**, plus the totals and the tax. It also sets `orders.tax_cents`.
    - The due date defaults to `invoice_due_days`.
    - Makes a 64-hex token (only the sha256 is stored) and adds an order event.
  - **`VoidInvoice`** (new `void` ability for admin and sales): needs a reason. It keeps the number, clears the token so the public link stops working, resets `orders.tax_cents`, and adds an event. Lock order: order → invoice. A new invoice can then be issued with the next number.
  - **`GetInvoiceLink`** ("Get new invoice link"): makes a new link and kills the old one, and is refused for a void invoice.
  - **Invoice protection:** an invoice can never be deleted, and after issue only the issued → void change and the token rotation are allowed. A void invoice can't be changed at all, not even its `updated_at` or by touching it.
  - **`TransitionOrder`:** an order that has an issued invoice can't be cancelled ("Void invoice INV-… before cancelling this order.").
  - **Documents:** `BuildInvoiceDocument` and `BuildReceiptDocument` (receipts exist only for confirmed payments with a receipt number; refunds have none).
- **Printable pages** (English, plain layout ready for your own design, `resources/views/documents/{layout,invoice,receipt}`):
  - **Invoice:** "TAX INVOICE" or "INVOICE", seller details and VAT number, the buyer from the copied details, lines, totals, "VAT included at X%", payments with receipt numbers (refunds shown negative), amount paid, balance due, bank details when the setting is on and an account is set, the footer, and a VOID banner.
  - **Receipt:** one payment, with the order, the invoice number, the order totals and the balance.
  - **Public link** `/i/{number}/{token}`: timing-safe check, issued invoices only, noindex/no-store/no-referrer, throttled.
  - **Admin print pages** `admin/invoices/{id}/print` and `admin/payments/{id}/receipt`, inside the panel's authenticated routes.
- **Filament:**
  - **Invoices** (group Sales): a list with status, date and customer filters, and a view page with the details, buyer, lines, totals and payments (each with "Print receipt"). Buttons: Get new invoice link, Print, Void. No create, edit or delete. Empty fields are hidden.
  - **On the order:** "Issue invoice" (due date, then the link pop-up), or "View invoice" once one exists, and "Print receipt" on each payment.
  - **On the customer:** an Invoices panel.
  - **Settings** (admin only): Business details, Tax, Documents, Bank details and Quotes, using the installed spatie settings plugin.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 1687/1687 (7821 assertions).
- **Two-connection Postgres tests:** two invoices issued at once for the same order give exactly one invoice, and invoice numbers stay consecutive with no gap after a rolled-back issue.
- No new migrations.
- **Browser, on the dev database:**
  - **Tax settings:** a VAT number without a rate was refused with the "incomplete" message. A 12.5% rate saved as 1250 bp.
  - **Business details:** saved test values (address and phone).
  - **Issuing:** Q-5Q4KVC → "Issue invoice" (due date defaulted to today) → **INV-2026-00001**, with the link pop-up and its WhatsApp text.
  - **The public page:** "TAX INVOICE", the seller VAT number, "VAT included at 12.5%: Rs 972.22" (875,000 × 1,250 ÷ 11,250 = 97,222 cents), receipts RCP-00003 and 00004, and a balance due of Rs 0.00.
  - **Receipt RCP-2026-00003:** shows the order, the invoice number and the totals.
  - **Voiding:** with a reason, the status became Void, the public link gave 404, and the order's tax went back to 0.

**Fixed after the checks:**
- **Postgres stopped** (exit 255) during a test run, the same as in IS-10. A restart was enough.
- **Found by the tests:**
  - Missing date annotations (Larastan).
  - `/admin/invoices/create` gave a 500 instead of 404.
  - The Void button didn't show a message when the invoice was already void.
  - A test refunded money that hadn't been overpaid.
  - Pop-up tests checked the page instead of the pop-up.
  - A void invoice's `updated_at` could still change.
- **Found by the review subagent** (nothing critical):
  - An order with a live invoice could be cancelled.
  - A half-set VAT setup silently issued plain invoices.
  - A void invoice could be edited back to issued.
  - The link button didn't say it replaces the old link.
  - An anonymised customer's details weren't all blanked on the invoice.
- **Found in the browser:** empty buyer fields showed as blank labels on the invoice page.

**Carried forward:**
- **Before this phase is final, your accountant must approve the invoice and receipt layouts** (plan item 3). The VAT rate and number come from them.
- **IS-12:**
  - Credit notes link to the invoice.
  - Voiding an invoice that has credit notes needs a rule (refuse it, or void the credit notes too).
  - The IS-10 note about early cancels still applies.
- The receipt page uses the invoice footer. A separate receipt footer can be added if you want one.

**Dev data left by the check:**
- **Settings:** VAT number "TEST-VAT-123456789" at 12.5% (**clear both on the Tax page** before any real use), and test business details (the address "1 Test Road, Colombo", the phone "011 200 0000", and the WhatsApp digits "94770000000").
- **INV-2026-00001** (void). The next invoice number will be 00002.

## IS-10 log

**Built (by Codex, verified 17 Nov 2026):**
- **`Actions/Orders/TransitionOrder`** (the "Move to…" button; new `transition` ability for admin and sales):
  - **Moves it handles:** withdrawing a quote (quoted → cancelled), confirmed → processing, processing → ready for collection or dispatched, ready/dispatched → completed, and cancelling.
  - **Moves it refuses** with a message, so other actions own them: accepting a quote ("Use Mark accepted"), expiry (sweep only) and every `placed` row (Release 3).
  - **`targetsFor()`** gives the UI only the moves allowed from the current status.
  - **Cancelling:**
    - A reason is always required. Early cancels take free text. Late cancels (ready or dispatched) must use one of the `LateCancelReason` values: delivery refused, not collected, damaged in transit.
    - **Early cancel (confirmed or processing):** puts back exactly what the order took out. It nets the order's own stock movements per variant and location, in ascending variant order, with reason `order_cancelled`. That stays correct even after an override sale took stock negative, or when two lines share a variant.
    - **Withdrawing a quote** releases nothing.
    - **Late cancel:** releases nothing. It flags the order needs-attention with "Cancelled after {status}: {reason}. Check what came back and adjust stock."
    - **Any cancel with money still paid** adds "Rs X was paid on this cancelled order — record a refund."
  - **Fulfilment:** ready for collection sets fulfilment to collection, and dispatched sets it to delivery.
  - **Completing:** refused while a balance is owed, unless an admin (new `completeOverride` ability, admin only) gives a reason. That is logged as "Completed with Rs X owed: …".
  - **Locks:** the order is locked, then status and balance are re-checked under the lock, then stock rows in ascending order. Payments are recalculated after the move.
- **`ClearAttention`** (new `clearAttention` ability for admin and sales): needs a note, and writes "Attention cleared: …".
- **`AppendAttentionNote`** (shared by late payments, late cancels, the refund reminder and the sweep): when the note is full, it keeps the **newest** text and trims the oldest, prefixed with "… ".
- **`SweepExpired`:** a quote that expires with an advance paid is still expired, but flagged with a refund reminder. This was the "check nothing is paid in the sweep" item carried from IS-7.
- **Order balance:** `PaymentStates::balance()` and `Order::balanceCents()` return 0 for cancelled and expired orders, because nobody owes them. A quote still shows total minus paid.
- **Filament** (Quotes and orders):
  - **List:** tabs for All, Quotes, Accepted, In progress, Done and Needs attention (with a count badge), plus a "Needs attention" column.
  - **Order page buttons:**
    - **"Move to…":** offers only the allowed moves. Cancelling shows free text or the late reasons. Completing with money owed shows "Rs X is still owed", and the override reason field appears for admins only.
    - **"Record payment":** allowed on quotes too, for an advance. The amount defaults to the balance, or is left blank on a cancelled or expired order. The button is hidden once an active order is fully paid. The confirmation shows the receipt number.
    - **"Record refund"**, while net paid is above 0.
    - **"Clear attention"**, when the order is flagged.
  - **Order page layout:** a "Needs attention" box at the top when flagged, a newest-first timeline, and the cancel reason, fulfilment and completed/cancelled times when they are set.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 1482/1482 (6777 assertions).
- **Two-connection Postgres test:** a cancellation racing a payment on the same order is serialised by the order lock and ends in a consistent state.
- **Lock timeouts:** every two-connection test (orders, quotes, purchasing, fulfilment) now sets a 5-second lock timeout on its setup and cleanup, so a stuck lock fails quickly instead of hanging the suite.
- **The blueprint's "done when" scenario** is a single feature test.
- No new migrations.
- **Browser, on the dev database:**
  - **Q-5Q4KVC:**
    - Recorded a Rs 4,375 advance (RCP-2026-00003, partially paid).
    - Moved it to processing, then dispatched (the confirmed order offered only Processing and Cancelled).
    - Choosing Completed showed "Rs 4,375.00 is still owed" with the admin override field. I didn't use it.
    - Recorded the balance (RCP-2026-00004). "Record payment" then disappeared, and the order completed.
  - **O-GCCWMW:** moved to processing, then dispatched, then cancelled as "Delivery refused". The reason was a list of the three late reasons. TEE-3000 stayed at 18. The order was flagged with the check-stock note and the Rs 3,500 refund reminder. The list's Needs attention tab showed 1.
  - **O-PZGKPR (early cancel):** GYP-9-2400 went from −10 back to **40** (+50 `order_cancelled`). The refund reminder appeared. Recording a Rs 162,500 refund set the order to Refunded, and "Clear attention" cleared the flag with its note.

**Fixed after the checks:**
- **Test run:** the first full run hung because the Postgres container had stopped (exit 255, an unclean shutdown, probably Docker Desktop restarting or the machine sleeping). Restarting it was enough; Postgres recovered by itself.
- **Found by the tests:**
  - Several timestamp tests failed by microseconds (`freezeTime` instead of `freezeSecond`).
  - A tab badge returned a string where the test expected a number.
  - The late-cancel reason reached the action as an enum.
- **Found by the review subagent** (nothing critical):
  - A paid quote expired silently.
  - When the attention note was full, the newest note was dropped.
  - The payment form's default amount was wrong on cancelled and on fully paid orders.
  - Two-connection test setup had no lock timeout.
- **Found in the browser:** a cancelled order that had been refunded in full showed its whole total as the balance.

**Carried forward:**
- **IS-12:** an early cancel releases only movements whose source is the order. If returns are ever allowed before completion, either block cancelling once a return exists, or include return movements in the net. Otherwise stock is released twice.
- **IS-13:** the dashboard widgets from blueprint phase 11 (accepted orders with a balance, orders needing attention) are deferred to the IS-13 dashboard.
- **Release 3:** `placed` orders still need their sweep (release stock when nothing is paid, otherwise flag).

**Dev data left by the check:**
- Q-5Q4KVC: completed and paid (RCP-2026-00003 and 00004).
- O-GCCWMW: cancelled after dispatch, still flagged, Rs 3,500 not yet refunded.
- O-PZGKPR: cancelled and fully refunded.
- Stock: TEE-3000 at 18, GYP-9-2400 back at 40.

## IS-9 log

**Built (by Codex in two parts, verified 17 Nov 2026):**
- **How a quote works:**
  - A quote is an order with status `quoted`, source `quote` and reference `Q-XXXXXX`.
  - Until it is first sent, it is at revision 0 with no link (`token_hash` null). There is no draft status.
  - A quote needs a real customer: the walk-in, merged and anonymised customers are refused.
  - `valid_until` is the end of the chosen day (23:59:59) in Colombo, stored in the app timezone. The quote is expired after that second.
- **Domain** (pure PHP):
  - `QuoteValidity`, `QuoteMessage` (the WhatsApp text and the `wa.me` link), `QuoteLinesComparison` (has anything changed since the last send?).
  - `Totals::percentOf()`, rounding half up, used for the default advance.
- **Shared with counter sales** (IS-8 code renamed, with no change in behaviour):
  - `OrderLineInput`, `OrderLinePricing`, `PrepareOrderLines`.
  - `InsertOrderWithReference`: the reference is retried inside a savepoint.
  - `ReduceOrderStock`: a normal attempt first, an override only for the short variants, ascending `variant_id`, and every shortage listed at once.
- **Actions** (`app/Actions/Orders`):
  - **`CreateQuote`:** validity defaults to today plus `QuoteSettings::validity_days`.
  - **`SaveQuoteLines`:**
    - Only while the quote is `quoted`. The status is checked before the Gate and again under the lock.
    - The customer row is share-locked and re-checked first.
    - Prices use the customer's tier. The advance must be between 0 and the total, exclusions are at most 2,000 characters, and the validity date can't be in the past.
    - Lines are replaced, and payments are recalculated.
  - **`SendQuote`** (a new `send` ability for admin and sales):
    - Refused for an empty or expired quote, or an ineligible customer.
    - Raises the revision and writes a `quote_snapshots` row.
    - Rotates the token (`RotateOrderToken`): 64 hex characters, only the sha256 is stored, and the old link stops working.
    - Returns the link, the WhatsApp text and the `wa.me` URL, and adds a "Quote sent (revision N)" event.
  - **`AcceptQuoteByStaff`** (a new `accept` ability; the override needs `overrideStock`):
    - Refused if the quote is not open, is expired, was never sent, has no product line, or **has changed since the last revision was sent**. All of this is checked before the Gate and again under the lock.
    - Lock order: customer (shared) → order → stock, with reason `quote_accepted`.
    - Sets status `confirmed`, `accepted_via` = staff, and adds the status event (plus an override event if used).
    - Doesn't re-price. A variant deactivated after sending can still be accepted (a test covers this).
  - **`SweepExpired`:** expires `quoted` orders past their validity, locking and re-checking each row. The `store:sweep` command runs **hourly**. Placed orders (online checkout, Release 3) are not handled in this slice.
- **Printable quote** (English):
  - `BuildQuoteDocument` builds it from the **snapshot**, not the live lines, so unsent edits never reach the customer.
  - `resources/views/documents/quote.blade.php` is a plain standalone page with a print stylesheet (A4), a Print button, and notices for expired, withdrawn or accepted quotes. The "Reply on WhatsApp to accept" link appears only while the quote is open, and only when `BusinessSettings::whatsapp_digits` is set. The layout is deliberately neutral, ready for your own design.
  - **Public link** `/q/{reference}/{token}` (throttled 30/min): a timing-safe token check, quotes only, always the latest revision, with `noindex`, `no-store` and `no-referrer` headers. Anything else returns 404.
  - **Admin print** `/admin/orders/{order}/quotes/{revision}/print` sits in the panel's authenticated routes (sign-in and authenticator app) and needs `view` on the order. Any revision can be printed.
- **Filament:**
  - **Quotes and orders:**
    - "New quote" (pick or add a customer), next to "New sale".
    - **Quote editor** (`/admin/orders/{id}/quote`): the customer summary, the shared lines repeater with live totals, the advance with "Use default (50%)", exclusions and the validity date.
    - **Get quote link:** saves first, then shows a pop-up with the copyable link, the WhatsApp text, "Open WhatsApp" and "Open quote". The link exists only in that pop-up and is never stored.
    - **Mark accepted:** a note, plus the stock override reason for those allowed.
    - **Print**, a **Revisions** list with a print button per revision, and "Edit quote" on the order page.
  - **Shared pieces:** `CustomerPicker`, `OrderLinesRepeater`, `OrderLineForm`, `InteractsWithOrderLines` and `HasQuoteActions`. New sale now uses them too, and its tests didn't change.
  - **Error messages** (`PurchasingValidation::notify`): a single error now shows once (title only) instead of as both title and body. This also affects goods receipts and stocktakes.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 1288/1288 (5922 assertions).
- **Two-connection Postgres tests:** accepting a quote while a counter sale takes the last unit, and accepting while the sweep runs, each give exactly one outcome, and stock never goes negative.
- No new migrations.
- **Browser, on the dev database:**
  - "New quote" for Test Customer Perera opened **Q-5Q4KVC**.
  - 5 × TEE-3000 gave a live total of Rs 8,750. "Use default" filled in Rs 4,375. Validity defaulted to 24 Nov 2026 (7 days). Saved with an exclusion.
  - "Mark accepted" on the unsent quote was refused with "Send the quote before marking it accepted."
  - "Get quote link" showed the link and the WhatsApp text (revision 1). The customer page showed the revision, send time, lines, totals, advance, exclusion and validity. A wrong token gave 404.
  - "Mark accepted" gave status confirmed via staff. TEE-3000 went from 23 to 18 with a `quote_accepted` movement. The history reads created → sent (revision 1) → accepted. The customer link then said "This quote has been accepted."

**Fixed after the checks:**
- **Found by me:** `valid_until` was first stored in UTC while every other column is in Colombo time (my spec was wrong); it's now stored in the app timezone. Codex had also edited this plan file; I reverted that.
- **Found by the tests:**
  - Accepting an unsent quote gave a 403 instead of the message.
  - The quote editor crashed on open (the record type).
  - Business settings were missing in a test.
  - The tests for the link pop-up and for line errors didn't actually check what they claimed.
- **Found by the review subagent** (Part A; nothing critical or high):
  - Send didn't re-check the customer (privacy).
  - The token was 40 hex characters instead of 64.
  - The public link didn't require `source = quote`.
  - The case of a variant deactivated after sending wasn't decided.
  - Expiry depended on `APP_TIMEZONE`.
  - Tests were missing: re-saving identical content must not count as a change, and a cost-only change must not count either.
- **Found in the browser:** the duplicated error message, and the stray "·" when business contact details are empty.

**Carried forward:**
- **Dev setup:** the business settings (name, address, phone, email, WhatsApp number, quote footer) are empty on the dev database. Fill them in to see the full quote header and the WhatsApp accept link.
- **IS-10:** withdrawing a quote (`quoted → cancelled` with a reason) goes through `TransitionOrder`. "Record payment" (including an advance on a quote) arrives with the order-screen actions.
- **Release 3:** the sweep must also handle `placed` orders (release stock when nothing is paid, otherwise flag needs-attention), as the IS-7 notes say.
- **Lock order** is unchanged: customer (shared) → order → document sequence → stock levels (ascending variant).

**Dev data left by the check:** quote/order **Q-5Q4KVC** (confirmed, Rs 8,750, nothing paid yet), revision 1 snapshot. TEE-3000 is at 18. GYP-9-2400 is still at −10 from IS-8.

## IS-8 log

**Built (by Codex, verified 17 Nov 2026):**
- **Domain:** `Domain/Order/OrderReference::generate()` makes `O-` / `Q-` plus 6 characters from the blueprint 3.7 alphabet. The random source is injected, and `Actions/Orders/OrderReferenceGenerator` wraps it so tests can swap it.
- **`Actions/Orders/CreateCounterSale`** (`handle(Customer, list<CounterSaleLine>, User, ?CounterSalePayment, ?string $stockOverrideReason)`):
  - **Checks before anything is written:**
    - Gate `create` on Order (admin and sales). The stock role is refused.
    - Merged, anonymised and deleted customers are refused.
    - At least one product line, at most 200 lines. Product lines need an active variant. Delivery, labour, other and discount lines need a description and a price.
  - **Prices** (`CounterSalePricing`, `PrepareCounterSaleLines`):
    - A trade-tier customer gets `trade_price_cents`, falling back to retail. Any explicit price is kept.
    - Lines snapshot the SKU, description, sale unit and last cost.
    - Totals come from `Domain\Order\Totals`.
  - **Payment:** a walk-in sale must be paid in full, unless the total is 0. A named customer can pay part or nothing, and the rest shows as balance owed.
  - **One transaction, in this lock order:**
    1. Customer row (`FOR SHARE`, re-checked; this blocks a concurrent delete or merge without making walk-in sales wait for each other).
    2. Order insert (reference retried up to 5 times inside a savepoint), lines, `RecalculateOrderPayments` (a zero total is paid), and a status event "Counter sale".
    3. `RecordPayment`, which locks the order and takes the `RCP-` number.
    4. Stock: product lines are combined per tracked variant (`ReserveStock::handleAggregated`, new, with `handle()` unchanged) in ascending `variant_id`, with reason `counter_sale` and the order as source.
  - **Shortages:**
    - Every short item is listed in one message ("SKU: wanted N, in stock M") and the whole sale rolls back, so no order, payment or receipt number is used.
    - With a reason and the new `overrideStock` ability (admin and sales), only the short variants are retried with `is_override`. The reason goes on the movement, and a note event "Stock override: … — SKUs" is written.
- **Filament** (group Sales):
  - **Quotes and orders** (`OrderResource`): a read-only list (reference, customer, source, status, payment state, total, paid, balance) with search and filters, and a view page showing details, totals, lines, payments and events. Empty fields are hidden. There are no create or edit routes; IS-10 extends this.
  - **New sale** (`/admin/orders/new-sale`, a header action):
    - **Customer:** walk-in by default, or search, or add by phone through `ResolveCustomer`.
    - **Lines:** a repeater with live line totals and order totals. Items are searched by SKU or name, with stock shown. The price is filled in for the customer's tier, and switching customer re-prices lines whose price wasn't edited by hand.
    - **Payment:** on the same screen. A blank amount pays the full total.
    - **Stock override:** the reason field appears only after a shortage, and only for users with `overrideStock`. Editing the lines or the customer hides it again.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 1116/1116 (5121 assertions).
- **Two-connection Postgres test:** two sales race for the last unit. One succeeds, the other is refused, and stock never goes negative.
- No new migrations.
- **Browser, on the dev database:**
  - A walk-in sale of 2 × TEE-3000, cash, became **O-GCCWMW** with **RCP-2026-00001**. TEE-3000 went from 25 to 23, and the movement is `counter_sale` with the order as source.
  - A walk-in sale of 50 × GYP-9-2400 with only 40 in stock was refused with "Stock is short. GYP-9-2400: wanted 50, in stock 40", and the override field appeared.
  - With a reason, it went through as **O-PZGKPR** with **RCP-2026-00002**, so the refused attempt used no number. The movement is flagged override and carries the reason, and the order history has the status, payment and override entries.

**Fixed after the checks:**
- **Found by the tests:** the New sale page crashed on load because the line-kind field holds an enum, not a string. Codex also replaced its custom page template with Filament's default page layout.
- **Found by the review subagent:**
  - Line prices didn't change when the customer changed (retail vs trade).
  - An override reason given for one shortage would also cover shortages added later.
  - The customer wasn't locked or re-checked inside the transaction, so a customer deleted mid-sale gave a 500.
  - The live form ran several queries per line on every update.
  - A lock-order test checked a doc comment instead of behaviour.
- **Found in the browser:** empty fields showed as blank labels on the order page.

**Carried forward:**
- **IS-10** extends `OrderResource` (tabs, "Move to…", the balance actions). Counter sales start at `confirmed`, so they follow the normal fulfilment path from there.
- **IS-9 `AcceptQuoteByStaff`** should reuse the same stock pattern: a normal attempt first, an override only for short variants, all in ascending `variant_id`, after the order lock and any payment.
- **Lock order** is now: customer row (shared) → order row → document sequence → stock levels (ascending variant).

**Dev data left by the check:** orders O-GCCWMW and O-PZGKPR (walk-in, paid), receipts RCP-2026-00001 and 00002. TEE-3000 is at 23 and **GYP-9-2400 is at −10** because of the override sale; a stock adjustment or a goods receipt puts it right.

## IS-7 log

**Built (by Codex, verified 17 Nov 2026).** This phase has no UI; later phases call these actions.
- **Domain** (`app/Domain/Order`, pure PHP):
  - `Totals` and `OrderTotals`:
    - Product, labour and other lines make the subtotal. Delivery is separate.
    - Discount lines are entered as positive amounts and stored as negatives.
    - The total can't be below 0, and the advance must be between 0 and the total. Quantity is 1 to 1,000,000 and unit price Rs 0 to Rs 100M, with overflow guards.
  - `StateMachine` covers the complete table in blueprint 3.7, including the Release 3 rows, and refuses every other pair with `InvalidTransition`.
    - `allowedFrom()` lists the moves allowed from a status, for IS-10's "Move to…" button.
    - It returns `TransitionEffects`:
      - `reduceStock` when moving to confirmed.
      - `reserveStock` when moving to placed.
      - `releaseStock` when cancelling an order that is placed, confirmed or processing, or when a placed order expires.
      - `flagNeedsAttention` when cancelling a ready-for-collection or dispatched order.
      - `requiresSettledBalance` when moving to completed. The admin override arrives in IS-10.
      - `requiresNothingPaid` when a placed order expires.
      - `requiresCancelReason`: free text for early cancellations; for late ones, only `delivery_refused`, `not_collected` or `damaged_in_transit`.
  - `PaymentStates`:
    - Payment state: Refunded if nothing is left paid after a refund; Paid when the net paid covers the total (including a zero total); Unpaid when nothing is paid; Partially paid otherwise.
    - Balance owed: total minus net paid, never below 0.
- **Actions:**
  - `Orders/RecalculateOrderPayments`: needs a transaction and the order lock. `paid_cents` is the **net** amount (payments minus refunds), and `refunded_cents` is the total refunded.
  - `Orders/RecordOrderEvent`: writes the order's history entries.
  - `Payments/RecordPayment` (admin and sales):
    - Cash, bank transfer, card or cheque. PayHere is refused because the gateway records it. Bank transfer and cheque need a reference.
    - Locks the order row first. The amount can't be more than the balance.
    - Gives the payment an `RCP-YYYY-NNNNN` receipt number inside the same transaction, so a failed payment leaves no gap in the numbers.
    - Recalculates the paid amount and payment state, and writes a history entry.
    - A payment on a cancelled or expired order is still recorded. The order is flagged needs-attention, the note is added to any existing note while it fits in 255 characters, and an Attention entry is added to the history.
  - `Payments/RecordRefund` (a new `refund` ability for admin and sales):
    - A note is required. There is no receipt number.
    - Cancelled or expired order: up to the net amount paid.
    - Active order: only an overpayment can be refunded, unless the refund is tied to a return (`SalesReturn`). A return-linked refund can be up to the net paid, and IS-12 must lower the amount owed in the same transaction.
- `Order::balanceCents()` is fixed: it now uses the net `paid_cents`, so refunds aren't subtracted twice.
- `Order` and `Payment` have enum `@property` annotations.
- **Lock-ordering rule:** the order row first, then the document sequence and stock levels (ascending variant). IS-8 to IS-12 must follow this.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 1027/1027 (4614 assertions). The state-machine test checks every from/to pair.
- **Two-connection Postgres tests:**
  - Receipt numbers wait on the sequence lock, come out in order, and leave no gap after a rollback.
  - A payment or refund racing another on the same order times out on the order lock, then is refused because the balance has changed.
- **Lock check:** I removed `lockForUpdate` from `RecordPayment`, and the concurrency test failed as it should. The file was then restored.

**Fixed after review** (independent Claude review subagent):
- A refund on an active order made the customer owe money again.
- No test covered racing payments, so removing the lock broke nothing.
- An expiry was allowed on a part-paid placed order.
- A late payment overwrote the existing attention note.
- A zero-total order showed as unpaid.
- Larastan: the enum annotations were missing.
- A refund test had its length arithmetic wrong.

**Carried forward:**
- **IS-8, IS-9 and IS-12:** any change to `total_cents` must call `RecalculateOrderPayments` under the order lock, so the payment state and balance stay in step.
- **IS-10:** add the admin-only override for completing an order with an unpaid balance (an ability such as `completeOverride`, plus a reason), and check `requiresNothingPaid` in the sweep.
- **IS-12:** a refund tied to a return must lower the amount owed (by credit note or return value) in the same transaction.

**Note:** the machine restarted overnight. Docker had to be started again (`docker compose up -d`) before the tests could run.

## IS-6 log

**Built (by Codex, verified 17 Nov 2026):**
- **`Actions/Customers/ResolveCustomer`** is the only way a customer is found or created by phone, as blueprint 5.1 requires.
  - **Phone numbers:** normalised with `Domain/Contact/PhoneNumber` from IS-5. The lookup locks the row, and a new number is created with `createOrFirst`, which uses a savepoint, so two staff entering the same number can't create a duplicate.
  - **Merged records:** a merge chain is followed to the surviving customer. If that customer has been anonymised, the stale phone is removed from the chain and a fresh customer is created.
  - **Names:** a known number never changes the customer's name. A different name is added to the notes once per name ("Also gave the name … on …"), and never if it would push the notes past 2,000 characters.
  - **Customers without a phone:** staff can create these, as the blueprint allows.
  - **`walkIn()`:** returns the single shared walk-in customer, which the partial unique index guarantees.
- **`UpdateCustomer`:**
  - Only the fields given are changed. Changing the name sets `name_edited_by_staff`.
  - A duplicate phone gives "That number already belongs to <name>", naming the surviving customer when the holder has been merged.
  - Protected fields (`is_walk_in`, `merged_into_id`, `anonymised_at`, `name_edited_by_staff`) can't be set from any form or action. The fields allowed are listed in `CustomerData`.
- **`DeleteCustomer`:** admin only. It is refused for the walk-in customer, a customer with orders or invoices, or a customer that other records are merged into, because the foreign key would un-merge them.
- **Balance owed:**
  - `Domain/Customers/Balance` defines the counted statuses (`confirmed`, `processing`, `ready_for_collection`, `dispatched`, `completed`) and floors each order at 0.
  - `Customer::balanceCents()` gives one customer's balance.
  - The `withBalance()` subquery lets the list show and sort balances without one query per row. The `owingMoney()` scope uses EXISTS.
- **`SearchCustomers`:**
  - Searches by name, company and phone.
  - A full number in any format matches exactly.
  - A partial number that looks like a phone number ("0775", "077 555", "+94 77") is matched against the digits after +94.
  - Text containing digits (for example "Lanka Ceilings 2000") does not match phone numbers.
  - LIKE wildcards are escaped.
- **Roles:**
  - Admin and sales can use the Customers pages. Stock staff have no access. Only an admin can delete.
  - `RoleMatrix` refuses update and delete for walk-in, merged and anonymised customers.
- **Filament: Customers** (group Sales):
  - **List:**
    - Shows the walk-in badge and balance owed (sortable), with filters for type, price tier and "Owes money".
    - Merged customers are hidden, and anonymised ones show as "Removed customer".
  - **"Find or add by phone"** (header action): opens the existing customer or adds a new one.
  - **Create page:** goes through `ResolveCustomer`. A number that already exists opens that customer with "Already a customer" and doesn't create a duplicate.
  - **View page:** details and balance owed, with read-only orders and payments panels (`Customer::payments()` is a has-many-through).
  - **Edit page:** refused (403) for the walk-in customer.
- The reusable customer picker for the New sale screen is deferred to IS-8, where it is first used.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 755/755 (3707 assertions).
- No new migrations.
- **Browser, on the dev database:**
  - Find or add with `077 555 0101` created customer #2 with phone `+94775550101`.
  - New customer with `+94 77 555 0101` and the name "Nimal Perera" opened customer #2 instead of creating a duplicate, and added the alias note.
  - Searching `0775550101` and `077 555` both found the customer.
  - The walk-in customer's edit page returned 403.

**Fixed after the checks:**
- **Larastan:** the "Owes money" filter is now a typed scope and a named method.
- **Found in the browser:**
  - A partial local-number search found nothing.
  - An empty "Customer" field appeared on the view page.
- **Found by the review subagent:**
  - The alias note grew daily until the customer could no longer be edited.
  - Deleting a merge survivor un-merged its records.
  - Searches containing digits matched phone numbers.
  - A merge chain ending at an anonymised customer wrote personal data into it.
  - The duplicate-phone message could name a merged customer.
  - No test covered `ResolveCustomer`'s own concurrent-create path. One now does.

**Dev data left by the check:** customer #2, "Test Customer Perera" (`+94775550101`), with the note "Also gave the name "Nimal Perera" on 2026-11-17."

## IS-5 log

**Built (by Codex, verified 17 Nov 2026):**
- **Domain** (pure PHP):
  - `Domain/Contact/PhoneNumber::toE164()`: Sri Lankan default (`077…`, `77…`, `0094…`, `011-…` become `+94…`). IS-6 reuses it for customers.
  - `Domain/Documents/DocumentNumber::format()`: `GRN-2026-00001`.
  - `Domain/Purchasing/ReceiptTotals`: line and receipt totals, overflow-checked.
- **`Actions/Documents/NextDocumentNumber`**, brought forward from IS-7. It gives one sequence per kind and per Asia/Colombo year. It must run inside the caller's transaction (`insertOrIgnore` and `lockForUpdate`), so a rollback gives the number back and leaves no gap. IS-7 and IS-11 reuse it for `RCP-`, `INV-` and `CRN-`.
- **Actions** (`Actions/Purchasing`):
  - **Suppliers:** `SaveSupplier` only touches the fields it is given. `DeleteSupplier` is refused once a supplier has receipts; deactivate it instead.
  - **Drafts:**
    - Create, update and delete a receipt.
    - Save and delete lines. Only active, stock-tracked variants can be added, each variant once per receipt. Quantity is 1 to 1,000,000 and unit cost Rs 0 to Rs 100M. The total is recalculated in the same transaction.
  - **`PostGoodsReceipt`:**
    - Refused if the receipt has no lines, if the supplier is now inactive, or if any variant is now inactive or untracked.
    - Assigns the GRN number and adds stock with reason `goods_received`.
    - Stores the cost each line replaces (`goods_receipt_lines.previous_last_cost_cents`, migration `000011`), then sets `last_cost_cents`.
  - **`VoidGoodsReceipt`:**
    - Needs a reason. All shortages are checked under row locks before anything changes, and one message lists them ("SKU: received 50, in stock 20").
    - Only admins can override, and only the lines that are actually short are flagged `is_override`.
    - The number is kept.
    - Last cost goes back to the latest other still-posted receipt, judged by real posting order (`stock_movements.id`, because `posted_at` is only accurate to the second). If there is none, it goes back to the cost stored on the line.
  - **Locks:** receipt, then variants (`FOR NO KEY UPDATE`, ascending), then document sequence, then stock levels (ascending variant). This follows the IS-4 lock-ordering rule.
- **Roles:**
  - `GoodsReceipt` gains `post` and `void` (admin and stock) and `voidOverride` (admin only).
  - Sales has no access to suppliers or receipts.
  - Posted and void receipts can't be changed through the policy, the actions, the lines relation manager or the edit route (403).
  - Saving an edit page left open after a colleague has posted or voided the receipt now shows a message instead of a 403. The same fix was applied to `EditStocktake`.
- **Filament** (group Inventory):
  - **Suppliers:** list, create, edit and view, with that supplier's receipts on the view page.
  - **Goods received:**
    - The list, a create page that opens the draft's edit page, a view page, and an edit page for drafts only.
    - On a draft: post (the confirmation shows lines, units and cost) and delete. On a posted receipt: void, with a reason and the admin-only override checkbox.
    - A lines relation manager with an SKU or name search, and unit cost pre-filled from the last cost.
  - Stock history shows the GRN number as each movement's source.
- `GoodsReceiptLine` now has the activity log.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: 694/694 (3439 assertions), including two-connection Postgres locking tests.
- Migration `000011` migrates, rolls back and re-migrates.
- **Browser, on the dev database:**
  - The supplier phone `077 123 4567` was stored as `+94771234567`.
  - A draft with a line of 50 × Rs 2,400 showed a total of Rs 120,000.
  - The post confirmation read "1 line, 50 units, Rs 120,000.00", and the receipt posted as **GRN-2026-00001**. GYP-9-2400 went from 40 to 90, its last cost became Rs 2,400, and the movement's source is the receipt.
  - The posted receipt's edit route returned 403.
  - The void, with a reason, put stock back to 40.
  - This check also found the cost-restore bug below. It has been fixed and the test cost put back to Rs 2,500.

**Fixed after the checks:**
- **Found in the browser:**
  - A void wiped a cost that hadn't come from a receipt (for example, from the import).
  - The supplier was labelled "Name" on the receipt pages.
- **Found by the review subagent:**
  - A partial supplier update blanked the contact fields.
  - When two receipts were posted in the same second, the cost restore could pick the wrong one.
  - Saving an out-of-date edit page gave a 403.
  - The override flag was set on lines that weren't short.
  - Receipt lines had no activity log.
  - An inactive supplier's draft could still be posted.
  - The variant read when saving a line wasn't locked.

**Dev data left by the check:** supplier #1 "Test Gypsum Supplier", and GRN-2026-00001 (void). The next GRN number this year will be 00002.

## IS-4 log

**Built (by Codex, verified 17 Nov 2026):**
- **Domain** (`app/Domain/Stock`, pure PHP):
  - `InsufficientStock` and `StockLimitExceeded`
  - `StockLevelStatus`: low means qty ≤ reorder level; out means qty ≤ 0
  - `StocktakeVariance` and `StocktakeImportReport`
- **Ledger** (`app/Actions/Stock/StockLedger`): the only code that writes `stock_levels`, and every change writes one `stock_movements` row.
  - It must run inside a transaction.
  - Level rows are created lazily with `insertOrIgnore`.
  - Increments are atomic, and guarded so a quantity never passes the 32-bit maximum.
  - Decrements are conditional (`where qty >= n`). An override is allowed only with a non-blank reason and is flagged `is_override`.
  - `qty_after` is read under the row lock.
- **Actions:**
  - `AdjustStock` (admin and stock roles, with the new `adjust` ability on `StockLevel`): a reason is required, it can't go below zero, and untracked items are refused.
  - `ReserveStock` and `ReleaseStock`, for IS-7 to IS-12: quantity 1 to 1,000,000. Untracked items are a no-op that returns null. A shortage throws `InsufficientStock` unless an override reason is given.
  - Stocktakes: `CreateStocktake`, `SaveStocktakeLine`, `AddAllTrackedItems`, `ImportStocktakeCounts`, `PostStocktake`, `CancelStocktake`, `LockDraftStocktake`, `UpdateStocktakeNote`, `PreviewStocktake` and `DeleteStocktakeLine`.
- **Stocktake rules:**
  - A stocktake is a draft until it is posted or cancelled.
  - "Add all tracked items" creates lines marked **not counted** (`counted_qty` is null; migration `000010`).
  - Posting sets `expected_qty` to the stock at the moment of posting, and writes a `stocktake` movement for each counted line whose figure differs.
  - Uncounted and untracked lines are skipped and never create level rows.
  - Posted and cancelled stocktakes can't be changed through any path: the policy (`update` is now draft-only for `Stocktake` and `StocktakeLine`), the actions (status is checked before the Gate, so a colleague's earlier post gives a message, not a 403), the relation-manager actions (re-checked on the parent), and the edit route (403).
- **Opening stock = a stocktake.** Either import counts from a CSV (`sku,counted_qty`; all-or-nothing, with line numbers) or enter counts against "Add all tracked items", then post.
- **Filament** (group Inventory):
  - **Stock:** tracked variants with quantity at Main, reorder level and last movement.
    - Filters for low stock, out of stock and active.
    - A low-stock badge on the menu item.
    - Adjust and history actions, plus a view page listing movements.
  - **Stocktakes:**
    - A list, and create, edit and view pages.
    - Header actions: add all, import counts, post (the confirmation says how many lines will change stock and how many are uncounted), and cancel.
    - A lines relation manager showing "In stock now", the difference, and a "Not counted" filter.
- **Lock-ordering rule** (documented in `StockLedger`): any code that changes stock on several rows must lock `stock_levels` in ascending `variant_id` order. IS-5, IS-8 and IS-9 must follow this rule.

**Checks:**
- Pint and Larastan level 6 (0 errors) pass.
- Pest: all tests pass, with 107 in the stock suite.
- Migration `000010` migrates, rolls back and re-migrates cleanly.
- Browser, on the dev database:
  - The stock list loads.
  - Adjusting by +25 gave "Stock adjusted to 25" and a matching movement in the history.
  - A stocktake with "add all" plus one count (GYP-9-2400 at 40) gave the confirmation "1 line will change stock, 7 not counted". After posting, GYP-9-2400 was 40 and the uncounted TEE-3000 stayed at 25.
  - The posted stocktake's edit route returned 403.

**Review findings (Claude subagent), all fixed:**
- **Add-all pre-filled counts**, so posting reversed sales made since then. Lines now start as not counted.
- **403 instead of a message** when a stocktake was already posted.
- **32-bit overflow gave a 500.**
- **Untracked lines created level rows.**
- **Smaller fixes:**
  - import errors at commit time were hidden
  - uploaded files were left behind on failure
  - a header-only CSV was accepted
  - "1 lines" in the post confirmation

**Design note:** a count is compared with stock *at posting time*. A sale made between counting an item and posting the stocktake still shows up as a difference. Staff should post soon after counting. A count-time snapshot can be added later if this causes trouble.

## IS-3 log

**Built (by Codex, verified 17 Nov 2026):**
- **Pure domain helpers:**
  - `Domain\Money\Lkr`: string-only rupee parsing to cents, accepting "Rs.1,250" and rejecting "12,50", ".5" and ">2 decimals"; formatting as "1,250.50".
  - `Domain\Catalog\Measure`: mm/microns, feet-inches to 1/4", ft² and price per ft², all integer-only.
  - `Prices` and `CatalogFormat`: slug/SKU rules and reversible CSV formula escaping.
- **Filament support pieces:**
  - `TranslatableFields`: English, Sinhala and Tamil tabs. English is required, and saving one language never wipes the others.
  - `MoneyInput`: an "Rs" field that saves integer cents.
- **Resources** (Inventory group, permissions from the IS-2 matrix):
  - **Categories:**
    - slug format `^[a-z0-9]+(?:-[a-z0-9]+)*$`
    - the parent picker excludes descendants, and the save path also checks for loops
    - delete is refused while the category has products or children
  - **Products** with a variants relation manager:
    - SKU is trimmed, uppercased, follows `^[A-Z0-9][A-Z0-9._/-]*$`, and is unique ignoring case (unique index on `upper(sku)`, migration 000009)
    - trade price can't exceed price, and price must be above 0
    - feet-inches, ft² and price/ft² are shown read-only
  - **Delete guards:** a product or variant with stock movements, order lines, goods-receipt lines or stocktake lines can't be deleted. Deleting a product deletes its variants through Eloquent first, so each one is logged.
- **CSV import/export:**
  - **Format:** one row per variant, columns in the IS-3 spec.
  - `ImportProducts` always dry-runs first. It writes only when there are no errors, in one transaction, and upserts by slug and SKU (case-insensitive). It never deletes and never touches stock.
  - **File handling:** streaming reader, limit of 5,000 rows, BOM stripped before parsing, blank rows skipped, errors reported by physical line number.
  - **Columns missing from the header are left untouched** on existing rows.
  - **Warnings:** missing Sinhala or Tamil title, missing weight, and "??" in Sinhala/Tamil text (the file was probably re-saved as plain CSV).
  - **Export:** has a UTF-8 BOM and escapes formula cells (`'`), reversibly. Export then import is a no-op.
- **Import page:**
  - "Download template", "Export catalog", "Check file" and "Import".
  - "Import" works only after a clean check of the same file contents (hash).
  - The file is deleted after commit. `catalog:prune-imports` runs daily and removes anything older than a day.

**Checks:**
- `composer lint` passed. `composer analyse` found 0 errors. `composer test` passed: 443 tests, 2269 assertions, at the default 128 MB PHP limit. `phpunit.xml` also sets 512M.
- Migration 000009 migrates, rolls back and re-migrates cleanly.
- In a browser: the products list, the product edit page with the variants table (3000×200 mm shows 9' 10" × 0' 7 3/4", price/ft² Rs 193.55), and the import page all work, with no console errors.
- On the real local database: export then commit-import gave 4 products and 8 variants, all unchanged, and no stock rows. The template dry run was clean.

**Review:** a subagent review found 4 must-fixes and 9 smaller items. All were fixed in Codex rounds 3 and 4:
- a partial-column CSV wiped data
- formula injection in the export
- no BOM, so Sinhala/Tamil was garbled
- no slug/SKU format check
- SKU uniqueness depended on letter case
- BOM followed by a quoted header failed
- blank rows counted as rows
- line numbers drifted after multi-line cells
- "0" became null
- no 255-character limits
- uploaded files were never pruned
- deleting a product skipped the variants' activity log
- the category parent picker allowed loops

**Carried forward:**
- **IS-4:** the plan had the CSV import loading opening stock. No stock is written before the stock ledger exists, so opening quantities go in with IS-4 (an opening-stock import, or a stocktake).

## IS-2 log

**Built (by Codex, verified 17 Nov 2026):**
- **Filament panel** at `/admin`, named "N-Pannel":
  - sign-in, password reset, and a profile page where email is read-only (admins manage emails)
  - **required authenticator-app sign-in with recovery codes**
  - the profile can't disable two-factor while it's required
  - `RequireMultiFactorAuthentication` is persistent middleware, so Livewire update requests from an already-open tab are checked too. Filament itself checks only page routes: vendor `HasRoutes.php:100`.
- **Roles:** `admin`, `sales` and `stock`. One matrix (`app/Policies/Concerns/RoleMatrix.php`) feeds a thin policy for every model and for the activity log.
  - No `Gate::before`, so even an admin can't delete payments, invoices, orders or stock movements.
  - Draft-only rules apply to goods receipts and stocktakes.
  - Inactive users can do nothing.
- **Users** (admin only):
  - Creating a user sends the panel's password-reset link. There is no password field.
  - Row actions: send reset link (refused for inactive users), and "Reset two-factor", which clears the secret, deletes the user's sessions and rotates `remember_token`.
  - `UpdateStaffMember`: an admin can't change their own role or deactivate themselves, and the last active admin is protected. The actor is re-checked after the row lock.
  - `role`, `is_active` and the MFA columns aren't mass-assignable. The actions use `forceFill`.
- **Activity log** (admin only): read-only list with filters (subject type, causer, date range), plus a view showing old and new values. Seeding doesn't log: `DatabaseSeeder` uses `WithoutModelEvents`.

**Checks:**
- `composer lint` passed. `composer analyse` found 0 errors. `composer test` passed: 259 tests, 1366 assertions.
- In a browser on local:
  - a guest is sent to sign-in
  - the seeded admin signs in and is forced to two-factor setup
  - `/admin/users` sends them back to setup until enrolment is done
  - enrolment worked, with recovery codes shown
  - the Users, Activity Log and Profile pages work
  - Livewire updates return 200 with no console errors

**Review:** a subagent security review found 2 must-fixes and 7 smaller items. All were fixed in Codex rounds 3 and 4:
- the "Reset two-factor" session takeover
- Livewire requests skipping the two-factor check
- the concurrent-demotion re-check
- the silent "sent" for inactive users
- mass assignment of roles and two-factor columns
- self-edit fields, the profile email, and test gaps

**Carried forward:**
- **IS-4 / IS-5:** the role matrix can't stop someone adding a *line* to a posted goods receipt or stocktake, because `create` has no record to check. The relation managers must check `update` on the parent before creating a line.

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
