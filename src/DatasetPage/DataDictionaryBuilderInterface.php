<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\node\NodeInterface;

/**
 * Builds the Data Dictionary tab.
 */
interface DataDictionaryBuilderInterface {

  /**
   * Cache tag of the config that holds the data dictionary mode.
   */
  const SETTINGS_CACHE_TAG = 'config:dkan_metastore.settings';

  /**
   * Build the data dictionary render array.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The dataset node.
   * @param object $metadata
   *   Dereferenced dataset metadata.
   *
   * @return array
   *   Render array, or an empty array when the site has data dictionaries
   *   disabled (the tab is then omitted).
   */
  public function build(NodeInterface $node, object $metadata): array;

}
