# DKAN Catalog UI

Drupal-native dataset search and dataset pages for DKAN 4.x, modeled on the
cmsds-open-data-components React frontend. Proof of concept; the plan lives in
the site repo (`PLAN-native-frontend.md`).

## What it provides

- `/dataset/{uuid}` aliases on dataset nodes (presave hook; `drush dkan-catalog-ui:aliases` backfills)
  and a breadcrumb builder (Home > Datasets) for them.
- Dataset page (`node--data--full`) with tabs: Data Table (preview submodule),
  Overview (resources with row/column counts, metadata table), Data Dictionary
  (sitewide or reference mode), API (server-rendered endpoint reference from
  the dataset's OpenAPI document, plus the raw JSON link; no client library).
- `/api/docs`: the site API reference, the same endpoint list rendered from
  DKAN's `/api/1` document grouped by tag (cached for an hour; the
  generator has no cache tags). Main-menu links Datasets and API ship in
  `dkan_catalog_ui.links.menu.yml`; a post-update removes a legacy content
  link titled "Datasets" pointing at `/search` (exact match only).
- `/search`: Views page on the `dkan` search index with fulltext, exposed sort,
  result cards (`search_result` view mode), and facet blocks rendered inside the
  view (theme, keyword, publisher, format). Adds `distribution__item__format` to
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
(GetDKAN/dkan#4784 at `0aa181ff7`), maintained here. It cannot be enabled together with `dkan_datastore_preview`.

## Tests and standards

```bash
ddev exec "DRUPAL_ROOT=/var/www/html/docroot SIMPLETEST_BASE_URL=\$DDEV_PRIMARY_URL SIMPLETEST_DB=mysql://db:db@db:3306/db vendor/bin/phpunit -c docroot/modules/custom/dkan_catalog_ui --testsuite unit,kernel"
# Functional (BrowserTestBase): see modules/dkan_catalog_ui_preview/README.md
ddev exec "cd docroot/modules/custom/dkan_catalog_ui && ../../../../vendor/bin/phpcs --standard=phpcs.xml.dist ."
```
