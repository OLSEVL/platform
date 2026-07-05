<?php

namespace Tests\Unit;

use App\Support\MirrorProxyMeta;
use PHPUnit\Framework\TestCase;

class MirrorProxyMetaTest extends TestCase
{
    public function test_empty_proxy_means_direct(): void
    {
        $meta = MirrorProxyMeta::forTradeMeta(null);

        $this->assertTrue($meta['mirror_proxy_direct']);
        $this->assertNull($meta['mirror_proxy_host']);
        $this->assertNull($meta['mirror_proxy_url']);
    }

    public function test_redacts_password_and_sets_host(): void
    {
        $meta = MirrorProxyMeta::forTradeMeta('http://user:secret@38.225.15.13:5293');

        $this->assertFalse($meta['mirror_proxy_direct']);
        $this->assertSame('38.225.15.13', $meta['mirror_proxy_host']);
        $this->assertSame(5293, $meta['mirror_proxy_port']);
        $this->assertSame('http://user:***@38.225.15.13:5293', $meta['mirror_proxy_url']);
        $this->assertStringNotContainsString('secret', (string) $meta['mirror_proxy_url']);
    }
}
