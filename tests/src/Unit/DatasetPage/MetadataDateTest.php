<?php

namespace Drupal\Tests\dkan_catalog_ui\Unit\DatasetPage;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\dkan_catalog_ui\DatasetPage\MetadataDate;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Metadata dates are parsed and formatted in UTC.
 */
#[Group('dkan_catalog_ui')]
class MetadataDateTest extends UnitTestCase {

  /**
   * Parsing yields UTC instants regardless of the process timezone.
   */
  public function testParse(): void {
    date_default_timezone_set('Pacific/Auckland');
    $cases = [
      '2020-01-15' => '2020-01-15 00:00 UTC',
      '2020-01-15T23:30:00Z' => '2020-01-15 23:30 UTC',
      '2020-01-15T23:30:00-08:00' => '2020-01-16 07:30 UTC',
      ' 2020-01-15 ' => '2020-01-15 00:00 UTC',
    ];
    foreach ($cases as $value => $expected) {
      $this->assertSame($expected, MetadataDate::parse($value)?->format('Y-m-d H:i T'), $value);
    }
    $this->assertNull(MetadataDate::parse(''));
    $this->assertNull(MetadataDate::parse('not a date'));
    $this->assertSame('2020-01-15', MetadataDate::iso('2020-01-15T23:30:00Z'));
    $this->assertSame('', MetadataDate::iso('not a date'));
  }

  /**
   * Display goes through the formatter in UTC; unparseable values pass through.
   */
  public function testDisplay(): void {
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->expects($this->once())
      ->method('format')
      ->with(1579131000, 'custom', 'F j, Y', 'UTC')
      ->willReturn('January 15, 2020');
    $this->assertSame('January 15, 2020', MetadataDate::display($formatter, '2020-01-15T23:30:00Z'));
    $this->assertSame('circa 2020', MetadataDate::display($formatter, 'circa 2020'));
  }

}
