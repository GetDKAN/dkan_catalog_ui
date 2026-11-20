<?php

namespace Drupal\dkan_catalog_ui\Alias;

use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;

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
   * The alias language: not specified unless the node is translatable.
   *
   * A language-neutral alias resolves under every language prefix, so
   * /es/dataset/{uuid} works on a site whose datasets are not translated.
   */
  public function langcodeFor(NodeInterface $node): string;

  /**
   * Give a managed dataset alias its langcodeFor() language.
   *
   * Called from hook_path_alias_presave().
   *
   * Other aliases, including hand-made ones on the same path, are untouched.
   */
  public function normalizeLangcode(PathAliasInterface $pathAlias): void;

  /**
   * Create missing aliases for existing dataset nodes.
   *
   * @return int
   *   Number of aliases created.
   */
  public function backfill(): int;

}
