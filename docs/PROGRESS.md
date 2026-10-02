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

### Bolt technician UI — draft, validation pending

- My Jobs: rounded cards, larger touch targets, active-tab accessibility, wrapping time/status rows,
  and full-width contact actions when only one contact method is available.
- Job Detail: centered mobile layout, larger customer summary, rounded action buttons, and safe-area spacing
  for the fixed visit action bar.
- These changes are in the `chatgpt/bolt-ui` draft branch. They are not released or marked complete.
- Validation blocked locally: PHP is unavailable and the sandbox cannot connect to the configured proxy
  to install npm dependencies. Build, formatting, TypeScript and backend tests must pass in GitHub Actions.
- Manual validation still required: narrow mobile screens, long names/addresses, every visit state,
  iPhone home-indicator spacing, navigation/call links, dark mode and keyboard focus.
- Next functional work: finish price-book categories and basic revenue/conversion reports, then the remaining
  stages in SPEC.md. Stage 2, Stage 3 and platform subscription billing are not complete.

| #   | Task                                                                         | Status    |
| --- | ---------------------------------------------------------------------------- | --------- |
| 1   | Customers, properties (manual address), appliances (§6, §7.1)                | ✅ Done   |
| 2   | Jobs & statuses, visits, My jobs (§6, §7.3 w/o calendar, §7.4)               | ✅ Done   |
| 3   | Calendar & dispatch                                                          | ✅ Done   |
| 4   | Technician PWA view, photos, signatures                                      | ✅ Done   |
| 5   | Estimates, invoices, manual payments (§7.5, §7.6)                            | ✅ Done   |
| 6   | International groundwork, payment terms, Square payments (§1.1, §1.2, §7.6)  | ✅ Done   |
| 7   | PDF + email sending of documents, price book on lines, Square tips/refunds   | ✅ Done   |
| 8   | SMS (3 modes, Twilio, A2P 10DLC, STOP, quiet hours) + Google review requests | ✅ Done   |
| 9   | Online estimate approval (signature, options, expiry, deposit) + Places      | ✅ Done   |
| 10A | Estimate revisions, deleting jobs, outcomes, visit types, strict arrival     | ✅ Done   |
| 10B | Warranty & callbacks, refunds, costs & profit, no charge, cash               | ✅ Done   |
| —   | Price book: categories (parts/materials with cost and markup done in 10B)    | 🚧 Partly |
| —   | Basic reports (profit, callbacks, no charge done in 10B; revenue/conversion) | 🚧 Partly |

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
3. Webhooks → add subscription (sandbox) for `payment.created`, `payment.updated`, `refund.created`, `refund.updated`,
   `oauth.authorization.revoked` with
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

### Task 7 — Sending documents, phones on screen, Square tips and refunds, price book on lines ✅

**PDF of estimates and invoices** (dompdf, `resources/views/pdf/document.blade.php`), branded with the document's
brand: logo (embedded), color, name, address, phone, email, website, tax/business numbers, customer and service
address, lines, discount, taxes (or "includes tax"), total, paid and balance, notes, the brand's **terms** and **footer**,
and for an unpaid invoice the pay-online link. Money, dates and phones in the company's currency and regional format;
Letter paper in North America, A4 elsewhere. Staff: "PDF" button on the document page (`/invoices/{id}/pdf`,
`/estimates/{id}/pdf`, same access as viewing the document).

**Sending by email**: "Send by email" on the document page opens a dialog with the customer's primary email and an
editable message (different text for estimates, unpaid and paid invoices). The email (queued, `DocumentMail`) has the
message, a button to the **online page** and the PDF attached; it goes out from `MAIL_FROM_ADDRESS` with the brand's
sender name, replies go to the brand's sender email (or brand email). The document shows when and to whom it was sent
and when the customer first opened it. Sending is audited. A void invoice cannot be sent.

**Online page** `/d/{token}` (no login): the branded document, Download PDF and, for an invoice with a balance and a
connected provider, **"Pay $X online"** — creates (or reuses) the Square link for the balance and sends the customer
to Square checkout. The token is 48 random characters (first letter says estimate or invoice), created on first send
or PDF; routes are rate-limited; documents of suspended companies or deleted documents are not shown.

**Phones on screen** in the national format of the company's country (libphonenumber-js; `+1…` → `(604) 555-0142`,
other countries in international format); edit forms start with the national format too. Stored as E.164, `tel:` and
`sms:` links use E.164. PDFs and emails use the same rule on the server (`PhoneNumber::display`).

**Square tips**: the tip is stored on the payment (`payments.tip_amount`), the amount applied to the invoice is the
payment without the tip, so invoice revenue stays the invoice total while amount + tip per payment equals what Square
pays out. Company setting "Let customers add a tip when paying online" turns on tipping in the Square checkout
(off by default). Tips are shown on the payment line.

**Square refunds**: `refund.created` / `refund.updated` webhooks with status COMPLETED add a refund row (negative
amount, linked to the refunded payment, idempotent on the Square refund ID). The invoice's paid amount, balance and
status follow: a partial refund → partially paid with the refunded amount due again; everything refunded → new status
**Refunded**. The refund is applied to the invoice up to the payment's amount; anything beyond refunds the tip. A fully
refunded invoice can be voided (manual payments must still be voided first, as before).

**Price book on lines**: every estimate/invoice line has "Pick from price book…" — fills the description (name — description),
price and taxable flag from Company → Services. Only active services; a service without a price fills the text only;
a price is not copied onto a document in another currency than the company's.

Decisions made without asking (change if needed):

- Emails are sent from the platform address (`MAIL_FROM_ADDRESS`) with the brand's name and the brand's reply-to,
  not from the brand's own address: sending as the brand's domain needs SPF/DKIM set up per brand (later, with the
  email provider choice).
- The online page is "view + pay + PDF". Online approval and signature of estimates is not built yet (SPEC §7.5),
  nor reminders or "viewed" notifications; estimate status does not change when it is sent.
- A refunded invoice is not counted as outstanding (it is not money owed until the office decides); a partially
  refunded one is (its balance is due again). Square processing fees are not recorded.
- The PDF is rendered on demand (not stored). The email attaches a fresh PDF when the queue sends it.
- Numbers with the company's calling code are shown in national format (a US number in a Canadian company:
  "(512) 555-0142"); other countries in international format.
- The online page uses the company's regional format; its UI text is English like the rest of the app.

Deferred on purpose: SMS sending and Google review request (next task), online estimate approval/signature, payment
reminders (Stage 2), Stripe.

### Task 8 — SMS and Google review requests ✅

**SMS mode** (Company → Messaging, Owner): _Automatic_ / _From technician's phone_ (default for new and existing
companies) / _Off_.

- **Automatic**: `App\Sms\SmsProvider` interface, Twilio first (`config/sms.php`, keys `TWILIO_*` in `.env`).
  "Get an SMS number" creates the company's **Twilio subaccount** under the platform account and buys a **local number**
  in the company's country (incoming webhook set on the number). Subaccount token encrypted.
  Texts: day-before **visit reminder** (hourly command at 17:00 company time, `SMS_REMINDER_HOUR`), **On my way** with
  the arrival window when the technician taps the button, **estimate/invoice link** ("Send by SMS" next to "Send by
  email"), **review request**, free text from the job ("Send SMS" dialog).
- **US A2P 10DLC**: US companies get a business-details form (legal name, type, EIN, address, contact, use case,
  sample message) with Save / Submit and the status (Not submitted → Submitted → Approved / Rejected + reason). Until
  approved, texts to US numbers are not sent: the message is recorded as "Not sent" with the reason, the job page says
  why, and automated messages go by email instead when there is an address. Canadian and other numbers: no restriction.
- **STOP/START/HELP**: incoming STOP (and STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT…) marks the number opted out — no
  more texts, red "Unsubscribed from texts" badge on the customer card; START/UNSTOP/YES opts back in; Twilio answers
  STOP/HELP itself (Advanced Opt-Out). The opt-out is checked again right before sending.
- **Quiet hours** (company setting, default 21:00–08:00, company time zone): a text due at night is scheduled for the
  morning (`messages:deliver-due` every minute also picks up anything delayed).
- **History**: every SMS, email, text opened on a phone and every customer reply is a `messages` row on the customer
  card and the job page (kind, channel, status: scheduled / sent / delivered / failed / not sent + reason / received /
  opened). Delivery status comes back from Twilio (`/webhooks/sms/twilio/status`); incoming texts via
  `/webhooks/sms/twilio`, both checked with the Twilio signature (subaccount token). Replies are linked to the
  customer's latest job.
- **From technician's phone**: "Send SMS", "On my way", "Send by SMS" (documents) and "Send review request" open the
  phone's messages app with the number and text ready (`sms:+1…?body=` on Android, `sms:+1…&body=` on iOS) and record
  "SMS opened from technician's phone" on the job. Reminders and review requests go by email.
- **Off**: everything that would be a text goes by email ("Send by SMS" is hidden).
- **Templates** per company (Messaging page) for every message, with placeholders; empty = English default.

**Google review requests**: Company → Google reviews (office) — profiles with label, review link (https), optional
brand; each brand picks its default profile on the brand form. Every job has "Ask for a review" (default from the
company setting, switch on the job page). When the job becomes **paid in full**, a request is scheduled after the
delay (default 2 h) and sent by SMS (Automatic) or email; in technician's phone mode the job also has "Send review
request". At most one request per customer in the cooldown (default 180 days); skipped requests show why on the job.
Same text for everyone; no incentives, no "happy?" gating — written in SPEC §8 and shown next to the settings.

Super-admin: company page shows SMS mode/number and the A2P details; the platform registers the brand and campaign in
the Twilio console and records the IDs and status there; `sms:sync-registrations` (daily) then follows the campaign /
brand status at Twilio (VERIFIED → approved, FAILED → rejected).

How to test with Twilio (no Twilio keys were available in this environment; automated tests use a faked Twilio API
with Twilio's request/response and webhook shapes, including the X-Twilio-Signature check):

1. Twilio test credentials (Console → Account → API keys & tokens → Test credentials) work for sending to the magic
   test numbers but cannot create subaccounts or buy numbers — use the live account in a trial/sandbox setup for a full
   test: put the master `TWILIO_ACCOUNT_SID` / `TWILIO_AUTH_TOKEN` in `.env`.
2. Log in as the Owner of a Canadian company → Messaging → mode Automatic → Get an SMS number. The app must be reachable
   from the internet (ngrok locally) so Twilio can call `/webhooks/sms/twilio`.
3. On a job: Send SMS, On my way; reply from your phone — the reply appears on the job/customer; reply STOP — the badge
   appears and further texts show "Not sent".
4. `php artisan messages:send-visit-reminders --force` sends tomorrow's reminders immediately.

Decisions made without asking (change if needed):

- **One SMS number per company** (not per brand as SPEC §7.7 said before); texts are signed with the job's brand name.
- **A2P registration is done by the platform**: the company submits its details in the app; the platform registers
  brand + campaign in Twilio (Trust Hub) and records the IDs; the app then tracks the status automatically. Fully
  automated Trust Hub submission through the API can come later.
- The US rule is applied by the **destination** number (US numbers need the approved registration), whatever the
  company's country.
- When an automatic text cannot go (no number, STOP, registration, no SMS number) and the customer has an email, the
  message goes by email instead and both records are kept. A text typed by staff ("Send SMS", "Send by SMS") is not
  turned into an email: staff see the reason.
- Quiet hours apply to texts only (emails go any time) and to every text, including ones staff send at night.
- Texts use plain spaces and "-" in times so they stay in the GSM-7 alphabet (cheaper, fewer segments).
- The review request is triggered when the **job** becomes paid (all its invoices paid), not when a single
  deposit/diagnosis invoice is paid while the job is still open.
- "On my way" in Off mode is emailed (the rule "everything that would be an SMS goes by email").
- The `sms:` link is opened right after the tap; the server record is posted in the background.
- No shared SMS inbox page yet (replies are on the customer and job timelines); no notifications to staff on replies.

Deferred on purpose: shared inbox and reply notifications, "parts arrived" / "payment received" texts and payment
reminders (Stage 2), click-to-call (Twilio voice), per-brand numbers, automated Trust Hub submission, link-click
tracking for review requests.

### Task 9 — Online estimate approval and Google Places addresses ✅

**Online approval on the estimate's page** `/d/{token}` (no login):

- **Approve** / **Decline** buttons (big, one-handed). Decline asks for an optional reason.
- **Signature** on approval: draw with a finger (PNG, max 512 KB, kept on the private disk) or type the name. Stored:
  signer name, signature type and image, date/time, IP address and browser (user agent). Audited
  (`estimate.approved_online` / `estimate.declined_online`).
- **Optional lines** (`estimate_items.optional`/`selected`): the office marks a line "Optional" (and may tick "Included"
  for an on-site agreement). On the page the customer ticks the options; subtotal, discount, taxes, total and deposit are
  recalculated live (browser mirrors `DocumentTotals`) and again on the server on approval. Lines not picked stay on the
  estimate as "Optional · not included" and are left out of the invoice.
- **Expiry**: after "Valid until" (company time zone) Approve is gone and the page says the estimate expired; the server
  refuses a late approval too. New company setting "Estimates valid for (days)" (default 30, empty = no expiry) fills in
  "Valid until" on new estimates.
- **Deposit** (optional, per estimate): % of the total or a fixed amount (never more than the total). On approval the
  customer goes straight to the connected provider's checkout (Square link for the deposit, no tips); "Pay deposit"
  stays on the page until paid. The deposit is a payment on the **estimate** (`payments.estimate_id`, no invoice yet;
  webhook idempotent, refunds handled); converting the estimate moves it onto the invoice (partially paid); voiding that
  invoice gives it back to the estimate. A paid deposit blocks deleting the estimate. Without a provider the deposit is
  shown with "we will contact you about payment".
- **After approval**: status Approved; the office (active Owners/Admins with access to the brand) gets an email; in SMS
  mode Automatic they also get a text from the company number (to the phone in their profile, after quiet hours, US
  10DLC rule applies). The estimate page has **Convert to invoice** (one tap, no confirm) and **Schedule visit**.
- **PDF** of an approved estimate: "Approved by the customer" box with the signature (or typed name), name, date and
  time, IP; optional lines marked; deposit and deposit paid.

**Google Places** on customer address forms (property dialog, new customer, new customer in the job form):

- Suggestions while typing the street address (Places API (New) through the Maps JavaScript API, session tokens),
  limited to the property's country (the company's country by default). Picking fills street, unit, city,
  state/province, postal code, country and keeps `google_place_id`, latitude, longitude. Typing over the street, city,
  region, postal code or country clears the place ID and coordinates.
- Key only from `.env` (`GOOGLE_MAPS_BROWSER_KEY`, shared with the page only to logged-in company users). No key, or
  Google unreachable → the field is a normal input (manual entry as before).

How to test manually:

1. Create an estimate on a job, mark one line Optional, set Deposit 25%, save, "Send by email" (or copy the online link).
2. Open the link on a phone: tick the option (total changes), Approve → sign with a finger → Approve and sign. With
   Square connected you land on the Square checkout (sandbox card 4111 1111 1111 1111); without it the page shows the
   approval and the deposit due. The office email arrives (`MAIL_MAILER=log` → `storage/logs`).
3. On the estimate page: signature box, deposit paid, "Convert to invoice" → the invoice is partially paid by the deposit.
4. Download the PDF: signature and approval date are in it.
5. Set "Valid until" to yesterday → the page shows "expired" and has no Approve button.
6. Places: put a browser key in `GOOGLE_MAPS_BROWSER_KEY` (Maps JavaScript API + Places API (New) enabled, restricted
   to your domain), open Customers → New → type an address and pick a suggestion.

Decisions made without asking (change if needed):

- **Estimates always belong to a job**, so "Convert to job/invoice" = one-tap "Convert to invoice" plus a "Schedule
  visit" shortcut to the job (the job already exists).
- **The deposit is a payment on the estimate**, not a separate deposit invoice: no double billing, taxes stay correct,
  and it moves to the invoice made from the estimate.
- Approval comes first, then the deposit payment: an unpaid deposit does not undo the approval (the office sees
  "deposit paid" or not). The checkout is opened right after signing.
- Optional lines start **not included**; the customer adds them. The office can pre-tick "Included".
- An estimate **signed online is locked**: no editing, no staff approve/decline (the signature is for those lines and that
  total); it can still be converted. Staff-recorded (on-site) approvals stay editable as before.
- A declined estimate can still be approved online later (customers change their minds); an approved one cannot be
  declined online (call the office).
- Office texts go only for approvals; declines are email only. Office = Owners and Admins (technicians are not told).
- Staff texts are not stored in the customer message history (they are not customer messages); failures are logged.
- The signature is embedded in the page/PDF as a data URI (small PNG) instead of a separate route.
- Places: no geocoding of addresses typed by hand (coordinates only from a picked suggestion); no server-side key.

Deferred on purpose: estimate follow-up reminders and "viewed" notifications, Good/Better/Best option groups, a map
of the day from the stored coordinates, server-side geocoding of old addresses, Stripe.

### Task 10A — Estimate revisions, deleting jobs, job outcomes, visit types, strict arrival ✅

**Revise a signed estimate.** An estimate the customer signed online is locked; "Revise" makes a new version
(`EST-1001-R2`, then `-R3` …) as a draft copy with its own number and online link. The signed version becomes
**Revised** (read-only, kept in the history with its signature; cannot be sent or converted). The page lists all
versions. A deposit already paid moves to the new version; open deposit links are cancelled. The new version shows "Send
the new link to the customer"; once it is sent, the old link says "replaced by a newer version" with a link to it.

**Deleting jobs** (soft delete): Owners/Admins always; technicians only on their own jobs and only with the company
setting "Allow technicians to delete jobs" (off by default). An Owner going on calls deletes as the Owner. A job with an
invoice (even void) or a payment (incl. an estimate deposit) cannot be deleted — cancel it or close it instead. Who and
when is stored (`deleted_by`, `deleted_at`) and audited. Jobs → "Deleted jobs" lists the last 30 days with Restore
(office); restore is audited.

**Closing outcomes** (`service_jobs.outcome`, reason, comment, closed at/by): Repaired / Customer declined repair /
Unable to repair (reason required, from a list) and Cancelled (reason required). On site: Finish → Completed, Waiting for
parts, Customer declined repair, Unable to repair. From the job page: "Close job" (when no visit is under way). Declined /
unable can go straight to "Invoice the diagnosis / service call only": the invoice form opens with one line from the
price book service picked in Company settings ("Diagnostic fee"), or an empty-priced "Diagnostic / service call" line.
**Cancel** only before any work: refused once a visit was started, needs a reason. Scheduling a new visit or reopening
by hand clears the outcome. Reasons per outcome are editable in Company settings → Jobs (one per line; empty = defaults).

**Visit types** on the job form: New diagnosis / Known problem / Return visit (parts) / Callback (warranty). Return visit
and callback must link an earlier job of the same customer (its appliances are preselected). Return visits have a
"Bring with you" list (part/material + qty), shown on the job page and in My jobs ("2 of 5 loaded"), ticked by the
technician. The earlier job lists its follow-ups.

**Strict arrival time** (per visit, in the job form and visit dialog): red "Strict time" mark on the calendar (day and
week), job lists, My jobs and the job page (red banner for the technician). `visits:strict-arrival-reminders` (every 5
min) reminds the assigned people once, N minutes before (company setting, default 60): email, plus SMS in Automatic mode.

**Job list filters**: visit type, outcome (incl. "No outcome yet"), "Strict arrival only".

How to test manually:

1. Estimate → send → sign on the online page → back on the estimate press "Revise" → edit → send; open the old link.
2. Delete a job without invoices → Jobs → Deleted jobs → Restore. Try deleting a job with an invoice (refused).
   Company settings → Jobs → "Allow technicians to delete jobs" → log in as tech@example.com.
3. As tech: start a visit → Finish → Customer declined repair → reason → keep "Invoice the diagnosis…" → the invoice form
   opens with the diagnostic line. Set "Diagnostic fee" in Company settings first to get the price.
4. Change status → Cancelled needs a reason; after a visit was started it is refused.
5. New job → Return visit → pick the earlier job → add "Bring with you" items → schedule with "Strict arrival time" →
   check calendar, My jobs, job page. `php artisan visits:strict-arrival-reminders` sends the reminder (window ≤ 60 min).
6. Jobs list → filters by visit type, outcome, strict.

Decisions made without asking (change if needed):

- Revisions are separate estimate rows linked by `revision_root_id`; numbers get `-R2`, `-R3`. The new link goes to the
  customer when the office sends the revised version (it is a draft first, so it is not sent automatically); the old link
  points to the new one only after that.
- Deleted jobs are kept after 30 days (no hard delete): they just cannot be restored any more. Financial records and,
  from 10B, supplier receipts must stay anyway.
- Only the office restores deleted jobs.
- Outcome is stored on the job (not on the visit); "Repaired" is recorded when a visit is finished as completed.
- "Cancelled" is an outcome with its own reasons; cancelling via the status dialog requires a reason.
- Visit type is set on the job (first visit); later visits of the same job keep it.
- Staff reminders ignore the customers' quiet hours (they are work alerts).

### Task 10B — Warranty and callbacks, refunds, costs and profit, no charge, cash ✅

**Lines: service / part / material** on estimates and invoices (typed freely; the price book is optional).
Part: name, part number, supplier, cost, price. Material: the same + quantity and unit (pcs, ft, m, lb, oz or a custom
word), cost and price per unit. The price is filled from the cost by the company's **markup scale** (Company settings →
Costs and payments; separate tiers for parts and materials: "cost up to → multiplier"), and can always be changed.
Typing a part number or name shows the company's **earlier costs** (date, cost, supplier, part number — a price rise is
visible). "Save to price book" stores the line as a price book item. **Supplier tax paid** per company tax rate; each tax
rate has "Recoverable when paid to suppliers" (e.g. GST yes, BC PST no): non-recoverable tax is part of the cost.
**Bill to customer** off = internal line: cost only, never in the total, the PDF, the online page or the email. Cost,
internal flag and warranty work on estimates too and carry over on Convert to invoice and Revise; customers never see
costs. Who sees costs and profit: Owners/Admins; technicians only with "Technicians see costs and profit" (off by
default; without it their edits keep the costs already entered).

**Warranty per line**: length in days / weeks / months, 0 = no warranty. Default: the line's own value → the price book
item's warranty (new field, e.g. "Drain unclogging 14 days") → company settings (labour and materials; parts; parts
priced above a threshold, e.g. 3 months manufacturer warranty). End dates are stored per line (from the day the job is
closed, the invoice date before that). Closing a repaired job opens the **warranty summary** (change per line, "Apply
to all lines"). Shown per line on the invoice page, PDF, online page and in the appliance's repair history; the
company's **warranty terms** text is printed with them.

**Warranty callbacks**: always linked to the original job (10A). The job form shows which warranties of the original job
still run on the visit day, line by line. The callback's first invoice starts with the original lines: free while under
warranty, the original price otherwise (editable). Outcomes: **Fixed under warranty** / Customer declined repair /
Unable to repair. For the last two, the close dialog offers a **refund on the original job** (none / full / partial,
reason required) through the refund module; the original job's invoice shows **Refunded / Partially refunded** and the
job links its callbacks.

**Refunds** (invoice page → Refund, office): money given back **as settled** — the customer does not owe it again
(`invoices.credited_amount`). Spread over the payments, newest first: Square payments are refunded at Square
(`/v2/refunds`, recorded at once; the later webhook is recognised), manual payments get a refund row with the reason.
New invoice status **Partially refunded**. Square's processing fee is stored on each payment from the webhook.

**Costs and profit**: job page → "Costs & profit" (people who see costs): revenue without tax (less settled refunds) −
cost of every line and of the job's **cost lines that are on no invoice** (part used on a declined/no-charge job,
consumables) − processor fees = profit and margin %. **Supplier receipts** (photo/PDF) are attached to a job and can be
linked to other jobs by number; strictly internal; kept when a job is deleted; only the uploader can remove a wrong
file within an hour.

**No charge**: closing outcome for jobs with no invoice or invoices totalling zero; reason (Goodwill / Could not
diagnose / Other, editable list) + comment; costs stay, so the profit shows the loss.

**Reports** (office, jobs closed in a period): profit and margin by technician and appliance type; callback rate by
technician, brand and appliance type; no-charge jobs (count and loss) by technician; **Expenses CSV** and **supplier
receipts ZIP** for the bookkeeper.

**Cash**: a technician records a cash payment on the invoice (amount, date, optional receipt photo). **Cash on hand**
per person (office page; the technician sees their own on My jobs). The office records a **cash deposit** (handed to the
office: person, date, amount). Every movement is in a journal; nothing is deleted — a wrong entry is reversed with a
reason; voiding a cash payment reverses it automatically. **"Accept cash payments"** (Company settings, on by default):
off = cash is not offered and refused on the server, so technicians cannot take cash.

**Deployment docs** (`docs/DEPLOYMENT.md` §5–7): Redis is optional (queue, cache and sessions on PostgreSQL; how to
switch to Redis later), the Google Maps key (APIs, referrer restriction to app.doctor-appliance.ca), the full `.env`
list. `.env.example` now defaults to the database queue and cache; the supervisor worker uses `QUEUE_CONNECTION`.

How to test manually:

1. Company settings → Warranty (labour 30 days, parts 90 days, above $300: 3 months, terms) and Costs (markup tiers,
   technicians see costs, accept cash). Taxes → PST: untick "Recoverable".
2. Job → New invoice → Part: part number, supplier, cost 40 → price fills from the markup; Cost & warranty → supplier
   tax, warranty; add a Material in ft; add an internal line (Bill to customer off). Save → PDF / online page: no internal
   line, warranties with end dates and terms.
3. Type the same part number on another invoice → "Earlier costs". "Save to price book" → Company → Services.
4. Close the job (repaired) → warranty summary → "Apply to all lines".
5. Job page → Costs & profit: add a cost line, upload a receipt, link it to another job number.
6. New job → Callback (warranty) → pick the original job → the warranty list; schedule; New invoice → original lines
   free/charged. Close → Customer declined repair → partial refund + reason → original invoice "Partially refunded".
7. Invoice → Refund (office) on a cash-paid invoice.
8. Close a job without an invoice as No charge → Reports: no-charge loss, profit by technician, callback rate;
   download Expenses CSV and Receipts ZIP.
9. As tech: take a cash payment with a photo → My jobs shows cash on hand → office: Cash on hand → Record cash deposit →
   reverse it with a reason. Turn "Accept cash payments" off → cash is gone from the payment dialog.

Decisions made without asking (change if needed):

- One line model for all kinds (estimate/invoice items got kind, cost, supplier, unit, supplier taxes, internal flag,
  warranty); job-level cost lines exist only for costs that belong to no invoice.
- Revenue for profit = invoice total minus its taxes, reduced in proportion by refunds given as settled; costs of
  estimate lines count only once invoiced.
- Warranty runs from the day the job was closed (company time zone); "months" use calendar months.
- Refunds from this module are "settled" (credited); refunds made directly in the Square dashboard still come back by
  webhook and reopen the balance as before. Cash refunds are recorded as refund rows; handing the cash back is not
  taken off anyone's cash on hand automatically.
- Square refunds are recorded when Square accepts them (PENDING); a later failure at Square is not reverted
  automatically (rare; check Square).
- Technician of a job in reports = first person on its last started visit; callback rate = callbacks / jobs closed in
  the period that were not callbacks or cancelled.
- No charge is refused if a non-void invoice has a total above zero.
- Cash deposits and reversals are office-only; balances are per currency.
- Supplier receipts can't be deleted after an hour (kept for the bookkeeper, 6+ years).
- Markup tiers are stored in major units of the company currency; prices from markup are suggestions only.

Ideas for later: **stock / inventory of materials** (van stock, reorder levels, consumption per job) — out of scope
now; automatic supplier price import; categories in the price book; cash refunds tied to cash on hand.

## Stage 2 — ⏳ Not started

## Stage 3 — ⏳ Not started

## Next

Stage 1 — remaining reports (revenue by brand/job type/lead source, average ticket, estimate conversion), price book
categories, estimate follow-up reminders, map of the day from property coordinates. Stripe as the second payment
provider. Then Stage 2 (parts orders, warranty claims, online booking, payment reminders, shared SMS inbox).
