<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\dkan_catalog_ui\DatasetPage\OverviewBuilder;
use Drupal\dkan_catalog_ui\Form\SettingsForm;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Overview metadata table follows dkan_catalog_ui.settings.
 */
#[Group('dkan_catalog_ui')]
class OverviewSettingsTest extends CatalogUiKernelTestBase {

  /**
   * Metadata table rows (label => value) for a dataset, plus cache tags.
   *
   * @return array{0: array<string, string>, 1: string[]}
   *   Rows and the build's cache tags.
   */
  protected function overview(string $identifier): array {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uuid' => $identifier]);
    $node = reset($nodes);
    $metadata = $this->container->get('dkan_catalog_ui.dataset_metadata')->load($node);
    $build = $this->container->get('dkan_catalog_ui.overview_builder')->build($node, $metadata);
    $rows = array_column($build['metadata']['#props']['rows'] ?? [], 'value', 'label');
    return [$rows, $build['#cache']['tags']];
  }

  /**
   * Saves the settings.
   *
   * @param string[] $properties
   *   Overview properties.
   * @param bool $others
   *   Whether to append other schema properties.
   */
  protected function settings(array $properties, bool $others = FALSE): void {
    $this->config('dkan_catalog_ui.settings')
      ->set('overview_properties', $properties)
      ->set('overview_other_properties', $others)
      ->save();
  }

  /**
   * Listed properties show in list order; others only with the flag.
   */
  public function testPropertyList(): void {
    $this->createDataset('settings-test', [
      'isPartOf' => 'collection-1',
      'references' => ['https://example.com/related'],
    ]);

    // No config yet (as before updb): the built-in default list.
    [$rows, $tags] = $this->overview('settings-test');
    $this->assertSame('Last Update', array_key_first($rows));
    $this->assertArrayNotHasKey('Collection', $rows);
    $this->assertContains('config:dkan_catalog_ui.settings', $tags);

    // A property not in the metadata is skipped.
    $this->settings(['license', 'modified', 'notInMetadata']);
    [$rows] = $this->overview('settings-test');
    $this->assertSame(['License', 'Last Update'], array_keys($rows));

    // Other schema properties follow, in schema order; title, description
    // and distribution never do.
    $this->settings(['license'], TRUE);
    [$rows] = $this->overview('settings-test');
    $labels = array_keys($rows);
    $this->assertSame('License', $labels[0]);
    $this->assertSame('collection-1', $rows['Collection']);
    $this->assertSame('https://example.com/related', $rows['Related Documents']);
    $this->assertLessThan(array_search('Related Documents', $labels), array_search('Collection', $labels));
    $this->assertContains('Unique Identifier', $labels);
    $this->assertNotContains('Title', $labels);
    $this->assertNotContains('Description', $labels);
    $this->assertNotContains('Distribution', $labels);
    $this->assertCount(count(array_unique($labels)), $labels);
  }

  /**
   * The post-update creates the shipped config once.
   */
  public function testPostUpdate(): void {
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'php', 'dkan_catalog_ui.post_update');
    $this->assertStringStartsWith('Created', dkan_catalog_ui_post_update_overview_settings());
    $config = $this->config('dkan_catalog_ui.settings');
    $this->assertSame(OverviewBuilder::DEFAULT_PROPERTIES, $config->get('overview_properties'));
    $this->assertFalse($config->get('overview_other_properties'));

    $this->settings(['license']);
    $this->assertStringEndsWith('nothing changed.', dkan_catalog_ui_post_update_overview_settings());
    $this->assertSame(['license'], $this->config('dkan_catalog_ui.settings')->get('overview_properties'));
  }

  /**
   * The form saves one trimmed, unique property per line.
   */
  public function testForm(): void {
    $form_state = (new FormState())->setValues([
      'overview_properties' => " license\r\n\nmodified \nlicense\nreferences",
      'overview_other_properties' => 1,
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('dkan_catalog_ui.settings');
    $this->assertSame(['license', 'modified', 'references'], $config->get('overview_properties'));
    $this->assertTrue($config->get('overview_other_properties'));
  }

}
