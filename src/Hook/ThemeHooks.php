<?php

namespace Drupal\dkan_catalog_ui\Hook;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Theme hook implementations for the dataset page.
 */
class ThemeHooks {

  use StringTranslationTrait;

  /**
   * Tab id => [extra field name, label], in display order.
   */
  protected function tabs(): array {
    return [
      'data-table' => [EntityHooks::FIELD_PREVIEW, $this->t('Data Table')],
      'overview' => [EntityHooks::FIELD_OVERVIEW, $this->t('Overview')],
      'data-dictionary' => [EntityHooks::FIELD_DATA_DICTIONARY, $this->t('Data Dictionary')],
      'api' => [EntityHooks::FIELD_API, $this->t('API')],
    ];
  }

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    $path = $this->moduleList->getPath('dkan_catalog_ui') . '/templates';
    return [
      'node__data__full' => [
        'base hook' => 'node',
        'template' => 'node--data--full',
        'path' => $path,
      ],
      'node__data__search_result' => [
        'base hook' => 'node',
        'template' => 'node--data--search-result',
        'path' => $path,
      ],
      'views_view__dkan_catalog_ui_search' => [
        'base hook' => 'views_view',
        'template' => 'views-view--dkan-catalog-ui-search',
        'path' => $path,
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK() for node templates.
   *
   * Adds dcu_tabs (the non-empty dataset tabs) for node--data--full.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'data') {
      return;
    }
    if (($variables['view_mode'] ?? '') !== 'full') {
      return;
    }
    $content = $variables['content'] ?? [];
    $tabs = [];
    foreach ($this->tabs() as $id => [$field, $label]) {
      if (isset($content[$field]) && is_array($content[$field]) && $this->hasOutput($content[$field])) {
        $tabs[] = ['id' => $id, 'label' => (string) $label];
      }
    }
    $variables['dcu_tabs'] = $tabs;
    $variables['#attached']['library'][] = 'dkan_catalog_ui/base';
  }

  /**
   * Implements hook_preprocess_HOOK() for views templates.
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    if (($variables['view']->id() ?? '') !== 'dkan_catalog_ui_search') {
      return;
    }
    $variables['#attached']['library'][] = 'dkan_catalog_ui/search';
    // Facets go to the sidebar; the rest of the header (result summary)
    // stays with the results.
    $variables['dcu_facets'] = [];
    if (is_array($variables['header'] ?? NULL) && isset($variables['header']['dkan_catalog_ui_facets'])) {
      $variables['dcu_facets'] = $variables['header']['dkan_catalog_ui_facets'];
      unset($variables['header']['dkan_catalog_ui_facets']);
    }
  }

  /**
   * Whether a render array produces output (has children or is an element).
   */
  protected function hasOutput(array $element): bool {
    if (Element::isEmpty($element)) {
      return FALSE;
    }
    return Element::children($element) !== []
      || isset($element['#lazy_builder'])
      || isset($element['#type'])
      || isset($element['#theme'])
      || isset($element['#markup']);
  }

}
