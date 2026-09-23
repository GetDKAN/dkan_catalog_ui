<?php

namespace Drupal\dkan_catalog_ui_preview;

use Symfony\Component\HttpFoundation\Request;

/**
 * Immutable state of one data table, parsed from and serialized to the URL.
 *
 * Canonical query parameters, in order, defaults omitted: `table`,
 * `conditions[i][property|operator|value]`, `page` (1-based), `page_size`,
 * `sort`, `direction` (only `desc`), `columns` (comma list, sets order).
 * Invalid values are dropped, never errors; a sort on a hidden column is
 * dropped too.
 */
final class TableState {

  const PAGE_SIZES = [10, 25, 50, 100];
  const DEFAULT_PAGE_SIZE = 25;
  const MAX_CONDITIONS = 10;
  const MAX_PAGE = 1000000;

  /**
   * Constructor. Use ::fromQuery() to validate input.
   *
   * @param int $table
   *   Distribution index.
   * @param int $page
   *   Page number, 1-based.
   * @param int $pageSize
   *   Rows per page.
   * @param string|null $sort
   *   Sort column machine name.
   * @param string $direction
   *   Sort direction, 'asc' or 'desc'.
   * @param \Drupal\dkan_catalog_ui_preview\Condition[] $conditions
   *   Filter conditions.
   * @param string[] $columns
   *   Visible columns in order; empty means all columns in schema order.
   */
  public function __construct(
    public readonly int $table = 0,
    public readonly int $page = 1,
    public readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
    public readonly ?string $sort = NULL,
    public readonly string $direction = 'asc',
    public readonly array $conditions = [],
    public readonly array $columns = [],
  ) {}

  /**
   * Read the distribution index from a query, clamped to the available count.
   */
  public static function tableIndex(array $query, int $tableCount = PHP_INT_MAX): int {
    $table = $query['table'] ?? 0;
    if (!is_scalar($table) || !ctype_digit((string) $table)) {
      return 0;
    }
    return min((int) $table, max($tableCount - 1, 0));
  }

  /**
   * Page sizes allowed under a row limit; always at least one.
   *
   * @return int[]
   *   Sizes.
   */
  public static function pageSizes(?int $maxPageSize = NULL): array {
    $sizes = self::PAGE_SIZES;
    if ($maxPageSize !== NULL && $maxPageSize > 0) {
      $sizes = array_values(array_filter($sizes, fn ($size) => $size <= $maxPageSize)) ?: [$maxPageSize];
    }
    return $sizes;
  }

  /**
   * Parse and validate a query against the table's schema.
   *
   * @param array $query
   *   Query parameters (nested arrays for conditions; `columns` as a comma
   *   list or an array of names).
   * @param array $fields
   *   Schema fields keyed by machine name.
   * @param int $tableCount
   *   Number of tabular distributions.
   * @param int|null $maxPageSize
   *   Row limit that caps the page size.
   */
  public static function fromQuery(array $query, array $fields, int $tableCount = 1, ?int $maxPageSize = NULL): self {
    $sizes = self::pageSizes($maxPageSize);
    $pageSize = $query['page_size'] ?? NULL;
    $pageSize = (is_scalar($pageSize) && ctype_digit((string) $pageSize)) ? (int) $pageSize : NULL;
    if (!in_array($pageSize, $sizes, TRUE)) {
      $pageSize = in_array(self::DEFAULT_PAGE_SIZE, $sizes, TRUE) ? self::DEFAULT_PAGE_SIZE : end($sizes);
    }

    $page = $query['page'] ?? 1;
    $page = (is_scalar($page) && ctype_digit((string) $page)) ? min(max(1, (int) $page), self::MAX_PAGE) : 1;

    $columns = self::normalizeColumns($query['columns'] ?? [], $fields);
    $sort = $query['sort'] ?? NULL;
    $sort = (is_string($sort) && isset($fields[$sort]) && ($columns === [] || in_array($sort, $columns, TRUE))) ? $sort : NULL;
    $direction = $query['direction'] ?? 'asc';
    $direction = ($sort !== NULL && is_string($direction) && strtolower($direction) === 'desc') ? 'desc' : 'asc';

    $conditions = [];
    if (is_array($query['conditions'] ?? NULL)) {
      foreach ($query['conditions'] as $input) {
        if ($condition = Condition::create($input, $fields)) {
          $conditions[] = $condition;
        }
        if (count($conditions) === self::MAX_CONDITIONS) {
          break;
        }
      }
    }

    return new self(
      self::tableIndex($query, $tableCount),
      $page,
      $pageSize,
      $sort,
      $direction,
      $conditions,
      $columns,
    );
  }

  /**
   * Parse the current request's query.
   */
  public static function fromRequest(Request $request, array $fields, int $tableCount = 1, ?int $maxPageSize = NULL): self {
    return self::fromQuery($request->query->all(), $fields, $tableCount, $maxPageSize);
  }

  /**
   * Validate a column list: known names only, deduped, order kept.
   *
   * A list equal to every column in schema order is the default and becomes
   * empty.
   *
   * @return string[]
   *   Column machine names.
   */
  public static function normalizeColumns(mixed $columns, array $fields): array {
    if (is_string($columns)) {
      $columns = explode(',', $columns);
    }
    if (!is_array($columns)) {
      return [];
    }
    $valid = [];
    foreach ($columns as $column) {
      if (is_string($column) && ($column = trim($column)) !== '' && isset($fields[$column]) && !in_array($column, $valid, TRUE)) {
        $valid[] = $column;
      }
    }
    return $valid === array_keys($fields) ? [] : $valid;
  }

  /**
   * Visible columns in display order.
   *
   * @return string[]
   *   Machine names.
   */
  public function visibleColumns(array $fields): array {
    return $this->columns ?: array_keys($fields);
  }

  /**
   * Row offset for the current page.
   */
  public function offset(): int {
    return ($this->page - 1) * $this->pageSize;
  }

  /**
   * Canonical query parameters.
   */
  public function toQuery(): array {
    $query = [];
    if ($this->table !== 0) {
      $query['table'] = $this->table;
    }
    if ($this->conditions) {
      $query['conditions'] = array_map(fn (Condition $c) => $c->toArray(), $this->conditions);
    }
    if ($this->page > 1) {
      $query['page'] = $this->page;
    }
    if ($this->pageSize !== self::DEFAULT_PAGE_SIZE) {
      $query['page_size'] = $this->pageSize;
    }
    if ($this->sort !== NULL) {
      $query['sort'] = $this->sort;
      if ($this->direction === 'desc') {
        $query['direction'] = 'desc';
      }
    }
    if ($this->columns) {
      $query['columns'] = implode(',', $this->columns);
    }
    return $query;
  }

  /**
   * Datastore query payload for the download route (no limit, no offset).
   */
  public function toDatastoreQuery(LikeEscaper $escaper): array {
    $query = [];
    if ($this->conditions) {
      $query['conditions'] = array_map(fn (Condition $c) => $c->toQueryCondition($escaper), $this->conditions);
    }
    if ($this->sort !== NULL) {
      $query['sorts'] = [['property' => $this->sort, 'order' => $this->direction]];
    }
    if ($this->columns) {
      $query['properties'] = $this->columns;
    }
    $query['format'] = 'csv';
    return $query;
  }

  /**
   * Whether two states serialize identically.
   */
  public function equals(self $other): bool {
    return $this->toQuery() === $other->toQuery();
  }

  /**
   * Copy with a different page.
   */
  public function withPage(int $page): self {
    return new self($this->table, min(max(1, $page), self::MAX_PAGE), $this->pageSize, $this->sort, $this->direction, $this->conditions, $this->columns);
  }

  /**
   * Copy sorted by a column, back on page 1.
   */
  public function withSort(?string $sort, string $direction = 'asc'): self {
    $direction = ($sort !== NULL && $direction === 'desc') ? 'desc' : 'asc';
    return new self($this->table, 1, $this->pageSize, $sort, $direction, $this->conditions, $this->columns);
  }

  /**
   * Copy with a page size, back on page 1.
   */
  public function withPageSize(int $pageSize): self {
    return new self($this->table, 1, $pageSize, $this->sort, $this->direction, $this->conditions, $this->columns);
  }

  /**
   * Copy with conditions, back on page 1.
   *
   * @param \Drupal\dkan_catalog_ui_preview\Condition[] $conditions
   *   Conditions.
   */
  public function withConditions(array $conditions): self {
    return new self($this->table, 1, $this->pageSize, $this->sort, $this->direction, array_values($conditions), $this->columns);
  }

  /**
   * Copy without the condition at an index, back on page 1.
   */
  public function withoutCondition(int $index): self {
    $conditions = $this->conditions;
    unset($conditions[$index]);
    return $this->withConditions($conditions);
  }

  /**
   * Copy with visible columns; the sort is dropped if its column is hidden.
   *
   * @param string[] $columns
   *   Column machine names, already normalized.
   */
  public function withColumns(array $columns): self {
    $sort = ($columns && $this->sort !== NULL && !in_array($this->sort, $columns, TRUE)) ? NULL : $this->sort;
    return new self($this->table, $this->page, $this->pageSize, $sort, $sort ? $this->direction : 'asc', $this->conditions, array_values($columns));
  }

}
