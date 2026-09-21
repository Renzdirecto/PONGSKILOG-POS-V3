<?php

namespace App\Actions\Catalog;

use App\Models\User;

class CreateInlineModifierGroups
{
    public function __construct(
        private CreateModifierGroup $createGroup,
        private CreateModifierOption $createOption,
    ) {}

    /**
     * @param  list<array{name: string, semantic_role: string|null, selection_type: string, min_select: int, max_select: int, is_active: bool, options: list<array{name: string, price_delta: string, sort_order: int, is_active: bool}>}>  $groups
     * @return list<string>
     */
    public function execute(User $user, array $groups): array
    {
        $ids = [];

        foreach ($groups as $groupAttributes) {
            $options = $groupAttributes['options'];
            unset($groupAttributes['options']);

            $group = $this->createGroup->execute($user, $groupAttributes);

            foreach ($options as $optionAttributes) {
                $this->createOption->execute($user, [
                    ...$optionAttributes,
                    'modifier_group_id' => $group->id,
                ]);
            }

            $ids[] = $group->id;
        }

        return $ids;
    }
}
