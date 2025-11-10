<?php

declare(strict_types=1);

namespace Drupal\Tests\server_general\ExistingSite;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use League\Csv\Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test Country group access control and hostname-based restrictions.
 */
class ServerGeneralCountryGroupAccessTest extends ServerGeneralTestBase {

  /**
   * Test that anonymous users cannot access unpublished country.
   */
  public function testUnpublishedCountryAccessAnonymous(): void {
    // Create an unpublished Country node.
    $country = $this->createNode([
      'type' => 'country',
      'title' => 'Test Unpublished Country',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_country_code' => 'tc',
      'field_hostnames' => ['tc.microsites-drupal-starter.ddev.site'],
      'field_languages' => ['en'],
    ]);

    // Anonymous user should get forbidden accessing the country node.
    $this->visitCountry($country, 'tc');
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
  }

  /**
   * Test that privileged users can access unpublished country with warning.
   */
  public function testUnpublishedCountryAccessPrivileged(): void {
    // Create an unpublished Country node.
    $country = $this->createNode([
      'type' => 'country',
      'title' => 'Test Unpublished Country',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_country_code' => 'tc',
      'field_hostnames' => ['tc.microsites-drupal-starter.ddev.site'],
      'field_languages' => ['en'],
    ]);


    // User with bypass node access should be able to access.
    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);

    $this->visitCountry($country, 'tc');

    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    // Should show warning.
    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
  }

//  /**
//   * Test that group members can access unpublished country.
//   */
//  public function testUnpublishedCountryAccessGroupMember(): void {
//    // Create an unpublished Country node.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Test Unpublished Country',
//      'status' => NodeInterface::NOT_PUBLISHED,
//      'field_country_code' => 'tc',
//      'field_hostnames' => [$this->getHostname('tc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Create a user and make them a member of the country group.
//    $member = $this->createUser();
//    $this->markEntityForCleanup($member);
//
//    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
//    $membership_manager = \Drupal::service('og.membership_manager');
//    $membership = $membership_manager->createMembership($country, $member);
//    $membership->save();
//    $this->markEntityForCleanup($membership);
//
//    $this->drupalLogin($member);
//    $this->drupalGet($country->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    // Should show warning for group members too.
//    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
//  }
//
//  /**
//   * Test that non-members cannot access unpublished country.
//   */
//  public function testUnpublishedCountryAccessNonMember(): void {
//    // Create an unpublished Country node.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Test Unpublished Country',
//      'status' => NodeInterface::NOT_PUBLISHED,
//      'field_country_code' => 'tc',
//      'field_hostnames' => [$this->getHostname('tc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Regular user without group membership should not have access.
//    $user = $this->createUser();
//    $this->drupalLogin($user);
//
//    $this->drupalGet($country->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//  }
//
//  /**
//   * Test that all users can access published country.
//   */
//  public function testPublishedCountryAccess(): void {
//    // Create a published Country node.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Test Published Country',
//      'status' => NodeInterface::PUBLISHED,
//      'field_country_code' => 'tc',
//      'field_hostnames' => [$this->getHostname('tc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Anonymous user should have access.
//    $this->drupalGet($country->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//
//    // Regular user should have access.
//    $user = $this->createUser();
//    $this->drupalLogin($user);
//    $this->drupalGet($country->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    // Should NOT show warning for published country.
//    $this->assertSession()->pageTextNotContains('You are viewing content on an unpublished country');
//  }
//
//  /**
//   * Test language restrictions for non-privileged users.
//   */
//  public function testLanguageAccessRestrictions(): void {
//    // Create a Country that only allows English.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'English Only Country',
//      'status' => NodeInterface::PUBLISHED,
//      'field_country_code' => 'eo',
//      'field_hostnames' => [$this->getHostname('eo')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Add Spanish translation to the country.
//    $country_es = $country->addTranslation('es', $country->toArray());
//    $country_es->setTitle('País solo inglés');
//    $country_es->save();
//
//    // Anonymous user trying to access Spanish version should be denied.
//    $this->drupalGet($country_es->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//
//    // Regular user should also be denied.
//    $user = $this->createUser();
//    $this->drupalLogin($user);
//    $this->drupalGet($country_es->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//  }
//
//  /**
//   * Test that privileged users can access non-enabled languages with warning.
//   */
//  public function testLanguageAccessPrivileged(): void {
//    // Create a Country that only allows English.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'English Only Country',
//      'status' => NodeInterface::PUBLISHED,
//      'field_country_code' => 'eo',
//      'field_hostnames' => [$this->getHostname('eo')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Add Spanish translation.
//    $country_es = $country->addTranslation('es', $country->toArray());
//    $country_es->setTitle('País solo inglés');
//    $country_es->save();
//
//    // Admin user should be able to access with warning.
//    $admin = $this->createUser([], NULL, TRUE);
//    $this->drupalLogin($admin);
//
//    $this->drupalGet($country_es->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    $this->assertSession()->pageTextContains('You are viewing content in a language');
//    $this->assertSession()->pageTextContains('that is not enabled for this country');
//  }
//
//  /**
//   * Test group content access on unpublished country.
//   */
//  public function testGroupContentOnUnpublishedCountry(): void {
//    // Create an unpublished Country.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Unpublished Country',
//      'status' => NodeInterface::NOT_PUBLISHED,
//      'field_country_code' => 'uc',
//      'field_hostnames' => [$this->getHostname('uc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Create a News article belonging to this country.
//    $news = $this->createNode([
//      'type' => 'news',
//      'title' => 'Test News',
//      'status' => NodeInterface::PUBLISHED,
//      'og_audience' => ['target_id' => $country->id()],
//    ]);
//    $this->markEntityForCleanup($news);
//
//    // Anonymous user should not be able to access news on unpublished country.
//    $this->drupalGet($news->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//
//    // Regular user should not be able to access.
//    $user = $this->createUser();
//    $this->drupalLogin($user);
//    $this->drupalGet($news->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//  }
//
//  /**
//   * Test group content access on unpublished country for privileged users.
//   */
//  public function testGroupContentOnUnpublishedCountryPrivileged(): void {
//    // Create an unpublished Country.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Unpublished Country',
//      'status' => NodeInterface::NOT_PUBLISHED,
//      'field_country_code' => 'uc',
//      'field_hostnames' => [$this->getHostname('uc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Create a News article belonging to this country.
//    $news = $this->createNode([
//      'type' => 'news',
//      'title' => 'Test News',
//      'status' => NodeInterface::PUBLISHED,
//      'og_audience' => ['target_id' => $country->id()],
//    ]);
//    $this->markEntityForCleanup($news);
//
//    // Admin should be able to access with warning.
//    $admin = $this->createUser([], NULL, TRUE);
//    $this->drupalLogin($admin);
//
//    $this->drupalGet($news->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    $this->assertSession()->pageTextContains('You are viewing content on an unpublished country');
//  }
//
//  /**
//   * Test unpublished group content on published country.
//   */
//  public function testUnpublishedGroupContent(): void {
//    // Create a published Country.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Published Country',
//      'status' => NodeInterface::PUBLISHED,
//      'field_country_code' => 'pc',
//      'field_hostnames' => [$this->getHostname('pc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Create an unpublished News article.
//    $news = $this->createNode([
//      'type' => 'news',
//      'title' => 'Unpublished News',
//      'status' => NodeInterface::NOT_PUBLISHED,
//      'og_audience' => ['target_id' => $country->id()],
//    ]);
//    $this->markEntityForCleanup($news);
//
//    // Anonymous user should not have access.
//    $this->drupalGet($news->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//
//    // Admin should have access with warning.
//    $admin = $this->createUser([], NULL, TRUE);
//    $this->drupalLogin($admin);
//
//    $this->drupalGet($news->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    $this->assertSession()->pageTextContains('You are viewing unpublished content');
//  }
//
//  /**
//   * Test that admin routes allow access even for unpublished countries.
//   */
//  public function testAdminRouteAccess(): void {
//    // Create an unpublished Country.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'Unpublished Country',
//      'status' => NodeInterface::NOT_PUBLISHED,
//      'field_country_code' => 'uc',
//      'field_hostnames' => [$this->getHostname('uc')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Create a content editor user.
//    $editor = $this->createUser();
//    $editor->addRole('content_editor');
//    $editor->save();
//    $this->drupalLogin($editor);
//
//    // Editor should be able to access edit form (admin route).
//    $this->drupalGet($country->toUrl('edit-form'));
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    // Should NOT show warning on admin routes.
//    $this->assertSession()->pageTextNotContains('You are viewing content on an unpublished country');
//  }
//
//  /**
//   * Test language restrictions for group content.
//   */
//  public function testGroupContentLanguageRestrictions(): void {
//    // Create a Country that only allows English.
//    $country = $this->createNode([
//      'type' => 'country',
//      'title' => 'English Only Country',
//      'status' => NodeInterface::PUBLISHED,
//      'field_country_code' => 'eo',
//      'field_hostnames' => [$this->getHostname('eo')],
//      'field_languages' => ['en'],
//    ]);
//
//    // Create English News article.
//    $news = $this->createNode([
//      'type' => 'news',
//      'title' => 'English News',
//      'status' => NodeInterface::PUBLISHED,
//      'og_audience' => ['target_id' => $country->id()],
//    ]);
//    $this->markEntityForCleanup($news);
//
//    // Add Spanish translation.
//    $news_es = $news->addTranslation('es', $news->toArray());
//    $news_es->setTitle('Noticias en español');
//    $news_es->save();
//
//    // Anonymous user should not access Spanish version.
//    $this->drupalGet($news_es->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);
//
//    // Admin should access with warning.
//    $admin = $this->createUser([], NULL, TRUE);
//    $this->drupalLogin($admin);
//
//    $this->drupalGet($news_es->toUrl());
//    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
//    $this->assertSession()->pageTextContains('You are viewing content in a language');
//  }

  /**
   * Visits the given country's canonical URL on the mapped hostname.
   */
  private function visitCountry(NodeInterface $country, string $identifier): void {
    $url = $country->toUrl();
    $this->visitUrlOnHost($url, $identifier);
  }

  /**
   * Visits the given URL on the mapped hostname.
   */
  private function visitUrlOnHost(Url $url, string $identifier): void {
    $baseUrl = $this->buildBaseUrl($identifier);
//    $url->setOption('absolute', TRUE);
    $url->setOption('base_url', $baseUrl);
    $this->drupalGet($url);
  }

  /**
   * Builds the external base URL used for router access inside DDEV.
   */
  private function buildBaseUrl(string $identifier): string {
    $host = match ($identifier) {
      'tc' => 'tc.microsites-drupal-starter.ddev.site',
      default => 'microsites-drupal-starter.ddev.site',
    };

    return sprintf('https://%s:4443', $host);
  }

}
