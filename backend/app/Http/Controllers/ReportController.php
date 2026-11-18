<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\Reports\CsvExport;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportScope;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin: validate, derive the scope from the signed-in user, run the report. Admin and agency routes share
 * this controller; the route middleware decides who may call which, and the scope (never request input)
 * decides what they see.
 */
class ReportController extends Controller
{
    public function admin(ReportRequest $request, string $type): Response|JsonResponse
    {
        return $this->respond($request);
    }

    public function agency(ReportRequest $request, string $type): Response|JsonResponse
    {
        return $this->respond($request);
    }

    private function respond(ReportRequest $request): Response|JsonResponse
    {
        $report = $request->report();
        $filters = $request->filters();
        $scope = ReportScope::fromUser($request->user());

        if ($filters->csv) {
            $export = $report->csv($filters, $scope);

            return CsvExport::response($this->filename($report->type(), $filters), $export['headers'], $export['rows'], $export['truncated']);
        }

        return response()->json($report->run($filters, $scope));
    }

    private function filename(string $type, ReportFilters $filters): string
    {
        return "report-{$type}-{$filters->fromDate()}-{$filters->toDate()}.csv";
    }
}
