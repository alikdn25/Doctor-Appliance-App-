# Automated MVP browser checks

GitHub Actions runs the built Laravel application on PostgreSQL and Chromium at desktop and phone widths.
Owner authentication passes the real password and TOTP challenge; a technician has a separate session.
The HTTP server runs in local mode with CSRF protection; only the database fixture command uses testing mode.
All accounts, phone numbers, receipts and documents are isolated test fixtures. No live SMS is sent:
the provider points to a loopback stub, which records the actual destination and text.

Covered flows: saving customer context and manual icons, booking with that context, an old unfinished job,
calendar map fallback and person filter, a business expense with actual named taxes and a private receipt,
custom tax settings, inbox read/reply, invoice/public totals and PDF download, technician expense isolation,
foreign customer denial, queued job photos and drawn signatures, core office screens, dark mode,
uncaught JavaScript errors and document width.

Public signup coverage uses a separate anonymous session on each screen size: registration,
correction of an email typo, signed email confirmation, first-company setup with country/timezone,
and the first customer screen without mandatory 2FA. These tests read the isolated log mailer;
they do not verify delivery to a real inbox.

The workflow uploads browser-smoke-results for seven days. The HTML report includes desktop/mobile screenshots;
failed scenarios retain traces. Authentication state JSON files are excluded from the upload.
These are functional browser checks. Real phone camera behavior, visual review, Google Maps credentials,
Square sandbox callbacks, Twilio delivery, production queue/scheduler and backup restoration still need
the server checklist in LAUNCH_TESTING.md.

To reproduce on a disposable testing database: install PHP/Node dependencies, copy .env.example, generate APP_KEY,
build assets, set APP_ENV=testing and the test DB_DATABASE, and configure the browser-step environment from
.github/workflows/tests.yml. Run migrate:fresh, db:seed --class=BrowserSmokeSeeder, then npm run test:browser.
Never point this reset/fixture workflow at business data. The seeder refuses other application environments.
