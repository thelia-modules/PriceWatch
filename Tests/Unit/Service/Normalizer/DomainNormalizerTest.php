<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Normalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\DomainNormalizer;

final class DomainNormalizerTest extends TestCase
{
    private DomainNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new DomainNormalizer();
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function hosts(): iterable
    {
        yield 'bare domain' => ['dafy-moto.com', 'dafy-moto.com'];
        yield 'full url with query' => ['https://www.Dafy-Moto.com/casque/xyz?utm=1', 'dafy-moto.com'];
        yield 'scheme only' => ['http://motoblouz.com', 'motoblouz.com'];
        yield 'port stripped' => ['https://motoblouz.com:8443/x', 'motoblouz.com'];
        yield 'www stripped' => ['www.motoblouz.com', 'motoblouz.com'];
        yield 'trailing dot stripped' => ['motoblouz.com.', 'motoblouz.com'];
        yield 'subdomain kept by host()' => ['https://shop.dafy-moto.com/x', 'shop.dafy-moto.com'];
        yield 'uppercase folded' => ['MOTOBLOUZ.COM', 'motoblouz.com'];
        yield 'no tld is refused' => ['localhost', null];
        yield 'ip address is refused' => ['192.168.1.1', null];
        yield 'empty is refused' => ['', null];
        yield 'null is refused' => [null, null];
    }

    #[DataProvider('hosts')]
    public function testHost(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer->host($raw));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function registrableDomains(): iterable
    {
        yield 'two labels' => ['dafy-moto.com', 'dafy-moto.com'];
        yield 'subdomain collapsed' => ['https://shop.dafy-moto.com/x', 'dafy-moto.com'];
        yield 'deep subdomain collapsed' => ['a.b.c.dafy-moto.com', 'dafy-moto.com'];
        yield 'multi label suffix kept' => ['www.motoblouz.co.uk', 'motoblouz.co.uk'];
        yield 'subdomain under multi label suffix' => ['shop.motoblouz.co.uk', 'motoblouz.co.uk'];
        yield 'refused host stays null' => ['localhost', null];
    }

    #[DataProvider('registrableDomains')]
    public function testRegistrableDomain(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer->registrableDomain($raw));
    }

    public function testEveryUrlOfOneCompetitorCollapsesToOneKey(): void
    {
        $urls = [
            'https://www.dafy-moto.com/',
            'http://dafy-moto.com/casque-jet',
            'https://shop.dafy-moto.com/p/123?ref=a',
            'DAFY-MOTO.COM',
        ];
        $keys = array_map(fn (string $url): ?string => $this->normalizer->registrableDomain($url), $urls);

        self::assertCount(1, array_unique($keys));
        self::assertSame('dafy-moto.com', $keys[0]);
    }
}
