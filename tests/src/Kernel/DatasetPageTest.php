<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\dkan_catalog_ui\Hook\EntityHooks;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the dataset page sections and template.
 */
#[Group('dkan_catalog_ui')]
class DatasetPageTest extends CatalogUiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // hook_install() ran before the display config existed.
    EntityViewDisplay::load('node.data.default')
      ->setComponent(EntityHooks::FIELD_HEADER, ['weight' => 0])
      ->setComponent(EntityHooks::FIELD_OVERVIEW, ['weight' => 20])
      ->save();
  }

  /**
   * Build the full node view.
   */
  protected function buildNodeView(NodeInterface $node): array {
    $viewBuilder = $this->container->get('entity_type.manager')->getViewBuilder('node');
    $build = $viewBuilder->view($node, 'full');
    return $viewBuilder->build($build);
  }

  /**
   * Header and overview sections are built from dereferenced metadata.
   */
  public function testSections(): void {
    $node = $this->createDataset('page-test');
    $build = $this->buildNodeView($node);

    $header = $build[EntityHooks::FIELD_HEADER];
    $this->assertSame('dkan_catalog_ui:dataset-header', $header['#component']);
    $this->assertSame('January 15, 2020', $header['#props']['updated']);
    $this->assertSame('2020-01-15', $header['#props']['updated_iso']);
    $this->assertSame('A <strong>test</strong> description.', $header['#slots']['description']['#markup']);

    $overview = $build[EntityHooks::FIELD_OVERVIEW];
    $resources = $overview['resources']['#props']['resources'];
    $this->assertCount(2, $resources);
    $this->assertSame('Tabular', $resources[0]['title']);
    $this->assertSame('CSV', $resources[0]['format']);
    $this->assertSame('http://example.com/data.csv', $resources[0]['url']);
    $this->assertSame('', $resources[0]['rows']);
    $this->assertSame('PDF', $resources[1]['format']);
    $this->assertContains('node:' . $node->id(), $overview['#cache']['tags']);

    $rows = [];
    foreach ($overview['metadata']['#props']['rows'] as $row) {
      $rows[$row['label']] = $row;
    }
    $this->assertSame('January 15, 2020', $rows['Last Update']['value']);
    $this->assertSame('June 1, 2019', $rows['Release Date']['value']);
    $this->assertSame('Test Publisher', $rows['Organization']['value']);
    $this->assertSame('Jane Doe', $rows['Contact']['value']);
    $this->assertSame('mailto:jane@example.com', $rows['Contact']['href']);
    $this->assertSame('bikes, streets', $rows['Tags']['value']);
    $this->assertSame('http://opendatacommons.org/licenses/by/1.0/', $rows['License']['href']);
  }

  /**
   * The full template renders tabs for the non-empty sections only.
   */
  public function testTemplate(): void {
    $node = $this->createDataset('template-test');
    $build = $this->buildNodeView($node);
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('class="dcu-dataset', $html);
    $this->assertStringContainsString('href="#overview"', $html);
    $this->assertStringContainsString('<section class="dcu-tabs__panel" id="overview"', $html);
    $this->assertStringContainsString('Additional Information', $html);
    $this->assertStringContainsString('Test Publisher', $html);
    $this->assertStringContainsString('href="#api"', $html);
    $this->assertStringContainsString('class="dcu-api-docs"', $html);
    // No preview module and dictionaries disabled: no such tabs.
    $this->assertStringNotContainsString('href="#data-table"', $html);
    $this->assertStringNotContainsString('href="#data-dictionary"', $html);
    // Description markup is filtered, not escaped.
    $this->assertStringContainsString('A <strong>test</strong> description.', $html);
  }

  /**
   * The API section links the OpenAPI document and endpoints.
   */
  public function testApiSection(): void {
    $node = $this->createDataset('api-test');
    $build = $this->buildNodeView($node);
    $api = $build[EntityHooks::FIELD_API];
    $this->assertSame('dkan_catalog_ui:api-docs', $api['#component']);
    $this->assertStringEndsWith('/api/1/metastore/schemas/dataset/items/api-test/docs', $api['#props']['docs_url']);
    $endpoints = $api['#props']['endpoints'];
    $this->assertNotEmpty($endpoints);
    $methods = array_unique(array_column($endpoints, 'method'));
    $this->assertContains('GET', $methods);
    $this->assertContains('POST', $methods);
    $paths = array_column($endpoints, 'path');
    $this->assertContains('/api/1/metastore/schemas/dataset/items/api-test', $paths);
    $this->assertContains('/api/1/datastore/sql', $paths);
    // A resolved $ref parameter with its example, and an example URL built
    // from it.
    $query = array_values(array_filter($endpoints, fn($e) => $e['method'] === 'GET' && str_contains($e['path'], '{distributionId}')));
    $this->assertNotEmpty($query);
    $names = array_column($query[0]['parameters'], 'name');
    $this->assertContains('distributionId', $names);
    $this->assertContains('limit', $names);
    $this->assertStringStartsWith('/api/1/datastore/query/', $query[0]['example_url']);
    $this->assertStringNotContainsString('{', $query[0]['example_url']);
  }

  /**
   * Dictionaries disabled: no section at all.
   */
  public function testDataDictionaryNone(): void {
    $node = $this->createDataset('dict-none');
    $build = $this->buildNodeView($node);
    $this->assertArrayNotHasKey(EntityHooks::FIELD_DATA_DICTIONARY, $build);
  }

  /**
   * Sitewide mode renders the one dictionary once for the dataset.
   */
  public function testDataDictionarySitewide(): void {
    $this->setDictionaryMode('sitewide', 'dict-1');
    $this->postDictionary('dict-1', 'Site Dictionary');

    $node = $this->createDataset('dict-sitewide');
    $build = $this->buildNodeView($node);
    $section = $build[EntityHooks::FIELD_DATA_DICTIONARY];
    $this->assertArrayNotHasKey('empty', $section);
    $this->assertArrayNotHasKey(1, $section);
    $this->assertSame('dkan_catalog_ui:data-dictionary', $section[0]['#component']);
    $this->assertSame('Site Dictionary', $section[0]['#props']['title']);
    $this->assertTrue($section[0]['#props']['show_title']);
    $fields = $section[0]['#props']['fields'];
    $this->assertCount(2, $fields);
    $this->assertSame([
      'name' => 'a',
      'title' => 'Field A',
      'type' => 'string',
      'format' => '',
      'description' => 'The A',
    ], $fields[0]);
  }

  /**
   * The heading and Format column render only when they carry information.
   */
  public function testDataDictionaryHeadingAndFormat(): void {
    $this->setDictionaryMode('sitewide', 'dict-same');
    $this->postDictionary('dict-same', 'Catalog Test Dataset ');

    $node = $this->createDataset('dict-heading');
    $section = $this->buildNodeView($node)[EntityHooks::FIELD_DATA_DICTIONARY];
    $this->assertFalse($section[0]['#props']['show_title']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($section);
    $this->assertStringNotContainsString('<h3', $html);
    $this->assertStringNotContainsString('Format', $html);
    $this->assertSame(4, substr_count($html, '<th scope="col">'));

    // With a format on one field the column and heading return (a rendered
    // array is spent, so build a fresh one).
    $section = $this->buildNodeView($node)[EntityHooks::FIELD_DATA_DICTIONARY];
    $section[0]['#props']['show_title'] = TRUE;
    $section[0]['#props']['fields'][1]['format'] = 'default';
    $html = (string) $this->container->get('renderer')->renderInIsolation($section);
    $this->assertStringContainsString('<h3 class="dcu-data-dictionary__heading">Catalog Test Dataset </h3>', $html);
    $this->assertSame(5, substr_count($html, '<th scope="col">'));
    $this->assertStringContainsString('<td>default</td>', $html);
  }

  /**
   * Reference mode with no referenced dictionary shows the empty message.
   */
  public function testDataDictionaryReferenceEmpty(): void {
    $this->setDictionaryMode('reference');
    $node = $this->createDataset('dict-reference');
    $build = $this->buildNodeView($node);
    $section = $build[EntityHooks::FIELD_DATA_DICTIONARY];
    $this->assertArrayHasKey('empty', $section);
    $this->assertArrayNotHasKey(0, $section);
  }

  /**
   * Set the data dictionary mode.
   *
   * The discovery service reads its config object at construction, so the
   * container is rebuilt to pick up the change.
   */
  protected function setDictionaryMode(string $mode, ?string $sitewide = NULL): void {
    $config = $this->config('dkan_metastore.settings')->set('data_dictionary_mode', $mode);
    if ($sitewide !== NULL) {
      $config->set('data_dictionary_sitewide', $sitewide);
    }
    $config->save();
    $this->container = $this->container->get('kernel')->rebuildContainer();
  }

  /**
   * Post a data dictionary through the metastore.
   */
  protected function postDictionary(string $identifier, string $title): void {
    /** @var \Drupal\dkan_metastore\MetastoreService $metastore */
    $metastore = $this->container->get('dkan.metastore.service');
    $dictionary = $metastore->getValidMetadataFactory()->get(json_encode([
      'identifier' => $identifier,
      'data' => [
        'title' => $title,
        'fields' => [
          ['name' => 'a', 'title' => 'Field A', 'type' => 'string', 'description' => 'The A'],
          ['name' => 'b', 'type' => 'integer'],
        ],
      ],
    ]), 'data-dictionary');
    $metastore->post('data-dictionary', $dictionary);
  }

  /**
   * Distribution nodes get none of the dataset sections.
   */
  public function testNonDatasetNode(): void {
    $this->createDataset('nondataset-test');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['field_data_type' => 'distribution']);
    $build = $this->buildNodeView(reset($nodes));
    $this->assertArrayNotHasKey(EntityHooks::FIELD_HEADER, $build);
    $this->assertArrayNotHasKey(EntityHooks::FIELD_OVERVIEW, $build);
  }

}
