<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dkan_metastore\DataDictionary\DataDictionaryDiscoveryInterface;
use Drupal\dkan_metastore\MetastoreService;
use Drupal\dkan_metastore\NodeWrapper\NodeDataFactory;
use Drupal\node\NodeInterface;

/**
 * Builds the Data Dictionary tab.
 *
 * Mode none: nothing (tab hidden). Mode sitewide: one table for the dataset.
 * Mode reference: one table per distribution that resolves a dictionary,
 * or a "no dictionary" message.
 */
class DataDictionaryBuilder implements DataDictionaryBuilderInterface {

  use StringTranslationTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly DataDictionaryDiscoveryInterface $discovery,
    protected readonly MetastoreService $metastore,
    protected readonly NodeDataFactory $itemFactory,
    protected readonly DistributionInfoInterface $distributionInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, object $metadata): array {
    $mode = $this->discovery->getDataDictionaryMode();
    if ($mode === DataDictionaryDiscoveryInterface::MODE_NONE) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dcu-dataset-dictionaries']],
      '#cache' => [
        'tags' => Cache::mergeTags($node->getCacheTags(), $this->distributionInfo->cacheTags($node)),
      ],
    ];

    $tables = $mode === DataDictionaryDiscoveryInterface::MODE_SITEWIDE
      ? $this->sitewide($node)
      : $this->byReference($node, $metadata);

    if (!$tables) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('There is no data dictionary associated with this dataset.'),
        '#attributes' => ['class' => ['dcu-dataset-dictionaries__empty']],
      ];
      return $build;
    }

    foreach ($tables as $delta => $table) {
      $build[$delta] = $table;
    }
    return $build;
  }

  /**
   * The single sitewide dictionary table.
   */
  protected function sitewide(NodeInterface $node): array {
    try {
      $id = $this->discovery->getSitewideDictionaryId();
    }
    catch (\Throwable) {
      return [];
    }
    $table = $this->table($id, NULL, $node->getTitle());
    return $table ? [$table] : [];
  }

  /**
   * One table per distribution that references a dictionary.
   */
  protected function byReference(NodeInterface $node, object $metadata): array {
    $titles = $this->distributionTitles($metadata);
    $tables = [];
    foreach ($this->distributionInfo->all($node) as $entry) {
      if (empty($entry['resource_id']) || empty($entry['resource_version'])) {
        continue;
      }
      try {
        $id = $this->discovery->dictionaryIdFromResource($entry['resource_id'], (int) $entry['resource_version']);
      }
      catch (\Throwable) {
        continue;
      }
      if (!$id) {
        continue;
      }
      $path = $this->distributionInfo->urlPath((string) ($entry['source_path'] ?? ''));
      $table = $this->table($id, $titles[$path] ?? NULL, $node->getTitle());
      if ($table) {
        $tables[] = $table;
      }
    }
    return $tables;
  }

  /**
   * Distribution titles from metadata keyed by download URL path.
   */
  protected function distributionTitles(object $metadata): array {
    $titles = [];
    foreach ($metadata->distribution ?? [] as $dist) {
      if (!is_object($dist)) {
        continue;
      }
      $url = (string) ($dist->downloadURL ?? $dist->accessURL ?? '');
      if ($url !== '' && !empty($dist->title)) {
        $titles[$this->distributionInfo->urlPath($url)] = (string) $dist->title;
      }
    }
    return $titles;
  }

  /**
   * Render a dictionary as a data-dictionary component, or NULL if unloadable.
   *
   * The heading is hidden when it would repeat the dataset title.
   */
  protected function table(string $id, ?string $distributionTitle, string $datasetTitle): ?array {
    try {
      $dictionary = json_decode((string) $this->metastore->get('data-dictionary', $id));
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!is_object($dictionary)) {
      return NULL;
    }

    $fields = [];
    foreach ($dictionary->data->fields ?? [] as $field) {
      if (!is_object($field) || empty($field->name)) {
        continue;
      }
      $fields[] = [
        'name' => (string) $field->name,
        'title' => (string) ($field->title ?? ''),
        'type' => (string) ($field->type ?? ''),
        'format' => (string) ($field->format ?? ''),
        'description' => (string) ($field->description ?? ''),
      ];
    }

    $title = $distributionTitle ?? (string) ($dictionary->data->title ?? '');

    $tags = [];
    try {
      $tags = $this->itemFactory->getInstance($id)->getCacheTags();
    }
    catch (\Throwable) {
      // Fall back to the dataset-level tags only.
    }

    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:data-dictionary',
      '#props' => [
        'title' => $title,
        'show_title' => mb_strtolower(trim($title)) !== mb_strtolower(trim($datasetTitle)),
        'fields' => $fields,
      ],
      '#cache' => ['tags' => $tags],
    ];
  }

}
