<?php

namespace Drupal\dkan_catalog_ui_preview;

/**
 * One validated filter condition.
 *
 * The URL form keeps the user's operator and value (`in` values as a comma
 * list); toQueryCondition() produces the datastore query shape.
 */
final class Condition {

  const MAX_VALUE_LENGTH = 255;
  const MAX_IN_ITEMS = 50;

  /**
   * Constructor. Use ::create() to validate input.
   */
  public function __construct(
    public readonly string $property,
    public readonly string $operator,
    public readonly string $value,
  ) {}

  /**
   * Validate raw input against the schema and build a condition.
   *
   * @param mixed $input
   *   Array with 'property', 'operator' (default '='), 'value'.
   * @param array $fields
   *   Schema fields keyed by machine name.
   *
   * @return static|null
   *   The condition, or NULL when the input is unusable (dropped silently).
   */
  public static function create(mixed $input, array $fields): ?self {
    if (!is_array($input)) {
      return NULL;
    }
    $property = $input['property'] ?? '';
    $operator = $input['operator'] ?? '=';
    $value = $input['value'] ?? '';
    if (!is_string($property) || !is_string($operator) || !is_string($value)) {
      return NULL;
    }
    if (!isset($fields[$property]) || !OperatorRegistry::isAllowed($operator, $fields[$property])) {
      return NULL;
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
      return NULL;
    }
    if ($operator === 'in') {
      $items = self::normalizeList($value);
      if ($items === []) {
        return NULL;
      }
      $value = implode(',', $items);
    }
    return new self($property, $operator, $value);
  }

  /**
   * Split an `in` value: comma separated, trimmed, no empties, deduped, capped.
   *
   * @return string[]
   *   The items.
   */
  public static function normalizeList(string $value): array {
    $items = array_map('trim', explode(',', $value));
    $items = array_values(array_unique(array_filter($items, fn ($item) => $item !== '')));
    return array_slice($items, 0, self::MAX_IN_ITEMS);
  }

  /**
   * The list items for an `in` condition, or the single value otherwise.
   *
   * @return string[]
   *   Values.
   */
  public function values(): array {
    return $this->operator === 'in' ? self::normalizeList($this->value) : [$this->value];
  }

  /**
   * URL form: property, operator, value.
   */
  public function toArray(): array {
    return [
      'property' => $this->property,
      'operator' => $this->operator,
      'value' => $this->value,
    ];
  }

  /**
   * Datastore query form.
   *
   * `contains` and `starts with` become an escaped `like` so the value
   * matches literally; `in` carries an array value.
   */
  public function toQueryCondition(LikeEscaper $escaper): array {
    [$operator, $value] = match ($this->operator) {
      'contains' => ['like', $escaper->contains($this->value)],
      'starts with' => ['like', $escaper->startsWith($this->value)],
      'in' => ['in', $this->values()],
      default => [$this->operator, $this->value],
    };
    return ['property' => $this->property, 'operator' => $operator, 'value' => $value];
  }

}
