<?php

namespace App\Observers;

use App\Models\Journal;
use App\Models\User;
use App\Services\ActivityLogger;

class JournalObserver
{
    public function created(Journal $journal): void
    {
        if ($journal->created_by === null) {
            return;
        }

        $actor = User::query()->find($journal->created_by);

        if ($actor === null) {
            return;
        }

        app(ActivityLogger::class)->recordForAuthorizedActor(
            $actor,
            'accounting.journal_created',
            $journal,
            [
                'branch_id' => $journal->branch_id !== null ? (int) $journal->branch_id : null,
                'journal_date' => $journal->journal_date?->toIso8601String(),
                'reference_type' => $journal->reference_type,
                'reference_id' => $journal->reference_id !== null ? (int) $journal->reference_id : null,
            ],
        );
    }
}
