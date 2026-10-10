# UX redesign preview

Design approval only: no application code depends on this folder.

- `index.html` — the preview (one self-contained file; only the Inter font is loaded from Google Fonts).
- `src/` — sources: `preview.src.html` (viewer + styles), `parts/` (one file per section), `snippets/` (repeated
  pieces), `icons.svg`, `img/` (customer avatars and appliance pictures from `public/images`, resized),
  `before/` (screenshots of the current app on DemoSeeder data).

Rebuild after editing the sources: `python3 design-preview/src/build.py`.
