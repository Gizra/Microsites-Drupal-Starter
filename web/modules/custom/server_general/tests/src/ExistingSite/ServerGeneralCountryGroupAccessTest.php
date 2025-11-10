<?php

declare(strict_types=1);

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use League\Csv\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test Country group access control and hostname-based restrictions.
 */
class ServerGeneralCountryGroupAccessTest extends ServerGeneralTestBase {

  private const UNPUBLISHED_COUNTRY_HOST = 'unpublished-country.microsites-drupal-starter.ddev.site';
  private const PUBLISHED_COUNTRY_HOST = 'published-country.microsites-drupal-starter.ddev.site';
  private const LANGUAGE_COUNTRY_HOST = 'es.microsites-drupal-starter.ddev.site';
  private const GROUP_COUNTRY_HOST = 'iq.microsites-drupal-starter.ddev.site';

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
    $this->visitCountry($this->unpublishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test that privileged users can access unpublished country with warning.
   */
  public function testUnpublishedCountryAccessPrivileged(): void {
    // User with bypass node access should be able to access.
    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);

    $this->visitCountry($this->unpublishedCountry);

    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    // Should show warning.
    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
  }

  /**
   * Test that non-privileged group members can access unpublished country.
   */
  public function testUnpublishedCountryAccessNonPrivilegedGroupMember(): void {
    $member = $this->createUser();
    $this->markEntityForCleanup($member);
    $this->assertFalse($member->hasPermission('bypass node access'));

    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $membership = $membership_manager->createMembership($this->unpublishedCountry, $member);
    $membership->save();
    $this->markEntityForCleanup($membership);

    $this->assertTrue($membership_manager->isMember($this->unpublishedCountry, $member->id()), 'Member should belong to unpublished country.');

    $this->drupalLogin($member);
    $this->visitCountry($this->unpublishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    // Should show warning for group members too.
    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
  }

  /**
   * Test hook_node_access grants access to members on hostname context.
   */
  public function testNodeAccessAllowsGroupMemberOnHostname(): void {
    $member = $this->createUser();
    $this->markEntityForCleanup($member);

    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $membership = $membership_manager->createMembership($this->unpublishedCountry, $member);
    $membership->save();
    $this->markEntityForCleanup($membership);

    $request = Request::create(sprintf('https://%s/', self::UNPUBLISHED_COUNTRY_HOST));
    $request_stack = \Drupal::service('request_stack');
    $request_stack->push($request);

    $access_handler = \Drupal::entityTypeManager()->getAccessControlHandler('node');
    $this->assertTrue($access_handler->access($this->unpublishedCountry, 'view', $member));
  }
  /**
   * Test that non-members cannot access unpublished country.
   */
  public function testUnpublishedCountryAccessNonMember(): void {
    $user = $this->createUser();
    $this->markEntityForCleanup($user);
    $this->drupalLogin($user);

    $this->visitCountry($this->unpublishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test that all users can access published country.
   */
  public function testPublishedCountryAccess(): void {
    $this->visitCountry($this->publishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);

    $user = $this->createUser();
    $this->markEntityForCleanup($user);
    $this->drupalLogin($user);
    $this->visitCountry($this->publishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextNotContains('You are viewing content on an unpublished country');
  }
  /**
   * Test language restrictions for non-privileged users.
   */
  public function testLanguageAccessRestrictions(): void {
    $country = $this->createCountryOnHost(self::LANGUAGE_COUNTRY_HOST, [
      'title' => 'English Only Country',
      'field_country_code' => 'eo',
      'field_languages' => ['en'],
    ]);

    $country_es = $country->addTranslation('es', $country->toArray());
    $country_es->setTitle('País solo inglés');
    $country_es->save();

    $this->visitTranslationOnCountry($country_es, $country);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $user = $this->createUser();
    $this->markEntityForCleanup($user);
    $this->drupalLogin($user);
    $this->visitTranslationOnCountry($country_es, $country);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
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
    $this->markEntityForCleanup($user);
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
    $news = $this->createNewsForCountry($this->publishedCountry, [
      'status' => NodeInterface::NOT_PUBLISHED,
      'title' => 'Unpublished News',
    ]);

    $this->visitGroupContentOnCountry($news, $this->publishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    $this->visitGroupContentOnCountry($news, $this->publishedCountry);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextContains('You are viewing unpublished content');
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
   * Test language restrictions for group content.
   */
  public function testGroupContentLanguageRestrictions(): void {
    $country = $this->createCountryOnHost(self::GROUP_COUNTRY_HOST, [
      'title' => 'Group English Country',
      'field_country_code' => 'ge',
      'field_languages' => ['en'],
    ]);

    $news = $this->createNewsForCountry($country);
    $news_es = $news->addTranslation('es', $news->toArray());
    $news_es->setTitle('Noticias en español');
    $news_es->save();

    $this->visitTranslationOnCountry($news_es, $country);
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $admin = $this->createUser(['bypass node access']);
    $this->markEntityForCleanup($admin);
    $this->addMembership($country, $admin);
    $this->drupalLogin($admin);
    $this->visitTranslationOnCountry($news_es, $country);
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextContains('You are viewing content in a language');
  }

  /**
   * Visits the given country's canonical URL on the mapped hostname.
   */
  private function visitCountry(NodeInterface $country): void {
    $url = $country->toUrl();
    $host = $this->resolveCountryHost($country);
    $this->visitUrlOnHost($url, $host);
  }

  /**
   * Creates a published Country on the provided hostname.
   */
  private function createCountryOnHost(string $host, array $overrides = []): NodeInterface {
    $default_code = substr(str_replace(['.', '-'], '', $host), 0, 2) ?: 'ct';
    $defaults = [
      'type' => 'country',
      'title' => sprintf('Country %s', $host),
      'status' => NodeInterface::PUBLISHED,
      'field_country_code' => $default_code,
      'field_hostnames' => [$host],
      'field_languages' => ['en'],
    ];

    $values = array_merge($defaults, $overrides);
    $country = $this->createNode($values);
    $this->markEntityForCleanup($country);
    return $country;
  }

  /**
   * Creates a News node assigned to the provided Country.
   */
  private function createNewsForCountry(NodeInterface $country, array $overrides = []): NodeInterface {
    $defaults = [
      'type' => 'news',
      'title' => sprintf('News for %s', $country->label()),
      'status' => NodeInterface::PUBLISHED,
      'og_audience' => ['target_id' => $country->id()],
    ];

    $values = array_merge($defaults, $overrides);
    $news = $this->createNode($values);
    $this->markEntityForCleanup($news);
    return $news;
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

  /**
   * Visits group content on the correct hostname for its country.
   */
  private function visitGroupContentOnCountry(NodeInterface $node, NodeInterface $country): void {
    $this->visitUrlOnHost($node->toUrl(), $this->resolveCountryHost($country));
  }

  /**
   * Visits a translation of a country or group content on the country hostname.
   */
  private function visitTranslationOnCountry(NodeInterface $translation, NodeInterface $country): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => $translation->id()]);
    $this->visitUrlOnHost($url, $this->resolveCountryHost($country));
  }

  /**
   * Visits the given URL on the mapped hostname.
   */
  private function visitUrlOnHost(Url $url, string $host): void {
    $baseUrl = $this->buildBaseUrl($host);
    $url->setOption('base_url', $baseUrl);
    $this->drupalGet($url);
  }

  /**
   * Determines the hostname assigned to the provided country.
   */
  private function resolveCountryHost(NodeInterface $country): string {
    $hosts = $country->get('field_hostnames')->getValue();
    $host = $hosts[0]['value'] ?? '';

    if ($host === '') {
      throw new \LogicException('Country host configuration is missing.');
    }

    return $host;
  }

  /**
   * Builds the external base URL used for router access inside DDEV.
   */
  private function buildBaseUrl(string $host): string {
    return sprintf('https://%s:4443', $host);
  }

}
