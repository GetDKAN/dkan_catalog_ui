# DKAN Catalog UI Preview

Server-rendered data table for the dataset page (`/dataset/{uuid}`, `/node/{nid}`). Forked from DKAN core `dkan_datastore_preview` (GetDKAN/dkan#4784, commit `0aa181ff7`) on 2026-09-17 and maintained here; see `PLAN-data-table.md` in the site root for the roadmap.

Enable with `drush en dkan_catalog_ui_preview`. Install adds a **Data Preview** extra field to the `data` node type's default view display; toggle or reorder it under *Structure → Content types → Data → Manage display*. Cannot be enabled together with `dkan_datastore_preview`.

## Behavior

* One table at a time for the dataset's tabular distributions (CSV/TSV); non-tabular distributions render nothing. The dataset page renders the table through a lazy-builder placeholder (`DataPreviewBuilder::lazyBuild()`), so the node stays in the render cache and the dynamic page cache while the table (`max-age` 0) renders per request.
* One workspace (`data-table` component): toolbar, table, footer. Toolbar (`data-table-toolbar`), three rows: the file header (distribution chooser when more than one tabular distribution, the file caption, `CSV · 15 columns` metadata from the file extension and the exposed schema, then Download); the tools row (Filters / Columns / View / Share view, `<details>` panels); the status row (result summary, then chips for active filters and hidden columns with Clear all, which clears both but not sort). Footer: the `data-table-page-size` form (Rows per page) and the pager. Everything works without JavaScript; sort columns, filter columns and operators are validated against the table schema.
* Download: a disclosure with "Original file (FORMAT)" (validated `downloadURL`, independent of the view) and "Current results (CSV)" on the datastore download route with the current conditions, sort and columns (all matching rows, never a page limit; rendered disabled with zero matches). With only the original (no table yet) it is a direct link; with neither it is absent.
* Panels: Filters and Columns hold GET forms to the apply route; each primary action (Apply filters, Save) closes its panel, secondary controls (add or remove a filter row, move a column, reset) leave it open. Filter rows have visible Column / Condition / Value labels. View (row height, Expand table) is JS-only and hidden without the script. Share view shows the canonical link as a read-only field plus a JS copy button; the link carries distribution, filters, columns, sort and page, not row height.
* Rows per page: GET form in the footer carrying the state minus `page` / `page_size` (no `panel`), so a change returns to page 1. Without JS it has an Apply button; with JS the button is hidden and the table updates in place on change.
* Summary: `10 rows`; `3 of 10 rows` (filtered); `Rows 26–50 of 1,000`; `Rows 26–50 of 120 matching rows · 1,000 total`; `No matching rows · 10 total`; `No rows available` (empty file). The unfiltered total comes from `DataSourceResult::$unfilteredTotalCount`; a source that leaves it NULL gets the same sentences without the total.
* Column labels: data dictionary `title` when `dkan_metastore.settings.data_dictionary_mode` is not `none` and a dictionary resolves for the resource (fields matched by machine name, then original header); `description` is the header tooltip. Otherwise the original CSV header.
* Class contract: `.dcu-table` (root, `id="dcu-table"`, `tabindex="-1"`, the bordered workspace), `.dcu-table__toolbar`, `.dcu-table__file` (`.dcu-table__identity` with `.dcu-table__chooser` / `.dcu-table__caption` and its `.dcu-table__file-label` / `.dcu-table__meta`, then `details#dcu-table-download` or `a.dcu-table__download`), `.dcu-table__tools`, `.dcu-table__status` (`.dcu-table__summary`, chips), `.dcu-table__panel` (`--filters`, `--columns`, `--display` (View), `--share`, `--download`), `.dcu-table__panel-body`, `.dcu-table__field`, `.dcu-table__download-option` with `.dcu-table__download-original` or `.dcu-table__download-results`, `.dcu-table__chip`, `.dcu-table__wrapper` (the only horizontal scroller), `.dcu-table__table`, `.dcu-table__footer` (`.dcu-table__page-size`, pager), `.dcu-table__message`.
* Breaking changes (2026-09-23): toolbar props `display` and `share.download_url` removed; `download` is now `{original, results}`; new `file_meta`, `chips.label`, `chips.clear_all_label`, `labels.download`; `data-table` has a `page_size` slot; `.dcu-table__filtered-download` and the tools-row Full Screen button are gone.
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

Markup notes: the caption is a `p.dcu-table__caption#dcu-table-caption` in the file header's identity block and the table's `aria-labelledby`; it renders whenever a file label exists, with a `span.dcu-table__file-label` micro-label except alongside the chooser, which carries its own label (the chooser lists distribution titles, the caption the file name); numeric columns (datastore type int/float/numeric, or a dictionary field typed integer/number) carry `dcu-table__cell--number` on `th` and `td`; each panel body starts with `h3.dcu-table__panel-heading`, and reset actions are `button.dcu-table__link-button`.

Progressive enhancement, attached with the table. Icons come from the `dkan_catalog_ui:icon` component (toolbar, chips, pager, sort arrows, column reorder); the expand button keeps its icons and swaps only `.dcu-table__button-label`. Links, toolbar forms and `form[data-dcu-state-form]` (the footer page size) inside `#dcu-table` update the table in place (apply route in JSON mode, then the fragment route), push history and restore on back/forward; a newer request supersedes an older one; any failure falls back to navigation. Download and export links are never intercepted. Panels (Download included) close on Escape, outside clicks, when a sibling opens and from the JS-only close button in each panel head (focus returns to the toggle). JS-only controls: the View panel (`[data-dcu-view]`) with row height (compact/normal/expanded, `localStorage` key `dcuTableDensity`, not part of shared links) and Expand table (`inert` on the rest of the page; Escape, the button or the top-right close button exit, closing View and focusing its toggle); "+ Add filter" rows; operator lists narrowed to the column type; copy link; page size on change (Apply hidden).

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
