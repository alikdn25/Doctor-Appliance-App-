# October 3 supplement: mail readiness and administration

This report covers only the new comments in Grok's supplemental live audit. Earlier booking, pricing, navigation and login corrections remain recorded in FEEDBACK_2026_10_03.md and FEEDBACK_FOLLOWUP_2026_10_03.md. PR #12 was merged into main on October 3, 2026, at b3a96c2ad0009a591466e1b9c18f9c122b6b8a07. Server installation still requires the operator to deploy the candidate.

## Mail-dependent account access

- Email readiness is controlled by the operator, after a real delivery test. `AUTH_EMAIL_DELIVERY_ENABLED` defaults false; `AUTH_EMAIL_VERIFICATION_REQUIRED` defaults true and is effective only with delivery enabled.
- With delivery off, ordinary Owners/Admins/technicians and separate administrator support access reach working screens without `/email/verify`. Public signup proceeds to company setup. Emails remain unverified, and tenant isolation, inactive account checks, suspended-company checks and role permissions remain enforced.
- Registration and setup omit the email confirmation step when it cannot deliver. Password recovery and resend do not claim to send unavailable mail. Valid existing invitation/reset tokens continue to work.
- Owners/Admins can give new staff an initial confirmed password while mail is off; the platform administrator can do the same for a new company Owner. Existing accounts retain their passwords. Invitation resend is unavailable while delivery is off. Password values are not audit content or validation old input.
- After delivery is enabled, unverified accounts must confirm. The confirmation screen sends a first signed link for accounts created while mail was off; refreshing does not repeatedly send it. Resend remains throttled. There is no automatic relaxation on a mail outage once confirmation is enabled.

## Honest empty and administrative states

- An invoice list with no visible invoices says “No invoices yet. Create your first invoice.” The filter message is used only if visible invoices exist but filters hide them. This does not reveal invoices in inaccessible brands.
- Workspace access, optional plan name and billing status are explained independently. Missing plans/statuses are explicitly unassigned; no subscription tier is invented. Company, brand and active-member counts use the singular for one.
- A member with no direct login is labelled “No direct sign-in recorded.” Their latest support access is shown separately, so visiting via support does not pretend the member signed in personally.
- New support-start audit records identify the member as the actor and the administrator as the supporter. Historical self-duplicates are displayed without “Alex via Alex”; stored history is preserved.
- Return to admin and ordinary logout close support records. A historical record without an explicit end says so, rather than claiming an active connection. Browser closures and expired sessions do not acquire fabricated end times.
- Support/audit timestamps carry ISO offsets and are displayed in the selected company's time zone, with that zone labelled. Optional support notes are labelled as administrator-entered text and rendered as plain text.

SMS settings already exist in the company's working Messaging section. The audit could not reach that section because confirmation blocked access; this supplement removes that barrier when mail is unavailable. SMS delivery and A2P registration still depend on the company's actual provider setup.

## Validation and server status

Tested code commit: c350d177aac10213e43750c16ccc4fdcee5934ac. All checks passed in [run 37146504326](https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37146504326): build, deployment script syntax, PHP style, frontend format/lint, TypeScript, 740 backend tests (5,984 assertions) and 40 desktop/mobile browser scenarios. The browser total includes 36 existing scenarios and four dedicated mail-disabled scenarios; those four are intentionally skipped in the mail-enabled pass and run separately with delivery disabled. Mobile screenshots of manual staff access and unavailable recovery were inspected.

New tests cover mail-disabled signup and direct work access, later confirmation, permission boundaries, manual staff credentials, truthful support history and invoice empty states. Desktop/mobile browser checks include the complete mail-disabled signup-to-staff-login flow.

This workspace does not have VPS access. Installing the tested candidate and checking actual SMTP delivery remain with the server operator; use SERVER_HANDOFF.md. Main now contains the corrections. The server operator must install the code, set mail readiness false while delivery is unavailable, refresh configuration and reload PHP-FPM before repeating the login check.
