<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\ExecuteSqlQueryRequest;
use App\Services\ReadOnlySqlBrowser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SqlQueryController extends Controller
{
    public function index(): View
    {
        $tables = $this->tableNames();

        return view('system.sql-query', compact('tables'));
    }

    public function tables(): JsonResponse
    {
        return response()->json(['tables' => $this->tableNames()]);
    }

    public function execute(
        ExecuteSqlQueryRequest $request,
        ReadOnlySqlBrowser $sqlBrowser,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            return response()->json($sqlBrowser->execute(
                query: $validated['query'],
                page: (int) ($validated['page'] ?? 1),
                perPage: (int) ($validated['per_page'] ?? 10),
                filters: $validated['filters'] ?? [],
            ));
        } catch (Throwable $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        $tables = Schema::getTableListing(
            Schema::getCurrentSchemaName(),
            schemaQualified: false,
        );
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($tables);
    }
}
