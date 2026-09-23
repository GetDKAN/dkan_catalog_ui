<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\node\NodeInterface;

/**
 * Builds the API tab.
 */
interface ApiBuilderInterface {

  /**
   * Build the API render array.
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
