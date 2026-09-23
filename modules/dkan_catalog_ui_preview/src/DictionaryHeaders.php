<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\dkan_common\DataResource;
use Drupal\dkan_metastore\DataDictionary\DataDictionaryDiscoveryInterface;
use Drupal\dkan_metastore\MetastoreService;
use Drupal\dkan_metastore\NodeWrapper\NodeDataFactory;

/**
 * Resolves a resource's data dictionary into column labels.
 */
class DictionaryHeaders implements DictionaryHeadersInterface {

  /**
   * Dictionary ids resolved per resource id (NULL when none).
   *
   * @var array<string, string|null>
   */
  protected array $ids = [];

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DataDictionaryDiscoveryInterface $discovery,
    protected readonly MetastoreService $metastore,
    protected readonly NodeDataFactory $itemFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function labels(string $resource_id, array $fields): array {
    $id = $this->dictionaryId($resource_id);
    if ($id === NULL) {
      return [];
    }
    try {
      $dictionary = json_decode((string) $this->metastore->get('data-dictionary', $id));
    }
    catch (\Throwable) {
      return [];
    }

    $byName = [];
    foreach ($dictionary->data->fields ?? [] as $field) {
      if (is_object($field) && !empty($field->name)) {
        $byName[(string) $field->name] = $field;
      }
    }

    $labels = [];
    foreach ($fields as $name => $definition) {
      $field = $byName[$name] ?? $byName[(string) ($definition['description'] ?? '')] ?? NULL;
      if (!$field || (empty($field->title) && empty($field->type))) {
        continue;
      }
      $labels[$name] = [
        'title' => (string) ($field->title ?? ''),
        'description' => (string) ($field->description ?? ''),
        'type' => (string) ($field->type ?? ''),
      ];
    }
    return $labels;
  }

  /**
   * {@inheritdoc}
   */
  public function cacheTags(string $resource_id): array {
    $id = $this->dictionaryId($resource_id);
    if ($id === NULL) {
      return [];
    }
    try {
      return $this->itemFactory->getInstance($id)->getCacheTags();
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * The dictionary id for a resource, or NULL (mode "none", unresolvable).
   */
  protected function dictionaryId(string $resource_id): ?string {
    if (array_key_exists($resource_id, $this->ids)) {
      return $this->ids[$resource_id];
    }
    $id = NULL;
    if ($this->discovery->getDataDictionaryMode() !== DataDictionaryDiscoveryInterface::MODE_NONE) {
      try {
        [$identifier, $version] = DataResource::getIdentifierAndVersion($resource_id);
        $id = $this->discovery->dictionaryIdFromResource($identifier, (int) $version) ?: NULL;
      }
      catch (\Throwable) {
        $id = NULL;
      }
    }
    return $this->ids[$resource_id] = $id;
  }

}
