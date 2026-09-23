<?php

namespace Drupal\dkan_catalog_ui\Plugin\views\row;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Utility\Utility;
use Drupal\views\Attribute\ViewsRow;
use Drupal\views\Plugin\views\row\RowPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a DKAN search index result as its dataset node in a view mode.
 *
 * The dkan_dataset search_api datasource does not implement viewItem(), so
 * the stock "Rendered entity" row cannot be used. Item ids are the dataset
 * identifiers, which are also the node UUIDs.
 */
#[ViewsRow(
  id: 'dkan_dataset_card',
  title: new TranslatableMarkup('DKAN dataset card'),
  help: new TranslatableMarkup('Render each dataset search result as its node in a view mode.'),
  display_types: ['normal'],
)]
class DatasetCard extends RowPluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesOptions = TRUE;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['view_mode'] = ['default' => 'search_result'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['view_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('View mode'),
      '#default_value' => $this->options['view_mode'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preRender($result) {
    $uuids = [];
    foreach ($result as $row) {
      if ($uuid = $this->uuidFromRow($row)) {
        $uuids[$row->index] = $uuid;
      }
    }
    if (!$uuids) {
      return;
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'uuid' => array_values($uuids),
      'type' => 'data',
    ]);
    $byUuid = [];
    foreach ($nodes as $node) {
      $byUuid[$node->uuid()] = $node;
    }
    foreach ($result as $row) {
      $row->_dkan_node = $byUuid[$uuids[$row->index] ?? ''] ?? NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render($row) {
    $node = $row->_dkan_node ?? NULL;
    if (!$node) {
      return [];
    }
    return $this->entityTypeManager->getViewBuilder('node')->view($node, $this->options['view_mode']);
  }

  /**
   * Dataset identifier (node uuid) from a search result row.
   */
  protected function uuidFromRow(ResultRow $row): ?string {
    $combined = $row->_item?->getId() ?? $row->search_api_id ?? NULL;
    if (!$combined) {
      return NULL;
    }
    [, $raw] = Utility::splitCombinedId($combined);
    // Search API item ids may carry a language suffix ("uuid:en").
    return $raw ? explode(':', $raw, 2)[0] : NULL;
  }

}
