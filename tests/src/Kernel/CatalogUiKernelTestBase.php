<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;

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
   * Install the search index, the format index field and the module config.
   *
   * Needs views, search_api, search_api_db, facets and dkan_metastore_search
   * in $modules. Rebuilds the container afterwards: saving a facet whose
   * source view caches permanently pre-executes the search query, which
   * leaves the facets manager's static cache holding only the facets saved
   * so far in this process.
   */
  protected function installSearchConfig(): void {
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');
    \Drupal::state()->set('search_api_use_tracking_batch', FALSE);
    $this->installConfig(['search_api', 'dkan_metastore_search']);
    // hook_install() does not run in kernel tests.
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'install');
    dkan_catalog_ui_ensure_format_index_field();
    $this->installConfig(['dkan_catalog_ui']);
    // Cards check node access; anonymous needs the permission a site grants.
    $this->installConfig(['user']);
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();
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
