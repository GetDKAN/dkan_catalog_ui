<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the dataset search view.
 */
#[Group('dkan_catalog_ui')]
class SearchViewTest extends CatalogUiKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');
    \Drupal::state()->set('search_api_use_tracking_batch', FALSE);
    $this->installConfig(['search_api', 'dkan_metastore_search', 'dkan_catalog_ui']);
    // hook_install() does not run in kernel tests.
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'install');
    dkan_catalog_ui_ensure_format_index_field();
  }

  /**
   * Merge the base class modules with this test's.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    static::$modules = array_values(array_unique(array_merge(parent::$modules, static::$modules)));
  }

  /**
   * Index the posted datasets.
   */
  protected function index(): void {
    $index = Index::load('dkan');
    $index->indexItems();
  }

  /**
   * Results render as dataset cards with facets and a summary.
   */
  public function testSearchPage(): void {
    $this->createDataset('search-one', ['title' => 'Bike Lanes', 'theme' => ['Transportation']]);
    $this->createDataset('search-two', [
      'title' => 'Parks',
      'theme' => ['Recreation'],
      'keyword' => ['parks', 'k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7', 'k8'],
    ]);
    $this->index();

    $view = Views::getView('dkan_catalog_ui_search');
    $view->setDisplay('page_1');
    $view->setExposedInput(['fulltext' => '']);
    $view->execute();
    $this->assertCount(2, $view->result);

    $build = $view->render('page_1');
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('class="dcu-search', $html);
    $this->assertStringContainsString('dcu-card__title', $html);
    $this->assertStringContainsString('>Bike Lanes<', $html);
    $this->assertStringContainsString('>Parks<', $html);
    $this->assertStringContainsString('href="/dataset/search-one"', $html);
    $this->assertStringContainsString('#data-table', $html);
    $this->assertStringContainsString('Showing 1 - 2 of 2 datasets', $html);
    $this->assertStringContainsString('name="fulltext"', $html);
    $this->assertStringContainsString('name="sort_by"', $html);
    // Facet blocks rendered inside the view with counts.
    $this->assertStringContainsString('dcu-facets__facet--dcu_theme', $html);
    $this->assertStringContainsString('Transportation', $html);
    $this->assertStringContainsString('id="dcu-facets"', $html);
    $this->assertStringContainsString('<a class="dcu-search__filter-link" href="#dcu-facets">Filter results</a>', $html);
    // Results precede the facets in the DOM.
    $this->assertLessThan(strpos($html, 'class="dcu-search__sidebar"'), strpos($html, 'class="dcu-search__main"'));
    $this->assertStringContainsString('dcu-facets__facet--dcu_format', $html);
    // Nine or more items mark the facet as long; two themes do not.
    $this->assertStringContainsString('dcu-facets__facet--dcu_keyword dcu-facets__facet--long', $html);
    $this->assertStringNotContainsString('dcu-facets__facet--dcu_theme dcu-facets__facet--long', $html);
    // No active facet: no clear link.
    $this->assertStringNotContainsString('Clear all filters', $html);
  }

  /**
   * An active facet in the request adds the clear link.
   */
  public function testClearLink(): void {
    $this->createDataset('search-one', ['title' => 'Bike Lanes', 'theme' => ['Transportation']]);
    $this->index();

    $view = Views::getView('dkan_catalog_ui_search');
    $view->setDisplay('page_1');
    $view->setRequest(Request::create('/search', 'GET', ['f' => ['dcu_theme:Transportation']]));
    $view->setExposedInput(['fulltext' => '']);
    $view->execute();

    $build = $view->render('page_1');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('class="dcu-facets__clear"', $html);
    $this->assertStringContainsString('Clear all filters', $html);

    // Empty values are not active facets.
    foreach ([['f' => ''], ['f' => ['']]] as $query) {
      $view = Views::getView('dkan_catalog_ui_search');
      $view->setDisplay('page_1');
      $view->setRequest(Request::create('/search', 'GET', $query));
      $view->setExposedInput(['fulltext' => '']);
      $view->execute();
      $build = $view->render('page_1');
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $this->assertStringNotContainsString('Clear all filters', $html);
    }
  }

  /**
   * Fulltext input filters the results.
   */
  public function testFulltext(): void {
    $this->createDataset('search-bikes', ['title' => 'Bike Lanes']);
    $this->createDataset('search-parks', ['title' => 'Parks']);
    $this->index();

    $view = Views::getView('dkan_catalog_ui_search');
    $view->setDisplay('page_1');
    $view->setExposedInput(['fulltext' => 'Parks']);
    $view->execute();
    $this->assertCount(1, $view->result);
    $this->assertSame('dkan_dataset/search-parks', $view->result[0]->_item->getId());
  }

}
