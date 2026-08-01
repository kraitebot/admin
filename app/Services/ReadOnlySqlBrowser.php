<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReadOnlySqlBrowser
{
    /**
     * @param  array<string, string|null>  $filters
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     columns: array<int, string>,
     *     duration: float,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int
     * }
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
            function (Connection $connection) use ($bindings, $page, $perPage, $query, $whereClause): array {
                $startedAt = microtime(true);
                $source = "({$query}) AS _sql_editor_source";
                $count = $connection->selectOne(
                    "SELECT COUNT(*) AS aggregate FROM {$source}{$whereClause}",
                    $bindings,
                );
                $total = (int) ($count?->aggregate ?? 0);
                $lastPage = max(1, (int) ceil($total / $perPage));
                $currentPage = min($page, $lastPage);
                $offset = ($currentPage - 1) * $perPage;

                $rows = $connection->select(
                    "SELECT * FROM {$source}{$whereClause} LIMIT {$perPage} OFFSET {$offset}",
                    $bindings,
                );
                $results = array_map(
                    static fn (object $row): array => (array) $row,
                    $rows,
                );

                return [
                    'results' => $results,
                    'columns' => $results === [] ? [] : array_keys($results[0]),
                    'duration' => round((microtime(true) - $startedAt) * 1000, 2),
                    'total' => $total,
                    'page' => $currentPage,
                    'per_page' => $perPage,
                    'last_page' => $lastPage,
                ];
            },
        );
    }

    /**
     * @param  Closure(Connection): array{
     *     results: array<int, array<string, mixed>>,
     *     columns: array<int, string>,
     *     duration: float,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int
     * }  $callback
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     columns: array<int, string>,
     *     duration: float,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int
     * }
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

        if ($query === '' || preg_match('/\ASELECT\b/i', $structuralSql) !== 1) {
            throw new InvalidArgumentException('Only SELECT statements are allowed.');
        }

        if (str_contains($structuralSql, ';')) {
            throw new InvalidArgumentException('Run one SELECT statement at a time.');
        }

        if (preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $structuralSql) === 1) {
            throw new InvalidArgumentException('File-writing SELECT statements are not allowed.');
        }

        if (preg_match('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $structuralSql) === 1) {
            throw new InvalidArgumentException('Locking SELECT statements are not allowed.');
        }

        if (preg_match('/\b(?:LOAD_FILE|GET_LOCK|RELEASE_LOCK|SLEEP|BENCHMARK)\s*\(/i', $structuralSql) === 1) {
            throw new InvalidArgumentException('This SELECT function is not allowed in the inspection workspace.');
        }

        return $query;
    }

    private function withoutQuotedContentOrComments(string $query): string
    {
        $length = strlen($query);
        $sanitized = '';
        $state = 'sql';

        for ($index = 0; $index < $length; $index++) {
            $character = $query[$index];
            $next = $index + 1 < $length ? $query[$index + 1] : '';

            if ($state === 'line-comment') {
                if ($character === "\n") {
                    $state = 'sql';
                    $sanitized .= "\n";
                } else {
                    $sanitized .= ' ';
                }

                continue;
            }

            if ($state === 'block-comment') {
                if ($character === '*' && $next === '/') {
                    $sanitized .= '  ';
                    $index++;
                    $state = 'sql';
                } else {
                    $sanitized .= $character === "\n" ? "\n" : ' ';
                }

                continue;
            }

            if ($state !== 'sql') {
                $sanitized .= ' ';

                if ($character === '\\') {
                    if ($next !== '') {
                        $sanitized .= ' ';
                        $index++;
                    }

                    continue;
                }

                if ($character === $state) {
                    if ($next === $state) {
                        $sanitized .= ' ';
                        $index++;
                    } else {
                        $state = 'sql';
                    }
                }

                continue;
            }

            if ($character === '-' && $next === '-') {
                $sanitized .= '  ';
                $index++;
                $state = 'line-comment';

                continue;
            }

            if ($character === '#') {
                $sanitized .= ' ';
                $state = 'line-comment';

                continue;
            }

            if ($character === '/' && $next === '*') {
                $sanitized .= '  ';
                $index++;
                $state = 'block-comment';

                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $sanitized .= ' ';
                $state = $character;

                continue;
            }

            $sanitized .= $character;
        }

        return $sanitized;
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

            $identifier = $this->quoteIdentifier((string) $column);

            if (strcasecmp($value, 'NULL') === 0) {
                $clauses[] = "{$identifier} IS NULL";

                continue;
            }

            if (str_contains($value, '*')) {
                $escaped = str_replace(
                    ['!', '%', '_', '*'],
                    ['!!', '!%', '!_', '%'],
                    $value,
                );
                $clauses[] = "{$identifier} LIKE ? ESCAPE '!'";
                $bindings[] = $escaped;

                continue;
            }

            $clauses[] = "{$identifier} = ?";
            $bindings[] = $value;
        }

        return [
            $clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses),
            $bindings,
        ];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
