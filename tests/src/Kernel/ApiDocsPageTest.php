<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\dkan_catalog_ui\Controller\ApiDocsController;
use Drupal\dkan_common\DkanApiDocsGenerator;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the /api/docs page.
 */
#[Group('dkan_catalog_ui')]
class ApiDocsPageTest extends CatalogUiKernelTestBase {

  /**
   * The page renders the document links and grouped endpoints.
   */
  public function testPage(): void {
    $build = ApiDocsController::create($this->container)->page();
    $this->assertSame(ApiDocsController::MAX_AGE, $build['#cache']['max-age']);
    $props = $build['#props'];
    $this->assertSame('/api/1', $props['docs_url']);
    $this->assertSame(['/api/1', '/api/1.yml'], array_column($props['links'], 'url'));
    $this->assertNotEmpty($props['groups']);
    $paths = [];
    foreach ($props['groups'] as $group) {
      $this->assertNotEmpty($group['title']);
      foreach ($group['endpoints'] as $endpoint) {
        $paths[] = $endpoint['path'];
        $this->assertArrayHasKey('example_url', $endpoint);
      }
    }
    $this->assertContains('/api/1/metastore/schemas/{schema_id}/items', $paths);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('href="/api/1.yml"', $html);
    $this->assertStringContainsString('class="dcu-api-docs__group-title"', $html);
    $this->assertStringContainsString('programmatic access to the catalog', $html);
    $this->assertStringNotContainsString('this dataset', $html);
  }

  /**
   * A failing generator renders the empty state, uncached, and logs.
   */
  public function testUnavailable(): void {
    $generator = $this->createMock(DkanApiDocsGenerator::class);
    $generator->method('buildSpec')->willThrowException(new \RuntimeException('boom'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');
    $controller = new ApiDocsController($generator, $this->container->get('dkan_catalog_ui.openapi_reference'), $logger);

    $build = $controller->page();
    $this->assertSame(0, $build['#cache']['max-age']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('API documentation is unavailable.', $html);
    $this->assertStringContainsString('href="/api/1"', $html);
  }

}
