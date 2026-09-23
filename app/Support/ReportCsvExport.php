<?php

namespace App\Support;

/**
 * Plain CSV rows of an Owner report, built only from the already authorized and filtered report and analytics arrays,
 * so an export can never contain a figure or a Branch the on-screen report does not.
 */
class ReportCsvExport
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $analytics
     * @param  list<string>  $filterLabels
     * @return list<array<int, mixed>>
     */
    public function rows(array $report, array $analytics, array $filterLabels): array
    {
        $period = $report['period'];
        $scope = $report['scope'] === null ? 'All Branches' : $report['scope']['code'].' · '.$report['scope']['name'];
        $kpis = $analytics['kpis'];
        $share = fn (mixed $basisPoints): string => is_int($basisPoints) ? number_format($basisPoints / 100, 2, '.', '') : '';
        $rows = [
            ['PONGSKILOG Sales report'],
            ['Branch scope', $scope],
            ['Business dates', $period['label'].' ('.$period['from'].' to '.$period['to'].', Asia/Manila)'],
            ['Compared with', $analytics['comparison']['available'] ? $period['comparison']['label'].' ('.$period['comparison']['description'].')' : 'Not compared (single Store Session)'],
            ['Store Session', $report['session_filter']['selected'] === null ? 'All sessions' : $this->sessionLabel($report)],
            ['Active filters', $filterLabels === [] ? 'None' : implode(' | ', $filterLabels)],
            [],
            ['Metric', 'This period', 'Previous period'],
            ['Total sales', $kpis['sales']['value'], $kpis['sales']['previous']],
            ['Transactions', $kpis['transactions']['value'], $kpis['transactions']['previous']],
            ['Average order value', $kpis['average_order']['value'], $kpis['average_order']['previous']],
            ['Items sold', $kpis['items']['value'], $kpis['items']['previous']],
            ['Cashless share %', $share($kpis['cashless_share']['value']), $share($kpis['cashless_share']['previous'])],
            [],
            ['Collections', 'Amount'],
            ['Cash (net)', $analytics['collections']['cash']],
            ['Cashless (net)', $analytics['collections']['cashless']],
            ['Total collected', $analytics['collections']['total']],
            ['Split payments (already in Cash and Cashless)', $analytics['collections']['split']['total']],
            ['Corrections pending allocation', $analytics['collections']['unallocated']],
            [],
            ['Payment method (each paid order counted once)', 'Transactions', 'Sales', '% of paid transactions', '% of Cash + Cashless transactions'],
        ];
        foreach ($analytics['payment_mix']['methods'] as $method) {
            $rows[] = [$method['label'], $method['transactions'], $method['sales'], $share($method['share_with_split']), $share($method['share'])];
        }
        $rows[] = ['Unpaid (Pay Later, not in the mix)', $analytics['payment_mix']['unpaid']['transactions'], $analytics['payment_mix']['unpaid']['sales']];
        $rows[] = [];
        $rows[] = ['Sales trend', 'Sales', 'Transactions', 'Previous sales', 'Previous transactions'];
        foreach ($analytics['trend']['buckets'] as $bucket) {
            $rows[] = [$bucket['full'], $bucket['sales'], $bucket['transactions'], $bucket['previous']['sales'] ?? null, $bucket['previous']['transactions'] ?? null];
        }
        $rows[] = [];
        $rows[] = ['Order type', 'Sales', 'Transactions', 'Items', 'Average order'];
        foreach ($analytics['order_types'] as $type) {
            $rows[] = [$type['label'], $type['sales'], $type['transactions'], $type['items'], $type['average']];
        }
        $rows[] = [];
        $rows[] = ['Category (current product category)', 'Sales', 'Items sold', '% of sales'];
        foreach ($analytics['categories'] as $category) {
            $rows[] = [$category['name'], $category['sales'], $category['items'], $share($category['share'])];
        }
        $rows[] = [];
        $rows[] = [$analytics['filters']['categories'] === [] ? 'Product' : 'Product (selected categories only)', 'Category', 'Qty sold', 'Orders', 'Total sales', '% of sales', 'Average price'];
        foreach ($analytics['products'] as $product) {
            $rows[] = [$product['name'], $product['category'], $product['quantity'], $product['orders'], $product['sales'], $share($product['share']), $product['average_price']];
        }
        $rows[] = [];
        $rows[] = ['Hour (Asia/Manila)', 'Sales', 'Transactions'];
        foreach ($analytics['hours'] as $hour) {
            $rows[] = [$hour['full'], $hour['sales'], $hour['transactions']];
        }
        $rows[] = [];
        $rows[] = ['Cashier', 'Transactions', 'Sales', 'Average order', 'Cash orders', 'Cashless orders', 'Split orders', 'Unpaid'];
        foreach ($analytics['cashiers'] as $cashier) {
            $rows[] = [$cashier['name'], $cashier['transactions'], $cashier['sales'], $cashier['average'], $cashier['cash'], $cashier['cashless'], $cashier['split'], $cashier['unpaid']];
        }
        $rows[] = [];
        $rows[] = ['Kitchen', 'Value'];
        $rows[] = ['Orders completed', $analytics['kitchen']['completed']];
        $rows[] = ['Average prep time (seconds, committed to ready)', $analytics['kitchen']['average_prep_seconds']];
        if (is_array($analytics['branches'])) {
            $rows[] = [];
            $rows[] = ['Branch', 'Store Sessions', 'Orders', 'Sales', 'Cash (net)', 'Cashless (net)', 'Expenses'];
            foreach ($analytics['branches'] as $branch) {
                $rows[] = [$branch['branch']['code'].' · '.$branch['branch']['name'], $branch['sessions'], $branch['orders'], $branch['sales'], $branch['cash'], $branch['cashless'], $branch['expenses']];
            }
        }
        $rows[] = [];
        $rows[] = ['Store Session (all orders)', 'Branch', 'Status', 'Opened by', 'Closed by', 'Orders', 'Net sales', 'Cash', 'Cashless', 'Expenses', 'Expected Cash', 'Expected Cashless', 'Actual Cash', 'Actual Cashless', 'Cash variance', 'Cashless variance'];
        foreach ($report['sessions'] as $session) {
            $reconciliation = $session['reconciliation'];
            $rows[] = [
                $session['business_date_label'].' · '.$session['time_range'], $session['branch']['code'], $session['result'],
                $session['opened_by'], $session['closed_by'], $session['orders'], $session['net_sales'],
                $session['collections']['cash'], $session['collections']['cashless'], $session['expenses']['total'],
                $reconciliation['expected']['cash'], $reconciliation['expected']['cashless'],
                $reconciliation['actual']['cash'], $reconciliation['actual']['cashless'],
                $reconciliation['variance']['cash'], $reconciliation['variance']['cashless'],
            ];
        }

        return $rows;
    }

    /**
     * RFC 4180 CSV with spreadsheet formula injection neutralised.
     *
     * @param  list<array<int, mixed>>  $rows
     */
    public function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }
        fwrite($stream, "\u{FEFF}");
        foreach ($rows as $row) {
            fputcsv($stream, array_map(function (mixed $value): string {
                $text = (string) ($value ?? '');

                return preg_match('/\A[=+@\t\r]/', $text) === 1 || (str_starts_with($text, '-') && ! is_numeric($text)) ? "'".$text : $text;
            }, $row), escape: '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @param array<string, mixed> $report */
    private function sessionLabel(array $report): string
    {
        foreach ($report['session_filter']['options'] as $option) {
            if ($option['id'] === $report['session_filter']['selected']) {
                return $option['label'];
            }
        }

        return 'Selected Store Session';
    }
}
