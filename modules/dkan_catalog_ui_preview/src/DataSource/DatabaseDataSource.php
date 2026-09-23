<?php

namespace Drupal\dkan_catalog_ui_preview\DataSource;

use Drupal\dkan_common\DataResource;
use Drupal\dkan_common\Storage\Query;
use Drupal\dkan_datastore\DatastoreService;

/**
 * Data source that queries datastore tables directly via the database.
 */
class DatabaseDataSource implements DataSourceInterface {

  /**
   * Operators passed through to the datastore query.
   */
  const OPERATORS = ['=', '<>', '<', '<=', '>', '>=', 'like', 'in'];

  /**
   * Database table storage objects, keyed by resource id.
   *
   * The $storages array is populated as ::getStorage is called; the array
   * memoizes it for later use.
   *
   * @var \Drupal\dkan_datastore\Storage\DatabaseTable[]
   */
  protected array $storages = [];

  /**
   * Constructor.
   *
   * @param \Drupal\dkan_datastore\DatastoreService $datastoreService
   *   The DKAN datastore service.
   */
  public function __construct(
    protected readonly DatastoreService $datastoreService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $resource_id): array {
    $storage = $this->getStorage($resource_id);
    if (!$storage) {
      return [];
    }
    $schema = $storage->getSchema();
    if (empty($schema['fields'])) {
      return [];
    }
    unset($schema['fields'][self::HIDDEN_FIELD]);
    return $schema;
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
    $storage = $this->getStorage($resource_id);
    if (!$storage) {
      return new DataSourceResult([], 0);
    }

    $query = $this->buildQuery($limit, $offset, $sort_field, $sort_direction, $conditions, $properties);
    $rows = $storage->query($query);

    $totalCount = $this->count($storage, $conditions);
    // Only filtered requests pay for the second count.
    $unfilteredTotalCount = $conditions ? $this->count($storage, []) : $totalCount;

    return new DataSourceResult($rows, $totalCount, $unfilteredTotalCount);
  }

  /**
   * Count-only query: conditions, no sort, limit or properties.
   *
   * @param \Drupal\dkan_datastore\Storage\DatabaseTable $storage
   *   The resource storage.
   * @param array $conditions
   *   Conditions in datastore query shape.
   */
  protected function count($storage, array $conditions): int {
    $query = $this->buildQuery(0, 0, NULL, 'asc', $conditions, []);
    $query->count();
    $result = $storage->query($query);
    return (int) ($result[0]->expression ?? 0);
  }

  /**
   * Get the datastore storage for a resource id, or NULL if none exists.
   *
   * @param string $resource_id
   *   Resource id: "identifier__version", a full unique identifier, or a
   *   distribution UUID (see DataResource::getIdentifierAndVersion()).
   *
   * @return \Drupal\dkan_datastore\Storage\DatabaseTable|null
   *   The storage, or NULL when the resource has no datastore table yet.
   *
   * @throws \Exception
   *   When the resource id cannot be resolved to an identifier and version.
   */
  protected function getStorage(string $resource_id) {
    if (!array_key_exists($resource_id, $this->storages)) {
      [$identifier, $version] = DataResource::getIdentifierAndVersion($resource_id);
      try {
        $this->storages[$resource_id] = $this->datastoreService->getStorage($identifier, $version);
      }
      catch (\InvalidArgumentException) {
        // No local_file perspective yet: the expected pre-import state.
        $this->storages[$resource_id] = NULL;
      }
    }
    return $this->storages[$resource_id];
  }

  /**
   * Build a DKAN Query object.
   */
  protected function buildQuery(
    int $limit,
    int $offset,
    ?string $sort_field,
    string $sort_direction,
    array $conditions,
    array $properties,
  ): Query {
    $query = new Query();

    if ($limit > 0) {
      $query->limitTo($limit);
    }
    if ($offset > 0) {
      $query->offsetBy($offset);
    }

    if ($sort_field) {
      if (strtolower($sort_direction) === 'desc') {
        $query->sortByDescending($sort_field);
      }
      else {
        $query->sortByAscending($sort_field);
      }
    }

    foreach ($conditions as $condition) {
      if ($normalized = $this->normalizeCondition($condition)) {
        $query->conditions[] = $normalized;
      }
    }

    foreach ($properties as $property) {
      $query->filterByProperty($property);
    }

    return $query;
  }

  /**
   * Whitelist a condition for the datastore query.
   *
   * Only plain comparison operators, `like` and `in` (array value) pass;
   * `contains`, `starts with` and `match` are refused because SelectFactory
   * does not escape their values.
   *
   * @return object|null
   *   The condition object, or NULL to drop it.
   */
  protected function normalizeCondition(mixed $condition): ?object {
    if (!is_array($condition) || !is_string($condition['property'] ?? NULL)) {
      return NULL;
    }
    $operator = strtolower((string) ($condition['operator'] ?? '='));
    $value = $condition['value'] ?? NULL;
    if (!in_array($operator, self::OPERATORS, TRUE)) {
      return NULL;
    }
    if ($operator === 'in') {
      if (!is_array($value) || $value === [] || array_filter($value, fn ($v) => !is_scalar($v))) {
        return NULL;
      }
      $value = array_values(array_map('strval', $value));
    }
    elseif (!is_scalar($value)) {
      return NULL;
    }
    else {
      $value = (string) $value;
    }
    return (object) [
      'property' => $condition['property'],
      'value' => $value,
      'operator' => $operator,
    ];
  }

}
