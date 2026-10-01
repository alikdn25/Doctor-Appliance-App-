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

| #   | Task                                                          | Status         |
| --- | ------------------------------------------------------------- | -------------- |
| 1   | Customers, properties (manual address), appliances (§6, §7.1) | ✅ Done        |
| —   | Jobs & statuses                                               | ⏳ Not started |
| —   | Calendar & dispatch                                           | ⏳ Not started |
| —   | Technician PWA view, photos, signatures                       | ⏳ Not started |
| —   | Estimates, invoices                                           | ⏳ Not started |
| —   | Square payments                                               | ⏳ Not started |
| —   | Twilio SMS (automated messages + inbox)                       | ⏳ Not started |
| —   | Review request toggle                                         | ⏳ Not started |
| —   | Price book                                                    | ⏳ Not started |
| —   | Basic reports                                                 | ⏳ Not started |
| —   | Google Places autocomplete + geocoding for properties         | ⏳ Not started |

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

## Stage 2 — ⏳ Not started

## Stage 3 — ⏳ Not started

## Next

Stage 1 — Jobs & statuses (job → customer, property, appliances; status log; technician access to customers of
their own jobs; appliance repair history from jobs).
