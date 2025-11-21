<?php

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test input formats.
 */
class ServerGeneralInputFormatTest extends ServerGeneralTestBase {

  private const PUBLISHED_COUNTRY_HOST = 'published-country.microsites-drupal-starter.ddev.site';

  /**
   * Test Full HTML input format.
   */
  public function testFullHtmlFormat() {
    $country = $this->createNode([
      'type' => 'country',
      'title' => 'Test Published Country',
      'status' => NodeInterface::PUBLISHED,
      'field_country_code' => 'pc',
      'field_hostnames' => [self::PUBLISHED_COUNTRY_HOST],
      'field_languages' => ['en'],
    ]);

    $user = $this->createUser();
    $user->addRole('administrator');
    $user->save();

    $this->drupalLogin($user);

    $this->drupalGet('/node/add/news');
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);

    $this->getSession()->getPage()->fillField('edit-title-0-value', 'Test Page');
    $this->getSession()->getPage()->fillField('edit-field-body-0-value', 'I cannot have a script tag: <script></script>, as that would be way too dangerous. See https://owasp.org/www-community/attacks/xss/. <div class="danger-danger" onmouseover="javascript: whatafunction()">abc</div>');
    $this->getSession()->getPage()->selectFieldOption('edit-field-body-0-format--2', 'full_html');

    // Set the Country field.
    $this->getSession()->getPage()->selectFieldOption('edit-og-audience', $country->id());

    $this->click('#edit-submit');
    // <script> tag is eliminated.
    $this->assertSession()->elementNotExists('css', '.node--type-news script');
    // The class attribute is preserved.
    $this->createHtmlSnapshot();
    $this->assertSession()->elementExists('css', '.danger-danger');
    // The onmouseover attribute is completely droppped.
    $this->assertStringNotContainsString('onmouseover', $this->getCurrentPage()->getOuterHtml());

    throw new \Exception('Debugging');
  }

}
