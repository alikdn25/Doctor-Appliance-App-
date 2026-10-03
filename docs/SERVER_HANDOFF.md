# Server agent handoff

Repository: alikdn25/Doctor-Appliance-App-. MVP PR #11 is merged into main.
Signup, first-run usability, the seven October 3 feedback fixes, Grok follow-up and mail/admin
supplement are in PR #12, branch chatgpt/self-service-onboarding. FEEDBACK_2026_10_03.md,
FEEDBACK_FOLLOWUP_2026_10_03.md and FEEDBACK_MAIL_ADMIN_2026_10_03.md record the changes.
PR #12 was merged into main on October 3, 2026. Merge commit:
b3a96c2ad0009a591466e1b9c18f9c122b6b8a07. Its tree is identical to the validated
documentation head 3fc1682d327be0f3fe191592e9487d400c647f64. Fetch main for installation;
the tested code candidate below is now an ancestor of main.
Tested code commit: c350d177aac10213e43750c16ccc4fdcee5934ac.
Validation run: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37146504326.
All checks passed: build, PHP style, frontend format/lint, TypeScript, deployment shell syntax,
740 backend tests (5,984 assertions) and 40 desktop/mobile browser scenarios with CSRF protection.
The browser total includes a separate mail-disabled pass; no real mail provider is used in CI.
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
4. Mail is currently reported disconnected. Set AUTH_EMAIL_DELIVERY_ENABLED=false and
   AUTH_EMAIL_VERIFICATION_REQUIRED=true in the server environment. With delivery disabled,
   ordinary working access and signup must not require confirmation; emails remain unverified.
   New staff/administrator-created Owners can receive manual initial passwords from their forms.
   Check the existing mail configuration securely. Real SMTP needs a working provider, credentials
   and a verified sender. Use a Bluehost mailbox only with its actual supported SMTP settings;
   do not invent host names, ports or passwords. Request missing credentials through secure fields.
   Configure the database queue and Supervisor worker, verify real receipt using the actual provider,
   then set AUTH_EMAIL_DELIVERY_ENABLED=true. Refresh configuration and restart the queue after
   changing readiness. Keep verification required when delivery is enabled; do not set readiness true
   merely because SMTP fields are populated or /up is healthy.
5. As the application owner, fetch the branch and bring in the tested deployment script first:

```bash
cd /var/www/fieldservice
git fetch --no-tags origin main
git merge-base --is-ancestor c350d177aac10213e43750c16ccc4fdcee5934ac FETCH_HEAD
php artisan down --retry=15
git merge --ff-only c350d177aac10213e43750c16ccc4fdcee5934ac
DEPLOY_REF=main DEPLOY_SHA=c350d177aac10213e43750c16ccc4fdcee5934ac bash deploy/deploy.sh
```

Run these commands sequentially and stop on any failure. If fast-forwarding fails, report the
current commits/local changes before choosing a recovery. The deployment script installs
dependencies, builds assets, migrates without resetting data, refreshes caches and restarts the
queue. A failure leaves maintenance enabled for diagnosis. Do not use migrate:fresh or seed the
browser fixtures on this VPS. After deployment, reload the actual PHP-FPM service used by Nginx
so workers do not retain old PHP/opcache state. Determine the installed service rather than guessing
the PHP version. Confirm the existing queue worker is running after queue restart.

The feedback migrations add private purchase-cost ownership and durable responsibility for transferred
jobs. They run after the existing billing tables, preserve historical records and do not remove legacy
markup columns. Existing document costs are attributed to the document creator; unknown price-book
cost authors remain hidden. Review attribution on a controlled copy of older records before launch.

## Acceptance checks

- If the existing session is at /email/verify, do not use Resend while mail is disconnected. First
  install this code and verify that runtime auth.email_delivery_enabled is false after configuration
  refresh. A stale cached setting or old PHP worker can retain the verification wall. Reload the page
  and check ordinary working login; do not mark the owner's email verified just to hide the barrier.
- Verify the deployed SHA, HTTPS /up, /login and the Create account link to /register.
- With mail disabled, use a private browser on desktop and mobile: register, create a company,
  open the first customer/job, add a new Technician with a confirmed password and sign in as them.
  No confirmation wall or fake mail-success message may appear. Emails remain unverified.
  The Owner and first brand are automatic. Password recovery must explain mail is unavailable.
- When enabling delivery, separately verify actual receipt, click confirmation, correct an email
  typo, check resend and an expired link. Unverified existing accounts then require confirmation;
  the confirmation screen sends their first link. A log mailer is not delivery. Valid invitation/reset
  tokens also confirm email. Existing inactive/suspended memberships cannot bypass restrictions.
- Confirm Supervisor worker status, queue:failed and the minute scheduler. Successful /up alone
  does not prove queued mail is delivered. Report mail readiness independently from work access.
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
  An entirely empty Invoices list should explain that there are no invoices yet, rather than blame filters.
- In platform company details, check separate workspace/billing explanations, unassigned plans,
  singular counts, direct sign-in versus support access, and labelled company-zone times. Ordinary
  logout from support access closes its log. Historical entries without an end must say the end is
  unrecorded, without claiming they are active; administrator notes must be labelled as such.
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
