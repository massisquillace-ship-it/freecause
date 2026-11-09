<?php

namespace Tests\Unit;

use App\Rules\SafeExternalImageUrl;
use Tests\TestCase;

class SafeExternalImageUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['petition.verify_external_image' => true]);
    }

    public function test_private_local_link_local_and_reserved_addresses_are_rejected(): void
    {
        $addresses = [
            '10.0.0.1',
            '127.0.0.1',
            '169.254.1.1',
            '100.64.0.1',
            '192.0.0.1',
            '198.18.0.1',
            '64:ff9b::7f00:1',
            '2002:7f00:1::',
        ];

        foreach ($addresses as $address) {
            $host = str_contains($address, ':') ? "[{$address}]" : $address;

            $this->assertSame(
                'The image link points to a private or local address.',
                $this->staticChecks("https://{$host}/image.jpg"),
                $address
            );
        }
    }

    public function test_public_literal_ip_passes_shape_and_address_checks(): void
    {
        $this->assertNull($this->staticChecks('https://93.184.216.34/image.jpg'));
    }

    private function staticChecks(string $url): ?string
    {
        $rule = new SafeExternalImageUrl;
        $method = (new \ReflectionClass($rule))->getMethod('staticChecks');
        $method->setAccessible(true);

        return $method->invoke($rule, $url);
    }
}
