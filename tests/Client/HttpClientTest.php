<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Client;

use PHPUnit\Framework\TestCase;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\HttpClient;

/**
 * What every call carries: the API key, the license key of an existing
 * Tarsus customer, and a brain ID only when there is one.
 */
final class HttpClientTest extends TestCase
{
    public function testAPartnerCustomerSendsApiKeyAndBrainId(): void
    {
        $client = new InspectableClient('https://brain.example', 'key-1', 'brain-1');
        $fields = $client->fields(['brain_id' => 'brain-1', 'state' => 'ask']);
        $this->assertSame('key-1', $fields['api_key']);
        $this->assertSame('brain-1', $fields['brain_id']);
        $this->assertArrayNotHasKey(HttpClient::LICENSE_FIELD, $fields, 'no license key, no field');
        $this->assertSame('ask', $fields['state'], 'the caller\'s fields pass through');
    }

    public function testAnExistingTarsusCustomerSendsTheLicenseKeyWithTheApiKey(): void
    {
        $client = new InspectableClient('https://brain.example', 'key-1', '', ' LK-42 ');
        $fields = $client->fields(['brain_id' => $client->brainForTest(), 'state' => 'ask']);
        $this->assertSame('key-1', $fields['api_key'], 'the API key still travels');
        $this->assertSame('LK-42', $fields[HttpClient::LICENSE_FIELD], 'Der Partner übergibt den License-Key zusammen mit dem API-Key');
        $this->assertArrayNotHasKey('brain_id', $fields, 'a license key stands in place of a brain ID');
    }

    public function testTheLicenseKeyReplacesAConfiguredBrainId(): void
    {
        $client = new InspectableClient('https://brain.example', 'key-1', 'brain-9', 'LK-42');
        $fields = $client->fields(['brain_id' => $client->brainForTest()]);
        $this->assertArrayNotHasKey('brain_id', $fields, 'either api_key and brain_id, or api_key and license_key - never all three');
        $this->assertSame('LK-42', $fields[HttpClient::LICENSE_FIELD]);
        $this->assertSame('key-1', $fields['api_key']);
    }

    public function testWithoutBrainAndLicenseKeyTheClientRefusesToCall(): void
    {
        $client = new InspectableClient('https://brain.example', 'key-1', '');
        $this->expectException(ConfigurationException::class);
        $client->brainForTest();
    }

    public function testTheLicenseFieldIsOneConstant(): void
    {
        $this->assertSame('license_key', HttpClient::LICENSE_FIELD, 'switching the transport is a one-line change');
    }
}

/**
 * Exposes the request assembly without any network.
 */
final class InspectableClient extends HttpClient
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function fields(array $params): array
    {
        return $this->requestFields($params);
    }

    public function brainForTest(): string
    {
        $method = new \ReflectionMethod(HttpClient::class, 'brain');
        $method->setAccessible(true);
        return (string) $method->invoke($this);
    }
}
