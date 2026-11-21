<?php

namespace Drupal\dkan_catalog_ui_preview\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drops array `sort` / `order` query values on routes that render the table.
 *
 * Core's table preprocess reads both with a scalar InputBag::get() for any
 * table with a header (TableSort::getContextFromRequest()), so `?sort[]=x`
 * throws and the page returns 500. TableState ignores non-string values.
 */
class TableSortQuerySubscriber implements EventSubscriberInterface {

  /**
   * Routes whose responses render the data table.
   */
  const ROUTES = ['entity.node.canonical', 'dkan_catalog_ui_preview.fragment'];

  /**
   * Removes non-scalar `sort` and `order` values.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!in_array($request->attributes->get('_route'), self::ROUTES, TRUE)) {
      return;
    }
    $query = $request->query->all();
    foreach (['sort', 'order'] as $key) {
      if (isset($query[$key]) && !is_scalar($query[$key])) {
        $request->query->remove($key);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (32), which sets _route.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

}
