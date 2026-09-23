<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Unit;

use Drupal\dkan_catalog_ui_preview\Condition;
use Drupal\dkan_catalog_ui_preview\TableState;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\LikeEscaperTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests parsing, validation and canonical serialization of table state.
 */
#[Group('dkan_catalog_ui_preview')]
class TableStateTest extends UnitTestCase {

  use LikeEscaperTrait;

  /**
   * Schema fields used by every test.
   */
  protected array $fields = [
    'name' => ['type' => 'text'],
    'age' => ['type' => 'int'],
    'city' => ['type' => 'text'],
  ];

  /**
   * An empty query is the default state and serializes to nothing.
   */
  public function testDefaults(): void {
    $state = TableState::fromQuery([], $this->fields);
    $this->assertSame(0, $state->table);
    $this->assertSame(1, $state->page);
    $this->assertSame(25, $state->pageSize);
    $this->assertNull($state->sort);
    $this->assertSame('asc', $state->direction);
    $this->assertSame([], $state->conditions);
    $this->assertSame([], $state->columns);
    $this->assertSame([], $state->toQuery());
    $this->assertSame(['format' => 'csv'], $state->toDatastoreQuery($this->likeEscaper()));
    $this->assertSame(['name', 'age', 'city'], $state->visibleColumns($this->fields));
    $this->assertSame(0, $state->offset());
  }

  /**
   * A full query round-trips in canonical order with defaults omitted.
   */
  public function testRoundTrip(): void {
    $query = [
      'columns' => 'city,name',
      'direction' => 'desc',
      'sort' => 'name',
      'page_size' => '50',
      'page' => '3',
      'conditions' => [
        ['property' => 'name', 'operator' => 'contains', 'value' => 'an'],
        ['property' => 'age', 'operator' => '>', 'value' => '30'],
      ],
      'table' => '1',
      'panel' => 'filters',
      'foo' => 'bar',
    ];
    $state = TableState::fromQuery($query, $this->fields, 2);
    $this->assertSame([
      'table' => 1,
      'conditions' => [
        ['property' => 'name', 'operator' => 'contains', 'value' => 'an'],
        ['property' => 'age', 'operator' => '>', 'value' => '30'],
      ],
      'page' => 3,
      'page_size' => 50,
      'sort' => 'name',
      'direction' => 'desc',
      'columns' => 'city,name',
    ], $state->toQuery());
    $this->assertSame(100, $state->offset());
    $this->assertSame(['city', 'name'], $state->visibleColumns($this->fields));
    $this->assertTrue($state->equals(TableState::fromQuery($state->toQuery(), $this->fields, 2)));

    $this->assertSame([
      'conditions' => [
        ['property' => 'name', 'operator' => 'like', 'value' => '%an%'],
        ['property' => 'age', 'operator' => '>', 'value' => '30'],
      ],
      'sorts' => [['property' => 'name', 'order' => 'desc']],
      'properties' => ['city', 'name'],
      'format' => 'csv',
    ], $state->toDatastoreQuery($this->likeEscaper()));
  }

  /**
   * Invalid values fall back to defaults instead of erroring.
   */
  public function testInvalidInput(): void {
    $state = TableState::fromQuery([
      'table' => '-1',
      'page' => 'x',
      'page_size' => '33',
      'sort' => 'evil',
      'direction' => 'desc',
      'columns' => 'evil,,age,age',
      'conditions' => 'nope',
    ], $this->fields);
    $this->assertSame(['columns' => 'age'], $state->toQuery());

    // Direction without a valid sort is dropped; asc is never serialized.
    $state = TableState::fromQuery(['sort' => 'age', 'direction' => 'ASC'], $this->fields);
    $this->assertSame(['sort' => 'age'], $state->toQuery());
    $state = TableState::fromQuery(['sort' => 'age', 'direction' => 'DESC'], $this->fields);
    $this->assertSame(['sort' => 'age', 'direction' => 'desc'], $state->toQuery());

    // Page 0 and table beyond the count clamp.
    $state = TableState::fromQuery(['page' => '0', 'table' => '9'], $this->fields, 2);
    $this->assertSame(['table' => 1], $state->toQuery());
    $this->assertSame(0, TableState::fromQuery(['table' => '9'], $this->fields)->table);

    // Oversized page numbers are capped so the offset stays an integer.
    $state = TableState::fromQuery(['page' => str_repeat('9', 30)], $this->fields);
    $this->assertSame(TableState::MAX_PAGE, $state->page);
    $this->assertIsInt($state->offset());

    // A sort on a hidden column is dropped.
    $state = TableState::fromQuery(['sort' => 'age', 'columns' => 'name'], $this->fields);
    $this->assertSame(['columns' => 'name'], $state->toQuery());

    // Nested array values in conditions and arrays as scalars are ignored.
    $state = TableState::fromQuery(['page' => ['1'], 'conditions' => [['property' => 'name', 'value' => ['a']]]], $this->fields);
    $this->assertSame([], $state->toQuery());
  }

  /**
   * Conditions: invalid rows dropped, capped at ten, keys re-indexed.
   */
  public function testConditions(): void {
    $conditions = [
      5 => ['property' => 'name', 'value' => 'a'],
      'x' => ['property' => 'nope', 'value' => 'b'],
      7 => ['property' => 'name', 'value' => ''],
      9 => ['property' => 'city', 'operator' => 'in', 'value' => 'x, y'],
    ];
    $state = TableState::fromQuery(['conditions' => $conditions], $this->fields);
    $this->assertSame([
      ['property' => 'name', 'operator' => '=', 'value' => 'a'],
      ['property' => 'city', 'operator' => 'in', 'value' => 'x,y'],
    ], $state->toQuery()['conditions']);

    $many = array_fill(0, 12, ['property' => 'name', 'value' => 'a']);
    $this->assertCount(10, TableState::fromQuery(['conditions' => $many], $this->fields)->conditions);
  }

  /**
   * Column lists equal to the schema are the default; arrays are accepted.
   */
  public function testColumns(): void {
    $this->assertSame([], TableState::normalizeColumns('name,age,city', $this->fields));
    $this->assertSame(['city', 'name', 'age'], TableState::normalizeColumns('city,name,age', $this->fields));
    $this->assertSame(['age'], TableState::normalizeColumns(['age', 'nope', 3], $this->fields));
    $this->assertSame([], TableState::normalizeColumns(NULL, $this->fields));
    $this->assertSame(['age'], TableState::fromQuery(['columns' => ['age']], $this->fields)->columns);
  }

  /**
   * Page sizes are capped by the row limit.
   */
  public function testPageSizes(): void {
    $this->assertSame([10, 25, 50, 100], TableState::pageSizes(NULL));
    $this->assertSame([10, 25, 50, 100], TableState::pageSizes(500));
    $this->assertSame([10, 25], TableState::pageSizes(30));
    $this->assertSame([5], TableState::pageSizes(5));

    $this->assertSame(25, TableState::fromQuery(['page_size' => '100'], $this->fields, 1, 30)->pageSize);
    $this->assertSame(10, TableState::fromQuery(['page_size' => '25'], $this->fields, 1, 20)->pageSize);
    $this->assertSame(5, TableState::fromQuery([], $this->fields, 1, 5)->pageSize);
  }

  /**
   * Withers return new states and reset the page where React does.
   */
  public function testWithers(): void {
    $base = TableState::fromQuery(['page' => '4', 'sort' => 'age', 'columns' => 'age,name'], $this->fields);

    $this->assertSame(2, $base->withPage(2)->page);
    $this->assertSame(1, $base->withPage(0)->page);
    $this->assertSame(4, $base->page);

    $sorted = $base->withSort('name', 'desc');
    $this->assertSame(['sort' => 'name', 'direction' => 'desc', 'columns' => 'age,name'], $sorted->toQuery());
    $this->assertSame([], $base->withSort(NULL, 'desc')->toQuery()['sort'] ?? []);

    $this->assertSame(['page_size' => 10, 'sort' => 'age', 'columns' => 'age,name'], $base->withPageSize(10)->toQuery());

    $condition = new Condition('name', '=', 'a');
    $filtered = $base->withConditions([3 => $condition]);
    $this->assertSame(1, $filtered->page);
    $this->assertSame([$condition], $filtered->conditions);
    $this->assertSame([], $filtered->withoutCondition(0)->conditions);

    // Hiding the sorted column drops the sort; page is kept.
    $columns = $base->withColumns(['name']);
    $this->assertSame(['page' => 4, 'columns' => 'name'], $columns->toQuery());
    $this->assertSame(['page' => 4, 'sort' => 'age', 'columns' => 'city,age'], $base->withColumns(['city', 'age'])->toQuery());
    $this->assertSame(['page' => 4, 'sort' => 'age'], $base->withColumns([])->toQuery());
  }

  /**
   * Requests parse through the same path.
   */
  public function testFromRequest(): void {
    $request = Request::create('/dataset/x', 'GET', [
      'sort' => 'age',
      'conditions' => [['property' => 'name', 'value' => 'a']],
    ]);
    $state = TableState::fromRequest($request, $this->fields);
    $this->assertSame('age', $state->sort);
    $this->assertCount(1, $state->conditions);
  }

}
