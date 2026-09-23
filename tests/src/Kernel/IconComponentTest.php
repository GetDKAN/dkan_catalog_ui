<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use PHPUnit\Framework\Attributes\Group;
use Twig\Error\RuntimeError;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders every icon in the icon component's enum.
 */
#[Group('dkan_catalog_ui')]
class IconComponentTest extends CatalogUiKernelTestBase {

  /**
   * Each enum value renders one path; the label switches the ARIA role.
   */
  public function testIcons(): void {
    $definition = Yaml::parseFile($this->container->get('extension.list.module')->getPath('dkan_catalog_ui') . '/components/icon/icon.component.yml');
    $names = $definition['props']['properties']['name']['enum'];
    $this->assertNotEmpty($names);

    $renderer = $this->container->get('renderer');
    foreach ($names as $name) {
      $build = [
        '#type' => 'component',
        '#component' => 'dkan_catalog_ui:icon',
        '#props' => ['name' => $name],
      ];
      $html = (string) $renderer->renderInIsolation($build);
      $this->assertStringContainsString('class="dcu-icon dcu-icon--' . $name . '"', $html, $name);
      $this->assertStringContainsString('aria-hidden="true"', $html, $name);
      $this->assertSame(1, substr_count($html, '<path d="M'), $name);
      $this->assertStringNotContainsString('d=""', $html, $name);
      $this->assertStringNotContainsString(' width="', $html, $name);
    }

    $build = [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:icon',
      '#props' => ['name' => 'close', 'size' => 12, 'label' => 'Close'],
    ];
    $html = (string) $renderer->renderInIsolation($build);
    $this->assertStringContainsString('role="img" aria-label="Close"', $html);
    $this->assertStringContainsString('width="12" height="12" style="--dcu-icon-size: 12px"', $html);
    $this->assertStringNotContainsString('aria-hidden', $html);
  }

  /**
   * An unknown name fails prop validation (PHP assertions on, as in tests).
   */
  public function testUnknownName(): void {
    $build = [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui:icon',
      '#props' => ['name' => 'nope'],
    ];
    try {
      $this->container->get('renderer')->renderInIsolation($build);
      $this->fail('No exception.');
    }
    catch (RuntimeError $e) {
      // Twig wraps the validator's exception.
      $this->assertInstanceOf(InvalidComponentException::class, $e->getPrevious());
      $this->assertStringContainsString('"nope"', $e->getMessage());
    }
  }

}
