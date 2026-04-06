<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SqlQueryController extends Controller
{
    public function index()
    {
        $tables = $this->getTableMetadata();

        return view('system.sql-query', compact('tables'));
    }

    public function tables(): JsonResponse
    {
        return response()->json($this->getTableMetadata());
    }

    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'max:5000'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
        ]);

        $query = trim($request->input('query'));
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 25);

        $allowed = ['select', 'show', 'describe', 'desc', 'explain'];
        $firstWord = strtolower(strtok($query, " \t\n\r"));

        if (! in_array($firstWord, $allowed)) {
            return response()->json([
                'error' => 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN).',
            ], 422);
        }

        try {
            $startTime = microtime(true);

            // Get total count by wrapping in a subquery
            $countQuery = "SELECT COUNT(*) as total FROM ({$query}) as _count_sub";
            $total = DB::select($countQuery)[0]->total;

            // Get paginated results
            $offset = ($page - 1) * $perPage;
            $paginatedQuery = "SELECT * FROM ({$query}) as _page_sub LIMIT {$perPage} OFFSET {$offset}";
            $results = DB::select($paginatedQuery);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $results = array_map(fn ($row) => (array) $row, $results);
            $columns = ! empty($results) ? array_keys($results[0]) : [];

            return response()->json([
                'results' => $results,
                'columns' => $columns,
                'duration' => $duration,
                'total' => (int) $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function getTableMetadata(): array
    {
        $database = DB::getDatabaseName();
        $tables = DB::select('SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]);

        $metadata = [];

        foreach ($tables as $table) {
            $columns = DB::select(
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$database, $table->TABLE_NAME]
            );

            $metadata[] = [
                'name' => $table->TABLE_NAME,
                'rows' => $table->TABLE_ROWS ?? 0,
                'columns' => array_map(fn ($col) => [
                    'name' => $col->COLUMN_NAME,
                    'type' => $col->DATA_TYPE,
                    'nullable' => $col->IS_NULLABLE === 'YES',
                    'key' => $col->COLUMN_KEY,
                ], $columns),
            ];
        }

        return $metadata;
    }
}
