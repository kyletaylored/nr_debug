<?php

namespace Drupal\nr_debug\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the Monolog BufferHandler at request termination.
 *
 * BufferHandler accumulates log records in memory and sends them in a single
 * batch at the end of the request. In Drupal/PHP-FPM environments, the handler
 * destructor is not reliably called, so we flush explicitly on kernel.terminate.
 */
class MonologFlushSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected \Monolog\Handler\BufferHandler $bufferHandler,
  ) {
    // Register a shutdown function as a belt-and-suspenders fallback for
    // PHP-FPM environments where KernelEvents::TERMINATE may not fire or
    // the handler destructor is not called before the worker is recycled.
    register_shutdown_function([$this, 'flush']);
  }

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => ['flush', -1024],
    ];
  }

  public function flush(): void {
    $this->bufferHandler->close();
  }

}
