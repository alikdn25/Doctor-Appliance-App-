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
- Tax rates per company (GST/PST as settings).
- Super-admin panel: companies list, status, plan, impersonation with audit log.
- CI (GitHub Actions tests) and manual deploy pipeline to the VPS (`docs/DEPLOYMENT.md`).

## Stage 1 — MVP 🚧 In progress

| #   | Task                                                           | Status         |
| --- | -------------------------------------------------------------- | -------------- |
| 1   | Customers, properties (manual address), appliances (§6, §7.1)  | ✅ Done        |
| 2   | Jobs & statuses, visits, My jobs (§6, §7.3 w/o calendar, §7.4) | ✅ Done        |
| —   | Calendar & dispatch                                            | ⏳ Not started |
| —   | Technician PWA view, photos, signatures                        | ⏳ Not started |
| —   | Estimates, invoices                                            | ⏳ Not started |
| —   | Square payments                                                | ⏳ Not started |
| —   | Twilio SMS (automated messages + inbox)                        | ⏳ Not started |
| —   | Review request toggle                                          | ⏳ Not started |
| —   | Price book                                                     | ⏳ Not started |
| —   | Basic reports                                                  | ⏳ Not started |
| —   | Google Places autocomplete + geocoding for properties          | ⏳ Not started |

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

## Stage 2 — ⏳ Not started

## Stage 3 — ⏳ Not started

## Next

Stage 1 — Technician PWA view, photos, signatures (installable app, offline-friendly job screen, before/after
photos with upload retry, rating plate photo, checklists per job type, customer signature).
