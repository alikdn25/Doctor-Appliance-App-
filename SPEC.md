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
- **Time zone**, **regional format** (date, time and number format, e.g. en-US, en-CA, en-GB) and **address format**
  (labels and order of state/province/county and ZIP/postal code) are company settings derived from the country.
- **Phone numbers** are stored in **E.164** (+15551234567). Numbers typed without a country code are read as numbers
  of the company's country.
- **All UI strings** live in translation files; English is the default (and the only language in v1).

### 1.2 Verticals

A company picks its **vertical** when it is created. The vertical sets its job types, default checklists and the
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
- Its own phone number for SMS (Twilio) and email sender identity
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
  tags, lead source, payment terms (empty = the company default; see §7.6).
- **Property:** address in the format of its country (Google Places autocomplete, geocoded), access notes, gate/buzzer code. A customer can have many properties. A strata building can have many **units**.
- **Appliance** (appliance repair vertical): property, type (washer, dryer, fridge, range, dishwasher, etc.), brand, model number, serial number, photo of the rating plate, install/purchase date, warranty info, full repair history.
- **Job:** brand, customer, property, appliance(s), job type (per vertical — appliance repair: repair / warranty /
  maintenance / installation / vent cleaning / inspection; handyman: repair / installation / assembly / mounting /
  maintenance / inspection), source, assigned user(s), scheduled window, estimated duration, status, notes, photos, checklists, signatures. A job can have several **visits** (diagnosis visit → parts → repair visit).
- **Estimate**, **Invoice**, **Payment**, **Price book item**, **Parts order**, **Warranty claim**, **Service plan**, **Inspection report**, **Message**, **Attachment**, **Activity log**.

### Job statuses

`new` → `scheduled` → `on_the_way` → `in_progress` → `waiting_for_parts` → `completed` → `invoiced` → `paid`, plus `waiting_for_customer`, `cancelled` and `on_hold`. Every status change is logged with user and time.

All unfinished jobs remain in a date-independent queue, reached from a compact persistent top bar with a job
counter. It includes overdue visits, work needing scheduling, waiting for parts/customer, on-hold work and future
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
- Map view of the day's jobs.
- Assign to own technicians or pass to a subcontractor.

### 7.4 Technician mobile view (PWA)

- Today's jobs list: customer, address, appliance, status, ticket size.
- One-tap navigation (opens Google Maps).
- Status buttons: On my way (sends SMS with ETA), Started, Waiting for parts, Completed. Time on job is tracked automatically.
- Photos before/after with upload retry when signal is weak.
- Scan/photograph rating plate → store model & serial (manual entry in v1; OCR later).
- Checklists per job type.
- Customer signature on screen.
- Create estimate or invoice on site and take payment.

### 7.5 Estimates

- Line items from price book or custom.
- Multiple options (Good / Better / Best) for the customer to choose.
- Send by SMS/email as a link; customer approves and signs online; approved estimate converts to job/invoice.
- Estimate expiry and follow-up reminders.

### 7.6 Invoices and payments

- Invoice from job in one tap; line items, taxes, discounts, deposits, partial payments.
- Each estimate/invoice item can inherit enabled document taxes or select its own subset, including no taxes.
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
- **Review request toggle on invoice sending** (see §8).

### 7.7 Customer communication

**SMS mode** (company setting; new companies start on *From technician's phone*):

- **Automatic** — the app sends texts itself. The platform holds one Twilio account; every company gets its own
  **subaccount and local number** in its country (one per company for now), so companies never sign up with Twilio.
  The SMS provider is pluggable (`SmsProvider` interface, Twilio first), like payment providers. Keys only in `.env`.
- **From technician's phone** — buttons ("Send SMS", "On my way", "Send by SMS" on documents, "Send review request")
  open the phone's messages app (`sms:` link, iOS and Android formats) with the number and the text ready; the job
  history records "SMS opened from technician's phone". No automatic texts: day-before reminders and review requests
  go by email.
- **Off** — everything that would be a text goes by email.

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
  START turns texts back on; Twilio answers STOP/HELP itself.
- **Quiet hours** in the company time zone (default 21:00–08:00): texts due at night go out in the morning.
- All texts, emails and the customer's replies are kept on the customer and job timelines with their status
  (scheduled, sent, delivered, failed, not sent + reason).

Later: automated "parts arrived", "payment received", payment reminders (Stage 2), click-to-call
from the company number (Twilio voice).

### 7.8 Appliance-specific features (appliance repair vertical; not in Housecall Pro)

- Appliance card with model, serial, plate photo and full repair history across jobs.
- **Parts orders:** part number, description, supplier, supplier order number, cost, sell price, status (to order / ordered / shipped / received / installed / returned), ETA. Job automatically moves to `waiting_for_parts`; when part is marked received, office is notified and customer optionally gets an SMS.
- **Manufacturer warranty jobs** (e.g. Equator, Midea, Hisense, Haier): manufacturer, claim/authorization number, model/serial, proof of purchase upload, billed to manufacturer (not the customer), claim status (submitted / approved / paid / rejected).
- Price book with typical repairs per appliance type and brand.

### 7.9 Strata / property manager features (for Duct Works)

- Property manager customer with many buildings; building with many units.
- One job covering many units; per-unit checklist (cleaned / inspected / issue found), per-unit photos and notes.
- **Inspection report PDF** per building for the property manager, branded.
- Recurring service (e.g. yearly vent cleaning) auto-creates future jobs.

### 7.10 Service plans and recurring jobs

- Recurring jobs on a schedule (every N months/years).
- Maintenance plans with a price and included visits.

### 7.11 Price book and materials

- Services and parts catalog with cost and price, categories, per-brand availability.
- Add to estimates/invoices quickly; margin visible to Owner/Admin only.

### 7.12 Team

- Roles as in §5.
- Time on jobs per technician.
- Subcontractor payout share per job (percentage or fixed), payout report.

### 7.13 Reports

- Revenue by brand, technician, job type, lead source, period.
- Average ticket, estimate conversion rate.
- Accounts receivable aging.
- Jobs waiting for parts.
- Warranty claims outstanding.

#### Business expenses (bookkeeping)

- Expenses independent of jobs and customer invoices: fuel, meals, tools and other overhead.
- Company members create shared custom categories. Each record has a date, description, merchant, price before
  tax, actual tax paid, original currency, notes and an optional private receipt photo/PDF. Select any number of
  company taxes per receipt and adjust their actual amounts. Historical undivided tax entries remain editable.
- Show price, tax and total beside each category for the chosen period, with separate rows for currencies.
  No combined expense counter or overall amount. Filter/search/pagination and CSV export for bookkeeping.
- Owners/Admins can filter company expenses by employee and see price/tax/total per employee and currency; technicians see and manage their own entries. Members manage categories
  they created; the office manages all categories. Archive categories without losing historical records.
- Expenses do not change a job's margin or appear on customer documents. Receipts and removed records are
  retained privately; changes are audited.

### 7.14 Integrations

- Square (required, v1)
- Twilio SMS + voice (v1)
- Google Maps / Places (v1)
- Transactional email provider (v1)
- QuickBooks Online (later)
- AI features (later, not in scope now)

## 8. Google review requests

- A company can have **several Google profiles** (e.g. per brand or city). Each profile: label, direct review link,
  optional brand. Each brand picks its **default profile**.
- Every job has **"Ask for a review"**; its default comes from the company setting.
- The request goes out **after the job is paid in full**, after a configurable delay (default 2 hours), by SMS in
  Automatic mode, otherwise by email. In *From technician's phone* mode the job also has a **"Send review request"**
  button that opens the text on the technician's phone.
- Each company writes its own request text (template with `{customer_first_name}`, `{brand}`, `{review_link}`, …).
- **At most one request per customer** within a configurable period (default 180 days); later jobs are skipped with
  the reason shown on the job.
- Track: scheduled / sent (when, channel, profile) / skipped (why).
- **Forbidden by Google's and the FTC's rules, and not supported by the app:** no discounts, gifts or any reward for a
  review; no review gating — never ask "were you happy?" first and send only happy customers to Google. The same
  request goes to every customer.

## 9. Non-functional requirements

- Mobile-first UI; technician screens usable with one hand.
- English UI in v1; all text strings kept in translation files for future languages.
- Localization per company as in §1.1 (currency, taxes, time zone, regional format, address format, E.164 phones).
- Security: hashed passwords, optional 2FA for every user, role-based access, rate limiting, audit log of sensitive actions.
- Privacy: customer data belongs to the company; company can export all its data (CSV); deletion on request.
- Daily database backups, off-server.
- Performance: main screens load in under 2 seconds on 4G.
- Every tenant-owned query is tenant-scoped; automated tests prove isolation.

## 10. Delivery stages

### First-run account setup

Public registration with name, email and password, signed email confirmation and a clear three-step account/company setup. A verified new user creates their first company as Owner and gets a first brand automatically. Existing memberships keep their company access; suspended accounts cannot bypass restrictions through setup. Two-factor enrollment is optional for all roles, including the platform super-admin. Email confirmation is separate from optional authenticator-based 2FA; email login codes are a future task.

### Stage 0 — Foundation

Project skeleton, auth, companies (tenants), brands, users & roles, super-admin panel, tenant isolation tests, deployment pipeline to VPS.

### Stage 1 — MVP (owner can run the business on it)

Customers, properties, appliances, jobs & statuses, calendar & dispatch, technician PWA view, photos, signatures, estimates, invoices, Square payments, Twilio SMS (automated messages + inbox), review request toggle, price book, basic reports.

### Stage 2

Parts orders, manufacturer warranty claims, online booking page, click-to-call, Collector and Subcontractor roles, payment reminders, AR aging.

### Stage 3

Strata features & inspection PDFs, service plans & recurring jobs, QuickBooks, advanced reports, extended onboarding flow for new companies.

Tenant subscription billing (§11) is a separate task scheduled before the Phase B launch.

### Later (not in scope now)

AI features (call answering, plate OCR, estimate drafting), native mobile apps, payroll.

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
