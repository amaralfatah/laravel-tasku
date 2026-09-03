<?php

namespace App\Http\Controllers;

use App\Concerns\StreamsWorkbook;
use App\Enums\ExportZoom;
use App\Models\Project;
use App\Models\Task;
use App\Services\WorkloadExport;
use App\Support\TaskFilters;
use App\Support\TaskOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The project timeline as the workbook the monitoring pages already produce.
 *
 * One sheet, headed by the project rather than a person — the same Gantt, the
 * same three zooms, and the same filters the page was showing, so a download
 * matches what was on screen.
 */
class ProjectExportController extends Controller
{
    use StreamsWorkbook;

    public function __construct(protected WorkloadExport $export) {}

    public function __invoke(Request $request, Project $project): StreamedResponse
    {
        $this->authorize('view', $project);

        $project->load('orgUnit:id,name');

        $spreadsheet = $this->export->build(
            [[
                'name' => $project->name,
                'subtitle' => $project->orgUnit?->name,
                'tasks' => $this->tasks($request, $project),
            ]],
            zoom: $request->enum('zoom', ExportZoom::class) ?? ExportZoom::Week,
        );

        return $this->streamWorkbook($spreadsheet, $project->name);
    }

    /**
     * The project's tasks under the page's own filters, in tree order.
     *
     * The page's sort is deliberately not carried over: a Gantt only reads
     * correctly when a parent is followed by its sub tasks.
     *
     * @return Collection<int, Task>
     */
    protected function tasks(Request $request, Project $project): Collection
    {
        $query = Task::query()
            ->where('project_id', $project->id)
            ->with('assignee:id,name,avatar_path');

        TaskFilters::fromRequest($request)->apply($query);

        return TaskOrder::tree($query->get());
    }
}
