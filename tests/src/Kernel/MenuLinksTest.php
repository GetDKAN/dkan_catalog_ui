<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shipped main-menu links and the legacy link cleanup.
 */
#[Group('dkan_catalog_ui')]
class MenuLinksTest extends CatalogUiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'search_api', 'facets', 'dkan_metastore_search', 'link', 'menu_link_content'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['dkan_catalog_ui']);
    // Static links and the view route are discovered on rebuild.
    $this->container->get('router.builder')->rebuild();
    $this->container->get('plugin.manager.menu.link')->rebuild();
  }

  /**
   * Merge the base class modules with this test's.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    static::$modules = array_values(array_unique(array_merge(parent::$modules, static::$modules)));
  }

  /**
   * Titles of the main menu's top-level links, keyed by plugin id.
   */
  protected function mainMenu(): array {
    $tree = $this->container->get('menu.link_tree')->load('main', (new MenuTreeParameters())->setMaxDepth(1));
    $links = [];
    foreach ($tree as $id => $element) {
      $links[$id] = [$element->link->getTitle(), $element->link->getRouteName()];
    }
    return $links;
  }

  /**
   * Datasets and API come from the module; the cleanup is an exact match.
   */
  public function testLinks(): void {
    $links = $this->mainMenu();
    $this->assertSame(['Datasets', 'view.dkan_catalog_ui_search.page_1'], $links['dkan_catalog_ui.datasets']);
    $this->assertSame(['API', 'dkan_catalog_ui.api_docs'], $links['dkan_catalog_ui.api']);
    $this->assertCount(2, $links);

    // A fresh site has nothing to clean up.
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'post_update.php');
    $this->assertSame('No content link to /search in the main menu.', dkan_catalog_ui_remove_search_menu_link());

    // The legacy link goes; a differently titled /search link stays.
    MenuLinkContent::create(['title' => 'Datasets', 'menu_name' => 'main', 'link' => ['uri' => 'internal:/search']])->save();
    MenuLinkContent::create(['title' => 'Find data', 'menu_name' => 'main', 'link' => ['uri' => 'internal:/search']])->save();
    $this->assertCount(4, $this->mainMenu());
    $this->assertSame('Removed 1 legacy Datasets link(s). Kept: Find data.', dkan_catalog_ui_remove_search_menu_link());
    $titles = array_column($this->mainMenu(), 0);
    sort($titles);
    $this->assertSame(['API', 'Datasets', 'Find data'], $titles);
    // Idempotent.
    $this->assertSame('Removed 0 legacy Datasets link(s). Kept: Find data.', dkan_catalog_ui_remove_search_menu_link());
  }

}
