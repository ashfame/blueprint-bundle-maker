# Blueprint Bundle Maker

<img width="1728" height="781" alt="Screenshot 2026-06-12 at 02 17 54" src="https://github.com/user-attachments/assets/1f9aa70c-969b-49f1-93a4-4145f2affcce" />

Blueprint Bundle Maker is an experimental WordPress plugin that generates a WordPress Playground Blueprint bundle ZIP from the current installation.

The generated ZIP contains:

- `blueprint.json`
- `content/site.wxr`
- `files/wordpress-files.zip`
- `metadata/manifest.json`

The WordPress files ZIP contains `wp-content` with smart exclusions for cache, backup, log, temp, secret-like, and generated export paths.

`blueprint.json` uses the closest Playground-supported WordPress and PHP runtime lines in `preferredVersions`. Exact source WordPress and PHP versions are recorded in `metadata/manifest.json`, and the job warnings explain any loss of patch-level precision.

## Admin Usage

Activate the plugin, then go to **Tools > Blueprint Bundle Maker** and click **Generate Bundle**. The browser keeps the staged job moving through AJAX requests and shows progress until the download is ready.

Completed bundles appear in a generated bundles table. Each row can be downloaded, deleted, or published with **Get URL**. Publishing copies the bundle under the uploads directory with an unguessable filename and then shows a public `.zip` bundle URL plus an **Open in Playground** link using `https://playground.wordpress.net/?blueprint-url=...`.

The public bundle URL is routed through WordPress so the plugin can stream the ZIP with CORS headers for Playground. The URL still needs to be publicly reachable by the browser, and HTTPS is recommended for loading bundles on `playground.wordpress.net`.

## WP-CLI Usage

```bash
wp blueprint-bundle make --output=/tmp/site-blueprint-bundle.zip
```

Use `--force` to overwrite an existing output file.
Use `--publish` to also publish the generated bundle and print the public URL plus Playground URL.

## Filters

- `blueprint_bundle_maker_excluded_paths`
- `blueprint_bundle_maker_included_paths`
- `blueprint_bundle_maker_blueprint`
- `blueprint_bundle_maker_safe_options`
- `blueprint_bundle_maker_active_plugins`
- `blueprint_bundle_maker_preferred_versions`
- `blueprint_bundle_maker_job_capability`
- `blueprint_bundle_maker_wxr_args`
- `blueprint_bundle_maker_zip_chunk_file_limit`
