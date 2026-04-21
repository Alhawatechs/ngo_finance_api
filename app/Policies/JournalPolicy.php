<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;
use App\Services\OfficeScopeService;

/**
 * Journal books (CRUD + soft delete / restore / permanent delete) — permissions + office scope.
 */
class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-journal-books');
    }

    public function view(User $user, Journal $journal): bool
    {
        if (! $user->can('view-journal-books')) {
            return false;
        }
        if ($journal->trashed() && ! $user->can('delete-journal-books')) {
            return false;
        }

        return app(OfficeScopeService::class)->userCanAccessJournalBook($user, $journal);
    }

    public function create(User $user): bool
    {
        return $user->can('create-journal-books');
    }

    public function update(User $user, Journal $journal): bool
    {
        if (! $user->can('edit-journal-books')) {
            return false;
        }

        return app(OfficeScopeService::class)->userCanAccessJournalBook($user, $journal);
    }

    public function delete(User $user, Journal $journal): bool
    {
        if (! $user->can('delete-journal-books')) {
            return false;
        }

        return app(OfficeScopeService::class)->userCanAccessJournalBook($user, $journal);
    }

    public function restore(User $user, Journal $journal): bool
    {
        if (! $user->can('delete-journal-books')) {
            return false;
        }

        return app(OfficeScopeService::class)->userCanAccessJournalBook($user, $journal);
    }

    public function forceDelete(User $user, Journal $journal): bool
    {
        if (! $user->can('delete-journal-books-permanently')) {
            return false;
        }

        return app(OfficeScopeService::class)->userCanAccessJournalBook($user, $journal);
    }
}
