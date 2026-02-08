# Versioning Strategy

## Overview

Our release versioning is designed for predictability, stability, and ease of communication. Each release is assigned a sequential version number in the following format:

- **X.Y** – Major releases: new features, enhancements, or significant changes.
- **X.Y.Z** – Minor/maintenance releases: bug fixes, patches, and security updates.
- **X.Y-betaN, X.Y-RCN, X.Y-alphaN** – Pre-release versions: beta, release candidate, and alpha builds for testing.

This approach prioritizes backward compatibility and a stable upgrade path rather than strict semantic versioning.

## Version Number Format

- **Major Release:**  
  `X.Y`  
  (e.g., `1.0`, `1.1`, `2.0`)

  Criteria: substantial new features, enhancements, and possible breaking changes (though we strive to minimize these).

- **Minor/Maintenance Release:**  
  `X.Y.Z`  
  (e.g., `1.0.1`, `1.1.2`, `2.0.5`)

  Criteria: bug fixes, security patches, or other minor improvements. Should not introduce breaking changes.

- **Pre-release/Development:**  
  (e.g., `1.0.0-beta1`, `1.0.0-RC1`, `1.0.0-alpha2`)

  These builds are made available for testing and preview purposes before a stable release.  
  Suffixes:
  - `-alphaN`: Early preview, feature-incomplete.
  - `-betaN`: Feature-complete, testing for stability.
  - `-RCN`: Final validation prior to stable release.

## Initial Version

- The initial public release will be **v1.0**, unless otherwise specified by product leadership.
- Pre-release builds for initial versions will follow the conventions above (e.g., `1.0.0-beta1`, `1.0.0-RC1`).

## Version Bumping Policy

- **Major bump (X.Y):** Used when introducing new features, enhancements, or significant changes.
- **Minor bump (X.Y.Z):** Used for urgent bug fixes, security patches, or minor maintenance between major releases.
- **Pre-release:** Used for alpha, beta, or release candidate builds prior to a planned stable release.

All releases are accompanied by release notes, changelogs, and necessary upgrade instructions.

## Examples

- `1.0` – Initial stable release
- `1.0.1` – Bug fix or security patch
- `1.1` – Added new feature(s) or enhancements
- `1.1.2` – Additional maintenance fixes
- `1.2-beta1` – First beta for an upcoming major version
