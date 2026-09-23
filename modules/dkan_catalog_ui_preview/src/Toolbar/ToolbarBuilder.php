<?php

namespace Drupal\dkan_catalog_ui_preview\Toolbar;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dkan_catalog_ui\DatasetPage\DownloadUrl;
use Drupal\dkan_catalog_ui_preview\Condition;
use Drupal\dkan_catalog_ui_preview\DataPreviewBuilder;
use Drupal\dkan_catalog_ui_preview\LikeEscaper;
use Drupal\dkan_catalog_ui_preview\OperatorRegistry;
use Drupal\dkan_catalog_ui_preview\TableState;

/**
 * Builds the toolbar as props for the data-table-toolbar component.
 *
 * Every form is a GET form posting to the apply route; the state a form does
 * not edit travels as hidden inputs. Panels are <details> elements; the one
 * named by the `panel` option renders open.
 */
class ToolbarBuilder implements ToolbarBuilderInterface {

  use StringTranslationTrait;

  const PANEL_FILTERS = 'filters';
  const PANEL_COLUMNS = 'columns';
  const PANEL_DISPLAY = 'display';
  const PANEL_SHARE = 'share';

  /**
   * Datastore download route (streams CSV for a query).
   */
  const DOWNLOAD_ROUTE = 'dkan_datastore.1.query.id.download';

  /**
   * Display labels per column for the current build.
   *
   * @var array<string, string>
   */
  protected array $labels = [];

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly LikeEscaper $likeEscaper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(TableState $state, array $fields, array $options = []): array {
    $options += [
      'base_url' => NULL,
      'apply_url' => NULL,
      'tables' => [],
      'panel' => NULL,
      'page_sizes' => TableState::PAGE_SIZES,
      'summary' => [],
      'caption' => NULL,
      'panels' => TRUE,
      'fragment_url' => NULL,
      'resource_id' => NULL,
      'labels' => [],
    ];
    $this->labels = $options['labels'];
    $baseUrl = $options['base_url'];
    $applyUrl = $options['apply_url'] ?? $baseUrl;
    $current = $options['tables'][$state->table] ?? NULL;

    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui_preview:data-table-toolbar',
      '#props' => [
        'apply_url' => $applyUrl->toString(),
        'fragment_url' => $options['fragment_url'] ? $options['fragment_url']->toString() : '',
        'show_panels' => (bool) $options['panels'],
        'has_summary' => !empty($options['summary']),
        'open_panel' => (string) $options['panel'],
        'caption' => $this->caption($options['caption']),
        'download' => $this->download($current),
        'chooser' => $this->chooser($options['tables'], $state),
        'filters' => $this->filters($state, $fields),
        'columns' => $this->columns($state, $fields, $baseUrl),
        'display' => $this->display($state, $options['page_sizes']),
        'chips' => $this->chips($state, $fields, $baseUrl),
        'share' => $this->share($state, $baseUrl, $options['resource_id']),
        'labels' => $this->labels(),
      ],
      '#slots' => [
        'summary' => $options['summary'] ?: ['#markup' => ''],
      ],
    ];
  }

  /**
   * The file caption (the table's accessible name), or NULL.
   *
   * DataPreviewBuilder applies the same emptiness test when it sets the
   * table's aria-labelledby, so the attribute never points at nothing.
   */
  protected function caption(mixed $caption): ?array {
    $text = (string) ($caption ?? '');
    if ($text === '') {
      return NULL;
    }
    return ['id' => DataPreviewBuilder::CAPTION_ID, 'text' => $text];
  }

  /**
   * The validated full-dataset download link, or NULL.
   */
  protected function download(?array $table): ?array {
    $url = $table ? DownloadUrl::fromString($table['download_url'] ?? '') : NULL;
    if (!$url) {
      return NULL;
    }
    $extension = strtoupper((string) pathinfo($table['label'] ?? '', PATHINFO_EXTENSION));
    return [
      'url' => $url->toString(),
      'label' => (string) ($extension
        ? $this->t('Download full dataset (@format)', ['@format' => $extension])
        : $this->t('Download full dataset')),
    ];
  }

  /**
   * Distribution chooser; NULL with fewer than two tables.
   *
   * The form carries no other state: switching tables resets everything.
   */
  protected function chooser(array $tables, TableState $state): ?array {
    if (count($tables) < 2) {
      return NULL;
    }
    $items = [];
    foreach ($tables as $index => $table) {
      $items[] = [
        'value' => (string) $index,
        'label' => $table['title'] ?: ($table['label'] ?: (string) $this->t('Distribution @n', ['@n' => $index + 1])),
        'selected' => $index === $state->table,
      ];
    }
    return ['tables' => $items];
  }

  /**
   * Filter panel: one row per condition plus a blank row.
   */
  protected function filters(TableState $state, array $fields): array {
    $columns = [];
    foreach ($fields as $name => $field) {
      $columns[] = [
        'value' => $name,
        'label' => $this->label($name, $fields),
        'category' => OperatorRegistry::category($field),
      ];
    }
    $allOperators = $this->operatorOptions(OperatorRegistry::labels());
    $labels = OperatorRegistry::labels();
    $operatorSets = [];
    foreach (OperatorRegistry::OPERATORS as $category => $operators) {
      $operatorSets[$category] = $this->operatorOptions(array_intersect_key($labels, array_flip($operators)));
    }

    $rows = [];
    foreach ($state->conditions as $index => $condition) {
      $rows[] = [
        'index' => $index,
        'property' => $condition->property,
        'operator' => $condition->operator,
        'value' => $condition->value,
        'operators' => $this->operatorOptions(OperatorRegistry::optionsForField($fields[$condition->property])),
      ];
    }
    $hidden = $state->toQuery();
    unset($hidden['conditions'], $hidden['page']);

    return [
      'columns' => $columns,
      'rows' => $rows,
      'blank_index' => count($rows),
      'all_operators' => $allOperators,
      'operator_sets' => $operatorSets,
      'hidden' => DataPreviewBuilder::flattenQuery($hidden),
      'count' => count($rows),
      'max' => TableState::MAX_CONDITIONS,
      'value_max_length' => Condition::MAX_VALUE_LENGTH,
    ];
  }

  /**
   * Manage columns panel: visible columns in order, then hidden ones.
   */
  protected function columns(TableState $state, array $fields, ?Url $baseUrl): array {
    $visible = $state->visibleColumns($fields);
    $ordered = array_merge($visible, array_diff(array_keys($fields), $visible));
    $items = [];
    foreach ($ordered as $position => $name) {
      $isVisible = in_array($name, $visible, TRUE);
      $items[] = [
        'name' => $name,
        'label' => $this->label($name, $fields),
        'visible' => $isVisible,
        'can_move_up' => $isVisible && $position > 0,
        'can_move_down' => $isVisible && $position < count($visible) - 1,
      ];
    }
    $hidden = $state->toQuery();
    unset($hidden['columns']);

    return [
      'items' => $items,
      'hidden' => DataPreviewBuilder::flattenQuery($hidden),
      'hidden_count' => count($fields) - count($visible),
      'cancel_url' => $this->stateUrl($baseUrl, $state),
    ];
  }

  /**
   * Display settings panel: rows per page.
   */
  protected function display(TableState $state, array $pageSizes): array {
    $hidden = $state->toQuery();
    unset($hidden['page_size'], $hidden['page']);
    return [
      'page_sizes' => array_values($pageSizes),
      'current' => $state->pageSize,
      'hidden' => DataPreviewBuilder::flattenQuery($hidden),
    ];
  }

  /**
   * Chips for active conditions and hidden columns, with a clear-all link.
   */
  protected function chips(TableState $state, array $fields, ?Url $baseUrl): array {
    $labels = OperatorRegistry::labels();
    $items = [];
    foreach ($state->conditions as $index => $condition) {
      $text = $this->label($condition->property, $fields) . ' ' . $labels[$condition->operator] . ' ' . $condition->value;
      $items[] = [
        'label' => $text,
        'url' => $this->stateUrl($baseUrl, $state->withoutCondition($index)),
        'remove_label' => (string) $this->t('Remove filter: @filter', ['@filter' => $text]),
      ];
    }
    $hiddenCount = count($fields) - count($state->visibleColumns($fields));
    if ($hiddenCount > 0) {
      $items[] = [
        'label' => (string) $this->formatPlural($hiddenCount, '1 column hidden', '@count columns hidden'),
        'url' => $this->stateUrl($baseUrl, $state->withColumns([])),
        'remove_label' => (string) $this->t('Show all columns'),
      ];
    }
    return [
      'items' => $items,
      'clear_all_url' => $items ? $this->stateUrl($baseUrl, $state->withConditions([])->withColumns([])) : '',
    ];
  }

  /**
   * Share panel: filtered CSV download and the canonical link to copy.
   *
   * The download goes to DKAN's streaming download route with the state
   * translated to the datastore query shape (no limit: every matching row).
   */
  protected function share(TableState $state, ?Url $baseUrl, ?string $resourceId): array {
    $download = '';
    if ($resourceId) {
      $download = Url::fromRoute(self::DOWNLOAD_ROUTE, ['identifier' => $resourceId], [
        'query' => $state->toDatastoreQuery($this->likeEscaper),
      ])->toString();
    }
    $copy = '';
    if ($baseUrl) {
      $url = clone $baseUrl;
      $copy = $url->setOption('query', $state->toQuery())->setAbsolute()->toString();
    }
    return [
      'download_url' => $download,
      'copy_url' => $copy,
    ];
  }

  /**
   * Translated labels used by the template.
   */
  protected function labels(): array {
    return [
      'filters' => (string) $this->t('Filter Dataset'),
      'columns' => (string) $this->t('Manage Columns'),
      'display' => (string) $this->t('Display Settings'),
      'share' => (string) $this->t('Share'),
    ];
  }

  /**
   * Operator options as value/label pairs.
   */
  protected function operatorOptions(array $options): array {
    $list = [];
    foreach ($options as $value => $label) {
      $list[] = ['value' => $value, 'label' => (string) $label];
    }
    return $list;
  }

  /**
   * Column label.
   *
   * The labels option (dictionary title or original header), else the
   * schema description, else the machine name.
   */
  protected function label(string $name, array $fields): string {
    return (string) ($this->labels[$name] ?? $fields[$name]['description'] ?? $name);
  }

  /**
   * URL string for a state on the base URL ('' without a base URL).
   */
  protected function stateUrl(?Url $baseUrl, TableState $state): string {
    if (!$baseUrl) {
      return '';
    }
    $url = clone $baseUrl;
    return $url->setOption('query', $state->toQuery())->toString();
  }

}
