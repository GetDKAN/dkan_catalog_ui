<?php

namespace Drupal\Tests\dkan_catalog_ui\Unit;

use Drupal\dkan_catalog_ui\Plugin\facets\processor\CaseProcessor;
use Drupal\facets\FacetInterface;
use Drupal\facets\Result\Result;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Re-casing keeps other letters and handles non-ASCII input.
 */
#[Group('dkan_catalog_ui')]
class CaseProcessorTest extends UnitTestCase {

  /**
   * Run the processor in a mode over display values.
   *
   * @return string[]
   *   The re-cased display values.
   */
  protected function process(string $mode, array $values): array {
    $facet = $this->createMock(FacetInterface::class);
    $results = array_map(fn(string $value) => new Result($facet, $value, $value, 1), $values);
    $processor = new CaseProcessor(['mode' => $mode], 'dcu_case', []);
    return array_map(fn(Result $result) => $result->getDisplayValue(), $processor->build($facet, $results));
  }

  public function testCapitalizeWords(): void {
    $this->assertSame(
      ['City Planning', 'Bike-Lanes', 'NASA Data', 'Élan Vital', 'A/B Test', 'Snake_Case'],
      $this->process('ucwords', ['city planning', 'bike-lanes', 'NASA data', 'élan vital', 'a/b test', 'snake_case'])
    );
  }

  public function testUpper(): void {
    $this->assertSame(['ÉLAN VITAL', 'NASA'], $this->process('upper', ['élan vital', 'NASA']));
  }

}
