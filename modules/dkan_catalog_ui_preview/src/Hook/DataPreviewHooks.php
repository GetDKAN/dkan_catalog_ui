<?php

namespace Drupal\dkan_catalog_ui_preview\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dkan_catalog_ui_preview\TabularDistributionsInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for dkan_catalog_ui_preview.
 *
 * On Drupal 10 the procedural stubs in dkan_catalog_ui_preview.module
 * delegate here; on Drupal 11.1+ the #[Hook] attributes register directly.
 */
class DataPreviewHooks {

  use StringTranslationTrait;

  /**
   * Extra field name on the data node display.
   */
  const EXTRA_FIELD = 'data_preview';

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TabularDistributionsInterface $tabularDistributions,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'dkan_catalog_ui_preview_pager' => [
        'variables' => [
          'items' => [],
          'current' => 1,
          'total' => 1,
          'ellipses' => [],
        ],
        'template' => 'dkan-catalog-ui-preview-pager',
      ],
    ];
  }

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra = [];
    if ($this->entityTypeManager->getStorage('node_type')->load('data')) {
      $extra['node']['data']['display'][self::EXTRA_FIELD] = [
        'label' => $this->t('Data Table'),
        'description' => $this->t('Filterable, sortable table of the datastore data.'),
        'weight' => 100,
        'visible' => TRUE,
      ];
    }
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for node entities.
   *
   * Renders the data table through a lazy-builder placeholder so its
   * request-dependent state never enters the node render cache.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'data') {
      return;
    }
    if (($entity->get('field_data_type')->value ?? '') !== 'dataset') {
      return;
    }
    if (!$display->getComponent(self::EXTRA_FIELD)) {
      return;
    }

    // Datastore tables cannot exist for an unsaved node being previewed.
    if (!empty($entity->in_preview) && $entity->isNew()) {
      $build[self::EXTRA_FIELD] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The data table is available after the dataset is saved and data is imported.'),
      ];
      return;
    }

    if (!$this->tabularDistributions->forNode($entity)) {
      return;
    }
    $build[self::EXTRA_FIELD] = [
      '#lazy_builder' => ['dkan_catalog_ui.preview.builder:lazyBuild', [(int) $entity->id()]],
      '#create_placeholder' => TRUE,
      '#cache' => [
        'tags' => $this->tabularDistributions->cacheTags($entity),
      ],
    ];
  }

}
