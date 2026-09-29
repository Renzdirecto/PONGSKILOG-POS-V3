<?php

namespace App\Support;

use App\Models\Branch;

/**
 * The serialization point of Branch configuration writers: assortment add/remove/copy, recipe mode, Recipes, Add-on
 * effects, Plans, Ingredient setup and Operations setup copies.
 *
 * They lock the Branch row FOR NO KEY UPDATE before anything else. That conflicts with every commercial writer of the
 * same Branch (POS/QR commits hold it FOR UPDATE; edits, voids, Giveaways and stock writers FOR SHARE), so a sale either
 * commits entirely before a configuration change or runs entirely after it: it never sells a removed Product and never
 * snapshots a half-old/half-new recipe. It does not conflict with plain foreign-key KEY SHARE checks, so unrelated
 * inserts that reference the Branch are never blocked. Another Branch is never locked.
 */
final class BranchConfiguration
{
    /** Locks one Branch's configuration for writing and returns the fresh row. */
    public static function lock(Branch $branch): Branch
    {
        return Branch::query()->whereKey($branch->id)->lock('for no key update')->firstOrFail();
    }

    /**
     * Locks a copy's source (FOR SHARE: its configuration cannot change mid-copy) and destination (for writing) in
     * Branch-id order, so a MAIN → QAVE copy and a QAVE → MAIN copy running together never deadlock.
     *
     * @return array{0: Branch, 1: Branch} the fresh source and destination rows
     */
    public static function lockCopy(Branch $source, Branch $destination): array
    {
        $locked = [];
        foreach (collect([$source, $destination])->sortBy('id') as $branch) {
            $locked[$branch->id] = $branch->is($destination)
                ? self::lock($branch)
                : Branch::query()->whereKey($branch->id)->sharedLock()->firstOrFail();
        }

        return [$locked[$source->id], $locked[$destination->id]];
    }
}
