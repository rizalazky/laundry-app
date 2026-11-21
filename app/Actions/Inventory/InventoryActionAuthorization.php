<?php

namespace App\Actions\Inventory;

use App\Actions\Orders\OrderActionAuthorization;
use App\Models\Branch;
use App\Models\User;
use DomainException;

class InventoryActionAuthorization
{
    public function __construct(
        private OrderActionAuthorization $authorization,
    ) {}

    public function authorizeBranch(User $user, Branch $branch, string $permission): Branch
    {
        $authorizedBranch = $this->authorization->authorizeBranch(
            $user,
            $branch,
            ['tenant_owner', 'branch_manager', 'cashier', 'operator'],
        );

        $actor = User::query()->find($user->getKey());

        if ($actor === null || ! $actor->hasPermissionTo($permission)) {
            throw new DomainException('Anda tidak memiliki izin untuk melakukan tindakan ini.');
        }

        return $authorizedBranch;
    }
}
