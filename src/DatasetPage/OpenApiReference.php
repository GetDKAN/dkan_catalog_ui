<?php

namespace Drupal\dkan_catalog_ui\DatasetPage;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Turns an OpenAPI 3 document into a flat, renderable endpoint list.
 *
 * Only what the API tab shows is extracted; schemas and responses are left
 * to the raw document.
 */
class OpenApiReference implements OpenApiReferenceInterface {

  use StringTranslationTrait;

  /**
   * Constructor.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function endpoints(array $spec): array {
    $endpoints = [];
    foreach ($spec['paths'] ?? [] as $path => $operations) {
      if (!is_array($operations)) {
        continue;
      }
      foreach ($operations as $method => $operation) {
        if (!is_array($operation) || !in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete'], TRUE)) {
          continue;
        }
        $parameters = [];
        foreach ($operation['parameters'] ?? [] as $parameter) {
          $resolved = $this->resolve($parameter, $spec);
          if (!empty($resolved['name'])) {
            $parameters[] = $this->parameter($resolved);
          }
        }
        $endpoints[] = [
          'method' => strtoupper($method),
          'path' => (string) $path,
          'summary' => (string) ($operation['summary'] ?? ''),
          'description' => $this->plainText((string) ($operation['description'] ?? '')),
          'tag' => (string) (($operation['tags'] ?? [])[0] ?? ''),
          'parameters' => $parameters,
          'request_example' => $this->requestExample($operation, $spec),
          'example_path' => $this->examplePath((string) $path, $parameters),
        ];
      }
    }
    return $endpoints;
  }

  /**
   * {@inheritdoc}
   */
  public function groupedEndpoints(array $spec): array {
    $groups = [];
    $other = [];
    foreach ($this->endpoints($spec) as $endpoint) {
      if ($endpoint['tag'] === '') {
        $other[] = $endpoint;
        continue;
      }
      $groups[$endpoint['tag']]['title'] = $endpoint['tag'];
      $groups[$endpoint['tag']]['endpoints'][] = $endpoint;
    }
    if ($other) {
      $groups[] = ['title' => (string) $this->t('Other'), 'endpoints' => $other];
    }
    return array_values($groups);
  }

  /**
   * Resolve a local "$ref" against the document; other values pass through.
   */
  protected function resolve(mixed $value, array $spec): array {
    if (!is_array($value)) {
      return [];
    }
    $ref = $value['$ref'] ?? NULL;
    if (!is_string($ref) || !str_starts_with($ref, '#/')) {
      return $value;
    }
    $target = $spec;
    foreach (explode('/', substr($ref, 2)) as $segment) {
      $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
      if (!is_array($target) || !array_key_exists($segment, $target)) {
        return [];
      }
      $target = $target[$segment];
    }
    return is_array($target) ? $target : [];
  }

  /**
   * Normalize one parameter object.
   */
  protected function parameter(array $parameter): array {
    $example = $parameter['example'] ?? NULL;
    if ($example === NULL && !empty($parameter['examples']) && is_array($parameter['examples'])) {
      $first = reset($parameter['examples']);
      $example = is_array($first) ? ($first['value'] ?? NULL) : $first;
    }
    if ($example === NULL) {
      $example = $parameter['schema']['example'] ?? $parameter['schema']['default'] ?? NULL;
    }
    return [
      'name' => (string) $parameter['name'],
      'in' => (string) ($parameter['in'] ?? 'query'),
      'required' => !empty($parameter['required']),
      'description' => $this->plainText((string) ($parameter['description'] ?? '')),
      'type' => (string) ($parameter['schema']['type'] ?? ''),
      'example' => is_scalar($example) ? (string) $example : '',
    ];
  }

  /**
   * Reduce Markdown links in DKAN's descriptions to "text (url)".
   *
   * Descriptions are rendered escaped, so Markdown would otherwise show raw.
   */
  protected function plainText(string $text): string {
    return preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '$1 ($2)', $text) ?? $text;
  }

  /**
   * Pretty-printed JSON request body example, if the operation has one.
   */
  protected function requestExample(array $operation, array $spec): string {
    $body = $this->resolve($operation['requestBody'] ?? NULL, $spec);
    $content = $body['content']['application/json'] ?? NULL;
    if (!is_array($content)) {
      return '';
    }
    $example = $content['example'] ?? NULL;
    if ($example === NULL && !empty($content['examples']) && is_array($content['examples'])) {
      $first = reset($content['examples']);
      $example = is_array($first) ? ($first['value'] ?? NULL) : $first;
    }
    if ($example === NULL) {
      return '';
    }
    return (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * The path with example values substituted for path parameters.
   */
  protected function examplePath(string $path, array $parameters): string {
    foreach ($parameters as $parameter) {
      if ($parameter['in'] === 'path' && $parameter['example'] !== '') {
        $path = str_replace('{' . $parameter['name'] . '}', rawurlencode($parameter['example']), $path);
      }
    }
    return str_contains($path, '{') ? '' : $path;
  }

}
