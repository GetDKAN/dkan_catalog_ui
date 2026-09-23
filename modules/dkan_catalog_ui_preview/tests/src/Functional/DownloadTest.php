<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_catalog_ui_preview\Functional;

use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The filtered download link streams the table state as CSV.
 */
#[Group('dkan_catalog_ui_preview')]
class DownloadTest extends BrowserTestBase {

  use DatastoreFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui_preview'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
  }

  /**
   * Create a dataset through the metastore and import the fixture.
   */
  protected function createImportedDataset(string $identifier): NodeInterface {
    $metastore = $this->container->get('dkan.metastore.service');
    $metadata = $metastore->getValidMetadataFactory()->get(json_encode([
      'title' => 'Download Test Dataset',
      'identifier' => $identifier,
      'keyword' => ['test'],
      'description' => 'Test description.',
      'modified' => '2020-01-15',
      'accessLevel' => 'public',
      'distribution' => [
        ['title' => 'Tabular', 'downloadURL' => 'http://example.com/data.csv', 'mediaType' => 'text/csv'],
      ],
    ]), 'dataset');
    $metastore->post('dataset', $metadata);
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uuid' => $identifier]);
    $node = reset($nodes);
    $this->importDatasetFixture($node);
    return $node;
  }

  /**
   * Rows, column order and sort of the streamed CSV follow the state.
   */
  public function testFilteredDownload(): void {
    $this->createImportedDataset('dl-test');
    $query = [
      'conditions' => [['property' => 'name', 'operator' => 'contains', 'value' => '_1']],
      'sort' => 'name',
      'direction' => 'desc',
      'columns' => 'city,name',
      'page_size' => '10',
    ];
    $this->drupalGet('/dataset/dl-test', ['query' => $query]);
    $assert = $this->assertSession();
    $assert->pageTextContains('10 of 30 rows');

    // Both choices sit in the Download disclosure; the original ignores the
    // view state.
    $page = $this->getSession()->getPage();
    $this->assertNotNull($page->find('css', 'details#dcu-table-download a.dcu-table__download-original'));
    $this->assertStringNotContainsString('?', $page->find('css', 'a.dcu-table__download-original')->getAttribute('href'));
    $link = $page->find('css', 'details#dcu-table-download a.dcu-table__download-results');
    $this->assertNotNull($link);
    $href = $link->getAttribute('href');
    $this->assertStringContainsString('/api/1/datastore/query/', $href);
    $this->assertStringContainsString('/download?', $href);
    $this->assertStringContainsString('format=csv', $href);
    // The copy link is the canonical page URL, absolute, without panel.
    $copy = $this->getSession()->getPage()->find('css', '[data-dcu-copy]')->getAttribute('data-dcu-copy');
    $this->assertStringStartsWith($this->baseUrl . '/dataset/dl-test?', $copy);
    $this->assertStringNotContainsString('panel', $copy);
    // Without JavaScript the same link is a selectable field.
    $this->assertSame($copy, $this->getSession()->getPage()->find('css', 'input#dcu-table-share-url')->getAttribute('value'));

    // An absolute URL keeps the query string; a path would be encoded.
    $this->drupalGet($this->getAbsoluteUrl($href));
    $assert->statusCodeEquals(200, $href);
    $this->assertStringStartsWith('text/csv', (string) $this->getSession()->getResponseHeader('Content-Type'));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $this->getSession()->getPage()->getContent()))));
    $this->assertCount(11, $lines);
    // Header cells are the original column names, in the state's order.
    $this->assertSame('city,name', $lines[0]);
    // Only names containing "_1", sorted by name descending.
    $rows = array_map('str_getcsv', array_slice($lines, 1));
    $names = array_column($rows, 1);
    $this->assertSame(10, count(array_filter($names, fn ($n) => str_contains($n, '_1'))));
    $descending = $names;
    rsort($descending);
    $this->assertSame($descending, $names);
    $this->assertSame('person_19', $names[0]);
  }

  /**
   * Current results export every matching row, whatever the page.
   */
  public function testResultsIgnorePaging(): void {
    $this->createImportedDataset('dl-pages');
    $this->drupalGet('/dataset/dl-pages', [
      'query' => [
        'conditions' => [['property' => 'name', 'operator' => 'contains', 'value' => 'person']],
        'page' => '2',
        'page_size' => '10',
      ],
    ]);
    $href = $this->getSession()->getPage()->find('css', 'a.dcu-table__download-results')->getAttribute('href');
    $this->assertStringNotContainsString('limit', $href);
    $this->assertStringNotContainsString('offset', $href);
    $this->drupalGet($this->getAbsoluteUrl($href));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $this->getSession()->getPage()->getContent()))));
    // Header plus all 30 rows.
    $this->assertCount(31, $lines);
  }

  /**
   * Zero matches: current results are disabled, the original stays a link.
   */
  public function testZeroMatchesDisablesResults(): void {
    $this->createImportedDataset('dl-none');
    $this->drupalGet('/dataset/dl-none', [
      'query' => [
        'conditions' => [['property' => 'name', 'operator' => '=', 'value' => 'nobody']],
      ],
    ]);
    $assert = $this->assertSession();
    $assert->pageTextContains('No matching rows · 30 total');
    $assert->elementNotExists('css', 'a.dcu-table__download-results');
    $assert->elementExists('css', '.dcu-table__download-results[aria-disabled="true"]');
    $assert->elementExists('css', 'a.dcu-table__download-original');
  }

}
