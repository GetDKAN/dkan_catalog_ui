# DKAN Catalog UI Preview

Server-rendered data table for the dataset page (`/dataset/{uuid}`, `/node/{nid}`). Forked from DKAN core `dkan_datastore_preview` (GetDKAN/dkan#4784, commit `0aa181ff7`) on 2026-09-17 and maintained here; see `PLAN-data-table.md` in the site root for the roadmap.

Enable with `drush en dkan_catalog_ui_preview`. Install adds a **Data Preview** extra field to the `data` node type's default view display; toggle or reorder it under *Structure → Content types → Data → Manage display*. Cannot be enabled together with `dkan_datastore_preview`.

## Behavior

* One table at a time for the dataset's tabular distributions (CSV/TSV); non-tabular distributions render nothing. The dataset page renders the table through a lazy-builder placeholder (`DataPreviewBuilder::lazyBuild()`), so the node stays in the render cache and the dynamic page cache while the table (`max-age` 0) renders per request.
* Toolbar (`data-table-toolbar` component), three rows: the file row (distribution chooser when more than one tabular distribution, else the file caption, then the full-dataset download link from a validated `downloadURL`); the tools row (Filter Dataset / Manage Columns / Display Settings / Share panels, `<details>` with GET forms to the apply route, then Full Screen); the status row (result summary and chips for active filters and hidden columns with Clear all). Share panel: copy link (JS) and "Download filtered data (CSV)" on the datastore download route with the current conditions, sort and columns (all matching rows, no page limit). Sorting and paging are links. Each panel's primary action (Apply, Save) closes it; the secondary controls (add or remove a filter row, move a column, reset) leave it open. Everything works without JavaScript; sort columns, filter columns and operators are validated against the table schema.
* Column labels: data dictionary `title` when `dkan_metastore.settings.data_dictionary_mode` is not `none` and a dictionary resolves for the resource (fields matched by machine name, then original header); `description` is the header tooltip. Otherwise the original CSV header.
* Class contract: `.dcu-table` (root, `id="dcu-table"`, `tabindex="-1"`), `.dcu-table__toolbar`, `.dcu-table__file` (`.dcu-table__chooser` / `.dcu-table__caption` with its `.dcu-table__file-label`, then `.dcu-table__download`), `.dcu-table__tools`, `.dcu-table__status` (`.dcu-table__summary`, chips), `.dcu-table__panel`, `.dcu-table__panel-body`, `.dcu-table__chip`, `.dcu-table__wrapper` (scrolling), `.dcu-table__table`, `.dcu-table__footer`, `.dcu-table__message`.
* If a distribution's datastore table does not exist yet, a status message renders instead ("still being processed" while the fetch/import is queued or running, an error message if the import failed). The placeholder carries the dataset and distribution cache tags, so it switches to the table once the import's post-processing invalidates them.

### URL parameters

Canonical order, defaults omitted; anything invalid is dropped (`TableState`):

| Parameter | Meaning |
|---|---|
| `table` | Tabular distribution index (default 0). |
| `conditions[i][property]`, `[operator]`, `[value]` | Filters, max 10, value max 255 chars. Operators by column type: text `=`, `contains`, `starts with`, `<>`, `in`; number/date `=`, `<>`, `>`, `<`; other `=`, `<>`. `contains` / `starts with` match literally (LIKE metacharacters escaped). `in` takes a comma list (trimmed, deduped, max 50); a value containing a comma cannot be used with `in`. |
| `page` | 1-based, clamped to the last page. |
| `page_size` | 10, 25 (default), 50 or 100, capped by `dkan_datastore.settings.rows_limit`. |
| `sort`, `direction` | Column machine name and `desc` (`asc` is the default and omitted). A sort on a hidden column is dropped. |
| `columns` | Comma list of visible columns in display order; the full list in schema order is the default and omitted. |
| `panel` | Transient: which toolbar panel renders open after an apply-route redirect. |

URL length: ten conditions of 255 characters plus a full column list stay under about 4 KB, within common server limits (8 KB); the filtered download URL carries the same parameters in the datastore query shape.

### Routes

* `dkan_catalog_ui_preview.fragment`: `/dataset/{dataset}/table` (GET). The rendered table for the query string, for in-place updates (`max-age` 0, `X-Robots-Tag: noindex`). 404 when the dataset has no tabular distribution.
* `dkan_catalog_ui_preview.apply`: `/dataset/{dataset}/table/apply` (GET). Target of every toolbar form. Normalizes the submitted state (`columns[]` checkboxes become the comma list; actions `remove`, `reset_filters`, `move_up`, `move_down`, `reset_columns` are applied; unknown parameters dropped) and 302-redirects to the dataset's canonical URL with `#dcu-table`. Requires `access content` and node view access. `{dataset}` is the metastore identifier (usually, not always, a UUID). With `Accept: application/json` it returns `{"url": canonical}` instead of redirecting.

### JavaScript (`js/data-table.js`)

Markup notes: the caption is a `p.dcu-table__caption#dcu-table-caption` in the toolbar's file row and the table's `aria-labelledby`; it renders whenever a file label exists, with a `span.dcu-table__file-label` micro-label except alongside the chooser, which carries its own label (the chooser lists distribution titles, the caption the file name); numeric columns (datastore type int/float/numeric, or a dictionary field typed integer/number) carry `dcu-table__cell--number` on `th` and `td`; each panel body starts with `h3.dcu-table__panel-heading`, and reset actions are `button.dcu-table__link-button`.

Progressive enhancement, attached with the table. Icons come from the `dkan_catalog_ui:icon` component (toolbar, chips, pager, sort arrows, column reorder); the Full Screen button keeps its icons and swaps only `.dcu-table__button-label`. Links and toolbar forms inside `#dcu-table` update the table in place (apply route in JSON mode, then the fragment route), push history and restore on back/forward; any failure falls back to navigation. Panels close on Escape, outside clicks, when a sibling opens and from the JS-only close button in each panel head (focus returns to the toggle). JS-only controls: "+ Add filter" rows, operator lists narrowed to the column type, Full Screen (`inert` on the rest of the page; Escape, the button or the top-right close button exit), row height (compact/normal/expanded, `localStorage` key `dcuTableDensity`).

## Limitations

* Text columns sort lexically (`"10" < "9"`) because the datastore stores CSV values as strings unless a data dictionary types them.
* No block placement; the `dkan_catalog_ui_preview` render element (`#resource_id`) renders one table from the current request's query, for custom placements.
* `dkan_catalog_ui.preview.data_source.database` is the only data source. `DataSourceInterface` is the extension point.

## Datastore download route (verified 2026-09-17)

`/api/1/datastore/query/{identifier}/download` (route `dkan_datastore.1.query.id.download`) is the target for filtered downloads.

* `{identifier}` must be `resource_id__version` or a distribution UUID; a bare resource id is a 404.
* Streams every matching row; `rows_limit` does not apply. A `limit` parameter is honoured, so never send one.
* `properties[]` restricts and orders columns. `sorts[0][property|order]` applies. `conditions[i][property|operator|value]` as in the query API; `in` needs an array value (`value[0]`, `value[1]`); a comma string matches nothing.
* CSV header cells are the original column names from the import, not machine names.
* An unknown property returns HTTP 200 with an error body (streaming has already started), so validate columns before building the link.

## Tests

Unit and kernel suites run from the parent module (see its README). Functional tests (BrowserTestBase, about four minutes) need the ddev site:

```bash
ddev exec "DRUPAL_ROOT=/var/www/html/docroot SIMPLETEST_BASE_URL=\$DDEV_PRIMARY_URL SIMPLETEST_DB=mysql://db:db@db:3306/db BROWSERTEST_OUTPUT_DIRECTORY=/tmp vendor/bin/phpunit -c docroot/modules/custom/dkan_catalog_ui --testsuite functional"
```
