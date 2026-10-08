<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Normalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;

final class TextAndBrandNormalizerTest extends TestCase
{
    private TextNormalizer $text;
    private BrandNormalizer $brand;

    protected function setUp(): void
    {
        $this->text = new TextNormalizer();
        $this->brand = new BrandNormalizer($this->text);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function texts(): iterable
    {
        yield 'accents folded' => ['Blouson cuir vachette à côtés renforcés', 'blouson cuir vachette a cotes renforces'];
        yield 'punctuation becomes a separator' => ['Casque jet - Custom 500 (2026)', 'casque jet custom 500 2026'];
        yield 'collapsed whitespace' => ["  Gants   \t été  ", 'gants ete'];
        yield 'ligatures expanded' => ['Œuf, straße', 'oeuf strasse'];
        yield 'already clean' => ['ixon tracker', 'ixon tracker'];
        yield 'digits kept' => ['EN 17092-4', 'en 17092 4'];
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
        yield 'punctuation only' => ['---', ''];
    }

    #[DataProvider('texts')]
    public function testTextNormalisation(?string $raw, string $expected): void
    {
        self::assertSame($expected, $this->text->normalize($raw));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function brands(): iterable
    {
        yield 'case folded' => ['IXON', 'ixon'];
        yield 'trailing space' => ['Ixon ', 'ixon'];
        yield 'spaces removed' => ['Alpine Stars', 'alpinestars'];
        yield 'accent folded' => ['Ségura', 'segura'];
        yield 'punctuation removed' => ['Five-Gloves', 'fivegloves'];
        yield 'empty becomes null' => ['', null];
        yield 'null stays null' => [null, null];
        yield 'punctuation only becomes null' => ['.', null];
    }

    #[DataProvider('brands')]
    public function testBrandNormalisation(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->brand->normalize($raw));
    }

    public function testTwoSpellingsOfTheSameBrandCollapseToOneKey(): void
    {
        self::assertSame(
            $this->brand->normalize('ALPINESTARS'),
            $this->brand->normalize('Alpine Stars'),
        );
    }

    public function testDistinctBrandsDoNotCollapse(): void
    {
        self::assertNotSame(
            $this->brand->normalize('Ixon'),
            $this->brand->normalize('Icon'),
        );
    }
}
