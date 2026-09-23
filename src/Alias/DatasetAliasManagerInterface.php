<?php

namespace Drupal\dkan_catalog_ui\Alias;

use Drupal\node\NodeInterface;

/**
 * Maintains /dataset/{uuid} path aliases for dataset nodes.
 */
interface DatasetAliasManagerInterface {

  /**
   * Alias prefix; the node UUID is the dataset identifier.
   */
  const ALIAS_PREFIX = '/dataset/';

  /**
   * Whether the node is a dataset node that gets an alias.
   */
  public function applies(NodeInterface $node): bool;

  /**
   * The alias a dataset node should have.
   */
  public function aliasFor(NodeInterface $node): string;

  /**
   * Set the alias on the node's path field (call from presave).
   */
  public function apply(NodeInterface $node): void;

  /**
   * Create missing aliases for existing dataset nodes.
   *
   * @return int
   *   Number of aliases created.
   */
  public function backfill(): int;

}
