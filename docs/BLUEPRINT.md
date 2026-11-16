# BLUEPRINT: building-materials store and CRM (Sri Lanka), staged release

Written 2 October 2026. **Revision 3, 16 November 2026**: review fixes applied and a CRM added; section 12 lists every change, and revision 2 is kept beside this file as `BLUEPRINT-rev2.md`. Replaces `competitive-website-a-z-plan.md` and `implementation-blueprint.md` (30 September 2026) as the build plan. Competitor reference: https://www.sivilima.lk

`BLUEPRINT-nextjs-variant.md` is the same product on Next.js as it stood at revision 2. It has none of the revision 3 changes and is kept only as a record.

**The CRM scope in section 1.9 is my proposal, not the client's written requirement.** Get it confirmed in step 0 before building anything in Release 2.

This file is meant to be built from cold. A fresh session should be able to read section 8, find the first phase that is not marked done, and continue.

## 0. Read this first

### Assumptions

| Assumption | Status | If it is wrong |
|---|---|---|
| The business belongs to a client. The client asked for more than a website: a whole CRM system. | Stated by you on 16 November 2026. | Nothing changes in the build; the questions in 1.6 become yours to answer. |
| The CRM scope in section 1.9 is what the client means by "CRM". | **My proposal, not the client's words.** No written CRM requirement exists yet. | Walk the client through 1.9 in step 0. Every item they strike removes a phase from Release 2; every item they add (1.9 lists the usual ones) is a new phase and more hours. |
| The product range is not confirmed. Release 1 content is planned around ceiling sheets and their accessories. | You answered "not sure yet". | The data model handles all five categories. The other three are seeded switched off; turning one on is catalog work, not code, except roofing, whose estimator mode is built only if roofing is confirmed (phase 7). |
| You build and maintain this alone. | Unconfirmed. You said "our project". | A second developer changes the timeline, not the plan. If it will be maintained through WordPress freelancers, this is the wrong blueprint. |
| The stack is chosen for the project, not for familiarity: Laravel, Filament, Livewire, Postgres (section 2). You have built PHP projects before. | Decided 2 October. | The CRM requirement strengthens this choice: the CRM is more generated admin screens, which is exactly what Filament saves. |
| You hand-design the customer-facing UI. | Yours to confirm. | Every public phase builds data-complete, semantic, minimally styled pages. Your designs are integrated afterwards with `/ui-drop`. Designs must arrive as HTML with Tailwind classes, or be converted from JSX to Blade. The CRM screens use Filament's defaults and need no design. |
| Placeholder project name `materials-store`, placeholder domain `example.lk`. | Placeholder. | Search and replace once the brand is chosen. |

### Why four releases and not one

1. **The search-ranking clock.** A new `.lk` domain needs months to rank. Shipping the catalog and quote flow first starts that clock.
2. **The CRM is the client's stated requirement and does not depend on the public site ranking.** It ships second, as soon as the website is feeding it leads, and before online checkout.
3. **The payment gateway is outside your control.** PayHere onboarding needs a registered business, a bank account and an approved domain. It must never block the website or the CRM.
4. **Quotes are the main sales path for this trade.** Ceilings, roofs and floors are bought by the job. Online checkout carries the most integration risk and probably the minority of revenue.
5. **You learn before you build the expensive half.** Real enquiries and real CRM use tell you which products people ask about, which districts, and how they pay, before checkout rules are built around guesses.
6. **One person cannot test everything at once.** Each release has a short checklist that one person can actually run.

| Release | Contents | Who it is for |
|---|---|---|
| 1. Website and sales desk | Catalog, estimator, quote requests, WhatsApp handoff; in the admin: customers, leads from every channel, quotes, staff-recorded orders and payments | Customers and the sales staff |
| 2. CRM | Follow-ups, contact history, customer import, counter sales, invoices and receipts, SMS, reports | The business owner and staff |
| 3. Online checkout | Cart, delivery rules, bank transfer, cash methods, card payment, online quote acceptance | Customers who buy without talking to anyone |
| 4. Depth | Comparison, guide library, reviews, ad pixels | Marketing |

Online checkout was a stated requirement of the original plan, so it stays in scope. The review point before Release 3 (section 8.3) decides its size, meaning which products and payment methods, not whether it is built.

### What the competitor does today, and the response

Observed on 2 October 2026 through automated page fetches of the public site. Verify by eye before relying on any of it; nothing was submitted.

| Observation | Response in this plan |
|---|---|
| Navigation: Roofing, Ceiling, Wall Panel, Vinyl Flooring, Roofing and Ceiling Accessories, Showrooms, Get your Quotation, Warranty, Installation Guides, Contact, FAQs. | Same five product areas, so the taxonomy in section 4 matches what buyers already expect. |
| It is a manufacturer brand with its own product lines, a branch selector covering all 25 districts, and a Facebook page reported at over 316,000 followers. | You cannot out-network it. Compete on the three things below it does badly, and on language. |
| Quote form: five steps (Details, Category, Location, Product, Specs). Name, mobile and email are all required on step one. It promises a reply by email within 24 hours. | One screen. Phone is the only required contact. Reply over WhatsApp. Reference number shown immediately. |
| Category pages filter by a price slider only. | Filter by size, finish, thickness and use, which is how these products are actually chosen. |
| Prices are per sheet with wide ranges (roofing shown as Rs 7,992 to 33,939 per sheet) and no per-area basis. | Show price per sale unit and the equivalent per square foot next to it, per variant. |
| A 12-foot ceiling variant is labelled 360 cm in the variant option and 365 cm in the specification. Twelve feet is 365.76 cm. | One stored length in millimetres per variant. Every displayed unit is derived from it. |
| No quantity estimator; a plain quantity box. | The estimator is the headline feature (section 3.5). |
| No Sinhala or Tamil pages found. | Full Sinhala and Tamil journeys, plus romanised Sinhala search terms. |
| Page speed: not measured. My tools could not load PageSpeed Insights. | Step 0 task. The "be faster" claim stays unproven until you run it. |

## 1. Product definition

### 1.1 What it is

Two things in one application, sharing one database.

- **A three-language website** (English, Sinhala, Tamil) for a Sri Lankan building-materials business. A customer can find a suitable product, work out how much they need, and either send that estimate to the business as a quote request or, from Release 3, buy standard items directly.
- **A CRM for the staff.** Every customer, every lead from every channel (website, walk-in, phone, WhatsApp, Facebook), every follow-up, quote, order, invoice and payment in one place, with reports for the owner.

### 1.2 The core loop

Estimate, WhatsApp, quote, payment, follow-up. A customer enters room dimensions, gets an itemised estimate including accessories, taps one button to send it to the business on WhatsApp with a reference number, and receives a priced quote. Staff record the acceptance and the payment against a customer record, and the CRM reminds them who to call next.

### 1.3 User flows

**Flow A, estimate to quote (Release 1).**
Product page or calculator page → enter dimensions → itemised estimate → add to quote list → one-screen request (district, installation yes/no, name, phone) → stored with reference, linked to a customer record by phone → confirmation screen with "Send on WhatsApp" → staff build a priced quote → customer opens the quote link, which can be printed.

**Flow B, direct purchase (Release 3).**
Product page → add to cart → cart repriced on the server → district or showroom collection → delivery fee and allowed payment methods shown → place order → pay by bank transfer, on collection, cash on delivery, or card → order page with status. The link to the order page is also sent by SMS.

**Flow C, quote to order.**
Release 1: the customer replies on WhatsApp or by phone; staff mark the quote accepted and record the advance or full payment. Release 3: the customer can also accept and pay from the quote link.

**Flow D, find a showroom (Release 1).**
Showrooms → pick district from a list → address, hours, phone, WhatsApp, directions link. No map embed and no location permission needed.

**Flow E, sales desk (Release 1).**
Sign in → lead queue (new, overdue) → open the lead, see the customer's history → build quote → send link → mark accepted → record payment → move the order through fulfilment.

**Flow F, a lead that did not come from the website (Release 1).**
A customer phones or walks in → staff type the phone number → the existing customer opens, or a new one is created → staff add a lead with its channel → from here it is Flow E.

**Flow G, follow-up (Release 2).**
Staff open "My follow-ups" → today's and overdue calls → tap WhatsApp or call → log the outcome in one line → set the next follow-up or close the lead with a reason.

**Flow H, counter sale and invoice (Release 2).**
Walk-in customer buys → staff create an order directly, pick items, record the payment → stock falls → invoice and receipt printed or sent as a link.

**Flow I, owner's view (Release 2).**
Reports: sales by period, product, district and salesperson; where leads come from and how many convert; how fast staff respond; who owes money.

### 1.4 Release scope

| Feature | R1 Website and sales desk | R2 CRM | R3 Checkout | R4 Depth |
|---|---|---|---|---|
| Three-language catalog, variants, units, coverage | Yes | | | |
| Category filters (size, finish, thickness, use) | Yes | | | |
| Quantity estimator with accessories, on product pages and as a calculator page | Yes | | | |
| Quote list and one-screen enquiry | Yes | | | |
| WhatsApp handoff with pre-filled message | Yes | | | |
| Campaign source stored on every lead | Yes | | | |
| Staff alert by SMS as well as email | Yes | | | |
| Customer records, created and matched by phone number | Yes | | | |
| Leads from any channel, entered by staff | Yes | | | |
| Quote builder, printable quote, quote revisions | Yes | | | |
| Staff mark a quote accepted, record payments, track the balance | Yes | | | |
| Showrooms, installation page, policies, FAQ | Yes | | | |
| Search with synonyms including romanised Sinhala | Yes | | | |
| Three buying guides | Yes | | | |
| Follow-up tasks, reminders, daily digest | | Yes | | |
| Contact history on each customer | | Yes | | |
| Customer import, tags, duplicate merge | | Yes | | |
| Counter sales with stock adjustment | | Yes | | |
| Invoices, receipts, numbering, VAT breakdown | | Yes | | |
| SMS to customers (order link, payment received, ready) | | Yes | | |
| Reports and exports | | Yes | | |
| Cart and checkout for eligible items | | | Yes | |
| Delivery matrix by district and delivery class | | | Yes | |
| Bank transfer, pay on collection, cash on delivery online | | | Yes | |
| Card payment through PayHere | | | Yes, when merchant approved | |
| Online quote acceptance and advance payment | | | Yes | |
| Stock holds and expiry | | | Yes | |
| Customer order status page | | | Yes | |
| Product comparison | | | | Yes |
| Full guide library, project case studies | | | | Yes |
| Reviews | | | | Yes |
| Ad pixels and consent preferences | | | | Yes, or earlier: build phase 32 as soon as paid ads start |

### 1.5 Explicit non-goals for all four releases

Customer accounts and login, saved cards, instalment or buy-now-pay-later providers, live multi-branch stock, a dealer portal, a native app, AR or AI previews, automated WhatsApp messaging through the Business API, customer file uploads, multiple currencies, interactive maps, a wishlist, coupons, and loyalty points.

CRM non-goals: a general ledger or full accounting, payroll, purchasing and supplier management, a point-of-sale till with barcode scanning and cash drawer, salesperson commission calculation, bulk marketing campaigns by SMS, email or WhatsApp, a shared WhatsApp inbox inside the CRM, and telephone-system integration. Section 1.9 says which of these clients most often ask for.

Reasons: none of these is needed to prove the core loop, and several (uploads, accounts, saved cards, bulk messaging) add security or legal work out of proportion to their value at this size.

### 1.6 Decisions the client must make

These block specific phases. The phase is listed so nothing waits unnecessarily. Phase numbers are those of section 8.

| Decision | Blocks |
|---|---|
| Sign-off on the CRM scope in 1.9 | Phase 9 (customer and lead fields), all of Release 2 |
| Which product categories the business sells | Phase 4 (catalog), phase 7 (whether roofing mode is built) |
| Brand name and `.lk` domain | Phase 0 deployment, PayHere domain approval |
| Business registration and bank account | PayHere onboarding (start in step 0) |
| Whether the business resells one supplier or several brands | Catalog taxonomy in phase 4 |
| First products with supplier documents (target 30; fewer is acceptable if the range is ceilings only, with a floor of 12) | Phase 4 |
| Whether displayed prices include VAT, whether the business is VAT registered, and whether trade buyers need tax invoices | Phase 6 (price display), phase 19 (invoices) |
| Whether contractors and dealers get a different price | Phase 10 (quote builder) |
| Whether any customer buys on credit, and on what terms | Phase 19 (invoice due dates), phase 21 (receivables report) |
| Business WhatsApp number, who answers it and during which hours; staff phone numbers for alerts | Phase 8 |
| SMS provider and sender name (registration can take days) | Phase 8 |
| Quote validity period and the standard advance percentage | Phase 10 |
| Showroom or collection addresses and verified hours | Phase 12 |
| How many staff use the CRM, and whether a salesperson may see other salespeople's leads | Phase 9 |
| Existing customer list to import (spreadsheet, phone contacts, invoice book) | Phase 17 |
| Sinhala reviewer and Tamil reviewer | Phase 2 onward |
| Delivery fee per district and delivery class; who delivers long sheets; weight limit per delivery | Phase 23 |

### 1.7 Step 0: validate before building (a few days, no code)

1. **Walk the client through section 1.9** and get a yes, no or change against every row. Ask what they do today for each (notebook, spreadsheet, memory) and ask to see one real quotation and one real invoice.
2. Confirm the product range and count the products.
3. Run PageSpeed Insights on the competitor's home page, one category page and one product page, on mobile. Record the field data. If their pages are already fast, drop "speed" from the positioning.
4. Look at two or three other local sellers of ceiling and roofing materials and how they sell on Facebook and ikman.
5. Keyword research in English, Sinhala, Tamil and romanised Sinhala. The competitor's own name is the romanised Sinhala word for ceiling, which shows how people type.
6. Phone five contractors or installers. Ask how they buy now, what goes wrong, and how they pay.
7. Write the one-page business case: target enquiries and orders per month, average basket, gross margin, monthly ad budget, break-even.
8. Start business registration, the bank account and PayHere onboarding. Compare PayHere Lite with Plus (section 2.3).
9. Choose an SMS provider, check its price per message and how to call it, and start sender-name registration.
10. Collect supplier documents for the first products.
11. Confirm with a lawyer the current operative status of the Personal Data Protection Act No. 9 of 2022. A CRM holds more personal data for longer than a quote form does, so this matters more than it did in revision 2.
12. Work out your own hours per week honestly and read the timeline in section 8.2 against them.

**Done when:** the client has signed off 1.9, the range is confirmed, the business case exists, the first ten products have verified dimensions and prices, and the two reviewers are named.

### 1.8 Content tiers

Translation is on the critical path. It is tiered so it never blocks a release it does not belong to.

| Tier | Content | Needed for | Rule |
|---|---|---|---|
| 1 | Interface strings, category names, product titles **and product summaries**, specification labels, units, estimator and calculator, enquiry form, the printable quote, error messages | Release 1 | Native reviewer approves. A product cannot be published until its title and its summary are reviewed in all three languages. |
| 2a | Invoice, receipt and SMS texts | Release 2 | Native reviewer approves. |
| 2b | Checkout, order states, payment instructions, transactional messages, policies | Release 3 | Native reviewer approves. |
| 3 | Product long descriptions, guides, case studies | Rolling | May show English with `lang="en"` until reviewed. |

The summary rule is new in revision 3. Revision 2 let a product go live with only its title translated, which left Sinhala and Tamil pages mostly English. A summary is two or three sentences, so the cost is small and each language page now carries real text in its own language.

The CRM screens are English only. That is a limitation if the client's staff are not comfortable reading English; ask in step 0.

Budget the catalog separately from engineering: roughly 1.5 to 3 hours per product to collect documents, verify dimensions, photograph, write and get two translations reviewed. Thirty products is 45 to 90 hours that is not development time.

### 1.9 Proposed CRM scope (assumption, for the client to confirm)

**Nothing in this section came from the client.** It is what a building-materials business of this size typically needs, written so the client can accept, strike or change each row.

| # | Capability | What staff can do | Release | Client says |
|---|---|---|---|---|
| C1 | Customers | One record per phone number: name, type (homeowner, contractor, dealer, company), company, district, address, language, notes, tags, assigned salesperson. Found by typing a phone number or name. Created automatically when a website enquiry or order arrives. | 1 | |
| C2 | Leads from every channel | Website enquiries arrive by themselves. Staff add walk-in, phone, WhatsApp, Facebook and referral leads in a few fields. Each lead has a status (new, contacted, quoted, won, lost), an owner, an expected value and, when lost, a reason. | 1 | |
| C3 | Quotes | Priced quote built from a lead or from scratch, with delivery, labour and discount lines, exclusions, validity and advance. Customer link, printable page, revision history. | 1 | |
| C4 | Orders and payments | Mark a quote accepted. Record advance, balance and refunds by method. See what is still owed on each order. Move the order through fulfilment. | 1 | |
| C5 | Follow-ups | A task with a due date on any lead, customer or order (call, WhatsApp, visit, site measurement). "My follow-ups" list, overdue badge, a morning summary by email and SMS. | 2 | |
| C6 | Contact history | Every call, WhatsApp chat, visit and note logged in one line on the customer, next to their leads, quotes, orders, invoices and payments, in date order. | 2 | |
| C7 | Customer import and upkeep | Import the existing customer list from a spreadsheet. Tags. Merge duplicates. | 2 | |
| C8 | Counter sales | Create an order directly for a walk-in sale, record the payment, reduce stock. | 2 | |
| C9 | Invoices and receipts | Numbered invoice from an order, with VAT breakdown if registered; numbered receipt for each payment; print or send as a link; void with a reason. | 2 | |
| C10 | Customer SMS | Automatic short messages: order link, payment received, ready for collection or dispatched. No bulk marketing. | 2 | |
| C11 | Reports | Sales by period, product, category, district and salesperson. Lead sources and campaigns. Conversion from lead to quote to sale. Response time. Lost reasons. Money owed, by age. Top customers. All exportable. | 2 | |
| C12 | Staff and control | Roles (admin, sales, catalog), sign-in with an authenticator app, and a record of who changed what. | 1 | |

**What "CRM" sometimes means to a client, and is not in this plan.** Ask about each one directly in step 0, because any yes changes the hours.

| Often asked for | Rough size if added | Note |
|---|---|---|
| Stock purchasing and suppliers (purchase orders, goods received, supplier balances) | 30 to 45 hours | This is inventory management, a separate module. |
| A point-of-sale till (barcode scanner, cash drawer, receipt printer, end-of-day cash-up) | 40 to 60 hours | C8 covers recording a counter sale, not running a till. |
| Stock by branch or warehouse | 20 to 30 hours | The plan has one stock figure per product variant. |
| Credit limits and statements for dealers | 12 to 18 hours | C11 already shows who owes what. |
| Salesperson targets and commission | 10 to 15 hours | Needs the client's commission rules. |
| Bulk SMS or WhatsApp promotions | 15 to 25 hours | Needs recorded consent from each customer and, for WhatsApp, the paid Business API. |
| A dealer login to place orders | 40 hours or more | Listed as a non-goal since the first plan. |
| Accounting (ledger, expenses, profit and loss) | Do not build | Export invoices and payments to the accountant's software instead. |

These sizes are my rough estimates, not quotes, and none has been designed.

## 2. Tech stack

### 2.1 How the stack was chosen

The project is roughly 70% back office and translated content (catalog editing, enquiry queue, quote builder, payment confirmation, staff roles, audit trail, import) and 30% storefront (fast pages, estimator, cart). Traffic is low, customers are on low-end phones, staff are not technical, and one person maintains it for years. Those facts were the criteria. Familiarity was not.

| Option | Verdict | Why |
|---|---|---|
| **Laravel + Filament + Livewire + Postgres** | **Chosen** | The staff admin, with tables, filters, row actions, roles, an audit trail and multi-factor sign-in, is generated from configuration instead of hand-built. Scheduler, mail, validation, localisation, rate limiting and database transactions are first-party. Pages are server-rendered HTML with a small script, which suits low-end phones. Yearly, low-drama upgrades. About US$15 to 25 a month on a managed host with a Singapore region. |
| Next.js + Payload CMS | Runner-up | One language end to end and the best localised content editing. But its commerce plugin was still marked beta when checked, it has no built-in multi-factor sign-in, and order, stock and payment workflows would be custom React inside an admin designed for content. |
| Next.js + hand-built admin (`BLUEPRINT-nextjs-variant.md`) | Rejected | About 60 hours of admin work that a framework generates, on four recently changed major versions (Next 16, Prisma 7, Clerk 7, TypeScript 7). |
| Medusa | Rejected | A real commerce engine, but two services plus Redis to operate, for about 100 products. Reconsider if a dealer portal, trade price lists or several warehouses become real requirements. |
| Shopify | Rejected | Fastest start and a good staff app. But the estimator and quote list fight the theme system, and card payments through an external gateway pay Shopify's fee on top of PayHere's. The fallback if you decide you do not want to maintain code at all. |
| WooCommerce | Rejected | Checkout strings are 1 to 2% translated into Sinhala and Tamil; the competitor's weakness is this same plugin stack. |

What the choice costs you, stated plainly:

- You have built PHP projects before, so the language is not a cost. If Filament or Livewire specifically are new to you, expect phases 3 and 4 to run a little slower while their conventions settle.
- Your machine has XAMPP with PHP 8.2.12, which is too old for Laravel 13 (it needs 8.3 or newer), and no Composer. Phase 0 installs Herd, which brings PHP 8.4 and Composer alongside XAMPP without disturbing it.
- Your designs must be HTML with Tailwind, or converted from JSX to Blade.

### 2.2 Versions

Published stable versions on Packagist and npm on 2 October 2026. They were not re-checked for revision 3, so in phase 0 look up the current stable version of each and pin that in `composer.json` and `package.json`. Laravel Boost (below) gives the coding agent the documentation for the versions actually installed; use it before writing integration code.

| Layer | Choice | Why | Rejected alternative |
|---|---|---|---|
| Language | PHP 8.4 | Pest 5 and three of the packages below require 8.4. Herd offers up to 8.5; move up when the host does. | PHP 8.3 |
| Framework | `laravel/framework` 13.34.0 | Section 2.1. | Django, Rails: comparable frameworks, but neither has an admin generator as capable as Filament, and Laravel skills are common locally |
| Admin | `filament/filament` 5.9.0 | Resources, relation managers, row and bulk actions, dashboard widgets, built-in multi-factor sign-in. | Hand-built admin |
| Interactive pages | `livewire/livewire` 4.4.7 (ships Alpine.js) | Estimator, quote list, cart and checkout as server-driven components with no separate API to design. Filament already depends on it. | A React or Vue front end with a JSON API |
| Database | PostgreSQL 17 (managed on the host; Docker locally) | Transactions and conditional updates for stock; `jsonb` columns for translations. | MySQL: fine, but Postgres is the host's serverless option |
| Translated fields | `spatie/laravel-translatable` 6.14.1, edited through your own small `TranslatableTabs` form helper (three tabs, one per language) | Translations stored as `jsonb` on the row. The helper reads with `getTranslations()` and saves with `setTranslations()`, so three-language editing does not depend on a community plugin. Reviewers also see all three languages on one screen. | `lara-zeus/spatie-translatable` 2.0.1: a single-maintainer plugin on the critical path. Try it in phase 4 if the helper proves awkward. Separate translation tables. |
| Images and documents | `spatie/laravel-medialibrary` 11.23.8 with `filament/spatie-laravel-media-library-plugin` 5.9.0 | Uploads, WebP conversions and responsive sizes generated at upload. | Hand-rolled uploads |
| Settings | `spatie/laravel-settings` 3.9.0 with `filament/spatie-laravel-settings-plugin` 5.9.0 | Typed settings classes with admin pages. | Environment variables for business data |
| Audit trail | `spatie/laravel-activitylog` 5.1.1 | Who changed what, on every model, with one trait. | Hand-written log calls |
| Phone numbers | `propaganistas/laravel-phone` 6.1.0 | Validation and normalisation for Sri Lankan numbers. | A regular expression |
| Spam | `spatie/laravel-honeypot` 4.7.3 plus Laravel's rate limiter | Honeypot and minimum fill time in one middleware. | CAPTCHA |
| Sitemap | `spatie/laravel-sitemap` 8.2.0 | Supports language alternates. | Hand-built XML |
| Security headers | `spatie/laravel-csp` 3.29.0 | Content Security Policy with a report-only mode. | Hand-set headers |
| Email | `resend/resend-laravel` 1.6.0 | Staff alerts and optional customer messages. Resend's free allowance was not verified. | Postmark |
| SMS | A local provider chosen in step 0, called over HTTP through your own `SmsSender` interface (section 3.15) | Staff alerts and customer order links. Phone is the only contact most customers give. No provider or package has been checked. | Email only |
| Printable documents | Blade pages with a print stylesheet (section 3.14) | Quotes, invoices and receipts print or save as PDF from the browser, which shapes Sinhala and Tamil correctly. | A PHP PDF library: unreliable with Sinhala and Tamil shaping. A headless-browser PDF package: needs Chromium on the host. |
| Errors | `sentry/sentry-laravel` 4.28.0, free plan | Server and browser errors. | Log files only |
| Styling and build | `tailwindcss` 4.3.3, `@tailwindcss/vite` 4.3.3, `vite` 8.3.2, `laravel-vite-plugin` 3.2.0 | Assumed to match your designs. | A component library |
| Tests | `pestphp/pest` 5.3.0, `pestphp/pest-plugin-laravel` 5.0.1, `pestphp/pest-plugin-browser` 5.1.2 | Unit, database and browser tests in one runner. The browser plugin drives Playwright and has `assertNoAccessibilityIssues()`. | PHPUnit plus a separate Playwright suite |
| Static analysis and style | `larastan/larastan` 3.12.2, `laravel/pint` 1.32.1 | Catches type errors before runtime; consistent formatting. | None |
| Agent support | `laravel/boost` 2.10.1 (development only) | Gives Claude Code version-specific documentation search, database schema and logs for this project. This directly addresses the "installed version is newer than training data" problem. | None |
| Local environment | Laravel Herd for Windows, free tier, plus Postgres 17 in Docker | Herd supplies PHP, Composer, Node and nginx. The paid tier is only needed for bundled databases, which Docker replaces. | Laravel Sail: needs a full WSL distribution you do not have |
| Hosting | Laravel Cloud, Starter plan, Singapore | Fully managed: no server to patch. Scheduler included. | A VPS with Forge: cheaper at scale, but a machine to look after |
| Page analytics | Cloudflare Web Analytics | Free, cookieless, includes real-user Core Web Vitals. No consent banner needed. | Tag Manager and GA4 at launch |
| Funnel analytics | Your own `analytics_events` table | A dozen event types, no personal data. | A vendor |
| DNS | Cloudflare, DNS only | Free and reliable. | Registrar DNS |
| Uptime | Better Stack free tier | Checks on the home page and `/up`. | Paid monitoring |
| Independent backup | Nightly `pg_dump` from GitHub Actions, encrypted, to a private bucket | Survives losing the hosting account. Works with any host. | A backup package that needs `pg_dump` on the app server |
| CI | GitHub Actions | Style, static analysis and tests on every pull request. | None |

Deliberately not used: a permissions package (three roles are an enum and a few policy checks), a localisation-routing package (a route prefix and one middleware), a full-page cache (section 3.3), Redis, a queue worker.

### 2.3 Running costs

| Item | Monthly |
|---|---|
| Laravel Cloud Starter (includes US$5 of usage) | US$5 |
| Production compute, Flex 1 GiB kept awake | capped at US$12 |
| Staging compute, scale-to-zero | cents |
| Serverless Postgres and object storage at this size | a few dollars |
| Sentry, Better Stack, Resend, Cloudflare | US$0 on free tiers |
| Keeping the production database awake (section 3.3) | not known; record it in phase 0 |
| SMS | per message; get the price in step 0 |
| PayHere, from Release 3 | Plus: LKR 3,990 plus 2.99% of card payments. Lite: no monthly fee, 3.30%. Below about LKR 1.3 million of card payments a month Lite is cheaper, if its per-payment limit is high enough; check the limit in step 0. |
| Domain | annual, quote from the LK Domain Registry |

Roughly US$15 to 25 a month before PayHere, SMS and the always-on database. Set a spending limit in Laravel Cloud on day one.

Not in this table, and not engineering: translation review fees, the lawyer, the accountant's check of the invoice layout, and photography.

## 3. Architecture

### 3.1 Shape

One Laravel application with three areas:

- **Public site** under `/{locale}/...`: Blade templates rendered on the server. Interactive parts are Livewire components or small Alpine behaviours.
- **Transactional pages**: quote list, cart, checkout, customer quote page, order page. Livewire components. `noindex`.
- **Admin and CRM** at `/admin`: one Filament panel, English only, multi-factor sign-in required. Catalog management and the whole CRM (section 3.14) live here.
- **Documents**: printable quote, invoice and receipt pages (section 3.14). Token in the address, `noindex`.

Business rules live in plain PHP classes under `app/Domain` with no framework imports, so they are tested in milliseconds. Each write is one class under `app/Actions` with a `handle()` method, called by Livewire components, Filament actions and console commands alike. Controllers exist only for the PayHere notification, search, analytics events and the sitemap.

### 3.2 Where state lives

| State | Lives in | Notes |
|---|---|---|
| Catalog, prices, stock, orders, enquiries | Postgres | The only source of truth. |
| Quote list | Browser `localStorage`, key `ms_quote_v1`, as `{ items: [{ variantId, qty, estimateId? }], estimates: [{ id, productId, variantId, method, unit, rooms or planes, wastePct, layout }] }` | No table until it is submitted. Switching language keeps it. Alpine reads it and passes it to the Livewire component; the server reprices and re-runs every estimate from the database every time. |
| Cart (Release 3) | Browser `localStorage`, key `ms_cart_v1`, as `[{ variantId, qty }]` | Separate from the quote list, so a customer can hold both. |
| Visit source | Browser `localStorage`, key `ms_attr_v1` | Section 3.16. |
| Customers, leads, follow-ups, contact history, invoices | Postgres | Section 3.14. |
| Interface strings | `lang/en.json`, `si.json`, `ta.json`, plus `lang/{locale}/validation.php` | Reviewed through pull requests. |
| Translated content | JSON columns on each model | Edited in the admin with a locale switcher. Review flags per locale. |
| Images and PDFs | Object storage through the media library | Conversions generated at upload. |
| Settings | Settings classes backed by the `settings` table | Edited in the admin. |
| Sessions | Postgres (`sessions` table) | No Redis. |
| Cache | The `file` store on the instance | Section 3.3. Rate-limit counters use the `database` store so they are shared between instances. |
| Secrets | Host environment variables | Never in the repository. |

### 3.3 Caching

There is no full-page cache. Avoiding one avoids the classic trap of serving one visitor's form token to another.

Revision 2 kept the query cache in Postgres. That saved almost nothing: reading a cached result from the database is itself a database query. Revision 3 changes two things.

- **The catalog cache uses the `file` store on the application instance**, not the database. `App\Support\CatalogCache::remember($key, $callback)` prefixes every key with a `catalog_version` integer. That integer is the one value read from Postgres on each request (a single-row lookup), so every instance sees a change at once even though each keeps its own files. A deploy starts with an empty cache, which is fine.
- **The production database must not go to sleep.** A serverless Postgres that hibernates when idle makes the first visitor after a quiet spell wait for it to wake, and a low-traffic site is idle most of the day. In phase 0, find the hibernation setting on the host, turn it off for production, and record the monthly cost in 2.3. If it cannot be turned off, move production to the smallest always-on database the host offers. Staging may sleep.

Rules that are unchanged:

- Any save to a catalog model, showroom or setting increments `catalog_version` in a model observer. Old entries are never read again and expire after 24 hours.
- Budget: server time for a product page at or under 150 ms at the 95th percentile. **Measure it in phase 6 with the cache switched off first.** If the uncached page already meets the budget, keep `CatalogCache` for the search index only and delete the rest; a cache you do not need is a bug you do not have.
- Prices on a page are always current to the last save. The server still reprices at the quote list, the cart, checkout and quote acceptance.
- Sessions stay in Postgres. CRM screens are never cached.

### 3.4 Languages

- Locales `en`, `si`, `ta`. Every public URL is prefixed. A `SetLocale` middleware on the route group reads the prefix, sets the app locale and stores a `locale` cookie. The root `/` redirects using that cookie, then `Accept-Language`, then `en`.
- Slugs are shared across locales, so the language switch always lands on the equivalent page.
- `<html lang>` is the current locale. Any block that falls back to English carries `lang="en"`.
- Every public page emits a self-referencing canonical and `hreflang` alternates `en-LK`, `si-LK`, `ta-LK` and `x-default` (English), from one Blade component.
- Translated model fields fall back to English when a locale is empty. Each translatable model has `title_reviewed` and `body_reviewed` JSON arrays listing the locales a reviewer has approved.
- A product is publishable only when `title_reviewed` and `summary_reviewed` each contain all three locales (section 1.8).
- Fonts: Noto Sans Sinhala and Noto Sans Tamil, self-hosted as WOFF2 in `resources/fonts`, two weights (400 and 600), declared with `unicode-range` so a browser downloads each only when its script appears on the page. No preload.
- Numbers, prices and dimensions are never translated. They are formatted from stored integers with Western digits in every locale.
- Validation messages: only the rules the public forms use are translated, in `lang/si/validation.php` and `lang/ta/validation.php`, and reviewed as Tier 1.

### 3.5 The estimator

Pure PHP in `app/Domain/Estimator`. A Livewire component collects input and calls it; the same classes re-run on the server when an estimate is attached to an enquiry. There is one implementation.

It appears in two places: on every product page, and on a calculator page per category (`/{locale}/calculator/{category}`) where the customer enters the room first and then sees the quantity and price for each product in that category. Revision 2 forbade the calculator page. It is restored because "how many ceiling sheets do I need" is what people search for before they have chosen a product, which makes it the best landing page for search and for ads.

**Units.** All lengths are integer millimetres. Feet and inches are converted on input: 1 ft = 304.8 mm, 1 in = 25.4 mm, rounded up to the next millimetre. All division rounds up with `intdiv($n + $d - 1, $d)`, never floating point.

**Three methods.** A category sets the default; a product may override it.

| Method | Used for | Why |
|---|---|---|
| `strip` | Ceiling sheets and wall panels: long, narrow pieces laid in rows | Offcuts shorter than the room are waste, so area alone undercounts |
| `area` | Flooring and anything sold in small pieces that tile a surface | Offcuts are reused, so area plus a waste allowance is right |
| `roof_plane` | Roofing sheets | Rows and columns with overlaps |

**Method `strip`.** This replaces revision 2's area formula for ceilings, which was wrong: for a 7 ft by 10 ft room it gave 6 panels (7 with 10% waste) where the room needs 8.

Inputs per rectangle: length and width of the room. From the variant: `cover_length_mm` (P) and `cover_width_mm` (C). From settings: `fit_tolerance_mm` (T, default 20), subtracted from each room dimension first, because the perimeter trim hides a small gap. The specialist sets T.

```
layout(run, across):                       panels run along "run"
  rows = ceil(across / C)
  if run <= P:
      piecesPerPanel = floor(P / run)
      panels = ceil(rows / piecesPerPanel)          joins = no
  else:
      full = floor(run / P)
      rest = run - full * P
      if rest == 0:  panels = rows * full
      else:          panels = rows * full + ceil(rows / floor(P / rest))
      joins = yes

A = layout(length - T, width - T)          panels along the length
B = layout(width - T, length - T)          panels along the width
```

Choose the layout with fewer panels among those that need no joins. If both need joins, choose the one with fewer panels. Show the customer both, with the direction in words ("laid along the 10 ft wall") and a note where joins are needed, and let them switch.

Then `units = ceil(panels * (100 + wastePct) / 100)`, and if the variant is sold in packs, divide by `pieces_per_unit` rounding up. Offcuts are not shared between rectangles. Because cutting is already counted, the default waste for `strip` categories is 5%, covering breakage only.

Worked examples for the unit tests, panel 3650 mm by 300 mm, T = 20. All were checked on 16 November 2026 by running the arithmetic in Node; the old area result is shown for comparison only.

| Room | Millimetres | Along length (A) | Along width (B) | Chosen | With 5% | Old area method, 0% |
|---|---|---|---|---|---|---|
| 12 ft by 10 ft | 3658 by 3048 | 11 | 13 | 11, along the length | 12 | 11 |
| 7 ft by 10 ft | 2134 by 3048 | 11 | 8 | 8, along the width | 9 | 6 |
| 5 ft by 8 ft | 1524 by 2439 | 5 (two pieces per panel) | 6 | 5, along the length | 6 | 4 |
| 15 ft by 10 ft | 4572 by 3048 | 14, with joins | 16 | 16, along the width | 17 | 13 |

**Method `area`.**

```
areaMm2  = sum of (lengthMm * widthMm) over each rectangle entered
units    = ceil( areaMm2 * (100 + wastePct) / (100 * coverageMm2) )
```

Coverage of one sale unit is stored per variant as `coverage_mm2`. Default waste 10%. Test case: 3658 mm by 3048 mm is 11,149,584 mm²; with `coverage_mm2` 1,095,000 the result is 11 at 0% and 12 at 10%.

**Waste.** `wastePct` defaults to the product's `default_waste_pct`, is shown to the customer with a one-line explanation, and is adjustable from 0 to 25.

**Method `roof_plane`** (roofing sheets), always labelled provisional. **Build it only if the client confirms roofing is in the range.**

```
columns = ceil( eaveLengthMm / coverWidthMm )
rows    = max( 1, ceil( (rafterLengthMm - endLapMm) / (sheetLengthMm - endLapMm) ) )
sheets  = columns * rows            per roof plane, summed over planes
```

Revision 2 divided the rafter length by `sheetLength - endLap`, which charges an overlap to the last sheet too. For a 3,000 mm rafter and a 3,048 mm sheet with a 150 mm lap it gave 2 rows where one sheet covers the run. Test cases with that sheet and lap: rafter 3000 gives 1; 3048 gives 1; 3049 gives 2; 5946 gives 2; 5947 gives 3. A sheet no longer than the end lap is rejected.

The customer enters eave length and rafter (slope) length for each plane. The result is shown as a rough figure with "confirmed by our team before you pay", and the only action offered is "Request a quote".

**Accessories.** Each product can have rules pointing at accessory variants:

| Basis | Quantity needed |
|---|---|
| `per_m2` | area in m² × `qty_per_thousand` ÷ 1000 |
| `per_main_unit` | main units × `qty_per_thousand` ÷ 1000 |
| `per_perimeter_m` | perimeter in m × `qty_per_thousand` ÷ 1000, where perimeter = 2 × (length + width) per rectangle |
| `fixed` | `qty_per_thousand` ÷ 1000 |

Accessory sale units = ceil(quantity needed ÷ the accessory variant's `pieces_per_unit`).

**Output.** A list of lines (variant, quantity, unit price, line total), a materials subtotal, the layout chosen, and a fixed note that delivery, labour and installation are not included. Buttons: "Add all to quote list" always; "Add all to cart" only from Release 3 and only when every line is directly purchasable.

**The measurements travel with the request.** Each estimate added to the quote list keeps its inputs (rooms, unit, waste, variant, layout). On submit the server re-runs every estimate and stores one `enquiry_estimates` row per estimate, linked to the lines it produced. Staff see "Room 1: 12 ft by 10 ft, laid along the length, 5% waste" beside the items. Revision 2 stored only product and quantity in the list and one snapshot per enquiry, so the dimensions, which are the most useful thing a salesperson can be told, were lost.

A product specialist approves the formulas, the fit tolerance and the default waste percentages before Release 1. The approval is stored in `EstimatorSettings` with a name and date; until then every estimate shows a "provisional" badge.

### 3.6 Money, references and access tokens

- Money is integer LKR cents in `unsignedBigInteger` columns and PHP integers. No floating-point arithmetic on money anywhere.
- References are a prefix plus six characters from the alphabet `23456789ABCDEFGHJKMNPQRSTVWXYZ`: `E-7K3M9P` for enquiries, `Q-...` for quotes, `O-...` for orders. Generated with `random_int`, retried on the unique constraint. A quote keeps its `Q-` reference when it becomes an order.
- A reference alone grants nothing. Customer pages need the reference and a token: `bin2hex(random_bytes(32))` in the URL path. Only `hash('sha256', $token)` is stored. Compare with `hash_equals`. These pages send `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex`.
- Tokens and URLs containing them are never written to logs, Sentry or analytics.

### 3.7 Order and payment model

A quote and an order are the same table. A quote is an order in status `quoted`. Every order belongs to a customer record and has a `source`: `quote`, `counter` (staff-created walk-in sale, Release 2) or `web_checkout` (Release 3).

| From | To | Trigger | Side effects | Release |
|---|---|---|---|---|
| (none) | `quoted` | Staff send a quote built from a lead or from scratch | Sets `valid_until`. Saves a quote snapshot. No stock reserved. | 1 |
| `quoted` | `confirmed` | **Staff mark the quote accepted** on the customer's word | Tracked stock reduced. `accepted_via` = `staff`. Payment is recorded separately and may be zero at this point. | 1 |
| `quoted` | `cancelled` | Staff withdraw a quote, with a reason | None. | 1 |
| `quoted` | `expired` | Scheduled sweep, after `valid_until` | None. | 1 |
| (none) | `confirmed` | Staff create a counter sale | Tracked stock reduced. | 2 |
| `quoted` | `placed` | Customer accepts online before `valid_until` | Stock checked and reserved, `hold_expires_at` set. `accepted_via` = `online`. | 3 |
| (none) | `placed` | Customer completes checkout | Server reprices, reserves stock, sets `hold_expires_at`. | 3 |
| `placed` | `confirmed` | Required payment confirmed (gateway or staff), or staff accept pay-on-collection or cash on delivery | Clears `hold_expires_at`. | 3 |
| `placed` | `expired` | Scheduled sweep, after `hold_expires_at`, with nothing paid | Stock released. | 3 |
| `confirmed` | `processing` | Staff | None. | 1 |
| `processing` | `ready_for_collection` or `dispatched` | Staff | Customer notified by SMS (Release 2) and by email if given. | 1 |
| `ready_for_collection` or `dispatched` | `completed` | Staff | Refused while a balance is unpaid, unless an admin overrides with a reason (a customer on credit). | 1 |
| `placed`, `confirmed`, `processing` | `cancelled` | Staff, with a reason | Stock released. Refund is a separate manual record. | 1 |
| `ready_for_collection`, `dispatched` | `cancelled` | Staff, with one of the reasons `delivery_refused`, `not_collected`, `damaged_in_transit` | Stock is **not** released automatically. The order is flagged `needs_attention` until staff confirm what came back and adjust stock by hand. Any payment already recorded needs a refund record. | 1 |

The last row is new. Revision 2 had no way out of `dispatched` except `completed`, so a refused cash-on-delivery order could not be recorded truthfully.

Any other transition is rejected by `App\Domain\Order\StateMachine`. Every transition writes an `order_events` row.

Payment state is derived inside the same transaction whenever a payment changes: `unpaid`, `partially_paid`, `paid`, or `refunded`. The balance is `total_cents - paid_cents` and is shown on the order, on the customer, and in the receivables report.

"Required payment" for an online confirmation is `required_advance_cents` when staff set one on a quote, otherwise the full total.

**Holds, for every payment method.** Revision 2 defined a hold only for bank transfer and card.

| Method chosen at checkout | `hold_expires_at` | What ends the hold |
|---|---|---|
| Bank transfer | `bank_hold_hours`, default 48 | Staff confirm the transfer |
| Card | `card_hold_minutes`, default 45 | The gateway notification |
| Pay on collection | `collection_hold_hours`, default 72 | Staff accept the order |
| Cash on delivery | `cod_hold_hours`, default 24 | Staff phone the customer and accept the order |

The sweep expires a `placed` order only when nothing has been paid. A `placed` order past its hold with a part payment is flagged `needs_attention` instead, and its stock stays reserved until a person decides.

A payment that arrives for an order already `expired` or `cancelled` is recorded, and the order is flagged `needs_attention` with a note. Nothing ships automatically.

**Quote revisions.** Each time staff press "Get quote link" the lines, totals, exclusions and validity are copied into `quote_snapshots` and `quote_revision` goes up by one. The customer page and the printed quote show the revision number and date. Staff can see every earlier version; the customer sees only the latest.

### 3.8 Stock

- A variant either tracks a quantity (`stock_qty` is a number) or does not (`stock_qty` is null and `stock_status` is set by hand).
- Reserving is a conditional update inside the order transaction:
  `Variant::whereKey($id)->where('stock_qty', '>=', $n)->decrement('stock_qty', $n)`. A return value of 0 means the order fails with `out_of_stock` for that line. This is what makes two simultaneous purchases of the last unit safe.
- Every change writes a `stock_movements` row.
- Hold periods come from settings, one per payment method (the table in 3.7).
- **There is one stock figure per variant and it is only as good as the counter.** A sale at the shop that nobody enters leaves the website wrong. So: every variant stays untracked (`stock_qty` empty, `stock_status` set by hand) until counter sales exist in Release 2, and after that a variant is tracked only if the client names the person who keeps it current.
- Staff-accepted quotes and counter sales reduce tracked stock with the same conditional update. If it fails, staff are told which line is short and may override with a reason; the override is logged.

### 3.9 Checkout eligibility and payment methods

Decided on the server by `App\Domain\Order\Eligibility`, never by the browser.

A cart can be checked out when every line is `direct`, every line is available, and either collection is chosen or a delivery rate exists for the district and the cart's delivery class and the cart's total weight is at or under that rate's `max_weight_grams` (empty means no limit). The fee is flat per district and class, so the weight limit is what stops one sheet and two hundred sheets being charged the same: above it the cart goes to a quote, where staff price the delivery. A line whose variant has no weight counts as over the limit when a limit is set. The cart's delivery class is the largest among its lines in the order `small < standard < long < special`. `special` never has a rate. A cart that fails any test is offered "Request a quote for these items" with the reason shown.

| Method | Offered when |
|---|---|
| Bank transfer | Always. |
| Pay on collection | Fulfilment is showroom collection. |
| Cash on delivery | Fulfilment is delivery, delivery class is `small`, and total is at or below `cod_max_cents`. |
| Card (PayHere) | `PAYHERE_ENABLED` is true and total is at or below `payhere_max_cents` (25,000,000 cents on the Plus plan). Email becomes required, because PayHere requires it. |

Bank transfer flow: the order page shows the bank details from settings, the exact amount and the order reference, with a "Send slip on WhatsApp" button. Staff check the bank statement and confirm the payment in the admin. There is no upload feature.

### 3.10 PayHere

Protocol confirmed against PayHere's Checkout API page on 2 October 2026.

- Checkout is a browser form POST to `https://sandbox.payhere.lk/pay/checkout` (sandbox) or `https://www.payhere.lk/pay/checkout` (live).
- Required fields: `merchant_id`, `return_url`, `cancel_url`, `notify_url`, `first_name`, `last_name`, `email`, `phone`, `address`, `city`, `country`, `order_id`, `items`, `currency`, `amount`, `hash`. Optional `custom_1` and `custom_2` are returned in the notification.
- `amount` has exactly two decimals, a full stop, and no thousands separator.
- `hash = UPPER( md5( merchant_id + order_id + amount + currency + UPPER( md5( merchant_secret ) ) ) )`.
- PayHere POSTs `application/x-www-form-urlencoded` to `notify_url` with `merchant_id`, `order_id`, `payment_id`, `payhere_amount`, `payhere_currency`, `status_code`, `md5sig`, `custom_1`, `custom_2`, `method`, `status_message`, and for cards `card_holder_name`, `card_no` (masked), `card_expiry`.
- `md5sig = UPPER( md5( merchant_id + order_id + payhere_amount + payhere_currency + status_code + UPPER( md5( merchant_secret ) ) ) )`.
- `status_code`: `2` success, `0` pending, `-1` cancelled, `-2` failed, `-3` charged back.
- Nothing about the payment is passed to `return_url`. The return page only says "we are confirming your payment" and polls the order.
- The notification cannot reach a local machine. Test it on the staging environment.

Implementation rules:

- Each attempt creates a `payments` row in `pending` with `gateway_order_id = "{order.reference}-{attempt number}"`, sent as `order_id`. `custom_1` carries the payment id.
- The notify route is excluded from CSRF verification and uses no session.
- The handler, in order: store a `payment_events` row with a redacted payload; verify `md5sig`; check `merchant_id` equals the configured one; load the payment by `gateway_order_id`; check `payhere_currency` is `LKR` and `payhere_amount` equals the payment amount exactly; then apply the status inside a transaction that locks the payment row.
- Applying is idempotent. A second `2` for a confirmed payment changes nothing. A `-3` after confirmation sets `charged_back` and flags the order.
- A notification never moves a payment out of a final state except `confirmed` to `charged_back`.
- Respond `200` once the event is stored and processed, `400` on a bad signature. Never trust the browser return.

Reference implementation:

```php
<?php

namespace App\Domain\PayHere;

final class Signature
{
    public static function formatAmount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function checkoutHash(
        string $merchantId,
        string $orderId,
        int $amountCents,
        string $currency,
        string $merchantSecret,
    ): string {
        return strtoupper(md5(
            $merchantId . $orderId . self::formatAmount($amountCents) . $currency
                . strtoupper(md5($merchantSecret))
        ));
    }

    /** @param array<string, mixed> $p the notification's form fields */
    public static function notifyValid(array $p, string $merchantSecret): bool
    {
        $expected = strtoupper(md5(
            ($p['merchant_id'] ?? '') . ($p['order_id'] ?? '') . ($p['payhere_amount'] ?? '')
                . ($p['payhere_currency'] ?? '') . ($p['status_code'] ?? '')
                . strtoupper(md5($merchantSecret))
        ));

        return hash_equals($expected, (string) ($p['md5sig'] ?? ''));
    }
}
```

Fixture for the unit test. Verified on 2 October 2026 by running this exact class under PHP 8.2: both expected values match, and a wrong secret, a tampered amount and a missing signature are all rejected. It proves the class matches the documented formula; only the sandbox proves PayHere accepts it.

| Input | Value |
|---|---|
| `merchantId` | `1211149` |
| `merchantSecret` | `TEST_SECRET_DO_NOT_USE` |
| `orderId` | `O-7K3M9P-1` |
| `amountCents` | `4525050` (formats as `45250.50`) |
| `currency` | `LKR` |
| Expected checkout hash | `6D5CE86313706F7778A2FCD2EED7D65C` |
| Expected notify signature for `status_code` `2` | `D621B6B8577E1A875548AA3B6F8E5AD6` |

`formatAmount` must also give `1000.00` for 100000, `0.05` for 5 and `0.00` for 0.

### 3.11 WhatsApp handoff

No API, no automation. A link of the form `https://wa.me/{digits}?text={encoded message}`, where `{digits}` is the business number in international format without `+`.

Message templates live in the `lang` files so they are translated and reviewed like any other string. The enquiry template contains the reference, up to ten item lines (`12 × iPanel White Wood 12 ft`), the district and whether installation is wanted. It does not contain the access token or any link. If the list is longer than ten lines, the message ends with "and N more items, see reference". Build it with `rawurlencode` and keep the encoded message under 1,500 characters.

The same pattern is used on the order page ("Send bank slip") and on every product page ("Ask about this product").

### 3.12 Search

With 50 to 100 products there is no search engine. Each product has a lowercase search string per locale built from title, SKU, category name, material, finish and every synonym attached to the product or its category. The synonym table includes romanised forms, entered by the reviewers.

Normalisation applied to both the index and the query: Unicode NFC (`Normalizer::normalize`), `mb_strtolower`, remove zero-width joiner and non-joiner characters, collapse whitespace. A product matches when every query token is a substring of its search string. Title matches rank above the rest. The index is built once per locale and held in `CatalogCache`.

### 3.13 Security and privacy

- The admin panel requires app-based multi-factor sign-in for every staff member, with recovery codes. There is no public registration; an administrator creates staff accounts. Confirmed Filament 5 configuration:

```php
use Filament\Auth\MultiFactor\App\AppAuthentication;

$panel->multiFactorAuthentication([
    AppAuthentication::make()->recoverable(),
], isRequired: true);
```

  The `User` model implements `HasAppAuthentication` and `HasAppAuthenticationRecovery`, uses `InteractsWithAppAuthentication` and `InteractsWithAppAuthenticationRecovery`, and has two nullable text columns, `app_authentication_secret` and `app_authentication_recovery_codes`.
- Three roles in a `role` column: `admin`, `sales`, `catalog`. Each Filament resource and action checks the role through a policy. A user with `is_active` false cannot enter the panel.
- Public forms: validation with length limits, the honeypot middleware with a three-second minimum, and two rate limits: three enquiries or orders per phone number per hour, and thirty per hashed IP per hour. Revision 2 allowed five per IP, which would have blocked real customers, because Sri Lankan mobile networks put many subscribers behind one address. The IP is stored only as `hash_hmac('sha256', $ip, config('store.ip_hash_secret'))` and blanked after 30 days.
- Prices, eligibility and totals are always computed on the server from stored records. Livewire public properties that must not be tampered with are marked locked.
- Headers: HSTS, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy` denying camera, microphone and geolocation, and a Content Security Policy that starts in report-only mode. `form-action` must allow the two PayHere checkout hosts.
- Sentry: `send_default_pii` false, request bodies not captured, URLs under `/q/` and `/o/` scrubbed in `before_send`.
- Analytics events carry no name, phone, address, free text, reference or token.
- Staging is behind HTTP basic auth through a middleware active only when `APP_ENV=staging`, with the PayHere notify route exempt. The signature check still applies.
- Retention: leads closed as `spam` are blanked after 30 days. A customer with no order and no activity for 24 months is anonymised by the scheduled sweep: name, phone, email, address and notes blanked, contact history deleted, `anonymised_at` set. Customers with orders are kept, because orders and invoices are accounting records. State this in the privacy policy. The periods are my defaults; the lawyer confirms them.
- A CRM is a list of people's names, phone numbers and addresses. Exports of customers are limited to `admin`, every export is written to the audit trail, and a customer can be anonymised on request from their page.
- Promotional messages need recorded consent (`marketing_opt_in` with a date). Nothing in this plan sends any.
- The applicable law is the Personal Data Protection Act No. 9 of 2022. Confirm its operative status in step 0 and have the privacy policy reviewed.

### 3.14 The CRM

The CRM is the Filament panel at `/admin`. It is not a second application: it reads and writes the same tables as the website through the same Action classes. Scope is section 1.9; what follows is how it works.

**Customers.** One row per phone number in E.164 form. `App\Actions\Crm\ResolveCustomer` is the only way a customer is found or created: given a phone and a name it returns the existing customer or creates one. It runs when a website enquiry is stored, when an order is placed online, and when staff type a number into "New lead". It never overwrites a name or note that a member of staff has edited; a different name arriving for a known number is added to the contact history as a note. A customer with no phone (a company reached only by email) is allowed for staff-created records only.

**Leads.** The `enquiries` table is the lead table. A lead has a `channel` (`website`, `walk_in`, `phone`, `whatsapp`, `facebook`, `referral`, `other`), a status, an owner, an optional expected value, and a lost reason when it is closed as lost. Website leads keep the on-site entry point in `source` and their campaign details (3.16). Staff-entered leads need only a customer, a channel and one line of what was asked for.

**The customer page** is the centre of the CRM: details and tags at the top, the balance owed, and one timeline, newest first, merging leads, quotes, orders, invoices, payments, follow-ups and logged contacts. Buttons: WhatsApp, call, new lead, new quote, new follow-up, log a contact.

**Follow-ups** (Release 2). A follow-up has a due time, a kind (call, WhatsApp, visit, site measurement, other), an owner, and may point at a lead or an order. Closing one requires a one-line outcome, which is written to the contact history, and offers "set the next one". Rules that create them automatically: a new website lead creates "first response" due in `first_response_target_hours`; sending a quote creates "ask about the quote" due in `default_follow_up_days`; a quote two days from expiry creates "quote expiring". Each can be switched off in settings. The dashboard and the morning digest show today's and overdue follow-ups per person.

**Contact history** (Release 2). `interactions` rows: kind, direction, a summary line, who, when. They are typed by staff; nothing is read from WhatsApp or the phone. The WhatsApp and call buttons open a "log this contact" prompt when staff come back to the page, so logging takes one line.

**Documents.** Quotes, invoices and receipts are web pages with a print stylesheet, opened by a link that carries a token, in the customer's language. The browser prints them or saves them as PDF. There is no server-side PDF generator: the browser shapes Sinhala and Tamil text correctly, and PHP PDF libraries are unreliable at that. The cost is that a document cannot be attached to an email automatically; a link is sent instead.

**Invoice and receipt numbers.** `INV-2026-00001` and `RCP-2026-00001`, one sequence per kind per calendar year, with no gaps. The next number is taken from `document_sequences` with a row lock inside the transaction that creates the document. An invoice is never edited or deleted after issue: it is voided with a reason and a new one is issued. Its lines and totals are copied from the order at issue, so later changes to the order do not alter it.

**VAT.** If `TaxSettings::vat_number` is set, invoices are tax invoices and show the VAT inside the total. With VAT-inclusive prices, `tax = round(total × rate ÷ (100 + rate))` in integer cents. Test value: at a rate of 18, a total of 11,800,000 cents contains 1,800,000 cents of VAT, and 4,525,050 contains 690,262. The rate is a setting supplied by the client's accountant; nothing in this file asserts what the current rate is. The layout of a tax invoice must be checked by the accountant before Release 2.

**Counter sales** (Release 2). Staff create an order with source `counter` for a walk-in customer, record the payment, and the order starts at `confirmed` with stock reduced. This is what keeps the website's stock figure honest when most sales happen at the counter. Until Release 2, leave `stock_qty` empty (untracked) on every variant and set `stock_status` by hand.

**Trade prices.** A variant may have a `trade_price_cents`. The public site always shows the retail price. The quote builder pre-fills the trade price when the customer's `price_tier` is `trade`; staff can still change any line. If the client says there is one price for everyone, leave the column empty.

**Who sees what.** `sales` users see all leads and customers, with "mine" as the default filter. If the client wants each salesperson limited to their own leads, that is a policy change in phase 9, not a redesign. Reports are for `admin`.

**Reports** (Release 2) are Filament pages built on SQL over the existing tables, with a date range and an export button. There is no separate reporting store. The list is in phase 21.

### 3.15 Alerts and SMS

Revision 2 alerted staff by email only. Sales staff in this trade live on their phones, and a customer who does not tap "Send on WhatsApp" would otherwise wait on an unread inbox.

- `App\Domain\Sms\Message` builds the text; `App\Support\Sms\SmsSender` is an interface with two drivers, `log` (local and tests) and `http` (the provider chosen in step 0). The provider is not chosen and its interface is not verified; the driver is written in phase 8 from the provider's own documentation.
- **Staff alerts, Release 1:** a new website lead sends one SMS to the numbers in `CrmSettings::staff_alert_phones` with the reference, the district and the first item. No customer name or number in the text.
- **Customer messages, Release 2:** order link when an order is created, payment received, ready for collection or dispatched. Sent in the customer's language, only for things they asked for. Every message is stored in `interactions`.
- **The order link by SMS is what lets a customer without email get back to their order.** In revision 2 the link existed only in the browser tab.
- Messages are sent with `defer()` after the response. A failed send is reported to Sentry and never rolls back the order or the lead.
- Sinhala and Tamil text uses a longer encoding, so one message holds about 70 characters instead of 160. Keep every template under 70 characters in those languages, or accept that it is billed as two.
- No promotional messages. `customers.marketing_opt_in` exists so that consent is recorded from day one, but nothing sends on it.

### 3.16 Where leads come from

Revision 2 could not tell which advertisement or post produced an enquiry. A new domain will depend on paid and social traffic for months, so this is recorded from the first day.

- On the first page view of a visit, a small script stores in `localStorage` (key `ms_attr_v1`, kept 30 days): `utm_source`, `utm_medium`, `utm_campaign` from the address, whether a Facebook or Google click identifier was present (a yes or no, never the identifier itself), the landing path and the referring host.
- The values are sent with the enquiry form and the checkout form and stored on the lead or order. They describe the visit, not the person, and the privacy policy says so.
- Staff-entered leads have a `channel` instead.
- The "lead sources" report groups won and lost leads and their value by channel and campaign.
- Tell the client to put campaign tags on every link they post or advertise. Without them every visit shows as "direct".

## 4. Data model

Verification status: the migration below passes PHP's syntax check, but it has not been run against a database, because the only PHP on the machine (8.2) cannot run Laravel 13. Running it is the first verification step of phase 1. The revision 2 structure mirrors the Prisma schema in the Next.js variant, which did pass its validator. The revision 3 additions (customers, enquiry estimates, quote snapshots, follow-ups, interactions, document sequences, invoices, and the new columns) have no such cross-check.

### 4.1 Conventions

- Table and column names are `snake_case`. Primary keys are auto-increment big integers; nothing public uses them (customers see references).
- Enumerations are PHP backed enums stored as lowercase strings, cast on the model.
- Translated fields are `jsonb` columns shaped `{"en": "...", "si": "...", "ta": "..."}`, handled by the `HasTranslations` trait. Every JSON column in this schema is `jsonb`, never `json`: Postgres cannot compare two `json` values, so any query that uses `DISTINCT`, `GROUP BY` or `UNION` over a table with a `json` column fails, and Filament generates such queries.
- Money is `unsignedBigInteger` cents. Lengths are integer millimetres, thickness integer microns, weight integer grams.
- Images and documents use the media library's own `media` table. Audit entries use the activity log's `activity_log` table. Settings use the settings package's `settings` table. Publish those three package migrations in phase 1; they are not repeated here.

### 4.2 Enums

All in `app/Enums`, each a `string`-backed enum.

| Enum | Values |
|---|---|
| `UserRole` | `admin`, `sales`, `catalog` |
| `TermKind` | `material`, `finish`, `use` |
| `SaleUnit` | `sheet`, `panel`, `box`, `pack`, `piece`, `metre`, `roll` |
| `PurchaseMode` | `direct`, `quote_only` |
| `StockStatus` | `in_stock`, `made_to_order`, `out_of_stock` |
| `DeliveryClass` | `small`, `standard`, `long`, `special` (with an `order(): int` method for comparison) |
| `EstimatorMethod` | `none`, `strip`, `area`, `roof_plane` |
| `AccessoryBasis` | `per_m2`, `per_main_unit`, `per_perimeter_m`, `fixed` |
| `SynonymScript` | `en`, `si`, `ta`, `roman_si`, `roman_ta` |
| `ContactChannel` | `whatsapp`, `call`, `email` |
| `EnquirySource` | `product_page`, `estimator`, `quote_list`, `installation_page`, `cart_ineligible` |
| `EnquiryStatus` | `new`, `contacted`, `quoted`, `won`, `lost`, `spam` |
| `OrderStatus` | `quoted`, `placed`, `confirmed`, `processing`, `ready_for_collection`, `dispatched`, `completed`, `cancelled`, `expired` |
| `PaymentState` | `unpaid`, `partially_paid`, `paid`, `refunded` |
| `PaymentMethod` | `bank_transfer`, `pay_on_collection`, `cash_on_delivery`, `payhere` |
| `PaymentStatus` | `pending`, `confirmed`, `failed`, `cancelled`, `refunded`, `charged_back` |
| `FulfilmentMethod` | `delivery`, `collection` |
| `OrderLineKind` | `product`, `delivery`, `labour`, `other`, `discount` |
| `StockReason` | `order_placed`, `order_released`, `counter_sale`, `manual_adjust`, `import` |
| `CustomerType` | `homeowner`, `contractor`, `dealer`, `company` |
| `PriceTier` | `retail`, `trade` |
| `LeadChannel` | `website`, `walk_in`, `phone`, `whatsapp`, `facebook`, `referral`, `other` |
| `OrderSource` | `quote`, `counter`, `web_checkout` |
| `AcceptedVia` | `staff`, `online` |
| `FollowUpKind` | `call`, `whatsapp`, `visit`, `site_measure`, `other` |
| `InteractionKind` | `call`, `whatsapp`, `visit`, `email`, `sms`, `note` |
| `InteractionDirection` | `inbound`, `outbound`, `internal` |
| `InvoiceStatus` | `issued`, `void` |
| `DocumentKind` | `invoice`, `receipt` |

`EnquirySource` is where on the website a lead started; `LeadChannel` is how it reached the business. A staff-entered lead has a channel and the source `staff`; add `staff` to `EnquirySource`.

### 4.3 Migration

`database/migrations/2026_10_02_000000_create_store_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- Staff ----------
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('sales');
            $table->boolean('is_active')->default(true);
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });

        // ---------- Catalog ----------
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->jsonb('title_reviewed')->default('[]');
            $table->string('estimator_method')->default('none');
            $table->unsignedTinyInteger('default_waste_pct')->default(10);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('code');
            $table->jsonb('label');
            $table->jsonb('title_reviewed')->default('[]');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['kind', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('category_id')->constrained();
            $table->string('brand')->nullable();
            $table->foreignId('material_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->string('purchase_mode')->default('quote_only');
            $table->string('delivery_class')->default('standard');
            $table->string('estimator_method')->nullable();          // null = inherit from category
            $table->unsignedTinyInteger('default_waste_pct')->nullable(); // null = inherit
            $table->unsignedInteger('end_lap_mm')->nullable();        // roof_plane only
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->jsonb('title');
            $table->jsonb('summary')->nullable();
            $table->jsonb('description')->nullable();                  // Markdown per locale
            $table->jsonb('care')->nullable();
            $table->jsonb('seo_title')->nullable();
            $table->jsonb('seo_description')->nullable();
            $table->jsonb('title_reviewed')->default('[]');            // e.g. ["en","si"]
            $table->jsonb('summary_reviewed')->default('[]');
            $table->jsonb('body_reviewed')->default('[]');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['category_id', 'is_published', 'sort_order']);
        });

        Schema::create('product_use', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'term_id']);
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->foreignId('finish_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->string('colour_hex', 7)->nullable();
            $table->unsignedInteger('length_mm')->nullable();         // nominal manufactured size
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('cover_length_mm')->nullable();   // effective, after overlaps
            $table->unsignedInteger('cover_width_mm')->nullable();
            $table->unsignedBigInteger('coverage_mm2')->nullable();   // of ONE sale unit; drives the estimator
            $table->unsignedInteger('thickness_micron')->nullable();  // 7.5 mm = 7500, 0.47 mm = 470
            $table->unsignedInteger('weight_grams')->nullable();      // of one sale unit
            $table->string('sale_unit');
            $table->unsignedInteger('pieces_per_unit')->default(1);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('trade_price_cents')->nullable(); // quote builder only; never shown publicly
            $table->integer('stock_qty')->nullable();                 // null = not tracked
            $table->string('stock_status')->default('in_stock');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        Schema::create('accessory_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accessory_variant_id')->constrained('variants')->cascadeOnDelete();
            $table->string('basis');
            $table->unsignedInteger('qty_per_thousand');              // 12 per m2 = 12000; 0.5 per unit = 500
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'accessory_variant_id']);
        });

        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('term');                                   // stored normalised
            $table->string('script');
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('term');
        });

        // ---------- Places and delivery ----------
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                         // "colombo"
            $table->string('province');
            $table->jsonb('name');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_class');
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->unsignedInteger('max_weight_grams')->nullable();  // null = no limit; heavier carts go to a quote
            $table->unsignedSmallInteger('lead_days_min')->default(1);
            $table->unsignedSmallInteger('lead_days_max')->default(3);
            $table->boolean('is_available')->default(false);
            $table->timestamps();
            $table->unique(['district_id', 'delivery_class']);
        });

        Schema::create('showrooms', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('district_id')->constrained();
            $table->jsonb('name');
            $table->jsonb('address');
            $table->jsonb('notes')->nullable();
            $table->jsonb('title_reviewed')->default('[]');
            $table->string('phone');
            $table->string('whatsapp')->nullable();
            $table->string('maps_url');
            $table->jsonb('hours');                                    // {"mon":["08:30","17:30"],...,"sun":null}
            $table->date('hours_verified_on')->nullable();
            $table->boolean('offers_collection')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---------- Customers (CRM) ----------
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164', 16)->nullable()->unique();   // null only on staff-created records
            $table->string('name');
            $table->boolean('name_edited_by_staff')->default(false);
            $table->string('type')->default('homeowner');
            $table->string('price_tier')->default('retail');
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_locale', 2)->default('en');
            $table->string('preferred_channel')->default('whatsapp');
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
            $table->index('name');
            $table->index('last_activity_at');
        });

        // ---------- Leads (the enquiries table) ----------
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();                    // E-7K3M9P
            $table->string('token_hash', 64);
            $table->string('locale', 2);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel')->default('website');
            $table->string('source');
            $table->string('status')->default('new');
            $table->string('name');
            $table->string('phone_e164', 16)->nullable();              // null only on staff-entered leads
            $table->string('email')->nullable();
            $table->string('preferred_channel')->default('whatsapp');
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('needs_installation')->default(false);
            $table->boolean('measurements_known')->default(true);
            $table->text('project_notes')->nullable();
            $table->unsignedBigInteger('expected_value_cents')->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->boolean('ad_click')->default(false);
            $table->string('landing_path')->nullable();
            $table->string('referrer_host')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->string('closed_reason')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['ip_hash', 'created_at']);
        });

        Schema::create('enquiry_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->jsonb('input');                                   // rooms or planes, unit, waste, layout chosen
            $table->jsonb('result');                                  // re-run on the server at submit
            $table->unsignedSmallInteger('formula_version');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('enquiry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enquiry_estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');                            // snapshot
            $table->unsignedInteger('qty');
        });

        // ---------- Quotes and orders (one table) ----------
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();                    // Q-... or O-...
            $table->string('token_hash', 64);
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('quote');
            $table->string('locale', 2);
            $table->string('status');
            $table->string('payment_state')->default('unpaid');
            $table->string('fulfilment')->nullable();
            $table->foreignId('showroom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone_e164', 16);
            $table->string('email')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('delivery_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);      // VAT contained in the total
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->unsignedBigInteger('required_advance_cents')->nullable();
            $table->text('exclusions')->nullable();                   // quote: what is NOT included
            $table->timestamp('valid_until')->nullable();             // quote validity
            $table->timestamp('hold_expires_at')->nullable();         // stock hold on placed orders
            $table->unsignedSmallInteger('quote_revision')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_via')->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->boolean('ad_click')->default(false);
            $table->boolean('needs_attention')->default(false);
            $table->string('attention_note')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('hold_expires_at');
            $table->index('needs_attention');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('product');
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();                        // snapshot
            $table->string('description');                            // snapshot, in the order's locale
            $table->string('sale_unit')->nullable();
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_price_cents');                   // snapshot; negative for discount
            $table->bigInteger('line_total_cents');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('method');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->string('gateway_order_id')->nullable()->unique(); // "{reference}-{attempt}"
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('gateway_method')->nullable();             // VISA, MASTER, ...
            $table->string('bank_reference')->nullable();
            $table->string('receipt_number')->nullable()->unique();   // RCP-2026-00001, set when confirmed
            $table->string('note')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');                                 // "payhere-notify" | "staff"
            $table->string('status_code')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->jsonb('payload');                                  // redacted: no card holder name, no md5sig
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type');                                   // status | payment | note | attention
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained();
            $table->integer('delta');
            $table->string('reason');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['variant_id', 'created_at']);
        });

        // ---------- CRM: quote history, follow-ups, contact history ----------
        Schema::create('quote_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->jsonb('lines');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('required_advance_cents')->nullable();
            $table->text('exclusions')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['order_id', 'revision']);
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->default('call');
            $table->string('title');
            $table->timestamp('due_at');
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_automatic')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('outcome')->nullable();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assigned_to_id', 'completed_at', 'due_at']);
            $table->index(['customer_id', 'due_at']);
        });

        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('direction')->default('outbound');
            $table->string('summary', 500);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = sent by the system
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'occurred_at']);
        });

        // ---------- CRM: invoices and receipts ----------
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('kind');                                   // invoice | receipt
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->unique(['kind', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                       // INV-2026-00001
            $table->string('token_hash', 64);
            $table->foreignId('order_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('issued');
            $table->string('locale', 2);
            $table->jsonb('customer_snapshot');                       // name, address, VAT number at issue
            $table->jsonb('lines');                                   // copied from the order at issue
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('delivery_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->unsignedSmallInteger('vat_rate_bp')->nullable();  // basis points, 18% = 1800; null = not a tax invoice
            $table->string('seller_vat_number')->nullable();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['status', 'due_on']);
        });

        // ---------- Analytics ----------
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('locale', 2);
            $table->string('device', 8);                              // mobile | tablet | desktop
            $table->foreignId('product_id')->nullable();
            $table->foreignId('category_id')->nullable();
            $table->jsonb('meta')->nullable();                         // never personal data
            $table->timestamp('created_at')->useCurrent();
            $table->index(['name', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'analytics_events', 'invoices', 'document_sequences', 'interactions',
            'follow_ups', 'quote_snapshots', 'stock_movements', 'order_events',
            'payment_events', 'payments', 'order_lines', 'orders', 'enquiry_items',
            'enquiry_estimates', 'enquiries', 'customers',
            'showrooms', 'delivery_rates', 'districts', 'search_synonyms',
            'accessory_rules', 'variants', 'product_use', 'products', 'terms', 'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role', 'is_active', 'app_authentication_secret', 'app_authentication_recovery_codes',
            ]);
        });
    }
};
```

### 4.4 Models

One Eloquent model per table in `app/Models`. Rules that are not obvious from the migration:

| Model | Rules |
|---|---|
| `User` | Casts `role` to `UserRole`. Implements Filament's `FilamentUser` with `canAccessPanel()` returning `is_active`. Implements the two multi-factor interfaces from 3.13. |
| `Category`, `Term`, `Product`, `Showroom`, `District` | Use `HasTranslations`; list translatable attributes in `$translatable`. Cast the `*_reviewed` columns to `array`. |
| `Product` | `isPublishable(): bool` is true when `title_reviewed` and `summary_reviewed` each contain `en`, `si` and `ta`, at least one active variant exists, and at least one image exists. `effectiveEstimatorMethod()` and `effectiveWastePct()` fall back to the category. Registers media collections `images` (custom properties `alt` as a per-locale map, and optional `variant_id`) and `documents` (custom properties `kind` and optional `locale`). Image conversions: WebP at widths 400, 800 and 1200, not queued. Because there is no queue, the admin upload field resizes each photo in the browser to at most 2000 pixels wide before sending and rejects files over 8 MB, so a phone photo cannot time out the request. |
| `Variant` | Casts enums. `pricePerSqFtCents(): ?int` derived from `price_cents` and `coverage_mm2`. `displayLength()` returns millimetres, centimetres and feet-inches from the single stored value. |
| `Order` | Casts enums and timestamps. `recalculateTotals()` and `recalculatePaymentState()` are called inside the transaction that changes lines or payments. `requiredPaymentCents()` returns the advance if set, otherwise the total. |
| `Enquiry`, `Order`, `Invoice` | Never expose `token_hash` or `ip_hash` (in `$hidden`). |
| `Customer` | Casts `type`, `price_tier` and `tags`. Created and found only through `ResolveCustomer`. `balanceCents()` is the sum of `total_cents - paid_cents` over its orders that are `confirmed` or later and not cancelled. `timeline()` merges leads, orders, invoices, payments, follow-ups and interactions by date. A customer with `merged_into_id` set is hidden everywhere and redirects to the surviving record. An anonymised customer shows as "Removed customer" and cannot be edited. |
| `Enquiry` | Is the lead. Belongs to a customer. `estimates()` has many `EnquiryEstimate`; `items()` has many, each optionally pointing at the estimate that produced it. |
| `Order` | Belongs to a customer. `balanceCents()`. Has many `QuoteSnapshot` and `Invoice`. `activeInvoice()` returns the one invoice whose status is `issued`, or null. |
| `Invoice` | Never updated after creation except to void it. A second `issued` invoice for the same order is refused. |
| `FollowUp` | Scopes `open()`, `overdue()`, `dueToday()`, `for(User)`. |
| All catalog models, `Showroom` | A shared observer increments `catalog_version` on save and delete. |
| `Product`, `Variant`, `Order`, `Payment`, `Enquiry`, `Customer`, `Invoice`, `DeliveryRate`, `User` | Use the activity log trait, logging changed attributes only. `token_hash`, `ip_hash` and the multi-factor columns are excluded. |

### 4.5 Settings

Typed classes in `app/Settings`, each with a settings migration that sets the defaults and a Filament settings page.

| Class | Properties | Defaults |
|---|---|---|
| `BusinessSettings` | `name`, `phone`, `whatsapp_digits`, `email`, `address` (per-locale map), `response_hours` | placeholders |
| `BankSettings` | `account_name`, `bank_name`, `branch`, `account_number` | empty; bank transfer is hidden until filled |
| `CheckoutSettings` | `bank_hold_hours`, `card_hold_minutes`, `collection_hold_hours`, `cod_hold_hours`, `payhere_max_cents`, `cod_max_cents` | `48`, `45`, `72`, `24`, `25000000`, `2500000` |
| `CrmSettings` | `staff_alert_phones` (list), `first_response_target_hours`, `default_follow_up_days`, `auto_follow_ups_enabled`, `digest_time` | empty, `4`, `2`, `true`, `08:00` |
| `DocumentSettings` | `invoice_due_days`, `quote_footer`, `invoice_footer` (each footer a per-locale map), `show_bank_details_on_invoice` | `0`, empty, empty, `true` |
| `QuoteSettings` | `validity_days`, `default_advance_pct` | `7`, `50` |
| `TaxSettings` | `prices_include_vat`, `vat_rate_pct`, `vat_number` | `true`, `null`, `null` |
| `EstimatorSettings` | `approved_by`, `approved_on`, `formula_version`, `fit_tolerance_mm` | `null`, `null`, `1`, `20` |

### 4.6 Seed data

`database/seeders/DatabaseSeeder.php` calls idempotent seeders (`updateOrCreate` on natural keys):

- The 25 districts. English names and provinces are below. Sinhala and Tamil names are entered and approved by the reviewers; seed them as the English name until then so nothing is blank.
- Five top-level categories with slugs `roofing`, `ceilings`, `wall-panels`, `flooring`, `accessories`, estimator methods `roof_plane`, `strip`, `strip`, `area`, `none`, and default waste 10, 5, 5, 10, 0. **Only `ceilings` and `accessories` are seeded active.** The other three are seeded with `is_active` false and appear nowhere on the site until the client confirms the range and an admin switches them on.
- One `document_sequences` row per kind for the current year is not seeded; `NextDocumentNumber` creates it on first use.
- One `delivery_rates` row per district and delivery class except `special`, all with `is_available = false`, so nothing is deliverable until the business fills in the matrix.
- One `admin` user for the email in `SEED_ADMIN_EMAIL` with a random password; they set their own through the password-reset link and enrol in multi-factor sign-in on first login.
- In local and staging only: six sample products across the categories with realistic variants, so every page renders.

| Province | Districts |
|---|---|
| Western | Colombo, Gampaha, Kalutara |
| Central | Kandy, Matale, Nuwara Eliya |
| Southern | Galle, Matara, Hambantota |
| Northern | Jaffna, Kilinochchi, Mannar, Vavuniya, Mullaitivu |
| Eastern | Batticaloa, Ampara, Trincomalee |
| North Western | Kurunegala, Puttalam |
| North Central | Anuradhapura, Polonnaruwa |
| Uva | Badulla, Monaragala |
| Sabaragamuwa | Ratnapura, Kegalle |

### 4.7 Catalog import format

One CSV row per variant. The product columns repeat on each of its variants and must agree.

```
product_slug, category_slug, brand, material_code, use_codes, purchase_mode,
delivery_class, warranty_months, title_en, title_si, title_ta, summary_en,
summary_si, summary_ta, sku, finish_code, colour_hex, length_mm, width_mm,
cover_length_mm, cover_width_mm, coverage_mm2, thickness_micron, weight_grams,
sale_unit, pieces_per_unit, price_lkr, trade_price_lkr, stock_qty, stock_status
```

`trade_price_lkr` may be empty.

`use_codes` is separated by `|`. `price_lkr` is a decimal with at most two places, converted to cents by string handling, not floating point. An empty `stock_qty` means untracked.

The importer always runs as a dry run first and returns a report. It refuses to write if any row has one of these errors: duplicate SKU in the file; unknown category, term code, sale unit, delivery class or purchase mode; missing English title; non-positive price; `coverage_mm2` missing on a product whose estimator method is `area`; `cover_length_mm` or `cover_width_mm` missing on a product whose estimator method is `strip`; a trade price above the retail price; `coverage_mm2` larger than `length_mm × width_mm × pieces_per_unit`; `cover_width_mm` missing on a `roof_plane` product; product columns that disagree between rows of the same product.

Warnings that do not block: missing Sinhala or Tamil title (the product imports unpublished), missing weight, missing images.

Imports upsert by `sku` and `product_slug` inside one transaction. They never delete. Imported titles are not marked reviewed.

This is a custom importer rather than Filament's built-in import action, because that one accepts or rejects row by row and cannot check that rows of the same product agree.

## 5. Application surface

### 5.1 Conventions

- Every write is one class in `app/Actions` with a single public `handle()` method. Livewire components, Filament actions and console commands call these; none of them contain business rules themselves.
- Actions take scalars, enums or small readonly data objects from `app/Data`, and return a data object.
- Expected failures throw a `App\Exceptions\StoreException` carrying a code and a translation key. Callers catch it and show the translated message.

```php
enum StoreError: string
{
    case Validation = 'validation';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case OutOfStock = 'out_of_stock';
    case NotEligible = 'not_eligible';
    case PriceChanged = 'price_changed';
    case Expired = 'expired';
    case InvalidTransition = 'invalid_transition';
}
```

- Phone numbers are accepted as `07XXXXXXXX`, `0XXXXXXXXX` or `+94XXXXXXXXX`, validated with the `phone:LK` rule and stored in E.164 form.
- A cart item is `['variantId' => int, 'qty' => int]` with `qty` from 1 to 9999. A quote-list item may also carry `'estimateId' => string`, naming one of the estimates sent with it (shape in 3.2). At most 20 estimates and 60 items per request.
- A customer is never created or looked up directly from a component or a Filament resource; always through `ResolveCustomer`.

### 5.2 Routes

`routes/web.php`. Everything under `{locale}` is constrained to `en|si|ta` and passes through `SetLocale`.

| Method and path | Handler | Notes |
|---|---|---|
| `GET /` | `RootRedirectController` | Cookie, then `Accept-Language`, then `en` |
| `GET /up` | Laravel health route | Used by the uptime monitor |
| `GET /sitemap.xml` | `SitemapController` | All public URLs with alternates |
| `POST /events` | `AnalyticsEventController` | CSRF-exempt, throttled, name must be in the allow-list, returns `204` |
| `GET /{locale}` | `HomeController` | |
| `GET /{locale}/c/{category:slug}` | `CategoryController` | Filters in the query string. 404 unless the category is active |
| `GET /{locale}/p/{product:slug}` | `ProductController` | 404 unless published |
| `GET /{locale}/calculator/{category:slug}` | Livewire `Calculator` | Indexable. 404 unless the category is active and has an estimator method |
| `GET /{locale}/search` | `SearchController@page` | |
| `GET /{locale}/search/suggest?q=` | `SearchController@suggest` | JSON, max 20 results, `q` max 80 characters |
| `GET /{locale}/quote` | Livewire `QuoteList` | noindex |
| `GET /{locale}/quote/sent` | `QuoteSentController` | Reads the reference and WhatsApp link from the session flash; shows a generic message if opened directly |
| `GET /{locale}/q/{reference}/{token}` | Livewire `QuoteView` | Token middleware, noindex. Prints through its stylesheet |
| `GET /{locale}/i/{number}/{token}` | Livewire `InvoiceView` | Token middleware, noindex. Release 2 |
| `GET /{locale}/showrooms`, `/{locale}/showrooms/{showroom:slug}` | `ShowroomController` | |
| `GET /{locale}/installation` | `PageController` | |
| `GET /{locale}/guides`, `/{locale}/guides/{slug}` | `GuideController` | Markdown files |
| `GET /{locale}/{page}` for `about`, `contact`, `faq`, `delivery`, `returns`, `warranty`, `privacy`, `terms` | `PageController` | |
| `GET /{locale}/cart` | Livewire `Cart` | Release 3 |
| `GET /{locale}/checkout` | Livewire `Checkout` | Release 3 |
| `GET /{locale}/o/{reference}/{token}` | Livewire `OrderView` | Token middleware, noindex. Release 3 |
| `GET /{locale}/o/{reference}/{token}/paying` | Livewire `OrderPaying` | PayHere return page; polls. Release 3 |
| `POST /payhere/notify` | `PayHereNotifyController` | CSRF-exempt, signature-verified, returns `200` or `400`. Release 3 |
| `GET /{locale}/compare` | Livewire `Compare` | Release 4 |
| `/admin/...` | Filament panel | Staff only. The CRM, including receipt print pages, which need a signed-in user and no token |

Allowed analytics event names: `product_view`, `variant_select`, `estimator_start`, `estimator_result`, `calculator_start`, `calculator_result`, `quote_list_add`, `enquiry_start`, `enquiry_submit`, `whatsapp_click`, `call_click`, `search`, `search_no_results`, `cart_add`, `checkout_start`, `order_placed`, `payment_confirmed`. The last two are written on the server from order records, not from the browser.

### 5.3 Livewire components (public site)

| Component | Where | State and behaviour |
|---|---|---|
| `Estimator` | Product page | Locked `productId`. Inputs: selected variant, unit (`ft` or `m`), rectangles or roof planes, waste percentage. `calculate()` calls the estimator domain classes and renders the itemised result. For `strip` products it shows both layouts and lets the customer switch. `addToQuoteList()` and, in Release 3, `addToCart()` dispatch a browser event with the lines and the estimate's inputs; Alpine writes them to `localStorage`. |
| `Calculator` | `/calculator/{category}` | Locked `categoryId`. The customer enters rooms once; the component runs the estimator for every published product in the category and lists each with its quantity, price and materials subtotal, cheapest first. Choosing one adds that estimate to the quote list. Uses the same domain classes as `Estimator`. |
| `QuoteList` | `/quote` | Alpine passes the stored items on load through `setItems(array $items)`. Shows priced lines from `PriceCart`. Form fields: district, installation, measurements known, notes, name, phone, preferred channel, optional email. The stored estimates and the visit source (3.16) are sent with the form. `submit()` calls `SubmitEnquiry`, flashes the result, tells Alpine to clear the list, and redirects to `/quote/sent`. |
| `QuoteView` | `/q/...` | Read-only priced quote showing its revision number and date, with a Print button (print stylesheet, section 3.14). In Release 3 it gains the acceptance form and calls `AcceptQuote`. |
| `InvoiceView` | `/i/...` | Release 2. Read-only invoice with the payments received against it and their receipt numbers, and a Print button. |
| `Cart` | `/cart` | `setItems()`, priced lines, quantity changes echoed back to `localStorage`, explanation of removed or changed lines, eligibility message. |
| `Checkout` | `/checkout` | Fulfilment, district or showroom, address, contact, payment method. Recomputes options through `GetCheckoutOptions` whenever fulfilment or district changes. `placeOrder()` calls `PlaceOrder` with the total the customer was shown. |
| `OrderView` | `/o/...` | Status, lines, totals, payment instructions. `payByCard()` calls `StartCardPayment` and renders an auto-submitting form to PayHere. |
| `OrderPaying` | `/o/.../paying` | Polls every three seconds for up to two minutes, then shows "we will confirm by message". |

Header quote-list and cart counts are plain Alpine reading `localStorage`; they involve no server call.

### 5.4 Actions

Signature notation, not runnable PHP: each line is `ClassName::handle(parameters): ReturnType`, with the shape of the data objects in comments.

```text
// app/Actions/Cart
PriceCart::handle(string $locale, array $items): PricedCart
// PricedCart: lines[] (variantId, sku, productSlug, title, variantLabel, saleUnit, qty,
//   unitPriceCents, lineTotalCents, purchaseMode, availability, availableQty),
//   removedVariantIds[], subtotalCents, checkoutEligible, ineligibleReasons[]

// app/Actions/Enquiry
SubmitEnquiry::handle(EnquiryInput $input, string $ip): EnquiryResult
// EnquiryInput: locale, source, items[], name, phone, email?, preferredChannel,
//   districtCode?, needsInstallation, measurementsKnown, projectNotes? (max 1000),
//   estimates[] (each re-run on the server and stored as an enquiry_estimates row),
//   attribution? (utmSource, utmMedium, utmCampaign, adClick, landingPath, referrerHost)
// Calls ResolveCustomer, stores the lead with channel "website", then defers the
//   staff email and the staff SMS.
// EnquiryResult: reference, whatsappUrl

// app/Actions/Checkout   (Release 3)
GetCheckoutOptions::handle(string $locale, array $items, FulfilmentMethod $fulfilment,
    ?string $districtCode): CheckoutOptions
// CheckoutOptions: eligible, reasons[], deliveryClass, deliveryCents?, leadDaysMin?,
//   leadDaysMax?, collectionShowrooms[], subtotalCents, totalCents?, paymentMethods[]

PlaceOrder::handle(OrderInput $input, int $expectedTotalCents, string $ip): PlacedOrder
// OrderInput: locale, items[], contact fields, fulfilment, showroomId?, districtCode?,
//   addressLine1?, addressLine2?, city?, deliveryNotes?, paymentMethod
// PlacedOrder: reference, token, paymentNext
// Throws PriceChanged, NotEligible, OutOfStock, RateLimited

AcceptQuote::handle(Order $quote, FulfilmentMethod $fulfilment, ?int $showroomId,
    ?AddressInput $address, PaymentMethod $method, bool $advanceOnly, ?string $email): PlacedOrder
// Throws Expired, OutOfStock

StartCardPayment::handle(Order $order, ?int $amountCents = null): PayHereForm
// PayHereForm: action (URL), fields (array<string, string>)

// app/Actions/Payment
HandlePayHereNotification::handle(array $fields): bool      // false = bad signature
RecordPayment::handle(Order $order, PaymentMethod $method, int $amountCents,
    ?string $bankReference, ?string $note, User $by): Payment
RecordRefund::handle(Payment $payment, string $note, User $by): void   // records only; moves no money

// app/Actions/Order
TransitionOrder::handle(Order $order, OrderStatus $to, ?User $by, ?string $note = null,
    ?string $cancelReason = null): Order                       // throws InvalidTransition
CreateQuoteFromEnquiry::handle(Enquiry $enquiry, User $by): Order
SaveQuoteLines::handle(Order $quote, array $lines, ?int $requiredAdvanceCents,
    ?string $exclusions, CarbonImmutable $validUntil, User $by): Order
RotateOrderToken::handle(Order $order): string                 // returns the new plain token
SweepExpired::handle(): SweepResult
// SweepResult: expiredOrders, expiredQuotes, releasedUnits, anonymisedEnquiries, purgedIpHashes

// app/Actions/Order   (added in revision 3)
SendQuote::handle(Order $quote, User $by): string
// Saves a quote_snapshots row, raises quote_revision, rotates the token, returns the link.
AcceptQuoteByStaff::handle(Order $quote, User $by, ?string $note = null,
    bool $overrideStock = false): Order                        // quoted -> confirmed; throws Expired, OutOfStock
CreateCounterSale::handle(Customer $customer, array $lines, User $by,
    bool $overrideStock = false): Order                        // Release 2; starts at confirmed

// app/Actions/Crm
ResolveCustomer::handle(?string $phone, string $name, ?string $locale = null,
    ?string $districtCode = null, ?User $by = null): Customer  // find by phone or create
CreateLead::handle(Customer $customer, LeadChannel $channel, string $summary,
    array $items, ?int $expectedValueCents, User $by): Enquiry
CloseLead::handle(Enquiry $lead, EnquiryStatus $status, ?string $reason, User $by): Enquiry
// A reason is required for "lost".
AnonymiseCustomer::handle(Customer $customer, ?User $by): void
SendSms::handle(string $phoneE164, string $templateKey, array $params, string $locale,
    ?Customer $customer = null): void                          // deferred; never throws to the caller

// app/Actions/Crm   (Release 2)
ScheduleFollowUp::handle(Customer $customer, FollowUpKind $kind, string $title,
    CarbonImmutable $dueAt, ?User $assignee, ?Enquiry $lead = null, ?Order $order = null,
    bool $automatic = false): FollowUp
CompleteFollowUp::handle(FollowUp $followUp, string $outcome, User $by,
    ?CarbonImmutable $nextDueAt = null): ?FollowUp             // logs an interaction; returns the next one if set
LogInteraction::handle(Customer $customer, InteractionKind $kind,
    InteractionDirection $direction, string $summary, ?User $by,
    ?Enquiry $lead = null, ?Order $order = null): Interaction
MergeCustomers::handle(Customer $keep, Customer $duplicate, User $by): Customer
// Moves leads, orders, invoices, follow-ups and interactions to $keep in one transaction.
ImportCustomers::handle(string $csv, bool $commit, User $by): ImportReport
SendDailyDigest::handle(): int                                 // returns the number of staff messaged

// app/Actions/Invoice   (Release 2)
NextDocumentNumber::handle(DocumentKind $kind, int $year): string   // row lock; call inside a transaction
IssueInvoice::handle(Order $order, ?CarbonImmutable $dueOn, User $by): IssuedInvoice
// IssuedInvoice: invoice, token (plain, shown once)
VoidInvoice::handle(Invoice $invoice, string $reason, User $by): Invoice
RotateInvoiceToken::handle(Invoice $invoice): string

// app/Actions/Catalog
ImportCatalog::handle(string $csv, bool $commit, User $by): ImportReport
// ImportReport: rows, errors[] (row, column, message), warnings[], created, updated
AdjustStock::handle(Variant $variant, ?int $newQty, string $note, User $by): void
```

`PlaceOrder` runs in one database transaction: reload variants, recompute lines and total, compare with `$expectedTotalCents`, re-check eligibility, reserve tracked stock with the conditional update, insert the order, lines, the pending payment, stock movements and the first order event. The staff alert is sent with `defer()` after the response; a failed send is reported to Sentry and never rolls the order back.

`paymentNext` is one of: bank transfer (amount, bank details, hold deadline, WhatsApp link), PayHere (form action and fields), or none (pay on collection, cash on delivery).

### 5.5 Console commands and schedule

| Command | Schedule | Purpose |
|---|---|---|
| `store:sweep` | every 15 minutes | Calls `SweepExpired`. Idempotent. Logs its result. |
| `store:import-catalog {file} {--commit}` | manual | Same importer as the admin page, for large files. |
| `activitylog:clean` | daily | Keeps 12 months of audit entries. |
| `crm:digest` | daily at `CrmSettings::digest_time` | Release 2. Calls `SendDailyDigest`: one email and one SMS per staff member with open follow-ups, giving the counts due today and overdue. No customer details in the SMS. |

`store:sweep` exists from Release 1, where it only expires quotes and anonymises old records. Release 3 adds stock-hold expiry to the same command. `RecordPayment` assigns the receipt number through `NextDocumentNumber` when the payment is confirmed.

### 5.6 Admin and CRM (Filament panel at `/admin`)

Roles: `admin` can do everything, including reports, settings, exports and merges; `sales` handles customers, leads, follow-ups, quotes, orders, invoices and payments; `catalog` handles products, terms, showrooms and guides.

Navigation groups, in this order: **Sales desk** (Dashboard, Leads, Follow-ups, Customers, Quotes and orders, Invoices), **Catalog**, **Reports**, **Settings**.

| Resource or page | Roles | Release | Contents |
|---|---|---|---|
| Dashboard | all | 1, growing | Release 1: new leads, leads with no response past the target, accepted orders with a balance owed, orders needing attention, funnel counts per event per day by locale. Release 2 adds: my follow-ups today, overdue follow-ups per person, this month against last month. Release 3 adds: web orders awaiting payment confirmation. |
| Find or add a customer | sales | 1 | A field in the panel header on every page: type a phone number or a name. |
| `CustomerResource` | sales | 1, growing | Table searchable by phone and name, filters for type, tag, district, owner and "has a balance". View page: details, tags, balance, timeline, and the action buttons from 3.14. Anonymise and export are `admin` only. Release 2 adds import, merge and possible duplicates. |
| `EnquiryResource`, shown as **Leads** | sales | 1 | Table with status, channel, owner and age filters, an overdue badge, "mine" by default. View page with the customer, WhatsApp and call buttons, items, the room measurements of each estimate, and the campaign. Actions: new lead, assign, mark contacted, close with a reason, **Create quote**. |
| `FollowUpResource` | sales | 2 | "My follow-ups" with tabs for today, overdue, upcoming and done. Complete with an outcome; set the next one. |
| `OrderResource`, shown as **Quotes and orders** | sales | 1, growing | Tabs for quotes, accepted, in progress, done, needs attention. While `quoted`: a repeater for lines (kind, variant or free text, quantity, unit price) with live totals, advance, exclusions, validity, and the list of earlier revisions. Actions: **Get quote link**, **Mark accepted**, **Record payment**, **Record refund**, **Move to...** (only transitions the state machine allows), **Clear attention**, Print. Timeline of order events. Release 2 adds **New sale** (counter sale) and **Issue invoice**. Release 3 adds the tab for web orders awaiting payment confirmation. |
| `InvoiceResource` | sales | 2 | Table with status, date and customer filters. View with lines, totals, tax and payments. Actions: **Get invoice link**, Print, Print receipt, **Void** with a reason. No edit and no delete. |
| `ProductResource` | catalog | 1 | Core fields; translatable fields in three language tabs; review toggles per locale for title, summary and body; relation managers for variants, images, documents, accessory rules and synonyms. Derived read-only fields beside each variant: length in feet and inches, coverage in square feet, price per square foot, so data-entry errors are visible. Action: **Publish**, refused with reasons unless `isPublishable()`. |
| `CategoryResource`, `TermResource` | catalog | 1 | Translatable names with review toggles. Category has the active switch, the estimator method and the default waste. |
| `ShowroomResource` | catalog | 1 | Translatable fields, hours editor, "hours verified on" date. |
| Stock list | sales, catalog | 2 | Tracked variants with quantity and last movement; adjust with a reason. |
| Catalog import page | catalog | 1 | Upload, dry-run report, confirm. Export in the same format. |
| Customer import page | admin | 2 | Upload, dry-run report, confirm. Template download. |
| Reports | admin | 2 | The eight reports listed in phase 21, each with a date range and an export. |
| Delivery matrix page | admin | 3 | Districts by delivery class; fee, weight limit, lead days, availability. |
| Settings pages | admin | 1, growing | One per settings class in 4.5, and from Release 2 the SMS message switches. |
| `UserResource` | admin | 1 | Create staff, set role, deactivate. |
| Activity log | admin | 1 | Read-only audit trail, including every export of customer data. |

Every Filament action that changes data calls an Action class from 5.4. Exports use Filament's built-in table export.

## 6. External services

For each: what to set up, the gotcha, and the limit that matters.

**Laravel Herd (local).** Install the free tier for Windows. It provides PHP 8.4, Composer, Node and nginx, and serves the project at `materials-store.test`. It sits beside XAMPP without touching it; make sure `php -v` in Git Bash reports 8.4 afterwards, not XAMPP's 8.2.

**Local Postgres.** `compose.yaml` runs `postgres:17` on port 5432 with a named volume, plus a second database for tests. Development and tests never depend on your internet connection.

**Laravel Cloud.** One organisation on the Starter plan. One application from the GitHub repository with two environments: `production` from `main`, `staging` from `dev`. Region Asia Pacific (Singapore). Attach a Serverless Postgres database and an object storage bucket to each environment, and turn on the scheduler. Production compute: Flex 1 GiB with scale-to-zero disabled. Staging: scale-to-zero. Deploy command: `php artisan migrate --force`. Set a monthly spending limit. Gotchas: confirm in phase 0 that PHP 8.4 is offered; find out whether the serverless database sleeps when idle, turn that off for production and note the cost (section 3.3); the Starter plan keeps logs for one day, which is why Sentry matters; check in phase 1 what database restore window the plan gives you and write it into section 9.5.

**PayHere.** Create a sandbox account first at `sandbox.payhere.lk`. The merchant secret is issued per approved domain, so add the staging domain in sandbox and the real domain in live, and expect a wait for approval on live. Gotchas: the notification needs a public URL; `email`, `address` and `city` are mandatory fields even for collection orders (send the showroom's address and city for those). Limits on Plus: LKR 250,000 per payment, LKR 3,000,000 per month.

**Resend.** Verify a sending subdomain such as `mail.example.lk` with the DKIM and SPF records it gives you, and add a DMARC record. Used for staff alerts, password resets and customer messages when an email was given. Persist first, send after.

**SMS provider.** Not chosen and not checked. In step 0 compare two or three local providers on price per message, whether Sinhala and Tamil text is supported, how the sender name is registered and how long that takes, and whether they offer a plain HTTP interface with delivery status. Write the `http` driver in phase 8 from the chosen provider's documentation. Gotchas that apply to any of them: an unregistered sender name may be replaced or blocked; Sinhala and Tamil messages hold about 70 characters each; buy credit in advance and alert yourself when it runs low, because an empty balance fails silently from the customer's side.

**Sentry.** One project. `send_default_pii` false. A `before_send` callback drops request bodies and removes the token from any URL containing `/q/` or `/o/`.

**Cloudflare.** Add the domain, DNS only, pointing at Laravel Cloud. Enable Web Analytics and add its script to the public layout only, not the admin.

**Better Stack.** Two monitors: the English home page and `/up`.

**Backup bucket.** A private bucket on any S3-compatible store, in a different account from the host. A scheduled GitHub Actions workflow runs `pg_dump` against the production database each night, encrypts the file with a public key whose private half is kept offline, and uploads it. Keep 30 daily and 12 monthly copies. This needs the database to accept connections from outside the host; confirm that in phase 1. If it does not, replace the workflow with a scheduled console command that exports every table to the bucket through the application.

**Photo and document backup.** Revision 2 backed up the database only, leaving the product photos, which are weeks of catalog work, with a single copy. A second weekly GitHub Actions workflow copies the production media bucket to the backup bucket with `rclone sync`, without deleting files that have disappeared from the source. The person who takes the photographs also keeps the originals.

### Environment variables

`.env.example`, beyond Laravel's defaults:

```
APP_NAME="Materials Store"
APP_ENV=local                    # local | staging | production
APP_URL=http://materials-store.test
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=materials_store
DB_USERNAME=postgres
DB_PASSWORD=postgres

SESSION_DRIVER=database
CACHE_STORE=file                 # catalog cache, per instance (section 3.3)
CACHE_LIMITER=database           # rate-limit counters shared between instances; confirm the config key in Boost
QUEUE_CONNECTION=sync

FILESYSTEM_DISK=local            # s3 on staging and production
MEDIA_DISK=public                # s3 on staging and production

MAIL_MAILER=log                  # resend on staging and production
RESEND_API_KEY=
MAIL_FROM_ADDRESS=orders@mail.example.lk
STAFF_ALERT_EMAIL=

SMS_DRIVER=log                   # log | http (staging and production)
SMS_API_URL=
SMS_API_KEY=
SMS_SENDER_ID=

STORE_IP_HASH_SECRET=            # 32+ random bytes
STAGING_BASIC_AUTH=              # user:password, staging only
SEED_ADMIN_EMAIL=

PAYHERE_ENABLED=false
PAYHERE_MODE=sandbox             # sandbox | live
PAYHERE_MERCHANT_ID=
PAYHERE_MERCHANT_SECRET=

SENTRY_LARAVEL_DSN=
CLOUDFLARE_ANALYTICS_TOKEN=
```

Store-specific values are read once in `config/store.php` and accessed through `config('store.*')`, never through `env()` in application code.

## 7. Project structure

```
materials-store/
  BLUEPRINT.md
  IMPLEMENTATION_PLAN.md            phase status, copied from section 8
  CLAUDE.md                         base branch dev, commands, UI rule, "use Boost docs"
  compose.yaml                      local Postgres 17
  composer.json  package.json  vite.config.js
  phpstan.neon  pint.json
  .env.example
  .github/workflows/
    ci.yml                          pint, larastan, pest
    backup.yml                      nightly pg_dump
  app/
    Actions/
      Cart/  Enquiry/  Checkout/  Payment/  Order/  Catalog/  Crm/  Invoice/
    Data/                           readonly input and result objects
    Domain/                         pure PHP, no framework imports, unit tested
      Estimator/                    Units, StripEstimator, AreaEstimator, RoofPlaneEstimator, Accessories
      Order/                        StateMachine, Eligibility, Totals
      Invoice/                      Tax, DocumentNumber
      Sms/                          Message
      PayHere/                      Signature
      Money.php  Phone.php  Reference.php  WhatsApp.php
      Search/Normaliser.php
    Enums/
    Exceptions/                     StoreException, StoreError
    Filament/
      Resources/                    Customer, Enquiry (leads), Order, Invoice, FollowUp, Product, Category, Term, Showroom, User
      Pages/                        CatalogImport, CustomerImport, DeliveryMatrix, Reports/, settings pages
      Widgets/
    Http/
      Controllers/
      Middleware/                   SetLocale, VerifyOrderToken, StagingBasicAuth, SecurityHeaders
    Livewire/                       Estimator, Calculator, QuoteList, QuoteView, InvoiceView, Cart, Checkout, OrderView, OrderPaying
    Mail/
    Models/
    Observers/                      CatalogVersionObserver
    Policies/
    Providers/
      Filament/AdminPanelProvider.php
    Settings/
    Support/                        CatalogCache, Tokens, IpHash, SearchIndex, Sms/ (SmsSender, LogDriver, HttpDriver)
    Console/Commands/               StoreSweep, StoreImportCatalog, CrmDigest
  bootstrap/app.php                 middleware, CSRF exceptions, schedule
  config/store.php
  database/
    migrations/  seeders/  factories/  settings/
  lang/
    en.json  si.json  ta.json  _pending.json
    en/validation.php  si/validation.php  ta/validation.php
  resources/
    content/guides/{slug}/{en,si,ta}.md
    css/app.css
    fonts/
    js/app.js                       cart store, small Alpine behaviours
    views/
      components/                   layout, hreflang, price, your designed components
      livewire/
      pages/
      mail/
  routes/
    web.php  console.php
  tests/
    Unit/                           mirrors app/Domain
    Feature/                        actions, HTTP, Filament, against Postgres
    Browser/                        Pest browser journeys
    fixtures/
```

Rule for `app/Domain`: no `Illuminate\...` imports, no models, no facades. Inputs and outputs are scalars, arrays, enums and small value objects. An architecture test in Pest enforces this.

## 8. Phased build plan

Copy this section into `IMPLEMENTATION_PLAN.md` and update the status column after every phase. Phases of up to 9 hours are sized for one session. **Longer phases are two sessions: stop at the midpoint marked in the phase, run `/checkpoint`, and start a fresh session for the rest.** Revision 2 called every phase one session, including the 12-hour ones.

### 8.1 Ground rules for every phase

- Base branch is `dev`. Work on a feature branch, open a pull request into `dev`. `main` is production and is only updated by merging `dev` at a release. You merge.
- Customer-facing pages are built data-complete with semantic HTML and neutral minimal styling. Do not design, restyle or "improve" visuals. When designed components are supplied, integrate them with `/ui-drop`, preserving markup and styles.
- Admin and CRM pages use Filament's defaults. No design effort.
- Every customer-facing string goes into all three `lang` files in the same commit. Sinhala and Tamil entries start as the English text, and the key is added to `lang/_pending.json` under its locale so the reviewers can see exactly what is outstanding. A reviewed key is removed from that list.
- Before writing code against Laravel, Filament, Livewire or Pest, look up the installed version's documentation through Boost. Do not write from memory.
- Business rules go in `app/Domain` or `app/Actions`, never in a Livewire component, a Filament resource or a controller.
- A phase is done only when its verification steps have actually been run and passed.
- Run `php artisan`, `composer` and `npm` through Bash, not PowerShell.
- **Do not start any Release 2 phase until the client has signed off section 1.9.** If they changed it, update 1.9 and the affected phases first.

### 8.2 Status

| # | Phase | Release | Est. hours | Status |
|---|---|---|---|---|
| S0 | Validate, including CRM sign-off (section 1.7, no code) | before R1 | n/a | not started |
| 0 | Environment, scaffold, tooling, first deploy | R1 | 5 | not started |
| 1 | Migrations, enums, models, settings, seed | R1 | 8 | not started |
| 2 | Locale routing and page shell | R1 | 5 | not started |
| 3 | Admin panel, staff, roles, multi-factor sign-in, audit | R1 | 4 | not started |
| 4 | Admin catalog | R1 | 10 | not started |
| 5 | Catalog import and export | R1 | 6 | not started |
| 6 | Public category and product pages | R1 | 12 | not started |
| 7 | Estimator and calculator page | R1 | 13 | not started |
| 8 | Quote list, enquiry, WhatsApp handoff, alerts, lead source | R1 | 11 | not started |
| 9 | Customers and leads | R1 | 9 | not started |
| 10 | Quote builder, printable quote, customer quote page | R1 | 10 | not started |
| 11 | Staff orders and payments | R1 | 9 | not started |
| 12 | Showrooms, installation, information pages, guides | R1 | 6 | not started |
| 13 | Search and synonyms | R1 | 5 | not started |
| 14 | SEO, analytics, security headers | R1 | 7 | not started |
| 15 | Release 1 hardening and launch | R1 | 12 | not started |
| 16 | Follow-ups and contact history | R2 | 9 | not started |
| 17 | Customer import, tags, merge | R2 | 6 | not started |
| 18 | Counter sales and stock | R2 | 8 | not started |
| 19 | Invoices and receipts | R2 | 11 | not started |
| 20 | Customer SMS | R2 | 6 | not started |
| 21 | Reports and exports | R2 | 10 | not started |
| 22 | Release 2 hardening, staff training, launch | R2 | 9 | not started |
| 23 | Delivery matrix and checkout options | R3 | 5 | not started |
| 24 | Cart, checkout, order placement, stock holds | R3 | 12 | not started |
| 25 | Customer order page, bank transfer, hold expiry | R3 | 8 | not started |
| 26 | PayHere | R3 | 10 | not started |
| 27 | Online quote acceptance and advance payment | R3 | 6 | not started |
| 28 | Customer messages, Release 3 hardening and launch | R3 | 12 | not started |
| 29 | Product comparison | R4 | 7 | not started |
| 30 | Guide library and case studies | R4 | 8 | not started |
| 31 | Reviews | R4 | 9 | not started |
| 32 | Ad pixels with consent | R4 | 7 | not started |

Totals: Release 1 about 132 hours, Release 2 about 59, Release 3 about 53, Release 4 about 31: 275 hours. Add 25% contingency and 25 to 45 hours for integrating your designs as Blade templates: roughly 370 to 390 hours of engineering. Revision 2 was 270 to 290 for the website alone; the difference is the CRM (Release 2, and phases 9 and 11), the calculator page, and the alert and lead-source work.

These are my estimates for agent-assisted building, not measurements. They exclude:

- **Your design time.** Revision 2 left this out without saying how large it is. About fifteen public page types in three languages has to be designed before it can be integrated; only you can size it.
- Catalog preparation (45 to 90 hours for 30 products) and translation review.
- Anything the client adds from the table of extras in 1.9.

**Timeline.** Engineering hours per release, with contingency and design integration, against two honest weekly budgets:

| Release | Engineering hours | At 25 to 30 hours a week | At 10 to 15 hours a week |
|---|---|---|---|
| 1. Website and sales desk | about 190 | 6 to 8 weeks | 13 to 19 weeks |
| 2. CRM | about 74 | about 3 weeks | 5 to 7 weeks |
| 3. Online checkout | about 75 | about 3 weeks | 5 to 8 weeks |
| 4. Depth | about 44 | about 2 weeks | 3 to 4 weeks |
| All four | about 383 | 14 to 16 weeks | 26 to 38 weeks |

Design and catalog work come out of the same weeks, so add them. Pick the column that matches the hours you worked out in step 0 before giving the client a date.

### 8.3 Review points

**After Release 1 has run for four weeks, before starting Release 3.** Online checkout stays in scope; this decides how much of it to build. Look at the leads:

- How many asked to pay or order without wanting a quote? If almost none, build Release 3 for accessories and small items only and leave long sheets on the quote path.
- How do customers who accepted a quote actually pay? If nearly all pay by bank transfer or cash, PayHere (phase 26) moves to the end of Release 3 or is dropped with the client's agreement, which also saves its monthly fee.
- Which districts do leads come from? Fill the delivery matrix for those first.

Write the decision into this section. Release 2 is not affected and can be built while the four weeks run.

**When paid advertising starts, whenever that is.** Build phase 32 then, out of order. The lead-source data from phase 8 works without it; the pixel adds audience building and conversion reporting inside the ad platform.

### Phase 0: Environment, scaffold, tooling, first deploy

**Tasks**
1. Install Herd for Windows (free tier). Confirm in Git Bash that `php -v` reports 8.4 and `composer -V` works. XAMPP's PHP 8.2 must not be the one on the path.
2. Create the repository `materials-store`, default branch `main`, then branch `dev`.
3. `composer create-project laravel/laravel materials-store`, then pin the current stable version of each package (section 2.2 lists what they were on 2 October). Install Filament, Livewire, the spatie packages, Pest with the Laravel and browser plugins, Larastan, Pint, Sentry, Resend.
4. Install Boost as a development dependency and run its installer so Claude Code gets the project's documentation and tools.
5. Add `compose.yaml` for Postgres 17 with `materials_store` and `materials_store_test` databases. Point `.env` and `phpunit.xml` at them.
6. Add Tailwind 4 through the Vite plugin. Add `config/store.php` and `.env.example` per section 6.
7. Configure Larastan at level 6 and Pint with the Laravel preset. Add one passing Pest test and one architecture test asserting `App\Domain` uses nothing from `Illuminate`.
8. Add `ci.yml`: Pint in test mode, Larastan, Pest against a Postgres service.
9. Add the project `CLAUDE.md`: base branch `dev`; the commands; the UI rule; "use Boost documentation search before integration code"; "business rules live in Domain and Actions".
10. Create the Laravel Cloud organisation, application and two environments per section 6. Confirm PHP 8.4 is offered. Turn off database sleep for production and record what it costs in section 2.3.
11. Copy this file to the repository root and section 8 to `IMPLEMENTATION_PLAN.md`.

**Done when** CI is green on a pull request and the default Laravel page is reachable on the staging domain.

**Verify** `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/pest` all exit 0. Open the staging URL.

### Phase 1: Migrations, enums, models, settings, seed

**Tasks**
1. Publish the media library, activity log and settings migrations.
2. Add the enums from 4.2 and the migration from 4.3. Run it. Fix anything the database rejects and update section 4.3 of this file to match.
3. Add the models with the rules in 4.4, factories for each, and the `CatalogVersionObserver` with `App\Support\CatalogCache`.
4. Add the settings classes and their migrations from 4.5.
5. Write the seeders per 4.6, idempotent.
6. Add a feature test that selects `DISTINCT` rows from every table that has a translated column, to prove no `json` column is left.
7. Apply migrations on staging. Check the database restore window the host gives you and whether the database accepts outside connections; record both in section 9.5 and section 6.

**Done when** `php artisan migrate:fresh --seed` runs cleanly twice, and staging has 25 districts and 5 categories, of which two are active.

**Verify** Run the seeder twice; row counts do not change the second time. A Pest test creates one of each model through its factory. Query district and category counts on staging.

### Phase 2: Locale routing and page shell

**Tasks**
1. `SetLocale` middleware, the `{locale}` route group, and `RootRedirectController`.
2. A `lroute()` helper that builds a named route in the current or a given locale.
3. `lang/en.json`, `si.json`, `ta.json`, `_pending.json`.
4. The public layout component: `<html lang>`, header with category links, quote-list link with an Alpine count from `localStorage`, a language switch that keeps the current path, footer with contact links.
5. Fonts per 3.4 with `unicode-range`.
6. The `hreflang` and canonical component, used by the layout.
7. Translated 404 and error pages.

**Done when** `/en`, `/si`, `/ta` render with the right `lang`, the switch preserves the path, and an English page makes no request for the Sinhala or Tamil font files.

**Verify** Pest browser test: visit a missing `/si/...` path, switch to Tamil, land on the same path under `/ta`. Inspect network requests on `/en`. View source for the alternates.

### Phase 3: Admin panel, staff, roles, multi-factor sign-in, audit

**Tasks**
1. Create the Filament panel at `/admin` with required app-based multi-factor sign-in and recovery codes, exactly as in 3.13.
2. `User` model: role cast, `canAccessPanel()`, the multi-factor interfaces and traits.
3. Policies per role for every model. `UserResource` for `admin`.
4. Activity log trait on the models listed in 4.4, with sensitive columns excluded, and a read-only activity page.
5. Dashboard with the four stat widgets (zeros for now).
6. Password reset by email for staff.

**Done when** the seeded admin can set a password, enrol an authenticator app and sign in; a `catalog` user cannot open orders; a deactivated user cannot sign in.

**Verify** By hand on staging for the enrolment. Pest feature tests for role access on each resource and for the deactivated user.

### Phase 4: Admin catalog

**Tasks**
1. The `TranslatableTabs` form helper from 2.2, with a test that saving one language leaves the other two untouched. Then `CategoryResource` and `TermResource` with translatable fields and per-locale review toggles.
2. `ProductResource` per 5.6: core fields, translatable fields, relation managers for variants, images, documents, accessory rules and synonyms.
3. The derived read-only fields beside each variant (feet and inches, square feet, price per square foot). The optional trade price sits beside the retail price.
4. The **Publish** action using `isPublishable()`, listing every unmet rule.
5. `AdjustStock` action writing a stock movement.
6. Image conversions at 400, 800 and 1200 pixels in WebP; alt text per locale as custom properties. Browser-side resize to 2000 pixels and an 8 MB limit on the upload field.

*Midpoint: checkpoint after task 3.*

**Done when** a product with two variants, three reviewed titles and summaries, an image, an accessory rule and a synonym can be created and published entirely through the admin, and publishing is refused with reasons when a title is unreviewed.

**Verify** Do it by hand on staging. Pest tests for `isPublishable()` and for the unit conversions on `Variant`.

### Phase 5: Catalog import and export

**Tasks**
1. `ImportCatalog` per 4.7: parse with PHP's CSV functions, validate every rule, return an `ImportReport`; write only when `commit` is true and there are no errors, inside one transaction.
2. The Filament import page: upload, dry-run report by row, confirm.
3. The `store:import-catalog` command using the same action.
4. Catalog export in the same column format.

**Done when** a 30-row sample file imports cleanly, a deliberately broken file is refused with every error listed, and export followed by import changes nothing.

**Verify** One Pest test per validation rule in 4.7. Run the round trip on staging.

### Phase 6: Public category and product pages

**Tasks**
1. Query classes for category and product pages that read through `CatalogCache`. Only published products with active variants appear.
2. Category page: product cards with "from" price and its sale unit; filters for size, finish, thickness and use, applied on the server from the query string so every filtered view is a shareable URL.
3. Product page: gallery with responsive images, variant selector (Alpine, updating price and specifications without a round trip), price per sale unit and equivalent per square foot, nominal size and effective coverage shown separately, specification table, stock label, documents, "Ask on WhatsApp", "Add to quote list".
4. Fallback rule: body text not yet reviewed in the current locale shows English with `lang="en"`.
5. Home page: cards for the active categories only, a link to the calculator, a short process explanation, selected products, quote call to action.
6. The quote-list store in `resources/js/app.js` (key `ms_quote_v1`, shape in 3.2): read, write and a change event for the header count. The visit-source capture from 3.16.

*Midpoint: checkpoint after task 3.*

**Done when** editing a price in the admin changes the public page on the next load in all three locales, and unpublished products return 404.

**Verify** Pest feature tests for visibility and the fallback rule. Check a 12-foot variant shows one consistent length everywhere. Measure server time for the product page locally with `CatalogCache` switched off, then on, and apply the rule in 3.3.

### Phase 7: Estimator and calculator page

**Tasks**
1. `app/Domain/Estimator`: `Units`, `StripEstimator`, `AreaEstimator`, `Accessories`, with typed inputs and a versioned result object that records the layout chosen. Add `RoofPlaneEstimator` only if the client confirmed roofing in step 0; otherwise leave the enum value and build nothing.
2. Unit tests first: every row of the tables in 3.5 and 9.1, including the 7 ft by 10 ft room that revision 2 got wrong, zero and negative input, the 25% waste ceiling, several rectangles, feet-and-inches conversion, each accessory basis and pack rounding.
3. The `Estimator` Livewire component per 5.3: room inputs with a unit toggle, "add another room", both strip layouts with a switch, waste percentage with its explanation, itemised result with accessories.
4. A "provisional" badge on every estimate while `EstimatorSettings::approved_on` is null.

*Midpoint: checkpoint here.*

5. The `Calculator` component and route per 5.2 and 5.3, for each active category whose method is not `none`. Link it from the header, the home page and the category page.
6. A short text block above the calculator, per locale, answering the question people search for. It is Tier 1 content.

**Done when** all estimator tests pass, the product page and the calculator page give the same numbers as the tests for the same room, and the calculator lists every published product in the category.

**Verify** `vendor/bin/pest tests/Unit/Estimator`. Enter 12 ft by 10 ft on the sample ceiling product and read 11 panels along the length, 12 with 5% waste. Enter 7 ft by 10 ft and read 8 along the width. Do the same on the calculator page.

### Phase 8: Quote list, enquiry, WhatsApp handoff, alerts, lead source

**Tasks**
1. `PriceCart` action and the `QuoteList` component per 5.3, reading `ms_quote_v1` with its estimates.
2. Domain classes `Phone`, `Reference`, `WhatsApp`; support classes `Tokens` and `IpHash`.
3. `ResolveCustomer` per 3.14 and 5.4, with its tests from 9.2. It is needed here because every website lead is linked to a customer.
4. `SubmitEnquiry`: validate, honeypot, the two rate limits from 3.13, re-run every estimate sent and store one `enquiry_estimates` row each, store the visit source, link the customer, store the lead.
5. The visit-source script from 3.16 if it was not finished in phase 6.
6. `SmsSender` with the `log` driver and the `http` driver for the provider chosen in step 0; `Sms\Message`; `SendSms`. Staff alert by email and SMS, both sent with `defer()`.

*Midpoint: checkpoint here.*

7. `/quote/sent`: reference, the response-hours promise from settings, "Send on WhatsApp" with the pre-filled message. The quote list is cleared after a successful submit.
8. Entry points from the product page, the estimator, the calculator and the installation page set `source`.
9. Wire the dashboard's lead counts.

**Done when** an enquiry submitted in each language is stored with its reference, its room measurements, its campaign and a customer record; the WhatsApp link opens with the right text in that language; a staff phone receives the SMS on staging; and the fourth submission in an hour from one phone number is refused.

**Verify** Pest browser test in three locales. Unit tests for phone normalisation, the reference alphabet, the WhatsApp length cap and the SMS templates. Feature tests from 9.2 for `ResolveCustomer` and `SubmitEnquiry`. Set the mailer and the SMS driver to fail and confirm the enquiry is still stored. If the SMS provider is not ready, finish the phase on the `log` driver and note it in the status table; it must be live before the Release 1 checklist.

### Phase 9: Customers and leads

The client's answers on 1.9 rows C1 and C2 must be in hand.

**Tasks**
1. `CustomerResource`: table searchable by phone and name with type and tag filters; a view page with details, balance, and the timeline (leads, quotes, orders and payments for now; follow-ups, contacts and invoices join it in Release 2). Buttons: WhatsApp, call, new lead, new quote, anonymise (admin only).
2. A global "find or add a customer" field in the panel header: type a phone number, open the customer or create one through `ResolveCustomer`.
3. `EnquiryResource` presented to staff as **Leads**: table with status, channel, owner and age filters, an overdue badge, and "mine" as the default filter. View page with the customer, WhatsApp and call buttons, items, and the room measurements from each estimate.
4. `CreateLead` and a "New lead" form of four fields (customer, channel, what they asked for, expected value) plus optional items.
5. Actions: assign, mark contacted, `CloseLead` with a required reason for lost, **Create quote**.
6. Policies for the new resources. Customer export limited to `admin` and written to the audit trail.
7. Dashboard widgets: new leads, leads with no response past the target.

**Done when** staff can find a customer by phone, add a walk-in lead for them in under a minute, see website leads with their measurements, and close a lead as lost with a reason.

**Verify** By hand on staging. Pest feature tests for role access, for `CreateLead` and `CloseLead`, and that a `catalog` user cannot open customers or leads.

### Phase 10: Quote builder, printable quote, customer quote page

**Tasks**
1. `CreateQuoteFromEnquiry` and `SaveQuoteLines`; `app/Domain/Order/Totals` with tests. A quote can also be started from a customer with no lead.
2. `OrderResource` in its quote form: the lines repeater (kind, variant or free text, quantity, unit price) with live totals, advance, exclusions, validity. Choosing a variant pre-fills the trade price when the customer's price tier is `trade` and one exists, otherwise the retail price.
3. `SendQuote` behind **Get quote link**: snapshot, revision number, token rotation, and a ready WhatsApp message. A list of earlier revisions on the quote for staff.
4. `VerifyOrderToken` middleware and the `QuoteView` component: lines, total, exclusions, validity, advance required, revision number and date, "Reply on WhatsApp to accept".
*Midpoint: checkpoint here.*

5. The print stylesheet for the quote: business details and footer from settings, one page where it fits, no site header or buttons. A Print button on the customer page and in the admin.

**Done when** staff can turn a lead into a priced quote, the customer link shows it and prints cleanly in all three languages, a second send shows revision 2 while staff can still read revision 1, a wrong token returns 404, and a rotated link stops working.

**Verify** Pest browser test end to end. Unit tests for totals including discount and advance. Feature tests for right, wrong and rotated tokens and for the snapshot. Print a Sinhala and a Tamil quote to PDF from Chrome on a phone and on a desktop and read them.

### Phase 11: Staff orders and payments

This is what lets Release 1 record a sale. Revision 2 had no way to do that before online checkout existed.

**Tasks**
1. `app/Domain/Order/StateMachine` from the table in 3.7, all rows, with a test per allowed and per forbidden transition. Rows marked Release 3 are in the machine now; nothing calls them yet.
2. `AcceptQuoteByStaff` behind a **Mark accepted** action, with the stock override.
3. `RecordPayment` and `RecordRefund`; payment-state and balance recalculation in the same transaction. The receipt number column stays empty until phase 19.
4. `TransitionOrder` behind **Move to...**, offering only the transitions the machine allows, including the three post-dispatch cancel reasons and the admin override for completing with a balance.
5. `OrderResource` tabs: quotes, accepted, in progress, done, needs attention. Timeline of order events. Balance shown on the order and on the customer.
6. `SweepExpired` in its Release 1 form and the `store:sweep` command on the schedule: expire quotes past `valid_until`, anonymise per 3.13, blank old IP hashes.
7. Dashboard widgets: accepted orders with a balance owed, orders needing attention.

**Done when** a quote can be marked accepted, an advance recorded, the order moved to completed once the balance is paid, a dispatched order cancelled as refused and flagged, and a quote left alone expires.

**Verify** Unit tests for the state machine. Feature tests from 9.2 for `AcceptQuoteByStaff`. A feature test travels time past `valid_until`, runs the sweep, and checks the quote; running it again changes nothing.

### Phase 12: Showrooms, installation, information pages, guides

**Tasks**
1. `ShowroomResource` and the public directory grouped by district, with detail pages: address, hours with the date verified, phone, WhatsApp, directions link.
2. Installation page with service-area text and an enquiry entry.
3. Information and policy pages as Blade views with translated strings.
4. Guides from Markdown files per locale with English fallback. Write three: measuring a room, choosing a ceiling panel, how delivery of long sheets works.

**Done when** every Release 1 route in 5.2 renders in three locales with no missing translation keys.

**Verify** A Pest browser smoke test over every Release 1 route in every locale: no errors, exactly one `h1`, no raw translation keys, no accessibility issues.

### Phase 13: Search and synonyms

**Tasks**
1. `app/Domain/Search/Normaliser` per 3.12 with tests covering zero-width joiners and mixed scripts.
2. `App\Support\SearchIndex` built per locale through `CatalogCache`; `SearchController` page and suggest endpoints.
3. Header search field with suggestions. No-results state suggests the five categories and the quote form.
4. Seed synonyms for the five categories in all five scripts with the reviewers.

**Done when** searching an English term, a Sinhala term, a Tamil term and a romanised Sinhala term each returns the expected sample product.

**Verify** Unit tests plus one feature test per script.

### Phase 14: SEO, analytics, security headers

**Tasks**
1. `SitemapController` with alternates, including the calculator pages; `robots.txt` route that disallows everything outside production; noindex headers on transactional routes.
2. Product structured data (name, image, price in LKR, availability) and local-business data on showroom pages, matching what is visible.
3. `AnalyticsEventController` with the allow-list, and a small `sendBeacon` helper. Wire the Release 1 events.
4. Cloudflare Web Analytics on the public layout.
5. `SecurityHeaders` middleware and the Content Security Policy in report-only mode.
6. Sentry with scrubbing.
7. The dashboard funnel widget.

**Done when** the sitemap lists every public URL in three locales, headers are present on every response, and an estimator run appears in the funnel widget.

**Verify** Fetch headers with `curl -I`. Validate the sitemap. Trigger a test error and confirm Sentry receives it with no personal data.

### Phase 15: Release 1 hardening and launch

**Tasks**
1. Pest browser journeys 1 to 5 from 9.3 (Flows A, D, E and F) with `assertNoAccessibilityIssues()`.
2. Manual accessibility pass: keyboard only, 200% zoom, 320-pixel width, a screen reader on the product, estimator and enquiry pages.
3. Performance pass on a throttled mid-range Android profile: fix anything stopping LCP at or under 2.5 seconds and CLS at or under 0.1 on home, category and product pages. Check the 150 ms server-time budget from 3.3 on staging.
4. Five-person usability check, at least one per language: find a product, estimate a room, send a quote request. Separately, sit with one member of the sales staff while they handle a lead from arrival to recorded payment, and fix what slows them down.
5. Move the Content Security Policy to enforcing.
6. Run the Release 1 checklist in 10.1.

*Midpoint: checkpoint after task 3.*

**Done when** the checklist is fully ticked and a real enquiry from a phone has been answered by the business.

**Verify** The checklist itself.

### Phase 16: Follow-ups and contact history

Release 2 starts here. Section 1.9 must be signed off.

**Tasks**
1. `ScheduleFollowUp`, `CompleteFollowUp`, `LogInteraction` with tests.
2. `FollowUpResource`: "My follow-ups" as the default view with tabs for today, overdue, upcoming and done. Completing one asks for a one-line outcome and offers "set the next one".
3. Follow-up and "log a contact" actions on the customer, lead and order pages. After staff use a WhatsApp or call button, the page offers "log this contact" with the kind pre-filled.
4. The three automatic rules from 3.14 (first response, ask about the quote, quote expiring), each behind `CrmSettings::auto_follow_ups_enabled`. Closing a lead or accepting a quote completes its open automatic follow-ups.
5. Follow-ups and interactions join the customer timeline.
6. `SendDailyDigest` and the `crm:digest` command on the schedule.
7. Dashboard widgets: my follow-ups today, overdue follow-ups per person (admin).

*Midpoint: checkpoint after task 3.*

**Done when** a new website lead produces a "first response" follow-up for the right person, completing it logs the contact on the customer, and the digest reaches a staff phone and inbox on staging.

**Verify** Feature tests for each automatic rule, for completion writing an interaction, and for the digest counts. By hand: work one lead from arrival to a logged call.

### Phase 17: Customer import, tags, merge

**Tasks**
1. Ask the client for their existing list and look at it before writing the importer; its columns decide the format.
2. `ImportCustomers`: one row per customer with phone, name, type, company, district, address, tags, notes. Dry run first with a report. Refuses on an invalid phone or a duplicate phone within the file. A phone that already exists updates only empty fields.
3. The import page in the admin and a template file to download.
4. Tags: free-text tags on a customer with a filter, and a settings list of suggested tags.
5. `MergeCustomers` behind a "Merge into..." action for `admin`, with a confirmation that names both records.
6. A "possible duplicates" list: customers sharing a name and district, or an email.

**Done when** the client's real list imports cleanly after a dry run, and two records for one person can be merged with nothing lost.

**Verify** One test per import rule. The `MergeCustomers` test from 9.2. Import the real list on staging and have the client check twenty rows.

### Phase 18: Counter sales and stock

**Tasks**
1. `CreateCounterSale` and a "New sale" form: find or add the customer, add lines by searching product or SKU, live total, then record the payment in the same screen.
2. A "walk-in customer" shortcut for a sale with no name or phone, using one shared customer record that is excluded from customer reports.
3. Stock: `AdjustStock` with a reason, a stock list showing tracked variants with quantity and last movement, and a low-stock filter with a threshold per variant if the client wants one.
4. With the client, decide which variants to track and enter opening quantities. Leave the rest untracked.

**Done when** a counter sale for a tracked item reduces its stock, appears on the customer and in the order list, and selling more than is in stock is refused unless overridden.

**Verify** Feature tests for `CreateCounterSale` including the stock override. By hand: a member of staff records three sales on staging without help.

### Phase 19: Invoices and receipts

Get one real invoice from the client and the accountant's requirements first.

**Tasks**
1. `app/Domain/Invoice/Tax` and `DocumentNumber` with the tests in 9.1.
2. `NextDocumentNumber` with its concurrency test.
3. `IssueInvoice`, `VoidInvoice`, `RotateInvoiceToken`. An **Issue invoice** action on a confirmed order.
4. `RecordPayment` now assigns a receipt number on confirmation.

*Midpoint: checkpoint here.*

5. `InvoiceResource`: table with status, date and customer filters; the view shows the lines, totals, tax, and payments against the order.
6. The `InvoiceView` page and route with a print stylesheet: seller and buyer details, VAT numbers and breakdown when it is a tax invoice, payments received with receipt numbers, balance due, bank details if the setting is on. A receipt print page for a single payment, from the admin.
7. Invoices join the customer timeline. **Get invoice link** gives a WhatsApp message with the link.
8. Tier 2a strings to the reviewers.

**Done when** an invoice can be issued, printed in three languages, voided and reissued with the next number; the client's accountant has read a sample tax invoice and a sample receipt and agreed the layout.

**Verify** The unit and feature tests from 9.1 and 9.2. Print to PDF in three languages. Record the accountant's approval, with the date, in `docs/invoice-approval.md`.

### Phase 20: Customer SMS

**Tasks**
1. Templates in three languages for: order link (on acceptance or counter sale, with the quote or invoice link), payment received, ready for collection, dispatched. Each Sinhala and Tamil template at or under 70 characters.
2. Send from the matching actions with `defer()`; each send writes an interaction. A per-customer "no SMS" switch.
3. A settings page to turn each message type on or off, and a test-send button.
4. A low-credit check if the provider exposes the balance; otherwise a monthly reminder in 10.5.

**Done when** each message arrives on a real phone in the customer's language on staging, and a failed send changes nothing else.

**Verify** Unit tests for templates. Feature test with the driver failing. Send each type to your own phone.

### Phase 21: Reports and exports

**Tasks**
1. A seeded reporting data set with known totals, used by every test in this phase.
2. Report pages for `admin`, each with a date range, a table, and export:
   - **Sales**: confirmed order value and count by day, week or month; by product and category; by district; by salesperson; by order source.
   - **Lead sources**: leads, quotes, wins and won value by channel and by campaign.
   - **Pipeline**: counts at each lead status, conversion from lead to quote to win, average days to win.
   - **Response time**: time from lead arrival to first response, by salesperson, with the share inside the target.
   - **Lost reasons**: count and expected value by reason.
   - **Money owed**: customers with a balance, by age since the order was confirmed (0 to 30, 31 to 60, 61 to 90, over 90 days).
   - **Top customers**: by value over the period.
   - **Payments**: received by method and day, for reconciling with the bank and the till.

*Midpoint: checkpoint after the first four reports.*

3. Dashboard: this month's sales, leads and conversion beside last month's.
4. An "accountant's export": invoices and payments for a period as two CSV files.

**Done when** every report matches the known totals of the seeded data set, and the client has read each one on real data and said which they will use.

**Verify** One feature test per report query. Check two reports by hand against a count done in the database.

### Phase 22: Release 2 hardening, staff training, launch

**Tasks**
1. Pest browser journey for Flows G and H from 1.3.
2. Query review: open the customer page, lead list and each report with 5,000 customers and 20,000 orders seeded; add indexes where a page takes over a second.
3. Restore the latest nightly backup into a scratch database and check row counts. Restore one photo from the weekly copy.
4. A written one-page guide per role, with screenshots, in the language the staff read.
5. A training session with every member of staff on their own account, using real leads, not a demonstration.
6. Run the Release 2 checklist in 10.2.

**Done when** the checklist is fully ticked.

**Verify** The checklist itself.

### Phase 23: Delivery matrix and checkout options

Release 3 starts here. Hold the review point in 8.3 first.

**Tasks**
1. The delivery matrix page.
2. `app/Domain/Order/Eligibility`: delivery-class ordering, eligibility, the weight limit and the payment-method rules from 3.9, with a test for every row of that table.
3. `GetCheckoutOptions`.

**Done when** the tests cover every rule and the action returns the right options for sample carts.

**Verify** Unit tests. Feature tests for: a small cart to Colombo, a long-sheet cart to a district with no rate, a cart with a quote-only item.

### Phase 24: Cart, checkout, order placement, stock holds

**Tasks**
1. The cart store (key `ms_cart_v1`) and the `Cart` component, with removed and changed items explained.
2. The `Checkout` component: fulfilment, district or showroom, address, contact, payment method, full total before the button.
3. `PlaceOrder` per 5.4 in one transaction with the conditional stock update. It calls `ResolveCustomer`, stores the visit source, and sets the order source to `web_checkout`.
4. "Add to cart" on product pages and estimator results for `direct` items.
5. A cart that is not eligible offers "Request a quote for these items" with source `cart_ineligible`.

*Midpoint: checkpoint after task 3.*

**Done when** an order can be placed for each non-card payment method, the stored total matches the displayed total, and tracked stock falls by the ordered quantity.

**Verify** A script fires 20 simultaneous `PlaceOrder` calls (separate processes) for a variant with one unit left: exactly one succeeds, 19 fail with `out_of_stock`, and stock ends at zero. Change a price between cart and submit and confirm `price_changed`.

### Phase 25: Customer order page, bank transfer, hold expiry

The state machine, payment recording and staff order screens already exist from phase 11.

**Tasks**
1. The `OrderView` component: status, lines, total, paid amount, balance, and for bank transfer the bank details, exact amount, reference, hold deadline and "Send slip on WhatsApp".
2. The four holds from the table in 3.7, set by `PlaceOrder` and cleared on confirmation.
3. Extend `SweepExpired`: expire `placed` orders past their hold with nothing paid and release their stock; flag those with a part payment instead.
4. Staff actions for web orders in `OrderResource`: confirm a transfer, accept a pay-on-collection or cash-on-delivery order, and a tab for orders awaiting payment confirmation.
5. Order-link SMS on placement, using the templates from phase 20.
6. Wire the remaining dashboard counts.

**Done when** a bank-transfer order can be confirmed by staff and moved to completed, an unpaid order expires and returns its stock, a part-paid order past its hold is flagged and keeps its stock, and the customer can reopen the order from the SMS on another device.

**Verify** A feature test travels time past each of the four holds, runs the sweep, and checks the order, the stock and the movement row; running the sweep again changes nothing.

### Phase 26: PayHere

Start only when sandbox credentials exist. Release 3 can launch without this phase, and the review point in 8.3 may have moved or dropped it.

**Tasks**
1. Re-read PayHere's Checkout API page and compare it with 3.10. If anything differs, the page wins; update this file.
2. `app/Domain/PayHere/Signature` from 3.10 with the fixture test.
3. `StartCardPayment` creating the pending payment and returning the form; the auto-submitting form in `OrderView`.
4. `PayHereNotifyController` and `HandlePayHereNotification`, following the handler order in 3.10 exactly. Exclude the route from CSRF in `bootstrap/app.php`.
5. The `OrderPaying` component.
6. Late-payment handling for expired or cancelled orders.
7. A reconciliation table in the admin: confirmed card payments by day with gateway payment IDs, to compare with the PayHere dashboard.

*Midpoint: checkpoint after task 4.*

**Done when**, on staging against the sandbox: a successful payment confirms the order; failure and cancel leave it placed; a replayed notification changes nothing; a tampered amount or signature is rejected; closing the browser after paying still confirms the order.

**Verify** Feature tests for each notification case. Run each case against the sandbox and record the result in `docs/payhere-test-record.md`. Replay a captured notification with `curl`.

### Phase 27: Online quote acceptance and advance payment

**Tasks**
1. The acceptance form in `QuoteView`: fulfilment, address or showroom, payment method, and "pay the advance only" when an advance is set.
2. `AcceptQuote`, the online counterpart of `AcceptQuoteByStaff` from phase 11: check validity, reserve stock, move to `placed`, create the pending payment for the advance or the total.
3. Confirmation rule: reaching the required advance confirms the order; the balance stays visible on the order page and in the admin.
4. An expired quote shows "ask us to refresh this quote" with a WhatsApp button.

**Done when** a quote with a 50% advance can be accepted, the advance recorded, the order confirmed, and the balance recorded later.

**Verify** Pest browser test for the flow. Unit tests for the confirmation rule.

### Phase 28: Customer messages, Release 3 hardening and launch

**Tasks**
1. Mail classes in three languages for order placed, payment confirmed, ready or dispatched, sent only when an email exists.
2. Tier 2b translation review complete.
3. Pest browser journeys 7 and 8 from 9.3 (Flows B and C) in three locales. Replace Livewire's default "page expired" prompt on the cart and checkout pages with a translated "reload" message.
4. One baseline security scan of staging; fix anything real.
5. Repeat the restore drill from phase 22.
6. One real low-value card purchase and refund on production, by you, to check the live merchant settings.
7. Run the Release 3 checklist in 10.3.

*Midpoint: checkpoint after task 3.*

**Done when** the checklist is fully ticked.

**Verify** The checklist itself.

### Phases 29 to 32: Release 4

Specified lightly on purpose. Re-plan each one using what the earlier releases taught you.

- **29 Comparison.** Up to three products of one category side by side from the stored specification fields. Selection in `localStorage`. Done when the table shows only fields that differ plus price and coverage.
- **30 Guide library and case studies.** More Markdown guides chosen from search-query and lead data. Case studies need written permission for photos.
- **31 Reviews.** Only from customers with a completed order, invited by link, moderated in the admin before publishing.
- **32 Ad pixels with consent.** A preference control that blocks advertising scripts until opt-in. Purchases reported from confirmed orders on the server, never from the return page. Build this as soon as paid advertising starts, whatever release is in progress (section 8.3).

## 9. Testing and verification

The test effort is concentrated where a mistake costs money or trust: quantities, prices, stock, payments and access to private pages. Everything runs in one tool, Pest.

### 9.1 Unit tests (`tests/Unit`, `app/Domain` only, no database)

| Class | Cases that must exist |
|---|---|
| `Estimator\Units` | Feet and inches to millimetres rounding up; metres to millimetres |
| `Estimator\StripEstimator` | All four rooms in the table in 3.5, each checking both layouts, the chosen one and the 5% figure; the no-join preference (15 ft by 10 ft chooses 16, not 14); two rectangles summed without sharing offcuts; pack rounding with `pieces_per_unit` above 1; a room smaller than the fit tolerance rejected; zero and negative input rejected; waste above 25 rejected |
| `Estimator\AreaEstimator` | 3658 mm by 3048 mm with coverage 1,095,000 gives 11 at 0% and 12 at 10%; several rectangles; zero and negative input rejected; waste above 25 rejected |
| `Estimator\RoofPlaneEstimator` | Only if roofing is built. The five rafter lengths in 3.5 (3000, 3048, 3049, 5946, 5947 giving 1, 1, 2, 2, 3); two planes; a sheet no longer than the end lap rejected |
| `Invoice\Tax` | VAT inside a VAT-inclusive total: 11,800,000 at 1800 basis points gives 1,800,000; 4,525,050 gives 690,262; a null rate gives 0 |
| `Invoice\DocumentNumber` | Formats `INV-2026-00001`; pads to five digits; a number above 99,999 still formats |
| `Sms\Message` | Each template in three languages; Sinhala and Tamil templates at or under 70 characters with realistic values; no token and no customer name in staff alerts |
| `Estimator\Accessories` | Each basis; rounding to accessory pack size |
| `Money` | Cents to display string; parsing `price_lkr` strings without floats |
| `Phone` | `0771234567`, `+94771234567`, `077 123 4567`, a landline, and five invalid forms |
| `Reference` | Alphabet excludes ambiguous characters; prefix and length |
| `Search\Normaliser` | Case, NFC, zero-width joiner removal, whitespace |
| `WhatsApp` | Encoding of Sinhala and Tamil text; ten-line cap; length cap |
| `Order\Totals` | Subtotal, delivery, discount, total; advance not above total |
| `Order\Eligibility` | Every rule in 3.9, one test per row of the payment-method table |
| `Order\StateMachine` | Every allowed transition passes; every other pair is refused |
| `PayHere\Signature` | The fixture in 3.10; wrong secret fails; tampered amount fails; missing signature fails without an error |

Plus one architecture test: nothing in `App\Domain` uses `Illuminate`, models or facades.

### 9.2 Feature tests (`tests/Feature`, against Postgres)

- `PlaceOrder` reserves stock and writes movements; a second order for the last unit fails.
- The 20-way concurrency script from phase 24.
- `HandlePayHereNotification`: valid, duplicate, bad signature, wrong amount, wrong merchant, late payment on an expired order, charge-back after confirmation.
- `SweepExpired`: expires what it should and nothing else; safe to run twice.
- Role access for each Filament resource and action.
- Token access: right token, wrong token, rotated token.
- Product visibility: unpublished and inactive never appear publicly.
- The importer: one test per rule in 4.7.
- `ResolveCustomer`: the same number in three written forms resolves to one customer; a new number creates one; a staff-edited name is not overwritten; two simultaneous enquiries from a new number create one customer, not two (the unique index decides, and the loser retries).
- `SubmitEnquiry`: stores one estimate row per estimate sent, with the server's result and not the browser's; stores the visit source; the fourth enquiry from one phone in an hour is refused while a different phone from the same address succeeds.
- `AcceptQuoteByStaff`: an expired quote is refused; tracked stock falls; a short line is refused unless overridden, and the override is logged.
- `NextDocumentNumber`: 20 simultaneous calls return 20 different consecutive numbers with no gap.
- `IssueInvoice`: copies lines and totals; a second issue for the same order is refused; a later change to the order does not change the invoice; voiding needs a reason and frees the order for a new invoice.
- `MergeCustomers`: everything moves to the surviving record and the balance is the sum of both.
- `SweepExpired`: a customer with no order and no activity past the limit is anonymised; one with an order is not.
- Every report query in phase 21, against a seeded data set with known totals.

### 9.3 Browser tests (`tests/Browser`, Pest browser plugin)

Eight journeys. The customer-facing ones run in `en`, `si` and `ta`; the staff ones run once, because the admin is English only.

| # | Journey | Flow | Release | Locales |
|---|---|---|---|---|
| 1 | Estimate on a product page to enquiry to WhatsApp link, with the measurements stored | A | 1 | three |
| 2 | Calculator page to enquiry | A | 1 | three |
| 3 | Showroom lookup | D | 1 | three |
| 4 | Staff: website lead to quote to customer quote page to mark accepted to record payment | E | 1 | once, plus the quote page in three |
| 5 | Staff: phone lead for a new customer to quote | F | 1 | once |
| 6 | Staff: follow-up completed and logged; counter sale to invoice to printed page | G, H | 2 | once, plus the invoice page in three |
| 7 | Direct purchase by bank transfer through to staff confirmation | B | 3 | three |
| 8 | Online quote acceptance with an advance | C | 3 | three |

Each journey asserts no JavaScript errors and no accessibility issues on every customer-facing page it visits. Card payment is not automated in the browser; it is covered by the notification feature tests and the manual sandbox record from phase 26.

### 9.4 Manual checks, once per release

- Keyboard only, 200% zoom, 320-pixel width, one screen reader pass on the critical pages.
- A real low-end Android phone and an iPhone, on mobile data, in all three languages.
- Native reviewers read every screen of the journeys in their language.
- Five-person usability check before Release 1.

### 9.5 Targets

| Measure | Target | Evidence |
|---|---|---|
| Largest Contentful Paint | at or under 2.5 s at the 75th percentile, mobile | Lab before launch; Cloudflare Web Analytics once there is traffic |
| Interaction to Next Paint | at or under 200 ms | Cloudflare Web Analytics |
| Cumulative Layout Shift | at or under 0.1 | Lab and Cloudflare Web Analytics |
| Server time, product page | at or under 150 ms at the 95th percentile | Measured locally in phase 6 and on staging in phase 15 |
| Accessibility | WCAG 2.2 AA on the five journeys | Automated assertions plus the manual pass |
| Availability | 99.5% monthly, as an objective | Better Stack |
| Recovery | Service back within 4 hours; data loss bounded by the host's restore window, to be recorded here in phase 1, and at worst 24 hours from the nightly copy | Restore drill in phase 22, before the CRM holds real customer records |

A new site has no field data on launch day. Lab results stand in until there is a meaningful sample; say so rather than claiming the targets are met.

## 10. Deployment and launch

### 10.1 Release 1 checklist

1. The business owns the domain, Laravel Cloud, Cloudflare, Resend, Sentry and GitHub accounts, each with 2-step verification and a recovery contact.
2. Production migrations applied from `main`. The seeder has run with the real admin email, and that admin has enrolled an authenticator app and stored the recovery codes offline.
3. Production compute is kept awake; a spending limit is set.
4. The agreed number of products is published (30, or the client's full range if it is smaller, and never fewer than 12), each with a reviewed title and summary in three languages, verified dimensions and coverage, at least one photo, and a price with its unit.
5. The estimator formulas are approved and recorded in settings.
6. Settings filled in: business details, WhatsApp number, response hours, staff alert phone numbers. The SMS sender name is registered and the account has credit.
7. Showroom hours verified within the last 30 days.
8. The Tier 1 list in `lang/_pending.json` is empty.
9. Privacy policy and terms published in three languages and reviewed.
10. Sending domain verified; a test staff alert arrives.
11. Sitemap submitted to Search Console; indexing allowed on production only; staging is `noindex` and behind basic auth.
12. Security headers present; Content Security Policy enforcing; Sentry receiving; `APP_DEBUG` false.
13. Uptime monitors green; an alert test reaches your phone.
14. The nightly database backup and the weekly photo backup have each succeeded at least once. The production database is set not to sleep.
15. No cart or checkout route exists on `main` yet, and `PAYHERE_ENABLED` is false.
16. A real enquiry from a phone, in each language, reaches the staff inbox and a staff phone by SMS, shows the room measurements, is linked to a customer record, and is answered on WhatsApp.
17. Each member of the sales staff has, on production and without help: added a phone lead, built and sent a quote, marked it accepted, and recorded a payment.
18. A link with campaign tags was opened on a phone, an enquiry sent, and the campaign shows on the lead.
19. Merge `dev` into `main`. Share with a small audience first and watch enquiries and errors daily for a week.

### 10.2 Release 2 checklist (CRM)

1. Section 1.9 carries the client's answer against every row, and what was built matches it.
2. The client's existing customer list is imported, and the client has checked a sample.
3. Every member of staff has their own account with an authenticator app, the right role, and has been through the training session. Nobody shares a sign-in.
4. Each automatic follow-up rule is on or off as the client chose, and the morning digest arrives at the agreed time.
5. VAT settings entered from the accountant: registered or not, number, rate, and whether prices include VAT.
6. The accountant has approved a sample invoice and a sample receipt, and the approval is recorded.
7. The first invoice number is agreed with the accountant. If the business already has an invoice book for the year, the sequence starts after its last number.
8. Tier 2a translations reviewed; invoice and receipt printed in three languages on the client's own printer.
9. SMS: each customer message type is on or off as the client chose, each arrives on a real phone, and the account has credit.
10. Stock: the tracked variants are agreed, opening quantities entered, and the person who keeps them current is named. Everything else is untracked.
11. The client has read every report on real data.
12. The privacy policy describes the CRM: what is kept, for how long, and how to ask for removal.
13. Backup restore drill done and recorded, for the database and for one photo.
14. One counter sale, one invoice and one payment done on production by a member of staff, not by you.
15. Merge `dev` into `main`.

### 10.3 Release 3 checklist (online checkout)

1. The review point in 8.3 has been held and its decision is written there.
2. Delivery matrix filled in for every district the business will actually serve, with weight limits; the rest left unavailable.
3. Bank details in settings, checked against a bank document by a second person.
4. Limits in settings match the PayHere plan actually approved.
5. Tier 2b translations reviewed.
6. Delivery, returns and warranty policies published and consistent with what checkout says.
7. The scheduler is on in production and `store:sweep` has logged a run that expired a test hold.
8. One bank-transfer order placed, confirmed and completed on production by a real person, who reopened it from the SMS link.
9. If cards are live: `PAYHERE_MODE=live`, live merchant ID and secret set, the production domain approved, and one small real purchase and refund reconciled against the PayHere dashboard. If the merchant account is not approved yet, launch without cards and add them later; nothing else waits.
10. Staff have practised: confirm a transfer, accept a cash-on-delivery order by phone, cancel a refused delivery, handle a "needs attention" payment.
11. Merge `dev` into `main`. Announce.

### 10.4 Rollback

- **Code.** Redeploy the previous deployment from the host's deployment history. This touches no data.
- **Schema.** Migrations are additive within a release (add columns and tables; do not drop or rename). Removing things happens in a later release once nothing reads them. That is what makes a code rollback safe.
- **Payments or stock behaving wrongly.** Set `PAYHERE_ENABLED=false`, or put checkout behind a setting, and leave the quote path running. Then reconcile.
- **Data.** A database restore is an incident decision, not a deployment step. After any restore, compare PayHere's records with the orders table before fulfilling anything.

### 10.5 Routine after launch

- **Daily, first month:** lead queue and overdue count, overdue follow-ups per person, SMS credit, orders awaiting payment confirmation, anything flagged for attention, Sentry.
- **Weekly:** reconcile card payments with PayHere and transfers with the bank statement; check the backup workflow; review search terms with no results and add synonyms or products.
- **Monthly:** `composer update` and `npm update` on a branch with the full test run; funnel by language and device; deactivate staff who have left; check usage and the spending limit on the host.
- **Yearly:** the Laravel major upgrade, on a branch, with the test suite as the safety net.
- **Before any ad campaign:** stock, response capacity and landing pages checked.

## 11. Risks and hard parts

| Risk | Why it is hard | Mitigation |
|---|---|---|
| The estimator gives a wrong quantity | Customers act on it, and real rooms are not rectangles. Revision 2's formulas were wrong for ceilings and for roofing, and still passed their own test, because the single worked example happened to hide the error. | Four worked rooms per method, chosen to be awkward, each checked by running the arithmetic; specialist approval before launch; waste allowance shown and adjustable; "provisional" wording; roofing is quote-only; every estimate attached to an enquiry is re-run and visible to staff. |
| Catalog data is not ready | It is the differentiator and it is not engineering work. | Start in step 0. Launch bar is 30 complete products, not 100. The import refuses inconsistent rows. |
| Translation quality | Technical terms for building materials are easy to get wrong, and an error reads as carelessness. | Named native reviewers, a glossary, review flags on every record, and publishing blocked on unreviewed titles. |
| PayHere approval is slow or refused | Outside your control. | Release 3 works without cards. Bank transfer and pay on collection need nobody's approval. |
| Card limit below the order value | Plus allows LKR 250,000 per payment. | Card option hidden above the limit; bank transfer and advance payments cover large orders. |
| Bank-transfer fraud or mistakes | A forwarded slip image proves nothing. | Staff confirm only against the bank statement. The order page says so. |
| One maintainer | No second pair of eyes on payment code. | Payment logic is small, isolated in `app/Domain` and `app/Actions`, and covered by fixed-value tests and the sandbox test record. Consider paying for a one-off review of phases 24 to 26. |
| Business rules leaking into Livewire components or Filament resources | It is the easy path in this stack, and it makes rules untestable and duplicated. | The Domain and Actions rule in 8.1, the architecture test, and a check in every pull request. |
| Livewire public properties are client-visible and client-editable | A customer could alter a product id or a price held in component state. | Prices are never held in component state as inputs; ids that must not change are locked; every action recomputes from the database. |
| Hosting on a young platform | Laravel Cloud launched in 2025; features and prices may shift. | Nothing in the application is specific to it. The same code runs on any PHP host; moving means a new host and a database dump. |
| Two PHP versions on your machine | XAMPP's 8.2 could be picked up instead of Herd's 8.4. | Phase 0 checks `php -v`. CI runs 8.4 regardless. |
| Nobody answers the enquiries | The whole plan assumes a fast human reply. | Named owner and hours in step 0; overdue badge on the dashboard; response hours shown to customers so the promise is one you can keep. |
| The competitor is a manufacturer with a national network | You will not beat it on reach or brand. | Compete where the evidence shows a gap: Sinhala and Tamil, quantity help, a short quote path, honest units. Re-check after step 0. |
| Legal exposure | Data protection law is in transition; price and return rules apply to retail. A CRM keeps personal data on people who never bought anything. | Lawyer review of the privacy policy, terms, returns policy and the retention periods in 3.13 before Release 1. Anonymisation is built in, and nothing sends promotions. |
| The CRM built is not the CRM the client meant | Section 1.9 is my guess at what "a whole CRM system" means. Clients often mean stock purchasing, a till, or accounting. | Sign-off on 1.9 is the first task of step 0 and blocks Release 2. The table of usual extras in 1.9 puts a rough size on each. Release 1 is safe either way. |
| Staff do not use the CRM | A CRM is only as good as what staff type into it, and logging a call is extra work with no visible reward. | Website leads log themselves. A staff-entered lead is four fields. Closing a follow-up logs the contact in one line. The owner's morning digest makes neglect visible. Phase 22 is training on the client's real data, not a demonstration. |
| Invoice numbering or tax layout is wrong | Gaps or duplicates in invoice numbers, or a tax invoice missing a required field, are an accounting problem for the client. | Numbers come from one locked sequence with a concurrency test. Invoices are never edited. The client's accountant checks a sample invoice before Release 2. |
| SMS provider is unreliable or the sender name is delayed | Chosen late, not yet examined. | Email alerts still go out. The driver is behind an interface so the provider can be changed in an afternoon. Start registration in step 0. |
| The scope has grown by about a third | Revision 2 was 195 hours before contingency; revision 3 is 275. | The releases are independent. Release 1 alone is a working website and sales desk. Read the timeline in 8.2 against your real weekly hours before promising the client a date. |

## 12. What changed, and why

### From revision 2 (16 November 2026)

Two causes: a review of revision 2 that found errors and gaps, and the client's requirement for a CRM. The tables in the two subsections after this one are history and still use revision 2's release and phase numbers.

**Errors corrected**

| Revision 2 | Revision 3 | Where |
|---|---|---|
| Ceiling quantities from room area divided by panel area | Row-by-row layout in both directions; the 7 ft by 10 ft room now gives 8 panels, not 6 | 3.5 |
| Roofing rows charged an overlap to the last sheet | Corrected formula; a 3,000 mm rafter now needs 1 sheet, not 2 | 3.5 |
| Quote list kept product and quantity only; one estimate snapshot per enquiry | Every estimate's room measurements are stored and shown to staff | 3.2, 3.5, 4.3 |
| Five phase numbers in the decisions table pointed at the wrong phase | Table rebuilt against the new numbering | 1.6 |
| Translated columns were `json` | `jsonb`, with a test | 4.1, 4.3 |
| Query cache stored in the database it was meant to spare; no rule on database sleep | File cache per instance; production database kept awake; measure before keeping the cache at all | 3.3 |
| Five enquiries per IP address per hour | Three per phone number, thirty per address | 3.13 |
| No way out of `dispatched` except `completed`; no hold for cash methods | Cancel-after-dispatch with reasons; a hold for every payment method; part-paid orders flagged, not expired | 3.7 |
| Only the database was backed up | Weekly photo and document backup added | 6 |

**Limitations addressed**

| Revision 2 | Revision 3 | Where |
|---|---|---|
| Five product categories copied from the competitor | Ceilings and accessories active; the rest switched off until the client confirms; roofing mode built only if confirmed | 0, 4.6, phase 7 |
| Release 1 could send a quote but not record a sale | Staff mark quotes accepted and record payments in Release 1 | 3.7, phase 11 |
| No record of which campaign produced a lead | Visit source stored on every lead and order | 3.16 |
| A product could go live with only its title translated | Title and summary must be reviewed in all three languages | 1.8, 3.4 |
| A customer without email had no way back to their order and got no updates | Order link and status by SMS | 3.15, phases 20 and 25 |
| Staff were alerted by email only | Email and SMS | 3.15, phase 8 |
| No customer record; repeat buyers looked new | Customer record keyed by phone number | 3.14, phase 9 |
| One price for everyone | Optional trade price used by the quote builder | 3.14, 4.3 |
| The quote existed only as a web page | Printable quote with revisions; printable invoices and receipts | 3.14, phases 10 and 19 |
| Flat delivery fee whatever the quantity | Weight limit per rate; heavier carts go to a quote | 3.9 |
| Stock figure drifts when sales happen at the counter | Untracked until counter sales exist; then tracked only with a named owner | 3.8, phase 18 |
| No estimator outside product pages | Calculator page per category | 3.5, phase 7 |
| No decision point before building checkout | Review point after four weeks of Release 1 | 8.3 |
| Three-language editing relied on a community plugin | Own small form helper | 2.2 |
| Timeline assumed 25 to 30 hours a week and called every phase one session | Two weekly budgets shown; long phases split with a checkpoint; design time named as excluded | 8, 8.2 |
| PayHere Plus assumed | Lite compared; choose in step 0 | 2.3 |

**Added for the client's CRM requirement**

| Addition | Where |
|---|---|
| Proposed CRM scope, row by row, for the client to confirm, with a table of the extras clients often mean | 1.9 |
| How the CRM works: customers, leads, follow-ups, contact history, documents, numbering, VAT, counter sales, reports | 3.14 |
| Tables: customers, enquiry estimates, quote snapshots, follow-ups, interactions, document sequences, invoices | 4.3 |
| A whole release for it, seven phases, about 59 hours | Phases 16 to 22 |
| Releases renumbered: CRM is Release 2, online checkout moves to Release 3, depth to Release 4 | 0, 1.4, 8.2 |

**Reviewed and left as it was**

| Point | Why |
|---|---|
| The admin is English only | Translating Filament and every CRM screen is large; ask the client in step 0 whether staff need it before pricing it. |
| A session expiring on an idle checkout tab shows Livewire's "page expired" prompt | Real, but cosmetic. Phase 28 makes that prompt a translated "reload" message. |
| WhatsApp conversations are not recorded automatically | That needs the paid Business API. Staff log contacts in one line instead. |

### From the 30 September plan

| Earlier plan | This plan | Reason |
|---|---|---|
| Everything in one launch at week 12 | Three releases | Section 0 |
| WooCommerce with eleven plugins | One Laravel application | WooCommerce is 1 to 2% translated in Sinhala and Tamil; the competitor's weakness is the same plugin stack |
| Seven roles, review board | One developer plus two native reviewers | It is one person |
| Card gateway only; deposits deferred | Bank transfer, pay on collection, cash on delivery, card, advance payments | About half of local online shoppers prefer cash on delivery; card payments cap at LKR 250,000; 2.99% is costly on thin margins |
| Quote plugin plus a separate custom enquiry record | One enquiry, one quote that becomes the order | Half the screens to build, translate and test |
| WhatsApp as a contact link | WhatsApp as the handoff for estimates, quotes and bank slips | It is how local buyers already deal with suppliers, and it costs nothing |
| Search synonyms in three languages | Plus romanised Sinhala and Tamil | People type Sinhala in Latin letters |
| Catalog and translation "additional" | Tiered, sized and on the critical path | They are the differentiator |
| 99.9% availability, two hourly backup systems | 99.5% objective, host restore plus a nightly independent copy | Honest for one maintainer |
| Nine QA tools on every change | One test runner covering unit, database and browser; one-off scan and restore drill | Test effort goes where mistakes cost money |
| Tag Manager, GA4, custom consent module at launch | Cookieless analytics now, consent when ad pixels arrive | Lighter pages and less to build |
| US$75 to 115 a month plus licences | About US$15 to 25 a month before PayHere | Managed host, no paid plugins |

### From my first blueprint (now `BLUEPRINT-nextjs-variant.md`)

| First blueprint | This revision | Reason |
|---|---|---|
| Next.js, Prisma, Clerk, Vercel, chosen because it was your usual stack | Laravel, Filament, Livewire, Laravel Cloud, chosen for the project | You asked for the best fit rather than the familiar one |
| Hand-built admin, about 60 hours | Generated Filament admin | The largest single saving |
| Staff sign-in through Google accounts to work around a paid multi-factor tier | Built-in authenticator-app sign-in with recovery codes | First-party, free, no third service |
| Cron, email, rate limiting and audit log assembled from separate services | Scheduler, mail, rate limiter and activity log in the framework | Fewer accounts and fewer moving parts |
| Static pages with a new caching API | Server-rendered pages with cached queries | Simpler to reason about; no page cache to invalidate |
| Vitest plus Playwright plus axe | Pest for all three | One runner |
| 310 to 330 hours | 270 to 290 hours | Same scope |
| Product, releases, payments, estimator, order model, checklists | Unchanged | They never depended on the stack |

## 13. Sources checked on 2 October 2026

- Competitor site, public pages: https://www.sivilima.lk
- PayHere Checkout API: https://support.payhere.lk/api-&-mobile-sdk/checkout-api
- PayHere fees and limits: https://www.payhere.lk/fees/
- Laravel Cloud pricing and regions: https://laravel.com/cloud/pricing
- Filament multi-factor authentication: https://filamentphp.com/docs/5.x/users/multi-factor-authentication
- Filament 5 release: https://laravel-news.com/filament-5
- Laravel Herd for Windows: https://herd.laravel.com/windows
- Pest browser testing: https://pestphp.com/docs/browser-testing
- Payload e-commerce plugin status: https://payloadcms.com/docs/ecommerce/plugin
- WooCommerce translation status: https://translate.wordpress.org/projects/wp-plugins/woocommerce/
- WPML pre-configured languages: https://wpml.org/documentation/getting-started-guide/language-setup/pre-configured-languages/
- Payment preference survey: https://www.dailymirror.lk/breaking-news/Sri-Lankan-online-shoppers-stick-to-cash-on-delivery-amid-digital-payment-hesitation/108-307888
- Personal Data Protection Act: https://www.dpa.gov.lk/est.php
- Package versions: Packagist and the npm registry

Verified by running code: the PayHere signature class in 3.10 against its fixture (PHP 8.2), the estimator worked example in 3.5 (PHP 8.2), and PHP syntax of the migration in 4.3.

Not verified: the migration against a real database (needs PHP 8.3 or newer), Resend's free allowance, that Laravel Cloud offers PHP 8.4 and outside database connections, that the server-rendered pages are in practice lighter on low-end phones than the Next.js variant would be, the competitor's page speed, and anything on the competitor's site beyond what an automated fetch returned.

**Revision 3, 16 November 2026.** Nothing online was re-checked: the package versions, PayHere's protocol and fees, and the host's plans are all as found on 2 October. Verified by running code: the four strip-layout rooms, the area test case, the five roofing rafter lengths and the two VAT values, in Node with integer arithmetic. Not verified: the new tables and columns in 4.3 against a real database; every SMS provider; PayHere Lite's per-payment limit; whether the host's database can be kept awake and what that costs; that browser printing produces an acceptable Sinhala and Tamil invoice on the client's printer; and the whole of section 1.9, which no client has seen.
