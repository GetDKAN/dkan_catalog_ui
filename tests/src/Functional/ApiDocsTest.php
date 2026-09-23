<?php

namespace Drupal\Tests\dkan_catalog_ui\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the /api/docs page over HTTP.
 */
#[Group('dkan_catalog_ui')]
class ApiDocsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous users get the reference with its title and breadcrumb.
   */
  public function testPage(): void {
    $this->drupalPlaceBlock('system_breadcrumb_block');
    $this->drupalGet('/api/docs');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->titleEquals('API | Drupal');
    $assert->elementExists('css', '.dcu-api-docs__group');
    $assert->linkByHrefExists('/api/1.yml');
    $assert->elementExists('css', '.dcu-endpoint__method--get');
    // Stark's breadcrumb markup: the block's own nav landmark.
    $assert->elementTextContains('css', 'nav[aria-labelledby="system-breadcrumb"]', 'Home');
    // DKAN's own routes are untouched.
    $this->drupalGet('/api');
    $assert->statusCodeEquals(200);
    $assert->responseContains('"version"');
  }

}
