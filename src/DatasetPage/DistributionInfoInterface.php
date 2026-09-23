<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\node\NodeInterface;

/**
 * Import and resource info for a dataset's distributions.
 *
 * Wraps DatasetInfo::gather() so builders share one lookup per request.
 * Dereferenced metadata carries no distribution identifiers, so entries are
 * also keyed by download URL path for matching against metadata.
 */
interface DistributionInfoInterface {

  /**
   * Distribution info entries for the node's current revision.
   *
   * @return array[]
   *   Each entry has distribution_uuid, resource_id, resource_version,
   *   mime_type and source_path (some may be empty).
   */
  public function all(NodeInterface $node): array;

  /**
   * The same entries keyed by the URL path of their source file.
   *
   * @return array[]
   *   Entries keyed by path.
   */
  public function byPath(NodeInterface $node): array;

  /**
   * Cache tags of every distribution item of the node.
   *
   * @return string[]
   *   Cache tags.
   */
  public function cacheTags(NodeInterface $node): array;

  /**
   * URL path component used as the matching key.
   */
  public function urlPath(string $url): string;

}
