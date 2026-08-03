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

## Current release (v1.0.3)

> **Renew this block on each release:** update the version in the heading, the bullets under “Documentation reviewed”, and the example commands below.

### Documentation reviewed for this release

- **CHANGELOG.md**: `[1.0.3] - 2026-08-03` — `actions/stale` v11, Rector/CS Fixer hygiene for SF6 Autowire + `reference.php`, lock refresh.
- **UPGRADING.md**: “Upgrading to 1.0.3” — no API breaks.
### Example commands for this version

```bash
make release-check
git status
git add -A
git -c core.hooksPath=.githooks commit -m "release: v1.0.3 (CI stale + lock hygiene)"
make check-no-cursor-coauthor
git tag -a v1.0.3 -m "Release v1.0.3"
git push origin main
git push origin v1.0.3
```

### Verify on GitHub

- *Actions* → CI green; *Releases* → **v1.0.3** with body aligned to `docs/CHANGELOG.md` (`## [1.0.3]`).

### Notes

- `.github/workflows/release.yml` runs when pushing a tag `v*`.
- The release body is generated from the `## [1.0.3]` section of `docs/CHANGELOG.md`.
