# Instructions for Claude Code

Read `SPEC.md` before any task. It is the source of truth for scope and stages.

## Rules
Reply to the user in Russian. Keep code, comments, commit messages and UI text in English.
Stack: Laravel (latest stable) + Inertia.js + React + TypeScript + Tailwind, PostgreSQL, Redis.
Multi-tenant: every tenant-owned table has company_id; all queries are tenant-scoped. Never break isolation. Add tests for it.
Work only on the stage/task requested. Do not build features from later stages.
Mobile-first UI. Technician screens must work well on a phone.
Every feature ships with tests. Run tests before finishing.
Keep secrets in .env, never commit keys. Keep .env.example up to date.
UI text in English, stored in translation files.
At the end of each task, write a short summary: what was built, how to test it manually, what is left.
If something in SPEC.md is unclear or contradictory, stop and ask instead of guessing.
