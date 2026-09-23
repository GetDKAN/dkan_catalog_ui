<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Traits;

use Drupal\Core\Database\Connection;
use Drupal\dkan_catalog_ui_preview\LikeEscaper;

/**
 * A LikeEscaper over a mocked connection using core's escaping.
 */
trait LikeEscaperTrait {

  /**
   * Get an escaper whose connection escapes like core's default.
   */
  protected function likeEscaper(): LikeEscaper {
    $connection = $this->createMock(Connection::class);
    $connection->method('escapeLike')->willReturnCallback(fn ($value) => addcslashes($value, '\\%_'));
    return new LikeEscaper($connection);
  }

}
