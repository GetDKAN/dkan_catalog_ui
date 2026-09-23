<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Kernel;

use Drupal\Core\Url;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceResult;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the database data source and render element against a real table.
 */
#[Group('dkan_catalog_ui_preview')]
class PreviewIntegrationTest extends KernelTestBase {

  use DatastoreFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'user',
    'dkan_common',
    'dkan_datastore',
    'dkan_metastore',
    'dkan_catalog_ui',
    'dkan_catalog_ui_preview',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('resource_mapping');
  }

  /**
   * Push a request with the given query.
   */
  protected function pushRequest(string $uri, array $query = []): void {
    $request = Request::create($uri, 'GET', $query);
    $request->setSession($this->container->get('request_stack')->getCurrentRequest()->getSession());
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Schema, sorting and paging.
   */
  public function testDatabaseDataSource(): void {
    $resourceId = $this->importFixture();
    $dataSource = $this->container->get('dkan_catalog_ui.preview.data_source.database');

    $schema = $dataSource->getSchema($resourceId);
    $this->assertSame(['name', 'age', 'city'], array_keys($schema['fields']));
    $this->assertSame('text', $schema['fields']['name']['type']);

    // Descending sort by age returns the maximum age first.
    $result = $dataSource->fetchData($resourceId, 10, 0, 'age', 'desc');
    $this->assertCount(10, $result->rows);
    $this->assertSame(30, $result->totalCount);
    $this->assertSame(69, (int) $result->rows[0]->age);

    // Offset paging with ascending name sort.
    $result = $dataSource->fetchData($resourceId, 10, 10, 'name', 'asc');
    $this->assertSame('person_11', $result->rows[0]->name);

    // Unknown resources yield empty schema and results, not exceptions.
    $this->assertSame([], $dataSource->getSchema('missing__123'));
    $this->assertSame(0, $dataSource->fetchData('missing__123', 10, 0, NULL, 'asc')->totalCount);
  }

  /**
   * Conditions: escaped like, in, unsupported operators dropped, properties.
   */
  public function testConditions(): void {
    $resourceId = $this->importFixture();
    $dataSource = $this->container->get('dkan_catalog_ui.preview.data_source.database');

    // Escaped: "_1" matches person_10..person_19 only (10 rows). Unescaped
    // "_" would also match person_01 and person_21.
    $like = [['property' => 'name', 'operator' => 'like', 'value' => '%\_1%']];
    $this->assertSame(10, $dataSource->fetchData($resourceId, 100, 0, NULL, 'asc', $like)->totalCount);
    $unescaped = [['property' => 'name', 'operator' => 'like', 'value' => '%_1%']];
    $this->assertSame(12, $dataSource->fetchData($resourceId, 100, 0, NULL, 'asc', $unescaped)->totalCount);

    $in = [
      ['property' => 'name', 'operator' => 'in', 'value' => ['person_01', 'person_02', 'nobody']],
    ];
    $result = $dataSource->fetchData($resourceId, 100, 0, 'name', 'asc', $in, ['name']);
    $this->assertSame(2, $result->totalCount);
    $this->assertSame(['name'], array_keys((array) $result->rows[0]));

    // Unsupported operators are dropped rather than sent.
    $over60 = ['property' => 'age', 'operator' => '>', 'value' => '60'];
    $dropped = [['property' => 'name', 'operator' => 'contains', 'value' => '_1'], $over60];
    $this->assertSame(
      $dataSource->fetchData($resourceId, 100, 0, NULL, 'asc', [$over60])->totalCount,
      $dataSource->fetchData($resourceId, 100, 0, NULL, 'asc', $dropped)->totalCount
    );
  }

  /**
   * Numeric cells carry the number class on the rendered th and td.
   */
  public function testNumericCells(): void {
    $this->pushRequest('/preview-test');
    $rows = [(object) ['name' => 'a', 'count' => 3]];
    $source = new class ($rows) implements DataSourceInterface {

      public function __construct(protected array $rows) {}

      /**
       * {@inheritdoc}
       */
      public function getSchema(string $resource_id): array {
        return ['fields' => ['name' => ['type' => 'text'], 'count' => ['type' => 'int']]];
      }

      /**
       * {@inheritdoc}
       */
      public function fetchData(string $resource_id, int $limit, int $offset, ?string $sort_field, string $sort_direction, array $conditions = [], array $properties = []): DataSourceResult {
        return new DataSourceResult($this->rows, count($this->rows));
      }

    };
    $build = [
      '#type' => 'dkan_catalog_ui_preview',
      '#resource_id' => 'stub__1',
      '#data_source_instance' => $source,
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    // Drupal's table preprocess puts the class on the final th and td.
    $this->assertStringContainsString('<th data-column="count" class="dcu-table__cell--number">', $html);
    $this->assertStringContainsString('<th data-column="name"><a', $html);
    $this->assertMatchesRegularExpression('/<td class="dcu-table__cell--number">3<\/td>/', $html);
    $this->assertMatchesRegularExpression('/<td>a<\/td>/', $html);
  }

  /**
   * The element renders a table from the request state.
   */
  public function testElementRendersTable(): void {
    $resourceId = $this->importFixture();
    $this->pushRequest('/preview-test', ['sort' => 'age', 'direction' => 'desc', 'page_size' => '10']);

    $build = [
      '#type' => 'dkan_catalog_ui_preview',
      '#resource_id' => $resourceId,
      '#caption' => 'preview_sample.csv',
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('<table', $html);
    $this->assertStringContainsString('<p class="dcu-table__caption" id="dcu-table-caption"><span class="dcu-table__file-label">Data file</span> preview_sample.csv</p>', $html);
    $this->assertStringNotContainsString('<caption>', $html);
    $this->assertStringContainsString('aria-labelledby="dcu-table-caption"', $html);
    $this->assertStringContainsString('Rows 1–10 of 30', $html);
    $this->assertStringContainsString('aria-sort="descending"', $html);
    $this->assertStringContainsString('href="/preview-test?page_size=10&amp;sort=name"', $html);
    $this->assertStringContainsString('aria-label="Sort by name, ascending"', $html);
    // Without an apply route the panels are omitted; the status row and
    // its summary stay.
    $this->assertStringNotContainsString('id="dcu-table-filters"', $html);
    $this->assertStringNotContainsString('dcu-table__tools', $html);
    $this->assertStringContainsString('<div class="dcu-table__status"', $html);
    $this->assertStringContainsString('dcu-table__summary', $html);

    // A rendered array is spent; build a fresh one with an apply route.
    $build = [
      '#type' => 'dkan_catalog_ui_preview',
      '#resource_id' => $resourceId,
      '#apply_url' => Url::fromUri('base:preview-apply'),
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('action="/preview-apply"', $html);
    $this->assertStringContainsString('id="dcu-table-filters"', $html);
    $this->assertStringContainsString('name="conditions[0][property]"', $html);
    $this->assertStringContainsString('name="columns[]" value="name" checked', $html);
    $this->assertStringContainsString('<option value="10" selected>', $html);
    $this->assertStringContainsString('class="pager"', $html);
    $this->assertStringContainsString('href="/preview-test?page=2&amp;page_size=10&amp;sort=age&amp;direction=desc"', $html);
    $this->assertMatchesRegularExpression('/<tbody[^>]*>.*?<td>69<\/td>/s', $html);
  }

  /**
   * An unknown resource renders the generic status message.
   */
  public function testUnavailableResourceRendersMessage(): void {
    $this->pushRequest('/preview-test');

    $build = [
      '#type' => 'dkan_catalog_ui_preview',
      '#resource_id' => 'missing__123',
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('Data preview is not yet available.', $html);
    $this->assertStringNotContainsString('still being processed', $html);
    $this->assertStringContainsString('dcu-table__message', $html);
    $this->assertStringNotContainsString('<table', $html);
  }

}
