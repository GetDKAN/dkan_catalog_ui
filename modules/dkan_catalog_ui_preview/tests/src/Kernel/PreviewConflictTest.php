<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\dkan_catalog_ui_preview\Hook\DataPreviewHooks;
use Drupal\dkan_catalog_ui_preview\Install\Requirements\DkanCatalogUiPreviewRequirements;
use Drupal\node\NodeInterface;
use Drupal\Tests\dkan_catalog_ui\Kernel\CatalogUiKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Coexistence with DKAN core's dkan_datastore_preview.
 */
#[Group('dkan_catalog_ui_preview')]
class PreviewConflictTest extends CatalogUiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
    'search_api',
    'search_api_db',
    'facets',
    'dkan_metastore_search',
    'dkan_catalog_ui_preview',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // DKAN 4.x-dev ships dkan_datastore_preview; 4.1.x releases do not.
    if (!$this->container->get('extension.list.module')->exists('dkan_datastore_preview')) {
      $this->markTestSkipped('This DKAN version does not include dkan_datastore_preview.');
    }
    $this->enableModules(['dkan_datastore_preview']);
    // Ships the search_result view mode and its display.
    $this->installSearchConfig();
    // Both install hooks would enable their field on the default display.
    EntityViewDisplay::load('node.data.default')
      ->setComponent(DataPreviewHooks::EXTRA_FIELD, ['weight' => 10])
      ->setComponent('data_preview', ['weight' => 100])
      ->save();
    // A display the catalog UI template does not own, showing core's field
    // and not this module's (extra fields are visible until a display
    // hides them).
    EntityViewDisplay::load('node.data.teaser')
      ->setComponent('data_preview', ['weight' => 0])
      ->removeComponent(DataPreviewHooks::EXTRA_FIELD)
      ->save();
  }

  /**
   * Build (but do not render) a node view.
   */
  protected function build(NodeInterface $node, string $viewMode): array {
    $viewBuilder = $this->container->get('entity_type.manager')->getViewBuilder('node');
    return $viewBuilder->build($viewBuilder->view($node, $viewMode));
  }

  /**
   * The full page keeps the catalog table and drops the core preview.
   */
  public function testFullPage(): void {
    $tabular = $this->createDataset('conflict-tabular');
    $build = $this->build($tabular, 'full');
    $this->assertArrayHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
    $this->assertArrayNotHasKey('data_preview', $build);

    $document = $this->createDataset('conflict-pdf', [
      'distribution' => [
        ['title' => 'Document', 'downloadURL' => 'http://example.com/doc.pdf', 'mediaType' => 'application/pdf'],
      ],
    ]);
    $build = $this->build($document, 'full');
    $this->assertArrayNotHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
    $this->assertArrayNotHasKey('data_preview', $build);
  }

  /**
   * Other view modes: the card hides both fields; a teaser keeps core's.
   */
  public function testOtherViewModes(): void {
    $node = $this->createDataset('conflict-modes');
    $build = $this->build($node, 'search_result');
    $this->assertArrayNotHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
    $this->assertArrayNotHasKey('data_preview', $build);

    $build = $this->build($node, 'teaser');
    $this->assertArrayHasKey('data_preview', $build);
    $this->assertArrayNotHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
  }

  /**
   * Install is refused and runtime warns while core's module is enabled.
   */
  public function testRequirements(): void {
    $install = DkanCatalogUiPreviewRequirements::getRequirements();
    $this->assertSame(RequirementSeverity::Error, $install['dkan_catalog_ui_preview_conflict']['severity']);
    $runtime = $this->container->get('module_handler')->invokeAll('runtime_requirements');
    $this->assertSame(RequirementSeverity::Warning, $runtime['dkan_catalog_ui_preview_conflict']['severity']);
  }

}
