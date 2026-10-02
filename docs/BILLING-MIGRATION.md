# Moving subscription billing to a new Stripe account

> Status: **procedure agreed, not built yet.** Subscription billing (SPEC §11) is a separate task before the
> Phase B launch. The command and config names below are the contract for that task; update this page when it
> ships.

The legal entity that owns the app will change. Its Stripe account changes with it, and every company's
subscription has to continue on the new account without being charged early, twice or at a different price.

## Why this works

- **Our database is the source of truth** for each company's plan, price, founding member status, trial end and
  billing anchor date (SPEC §11.5). Stripe only holds billing objects; we keep `stripe_customer_id` and
  `stripe_subscription_id`.
- Stripe can **copy customers and their saved cards** to another Stripe account
  (a data migration request to Stripe; see Stripe's docs on copying data between accounts).
  After the copy:
    - **customer IDs stay the same** (`cus_…`), so `stripe_customer_id` stays valid;
    - **payment method IDs change** (`pm_…`), so we never store them and always read the customer's default
      payment method on the new account;
    - **subscriptions, prices and products are not copied.** We recreate them from our database.

## What we need before starting

- Access to both Stripe accounts (old and new), with the new account fully activated (business details, bank
  account for payouts, tax settings for GST).
- The new account's keys: secret key, publishable key, webhook signing secret.
- A maintenance window outside renewal peaks (avoid the 1st of the month if many anchors fall on it).
- A fresh database backup.

## Procedure

### 1. Request the data copy (days before)

1. In the **old** Stripe account, start the data migration / copy request to the new account, choosing
   "customers and payment methods". Stripe support confirms and runs it; this can take several days.
2. Wait for Stripe's confirmation. Spot-check a few customers on the new account: same `cus_…` ID, card present.

### 2. Freeze changes

1. Put plan changes and new signups on hold (feature flag in the super-admin panel, built with the billing task).
2. Leave the old webhook endpoint running so renewals during the window are still recorded.

### 3. Switch config to the new account

In `.env` on the server (never in code):

```dotenv
STRIPE_KEY=pk_live_...            # new account
STRIPE_SECRET=sk_live_...         # new account
STRIPE_WEBHOOK_SECRET=whsec_...   # new account endpoint
# Old account, kept only until step 7:
STRIPE_OLD_SECRET=sk_live_...
```

Create the webhook endpoint on the new account pointing to the same URL, then run `php artisan config:cache`.

### 4. Dry run

```bash
php artisan billing:migrate-stripe-account --dry-run
```

For every company with an active or trialing subscription it prints: company, plan, price, founding status,
trial end, billing anchor date, whether the customer and a default card exist on the new account, and what it
would create. Fix every "cannot migrate" line (usually a customer without a card) before continuing.

### 5. Run

```bash
php artisan billing:migrate-stripe-account
```

For each company it:

1. finds the customer on the new account by the same `stripe_customer_id`;
2. sets the customer's copied card as the default payment method;
3. creates the price on the new account from **our** stored amount and currency (founding prices included);
4. creates the subscription with:
    - `trial_end` = our trial end, if the company is still in trial;
    - otherwise `billing_cycle_anchor` = the next renewal date from our anchor date, with `proration_behavior=none`,
      so the first charge on the new account is on the date the old one would have charged;
5. stores the new `stripe_subscription_id` and marks the company as migrated.

The command is idempotent: re-running skips companies already migrated. It ends with a report of successes and
failures.

### 6. Verify

- Super-admin panel: every paying company shows the new subscription ID, unchanged price, founding status, trial
  end and anchor date.
- New Stripe dashboard: number of active + trialing subscriptions equals the count in our database; MRR matches.
- Send a test webhook from the new account and check the status updates in our database.

### 7. Cancel the old subscriptions

Only after step 6 is clean:

```bash
php artisan billing:migrate-stripe-account --cancel-old
```

Cancels each migrated company's subscription on the **old** account immediately and without proration or refund,
so no company is billed by both accounts. Then remove `STRIPE_OLD_SECRET` and the old webhook endpoint.

### 8. Unfreeze

Re-enable signups and plan changes. Tell customers that card statements will show the new business name.

## Rollback

- Before step 7 nothing has been charged on the new account unless a renewal date passed. To roll back: cancel
  the subscriptions created on the new account, restore the old `STRIPE_*` keys, restore the previous
  `stripe_subscription_id` values (the command keeps them in `previous_stripe_subscription_id`) and re-cache config.
- After step 7, roll forward instead: fix individual companies on the new account.

## Rules that keep this possible

- Never store card data or payment method IDs in our database.
- Never read plan, price, founding status or dates from Stripe back into our database; webhooks only update
  `subscription_status`.
- Never hardcode Stripe keys, account IDs, product or price IDs.
