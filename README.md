# Blueprint Bundle Maker

Blueprint Bundle Maker is a WordPress plugin that generates a WordPress Playground Blueprint bundle ZIP from the current installation.

The generated ZIP contains:

- `blueprint.json`
- `content/site.wxr`
- `files/wordpress-files.zip`
- `metadata/manifest.json`

The WordPress files ZIP contains `wp-content` with smart exclusions for cache, backup, log, temp, secret-like, and generated export paths.

## Admin Usage

Activate the plugin, then go to **Tools > Blueprint Bundle Maker** and click **Generate Bundle**. The browser keeps the staged job moving through AJAX requests and shows progress until the download is ready.

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
- `blueprint_bundle_maker_job_capability`
- `blueprint_bundle_maker_wxr_args`
