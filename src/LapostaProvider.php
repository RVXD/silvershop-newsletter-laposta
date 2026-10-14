<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Laposta;

use SilverShop\Newsletter\Model\NewsletterSubscription;
use SilverShop\Newsletter\Provider\NewsletterProvider;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Laposta implementation of silvershop/newsletter's {@see NewsletterProvider}. A thin mapping layer: it turns a
 * {@see NewsletterSubscription} into a Laposta list member (idempotent upsert + soft unsubscribe via
 * {@see LapostaClient}) and maps Laposta webhooks back onto the local subscription status. Its write side mirrors
 * the Mailchimp adapter (upsert-by-email, single opt-in by default); its webhook side is Campaign-Monitor-shaped
 * (a JSON batch of events) but is the first adapter in the suite to verify a real HMAC-SHA256 signature.
 *
 * Configuration comes from the environment (mirroring the Mailchimp adapter):
 *   SS_LAPOSTA_API_KEY        the Laposta API key (HTTP Basic username, empty password)
 *   SS_LAPOSTA_LIST_ID        the target list id
 *   SS_LAPOSTA_WEBHOOK_SECRET optional per-webhook `secret`, used to verify the X-Laposta-Signature HMAC
 *
 * The core already performs double opt-in, so the adapter sends `options[ignore_doubleoptin]=true` by default:
 * Laposta activates the member immediately and sends **no** second confirmation email. Set {@see $double_opt_in}
 * true to omit that option and let Laposta run its own confirmed opt-in (the analogue of Mailchimp's
 * subscribed/pending status).
 */
class LapostaProvider implements NewsletterProvider
{
    use Configurable;
    use Injectable;

    /**
     * When true, the adapter omits `options[ignore_doubleoptin]` so Laposta runs its own confirmed-opt-in email.
     * Off by default: the core owns double opt-in, so Laposta should activate the member silently.
     */
    private static bool $double_opt_in = false;

    /**
     * Map of NewsletterSubscription merge-field key => Laposta custom-field `custom_name`. The mapped custom
     * fields must already exist on the Laposta list. Laposta has no native first/last-name fields, so names are
     * sent through this map too (e.g. ['FirstName' => 'voornaam', 'Surname' => 'achternaam']).
     *
     * @var array<string, string>
     */
    private static array $custom_field_map = [];

    /**
     * Registration IP sent on member-create — Laposta requires an `ip`. The sync runs inside a queued job with no
     * originating web request, so a placeholder is sent by default; a shop may set its own server IP.
     */
    private static string $default_ip = '0.0.0.0';

    /**
     * Override the Laposta API host (defaults to {@see LapostaClient::DEFAULT_API_BASE}). The docs show both
     * api.laposta.nl and api.laposta.org; set this only when a sandbox needs a different host.
     */
    private static string $api_base = '';

    private ?LapostaClient $client = null;

    public function setClient(LapostaClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getKey(): string
    {
        return 'laposta';
    }

    public function getName(): string
    {
        return _t(self::class . '.NAME', 'Laposta');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->listId() !== '';
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function supportsDoubleOptIn(): bool
    {
        // The core owns double opt-in; the adapter sends ignore_doubleoptin unless $double_opt_in is set, in which
        // case Laposta runs the confirmed-opt-in step.
        return (bool) static::config()->get('double_opt_in');
    }

    public function supportsEcommerce(): bool
    {
        return false;
    }

    public function supportsMergeFields(): bool
    {
        return true;
    }

    public function supportsTags(): bool
    {
        // Laposta has no tags; it has custom fields + segments.
        return false;
    }

    public function supportsSegments(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function subscribe(NewsletterSubscription $subscription): bool
    {
        return $this->upsert($subscription);
    }

    public function update(NewsletterSubscription $subscription): bool
    {
        return $this->upsert($subscription);
    }

    public function unsubscribe(NewsletterSubscription $subscription): bool
    {
        try {
            $this->client()->unsubscribe((string) $subscription->Email);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function syncAll(): int
    {
        $synced = 0;
        $subscribers = NewsletterSubscription::get()->filter([
            'Status' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'Consent' => true,
        ]);
        foreach ($subscribers as $subscription) {
            if ($this->upsert($subscription)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function verifyWebhook(HTTPRequest $request): bool
    {
        // Laposta signs every webhook with HMAC-SHA256 of the RAW request body, keyed with the webhook's `secret`,
        // delivered as `X-Laposta-Signature: sha256=<hex>`. Recompute and constant-time compare. (This is the
        // first adapter in the suite that verifies a genuine signature — the siblings only string-compare a shared
        // secret.)
        $secret = (string) Environment::getEnv('SS_LAPOSTA_WEBHOOK_SECRET');
        if ($secret === '') {
            // No secret configured — accept (the webhook only ever changes status for emails already known locally).
            return true;
        }

        $provided = (string) $request->getHeader('X-Laposta-Signature');
        $expected = 'sha256=' . hash_hmac('sha256', (string) $request->getBody(), $secret);

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(HTTPRequest $request): array
    {
        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            return [];
        }

        // Laposta posts { "date_requested": …, "data": [ { "type": "member", "event": …, "data": { … }, "info": …
        // }, … ] } — a batch of events, parsed like Campaign Monitor's loop but keyed off the richer member state.
        $rawEvents = $payload['data'] ?? (array_is_list($payload) ? $payload : []);
        $events = [];
        foreach ($rawEvents as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $member = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $info = is_array($entry['info'] ?? null) ? $entry['info'] : [];
            $email = (string) ($member['email'] ?? '');
            $status = $this->mapEvent(
                (string) ($member['state'] ?? ''),
                (string) ($entry['event'] ?? ''),
                (string) ($info['action'] ?? '')
            );
            if ($email !== '' && $status !== '') {
                $events[] = ['email' => $email, 'status' => $status];
            }
        }

        return $events;
    }

    /**
     * Map a Laposta webhook event to a local subscription status. The member `state` is the primary signal; the
     * finer `info.action` (hardbounce / deleted) wins over it, and the `event` name is a fallback when no state is
     * present.
     */
    private function mapEvent(string $state, string $event, string $action): string
    {
        // Finer deactivation actions take precedence over the coarse member state.
        $byAction = match (strtolower($action)) {
            'hardbounce' => NewsletterSubscription::STATUS_BOUNCED,
            'deleted' => NewsletterSubscription::STATUS_UNSUBSCRIBED,
            default => '',
        };
        if ($byAction !== '') {
            return $byAction;
        }

        // The member state is the primary signal.
        $byState = match (strtolower($state)) {
            'active', 'subscribed' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'unsubscribed', 'deactivated' => NewsletterSubscription::STATUS_UNSUBSCRIBED,
            'cleaned' => NewsletterSubscription::STATUS_CLEANED,
            'hardbounce' => NewsletterSubscription::STATUS_BOUNCED,
            default => '',
        };
        if ($byState !== '') {
            return $byState;
        }

        // Fall back to the event name when the state is absent.
        return match (strtolower($event)) {
            'subscribed', 'modified' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'deactivated' => NewsletterSubscription::STATUS_UNSUBSCRIBED,
            default => '',
        };
    }

    private function upsert(NewsletterSubscription $subscription): bool
    {
        try {
            $this->client()->upsertMember((string) $subscription->Email, $this->payload($subscription));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the Laposta upsert-member form payload from a subscription (the client adds `list_id` + `email`). All
     * merge data goes through {@see $custom_field_map}; `options[upsert]` makes the call idempotent and
     * `options[ignore_doubleoptin]` suppresses Laposta's own confirmation unless {@see $double_opt_in} is set.
     *
     * @return array<string, mixed>
     */
    public function payload(NewsletterSubscription $subscription): array
    {
        $merge = $subscription->getMergeFields();

        $customFields = [];
        foreach ((array) static::config()->get('custom_field_map') as $field => $name) {
            if (!empty($merge[$field])) {
                $customFields[$name] = $merge[$field];
            }
        }

        $options = ['upsert' => 'true'];
        if (!static::config()->get('double_opt_in')) {
            $options['ignore_doubleoptin'] = 'true';
        }

        return [
            'ip' => $this->registrationIp(),
            'custom_fields' => $customFields,
            'options' => $options,
        ];
    }

    private function registrationIp(): string
    {
        $ip = (string) static::config()->get('default_ip');

        return $ip !== '' ? $ip : '0.0.0.0';
    }

    private function apiKey(): string
    {
        return (string) Environment::getEnv('SS_LAPOSTA_API_KEY');
    }

    private function listId(): string
    {
        return (string) Environment::getEnv('SS_LAPOSTA_LIST_ID');
    }

    private function client(): LapostaClient
    {
        if ($this->client) {
            return $this->client;
        }

        $apiBase = (string) static::config()->get('api_base');
        if ($apiBase === '') {
            $apiBase = LapostaClient::DEFAULT_API_BASE;
        }

        return $this->client = LapostaClient::create($this->apiKey(), $this->listId(), $apiBase);
    }
}
