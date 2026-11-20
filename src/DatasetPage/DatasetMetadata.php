<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Cache\Cache;
use Drupal\dkan_metastore\MetastoreService;
use Drupal\dkan_metastore\NodeWrapper\NodeDataFactory;
use Drupal\dkan_metastore\SchemaRetriever;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads dereferenced dataset metadata through the metastore service.
 *
 * MetastoreService::get() dispatches the data-get event, so references
 * (publisher, theme, keyword, distribution) come back expanded exactly as
 * the public API returns them.
 */
class DatasetMetadata implements DatasetMetadataInterface {

  /**
   * Per-request cache of decoded metadata keyed by uuid and published flag.
   *
   * @var array<string, object|null>
   */
  protected array $cache = [];

  /**
   * Cached schema labels.
   *
   * @var string[]|null
   */
  protected ?array $labels = NULL;

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly MetastoreService $metastore,
    protected readonly SchemaRetriever $schemaRetriever,
    protected readonly LoggerInterface $logger,
    protected readonly NodeDataFactory $itemFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(NodeInterface $node): ?object {
    $uuid = (string) $node->uuid();
    $published = $node->isPublished();
    $key = $uuid . ':' . (int) $published;
    if (array_key_exists($key, $this->cache)) {
      return $this->cache[$key];
    }

    $data = NULL;
    try {
      $json = $this->metastore->get('dataset', $uuid, $published);
      $decoded = json_decode((string) $json);
      $data = is_object($decoded) ? $decoded : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->notice('Dataset metadata unavailable for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    return $this->cache[$key] = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function propertyLabels(): array {
    if ($this->labels !== NULL) {
      return $this->labels;
    }
    $this->labels = [];
    $schema = json_decode((string) $this->schemaRetriever->retrieve('dataset'), TRUE);
    foreach ($schema['properties'] ?? [] as $name => $definition) {
      if (!empty($definition['title']) && is_string($definition['title'])) {
        $this->labels[$name] = $definition['title'];
      }
    }
    return $this->labels;
  }

  /**
   * {@inheritdoc}
   *
   * Reads the stored (referenced) metadata: every `%Ref:*` property holds
   * the identifiers of the items its values were dereferenced from, as
   * \Drupal\dkan_metastore\MetastoreApiResponse does for API responses.
   */
  public function referenceCacheTags(NodeInterface $node): array {
    $tags = [];
    if (!$node->hasField('field_json_metadata')) {
      return $tags;
    }
    $stored = json_decode((string) $node->get('field_json_metadata')->value);
    foreach (is_object($stored) ? get_object_vars($stored) : [] as $property => $value) {
      if (!str_starts_with((string) $property, '%Ref')) {
        continue;
      }
      foreach (is_array($value) ? $value : [$value] as $reference) {
        $identifier = is_object($reference) ? ($reference->identifier ?? NULL) : NULL;
        if (!is_string($identifier) || $identifier === '') {
          continue;
        }
        try {
          $tags = Cache::mergeTags($tags, $this->itemFactory->getInstance($identifier)->getCacheTags());
        }
        catch (\Throwable) {
          // A dangling reference has nothing to depend on.
        }
      }
    }
    return $tags;
  }

}
