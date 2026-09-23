<?php

namespace Drupal\dkan_catalog_ui_preview\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Url;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;
use Drupal\dkan_catalog_ui_preview\TableState;

/**
 * Provides a render element for data preview tables.
 *
 * Usage:
 * @code
 * $build['preview'] = [
 *   '#type' => 'dkan_catalog_ui_preview',
 *   '#resource_id' => $resource_id,
 * ];
 * @endcode
 *
 * The table state (page, sort, filters, columns) comes from the current
 * request's query string (see TableState). Only one table per page is
 * supported; the dataset page uses DataPreviewBuilder::lazyBuild() instead.
 *
 * The toolbar panels (filters, columns) submit to an apply route that
 * normalizes their input; pass one as #apply_url (a Url) when the placement
 * provides it. Without it the toolbar shows the summary only and the table
 * is driven by links, the footer rows-per-page form (a plain GET to the
 * current path) and hand-written URLs.
 */
#[RenderElement('dkan_catalog_ui_preview')]
class DataPreview extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#resource_id' => '',
      '#data_source_instance' => NULL,
      // File name for the toolbar's file row and the table's accessible
      // name; see DataPreviewBuilderInterface::build().
      '#caption' => NULL,
      '#apply_url' => NULL,
      '#pre_render' => [
        [$class, 'preRender'],
      ],
    ];
  }

  /**
   * Pre-render callback: builds the data preview table.
   */
  public static function preRender(array $element): array {
    if (empty($element['#resource_id'])) {
      return $element;
    }

    $dataSource = $element['#data_source_instance'] ?? NULL;
    if (!$dataSource instanceof DataSourceInterface) {
      $dataSource = \Drupal::service('dkan_catalog_ui.preview.data_source.database');
    }

    try {
      $rowsLimit = (int) \Drupal::config('dkan_datastore.settings')->get('rows_limit') ?: NULL;
      $request = \Drupal::request();
      $fields = $dataSource->getSchema($element['#resource_id'])['fields'] ?? [];
      $state = TableState::fromRequest($request, $fields, 1, $rowsLimit);

      /** @var \Drupal\dkan_catalog_ui_preview\DataPreviewBuilderInterface $builder */
      $builder = \Drupal::service('dkan_catalog_ui.preview.builder');
      $panel = $request->query->get('panel');
      $applyUrl = ($element['#apply_url'] ?? NULL) instanceof Url ? $element['#apply_url'] : NULL;
      $buildResult = $builder->build($dataSource, $element['#resource_id'], $state, [
        'caption' => $element['#caption'] ?? NULL,
        'max_page_size' => $rowsLimit,
        'panel' => is_string($panel) ? $panel : NULL,
        'apply_url' => $applyUrl,
        'panels' => $applyUrl !== NULL,
      ]);

      if ($buildResult === NULL) {
        $element['message'] = static::statusMessage($element['#resource_id']);
        return $element;
      }
      // A child so the component's own pre-render callbacks run.
      $element['table'] = $buildResult;
    }
    catch (\Throwable $e) {
      \Drupal::logger('dkan_catalog_ui_preview')->warning('Data preview error for resource @id: @message', [
        '@id' => $element['#resource_id'],
        '@message' => $e->getMessage(),
      ]);
      $element['message'] = static::statusMessage($element['#resource_id']);
    }

    return $element;
  }

  /**
   * Build the import status message for an unavailable preview.
   */
  protected static function statusMessage(string $resource_id): array {
    /** @var \Drupal\dkan_catalog_ui_preview\ImportStatusMessageInterface $statusMessage */
    $statusMessage = \Drupal::service('dkan_catalog_ui.preview.import_status_message');
    return $statusMessage->build($resource_id);
  }

}
