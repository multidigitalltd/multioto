<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Jobs\NotifyTaskCreatedJob;
use Filament\Resources\Pages\CreateRecord;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    /** Tell the assignees (or, if none, the managers) a task just landed. */
    protected function afterCreate(): void
    {
        NotifyTaskCreatedJob::dispatch($this->record->id);
    }
}
