# UX notes

## Signed in as one person, then suddenly as another (October 14, 2026)

**What happened.** The owner was signed in as Owner and saw all jobs; later, without doing anything, the app showed
another account and only that account's jobs.

**Cause.** The temporary "sign-in without a password" mode (`AUTH_AUTO_LOGIN_EMAIL`, October 5). While it was set,
every visitor _without a session_ was signed in silently as the account in that setting, with "remember me". The
owner's own session was fine until it ended for any reason: the session expired or was cleared (deployment, cache or
cookie cleanup, browser storage cleanup on the phone, a new APP_KEY), a sign-out on that device, or a password reset
that signs out other devices. On the very next page the middleware signed the phone in as the configured account, which saw only its own
jobs. No click was needed, so it looked like a spontaneous switch.

A second, smaller hole made the same thing possible without that setting: signing in _without_ "remember me" left
another person's "remember me" cookie on the device (for example a technician who had used the same phone). When the
session later ended, Laravel signed that person back in from their cookie.

**Checked and not the cause.** Support access (it needs the platform admin to press "Support access", is logged in
`impersonation_logs`, and shows a banner); the service worker (pages are never cached, only built assets); server
caches (no user data is cached); the session cookie domain (one host).

**Fix.**

- The auto-login mode is removed (middleware, setting and `.env` key). Everyone signs in with their own account;
  sessions last 30 days (`SESSION_LIFETIME=43200`) and "remember me" keeps the phone signed in longer.
- Every sign-in without "remember me" deletes any remember cookie left on the device.
- Pages of a signed-in person are sent with `Cache-Control: no-store, private`, so Back or a cached copy never shows
  them after a sign-out or to the next person on the phone.
- Page data in the browser history is encrypted (Inertia history encryption) and the history is cleared at each sign-in
  and on the sign-in page.
- Sign-out also stops push notifications on that device.
- The menu always shows the company, the person's name and role (top) and their email (bottom), so a wrong account is
  noticed at once.
- Tests: `tests/Feature/DataProtection/AccessAndSessionTest.php` (each role sees exactly its data, sign out and in as
  someone else, no automatic sign-in, remember cookie dropped, no-store, who is signed in).

**For the owner.** After the update, sign in once with your own email and password. If the password is not known
(it was never needed while auto-login was on), the server agent resets it: `php artisan app:reset-password <email>
--generate` (SERVER_HANDOFF.md).
