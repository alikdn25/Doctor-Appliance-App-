# Website booking bot API

For the booking assistant on the company website (Claude through the API, with tools that call these endpoints).
The Owner creates a token in **Company settings → Booking bot**; the token is shown once.

Every request: `Authorization: Bearer <token>`, `Accept: application/json`. Base URL: `https://<app domain>/api/bot/v1`.

The bot can only: see free arrival windows, find a customer by phone, add a customer, book a job. It cannot change
or delete anything and never sees invoices, payments, prices or reports. Everything it does is in the change log
marked "Booking bot"; every booking is pushed to the company's Owners.

Limits per token (set by the Owner): requests per minute (default 30) and new bookings per hour (default 5). Over a
limit the answer is `429` with `Retry-After` (seconds).

## GET /windows?from=YYYY-MM-DD&days=7

Free two-hour arrival windows (9–11, 11–1, 1–3, 3–5 in the company's time zone) on open business days, from today
(or `from`) for up to 30 days. Windows that already started are not offered.

```json
{
    "timezone": "America/Vancouver",
    "windows": [{ "date": "2026-10-19", "start": "11:00", "end": "13:00" }]
}
```

## GET /customers?phone=6045550101

```json
{
    "found": true,
    "customer": {
        "id": 12,
        "first_name": "Maria",
        "addresses": [{ "id": 30, "street": "12 Main St", "city": "Surrey" }]
    }
}
```

Only the first name and the street of each address, so the bot can confirm "Is it the address on Main St?".

## POST /customers

```json
{
    "first_name": "Anna",
    "last_name": "Kim",
    "phone": "604-555-0303",
    "email": "anna@example.com",
    "address": {
        "line1": "5 Oak Ave",
        "unit": "2",
        "city": "Burnaby",
        "region": "BC",
        "postal_code": "V5H 1A1"
    }
}
```

`201` `{"customer_id": 40, "address_id": 51, "existing": false}`. A phone that already belongs to a customer returns
`200` with that customer and `"existing": true` (nothing is changed).

## POST /bookings

```json
{
    "customer_id": 40,
    "address_id": 51,
    "date": "2026-10-19",
    "start": "11:00",
    "appliance": "Washer",
    "problem": "Does not drain"
}
```

`201` `{"job_number": 1042, "date": "2026-10-19", "start": "11:00", "end": "13:00"}`. The job is created as a repair
from the website, with a visit in that window and nobody assigned yet. A window taken in the meantime returns `422`
with an error on `start`: offer the next free window.

Errors: `401` bad or revoked token, `403` company unavailable, `404` customer or address not in this company, `422`
invalid data, `429` over a limit.
