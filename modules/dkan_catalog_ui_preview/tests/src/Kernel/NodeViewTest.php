<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\dkan_catalog_ui_preview\Hook\DataPreviewHooks;
use Drupal\node\NodeInterface;
use Drupal\Tests\dkan_catalog_ui\Kernel\CatalogUiKernelTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the data table on dataset node views.
 */
#[Group('dkan_catalog_ui_preview')]
class NodeViewTest extends CatalogUiKernelTestBase {

  use DatastoreFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui_preview'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // hook_install() ran before the display config existed; enable the
    // extra field the way the install hook does on a real site.
    EntityViewDisplay::load('node.data.default')
      ->setComponent(DataPreviewHooks::EXTRA_FIELD, ['weight' => 100])
      ->save();
  }

  /**
   * Build (but do not render) the full node view.
   */
  protected function buildNodeView(NodeInterface $node): array {
    $viewBuilder = $this->container->get('entity_type.manager')->getViewBuilder('node');
    $build = $viewBuilder->view($node, 'full');
    return $viewBuilder->build($build);
  }

  /**
   * Datasets with a tabular distribution get a lazy-builder placeholder.
   */
  public function testDatasetPlaceholder(): void {
    $node = $this->createDataset('preview-test');
    $build = $this->buildNodeView($node);

    $this->assertArrayHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
    $element = $build[DataPreviewHooks::EXTRA_FIELD];
    $this->assertSame(100, $element['#weight']);
    $this->assertSame(['dkan_catalog_ui.preview.builder:lazyBuild', [(int) $node->id()]], $element['#lazy_builder']);
    $this->assertTrue($element['#create_placeholder']);
    // No query-dependent cache metadata reaches the node render cache.
    $this->assertArrayNotHasKey('contexts', $element['#cache']);
    $this->assertArrayNotHasKey('max-age', $element['#cache']);

    // Dataset and distribution node tags are both attached.
    $tags = $element['#cache']['tags'];
    $this->assertContains('node:' . $node->id(), $tags);
    $info = $this->container->get('dkan.common.dataset_info')->gather($node->uuid());
    $distributionUuid = $info['latest_revision']['distributions'][0]['distribution_uuid'];
    $distributionNodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uuid' => $distributionUuid]);
    $this->assertContains('node:' . reset($distributionNodes)->id(), $tags);
  }

  /**
   * Rendering replaces the placeholder: status message before import.
   */
  public function testRenderBeforeImport(): void {
    $node = $this->createDataset('preview-pending');
    $build = $this->buildNodeView($node);
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('dcu-table__message', $html);
    $this->assertStringContainsString('Data preview is not yet available.', $html);
    $this->assertStringNotContainsString('dcu-table__table', $html);
    // The download link renders without a table; the panels do not.
    $this->assertStringContainsString('dcu-table__download-original" href="http://example.com/data.csv"', $html);
    $this->assertStringNotContainsString('dcu-table__panel', $html);
    // The toolbar closes before the message: nothing nests inside it.
    $xpath = new \DOMXPath(Html::load($html));
    $this->assertCount(0, $xpath->query('//div[@class="dcu-table__toolbar"]//p[contains(@class, "dcu-table__message")]'));
    $this->assertCount(1, $xpath->query('//div[@class="dcu-table__toolbar"]'));
    // The file row names the file and links the download; without a table
    // there are no tools and no status.
    // Only the original exists, so it is a direct link, not a disclosure.
    $file = $xpath->query('//div[@class="dcu-table__file"]/*');
    $this->assertCount(2, $file);
    $this->assertSame('dcu-table__identity', $file->item(0)->getAttribute('class'));
    $this->assertStringContainsString('data.csv', $file->item(0)->textContent);
    $this->assertSame('dcu-button dcu-table__download dcu-table__download-original', $file->item(1)->getAttribute('class'));
    $this->assertStringContainsString('Download (CSV)', $file->item(1)->textContent);
    $this->assertCount(0, $xpath->query('//div[@class="dcu-table__tools"]'));
    $this->assertCount(0, $xpath->query('//div[@class="dcu-table__status"]'));
    $this->assertCount(0, $xpath->query('//form[@class="dcu-table__page-size"]'));
    $this->assertStringContainsString('href="#data-table"', $html);
  }

  /**
   * No accepted download URL and no table: the file row names the file.
   */
  public function testRenderWithoutDownload(): void {
    $node = $this->createDataset('preview-no-download', [
      'distribution' => [
        [
          'title' => 'Tabular',
          'downloadURL' => 'ftp://example.com/data.csv',
          'mediaType' => 'text/csv',
          'format' => 'csv',
        ],
      ],
    ]);
    $build = $this->buildNodeView($node);
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('dcu-table__message', $html);
    $this->assertStringNotContainsString('dcu-table__download', $html);
    $xpath = new \DOMXPath(Html::load($html));
    $this->assertCount(1, $xpath->query('//div[@class="dcu-table__toolbar"]'));
    // The file row still names the file; nothing else renders.
    $this->assertCount(1, $xpath->query('//div[@class="dcu-table__file"]/div[@class="dcu-table__identity"]/p[@class="dcu-table__caption"]'));
    $this->assertCount(1, $xpath->query('//div[@class="dcu-table__file"]/*'));
    $this->assertCount(0, $xpath->query('//div[@class="dcu-table__tools"]'));
  }

  /**
   * Rendering after import: table, links and form on the dataset URL.
   */
  public function testRenderAfterImport(): void {
    $node = $this->createDataset('preview-imported');
    $this->importDatasetFixture($node);
    $this->container->get('request_stack')->getCurrentRequest()->query->replace(['sort' => 'age', 'direction' => 'desc']);

    $build = $this->buildNodeView($node);
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('id="dcu-table"', $html);
    $this->assertStringContainsString('<p class="dcu-table__caption" id="dcu-table-caption"><span class="dcu-table__file-label">Data file</span> data.csv</p>', $html);
    $this->assertStringContainsString('aria-labelledby="dcu-table-caption"', $html);
    $this->assertStringNotContainsString('<caption>', $html);
    $this->assertStringContainsString('Rows 1–25 of 30', $html);
    $this->assertStringContainsString('aria-sort="descending"', $html);
    // Links and the form target the dataset alias and the apply route.
    $this->assertStringContainsString('href="/dataset/preview-imported?sort=name"', $html);
    $this->assertStringContainsString('href="/dataset/preview-imported?page=2&amp;sort=age&amp;direction=desc"', $html);
    $this->assertStringContainsString('action="/dataset/preview-imported/table/apply"', $html);
    $this->assertStringContainsString('name="sort" value="age"', $html);
    $this->assertStringContainsString('name="direction" value="desc"', $html);
    $this->assertStringContainsString('class="dcu-table__download-option dcu-table__download-original" href="http://example.com/data.csv"', $html);
    $this->assertStringContainsString('class="dcu-table__download-option dcu-table__download-results" href="/api/1/datastore/query/', $html);
    $this->assertStringNotContainsString('dcu-table__chooser', $html);
    $this->assertMatchesRegularExpression('/<tbody[^>]*>.*?<td>69<\/td>/s', $html);
    // Toolbar rows in order: file (identity then download), tools (the
    // panels), status (the summary); footer: page size then pager.
    $xpath = new \DOMXPath(Html::load($html));
    $rows = $xpath->query('//div[@class="dcu-table__toolbar"]/*');
    $this->assertCount(3, $rows);
    $this->assertSame('dcu-table__file', $rows->item(0)->getAttribute('class'));
    $this->assertSame('dcu-table__tools', $rows->item(1)->getAttribute('class'));
    $this->assertSame('dcu-table__status', $rows->item(2)->getAttribute('class'));
    $file = $xpath->query('//div[@class="dcu-table__file"]/*');
    $this->assertCount(2, $file);
    $this->assertSame('dcu-table__identity', $file->item(0)->getAttribute('class'));
    $this->assertSame('CSV · 3 columns', $xpath->query('//p[@class="dcu-table__meta"]')->item(0)->textContent);
    $this->assertSame('dcu-table-download', $file->item(1)->getAttribute('id'));
    $tools = $xpath->query('//div[@class="dcu-table__tools"]/details');
    $this->assertSame(['dcu-table-filters', 'dcu-table-columns', 'dcu-table-display', 'dcu-table-share'], array_map(fn ($d) => $d->getAttribute('id'), iterator_to_array($tools)));
    // View is JS-only.
    $this->assertTrue($tools->item(2)->hasAttribute('hidden'));
    $this->assertCount(1, $xpath->query('//div[@class="dcu-table__status"]/p[@class="dcu-table__summary"]'));
    $footer = $xpath->query('//div[@class="dcu-table__footer"]/*');
    $this->assertSame('dcu-table__page-size', $footer->item(0)->getAttribute('class'));
    $this->assertCount(0, $xpath->query('//form[@class="dcu-table__page-size"]//input[@name="panel" or @name="page" or @name="page_size"]'));
  }

  /**
   * A disabled display component renders nothing.
   */
  public function testDisabledComponent(): void {
    EntityViewDisplay::load('node.data.default')
      ->removeComponent(DataPreviewHooks::EXTRA_FIELD)
      ->save();
    $node = $this->createDataset('preview-disabled');
    $build = $this->buildNodeView($node);
    $this->assertArrayNotHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
  }

  /**
   * Distribution nodes get no table.
   */
  public function testNonDatasetNode(): void {
    $this->createDataset('preview-nondataset');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['field_data_type' => 'distribution']);
    $this->assertNotEmpty($nodes);
    $build = $this->buildNodeView(reset($nodes));
    $this->assertArrayNotHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
  }

  /**
   * Datasets without a tabular distribution get no tab.
   */
  public function testNoTabularDistribution(): void {
    $node = $this->createDataset('preview-pdf-only', [
      'distribution' => [
        ['title' => 'Document', 'downloadURL' => 'http://example.com/doc.pdf', 'mediaType' => 'application/pdf'],
      ],
    ]);
    $build = $this->buildNodeView($node);
    $this->assertArrayNotHasKey(DataPreviewHooks::EXTRA_FIELD, $build);
  }

}
