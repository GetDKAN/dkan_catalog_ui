<?php

namespace Drupal\Tests\dkan_catalog_ui\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\dkan_catalog_ui\Hook\EntityHooks;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Field;
use PHPUnit\Framework\Attributes\Group;

/**
 * Install and uninstall hooks create and remove exactly their own state.
 */
#[Group('dkan_catalog_ui')]
class InstallUninstallTest extends CatalogUiKernelTestBase {

  const SECTION_FIELDS = [
    EntityHooks::FIELD_HEADER,
    EntityHooks::FIELD_PREVIEW,
    EntityHooks::FIELD_OVERVIEW,
    EntityHooks::FIELD_DATA_DICTIONARY,
    EntityHooks::FIELD_API,
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
    'search_api',
    'search_api_db',
    'facets',
    'dkan_metastore_search',
    'link',
    'menu_link_content',
    'dkan_catalog_ui_preview',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('menu_link_content');
    $this->installSearchConfig();
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'install');
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui', 'php', 'dkan_catalog_ui.post_update');
    \Drupal::moduleHandler()->loadInclude('dkan_catalog_ui_preview', 'install');
  }

  /**
   * Install orders the display, backfills aliases and removes the legacy link.
   */
  public function testInstall(): void {
    // installSearchConfig() added the index field through the helper.
    $this->assertTrue(\Drupal::state()->get(EntityHooks::FORMAT_FIELD_STATE_KEY));

    MenuLinkContent::create([
      'title' => 'Datasets',
      'link' => ['uri' => 'internal:/search'],
      'menu_name' => 'main',
    ])->save();
    $node = $this->createDataset('install-test');
    $aliasStorage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    foreach ($aliasStorage->loadByProperties(['path' => '/node/' . $node->id()]) as $alias) {
      $alias->delete();
    }

    dkan_catalog_ui_install();
    dkan_catalog_ui_preview_install();

    $this->assertCount(0, MenuLinkContent::loadMultiple());
    $this->assertCount(1, $aliasStorage->loadByProperties(['alias' => '/dataset/install-test']));
    $display = EntityViewDisplay::load('node.data.default');
    $weights = [];
    foreach (self::SECTION_FIELDS as $field) {
      $weights[$field] = $display->getComponent($field)['weight'];
    }
    $this->assertSame([0, 10, 20, 30, 40], array_values($weights));
    $this->assertNull($display->getComponent(EntityHooks::FIELD_CARD));
  }

  /**
   * Uninstall removes managed aliases, the owned index field and components.
   */
  public function testUninstall(): void {
    $node = $this->createDataset('uninstall-test');
    dkan_catalog_ui_install();
    dkan_catalog_ui_preview_install();
    $aliasStorage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    // An unrelated alias under the prefix, pointing at another node.
    $distributions = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['field_data_type' => 'distribution']);
    $aliasStorage->create([
      'path' => '/node/' . reset($distributions)->id(),
      'alias' => '/dataset/hand-made',
    ])->save();
    // A managed alias whose node later stopped being a dataset.
    $node->set('field_data_type', 'other')->save();
    $this->assertCount(1, $aliasStorage->loadByProperties(['alias' => '/dataset/uninstall-test']));

    // Sites installed before the ownership flag get it from the post-update.
    \Drupal::state()->delete(EntityHooks::FORMAT_FIELD_STATE_KEY);
    $this->assertStringStartsWith('The format index field is now recorded', dkan_catalog_ui_post_update_flag_format_field());

    dkan_catalog_ui_preview_uninstall();
    dkan_catalog_ui_uninstall();

    $this->assertCount(0, $aliasStorage->loadByProperties(['alias' => '/dataset/uninstall-test']));
    $this->assertCount(1, $aliasStorage->loadByProperties(['alias' => '/dataset/hand-made']));
    $this->assertNull(Index::load('dkan')->getField('distribution__item__format'));
    $this->assertNull(\Drupal::state()->get(EntityHooks::FORMAT_FIELD_STATE_KEY));
    $display = EntityViewDisplay::load('node.data.default');
    foreach (self::SECTION_FIELDS as $field) {
      $this->assertNull($display->getComponent($field), $field);
    }
  }

  /**
   * A format field the site added itself survives uninstall.
   */
  public function testUninstallKeepsSiteField(): void {
    $index = Index::load('dkan');
    $index->removeField('distribution__item__format');
    $index->save();
    \Drupal::state()->delete(EntityHooks::FORMAT_FIELD_STATE_KEY);
    $field = new Field($index, 'distribution__item__format');
    $field->setDatasourceId('dkan_dataset');
    $field->setPropertyPath('distribution__item__format');
    $field->setType('string');
    $field->setLabel('Format');
    $index->addField($field);
    $index->save();

    $this->assertStringStartsWith('No module-added', dkan_catalog_ui_post_update_flag_format_field());
    dkan_catalog_ui_uninstall();
    $this->assertNotNull(Index::load('dkan')->getField('distribution__item__format'));
  }

}
