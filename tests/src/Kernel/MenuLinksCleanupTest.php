<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * The legacy-link cleanup is a no-op without menu_link_content.
 */
#[Group('dkan_catalog_ui')]
class MenuLinksCleanupTest extends CatalogUiKernelTestBase {

  /**
   * Without the module there is nothing to query.
   */
  public function testNoOpWithoutModule(): void {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('menu_link_content'));
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'post_update.php');
    $this->assertSame('menu_link_content is not installed; nothing to do.', dkan_catalog_ui_remove_search_menu_link());
  }

}
