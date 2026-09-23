<?php

namespace Drupal\dkan_catalog_ui_preview\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\dkan_catalog_ui_preview\DataSource\DataSourceInterface;
use Drupal\dkan_catalog_ui_preview\TableState;
use Drupal\dkan_catalog_ui_preview\TabularDistributionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalizes toolbar form input and redirects to the canonical dataset URL.
 *
 * Every toolbar form is a GET form whose action is this route. State is
 * carried as the canonical parameters (`columns` may also arrive as the
 * checkbox array `columns[]`); action parameters are consumed here:
 * - remove (condition index), reset_filters: filter panel.
 * - move_up, move_down (column name), reset_columns: manage columns panel.
 * - panel (filters|columns|display): which panel to reopen after redirect.
 * - close: sent by each panel's primary action (Apply, Save), dropping
 *   `panel` so the panel closes once the change is applied.
 * Everything else is dropped. Conditions, sort, page size and table changes
 * land on page 1 unless the form sends `page`.
 *
 * With `Accept: application/json` (the progressive-enhancement script) the
 * canonical URL is returned as JSON instead of a redirect.
 */
class TableApplyController implements ContainerInjectionInterface {

  use DatasetRouteTrait;

  const PANELS = ['filters', 'columns', 'display', 'share'];

  const TABLE_ID = 'dcu-table';

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly TabularDistributionsInterface $tabularDistributions,
    protected readonly DataSourceInterface $dataSource,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('dkan_catalog_ui.preview.tabular_distributions'),
      $container->get('dkan_catalog_ui.preview.data_source.database'),
      $container->get('config.factory'),
    );
  }

  /**
   * Redirects to the canonical dataset URL for the submitted state.
   */
  public function apply(string $dataset, Request $request): Response {
    $node = $this->loadDataset($this->entityRepository, $dataset);
    $query = $request->query->all();

    $tables = $this->tabularDistributions->forNode($node);
    $table = TableState::tableIndex($query, count($tables));
    $fields = $tables ? $this->dataSource->getSchema($tables[$table]['resource_id'])['fields'] ?? [] : [];
    $state = TableState::fromQuery($query, $fields, max(count($tables), 1), $this->rowsLimit());
    $state = $this->applyActions($state, $query, $fields);

    $redirectQuery = $state->toQuery();
    $panel = $query['panel'] ?? NULL;
    // The primary action closes the panel; every other control reopens it.
    if (empty($query['close']) && is_string($panel) && in_array($panel, self::PANELS, TRUE)) {
      $redirectQuery['panel'] = $panel;
    }

    $url = $node->toUrl('canonical', ['query' => $redirectQuery, 'fragment' => self::TABLE_ID]);
    if (in_array('application/json', $request->getAcceptableContentTypes(), TRUE)) {
      $response = new JsonResponse(['url' => $url->toString()]);
      $response->setPrivate()->setMaxAge(0);
      return $response;
    }
    // A plain RedirectResponse is never cached: the target depends on the
    // full query string.
    return new RedirectResponse($url->toString(), 302);
  }

  /**
   * Apply the action parameters to a parsed state.
   */
  protected function applyActions(TableState $state, array $query, array $fields): TableState {
    if (!empty($query['reset_filters'])) {
      $state = $state->withConditions([]);
    }
    elseif (isset($query['remove']) && is_scalar($query['remove']) && ctype_digit((string) $query['remove'])) {
      $state = $state->withoutCondition((int) $query['remove']);
    }

    if (!empty($query['reset_columns'])) {
      $state = $state->withColumns([]);
    }
    foreach (['move_up' => -1, 'move_down' => 1] as $action => $delta) {
      $column = $query[$action] ?? NULL;
      if (is_string($column) && $column !== '') {
        $state = $state->withColumns($this->moveColumn($state->visibleColumns($fields), $column, $delta, $fields));
      }
    }

    // Any state change other than columns starts over on page 1.
    if (!isset($query['page'])) {
      $state = $state->withPage(1);
    }
    return $state;
  }

  /**
   * Move a column one step in a list; unknown names leave it unchanged.
   */
  protected function moveColumn(array $columns, string $column, int $delta, array $fields): array {
    $index = array_search($column, $columns, TRUE);
    $target = $index === FALSE ? -1 : $index + $delta;
    if ($index === FALSE || $target < 0 || $target >= count($columns)) {
      return TableState::normalizeColumns($columns, $fields);
    }
    [$columns[$index], $columns[$target]] = [$columns[$target], $columns[$index]];
    return TableState::normalizeColumns($columns, $fields);
  }

  /**
   * The datastore row limit, capping the page size.
   */
  protected function rowsLimit(): ?int {
    $limit = (int) $this->configFactory->get('dkan_datastore.settings')->get('rows_limit');
    return $limit > 0 ? $limit : NULL;
  }

}
