# Design system — Doctor Appliance

Approved mockup (source of truth for look and layout):
https://claude.ai/artifact/WWiBsi8bFjkdVkQqLMCYrv — canvas "Doctor Appliance — field screens".

Screens on the canvas (phone = 390 px wide, desktop = 1440 px):

Field screens (approved):

1. My Jobs (phone)
2. Job page
3. Finish visit
4. Select appliance
5. Status sheet — every job, visit, invoice and estimate status

Booking, invoice and payment (phone):

6. Book customer — phone first (finds an existing customer), name, optional address, appliance tile, problem, day and
   arrival window chips, technician, collapsed "More options" (manufacturer warranty, lead source, note), confirmation
   text checkbox. One "Book job" button that repeats the chosen time.
7. Invoice — bill to Customer or Manufacturer, item lines (price book or custom line), subtotal, company taxes, total,
   balance due, repair warranty; pinned "Send" and "Take payment".
8. Take payment — large balance, partial payment link, 5 methods (card via Square, cash with change, check, bank
   transfer, payment link); tip chips only for card; one button that names the action and amount.
9. Paid — green raised check, amount, tip, method, receipt sent, scheduled Google review request, back to My Jobs.
10. Review request — recipient, Text / Email, message preview (same text for everyone, no incentives or gating), when:
    in 2 hours (default) / now / don't ask this time.

Calendar:

11. Day (desktop) — one column per technician plus Unassigned, hour grid, red "now" line, "To schedule" queue to drag
    from.
12. Week (desktop) — 7 days, technician filter chips, closed days hatched, technician dot on each visit.
13. Map (desktop) — route list per technician with numbered stops, map with numbered pins in technician colors.
14. Day (phone) — 7-day strip, technician chips, visit cards in time order; free gaps of 1 h+ offer "Book".
15. Map (phone) — map on top, route sheet below, "Navigate to next stop".

Interactive states (selected option, tabs, filters) can be clicked on the canvas.

All images (appliances, avatars) are stored inside that artifact.

## Principle: volumetric, never flat

Everything pressable is a raised, soft, rounded shape:

- Color runs as a gradient from left (lighter) to right (darker).
- A vertical highlight on top (light at the top, faint dark at the bottom).
- Soft "spherical" edges: no border; inner shadows are light on the top and left, dark on the bottom and right.
- Corner radius 16–18 px (14 px for small icon buttons, pill for badges).
- A soft drop shadow under the element.
- On press the element sinks by 2 px: `transform: translateY(2px) scale(.99)`.

## Colors

| Token           | Value                                                                                       |
| --------------- | ------------------------------------------------------------------------------------------- |
| Page background | `#EEF3FA`                                                                                   |
| Text            | `#0F1B2D`                                                                                   |
| Secondary text  | `#5B6779`                                                                                   |
| Link            | `#0A6CF5` (hover `#0050D0`)                                                                 |
| Header          | `linear-gradient(90deg, #3535FF 0%, #000000 100%)` (bright blue to black, as on the canvas) |

The dark-blue header sits above a light "sheet" (page background) with rounded top corners.

## Components

**Primary button** (gradient taken from ChatGPT mockups), white text with shadow:

```css
background:
    linear-gradient(
        180deg,
        rgba(255, 255, 255, 0.3) 0%,
        rgba(255, 255, 255, 0) 55%,
        rgba(0, 20, 80, 0.14) 100%
    ),
    linear-gradient(90deg, #4cbdfe 0%, #1e8cfd 35%, #0567f5 65%, #0046cc 100%);
color: #fff;
text-shadow: 0 1px 2px rgba(0, 30, 90, 0.45);
box-shadow:
    inset 0 2px 2px rgba(255, 255, 255, 0.55),
    inset 0 -4px 8px rgba(0, 30, 110, 0.38),
    inset 4px 0 8px rgba(255, 255, 255, 0.22),
    inset -5px 0 10px rgba(0, 20, 90, 0.3),
    0 12px 20px -6px rgba(5, 103, 245, 0.6),
    0 3px 6px rgba(0, 50, 140, 0.25);
```

**Secondary button** — white to `#E1EAF6`:

```css
border: 0;
border-radius: 16px;
background:
    linear-gradient(
        180deg,
        rgba(255, 255, 255, 0.95) 0%,
        rgba(255, 255, 255, 0) 55%,
        rgba(0, 36, 73, 0.05) 100%
    ),
    linear-gradient(90deg, #ffffff 0%, #e1eaf6 100%);
box-shadow:
    inset 0 2px 1px #fff,
    inset 0 -3px 6px rgba(0, 36, 73, 0.13),
    inset 3px 0 5px rgba(255, 255, 255, 0.9),
    inset -4px 0 8px rgba(0, 36, 73, 0.09),
    0 0 0 1px rgba(0, 36, 73, 0.07),
    0 9px 16px -6px rgba(0, 36, 73, 0.32),
    0 2px 4px rgba(0, 36, 73, 0.12);
```

**Soft blue button** (blue text `#0050D0`): `linear-gradient(90deg, #F2F9FF 0%, #C6DDFB 100%)` with the same highlight.

**Icon buttons** ("Call", "SMS"), radius 14 px:

```css
background:
    linear-gradient(
        180deg,
        rgba(255, 255, 255, 0.85) 0%,
        rgba(255, 255, 255, 0) 55%,
        rgba(0, 40, 120, 0.06) 100%
    ),
    linear-gradient(90deg, #f4faff 0%, #cbe0fa 100%);
box-shadow:
    inset 0 2px 1px #fff,
    inset 0 -3px 6px rgba(0, 50, 140, 0.14),
    inset -3px 0 6px rgba(0, 50, 140, 0.08),
    0 0 0 1px rgba(0, 50, 140, 0.08),
    0 8px 14px -6px rgba(0, 36, 73, 0.35);
```

**Header buttons** (on the dark header), radius 14 px, white:

```css
background:
    linear-gradient(
        180deg,
        rgba(255, 255, 255, 0.28) 0%,
        rgba(255, 255, 255, 0) 60%
    ),
    linear-gradient(
        90deg,
        rgba(255, 255, 255, 0.26) 0%,
        rgba(255, 255, 255, 0.06) 100%
    );
box-shadow:
    inset 0 2px 1px rgba(255, 255, 255, 0.35),
    inset 0 -3px 6px rgba(0, 0, 0, 0.25),
    inset -3px 0 6px rgba(0, 0, 0, 0.15),
    0 8px 14px -4px rgba(0, 0, 0, 0.45);
```

**Cards** — white with a light gradient and soft shadow, radius 18 px:

```css
background: linear-gradient(90deg, #ffffff 0%, #f3f7fd 100%);
box-shadow:
    inset 0 1px 0 #fff,
    0 2px 4px rgba(0, 36, 73, 0.06),
    0 14px 28px -8px rgba(0, 36, 73, 0.16);
```

**Inputs** are sunken, not raised (they are not pressed): white with a light top shade
`linear-gradient(180deg, #F4F7FB 0%, #FFFFFF 40%)`, border `#D5DEEA`, radius 14 px, height 52 px,
`box-shadow: inset 0 2px 4px rgba(16,42,79,.08)`, 16 px text (no zoom on Android). Label above in 13 px semibold.

**Chips and segmented controls** (days, time windows, tips, filters, tabs): raised chips `#FFFFFF → #EAF0F8`; the selected
one uses the primary blue gradient with white text. Segmented controls sit in a sunken track
`linear-gradient(90deg, #E3EAF4 0%, #D3DDEB 100%)` with `inset 0 2px 4px rgba(16,42,79,.14)`.

**Option rows** (outcome, payment method, review timing): 64 px raised rows, radius 18 px, colored icon tile on the left,
title + one-line hint, radio dot on the right. Selected: blue border `#0A6CF5` and background
`linear-gradient(90deg, #F2F8FF 0%, #CFE1FA 100%)`.

**Switches:** sunken grey track, raised white knob; on = blue gradient track.

**Pinned action bar** (inner screens): white, top border `#E1E8F2`, shadow `0 -6px 18px rgba(16,42,79,.08)`. The main
button is 56–60 px high and says what will happen, with the amount or time ("Charge CA$388.34", "Book job · Fri Oct 3").

**Calendar blocks:** a visit is a raised block in its status gradient (see below), radius 14 px, with status icon, time,
customer and appliance. Technician colors: Alex blue `#4CBDFE → #0046CC`, Sam teal `#2DD4BF → #0F766E`, Unassigned grey
`#A9B6C8 → #64748B` (assigned per technician in settings). The "now" line is `#E11D48`.

**Big result medallion** (Paid): 96 px circle, green gradient `#6EE7A8 → #22C55E → #15803D` with the same highlight and
inner shadows as the primary button.

## Statuses

A status is always **color + icon + text**. Names come from the code enums (translated). Badges are pills with a light
left-to-right gradient and a soft raised shadow:

```css
box-shadow:
    inset 0 1px 1px rgba(255, 255, 255, 0.7),
    inset 0 -1px 2px rgba(0, 36, 73, 0.1),
    0 1px 3px rgba(0, 36, 73, 0.16);
```

| Status                      | Gradient            | Text                          | Icon                                  |
| --------------------------- | ------------------- | ----------------------------- | ------------------------------------- |
| New                         | `#F3FAFF → #B7E0FF` | `#075985`                     | file-plus                             |
| Scheduled                   | `#F3F5FF → #B7C6FF` | `#3730A3`                     | calendar                              |
| On the way                  | `#FFFBE9 → #FFEC9F` | `#92400E`                     | car                                   |
| In progress                 | `#FFF8EE → #FFDCAC` | `#9A3412`                     | wrench                                |
| Waiting for parts           | `#FBF6FF → #DFC0FF` | `#6B21A8`                     | package                               |
| Waiting for customer        | `#EBFDFF → #A5F7FF` | `#155E75`                     | clock                                 |
| Completed / Done / Approved | `#ECFDF4 → #A3FFCF` | `#065F46`                     | check-circle (thumbs-up for Approved) |
| Invoiced                    | `#EBFDFA → #9FFFED` | `#115E59`                     | receipt                               |
| Paid                        | `#F1FEF5 → #B0FFCB` | `#166534`                     | banknote                              |
| On hold / Declined          | `#FFF4F5 → #FFBCC1` | `#9F1239`                     | pause-circle / x-circle               |
| Cancelled / Void            | `#F4F4F5 → #CCCCD6` | `#3F3F46`, struck through     | x-circle                              |
| Unpaid                      | `#FFFBE9 → #FFEC9F` | `#92400E`                     | clock (not car)                       |
| Partially paid              | `#FFF8EE → #FFDCAC` | `#9A3412`                     | half-filled circle                    |
| Refunded                    | `#F8F6FF → #CDC0FF` | `#5B21B6`                     | rotate-ccw                            |
| Partially refunded          | `#FBFBFF → #CBCBFF` | `#5B21B6`, outlined `#C4B5FD` | rotate-ccw                            |
| Draft                       | `#FAFBFC → #D1DCF0` | `#334155`                     | pencil                                |
| Revised                     | `#FAFBFC → #D1DCF0` | `#64748B`, struck through     | rotate-ccw                            |

Visit statuses use the same look as the matching job status.

## Imagery

- **Customer avatars:** cartoon 3D faces, all the same size (60 px), white outline and shadow. If a customer has two
  contacts (e.g. husband and wife), both faces go into **one** circle.
- **Team avatars** (technicians, office): same cartoon style in a blue polo; 40 px in compact places (headers,
  columns, chips).
- **No avatar yet:** a 60 px circle with initials on the light blue gradient `#F1F7FF → #B3D4FF`.
- **Appliance images:** calm, light stainless steel without strong glare. Black parts (glass, cooktop) are dark but not
  harsh.
- Icons: inline stroke SVG (stroke width 2, round caps), never emoji.

## Navigation and layout

- Bottom bar with 4 tabs: **Today · Calendar · Messages · More**.
- Inner screens have no tab bar: a back button at the top and actions pinned to the bottom.
- **Book customer** button lives in the My Jobs header.
- The third button in a job card changes with the status: **On my way / Start / Finish visit**. No "View job" button —
  the whole card opens the job.
- Touch targets at least 44 px; screens designed for one-handed use on a phone.
- Money is always formatted with the company currency (e.g. `CA$95.00`); never a hard-coded "$".
- Tax lines show the names and rates configured by the company (the mockup uses the first customer's GST/PST as sample
  data); nothing about taxes, phone formats or currency is hard-coded.
