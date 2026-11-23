# Changelog

All notable changes are documented here. During 0.x, minor releases may contain
breaking changes; patch releases preserve the public API.

## [Unreleased]

## [0.1.6] - 2026-11-23

### Added

- Fill independent canvases from a Laravel model factory using `withTgs()` and
  block recipes. Recipes support registered custom blocks, nested slots, and
  ordered media. A failed create rolls back the current model and its content.
- Generate `KnownBlock` from the registered block types.
- Document factory recipes, custom block media, and Docker conversion checks.

### Fixed

- Restore ordinary fields and canvas content when reopening the same Filament
  create draft in the same session.
- Describe both existing JSON shapes of `UnknownBlock.data` in TypeScript.

## [0.1.5] - 2026-11-19

### Added

- Russian and English customer guides bundled in `resources/docs`, discovered
  through Composer metadata by `apimediaru/filament-documentation`.
- Optional instructions link in editable and read-only canvases.

## [0.1.4] - 2026-11-17

### Added

- Configure public site cache invalidation through `TgsPlugin::siteCache()`.
  The default uses Laravel's cache. A custom `SiteCacheInvalidator` or
  disabling site cache invalidation can be selected application-wide.

### Fixed

- Invalidate public cache after the commit that removes a canvas or a model
  host and its canvas content, including when deletion starts without an
  outer transaction.

## [0.1.3] - 2026-11-17

### Fixed

- Keep the provisional record attached when Filament rebuilds a create form.
- Save and create another opens a blank draft at a fresh URL. Replacing the
  history entry lets Cancel return to the previous page.

## [0.1.2] - 2026-11-17

### Added

- Site-wide `commonSettings()` descriptor with shared section DTO selection for
  runtime and TypeScript, consistent across panels and CLI.
- Check common form paths and collisions with `tgs:check`; preserve inaccessible
  saved fields during canvas/orphan edits, including nested repeater values.
- Document shared settings, per-block DTO replacements and published Blade overrides;
  cover local page/partial rendering, view cache and package fallback.

### Fixed

- Derive edit schemas from the stored block type and verify root placements; keep
  draft destinations in locked server state instead of trusting action arguments.
- Reject stale repeater edits under a database row lock, without partial writes or
  history entries; cover competing PostgreSQL connections and reopening the form.
  Build the initial snapshot from the same payload as the form, including changes
  made by another editor during form hydration.
- Accept finite nested DTO paths that revisit a class in `tgs:check`, retaining
  cycle detection for legacy flattened field lookup.

- Reject non-HTTP(S) and relative embedded media URLs in previews and `embedded_src`,
  preserving the stored `source`. Escape preview URL attributes and normalize
  scheme-relative provider output to HTTPS.
- Capture declared cache keys before host/canvas deletion and invalidate only after
  commit. Rollbacks preserve cache; surviving hosts also contribute their final keys.
- Add portable regression tests for both embed preview forms, DTO parsing, deletion
  of named/embedded/model hosts, nested rollback, shared blocks and late media events.

## [0.1.1] - 2026-11-13

### Fixed

- Preserve repeated placements of shared blocks and their subtrees at different
  depths in canvas reads and single-block reads. Cycle protection now checks
  ancestors of each placement instead of treating every repeated block as a cycle.
- Add regression coverage for nested reuse, real ancestor cycles, and descendants
  of shared containers.

## [0.1.0] - 2026-11-13

### Added

- Independent Laravel 13 / Filament 5 content canvas, extracted from the starter.
- Typed block descriptors, host registration, block/category additions and hiding,
  replacement descriptors with inherited DTOs, and TypeScript union generation.
- Nested layouts, media and configurable conversions, shared references and copies,
  block library, collections, version history, locks, trash and canvas claims.
- Gate abilities owned by the host application; configurable model classes.
- Fresh SQLite/PostgreSQL schema and explicit adoption of an existing compatible schema.
- Standalone Russian Workbench with library and collection examples, a manual test
  guide and administrator/editor accounts; English translations for package tools.
- Independent Testbench tests, architecture checks and PHP 8.3/8.4 CI matrix.

### Fixed

- Demo seeding preserves existing edited content and account passwords.
- Collection and lock action labels respect the active locale.

[Unreleased]: https://github.com/apimediaru/filament-tgs/compare/v0.1.5...HEAD
[0.1.5]: https://github.com/apimediaru/filament-tgs/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/apimediaru/filament-tgs/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/apimediaru/filament-tgs/compare/v0.1.2...v0.1.3
[0.1.0]: https://github.com/apimediaru/filament-tgs/releases/tag/v0.1.0
