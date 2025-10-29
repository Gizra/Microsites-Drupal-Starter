<?php

declare(strict_types=1);

namespace Drupal\server_general\ThemeTrait;

/**
 * Helper methods for rendering Country elements.
 */
trait CountryThemeTrait {

  use ElementLayoutThemeTrait;
  use ElementWrapThemeTrait;
  use NewsTeasersThemeTrait;

  /**
   * Build Country element.
   *
   * @param string $country_code
   *   The country code.
   * @param string $title
   *   The title.
   * @param array $body
   *   The body render array.
   *
   * @return array
   *   Render array.
   */
  protected function buildElementCountry(string $country_code, string $title, array $body): array {
    $elements = [];

    $title_with_flag = $this->getFlagEmojiFromCountryCode($country_code) . ' ' . $title;

    $elements[] = $this->buildPageTitle($title_with_flag);
    $elements[] = $this->wrapProseText($body);

    $elements = $this->wrapContainerVerticalSpacing($elements);
    return $this->wrapContainerWide($elements);
  }

  /**
   * Get flag emoji from country code.
   *
   * @param string $country_code
   *   Two-letter country code (e.g., 'es', 'iq', 'us').
   *
   * @return string
   *   Flag emoji.
   */
  protected function getFlagEmojiFromCountryCode(string $country_code): string {
    $code = strtoupper($country_code);
    $emoji = '';
    foreach (str_split($code) as $char) {
      $emoji .= mb_chr(127397 + ord($char), 'UTF-8');
    }
    return $emoji;
  }

}
