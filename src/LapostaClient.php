<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Laposta;

use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin Laposta API v2 client (Guzzle). Deliberately minimal — just the member upsert + soft-unsubscribe endpoints
 * the provider needs — to avoid the official Laposta PHP SDK (which carries its own HTTP stack). Mirrors
 * silvershop/newsletter-mailchimp's MailchimpClient and silvershop/newsletter-campaignmonitor's
 * CampaignMonitorClient.
 *
 * Two things differ from both siblings:
 *   - Authentication is HTTP Basic with the API key as the **username** and an **empty password** (curl `-u KEY:`).
 *   - The transport is **form-encoded** (`application/x-www-form-urlencoded`), not JSON. Nested parameters ride
 *     PHP's array-bracket query syntax (`custom_fields[<name>]`, `options[upsert]`), which Guzzle's `form_params`
 *     produces via http_build_query.
 *
 * The API host is configurable (`api_base`): the docs show both api.laposta.nl and api.laposta.org; the official
 * PHP SDK uses api.laposta.nl, which is the default here.
 * @see https://api.laposta.nl/doc/index.en.php
 */
class LapostaClient
{
    use Injectable;

    public const DEFAULT_API_BASE = 'https://api.laposta.nl/v2/';

    private Client $http;

    /**
     * @param array<string, mixed> $httpOptions extra Guzzle config merged last (a test seam for a mock handler)
     */
    public function __construct(
        string $apiKey,
        private string $listId,
        string $apiBase = self::DEFAULT_API_BASE,
        array $httpOptions = []
    ) {
        $this->http = new Client(array_merge([
            'base_uri' => $apiBase,
            // Laposta HTTP Basic: API key as the username, empty password.
            'auth' => [$apiKey, ''],
            'timeout' => 15,
        ], $httpOptions));
    }

    /**
     * Add or update a member on the list (idempotent upsert via `options[upsert]=true`), so this one call covers
     * both subscribe and update. Form-encoded; the client injects `list_id` + `email`, the caller supplies `ip`,
     * `custom_fields` and `options`.
     *
     * @param array<string, mixed> $form e.g. ['ip' => …, 'custom_fields' => [...], 'options' => [...]]
     * @return array<string, mixed>
     */
    public function upsertMember(string $email, array $form): array
    {
        $form['list_id'] = $this->listId;
        $form['email'] = $email;

        return $this->form('POST', 'member', $form);
    }

    /**
     * Soft-unsubscribe a member: POST member/{email} with state=unsubscribed. The member is addressed by its email
     * address in the URL (no hash), and the record + history are kept (this is NOT a hard DELETE).
     */
    public function unsubscribe(string $email): void
    {
        $this->http->request('POST', 'member/' . rawurlencode($email), [
            'form_params' => [
                'list_id' => $this->listId,
                'state' => 'unsubscribed',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function form(string $method, string $uri, array $form): array
    {
        $response = $this->http->request($method, $uri, ['form_params' => $form]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
