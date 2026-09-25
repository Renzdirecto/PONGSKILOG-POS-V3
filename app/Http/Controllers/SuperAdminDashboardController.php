<?php

namespace App\Http\Controllers;

use App\Http\Requests\SuperAdminDashboardRequest;
use App\Models\Branch;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BusinessSnapshot;
use App\Support\ExecutiveSnapshot;
use App\Support\SalesAnalytics;
use Closure;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminDashboardController extends Controller
{
    /** Top products listed on the executive overview; Reports lists them all. */
    private const PRODUCT_LIMIT = 5;

    /**
     * The Super Admin Executive Dashboard for the global Branch scope. Every money figure is the Owner Dashboard's own
     * SalesAnalytics result for the same period (no second calculation); live operating state reuses BusinessSnapshot;
     * Staff, notification and Audit summaries come from ExecutiveSnapshot. Props are lazy and memoized, so a realtime
     * partial reload computes only what it asked for.
     */
    public function __invoke(
        SuperAdminDashboardRequest $request,
        ActiveBranchContext $context,
        SalesAnalytics $analytics,
        BusinessSnapshot $snapshot,
        ExecutiveSnapshot $executive,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        $period = $request->validated('period') ?? 'today';

        $sales = $this->memo(fn (): array => $analytics->for($branch, ['date' => $period]));
        $inventory = $this->memo(fn (): array => $snapshot->inventoryAttention($branch));
        $stores = $this->memo(fn (): array => $executive->stores($branch));
        $ingredients = $this->memo(fn (): array => $executive->ingredientsOut($branch));
        $security = $this->memo(fn (): array => $executive->security($user));

        return Inertia::render('super-admin/dashboard', [
            'period' => $period,
            'analytics' => fn (): array => $this->analytics($sales()['analytics']),
            'report' => fn (): array => $this->report($sales()['report']),
            'kitchen' => fn (): array => $snapshot->kitchen($branch),
            'inventory' => $inventory,
            'stores' => $stores,
            'ingredients' => $ingredients,
            'people' => fn (): array => $executive->people(),
            'security' => $security,
            'attention' => fn (): array => $this->attention($branch, $inventory(), $ingredients(), $stores(), $security()),
        ]);
    }

    /**
     * The analytics sections the executive view shows, straight from SalesAnalytics (Top products trimmed).
     *
     * @param  array<string, mixed>  $analytics
     * @return array<string, mixed>
     */
    private function analytics(array $analytics): array
    {
        /** @var list<array{sales_cents: int, quantity: int}> $products */
        $products = $analytics['products'];
        usort($products, fn (array $a, array $b): int => [$b['sales_cents'], $b['quantity']] <=> [$a['sales_cents'], $a['quantity']]);

        return [
            ...array_intersect_key($analytics, array_flip(['comparison', 'kpis', 'trend', 'collections', 'payment_mix', 'categories', 'branches', 'highlights'])),
            'products' => array_slice($products, 0, self::PRODUCT_LIMIT),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function report(array $report): array
    {
        /** @var list<array<string, mixed>> $sessions */
        $sessions = $report['sessions'];

        return [
            'period' => $report['period'],
            'scope' => $report['scope'],
            'summary' => array_intersect_key($report['summary'], array_flip(['expenses', 'sessions', 'voids'])),
            'latest_session' => $sessions === [] ? null : $sessions[array_key_last($sessions)],
        ];
    }

    /**
     * Actionable items from real state only, most serious first. Tones: critical (a product or Ingredient cannot be
     * sold), warning (low stock), notice (a closed Store) and security (unread Control Center notifications).
     *
     * @param  array<string, mixed>  $inventory
     * @param  array{total: int, branches: list<array{code: string, count: int}>}  $ingredients
     * @param  list<array{branch: array{id: string, name: string, code: string}, open: bool, opened_at: string|null, opened_by: string|null}>  $stores
     * @param  array{unread: int, audit: list<array<string, mixed>>}  $security
     * @return list<array{key: string, tone: 'critical'|'warning'|'notice'|'security', title: string, detail: string, href: string}>
     */
    private function attention(?Branch $branch, array $inventory, array $ingredients, array $stores, array $security): array
    {
        $scope = $branch === null ? 'across Branches' : 'at '.$branch->code;
        [$out, $low] = $inventory['mode'] === 'branch'
            ? [(int) $inventory['out_of_stock'], (int) $inventory['low_stock']]
            : [
                (int) array_sum(array_column($inventory['branches'], 'out_of_stock')),
                (int) array_sum(array_column($inventory['branches'], 'low_stock')),
            ];
        $closed = array_values(array_filter($stores, fn (array $store): bool => ! $store['open']));
        $plural = fn (int $count, string $word): string => $count.' '.$word.($count === 1 ? '' : 's');

        return array_values(array_filter([
            $out > 0 ? [
                'key' => 'products_out',
                'tone' => 'critical',
                'title' => $plural($out, 'product').' out of stock',
                'detail' => 'Hidden from POS and the QR menu '.$scope.' until restocked.',
                'href' => route('inventory.index', ['stock_status' => 'out_of_stock'], false),
            ] : null,
            $ingredients['total'] > 0 ? [
                'key' => 'ingredients_out',
                'tone' => 'critical',
                'title' => $plural($ingredients['total'], 'Ingredient').' at zero',
                'detail' => 'Recipes that use them are unavailable ('.implode(', ', array_map(fn (array $row): string => $row['code'].' '.$row['count'], $ingredients['branches'])).').',
                'href' => route('operations.stock', [], false),
            ] : null,
            $low > 0 ? [
                'key' => 'products_low',
                'tone' => 'warning',
                'title' => $plural($low, 'product').' running low',
                'detail' => 'At or below the low-stock threshold '.$scope.'.',
                'href' => route('inventory.index', ['stock_status' => 'low_stock'], false),
            ] : null,
            $security['unread'] > 0 ? [
                'key' => 'notifications',
                'tone' => 'security',
                'title' => $plural($security['unread'], 'unread notification'),
                'detail' => 'Staff, access and stock alerts waiting in the Control Center.',
                'href' => route('super-admin.notifications', [], false),
            ] : null,
            $closed !== [] ? [
                'key' => 'stores_closed',
                'tone' => 'notice',
                'title' => count($stores) === count($closed)
                    ? (count($stores) === 1 ? 'Store is closed' : 'Every Store is closed')
                    : $plural(count($closed), 'Store').' closed',
                'detail' => 'No open Store Session: '.implode(', ', array_map(fn (array $store): string => $store['branch']['code'], $closed)).'.',
                'href' => route('workspaces.reports', [], false),
            ] : null,
        ]));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return Closure(): T
     */
    private function memo(Closure $resolve): Closure
    {
        $cache = [];

        return function () use ($resolve, &$cache) {
            if (! array_key_exists('value', $cache)) {
                $cache['value'] = $resolve();
            }

            return $cache['value'];
        };
    }
}
