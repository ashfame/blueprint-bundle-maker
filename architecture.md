# Architecture

Blueprint Bundle Maker is a WordPress plugin that creates WordPress Playground Blueprint bundle ZIP files from the current WordPress installation.

## High-Level Flow

The plugin has two user-facing entry points:

- Admin UI: `Tools > Blueprint Bundle Maker`
- WP-CLI: `wp blueprint-bundle make`

Both entry points use the same generation services:

- `Bundle_Generator` coordinates the staged export.
- `File_Scanner` scans `wp-content` in chunks.
- `Blueprint_Writer` builds `blueprint.json`.
- `Job_Store` owns filesystem paths, job state, public publishing, and cleanup.

The admin UI drives generation through repeated AJAX calls. Each request performs a bounded amount of work, persists job state, and returns progress. This avoids one long PHP request for medium or large sites.

## Storage Layout

Runtime files are stored under the WordPress uploads directory because that is the most portable writable location across normal WordPress hosts.

Private working and generated bundles:

```text
wp-content/uploads/blueprint-bundle-maker/
wp-content/uploads/blueprint-bundle-maker/jobs/<job-id>/
```

Each job directory contains:

```text
content/site.wxr
files/wordpress-files.zip
metadata/manifest.json
tmp/file-list.jsonl
blueprint.json
blueprint-bundle-<host>-<timestamp>.zip
```

Publicly published bundles:

```text
wp-content/uploads/blueprint-bundle-maker-public/
blueprint-bundle-<host>-<timestamp>-<random>.zip
```

The private directory is protected with `.htaccess` where supported. Published bundles are stored separately with unguessable filenames, and the URLs shown to admins route through WordPress so the plugin can add the required CORS headers. New public storage directories also deny direct static access with `.htaccess` where Apache honors it.

## Generation Stages

Generation is represented by a job array persisted in `wp_options` while the job is running. The generated bundle table does not depend on this database record; it scans the filesystem so bundles remain visible if the option is missing.

Stages:

1. `wxr`
   - Calls WordPress core `export_wp()` with `content => all`.
   - Writes `content/site.wxr`.
   - Clears export headers so AJAX can still return JSON.

2. `scan`
   - Breadth-first scans `wp-content`.
   - Writes eligible relative paths to `tmp/file-list.jsonl`.
   - Excludes cache, backups, logs, temp files, secret-like files, SQL dumps, generated exports, and symlinks.

3. `zip`
   - Reads `tmp/file-list.jsonl`.
   - Adds files to `files/wordpress-files.zip` as `wp-content/...`.
   - Tracks scanned, zipped, skipped, processed, and byte counts.

4. `bundle`
   - Builds `blueprint.json`.
   - Builds `metadata/manifest.json`.
   - Assembles the final root bundle ZIP with:

```text
blueprint.json
content/site.wxr
files/wordpress-files.zip
metadata/manifest.json
```

5. `complete`
   - Job is complete and the generated bundle appears in the admin table.

## Generated Bundle Table

The admin table is filesystem-backed. It scans:

```text
wp-content/uploads/blueprint-bundle-maker/jobs/*/blueprint-bundle-*.zip
```

This is deliberate: generated bundles should remain discoverable without relying on `wp_options`.

Each row gets a filesystem-derived bundle ID:

```text
rawurlencode(<job-id>) . ':' . rawurlencode(<bundle-filename>)
```

That ID is used for row-level actions:

- Download
- Get URL
- Open in Playground
- Delete

## Publishing Public URLs

Generated bundles are not published automatically from the admin UI. The row-level **Get URL** action copies the private generated bundle into:

```text
wp-content/uploads/blueprint-bundle-maker-public/
```

The public filename includes a random suffix. After publishing, the row displays:

- Public bundle URL
- Copy URL
- Open in Playground

The copied public URL is not the direct uploads URL. It points to:

```text
wp-admin/admin-post.php?action=blueprint_bundle_maker_public_bundle&file=<public-filename>
```

That endpoint is intentionally available without authentication because the random filename acts as the bearer token. It streams the ZIP through PHP and sends:

```text
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, HEAD, OPTIONS
Access-Control-Allow-Headers: Origin, Accept, Content-Type, Range
```

The Playground URL uses:

```text
https://playground.wordpress.net/?blueprint-url=<public-bundle-url>
```

The public URL must be reachable by the user's browser. HTTPS is recommended because the Playground site itself is HTTPS.

## Blueprint Contents

`Blueprint_Writer` creates `blueprint.json`.

Important fields:

- `$schema`
- `preferredVersions`
- `landingPage`
- `steps`

`preferredVersions` uses the closest Playground-supported runtime lines:

- WordPress: major/minor release line, such as `6.8`
- PHP: major/minor line, such as `8.3`

Exact source versions are stored in `metadata/manifest.json`. Warnings are added when patch-level precision cannot be represented in Playground `preferredVersions`.

Important steps:

- `unzip` extracts bundled WordPress files into `/wordpress`.
- `login` logs into the Playground admin user.
- `setSiteLanguage` is added for non-`en_US` locales.
- `activateTheme` restores the active theme folder.
- `activatePlugin` restores active plugins except this exporter plugin.
- `importWxr` imports the WXR content.
- `setSiteOptions` restores a safe allowlist of scalar site options.
- `runPHP` maps front page/posts page options after WXR import when needed.

## Security Model

Admin actions require the configured capability, defaulting to `export`.

AJAX actions use the admin nonce:

```text
blueprint_bundle_maker_admin
```

Download and delete links use per-action nonces.

Private generated bundles are downloaded through `admin-post.php` with a nonce and capability check. Public bundles are stored with unguessable filenames and streamed through a public `admin-post.php` endpoint after **Get URL** is used.

## Cleanup

Stale non-completed jobs can be removed by cleanup. Completed generated bundles are kept until the admin deletes them, because the table is filesystem-backed and the generated ZIP is the source of truth.

Deleting a generated bundle row removes:

- The job directory under `blueprint-bundle-maker/jobs/<job-id>/`
- The matching public ZIP when one exists
- The associated job option when one exists

## Extension Points

Filters:

- `blueprint_bundle_maker_excluded_paths`
- `blueprint_bundle_maker_included_paths`
- `blueprint_bundle_maker_blueprint`
- `blueprint_bundle_maker_safe_options`
- `blueprint_bundle_maker_active_plugins`
- `blueprint_bundle_maker_preferred_versions`
- `blueprint_bundle_maker_job_capability`
- `blueprint_bundle_maker_wxr_args`
- `blueprint_bundle_maker_zip_chunk_file_limit`
- `blueprint_bundle_maker_job_max_age`

## Operational Notes

Required PHP capability:

- `ZipArchive`
- Writable WordPress uploads directory

Host behavior that can affect published URLs:

- HTTPS availability
- Public access to `wp-admin/admin-post.php`

WP-CLI can generate bundles without the browser:

```bash
wp blueprint-bundle make --output=/tmp/site-blueprint-bundle.zip
wp blueprint-bundle make --publish
```
