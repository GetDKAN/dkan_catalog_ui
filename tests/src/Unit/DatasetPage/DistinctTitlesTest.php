<?php

namespace Drupal\Tests\dkan_catalog_ui\Unit\DatasetPage;

use Drupal\dkan_catalog_ui\DatasetPage\DistinctTitles;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Repeated distribution titles get a file name or number.
 */
#[Group('dkan_catalog_ui')]
class DistinctTitlesTest extends UnitTestCase {

  /**
   * Unique titles stay; repeats take the file name, then an index.
   */
  public function testApply(): void {
    $this->assertSame(
      ['a' => 'Data', 'b' => 'Other'],
      DistinctTitles::apply(['a' => 'Data', 'b' => 'Other'], ['a' => 'x.csv', 'b' => 'y.csv']),
    );
    $this->assertSame(
      ['Same (d1.csv)', 'same (d2.csv)', 'Unique'],
      DistinctTitles::apply(['Same', 'same', 'Unique'], ['d1.csv', 'd2.csv', 'd3.csv']),
    );
    $this->assertSame(
      ['Same (data.csv) (1)', 'Same (data.csv) (2)', 'Same (1)', 'Same (2)'],
      DistinctTitles::apply(['Same', 'Same', 'Same', 'Same'], ['data.csv', 'data.csv', '', '']),
    );
  }

}
