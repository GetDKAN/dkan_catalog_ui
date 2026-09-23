<?php

namespace Drupal\Tests\dkan_catalog_ui_preview\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\dkan_catalog_ui_preview\Controller\TableFragmentController;
use Drupal\Tests\dkan_catalog_ui\Kernel\CatalogUiKernelTestBase;
use Drupal\Tests\dkan_catalog_ui_preview\Traits\DatastoreFixtureTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the fragment route used for in-place table updates.
 */
#[Group('dkan_catalog_ui_preview')]
class TableFragmentControllerTest extends CatalogUiKernelTestBase {

  use DatastoreFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dkan_catalog_ui_preview'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * Run the controller for a dataset with the given query.
   */
  protected function fragment(string $uuid, array $query = []) {
    $request = Request::create('/dataset/' . $uuid . '/table', 'GET', $query);
    $request->setSession($this->container->get('request_stack')->getCurrentRequest()->getSession());
    $this->container->get('request_stack')->push($request);
    try {
      return TableFragmentController::create($this->container)->fragment($uuid);
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * The fragment is the table for the query, uncacheable and unindexed.
   */
  public function testFragment(): void {
    $node = $this->createDataset('frag-test');
    $this->importDatasetFixture($node);

    $response = $this->fragment('frag-test', ['sort' => 'age', 'direction' => 'desc', 'page_size' => '10']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    $this->assertContains('node:' . $node->id(), $response->getCacheableMetadata()->getCacheTags());

    $html = $response->getContent();
    $this->assertStringContainsString('<div id="dcu-table"', $html);
    $this->assertStringContainsString('Rows 1–10 of 30', $html);
    $this->assertStringContainsString('aria-sort="descending"', $html);
    $this->assertStringContainsString('data-dcu-fragment="/dataset/frag-test/table"', $html);
    $this->assertStringContainsString('data-dcu-apply="/dataset/frag-test/table/apply"', $html);
    $this->assertStringNotContainsString('<html', $html);
  }

  /**
   * The chosen file's chooser option, download and caption travel along.
   */
  public function testFragmentChooser(): void {
    $node = $this->createDataset('frag-chooser', [
      'distribution' => [
        [
          'title' => 'First',
          'downloadURL' => 'http://example.com/first.csv',
          'mediaType' => 'text/csv',
        ],
        [
          'title' => 'Second',
          'downloadURL' => 'http://example.com/second.csv',
          'mediaType' => 'text/csv',
        ],
      ],
    ]);
    $this->importDatasetFixture($node, 0);
    $this->importDatasetFixture($node, 1);

    $html = $this->fragment('frag-chooser', ['table' => '1'])->getContent();
    $this->assertStringContainsString('<option value="1" selected>Second</option>', $html);
    $this->assertStringContainsString('dcu-table__download-original" href="http://example.com/second.csv"', $html);
    // The chooser lists titles, so the caption still names the file.
    $xpath = new \DOMXPath(Html::load($html));
    $caption = $xpath->query('//div[@class="dcu-table__identity"]/p[@id="dcu-table-caption"]');
    $this->assertCount(1, $caption);
    $this->assertSame('dcu-table__caption', $caption->item(0)->getAttribute('class'));
    $this->assertSame('second.csv', trim($caption->item(0)->textContent));
    $this->assertCount(1, $xpath->query('//table[@aria-labelledby="dcu-table-caption"]'));
  }

  /**
   * Before import the fragment is the reduced toolbar and message.
   */
  public function testFragmentBeforeImport(): void {
    $this->createDataset('frag-pending');
    $html = $this->fragment('frag-pending')->getContent();
    $this->assertStringContainsString('dcu-table__message', $html);
    $this->assertStringNotContainsString('dcu-table__panel', $html);
  }

  /**
   * Unknown datasets are 404; datasets without tabular data too.
   */
  public function testNotFound(): void {
    $this->createDataset('frag-pdf', [
      'distribution' => [
        ['title' => 'Doc', 'downloadURL' => 'http://example.com/doc.pdf', 'mediaType' => 'application/pdf'],
      ],
    ]);
    $this->expectException(NotFoundHttpException::class);
    $this->fragment('frag-pdf');
  }

  /**
   * Node view access is enforced.
   */
  public function testAccess(): void {
    $this->createDataset('frag-access');
    $this->setUpCurrentUser([], []);
    $this->expectException(AccessDeniedHttpException::class);
    $this->fragment('frag-access');
  }

}
