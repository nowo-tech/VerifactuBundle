# Release

Maintainers: follow this process before creating a new tag.

## Pre-release checklist

1. **Update documentation**
   - Ensure [CHANGELOG.md](CHANGELOG.md) has an entry for the new version (e.g. `[1.0.0] - YYYY-MM-DD`) and that `[Unreleased]` is updated or empty.
   - Update [UPGRADING.md](UPGRADING.md) if there are behaviour changes or breaking changes for that version.

2. **Run quality checks**

   From the bundle root (with Docker up):

   ```bash
   make release-check
   ```

   This runs `check-no-cursor-coauthor`, composer-sync, cs-fix, cs-check, rector-dry, phpstan, test-coverage (100%), and demo verification (if present).

3. **Commit** any pending changes. Ensure the tree is clean and pushed.

   Re-run **after** the release commit and **before** `git push`:

   ```bash
   make check-no-cursor-coauthor
   make setup-hooks   # once per clone (REQ-GIT-001)
   git status
   git add -A && git commit -m "Release vX.Y.Z"   # if needed
   git push origin main
   ```

   See [GITHUB_CI.md](GITHUB_CI.md) if the git-hygiene job fails.

## Tag and publish

4. **Create an annotated tag** (replace with the version you are releasing):

   ```bash
   git tag -a v1.0.0 -m "Release v1.0.0"
   git push origin v1.0.0
   ```

   If the bundle is released from a separate clone (e.g. `nowo-tech/VerifactuBundle`), run these commands in the clone that is pushed to the release remote.

5. **GitHub release**  
   The [release workflow](.github/workflows/release.yml) runs on tag push and creates the GitHub Release. The release body is typically taken from the tag message and/or [CHANGELOG.md](CHANGELOG.md).

6. **Packagist**  
   If the package is on [Packagist](https://packagist.org/packages/nowo-tech/verifactu-bundle), the new tag is picked up automatically (or use “Update” there).

## Current release (v1.0.1)

> **Renew this block on each release:** update the version in the heading, the bullets under “Documentation reviewed”, and the example commands below.

### Documentation reviewed for this release

- **CHANGELOG.md**: `[1.0.1] - 2026-07-31` — REQ-GIT-001, 100% coverage gate, RUNTIME-001 timeout hierarchy, SEPA leftover fixes, demo FrankenPHP timeouts.
- **UPGRADING.md**: “Upgrading to 1.0.1” — no API breaks; FrankenPHP timeout guidance when raising `aeat.timeout`.
- **CONFIGURATION.md** / **DEMO-FRANKENPHP.md** / **SECURITY.md** / **GITHUB_CI.md**: Aligned with the above.

### Example commands for this version

```bash
make release-check
git status
git add -A
git -c core.hooksPath=.githooks commit -m "release: v1.0.1 (REQ compliance, 100% coverage, AEAT timeout hierarchy)"
make check-no-cursor-coauthor
git tag -a v1.0.1 -m "Release v1.0.1"
git push origin main
git push origin v1.0.1
```

### Verify on GitHub

- *Actions* → “Create Release” workflow green; *Releases* → **v1.0.1** with body aligned to `docs/CHANGELOG.md` (`## [1.0.1]`).

### If the tag already exists but the release failed

- Re-run the “Create GitHub Release” job from *Actions* (Re-run jobs), or delete the tag on the remote and recreate and push `v1.0.1`.

### Notes

- `.github/workflows/release.yml` runs when pushing a tag `v*`.
- The release body is generated from the `## [1.0.1]` section of `docs/CHANGELOG.md`.
