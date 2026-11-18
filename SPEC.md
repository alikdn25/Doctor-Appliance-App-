# Field Service App for Appliance Repair — Technical Specification

Version 0.2 · October 2026 · Owner: Alex (Doctor Appliance, Greater Vancouver, BC)

## 1. Goal

A field-service management app (Housecall Pro–style) built specifically for appliance repair companies, with
handyman as a second vertical.

- **Phase A:** used by the owner's own companies (Doctor Appliance, Duct Works).
- **Phase B:** sold as a monthly subscription to other appliance repair (and handyman) companies.

Therefore the system is **multi-tenant from day one**: every company is an isolated account, and every company can run several brands.

### 1.1 Markets and localization

The product is **international**. First markets: **USA and Canada**; later the whole world. Nothing in the code
assumes a particular country.

- **Country** is chosen when a company is created. It sets the defaults below; each of them can be changed.
- **Currency** per company (ISO 4217 code). Every amount is stored together with its currency (documents and payments
  keep the currency they were created in). Amounts are stored in the currency's minor units. The currency symbol and
  number format follow the company's currency and regional format; no "$" is hard-coded anywhere (discounts included).
- **Taxes** are configured by each company, with no fixed count limit: several named rates (e.g. GST + PST, state + county sales tax, VAT),
  **compound** taxes (charged on top of the previous taxes), and prices entered **with or without tax** (tax-inclusive
  pricing, as common in the UK/EU/Australia). Nothing like GST/PST is hard-coded.
- **Time zone** choices display UTC offsets, familiar cities and countries with search; IANA IDs remain stored to apply daylight saving rules. **Regional format** (date, time and number format, e.g. en-US, en-CA, en-GB) and **address format**
  (labels and order of state/province/county and ZIP/postal code) are company settings derived from the country.
- **Phone numbers** are stored in **E.164** (+15551234567). Numbers typed without a country code are read as numbers
  of the company's country.
- **All UI strings** live in translation files; English is the default (and the only language in v1). The spelling
  follows the company's regional format: American for en-US (Labor, Check), Commonwealth elsewhere (Labour, Cheque).

### 1.2 Verticals

A company picks its **vertical** when it is created. The vertical sets its job types and the
starting set of services (price book items) created with the company.

- **Appliance repair** — the main vertical (appliances, rating plates, manufacturer warranty, vent cleaning).
- **Handyman** — the second vertical: repairs, installation, assembly, mounting, maintenance, inspection.

**Out of scope** (for any vertical): full construction and renovation projects — multi-phase projects, change orders,
progress (staged) billing, subcontractor management for projects. The product stays a service-call app.

## 2. Tech stack

- **Backend:** Laravel (latest stable), PHP 8.3+
- **Frontend:** React + TypeScript via Inertia.js, Tailwind CSS
- **Database:** PostgreSQL
- **Queues/cache:** Redis (SMS, emails, PDFs, reminders run as queued jobs)
- **Mobile:** Progressive Web App (installable on Android/iOS home screen, camera, geolocation). No native app in v1.
- **File storage:** S3-compatible object storage (photos, PDFs, logos)
- **Hosting:** Linux VPS (Contabo), Nginx, Let's Encrypt SSL
- **Tests:** Pest/PHPUnit for backend; every feature ships with tests

## 3. Multi-tenancy model

- Single database, every tenant-owned table has `company_id`.
- Tenant scoping is enforced globally (Eloquent global scope + policy checks). A user must never see another company's data. This is covered by automated tests.
- Hierarchy: **Platform → Company (tenant) → Brands → Users / Customers / Jobs**.
- **Super-admin panel** (platform owner only): list of companies, status, plan, usage, ability to impersonate for support (logged).
- Company-level settings: country, vertical, timezone, currency, regional format, tax rates (named, compound, prices
  with/without tax), default payment terms, invoice numbering, business hours (see §1.1).
- Subscription billing for tenants (companies paying for the app) is specified in §11 and built as a separate task before the Phase B launch. The data model already includes `plan` and `subscription_status` on Company.

## 4. Brands

A company can have multiple brands (e.g. Doctor Appliance and Duct Works; a friend with 3 repair companies).

Each brand has:

- Name, logo, colors, website, address(es)
- Its own phone number for SMS (Telnyx; Twilio supported) and email sender identity
- Its own invoice/estimate template, footer, terms, tax/business registration numbers (e.g. GST/HST number, VAT
  number, EIN)
- Its own **Google review profiles** (see §8)
- Its own public online booking page

Every customer-facing document and message uses the brand of the job.

## 5. Users and roles

| Role          | Access                                                                                                                     |
| ------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Owner         | Everything in the company, billing, settings                                                                               |
| Office/Admin  | Customers, jobs, scheduling, estimates, invoices, messaging, reports                                                       |
| Technician    | Own assigned jobs, customers/appliances on those jobs, create estimates/invoices on site, take payments                    |
| Subcontractor | Only jobs explicitly passed to them; no prices/margins unless allowed; their payout share is recorded                      |
| Collector     | Unpaid invoices list, customer contact info for those invoices, messaging and calling from the brand number. Nothing else. |

Permissions are configurable per role later; v1 uses fixed roles above.

## 6. Core data model (summary)

- **Customer:** type (residential / commercial / property manager / strata or HOA), name, phones (E.164), emails, "About the customer" (persistent team notes about preferences and contact/arrival expectations, shown in booking and assigned jobs), optional name-suggested icon with a saved manual override,
  tags, lead source, payment terms (empty = the company default; see §7.6). The customer is the one who pays and has a
  **billing address** (empty = the primary property's address).
- **Property:** address in the format of its country (Google Places autocomplete, geocoded), access notes, gate/buzzer code. A customer can have many properties. A strata building can have many **units**.
  The first property is primary; an empty address is never saved. A property may have an **occupant** (name, phone),
  e.g. a tenant when a landlord or property manager pays; the occupant stays with the address for later bookings.
- **Appliance** (appliance repair vertical): property, type (washer, dryer, fridge, range, dishwasher, etc.), brand, model number, serial number, photo of the rating plate, install/purchase date, warranty info, full repair history.
- **Job:** brand, customer, property, appliance(s), job type (per vertical — appliance repair: repair / warranty /
  maintenance / inspection; handyman: repair / installation / assembly / mounting /
  maintenance / inspection), source, assigned user(s), scheduled window, estimated duration, status, notes, photos, signatures. **A job is one booking**: the interface shows no separate visits. A second trip (diagnosis → parts → repair) is a new time for the same job; the times are stored as visits internally (calendar, On my way / Start / Finish, time on job) and earlier times stay in the job history.
- **Estimate**, **Invoice**, **Payment**, **Price book item**, **Parts order**, **Warranty claim**, **Service plan**, **Inspection report**, **Message**, **Attachment**, **Activity log**.

### Job statuses

`new` → `scheduled` → `on_the_way` → `in_progress` → `need_to_order_parts` → `waiting_for_parts` → `completed` → `invoiced` → `paid`, plus `waiting_for_customer`, `cancelled` and `on_hold`. Every status change is logged with user and time.

**Need to order parts** (`need_to_order_parts`): the technician pressed "Part needed" when finishing a visit; the office
orders the part and presses **Parts ordered** on the job page, which moves the job to **Waiting for parts**. Both are
groups of the unfinished-jobs bar.

A booking saved with **Save as draft** has the status **Draft** (`booking_draft`): no confirmation has gone to the
customer yet. Drafts are orange with a "Draft" chip in My Jobs, the calendar and the Jobs list, are counted as
"Drafts" in the unfinished-jobs bar, and the job page shows an orange "Booking not finished" strip with **Finish
booking** (→ Scheduled, and the confirmation goes by itself). Draft is never picked from the status list.

All unfinished jobs remain in a date-independent queue, reached from a compact persistent top bar with a job
counter. It includes overdue visits, work needing scheduling, parts to order, waiting for parts/customer, on-hold work and future
scheduled work. Completed, invoiced, paid, cancelled, outcome-closed and deleted jobs are excluded. Reopened jobs
return. Each job counts once; access follows company, brand and technician assignment permissions.

## 7. Feature list

### 7.1 Customers (CRM)

- Customer card with all properties, appliances, jobs, estimates, invoices, payments, messages.
- Search by name, phone, address, model/serial number.
- Duplicate detection on phone/email.

### 7.2 Lead intake and online booking

- Public booking page per brand + embeddable widget for websites: customer picks service, appliance type, describes problem, chooses time window.
- Manual job creation from a phone call in under 1 minute (phone lookup auto-fills existing customer).
- Lead source tracking (Google profile, website, referral, manufacturer, property manager, etc.).
- Simple lead pipeline: new → contacted → estimate sent → won/lost.

### 7.3 Scheduling and dispatch

- Calendar (day / week), drag-and-drop, per-technician lanes.
- Arrival windows, durations, travel buffer.
- Map view of the day's jobs. A **Map** tab in the bottom bar for every role and menu: the chosen day's jobs (today by
  default) as pins numbered in the day's order and coloured by status, a technician filter for the office (a
  technician sees only their own jobs). A pin opens a card: customer, address, arrival window, technician, status,
  Navigate, Call, Open job; the office moves the job to another technician from it. **Route** draws the day in its
  order with the driving time and distance; **Optimize** offers a better order that keeps the arrival windows and says
  how much time it saves; **Apply** saves that order (the calendar and My Jobs follow it); **Open in Google Maps** sends
  the day's route to Google Maps.
- Completed, invoiced and paid jobs are greyed out in every list and hidden from the calendar by default
  ("Show completed" brings them back, greyed out).
- Assign to own technicians or pass to a subcontractor.
- **Tasks**: personal to-dos without a customer (title, date, a time or all day, note, for oneself or — for Owners and
  Admins — a teammate, an optional reminder by push and email). Shown in the calendar and on My Jobs in their own
  color; ticked done in one tap. Technicians see their own tasks; Owners and Admins see everyone's.
- **Overdue** is shown only after a visit's arrival window has ended; inside the window a job is not overdue.

### 7.4 Technician mobile view (PWA)

- Today's jobs list: customer, address, appliance, status, ticket size. My Jobs shows one day at a time (‹ › to
  other days), a Completed tab and the person's tasks of the day.
- A round **"+"** above the bottom bar on every screen (hidden on screens with their own pinned actions) opens, from
  the bottom up: **Job** (book a customer), **Expense** (a new business expense; a technician's goes to My expenses),
  **Trip** (the mileage form: from, to, distance) and **Task**. The bottom bar has no Book item; it has Map (a
  technician's My expenses is in More). For office roles a day also lists the jobs nobody is assigned to yet
  (marked "Not assigned") and says how many jobs other people have that day, with a link to the calendar.
- One-tap navigation (opens Google Maps).
- Status buttons: On my way (sends SMS with ETA), Started, Waiting for parts, Completed. Time on job is tracked automatically.
- Photos before/after with upload retry when signal is weak.
- Scan/photograph rating plate → store model & serial (manual entry in v1; OCR later).
- Customer signature on screen.
- After a deploy the app shows "New version available" with **Update** (checked when the app opens and every 30
  minutes); it waits while a form with unsaved changes is open.
- Create estimate or invoice on site and take payment.

### 7.5 Estimates

- Line items from price book or custom.
- One set of lines per estimate (Good / Better / Best options were removed by the owner on October 19, 2026; lines
  saved with an option count as ordinary lines). Optional lines the customer may add stay.
- Each line is one row (name, qty × price, amount); a tap opens a small sheet (name, quantity, price, Done) with the
  note, taxes and the line's own warranty under "More". Taxes come from the company's default rates; the warranty is
  set for the whole document (labour and parts, from the company's settings).
- Send by SMS/email as a link; customer approves and signs online; approved estimate converts to job/invoice.
- Estimate expiry and follow-up reminders.

### 7.6 Invoices and payments

- Invoice from job in one tap; line items, taxes, discounts, deposits, partial payments.
- Each estimate/invoice item can inherit enabled document taxes or select its own subset, including no taxes.
  Each tax rate sets the line types it is charged on by default (labor / parts / materials, e.g. BC PST on parts and
  materials only); it can still be ticked or unticked on a single line.
- Line warranty applies to labor and parts only; materials carry no warranty (not shown, printed or counted).
- Manual "Mark as paid" from the job and the invoice: Cash / Card / E-transfer (Bank transfer outside Canada) /
  Crypto / Other / Check, amount = balance, date = today; a partial payment leaves the balance visible. When an
  invoice is paid in full the app asks "Send Google Review request?" (§8) and then offers "Complete job" in one tap.
  Named rates can be activated/deactivated. Existing documents retain their tax names, rates and selections.
- Configurable taxes per company (§1.1): several named rates, compound taxes, prices with or without tax. Examples:
  BC — GST 5% + PST 7%; a US city — one combined sales tax rate; UK — VAT 20% with tax-inclusive prices.
- **Payment terms:** company default (Due on receipt, Net 7, Net 15, Net 30), changeable per customer (stratas and
  property managers usually need Net 30). The invoice due date = invoice date + the customer's terms (editable on the
  invoice).
- Send by SMS/email as a link with PDF.
- **Square integration (required):** each company connects its own Square account via OAuth. Money goes directly to the company.
    - Tokens are stored encrypted; the Owner can disconnect the account at any time.
    - **Square is not available in every country** (at the time of writing: USA, Canada, UK, Ireland, Australia,
      Japan, France, Spain). The provider is offered only to companies in those countries. For other countries the
      **next provider is Stripe**, built behind the same interface.
    - Online: a Square payment link for the invoice balance (and a QR code of it on the technician's screen).
    - On site: QR code / payment link on the tech's screen; investigate Square Point of Sale API hand-off from the PWA to the Square app for card-present payments.
    - Payments recorded back to the invoice automatically (webhooks), as a payment through the provider. A repeated
      webhook never creates a duplicate payment.
    - Partial and manual payments keep working next to the provider.
    - Tips taken by the provider are stored on the payment apart from the amount applied to the invoice (invoice
      revenue = invoice total; amount + tip = provider payout). Refunds made at the provider come back by webhook and
      reopen the invoice balance (status Refunded when everything was refunded).
    - Square keys (application ID/secret, webhook signature key, sandbox/production) come only from `.env`.
- **Payment providers are pluggable.** Build a provider interface; Square is the first implementation. Others (Stripe, Moneris, Helcim, Clover) can be added later without changing invoice logic. Each company picks its provider in settings, or none.
- A company with no provider records all payments manually.
- Manual payment methods: cash, check/cheque, bank transfer (e-Transfer, Zelle, ACH, BACS …, with reference), card on
  own terminal (with transaction reference), other (with note). Available in every company regardless of provider.
- Marking an invoice paid manually is a normal flow, not an exception.
- Automatic reminders for unpaid invoices; aging report.
- **"Send Google Review request?" after every invoice send** (see §8).

### 7.7 Customer communication

**SMS mode** (company setting; new companies start on *From technician's phone*):

- **Automatic** — the app sends texts itself. The platform holds one provider account (**Telnyx** by default,
  Twilio also supported); every company gets its own **messaging profile / subaccount and local number** in its
  country (one per company for now), so companies never sign up with the provider. The SMS provider is pluggable
  (`SmsProvider` interface), like payment providers; a company keeps the provider its number came from. Keys only in
  `.env`.
- **From technician's phone** — buttons ("Send SMS", "On my way", "Send by SMS" on documents, the review request after an invoice)
  open the phone's messages app (`sms:` link, iOS and Android formats) with the number and the text ready; the job
  history records "SMS opened from technician's phone". No automatic texts: day-before reminders
  go by email.
- **Off** — everything that would be a text goes by email.

**Booking confirmation**: the bottom of Book customer has **Finish booking** (main) and **Save as draft**. Finish
booking saves the job and sends the confirmation by itself as the SMS mode says — Automatic: a text from the company
number; From technician's phone: the phone's messages app opens with the text ready; when a text cannot go (no phone,
opted out, no SMS account) it is emailed if the customer has an address; Off: nothing is sent. The sheet after
booking says how it went. A finished booking whose day or arrival window changes asks **"Notify client?"** (Yes sends
the updated confirmation). The job's "…" has **Resend confirmation** for finished bookings only.

Messages: reminder the day before the visit, "On my way" with the arrival window (when the technician taps the
button), estimate/invoice link ("Send by SMS" next to "Send by email"), Google review request (§8), a free text from
the job. Templates per company with placeholders (`{customer_first_name}`, `{customer_name}`, `{brand}`, `{company}`,
`{tech_name}`, `{visit_date}`, `{arrival_window}`, `{number}`, `{amount}`, `{link}`, `{review_link}`); English defaults;
used in every mode.

Rules:

- **USA:** texts to US numbers only after the company's **A2P 10DLC registration** (brand + campaign) is approved.
  The company fills in its business details in settings and sees the status; until approval texts to US numbers are
  not sent and the UI says why (the message goes by email when there is an address). Canada and other countries:
  no such restriction today (other countries have their own sender rules).
- **STOP / START / HELP**: a customer who replies STOP gets no more texts to that number (shown on the customer card);
  START turns texts back on; the provider answers STOP/HELP itself.
- **Quiet hours** in the company time zone (default 21:00–08:00): texts due at night go out in the morning.
- All texts, emails and the customer's replies are kept on the customer and job timelines with their status
  (scheduled, sent, delivered, failed, not sent + reason).

Later: automated "parts arrived", "payment received", payment reminders (Stage 2), click-to-call
from the company number (provider voice).

### 7.8 Appliance-specific features (appliance repair vertical; not in Housecall Pro)

- Appliance card with model, serial, plate photo and full repair history across jobs.
- **Parts orders:** part number, description, supplier, supplier order number, cost, sell price, status (to order / ordered / shipped / received / installed / returned), ETA. Job automatically moves to `waiting_for_parts`; when part is marked received, office is notified and customer optionally gets an SMS.
- **Manufacturer warranty jobs** (e.g. Equator, Midea, Hisense, Haier): manufacturer, claim/authorization number, model/serial, proof of purchase upload, billed to manufacturer (not the customer), claim status (submitted / approved / paid / rejected).
- Price book with typical repairs per appliance type and brand.

### 7.9 Strata / property manager features (for Duct Works)

- Property manager customer with many buildings; building with many units.
- One job covering many units; per-unit status (cleaned / inspected / issue found), per-unit photos and notes. No checklists (removed by the owner, UX-REDESIGN §20).
- **Inspection report PDF** per building for the property manager, branded.
- Recurring service (e.g. yearly vent cleaning) auto-creates future jobs.

### 7.10 Service plans and recurring jobs

- Recurring jobs on a schedule (every N months/years).
- Maintenance plans with a price and included visits.

### 7.11 Price book and materials

- Services and parts catalog with cost and price, categories, per-brand availability.
- Add to estimates/invoices quickly. Parts and materials use a manually entered customer price and a separate private purchase price. Only the person who entered the purchase price sees it or its generated difference; other technicians, Owners/Admins and customer documents do not. Exception: the Owner's profit totals (§7.13) include everyone's costs without showing any line. No markup scale or automatic selling-price calculation.

### 7.12 Team

- Roles as in §5.
- Companies say when they register whether they work alone or with a team (changeable in settings; adding the first
  team member switches to "with a team"). Working alone there is no technician to choose anywhere and every job is
  the Owner's. With a team the technician of a job is chosen in one tap (job page, its header, "Not assigned" on My
  Jobs), with each person's jobs that day and "free" / "busy 11–1" for the job's window; "Transfer" in the calendar
  (per-person view) and on a Team card moves ticked jobs to another person. A person who goes on calls (Field menu)
  books to themselves by default; the office picks.
- Time on jobs per technician. Owners/Admins can transfer unfinished visits to active team members; completed history keeps its original identity. A company-only technician login can be reassigned with a new password and a new person identity while preserving the old history. Shared cross-company identities cannot be replaced by one company.
- Subcontractor payout share per job (percentage or fixed), payout report.

### 7.13 Reports

- Revenue by brand, technician, job type, lead source, period.
- **Profit** for a day / week / month / year (or any period): revenue without taxes − cost of parts and materials −
  card processing fees − business expenses (price plus non-recoverable taxes) − mileage at the company rate, with the
  margin in %. Parts/materials without a cost count as 0 and are listed.
- Average ticket, estimate conversion rate.
- Accounts receivable aging.
- Jobs waiting for parts.
- Warranty claims outstanding.

#### Mileage log

- Trips: date, from, to, distance, purpose, type (customer / parts store / supplier / other), driver.
- Trips to customers are created when a job is started: from the job started before it that day (or the person's
  start address, e.g. home) to the job's address; road distance from Google Distance Matrix (server key in `.env`),
  editable. Other trips: "+ → Trip" opens the form by hand (from, to, distance); "Add trip" in Mileage's "…" —
  only the destination is typed; "Navigate" saves the trip
  and opens Google Maps turn-by-turn to it ("Save only" for a trip already driven); the start is the phone's GPS
  position (or the last point of the day), and the next job's trip starts from that stop. "Enter by hand" on the
  same sheet adds a trip with any date and distance; trips are edited on the Mileage page.
- Distances in the company unit (km or mi; miles by default in the US/UK), a company rate per unit, month and year
  totals, CSV export of the month or year (date, route, purpose, distance). Technicians see their own trips;
  Owners/Admins see everyone's.

#### Business expenses (bookkeeping)

- Expenses independent of jobs and customer invoices: fuel, meals, tools and other overhead.
- Company members create shared custom categories. Each record has a date, description, merchant, price before
  tax, actual tax paid, original currency, notes and an optional private receipt photo/PDF. Select any number of
  company taxes per receipt and adjust their actual amounts. Historical undivided tax entries remain editable.
- Show price, tax and total beside each category for the chosen period, with separate rows for currencies.
  No combined expense counter or overall amount. Filter/search/pagination and CSV export for bookkeeping.
- Owners/Admins can filter company expenses by employee and see price/tax/total per employee and currency; technicians see and manage their own entries. Members manage categories
  they created; the office manages all categories. Archive categories without losing historical records.
- Expenses do not change a job's margin or appear on customer documents (they are part of the company profit). Receipts and removed records are
  retained privately; changes are audited.

### 7.14 Integrations

- Square (required, v1)
- Telnyx SMS (v1; Twilio supported) + voice later
- Google Maps / Places (v1)
- Transactional email provider (v1)
- QuickBooks Online (later)
- Anthropic API for the AI features of §7.15 (v1)
- Square import of customers and invoice history (§7.15.3)

### 7.15 AI features and Square import (owner's task of November 15)

Built in five PRs, in this order. Each one waits for the owner's "ok" before the next.

**Common AI service.** Every AI request goes through one service (`app/Services/AI`) on the Anthropic API. It
supports tools (tool use). Each feature always uses its own model, set in `.env`, with no automatic switching between
models: `AI_MODEL_BOOKING` (reading the text of a booking only), `AI_MODEL_ASSISTANT` (the assistant and Find
problems), `AI_MODEL_REVIEW` (App review only). Every request is logged: company, user, feature, model, tokens, cost,
time. A daily request limit per company is set in `.env` (`AI_DAILY_LIMIT_PER_COMPANY`). The API key
(`ANTHROPIC_API_KEY`) never reaches the browser. The AI never saves, sends, changes or deletes anything by itself.

#### 7.15.1 Quick booking (PR 1)

- "Quick booking" on Today and in the "+" menu; Owner and Office only.
- One big multi-line text field and Parse. Voice is the phone keyboard's microphone (no own recording); an SMS from
  the customer can be pasted; the text can be edited before Parse.
- Parse sends the text (Russian, English or mixed) to `AI_MODEL_BOOKING` and gets: first and last name, phone, email,
  address, unit, buzzer, appliance, brand, model, problem, date, arrival window, notes. Field values in English, names
  and addresses as written. "Today", "tomorrow", "on Monday" count in the company's time zone. The phone is stored in
  E.164. Anything not in the text stays empty.
- The usual Book customer form opens filled in, with the AI-filled fields highlighted. The customer is found by phone,
  then by name and address; when found, that customer is used and shown (no duplicate). The address is checked with
  the existing address suggestions (Google Places). Without a date or window the free windows of the next days are
  offered by the company's window rules ("Now" for today). Only the person's Save stores the booking. When the AI does
  not answer, a message is shown and the text stays.
- "Paste from message" in the booking form goes through the same service and model.

#### 7.15.2 AI assistant (PR 2)

- "Assistant" in More: a chat screen like the SMS screen; questions typed or dictated. Owner only for now (Office
  later). Always `AI_MODEL_ASSISTANT`.
- Read-only tools, strictly inside the user's company: unpaid and overdue invoices (amounts, days overdue); jobs on a
  date or period; jobs by status (Need to order parts, Waiting for parts, Overdue, Not completed); customer search by
  name, phone, address; customer history (jobs, estimates, invoices, payments, including invoices imported from
  Square); estimates waiting for approval; revenue and payments for a period; free windows on a date.
- Actions, only after confirmation: SMS payment reminder for an invoice; move a booking to another date or window;
  create a booking (the text goes to the Quick booking parser and the same filled form opens). Before any action the
  assistant shows a "what I will do" card with Confirm and Cancel; nothing runs without Confirm. Every action goes to
  the change log marked "AI assistant". The assistant cannot delete anything.
- The composer sits right above the bottom bar, and right on top of the keyboard while typing (the bar hides). A
  microphone next to Send dictates with the browser's speech recognition (EN / RU, remembered per user); nothing is
  sent by itself. The same composer and microphone are used in the SMS chat and Quick booking.
- Invoice from photos: up to 5 photos or screenshots (shrunk on the phone to 1600 px) go to `AI_MODEL_ASSISTANT` with
  the text. Tool `create_invoice`: the AI passes the customer (name, phone, email, address), work lines with amounts
  and whether the amounts include tax; code calculates the company's taxes and the total. A preview card (Confirm &
  send, Edit, Cancel); nothing is stored before a button. Confirm & send: customer found by phone or email (else
  created), invoice on the customer's open job (else a Completed job for today), photos saved as invoice attachments,
  link by SMS and by email when mail is set up. Edit: saved, not sent, the invoice editor opens. Without a phone or an
  amount the assistant asks; it never invents them.

#### 7.15.3 Import customers and invoice history from Square (PR 3)

- "Import from Square" in the integration settings; Owner only; uses the connected Square account and asks for the
  missing read permissions (customers, invoices, orders, payments) when pressed. No AI.
- Customers: first and last name, company, phone (E.164), email, address, notes, Square customer ID. Duplicates by
  Square ID, phone, email.
- Review screen before import: new customers, matches with existing ones, customers without phone and email. For each
  match: merge, skip or create new; "Merge all". The import starts only with Import.
- Invoice history for all time: number, date, status, items, amounts, taxes, payments, payment method; linked to the
  customer; marked "Imported from Square", read-only, left out of the tax reserve, shown in the customer's history.
- Runs in the background with progress on screen. Running it again brings only new and changed records (no
  duplicates). Imported customers get nothing (no SMS, no email). The whole import can be undone with one button
  (everything imported goes to Deleted items for 30 days). Each import is written to the change log (who, when, how
  many customers and invoices).

#### 7.15.4 Find problems (PR 4)

- "Find problems" on the Assistant screen, or asking the assistant "what needs fixing". Owner only. Never on a
  schedule, never in the background.
- Collected by code: overdue invoices; jobs in Waiting for parts / Need to order parts longer than N days (company
  setting, default 7); estimates without an answer longer than M days (default 3); unclosed jobs with a past date;
  technician schedule overlaps; customers without a phone or address; duplicate customers. Technical, last 7 days:
  grouped Laravel log errors, failed queue jobs, SMS not sent, Square sync failures, failed backups.
- One request to `AI_MODEL_ASSISTANT`: problems by importance, each with what to do; business problems link to the
  job, customer or invoice; technical problems come with a ready task text for Claude Code in one copyable block. "No
  problems" when there are none. The AI fixes nothing.

#### 7.15.5 App review (PR 5)

- Action log: screen opens, time on screen, button taps, form start / save / abandon, "back", searches and their result
  (found / not found), errors the user saw; for the main flows (booking, Quick booking, estimate, invoice create and
  send, customer search, moving a booking) time and taps from start to end. No customer data (names, phones,
  addresses, amounts): only actions, screens, the user's role and time. Kept 90 days; written in the background.
- "Inconvenient" button on every screen (header or More), every role: a text field (voice via the keyboard) and Send;
  stored with the screen and the user's last 20 actions.
- "App review" on the Assistant screen, Owner only, only by this button: period since the last review (default), 1,
  3, 7 or 30 days. Code builds the summary (flow times and taps, average and worst; abandoned forms; frequent "back";
  failed searches; errors; all "Inconvenient" notes) and sends it with the earlier reviews' suggestions and their
  statuses to `AI_MODEL_REVIEW` in one request. Answer: what is inconvenient and why (with numbers), what to fix by
  importance, and a final task for Claude Code in one copyable block (direct orders, plain Russian headings). The AI
  changes nothing.
- Every review is kept (date, period, text, cost); each suggestion has Done / Not needed / Later; a screen lists the
  past reviews.

### 7.16 Texts with AI, auto replies, voice, photos, booking edits and the morning summary (owner's task of November 18)

Built in three PRs. Common rules: every AI action except the auto replies (§7.16.3) and On my way (§7.16.4) runs only
after the person's Confirm or Save; the AI deletes nothing. Taxes and totals are always calculated by code. Every AI
call counts in `AI_DAILY_LIMIT_PER_COMPANY`. Words like "tomorrow" or "on Thursday" become a date in the company's
time zone (by code when the AI returns the word). A function whose service is not set up (Telnyx voice, web push keys,
Google Maps key) is built, stays off, and says what is missing.

#### 7.16.1 Incoming texts read by the AI (PR 1)

- Every incoming SMS goes to `AI_MODEL_BOOKING` with the latest messages of the conversation and the customer's open
  jobs. Answer: urgency (urgent / normal), proposed actions (book, move to another day, change the time, cancel) and a
  reply draft.
- In the SMS chat a card under the message lists the actions; each opens a preview (what will change) with Confirm and
  Dismiss. Book opens the filled Book customer form (saved only by Save). Moving or cancelling never texts the
  customer. The reply draft goes into the reply box (and "Use reply draft"); only the person sends it.
- Urgent: a red label on the message and the conversation; urgent conversations are always first in the list until
  someone replies or presses Done.
- A text from a customer with an unfinished job is linked to that job; the job's card in the calendar and in My Jobs
  shows a new-message badge with the count until the chat is read (a technician reads it on the job page).
- MMS photos are kept (private files) and added to the open job's photos. They go to `AI_MODEL_ASSISTANT`; a rating
  plate gives brand, model and serial, attached to the job's appliance only after Confirm.

#### 7.16.2 Reminders about unanswered texts (PR 1)

- An incoming text nobody answered and not marked Done: a reminder (push + the notification list) to the Owners and
  Admins after N hours (Settings → Auto messages, 2 by default since §7.17.2), repeated every N hours, at most 3 times, only during
  business hours (a reminder due after closing waits for the next opening). A text that came outside business hours
  gets one reminder at the start of the next business day.
- "Done" in the chat (no reply) stops the reminders; a reply from a person stops them too.
- Web push uses the existing VAPID keys and service worker (Settings → Notifications turns it on per device).

#### 7.16.3 Auto messages (PR 1)

- Settings → Auto messages (Owner). Auto reply to texts: on/off (off by default), the text, a delay of 0 / 5 / 15 / 30 /
  60 minutes (15 by default). Sent only when no person replied within the delay, at most once per 24 hours per number,
  only during business hours (never outside them), only in Automatic SMS mode.
- Text after a missed call to the company number: on/off and its own text, same rules (shared 24-hour limit per
  number). Needs calls to the company number to go through a Telnyx voice app with the webhook
  `/webhooks/voice/telnyx` and `TELNYX_VOICE_ENABLED=true`; off until then. The customer's answer comes in as §7.16.1.
- The company's own staff (their profile phone numbers) never get auto messages.
- The "Possible warranty" window (§7.16.13) is set on the same page.

#### 7.16.4 On my way with the drive time (PR 2)

- On my way (Automatic SMS mode) texts the customer at once. The phone's position (when the browser gives it within a
  few seconds) and the job's address go to Google Distance Matrix (the app's server maps key, with traffic); the text
  is the company's "On my way (with ETA)" template: "… On my way, ETA about {eta_minutes} min." Without an address, a
  position or a route, the usual On my way text goes (arrival window, no ETA). From technician's phone mode is
  unchanged (the phone's messages app opens with the usual text).

#### 7.16.5 Review request after payment (PR 2)

- Two hours after an invoice is marked paid: a notification "Send a review request?" (push + list) to the person who
  took the payment (else the Owners). It opens the invoice with the review request ready; the text can be edited and
  nothing goes before Send. Never sent automatically; skipped when the job already had a request, the company has no
  Google profile or texts are Off.

#### 7.16.6 Invoice from photos in the assistant

- Already built in §7.15.2 (November 16).

#### 7.16.7 Close by voice (PR 2)

- "Close by voice" on the job page (work in progress): a text field with the microphone (browser speech, EN / RU).
  `AI_MODEL_ASSISTANT` fills the note about the work, invoice lines (work / part, amounts as said, never invented),
  whether the amounts include tax, the payment (card / cash / e-transfer / not paid) and the status (Completed, or Need
  to order parts with the part). Code calculates taxes and the total. Preview → Confirm: the note is added to Work done,
  the invoice is created, the payment recorded (card terminal / cash / bank transfer), the visit finished and the job
  closed as repaired (or moved to Need to order parts).

#### 7.16.8 Quick edit of a booking by voice (PR 2)

- The job's edit sheet (pencil on the job page) starts with "Quick edit": text + microphone (EN / RU). The booking as
  it is and the text go to `AI_MODEL_BOOKING`; only the fields named come back (date, window or "Now", technician,
  name, phone, email, address, problem, notes). They are filled in and highlighted; nothing is saved until Save. When
  the AI cannot tell what to change, a short message and the form stays as it was. The sheet now also edits phone,
  email, problem and notes.
- "Edit by voice" on job cards in My Jobs and in the calendar's job details opens the job with the sheet open and the
  cursor in Quick edit (office).

#### 7.16.9 Parts (PR 2)

- When a job moves to Need to order parts, `AI_MODEL_ASSISTANT` suggests a possible part number from the appliance,
  the part and the problem; it is shown marked "check" with Use (fills the field; Save stores it). The job page has the
  part: name, number, supplier, order date.
- In Waiting for parts the office has "Part arrived": a text to the customer with the three nearest free arrival
  windows (from tomorrow, business days), editable, sent only on Send. The answer comes into the SMS chat (§7.16.1).

#### 7.16.10 Receipt from a photo (PR 3)

- A camera button in Expenses and My expenses (and "Read receipt" at the top of a new expense): the photo (shrunk to
  1600 px on the phone) goes to `AI_MODEL_ASSISTANT`, which reads the merchant, the date, the total, the taxes printed
  (GST, PST, …) and a category. Code fills the form: printed taxes matched to the company's tax names, the amount
  before tax = total − taxes (taxes not matched go into the tax total); an unknown category becomes a new one. My
  expenses gets only the amount paid. The filled form is the preview; Save stores the expense with the photo as its
  receipt.

#### 7.16.11 Morning summary (PR 3)

- Every day at each person's time (07:00 by default; Settings → Notifications, on/off, per company): a push and a
  notification opening **Today**: the day's jobs in order, open jobs without an address, unanswered texts (night ones
  too), unpaid invoices (count and sum per currency) and parts to receive. The office sees the company; a technician
  their own jobs. Code only, no AI. More than three hours late it is skipped.

#### 7.16.12 Mileage of the day (PR 3)

- At 20:00 company time, for each person with a start address (Mileage → Start address, e.g. home) and jobs of their
  own started that day: start → the jobs in the order they were started → start, by road through the app's map
  service, saved as a **draft** trip in the mileage log. Drafts are not in the totals, CSV, profit or the expenses hub
  until the Owner (or an Admin) confirms or corrects them. Without a route the draft has no distance to fill in.

#### 7.16.13 Possible warranty (PR 1)

- When the customer had a job finished within the last 90 days (setting) and texts in, or a new job is created for
  them, the job page and the chat show "Possible warranty" with a link to the earlier job: date, what was done, parts.

### 7.17 Reminders, morning SMS, Drive backup, debtors, actions from notifications, translation, self-booking, nearby windows (owner's task of November 19)

Built in several PRs. Common rules as in §7.16: the AI sends nothing to customers by itself and deletes nothing (the
only exceptions are written below); taxes and totals are calculated by code; every AI call counts in
`AI_DAILY_LIMIT_PER_COMPANY`; dates and times are in the company's time zone. A function whose key or service is not
set up is built, stays off and says what is missing.

#### 7.17.1 Fixes (PR 1)

- "Edit by voice" on My Jobs cards is shown to everyone who may edit the job, also in a one-person company
  (`works_alone`).
- The morning push (§7.16.11) and the morning SMS are checked every minute, so they arrive at the set time (at most
  one minute late).

#### 7.17.2 Reminders: I did not answer / the customer is quiet (PR 1)

- **I did not answer** (§7.16.2): a reminder 2 hours after an unanswered incoming text that is not marked Done (Settings →
  Auto messages, 2 hours by default; companies that kept the old default of 3 move to 2), repeated every 2 hours, at
  most 3 times.
- **The customer is quiet**: a text a person typed (a reply, a new text, later a payment reminder) that got no answer
  within N hours (Settings → Auto messages → Customer is quiet, 2 by default) gives the person who wrote it (else the
  Owners and Admins) one reminder "{name} has not answered for 2 hours" with the beginning of the text and the buttons
  **Text again** (the chat opens with a short follow-up written by `AI_MODEL_BOOKING` in the reply box; one tap on Send),
  **Call** (the chat and the phone's dialer) and **Not waiting**. Still unanswered the next morning: a line in the
  morning SMS (once). Never for auto replies, On my way, review requests or texts marked **Not waiting for a reply**
  (an action under each of our texts in the chat). A text from the customer ends the wait.
- All reminders: push + the notification list, only in business hours; a reminder due outside them comes once at the
  start of the next business day.

#### 7.17.3 Morning SMS instead of the morning push (PR 1)

- Company → Morning SMS & backup (Owner): **My personal number**, time (06:30 by default), on/off. The SMS goes from the
  company number (Telnyx or Twilio, whichever the company uses) to that number, once a day at the set time; it is not
  stored in any customer chat and replaces the morning push for Owners (other people keep their push).
- Content (code only): the day's jobs in order — time, name, phone, address, what is broken; jobs without an address;
  unanswered texts (night ones too); quiet customers; unpaid invoices (count and sum); Completed jobs without an
  invoice; parts to receive; "Backup failed" when the night's Drive backup did not go through. The page shows today's
  text as a preview. The **Today** screen is unchanged.

#### 7.17.4 Nightly backup to Google Drive (PR 1)

- Company → Morning SMS & backup → **Connect Google Drive** (OAuth, scope `drive.file`: the app sees only the files it
  creates). Needs `GOOGLE_DRIVE_CLIENT_ID` / `GOOGLE_DRIVE_CLIENT_SECRET` (redirect `{APP_URL}/settings/google-drive/callback`);
  without them the button says it is not set up.
- Every night at 03:00 company time two CSV files go to the folder "{company} Backup" ("Doctor Appliance Backup"):
  all customers (name, phones, email, address, date of the last job, appliances, balance) and the jobs of the next 7
  days (date, window, customer, phone, address, problem, technician). The 30 newest copies are kept; older files are
  deleted from that folder (code, not the AI). A failed upload: a notification to the Owners and a line in the
  morning SMS.

#### 7.17.5 Actions right from a notification (PR 1)

- Every incoming SMS gives the Owners and Admins a push: one line from the AI (who writes and what they want) and the
  Android buttons: the **main proposed action** (e.g. "Book Thu 10:00–12:00", "Move to Thu 10:00–12:00", "Cancel #1005"),
  **Draft**, **Later**. The text of the notification says what the main button will do; the tap is the confirmation
  and it is done at once (a booking needs a known customer with an address, a day and a window, and sends the usual
  booking confirmation; moving and cancelling never text the customer). **Draft** opens the chat with the reply draft in
  the box. **Later** shows the same notification again an hour later. The reminders of §7.17.2 come with buttons too.
  Android shows two buttons under a notification; all of them are in the app's notification list.
- A button's token belongs to one person and works once (3 days at most).

#### 7.17.6 Home-screen shortcuts (PR 1)

- The PWA manifest has two shortcuts (long press on the icon): **By voice** (the assistant with the microphone on) and
  **Quick booking** (with the microphone on).

#### 7.17.7 Debtors and invoices not sent yet (PR 2)

- Import page (Owner only): a CSV with the columns name, phone, email, address, date, work, amount (optional), note.
  Preview of the list → Confirm. Confirm finds the customer by phone or email (else creates one) and creates a
  Completed job on that date and an invoice "open, not sent". Without an amount the invoice line is marked "amount
  needed".
- **Unpaid** screen: all open invoices with the filter "not reminded yet / reminded / answered". **Soft reminder** for
  one or several chosen invoices: preview → Confirm. The template is in the settings, by default "Hi {first_name}, this
  is Alex from Doctor Appliance. Just a quick note: our records show the invoice for your {appliance} repair from
  {month} is still open. Could you take a look when you get a chance?" — no amount and no link in it.
- When the customer answers such an SMS, the AI prepares a reply draft with the amount and the invoice link; one tap
  sends it. No answer: the "customer is quiet" reminder (§7.17.2); nothing is texted again automatically.
- Completed jobs without an invoice: a counter in the "Not completed jobs" bar and in the morning SMS.

#### 7.17.8 Russian in, English out (PR 2)

- In the SMS chat and in reply drafts: Russian text in the reply box is turned by `AI_MODEL_ASSISTANT` into a short,
  polite English SMS and shown in the box before sending; the person sends it. English text is left as it is. A switch
  in the settings, on by default.

#### 7.17.9 Prices and answers; clarifying questions; a photo of the sticker (PR 2)

- Settings → **Prices and answers**: the diagnostic fee, service areas, business hours (text), free notes. The AI uses
  them in reply drafts and never names a price that is not there.
- A text about a breakdown with few details: the draft has at most 2 short questions (what exactly happens, since when,
  any error code). When the customer's appliance has no model number: always ask for a photo of the sticker with the
  model number (not to type it), with where to find it (fridge — inside on a side wall; washer — on the door rim;
  dryer — on the door frame; dishwasher — on the edge of the door; range — behind the oven door or in the bottom
  drawer).
- Switch "Send clarifying questions automatically" (off by default = a draft). Automatic only in business hours and at
  most once per 24 hours to one number.

#### 7.17.10 Hand a job to a technician (PR 2)

- "Hand to technician" on the job: a technician of the app or an outside technician (name, phone — a list in the
  settings). Preview of two texts: to the technician (customer, phone, address, window, problem, model, link to the
  photos) and to the customer ("Your technician {name} will arrive {window}."). Confirm sends both. The job shows
  "Handed to: {name}".

#### 7.17.11 Self-booking by link (PR 3)

- A public page `/book/{token}` without sign-in. A link lives 7 days and books once. Free windows of the next 7 days
  (the company's arrival windows minus busy ones), name and phone (filled when known), address, what is broken, a photo
  of the sticker with the model number. After sending: the booking is created, marked "Self-booked" in its own colour
  in the calendar, and the Owner gets a push. The AI adds the link to a reply draft when the customer asks about times
  or asks to come.

#### 7.17.12 Windows by area (PR 3)

- Addresses of jobs and customers are geocoded by the app's map service. When a window is picked (Book customer, Quick
  booking, the AI's proposals from texts, the self-booking page), windows on days that already have a job near the
  customer's address come first, labelled "Nearby: job in {city}, {N} km". Without a known address: the usual order.

## 8. Google review requests

- A company can have **several Google profiles (locations)**, named by the company itself (e.g. Surrey, Burnaby).
  Each location: name, direct review link, optional brand. Each brand may pick a **default location** (preselected).
- The **only** ways to send a request: after every invoice send (email or SMS), and after an invoice is paid in full,
  the app asks **"Send Google Review
  request?"** with **Yes** and **No**, nothing preselected. No closes it. Yes shows a **Location** dropdown, a
  **Phone** field (the customer's phone, editable) and **Send**, which sends the chosen location's review link as a
  **separate SMS** (Automatic mode) or opens it on the technician's phone (*From technician's phone* mode). In *Off*
  mode it cannot be sent. Nothing is sent automatically, and there are no review buttons or prompts anywhere else.
- Each company writes its own request text (template with `{customer_first_name}`, `{brand}`, `{review_link}`, …).
- Track the job's latest request: sent (when, channel, location) / not sent (why, e.g. opted out).
- **Forbidden by Google's and the FTC's rules, and not supported by the app:** no discounts, gifts or any reward for a
  review; no review gating — never ask "were you happy?" first and send only happy customers to Google. The same
  request goes to every customer.

## 9. Non-functional requirements

- Mobile-first UI; technician screens usable with one hand. Visible Menu and permanent Book customer actions lead directly to booking, including from Calendar. Login opens the working company for Owners/Admins and My jobs for technicians. Platform administration/support are separate; persistent sign-in is enabled by default.
- Quick office booking: phone, first and last name, the address (required, right after the name) and the arrival window create the customer, job and visit atomically; an existing customer can be found by name/phone. An address is never saved empty; a customer's first address is the primary one. Other details can be supplied later. Empty calendar days offer booking. New invoice is available from the invoice list and leads to an existing job or short customer entry, then prices. Full job creation cannot save without a customer/property.
- English UI in v1; all text strings kept in translation files for future languages.
- Localization per company as in §1.1 (currency, taxes, time zone, regional format, address format, E.164 phones).
- Security: hashed passwords, optional 2FA for every user, role-based access, rate limiting, audit log of sensitive actions.
- Privacy: customer data belongs to the company; company can export all its data (CSV); deletion on request.
- Daily database backups, off-server.
- Performance: main screens load in under 2 seconds on 4G.
- Every tenant-owned query is tenant-scoped; automated tests prove isolation.

## 10. Delivery stages

### First-run account setup

Public registration with name, email and password and clear account/company setup. Signed email confirmation is required only after the operator has tested real delivery and enabled `AUTH_EMAIL_DELIVERY_ENABLED=true` with `AUTH_EMAIL_VERIFICATION_REQUIRED=true`. Delivery defaults off: new users create their own company as Owner with a first brand, and existing company users work without a confirmation wall. Their email addresses remain unverified. Setup omits the confirmation step while delivery is off; password recovery and resend explain that mail is unavailable rather than promising a letter. New staff and administrator-created Owners receive a confirmed password entered by the authorized company administrator while mail is off; existing account passwords are never changed by adding a membership. When delivery is enabled, unverified accounts must confirm and receive their first signed link at the confirmation screen. Readiness is an explicit server setting, never a request parameter or an automatic bypass on a delivery outage. Existing memberships keep their company access; suspended accounts cannot bypass restrictions through setup. Two-factor enrollment is optional for all roles, including the platform super-admin. Email confirmation is separate from optional authenticator-based 2FA; email login codes are a future task.

### Stage 0 — Foundation

Project skeleton, auth, companies (tenants), brands, users & roles, super-admin panel, tenant isolation tests, deployment pipeline to VPS.

### Stage 1 — MVP (owner can run the business on it)

Customers, properties, appliances, jobs & statuses, calendar & dispatch, technician PWA view, photos, signatures, estimates, invoices, Square payments, Telnyx SMS (automated messages + inbox), review request after invoice send, price book, basic reports.

### Stage 2

Parts orders, manufacturer warranty claims, online booking page, click-to-call, Collector and Subcontractor roles, payment reminders, AR aging.

### Stage 3

Strata features & inspection PDFs, service plans & recurring jobs, QuickBooks, advanced reports, extended onboarding flow for new companies.

Tenant subscription billing (§11) is a separate task scheduled before the Phase B launch.

### Later (not in scope now)

Other AI features (call answering, plate OCR, estimate drafting), native mobile apps, payroll.

### Out of scope

Construction and renovation project management: multi-phase projects, change orders, progress billing, managing
subcontractors on projects (see §1.2).

## 11. Platform subscription billing

How companies (tenants) pay **us** for the app. Not to be confused with §7.6, where companies take payments from **their customers**: the two modules share no code, settings or credentials.

### 11.1 Provider

- **Stripe Billing** is used for company subscriptions to the app.
- It is a separate module from the invoice payment providers of §7.6 (Square etc.).
- Stripe keys, webhook secret and account come from env config only (`.env`), nothing hardcoded. No Stripe price/product IDs in code either; they are created or looked up from our plan data.

### 11.2 Plans and prices (USD per month)

All subscription prices are in **USD** and are **set in config** (`config/subscriptions.php`, amounts from env, created
when this module is built), not in code. The owner sets the amounts later; the founding price of Pro is configurable
too (it may equal the regular price).

| Plan | Users          | Regular price | Founding member price           |
| ---- | -------------- | ------------- | ------------------------------- |
| Solo | 1 user exactly | (config)      | (config)                        |
| Team | up to 5        | (config)      | (config)                        |
| Pro  | up to 15       | (config)      | (config; may equal the regular) |

- Solo is for exactly one user (the Owner). Adding a second user requires Team.

- **Founding members:** the first 20–30 companies (the exact cut-off is a platform setting). Their price is **locked for life**: later price changes never apply to them while their subscription stays active.
- Prices are stored per company subscription in our database, so a price change for new customers never changes existing subscriptions by accident.

### 11.3 Trial and signup

- Trial: **3 months** for founding members, **2 months** otherwise.
- A card is required at signup (collected by Stripe Checkout / Elements; the card never touches our servers).
- The subscription starts billing automatically at trial end.

### 11.4 Self-service

- Companies update their card, see invoices/receipts and billing history via the **Stripe Customer Portal**, opened from the Owner's billing page.
- We show no bank details and issue no manual subscription invoices.
- Only the Owner sees and manages the company subscription (Admins do not).

### 11.5 Source of truth

- **Our database is the source of truth** for: plan, price (amount and currency), founding member status, trial end date and billing anchor date (the day of month the subscription renews), subscription status.
- Stripe stores the billing objects; our database keeps only references: `stripe_customer_id` and `stripe_subscription_id`. **No card data** (not even last 4 digits) is stored in our database.
- Stripe webhooks update `subscription_status` (active, past_due, cancelled …) in our database; they never change plan, price, founding status or dates. Changes to those are made in our app and pushed to Stripe.
- The super-admin panel shows plan, price, founding status, trial end, anchor date and status per company.

### 11.6 Moving to a new Stripe account

The legal entity that owns the app will change later, so the billing setup must survive a move to a **new Stripe account**:

- Stripe can copy customers and their cards from the old account to the new one. **Customer IDs stay the same; payment method IDs change.** Subscriptions are **not** copied.
- An artisan command recreates every active (and trialing) subscription on the new Stripe account from our database, keeping each company's price, founding status, trial end and billing anchor date, so no customer is charged early, twice or at a different price. It must:
    - read the Stripe keys of the new account from env config;
    - be idempotent (safe to re-run; skips companies already migrated) and support a dry run;
    - attach each customer's copied default payment method on the new account;
    - store the new subscription ID in our database and report every company it could not migrate;
    - leave cancelling the old subscriptions as a separate, explicit step after checking the new ones.
- The full procedure (request the data copy from Stripe, switch env keys and webhook, dry run, run, verify, cancel old subscriptions, rollback) is documented in `docs/BILLING-MIGRATION.md`.

## 12. Open questions

- Product name and domain.
- Object storage provider.
- Transactional email provider.
