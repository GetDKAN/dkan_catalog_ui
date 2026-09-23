<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Filter operators allowed per column type, with their labels.
 *
 * Operators are the datastore query operators; `contains` and `starts with`
 * are translated to an escaped `like` by Condition::toQueryCondition(), never
 * sent to the datastore as-is.
 */
final class OperatorRegistry {

  const CATEGORY_STRING = 'string';
  const CATEGORY_NUMBER = 'number';
  const CATEGORY_DATE = 'date';
  const CATEGORY_OTHER = 'other';

  /**
   * Operators per category, in menu order.
   */
  const OPERATORS = [
    self::CATEGORY_STRING => ['=', 'contains', 'starts with', '<>', 'in'],
    self::CATEGORY_NUMBER => ['=', '<>', '>', '<'],
    self::CATEGORY_DATE => ['=', '<>', '>', '<'],
    self::CATEGORY_OTHER => ['=', '<>'],
  ];

  /**
   * Operator labels, matching the React frontend.
   */
  public static function labels(): array {
    return [
      '=' => new TranslatableMarkup('Is'),
      'contains' => new TranslatableMarkup('Contains'),
      'starts with' => new TranslatableMarkup('Starts With'),
      '<>' => new TranslatableMarkup('Is Not'),
      'in' => new TranslatableMarkup('Or'),
      '>' => new TranslatableMarkup('Greater Than'),
      '<' => new TranslatableMarkup('Less Than'),
    ];
  }

  /**
   * Classify a Schema API field definition.
   *
   * @param array $field
   *   Field definition from the datastore schema ('type', optional
   *   'mysql_type').
   *
   * @return string
   *   One of the CATEGORY_* constants.
   */
  public static function category(array $field): string {
    $dbType = strtolower((string) ($field['mysql_type'] ?? $field['pgsql_type'] ?? ''));
    if (in_array($dbType, ['date', 'datetime', 'timestamp', 'time', 'year'], TRUE)) {
      return self::CATEGORY_DATE;
    }
    if (in_array($dbType, ['tinyint', 'bool', 'boolean', 'bit'], TRUE)) {
      return self::CATEGORY_OTHER;
    }
    return match (strtolower((string) ($field['type'] ?? ''))) {
      'int', 'float', 'numeric', 'serial' => self::CATEGORY_NUMBER,
      'text', 'varchar', 'varchar_ascii', 'char', 'blob' => self::CATEGORY_STRING,
      default => self::CATEGORY_OTHER,
    };
  }

  /**
   * Operators allowed for a field, in menu order.
   *
   * @return string[]
   *   Operator keys.
   */
  public static function forField(array $field): array {
    return self::OPERATORS[self::category($field)];
  }

  /**
   * Operator options for a field as operator => label.
   */
  public static function optionsForField(array $field): array {
    $labels = self::labels();
    $options = [];
    foreach (self::forField($field) as $operator) {
      $options[$operator] = $labels[$operator];
    }
    return $options;
  }

  /**
   * Whether an operator is allowed for a field.
   */
  public static function isAllowed(string $operator, array $field): bool {
    return in_array($operator, self::forField($field), TRUE);
  }

}
