<?php

namespace Drupal\dkan_catalog_ui\Hook;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dkan_catalog_ui\Alias\DatasetAliasManagerInterface;
use Drupal\dkan_catalog_ui\DatasetPage\ApiBuilderInterface;
use Drupal\dkan_catalog_ui\DatasetPage\DataDictionaryBuilderInterface;
use Drupal\dkan_catalog_ui\DatasetPage\DatasetMetadataInterface;
use Drupal\dkan_catalog_ui\DatasetPage\OverviewBuilderInterface;
use Drupal\dkan_catalog_ui\Search\SearchResultBuilderInterface;
use Drupal\node\NodeInterface;

/**
 * Entity hook implementations: dataset aliases and dataset page sections.
 */
class EntityHooks {

  use StringTranslationTrait;

  /**
   * Extra field: modified date and description.
   */
  const FIELD_HEADER = 'dataset_header';

  /**
   * Extra field: Overview tab.
   */
  const FIELD_OVERVIEW = 'dataset_overview';

  /**
   * Extra field: Data Dictionary tab.
   */
  const FIELD_DATA_DICTIONARY = 'dataset_data_dictionary';

  /**
   * Extra field: API tab.
   */
  const FIELD_API = 'dataset_api';

  /**
   * Extra field provided by the preview submodule (Data Table tab).
   */
  const FIELD_PREVIEW = 'data_preview';

  /**
   * Extra field: dataset card for search results (hidden by default).
   */
  const FIELD_CARD = 'dataset_card';

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DatasetAliasManagerInterface $aliasManager,
    protected readonly DatasetMetadataInterface $datasetMetadata,
    protected readonly OverviewBuilderInterface $overviewBuilder,
    protected readonly DataDictionaryBuilderInterface $dataDictionaryBuilder,
    protected readonly ApiBuilderInterface $apiBuilder,
    protected readonly SearchResultBuilderInterface $searchResultBuilder,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() for node entities.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    $this->aliasManager->apply($node);
  }

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra = [];
    if (!$this->entityTypeManager->getStorage('node_type')->load('data')) {
      return $extra;
    }
    $fields = [
      self::FIELD_HEADER => [
        $this->t('Dataset header'),
        $this->t('Modified date and description.'),
        0,
      ],
      self::FIELD_OVERVIEW => [
        $this->t('Overview'),
        $this->t('Resources and additional information.'),
        20,
      ],
      self::FIELD_DATA_DICTIONARY => [
        $this->t('Data Dictionary'),
        $this->t('Data dictionary tables for the dataset.'),
        30,
      ],
      self::FIELD_API => [
        $this->t('API'),
        $this->t('API documentation for the dataset.'),
        40,
      ],
    ];
    foreach ($fields as $name => [$label, $description, $weight]) {
      $extra['node']['data']['display'][$name] = [
        'label' => $label,
        'description' => $description,
        'weight' => $weight,
        'visible' => TRUE,
      ];
    }
    $extra['node']['data']['display'][self::FIELD_CARD] = [
      'label' => $this->t('Dataset card'),
      'description' => $this->t('Compact summary used in search results.'),
      'weight' => 0,
      'visible' => FALSE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for node entities.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'data') {
      return;
    }
    if (($entity->get('field_data_type')->value ?? '') !== 'dataset') {
      return;
    }
    $wanted = array_filter(
      [self::FIELD_CARD, self::FIELD_HEADER, self::FIELD_OVERVIEW, self::FIELD_DATA_DICTIONARY, self::FIELD_API],
      fn(string $field) => (bool) $display->getComponent($field)
    );
    if (!$wanted) {
      return;
    }
    $metadata = $this->datasetMetadata->load($entity);
    if (!$metadata) {
      return;
    }
    if (in_array(self::FIELD_CARD, $wanted, TRUE)) {
      $build[self::FIELD_CARD] = $this->searchResultBuilder->build($entity, $metadata);
    }
    if (in_array(self::FIELD_HEADER, $wanted, TRUE)) {
      $build[self::FIELD_HEADER] = $this->buildHeader($metadata);
    }
    if (in_array(self::FIELD_OVERVIEW, $wanted, TRUE)) {
      $build[self::FIELD_OVERVIEW] = $this->overviewBuilder->build($entity, $metadata);
    }
    if (in_array(self::FIELD_DATA_DICTIONARY, $wanted, TRUE)) {
      $dictionary = $this->dataDictionaryBuilder->build($entity, $metadata);
      if ($dictionary) {
        $build[self::FIELD_DATA_DICTIONARY] = $dictionary;
      }
    }
    if (in_array(self::FIELD_API, $wanted, TRUE)) {
      $build[self::FIELD_API] = $this->apiBuilder->build($entity, $metadata);
    }
  }

  /**
   * Build the header: modified date and description.
   */
  protected function buildHeader(object $metadata): array {
    $modified = (string) ($metadata->modified ?? '');
    $timestamp = $modified !== '' ? strtotime($modified) : FALSE;
    $description = (string) ($metadata->description ?? '');

    $build = [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:dataset-header',
      '#props' => [
        'updated' => $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'F j, Y') : $modified,
        'updated_iso' => $timestamp ? date('Y-m-d', $timestamp) : '',
      ],
    ];
    if ($description !== '') {
      $build['#slots']['description'] = ['#markup' => Xss::filter($description)];
    }
    return $build;
  }

}
