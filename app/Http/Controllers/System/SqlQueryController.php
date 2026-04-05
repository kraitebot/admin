<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SqlQueryController extends Controller
{
    public function index()
    {
        $tables = $this->getTableMetadata();

        return view('system.sql-query', compact('tables'));
    }

    public function execute(Request $request)
    {
        $request->validate([
            'query' => ['required', 'string', 'max:5000'],
        ]);

        $query = trim($request->input('query'));

        // Only allow read-only statements
        $allowed = ['select', 'show', 'describe', 'desc', 'explain'];
        $firstWord = strtolower(strtok($query, " \t\n\r"));

        if (! in_array($firstWord, $allowed)) {
            return back()
                ->withInput()
                ->with('error', 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN).');
        }

        try {
            $startTime = microtime(true);
            $results = DB::select($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $results = array_map(fn ($row) => (array) $row, $results);

            $columns = ! empty($results) ? array_keys($results[0]) : [];

            return back()->withInput()->with(compact('results', 'columns', 'duration'));
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
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
