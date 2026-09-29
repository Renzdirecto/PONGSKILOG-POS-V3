<?php

namespace App\Support;

use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Store Sessions that can fund a Pamamalengke purchase (Phase 20) and how a funding session is described.
 *
 * Funded by the Branch's OPEN session, the purchase is that session's canonical Store Purchase and reduces its expected
 * closing Cash or Cashless. Funded by a CLOSED session, it is a profitability allocation only: the sealed Close Store
 * result (counted and expected balances, payments, variance) never changes.
 *
 * @phpstan-type FundingSession array{id: string, status: 'open'|'closed', label: string, opened_at: string, closed_at: string|null}
 */
class PamamalengkeFunding
{
    /** Closed sessions offered after the open one, newest first. */
    public const RECENT_CLOSED = 10;

    private const TIMEZONE = 'Asia/Manila';

    /** @return list<FundingSession> */
    public function options(Branch $branch): array
    {
        $open = StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Open)->first();
        $closed = StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Closed)
            ->orderByDesc('opened_at')->orderByDesc('id')->limit(self::RECENT_CLOSED)->get();

        return array_map(
            fn (StoreSession $session): array => $this->present($session),
            [...($open === null ? [] : [$open]), ...$closed->all()],
        );
    }

    /** @return FundingSession */
    public function present(StoreSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status === StoreSessionStatus::Open ? 'open' : 'closed',
            'label' => self::label($session),
            'opened_at' => $session->opened_at->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
        ];
    }

    /** "Sep 27 · 8:00 AM – LIVE" or "Sep 27 · 8:00 AM – 9:30 PM" (Asia/Manila), as in Reports. */
    public static function label(StoreSession $session): string
    {
        $opened = self::manila($session->opened_at);
        if ($session->closed_at === null) {
            return $opened->format('M j').' · '.$opened->format('g:i A').' – LIVE';
        }
        $closed = self::manila($session->closed_at);

        return $opened->format('M j').' · '.$opened->format('g:i A').' – '.($closed->isSameDay($opened) ? $closed->format('g:i A') : $closed->format('M j, g:i A'));
    }

    private static function manila(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::TIMEZONE);
    }
}
