<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\dkan_catalog_ui\Alias\DatasetAliasManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests /dataset/{uuid} aliases on dataset nodes.
 */
#[Group('dkan_catalog_ui')]
class DatasetAliasTest extends CatalogUiKernelTestBase {

  /**
   * Saving a dataset through the metastore creates the alias.
   */
  public function testAliasOnSave(): void {
    $node = $this->createDataset('alias-test');
    $aliasManager = $this->container->get('path_alias.manager');
    $this->assertSame('/dataset/alias-test', $aliasManager->getAliasByPath('/node/' . $node->id()));
    $this->assertSame('/node/' . $node->id(), $aliasManager->getPathByAlias('/dataset/alias-test'));

    // Distribution nodes are left alone.
    $distributions = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['field_data_type' => 'distribution']);
    $distribution = reset($distributions);
    $this->assertSame('/node/' . $distribution->id(), $aliasManager->getAliasByPath('/node/' . $distribution->id()));
  }

  /**
   * Backfill creates aliases only where they are missing.
   */
  public function testBackfill(): void {
    $node = $this->createDataset('backfill-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    foreach ($storage->loadByProperties(['path' => '/node/' . $node->id()]) as $alias) {
      $alias->delete();
    }
    $this->container->get('path_alias.manager')->cacheClear();

    /** @var \Drupal\dkan_catalog_ui\Alias\DatasetAliasManagerInterface $manager */
    $manager = $this->container->get(DatasetAliasManagerInterface::class);
    $this->assertSame(1, $manager->backfill());
    $this->assertSame(0, $manager->backfill());

    $aliases = $storage->loadByProperties(['path' => '/node/' . $node->id()]);
    $this->assertCount(1, $aliases);
    $this->assertSame('/dataset/backfill-test', reset($aliases)->getAlias());
  }

}
