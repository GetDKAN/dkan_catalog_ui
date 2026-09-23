<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Cache\Cache;
use Drupal\dkan_common\DatasetInfo;
use Drupal\dkan_metastore\NodeWrapper\NodeDataFactory;
use Drupal\node\NodeInterface;

/**
 * Import and resource info for a dataset's distributions.
 */
class DistributionInfo implements DistributionInfoInterface {

  /**
   * Per-request cache keyed by dataset uuid.
   *
   * @var array<string, array[]>
   */
  protected array $entries = [];

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DatasetInfo $datasetInfo,
    protected readonly NodeDataFactory $itemFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function all(NodeInterface $node): array {
    $uuid = (string) $node->uuid();
    if (!isset($this->entries[$uuid])) {
      $info = $this->datasetInfo->gather($uuid);
      $revision = $info['published_revision'] ?? $info['latest_revision'] ?? [];
      $this->entries[$uuid] = array_values(array_filter(
        $revision['distributions'] ?? [],
        'is_array'
      ));
    }
    return $this->entries[$uuid];
  }

  /**
   * {@inheritdoc}
   */
  public function byPath(NodeInterface $node): array {
    $byPath = [];
    foreach ($this->all($node) as $entry) {
      if (!empty($entry['source_path'])) {
        $byPath[$this->urlPath($entry['source_path'])] = $entry;
      }
    }
    return $byPath;
  }

  /**
   * {@inheritdoc}
   */
  public function cacheTags(NodeInterface $node): array {
    $tags = [];
    foreach ($this->all($node) as $entry) {
      if (empty($entry['distribution_uuid'])) {
        continue;
      }
      try {
        $tags = Cache::mergeTags($tags, $this->itemFactory->getInstance($entry['distribution_uuid'])->getCacheTags());
      }
      catch (\Throwable) {
        // Unloadable item: nothing to tag.
      }
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function urlPath(string $url): string {
    return (string) (parse_url($url, PHP_URL_PATH) ?: $url);
  }

}
