<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;

/**
 * Builds render arrays for data tables.
 */
interface DataPreviewBuilderInterface extends TrustedCallbackInterface {

  /**
   * Build a data table for one resource in a given state.
   *
   * @param \Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface $dataSource
   *   The data source to query.
   * @param string $resource_id
   *   The resource identifier ("identifier__version").
   * @param \Drupal\dkan_catalog_ui_preview\TableState $state
   *   Validated table state.
   * @param array $options
   *   Options:
   *   - caption (string|null): File name for the toolbar's file row; also
   *     the table's accessible name. Rendered after a "Data file" label
   *     unless the distribution chooser supplies one.
   *   - base_url (\Drupal\Core\Url|null): URL that links (sort, pager) carry
   *     the state on. Defaults to the current path.
   *   - apply_url (\Drupal\Core\Url|null): Action for toolbar forms. Defaults
   *     to base_url.
   *   - max_page_size (int|null): Row limit capping the page sizes.
   *   - attributes (array): Wrapper attributes.
   *   - tables (array[]): Tabular distributions for the chooser and download
   *     link (see TabularDistributionsInterface::forNode()).
   *   - panel (string|null): Toolbar panel to render open.
   *   - fragment_url (\Drupal\Core\Url|null): Fragment route for in-place
   *     updates; without it the script falls back to full navigation.
   *   - panels (bool): Render the toolbar panels (default TRUE). Only
   *     meaningful with an apply_url that runs TableApplyController; without
   *     one the panels' action buttons would be ignored.
   *
   * @return array|null
   *   Render array for the dkan_catalog_ui_preview:data-table component,
   *   #cache max-age 0, or NULL when the resource has no queryable table yet.
   */
  public function build(DataSourceInterface $dataSource, string $resource_id, TableState $state, array $options = []): ?array;

  /**
   * Lazy builder callback: the table for a dataset node and the request.
   *
   * @param int $nid
   *   Dataset node id.
   *
   * @return array
   *   Render array (empty when the node has no tabular distribution).
   */
  public function lazyBuild(int $nid): array;

}
