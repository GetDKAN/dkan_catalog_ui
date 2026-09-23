<?php

namespace Drupal\Tests\dkan_catalog_ui\Unit\DatasetPage;

use Drupal\dkan_catalog_ui\DatasetPage\DownloadUrl;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests download URL validation.
 */
#[Group('dkan_catalog_ui')]
class DownloadUrlTest extends UnitTestCase {

  /**
   * Only absolute http(s) URLs become links.
   */
  public function testValidation(): void {
    $this->assertSame('https://example.com/a%20b.csv', DownloadUrl::fromString(' https://example.com/a%20b.csv ')->getUri());
    $this->assertSame('http://example.com/data.csv?x=1', DownloadUrl::fromString('http://example.com/data.csv?x=1')->getUri());

    $this->assertNull(DownloadUrl::fromString(''));
    $this->assertNull(DownloadUrl::fromString('/sites/default/files/data.csv'));
    $this->assertNull(DownloadUrl::fromString('javascript:alert(1)'));
    $this->assertNull(DownloadUrl::fromString('data:text/csv,a'));
    $this->assertNull(DownloadUrl::fromString('ftp://example.com/data.csv'));
    $this->assertNull(DownloadUrl::fromString('http://exa mple.com/data.csv'));
    $this->assertNull(DownloadUrl::fromString('not a url'));
  }

}
