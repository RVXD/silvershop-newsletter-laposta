<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Laposta\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\Newsletter\Laposta\LapostaClient;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the real {@link LapostaClient} against a mocked Guzzle handler to pin the Laposta-specific transport:
 * form-encoded bodies (NOT JSON), HTTP Basic with the API key as the username + an empty password, and PHP
 * array-bracket nesting for options[...] / custom_fields[...].
 */
class LapostaClientTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @param array<int, mixed> $transactions captured request/response history
     */
    private function clientWithHistory(array &$transactions, ?Response $response = null): LapostaClient
    {
        $mock = new MockHandler([$response ?? new Response(200, [], (string) json_encode(['member' => []]))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new LapostaClient('my-api-key', 'list-123', LapostaClient::DEFAULT_API_BASE, ['handler' => $stack]);
    }

    public function testUpsertMemberSendsFormEncodedRequest(): void
    {
        $transactions = [];
        $client = $this->clientWithHistory($transactions);

        $client->upsertMember('buyer@example.com', [
            'ip' => '0.0.0.0',
            'custom_fields' => ['voornaam' => 'Ada'],
            'options' => ['upsert' => 'true', 'ignore_doubleoptin' => 'true'],
        ]);

        $this->assertCount(1, $transactions);
        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v2/member', $request->getUri()->getPath());

        // Form-encoded transport (NOT application/json — the key difference from the Mailchimp/CM siblings).
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

        // HTTP Basic: API key as the username, empty password → base64("my-api-key:").
        $this->assertSame('Basic ' . base64_encode('my-api-key:'), $request->getHeaderLine('Authorization'));

        $raw = (string) $request->getBody();
        // Nested params ride PHP array-bracket syntax (url-encoded "[" is "%5B").
        $this->assertStringContainsString('options%5Bupsert%5D=true', $raw);
        $this->assertStringContainsString('options%5Bignore_doubleoptin%5D=true', $raw);
        $this->assertStringContainsString('custom_fields%5Bvoornaam%5D=Ada', $raw);

        parse_str($raw, $parsed);
        $this->assertSame('list-123', $parsed['list_id']);
        $this->assertSame('buyer@example.com', $parsed['email']);
        $this->assertSame('0.0.0.0', $parsed['ip']);
        $this->assertSame('true', $parsed['options']['upsert']);
        $this->assertSame('true', $parsed['options']['ignore_doubleoptin']);
        $this->assertSame('Ada', $parsed['custom_fields']['voornaam']);
    }

    public function testUnsubscribeSoftStateChangeByEmail(): void
    {
        $transactions = [];
        $client = $this->clientWithHistory($transactions);

        $client->unsubscribe('leave@example.com');

        $this->assertCount(1, $transactions);
        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];

        // Soft unsubscribe: POST member/{email} (email in the URL, not a DELETE, not an md5 hash).
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v2/member/leave%40example.com', $request->getUri()->getPath());

        parse_str((string) $request->getBody(), $parsed);
        $this->assertSame('list-123', $parsed['list_id']);
        $this->assertSame('unsubscribed', $parsed['state']);
    }
}
