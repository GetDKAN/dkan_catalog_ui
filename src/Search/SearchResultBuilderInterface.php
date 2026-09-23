<?php

namespace Drupal\dkan_catalog_ui\Search;

use Drupal\node\NodeInterface;

/**
 * Builds the dataset card shown in search results.
 */
interface SearchResultBuilderInterface {

  /**
   * Build the card render array for a dataset node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The dataset node.
   * @param object $metadata
   *   Dereferenced dataset metadata.
   *
   * @return array
   *   Render array.
   */
  public function build(NodeInterface $node, object $metadata): array;

}
