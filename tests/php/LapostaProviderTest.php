<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Laposta\Tests;

use SilverShop\Newsletter\Laposta\LapostaProvider;
use SilverShop\Newsletter\Model\NewsletterSubscription;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class LapostaProviderTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testCapabilityFlags(): void
    {
        $provider = new LapostaProvider();
        $this->assertSame('laposta', $provider->getKey());
        $this->assertSame('Laposta', $provider->getName());
        $this->assertTrue($provider->isRemote());
        $this->assertTrue($provider->supportsWebhooks());
        $this->assertTrue($provider->supportsMergeFields());
        $this->assertTrue($provider->supportsSegments());
        // Laposta has no tags, and e-commerce sync is out of v1 scope.
        $this->assertFalse($provider->supportsTags());
        $this->assertFalse($provider->supportsEcommerce());
        // The core owns double opt-in by default, so Laposta runs single opt-in (ignore_doubleoptin).
        $this->assertFalse($provider->supportsDoubleOptIn());
    }

    public function testIsConfiguredFromEnv(): void
    {
        Environment::setEnv('SS_LAPOSTA_API_KEY', '');
        Environment::setEnv('SS_LAPOSTA_LIST_ID', '');
        $this->assertFalse((new LapostaProvider())->isConfigured());

        Environment::setEnv('SS_LAPOSTA_API_KEY', 'api-key-123');
        Environment::setEnv('SS_LAPOSTA_LIST_ID', 'list-abc');
        $this->assertTrue((new LapostaProvider())->isConfigured());

        Environment::setEnv('SS_LAPOSTA_API_KEY', '');
        Environment::setEnv('SS_LAPOSTA_LIST_ID', '');
    }

    public function testSubscribeSendsExpectedPayload(): void
    {
        $fake = new FakeLapostaClient();
        $provider = (new LapostaProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'buyer@example.com';
        $subscription->FirstName = 'Ada';
        $subscription->Surname = 'Lovelace';
        $subscription->confirm();
        $subscription->write();

        $this->assertTrue($provider->subscribe($subscription));
        $this->assertCount(1, $fake->upserted);
        $this->assertSame('buyer@example.com', $fake->upserted[0]['email']);

        $body = $fake->upserted[0]['body'];
        // Laposta requires an `ip` on member-create; v1 sends the configurable placeholder.
        $this->assertSame('0.0.0.0', $body['ip']);
        // Idempotent upsert + single opt-in (suppress Laposta's own confirmation on top of the core's).
        $this->assertSame('true', $body['options']['upsert']);
        $this->assertSame('true', $body['options']['ignore_doubleoptin']);
    }

    public function testDoubleOptInOmitsIgnoreFlag(): void
    {
        LapostaProvider::config()->set('double_opt_in', true);
        $fake = new FakeLapostaClient();
        $provider = (new LapostaProvider())->setClient($fake);
        $this->assertTrue($provider->supportsDoubleOptIn());

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'doi@example.com';
        $subscription->confirm();
        $subscription->write();

        $provider->subscribe($subscription);
        $options = $fake->upserted[0]['body']['options'];
        // Still an idempotent upsert, but ignore_doubleoptin is omitted so Laposta runs its own opt-in.
        $this->assertSame('true', $options['upsert']);
        $this->assertArrayNotHasKey('ignore_doubleoptin', $options);

        LapostaProvider::config()->set('double_opt_in', false);
    }

    public function testCustomFieldMapping(): void
    {
        LapostaProvider::config()->set('custom_field_map', ['FirstName' => 'voornaam', 'Locale' => 'taal']);
        $fake = new FakeLapostaClient();
        $provider = (new LapostaProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'loc@example.com';
        $subscription->FirstName = 'Ada';
        $subscription->Locale = 'nl_NL';
        $subscription->confirm();
        $subscription->write();

        $provider->subscribe($subscription);
        $custom = $fake->upserted[0]['body']['custom_fields'];
        // Mapped by Laposta custom_name; empty merge values are filtered out.
        $this->assertSame('Ada', $custom['voornaam']);
        $this->assertSame('nl_NL', $custom['taal']);
        $this->assertArrayNotHasKey('achternaam', $custom);

        LapostaProvider::config()->set('custom_field_map', []);
    }

    public function testSubscribeReturnsFalseOnApiError(): void
    {
        $fake = new FakeLapostaClient();
        $fake->throwOnUpsert = true;
        $provider = (new LapostaProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'err@example.com';
        $subscription->confirm();
        $subscription->write();

        $this->assertFalse($provider->subscribe($subscription));
    }

    public function testUnsubscribe(): void
    {
        $fake = new FakeLapostaClient();
        $provider = (new LapostaProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'leave@example.com';
        $subscription->write();

        $this->assertTrue($provider->unsubscribe($subscription));
        $this->assertSame(['leave@example.com'], $fake->unsubscribed);
    }

    public function testSyncAllPushesSubscribedRecords(): void
    {
        $fake = new FakeLapostaClient();
        $provider = (new LapostaProvider())->setClient($fake);

        foreach (['one@example.com', 'two@example.com'] as $email) {
            $subscription = NewsletterSubscription::create();
            $subscription->Email = $email;
            $subscription->confirm();
            $subscription->write();
        }
        // A pending record must NOT be synced.
        $pending = NewsletterSubscription::create();
        $pending->Email = 'pending@example.com';
        $pending->Status = NewsletterSubscription::STATUS_PENDING;
        $pending->write();

        $this->assertSame(2, $provider->syncAll());
        $this->assertCount(2, $fake->upserted);
    }

    public function testParseWebhookMapsJsonBatch(): void
    {
        $provider = new LapostaProvider();
        $body = json_encode([
            'date_requested' => '2026-10-14 12:00:00',
            'data' => [
                [
                    'type' => 'member',
                    'event' => 'subscribed',
                    'data' => ['email' => 'sub@example.com', 'state' => 'active'],
                    'info' => ['action' => 'subscribed', 'source' => 'app'],
                ],
                [
                    'type' => 'member',
                    'event' => 'modified',
                    'data' => ['email' => 'mod@example.com', 'state' => 'active'],
                    'info' => [],
                ],
                [
                    'type' => 'member',
                    'event' => 'deactivated',
                    'data' => ['email' => 'off@example.com', 'state' => 'unsubscribed'],
                    'info' => ['action' => 'unsubscribed'],
                ],
                [
                    'type' => 'member',
                    'event' => 'deactivated',
                    'data' => ['email' => 'bounce@example.com', 'state' => 'unsubscribed'],
                    'info' => ['action' => 'hardbounce'],
                ],
                [
                    'type' => 'member',
                    'event' => 'deactivated',
                    'data' => ['email' => 'clean@example.com', 'state' => 'cleaned'],
                    'info' => [],
                ],
                [
                    'type' => 'member',
                    'event' => 'deactivated',
                    'data' => ['email' => 'del@example.com', 'state' => 'unsubscribed'],
                    'info' => ['action' => 'deleted'],
                ],
            ],
        ]);
        $request = new HTTPRequest('POST', 'newsletter/webhook/laposta', [], [], (string) $body);

        $events = $provider->parseWebhook($request);
        $this->assertSame([
            ['email' => 'sub@example.com', 'status' => NewsletterSubscription::STATUS_SUBSCRIBED],
            ['email' => 'mod@example.com', 'status' => NewsletterSubscription::STATUS_SUBSCRIBED],
            ['email' => 'off@example.com', 'status' => NewsletterSubscription::STATUS_UNSUBSCRIBED],
            ['email' => 'bounce@example.com', 'status' => NewsletterSubscription::STATUS_BOUNCED],
            ['email' => 'clean@example.com', 'status' => NewsletterSubscription::STATUS_CLEANED],
            ['email' => 'del@example.com', 'status' => NewsletterSubscription::STATUS_UNSUBSCRIBED],
        ], $events);
    }

    public function testParseWebhookIgnoresInvalidPayloadAndEntries(): void
    {
        $provider = new LapostaProvider();

        // Non-JSON / non-array body → nothing to apply.
        $garbage = new HTTPRequest('POST', 'newsletter/webhook/laposta', [], [], 'not json');
        $this->assertSame([], $provider->parseWebhook($garbage));

        // An entry with no email, and one with an unmapped state, are skipped.
        $body = json_encode([
            'data' => [
                ['type' => 'member', 'event' => 'modified', 'data' => ['state' => 'active'], 'info' => []],
                [
                    'type' => 'member',
                    'event' => 'noop',
                    'data' => ['email' => 'x@example.com', 'state' => ''],
                    'info' => [],
                ],
            ],
        ]);
        $request = new HTTPRequest('POST', 'newsletter/webhook/laposta', [], [], (string) $body);
        $this->assertSame([], $provider->parseWebhook($request));
    }

    public function testVerifyWebhookWithoutSecretAccepts(): void
    {
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', '');
        $request = new HTTPRequest('POST', 'newsletter/webhook/laposta');
        $this->assertTrue((new LapostaProvider())->verifyWebhook($request));
    }

    public function testVerifyWebhookAcceptsValidSignature(): void
    {
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', 'whsec');
        $body = '{"data":[{"type":"member","event":"subscribed","data":{"email":"a@example.com","state":"active"}}]}';

        $request = new HTTPRequest('POST', 'newsletter/webhook/laposta', [], [], $body);
        $request->addHeader('X-Laposta-Signature', 'sha256=' . hash_hmac('sha256', $body, 'whsec'));

        $this->assertTrue((new LapostaProvider())->verifyWebhook($request));
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', '');
    }

    public function testVerifyWebhookRejectsTamperedBody(): void
    {
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', 'whsec');
        $body = '{"data":[]}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'whsec');

        // The delivered body differs from the one that was signed → signature no longer matches.
        $request = new HTTPRequest('POST', 'newsletter/webhook/laposta', [], [], $body . 'tampered');
        $request->addHeader('X-Laposta-Signature', $signature);

        $this->assertFalse((new LapostaProvider())->verifyWebhook($request));
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', '');
    }

    public function testVerifyWebhookRejectsTamperedSignature(): void
    {
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', 'whsec');
        $body = '{"data":[]}';

        $request = new HTTPRequest('POST', 'newsletter/webhook/laposta', [], [], $body);
        $request->addHeader('X-Laposta-Signature', 'sha256=' . str_repeat('0', 64));

        $this->assertFalse((new LapostaProvider())->verifyWebhook($request));
        Environment::setEnv('SS_LAPOSTA_WEBHOOK_SECRET', '');
    }
}
