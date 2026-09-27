<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @phpstan-type SqlPage array{results: array<int, array<string, mixed>>, columns: array<int, string>, duration: float, total: int, page: int, per_page: int, last_page: int}
 */
final class ReadOnlySqlBrowser
{
    /**
     * @param  array<string, string|null>  $filters
     * @return SqlPage
     */
    public function execute(
        string $query,
        int $page = 1,
        int $perPage = 10,
        array $filters = [],
    ): array {
        $query = $this->normalizeQuery($query);
        [$whereClause, $bindings] = $this->buildFilters($filters);

        return $this->withinReadOnlyTransaction(
            fn (Connection $connection): array => $this->queryPage(
                $connection,
                "({$query}) AS _sql_editor_source",
                $whereClause,
                $bindings,
                $page,
                $perPage,
            ),
        );
    }

    /**
     * @param  array<int, string>  $bindings
     * @return SqlPage
     */
    private function queryPage(
        Connection $connection,
        string $source,
        string $whereClause,
        array $bindings,
        int $page,
        int $perPage,
    ): array {
        $startedAt = microtime(true);
        $count = $connection->selectOne("SELECT COUNT(*) AS aggregate FROM {$source}{$whereClause}", $bindings);
        $total = (int) ($count?->aggregate ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $lastPage);
        $offset = ($currentPage - 1) * $perPage;
        $rows = $connection->select("SELECT * FROM {$source}{$whereClause} LIMIT {$perPage} OFFSET {$offset}", $bindings);
        $results = array_map(static fn (object $row): array => (array) $row, $rows);

        return [
            'results' => $results,
            'columns' => $results === [] ? [] : array_keys($results[0]),
            'duration' => round((microtime(true) - $startedAt) * 1000, 2),
            'total' => $total,
            'page' => $currentPage,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * @param  Closure(Connection): SqlPage  $callback
     * @return SqlPage
     */
    private function withinReadOnlyTransaction(Closure $callback): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return $callback($connection);
        }

        $connection->statement('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();

        try {
            return $callback($connection);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);

        if (str_ends_with($query, ';')) {
            $query = rtrim(substr($query, 0, -1));
        }

        $structuralSql = ltrim($this->withoutQuotedContentOrComments($query));
        $this->assertSelectQuery($query, $structuralSql);
        $this->assertSafeSelectOperations($structuralSql);

        return $query;
    }

    private function assertSelectQuery(string $query, string $structuralSql): void
    {
        if ($query === '' || preg_match('/\ASELECT\b/i', $structuralSql) !== 1) {
            throw new InvalidArgumentException('Only SELECT statements are allowed.');
        }

        if (str_contains($structuralSql, ';')) {
            throw new InvalidArgumentException('Run one SELECT statement at a time.');
        }
    }

    private function assertSafeSelectOperations(string $structuralSql): void
    {
        if (preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $structuralSql) === 1) {
            throw new InvalidArgumentException('File-writing SELECT statements are not allowed.');
        }

        if (preg_match('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $structuralSql) === 1) {
            throw new InvalidArgumentException('Locking SELECT statements are not allowed.');
        }

        if (preg_match('/\b(?:LOAD_FILE|GET_LOCK|RELEASE_LOCK|SLEEP|BENCHMARK)\s*\(/i', $structuralSql) === 1) {
            throw new InvalidArgumentException('This SELECT function is not allowed in the inspection workspace.');
        }
    }

    private function withoutQuotedContentOrComments(string $query): string
    {
        $length = strlen($query);
        $sanitized = '';
        $state = 'sql';

        for ($index = 0; $index < $length; $index++) {
            $character = $query[$index];
            $next = $index + 1 < $length ? $query[$index + 1] : '';
            [$replacement, $offset, $state] = $this->scanSqlCharacter($state, $character, $next);
            $sanitized .= $replacement;
            $index += $offset;
        }

        return $sanitized;
    }

    /** @return array{string, int, string} */
    private function scanSqlCharacter(string $state, string $character, string $next): array
    {
        if ($state === 'line-comment') {
            return $this->scanLineComment($character);
        }
        if ($state === 'block-comment') {
            return $this->scanBlockComment($character, $next);
        }
        if ($state !== 'sql') {
            return $this->scanQuotedContent($state, $character, $next);
        }

        return $this->scanSqlContent($character, $next);
    }

    /** @return array{string, int, string} */
    private function scanLineComment(string $character): array
    {
        return $character === "\n" ? ["\n", 0, 'sql'] : [' ', 0, 'line-comment'];
    }

    /** @return array{string, int, string} */
    private function scanBlockComment(string $character, string $next): array
    {
        if ($character === '*' && $next === '/') {
            return ['  ', 1, 'sql'];
        }

        return [$character === "\n" ? "\n" : ' ', 0, 'block-comment'];
    }

    /** @return array{string, int, string} */
    private function scanQuotedContent(string $quote, string $character, string $next): array
    {
        if ($character === '\\') {
            return $next !== '' ? ['  ', 1, $quote] : [' ', 0, $quote];
        }
        if ($character === $quote) {
            return $next === $quote ? ['  ', 1, $quote] : [' ', 0, 'sql'];
        }

        return [' ', 0, $quote];
    }

    /** @return array{string, int, string} */
    private function scanSqlContent(string $character, string $next): array
    {
        $comment = $this->commentStart($character, $next);
        if ($comment !== null) {
            return $comment;
        }
        if (in_array($character, ["'", '"', '`'], true)) {
            return [' ', 0, $character];
        }

        return [$character, 0, 'sql'];
    }

    /** @return array{string, int, string}|null */
    private function commentStart(string $character, string $next): ?array
    {
        if ($this->startsDoubleDashComment($character, $next)) {
            return ['  ', 1, 'line-comment'];
        }
        if ($character === '#') {
            return [' ', 0, 'line-comment'];
        }
        if ($character === '/' && $next === '*') {
            return ['  ', 1, 'block-comment'];
        }

        return null;
    }

    private function startsDoubleDashComment(string $character, string $next): bool
    {
        return $character === '-' && $next === '-';
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return array{0: string, 1: array<int, string>}
     */
    private function buildFilters(array $filters): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            [$clause, $binding] = $this->filterClause((string) $column, $value);
            $clauses[] = $clause;
            if ($binding !== null) {
                $bindings[] = $binding;
            }
        }

        return [$this->filterWhereClause($clauses), $bindings];
    }

    /** @param array<int, string> $clauses */
    private function filterWhereClause(array $clauses): string
    {
        return $clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses);
    }

    /** @return array{string, ?string} */
    private function filterClause(string $column, string $value): array
    {
        $identifier = $this->quoteIdentifier($column);

        if (strcasecmp($value, 'NULL') === 0) {
            return ["{$identifier} IS NULL", null];
        }

        if (str_contains($value, '*')) {
            $escaped = str_replace(['!', '%', '_', '*'], ['!!', '!%', '!_', '%'], $value);

            return ["{$identifier} LIKE ? ESCAPE '!'", $escaped];
        }

        return ["{$identifier} = ?", $value];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
