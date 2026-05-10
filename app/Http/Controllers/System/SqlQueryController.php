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
            'per_page' => ['sometimes', 'integer', 'in:10,15,20,50,100'],
        ]);

        $query = trim($request->input('query'));
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);

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

    public function truncate(Request $request): JsonResponse
    {
        $request->validate([
            'table' => ['required', 'string'],
        ]);

        $table = str_replace('`', '', $request->input('table'));

        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return response()->json(['error' => "Table \"{$table}\" does not exist."], 422);
        }

        try {
            DB::statement('TRUNCATE TABLE `'.$table.'`');

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function primaryKey(Request $request): JsonResponse
    {
        $request->validate([
            'table' => ['required', 'string'],
        ]);

        $table = str_replace('`', '', $request->input('table'));

        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return response()->json(['pk' => null, 'reason' => 'Table does not exist'], 422);
        }

        $keys = DB::select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");

        if (count($keys) === 0) {
            return response()->json(['pk' => null, 'reason' => 'No primary key']);
        }

        if (count($keys) > 1) {
            return response()->json(['pk' => null, 'reason' => 'Composite primary key not supported']);
        }

        return response()->json(['pk' => $keys[0]->Column_name]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'table' => ['required', 'string'],
            'pk_column' => ['required', 'string'],
            'pk_value' => ['required'],
            'column' => ['required', 'string'],
            'value' => ['nullable'],
        ]);

        $table = str_replace('`', '', $request->input('table'));
        $pkColumn = str_replace('`', '', $request->input('pk_column'));
        $column = str_replace('`', '', $request->input('column'));
        $pkValue = $request->input('pk_value');
        $value = $request->input('value');

        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return response()->json(['error' => "Table \"{$table}\" does not exist."], 422);
        }

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

        if (! in_array($pkColumn, $columns, true)) {
            return response()->json(['error' => "Column \"{$pkColumn}\" does not exist on \"{$table}\"."], 422);
        }

        if (! in_array($column, $columns, true)) {
            return response()->json(['error' => "Column \"{$column}\" does not exist on \"{$table}\"."], 422);
        }

        $resolvedValue = (is_string($value) && strtoupper($value) === 'NULL') ? null : $value;

        try {
            DB::table($table)
                ->where($pkColumn, $pkValue)
                ->update([$column => $resolvedValue]);

            return response()->json(['success' => true, 'value' => $resolvedValue]);
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
