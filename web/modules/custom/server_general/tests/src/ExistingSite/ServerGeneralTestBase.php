<?php

declare(strict_types=1);

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\Tests\server_general\TestConfiguration;
use Drupal\Tests\server_general\Traits\MemoryManagementTrait;
use weitzman\DrupalTestTraits\Exception\PhpWatchdogException;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Custom base class tailored for the site specifics.
 *
 * All non-js tests should extend this class instead of ExistingSiteBase.
 */
abstract class ServerGeneralTestBase extends ExistingSiteBase {

  use MemoryManagementTrait;

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $messages = [];
    $original_fail_on_watchdog = $this->failOnPhpWatchdogMessages;

    // Collect PHP watchdog entries so we can surface their full output in CI.
    if ($this->failOnPhpWatchdogMessages && \Drupal::database()->schema()->tableExists('watchdog')) {
      $messages = \Drupal::database()
        ->select('watchdog', 'w')
        ->fields('w')
        ->condition('w.type', 'PHP', '=')
        ->execute()
        ->fetchAll();

      foreach ($messages as $error) {
        // Perform replacements so the error message is easier to read.
        // @codingStandardsIgnoreLine
        $error->variables = unserialize($error->variables);
        $error->message = str_replace(array_keys($error->variables), $error->variables, $error->message);
        unset($error->variables);
      }
    }

    // Avoid the parent throwing its own generic exception before we can add
    // the detailed watchdog output to the message.
    $this->failOnPhpWatchdogMessages = FALSE;
    parent::tearDown();
    $this->failOnPhpWatchdogMessages = $original_fail_on_watchdog;

    $this->performMemoryCleanup();

    if (!empty($messages)) {
      $formatted = array_map(static fn($error) => trim(print_r($error, TRUE)), $messages);
      throw new PhpWatchdogException('PHP errors or warnings are introduced when running this test. Details: ' . implode(' | ', $formatted));
    }
  }

  /**
   * Creates a snapshot of the virtual browser for debugging purposes.
   */
  public function createHtmlSnapshot(): string {
    if (!file_exists(TestConfiguration::DEBUG_DIRECTORY)) {
      mkdir(TestConfiguration::DEBUG_DIRECTORY);
    }

    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

    // Start with level 1 (the immediate caller).
    $level = 1;
    $caller = $backtrace[$level]['function'] ?? 'unknown';

    // If it's a closure, try to get the next level up.
    if (str_contains($caller, '{closure}') && isset($backtrace[$level + 1])) {
      $level++;
      $caller = $backtrace[$level]['function'];
    }

    if (isset($backtrace[$level]['class'])) {
      $caller = $backtrace[$level]['class'] . '::' . $caller;
    }

    $timestamp = microtime(TRUE);
    $filename = TestConfiguration::DEBUG_DIRECTORY . '/' . $caller . '_' . $timestamp . '.html';
    file_put_contents($filename, $this->getCurrentPage()->getOuterHtml());
    \Drupal::logger('server_general')->notice('HTML snapshot created: ' . str_replace('../', '', $filename));
    return $filename;
  }

}
