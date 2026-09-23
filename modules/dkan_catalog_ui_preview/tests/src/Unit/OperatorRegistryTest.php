<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\dkan_catalog_ui_preview\OperatorRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the operator whitelist per column type.
 */
#[Group('dkan_catalog_ui_preview')]
class OperatorRegistryTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Categories from datastore schema definitions.
   */
  public function testCategory(): void {
    $this->assertSame('string', OperatorRegistry::category(['type' => 'text', 'mysql_type' => 'text']));
    $this->assertSame('string', OperatorRegistry::category(['type' => 'varchar']));
    $this->assertSame('number', OperatorRegistry::category(['type' => 'int', 'mysql_type' => 'int']));
    $this->assertSame('number', OperatorRegistry::category(['type' => 'numeric', 'mysql_type' => 'decimal']));
    $this->assertSame('number', OperatorRegistry::category(['type' => 'float']));
    // Dictionary-typed dates map to varchar in the Schema API; the driver
    // type tells them apart.
    $this->assertSame('date', OperatorRegistry::category(['type' => 'varchar', 'mysql_type' => 'date']));
    $this->assertSame('date', OperatorRegistry::category(['type' => 'varchar', 'mysql_type' => 'datetime']));
    $this->assertSame('other', OperatorRegistry::category(['type' => 'int', 'mysql_type' => 'tinyint']));
    $this->assertSame('other', OperatorRegistry::category([]));
  }

  /**
   * Operators per category and their labels.
   */
  public function testOperators(): void {
    $this->assertSame(['=', 'contains', 'starts with', '<>', 'in'], OperatorRegistry::forField(['type' => 'text']));
    $this->assertSame(['=', '<>', '>', '<'], OperatorRegistry::forField(['type' => 'int']));
    $this->assertSame(['=', '<>', '>', '<'], OperatorRegistry::forField(['type' => 'varchar', 'mysql_type' => 'date']));
    $this->assertSame(['=', '<>'], OperatorRegistry::forField(['type' => 'blob', 'mysql_type' => 'bit']));

    $this->assertTrue(OperatorRegistry::isAllowed('contains', ['type' => 'text']));
    $this->assertFalse(OperatorRegistry::isAllowed('contains', ['type' => 'int']));
    $this->assertFalse(OperatorRegistry::isAllowed('like', ['type' => 'text']));
    $this->assertFalse(OperatorRegistry::isAllowed('match', ['type' => 'text']));

    $options = OperatorRegistry::optionsForField(['type' => 'text']);
    $this->assertSame(['=', 'contains', 'starts with', '<>', 'in'], array_keys($options));
    $this->assertSame('Or', (string) $options['in']);
    $this->assertSame('Is Not', (string) $options['<>']);
  }

}
