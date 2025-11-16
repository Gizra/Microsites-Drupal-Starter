<?php

declare(strict_types=1);

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
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
    $this->addMembership($country, $user);

    $membership_manager = \Drupal::service('og.membership_manager');
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
   * Test hook_node_access grants access to members on hostname context.
   *
   * @todo: Remove? OR extend?
   */
  public function testNodeAccessAllowsGroupMemberOnHostname(): void {
    $country = $this->unpublishedCountry;
    $user = $this->createUser();

    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $membership = $membership_manager->createMembership($country, $user);
    $membership->save();
    $this->markEntityForCleanup($membership);

    $request = Request::create(sprintf('https://%s/', self::UNPUBLISHED_COUNTRY_HOST));
    $request_stack = \Drupal::service('request_stack');
    $request_stack->push($request);

    $access_handler = \Drupal::entityTypeManager()->getAccessControlHandler('node');
    $this->assertTrue($access_handler->access($country, 'view', $user));
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

    $language_object = \Drupal::languageManager()->getLanguage('es');

    $url = $news->toUrl(NULL, ['language' => $language_object])->toString();
    $this->assertStringStartsWith('/es/news/noticias-en-espanol', $url);
    $this->drupalGet($url);

    $this->assertHostname(self::DEFAULT_HOST);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test language restrictions for group content for an authenticated user.
   */
  public function testGroupContentLanguageRestrictionsMember(): void {
    $country = $this->publishedCountry;
    $country->set('field_languages', ['en', 'es'])->save();

    $admin = $this->createUser(['bypass node access']);
    $this->addMembership($country, $admin);
    $this->drupalLogin($admin);

    $news = $this->createNewsForCountry($country);
    $news_es = $news->addTranslation('es', $news->toArray());
    $news_es->setTitle('Noticias en español');
    $news_es->save();

    $this->drupalGet($news_es->toUrl());
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertHostname(self::PUBLISHED_COUNTRY_HOST);;

    // @todo: Assert warning text.

  }

  /**
   * Test language restrictions for non-privileged users.
   */
  public function testLanguageAccessRestrictions(): void {
    $country = $this->publishedCountry;

    $country_es = $country->addTranslation('es', $country->toArray());
    $country_es->setTitle('País solo inglés');
    $country_es->save();

    $this->drupalGet($country_es->toUrl());

    $this->assertSame(
      self::DEFAULT_HOST,
      parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST),
    );

    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//
//    $user = $this->createUser();
//
//    $this->drupalLogin($user);
//    $this->drupalGet($country_es->toUrl());
//
//    $this->assertSame(
//      self::DEFAULT_HOST,
//      parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST),
//    );
//
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test that privileged users can access non-enabled languages with warning.
   */
  public function testLanguageAccessPrivileged(): void {
    $country = $this->createCountryOnHost(self::LANGUAGE_COUNTRY_HOST, [
      'title' => 'English Only Country',
      'field_country_code' => 'eo',
      'field_languages' => ['en'],
    ]);

    $country_es = $country->addTranslation('es', $country->toArray());
    $country_es->setTitle('País solo inglés');
    $country_es->save();

    $admin = $this->createUser(['bypass node access']);
    $this->markEntityForCleanup($admin);
    $this->addMembership($country, $admin);
    $this->drupalLogin($admin);
    $this->visitTranslationOnCountry($country_es, $country);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextContains('You are viewing content in a language');
    $this->assertSession()->pageTextContains('that is not enabled for this country');
  }

  /**
   * Test group content access on unpublished country.
   */
  public function testGroupContentOnUnpublishedCountry(): void {
    $news = $this->createNewsForCountry($this->unpublishedCountry);

    $this->visitGroupContentOnCountry($news, $this->unpublishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $user = $this->createUser();

    $this->drupalLogin($user);
    $this->visitGroupContentOnCountry($news, $this->unpublishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test group content access on unpublished country for privileged users.
   */
  public function testGroupContentOnUnpublishedCountryPrivileged(): void {
    $news = $this->createNewsForCountry($this->unpublishedCountry);

    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    $this->visitGroupContentOnCountry($news, $this->unpublishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
  }

  /**
   * Test unpublished group content on published country.
   */
  public function testUnpublishedGroupContent(): void {
    $news = $this->createNewsForCountry($this->publishedCountry);
    $news->setUnpublished()->save();

    $this->visitGroupContentOnCountry($news, $this->publishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    $this->visitGroupContentOnCountry($news, $this->publishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
  }

  /**
   * Test that admin routes allow access even for unpublished countries.
   */
  public function testAdminRouteAccess(): void {
    $editor = $this->createUser(['bypass node access']);
    $this->markEntityForCleanup($editor);
    $this->drupalLogin($editor);

    $url = $this->unpublishedCountry->toUrl('edit-form');
    $query = $url->getOption('query') ?? [];
    $query['big_pipe_nojs'] = '1';
    $url->setOption('query', $query);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextNotContains('You are viewing content on an unpublished country');
  }

  /**
   * Creates a News node assigned to the provided Country.
   */
  private function createNewsForCountry(NodeInterface $country): NodeInterface {
    return $this->createNode([
      'type' => 'news',
      'title' => sprintf('News for %s', $country->label()),
      'status' => NodeInterface::PUBLISHED,
      'og_audience' => ['target_id' => $country->id()],
    ]);
  }

  /**
   * Adds an OG membership for the provided account on the country.
   */
  private function addMembership(NodeInterface $country, $account): void {
    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $membership = $membership_manager->createMembership($country, $account);
    $membership->save();
    $this->markEntityForCleanup($membership);
  }

  private function assertHostname(string $expected): void {
    $this->assertSame(
      $expected,
      parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST),
    );
  }

}
