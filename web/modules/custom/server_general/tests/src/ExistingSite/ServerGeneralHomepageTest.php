<?php

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\node\NodeInterface;
use Drupal\Tests\server_general\Traits\ParagraphCreationTrait;

/**
 * Tests for the Homepage.
 */
class ServerGeneralHomepageTest extends ServerGeneralSelenium2TestBase {

  use ParagraphCreationTrait;

  private const PUBLISHED_COUNTRY_HOST = 'published-country.microsites-drupal-starter.ddev.site';

  /**
   * Test the featured content carousel on homepage.
   */
  public function testHomeFeaturedContent() {
    $country = $this->createNode([
      'type' => 'country',
      'title' => 'Test Country',
      'status' => NodeInterface::PUBLISHED,
      'field_country_code' => 'tc',
      'field_hostnames' => [self::PUBLISHED_COUNTRY_HOST],
      'field_languages' => ['en'],
    ]);

    $first_news_title = 'News item 1';
    $second_news_title = 'News item 2';

    $first_news = $this->createNode([
      'type' => 'news',
      'title' => $first_news_title,
      'status' => NodeInterface::PUBLISHED,
      'moderation_state' => 'published',
      'og_audience' => ['target_id' => $country->id()],
    ]);

    $second_news = $this->createNode([
      'type' => 'news',
      'title' => $second_news_title,
      'status' => NodeInterface::PUBLISHED,
      'moderation_state' => 'published',
      'og_audience' => ['target_id' => $country->id()],
    ]);

    $featured_content_paragraph = $this->createParagraph([
      'type' => 'related_content',
      'field_title' => 'Featured Content',
      'field_is_featured' => 1,
      'field_related_content' => [
        ['target_id' => $first_news->id()],
        ['target_id' => $second_news->id()],
      ],
    ]);

    $landing_page = $this->createNode([
      'type' => 'landing_page',
      'title' => $this->randomString(),
      'status' => NodeInterface::PUBLISHED,
      'moderation_state' => 'published',
      'field_paragraphs' => [
        [
          'target_id' => $featured_content_paragraph->id(),
        ],
      ],
      'og_audience' => ['target_id' => $country->id()],
    ]);

    $this->drupalGet($landing_page->toUrl());
    $this->takeScreenshot('landing_page');
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $web_assert */
    $web_assert = $this->assertSession();

    $featured_content = $web_assert->waitForElement('css', '.paragraph--type--related-content');
    $this->assertNotNull($featured_content);
    $web_assert->elementTextContains('css', '.paragraph--type--related-content h2', 'Featured Content');
    // Slick is initialized.
    $carousel = $web_assert->waitForElement('css', '.paragraph--type--related-content .carousel-wrapper.slick-initialized');
    $this->assertNotNull($carousel);

    // Assert the current slide has the expected title.
    $web_assert->elementTextContains('css', '.paragraph--type--related-content .carousel-slide.slick-current', $first_news_title);
    // Click on the 2nd dot navigation.
    $dots_2 = $carousel->find('css', '.slick-dots li:nth-child(2)');
    $this->assertNotNull($dots_2);
    $dots_2->click();
    // Wait half sec for the JS and animation.
    $this->getSession()->wait(500);
    // Assert that the active slide is now different.
    $web_assert->elementTextContains('css', '.paragraph--type--related-content .carousel-slide.slick-current', $second_news_title);
  }

}
