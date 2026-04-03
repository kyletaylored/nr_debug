<?php

namespace Drupal\nr_debug\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * New Relic debug status report.
 */
class NrDebugController extends ControllerBase {

  public function report(): array {
    $build = [];

    $build['extension'] = $this->buildExtensionSection();
    $build['ini'] = $this->buildIniSection();
    $build['linking'] = $this->buildLinkingSection();
    $build['monolog'] = $this->buildMonologSection();
    $build['log_tail'] = $this->buildLogTailSection();

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Extension status
  // ---------------------------------------------------------------------------

  private function buildExtensionSection(): array {
    $loaded = extension_loaded('newrelic');
    $version = $loaded ? phpversion('newrelic') : NULL;

    $rows = [
      ['Extension loaded', $loaded ? $this->t('Yes') : $this->t('No')],
      ['Extension version', $version ?: $this->t('N/A')],
      ['newrelic_get_linking_metadata()', function_exists('newrelic_get_linking_metadata') ? $this->t('Available') : $this->t('Not available')],
      ['newrelic_notice_error()', function_exists('newrelic_notice_error') ? $this->t('Available') : $this->t('Not available')],
      ['newrelic_add_custom_parameter()', function_exists('newrelic_add_custom_parameter') ? $this->t('Available') : $this->t('Not available')],
    ];

    return [
      '#type' => 'details',
      '#title' => $this->t('PHP Extension'),
      '#open' => TRUE,
      'table' => $this->buildTable(['Setting', 'Value'], $rows),
    ];
  }

  // ---------------------------------------------------------------------------
  // INI settings
  // ---------------------------------------------------------------------------

  private function buildIniSection(): array {
    $keys = [
      'newrelic.enabled',
      'newrelic.appname',
      'newrelic.license',
      'newrelic.loglevel',
      'newrelic.logfile',
      'newrelic.application_logging.enabled',
      'newrelic.application_logging.forwarding.enabled',
      'newrelic.application_logging.forwarding.log_level',
      'newrelic.application_logging.local_decorating.enabled',
      'newrelic.distributed_tracing_enabled',
      'newrelic.error_collector.enabled',
      'newrelic.daemon.port',
      'newrelic.daemon.address',
    ];

    $rows = [];
    foreach ($keys as $key) {
      $val = ini_get($key);
      // Mask the license key.
      if ($key === 'newrelic.license' && $val) {
        $val = substr($val, 0, 4) . str_repeat('*', max(0, strlen($val) - 8)) . substr($val, -4);
      }
      $rows[] = [$key, $val !== FALSE && $val !== '' ? $val : $this->t('(not set)')];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('INI Settings'),
      '#open' => TRUE,
      'table' => $this->buildTable(['Directive', 'Value'], $rows),
    ];
  }

  // ---------------------------------------------------------------------------
  // Linking metadata
  // ---------------------------------------------------------------------------

  private function buildLinkingSection(): array {
    $rows = [];
    $note = '';

    if (function_exists('newrelic_get_linking_metadata')) {
      $data = newrelic_get_linking_metadata();
      if (!empty($data)) {
        foreach ($data as $key => $value) {
          $rows[] = [$key, $value];
        }
      }
      else {
        $note = $this->t('newrelic_get_linking_metadata() returned empty — this is expected in CLI/drush context. Trace IDs are only populated during active web transactions.');
      }
    }
    else {
      $note = $this->t('newrelic_get_linking_metadata() is not available. New Relic extension may not be loaded or is < v9.3.');
    }

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Linking Metadata (Logs in Context)'),
      '#open' => TRUE,
    ];

    if ($note) {
      $build['note'] = ['#markup' => '<p>' . $note . '</p>'];
    }
    if ($rows) {
      $build['table'] = $this->buildTable(['Key', 'Value'], $rows);
    }

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Monolog chain
  // ---------------------------------------------------------------------------

  private function buildMonologSection(): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Monolog Configuration'),
      '#open' => TRUE,
    ];

    try {
      $container = \Drupal::getContainer();
      $params = $container->getParameter('monolog.channel_handlers');
      $processors = $container->getParameter('monolog.processors');

      // Channel → handlers table.
      $rows = [];
      foreach ($params as $channel => $handlers) {
        $rows[] = [$channel, implode(', ', (array) $handlers)];
      }
      $build['channels_label'] = ['#markup' => '<h3>' . $this->t('Channel Handlers') . '</h3>'];
      $build['channels'] = $this->buildTable(['Channel', 'Handlers'], $rows);

      // Processors.
      $build['processors_label'] = ['#markup' => '<h3>' . $this->t('Processors') . '</h3>'];
      $build['processors'] = $this->buildTable(['Processor'], array_map(fn($p) => [$p], $processors));

      // Handler service existence check.
      $handler_rows = [];
      $all_handlers = array_unique(array_merge(...array_map(fn($h) => (array) $h, array_values($params))));
      foreach ($all_handlers as $handler) {
        $service_id = 'monolog.handler.' . $handler;
        $exists = $container->has($service_id);
        $class = '';
        if ($exists) {
          try {
            $class = get_class($container->get($service_id));
          }
          catch (\Throwable $e) {
            $class = $this->t('Error: @msg', ['@msg' => $e->getMessage()]);
          }
        }
        $handler_rows[] = [
          $service_id,
          $exists ? $this->t('Yes') : $this->t('No'),
          $class,
        ];
      }
      $build['handlers_label'] = ['#markup' => '<h3>' . $this->t('Handler Services') . '</h3>'];
      $build['handlers'] = $this->buildTable(['Service ID', 'Registered', 'Class'], $handler_rows);
    }
    catch (\Throwable $e) {
      $build['error'] = [
        '#markup' => '<p>' . $this->t('Could not read Monolog container parameters: @msg', ['@msg' => $e->getMessage()]) . '</p>',
      ];
    }

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Log file tail
  // ---------------------------------------------------------------------------

  private function buildLogTailSection(): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Log Files (last 50 lines)'),
      '#open' => FALSE,
    ];

    $nr_log = ini_get('newrelic.logfile') ?: '/logs/php/newrelic.log';

    // Rotating file handler writes application-YYYY-MM-DD.log; find the most
    // recent one. Fall back to the plain name if nothing dated exists.
    $app_log_glob = glob('/logs/php/application-*.log');
    if ($app_log_glob) {
      rsort($app_log_glob);
      $app_log = $app_log_glob[0];
    }
    else {
      $app_log = '/logs/php/application.log';
    }

    foreach ([$app_log, $nr_log] as $path) {
      $key = str_replace('/', '_', ltrim($path, '/'));
      if (file_exists($path) && is_readable($path)) {
        $lines = array_slice(file($path), -50);
        $build[$key] = [
          '#type' => 'details',
          '#title' => $path,
          '#open' => $path === $app_log,
          'content' => [
            '#markup' => '<pre style="font-size:0.8em;overflow:auto;max-height:400px">'
              . htmlspecialchars(implode('', $lines))
              . '</pre>',
          ],
        ];
      }
      else {
        $build[$key] = [
          '#markup' => '<p>' . $this->t('@path — not found or not readable.', ['@path' => $path]) . '</p>',
        ];
      }
    }

    // List all dated application logs found.
    if (!empty($app_log_glob)) {
      $file_list = implode('<br>', array_map('htmlspecialchars', $app_log_glob));
      $build['all_logs'] = [
        '#markup' => '<p><strong>' . $this->t('All rotating log files found:') . '</strong><br>' . $file_list . '</p>',
      ];
    }

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Helper
  // ---------------------------------------------------------------------------

  private function buildTable(array $headers, array $rows): array {
    return [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No data.'),
    ];
  }

}
