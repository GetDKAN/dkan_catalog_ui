<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatch;
use Drupal\dkan_catalog_ui\Breadcrumb\DatasetBreadcrumbBuilder;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the dataset breadcrumb.
 */
#[Group('dkan_catalog_ui')]
class DatasetBreadcrumbTest extends CatalogUiKernelTestBase {

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
  }

  /**
   * Merge the base class modules with this test's.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    static::$modules = array_values(array_unique(array_merge(parent::$modules, static::$modules)));
  }

  /**
   * Route match for a node's canonical page.
   */
  protected function routeMatch(NodeInterface $node): RouteMatch {
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.node.canonical');
    return new RouteMatch('entity.node.canonical', $route, ['node' => $node], ['node' => $node->id()]);
  }

  /**
   * Dataset pages get Home > Datasets; other nodes are untouched.
   */
  public function testBreadcrumb(): void {
    $node = $this->createDataset('crumb-test', ['title' => 'Crumb Dataset']);
    $breadcrumb = $this->container->get('breadcrumb')->build($this->routeMatch($node));

    $links = $breadcrumb->getLinks();
    $this->assertSame(['Home', 'Datasets'], array_map(fn ($link) => (string) $link->getText(), $links));
    $this->assertSame('/', $links[0]->getUrl()->toString());
    $this->assertSame('/search', $links[1]->getUrl()->toString());
    $this->assertContains('route', $breadcrumb->getCacheContexts());
    $this->assertContains('node:' . $node->id(), $breadcrumb->getCacheTags());

    // A distribution node is not a dataset page.
    $distributions = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['field_data_type' => 'distribution']);
    $distribution = reset($distributions);
    $builder = new DatasetBreadcrumbBuilder($this->container->get('string_translation'));
    $metadata = new CacheableMetadata();
    $this->assertFalse($builder->applies($this->routeMatch($distribution), $metadata));
    $this->assertContains('node:' . $distribution->id(), $metadata->getCacheTags());
    $this->assertContains('route', $metadata->getCacheContexts());
    $metadata = new CacheableMetadata();
    $this->assertTrue($builder->applies($this->routeMatch($node), $metadata));
    $this->assertContains('node:' . $node->id(), $metadata->getCacheTags());
  }

}
