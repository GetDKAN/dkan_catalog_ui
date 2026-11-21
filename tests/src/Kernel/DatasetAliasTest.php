<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\dkan_catalog_ui\Alias\DatasetAliasManagerInterface;
use Drupal\node\NodeInterface;
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
   * An editor-entered alias is replaced on save.
   */
  public function testEditorAliasIsOverwritten(): void {
    $node = $this->createDataset('forced-test');
    $node->set('path', ['alias' => '/my-dataset'])->save();
    $aliasManager = $this->container->get('path_alias.manager');
    $aliasManager->cacheClear();
    $this->assertSame('/dataset/forced-test', $aliasManager->getAliasByPath('/node/' . $node->id()));
  }

  /**
   * Managed aliases are language-neutral; other aliases keep their language.
   */
  public function testAliasLanguage(): void {
    $node = $this->createDataset('lang-test');
    $storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    $langcodes = fn (string $alias) => array_values(array_map(
      fn ($pathAlias) => $pathAlias->get('langcode')->value,
      $storage->loadByProperties(['alias' => $alias]),
    ));
    $this->assertSame([LanguageInterface::LANGCODE_NOT_SPECIFIED], $langcodes('/dataset/lang-test'));

    // A later dataset save keeps one neutral managed alias.
    $node->setNewRevision(FALSE);
    $node->save();
    $this->assertSame([LanguageInterface::LANGCODE_NOT_SPECIFIED], $langcodes('/dataset/lang-test'));

    // An alias under /dataset/ that is not the managed one keeps its language.
    $storage->create([
      'path' => '/node/' . $node->id() . '/revisions',
      'alias' => '/dataset/hand-made',
      'langcode' => 'en',
    ])->save();

    // The post-update migrates aliases written before this change, once.
    foreach (['path_alias', 'path_alias_revision'] as $table) {
      $this->container->get('database')->update($table)
        ->fields(['langcode' => 'en'])
        ->condition('alias', '/dataset/lang-test')
        ->execute();
    }
    $storage->resetCache();
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'php', 'dkan_catalog_ui.post_update');
    $sandbox = [];
    $this->assertSame('Dataset aliases made language-neutral: 1.', dkan_catalog_ui_post_update_language_neutral_aliases($sandbox));
    $this->assertSame(1, $sandbox['#finished']);
    $storage->resetCache();
    $this->assertSame([LanguageInterface::LANGCODE_NOT_SPECIFIED], $langcodes('/dataset/lang-test'));
    $this->assertSame(['en'], $langcodes('/dataset/hand-made'));
    $sandbox = [];
    $this->assertSame('Dataset aliases made language-neutral: 0.', dkan_catalog_ui_post_update_language_neutral_aliases($sandbox));
  }

  /**
   * A translatable dataset keeps per-language aliases.
   */
  public function testTranslatableLangcode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('isTranslatable')->willReturn(TRUE);
    $node->method('language')->willReturn(new Language(['id' => 'fr']));
    $this->assertSame('fr', $this->container->get(DatasetAliasManagerInterface::class)->langcodeFor($node));
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
