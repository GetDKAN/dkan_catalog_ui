<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;

/**
 * Shared setup for catalog UI kernel tests: a working metastore.
 */
abstract class CatalogUiKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'user',
    'field',
    'filter',
    'text',
    'path',
    'path_alias',
    'content_moderation',
    'workflows',
    'dkan_common',
    'dkan_metastore',
    'dkan_datastore',
    'dkan_catalog_ui',
    'dkan',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('system');
    $this->installConfig('node');
    $this->installConfig('field');
    $this->installConfig('dkan_common');
    $this->installConfig('dkan_metastore');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('resource_mapping');
  }

  /**
   * Post a dataset through the metastore and return its node.
   */
  protected function createDataset(string $identifier, array $overrides = []): NodeInterface {
    /** @var \Drupal\dkan_metastore\MetastoreService $metastore */
    $metastore = $this->container->get('dkan.metastore.service');
    $metadata = $metastore->getValidMetadataFactory()->get(json_encode($overrides + [
      'title' => 'Catalog Test Dataset',
      'identifier' => $identifier,
      'keyword' => ['bikes', 'streets'],
      'theme' => ['Transportation'],
      'description' => 'A <strong>test</strong> description.',
      'modified' => '2020-01-15',
      'issued' => '2019-06-01',
      'accessLevel' => 'public',
      'license' => 'http://opendatacommons.org/licenses/by/1.0/',
      'publisher' => ['name' => 'Test Publisher'],
      'contactPoint' => ['fn' => 'Jane Doe', 'hasEmail' => 'mailto:jane@example.com'],
      'distribution' => [
        [
          'title' => 'Tabular',
          'downloadURL' => 'http://example.com/data.csv',
          'mediaType' => 'text/csv',
          'format' => 'csv',
        ],
        [
          'title' => 'Document',
          'downloadURL' => 'http://example.com/doc.pdf',
          'mediaType' => 'application/pdf',
        ],
      ],
    ]), 'dataset');
    $metastore->post('dataset', $metadata);

    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uuid' => $identifier]);
    return reset($nodes);
  }

}
