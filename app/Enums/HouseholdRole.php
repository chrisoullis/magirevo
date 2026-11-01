<?php

namespace App\Enums;

enum HouseholdRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Roles that can be granted by invitation or role change. Ownership only moves through a transfer.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Viewer];
    }

    /**
     * @return list<HouseholdAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Owner => HouseholdAbility::cases(),
            self::Admin => [
                HouseholdAbility::View,
                HouseholdAbility::Update,
                HouseholdAbility::ManageMembers,
                HouseholdAbility::ManageInvitations,
                HouseholdAbility::EditPlans,
                HouseholdAbility::EditShopping,
                HouseholdAbility::ConsumeBatches,
            ],
            self::Member => [
                HouseholdAbility::View,
                HouseholdAbility::EditPlans,
                HouseholdAbility::EditShopping,
                HouseholdAbility::ConsumeBatches,
            ],
            self::Viewer => [HouseholdAbility::View],
        };
    }

    public function can(HouseholdAbility $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /**
     * The owner manages everyone else; an admin manages only members and viewers, never peers or the owner.
     */
    public function canManage(self $target): bool
    {
        return match ($this) {
            self::Owner => $target !== self::Owner,
            self::Admin => in_array($target, [self::Member, self::Viewer], true),
            default => false,
        };
    }

    /**
     * Roles this role may hand out when inviting or changing a role.
     *
     * @return list<self>
     */
    public function grantableRoles(): array
    {
        return array_values(array_filter(self::assignable(), fn (self $role): bool => $this->canManage($role)));
    }

    public function label(): string
    {
        return __('households.roles.'.$this->value);
    }
}
