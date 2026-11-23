# AGENTS.md

Instructions for AI coding agents and human contributors. Read [README.md](README.md) first: it covers requirements, local setup, commands and how to add an ability. Read [docs/SECURITY.md](docs/SECURITY.md) before changing permissions, safety settings or anything that takes user input.

## Layout

- `bricks-mcp.php`: plugin bootstrap.
- `includes/Abilities/`: ability modules. Each implements `BricksMCP\Abilities\Module` and is listed in `Registrar::MODULES`.
- `includes/Safety/`: CSS guard and rate limiter.
- `includes/Admin/`, `includes/Updates/`: settings page and the GitHub Releases updater.
- `tests/Unit/`: unit tests, run against the stubs in `tests/stubs/` (no WordPress or Bricks needed).
- `tests/Integration/`: tests that need WordPress, run inside wp-env.

## Before you hand back

```bash
composer install
composer test        # PHPCS and unit tests; must exit 0
```

CI runs PHPCS on changed lines only and the unit tests on PHP 8.2. Run `npm run test` too when a change depends on real WordPress behaviour (requires Docker).

## Rules

- PHP 8.2+, WordPress coding standards (`phpcs.xml`), text domain `bricks-mcp`.
- Every ability keeps a `permission_callback` that matches the table in `docs/SECURITY.md`. Update that table and the README ability list when you add or change an ability.
- Validate and sanitize input at the ability boundary. Never weaken an auth, capability, checksum or CSS safety check to make a test pass.
- No new Composer or npm dependency for something a few lines of code can do.
- When a stub in `tests/stubs/` is missing a WordPress or Bricks function, add it there, matching the real signature.
- Test behaviour that matters: permissions, data changes, error paths and each fixed bug. Skip tests for trivial code.
- Keep the change to what the task asks. Do not reformat unrelated code.
- Bricks Builder is a paid theme and is not in the repository. Do not commit it, its source or credentials (`.mcp.json`, `.env`).
