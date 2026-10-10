#!/usr/bin/env python3
"""Builds design-preview/index.html from preview.src.html.

Placeholders in the source:
  {{BEFORE:name}}  -> data URI of src/before/name.webp (screenshot of the current app)
  {{IMG:name}}     -> data URI of src/img/name.webp (avatars, appliance illustrations)
  {{ICONS}}        -> inline SVG sprite (icons.svg)
  {{PARTS}}        -> parts/*.html in name order (one file per section)
  {{INC:name}}     -> snippets/name.html (repeated pieces: bottom navs, app bars)

Run: python3 design-preview/src/build.py
"""
import base64
import pathlib
import re

SRC = pathlib.Path(__file__).resolve().parent
OUT = SRC.parent / "index.html"


def data_uri(path: pathlib.Path) -> str:
    return "data:image/webp;base64," + base64.b64encode(path.read_bytes()).decode()


def main() -> None:
    html = (SRC / "preview.src.html").read_text()
    html = html.replace("{{ICONS}}", (SRC / "icons.svg").read_text())
    parts = sorted((SRC / "parts").glob("*.html"))
    html = html.replace("{{PARTS}}", "\n".join(p.read_text() for p in parts))
    for _ in range(3):
        html = re.sub(r"\{\{INC:([\w-]+)\}\}", lambda m: (SRC / "snippets" / f"{m.group(1)}.html").read_text().strip(), html)
    cache: dict[str, str] = {}

    def sub(kind: str, folder: str):
        def repl(m: re.Match) -> str:
            key = f"{folder}/{m.group(1)}"
            if key not in cache:
                cache[key] = data_uri(SRC / folder / f"{m.group(1)}.webp")
            return cache[key]
        return repl

    html = re.sub(r"\{\{BEFORE:([\w-]+)\}\}", sub("BEFORE", "before"), html)
    html = re.sub(r"\{\{IMG:([\w-]+)\}\}", sub("IMG", "img"), html)
    left = re.findall(r"\{\{[^}]+\}\}", html)
    if left:
        raise SystemExit(f"Unresolved placeholders: {sorted(set(left))}")
    OUT.write_text(html)
    print(f"{OUT} {OUT.stat().st_size / 1024:.0f} KB")


if __name__ == "__main__":
    main()
