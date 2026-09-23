<?php

namespace Drupal\Tests\dkan_catalog_ui\Unit\DatasetPage;

use Drupal\dkan_catalog_ui\DatasetPage\OpenApiReference;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case.
 */
#[CoversClass(\Drupal\dkan_catalog_ui\DatasetPage\OpenApiReference::class)]
#[Group('dkan_catalog_ui')]
class OpenApiReferenceTest extends UnitTestCase {

  /**
   * A DKAN-shaped document with $ref parameters and a request body.
   */
  protected function spec(): array {
    return [
      'openapi' => '3.0.2',
      'paths' => [
        '/api/1/datastore/query/{distributionId}' => [
          'get' => [
            'summary' => 'Query with GET',
            'description' => 'Simple GET. See [this tool](https://example.com/x) for help.',
            'tags' => ['Datastore: query'],
            'parameters' => [
              ['$ref' => '#/components/parameters/distributionId'],
              ['$ref' => '#/components/parameters/limit'],
              ['$ref' => '#/components/parameters/missing'],
            ],
          ],
          'post' => [
            'summary' => 'Query with POST',
            'parameters' => [['$ref' => '#/components/parameters/distributionId']],
            'requestBody' => [
              'content' => [
                'application/json' => [
                  'schema' => ['$ref' => '#/components/schemas/query'],
                  'example' => ['limit' => 3],
                ],
              ],
            ],
          ],
          'x-extension' => 'ignored',
        ],
        '/api/1/datastore/sql' => [
          'get' => [
            'parameters' => [
              ['name' => 'query', 'in' => 'query', 'required' => TRUE, 'schema' => ['type' => 'string']],
            ],
          ],
        ],
      ],
      'components' => [
        'parameters' => [
          'distributionId' => [
            'name' => 'distributionId',
            'in' => 'path',
            'required' => TRUE,
            'description' => 'A distribution ID',
            'schema' => ['type' => 'string'],
            'examples' => ['abc' => ['value' => 'abc-123', 'summary' => 'Example']],
          ],
          'limit' => [
            'name' => 'limit',
            'in' => 'query',
            'schema' => ['type' => 'integer', 'default' => 100],
          ],
        ],
      ],
    ];
  }

  /**
   * Endpoints are read from the spec's paths.
   */
  public function testEndpoints(): void {
    $endpoints = (new OpenApiReference($this->getStringTranslationStub()))->endpoints($this->spec());
    $this->assertCount(3, $endpoints);

    $get = $endpoints[0];
    $this->assertSame('GET', $get['method']);
    $this->assertSame('/api/1/datastore/query/{distributionId}', $get['path']);
    $this->assertSame('Query with GET', $get['summary']);
    $this->assertSame('Simple GET. See this tool (https://example.com/x) for help.', $get['description']);
    $this->assertSame('Datastore: query', $get['tag']);
    // Unresolvable $ref is dropped; the other two resolve.
    $this->assertCount(2, $get['parameters']);
    $this->assertSame([
      'name' => 'distributionId',
      'in' => 'path',
      'required' => TRUE,
      'description' => 'A distribution ID',
      'type' => 'string',
      'example' => 'abc-123',
    ], $get['parameters'][0]);
    $this->assertSame('100', $get['parameters'][1]['example']);
    $this->assertSame('/api/1/datastore/query/abc-123', $get['example_path']);
    $this->assertSame('', $get['request_example']);

    $post = $endpoints[1];
    $this->assertSame('POST', $post['method']);
    $this->assertSame("{\n    \"limit\": 3\n}", $post['request_example']);

    $sql = $endpoints[2];
    $this->assertSame('', $sql['summary']);
    $this->assertTrue($sql['parameters'][0]['required']);
    $this->assertSame('/api/1/datastore/sql', $sql['example_path']);
  }

  /**
   * Groups follow the first tag; untagged endpoints go last under Other.
   */
  public function testGroupedEndpoints(): void {
    $groups = (new OpenApiReference($this->getStringTranslationStub()))->groupedEndpoints($this->spec());
    $this->assertSame(['Datastore: query', 'Other'], array_column($groups, 'title'));
    $this->assertSame(['GET'], array_column($groups[0]['endpoints'], 'method'));
    $this->assertSame(['/api/1/datastore/query/{distributionId}', '/api/1/datastore/sql'], array_column($groups[1]['endpoints'], 'path'));
    $this->assertSame([], (new OpenApiReference($this->getStringTranslationStub()))->groupedEndpoints([]));
  }

  /**
   * An empty spec yields no endpoints.
   */
  public function testEmptySpec(): void {
    $this->assertSame([], (new OpenApiReference($this->getStringTranslationStub()))->endpoints([]));
    $this->assertSame([], (new OpenApiReference($this->getStringTranslationStub()))->endpoints(['paths' => ['/x' => 'bad']]));
  }

}
