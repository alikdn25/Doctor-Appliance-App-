# Server agent handoff

The repository is alikdn25/Doctor-Appliance-App-. Current work is in draft PR #11, branch chatgpt/bolt-ui.
Last verified code commit: 2a891aa0d76fed8d80f0ad12727e3e8938e2038f.
Passing checks: build, PHP style, frontend format/lint, TypeScript, deployment shell syntax,
674 backend tests (5,276 assertions) and 20 desktop/mobile browser scenarios with CSRF protection.
Passing run: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37090807431.
Browser report/screenshots: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37090807431/artifacts/11261694174.
The following documentation-only update records these results. There is no server credential attached to this development workspace. A GitHub connection alone does not give
SSH access. Use a server agent with SSH credentials stored in its environment or the production GitHub environment;
never paste passwords/private keys into a chat or commit them.

## Task for the agent with server access

1. Confirm the OS, domain/DNS, SSH user, application path and whether an app/database already exist. The setup in
   DEPLOYMENT.md assumes Ubuntu 24.04, PHP 8.3 and PostgreSQL. Adapt versions/socket paths to the actual server.
   Save and verify database/media backups before changing an existing installation; do not overwrite other sites.
2. Select the exact code commit whose tests workflow passed. Retrieve PR #11 and its current test run, rather
   than assuming the branch head is tested. For a first testing install, check out that commit from chatgpt/bolt-ui.
   Keep Square in sandbox while testing. Follow-ups stay off until enabled in the testing company's settings.
3. Install the packages, database, deploy user and read-only repository access from DEPLOYMENT.md. Configure
   the server .env with a fresh APP_KEY, production mode, debug off, HTTPS URL, secure cookies and database details.
   Preserve the APP_KEY on subsequent updates because existing encrypted data depends on it.
4. Configure Nginx and a trusted TLS certificate, writable storage, the Supervisor queue worker and the minute
   scheduler. Use the example config files; update their domain, app directory and PHP-FPM socket.
5. On first install, install dependencies/build, run migrations and app:deployment-check, then create the
   super-admin interactively. For later updates use DEPLOY_REF=chatgpt/bolt-ui DEPLOY_SHA=<tested-code-sha>
   bash deploy/deploy.sh for this testing branch. The normal GitHub deploy workflow accepts main only.
6. Confirm /up and /login over HTTPS; check worker status, schedule:list and queue:failed. A successful /up alone
   does not prove that queued emails or scheduled reminders execute. Send a testing-company document to a controlled
   inbox, upload/download a private receipt, and verify another company/technician cannot open it.
7. Perform LAUNCH_TESTING.md on desktop and narrow mobile screens. Attach the production-restricted Maps browser
   key/map ID, test Square sandbox callbacks, and configure Twilio only when outbound SMS testing is intended.
8. Report the deployed commit, URL, worker/scheduler result, manual test outcomes and remaining blockers. Do not
   report Stage 2/3 or subscription billing complete. Record a backup restore test before allowing business data.

MVP code is ready for a testing installation by an agent with server access. Automated desktop/mobile browser
checks have passed. Server configuration/deployment, physical-device visual checks, live integrations and
backup restoration have not happened in the current development workspace.
