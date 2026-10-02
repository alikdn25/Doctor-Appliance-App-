# Progress

Status of the delivery stages from [`SPEC.md`](../SPEC.md) §10. Updated at the end of every task.

## Stage 0 — Foundation ✅ Done

- Laravel 13 + Inertia React + TypeScript + Tailwind skeleton, Pest tests, PostgreSQL.
- Auth (Fortify): login, password reset, 2FA (required for Owner/Admin).
- Companies (tenants) with timezone, currency, invoice/estimate numbering, business hours, `plan`,
  `subscription_status`.
- Tenant isolation: `BelongsToCompany` trait + global scope, fails closed without a current company;
  isolation tests in `tests/Feature/Tenancy`.
- Brands: name, logo, colors, website, contacts, sender identity, GST/business numbers, invoice footer/terms,
  addresses; per-brand user access.
- Team: memberships with fixed roles (Owner, Admin, Technician; Subcontractor/Collector reserved for Stage 2),
  invitations, company switcher.
- Tax rates per company (named rates as settings; compound and tax-inclusive since task 6).
- Super-admin panel: companies list, status, plan, impersonation with audit log.
- CI (GitHub Actions tests) and manual deploy pipeline to the VPS (`docs/DEPLOYMENT.md`).

## Stage 1 — MVP 🚧 In progress

| #   | Task                                                                        | Status         |
| --- | --------------------------------------------------------------------------- | -------------- |
| 1   | Customers, properties (manual address), appliances (§6, §7.1)               | ✅ Done        |
| 2   | Jobs & statuses, visits, My jobs (§6, §7.3 w/o calendar, §7.4)              | ✅ Done        |
| 3   | Calendar & dispatch                                                         | ✅ Done        |
| 4   | Technician PWA view, photos, signatures                                     | ✅ Done        |
| 5   | Estimates, invoices, manual payments (§7.5, §7.6)                           | ✅ Done        |
| 6   | International groundwork, payment terms, Square payments (§1.1, §1.2, §7.6) | ✅ Done        |
| —   | Twilio SMS (automated messages + inbox)                                     | ⏳ Not started |
| —   | Review request toggle                                                       | ⏳ Not started |
| —   | Price book (starting services and Services page done in task 6)             | 🚧 Partly      |
| —   | Basic reports                                                               | ⏳ Not started |
| —   | Google Places autocomplete + geocoding for properties                       | ⏳ Not started |

### Task 1 — Customers, properties, appliances ✅

- Tables: `customers`, `customer_phones`, `customer_emails`, `properties`, `appliances` (all with `company_id`,
  tenant-scoped, covered by isolation tests).
- Customer types (residential / commercial / property manager / strata), fixed lead source list, tags, notes,
  several phones and emails with one primary each.
- Properties: manual address (+ unit), gate/buzzer code, access notes, on-site contact with a call button,
  one primary property per customer. `latitude`/`longitude` are reserved for geocoding.
- Appliances: type, manufacturer (autocomplete), model/serial (stored upper case), rating plate photo
  (`MEDIA_DISK`, local `public` disk for now), purchase/install dates, warranty.
- Search by name, phone in any format, email, address, model or serial number; filters by type and tag.
- Duplicate warning on phone/email (never blocks saving).
- Access: Owner and Admin. Technicians see no customers until the Jobs task gives them their own jobs' customers.
- Deleting a customer soft-deletes its properties and appliances; deleting a property soft-deletes its appliances.

Deferred on purpose: strata `units` table (Stage 3), Google Places autocomplete + geocoding,
repair history and jobs/estimates/invoices/messages on the customer card (placeholders until those tasks).

### Task 2 — Jobs, statuses and visits ✅

- Tables: `service_jobs` (named so because `jobs` is Laravel's queue table), `job_appliance`, `job_visits`,
  `job_visit_user`, `job_status_changes`; `companies.job_next_number` (per-company numbering from #1001).
  All tenant-scoped and covered by isolation tests.
- Job: brand, customer, property, appliances, job type, source, problem description, team notes, "work done" notes.
- Visits: arrival window (entered in the company timezone, stored UTC), estimated duration, several assignees.
  Owners and Admins can be assigned too.
- Statuses per SPEC §6. Every change is logged with user, time, visit and optional note.
  Automatic: scheduling a visit → `scheduled`; On my way → `on_the_way`; Start → `in_progress`;
  Finish → `completed` or `waiting_for_parts`. Time on job = Start → Finish of each visit.
  Office can set any status by hand except `invoiced`/`paid` (reserved for invoices/payments); cancelling a job
  cancels its scheduled visits. Deleting the only scheduled visit returns the job to `new`.
- Office (Owner/Admin): job list with search (number, customer, phone, address, model/serial) and filters
  (status, open, type, brand, technician, date range); new job form with customer lookup or inline new customer
  (name, phone, email, address) with duplicate-phone warning, appliances (existing or new), optional first visit;
  job page with visits, manual status change, history. Brand-limited users see/create only their brands' jobs.
- "My jobs" for everyone who goes on calls: Today / Upcoming / Recent, Navigate and Call buttons; job page with a
  sticky action bar (On my way → Start → Finish) and a running timer.
- Technicians see only jobs they are assigned to, plus the customers, addresses and appliances of those jobs
  (read-only). On their jobs they can edit appliance manufacturer/model/serial, add or link an appliance, and write
  "work done". Appliance page shows repair history across all jobs (date, type, problem, work done, no prices).
- Customer card lists jobs and has "New job". Customers and properties with jobs cannot be deleted.
- Demo seeder creates three jobs (today's visit for tech@example.com, one waiting for parts, one unscheduled).

Deferred on purpose: calendar/dispatch, map, travel buffer (Calendar task); SMS on "On my way" (Twilio task);
photos, signatures, checklists, rating plate photo by tech, offline/PWA install (Technician PWA task);
subcontractors (Stage 2); estimates/invoices on the job (placeholders).

### Task 3 — Calendar & dispatch ✅

- `/calendar` for the office (Owner, Admin): **Day** view with one lane per person who can go on calls
  (Owner/Admin/Technician) plus an "Unassigned" lane, and a **Week** view (Mon–Sun, one row per person).
  Built on `job_visits`; laid out in the company's timezone.
- **Drag and drop**: move a visit to another time (15-minute snap) and/or another person; in the week view a drop
  keeps the time and changes the day/person. Drag a job from **To schedule** (new and waiting-for-parts jobs
  without an open visit) onto a lane to book a 2-hour arrival window. Tapping a visit opens details with
  "Open job" / "Edit visit"; tapping a job in To schedule opens the visit form.
- **Arrival windows, durations, travel buffer**: each visit shows its window, the extra time on site when the
  estimated duration is longer than the window, and a hatched travel buffer after it. Company setting
  "Travel buffer (minutes)", default 30. Visits of the same person that overlap including the buffer are
  flagged in red.
- **Route of the day** (instead of a map until Google Places): each person's addresses in visit order with an
  "Open route in Google Maps" link (directions URL, no API key).
- Visible hours follow the company's business hours, widened to fit early/late visits.
- New company time zone: in the super-admin form the default is "Detect from the Owner's browser". The company
  starts on the default zone with `timezone_pending`; the Owner's first page load sends the browser zone once
  (audited). Saving company settings ends detection.
- Super-admin panel warns when the server's time zone database is older than 6 months (or unreadable).

Decisions made without asking (change if needed):

- Calendar is office-only. Technicians keep "My jobs"; they cannot move visits.
- Only visits that have not started (and whose job is active) can be dragged. Started/finished visits are
  shown greyed out.
- Dragging a visit with several people from one lane to another swaps only the person it was dragged from;
  dropping on "Unassigned" removes that person.
- A visit belongs to the day it starts on. Time on site = the later of window end and start + estimated
  duration; conflicts are checked on that plus the travel buffer.
- Drag and drop uses the browser's native drag events: works with a mouse; on phones, tap a visit and use
  "Edit visit" instead (no extra library).
- People who left the team keep a lane (marked *) while they still have visits, so nothing disappears.
- The browser time zone is ignored while a super-admin impersonates the Owner (it would be the super-admin's).
- The time zone database stores only a version (e.g. `2026b`), not a release date. The age is estimated as two
  months per release letter from January 1 of that year (capped at December 1). Threshold:
  `fieldservice.tzdata.max_age_months` (6).
- Note: the current tzdata (2026b) treats America/Vancouver as UTC−7 all year (BC's permanent daylight time).

Deferred on purpose: Google map of the day and route optimisation (Google Places task), passing visits to a
subcontractor (Stage 2), recurring visits (Stage 3).

### Task 4 — Technician PWA, photos, checklists, signature ✅

- **Installable app**: web app manifest (`/manifest.webmanifest`, opens on My jobs), icons, service worker
  (`public/sw.js`) caching built assets, and an offline page when there is no connection. "Install app" button on
  My jobs (Android/Chrome; on iPhone: Share → Add to Home Screen).
- **Photos before/after**: one tap opens the camera, several photos at once. Photos are shrunk on the phone
  (max 1600 px JPEG), saved in the phone's storage (IndexedDB) and uploaded by a queue that retries with growing
  pauses, right away when the signal returns and on the next app start. A bar under the header shows how many
  are still waiting. Retries are idempotent (`client_uuid`). Photos are served only through an authorized route.
  The photographer or the office can delete a photo.
- **Rating plate photo** from the job (camera button on each appliance), same queue, kept sharper (2400 px).
- **Checklists per job type**: Company → Checklists (Owner/Admin), one item per line. A new job gets its own copy;
  changing the job type swaps it while nothing is ticked. Whole row is the tap target. Defaults are created for
  new and existing companies.
- **Customer signature** drawn with a finger, with the signer's name; stored as PNG, replaces the previous one.
- **Calendar on phones**: press and hold a visit (or a job in To schedule), then drag with the finger; the page
  scrolls near the edges.
- SPEC §7.6 updated: pluggable payment providers, manual payment methods.

Decisions made without asking (change if needed):

- No full offline mode (as agreed): pages need the network; only photos, rating plates and signatures are kept
  on the phone until they upload. Checklist ticks and notes need a connection.
- The installed app opens on My jobs (fewer taps for technicians; the owner also goes on calls).
- Photos are only "before" and "after" (no third kind). One signature per job; signing again replaces it.
- Job photos and signatures are served through the app (permission check), not by public URL. Rating plate
  photos still used the storage URL as in task 1 — fixed in task 5 (see below).
- Upload errors that cannot be fixed by retrying (validation, no access, deleted job) stop and show Retry /
  Discard; network errors and server errors retry automatically.
- Icon: a neutral wrench in the brand teal until the product name and logo are decided.
- Touch drag uses a 350 ms press-and-hold so normal swipes still scroll the calendar.

Deferred on purpose: estimates/invoices/payment on site (next tasks), SMS on "On my way" (Twilio task),
plate OCR (later), background sync while the app is closed (the queue resumes when the app is opened).

### Task 5 — Estimates, invoices, manual payments ✅

**Security fix first.** Job photos, signatures and rating plate photos were stored on the public disk, and rating
plates were opened by a direct `/storage/...` URL without a login. They now live on a private disk
(`PRIVATE_MEDIA_DISK`, default `local` = `storage/app/private`) and are only served by routes that check access.
Rating plates: `GET /appliances/{appliance}/rating-plate` with the appliance view policy (office, or a technician
with a job at that property). A migration moves existing files off the public disk. Tests: access by role, and a
user of another company gets 404.

**SPEC.** New §11 "Platform subscription billing" (Stripe Billing for company subscriptions, plans, founding
members, trials, Customer Portal, our DB as source of truth, moving to a new Stripe account) and
`docs/BILLING-MIGRATION.md` with the full procedure. Not implemented — separate task before the Phase B launch.

**Estimates and invoices** (tables `estimates`, `estimate_items`, `invoices`, `invoice_items`, `payments`; money in
cents; all tenant-scoped and in the isolation tests):

- Created from a job ("Estimates & invoices" section on the job page, also listed on the customer card). Customer,
  brand and address come from the job. Numbers: company prefix + counter (`EST-…`, `INV-…`), taken numbers are skipped.
- Line items: description, quantity (2 decimals), price (negative allowed for credits), taxable flag. Discount as a
  $ amount or % of the subtotal, taken before taxes. Taxes chosen per document (defaults ticked), copied onto the
  document with name and rate, so later rate changes never change existing documents. Totals are calculated on the
  server (`DocumentTotals`) and previewed live in the form.
- Estimate: draft → customer approved / declined (buttons, for on-site agreement) → "Create invoice" copies lines,
  discount, taxes and notes; the estimate becomes "invoiced" and read-only.
- Invoice: unpaid → partially paid → paid, from its payments. Editable until void; the total cannot go below what
  is already paid (such edits are audited). Void (office only, with reason, audited) needs its payments voided first;
  a voided invoice made from an estimate frees the estimate to be invoiced again.
- **Manual payments** on every company: cash, cheque (number optional), e-Transfer (reference optional), card on own
  terminal (transaction # required), other (note required). Partial payments; not more than the balance; date
  received (today = now, earlier day = noon that day). Method picked with one tap, amount defaults to the balance.
  Payments are never deleted: the office voids one with a reason (audited) and it stays in the history.
- Job status: a job that is completed moves to `invoiced` when it has invoices and to `paid` when all are paid;
  back to `completed` if they are voided. A job still in progress keeps its status and moves on when the visit is
  finished (tech invoices and takes payment, then taps Finish).
- Access: office of the job's brand and people assigned to the job (technicians create estimates/invoices and take
  payments on their jobs). Voiding invoices/payments and the invoice list (`/invoices`, outstanding by default,
  with the outstanding total) are for the office. A job with invoices cannot be deleted.
- **Payment provider interface** (`App\Payments\PaymentProvider`: key, label, isConnected, createPaymentLink),
  registry from `config/payments.php` (empty until Square), company setting "Online payment provider" (None by
  default), and `RecordPayment::fromProvider()` for webhooks (idempotent on the provider's payment ID; online
  payments are refunded at the provider, not voided here). Tested with a fake provider.
- Demo seeder: the waiting-for-parts job has a paid diagnosis invoice and a repair estimate with a fee credit.

Decisions made without asking (change if needed):

- **Several taxes can be "apply by default"** (was: only one). BC needs GST and PST on most appliance repairs; the
  demo company now has both on by default.
- Good / Better / Best options on estimates were **not** built (not in this task's list); the data model keeps lines
  on the document so options can be added as groups later.
- No invoice "draft" status: an invoice is issued when created (fewer taps on site). Due date defaults to the
  invoice date (due on receipt).
- Invoices stay editable after payments (e.g. an extra part added on site), as long as the total ≥ paid; changes
  of the total on an invoice with payments are written to the audit log.
- An invoice with payments cannot be voided until the payments are voided — no silent loss of money records.
- Overpayment is refused for manual payments; provider payments are recorded as reported.
- Estimates and invoices of a job follow the job's access rules; technicians see prices on their own jobs' documents
  (they create them on site, SPEC §5).
- Estimate approval is recorded by staff; online approval/signature comes with sending by SMS/email.

Deferred on purpose: Square (next task), sending estimates/invoices by SMS/email with a PDF and online approval,
price book picker on lines, review request toggle, payment reminders and aging report (Stage 2), deposits as a
separate concept (a partial payment covers it for now), platform subscription billing (SPEC §11, before launch).

### Task 6 — International product, handyman vertical, payment terms, Square ✅

**Product is international** (SPEC §1.1, CLAUDE.md updated). The code was checked for Canadian assumptions and fixed:

- Company: `country` (picked when the super-admin creates the company), `locale` (regional format: dates, times,
  numbers; e.g. en-US / en-CA / en-GB), `currency` (any ISO 4217 currency in use), `timezone`. The country fills in
  currency, regional format and default time zone; all can be changed in Company settings. No more CAD /
  America/Vancouver defaults in the schema or models; `DEFAULT_COMPANY_COUNTRY` (US) only preselects the admin form.
- **Money stored with its currency**: `currency` on estimates, invoices and payments (existing rows got their
  company's currency). Amounts are in the currency's **minor units** (JPY 0 decimals, KWD 3) — columns keep their
  old names. Formatting everywhere goes through `Intl` / PHP `NumberFormatter` with the company's regional format;
  no hard-coded "$" (price inputs, payment dialog, discount "Amount (symbol)"). A later currency change never changes
  existing documents; the invoice list sums outstanding money per currency.
- **Taxes**: named rates, **compound** taxes (charged on the amount plus the other taxes, applied last) and **prices
  with tax included** (company setting, copied onto each document). `DocumentTotals` and the React preview do both.
- **Addresses**: `province` → `region`; labels and postal-code validation per country (`config/countries.php`:
  State/ZIP for US, Province/Postal code for CA, County/Postcode for UK …); one-line address in the country's order.
  The country of a new address is the company's.
- **Phones in E.164** (libphonenumber): numbers typed without a country code are read as the company's country;
  existing customer, brand and on-site contact phones were converted. Search finds national formats too.
- Generic payment methods: Check, Bank transfer (e-Transfer, Zelle, ACH …) instead of Cheque / e-Transfer.
  Brand `gst_number` → `tax_number`. Lead source HomeStars → "Online directory" (Yelp, Angi, HomeStars …).
- Calendar and dates use the regional format (12/24 h, day/month order).
- Demo: the BC companies stay (GST + PST); new US company **Lone Star Appliance Repair** (Austin TX, USD, one
  "Sales tax 8.25%", en-US, ZIP codes, an HOA customer on Net 30) — log in as `us@example.com`.
- SPEC: §1.1 markets and localization, §1.2 verticals and out-of-scope construction projects, A2P 10DLC for US SMS,
  Square countries / Stripe next, §11 answers (Solo = exactly 1 user, webhooks change status only, Owner only,
  prices in USD set in config incl. the founding Pro price).

**Verticals** (SPEC §1.2): `companies.vertical` — appliance repair (main) or handyman, chosen when the company is
created. Each vertical has its job types (handyman: repair, installation, assembly, mounting, maintenance, inspection),
default checklists and **starting services**. Services live in a new `services` table (start of the price book) with a
Company → Services page (name, description, price in the company currency, taxable, active). Handyman companies don't
see the appliance sections on jobs (unless a job has appliances).

**Payment terms**: company default (Due on receipt / Net 7 / Net 15 / Net 30) in Company settings; a customer can have
its own terms (customer form; empty = company default). A new invoice's due date = invoice date + the customer's terms
(prefilled on the form with a hint, editable; also used when an estimate becomes an invoice).

**Square** (first provider behind `PaymentProvider`):

- Each company connects **its own Square account by OAuth** (Company settings → Payments → Connect Square; Owner only).
  `state` is checked; tokens are stored **encrypted** (`payment_provider_connections`), never sent to the browser;
  payments go to the account's main location. **Disconnect** revokes the token at Square and forgets it; a revoke made
  in the Square dashboard (`oauth.authorization.revoked`) does the same. Tokens are refreshed when close to expiry and
  by a daily command (`payments:refresh-square-tokens`, scheduled).
- Square is offered only in countries where it works (`config/payments.php`: US, CA, GB, IE, AU, JP, FR, ES) and only
  when the app keys are set. The company's provider select lists only connected providers.
- Invoice page → **Pay online**: one tap makes a Square payment link for the **balance** (QR code for the customer on
  site, Copy / Open). The link is reused while the balance is unchanged; after a partial/manual payment a new link for
  the rest replaces it (old one deleted at Square). Technicians on the job can do it too.
- **Webhook** `POST /webhooks/payments/square` (signature HMAC-SHA256 checked, no CSRF): a COMPLETED payment for one of
  our links is recorded on the invoice as an online payment through Square (card brand and last 4 as reference), via
  `RecordPayment::fromProvider()` — **idempotent on the Square payment ID**, so repeated webhooks create no duplicate.
  Partial online payments and manual payments work side by side. Other merchants, other orders (in-store sales) and
  non-completed payments are ignored.
- Keys only from `.env` (`SQUARE_*`, see `.env.example`).

How to test on the Square Sandbox (no sandbox keys were available in this environment, so the automated tests use a
faked Square API with Square's request/response and webhook shapes):

1. Square Developer Dashboard → your app → Sandbox: copy Application ID and secret to `SQUARE_APPLICATION_ID` /
   `SQUARE_APPLICATION_SECRET`, `SQUARE_ENVIRONMENT=sandbox`.
2. OAuth → Sandbox redirect URL: `{APP_URL}/payment-providers/square/callback`.
3. Webhooks → add subscription (sandbox) for `payment.created`, `payment.updated`, `oauth.authorization.revoked` with
   URL `{APP_URL}/webhooks/payments/square` (must be public: use a tunnel such as ngrok locally); put the signature key
   in `SQUARE_WEBHOOK_SIGNATURE_KEY` and the same URL in `SQUARE_WEBHOOK_URL`.
4. Open the sandbox test seller account from the dashboard (keep it open in the same browser), then log in as
   `us@example.com` → Company settings → Connect Square → Allow.
5. Open an invoice → Pay online → Get payment link → open the link and pay with Square's sandbox test card
   (e.g. 4111 1111 1111 1111, any future date, any CVV). The payment appears on the invoice within seconds.

Decisions made without asking (change if needed):

- One "regional format" setting (BCP 47 locale) covers date, time and number format instead of separate date/number
  settings. The UI stays English; the locale only changes formats.
- The country of a property/brand address is a 2-letter code field; labels follow the company's country.
- Postal codes are validated only for countries with a known pattern (US, CA, UK, AU, NZ, DE, FR, ES, IT, MX); others
  accept anything. Phones must be a plausible length for the country (lenient check, no real-range check).
- Phones are shown as stored (E.164) for now; pretty national formatting on screen can come later.
- Test data (factories) stays Canadian by default; `->inUnitedStates()` exists for US tests.
- Compound taxes always apply after normal taxes (ordering by type, then sort order).
- Starting services have no prices (each company sets its own in its currency); picking services on estimate/invoice
  lines comes with the price book task.
- The vertical is chosen at creation (super-admin form; there is no self-signup yet) and is not editable in settings.
- Square: payments go to the main location of the Square account; the link amount is the balance at that moment; tips
  added in Square checkout are not added to the invoice (the invoice records the base amount); refunds are done in
  Square (refund webhooks are not handled yet). A Square account in another currency than the invoice cannot make links.
- Webhook payment for a voided invoice is not recorded (logged), the money stays visible in Square.
- Platform subscription billing (§11) is still not built; only SPEC was updated.

Deferred on purpose: Stripe (next provider for non-Square countries), Square Point of Sale hand-off for card-present
payments, refund webhooks, sending invoices/estimates by SMS/email with the link, self-signup with country/vertical.

## Stage 2 — ⏳ Not started

## Stage 3 — ⏳ Not started

## Next

Stage 1 — Twilio SMS (automated messages + inbox per brand; A2P 10DLC registration for US numbers) and sending
estimates/invoices by SMS/email with a link and PDF (online approval of estimates, Square link in the invoice message).
Then price book picker on lines, review request toggle, basic reports, Google Places. Stripe as the second payment
provider for countries without Square.
