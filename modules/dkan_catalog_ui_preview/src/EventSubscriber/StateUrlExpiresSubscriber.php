<?php

namespace Drupal\dkan_catalog_ui_preview\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bounds page-cache lifetime of dataset table-state URLs.
 *
 * The page cache keys on the full URI and stores entries permanently unless
 * the response carries a future Expires (PageCache::storeResponse()). Every
 * table state, and any junk or reordered query, is its own entry, so state
 * variants of the dataset page and the fragment expire after TTL seconds; the
 * canonical URL keeps core's behaviour.
 */
class StateUrlExpiresSubscriber implements EventSubscriberInterface {

  /**
   * Page-cache lifetime of a state URL, in seconds.
   */
  const TTL = 3600;

  public function __construct(
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Sets Expires on successful state-variant responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $response = $event->getResponse();
    if (!$request->isMethodCacheable() || $response->getStatusCode() !== 200 || $request->getQueryString() === NULL) {
      return;
    }
    $route = $request->attributes->get('_route');
    $node = $request->attributes->get('node');
    $isDataset = $route === 'entity.node.canonical' && $node instanceof NodeInterface && $node->bundle() === 'data';
    if (!$isDataset && $route !== 'dkan_catalog_ui_preview.fragment') {
      return;
    }
    $response->setExpires(new \DateTimeImmutable('@' . ($this->time->getRequestTime() + self::TTL)));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber::onRespond() (0), which only fills in a
    // past Expires when none is set.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

}
