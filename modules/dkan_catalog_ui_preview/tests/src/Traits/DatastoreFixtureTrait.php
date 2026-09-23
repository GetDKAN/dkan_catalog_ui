<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Traits;

use Drupal\dkan_common\DataResource;
use Drupal\dkan_datastore\Service\ResourceLocalizer;
use Drupal\node\NodeInterface;
use Procrastinator\Result;

/**
 * Imports the sample CSV into a datastore table for kernel tests.
 */
trait DatastoreFixtureTrait {

  /**
   * Path of the sample CSV (30 rows: name, age, city).
   */
  protected function fixturePath(): string {
    return dirname(__DIR__, 2) . '/data/preview_sample.csv';
  }

  /**
   * Import the fixture as a standalone resource.
   *
   * @return string
   *   The resource id ("identifier__version").
   */
  protected function importFixture(): string {
    $path = $this->fixturePath();
    $source = new DataResource($path, 'text/csv', DataResource::DEFAULT_SOURCE_PERSPECTIVE);
    $this->container->get('dkan.metastore.resource_mapper')->register($source);
    return $this->importLocalCopy($source);
  }

  /**
   * Import the fixture as the table of a dataset's distribution.
   *
   * @return string
   *   The resource id ("identifier__version").
   */
  protected function importDatasetFixture(NodeInterface $node, int $index = 0): string {
    $info = $this->container->get('dkan.common.dataset_info')->gather($node->uuid());
    $revision = $info['published_revision'] ?? $info['latest_revision'];
    $dist = $revision['distributions'][$index];
    $source = $this->container->get('dkan.metastore.resource_mapper')
      ->get($dist['resource_id'], DataResource::DEFAULT_SOURCE_PERSPECTIVE, $dist['resource_version']);
    return $this->importLocalCopy($source);
  }

  /**
   * Register a local-file perspective pointing at the fixture and import it.
   */
  protected function importLocalCopy(DataResource $source): string {
    $mapper = $this->container->get('dkan.metastore.resource_mapper');
    $local = $source->createNewPerspective(ResourceLocalizer::LOCAL_FILE_PERSPECTIVE, $this->fixturePath());
    $mapper->registerNewPerspective($local);

    $importJob = $this->container->get('dkan.datastore.service.factory.import')
      ->getInstance($local->getUniqueIdentifier(), ['resource' => $local])
      ->getImporter();
    $importResult = $importJob->run();
    $this->assertEquals(Result::DONE, $importResult->getStatus(), $importResult->getError() ?? '');

    return $source->getIdentifier() . '__' . $source->getVersion();
  }

}
