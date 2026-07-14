<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Contracts\View\View;

/**
 * A print-friendly page listing every open team task. Team-only (panel auth);
 * the view auto-opens the browser print dialog. Reuses Task::openForReport so
 * it matches the emailed report exactly.
 */
class TasksPrintController extends Controller
{
    public function __invoke(): View
    {
        return view('tasks.print', [
            'tasks' => Task::openForReport(),
            'generatedAt' => now(),
        ]);
    }
}
