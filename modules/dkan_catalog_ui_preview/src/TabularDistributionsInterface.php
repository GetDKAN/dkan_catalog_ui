<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\node\NodeInterface;

/**
 * Lists the distributions of a dataset node that have datastore tables.
 */
interface TabularDistributionsInterface {

  /**
   * Tabular distributions in metadata order.
   *
   * @return array[]
   *   Entries with keys: resource_id ("identifier__version"), label (source
   *   file name), title (distribution title), download_url (raw metadata
   *   downloadURL, unvalidated), distribution_uuid.
   */
  public function forNode(NodeInterface $node): array;

  /**
   * Cache tags for the node and every distribution item.
   *
   * @return string[]
   *   Tags.
   */
  public function cacheTags(NodeInterface $node): array;

}
