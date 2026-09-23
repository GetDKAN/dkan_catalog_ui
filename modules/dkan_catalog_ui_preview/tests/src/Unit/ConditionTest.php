<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Unit;

use Drupal\dkan_catalog_ui_preview\Condition;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\LikeEscaperTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests condition validation and translation.
 */
#[Group('dkan_catalog_ui_preview')]
class ConditionTest extends UnitTestCase {

  use LikeEscaperTrait;

  /**
   * Schema fields used by every test.
   */
  protected array $fields = [
    'name' => ['type' => 'text'],
    'age' => ['type' => 'int'],
  ];

  /**
   * Invalid input is dropped.
   */
  public function testCreateRejects(): void {
    $this->assertNull(Condition::create('x', $this->fields));
    $this->assertNull(Condition::create(['property' => 'nope', 'value' => 'a'], $this->fields));
    $this->assertNull(Condition::create(['property' => 'age', 'operator' => 'contains', 'value' => '1'], $this->fields));
    $this->assertNull(Condition::create(['property' => 'name', 'operator' => 'like', 'value' => 'a'], $this->fields));
    $this->assertNull(Condition::create(['property' => 'name', 'value' => '   '], $this->fields));
    $this->assertNull(Condition::create(['property' => 'name', 'value' => ['a']], $this->fields));
    $this->assertNull(Condition::create(['property' => 'name', 'value' => str_repeat('a', 256)], $this->fields));
    $this->assertNull(Condition::create(['property' => 'name', 'operator' => 'in', 'value' => ' , ,'], $this->fields));
  }

  /**
   * Valid input keeps the user's operator; the default operator is "=".
   */
  public function testCreateAccepts(): void {
    $condition = Condition::create(['property' => 'name', 'value' => ' Ann '], $this->fields);
    $this->assertSame(['property' => 'name', 'operator' => '=', 'value' => 'Ann'], $condition->toArray());

    $condition = Condition::create(['property' => 'name', 'value' => str_repeat('a', 255)], $this->fields);
    $this->assertNotNull($condition);

    $condition = Condition::create(['property' => 'age', 'operator' => '>', 'value' => '30'], $this->fields);
    $this->assertSame(['property' => 'age', 'operator' => '>', 'value' => '30'], $condition->toQueryCondition($this->likeEscaper()));
  }

  /**
   * The "in" list grammar: trim, drop empties, dedupe, cap at 50.
   */
  public function testInGrammar(): void {
    $condition = Condition::create(['property' => 'name', 'operator' => 'in', 'value' => ' b, a ,,b , c'], $this->fields);
    $this->assertSame('b,a,c', $condition->value);
    $this->assertSame(['b', 'a', 'c'], $condition->values());
    $this->assertSame(['property' => 'name', 'operator' => 'in', 'value' => ['b', 'a', 'c']], $condition->toQueryCondition($this->likeEscaper()));

    $fifty = implode(',', range(1, 50));
    $this->assertCount(50, Condition::create(['property' => 'name', 'operator' => 'in', 'value' => $fifty], $this->fields)->values());
    $this->assertCount(50, Condition::create(['property' => 'name', 'operator' => 'in', 'value' => $fifty . ',51'], $this->fields)->values());
  }

  /**
   * Contains and starts-with become literal LIKE patterns.
   */
  public function testLikeTranslation(): void {
    $condition = Condition::create(['property' => 'name', 'operator' => 'contains', 'value' => '50%'], $this->fields);
    $this->assertSame(['property' => 'name', 'operator' => 'like', 'value' => '%50\%%'], $condition->toQueryCondition($this->likeEscaper()));
    $this->assertSame('contains', $condition->toArray()['operator']);

    $condition = Condition::create(['property' => 'name', 'operator' => 'starts with', 'value' => 'a_'], $this->fields);
    $this->assertSame(['property' => 'name', 'operator' => 'like', 'value' => 'a\_%'], $condition->toQueryCondition($this->likeEscaper()));
  }

}
