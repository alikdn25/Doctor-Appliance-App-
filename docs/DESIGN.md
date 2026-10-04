# Design system — Doctor Appliance

Approved mockup (source of truth for look and layout):
https://claude.ai/artifact/WWiBsi8bFjkdVkQqLMCYrv — canvas "Doctor Appliance — field screens".

Approved screens:

1. My Jobs (phone, 390 px)
2. Job page
3. Finish visit
4. Select appliance
5. Status sheet — every job, visit, invoice and estimate status

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

| Token | Value |
| --- | --- |
| Page background | `#EEF3FA` |
| Text | `#0F1B2D` |
| Secondary text | `#5B6779` |
| Link | `#0A6CF5` (hover `#0050D0`) |
| Header | `linear-gradient(90deg, #0B3D75, #00244A 55%, #001B3A)` |

The dark-blue header sits above a light "sheet" (page background) with rounded top corners.

## Components

**Primary button** (gradient taken from ChatGPT mockups), white text with shadow:

```css
background:
  linear-gradient(180deg, rgba(255,255,255,.30) 0%, rgba(255,255,255,0) 55%, rgba(0,20,80,.14) 100%),
  linear-gradient(90deg, #4CBDFE 0%, #1E8CFD 35%, #0567F5 65%, #0046CC 100%);
color: #fff;
text-shadow: 0 1px 2px rgba(0,30,90,.45);
box-shadow:
  inset 0 2px 2px rgba(255,255,255,.55),
  inset 0 -4px 8px rgba(0,30,110,.38),
  inset 4px 0 8px rgba(255,255,255,.22),
  inset -5px 0 10px rgba(0,20,90,.30),
  0 12px 20px -6px rgba(5,103,245,.60),
  0 3px 6px rgba(0,50,140,.25);
```

**Secondary button** — white to `#E1EAF6`:

```css
border: 0;
border-radius: 16px;
background:
  linear-gradient(180deg, rgba(255,255,255,.95) 0%, rgba(255,255,255,0) 55%, rgba(0,36,73,.05) 100%),
  linear-gradient(90deg, #FFFFFF 0%, #E1EAF6 100%);
box-shadow:
  inset 0 2px 1px #fff,
  inset 0 -3px 6px rgba(0,36,73,.13),
  inset 3px 0 5px rgba(255,255,255,.9),
  inset -4px 0 8px rgba(0,36,73,.09),
  0 0 0 1px rgba(0,36,73,.07),
  0 9px 16px -6px rgba(0,36,73,.32),
  0 2px 4px rgba(0,36,73,.12);
```

**Soft blue button** (blue text `#0050D0`): `linear-gradient(90deg, #F2F9FF 0%, #C6DDFB 100%)` with the same highlight.

**Icon buttons** ("Call", "SMS"), radius 14 px:

```css
background:
  linear-gradient(180deg, rgba(255,255,255,.85) 0%, rgba(255,255,255,0) 55%, rgba(0,40,120,.06) 100%),
  linear-gradient(90deg, #F4FAFF 0%, #CBE0FA 100%);
box-shadow:
  inset 0 2px 1px #fff,
  inset 0 -3px 6px rgba(0,50,140,.14),
  inset -3px 0 6px rgba(0,50,140,.08),
  0 0 0 1px rgba(0,50,140,.08),
  0 8px 14px -6px rgba(0,36,73,.35);
```

**Header buttons** (on the dark header), radius 14 px, white:

```css
background:
  linear-gradient(180deg, rgba(255,255,255,.28) 0%, rgba(255,255,255,0) 60%),
  linear-gradient(90deg, rgba(255,255,255,.26) 0%, rgba(255,255,255,.06) 100%);
box-shadow:
  inset 0 2px 1px rgba(255,255,255,.35),
  inset 0 -3px 6px rgba(0,0,0,.25),
  inset -3px 0 6px rgba(0,0,0,.15),
  0 8px 14px -4px rgba(0,0,0,.45);
```

**Cards** — white with a light gradient and soft shadow, radius 18 px:

```css
background: linear-gradient(90deg, #FFFFFF 0%, #F3F7FD 100%);
box-shadow: inset 0 1px 0 #fff, 0 2px 4px rgba(0,36,73,.06), 0 14px 28px -8px rgba(0,36,73,.16);
```

## Statuses

A status is always **color + icon + text**. Names come from the code enums (translated). Badges are pills with a light
left-to-right gradient and a soft raised shadow:

```css
box-shadow: inset 0 1px 1px rgba(255,255,255,.7), inset 0 -1px 2px rgba(0,36,73,.10), 0 1px 3px rgba(0,36,73,.16);
```

| Status | Gradient | Text | Icon |
| --- | --- | --- | --- |
| New | `#F3FAFF → #B7E0FF` | `#075985` | file-plus |
| Scheduled | `#F3F5FF → #B7C6FF` | `#3730A3` | calendar |
| On the way | `#FFFBE9 → #FFEC9F` | `#92400E` | car |
| In progress | `#FFF8EE → #FFDCAC` | `#9A3412` | wrench |
| Waiting for parts | `#FBF6FF → #DFC0FF` | `#6B21A8` | package |
| Waiting for customer | `#EBFDFF → #A5F7FF` | `#155E75` | clock |
| Completed / Done / Approved | `#ECFDF4 → #A3FFCF` | `#065F46` | check-circle (thumbs-up for Approved) |
| Invoiced | `#EBFDFA → #9FFFED` | `#115E59` | receipt |
| Paid | `#F1FEF5 → #B0FFCB` | `#166534` | banknote |
| On hold / Declined | `#FFF4F5 → #FFBCC1` | `#9F1239` | pause-circle / x-circle |
| Cancelled / Void | `#F4F4F5 → #CCCCD6` | `#3F3F46`, struck through | x-circle |
| Unpaid | `#FFFBE9 → #FFEC9F` | `#92400E` | clock (not car) |
| Partially paid | `#FFF8EE → #FFDCAC` | `#9A3412` | half-filled circle |
| Refunded | `#F8F6FF → #CDC0FF` | `#5B21B6` | rotate-ccw |
| Partially refunded | `#FBFBFF → #CBCBFF` | `#5B21B6`, outlined `#C4B5FD` | rotate-ccw |
| Draft | `#FAFBFC → #D1DCF0` | `#334155` | pencil |
| Revised | `#FAFBFC → #D1DCF0` | `#64748B`, struck through | rotate-ccw |

Visit statuses use the same look as the matching job status.

## Imagery

- **Customer avatars:** cartoon 3D faces, all the same size (60 px), white outline and shadow. If a customer has two
  contacts (e.g. husband and wife), both faces go into **one** circle.
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
