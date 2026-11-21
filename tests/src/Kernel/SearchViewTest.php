<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\facets\Entity\Facet;
use Drupal\user\Entity\User;
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
    $this->installSearchConfig();
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
    $view->setRequest(Request::create('/search', 'GET', [
      'fulltext' => 'bike',
      'f' => ['dcu_theme:Transportation'],
      'sort_by' => 'title',
      'page' => '2',
    ]));
    $view->setExposedInput(['fulltext' => '']);
    $view->execute();

    $build = $view->render('page_1');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('class="dcu-facets__clear"', $html);
    $this->assertStringContainsString('Clear all filters', $html);
    // The link drops the facets and the page, keeping the term and sort.
    $this->assertMatchesRegularExpression('@href="[^"]*\?fulltext=bike&amp;sort_by=title"[^>]*class="dcu-facets__clear"@', $html);

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
   * A node access module's grants hide the card; the row bubbles the context.
   */
  public function testNodeGrantsHideCard(): void {
    $node = $this->createDataset('search-grants', ['title' => 'Restricted']);
    $this->index();
    // node_access_test grants view only to the node's author and to users
    // with "node test view"; anonymous (the metastore's author in tests)
    // must not own the node.
    $this->enableModules(['node_access_test']);
    $author = User::create(['name' => 'author']);
    $author->save();
    $node->setOwnerId($author->id())->save();

    $view = Views::getView('dkan_catalog_ui_search');
    $view->setDisplay('page_1');
    $view->setExposedInput(['fulltext' => '']);
    $view->execute();
    $this->assertCount(1, $view->result);
    $view->rowPlugin->preRender($view->result);
    $row = $view->rowPlugin->render($view->result[0]);
    $this->assertArrayNotHasKey('#theme', $row);
    $this->assertContains('user.node_grants:view', $row['#cache']['contexts']);
    $build = $view->render('page_1');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringNotContainsString('dcu-card__title', $html);
    $this->assertStringNotContainsString('Restricted', $html);
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

    // No match: the empty text is wrapped for theme styling.
    $view = Views::getView('dkan_catalog_ui_search');
    $view->setDisplay('page_1');
    $view->setExposedInput(['fulltext' => 'zebra']);
    $view->execute();
    $build = $view->render('page_1');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertMatchesRegularExpression('@<div class="view-empty">\s*No datasets match your search.@', $html);
  }

  /**
   * Shipped facets show ten values; the post-update skips site-set limits.
   */
  public function testFacetSoftLimit(): void {
    foreach (['dcu_theme', 'dcu_keyword', 'dcu_publisher', 'dcu_format'] as $id) {
      $this->assertSame(10, Facet::load($id)->getWidget()['config']['soft_limit'], $id);
    }
    $setLimit = function (string $id, int $limit): void {
      $facet = Facet::load($id);
      $widget = $facet->getWidget();
      $widget['config']['soft_limit'] = $limit;
      $facet->setWidget($widget['type'], $widget['config']);
      $facet->save();
    };
    $setLimit('dcu_theme', 0);
    $setLimit('dcu_keyword', 5);
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'php', 'dkan_catalog_ui.post_update');
    $this->assertSame('Soft limit 10 set on: dcu_theme.', dkan_catalog_ui_post_update_facet_soft_limit());
    $this->assertSame(10, Facet::load('dcu_theme')->getWidget()['config']['soft_limit']);
    $this->assertSame(5, Facet::load('dcu_keyword')->getWidget()['config']['soft_limit']);
  }

  /**
   * Facets survive a views results-cache hit on a later request.
   *
   * Core's `tag` cache plugin stores only the rows; facets read the Search
   * API result set, which only the search_api_* plugins restore. Regression
   * for the live failure where the second user class saw empty facets.
   * Search API's view presave swaps `none`/`tag`/`time` for
   * `search_api_none`, which never caches, so the second run also asserts
   * a results-cache hit: shipping anything but search_api_tag fails here.
   */
  public function testFacetsSurviveResultsCache(): void {
    $this->createDataset('search-one', ['title' => 'Bike Lanes', 'theme' => ['Transportation']]);
    $this->createDataset('search-two', ['title' => 'Bike Parks', 'theme' => ['Recreation']]);
    $this->index();

    $view = Views::getView('dkan_catalog_ui_search');
    $view->setDisplay('page_1');
    $cache = $view->getDisplay()->getOption('cache');
    $this->assertStringStartsWith('search_api_', $cache['type']);
    // Every shipped facet is permanently cacheable (the dcu_case processor
    // declares it), so the query can be cached at all.
    foreach (Facet::loadMultiple() as $facet) {
      $this->assertSame(Cache::PERMANENT, $facet->getCacheMaxAge(), $facet->id());
    }

    $helper = $this->container->get('search_api.query_helper');
    $counts = [];
    foreach ([0, 1] as $run) {
      $request = Request::create('/search', 'GET', ['fulltext' => 'Bike']);
      if ($run === 1) {
        // A later request by another user class: Search API's per-request
        // results and the facets manager's static state are gone, the
        // rendered output is not cached for this user, and the views results
        // cache primed by run 0 answers.
        foreach (array_keys($helper->getAllResults()) as $searchId) {
          $helper->removeResults($searchId);
        }
        $this->container->set('facets.manager', NULL);
        $this->container->get('cache.render')->deleteAll();
      }
      $view = Views::getView('dkan_catalog_ui_search');
      $view->setDisplay('page_1');
      $view->setRequest($request);
      $view->setExposedInput(['fulltext' => 'Bike']);
      $view->execute();
      if ($run === 1) {
        // Served from the views results cache, not by a new search.
        $this->assertSame(0, $view->execute_time);
      }
      $build = $view->render('page_1');
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $xpath = new \DOMXPath(Html::load($html));
      $counts[] = $xpath->query('//*[contains(@class, "dcu-facets__facet--dcu_theme")]//a')->length;
    }
    $this->assertSame([2, 2], $counts);
  }

}
