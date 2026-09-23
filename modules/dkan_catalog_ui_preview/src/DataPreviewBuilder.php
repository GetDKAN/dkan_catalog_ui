<?php

namespace Drupal\dkan_catalog_ui_preview;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceResult;
use Drupal\dkan_catalog_ui_preview\Toolbar\ToolbarBuilder;
use Drupal\dkan_catalog_ui_preview\Toolbar\ToolbarBuilderInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds render arrays for data tables.
 *
 * Every link and form carries a TableState serialized to the canonical
 * query string; the render array is max-age 0 and meant to live inside a
 * lazy-builder placeholder (see ::lazyBuild()).
 */
class DataPreviewBuilder implements DataPreviewBuilderInterface {

  use StringTranslationTrait;

  /**
   * Number of page links shown around the current page.
   */
  const PAGER_WINDOW = 5;

  /**
   * Class on numeric header and body cells.
   */
  const NUMBER_CLASS = 'dcu-table__cell--number';

  /**
   * Frictionless field types rendered as numbers.
   */
  const DICTIONARY_NUMBER_TYPES = ['integer', 'number'];

  /**
   * Id of the file caption, referenced by the table's aria-labelledby.
   */
  const CAPTION_ID = 'dcu-table-caption';

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly RequestStack $requestStack,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TabularDistributionsInterface $tabularDistributions,
    protected readonly DataSourceInterface $dataSource,
    protected readonly ImportStatusMessageInterface $statusMessage,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelInterface $logger,
    protected readonly LikeEscaper $likeEscaper,
    protected readonly ToolbarBuilderInterface $toolbarBuilder,
    protected readonly DictionaryHeadersInterface $dictionaryHeaders,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['lazyBuild'];
  }

  /**
   * {@inheritdoc}
   */
  public function lazyBuild(int $nid): array {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return [];
    }
    $tables = $this->tabularDistributions->forNode($node);
    if (!$tables) {
      return [];
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dcu-table-region']],
      '#cache' => [
        'tags' => $this->tabularDistributions->cacheTags($node),
        'max-age' => 0,
      ],
    ];

    $query = $this->currentRequest()->query->all();
    $index = TableState::tableIndex($query, count($tables));
    $panel = $query['panel'] ?? NULL;
    $resourceId = $tables[$index]['resource_id'];
    $options = [
      'caption' => $tables[$index]['label'] ?: NULL,
      'base_url' => $node->toUrl(),
      'apply_url' => Url::fromRoute('dkan_catalog_ui_preview.apply', ['dataset' => $node->uuid()]),
      'fragment_url' => Url::fromRoute('dkan_catalog_ui_preview.fragment', ['dataset' => $node->uuid()]),
      'max_page_size' => $this->rowsLimit(),
      'tables' => $tables,
      'panel' => is_string($panel) ? $panel : NULL,
    ];
    try {
      $fields = $this->dataSource->getSchema($resourceId)['fields'] ?? [];
      $state = TableState::fromQuery($query, $fields, count($tables), $this->rowsLimit());
      $table = $this->build($this->dataSource, $resourceId, $state, $options);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Data table error for resource @id: @message', [
        '@id' => $resourceId,
        '@message' => $e->getMessage(),
      ]);
      $table = NULL;
    }
    $build['table'] = $table ?? $this->buildUnavailable($resourceId, new TableState($index), $options);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function build(DataSourceInterface $dataSource, string $resource_id, TableState $state, array $options = []): ?array {
    $options += [
      'caption' => NULL,
      'base_url' => NULL,
      'apply_url' => NULL,
      'max_page_size' => NULL,
      'attributes' => [],
      'tables' => [],
      'panel' => NULL,
      'panels' => TRUE,
      'fragment_url' => NULL,
    ];

    $schema = $dataSource->getSchema($resource_id);
    $fields = $schema['fields'] ?? [];
    if (!$fields) {
      return NULL;
    }
    $baseUrl = $options['base_url'] ?? $this->currentPathUrl();
    $applyUrl = $options['apply_url'] ?? $baseUrl;

    $columns = $state->visibleColumns($fields);
    $dictionary = $this->dictionaryHeaders->labels($resource_id, $fields);
    $labels = [];
    $numeric = [];
    foreach ($fields as $name => $field) {
      $labels[$name] = ($dictionary[$name]['title'] ?? '') !== ''
        ? $dictionary[$name]['title']
        : ($field['description'] ?? $name);
      // The datastore stores CSV values as text until a dictionary types
      // them, so the dictionary's own type counts too.
      if (OperatorRegistry::category($field) === OperatorRegistry::CATEGORY_NUMBER
        || in_array($dictionary[$name]['type'] ?? '', self::DICTIONARY_NUMBER_TYPES, TRUE)) {
        $numeric[] = $name;
      }
    }
    $conditions = array_map(fn (Condition $c) => $c->toQueryCondition($this->likeEscaper), $state->conditions);
    $properties = $state->columns;

    $result = $dataSource->fetchData($resource_id, $state->pageSize, $state->offset(), $state->sort, $state->direction, $conditions, $properties);
    // Clamp an out-of-range page (hand-edited URL) to the last valid page.
    if ($result->totalCount > 0 && $state->offset() >= $result->totalCount) {
      $state = $state->withPage((int) ceil($result->totalCount / $state->pageSize));
      $result = $dataSource->fetchData($resource_id, $state->pageSize, $state->offset(), $state->sort, $state->direction, $conditions, $properties);
    }

    $panels = [
      ToolbarBuilder::PANEL_FILTERS,
      ToolbarBuilder::PANEL_COLUMNS,
      ToolbarBuilder::PANEL_DISPLAY,
      ToolbarBuilder::PANEL_SHARE,
    ];
    $panel = in_array($options['panel'], $panels, TRUE) ? $options['panel'] : NULL;
    $toolbar = $this->toolbarBuilder->build($state, $fields, [
      'base_url' => $baseUrl,
      'apply_url' => $applyUrl,
      'tables' => $options['tables'],
      'panel' => $panel,
      'page_sizes' => TableState::pageSizes($options['max_page_size']),
      'summary' => $this->buildResultSummary($state->offset(), count($result->rows), $result->totalCount),
      'caption' => $options['caption'],
      'panels' => (bool) $options['panels'],
      'fragment_url' => $options['fragment_url'],
      'resource_id' => $resource_id,
      'labels' => $labels,
    ]);

    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui_preview:data-table',
      '#cache' => [
        'max-age' => 0,
        'tags' => $this->dictionaryHeaders->cacheTags($resource_id),
      ],
      '#props' => [
        'attributes' => $options['attributes'] + ['id' => 'dcu-table', 'tabindex' => '-1'],
      ],
      '#slots' => [
        'toolbar' => $toolbar,
        'table' => [
          '#type' => 'table',
          '#header' => $this->buildHeader($labels, $dictionary, $columns, $state, $baseUrl, $numeric),
          '#rows' => $this->buildRows($result->rows, $columns, $numeric),
          '#sticky' => FALSE,
          '#empty' => $this->t('No data available.'),
          '#attributes' => ['class' => ['dcu-table__table']]
          + ((string) ($options['caption'] ?? '') !== '' ? ['aria-labelledby' => self::CAPTION_ID] : []),
        ],
        // Slots must be render arrays; an empty pager still needs one.
        'pager' => $this->buildPager($state, $result, $baseUrl) ?: ['#markup' => ''],
      ],
      '#attached' => [
        'library' => ['dkan_catalog_ui_preview/preview'],
      ],
    ];
  }

  /**
   * The component with only the download link, chooser and status message.
   *
   * Used when the selected distribution has no queryable table (yet), so the
   * user can still switch tables or download the file.
   */
  protected function buildUnavailable(string $resource_id, TableState $state, array $options): array {
    $toolbar = $this->toolbarBuilder->build($state, [], [
      'base_url' => $options['base_url'],
      'apply_url' => $options['apply_url'],
      'fragment_url' => $options['fragment_url'],
      'tables' => $options['tables'],
      'caption' => $options['caption'],
      'panels' => FALSE,
    ]);
    return [
      '#type' => 'component',
      '#component' => 'dkan_catalog_ui_preview:data-table',
      '#cache' => ['max-age' => 0],
      '#props' => [
        'attributes' => ['id' => 'dcu-table', 'tabindex' => '-1'],
      ],
      '#slots' => [
        'toolbar' => $toolbar,
        'table' => $this->statusMessage->build($resource_id),
        'pager' => ['#markup' => ''],
      ],
    ];
  }

  /**
   * Build sortable table headers.
   *
   * @param array $labels
   *   Display label per column machine name.
   * @param array $dictionary
   *   Dictionary entries per machine name (title, description), if any.
   * @param string[] $columns
   *   Visible columns in order.
   * @param \Drupal\dkan_catalog_ui_preview\TableState $state
   *   Current state.
   * @param \Drupal\Core\Url $baseUrl
   *   URL that sort links carry state on.
   * @param string[] $numeric
   *   Columns whose values are numbers (right-aligned by the theme).
   */
  protected function buildHeader(array $labels, array $dictionary, array $columns, TableState $state, Url $baseUrl, array $numeric = []): array {
    $header = [];
    foreach ($columns as $machineName) {
      $label = $labels[$machineName] ?? $machineName;
      $active = ($state->sort === $machineName);
      $classes = in_array($machineName, $numeric, TRUE) ? [self::NUMBER_CLASS] : [];
      $nextDirection = ($active && $state->direction === 'asc') ? 'desc' : 'asc';

      $cellData = [
        'link' => [
          '#type' => 'link',
          '#title' => $label,
          '#url' => $this->stateUrl($baseUrl, $state->withSort($machineName, $nextDirection)),
          '#attributes' => [
            'aria-label' => $this->t('Sort by @label, @direction', [
              '@label' => $label,
              '@direction' => $nextDirection === 'asc' ? $this->t('ascending') : $this->t('descending'),
            ]),
          ],
        ],
      ];
      // Dictionary description as a tooltip; the machine name stays
      // discoverable on the cell.
      if (!empty($dictionary[$machineName]['description'])) {
        $cellData['link']['#attributes']['title'] = $dictionary[$machineName]['description'];
      }

      $cell = ['data' => $cellData, 'data-column' => $machineName];
      if ($classes) {
        $cell['class'] = $classes;
      }
      if ($active) {
        $cellData['indicator'] = [
          '#type' => 'component',
          '#component' => 'dkan_catalog_ui:icon',
          '#props' => [
            'name' => $state->direction === 'asc' ? 'sort-asc' : 'sort-desc',
            'size' => 12,
          ],
        ];
        $cell = [
          'data' => $cellData,
          'data-column' => $machineName,
          'aria-sort' => $state->direction === 'asc' ? 'ascending' : 'descending',
          'class' => array_merge(['is-active'], $classes),
        ];
      }
      $header[] = $cell;
    }
    return $header;
  }

  /**
   * Build table rows from result data; numeric cells carry NUMBER_CLASS.
   */
  protected function buildRows(array $rows, array $columns, array $numeric = []): array {
    $tableRows = [];
    foreach ($rows as $row) {
      $cells = [];
      foreach ($columns as $fieldName) {
        $value = $row->{$fieldName} ?? '';
        $cells[] = in_array($fieldName, $numeric, TRUE)
          ? ['data' => $value, 'class' => [self::NUMBER_CLASS]]
          : $value;
      }
      $tableRows[] = $cells;
    }
    return $tableRows;
  }

  /**
   * Build the pager (1-based `page`), reusing core pager class names.
   */
  protected function buildPager(TableState $state, DataSourceResult $result, Url $baseUrl): array {
    $totalPages = (int) ceil($result->totalCount / $state->pageSize);
    if ($totalPages <= 1) {
      return [];
    }
    $current = $state->page;
    $half = (int) floor(self::PAGER_WINDOW / 2);
    $start = max(1, min($current - $half, $totalPages - self::PAGER_WINDOW + 1));
    $end = min($totalPages, $start + self::PAGER_WINDOW - 1);

    $href = fn (int $page) => $this->stateUrl($baseUrl, $state->withPage($page))->toString();
    $items = [];
    if ($current > 1) {
      $items['first'] = ['href' => $href(1)];
      $items['previous'] = ['href' => $href($current - 1)];
    }
    for ($page = $start; $page <= $end; $page++) {
      $items['pages'][$page] = ['href' => $href($page)];
    }
    if ($current < $totalPages) {
      $items['next'] = ['href' => $href($current + 1)];
      $items['last'] = ['href' => $href($totalPages)];
    }

    return [
      '#theme' => 'dkan_catalog_ui_preview_pager',
      '#items' => $items,
      '#current' => $current,
      '#total' => $totalPages,
      '#ellipses' => ['previous' => $start > 1, 'next' => $end < $totalPages],
    ];
  }

  /**
   * Build the result summary markup.
   */
  protected function buildResultSummary(int $offset, int $rowCount, int $totalCount): array {
    $summary = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['dcu-table__summary'], 'aria-live' => 'polite'],
    ];
    if ($totalCount === 0) {
      $summary['#value'] = $this->t('No rows match.');
      return $summary;
    }
    $summary['#value'] = $this->t('Displaying @start - @end of @total rows', [
      '@start' => number_format($offset + 1),
      '@end' => number_format($offset + $rowCount),
      '@total' => number_format($totalCount),
    ]);
    return $summary;
  }

  /**
   * A copy of the base URL carrying a state's canonical query.
   */
  protected function stateUrl(Url $baseUrl, TableState $state): Url {
    $url = clone $baseUrl;
    return $url->setOption('query', $state->toQuery());
  }

  /**
   * Flatten a nested query into hidden input names ("conditions[0][value]").
   */
  public static function flattenQuery(array $query, string $prefix = ''): array {
    $flat = [];
    foreach ($query as $key => $value) {
      $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
      if (is_array($value)) {
        $flat += self::flattenQuery($value, $name);
      }
      else {
        $flat[$name] = (string) $value;
      }
    }
    return $flat;
  }

  /**
   * The current request, or a stand-in outside a request (drush, queues).
   */
  protected function currentRequest(): Request {
    return $this->requestStack->getCurrentRequest() ?? Request::create('/');
  }

  /**
   * Unrouted URL for the current path (works outside a routed request).
   */
  protected function currentPathUrl(): Url {
    return Url::fromUri('base:' . ltrim($this->currentRequest()->getPathInfo(), '/'));
  }

  /**
   * The datastore row limit, capping the page size.
   */
  protected function rowsLimit(): ?int {
    $limit = (int) $this->configFactory->get('dkan_datastore.settings')->get('rows_limit');
    return $limit > 0 ? $limit : NULL;
  }

}
