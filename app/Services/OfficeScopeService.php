<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Central vs regional office access for user and role management.
 * Central admins: manage_all_offices or can_manage_all_offices → any office in org.
 * Regional admins: manage_office_users / manage_office_roles → own office only.
 */
class OfficeScopeService
{
    public function canManageAllOffices(User $user): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }
        if ($user->can_manage_all_offices ?? false) {
            return true;
        }
        return $user->hasPermissionTo('manage_all_offices');
    }

    /**
     * Office IDs the user is allowed to manage users for (same org).
     * Empty = none; null = all offices in org.
     *
     * @return array<int>|null
     */
    public function allowedOfficeIdsForUsers(User $user): ?array
    {
        if ($this->canManageAllOffices($user)) {
            return null; // all offices
        }
        if ($user->hasPermissionTo('manage_office_users') || $user->hasPermissionTo('manage-users')) {
            return $user->office_id ? [$user->office_id] : [];
        }
        return [];
    }

    /**
     * Office IDs the user is allowed to manage roles for (same org).
     * null = all (org-level + any office); array = only those office ids + org-level if allowed.
     *
     * @return array<int>|null
     */
    public function allowedOfficeIdsForRoles(User $user): ?array
    {
        if ($this->canManageAllOffices($user)) {
            return null; // all offices
        }
        if ($user->hasPermissionTo('manage_organization_roles')) {
            // Can manage org-level roles; office-level only for own office
            $officeIds = $user->office_id ? [$user->office_id] : [];
            return $officeIds; // we'll treat "can manage org roles" as able to see org-level; office-level restricted to own
        }
        if ($user->hasPermissionTo('manage_office_roles') || $user->hasPermissionTo('manage-roles')) {
            return $user->office_id ? [$user->office_id] : [];
        }
        return [];
    }

    /**
     * Whether the user can manage organization-level (office_id null) roles.
     */
    public function canManageOrganizationRoles(User $user): bool
    {
        return $this->canManageAllOffices($user) || $user->hasPermissionTo('manage_organization_roles');
    }

    /**
     * Whether the current user can manage a user in the given office.
     */
    public function canActOnUserInOffice(User $currentUser, ?int $targetUserOfficeId): bool
    {
        $allowed = $this->allowedOfficeIdsForUsers($currentUser);
        if ($allowed === null) {
            return true;
        }
        if ($targetUserOfficeId === null) {
            return true; // head-office user with no office
        }
        return in_array($targetUserOfficeId, $allowed, true);
    }

    /**
     * Whether the current user can act on a role with the given office_id (null = org-level).
     */
    public function canActOnRoleInOffice(User $currentUser, ?int $roleOfficeId): bool
    {
        if ($roleOfficeId === null) {
            return $this->canManageOrganizationRoles($currentUser);
        }
        $allowed = $this->allowedOfficeIdsForRoles($currentUser);
        if ($allowed === null) {
            return true;
        }
        return in_array($roleOfficeId, $allowed, true);
    }

    /**
     * Get office IDs for listing (users). Returns null for "all", or list of office ids.
     *
     * @return array<int>|null
     */
    public function listOfficeIdsForUsers(User $user): ?array
    {
        return $this->allowedOfficeIdsForUsers($user);
    }

    /**
     * Get office IDs for listing (roles). Returns null for all offices + org-level.
     *
     * @return array<int>|null
     */
    public function listOfficeIdsForRoles(User $user): ?array
    {
        return $this->allowedOfficeIdsForRoles($user);
    }

    /**
     * Whether the user may see journal books for every office (head office + all provinces).
     */
    public function canViewAllJournalBooks(User $user): bool
    {
        if ($user->hasRole('super-admin') || $user->hasRole('finance-director')) {
            return true;
        }
        if ($this->canManageAllOffices($user)) {
            return true;
        }
        if ($user->hasPermissionTo('view-all-journal-books')) {
            return true;
        }

        return false;
    }

    /**
     * Provincial / office-scoped users may only access journal books tied to their office.
     * Books with null office_id are treated as organization-wide and only visible to users who can view all journal books.
     */
    public function userCanAccessJournalBook(User $user, Journal $journal): bool
    {
        if ((int) $journal->organization_id !== (int) $user->organization_id) {
            return false;
        }
        if ($this->canViewAllJournalBooks($user)) {
            return true;
        }
        if (! $user->office_id) {
            return false;
        }
        if ($journal->office_id === null) {
            return false;
        }

        return (int) $journal->office_id === (int) $user->office_id;
    }

    /**
     * Restrict journal book queries to the user's office when they are not allowed to see all offices.
     */
    public function applyJournalBookScope(Builder $query, User $user): void
    {
        if ($this->canViewAllJournalBooks($user)) {
            return;
        }
        if ($user->office_id) {
            $query->where('office_id', $user->office_id);
        } else {
            $query->whereRaw('1 = 0');
        }
    }
}
