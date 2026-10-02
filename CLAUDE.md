# Instructions for Claude Code

Read `SPEC.md` before any task. It is the source of truth for scope and stages.

## Rules
- Reply to the user in Russian. Keep code, comments, commit messages and UI text in English.
- Stack: Laravel (latest stable) + Inertia.js + React + TypeScript + Tailwind, PostgreSQL, Redis.
- Multi-tenant: every tenant-owned table has `company_id`; all queries are tenant-scoped. Never break isolation. Add tests for it.
- Work only on the stage/task requested. Do not build features from later stages.
- Mobile-first UI. Technician screens must work well on a phone.
- Every feature ships with tests. Run tests before finishing.
- Keep secrets in `.env`, never commit keys. Keep `.env.example` up to date.
- UI text in English, stored in translation files.
- At the end of each task, write a short summary: what was built, how to test it manually, what is left.
- At the end of each task, update `docs/PROGRESS.md`: what is done per stage and what is next.
- If something in SPEC.md is unclear or contradictory, stop and ask instead of guessing.

## Business context
- First user: the owner's own appliance repair company (Doctor Appliance, Metro Vancouver). Later the app will be sold to other appliance repair companies.
- Small teams: 1–5 people plus occasional subcontractors. The owner often goes on calls himself as a technician.
- Typical flow: phone call → job created in under a minute while still on the phone → diagnosis visit → parts ordered → second visit to repair → invoice → payment on site via Square.
- The technician learns brand, model and serial on site from the rating plate. The office often doesn't know them.
- Technicians use Android phones, often one-handed, in basements and laundry rooms with weak signal.
- Manufacturer warranty jobs are common and are billed to the manufacturer, not the customer.
- A remote office worker handles collections and calls customers.
- Canada/BC: CAD, GST/PST, Canadian addresses and postal codes.
- When unsure, choose the option with fewer taps for a technician in the field.
