<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\Core\Database\Connection;

/**
 * Builds literal LIKE patterns with the active connection's escaping.
 */
final class LikeEscaper {

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly Connection $connection,
  ) {}

  /**
   * Escape LIKE metacharacters so the value matches literally.
   */
  public function escape(string $value): string {
    return $this->connection->escapeLike($value);
  }

  /**
   * Pattern for "contains".
   */
  public function contains(string $value): string {
    return '%' . $this->escape($value) . '%';
  }

  /**
   * Pattern for "starts with".
   */
  public function startsWith(string $value): string {
    return $this->escape($value) . '%';
  }

}
