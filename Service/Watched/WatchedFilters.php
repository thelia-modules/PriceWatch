<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the watched products list is asked to show. Read leniently from the
 * query string: an empty or odd value means the default, never an error
 * (getInt() throws on those since Symfony 6.3).
 */
final readonly class WatchedFilters
{
    public const PAGE_SIZE = 50;
    public const SORTS = ['gap', 'collected', 'title'];
    private const MAX_SEARCH_LENGTH = 80;

    public function __construct(
        public WatchedView $view = WatchedView::All,
        public string $search = '',
        public string $sort = 'gap',
        public int $page = 1,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();
        $page = $query['page'] ?? null;
        $page = (\is_string($page) || \is_int($page)) && preg_match('/^[1-9]\d{0,8}$/', (string) $page) === 1 ? (int) $page : 1;
        $sort = $query['sort'] ?? null;

        return new self(
            WatchedView::fromQuery($query['view'] ?? null),
            mb_substr(trim(\is_string($query['q'] ?? null) ? $query['q'] : ''), 0, self::MAX_SEARCH_LENGTH),
            \is_string($sort) && \in_array($sort, self::SORTS, true) ? $sort : 'gap',
            $page,
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
            'view' => $this->view === WatchedView::All ? '' : $this->view->value,
            'q' => $this->search,
            'sort' => $this->sort === 'gap' ? '' : $this->sort,
            'page' => $this->page > 1 ? $this->page : 0,
        ], static fn (string|int $value): bool => $value !== '' && $value !== 0);
    }

    public function withView(WatchedView $view): self
    {
        return new self($view, $this->search, $this->sort);
    }

    public function withSort(string $sort): self
    {
        return new self($this->view, $this->search, $sort);
    }

    public function withPage(int $page): self
    {
        return new self($this->view, $this->search, $this->sort, max(1, $page));
    }
}
