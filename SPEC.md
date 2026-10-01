# Field Service App for Appliance Repair — Technical Specification

Version 0.1 · October 2026 · Owner: Alex (Doctor Appliance, Greater Vancouver, BC)

## 1. Goal

A field-service management app (Housecall Pro–style) built specifically for appliance repair companies.

- **Phase A:** used by the owner's own companies (Doctor Appliance, Duct Works).
- **Phase B:** sold as a monthly subscription to other appliance repair companies.

Therefore the system is **multi-tenant from day one**: every company is an isolated account, and every company can run several brands.

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
- Company-level settings: timezone, currency (CAD default), tax rates, invoice numbering, business hours.
- Subscription billing for tenants is NOT built in v1, but the data model includes `plan` and `subscription_status` on Company.

## 4. Brands

A company can have multiple brands (e.g. Doctor Appliance and Duct Works; a friend with 3 repair companies).

Each brand has:

- Name, logo, colors, website, address(es)
- Its own phone number for SMS (Twilio) and email sender identity
- Its own invoice/estimate template, footer, terms, tax/business registration numbers (GST number etc.)
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

- **Customer:** type (residential / commercial / property manager / strata), name, phones, emails, notes, tags, lead source.
- **Property:** address (Google Places autocomplete, geocoded), access notes, gate/buzzer code. A customer can have many properties. A strata building can have many **units**.
- **Appliance:** property, type (washer, dryer, fridge, range, dishwasher, etc.), brand, model number, serial number, photo of the rating plate, install/purchase date, warranty info, full repair history.
- **Job:** brand, customer, property, appliance(s), job type (repair / warranty / maintenance / installation / vent cleaning / inspection), source, assigned user(s), scheduled window, estimated duration, status, notes, photos, checklists, signatures. A job can have several **visits** (diagnosis visit → parts → repair visit).
- **Estimate**, **Invoice**, **Payment**, **Price book item**, **Parts order**, **Warranty claim**, **Service plan**, **Inspection report**, **Message**, **Attachment**, **Activity log**.

### Job statuses

`new` → `scheduled` → `on_the_way` → `in_progress` → `waiting_for_parts` → `completed` → `invoiced` → `paid`, plus `cancelled` and `on_hold`. Every status change is logged with user and time.

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
- Configurable taxes per company (BC: GST and PST; rates are settings, not hard-coded).
- Send by SMS/email as a link with PDF.
- **Square integration (required):** each company connects its own Square account via OAuth. Money goes directly to the company.
    - Online: Square payment link on the invoice page.
    - On site: QR code / payment link on the tech's screen; investigate Square Point of Sale API hand-off from the PWA to the Square app for card-present payments.
    - Payments recorded back to the invoice automatically (webhooks).
- Manual payment types: cash, cheque, e-Transfer.
- Automatic reminders for unpaid invoices; aging report.
- **Review request toggle on invoice sending** (see §8).

### 7.7 Customer communication

- Two-way SMS inbox per brand (Twilio, local Vancouver number per brand).
- Automated messages (each can be turned on/off and edited per brand): booking confirmation, reminder the day before, on my way + ETA, parts arrived, invoice sent, payment received, payment reminder.
- Click-to-call from the app using the brand number (Twilio voice) — so remote staff call from a local number.
- All messages stored on the customer and job timeline.

### 7.8 Appliance-specific features (not in Housecall Pro)

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

### 7.14 Integrations

- Square (required, v1)
- Twilio SMS + voice (v1)
- Google Maps / Places (v1)
- Transactional email provider (v1)
- QuickBooks Online (later)
- AI features (later, not in scope now)

## 8. Google review requests

- A company can have **several Google profiles** (e.g. one brand with 4 locations). Each profile: label (e.g. "Surrey"), direct review link, brand, optional address.
- When sending an invoice there is a checkbox **"Request a Google review"** — **off by default**, the user decides per job.
- When checked: dropdown to pick which Google profile; default = the brand's main profile. Optional setting: auto-select the profile nearest to the job address.
- Each company writes its own review request text (editable template per brand, with placeholders like `{customer_first_name}`, `{tech_name}`, `{review_link}`). The text can be edited per send.
- Track: requested at, which profile, link clicked.
- No discounts or rewards are tied to reviews.

## 9. Non-functional requirements

- Mobile-first UI; technician screens usable with one hand.
- English UI in v1; text strings kept in translation files for future languages.
- Security: hashed passwords, 2FA for Owner/Admin, role-based access, rate limiting, audit log of sensitive actions.
- Privacy: customer data belongs to the company; company can export all its data (CSV); deletion on request.
- Daily database backups, off-server.
- Performance: main screens load in under 2 seconds on 4G.
- Every tenant-owned query is tenant-scoped; automated tests prove isolation.

## 10. Delivery stages

### Stage 0 — Foundation

Project skeleton, auth, companies (tenants), brands, users & roles, super-admin panel, tenant isolation tests, deployment pipeline to VPS.

### Stage 1 — MVP (owner can run the business on it)

Customers, properties, appliances, jobs & statuses, calendar & dispatch, technician PWA view, photos, signatures, estimates, invoices, Square payments, Twilio SMS (automated messages + inbox), review request toggle, price book, basic reports.

### Stage 2

Parts orders, manufacturer warranty claims, online booking page, click-to-call, Collector and Subcontractor roles, payment reminders, AR aging.

### Stage 3

Strata features & inspection PDFs, service plans & recurring jobs, QuickBooks, advanced reports, tenant subscription billing, onboarding flow for new companies.

### Later (not in scope now)

AI features (call answering, plate OCR, estimate drafting), native mobile apps, payroll.

## 11. Open questions

- Product name and domain.
- Subscription billing provider for tenants (Square Subscriptions vs Stripe).
- Object storage provider.
- Transactional email provider.
