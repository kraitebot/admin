# Changelog

All notable design changes to **Kraite Admin** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com).
While work is in progress it lives under **[Unreleased]**; when you say
"we are releasing", that section is renamed to the next version with the
release date, and a fresh empty **[Unreleased]** is started on top.

## [Unreleased]

### Added
- Established this changelog. Baseline for version `0.0.1`.
- Backtesting → Token card: three token-universe filter checkboxes below the selector — **Top 100** (rank ≤ 100), **Only approved** (approved configs), and **Not concluded** (neither approved nor rejected). Each shows a live count; the two status filters combine as a union, AND'd with Top 100, and narrow the token dropdown live. Added a reusable `BtCheck` checkbox component.

### Changed
- Overview dashboard: replaced the "Worker nodes" KPI tile with **Step Dispatcher**, showing a circular ring gauge with the value centered in the dial (example 92%, sub "DISPATCH PERF · 4.2K STEPS/S"). Added a reusable `MiniGauge` ring-dial component with perf bands (≥80 green / 60–80 warn / <60 red, trading-safe).
- Backtesting → Token card now uses `overflow: visible` so the token-selector dropdown renders above the Config panel instead of being clipped/stacked behind it. Card heads (`ACardHead`) gained `rounded-t-surface` so their corners stay flush when a card is unclipped.
- Token-selector dropdown rows: removed the stray light-gray fill (was the browser's default `<button>` background showing through — rows now render transparent over the panel). Each row, the trigger, and the selected state now show a circular token avatar (initial monogram, one harmonized hue per token via golden-ratio hue spread).

### Fixed
- _Nothing yet._

### Removed
- _Nothing yet._
