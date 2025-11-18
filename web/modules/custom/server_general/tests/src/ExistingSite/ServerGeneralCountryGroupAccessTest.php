<?php

declare(strict_types=1);

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test Country group access control and hostname-based restrictions.
 */
class ServerGeneralCountryGroupAccessTest extends ServerGeneralTestBase {
  private const DEFAULT_HOST = 'microsites-drupal-starter.ddev.site';
  private const UNPUBLISHED_COUNTRY_HOST = 'unpublished-country.microsites-drupal-starter.ddev.site';
  private const PUBLISHED_COUNTRY_HOST = 'published-country.microsites-drupal-starter.ddev.site';

  /**
   * The unpublished country fixture.
   */
  protected NodeInterface $unpublishedCountry;

  /**
   * The published country fixture.
   */
  protected NodeInterface $publishedCountry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->unpublishedCountry = $this->createNode([
      'type' => 'country',
      'title' => 'Test Unpublished Country',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_country_code' => 'uc',
      'field_hostnames' => [self::UNPUBLISHED_COUNTRY_HOST],
      'field_languages' => ['en'],
    ]);

    $this->publishedCountry = $this->createNode([
      'type' => 'country',
      'title' => 'Test Published Country',
      'status' => NodeInterface::PUBLISHED,
      'field_country_code' => 'pc',
      'field_hostnames' => [self::PUBLISHED_COUNTRY_HOST],
      'field_languages' => ['en'],
    ]);
  }

  /**
   * Test that anonymous users cannot access unpublished country.
   */
  public function testUnpublishedCountryAccessAnonymous(): void {
    // Anonymous user should get forbidden accessing the country node.
    $this->drupalGet($this->unpublishedCountry->toUrl());

    // Assert hostname wasn't changed.
    $this->assertHostname(self::DEFAULT_HOST);

    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test that privileged users can access unpublished country with warning.
   */
  public function testUnpublishedCountryAccessPrivileged(): void {
    $country = $this->unpublishedCountry;
    // User with bypass node access should be able to access.
    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);

    $this->drupalGet($country->toUrl());

    // Assert hostname is correct.
    $this->assertHostname(self::UNPUBLISHED_COUNTRY_HOST);

    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    // Should show warning.
    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
  }

  /**
   * Test that non-privileged group members can access unpublished country.
   */
  public function testUnpublishedCountryAccessNonPrivilegedGroupMember(): void {
    $country = $this->unpublishedCountry;
    $user = $this->createUser();
    $this->assertFalse($user->hasPermission('bypass node access'));

    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $this->addMembership($country, $user);
    $this->assertTrue($membership_manager->isMember($this->unpublishedCountry, $user->id()), 'Member should belong to unpublished country.');

    $this->drupalLogin($user);
    $this->drupalGet($country->toUrl());

    // Assert hostname is correct.
    $this->assertSame(
      self::UNPUBLISHED_COUNTRY_HOST,
      parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST),
    );

    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    // Should show warning for group members too.
    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
  }

  /**
   * Test that non-members cannot access unpublished country.
   */
  public function testUnpublishedCountryAccessNonMember(): void {
    $country = $this->unpublishedCountry;
    $user = $this->createUser();

    $this->drupalLogin($user);

    $this->drupalGet($country->toUrl());

    // Assert hostname is correct.
    $this->assertSame(
      self::DEFAULT_HOST,
      parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST),
    );

    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test an anonymous user can access published country.
   */
  public function testPublishedCountryAccessAnonymous(): void {
    $country = $this->publishedCountry;
    $this->drupalGet($country->toUrl());

    $this->assertHostname(self::PUBLISHED_COUNTRY_HOST);

    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextNotContains('You are viewing content on an unpublished country');
  }

  /**
   * Test an anonymous user can access published country.
   */
  public function testPublishedCountryAccessAuthenticated(): void {
    $country = $this->publishedCountry;
    $user = $this->createUser();

    $this->drupalLogin($user);
    $this->drupalGet($country->toUrl());

    $this->assertHostname(self::PUBLISHED_COUNTRY_HOST);

    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextNotContains('You are viewing content on an unpublished country');
  }

  /**
   * Test language restrictions for group content for an anonymous user.
   */
  public function testGroupContentLanguageRestrictionsAnonymous(): void {
    $country = $this->publishedCountry;

    $news = $this->createNewsForCountry($country);
    $news_es = $news->addTranslation('es', $news->toArray());
    $news_es->setTitle('Noticias en español');
    $news_es->save();

    // As we return a 403, this causes a ResourceNotFoundException in the test.
    // Drupal logs the ResourceNotFoundException raised during the redirect as a
    // PHP watchdog entry, so disable the watchdog assertion for this test.
    $this->failOnPhpWatchdogMessages = FALSE;
    $this->drupalGet($news->toUrl(NULL, ['language' => \Drupal::languageManager()->getLanguage('es')]));

    $this->assertHostname(self::DEFAULT_HOST);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $this->assertSession()->pageTextNotContains('You are viewing content in a language (Spanish) that is not enabled for this country.');
  }

  /**
   * Test language restrictions for group content for privileged users.
   */
  public function testGroupContentLanguageRestrictionsAuthenticated(): void {
    $country = $this->publishedCountry;

    // Am admin user.
    $admin = $this->createUser(['bypass node access']);

    // A group member user.
    $member = $this->createUser();
    $this->addMembership($country, $member);

    foreach ([$admin, $member] as $user) {
      $this->drupalLogin($user);

      $news = $this->createNewsForCountry($country);
      $news_es = $news->addTranslation('es', $news->toArray());
      $news_es->setTitle('Noticias en español');
      $news_es->save();

      // As we return a 403, this causes a ResourceNotFoundException in the
      // test. Drupal logs the ResourceNotFoundException raised during the
      // redirect as a PHP watchdog entry, so disable the watchdog assertion for
      // this test.
      $this->failOnPhpWatchdogMessages = FALSE;
      $this->drupalGet($news->toUrl(NULL, ['language' => \Drupal::languageManager()->getLanguage('es')]));

      $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
      $this->assertHostname(self::PUBLISHED_COUNTRY_HOST);

      // Assert warning text.
      $this->assertSession()->pageTextContainsOnce('You are viewing content in a language (Spanish) that is not enabled for this country.');
    }
  }

  /**
   * Test that admin routes allow access even for unpublished countries.
   */
  public function testAdminRouteAccess(): void {
    $user = $this->createUser(['access content overview', 'bypass node access']);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/content');
    $this->createHtmlSnapshot();
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);

    foreach ([$this->publishedCountry, $this->unpublishedCountry] as $country) {
      $selector = sprintf('td.views-field-title a[href="/node/%d"]', $country->id());
      // Ensure each country shows up in the content overview as a link.
      $link = $this->assertSession()->elementExists('css', $selector);
      $this->assertSame($country->label(), $link->getText());
    }
  }

  /**
   * Creates a News node assigned to the provided Country.
   *
   * @param \Drupal\node\NodeInterface $country
   *   The country node.
   */
  private function createNewsForCountry(NodeInterface $country): NodeInterface {
    return $this->createNode([
      'type' => 'news',
      'title' => sprintf('News for %s', $country->label()),
      'status' => NodeInterface::PUBLISHED,
      'og_audience' => ['target_id' => $country->id()],
      'moderation_state' => 'published',
    ]);
  }

  /**
   * Adds an OG membership for the provided account on the country.
   *
   * @param \Drupal\node\NodeInterface $country
   *   The country node.
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   */
  private function addMembership(NodeInterface $country, UserInterface $account): void {
    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $membership = $membership_manager->createMembership($country, $account);
    $membership->save();
    $this->markEntityForCleanup($membership);
  }

  /**
   * Asserts that the current hostname matches the expected.
   *
   * @param string $expected
   *   The expected hostname.
   */
  private function assertHostname(string $expected): void {
    $this->assertSame(
      $expected,
      parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST),
    );
  }

}
