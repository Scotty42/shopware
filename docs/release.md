# Releasing the plugin

A release produces an installable **`OrderIntegration-<version>.zip`** attached
to a GitHub Release. The version is read from `composer.json` (`"version"`), so
the **git tag and that field must match** — the zip is named after composer, not
the tag.

## Versioning

- `composer.json` `"version"` is the single source of truth for the package
  name and the Shopware plugin version. Bump it **before** tagging.
- Use semver: `MAJOR.MINOR.PATCH`. Tags may be `1.2.3` or `v1.2.3` — the CI
  matches both.

## Automated release (CI)

`.github/workflows/package.yml` builds the zip on every push/PR (as a build
artifact) and, on a **semver tag**, attaches it to the matching GitHub Release.

1. **Bump** the version and merge to `main`:
   ```bash
   # edit composer.json -> "version": "1.0.1"
   git commit -am "chore: release 1.0.1" && git push   # via PR -> main
   ```
2. **Tag** the merged commit and push the tag:
   ```bash
   git checkout main && git pull
   git tag 1.0.1            # must equal composer.json "version"
   git push origin 1.0.1
   ```
3. CI builds `OrderIntegration-1.0.1.zip`, then creates (or updates) Release
   `1.0.1` with the zip attached and auto-generated notes
   (`gh release create … --generate-notes` / `gh release upload … --clobber`).

Re-run for an existing tag (e.g. after fixing the build) by force-pushing the tag:
```bash
git tag -f 1.0.1 && git push -f origin 1.0.1
```

## Manual release (fallback / no CI)

From a machine with `gh auth login` and a checkout of the tagged commit:
```bash
git fetch --tags
git checkout 1.0.1                 # or main, if tagging the current tip
bin/build-plugin-zip.sh            # -> OrderIntegration-1.0.1.zip
gh release create 1.0.1 OrderIntegration-1.0.1.zip --title 1.0.1 --generate-notes \
  || gh release upload 1.0.1 OrderIntegration-1.0.1.zip --clobber
```

`bin/build-plugin-zip.sh [git-ref] [output-dir]` also packages an explicit ref:
`bin/build-plugin-zip.sh 1.0.1 /tmp`.

## What's in the package

A single top-level `OrderIntegration/` folder with `composer.json`, `src/`,
`LICENSE` and `README.md`. Dev-only paths (`tests/`, `.github/`, `docs/`,
`.env*`, `phpunit.xml.dist`, `bin/`) are excluded via `.gitattributes`
`export-ignore`, and no `vendor/` is bundled (the plugin has no runtime composer
dependencies; `shopware/core` is provided by the host).

## Installing a released package

- **Admin UI:** Extensions → My extensions → *Upload extension* → the zip →
  Install → Activate.
- **CLI:** see the README section "Stage / production (packaged plugin)".

## Checklist

- [ ] `composer.json` `"version"` bumped (matches the intended tag)
- [ ] Changes merged to `main`, unit + bash tests green
- [ ] Tag pushed (`x.y.z`) — CI green, zip attached to the Release
- [ ] (optional) Release notes reviewed
