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

    public static function fromRequest(Request $request): self
    {
        $competitor = $request->query->getInt('competitor');

        return new self(
            ComparatorView::fromQuery($request->query->get('view')),
            $competitor > 0 ? $competitor : null,
            mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_SEARCH_LENGTH),
            $request->query->getBoolean('cheaper'),
            max(1, $request->query->getInt('page', 1)),
        );
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
