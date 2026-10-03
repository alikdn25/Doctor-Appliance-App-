# October 3 follow-up: Grok's live walkthrough

This report covers only the newly supplied live walkthrough. The earlier seven-point report is
in `FEEDBACK_2026_10_03.md` (and its implementation remains part of PR #12).

## Version mismatch

At inspection, GitHub main was `5778f621d385de4437211509536a9831da9eee14` and PR #12 was open,
not merged. Updating only main cannot install PR #12. The live server's current commit was not
independently checked here. Grok's old dashboard text, markup tiers and raw timezone identifiers
match the earlier implementation. Ask the server operator to report the served checkout's SHA,
then deploy the exact tested code in `SERVER_HANDOFF.md` and check the built assets.

## Additional implementation

- Book customer now opens a short form, not the full job form. A caller's name, phone and arrival
  window are enough. The company timezone and solo assignee are prefilled. Existing customers can
  be searched by name/phone; their preferences and saved addresses appear before saving. No
  automatic merge is performed for a shared phone number.
- A new caller, job and first visit are saved in one transaction. Optional address/problem/customer
  notes are under Address and details. An unprovided address is stored as blank, visibly marked
  Address not added yet, and has no navigation link. The normal detailed customer/job forms still
  require an address. Add it from the customer's profile before traveling or sending documents.
- The calendar has Book customer plus a Book on this day action on an empty selected day. The date
  carries into the short form. Dashboard booking also uses this flow.
- New job is exercised through its actual list button in browser tests. The detailed form's Save
  is disabled until a customer/property or the required new-customer details are present. The
  server rejects missing or foreign customer/property IDs even if the UI guard is bypassed.
- New invoice is visible on empty and populated invoice lists. It offers visible existing jobs or
  a short customer entry; if no jobs exist, it goes directly to customer entry. Continuing creates
  a job and opens its prices without an unnecessary appointment. The invoice is created only when
  the pricing form is saved. Tenant and office-role checks remain in force.
- An unused account alone no longer means Invited. The badge requires a pending invitation/reset
  token and no previous login. Accepting the link removes the token. This avoids labeling a manually
  created owner Invited merely because their first visit used support access.
- Dashboard explicitly explains a missing brand and links the Owner to setup. Its company-name
  subtitle does not append a second period to a name already ending in punctuation.

## Existing corrections to verify after deployment

The working login, separate platform administration, persistent sessions, optional 2FA, visible
mobile Menu, private manual purchase prices and searchable UTC/city/country labels are already in
PR #12. The real Team URL is `/company/team`; there is no `/company/members` route. Access must be
checked through Team with an actual Owner/Admin account, separate from platform support access.

## Validation and deployment

Automated results and the tested deployment SHA will be recorded after CI completes. Live email
delivery, the existing account's workspace selection and VPS deployment remain operator checks;
read-only walkthroughs do not install changes. See `SERVER_HANDOFF.md` for the complete sequence.
