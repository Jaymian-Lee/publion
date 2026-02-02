# Repository Guidelines

## Project Structure & Module Organization
- Plugin entry point: `publion.php` (hooks, activation, constants).
- Core logic: `includes/` (admin UI, AJAX, cron, settings, OpenAI helpers).
- Admin assets: `assets/admin.js`, `assets/admin.css`.
- Documentation: `publion-documentation.pdf`, `readme.txt`.
- Images: `includes/images/`.

## Build, Test, and Development Commands
- No build step is required for this plugin.
- There is no test harness configured in this repository.
- Useful ad-hoc checks:
  - `php -l publion.php` and `php -l includes/*.php` for syntax.
  - `rg "TODO|FIXME"` to scan for pending work.

## Coding Style & Naming Conventions
- PHP follows WordPress conventions: snake_case functions, `StudlyCaps` classes (e.g., `Publion_Admin`).
- Keep code WordPress-safe: use `esc_*`, `sanitize_*`, nonces, and capability checks.
- Indentation: 4 spaces in PHP; follow existing mixed PHP/HTML formatting.
- JS uses jQuery; keep selectors scoped to `#publion-*` elements.

## Testing Guidelines
- No automated tests exist; verify manually in WP Admin:
  - Publion tab navigation, topic suggestions, queue actions, scheduling, and settings save.
  - Cron-driven creation and daily topic generation.

## Commit & Pull Request Guidelines
- No commit message convention is defined in this repository.
- For PRs: include a short summary, steps to verify in WP Admin, and screenshots for UI changes.

## Configuration & Security Notes
- API key is stored in `publion_api_key` option; never hardcode secrets.
- Scheduled tasks use WP-Cron; confirm timezone settings in WordPress for scheduling accuracy.
