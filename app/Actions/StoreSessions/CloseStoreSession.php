<?php

namespace App\Actions\StoreSessions;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerCatalogChanged;
use App\Events\DisplayOrdersChanged;
use App\Events\StoreClosed;
use App\Http\Requests\CloseStoreSessionRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\PosAccess;
use App\Support\StoreSessionReconciliation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Closes the current Store Session atomically.
 *
 * Lock order: idempotency advisory lock → Branch (exclusive) → OPEN StoreSession (exclusive) → unclaimed QR Orders
 * (exclusive, by id). This matches Pay Now / Pay Later / reservation ordering (Branch → Session → Order) and holds the
 * exclusive Session boundary before any Order row, so every shared-Session mutation either commits first or observes
 * the closed Session.
 */
class CloseStoreSession
{
    public const MINIMUM_NOTE_LENGTH = 5;

    public function __construct(
        private PosAccess $access,
        private StoreSessionReconciliation $reconciliation,
        private ArchiveCustomerQrOrder $archive,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{session: StoreSession, replayed: bool, qr_archived_count: int}
     */
    public function execute(User $actor, Branch $branch, array $input): array
    {
        /** @var array{idempotency_key: string, store_session_id: string, closing_cash_amount: string, closing_cashless_amount: string, closing_note?: string|null} $data */
        $data = Validator::make($input, CloseStoreSessionRequest::closeRules(), CloseStoreSessionRequest::closeMessages())->validate();
        $key = strtolower($data['idempotency_key']);
        $expectedSessionId = strtolower($data['store_session_id']);
        $note = trim((string) ($data['closing_note'] ?? ''));
        $note = $note === '' ? null : $note;
        $actual = [
            'cash' => ExactMoney::cents($data['closing_cash_amount']),
            'cashless' => ExactMoney::cents($data['closing_cashless_amount']),
        ];

        return DB::transaction(function () use ($actor, $branch, $key, $expectedSessionId, $note, $actual): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['store-close:'.$key]);
            }

            $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $actor = $this->access->authorize($actor, $branch);
            if (! $actor->hasPermission('store.open_close')) {
                throw new AuthorizationException('You are not authorized to close this store.');
            }

            $requestHash = hash('sha256', json_encode([
                'branch_id' => $branch->id,
                'store_session_id' => $expectedSessionId,
                'actor_id' => $actor->id,
                'closing_cash_amount' => ExactMoney::decimal($actual['cash']),
                'closing_cashless_amount' => ExactMoney::decimal($actual['cashless']),
                'closing_note' => $note,
            ], JSON_THROW_ON_ERROR));

            $replay = AuditLog::query()->where('idempotency_key', $key)->first();
            if ($replay !== null) {
                if ($replay->action !== 'store.closed'
                    || $replay->user_id !== $actor->id
                    || data_get($replay->metadata, 'request_hash') !== $requestHash) {
                    abort(409, 'This close attempt has already been used with different details. Review the closing summary and try again.');
                }
                $session = StoreSession::query()->where('branch_id', $branch->id)->findOrFail($replay->auditable_id);

                return [
                    'session' => $session,
                    'replayed' => true,
                    'qr_archived_count' => (int) data_get($session->reconciliation_snapshot, 'qr_archived_count', 0),
                ];
            }

            $session = StoreSession::query()
                ->where('branch_id', $branch->id)
                ->where('status', StoreSessionStatus::Open)
                ->lockForUpdate()
                ->first();
            if ($session === null) {
                abort(409, 'The Store is already closed. Refresh to see the current Store state.');
            }
            if ($session->id !== $expectedSessionId) {
                abort(409, 'The Store Session changed. Refresh the closing summary and try again.');
            }

            $blockerCounts = $this->reconciliation->blockerCounts($this->reconciliation->blockers($branch, $session));
            $this->rejectBlockers($blockerCounts);

            $cents = $this->reconciliation->calculate($branch, $session);
            $variances = $this->reconciliation->variances($cents['expected'], $actual);
            $this->enforceVarianceRules($variances, $note);

            $closedAt = now();
            $archivedCount = 0;
            $this->reconciliation->unclaimedQrOrders($branch, $session)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function ($order) use ($closedAt, &$archivedCount): void {
                    $archivedCount += (int) $this->archive->execute($order, 'store_closed', $closedAt);
                });

            $snapshot = [
                ...$this->reconciliation->present($cents),
                'actual' => ['cash' => ExactMoney::decimal($actual['cash']), 'cashless' => ExactMoney::decimal($actual['cashless'])],
                'variance' => ['cash' => ExactMoney::signedDecimal($variances['cash']), 'cashless' => ExactMoney::signedDecimal($variances['cashless'])],
                'qr_archived_count' => $archivedCount,
                'blocker_counts' => $blockerCounts,
                'formula' => 'expected = opening + sales - expenses - corrections - voids',
                'variance_definition' => 'actual - expected',
            ];
            $before = [
                'status' => $session->status->value,
                'opened_at' => $session->opened_at->toIso8601String(),
                'opening_cash_amount' => $session->opening_cash_amount,
                'opening_cashless_amount' => $session->opening_cashless_amount,
            ];

            $session->update([
                'status' => StoreSessionStatus::Closed,
                'expected_cash_amount' => ExactMoney::signedDecimal($cents['expected']['cash']),
                'expected_cashless_amount' => ExactMoney::signedDecimal($cents['expected']['cashless']),
                'closing_cash_amount' => ExactMoney::decimal($actual['cash']),
                'closing_cashless_amount' => ExactMoney::decimal($actual['cashless']),
                'cash_variance' => ExactMoney::signedDecimal($variances['cash']),
                'cashless_variance' => ExactMoney::signedDecimal($variances['cashless']),
                'closing_note' => $note,
                'reconciliation_snapshot' => $snapshot,
                'closed_by_user_id' => $actor->id,
                'closed_at' => $closedAt,
            ]);

            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'store_sessions',
                action: 'store.closed',
                auditableType: StoreSession::class,
                auditableId: $session->id,
                before: $before,
                after: [
                    'status' => StoreSessionStatus::Closed->value,
                    'closed_at' => $closedAt->toIso8601String(),
                    'expected_cash_amount' => $session->expected_cash_amount,
                    'expected_cashless_amount' => $session->expected_cashless_amount,
                    'closing_cash_amount' => $session->closing_cash_amount,
                    'closing_cashless_amount' => $session->closing_cashless_amount,
                    'cash_variance' => $session->cash_variance,
                    'cashless_variance' => $session->cashless_variance,
                    'closing_note' => $note,
                ],
                metadata: [
                    'request_hash' => $requestHash,
                    'store_session_id' => $session->id,
                    'reconciliation' => $snapshot,
                    'qr_archived_count' => $archivedCount,
                    'blocker_counts' => $blockerCounts,
                ],
                idempotencyKey: $key,
            );

            StoreClosed::dispatch($session);
            CustomerCatalogChanged::dispatch($branch->id);
            DisplayOrdersChanged::dispatch($branch, $closedAt);

            return ['session' => $session, 'replayed' => false, 'qr_archived_count' => $archivedCount];
        }, attempts: 3);
    }

    /** @param array{outstanding: int, kitchen: int, loaded_qr: int, corrections: int} $counts */
    private function rejectBlockers(array $counts): void
    {
        $messages = array_filter([
            'outstanding' => $counts['outstanding'] > 0 ? 'Resolve all outstanding balances before closing.' : null,
            'kitchen' => $counts['kitchen'] > 0 ? 'All committed Kitchen orders must be Done before closing.' : null,
            'loaded_qr' => $counts['loaded_qr'] > 0 ? 'Complete payment or cancel LOAD for every loaded QR order before closing.' : null,
            'corrections' => $counts['corrections'] > 0 ? 'Payment correction requires Cash/Cashless allocation before closing this Store Session.' : null,
        ]);
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /** @param array{cash: int, cashless: int} $variances */
    private function enforceVarianceRules(array $variances, ?string $note): void
    {
        $shortages = [];
        foreach (['cash' => 'Cash', 'cashless' => 'Cashless'] as $method => $label) {
            if ($variances[$method] < 0) {
                $shortages["closing_{$method}_amount"] = "Closing {$label} is ".ExactMoney::display(abs($variances[$method])).' short. Review the count or missing transactions before closing.';
            }
        }
        /** Cash and Cashless are independent channels: an overage never offsets a shortage. */
        if ($shortages !== []) {
            throw ValidationException::withMessages($shortages);
        }
        if (($variances['cash'] > 0 || $variances['cashless'] > 0)
            && ($note === null || mb_strlen($note) < self::MINIMUM_NOTE_LENGTH)) {
            throw ValidationException::withMessages([
                'closing_note' => $note === null
                    ? 'Add an explanation for the overage before closing.'
                    : 'Explain the overage in at least '.self::MINIMUM_NOTE_LENGTH.' characters.',
            ]);
        }
    }
}
