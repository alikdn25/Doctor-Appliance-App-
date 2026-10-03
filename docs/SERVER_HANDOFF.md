# Server agent handoff

Repository: alikdn25/Doctor-Appliance-App-. MVP PR #11 is merged into main.
Signup, first-run usability, the seven October 3 feedback fixes and Grok follow-up are in PR #12,
branch chatgpt/self-service-onboarding. FEEDBACK_2026_10_03.md and
FEEDBACK_FOLLOWUP_2026_10_03.md record the behavior changes. PR #12 is not merged at this audit;
a pull of main alone does not install its corrections.
Tested code commit: c02a95db7edaf618787c03340bd216c77d49a9ad.
Validation run: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37143711689.
All checks passed: build, PHP style, frontend format/lint, TypeScript, deployment shell syntax,
719 backend tests (5,752 assertions) and 36 desktop/mobile browser scenarios with CSRF protection.
Later documentation-only commits do not change this tested code.

This development workspace has no VPS credentials; production deployment and live email delivery
are not verified here. Grok Bot is the separate agent with server access. Store credentials in its
secure fields or server environment, never in a conversation or GitHub.

## Existing Contabo installation

The user reports https://app.doctor-appliance.ca, VPS 144.126.129.149 and application directory
/var/www/fieldservice. Confirm the actual Nginx root, application owner, PHP version, database,
current commit and clean tracked worktree before changing anything. Adapt DEPLOYMENT.md to the
existing installation. Do not reinstall the OS or reset the database.

Start by reporting only the commit and changed file paths (no secrets):

```bash
cd /var/www/fieldservice
git rev-parse HEAD
git status --short
```

The successful loading of CSS/JS does not establish that the build is current. Compare the SHA
with the tested candidate above and check the new screens after rebuilding. Do not repeat the
old read-only walkthrough and report deployment complete without installing this candidate.

1. Back up the database, private media and server .env. Verify that the backups can be read.
   Preserve APP_KEY, user accounts, companies and receipts. Do not print .env or credentials.
2. Confirm that the exact candidate above passed its tests workflow. Do not substitute a newer
   branch head. After PR #12 merges, main can be used if it contains the same tested code.
3. Set APP_NAME="Doctor Appliance" and VITE_APP_NAME="${APP_NAME}" in the server .env if the
   existing installation still says Field Service. Use APP_URL=https://app.doctor-appliance.ca,
   production mode, debug off and secure cookies. Preserve the existing APP_KEY.
   Set SESSION_LIFETIME=43200 (30 days): the old server value overrides the new code default.
4. Check the existing mail configuration securely. Real SMTP needs a working provider, credentials
   and a verified sender. A Bluehost mailbox can be used only with its actual supported SMTP
   settings; do not invent host names, ports or passwords. Request any missing credentials through
   secure fields. Configure the database queue and its Supervisor worker before testing signup.
5. As the application owner, fetch the branch and bring in the tested deployment script first:

```bash
cd /var/www/fieldservice
git fetch --no-tags origin chatgpt/self-service-onboarding
git merge-base --is-ancestor c02a95db7edaf618787c03340bd216c77d49a9ad FETCH_HEAD
php artisan down --retry=15
git merge --ff-only c02a95db7edaf618787c03340bd216c77d49a9ad
DEPLOY_REF=chatgpt/self-service-onboarding DEPLOY_SHA=c02a95db7edaf618787c03340bd216c77d49a9ad bash deploy/deploy.sh
```

Run these commands sequentially and stop on any failure. If fast-forwarding fails, report the
current commits/local changes before choosing a recovery. The deployment script installs
dependencies, builds assets, migrates without resetting data, refreshes caches and restarts the
queue. A failure leaves maintenance enabled for diagnosis. Do not use migrate:fresh or seed the
browser fixtures on this VPS.

The feedback migrations add private purchase-cost ownership and durable responsibility for transferred
jobs. They run after the existing billing tables, preserve historical records and do not remove legacy
markup columns. Existing document costs are attributed to the document creator; unknown price-book
cost authors remain hidden. Review attribution on a controlled copy of older records before launch.

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
- For the user's existing platform-admin login: return from support access, sign in as the actual
  account, open the workspace selector or the company's Open workspace action once and select Doctor
  Appliance. This creates a real Owner membership. Subsequent normal logins open work, without Login
  as or a reason prompt; platform administration remains a separate destination. A regular invited
  technician should go directly to My jobs. Check Remember me and reopening the browser.
- As Owner and office Admin, open Team at /company/team (there is no /company/members route). Check that Admin can manage a Technician but cannot alter
  an Owner or promote roles. Verify the visible Menu and permanent Book customer on working screens,
  and Calendar booking with its selected date. Book customer must open the short name/phone/time
  form, including Book on this day on an empty calendar day. Save a controlled new caller without
  an address; confirm customer/job/visit and Address not added yet, then add the address in the
  customer profile. Test existing-customer search and preferences. Check UTC offsets and Vancouver/Canada search.
- From Jobs, click New job (without typing its URL). Save stays unavailable without a customer.
  From an empty/populated Invoices list, click New invoice. Choose an existing job or enter a new
  caller and continue to prices. No invoice exists until its pricing form is saved. Check that an
  unused manually created Owner account does not show Invited without a pending invitation.
- If there are no active brands, the dashboard explains the requirement and directs the Owner to
  create the first brand. Normal signup/workspace selection creates the first brand automatically.
- Enter a part and a material with manual customer prices and private purchase prices as a Technician;
  confirm the difference without changing the quoted prices. Another technician, office user and
  customer PDF/public page must not reveal private purchase costs or their difference/profit.
- Transfer unfinished work including a completed diagnosis waiting for parts. Confirm the replacement
  sees the backlog, the former technician's completed visit retains its name and a later reschedule
  follows the current assignment. In a controlled test, replace a company-only technician using the
  same email and a new password; check the old session/password fail and historical names remain.
  Shared accounts must use ordinary transfer/deactivation instead of a one-company credential reset.

Report the deployed SHA, URL, actual email receipt, worker/scheduler checks, desktop/mobile
results and remaining blockers. Stage 2/3 features and platform subscription billing are not
completed by this signup update.
