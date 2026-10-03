# Server agent handoff

Repository: alikdn25/Doctor-Appliance-App-. MVP PR #11 is merged into main.
Signup and first-run usability are in PR #12, branch chatgpt/self-service-onboarding.
Tested code commit: aedf2a89a9023e277b5eaed41c23d9cab84e699a.
Validation run: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37135218373.
All checks passed: build, PHP style, frontend format/lint, TypeScript, deployment shell syntax,
692 backend tests (5,413 assertions) and 22 desktop/mobile browser scenarios with CSRF protection.
Later documentation-only commits do not change this tested code.

This development workspace has no VPS credentials; production deployment and live email delivery
are not verified here. Grok Bot is the separate agent with server access. Store credentials in its
secure fields or server environment, never in a conversation or GitHub.

## Existing Contabo installation

The user reports https://app.doctor-appliance.ca, VPS 144.126.129.149 and application directory
/var/www/fieldservice. Confirm the actual Nginx root, application owner, PHP version, database,
current commit and clean tracked worktree before changing anything. Adapt DEPLOYMENT.md to the
existing installation. Do not reinstall the OS or reset the database.

1. Back up the database, private media and server .env. Verify that the backups can be read.
   Preserve APP_KEY, user accounts, companies and receipts. Do not print .env or credentials.
2. Confirm that the exact candidate above passed its tests workflow. Do not substitute a newer
   branch head. After PR #12 merges, main can be used if it contains the same tested code.
3. Set APP_NAME="Doctor Appliance" and VITE_APP_NAME="${APP_NAME}" in the server .env if the
   existing installation still says Field Service. Use APP_URL=https://app.doctor-appliance.ca,
   production mode, debug off and secure cookies. Preserve the existing APP_KEY.
4. Check the existing mail configuration securely. Real SMTP needs a working provider, credentials
   and a verified sender. A Bluehost mailbox can be used only with its actual supported SMTP
   settings; do not invent host names, ports or passwords. Request any missing credentials through
   secure fields. Configure the database queue and its Supervisor worker before testing signup.
5. As the application owner, fetch the branch and bring in the tested deployment script first:

```bash
cd /var/www/fieldservice
git fetch --no-tags origin chatgpt/self-service-onboarding
git merge-base --is-ancestor aedf2a89a9023e277b5eaed41c23d9cab84e699a FETCH_HEAD
php artisan down --retry=15
git merge --ff-only aedf2a89a9023e277b5eaed41c23d9cab84e699a
DEPLOY_REF=chatgpt/self-service-onboarding DEPLOY_SHA=aedf2a89a9023e277b5eaed41c23d9cab84e699a bash deploy/deploy.sh
```

Run these commands sequentially and stop on any failure. If fast-forwarding fails, report the
current commits/local changes before choosing a recovery. The deployment script installs
dependencies, builds assets, migrates without resetting data, refreshes caches and restarts the
queue. A failure leaves maintenance enabled for diagnosis. Do not use migrate:fresh or seed the
browser fixtures on this VPS.

## Acceptance checks

- Verify the deployed SHA, HTTPS /up, /login and the Create account link to /register.
- In a private browser on desktop and mobile: register with a controlled real inbox, receive and
  click confirmation, create a company, then open the first customer/job. Owner membership and
  the initial brand are automatic. No company data is available before email confirmation.
- Correct an email typo and verify that a new link goes to the corrected address and the app
  returns to confirmation. Check resend and an expired link. A log mailer is not email delivery.
- Confirm Supervisor worker status, queue:failed and the minute scheduler. Successful /up alone
  does not prove that queued mail is sent. Do not advertise registration until real email works.
- Existing unverified accounts must confirm their email. Valid invitation/password-reset tokens
  also confirm the email. Existing inactive/suspended memberships cannot bypass access through
  company setup. Test an existing employee invitation and existing company access.
- Two-factor enrollment is optional for every role. Legacy AUTH_REQUIRE_TWO_FACTOR flags are
  ignored. Existing enabled authenticator-based 2FA still challenges the user until they disable
  it in Security settings. Email login codes are a future task, separate from signup confirmation.
- Perform the remaining manual checks in LAUNCH_TESTING.md, including private receipts, tenant
  access, Maps credentials, Square sandbox callbacks and a separate backup restoration test.

Report the deployed SHA, URL, actual email receipt, worker/scheduler checks, desktop/mobile
results and remaining blockers. Stage 2/3 features and platform subscription billing are not
completed by this signup update.
