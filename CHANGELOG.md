# Changelog

All notable changes are documented here. During 0.x, minor releases may contain
breaking changes; patch releases preserve the public API.

## [Unreleased]

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

[Unreleased]: https://github.com/apimediaru/filament-tgs/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/apimediaru/filament-tgs/releases/tag/v0.1.0
