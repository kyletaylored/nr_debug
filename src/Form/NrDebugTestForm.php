<?php

namespace Drupal\nr_debug\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Test form for New Relic debug tools.
 *
 * Three independent actions:
 *  1. Send a test log via Drupal's logger (goes through Monolog pipeline).
 *  2. Send a test log directly to the NR Logs API via curl (bypasses Monolog).
 *  3. Query NR NerdGraph for recent logs for this app (requires User API key).
 */
class NrDebugTestForm extends FormBase {

  public function getFormId(): string {
    return 'nr_debug_test_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    // -------------------------------------------------------------------------
    // 1. Drupal logger test
    // -------------------------------------------------------------------------
    $form['drupal_logger'] = [
      '#type' => 'details',
      '#title' => $this->t('1. Send via Drupal Logger (Monolog pipeline)'),
      '#open' => TRUE,
      '#description' => $this->t('Fires a log record through <code>\Drupal::logger()</code>, which routes through the full Monolog handler chain configured in monolog.services.yml.'),
    ];
    $form['drupal_logger']['drupal_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Channel'),
      '#default_value' => 'nr_debug',
      '#size' => 30,
    ];
    $form['drupal_logger']['drupal_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Log level'),
      '#options' => [
        'debug' => 'DEBUG (100)',
        'info' => 'INFO (200)',
        'notice' => 'NOTICE (250)',
        'warning' => 'WARNING (300)',
        'error' => 'ERROR (400)',
        'critical' => 'CRITICAL (500)',
        'alert' => 'ALERT (550)',
        'emergency' => 'EMERGENCY (600)',
      ],
      '#default_value' => 'warning',
    ];
    $form['drupal_logger']['drupal_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message'),
      '#default_value' => 'New Relic debug test log — ' . date('Y-m-d H:i:s'),
      '#maxlength' => 255,
    ];
    $form['drupal_logger']['send_drupal'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send via Drupal Logger'),
      '#submit' => ['::submitDrupalLogger'],
    ];

    // -------------------------------------------------------------------------
    // 2. Direct Logs API test (bypasses Monolog)
    // -------------------------------------------------------------------------
    $license_key = ini_get('newrelic.license') ?: '';
    $appname = ini_get('newrelic.appname') ?: '';

    $form['direct_api'] = [
      '#type' => 'details',
      '#title' => $this->t('2. Send Directly to NR Logs API (bypasses Monolog)'),
      '#open' => TRUE,
      '#description' => $this->t('POSTs a log record directly to <code>log-api.newrelic.com/log/v1</code> using curl. Useful for isolating whether the issue is in the Monolog pipeline or the API connection.'),
    ];
    $form['direct_api']['license_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('License Key'),
      '#default_value' => $license_key,
      '#description' => $this->t('Ingest license key (NRAL). Pre-filled from ini_get("newrelic.license").'),
      '#size' => 50,
    ];
    $form['direct_api']['direct_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message'),
      '#default_value' => 'Direct NR Logs API test — ' . date('Y-m-d H:i:s'),
      '#maxlength' => 255,
    ];
    $form['direct_api']['direct_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Level'),
      '#options' => ['DEBUG' => 'DEBUG', 'INFO' => 'INFO', 'WARNING' => 'WARNING', 'ERROR' => 'ERROR'],
      '#default_value' => 'WARNING',
    ];
    $form['direct_api']['direct_appname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App name (entity.name)'),
      '#default_value' => $appname,
      '#size' => 50,
    ];
    $form['direct_api']['send_direct'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Directly to NR Logs API'),
      '#submit' => ['::submitDirectApi'],
    ];

    // -------------------------------------------------------------------------
    // 3. NerdGraph query for recent logs
    // -------------------------------------------------------------------------
    $form['nerdgraph'] = [
      '#type' => 'details',
      '#title' => $this->t('3. Query NR NerdGraph for Recent Logs'),
      '#open' => TRUE,
      '#description' => $this->t('Uses the NerdGraph API to fetch the last 10 log records for this app. Requires a User API key (starts with NRAK-) — the ingest license key will not work here.'),
    ];
    $form['nerdgraph']['user_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User API Key (NRAK-...)'),
      '#default_value' => '',
      '#size' => 60,
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['nerdgraph']['account_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Account ID'),
      '#default_value' => '',
      '#size' => 20,
      '#description' => $this->t('Find this in NR UI under your account name.'),
    ];
    $form['nerdgraph']['nrql_query'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NRQL'),
      '#default_value' => "SELECT * FROM Log WHERE entity.name = '" . $appname . "' SINCE 30 MINUTES AGO LIMIT 10",
      '#maxlength' => 500,
      '#size' => 80,
    ];
    $form['nerdgraph']['query_nerdgraph'] = [
      '#type' => 'submit',
      '#value' => $this->t('Query NerdGraph'),
      '#submit' => ['::submitNerdGraph'],
    ];

    // -------------------------------------------------------------------------
    // 4. Monolog pipeline probe
    // -------------------------------------------------------------------------
    $form['pipeline'] = [
      '#type' => 'details',
      '#title' => $this->t('4. Probe Monolog Pipeline'),
      '#open' => TRUE,
      '#description' => $this->t('Instantiates each configured handler directly and reports whether it can be constructed without errors. Also traces a log record through the pipeline and captures any exceptions thrown during formatting or sending.'),
    ];
    $form['pipeline']['probe_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Channel to probe'),
      '#default_value' => 'nr_debug',
      '#size' => 30,
    ];
    $form['pipeline']['probe_pipeline'] = [
      '#type' => 'submit',
      '#value' => $this->t('Probe Pipeline'),
      '#submit' => ['::submitProbePipeline'],
    ];

    // Results area.
    $results = $form_state->get('results');
    if ($results !== NULL) {
      $form['results'] = [
        '#type' => 'details',
        '#title' => $this->t('Results'),
        '#open' => TRUE,
        'output' => [
          '#markup' => '<pre style="font-size:0.85em;overflow:auto;max-height:500px">'
            . htmlspecialchars($results)
            . '</pre>',
        ],
      ];
    }

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Submit: Drupal logger
  // ---------------------------------------------------------------------------

  public function submitDrupalLogger(array &$form, FormStateInterface $form_state): void {
    $channel = $form_state->getValue('drupal_channel') ?: 'nr_debug';
    $level = $form_state->getValue('drupal_level') ?: 'warning';
    $message = $form_state->getValue('drupal_message') ?: 'NR debug test';

    $logger = \Drupal::logger($channel);
    $logger->{$level}('@message', ['@message' => $message]);

    $this->messenger()->addStatus($this->t(
      'Log sent via Drupal logger: channel=@channel level=@level message="@msg". Check New Relic Logs and your rotating log file.',
      ['@channel' => $channel, '@level' => $level, '@msg' => $message]
    ));
    $form_state->setRebuild(TRUE);
  }

  // ---------------------------------------------------------------------------
  // Submit: Direct API
  // ---------------------------------------------------------------------------

  public function submitDirectApi(array &$form, FormStateInterface $form_state): void {
    $license_key = trim($form_state->getValue('license_key'));
    $message = $form_state->getValue('direct_message') ?: 'Direct NR test';
    $level = $form_state->getValue('direct_level') ?: 'WARNING';
    $appname = $form_state->getValue('direct_appname') ?: '';

    if (!$license_key) {
      $this->messenger()->addError($this->t('License key is required.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // Attach linking metadata (trace.id, span.id, entity.guid, etc.) if the
    // NR extension is available and an active transaction exists.
    $linking = function_exists('newrelic_get_linking_metadata')
      ? newrelic_get_linking_metadata()
      : [];

    $log_record = array_merge([
      'timestamp' => (int) (microtime(TRUE) * 1000),
      'message' => $message,
      'level' => $level,
      'entity.name' => $appname,
      'source' => 'nr_debug_direct',
    ], $linking);

    $payload = [['logs' => [$log_record]]];

    [$http_code, $body, $error] = $this->curlPost(
      'https://log-api.newrelic.com/log/v1',
      ['Content-Type: application/json', 'X-License-Key: ' . $license_key],
      json_encode($payload)
    );

    $output = "HTTP: $http_code\n";
    $output .= "Body: $body\n";
    if ($error) {
      $output .= "Curl error: $error\n";
    }
    $output .= "\nPayload sent:\n" . json_encode($payload, JSON_PRETTY_PRINT);

    if ($http_code === 202) {
      $this->messenger()->addStatus($this->t('202 Accepted — log delivered to NR Logs API successfully.'));
    }
    elseif ($http_code === 0) {
      $this->messenger()->addError($this->t('Curl error (no response). Check network connectivity from this host.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Unexpected HTTP @code — see results below.', ['@code' => $http_code]));
    }

    $form_state->set('results', $output);
    $form_state->setRebuild(TRUE);
  }

  // ---------------------------------------------------------------------------
  // Submit: NerdGraph
  // ---------------------------------------------------------------------------

  public function submitNerdGraph(array &$form, FormStateInterface $form_state): void {
    $user_key = trim($form_state->getValue('user_api_key'));
    $account_id = (int) $form_state->getValue('account_id');
    $nrql = trim($form_state->getValue('nrql_query'));

    if (!$user_key || !$account_id) {
      $this->messenger()->addError($this->t('User API key and account ID are required.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $query = <<<GQL
    {
      actor {
        account(id: $account_id) {
          nrql(query: "$nrql") {
            results
          }
        }
      }
    }
    GQL;

    [$http_code, $body, $error] = $this->curlPost(
      'https://api.newrelic.com/graphql',
      ['Content-Type: application/json', 'API-Key: ' . $user_key],
      json_encode(['query' => $query])
    );

    $output = "HTTP: $http_code\n\n";
    if ($error) {
      $output .= "Curl error: $error\n";
    }
    else {
      $decoded = json_decode($body, TRUE);
      $output .= $decoded ? json_encode($decoded, JSON_PRETTY_PRINT) : $body;
    }

    if ($http_code === 200) {
      $this->messenger()->addStatus($this->t('NerdGraph query succeeded — see results below.'));
    }
    elseif ($http_code === 0) {
      $this->messenger()->addError($this->t('Curl error — no response from api.newrelic.com.'));
    }
    else {
      $this->messenger()->addWarning($this->t('HTTP @code from NerdGraph — see results below.', ['@code' => $http_code]));
    }

    $form_state->set('results', $output);
    $form_state->setRebuild(TRUE);
  }

  // ---------------------------------------------------------------------------
  // Submit: Pipeline probe
  // ---------------------------------------------------------------------------

  public function submitProbePipeline(array &$form, FormStateInterface $form_state): void {
    $channel = $form_state->getValue('probe_channel') ?: 'default';
    $output = "Probing Monolog pipeline for channel: $channel\n";
    $output .= str_repeat('=', 60) . "\n\n";

    try {
      $container = \Drupal::getContainer();
      $params = $container->getParameter('monolog.channel_handlers');
      $processors = $container->getParameter('monolog.processors');

      $handlers = (array) ($params[$channel] ?? $params['default'] ?? []);
      $output .= "Handlers for channel '$channel': " . implode(', ', $handlers) . "\n";
      $output .= "Processors: " . implode(', ', $processors) . "\n\n";

      // Check each handler service.
      foreach ($handlers as $handler_name) {
        $service_id = 'monolog.handler.' . $handler_name;
        $output .= "--- Handler: $service_id ---\n";

        if (!$container->has($service_id)) {
          $output .= "  ERROR: Service not registered in container.\n\n";
          continue;
        }

        try {
          $handler = $container->get($service_id);
          $output .= "  Class: " . get_class($handler) . "\n";
          $output .= "  Level: " . $handler->getLevel()->getName() . " (" . $handler->getLevel()->value . ")\n";
          $output .= "  Bubble: " . ($handler->getBubble() ? 'true' : 'false') . "\n";

          // Try formatting a test record through this handler.
          $test_record = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: $channel,
            level: \Monolog\Level::Warning,
            message: 'Pipeline probe test record',
            context: [],
            extra: [],
          );

          try {
            $formatter = $handler->getFormatter();
            $output .= "  Formatter: " . get_class($formatter) . "\n";
            $formatted = $formatter->format($test_record);
            if (is_string($formatted)) {
              $output .= "  Formatted output (" . strlen($formatted) . " bytes):\n";
              $output .= "    " . substr($formatted, 0, 300) . (strlen($formatted) > 300 ? '...' : '') . "\n";
            }
            else {
              $output .= "  Formatted output (non-string — " . gettype($formatted) . "):\n";
              $output .= "    " . substr(json_encode($formatted), 0, 300) . "\n";
            }
          }
          catch (\Throwable $e) {
            $output .= "  FORMATTER ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
            $output .= "    " . $e->getFile() . ":" . $e->getLine() . "\n";
          }
        }
        catch (\Throwable $e) {
          $output .= "  INSTANTIATION ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
          $output .= "    " . $e->getFile() . ":" . $e->getLine() . "\n";
        }

        $output .= "\n";
      }

      // Live send test — actually push a record through each handler and flush.
      $output .= "--- Live Send Test ---\n";
      $live_record = new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: $channel,
        level: \Monolog\Level::Warning,
        message: 'NR debug live send test — ' . date('Y-m-d H:i:s'),
        context: [],
        extra: [],
      );
      foreach ($handlers as $handler_name) {
        $service_id = 'monolog.handler.' . $handler_name;
        if (!$container->has($service_id)) {
          continue;
        }
        try {
          $handler = $container->get($service_id);
          $output .= "  $service_id handle(): ";
          $result = $handler->handle(clone $live_record);
          $output .= "returned " . ($result ? 'true (stop)' : 'false (bubble)') . "\n";
          // Flush buffers (BufferHandler, FingersCrossedHandler, etc.).
          if (method_exists($handler, 'close')) {
            $output .= "  $service_id close(): ";
            try {
              $handler->close();
              $output .= "OK\n";
            }
            catch (\Throwable $e) {
              $output .= "EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
              $output .= "    " . $e->getFile() . ":" . $e->getLine() . "\n";
            }
          }
        }
        catch (\Throwable $e) {
          $output .= "  $service_id EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
          $output .= "    " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
      }
      $output .= "\n";

      // Direct curl capture — bypass the BufferHandler and test new_relic_log
      // directly, capturing the HTTP response so we can see what NR returns.
      $output .= "--- Direct Curl Capture (new_relic_log) ---\n";
      $inner_service = 'monolog.handler.new_relic_log';
      if ($container->has($inner_service)) {
        try {
          $inner_handler = $container->get($inner_service);
          $formatter = $inner_handler->getFormatter();

          // Apply the new_relic_processor manually so entity.name is present.
          $enriched_record = $live_record;
          if ($container->has('monolog.processor.new_relic_processor')) {
            try {
              $processor = $container->get('monolog.processor.new_relic_processor');
              $enriched_record = $processor($live_record);
            }
            catch (\Throwable $e) {
              $output .= "  Processor error: " . $e->getMessage() . "\n";
            }
          }

          $formatted = $formatter->format($enriched_record);
          $payload = '[{"logs":' . $formatter->formatBatch([$enriched_record]) . '}]';
          $output .= "  Payload (" . strlen($payload) . " bytes):\n  " . $payload . "\n\n";

          // Make the curl call ourselves so we can capture the response.
          $license_key = ini_get('newrelic.license');
          $ch = curl_init('https://log-api.newrelic.com/log/v1');
          curl_setopt_array($ch, [
            CURLOPT_POST => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => [
              'Content-Type: application/json',
              'X-License-Key: ' . $license_key,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
          ]);
          $response = curl_exec($ch);
          $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
          $curl_error = curl_error($ch);
          curl_close($ch);

          $output .= "  HTTP response: $http_code\n";
          $output .= "  Body: " . ($response ?: '(empty)') . "\n";
          if ($curl_error) {
            $output .= "  Curl error: $curl_error\n";
          }
        }
        catch (\Throwable $e) {
          $output .= "  EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
          $output .= "  " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
      }
      else {
        $output .= "  monolog.handler.new_relic_log not registered — not using enricher handler directly.\n";
      }
      $output .= "\n";

      // Check each processor service.
      $output .= "--- Processors ---\n";
      foreach ($processors as $processor_name) {
        $service_id = 'monolog.processor.' . $processor_name;
        if (!$container->has($service_id)) {
          $output .= "  $service_id: NOT REGISTERED\n";
          continue;
        }
        try {
          $processor = $container->get($service_id);
          $output .= "  $service_id: " . get_class($processor) . " OK\n";
        }
        catch (\Throwable $e) {
          $output .= "  $service_id: ERROR — " . $e->getMessage() . "\n";
        }
      }
    }
    catch (\Throwable $e) {
      $output .= "FATAL: " . get_class($e) . ": " . $e->getMessage() . "\n";
      $output .= $e->getFile() . ":" . $e->getLine() . "\n";
    }

    $this->messenger()->addStatus($this->t('Pipeline probe complete — see results below.'));
    $form_state->set('results', $output);
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Individual submit handlers used; this is intentionally empty.
  }

  // ---------------------------------------------------------------------------
  // Curl helper
  // ---------------------------------------------------------------------------

  private function curlPost(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$http_code, $response ?: '', $error];
  }

}
