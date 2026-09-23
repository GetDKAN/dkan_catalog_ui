<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_catalog_ui_preview\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The data table toolbar without JavaScript: forms, redirects, access, cache.
 */
#[Group('dkan_catalog_ui_preview')]
class DataTableTest extends BrowserTestBase {

  use DatastoreFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui_preview', 'page_cache', 'dynamic_page_cache'];

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
   * Create a dataset through the metastore.
   */
  protected function createDataset(string $identifier, array $overrides = []): NodeInterface {
    $metastore = $this->container->get('dkan.metastore.service');
    $metadata = $metastore->getValidMetadataFactory()->get(json_encode($overrides + [
      'title' => 'Functional Test Dataset',
      'identifier' => $identifier,
      'keyword' => ['test'],
      'description' => 'Test description.',
      'modified' => '2020-01-15',
      'accessLevel' => 'public',
      'distribution' => [
        [
          'title' => 'Tabular',
          'downloadURL' => 'http://example.com/data.csv',
          'mediaType' => 'text/csv',
          'format' => 'csv',
        ],
      ],
    ]), 'dataset');
    $metastore->post('dataset', $metadata);
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uuid' => $identifier]);
    return reset($nodes);
  }

  /**
   * A dataset with the fixture imported into its first distribution.
   */
  protected function createImportedDataset(string $identifier, array $overrides = []): NodeInterface {
    $node = $this->createDataset($identifier, $overrides);
    $this->importDatasetFixture($node);
    return $node;
  }

  /**
   * Parsed query of the current URL.
   */
  protected function currentQuery(): array {
    parse_str((string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_QUERY), $query);
    return $query;
  }

  /**
   * Filter panel: apply, chip removal, remove button, reset.
   */
  public function testFilters(): void {
    $this->createImportedDataset('ft-filters');
    $this->drupalGet('/dataset/ft-filters');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Rows 1–25 of 30');
    $assert->elementExists('css', 'details#dcu-table-filters:not([open])');

    // Fill the blank row and apply: redirected to the canonical URL, the
    // panel closed by its primary action, chip shown, rows filtered.
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('conditions[0][property]', 'name');
    $page->selectFieldOption('conditions[0][operator]', 'starts with');
    $page->fillField('conditions[0][value]', ' person_1 ');
    $page->pressButton('Apply filters');

    $assert->statusCodeEquals(200);
    $this->assertSame([
      'conditions' => [['property' => 'name', 'operator' => 'starts with', 'value' => 'person_1']],
    ], $this->currentQuery());
    $assert->pageTextContains('10 of 30 rows');
    $assert->elementExists('css', 'details#dcu-table-filters:not([open])');
    $assert->elementTextContains('css', '.dcu-table__chips', 'name Starts With person_1');
    $assert->pageTextContains('person_10');
    $assert->pageTextNotContains('person_01');

    // A second condition through the new blank row keeps the first.
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('conditions[1][property]', 'city');
    $page->fillField('conditions[1][value]', 'nowhere');
    $page->pressButton('Apply filters');
    $assert->pageTextContains('No matching rows · 30 total');
    $this->assertCount(2, $this->currentQuery()['conditions']);

    // The Remove button drops one row and leaves the panel open.
    $this->getSession()->getPage()->find('css', 'button[name="remove"][value="1"]')->press();
    $this->assertSame([['property' => 'name', 'operator' => 'starts with', 'value' => 'person_1']], $this->currentQuery()['conditions']);
    $this->assertSame('filters', $this->currentQuery()['panel']);
    $assert->elementExists('css', 'details#dcu-table-filters[open]');
    $assert->pageTextContains('10 of 30 rows');

    // The chip link removes the filter without the apply route.
    $this->getSession()->getPage()->find('css', '.dcu-table__chip a[aria-label^="Remove filter"]')->click();
    $this->assertSame([], $this->currentQuery());
    $assert->pageTextContains('Rows 1–25 of 30');

    // Reset ignores the submitted rows.
    // Ages are text in the datastore, so ">" is not offered; use contains.
    $contains = ['property' => 'name', 'operator' => 'contains', 'value' => '_1'];
    $this->drupalGet('/dataset/ft-filters', ['query' => ['conditions' => [$contains]]]);
    $assert->pageTextContains('10 of 30 rows');
    $this->getSession()->getPage()->find('css', 'button[name="reset_filters"]')->press();
    $this->assertSame(['panel' => 'filters'], $this->currentQuery());
    $assert->pageTextContains('Rows 1–25 of 30');
  }

  /**
   * Manage columns: hide, move, chip, reset; hidden sort column drops sort.
   */
  public function testColumns(): void {
    $this->createImportedDataset('ft-columns');
    $this->drupalGet('/dataset/ft-columns', ['query' => ['sort' => 'city', 'page' => '2']]);
    $assert = $this->assertSession();

    $page = $this->getSession()->getPage();
    $page->find('css', '#dcu-column-city')->uncheck();
    $page->find('css', 'form.dcu-table__columns')->findButton('Save')->press();
    // Page is kept; the sort on the hidden column is dropped; Save closes.
    $this->assertSame(['page' => '2', 'columns' => 'name,age'], $this->currentQuery());
    $assert->elementNotExists('css', 'th a[aria-label^="Sort by city"]');
    $assert->elementTextContains('css', '.dcu-table__chips', '1 column hidden');
    $assert->elementExists('css', 'details#dcu-table-columns:not([open])');

    // Cancel is a link back to the current state.
    $assert->elementAttributeContains('css', 'a.dcu-table__cancel', 'href', '/dataset/ft-columns?page=2&columns=name%2Cage');

    // Move the first column down; a secondary action leaves the panel open.
    $this->getSession()->getPage()->find('css', 'button[name="move_down"][value="name"]')->press();
    $this->assertSame(['page' => '2', 'columns' => 'age,name', 'panel' => 'columns'], $this->currentQuery());
    $assert->elementExists('css', 'details#dcu-table-columns[open]');
    $headers = array_map(fn ($th) => trim($th->getText()), $this->getSession()->getPage()->findAll('css', '.dcu-table__table thead th'));
    $this->assertSame(['age', 'name'], $headers);

    // Unchecking everything means all columns.
    $page = $this->getSession()->getPage();
    $page->find('css', '#dcu-column-age')->uncheck();
    $page->find('css', '#dcu-column-name')->uncheck();
    $page->find('css', 'form.dcu-table__columns')->findButton('Save')->press();
    $this->assertSame(['page' => '2'], $this->currentQuery());

    // Reset columns from a hidden state; the chip link does the same.
    $this->drupalGet('/dataset/ft-columns', ['query' => ['columns' => 'age']]);
    $this->getSession()->getPage()->find('css', 'button[name="reset_columns"]')->press();
    $this->assertSame(['panel' => 'columns'], $this->currentQuery());
    $assert->elementExists('css', 'details#dcu-table-columns[open]');
    $this->drupalGet('/dataset/ft-columns', ['query' => ['columns' => 'age']]);
    $this->clickLink('Clear all');
    $this->assertSame([], $this->currentQuery());
  }

  /**
   * Footer rows per page, sort links and the pager.
   */
  public function testDisplaySortAndPager(): void {
    $this->createImportedDataset('ft-display');
    $this->drupalGet('/dataset/ft-display');
    $assert = $this->assertSession();

    $page = $this->getSession()->getPage();
    $page->selectFieldOption('page_size', '10');
    $page->find('css', 'form.dcu-table__page-size')->findButton('Apply')->press();
    $this->assertSame(['page_size' => '10'], $this->currentQuery());
    $assert->pageTextContains('Rows 1–10 of 30');

    $this->getSession()->getPage()->find('css', 'th a[aria-label="Sort by age, ascending"]')->click();
    $this->assertSame(['page_size' => '10', 'sort' => 'age'], $this->currentQuery());
    $assert->elementExists('css', 'th[aria-sort="ascending"]');
    $this->getSession()->getPage()->find('css', 'th a[aria-label="Sort by age, descending"]')->click();
    $this->assertSame(['page_size' => '10', 'sort' => 'age', 'direction' => 'desc'], $this->currentQuery());
    $assert->elementTextContains('css', 'tbody tr:first-child', '69');

    $this->clickLink('Next page');
    $this->assertSame(['page' => '2', 'page_size' => '10', 'sort' => 'age', 'direction' => 'desc'], $this->currentQuery());
    $assert->pageTextContains('Rows 11–20 of 30');
    $this->clickLink('Last page');
    $assert->pageTextContains('Rows 21–30 of 30');
    $assert->elementExists('css', '.pager__item.is-active a[aria-current="page"]');

    // Out-of-range pages clamp; junk is ignored.
    $this->drupalGet('/dataset/ft-display', ['query' => ['page' => '99', 'page_size' => '10', 'junk' => 'x']]);
    $assert->pageTextContains('Rows 21–30 of 30');

    // The footer form keeps filters, sort and columns and returns to page 1.
    $condition = ['property' => 'name', 'operator' => 'contains', 'value' => 'person'];
    $this->drupalGet('/dataset/ft-display', [
      'query' => [
        'conditions' => [$condition],
        'page' => '2',
        'page_size' => '10',
        'sort' => 'age',
        'columns' => 'age,name',
      ],
    ]);
    $assert->pageTextContains('Rows 11–20 of 30 matching rows · 30 total');
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('page_size', '25');
    $page->find('css', 'form.dcu-table__page-size')->findButton('Apply')->press();
    $this->assertEquals([
      'conditions' => [$condition],
      'sort' => 'age',
      'columns' => 'age,name',
    ], $this->currentQuery());
    $assert->pageTextContains('Rows 1–25 of 30 matching rows · 30 total');
    $assert->elementNotExists('css', 'details[open]');
  }

  /**
   * Distribution chooser: switching resets the state.
   */
  public function testChooser(): void {
    $this->createImportedDataset('ft-chooser', [
      'distribution' => [
        ['title' => 'First', 'downloadURL' => 'http://example.com/first.csv', 'mediaType' => 'text/csv'],
        ['title' => 'Second', 'downloadURL' => 'http://example.com/second.csv', 'mediaType' => 'text/csv'],
        ['title' => 'Doc', 'downloadURL' => 'http://example.com/doc.pdf', 'mediaType' => 'application/pdf'],
      ],
    ]);
    $this->drupalGet('/dataset/ft-chooser', ['query' => ['sort' => 'age']]);
    $assert = $this->assertSession();
    $assert->elementTextContains('css', '#dcu-table-caption', 'first.csv');
    $assert->elementExists('css', 'a.dcu-table__download-original[href="http://example.com/first.csv"]');
    // File row: chooser (titles), caption (the file name and the table's
    // accessible name), then the Download disclosure.
    $assert->elementExists('css', '.dcu-table__identity > form.dcu-table__chooser + p.dcu-table__caption');
    $assert->elementExists('css', '.dcu-table__file > .dcu-table__identity + details#dcu-table-download');
    $assert->elementNotExists('css', 'p.dcu-table__caption.visually-hidden');
    $assert->elementTextContains('css', 'p#dcu-table-caption', 'first.csv');
    $assert->elementExists('css', 'table.dcu-table__table[aria-labelledby="dcu-table-caption"]');

    $page = $this->getSession()->getPage();
    $page->selectFieldOption('table', 'Second');
    $page->find('css', 'form.dcu-table__chooser')->findButton('Show')->press();
    $this->assertSame(['table' => '1'], $this->currentQuery());
    // The second distribution is not imported: status message, no table.
    $assert->pageTextContains('Data preview is not yet available.');
    $assert->elementNotExists('css', 'table.dcu-table__table');
    $assert->elementNotExists('css', 'details#dcu-table-filters');
    $assert->elementExists('css', 'a.dcu-button.dcu-table__download-original[href="http://example.com/second.csv"]');
    // Switching back works from the reduced toolbar.
    $this->getSession()->getPage()->selectFieldOption('table', 'First');
    $this->getSession()->getPage()->find('css', 'form.dcu-table__chooser')->findButton('Show')->press();
    $this->assertSame([], $this->currentQuery());
    $assert->elementTextContains('css', '#dcu-table-caption', 'first.csv');
  }

  /**
   * Direct apply-route requests redirect to canonical URLs; access applies.
   */
  public function testApplyRouteAndAccess(): void {
    $this->createImportedDataset('ft-apply');
    $assert = $this->assertSession();

    $query = [
      'junk' => 'x',
      'direction' => 'desc',
      'sort' => 'age',
      'page_size' => '50',
      'columns' => ['city', 'age', 'evil'],
      'panel' => 'evil',
    ];
    $this->drupalGet('/dataset/ft-apply/table/apply', ['query' => $query]);
    $assert->statusCodeEquals(200);
    $this->assertSame('/dataset/ft-apply', parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
    $this->assertSame(['page_size' => '50', 'sort' => 'age', 'direction' => 'desc', 'columns' => 'city,age'], $this->currentQuery());

    $this->drupalGet('/dataset/nope/table/apply');
    $assert->statusCodeEquals(404);

    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->drupalGet('/dataset/ft-apply/table/apply');
    $assert->statusCodeEquals(403);
    $this->drupalGet('/dataset/ft-apply');
    $assert->statusCodeEquals(403);
  }

  /**
   * Page cache per URL, dynamic page cache across states, tag invalidation.
   */
  public function testCaching(): void {
    $node = $this->createDataset('ft-cache');
    $assert = $this->assertSession();

    $this->drupalGet('/dataset/ft-cache');
    $assert->pageTextContains('Data preview is not yet available.');
    $assert->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->drupalGet('/dataset/ft-cache');
    $assert->responseHeaderEquals('X-Drupal-Cache', 'HIT');

    // Importing and invalidating the distribution's tags (as DKAN's
    // post-import step does) flips the cached page to the table.
    $this->importDatasetFixture($node);
    $info = $this->container->get('dkan.common.dataset_info')->gather($node->uuid());
    $distributionNodes = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['uuid' => $info['latest_revision']['distributions'][0]['distribution_uuid']]);
    Cache::invalidateTags(reset($distributionNodes)->getCacheTags());

    $this->drupalGet('/dataset/ft-cache');
    $assert->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $assert->pageTextContains('Rows 1–25 of 30');

    // Another state is a page-cache miss but a dynamic-page-cache hit: the
    // page shell is cached, the table renders per request.
    $this->drupalGet('/dataset/ft-cache', ['query' => ['sort' => 'age']]);
    $assert->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $assert->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $assert->elementExists('css', 'th[aria-sort="ascending"]');
  }

}
