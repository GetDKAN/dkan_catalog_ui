# DKAN Catalog UI

Drupal-native dataset search and dataset pages for DKAN 4.x, modeled on the
cmsds-open-data-components React frontend. Proof of concept; the plan lives in
the site repo (`PLAN-native-frontend.md`).

## What it provides

- `/dataset/{uuid}` aliases on dataset nodes (forced on presave, overriding
  editor-entered aliases; language-neutral unless the dataset is translatable,
  so `/es/dataset/{uuid}` resolves; `drush dkan-catalog-ui:aliases` backfills;
  uninstall removes them) and a breadcrumb builder (Home > Datasets) for them.
- Dataset page (`node--data--full`) with tabs (the no-JS fallback relies on
  CSS `:has()`; without it every panel shows): Data Table (preview submodule),
  Overview (resources with row/column counts, metadata table), Data Dictionary
  (sitewide or reference mode), API (server-rendered endpoint reference from
  the dataset's OpenAPI document, plus the raw JSON link; no client library).
- `/api/docs`: the site API reference, the same endpoint list rendered from
  DKAN's `/api/1` document grouped by tag (cached until the set of enabled
  modules changes; an hour at most where max-age applies). Main-menu links Datasets and API ship in
  `dkan_catalog_ui.links.menu.yml`; install and a post-update remove a legacy
  content link titled "Datasets" pointing at `/search` (exact match only).
- `/search`: Views page on the `dkan` search index with fulltext, exposed sort,
  result cards (`search_result` view mode), and facet blocks rendered inside the
  view (theme, keyword, publisher, format; ten values each, then "Show more"). Adds `distribution__item__format` to
  the index on install.
- Single Directory Components under `components/`; neutral CSS tokens in `css/tokens.css`.
  `dkan_catalog_ui:icon` renders an inline SVG by `name` (enum in its
  `.component.yml`; unknown names fail prop validation where PHP assertions
  are on, as in tests), an optional fixed `size`
  in px (default: `1em`, so `font-size` scales it) and an optional `label`
  (otherwise `aria-hidden`). Include it from Twig with
  `{{ include('dkan_catalog_ui:icon', {name: 'filter'}, with_context = false) }}`.

## Install

```bash
drush en dkan_catalog_ui dkan_catalog_ui_preview
drush search-api:index
```

`dkan_catalog_ui_preview` is a fork of DKAN core's `dkan_datastore_preview`
(GetDKAN/dkan#4784 at `0aa181ff7`), maintained here. Its table replaces DKAN core's `dkan_datastore_preview` on dataset pages: installing it while that module is enabled is refused, and the status report warns when both are enabled.

## Tests and standards

```bash
ddev exec "DRUPAL_ROOT=/var/www/html/docroot SIMPLETEST_BASE_URL=\$DDEV_PRIMARY_URL SIMPLETEST_DB=mysql://db:db@db:3306/db vendor/bin/phpunit -c docroot/modules/custom/dkan_catalog_ui --testsuite unit,kernel"
# Functional (BrowserTestBase, needs BROWSERTEST_OUTPUT_DIRECTORY): --testsuite functional
ddev exec "cd docroot/modules/custom/dkan_catalog_ui && ../../../../vendor/bin/phpcs --standard=phpcs.xml.dist ."
ddev exec "vendor/bin/phpstan analyse -c docroot/modules/custom/dkan_catalog_ui/phpstan.neon.dist --memory-limit=1G"
```

CI: `.github/workflows/lint.yml` (phpcs, phpstan, eslint, stylelint) and
`tests.yml` (all PHPUnit suites on a ddev Drupal 11 + DKAN site).
