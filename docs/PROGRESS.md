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

| #   | Task                                                                    | Status                                |
| --- | ----------------------------------------------------------------------- | ------------------------------------- |
| 1   | Customers, properties (manual address), appliances (§6, §7.1)           | 📝 Schema and screens awaiting review |
| —   | Jobs & statuses                                                         | ⏳ Not started                        |
| —   | Calendar & dispatch                                                     | ⏳ Not started                        |
| —   | Technician PWA view, photos, signatures                                 | ⏳ Not started                        |
| —   | Estimates, invoices                                                     | ⏳ Not started                        |
| —   | Square payments                                                         | ⏳ Not started                        |
| —   | Twilio SMS (automated messages + inbox)                                 | ⏳ Not started                        |
| —   | Review request toggle                                                   | ⏳ Not started                        |
| —   | Price book                                                              | ⏳ Not started                        |
| —   | Basic reports                                                           | ⏳ Not started                        |
| —   | Google Places autocomplete + geocoding for properties                   | ⏳ Not started                        |

## Stage 2 — ⏳ Not started

## Stage 3 — ⏳ Not started

## Next

Get the schema and screens for Stage 1 task 1 approved, then implement them.
