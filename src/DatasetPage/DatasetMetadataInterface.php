<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\node\NodeInterface;

/**
 * Loads dereferenced dataset metadata and schema labels for rendering.
 */
interface DatasetMetadataInterface {

  /**
   * Dereferenced dataset metadata for a node, or NULL when unavailable.
   */
  public function load(NodeInterface $node): ?object;

  /**
   * Human-readable titles from the dataset schema, keyed by property name.
   *
   * @return string[]
   *   Property titles.
   */
  public function propertyLabels(): array;

}
