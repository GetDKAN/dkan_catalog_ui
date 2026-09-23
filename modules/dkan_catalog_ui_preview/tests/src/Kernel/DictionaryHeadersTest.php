<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Kernel;

use Drupal\Tests\dkan_catalog_ui\Kernel\CatalogUiKernelTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests data dictionary titles on table headers.
 */
#[Group('dkan_catalog_ui_preview')]
class DictionaryHeadersTest extends CatalogUiKernelTestBase {

  use DatastoreFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui_preview'];

  /**
   * Set the data dictionary mode (the discovery service reads it once).
   */
  protected function setDictionaryMode(string $mode, ?string $sitewide = NULL): void {
    $config = $this->config('dkan_metastore.settings')->set('data_dictionary_mode', $mode);
    if ($sitewide !== NULL) {
      $config->set('data_dictionary_sitewide', $sitewide);
    }
    $config->save();
    $this->container = $this->container->get('kernel')->rebuildContainer();
  }

  /**
   * Post a data dictionary through the metastore.
   */
  protected function postDictionary(string $identifier): void {
    $metastore = $this->container->get('dkan.metastore.service');
    $dictionary = $metastore->getValidMetadataFactory()->get(json_encode([
      'identifier' => $identifier,
      'data' => [
        'title' => 'Survey dictionary',
        'fields' => [
          ['name' => 'age', 'title' => 'Age (years)', 'type' => 'integer', 'description' => 'Age at survey'],
          ['name' => 'city', 'type' => 'string'],
          ['name' => 'nope', 'title' => 'Unused', 'type' => 'string'],
        ],
      ],
    ]), 'data-dictionary');
    $metastore->post('data-dictionary', $dictionary);
  }

  /**
   * Render the table for a resource id.
   */
  protected function renderTable(string $resourceId): string {
    $build = ['#type' => 'dkan_catalog_ui_preview', '#resource_id' => $resourceId];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Sitewide mode: titled fields get the title, others keep their header.
   */
  public function testSitewide(): void {
    $resourceId = $this->importFixture();
    $this->postDictionary('dict-1');
    $this->setDictionaryMode('sitewide', 'dict-1');

    $headers = $this->container->get('dkan_catalog_ui.preview.dictionary_headers');
    $fields = $this->container->get('dkan_catalog_ui.preview.data_source.database')->getSchema($resourceId)['fields'];
    $labels = $headers->labels($resourceId, $fields);
    $this->assertSame(['title' => 'Age (years)', 'description' => 'Age at survey', 'type' => 'integer'], $labels['age']);
    // Untitled but typed: the type is kept, the label falls back.
    $this->assertSame(['title' => '', 'description' => '', 'type' => 'string'], $labels['city']);
    $this->assertCount(2, $labels);
    $this->assertNotEmpty($headers->cacheTags($resourceId));

    $html = $this->renderTable($resourceId);
    $this->assertStringContainsString('title="Age at survey"', $html);
    $this->assertStringContainsString('>Age (years)</a>', $html);
    $this->assertStringContainsString('aria-label="Sort by Age (years), ascending"', $html);
    $this->assertStringContainsString('data-column="age"', $html);
    $this->assertStringContainsString('>city</a>', $html);
  }

  /**
   * Reference mode: the distribution's describedBy names the dictionary.
   */
  public function testReference(): void {
    $this->postDictionary('dict-3');
    $node = $this->createDataset('dict-ref', [
      'distribution' => [
        [
          'title' => 'Tabular',
          'downloadURL' => 'http://example.com/data.csv',
          'mediaType' => 'text/csv',
          'describedBy' => 'dkan://metastore/schemas/data-dictionary/items/dict-3',
          'describedByType' => 'application/vnd.tableschema+json',
        ],
      ],
    ]);
    $resourceId = $this->importDatasetFixture($node);
    $this->setDictionaryMode('reference');

    $headers = $this->container->get('dkan_catalog_ui.preview.dictionary_headers');
    $fields = $this->container->get('dkan_catalog_ui.preview.data_source.database')->getSchema($resourceId)['fields'];
    $labels = $headers->labels($resourceId, $fields);
    $this->assertSame(['title' => 'Age (years)', 'description' => 'Age at survey', 'type' => 'integer'], $labels['age']);
    // Untitled but typed: the type is kept, the label falls back.
    $this->assertSame(['title' => '', 'description' => '', 'type' => 'string'], $labels['city']);
    $this->assertCount(2, $labels);
    $this->assertNotEmpty($headers->cacheTags($resourceId));
    $this->assertStringContainsString('>Age (years)</a>', $this->renderTable($resourceId));

    // A distribution without describedBy has no dictionary (a distinct
    // downloadURL gives it its own resource).
    $plain = $this->createDataset('dict-plain', [
      'distribution' => [
        ['title' => 'Plain', 'downloadURL' => 'http://example.com/plain.csv', 'mediaType' => 'text/csv'],
      ],
    ]);
    $plainId = $this->importDatasetFixture($plain);
    $this->assertSame([], $headers->labels($plainId, $fields));
    $this->assertSame([], $headers->cacheTags($plainId));
  }

  /**
   * Mode "none": machine names, no dictionary lookups.
   */
  public function testNone(): void {
    $resourceId = $this->importFixture();
    $this->postDictionary('dict-2');
    $this->setDictionaryMode('none');

    $headers = $this->container->get('dkan_catalog_ui.preview.dictionary_headers');
    $this->assertSame([], $headers->labels($resourceId, ['age' => ['type' => 'text']]));
    $this->assertSame([], $headers->cacheTags($resourceId));
    $html = $this->renderTable($resourceId);
    $this->assertStringNotContainsString('Age (years)', $html);
    $this->assertStringContainsString('>age</a>', $html);
  }

}
