<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Kernel;

use Drupal\dkan_catalog_ui_preview\Controller\TableApplyController;
use Drupal\Tests\dkan_catalog_ui\Kernel\CatalogUiKernelTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the apply route's normalization and redirect.
 */
#[Group('dkan_catalog_ui_preview')]
class TableApplyControllerTest extends CatalogUiKernelTestBase {

  use DatastoreFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui_preview'];

  /**
   * The dataset UUID.
   */
  protected string $uuid = 'apply-test';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The first user is uid 1 and bypasses access checks; skip it.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);
    $node = $this->createDataset($this->uuid);
    $this->importDatasetFixture($node);
  }

  /**
   * Run the controller and return the redirect target split into parts.
   *
   * @return array
   *   Keys: path, query (parsed), fragment.
   */
  protected function apply(array $query, ?string $uuid = NULL): array {
    $controller = TableApplyController::create($this->container);
    $response = $controller->apply($uuid ?? $this->uuid, Request::create('/x', 'GET', $query));
    $this->assertSame(302, $response->getStatusCode());
    $parts = parse_url($response->getTargetUrl());
    parse_str($parts['query'] ?? '', $parsed);
    return ['path' => $parts['path'], 'query' => $parsed, 'fragment' => $parts['fragment'] ?? NULL];
  }

  /**
   * Input is normalized, unknown parameters dropped, the panel kept.
   */
  public function testCanonicalRedirect(): void {
    $target = $this->apply([
      'junk' => 'x',
      'page_size' => '10',
      'direction' => 'desc',
      'sort' => 'age',
      'page' => '3',
      'conditions' => [
        ['property' => 'nope', 'value' => 'b'],
        ['property' => 'name', 'operator' => 'contains', 'value' => ' an '],
      ],
      'columns' => ['city', 'age', 'evil'],
      'panel' => 'filters',
    ]);

    $this->assertSame('/dataset/' . $this->uuid, $target['path']);
    $this->assertSame('dcu-table', $target['fragment']);
    $this->assertSame([
      'conditions' => [['property' => 'name', 'operator' => 'contains', 'value' => 'an']],
      'page' => '3',
      'page_size' => '10',
      'sort' => 'age',
      'direction' => 'desc',
      'columns' => 'city,age',
      'panel' => 'filters',
    ], $target['query']);

    // Without a page parameter the redirect lands on page 1; an unknown
    // panel is dropped.
    $target = $this->apply(['sort' => 'name', 'panel' => 'evil']);
    $this->assertSame(['sort' => 'name'], $target['query']);
  }

  /**
   * The primary action's `close` drops the panel; other actions keep it.
   */
  public function testCloseDropsPanel(): void {
    $target = $this->apply(['sort' => 'age', 'panel' => 'filters', 'close' => '1']);
    $this->assertSame(['sort' => 'age'], $target['query']);

    // Without it the panel survives, and `close` is never canonical state.
    $target = $this->apply(['sort' => 'age', 'panel' => 'filters']);
    $this->assertSame(['sort' => 'age', 'panel' => 'filters'], $target['query']);

    // It wins over an action that would otherwise reopen the panel.
    $target = $this->apply(['panel' => 'columns', 'close' => '1', 'move_down' => 'name']);
    $this->assertArrayNotHasKey('panel', $target['query']);
    $this->assertSame('age,name,city', $target['query']['columns']);
  }

  /**
   * With Accept: application/json the canonical URL comes back as JSON.
   */
  public function testJsonResponse(): void {
    $request = Request::create('/x', 'GET', ['sort' => 'age', 'panel' => 'display', 'junk' => '1']);
    $request->headers->set('Accept', 'application/json');
    $response = TableApplyController::create($this->container)->apply($this->uuid, $request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('/dataset/' . $this->uuid . '?sort=age&panel=display#dcu-table', json_decode($response->getContent())->url);
    $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));

    // The JSON path drops the panel for the primary action too.
    $request = Request::create('/x', 'GET', ['sort' => 'age', 'panel' => 'display', 'close' => '1']);
    $request->headers->set('Accept', 'application/json');
    $response = TableApplyController::create($this->container)->apply($this->uuid, $request);
    $this->assertSame('/dataset/' . $this->uuid . '?sort=age#dcu-table', json_decode($response->getContent())->url);
  }

  /**
   * Empty input redirects to the bare dataset URL.
   */
  public function testEmptyInput(): void {
    $target = $this->apply([]);
    $this->assertSame([], $target['query']);
    $this->assertSame('/dataset/' . $this->uuid, $target['path']);
  }

  /**
   * Filter actions: remove one, reset all.
   */
  public function testFilterActions(): void {
    $conditions = [
      ['property' => 'name', 'value' => 'a'],
      ['property' => 'city', 'value' => 'b'],
    ];
    $target = $this->apply(['conditions' => $conditions, 'remove' => '0', 'page' => '2']);
    $this->assertSame([['property' => 'city', 'operator' => '=', 'value' => 'b']], $target['query']['conditions']);
    $this->assertArrayNotHasKey('page', $target['query']);

    $target = $this->apply(['conditions' => $conditions, 'reset_filters' => '1', 'sort' => 'age']);
    $this->assertSame(['sort' => 'age'], $target['query']);
  }

  /**
   * Column actions: move, reset, hidden sort column drops the sort.
   */
  public function testColumnActions(): void {
    $target = $this->apply(['columns' => ['name', 'age', 'city'], 'move_down' => 'name', 'page' => '2']);
    $this->assertSame(['page' => '2', 'columns' => 'age,name,city'], $target['query']);

    $target = $this->apply(['columns' => 'age,name', 'move_up' => 'name']);
    $this->assertSame(['columns' => 'name,age'], $target['query']);

    // Moving past the edge or an unknown column changes nothing.
    $target = $this->apply(['columns' => 'age,name', 'move_up' => 'age']);
    $this->assertSame(['columns' => 'age,name'], $target['query']);
    $target = $this->apply(['columns' => 'age,name', 'move_down' => 'evil']);
    $this->assertSame(['columns' => 'age,name'], $target['query']);

    // All columns in schema order is the default; moving from the default
    // list works too.
    $target = $this->apply(['columns' => 'name,age,city']);
    $this->assertSame([], $target['query']);
    $target = $this->apply(['move_up' => 'city']);
    $this->assertSame(['columns' => 'name,city,age'], $target['query']);

    // A sort on a hidden column is already gone when columns are reset.
    $target = $this->apply(['columns' => 'age', 'sort' => 'name', 'reset_columns' => '1']);
    $this->assertSame([], $target['query']);
    $target = $this->apply(['columns' => ['age'], 'sort' => 'name']);
    $this->assertSame(['columns' => 'age'], $target['query']);
    $target = $this->apply(['columns' => 'age,name', 'sort' => 'name', 'reset_columns' => '1']);
    $this->assertSame(['sort' => 'name'], $target['query']);
  }

  /**
   * Unknown, wrong-type and inaccessible datasets.
   */
  public function testAccess(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->apply([], '00000000-0000-4000-8000-000000000000');
  }

  /**
   * A distribution node is not a dataset.
   */
  public function testWrongType(): void {
    $info = $this->container->get('dkan.common.dataset_info')->gather($this->uuid);
    $distributionUuid = $info['latest_revision']['distributions'][0]['distribution_uuid'];
    $this->expectException(NotFoundHttpException::class);
    $this->apply([], $distributionUuid);
  }

  /**
   * The controller re-checks node view access for the current user.
   */
  public function testNoPermission(): void {
    $this->setUpCurrentUser([], []);
    $this->expectException(AccessDeniedHttpException::class);
    $this->apply([]);
  }

}
