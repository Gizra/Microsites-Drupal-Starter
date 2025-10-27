<?php

declare(strict_types=1);

namespace Drupal\server_general\ThemeTrait;

/**
 * Helper methods for rendering Country elements.
 */
trait CountryThemeTrait {

  use ElementLayoutThemeTrait;
  use NewsTeasersThemeTrait;

  /**
   * Build Country element.
   *
   * @param string $title
   *   The title.
   * @param array $news
   *   The news items array.
   *
   * @return array
   *   Render array.
   */
  protected function buildElementCountry(string $title, array $body, array $news = []): array {
    return $this->buildElementLayoutTitleBodyAndItems(
      $title,
      $body,
      $news,
    );
  }

}
