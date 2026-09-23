<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\dkan_catalog_ui_preview\DataPreviewBuilder;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceResult;
use Drupal\dkan_catalog_ui_preview\DictionaryHeadersInterface;
use Drupal\dkan_catalog_ui_preview\ImportStatusMessageInterface;
use Drupal\dkan_catalog_ui_preview\TableState;
use Drupal\dkan_catalog_ui_preview\TabularDistributionsInterface;
use Drupal\dkan_catalog_ui_preview\Toolbar\ToolbarBuilder;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\LikeEscaperTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the data table builder against an in-memory data source.
 */
#[Group('dkan_catalog_ui_preview')]
class DataPreviewBuilderTest extends UnitTestCase {

  use LikeEscaperTrait;

  /**
   * Calls recorded by the fake data source.
   *
   * @var array
   */
  public array $fetchCalls = [];

  /**
   * Schema fields of the fake data source.
   */
  public array $fields = [
    'name' => ['type' => 'text', 'description' => 'Name'],
    'age' => ['type' => 'int'],
  ];

  /**
   * Get a fake data source over an in-memory data set.
   */
  protected function getDataSource(int $rowCount = 30): DataSourceInterface {
    $rows = [];
    for ($i = 1; $i <= $rowCount; $i++) {
      $rows[] = (object) [
        'name' => sprintf('person_%02d', $i),
        'age' => 20 + ($i * 7) % 50,
      ];
    }
    $test = $this;

    return new class ($rows, $test) implements DataSourceInterface {

      public function __construct(protected array $rows, protected $test) {}

      /**
       * {@inheritdoc}
       */
      public function getSchema(string $resource_id): array {
        return $resource_id === 'missing__1' ? [] : ['fields' => $this->test->fields];
      }

      /**
       * {@inheritdoc}
       */
      public function fetchData(
        string $resource_id,
        int $limit,
        int $offset,
        ?string $sort_field,
        string $sort_direction,
        array $conditions = [],
        array $properties = [],
      ): DataSourceResult {
        $this->test->fetchCalls[] = [
          'limit' => $limit,
          'offset' => $offset,
          'sort_field' => $sort_field,
          'sort_direction' => $sort_direction,
          'conditions' => $conditions,
          'properties' => $properties,
        ];
        $rows = $this->rows;
        if ($sort_field) {
          usort($rows, fn ($a, $b) => $sort_direction === 'desc'
            ? $b->{$sort_field} <=> $a->{$sort_field}
            : $a->{$sort_field} <=> $b->{$sort_field});
        }
        return new DataSourceResult(array_slice($rows, $offset, $limit), count($this->rows));
      }

    };
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fetchCalls = [];

    // Unrouted URLs render as "uri?query" so hrefs can be asserted.
    $assembler = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $assembler->method('assemble')->willReturnCallback(function ($uri, $options) {
      $query = $options['query'] ?? [];
      return $uri . ($query ? '?' . urldecode(http_build_query($query)) : '');
    });
    // Routed URLs render as "route:name/params?query".
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturnCallback(function ($name, $parameters, $options) {
      $query = $options['query'] ?? [];
      return 'route:' . $name . '/' . implode('/', $parameters) . ($query ? '?' . urldecode(http_build_query($query)) : '');
    });
    $container = new ContainerBuilder();
    $container->set('url_generator', $generator);
    $container->set('unrouted_url_assembler', $assembler);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Get a builder whose current request is /node/1.
   */
  protected function getBuilder(): DataPreviewBuilder {
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/node/1'));
    $builder = new DataPreviewBuilder(
      $requestStack,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(TabularDistributionsInterface::class),
      $this->createMock(DataSourceInterface::class),
      $this->createMock(ImportStatusMessageInterface::class),
      $this->getConfigFactoryStub(['dkan_datastore.settings' => ['rows_limit' => 500]]),
      $this->createMock(LoggerChannelInterface::class),
      $this->likeEscaper(),
      $toolbar = new ToolbarBuilder($this->likeEscaper()),
      $this->dictionaryHeaders(),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    $toolbar->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Dictionary headers stub: titles for the columns given to ::withLabels().
   */
  protected array $dictionaryLabels = [];

  /**
   * Get a dictionary headers service returning the configured labels.
   */
  protected function dictionaryHeaders(): DictionaryHeadersInterface {
    $headers = $this->createMock(DictionaryHeadersInterface::class);
    $headers->method('labels')->willReturnCallback(fn () => $this->dictionaryLabels);
    $headers->method('cacheTags')->willReturn($this->dictionaryLabels ? ['node:99'] : []);
    return $headers;
  }

  /**
   * Parse a state the way the builder's callers do.
   */
  protected function state(array $query = []): TableState {
    return TableState::fromQuery($query, $this->fields);
  }

  /**
   * A resource without a table builds nothing.
   */
  public function testEmptySchemaReturnsNull(): void {
    $this->assertNull($this->getBuilder()->build($this->getDataSource(), 'missing__1', $this->state()));
    $this->assertSame([], $this->fetchCalls);
  }

  /**
   * Default state: first page of 25, no sort, uncacheable, focusable wrapper.
   */
  public function testBuildDefaults(): void {
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $this->state(), ['caption' => 'a.csv']);

    $this->assertSame('component', $build['#type']);
    $this->assertSame('dkan_catalog_ui_preview:data-table', $build['#component']);
    $this->assertSame(['max-age' => 0, 'tags' => []], $build['#cache']);
    $this->assertSame(['id' => 'dcu-table', 'tabindex' => '-1'], $build['#props']['attributes']);
    $table = $build['#slots']['table'];
    // The caption is a toolbar prop and the table's accessible name.
    $this->assertArrayNotHasKey('#caption', $table);
    $this->assertArrayNotHasKey('caption', $build['#slots']);
    $this->assertSame('dcu-table-caption', $table['#attributes']['aria-labelledby']);
    $this->assertCount(25, $table['#rows']);
    $this->assertSame('person_01', $table['#rows'][0][0]);
    // Numeric cells carry the number class; text cells are plain values.
    $this->assertSame(['data' => 27, 'class' => ['dcu-table__cell--number']], $table['#rows'][0][1]);
    $this->assertArrayNotHasKey('class', $table['#header'][0]);
    $this->assertSame(['dcu-table__cell--number'], $table['#header'][1]['class']);
    $toolbar = $build['#slots']['toolbar'];
    $this->assertSame('dkan_catalog_ui_preview:data-table-toolbar', $toolbar['#component']);
    $summary = $toolbar['#slots']['summary'];
    $this->assertSame('Displaying 1 - 25 of 30 rows', (string) $summary['#value']);
    $this->assertSame('polite', $summary['#attributes']['aria-live']);
    $this->assertSame('base:node/1', $toolbar['#props']['apply_url']);
    $this->assertSame('', $toolbar['#props']['fragment_url']);
    $this->assertSame('', $toolbar['#props']['open_panel']);
    $this->assertTrue($toolbar['#props']['show_panels']);
    $this->assertTrue($toolbar['#props']['has_summary']);
    $this->assertSame(['id' => 'dcu-table-caption', 'text' => 'a.csv'], $toolbar['#props']['caption']);
    $this->assertNull($toolbar['#props']['download']);
    $this->assertNull($toolbar['#props']['chooser']);
    $this->assertSame([], $toolbar['#props']['chips']['items']);
    $expectedCall = [
      'limit' => 25,
      'offset' => 0,
      'sort_field' => NULL,
      'sort_direction' => 'asc',
      'conditions' => [],
      'properties' => [],
    ];
    $this->assertSame([$expectedCall], $this->fetchCalls);
    $this->assertSame(['dkan_catalog_ui_preview/preview'], $build['#attached']['library']);
  }

  /**
   * Header links sort by the column; labels come from the description.
   */
  public function testHeaderSortLinks(): void {
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $this->state(['page' => '2']));

    $header = $build['#slots']['table']['#header'];
    $this->assertCount(2, $header);
    $this->assertSame('Name', $header[0]['data']['link']['#title']);
    $this->assertSame('age', $header[1]['data']['link']['#title']);

    $url = $header[0]['data']['link']['#url'];
    // Sorting starts over on page 1; links are path-based (no route needed).
    $this->assertSame(['sort' => 'name'], $url->getOption('query'));
    $this->assertFalse($url->isRouted());
    $this->assertSame('base:node/1', $url->getUri());
    $ariaLabel = (string) $header[0]['data']['link']['#attributes']['aria-label'];
    $this->assertSame('Sort by Name, ascending', $ariaLabel);
    $this->assertArrayNotHasKey('aria-sort', $header[0]);
  }

  /**
   * Without a caption the prop is NULL and the table has no accessible name.
   */
  public function testNoCaption(): void {
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $this->state());
    $this->assertNull($build['#slots']['toolbar']['#props']['caption']);
    $this->assertArrayNotHasKey('aria-labelledby', $build['#slots']['table']['#attributes']);
  }

  /**
   * Only number-category fields get the number class (not dates or text).
   */
  public function testNumericColumns(): void {
    $this->fields = [
      'name' => ['type' => 'text'],
      'count' => ['type' => 'int'],
      'score' => ['type' => 'float'],
      'day' => ['type' => 'text', 'mysql_type' => 'date'],
    ];
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $this->state());
    $header = $build['#slots']['table']['#header'];
    $classes = array_map(fn ($cell) => $cell['class'] ?? [], $header);
    $this->assertSame([[], ['dcu-table__cell--number'], ['dcu-table__cell--number'], []], $classes);
    $row = $build['#slots']['table']['#rows'][0];
    $this->assertSame('person_01', $row[0]);
    $this->assertSame(['dcu-table__cell--number'], $row[1]['class']);
    $this->assertSame(['dcu-table__cell--number'], $row[2]['class']);
    $this->assertSame('', $row[3]);

    // A text column typed as a number by the dictionary counts as numeric,
    // with or without a title; an untitled field keeps the schema label.
    $this->dictionaryLabels = [
      'name' => ['title' => '', 'description' => '', 'type' => 'integer'],
      'day' => ['title' => 'Day', 'description' => '', 'type' => 'date'],
    ];
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $this->state());
    $header = $build['#slots']['table']['#header'];
    $classes = array_map(fn ($cell) => $cell['class'] ?? [], $header);
    $this->assertSame([['dcu-table__cell--number'], ['dcu-table__cell--number'], ['dcu-table__cell--number'], []], $classes);
    $this->assertSame('name', $header[0]['data']['link']['#title']);
    $this->assertSame('Day', $header[3]['data']['link']['#title']);
  }

  /**
   * The active column is marked and its link toggles the direction.
   */
  public function testActiveSortColumn(): void {
    $state = $this->state(['sort' => 'age', 'direction' => 'desc']);
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $state);

    $header = $build['#slots']['table']['#header'];
    $this->assertSame('descending', $header[1]['aria-sort']);
    $this->assertSame(['is-active', 'dcu-table__cell--number'], $header[1]['class']);
    $this->assertSame('dkan_catalog_ui:icon', $header[1]['data']['indicator']['#component']);
    $this->assertSame('sort-desc', $header[1]['data']['indicator']['#props']['name']);
    $this->assertArrayNotHasKey('indicator', $header[0]['data']);

    $asc = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $this->state(['sort' => 'age']));
    $this->assertSame('sort-asc', $asc['#slots']['table']['#header'][1]['data']['indicator']['#props']['name']);
    $this->assertSame('ascending', $asc['#slots']['table']['#header'][1]['aria-sort']);
    $this->assertSame(['sort' => 'age'], $header[1]['data']['link']['#url']->getOption('query'));
    $this->assertSame(['sort' => 'name'], $header[0]['data']['link']['#url']->getOption('query'));
    $this->assertSame('desc', $this->fetchCalls[0]['sort_direction']);
    $this->assertSame('age', $this->fetchCalls[0]['sort_field']);
    // The first row is the maximum age.
    $this->assertSame(69, $build['#slots']['table']['#rows'][0][1]['data']);
  }

  /**
   * Column and condition state reaches the data source in query shape.
   */
  public function testColumnsAndConditions(): void {
    $state = $this->state([
      'columns' => 'age',
      'conditions' => [['property' => 'name', 'operator' => 'contains', 'value' => '50%']],
      'page_size' => '10',
    ]);
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $state);

    $this->assertCount(1, $build['#slots']['table']['#header']);
    $this->assertSame('age', $build['#slots']['table']['#header'][0]['data']['link']['#title']);
    $this->assertSame(27, $build['#slots']['table']['#rows'][0][0]['data']);
    $this->assertCount(1, $build['#slots']['table']['#rows'][0]);
    $this->assertSame(['age'], $this->fetchCalls[0]['properties']);
    $expectedCondition = ['property' => 'name', 'operator' => 'like', 'value' => '%50\%%'];
    $this->assertSame([$expectedCondition], $this->fetchCalls[0]['conditions']);
    // Links keep the columns, conditions and page size.
    $query = $build['#slots']['table']['#header'][0]['data']['link']['#url']->getOption('query');
    $this->assertSame(['conditions', 'page_size', 'sort', 'columns'], array_keys($query));
  }

  /**
   * An out-of-range page is clamped to the last page with a second fetch.
   */
  public function testPageClamping(): void {
    $build = $this->getBuilder()->build($this->getDataSource(5), 'abc__1', $this->state(['page' => '9']));

    $this->assertCount(2, $this->fetchCalls);
    $this->assertSame(200, $this->fetchCalls[0]['offset']);
    $this->assertSame(0, $this->fetchCalls[1]['offset']);
    $this->assertCount(5, $build['#slots']['table']['#rows']);
    $this->assertSame('Displaying 1 - 5 of 5 rows', (string) $build['#slots']['toolbar']['#slots']['summary']['#value']);
    $this->assertSame(['#markup' => ''], $build['#slots']['pager']);
  }

  /**
   * The pager uses a 1-based page parameter and the state's other params.
   */
  public function testPager(): void {
    $state = $this->state(['page' => '2', 'page_size' => '10', 'sort' => 'age']);
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $state);

    $pager = $build['#slots']['pager'];
    $this->assertSame('dkan_catalog_ui_preview_pager', $pager['#theme']);
    $this->assertSame(2, $pager['#current']);
    $this->assertSame(3, $pager['#total']);
    $this->assertSame(['previous' => FALSE, 'next' => FALSE], $pager['#ellipses']);
    $this->assertSame('base:node/1?page_size=10&sort=age', $pager['#items']['first']['href']);
    $this->assertSame('base:node/1?page_size=10&sort=age', $pager['#items']['previous']['href']);
    $this->assertSame([1, 2, 3], array_keys($pager['#items']['pages']));
    $this->assertSame('base:node/1?page=3&page_size=10&sort=age', $pager['#items']['pages'][3]['href']);
    $this->assertSame('base:node/1?page=3&page_size=10&sort=age', $pager['#items']['next']['href']);
    $this->assertSame('base:node/1?page=3&page_size=10&sort=age', $pager['#items']['last']['href']);
    $this->assertSame('Displaying 11 - 20 of 30 rows', (string) $build['#slots']['toolbar']['#slots']['summary']['#value']);

    // First page: no first/previous; a wide range gets an ellipsis.
    $build = $this->getBuilder()->build($this->getDataSource(200), 'abc__1', $this->state(['page_size' => '10']));
    $this->assertArrayNotHasKey('first', $build['#slots']['pager']['#items']);
    $this->assertSame([1, 2, 3, 4, 5], array_keys($build['#slots']['pager']['#items']['pages']));
    $this->assertSame(['previous' => FALSE, 'next' => TRUE], $build['#slots']['pager']['#ellipses']);
  }

  /**
   * Toolbar panels carry the untouched state; chips link to the rest.
   */
  public function testToolbarProps(): void {
    $state = $this->state([
      'page' => '3',
      'page_size' => '10',
      'sort' => 'age',
      'columns' => 'age',
      'conditions' => [['property' => 'name', 'operator' => 'in', 'value' => 'a,b']],
    ]);
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $state, [
      'max_page_size' => 30,
      'panel' => 'filters',
      'tables' => [
        [
          'resource_id' => 'abc__1',
          'label' => 'a.csv',
          'title' => 'A',
          'download_url' => 'https://example.com/a.csv',
          'distribution_uuid' => 'd1',
        ],
        [
          'resource_id' => 'def__1',
          'label' => 'b.csv',
          'title' => 'B',
          'download_url' => 'javascript:alert(1)',
          'distribution_uuid' => 'd2',
        ],
      ],
    ]);
    $props = $build['#slots']['toolbar']['#props'];

    $this->assertSame('filters', $props['open_panel']);
    $this->assertSame('https://example.com/a.csv', $props['download']['url']);
    $this->assertSame('Download full dataset (CSV)', $props['download']['label']);
    $this->assertSame([
      ['value' => '0', 'label' => 'A', 'selected' => TRUE],
      ['value' => '1', 'label' => 'B', 'selected' => FALSE],
    ], $props['chooser']['tables']);

    // Filters: page and conditions are not carried; the row keeps its
    // operator list for the column type; one blank row follows.
    $filters = $props['filters'];
    $this->assertSame(['page_size' => '10', 'sort' => 'age', 'columns' => 'age'], $filters['hidden']);
    $this->assertCount(1, $filters['rows']);
    $this->assertSame('in', $filters['rows'][0]['operator']);
    $this->assertSame(['=', 'contains', 'starts with', '<>', 'in'], array_column($filters['rows'][0]['operators'], 'value'));
    $this->assertSame(1, $filters['blank_index']);
    $this->assertSame(['string', 'number', 'date', 'other'], array_keys($filters['operator_sets']));
    $this->assertSame(['=', '<>', '>', '<'], array_column($filters['operator_sets']['number'], 'value'));
    $this->assertSame([
      ['value' => 'name', 'label' => 'Name', 'category' => 'string'],
      ['value' => 'age', 'label' => 'age', 'category' => 'number'],
    ], $filters['columns']);

    // Columns: visible first, then hidden; page is kept.
    $columns = $props['columns'];
    $this->assertSame(['age', 'name'], array_column($columns['items'], 'name'));
    $this->assertSame([TRUE, FALSE], array_column($columns['items'], 'visible'));
    $this->assertFalse($columns['items'][0]['can_move_up']);
    $this->assertFalse($columns['items'][0]['can_move_down']);
    $this->assertSame('3', $columns['hidden']['page']);
    $this->assertSame(1, $columns['hidden_count']);
    $this->assertSame('base:node/1?conditions[0][property]=name&conditions[0][operator]=in&conditions[0][value]=a,b&page=3&page_size=10&sort=age&columns=age', $columns['cancel_url']);

    // Display: page sizes capped by the row limit; page not carried.
    $this->assertSame([10, 25], $props['display']['page_sizes']);
    $this->assertSame(10, $props['display']['current']);
    $this->assertArrayNotHasKey('page', $props['display']['hidden']);

    // Chips: one per condition plus the hidden-columns chip, then clear all.
    $chips = $props['chips']['items'];
    $this->assertCount(2, $chips);
    $this->assertSame('Name Or a,b', $chips[0]['label']);
    $this->assertSame('base:node/1?page_size=10&sort=age&columns=age', $chips[0]['url']);
    $this->assertSame('1 column hidden', $chips[1]['label']);
    $this->assertSame('base:node/1?conditions[0][property]=name&conditions[0][operator]=in&conditions[0][value]=a,b&page=3&page_size=10&sort=age', $chips[1]['url']);
    $this->assertSame('base:node/1?page_size=10&sort=age', $props['chips']['clear_all_url']);

    // Share: filtered download on the datastore route, copy link canonical.
    $expectedDownload = 'route:dkan_datastore.1.query.id.download/abc__1'
      . '?conditions[0][property]=name&conditions[0][operator]=in&conditions[0][value][0]=a&conditions[0][value][1]=b'
      . '&sorts[0][property]=age&sorts[0][order]=asc&properties[0]=age&format=csv';
    $this->assertSame($expectedDownload, $props['share']['download_url']);
    $this->assertSame('base:node/1?conditions[0][property]=name&conditions[0][operator]=in&conditions[0][value]=a,b&page=3&page_size=10&sort=age&columns=age', $props['share']['copy_url']);
  }

  /**
   * Dictionary titles replace headers and toolbar labels; tags bubble.
   */
  public function testDictionaryLabels(): void {
    $this->dictionaryLabels = ['age' => ['title' => 'Age (years)', 'description' => 'Age at survey']];
    $state = $this->state(['conditions' => [['property' => 'age', 'operator' => '=', 'value' => '30']]]);
    $build = $this->getBuilder()->build($this->getDataSource(), 'abc__1', $state);

    $header = $build['#slots']['table']['#header'];
    $this->assertSame('Name', $header[0]['data']['link']['#title']);
    $this->assertSame('Age (years)', $header[1]['data']['link']['#title']);
    $this->assertSame('Age at survey', $header[1]['data']['link']['#attributes']['title']);
    $this->assertSame('age', $header[1]['data-column']);
    $this->assertSame(['node:99'], $build['#cache']['tags']);

    $props = $build['#slots']['toolbar']['#props'];
    $this->assertSame('Age (years)', $props['filters']['columns'][1]['label']);
    $this->assertSame('Age (years)', $props['columns']['items'][1]['label']);
    $this->assertSame('Age (years) Is 30', $props['chips']['items'][0]['label']);
  }

  /**
   * No matching rows.
   */
  public function testEmptyResult(): void {
    $build = $this->getBuilder()->build($this->getDataSource(0), 'abc__1', $this->state());
    $this->assertSame('No rows match.', (string) $build['#slots']['toolbar']['#slots']['summary']['#value']);
    $this->assertSame([], $build['#slots']['table']['#rows']);
    $this->assertSame(['#markup' => ''], $build['#slots']['pager']);
  }

}
