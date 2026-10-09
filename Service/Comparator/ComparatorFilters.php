<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the comparator screen is asked to show, read once from the query
 * string and carried as-is into links, so a filter survives paging and tabs.
 */
final readonly class ComparatorFilters
{
    public const PAGE_SIZE = 50;
    private const MAX_SEARCH_LENGTH = 80;

    public function __construct(
        public ComparatorView $view = ComparatorView::Gaps,
        public ?int $competitorId = null,
        public string $search = '',
        public bool $cheaperOnly = false,
        public int $page = 1,
    ) {
    }

    /**
     * Read leniently: the form itself sends competitor= for "all
     * competitors", and anyone can type anything in the address bar. Since
     * Symfony 6.3, getInt() and get() throw on such values (reported on
     * 2026-10-09); a filter that makes no sense falls back to its default.
     */
    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();

        return new self(
            ComparatorView::fromQuery($query['view'] ?? null),
            self::positiveInt($query['competitor'] ?? null),
            mb_substr(trim(\is_string($query['q'] ?? null) ? $query['q'] : ''), 0, self::MAX_SEARCH_LENGTH),
            \in_array(self::scalar($query['cheaper'] ?? null), ['1', 'true', 'on'], true),
            self::positiveInt($query['page'] ?? null) ?? 1,
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        $value = self::scalar($value);

        return $value !== null && preg_match('/^[1-9]\d{0,8}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * A URL only carries strings; a request built in code may carry ints.
     */
    private static function scalar(mixed $value): ?string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PAGE_SIZE;
    }

    /**
     * @return array<string, string|int>
     */
    public function toQueryParams(): array
    {
        return array_filter([
            'view' => $this->view->value,
            'competitor' => $this->competitorId ?? 0,
            'q' => $this->search,
            'cheaper' => $this->cheaperOnly ? 1 : 0,
            'page' => $this->page > 1 ? $this->page : 0,
        ], static fn (string|int $value): bool => $value !== '' && $value !== 0);
    }

    public function withView(ComparatorView $view): self
    {
        return new self($view, $this->competitorId, $this->search, $this->cheaperOnly);
    }

    public function withPage(int $page): self
    {
        return new self($this->view, $this->competitorId, $this->search, $this->cheaperOnly, max(1, $page));
    }
}
