<?php

namespace App\Policies;

use App\Models\Profile;
use App\Models\SupportTicket;

class SupportTicketPolicy
{
    private const CUSTOMER_ROLES = ['buyer', 'seller', 'logistics', 'driver', 'courier', 'rider'];

    public function viewAny(Profile $user): bool
    {
        return $this->create($user) || $this->isActiveAdmin($user);
    }

    public function view(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->viewOwn($user, $supportTicket) || $this->viewAsAdmin($user, $supportTicket);
    }

    public function create(Profile $user): bool
    {
        return in_array($user->role, self::CUSTOMER_ROLES, true)
            && $user->status === 'approved'
            && $user->account_status === 'active';
    }

    public function viewOwn(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->create($user) && $supportTicket->created_by === $user->id;
    }

    public function replyAsCreator(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->viewOwn($user, $supportTicket)
            && in_array($supportTicket->status, ['submitted', 'open', 'waiting_for_customer', 'escalated', 'reopened'], true);
    }

    public function closeAsCreator(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->viewOwn($user, $supportTicket) && $supportTicket->status === 'resolved';
    }

    public function reopenAsCreator(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->viewOwn($user, $supportTicket)
            && in_array($supportTicket->status, ['resolved', 'closed'], true);
    }

    public function viewAsAdmin(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->isActiveAdmin($user)
            && ($supportTicket->assigned_admin_id === null || $supportTicket->assigned_admin_id === $user->id);
    }

    public function assignAsAdmin(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->viewAsAdmin($user, $supportTicket);
    }

    public function addInternalNote(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->isAssignedAdmin($user, $supportTicket);
    }

    public function replyAsCustomerService(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->isAssignedAdmin($user, $supportTicket)
            && ! in_array($supportTicket->status, ['resolved', 'closed'], true);
    }

    public function changeStatus(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->isAssignedAdmin($user, $supportTicket);
    }

    public function resolve(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->isAssignedAdmin($user, $supportTicket)
            && ! in_array($supportTicket->status, ['resolved', 'closed'], true);
    }

    private function isActiveAdmin(Profile $user): bool
    {
        return $user->role === Profile::ROLE_ADMIN && $user->account_status === 'active';
    }

    private function isAssignedAdmin(Profile $user, SupportTicket $supportTicket): bool
    {
        return $this->isActiveAdmin($user) && $supportTicket->assigned_admin_id === $user->id;
    }
}
