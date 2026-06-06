# Kraite Admin — project conventions

## Icons
- The codebase uses **Feather icons** via `brunocfalcao/blade-feather-icons`. **Default to Feather** wherever possible.
- Keep custom inline SVGs **only** for brand glyphs that Feather doesn't cover: the snake logo, the wordmark, and the BSI (Black Swan) gauge.
- When adding or swapping an icon, prefer the matching Feather name/path over hand-drawn SVG.

## Styling
- UI is built with **TailwindCSS** (Play CDN) mapped to design tokens in `app/tw-config.js`. Prefer utilities; the irreducible base/theme rules live in `app/shell.css` and `assets/colors_and_type.css`.
- Typeface is **Space Grotesk** (sans/display) + a monospaced system stack for numbers. Set as the default in `assets/colors_and_type.css`.

## Trading semantics (never invert)
- **Green** = long / profit / safe. **Red** = short / loss / risk.
- Position tiles are fully directional: green for longs, red for shorts (2px border, ribbon header, lifecycle track, metric labels + values).
