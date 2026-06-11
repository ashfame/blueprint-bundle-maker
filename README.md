# Blueprint Bundle Maker

Blueprint Bundle Maker is a WordPress plugin that generates a WordPress Playground Blueprint bundle ZIP from the current installation.

The generated ZIP contains:

- `blueprint.json`
- `content/site.wxr`
- `files/wordpress-files.zip`
- `metadata/manifest.json`

The WordPress files ZIP contains `wp-content` with smart exclusions for cache, backup, log, temp, secret-like, and generated export paths.

`blueprint.json` uses the closest Playground-supported WordPress and PHP runtime lines in `preferredVersions`. Exact source WordPress and PHP versions are recorded in `metadata/manifest.json`, and the job warnings explain any loss of patch-level precision.

## Admin Usage

Activate the plugin, then go to **Tools > Blueprint Bundle Maker** and click **Generate Bundle**. The browser keeps the staged job moving through AJAX requests and shows progress until the download is ready.

Completed bundles are also published under the uploads directory with an unguessable filename. The admin page shows the public bundle URL, an **Open Playground** link using `https://playground.wordpress.net/?blueprint-url=...`, and a table of published bundles that can be copied, downloaded, opened in Playground, or deleted.

The plugin writes an Apache `.htaccess` CORS hint for published ZIP files when supported by the host. The URL still needs to be publicly reachable by the browser, and HTTPS is recommended for loading bundles on `playground.wordpress.net`.

## WP-CLI Usage

```bash
wp blueprint-bundle make --output=/tmp/site-blueprint-bundle.zip
```

Use `--force` to overwrite an existing output file.

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
