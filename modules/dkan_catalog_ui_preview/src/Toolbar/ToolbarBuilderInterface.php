<?php

namespace Drupal\dkan_catalog_ui_preview\Toolbar;

use Drupal\dkan_catalog_ui_preview\TableState;

/**
 * Builds the data table toolbar (file header, downloads, panels, chips).
 */
interface ToolbarBuilderInterface {

  /**
   * Build the toolbar component render array.
   *
   * @param \Drupal\dkan_catalog_ui_preview\TableState $state
   *   Current table state.
   * @param array $fields
   *   Schema fields keyed by machine name.
   * @param array $options
   *   Options:
   *   - base_url (\Drupal\Core\Url): Dataset URL that links carry state on.
   *   - apply_url (\Drupal\Core\Url): Action of the toolbar forms.
   *   - fragment_url (\Drupal\Core\Url|null): Fragment route for the script.
   *   - tables (array[]): Tabular distributions (see
   *     TabularDistributionsInterface::forNode()); empty for a standalone
   *     table.
   *   - panel (string|null): Panel that renders open.
   *   - summary (array): Result summary render array; a non-empty one
   *     renders the status row.
   *   - caption (string|null): File name for the file row; the element the
   *     table's aria-labelledby points at.
   *   - panels (bool): FALSE renders the file row only, without the tools
   *     (no table available).
   *   - resource_id (string|null): Resource id ("identifier__version") for
   *     the current-results download link.
   *   - matching_count (int|null): Rows matching the state; 0 disables the
   *     current-results download.
   *   - labels (array<string, string>): Display label per column machine
   *     name (dictionary titles); falls back to the schema description.
   *
   * @return array
   *   Render array for the dkan_catalog_ui_preview:data-table-toolbar
   *   component.
   */
  public function build(TableState $state, array $fields, array $options = []): array;

}
