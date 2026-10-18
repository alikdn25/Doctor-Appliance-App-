# October 18 — UX redesign, phase 13: final pass on texts, accessibility, speed

UX-REDESIGN.md §14–15. This closes the redesign plan (§16.1, phases 1–13).

1. **Accessibility check in the test suite**: `tests/browser/accessibility.spec.ts` runs axe (WCAG 2.1 A/AA) on 20
   screens on a phone and a computer: My Jobs, Jobs, Job page, Book customer, Calendar, Customers, Customer, Invoices,
   Invoice, Reports, Settings, Messages, Expenses & taxes, Business expenses, Tax reserve, Cash, Mileage, Team,
   Profile and the customer link page. All pass.
2. **Contrast**: the link blue is darker (#055bd6, at least 4.5:1 on the light blue backgrounds); the menu section
   titles and the profile initials pass 4.5:1.
3. **Choice chips** (date, arrival window, technician, trip type, "no visit yet") are proper radios (no
   `aria-pressed` on `role="radio"`); the selected style follows `aria-checked`.
4. **Less motion**: people who ask the system for reduced motion get no animations or transitions.
5. **Speed**: appliance pictures and avatars are WebP (about 940 KB → 100 KB; the PNGs stay for old links); avatars
   and brand logos load lazily; a job card starts loading the job page on hover or press (5 s cache).
6. **Texts**: counters in the platform admin use plural forms ("1 company", "1 brand", "1 active member"); the invite
   email says "1 minute" / "60 minutes"; the platform company search is live (no Search button); numeric keyboard on
   the booking-bot fields.

- No migrations.
- Next: what is left from earlier phases (profile and security §12.9, platform admin polish §12.11, "Default for brand" on
  Google review locations) and the owner's walkthrough of the redesign.

# October 18 — UX redesign, phase 12: desktop layouts

UX-REDESIGN.md §13 (from 1024 px).

1. **Tables** for Jobs (# · Status · Date / window · Customer · Address · Appliance · Technician · Total), Customers
   (Name · Type · Phone · Address · Jobs · Owes · Last job) and Invoices (# · Customer · Issued · Due · Total · Balance ·
   Status): the header stays at the top, a tap on a column title sorts on the server (?sort=&dir=, whitelisted
   columns), the whole row opens the record; lists use the full width up to 1440 px. Phones keep the card lists.
   Jobs sort by # or date on the All tab (the Open tab keeps its urgency order).
2. **Job page**: two columns — the customer, problem, appliances, photos and Work done on the left; status, money,
   the schedule and Activity on the right, kept in view while scrolling. On a phone the order is unchanged.
3. **Invoice / estimate**: the document as an 800 px sheet on the left, payments and history in a 320 px column.
4. **Keys**: N — Book customer, / — the screen's search, T — today in the calendar (not while typing).

- No migrations. New fields in lists: customers' jobs count and last job; a job row's invoiced total (only for people
  who can see invoices).
- Tests: invoice and customer sorting (MoneyScreensTest, CustomerDossierTest); browser desktop.spec (tables, two
  columns, keys) and specs updated for the tables.
- Next: phase 13 — final pass on texts, accessibility, speed (§14–15).

# October 18 — UX redesign, phase 11: settings, sign-in; checklists removed

UX-REDESIGN.md §12 and §20.

1. **Checklists removed** (§20, the owner's decision): the job-page section (already gone in phase 6), Company →
   Checklists, the hub item, the default checklists per business type, the code, routes and SPEC mentions.
   Migration `2026_10_26_000100_drop_checklists` drops `checklist_templates` and `job_checklist_items` with their
   data (approved by the owner on October 16; listed in database/approved-destructive-migrations.php). Back up first.
   The price book and Google reviews keep their access (Owner and Admin) through a `manageCatalog` gate.
2. **Company settings hub** (§12.1): a list of short pages — Company & region, Invoices & estimates, Business hours &
   scheduling, Jobs, Warranty, Mileage, Payments — each row with what is set now ("Canada · Vancouver · CAD",
   "Due on receipt · next INV-2", "Mon–Fri 8–5", "Diagnostic fee: none", "30 days / 90 days", "km · rate not set",
   "Manual only"), then Taxes, Services, Messaging, Google reviews, Brands, Team with a short status. Each page shows
   "Unsaved changes · Discard · Save" only after a change and asks before leaving with changes.
3. **Taxes** (§12.3): what each tax applies to (Labour · Part · Material), Default as a chip, Edit / Delete under ⋯.
4. **Price book** (§12.4): search, type chips with counts, "Price not set" in grey.
5. **Messaging** (§12.6): the SMS mode as three cards with what happens; quiet hours only in Automatic; each
   template folds open, with placeholder chips that insert at the cursor, a preview on sample details in the
   company's format, and the character and SMS-part count.
6. **Google reviews** (§12.7): labelled fields, "Test link", the rules behind (i).
7. **Brands** (§12.8): the logo, or initials on the brand colour, and a colour dot.
8. **Sign-in** (§12.10): email fields use autocomplete=username.
9. **Fixes reported by Grok Bot**: `app:reset-password --generate` now makes a password the production rules accept
   (mixed case, number, symbol); the visit length field takes any 5-minute value (60 was refused).

- Not done in this phase: the platform admin polish (§12.11, low priority) and "Default for brand" on Google review
  locations.
- Tests: the settings hub and section saving (CompanySettingsTest), checklist routes gone (FieldWorkTest), tenant
  isolation list, generated password rules (ResetPasswordCommandTest); browser specs updated.
- Next: phase 12 — desktop layouts (§13).

# October 16 — UX redesign, phase 10: reports, expenses, cash, mileage, tax reserve

UX-REDESIGN.md §11, §17 and the content of §23. Migration approved by the owner on October 15.

1. **Tax reserve** (§17): Expenses & taxes → Tax reserve (Owner only). Settings: on/off, income and dividend tax %
   (default 21), take off the recoverable sales tax paid on expenses, count by invoices paid (default) or issued,
   reminder day (1–28). The month card "Move to the tax account for September": each sales tax by its name (no tax
   names or countries in the code), income tax = rate × net profit as in Reports (0 on a loss), total; Mark transfer
   (amount, date, note); earlier months still short; the transfers; CSV by month (also under "For the bookkeeper").
   Paid basis: each payment carries its share of the invoice's taxes; refunds take theirs back; void payments do not
   count. Reminder: `tax-reserve:remind` hourly, from 9:00 on the chosen day, once a month, push + bell to Owners.
2. **Reports** (§11.1): Day · Week · Month · Year · Custom with ‹ ›, applies at once (no Show button); From / To only
   in Custom. Four figures: Revenue · invoices issued ("Based on 1 invoice issued Oct 1–9"), Profit · jobs closed with
   margin ("Based on 0 jobs closed Oct 1–9"), Average invoice, Estimate conversion. Sections that open on tap:
   Profit breakdown, By brand, By technician, By job type, By lead source, By appliance type, Warranty callbacks,
   No-charge jobs, For the bookkeeper. Rows read "Doctor Appliance — 1 invoice · $95.00 · avg $95.00"; methods behind
   (i); empty figures say "No data for this period". Calculations unchanged.
3. **Business expenses** (§11.2): the same period control; Category / Employee / Search in a "Filters (N)" sheet with
   active filters as chips with ✕; Export CSV under ⋯; "+" as a FAB; Expenses by employee folded (Owner/Admin).
   No grand total (SPEC). New expense: receipt first, the last 3 categories as chips, the price hint under Price,
   "Save · Total $X" pinned at the bottom.
4. **Cash on hand** (§11.3): one row per person with initials, the amount large, Record deposit only when there is
   cash to hand in; the hint behind (i).
5. **Mileage** (§11.4): the person chips from phase 4 already cover it.

- Migration `2026_10_25_000100_add_tax_reserve`: six `companies.tax_reserve_*` columns and the
  `tax_reserve_transfers` table (no cascading deletes). Nothing removed.
- Tests: TaxReserveTest (paid basis, refunds, issued basis with expense tax, transfers and months short, CSV, Owner
  only and tenant isolation, reminder once); tenant isolation list; browser profit.spec and updated expense scenario.
- Next: phase 11 — settings, sign-in, admin (§12) with the rest of §20 (checklists).

# October 15 — UX redesign, phase 9b: Good / Better / Best on estimates

SPEC §7.5, UX-REDESIGN §10.4, §21.2. Migration approved by the owner on October 15.

1. **Migration** `2026_10_24_000100_add_option_name_to_estimate_items`: `estimate_items.option_name` (nullable:
   good / better / best). Existing lines keep no option; nothing is removed.
2. **Estimate form**: each line has an "Options" chip row (All options · Good · Better · Best); a Good/Better/Best
   switch above the totals shows the total of each option (the shown one is saved as the chosen one).
3. **Estimate page**: one card per option with its total and "Chosen"; the lines follow the card; Mark approved
   approves the option shown.
4. **Customer's online page**: option cards with live totals; Approve requires a choice; the estimate total, deposit,
   PDF and the invoice (Create invoice, New invoice from the approved estimate) take only the chosen option's lines.
5. PDF: each option line is tagged, with a line "Options: Good $150 · Better $250 (Chosen) · Best $950".

- Tests: option totals, online choice and the invoice lines, Mark approved with an option (OnlineEstimateApprovalTest).
- Next: phase 10 — reports, expenses, cash, mileage (§11) with §17 (tax reserve, migrations approved) and §23.

# October 15 — UX redesign, phase 9: invoices, estimates, payments, inbox

UX-REDESIGN.md §10 with §18, §19, §21 and §26.

1. **Invoices** (§10.1): Outstanding and Overdue (red) totals on top, chips Outstanding · Overdue · Paid · All, live
   search, + in the header; rows "INV-12 Jane Cooper / Oct 8 · due Nov 7" with the amount owed and one badge (Paid,
   Due in 5 days, Due today, Overdue 3 days); empty states "No outstanding invoices." with [Show all invoices]. There
   are no draft invoices in the product, so there is no Drafts chip.
2. **Invoice and estimate page** (§10.2, §10.4): status in large type on top ("Paid · $106.40", "Due Nov 8 ·
   $106.40", "Overdue 3 days · $X"), sent/viewed under it; PDF is an icon in the header; "Send ▾" (Email, SMS, Copy
   link) replaces the three buttons. Invoice: Take payment is the main button (a sheet with the provider's QR/link and
   Mark as paid when a provider is connected, otherwise Mark as paid directly); Edit (with the "This invoice is paid"
   warning), Refund and Void are under ⋯. Estimate by stage: Draft → Send ▾ + Edit; Sent (draft with sent_at, no new
   status) → Mark approved + Mark declined; Approved → Create invoice; Declined → Invoice diagnosis only (when a
   diagnostic fee is set); Delete, Revise, Send again under ⋯. "Copy link" makes the customer link on first use.
3. **New invoice** (§10.3, §21.2): the short warning "This job already has INV-1 (Paid). You are creating a second
   invoice." with links; a job with an approved estimate opens with its lines (saving links the estimate and marks
   it invoiced, a deposit counts towards the invoice, as with Create invoice); Add item has "From estimate EST-1
   (N lines)"; due date "Due Nov 8 (Net 30)"; Subtotal/Taxes pinned above Save; "Add at least one item" only after
   Save.
4. **Estimate decisions** (§21.1): when a customer approves or declines online, everyone in the company (owners,
   admins, technicians, collectors; not subcontractors) gets a push and a line in the bell list; owners/admins keep
   the email.
5. **Review request** (§18): the dialog shows the Review request text filled in for the customer, editable for this
   customer only; Send / Don't send; the review link goes back at the end if removed; the text is saved in the job's
   messages.
6. **Receipt email** (§19): "Send receipt" on a paid invoice (never automatic): brand banner with the logo on the
   brand colour, amount and "Paid", date and how it was paid, lines, taxes, total, brand contacts, "View receipt
   online", "Download PDF" and the PDF attached; one 600 px column of tables for Gmail/Outlook/Apple Mail.
7. **SMS Inbox and chat** (§10.5, §26): the list is its own screen (live search, Unread only chip, empty state by
   SMS mode with a Messaging settings link for the Owner); a conversation opens as a chat screen with ←: customer's
   texts left, ours right, dates between days, status under ours (failed with the reason), earlier messages load on
   scroll up, the reply box pinned at the bottom with Templates (On my way, Reminder, Invoice link, General); call,
   job and customer in the header. In "From technician's phone" mode the texts opened on phones are listed and the
   chat says "Replies go to the technician's phone". The job and customer pages show the last 2 messages and
   "Open chat".

- No migrations. Good / Better / Best estimate options (§10.4, SPEC §7.5) are not built: they need new tables, so
  they wait for the owner's decision.
- Tests: MoneyScreensTest (list totals and days, tenant isolation, invoice from an approved estimate), receipt and
  Copy link (DocumentDeliveryTest), editable review text (ReviewRequestTest), push/bell alerts
  (OnlineEstimateApprovalTest), chat templates, earlier messages, technician-phone mode, Open chat (SmsInboxTest);
  browser scenarios updated.
- Next: phase 10 — reports, expenses, cash, mileage (§11) with §17 and §23.

# October 15 — UX redesign, phase 8: calendar and map

UX-REDESIGN.md §9.

1. **Toolbar** (§9.1): [Day | Week | Map], ‹ › and the date (tap to pick a day; "Today" when away from today) on one
   row; the separate date field is gone. "Show completed", the people shown and the travel-buffer legend are in a
   Filters sheet (remembered per device). The drag hint is shown once, with "Got it".
2. **Booking from the calendar**: "Book on this day" is gone; + Book in the bottom bar (or the sidebar) books on the
   day the calendar shows, and a tap on an empty spot of a lane opens Book customer with that day, time and person.
3. **Day on a phone** (§9.2): lanes only for the people who go on calls (plus anyone else with a job that day);
   people with work first, Unassigned last; lanes 176 px wide with the hour column pinned and a "+N →" chip for
   lanes off screen; blocks "9–11 a.m. · Jane Cooper" with the appliance below, a status-coloured stripe and a red
   frame when late; a red "now" line; "N to schedule" folds above the grid (a column on wide screens).
4. **Week on a phone** (§9.3): an agenda — "Fri, Oct 9 · 3 jobs" per day, opened at today; "⋯ → Reschedule" opens the
   time/person sheet. Tablets and computers keep the grid (all 7 days, status colours).
5. **Map** (§9.4): the person filter is a row of chips; the numbered list and "Open route" stay.

- No migrations (uses the "Goes on calls" flag added in phase 5).
- Tests: lanes and their order, the admin lane only with a job, booking from an empty slot (CalendarTest); browser
  scenarios for Filters/Show completed, + Book with the shown day, a tap on an empty slot, map chips.
- Next: phase 9 — invoices, estimates, payments, inbox (§10) with §21, §18, §19 and §26 (done, see above).

# October 15 — UX redesign, phase 7: customers and appliances

UX-REDESIGN.md §8.

1. **Customers** (§8.1): live search (no Search button), type and tag chips, one row per customer (avatar, name with
   type and tags, phone · address on one line) with "Owes $X" in red when money is owed (only for people who see
   invoices); + in the header.
2. **Customer card** (§8.2): Call · Text · Email · **New job** (opens Book customer with the customer chosen) right
   under the name; Text is off with "SMS off" when the customer replied STOP; Jobs · Paid · Owes on one line (Owes in
   red, jumps to the invoices); order: contacts → about the customer → properties (Navigate) → appliances → jobs →
   estimates & invoices → messages; Edit and Delete (with a confirmation) under "⋯"; the type is a badge by the name,
   the source and payment terms are in the contacts' details.
3. **New customer** (§8.3): the picture on top (tap → Man / Woman / Couple / Automatic, explanation behind (i)), then
   the name (company name first for business types), phones and emails (formatted as typed, a star for Primary, ✕,
   Add phone / Add email / Add second person as links, "Already a customer: … [Open]" right there), the service
   address (one field; the rest opens after a pick or with "Enter manually"; the country is a list of names; label
   chips Home / Rental / Cottage / Office), About the customer and tags (chips), and "More" folded (type, source,
   payment terms with "Net 30 for stratas and property managers"). Save is pinned. No address typed = saved without.
4. **Appliance** (§8.4): "Take photo" right in the empty rating-plate block; model and serial copy with one tap;
   Edit and Delete under "⋯" (Delete with a confirmation).

- No migrations.
- Tests: Owes on the list (and only from the company's own invoices), countries offered by name; browser scenarios
  for the list, the card actions and New job, the new-customer form and the icon sheet.
- Next: phase 8 — calendar and map (§9).

# October 15 — UX redesign, phase 6: Jobs tabs and the office job page

UX-REDESIGN.md §7.4, §7.5 and the job-page part of §20.

1. **Jobs** is one screen with **Open** (the unfinished queue, with its count) · **All** · **Deleted**. The old
   addresses (/jobs/not-completed, /jobs/deleted) open their tabs; the unfinished strip opens Open. Technicians see
   only Open; a link with a list filter (e.g. ?status=completed) opens All.
2. One line of filter chips: Open — Overdue (red) · Needs scheduling · Waiting for parts · Waiting for customer · On
   hold · Scheduled / in progress, zeros muted at the end; All — statuses that have jobs. Live search (number,
   customer, phone, address, model, serial). The other filters of All stay under the filter button.
3. Sorting: Open by urgency (overdue → needs scheduling → waiting → on hold → nearest visit); All by visit date,
   newest first. "⋯" switches to newest first / by job number.
4. **Office card**: compact, no Navigate; Call is an icon; the assignee's initials on the right; Overdue as a red flag
   next to the status.
5. **Deleted**: "Can be restored for 30 days" once at the top; Restore in each row.
6. **Office job page** (§7.5): one status badge (the "Invoice · Paid" chip moved to the money section's rows); Delete
   job under "⋯" with a confirmation and "Job deleted · Undo"; Costs & profit marked internal with a lock, its long
   notes behind (i); on a phone, anchor chips Overview · Work · Money · Activity under the header.
7. §20 (job page): the job page no longer loads or sends checklist items. Removing the Checklists settings screen,
   the code and the tables is phase 11 (the table drop needs the owner's permission).

- No migrations.
- Tests: Jobs tabs, redirects, technician access, urgency order, sort options, Undo after delete (JobBacklogTest),
  updated list/trash/isolation tests; browser scenarios for the tabs, office cards, Deleted and the anchor chips.
- Next: phase 7 — customers and appliances (§8).

# October 15 — UX redesign, phase 5: booking and job forms

UX-REDESIGN.md §7.1–7.3 and §25.

1. **Book customer** (§7.1) on one page in the order of a call: Phone first (formatted as typed; from 3 digits a caller
   already in the book is offered as "Jane Cooper · 8450 128 St · 2 jobs [Use]", by phone number only), Name (the
   magnifier searches by name), Appliance (4 columns, 40 px pictures, "Other" last, tap again to clear), Problem (grows
   as you type), When, Technician, Address (optional, unit/city after a pick or "Enter manually"), More details (about
   the customer, brand when more than one, source, notes for the team). A chosen customer is one row with Change and
   their notes. Book is pinned at the bottom with "To save, add: …" → "Ready"; ✕ cancels (asks when something was
   typed); "⋯ → Open full job form" carries what was typed.
2. **When** (§25): day chips (Today, Tomorrow, 5 more days, Pick date) and the company's arrival windows; windows that
   already started today are hidden; "Now" first for today — from now until the technician's next job (or one hour).
   "Custom time" opens date / from / to.
3. **Technician**: only people who go on calls (new "Goes on calls (shows in calendar)" on the Team screen; on for
   Owners and Technicians), each with "free" or "busy 9–11" for the chosen day and window.
4. After booking: the day in the calendar (My Jobs for people without the calendar) with "Booked #1005 · Tomorrow
   9:00–11:00 a.m." and Open job.
5. **Arrival windows** in Company settings: any number, may overlap; the default list is 9–11, 11–1, 1–3, 3–5.
6. **Full job form** (§7.2): header with ←, Job type ("what kind of work") and Visit reason ("why we are going", hidden
   for handyman) together, "Schedule it now" replaced by When (Later = Needs scheduling, day and window chips, custom
   time), Save pinned at the bottom.
7. **Edit job** (§7.3): the customer's name and phone folded under "Customer details" with "Changes apply to all of
   Jane's jobs"; the address fields open with "Edit address" instead of sitting under the address list.

- Migration (approved by the owner): `companies.arrival_windows` (JSON, empty = default windows) and
  `company_user.shows_in_calendar` (on for existing Owners and Technicians). Columns only; no data removed.
- Fixed: a customer search still running when Book was pressed used up the "Booked" message.
- Tests: `tests/Feature/Jobs/BookingTest.php` (defaults per role and Team toggle, windows and busy times, tenant
  isolation, settings validation, phone-only search with job counts, redirect and message), updated quick-entry,
  booking and edit browser scenarios, a "Now" scenario. TripTest pinned to midday (it failed late in the evening).
- Next: phase 6 — Jobs with Open / All / Deleted tabs (§7.4) and the office job page (§7.5).

# October 15 — UX redesign, phase 4: technician screens

UX-REDESIGN.md §6 and §24.

1. **My Jobs** (§6.1): one line with [Day | Completed] and ‹ Today › (a tap on the date picks a day); the day in one
   sentence ("3 jobs · next at 1:00 p.m."; Completed says "Last 30 days"); status chips only with more than three
   jobs; + Trip is a floating button with a car; the "Today (1)" heading, the status circles and Book customer
   (it is in the bottom bar) are gone; "more jobs for someone else" is a list row.
2. **Job card** (§6.1, §24): compact — window "1–3 p.m." in bold with the status and an Overdue flag, customer,
   address and "Washer · problem" on one line each, a 48 px appliance picture; three equal buttons: Navigate (light
   blue), Call (green) and the visit step in white (On my way / Start job / Complete). The next job has an accent
   border; a tap on the card opens the job (View job and the chevron removed). The jobs list uses the same card.
3. **Job page** (§6.2): header "#1001 · Repair" with the visit time and technician (or "Started 9:04 a.m. · 36 min
   on job"); "⋯" opens a bottom sheet with Close job, Change status, Set repair warranty, Edit job and Delete job.
   Sections in the order of the work: customer (gate / buzzer large, About the customer, Navigate · Call · Text,
   "Call Sam (on-site)"), Problem and notes for the team, appliances with the rating-plate camera, photos, Work done,
   estimates and invoices (moved to the top once invoiced or paid), the signature, and Activity folded (messages,
   schedule for the field, status history, details: source, brand, call type). The office keeps the schedule block
   visible.
4. **Bottom bar by status** (§6.2): scheduled — On my way / Start job; on the way — Start job; in progress —
   Complete / Waiting for parts (opens Finish visit with "Part needed" chosen); money owed — Take payment (with the
   balance) / Send invoice; waiting for parts — Schedule next time; completed — New invoice / New estimate; paid —
   Complete job. On a desktop the bar stays at the bottom of the window as a card.
5. **Work done saves by itself** a second after typing stops ("Saving… / Saved", retried on a weak signal) and when
   the page is left; the Save button is gone.
6. **Trip sheet** (§6.3): one line of explanation, "Recent" chips (the person's last five stores and suppliers),
   Navigate / Save only / Enter by hand all visible; Enter by hand from My Jobs opens Mileage with the form.
7. **Mileage** (§6.4): no long subtitle; a setup card first when there is no start address; month and year totals;
   "Set rate per km" for the Owner only when no rate is set; the person filter as chips (Owner/Admin); "Enter
   distance" chip when the distance was not found; Export month / year under "⋯"; + Trip as a floating button.

- Tests: `tests/Feature/Jobs/TechnicianScreensTest.php` (autosave JSON, access and tenant isolation, Finish screen
  with "Part needed", recent stops per person and company, Enter by hand); browser scenarios for My Jobs, the job
  page (⋯ sheet, autosave, Activity), Trip sheet, Mileage, Take payment and Complete.
- No migrations. Signature: still taken on the invoice (owner's earlier decision), so the job shows it once signed.
- Next: phase 5 — booking and job forms (§7.1–7.3) with arrival windows and "Now" (§25).

# October 15 — UX redesign, phase 3: app frame and navigation

UX-REDESIGN.md §5, §22 and the menu item of §23.

1. **Bottom bar by role** (phones): Owner/Office — Today · Calendar · ＋ Book · Jobs (Office) or Invoices (Field) · More;
   technician — Today · Mileage · My expenses · More. The active item is always lit; "＋ Book" books on the day the
   calendar shows. One source for the bottom bar, More and the desktop sidebar (`hooks/use-app-navigation.ts`).
2. **My menu: Field | Office** (§22) at the top of More, saved per person (new nullable `users.menu_mode`, migration
   approved by the owner). Technicians always have Field and do not see the switch.
3. **More is a bottom sheet** (§5.4): search over the menu, groups (open for the Owner), items of the bottom bar not
   repeated, then profile, notifications, company switch and sign-out. No menu button in the header on phones.
4. **Header** (§4.4, §5.3): compact (56 px on a phone), title 20/600; ← on every child screen (back through the app's
   history, or to the parent screen when opened from a link); none on root screens.
5. **Unfinished-jobs strip** (§5.2): total plus a counter per category (Overdue in red, Needs scheduling, Waiting for
   parts/customer, On hold, Scheduled; zeros hidden), each opening the unfinished jobs with that filter.
6. **Messages**: with automatic texting the inbox is an icon with the unread count in the header of Today and Jobs;
   otherwise it is in More (and always in the desktop sidebar).
7. **Expenses & taxes** (§23, Owner): one More item opening expenses, mileage and cash on hand with this month's
   figures (the tax reserve of §17 joins it in phase 10). Office keeps its separate items.
8. **Profile and security** in the same frame: personal settings now know the person's company, so they show the
   bottom bar and ← (never redirecting people without a company).

- Tests: `tests/Feature/NavigationTest.php` (menu mode per role, saving, Expenses & taxes access, props for the
  frame); browser `tests/browser/navigation.spec.ts` (owner and technician bars, Field/Office swap, More search and
  groups, ← on child screens, unfinished strip, profile in the frame) and the updated menu scenarios.
- Done in phase 4.

# October 14 — UX redesign, phase 2: design tokens and the UI kit

UX-REDESIGN.md §4 and §16.1 phase 2. The look follows the volumetric ux-preview mockups; where they differ, the
document wins (button radius 14, status colours of §4.3).

1. **Tokens** in `resources/css/app.css`: gradients (`--gradient-primary`, `--gradient-surface`, card, header,
   danger), shadows (`--shadow-raised`, `--shadow-pressed`, `--shadow-glow`, card, sunken, badge), semantic colours
   `--danger`, `--success`, `--warning` (also Tailwind `danger`, `success`, `warning`, `navy`, `link`) and the status
   dictionary `--status-<tone>-from/-to/-text`. The existing `.da-*` classes now read the tokens.
2. **Buttons — four levels**: Primary (only one glows), Secondary (raised, no glow), Tertiary (`tertiary`, `ghost`,
   `link`), Destructive (red text; the filled red `destructiveSolid` only in confirmation dialogs: void invoice, turn
   off two-factor). 48 px on a phone, 40 on a desktop, radius 14, pressing sinks 1 px with a flatter shadow, `loading`
   spinner.
3. **One status dictionary** (`lib/status.ts`): jobs, visits, estimates and invoices share one `StatusBadge` (colour +
   icon + text) and the red `OverdueFlag`. Status colours changed to §4.3: on the way cyan, in progress amber, waiting
   for customer orange, on hold slate, paid dark green. Estimate and invoice badges now have the same raised look.
4. **UI kit** (`resources/js/components/ui`): IconButton, StatusBadge/OverdueFlag, AppBar, BottomNav, Fab, ListRow,
   Section, bottom Sheet (rounded top, 44 px close), OverflowMenu, ConfirmDialog, toast with Undo, SearchField,
   FilterChips, SegmentedControl, StickyActionBar, EmptyState, FormField, PhoneInput, AddressInput, TagInput,
   EditableList, DateChips, TimeWindowChips (with an optional "Now" chip for §25), MoneyText, DateText. Screens adopt
   them in the next phases.
5. **`/dev/ui`**: every component in every state; registered only when `APP_ENV=local`.
6. Sections 17–26 are assigned to phases in UX-REDESIGN.md §16.1.

- Tests: `tests/Feature/UiKitTest.php` (the page never exists outside local), browser `tests/browser/ui-kit.spec.ts`
  (only Primary glows, 48/40 px heights, distinct status colours, Overdue flag, confirmation, toast with Undo, bottom
  sheet; phone and desktop screenshots).
- Next: phase 3 — app frame and navigation (§5, §22, menu item of §23).

# October 14 — Part A: data protection

Owner's brief (October 14): part A (data protection) before part B (redesign phases 2–13 of UX-REDESIGN.md, each its
own PR). UX-REDESIGN.md already holds sections 17–26 (only table formatting differs from the owner's file).

1. **Backups**: the database every hour, uploaded files every day, encrypted on the server (libsodium) and stored in
   external S3-compatible storage (Backblaze B2). Hourly copies kept 48 hours, daily 30 days, monthly 12 months, files
   30 days; pruning by the app or by bucket lifecycle rules. `backup:check` alerts the platform owner when a backup
   fails or is too old. Commands `backup:run|list|check|prune|restore|restore-company|key`. docs/RESTORE.md.
2. **Before every deployment** `deploy.sh` backs up the database; no backup, no migrations.
3. **Production guards**: `migrate:fresh/reset/refresh/rollback`, `db:wipe` refused (already), `db:seed` and
   `migrate --seed` refused, DemoSeeder refuses production; a migration that drops or renames tables or columns or
   deletes rows runs only when listed in `database/approved-destructive-migrations.php` (owner's permission), checked
   by `migrate` in production and by a CI test.
4. **Nothing is removed for good**: payments and job costs are soft-deleted too (customers, addresses, appliances,
   jobs, estimates, invoices, expenses already were). **Deleted items** (`/deleted-items`, Owner and office): customers,
   addresses, appliances, estimates, invoices, payments, expenses and job costs of the last 30 days with Restore; a
   customer comes back with the addresses and appliances deleted with it. Every `ON DELETE CASCADE` foreign key is now
   `RESTRICT` (only the people of a not-started visit time keep the cascade), so a hard delete can never take jobs,
   invoices or history with it.
5. **Change log**: create/change/delete/restore of customers, jobs, estimates, invoices and payments with who, when,
   old and new values and the source (in the app, booking bot, customer page or provider, server). The Owner sees
   "Show history" on the customer, job, estimate and invoice pages (invoice history includes its payments) and the whole
   log in Company settings → Change log.
6. **Watchdog** (`ops:watchdog`): at 7:00 company time counts customers, jobs and invoices per company; a drop of more
   than 5% or 10+ records since the day before alerts the company's Owners.
7. **Account switch bug**: caused by the temporary auto sign-in (`AUTH_AUTO_LOGIN_EMAIL`), which signed the device in
   as the configured account whenever the session ended; removed. Also: a sign-in without "remember me" drops someone
   else's remember cookie, signed-in pages are `no-store`, Inertia history is encrypted and cleared at sign-in, the menu
   shows company, name, role and email. docs/UX-NOTES.md.
8. **File backups**: see 1 (private and public storage, daily, 30 days).
9. **Restore one company**: `backup:restore-company <id> [backup]` restores the backup into a scratch database,
   shows per table what will be added back, put back and kept, asks, backs up the live database, then copies only that
   company's rows (nothing deleted, numbering never moved back). Tested with two companies.
10. **Server agent (Grok)** prepared, not switched on: `scripts/ops/` (status, check-backups, backup-now,
    restore-company with the owner's approval; every action logged to `storage/logs/ops-agent.log`),
    `setup-opsbot.sh` (Linux user without root, sudo only for those scripts), `opsbot-db.sql` (read-only DB user).
    docs/OPS.md.
11. **Website booking bot API** (`/api/bot/v1`, docs/BOOKING_BOT.md): per-company tokens (Company settings → Booking
    bot, Owner), its own bot user; free windows, find customer by phone (first name and street only), add customer,
    book a job. No changes, deletes, money or reports. Request and hourly booking limits. Every action logged as
    "Booking bot"; every booking pushed to the Owners.
12. **Notifications**: in-app list (Settings → Notifications, menu with unread count), Web Push on phones (VAPID keys:
    `webpush:keys`), email once mail delivery is enabled, Telegram for server alerts while email is not set up.

- Tests: `tests/Feature/DataProtection/*` (trash and restore, foreign keys, change log and access, bot API, sessions
  and roles, watchdog, production guards) and `tests/Integration/BackupTest.php` (real pg_dump/pg_restore:
  encryption, test restore, one of two companies restored, files, failure alert, retention, check, local fallback).
- Needed from the owner: B2 bucket and key, the backup encryption key stored outside the server, VAPID keys,
  optionally a Telegram bot for alerts; then a test restore on the server (docs/RESTORE.md §4).
- Next: part B, phase 2 — design tokens and the UI kit (UX-REDESIGN.md §4, §16.1).

# October 14 — UX redesign, phase 1: P0 fixes

The owner's UX audit and redesign brief is in [UX-REDESIGN.md](UX-REDESIGN.md) (13 phases, one PR each). Phase 1 fixes
the bugs, leaks and SPEC contradictions of its section 3, without redesigning screens.

1. **Cash on hand**: each person is a grid row (name truncates · amount · action); on a phone the button goes under
   the name, so names no longer run over "No cash on hand".
2. **Team**: one row per member (name, role badge, email truncated, brands); Edit, Transfer jobs, Replace technician,
   Resend invitation and Remove are in a "⋯" menu (`MemberTransitions` is now a controlled dialog).
3. **Calendar → Map** without a key: "Map is unavailable right now. Jobs and directions are listed below." Server
   details (`GOOGLE_MAPS_*`, DEPLOYMENT.md) are shown only to the platform admin.
4. **Invoice / estimate**: the raw customer URL is replaced with a "Copy customer link" button and a toast. Links are
   built with `route()` from the request host, so production links use the real domain.
5. **Log in**: "Forgot your password?" is always shown, under the password field; the recovery page already explains
   when mail is unavailable (SPEC §10).
6. **Settings → Payments**: in a country Square serves but with no Square keys on the server, the text is "Card
   payments with Square are coming soon. Record payments by hand for now." (`PaymentProvider::servesCountry`,
   `PaymentProviders::comingSoonFor`). Countries Square does not serve keep "not available in your country yet".
7. **Warranty settings**: "Labour and materials" → "Labour" / "Labor" (materials carry no warranty).
8. **Credits and discounts** (lines priced below zero) show and print no warranty (`IsBillingLine::hasWarranty`).
9. **Accept cash payments** hint: "Technicians can record cash payments."
10. **Reports**: plural counts ("1 invoice", "1 estimate").
11. **Reports**: the two bases are named — "Revenue · invoices issued" with "Based on N invoices issued in this
    period", and "Profit · jobs closed" with "Based on N jobs closed in this period"; the profit table says "Revenue of
    closed jobs (without taxes)". Calculations unchanged.
12. **No roadmap in the UI**: "Address autocomplete comes later" and "comes with the price book" removed (also the
    unused "Coming in a later update." strings).
13. **Confirm password**: "Confirm your password to continue.", Confirm + Cancel (back, or the profile).
14. **Dates**: appliance dates and the admin company list use the company's regional format (no `2027-10-09`).
    Native date inputs keep the browser's format.
15. **New invoice**: "Add at least one item." is a grey hint until the first Save, then an error; Save is no longer
    disabled by it.
16. **Spelling by regional format**: en-US (and US territories) reads Labor / Check; every other regional format
    reads Labour / Cheque. `App\Support\Locale\Spelling` maps the company's regional format to the translation
    locale (`en` or `en_GB`, an overlay of only the respelled keys in `lang/en_GB`); `CurrentCompany` switches it with
    the company, and each queued job starts on the default. The default "Labor (per hour)" service of a new company
    follows the same rule (existing services keep their names).
17. **Unfinished-jobs bar** is shown on every screen, including Jobs and My Jobs (it was hidden on screens with their
    own header).
18. **Demo data**: the fridge job is on the fridge, the washer job on the washer.

- Tests: `tests/Feature/UxP0FixesTest.php` (spelling per regional format, default service names, no warranty on
  credits), Square "coming soon" in `SquareTest`, recovery link in `EmailReadinessTest`; browser
  `tests/browser/ux-p0.spec.ts` (login link, map text, Copy customer link, team "⋯", cash row, plain settings
  texts, new-invoice hint, bar on Jobs) with phone and desktop screenshots. Existing browser scenarios now use the
  Canadian spelling of the fixture company ("Labour").
- `doctor-appliance-screens.html` is not in this repository, so it was not rebuilt; the Playwright report holds the
  screenshots of each phase.
- Next: phase 2 — design tokens and the UI kit (UX-REDESIGN.md §4, §16.1).

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
