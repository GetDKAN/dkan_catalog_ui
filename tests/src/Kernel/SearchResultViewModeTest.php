<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use PHPUnit\Framework\Attributes\Group;

/**
 * The search_result view mode is optional config.
 *
 * Drupal 11.3 node ships it (disabled); 11.4 moved it to search_node.
 */
#[Group('dkan_catalog_ui')]
class SearchResultViewModeTest extends CatalogUiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
    'search_api',
    'search_api_db',
    'facets',
    'dkan_metastore_search',
  ];

  /**
   * Without a view mode, the module's own is installed with the display.
   */
  public function testInstallsOwnViewMode(): void {
    $this->installSearchConfig();
    $this->assertSame('Search result', EntityViewMode::load('node.search_result')->label());
    $this->assertNotNull(EntityViewDisplay::load('node.data.search_result')->getComponent('dataset_card'));
  }

  /**
   * An existing view mode (core 11.3) is kept and the display still installs.
   */
  public function testReusesExistingViewMode(): void {
    // setUp() already installed the module's optional copies; start as 11.3.
    EntityViewDisplay::load('node.data.search_result')?->delete();
    EntityViewMode::load('node.search_result')->delete();
    EntityViewMode::create([
      'id' => 'node.search_result',
      'label' => 'Search result highlighting input',
      'targetEntityType' => 'node',
      'status' => FALSE,
    ])->save();
    $this->installSearchConfig();
    $this->assertSame('Search result highlighting input', EntityViewMode::load('node.search_result')->label());
    $this->assertNotNull(EntityViewDisplay::load('node.data.search_result')->getComponent('dataset_card'));

    // Rendering uses the display; the view mode's status does not matter.
    $node = $this->createDataset('view-mode-test');
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'search_result');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<div class="dcu-card">', $html);
  }

}
