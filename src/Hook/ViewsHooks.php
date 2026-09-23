<?php

namespace Drupal\dkan_catalog_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Views hook implementations.
 */
class ViewsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data().
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data['views']['dkan_catalog_ui_facets'] = [
      'title' => $this->t('DKAN facet blocks'),
      'help' => $this->t('Render facet blocks for this view inside the view.'),
      'area' => [
        'id' => 'dkan_catalog_ui_facets',
      ],
    ];
    return $data;
  }

}
