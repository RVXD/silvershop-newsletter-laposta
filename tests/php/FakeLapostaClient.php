<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Laposta\Tests;

use SilverShop\Newsletter\Laposta\LapostaClient;

/**
 * In-memory stand-in for {@link LapostaClient} — records calls, returns canned responses, makes no HTTP request.
 * Deliberately does not call the parent constructor (so no Guzzle setup). Mirrors the Mailchimp adapter's
 * FakeMailchimpClient. The real client's form-encoding / HTTP Basic are covered by {@link LapostaClientTest}.
 */
class FakeLapostaClient extends LapostaClient
{
    /** @var array<int, array{email: string, body: array<string, mixed>}> */
    public array $upserted = [];

    /** @var array<int, string> */
    public array $unsubscribed = [];

    public bool $throwOnUpsert = false;

    public function __construct()
    {
        // No HTTP client.
    }

    public function upsertMember(string $email, array $form): array
    {
        if ($this->throwOnUpsert) {
            throw new \RuntimeException('Simulated Laposta API error');
        }
        $this->upserted[] = ['email' => $email, 'body' => $form];

        return ['member' => ['member_id' => $email, 'email' => $email]];
    }

    public function unsubscribe(string $email): void
    {
        $this->unsubscribed[] = $email;
    }
}
