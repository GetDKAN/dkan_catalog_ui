<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\Core\Cache\Cache;
use Drupal\dkan_catalog_ui\DatasetPage\DistributionInfoInterface;
use Drupal\dkan_common\DataResource;
use Drupal\dkan_metastore\NodeWrapper\NodeDataFactory;
use Drupal\node\NodeInterface;

/**
 * Tabular distributions of a dataset, from dataset info and metastore items.
 */
class TabularDistributions implements TabularDistributionsInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DistributionInfoInterface $distributionInfo,
    protected readonly NodeDataFactory $itemFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function forNode(NodeInterface $node): array {
    $tables = [];
    foreach ($this->distributionInfo->all($node) as $dist) {
      if (empty($dist['resource_id']) || empty($dist['resource_version'])) {
        continue;
      }
      if (!in_array($dist['mime_type'] ?? '', DataResource::IMPORTABLE_FILE_TYPES)) {
        continue;
      }
      $label = '';
      if (!empty($dist['source_path'])) {
        $label = basename(parse_url($dist['source_path'], PHP_URL_PATH) ?: $dist['source_path']);
      }
      $metadata = $this->metadata($dist['distribution_uuid'] ?? '');
      $tables[] = [
        'resource_id' => $dist['resource_id'] . '__' . $dist['resource_version'],
        'label' => $label,
        'title' => (string) ($metadata->title ?? $label),
        'download_url' => (string) ($metadata->downloadURL ?? ''),
        'distribution_uuid' => (string) ($dist['distribution_uuid'] ?? ''),
      ];
    }
    return $tables;
  }

  /**
   * {@inheritdoc}
   */
  public function cacheTags(NodeInterface $node): array {
    return Cache::mergeTags($node->getCacheTags(), $this->distributionInfo->cacheTags($node));
  }

  /**
   * Distribution metadata, or NULL when the item cannot be loaded.
   */
  protected function metadata(string $uuid): ?object {
    if ($uuid === '') {
      return NULL;
    }
    try {
      $metadata = $this->itemFactory->getInstance($uuid)->getMetaData();
      return is_object($metadata) ? ($metadata->data ?? $metadata) : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
