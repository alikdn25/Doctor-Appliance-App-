# First-launch verification

Code commit 2a891aa0d76fed8d80f0ad12727e3e8938e2038f passed build, PHP style, frontend formatting/lint,
TypeScript, deployment script syntax, all 674 backend tests (5,276 assertions) and 20 desktop/mobile
browser scenarios: https://github.com/alikdn25/Doctor-Appliance-App-/actions/runs/37090807431.
See BROWSER_TESTING.md for the automated coverage and screenshot report. The HTTP server used active CSRF
protection; SMS went to a loopback test provider. Physical devices and live integrations remain below.
This checklist records remaining manual checks; none are marked complete by automated tests alone.

1. Sign in as an Owner, Admin and Technician; check 2FA and switching companies.
2. Create a customer with About the customer: "Text only; child sleeping. Arrive on time."
   Select that customer when booking, then open the assigned job as its technician. Confirm the note appears.
   Edit the note and open another job: it must show the same updated note. Confirm another company cannot
   search/open the customer or see its notes, jobs, invoices or messages.
3. Enter Maria, James, Alex and an unknown name. Check woman/man/neutral/neutral suggestions. Choose neutral
   manually, save, rename and reopen: the choice must persist. Check a business customer and long names.
4. Leave yesterday's work incomplete, open Not completed jobs, schedule a return, wait for parts/customer,
   close/reopen a job and check the bar and queue under each role.
5. Configure six named taxes. Apply one to labor, two to a part and none to another line. Compare invoice,
   PDF, public approval, optional-item selection, revisions and conversion; disable a rate and reopen an old document.
6. Add Fuel, Lunches and Tools business expenses with two receipt taxes, change an actual receipt tax amount,
   attach a camera photo and compare price/tax/total by category and employee. Check CSV and technician access.
7. On Calendar Map, choose a day/person, click each marker and open directions. Include an address without
   coordinates and visits with several assignees. Check empty days, narrow screens, Google API failure and
   a route with more than four stops. Verify browser-key domain restrictions and the production map ID.
8. Follow-ups are off by default. Enable a delay in Company settings; send an estimate. On a testing company,
   use estimates:send-followups --force after the delay. Check one message, repeat the command, approve/decline
   or expire a separate estimate, and close a job. No second reminder or reminder for those excluded cases.
   Check SMS STOP/quiet hours/email fallback and the editable estimate-follow-up template.
9. On the server, verify HTTPS, queue worker, minute scheduler, email delivery, private receipts/photos,
   database/files backups and a restore into a separate test database. Test Square sandbox callbacks before live payments.
10. Inspect iPhone/Android widths, long addresses, dark mode, keyboard focus, date/time formatting and fixed action bars.
11. Send a controlled customer SMS reply. Open SMS Inbox as two office users; verify their separate unread counts.
    Read a conversation, search old messages, reply to a secondary phone and compare the actual destination.
    Test STOP, US registration, quiet hours, unknown/shared numbers and archived jobs. Restrict an office user's
    brands and confirm other-brand/other-company conversations are inaccessible. Technicians use job messaging.

Record the tested commit, server URL, browser/device, date and result for each manual check before release.
Stage 2/3 and platform subscription billing remain outside the completed first-launch work.
