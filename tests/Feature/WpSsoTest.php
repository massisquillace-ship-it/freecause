<?php

namespace Tests\Feature;

use App\Support\WpSso;
use Tests\TestCase;

class WpSsoTest extends TestCase
{
    private function parts(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_signature_covers_the_redirect_target(): void
    {
        config(['app.sso_secret' => 'test-secret']);

        $q = $this->parts(WpSso::loginUrl('a@example.com', 'A', '/en/profile'));

        $this->assertSame(hash_hmac('sha256', $q['p'].'|/en/profile', 'test-secret'), $q['s']);
        $this->assertNotSame(hash_hmac('sha256', $q['p'].'|/en/other', 'test-secret'), $q['s']);
    }

    public function test_every_link_carries_a_fresh_nonce(): void
    {
        config(['app.sso_secret' => 'test-secret']);

        $first = json_decode(base64_decode($this->parts(WpSso::loginUrl('a@example.com', 'A', '/en'))['p']), true);
        $second = json_decode(base64_decode($this->parts(WpSso::loginUrl('a@example.com', 'A', '/en'))['p']), true);

        $this->assertNotEmpty($first['j']);
        $this->assertNotSame($first['j'], $second['j']);
    }
}
