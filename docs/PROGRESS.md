# October 9 — support access 403, trip navigation, My Jobs empty days

- **Support access 403**: "Return to admin" pressed twice (or in an old tab) and admin pages left open while support
  access is on no longer show a bare 403: they go home with a hint to press "Return to admin". "Support access" is
  no longer offered for platform admins who are also company members (it could not work for them).
- **Platform → Companies** in the menu is shown only to platform admins (Alex), by design; company owners never see
  it, including during support access.
- **+ Trip → Navigate**: saves the trip and opens Google Maps turn-by-turn to the store; "Save only" for a trip
  already driven. "+ Trip" on Mileage opens the same sheet; "Enter by hand" opens the full form.
- **My Jobs** lists only the person's own jobs. For owners/admins/dispatchers each day now says how many jobs others
  (or nobody yet) have that day, with a Calendar link, so an empty day is not mistaken for an empty schedule.
- Tests: double Return to admin, old admin tab, no support access for platform admins, others-on-day count (tenant
  isolated, office only); browser scenarios for Navigate and Enter by hand.
- **Unassigned jobs in My Jobs** (owner's answer: yes): owners/admins/dispatchers see the day's jobs nobody is
  assigned to yet, marked "Not assigned"; the Calendar line then counts only other people's jobs. Technicians and
  the Completed tab still show only the person's own work.
- Next: owner's feedback from the phone.

# October 8 (second round) — My Jobs by day, Completed and + Trip

- My Jobs tabs: the day with ‹ › arrows ("Today", other days as "Oct 9"), **Completed** (jobs completed, invoiced or
  paid in the last 30 days) instead of Upcoming/Recent, and a teal **+ Trip** button.
- **+ Trip**: type a store or address (Google suggestions), Save. The start is the phone's GPS position (asked when
  the sheet opens); without GPS, or when pressed already at the destination (under 300 m), the last point of the day
  is used (the latest stop, the job started last, or the start address). Google measures the road distance; the
  next "Start job" trip is counted from this stop. The trip is in Mileage as a parts store run, editable there.
- Tests: day navigation, Completed, quick trip with GPS / without GPS / on arrival, next job from the stop; browser
  scenarios for the tabs (390 px width) and + Trip.

# October 8 — owner's list: simpler invoice, taxes per line type, Mark as paid, no Visits, dossier, profit, mileage

1. **New invoice / estimate form**: one dashed "Add item" opens a bottom sheet (price book search with price and
   L/P/M type letter; "Or custom item" Labor / Part / Material). Items are compact rows (letter, name, "qty × price",
   amount) that open on tap with a −/+ stepper, price, taxes, warranty, "+ Note", Remove and Done; a new item opens,
   the rest stay folded. Dates fold into "Oct 8, 2026 · Due on receipt · Edit"; "+ Add discount" is a link; totals
   list each tax; pinned Cancel + "Save · total". Line data and types unchanged.
2. **Taxes per line type** (Settings → Taxes, "Charged on" Labor / Part / Material): new lines get the default taxes
   of their type; a tax can be ticked on one line (e.g. PST on a labor line). Rates without the setting apply to
   every line as before.
3. **Materials carry no warranty**: hidden on the form and price book, not printed, not counted (old values kept).
4. **Mark as paid** on the job page (and invoice): Cash / Card / E-transfer (Bank transfer outside Canada) / Crypto /
   Other / Check, amount = balance, date. Partial payments keep the balance visible. Paid in full → "Send Google
   Review request?" → "Complete job" in one tap (or "Finish job" while it is under way).
5. **Completed jobs** are grey in lists; the calendar hides them unless "Show completed" (remembered per device).
6. **No Visits in the interface**: a Schedule block on the job (time, Change time / Remove time, "Schedule a new
   time" for a second trip on the same job); "visit" wording replaced by job/time everywhere. Visits stay in the
   database and keep driving the calendar and the field steps.
7. **Customer dossier**: totals (jobs, paid, owes) and a job history (date, appliance, work done, amount,
   Paid / Partly paid / Unpaid, links to job and invoice), next to contacts, addresses, notes and appliances.
8. **Profit** in Reports for Day / Week / Month / Year: revenue without taxes − parts/materials cost − card fees −
   expenses (price + non-recoverable tax) − mileage at the company rate, margin %; items without a cost listed.
   The Owner's totals include everyone's private costs; an Admin's stay hidden when they would reveal them.
9. **Mileage log** (menu → Money and reports → Mileage): trips to customers are logged when a job is started
   (previous job or the person's start address → job address, distance from Google Distance Matrix in the
   background, editable), "+ Trip" for stores/suppliers/other, month and year totals, CSV of month/year. Company
   settings: distance unit (km/mi) and rate per unit.

- Tests: new backend tests for every item (taxes per type, warranty, mark as paid, dossier, mileage, profit,
  tenant isolation of trips) and browser scenarios (invoice form, tax per type, mark as paid, calendar, no visits,
  dossier, mileage, profit). 863 backend tests pass (the local-only deployment check aside); 67 browser scenarios pass.
- Next: Stage 10 — online booking bot (plan and database schema sent for the owner's approval first).

# October 6 — SMS right under Call/Navigate, text or call any number

- Job page: the Messages (SMS) block now sits right under Call and Navigate; Appliances come after it.
- SMS Inbox is now a messaging center: **New message** takes any phone number (read in the company's country,
  stored in E.164) and opens a conversation without booking a customer first. The conversation header has a **Call**
  button (tel: link). A number that belongs to one customer shows that customer; a number nobody has is texted as it
  is (office-only message without customer or job). Shared numbers still need review.
- Automatic SMS mode sends from the company number (quiet hours, US A2P 10DLC). In the other modes the composer
  opens the employee's own messages app with the text ready ("Text from my phone").
- A STOP from a number without a customer blocks further texts to it (also re-checked before a queued text goes out)
  until START.
- Who: Owner and Admin (a salesperson gets the Admin role). Brand-limited members cannot open a number whose texts
  belong to a hidden brand.
- Tests: new conversation, unlinked texting, customer lookup, STOP/START, queued STOP, mode, validation, tenant
  isolation, hidden brand.
- The Telnyx/mockup UI branch (ccr-8b80e0ee-yc44tp) was installed on the server but never merged into main;
  it is now merged together with this work, so the server can fast-forward to main again.
- Next: a dedicated Sales/Collector role limited to the inbox (Stage 2), and recording calls/texts made from the
  employee's own phone for numbers without a customer.

# October 5 (sixth round) — temporary sign-in without a password, Jobs as home

- At the owner's request, sign-in can be switched off for now: with AUTH_AUTO_LOGIN_EMAIL set in the server .env,
  every visitor is signed in as that account (no password, no two-factor code) and lands on Jobs. Anyone who has the
  address sees and changes that account's data. Remove the line (and refresh config) to bring sign-in back.
  Inactive or unknown accounts are never signed in this way.
- Home is now Jobs: "/" and sign-in open Jobs for Owners/Admins; technicians, who may not see Jobs, go to My Jobs.
- Tests: AutoLoginTest; login/home expectations updated.

# October 5 (fifth round) — screens exactly as the mockups

- Header as on the mockups: deep navy with square blue buttons. Mockup screens draw their own header (ScreenHeader):
  My Jobs (menu, "My Jobs", "Today, <date>", search, filters), Jobs (same), Finish Visit (back, "Finish Visit",
  "#number", ⋯ with "Open the job"). Other screens keep Menu + Book customer. The backlog bar is hidden on mockup screens.
- Phone tabs: My Jobs · Map · Messages · More.
- My Jobs: coloured count circles with the arrow that opens their legend (tap filters); search filters the cards.
- Finish Visit: 80 px face, Call/SMS beside the name, "#unit • Buzzer: code", appliance with View details,
  "Work completed / Notes (optional)" box with a 500-character counter, Photos with "+ Add photo" and crosses,
  Job result with the check on Completed and arrows on the others, Finish Visit button with the white check.
- DESIGN.md: the header colour follows the mockups (navy), replacing the earlier blue-to-black gradient.

# October 5 (fourth round) — Finish visit screen

- Finish visit is now its own screen (/visits/{visit}/finish, approved mockup) instead of a dialog: back button,
  "Finish visit #number", customer card (face, name, phone, address, unit/buzzer, Call and SMS), appliance cards
  (picture, type, brand + model, View details), one free-text box "Work completed / notes" (saved as the job's work
  notes; no checkboxes, no separate Notes), photos, Job result (Completed / Part needed / Not completed with
  declined / unable to repair / no charge, reason, diagnosis invoice and callback refund) and one Finish visit button.
- The job page's Finish visit button opens it; old links with ?finish=1 redirect to it. After finishing, or for a visit
  that is not under way, the screen returns to the job page. Closing a job without a visit (office) keeps the dialog.

# October 5 (third round) — the Jobs list uses the mockup too

- The owner opened Jobs (the office list), which still had the old rows and a wall of filters. Jobs, the backlog and
  the customer's jobs now use the same card as My jobs (mockup): face, number + status, time, name, address,
  "Appliance • problem", picture, Navigate · Call · View job; Book customer at the end.
- Jobs: search and filter are icon buttons next to the title; the filters (and New job / Deleted jobs) fold away until
  tapped. Coloured count circles per status sit above the list; tapping one shows only that status.
- Still different from the mockup: the shared dark header keeps Menu + Book customer instead of the page title,
  search and filter buttons inside it.

# October 5 (second round) — My jobs as on the mockup, address on Edit, no checklist, estimate lines

- My jobs follows the approved mockup: tabs Today / Upcoming / Recent with counts, "Today (N)", cards with the
  60 px face, number + status, time, name, address, "Appliance • problem" (Multiple / Installation as on the mockup)
  and the appliance picture on the right, buttons Navigate · Call · View job, Book customer at the end. Visit steps
  (On my way / Start / Finish) are on the job page. Phone tabs: Today · Map · Messages · More (Map opens the calendar
  map). Not done: the coloured count circles at the top of the mockup (their meaning is to be confirmed).
- Faces: the whole name typed into the first-name field ("Oleksandr Mykhailychenko") now finds the face by its first
  word; quick booking stores "Name Surname" as first and last name.
- Edit job: address, unit, city and postal code of the job's place can be added or corrected (Google suggestions).
- Checklist removed from the job page and the menu (Work done notes stay). Stored checklist data is kept.
- Estimate/invoice lines: every change now starts from the latest lines, so a quick second change (e.g. the default
  warranty following a typed price) can no longer overwrite a price typed a moment earlier. The exact erasing seen on
  the owner's Android phone did not reproduce in Chromium; to be rechecked on the phone after deployment.
- Tests: 765 backend (the local-only deployment check aside) and 46 browser scenarios passed locally.

# October 5 — owner feedback on the job page, avatars, appliances, menu, signature, editing

- Job page on phones: the visit buttons (On my way, Start job, Finish visit) were hidden under the bottom tabs. Screens
  with a pinned action bar (job, invoice/estimate view and form) now hide the tabs, as DESIGN.md asks for inner screens;
  the bar is white with 56 px buttons.
- Avatars: common Latin spellings of Ukrainian/Russian names (Oleksandr, Dmytro, Olena, Iryna…) now get the cartoon
  face; a name still unknown shows initials on the light blue circle instead of a faceless icon. Job page and customer
  page use the 60 px avatar.
- Appliances are picked from image tiles (the mockup's stainless pictures, 3 per row) when adding an appliance on the
  job, on the customer, on the full job form and in Book customer (optional; tap again to clear; an appliance of that
  type already at the address is linked, not duplicated). Appliance lists show the picture. New type Wine cooler; types
  follow the mockup order. Multiple / Installation tiles are still not in code (decision 3).
- Menu: Main keeps the everyday sections (Book customer, My jobs, Calendar, Jobs, Customers, Invoices, Messages);
  Dashboard, Reports, Cash and Expenses are under "Money and reports", company setup under "Company settings" — both
  collapsed until tapped (open while one of their pages is open).
- Signature: the job page no longer asks for it (it only shows one already taken). "Get signature" is on the invoice
  page, for whoever may work on the job, until the invoice is void.
- Edit job: the customer's first/last/company name and main phone can be corrected right on the job form (Owner/Admin,
  the job's own customer only, phone validated and stored in E.164). The service address list shows "Address not
  added yet" instead of an empty choice.
- Tests: new JobPageFeedbackTest (customer correction, isolation across companies, technician denied, wine cooler and
  pictures, booking with an appliance tile, names, signature on the invoice) and browser scenarios
  job-page-feedback.spec.ts (buttons not covered on phone, tabs hidden, image tiles, name correction, menu groups).
  Locally: 760/761 backend tests (the deployment-check test fails here and on unchanged main because this container has
  no production services) and 46 browser scenarios passed.
- Manual check on a phone: open a job assigned to you today → On my way / Start job are visible at the bottom; Edit →
  change the name or phone → Save; Add appliance → tap a picture; Menu → Money and reports opens on tap; open the
  invoice → Get signature. The server must install the new main to show these changes.
- Still to do from the mockups: Finish visit with 4 options, two contacts per customer with a paired avatar, team
  avatars, "Scan rating plate" and the Manufacturer warranty block on the job page, invoice/payment/calendar restyle.

# October 5 — sign-in recovery command

- `php artisan app:reset-password EMAIL [--generate] [--without-2fa]` sets a new password without email, switches the
  account back on, signs out other devices and reports company memberships (unchanged). Documented in
  SERVER_HANDOFF.md. The server must install the new main before it can be used.

# October 4 — volumetric design in the app, My jobs as home, visible booking address

- Owner, Admin and Technician land on My jobs after sign-in and from `/`; My jobs is first in the menu and the logo link.
- Volumetric style from DESIGN.md applied app-wide through the shared components: raised gradient buttons
  (primary blue, secondary, soft blue, danger), cards, sunken inputs/selects, dark gradient header with a light rounded
  sheet, status badges with colour + icon + text, cartoon customer avatars (man/woman images, icons otherwise).
- Phones get the bottom tabs Today · Calendar · Messages · More (only the sections the member may open).
- My jobs cards follow the mockup: 60 px avatar, number + status, name, time, address, appliance; buttons Go, Call and
  the next step (On my way → Start → Finish visit). On my way also opens the technician's messages app in
  technician's-phone SMS mode; Finish visit opens the finish dialog on the job page.
- Book customer: phone first, name, address with Google suggestions, unit, city and customer note are always visible;
  a separate "What needs fixing" block; one-tap day and arrival-window chips and technician chips; exact times stay.
- Not yet done from the mockups: Job page, Finish visit 4 options, appliance image tiles, invoice/payment/calendar
  restyle (product decisions 1–5 below). The server must install the new main to show these changes.

# October 3 login unblock — merged; VPS installation pending

- PR #12 is merged into main at b3a96c2ad0009a591466e1b9c18f9c122b6b8a07. Its tree matches validated head 3fc1682d327be0f3fe191592e9487d400c647f64; that full workflow passed 740 backend tests and 40 desktop/mobile browser scenarios. No application code changed during this integration.
- SERVER_HANDOFF.md now uses main and explains installing the mail-disabled candidate, refreshing configuration and reloading the actual PHP-FPM service. Resend cannot unblock an old installation when mail is disconnected. VPS access is absent here, so the server operator must confirm actual deployment and direct login.

# October 3 mail/admin supplement — code validated; server acceptance pending

- Operator-managed mail readiness removes confirmation walls while delivery is unavailable, without verifying emails or changing tenant/role permissions. Password recovery and resend stop promising unavailable mail; new staff/Owners can receive manual initial credentials. Enabling tested mail restores confirmation.
- Empty invoice states distinguish no invoices from filtered results. Administration explains access versus billing and unassigned plans, uses singular counts, separates direct login from support access and labels support/audit times in the company zone. Logout closes support access; unknown historical ends remain explicitly unknown.
- Tested code c350d177aac10213e43750c16ccc4fdcee5934ac passed build, deployment script syntax, PHP style, frontend formatting/lint, TypeScript, all 740 backend tests (5,984 assertions) and 40 desktop/mobile browser scenarios: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37146504326. This includes four dedicated mail-disabled scenarios; mobile screenshots were inspected. New comments are recorded in FEEDBACK_MAIL_ADMIN_2026_10_03.md. PR #12 is still unmerged; SERVER_HANDOFF.md pins this candidate. Server installation and real delivery checks remain pending with the server operator.

# October 3 follow-up — code validated; server acceptance pending

- Short Book customer form: name/phone/time, optional address/details, existing-customer search and visible customer preferences; saves customer/job/visit atomically. Calendar empty days and dashboard link to booking.
- New invoice opens from empty/populated lists, picks a visible job or enters a new customer, then opens prices. No empty invoice is saved by continuing. New job click is covered in browser checks; Save requires customer details.
- Missing-brand setup is explained, company punctuation is preserved, and Invited requires an actual pending invitation/reset token rather than an unused account alone.
- Tested code c02a95db7edaf618787c03340bd216c77d49a9ad passed build, PHP style, frontend formatting/lint, TypeScript, deployment syntax, all 719 backend tests (5,752 assertions) and 36 desktop/mobile browser scenarios: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37143711689. New job works through its actual button on both viewports; mobile booking/invoice screenshots were inspected. The new report is FEEDBACK_FOLLOWUP_2026_10_03.md. PR #12 remains unmerged; production needs the tested candidate in SERVER_HANDOFF.md. Server access is not available here.

# Feedback through October 3, 2026 — code validated; server acceptance pending

- Direct customer pricing with separate author-private purchase costs for parts/materials; computed differences. Markup settings and automatic price changes removed. Privacy covers document editing, price history, conversion/revision, PDF/public output, CSV and aggregate profit redaction.
- Searchable UTC-offset/city/country time zones retain IANA/DST behavior. Visible Menu, persistent Book customer, calendar booking with date and first-visit defaults.
- Working sign-in and remembered sessions, technician landing on My jobs, separate platform workspace/support entry without a reason prompt. Platform admins can explicitly join their own working company; no automatic grant to arbitrary tenants.
- Members is accessible to Owners/Admins; Admins manage technicians without promoting themselves or changing Owners. Transfer all unfinished assigned jobs, including waiting for parts without an open visit, and replace a company-only technician login with a new password; preserve former identities/history and invalidate old sessions.
- Code commit f2e4729e66f918a7aab9e5334b218396f977c26e passed build, PHP style, frontend formatting/lint, TypeScript, deployment shell syntax, all 710 backend tests (5,615 assertions) and 28 desktop/mobile browser scenarios: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37139569448. Mobile booking, time-zone and private-price screenshots were reviewed. Server deployment and live checks remain separate; SERVER_HANDOFF.md pins this tested code. FEEDBACK_2026_10_03.md records the seven supplied comments through October 3; future reports cover new comments only.

# Public signup and first-run usability — code validated; server acceptance pending

- Public account registration, queued signed email confirmation, throttled resend and account/company setup steps. Unverified users cannot enter company data. Verification through an invitation/password reset remains supported.
- Verified first-time users create their own company, become its Owner and receive a first brand. Company creation is transactional and serialized per user; request-supplied roles, owners, company IDs and plan settings are ignored. Existing inactive/suspended memberships cannot use setup to bypass access controls.
- Mandatory two-factor enrollment is removed for every role; existing enabled two-factor challenges and recovery codes remain. Authenticator-based 2FA is optional in Security settings. Email login codes are a future task.
- First dashboard offers real links to customers, jobs, team and taxes instead of an obsolete Coming soon message. Auth/setup screens have visible branding, step progress and mobile touch targets.
- Correcting an email address sends a new confirmation and returns directly to the confirmation screen.
- Tested code commit aedf2a89a9023e277b5eaed41c23d9cab84e699a passed build, PHP style, frontend format/lint, TypeScript, deployment script syntax, 692 backend tests (5,413 assertions) and 22 desktop/mobile browser scenarios: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37135218373. Real SMTP delivery and server deployment remain to be verified by the server agent; instructions are in SERVER_HANDOFF.md.

# Progress

Status of the delivery stages from [`SPEC.md`](../SPEC.md) §10. Updated at the end of every task.

## Stage 0 — Foundation ✅ Done

- Laravel 13 + Inertia React + TypeScript + Tailwind skeleton, Pest tests, PostgreSQL.
- Auth (Fortify): login, password reset, optional 2FA.
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

## Stage 1 — MVP code validated; server acceptance pending

### Office SMS inbox and desktop/mobile browser checks — CI passed

- Shared SMS Inbox for Owner/Admin: phone-based conversations, search, unread filter, pagination,
  incoming/outgoing bodies and delivery status, customer/job links and exact-number replies.
  Unknown numbers stay visible; a uniquely registered contact enables replies. Shared or removed
  contact numbers require review. STOP, US registration, quiet hours and company SMS mode apply.
- Personal read acknowledgements cover only rendered incoming messages. Prefetch/GET does not clear
  a badge; another employee keeps their own unread count. Sidebar polling refreshes the badge.
  Threads, acknowledgements and sends follow tenant and brand/job visibility; technicians retain
  their assigned-job composer and cannot open the shared office inbox.
- Archived-job correspondence remains visible without broken job links. Deleted brands retain their
  membership restriction, preventing an empty brand list from accidentally granting company-wide access.
- New message_reads migration and role/tenant isolation tests. Code commit
  2a891aa0d76fed8d80f0ad12727e3e8938e2038f passed build, PHP style, frontend formatting/lint,
  TypeScript, deployment shell syntax, all 674 backend tests (5,276 assertions), and 20 desktop/mobile
  browser scenarios with real password/TOTP login and active CSRF protection:
  https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37090807431.
  The following documentation-only commit records the result.
- Playwright exercises actual password/TOTP login on PostgreSQL with isolated testing fixtures and
  a local SMS stub. Desktop and phone checks include customer context/booking, expenses/receipts/taxes,
  inbox replies, public documents/PDF, technician access, photos/signatures, core screens and dark mode.
  BROWSER_TESTING.md describes reproduction and the screenshot/trace artifact.
- Manual check: send a controlled SMS reply, open the inbox as two office users, verify separate unread
  badges, reply to a secondary contact, and test STOP/quiet hours. Check old conversation pagination,
  an unknown number, restricted brands and another tenant. Physical-device camera/visual checks,
  Maps, real mail/SMS, Square callbacks, backups and server installation remain pending.

Shared SMS Inbox is part of Stage 1 in SPEC.md; the earlier Next entry deferring it to Stage 2 was incorrect.

### Customer context, name icons, estimate follow-ups and calendar map — CI passed

- **About the customer** reuses existing customer notes, preserving all earlier entries. Office staff edit it in
  the customer profile or during the first booking. It appears prominently when booking and on assigned jobs,
  alongside the customer's earlier jobs, estimates, invoices and message history. Booking history follows
  the user's brand access. Job-linked message history follows job visibility; customer-level correspondence is
  office-only. Technicians and other companies cannot browse unrelated customers. Removed the obsolete Messages placeholder.
- Optional name-based decorative icons use a local dictionary of 16,589 Latin names from 45 Faker locales,
  pinned to MIT-licensed FakerPHP v1.24.1. Unknown/conflicting names remain neutral; automatic, neutral,
  man and woman choices are saved per customer. Business customers use a business icon. No customer names
  are sent to a third-party guessing service. This is an icon suggestion, not a gender record.
- Company-configured estimate follow-ups: blank disables them, 1–90 days enables one attempt per explicitly
  sent estimate. Only unanswered, unexpired estimates on open jobs qualify. Local reminder hour, SMS opt-out,
  quiet hours and email fallback apply. Row locking prevents duplicate enqueueing; blocked attempts are visible
  in message history. A new explicit send resets the delay; revisions start without an old reminder stamp.
- Calendar Map shows the selected local day's visits in order, filtered by person, with numbered markers and
  links to visit details. Visits without saved coordinates remain in the list. Company/brand permissions apply.
  Maps and Places share one bounded loader. Directions remain available without an embedded map; long map
  routes are split into mobile-compatible sections. The optional GOOGLE_MAPS_MAP_ID selects a production
  Google map style (the demo map ID is the default for testing).
- New migration adds avatar_style, estimate_followup_days and followup_processed_at. Existing customers
  default to automatic icons; existing companies keep follow-ups disabled.
- Build, PHP style, frontend formatting/lint, TypeScript, deployment script syntax and all 655 backend tests
  (5,049 assertions) passed on code commit `39e560e`: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37086077377.
- First-server preparation adds app:deployment-check, serialized deployments, pinned commit selection,
  maintenance retained on failure, HTTPS health/login probes and docs/SERVER_HANDOFF.md. Web and queue processes
  must share a runtime user for private files; the setup also documents receipt upload limits.
- Physical-device/visual, Google map, outbound mail/SMS, payment callbacks, backup restore and server checks
  remain pending. No SSH credential is attached to this workspace and no deployment has occurred.

### Item taxes and employee expense view — CI passed

- Unlimited named company taxes, active/default switches and independent subsets per estimate/invoice item.
  Null inherits document taxes, an empty list is exempt. Existing documents retain their calculations and snapshots.
- Discounts, compound rates and inclusive pricing use each item's selected taxes; edits, conversion, revisions
  and online optional-item approval preserve the selections. Item tax names appear in the app, public page and PDF.
- Expenses select named receipt taxes with calculated suggestions and editable actual amounts. The server sums
  selected amounts and retains historical names/rates; old undivided taxes remain editable. CSV includes a breakdown.
- Office employee filter and price/tax/total rows cover all matching expenses across pages, split by currency.
  Former employees remain available in the ledger filter and CSV after their team membership is removed.
  Technician lists, receipts, exports and category totals remain limited to their own entries; no global counter.
- Migration adds nullable item tax selections and receipt tax snapshots. No new environment variables.
- Manual check: create six named rates; invoice labor with one and a part with two; disable one; compare saved totals,
  PDF, online optional approval, conversion and revision. Add an expense with multiple taxes and override one amount;
  filter and export by employee; check technician access and narrow-screen layout.
- Build, PHP style, frontend lint/format, TypeScript and all 637 tests (4,882 assertions) passed on commit
  `aa0d364`: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37072738948.
- Manual narrow-screen, receipt picker and end-to-end visual validation remains pending. PHP/dependencies are
  unavailable locally; server verification ran in GitHub Actions.

### Business expenses — CI passed

- Separate Business expenses navigation for Owners/Admins and technicians. Custom shared categories can be
  created from the ledger or inline while adding an expense; creators/the office can rename or archive them.
- Expense date, description, merchant, price before tax, actual tax amount, notes and receipt photo/PDF. Live total
  on the form; price/tax/total columns beside each category for the selected period, with no global counter.
- Dates and search filters, a paginated ledger and CSV export. Category sums cover all pages and keep currencies
  separate. New entries use the company currency; edits preserve their original currency and decimal precision.
- Saving an expense opens the month of its date, so backdated receipts are immediately visible.
- Office sees company expenses; technicians see/manage/export only their own records. Uploads and receipt routes
  are private, tenant-scoped and permission-checked. No job/invoice relationship or effect on job profit.
- Edits/removal/category changes are audited. Removal is a soft delete; receipt originals remain on private
  storage after removal or replacement. Receipt upload limit is 15 MB, images/PDF only.
- Tests cover amounts/taxes, zero/three-decimal currencies, category creation/archival, history, uploads, retention,
  tenant/role access, pagination, dates/search and CSV. Migration adds business_expenses and
  business_expense_categories; no environment settings required.
- Build, PHP style, frontend lint/format, TypeScript and all 623 tests (4,697 assertions) passed on commit
  `0ef332a`: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37069134254.
- Manual check: create Fuel/Lunches/Tools, enter a price and tax with a receipt, compare category columns, change
  the period, export CSV and check as a technician. Narrow-screen and camera/file-picker validation is pending.

### Unfinished jobs queue — CI passed

- Persistent compact "Not completed jobs" bar on tenant application screens, including a zero counter and a
  direct overdue shortcut. The count refreshes with every Inertia response, including partial navigation.
- Date-independent paginated queue: overdue visits and work needing scheduling first, then waiting for
  parts/customer, on hold and scheduled/in-progress jobs. One reason and one count per job; oldest jobs first
  within a group. Search and reason filters do not change the global counter.
- Future appointments remain in the total. An upcoming return visit takes precedence over a stale old visit
  for the scheduled reason and displayed arrival window. Completed/cancelled visits cannot keep a job scheduled.
- New logged "Waiting for customer" status, available in the office status selector. It pauses visit work;
  scheduling a new visit resumes the job. Closing/cancelling removes jobs; reopening brings them back.
- Both the queue and counter enforce company/brand access and technician assignment. Unsupported roles and
  public/platform pages receive no queue data. Closed/billed/paid/cancelled/deleted jobs are excluded.
- Tests cover old dates, waiting after diagnosis, multiple visits, future returns, closure/reopening, pagination,
  partial reloads, role/brand permissions and company switching. No database migration needed for this feature.
- Build, PHP style, frontend lint/format, TypeScript and all 596 backend tests (4,458 assertions) passed on
  commit `54d222f`: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37066318784.
- Manual check: leave yesterday's visit unfinished; open the top bar from My Jobs or the calendar; try each
  filter, schedule a return, set Waiting for customer, close and reopen a job, then check as a technician and
  after switching companies. Mobile visual validation remains pending.

### Bolt technician UI — draft, validation pending

- My Jobs: rounded cards, larger touch targets, active-tab accessibility, wrapping time/status rows,
  and full-width contact actions when only one contact method is available.
- Job Detail: centered mobile layout, larger customer summary, rounded action buttons, and safe-area spacing
  for the fixed visit action bar.
- These changes are in the `chatgpt/bolt-ui` draft branch. They are not released or marked complete.
- Local runtime checks remain unavailable (PHP missing; sandbox network access blocks dependency installation).
  Build, formatting, lint, TypeScript and backend tests passed in GitHub Actions on commit `0f538d1`.
- Manual validation still required: narrow mobile screens, long names/addresses, every visit state,
  iPhone home-indicator spacing, navigation/call links, dark mode and keyboard focus.
- Categories and business reports are implemented below. Stage 2, Stage 3 and platform subscription billing
  are not complete.

| #   | Task                                                                         | Status  |
| --- | ---------------------------------------------------------------------------- | ------- |
| 1   | Customers, properties (manual address), appliances (§6, §7.1)                | ✅ Done |
| 2   | Jobs & statuses, visits, My jobs (§6, §7.3 w/o calendar, §7.4)               | ✅ Done |
| 3   | Calendar & dispatch                                                          | ✅ Done |
| 4   | Technician PWA view, photos, signatures                                      | ✅ Done |
| 5   | Estimates, invoices, manual payments (§7.5, §7.6)                            | ✅ Done |
| 6   | International groundwork, payment terms, Square payments (§1.1, §1.2, §7.6)  | ✅ Done |
| 7   | PDF + email sending of documents, price book on lines, Square tips/refunds   | ✅ Done |
| 8   | SMS (3 modes, Twilio, A2P 10DLC, STOP, quiet hours) + Google review requests | ✅ Done |
| 9   | Online estimate approval (signature, options, expiry, deposit) + Places      | ✅ Done |
| 10A | Estimate revisions, deleting jobs, outcomes, visit types, strict arrival     | ✅ Done |
| 10B | Warranty & callbacks, refunds, costs & profit, no charge, cash               | ✅ Done |
| —   | Price book: categories (parts/materials with cost and markup done in 10B)    | ✅ Done |
| —   | Basic reports (profit, callbacks, no charge, revenue and conversion)         | ✅ Done |

### Price book categories and business reports — CI passed

- Price book: optional category on every service, part and material; suggestions from the company's current
  catalogue; grouped choices on estimate and invoice lines. Blank categories remain uncategorized.
- Per-brand price-book availability: no selection means all brands. New document choices and submitted service
  IDs enforce availability. Existing document references remain valid when availability changes later. Brand
  IDs from another company are rejected. Existing price-book items remain available to all brands after migration.
- Revenue: invoices issued in the selected period, excluding void invoices, taxes and tips; settled refunds
  reduce net revenue proportionally. Totals, average invoice, brand, technician, job type and source breakdowns
  keep each document currency separate. The first assignee on the last started visit identifies the technician
  (last scheduled visit when none started); people with the same name are kept separate.
- Conversion: current estimate versions issued in the period, with a sent timestamp or customer decision.
  Approved and invoiced count as converted; unsent drafts and superseded versions do not count.
- Reports enforce company and office brand access, validate real calendar dates and reject reversed ranges.
  Removed silent truncation after 5,000 closed jobs; receipt ZIP names include only accessible jobs.
- Profit/cost reports use only the company's current currency, explicitly labeled, so legacy document currencies
  are never added together. The new revenue section reports every document currency separately.
- CI runs frontend checks, TypeScript and backend tests independently, keeping failed checks visible. Suggested
  formatting diffs are printed after tests on failures; CI never writes fixes back to the repository.
- Tests added for categories, currency separation, refunds, periods, conversion, role/brand access and tenant
  isolation. Build, PHP style, frontend lint/format, TypeScript and 581 backend tests (4,180 assertions) passed on commit `0f538d1`. Manual validation is still pending.
- Manual check: set categories in Company → Services; select grouped items on an invoice; open Reports, choose a
  period and compare invoice totals and currencies, average invoices and estimate decisions with the documents.
- Still left: the remaining Stage 2/3/billing specification tasks and manual mobile/release validation.

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
- Legacy markup columns remain for compatibility; the current UI uses manual customer prices and private purchase prices, with no markup suggestions.

Ideas for later: **stock / inventory of materials** (van stock, reorder levels, consumption per job) — out of scope
now; automatic supplier price import; cash refunds tied to cash on hand.

### October 5 site walkthrough — fixes ✅

Built on the branch with the mockup screens (`claude/ui-buttons-avatars-appliances-gocqla`), which is what the
walkthrough tested. The "On my way" text with another customer's name was not a bug (the name was changed after the
text was sent).

- **Customer icon:** tap the avatar on the customer or job page to pick Man / Woman / Couple / Automatic; the customer
  form has the same buttons. There is no "Neutral" choice (saved ones were moved to Automatic); names that fit both
  (Sasha) show initials until someone picks.
- **Second person (couple):** each phone can carry the name of whoever answers it ("Anna (wife)"); the customer form has
  "Add second person". A named second person makes the automatic icon a couple (two faces in one circle, built from
  the man and woman pictures); the job page shows that person's name with Call and SMS buttons. Stored as
  `customer_phones.contact_name` and `customers.has_second_contact` (kept in sync when phones or the first name change).
- **Appliance type on a booked job** can be corrected in "Edit appliance details" (Change → appliance tiles), by the
  office and by the technician of the job.
- **On my way** from the technician's phone now changes the status: the status change and the recorded text go in one
  request (two requests at once made the browser drop the status change).
- **Map:** the calendar says why there is no map (no key / key rejected by Google / addresses without a position).
  Optional `GOOGLE_MAPS_SERVER_KEY` places addresses typed by hand in the background; `php artisan properties:geocode`
  places addresses saved earlier.
- **Booking:** the default arrival window is the first one with at least an hour left today (otherwise tomorrow 9–11);
  past windows are disabled and a past time shows a warning. The phone field keeps only phone characters, checks the
  number after leaving the field, clears the error when corrected and offers "Use this customer" when the number
  belongs to an existing customer. A disabled Save button says what is missing.
- **My jobs search** runs on the server over all tabs and updates the tab counts; finds phones in any format, appliance
  brand and type, problem text; stays open while switching tabs; says "No jobs match …". Customer search also finds
  the appliance brand. A visit started on an earlier day and still open is only in Today (with its date), not also in
  Recent.
- **Dates and times:** "Customer since" uses the company time zone and regional format; the calendar shows times in the
  regional format (1:00 p.m.) and labels the first hour; visit length reads "about 1 h of work".
- **Costs:** labelled fields with the error under each field and plain messages; "$15.5", "CA$ 1,250.00" and "15,50"
  are accepted in all money fields (costs, invoices, payments, expenses); a cost line can be edited; removing asks
  first; Cancel closes the form.
- **Invoices and payment:** an unsent unpaid invoice is marked "Not sent yet — you can still edit it"; the job page
  shows the invoice next to the job status while the work is not finished; toasts appear at the top for 3 s; date
  fields line up; the payment window is anchored to the top, scrolls, and keeps the Record payment button visible.
- **Navigation and headers:** every section shows its name in the dark header; the Menu button is only on phones
  (desktop has the sidebar, which folds from its edge); the header has no Book customer button; Jobs has no "New job"
  in the filters (the full form is linked from the booking screen); the calendar has one "Book on this day" button and
  no layout jump. Phone tabs: My Jobs · Calendar · Map · Messages · More, with Map lit only on the map view.
- **Texts:** status circles on Jobs and My jobs show their names; date filters are labelled From / To; "Change status"
  is a button; "Set repair warranty" replaces "Review warranties"; Dashboard is "Getting started" under Company
  settings and lists missing setup (no taxes; review requests on without a Google profile); the job page warns when a
  review request cannot be sent; Reports explains which dates each figure uses; expenses and the SMS inbox use correct
  empty/scope texts; plural forms ("1 job", "1 customer").
- **Services:** one folded line per service (name, kind, price); labels above the fields; the Save bar appears when
  something changed.
- **File fields** (receipts, rating plate, logo, cash receipt photo) are buttons in English instead of the browser's
  own field.

Tests: `tests/Feature/Feedback/*` (icon, geocoding, My jobs search/tabs, booking clock, On my way, cost lines and money
input); browser tests updated for the single menu, Book on this day, icon buttons and regional times.

Not changed (data or decisions for the owner): the company name "Doctor Appliance." is typed with a period (Company
settings); Team has two owner accounts (Alex and Oleksandr) — deactivate one if both are you; taxes and the Google
review profile must be added in settings. An invoice "Draft" status was not added: invoices stay editable until paid,
and "Not sent yet" marks them — say if a real draft step is wanted.

### SMS through Telnyx ✅

- `TelnyxProvider` (API v2): one platform API key; each company gets a messaging profile (texts only to its country)
  and a local number ordered onto it; sending with a per-message delivery webhook; incoming texts and delivery
  reports on `/webhooks/sms/telnyx` and `/webhooks/sms/telnyx/status`, checked with Ed25519 signatures
  (`TELNYX_PUBLIC_KEY`, 5-minute replay window); US 10DLC campaign status followed by `sms:sync-registrations`
  (`MNO_PROVISIONED` = approved; failed / rejected / suspended / expired = rejected).
- `SMS_PROVIDER=telnyx` (default) chooses where new numbers come from. A company keeps the provider of its number,
  so existing Twilio numbers keep working (`RoutedSmsProvider`, `SmsProviders`).
- Setup: DEPLOYMENT.md §6a. Tests: `tests/Feature/Messaging/TelnyxSmsTest.php`; the older messaging and browser tests
  run with `SMS_PROVIDER=twilio` because their fakes speak the Twilio API.
- Not done: voice calls; Telnyx's own number-porting; automatic 10DLC registration through the API (IDs are entered by
  the super-admin, as with Twilio).

### Review request after invoice send, chat order in conversations ✅ (October 6)

- Google review requests are sent only from the prompt after an invoice is sent (email, or SMS that went out):
  "Send Google Review request?" with Yes / No, nothing preselected. Yes shows Location (the company's own named Google
  profiles, e.g. Surrey / Burnaby; the brand's default preselected), Phone (customer's phone, editable) and Send, which
  texts the location's link as a separate SMS (Automatic) or opens it on the technician's phone. Route
  `invoices.review-request` (`InvoiceReviewRequestController`, `ReviewRequests::prompt/send`).
- Removed: the job's "Ask for a review" switch and "Send review request" button, the automatic request after full
  payment (and its delay/cooldown settings and dashboard warning), and review requests from `messages:deliver-due`.
  The old DB columns (`service_jobs.ask_for_review`, `companies.review_request_*`) are left unused.
- Conversations read like a chat: oldest at the top, newest at the bottom next to the reply box; opening a
  conversation or a new message scrolls to the bottom; older pages are above. Same order on the job page and customer
  card. Real-time (WebSocket) updates were not added; the inbox still refreshes every 15 seconds.
- Tests: `tests/Feature/Messaging/ReviewRequestTest.php`, tenant isolation in `TenantIsolationTest.php`.

### Estimate and invoice feedback fixes ✅ (October 6)

- Numbers typed with a comma are read like a person means them, on the client and the server
  (`parseNumber()` in `money.ts`, `MoneyInput::clean`): "150,00" = 150.00 (was 15 000), "50,5" = 50.50,
  quantity "1,5" = 1.5, "1,500" = 1 500 with a warning, "1.500,50" = 1 500.50, "150.5.5" is rejected (was cut to
  150.50). Price, cost, quantity, discount, deposit and payment fields show how the number was read ("= $150.00").
  Same reading in the payment dialog and the price book.
- Line editor: price book list shows only items of the line's kind (no services on a Part line), never switches the
  kind and keeps part number / unit; the picked item stays shown. Order is Qty → Price → line total. A service line
  never keeps a purchase price (server drops it too). Per-line tax choice moved under "Taxes, cost & warranty" and
  shown only with 2+ document taxes; "Taxable" only when taxes are on. Unpicked optional line reads
  "+ $X if the customer wants it" instead of being struck through. "Bill to customer" has a hint.
- Form: discount over 100% / over the items and unreadable numbers block Save with the message next to the button;
  errors clear as the field is fixed. Edit screens are titled "Edit invoice INV-2" with a back arrow. Toasts moved to
  the bottom so they no longer cover the title. Double tap on Save no longer creates two jobs / documents.
- Estimate "Your purchase total" leaves out optional lines the customer has not picked.
- Overdue: unpaid / partly paid invoices past the due date get a red "Overdue" badge; invoice list filter "Overdue".
- New invoice on a job that already has one shows "This job already has INV-1 (…)"; the "New invoice" job picker shows
  date, address, status, technicians and existing invoices. Invoicing a not-approved estimate asks with a clear text.
- Company address on documents: city / postal code typed into the street line are not repeated, a bare suite number
  becomes "Unit 514" in front, postal code in capitals.
- Tests: `tests/Feature/Billing/EstimateInvoiceFeedbackTest.php`.
- Not done (open product questions): locking partly paid invoices (credit notes), Good/Better/Best options, kits,
  photos on estimates, a separate estimates list, a one-line compact line card, PDF size.

### Invoice lines: labor, parts and materials add up ✅ (October 6)

- The Service / Part / Material tabs used to change the type of the same line, so a labor price became the part
  price instead of a second line. Now "+ Labor", "+ Part", "+ Material" under the lines each add a new empty line of
  that type (cursor in its description) and all lines add up. A filled line shows its type as a label with
  "Change type"; the tabs are shown only on an empty line. The untouched first line is reused, not left empty.
- "Service" is called "Labor" in the UI. "Taxes, cost & warranty" starts collapsed on parts too.
- Test: `tests/browser/invoice-lines.spec.ts` (labor $100 + part $150 + material $20 = $270, desktop and phone).

## Stage 2 — ⏳ Not started

## Stage 3 — ⏳ Not started

## Next

Stage 1 code is ready for the first testing installation. Connect the server agent/SSH environment and
install the tested code commit from SERVER_HANDOFF.md. No deployment has occurred in this workspace.
Verify mobile layouts, receipt camera uploads, Google map credentials, mail/SMS delivery, payment callbacks
and backup restore using LAUNCH_TESTING.md.
Stripe remains the second payment provider. Then Stage 2 (parts orders, warranty claims, online booking,
payment reminders), Stage 3 and subscription billing.

### Approved field-screen design — decisions not yet in code

Style is recorded in [DESIGN.md](DESIGN.md) (mockup: https://claude.ai/artifact/WWiBsi8bFjkdVkQqLMCYrv). No application
code has changed yet. Product decisions from the approved mockup:

1. Rename the job outcome "Fixed under warranty" (`JobOutcome::FixedUnderWarranty`) to **Manufacturer warranty**:
   repaired, invoice goes to the manufacturer.
2. **Finish visit — 4 large options:** Repaired; Manufacturer warranty; Waiting for parts; Not completed (inside:
   Customer declined / Unable to repair / No charge). Customer signature is optional and shown only for Repaired and
   Manufacturer warranty. Photos are removed with a long press.
3. **Appliance picker as image tiles:** Refrigerator, Freezer, Wine cooler; Washer, Dryer, Washer/dryer combo;
   Dishwasher, Range, Wall oven (`oven` in code); Cooktop, Microwave, Range hood; Multiple, Installation, Other; plus a
   "Not sure yet — tech adds it on site" button. `ApplianceType` has no Wine cooler or Multiple yet, and Installation
   is a `JobType` in code — decide how to store them.
4. **Two contacts per customer:** ✅ done (October 6): each phone has its own name, the Job page shows Call and SMS for
   each, and a paired avatar.
5. **Job page:** "Scan rating plate" button when model or serial is missing; a Manufacturer warranty block; invoices
   shown in the company currency (e.g. `CA$95.00`, never a hard-coded "$").

Design screens 6–15 are drawn on the same canvas: Book customer, Invoice, Take payment, Paid, Review request (phone) and
Calendar Day / Week / Map (desktop) plus Day and Map (phone). They are listed in DESIGN.md. Next: owner review of the
new screens, then restyle the app screen by screen to DESIGN.md (field screens first) and implement decisions 1–5.
