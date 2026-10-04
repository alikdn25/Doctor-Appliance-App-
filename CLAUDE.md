# Instructions for Claude Code

Read `SPEC.md` before any task. It is the source of truth for scope and stages.

## Rules
- Reply to the user in Russian. Keep code, comments, commit messages and UI text in English.
- Stack: Laravel (latest stable) + Inertia.js + React + TypeScript + Tailwind, PostgreSQL, Redis.
- Multi-tenant: every tenant-owned table has `company_id`; all queries are tenant-scoped. Never break isolation. Add tests for it.
- Work only on the stage/task requested. Do not build features from later stages.
- Mobile-first UI. Technician screens must work well on a phone.
- UI style is volumetric, never flat: follow docs/DESIGN.md.
- Every feature ships with tests. Run tests before finishing.
- Keep secrets in `.env`, never commit keys. Keep `.env.example` up to date.
- UI text in English, stored in translation files.
- At the end of each task, write a short summary: what was built, how to test it manually, what is left.
- At the end of each task, update `docs/PROGRESS.md`: what is done per stage and what is next.
- If something in SPEC.md is unclear or contradictory, stop and ask instead of guessing.

## Business context
- The product is international. First markets: USA and Canada; later the whole world. Nothing in the code may assume
  Canada (or any other country): no hard-coded currency, "$" sign, taxes (GST/PST), provinces, postal code formats,
  phone formats or time zones.
- Currency is set per company (ISO 4217); every amount is stored together with its currency and formatted by it.
- Taxes are configured by each company: several named rates, compound taxes, prices with or without tax.
- Time zone, date/number format (regional format) and address format are per company; the country is chosen when the
  company is created.
- Phone numbers are stored in E.164.
- All UI strings go through translation files; English is the default.
- Verticals: appliance repair is the main one; handyman is the second (own job types, default checklists and services).
  Full construction/renovation projects (multi-phase projects, change orders, progress billing, subcontractor
  management) are out of scope.
- First user: the owner's own appliance repair company (Doctor Appliance, Metro Vancouver). Later the app will be sold
  to other repair companies.
- Small teams: 1–5 people plus occasional subcontractors. The owner often goes on calls himself as a technician.
- Typical flow: phone call → job created in under a minute while still on the phone → diagnosis visit → parts ordered →
  second visit to repair → invoice → payment on site (Square or another provider).
- The technician learns brand, model and serial on site from the rating plate. The office often doesn't know them.
- Technicians use Android phones, often one-handed, in basements and laundry rooms with weak signal.
- Manufacturer warranty jobs are common and are billed to the manufacturer, not the customer.
- A remote office worker handles collections and calls customers.
- Commercial customers (stratas/HOAs, property managers) often pay on terms (e.g. Net 30).
- When unsure, choose the option with fewer taps for a technician in the field.
