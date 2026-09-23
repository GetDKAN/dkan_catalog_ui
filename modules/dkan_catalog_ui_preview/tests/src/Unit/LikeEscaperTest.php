<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Unit;

use Drupal\Core\Database\Connection;
use Drupal\dkan_catalog_ui_preview\LikeEscaper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests literal LIKE patterns.
 */
#[Group('dkan_catalog_ui_preview')]
class LikeEscaperTest extends UnitTestCase {

  /**
   * Metacharacters are escaped; multibyte text is untouched.
   */
  public function testPatterns(): void {
    $connection = $this->createMock(Connection::class);
    $connection->method('escapeLike')->willReturnCallback(fn ($v) => addcslashes($v, '\\%_'));
    $escaper = new LikeEscaper($connection);
    $this->assertSame('%50\%%', $escaper->contains('50%'));
    $this->assertSame('a\_b%', $escaper->startsWith('a_b'));
    $this->assertSame('%c:\\\\dir%', $escaper->contains('c:\\dir'));
    $this->assertSame('%Zürich 東京%', $escaper->contains('Zürich 東京'));
    $this->assertSame('%%', $escaper->contains(''));
  }

}
