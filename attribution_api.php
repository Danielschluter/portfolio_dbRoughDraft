<?php
// attribution_analysis.php - Portfolio Attribution Analysis API

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database connection
$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get parameters
$acct_num = $_GET['acct_num'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$benchmark = $_GET['benchmark'] ?? 'SPY';
$attribution_type = $_GET['type'] ?? 'security'; // summary, timeseries, security

if (!$acct_num) {
    http_response_code(400);
    echo json_encode(['error' => 'Account number is required']);
    exit;
}

class AttributionAnalyzer {
    private $con;
    private $acct_num;
    private $start_date;
    private $end_date;
    private $benchmark;
    
    public function __construct($con, $acct_num, $start_date, $end_date, $benchmark) {
        $this->con = $con;
        $this->acct_num = $acct_num;
        $this->start_date = $start_date;
        $this->end_date = $end_date;
        $this->benchmark = $benchmark;
    }
    
    /**
     * Get sector-level attribution analysis
     */
    public function getSectorAttribution() {
        $sql = "
        WITH portfolio_holdings AS (
            -- Portfolio holdings by sector
            SELECT 
                t.transaction_date as date,
                ss.sector,
                SUM(t.shares * p.close) as sector_market_value,
                SUM(t.shares * p.close) / SUM(SUM(t.shares * p.close)) OVER (PARTITION BY t.transaction_date) as portfolio_weight,
                -- Weighted return calculation
                SUM(t.shares * (p.close / LAG(p.close) OVER (PARTITION BY t.ticker ORDER BY t.transaction_date) - 1)) / 
                NULLIF(SUM(t.shares), 0) as sector_return
            FROM transactions_temp t
            JOIN prices_stocks p ON t.ticker = p.ticker AND t.transaction_date = p.date
            LEFT JOIN (
                -- Mock sector mappings - in production, use actual sector table
                SELECT ticker, 
                       CASE 
                           WHEN ticker IN ('AAPL', 'MSFT', 'GOOGL', 'AMZN', 'META') THEN 'Technology'
                           WHEN ticker IN ('JNJ', 'PFE', 'UNH', 'ABBV') THEN 'Healthcare'
                           WHEN ticker IN ('JPM', 'BAC', 'WFC', 'GS') THEN 'Financials'
                           WHEN ticker IN ('TSLA', 'HD', 'NKE', 'MCD') THEN 'Consumer Discretionary'
                           WHEN ticker IN ('PG', 'KO', 'WMT', 'PEP') THEN 'Consumer Staples'
                           ELSE 'Other'
                       END as sector
                FROM (SELECT DISTINCT ticker FROM transactions_temp WHERE acct_num = $1) tickers
            ) ss ON t.ticker = ss.ticker
            WHERE t.acct_num = $1
            AND ($2 IS NULL OR t.transaction_date >= $2)
            AND ($3 IS NULL OR t.transaction_date <= $3)
            GROUP BY t.transaction_date, ss.sector
        ),
        
        benchmark_performance AS (
            -- Benchmark sector performance (simplified - using overall benchmark return)
            SELECT 
                date,
                'Technology' as sector, 0.30 as benchmark_weight,
                close / LAG(close) OVER (ORDER BY date) - 1 as sector_return
            FROM prices_stocks 
            WHERE ticker = '$4' 
            AND ($2 IS NULL OR date >= $2)
            AND ($3 IS NULL OR date <= $3)
            
            UNION ALL
            
            SELECT 
                date,
                'Healthcare' as sector, 0.15 as benchmark_weight,
                close / LAG(close) OVER (ORDER BY date) - 1 as sector_return
            FROM prices_stocks 
            WHERE ticker = '$4'
            AND ($2 IS NULL OR date >= $2)
            AND ($3 IS NULL OR date <= $3)
            
            -- Add other sectors...
        ),
        
        attribution_calc AS (
            SELECT 
                ph.date,
                ph.sector,
                COALESCE(ph.portfolio_weight, 0) as portfolio_weight,
                COALESCE(bp.benchmark_weight, 0) as benchmark_weight,
                COALESCE(ph.sector_return, 0) as portfolio_sector_return,
                COALESCE(bp.sector_return, 0) as benchmark_sector_return,
                
                -- Attribution effects
                (COALESCE(ph.portfolio_weight, 0) - COALESCE(bp.benchmark_weight, 0)) * 
                COALESCE(bp.sector_return, 0) as allocation_effect,
                
                COALESCE(bp.benchmark_weight, ph.portfolio_weight, 0) * 
                (COALESCE(ph.sector_return, 0) - COALESCE(bp.sector_return, 0)) as selection_effect,
                
                (COALESCE(ph.portfolio_weight, 0) - COALESCE(bp.benchmark_weight, 0)) * 
                (COALESCE(ph.sector_return, 0) - COALESCE(bp.sector_return, 0)) as interaction_effect
                
            FROM portfolio_holdings ph
            FULL OUTER JOIN benchmark_performance bp ON ph.date = bp.date AND ph.sector = bp.sector
        )
        
        SELECT 
            sector,
            AVG(portfolio_weight) * 100 as avg_portfolio_weight_pct,
            AVG(benchmark_weight) * 100 as avg_benchmark_weight_pct,
            AVG(portfolio_sector_return) * 100 as avg_sector_return_pct,
            AVG(benchmark_sector_return) * 100 as avg_benchmark_sector_return_pct,
            SUM(allocation_effect) * 10000 as allocation_contribution_bps,
            SUM(selection_effect) * 10000 as selection_contribution_bps,
            SUM(interaction_effect) * 10000 as interaction_contribution_bps,
            (SUM(allocation_effect) + SUM(selection_effect) + SUM(interaction_effect)) * 10000 as total_contribution_bps
        FROM attribution_calc
        WHERE sector IS NOT NULL
        GROUP BY sector
        ORDER BY total_contribution_bps DESC";
        
        $result = pg_query_params($this->con, $sql, [
            $this->acct_num, 
            $this->start_date, 
            $this->end_date, 
            $this->benchmark
        ]);
        
        if (!$result) {
            throw new Exception('Sector attribution query failed: ' . pg_last_error($this->con));
        }
        
        $attribution = [];
        while ($row = pg_fetch_assoc($result)) {
            $attribution[] = [
                'sector' => $row['sector'],
                'portfolio_weight' => round(floatval($row['avg_portfolio_weight_pct']), 2),
                'benchmark_weight' => round(floatval($row['avg_benchmark_weight_pct']), 2),
                'portfolio_return' => round(floatval($row['avg_sector_return_pct']), 2),
                'benchmark_return' => round(floatval($row['avg_benchmark_sector_return_pct']), 2),
                'allocation_effect' => round(floatval($row['allocation_contribution_bps']), 1),
                'selection_effect' => round(floatval($row['selection_contribution_bps']), 1),
                'interaction_effect' => round(floatval($row['interaction_contribution_bps']), 1),
                'total_effect' => round(floatval($row['total_contribution_bps']), 1)
            ];
        }
        
        return $attribution;
    }
    
    /**
     * Get time series attribution data
     */
    public function getTimeSeriesAttribution() {
        // Simplified version - in production would use the complex CTE from above
        $sql = "
        WITH daily_returns AS (
            SELECT 
                t.transaction_date as date,
                SUM(t.shares * (p.close / LAG(p.close) OVER (PARTITION BY t.ticker ORDER BY t.transaction_date) - 1)) as portfolio_return,
                (SELECT close / LAG(close) OVER (ORDER BY date) - 1 
                 FROM prices_stocks ps 
                 WHERE ps.ticker = $4 AND ps.date = t.transaction_date) as benchmark_return
            FROM transactions_temp t
            JOIN prices_stocks p ON t.ticker = p.ticker AND t.transaction_date = p.date
            WHERE t.acct_num = $1
            AND ($2 IS NULL OR t.transaction_date >= $2)
            AND ($3 IS NULL OR t.transaction_date <= $3)
            GROUP BY t.transaction_date
        )
        SELECT 
            date,
            COALESCE(portfolio_return, 0) * 10000 as portfolio_return_bps,
            COALESCE(benchmark_return, 0) * 10000 as benchmark_return_bps,
            (COALESCE(portfolio_return, 0) - COALESCE(benchmark_return, 0)) * 10000 as active_return_bps,
            -- Simplified attribution - would be more complex in reality
            (COALESCE(portfolio_return, 0) - COALESCE(benchmark_return, 0)) * 0.6 * 10000 as selection_effect_bps,
            (COALESCE(portfolio_return, 0) - COALESCE(benchmark_return, 0)) * 0.4 * 10000 as allocation_effect_bps
        FROM daily_returns
        WHERE portfolio_return IS NOT NULL AND benchmark_return IS NOT NULL
        ORDER BY date";
        
        $result = pg_query_params($this->con, $sql, [
            $this->acct_num, 
            $this->start_date, 
            $this->end_date, 
            $this->benchmark
        ]);
        
        if (!$result) {
            throw new Exception('Time series attribution query failed: ' . pg_last_error($this->con));
        }
        
        $timeseries = [];
        $cumulative_allocation = 0;
        $cumulative_selection = 0;
        
        while ($row = pg_fetch_assoc($result)) {
            $allocation_effect = floatval($row['allocation_effect_bps']);
            $selection_effect = floatval($row['selection_effect_bps']);
            
            $cumulative_allocation += $allocation_effect;
            $cumulative_selection += $selection_effect;
            
            $timeseries[] = [
                'date' => $row['date'],
                'portfolio_return' => round(floatval($row['portfolio_return_bps']), 1),
                'benchmark_return' => round(floatval($row['benchmark_return_bps']), 1),
                'active_return' => round(floatval($row['active_return_bps']), 1),
                'allocation_effect' => round($allocation_effect, 1),
                'selection_effect' => round($selection_effect, 1),
                'cumulative_allocation' => round($cumulative_allocation, 1),
                'cumulative_selection' => round($cumulative_selection, 1)
            ];
        }
        
        return $timeseries;
    }
    
    /**
     * Get security-level attribution
     */
    public function getSecurityAttribution() {
        $sql = "
        WITH security_performance AS (
            SELECT 
                t.ticker,
                t.transaction_date,
                t.shares,
                p.close as price,
                SUM(t.shares) OVER (PARTITION BY t.ticker ORDER BY t.transaction_date) as cumulative_shares,
                p.close / LAG(p.close) OVER (PARTITION BY t.ticker ORDER BY t.transaction_date) - 1 as daily_return,
                -- Calculate market value
                SUM(t.shares) OVER (PARTITION BY t.ticker ORDER BY t.transaction_date) * p.close as market_value
            FROM transactions_temp t
            JOIN prices_stocks p ON t.ticker = p.ticker AND t.transaction_date = p.date
            WHERE t.acct_num = $1
            AND ($2 IS NULL OR t.transaction_date >= $2)
            AND ($3 IS NULL OR t.transaction_date <= $3)
        ),
        
        portfolio_totals AS (
            SELECT 
                transaction_date,
                SUM(market_value) as total_portfolio_value
            FROM security_performance
            WHERE cumulative_shares > 0
            GROUP BY transaction_date
        ),
        
        benchmark_returns AS (
            SELECT 
                date,
                close / LAG(close) OVER (ORDER BY date) - 1 as benchmark_return
            FROM prices_stocks 
            WHERE ticker = '$4'
            AND ($2 IS NULL OR date >= $2)
            AND ($3 IS NULL OR date <= $3)
        ),
        
        security_attribution AS (
            SELECT 
                sp.ticker,
                -- Average portfolio weight over the period
                AVG(sp.market_value / pt.total_portfolio_value) * 100 as avg_portfolio_weight_pct,
                
                -- Security total return
                (MAX(sp.price) / MIN(sp.price) - 1) * 100 as total_return_pct,
                
                -- Average daily return
                AVG(sp.daily_return) * 100 as avg_daily_return_pct,
                
                -- Contribution to portfolio return (weight * return)
                AVG(sp.market_value / pt.total_portfolio_value) * 
                (MAX(sp.price) / MIN(sp.price) - 1) * 10000 as contribution_bps,
                
                -- Excess return vs benchmark
                (MAX(sp.price) / MIN(sp.price) - 1) - 
                COALESCE((SELECT (MAX(close) / MIN(close) - 1) FROM prices_stocks WHERE ticker = '$4' 
                         AND date >= MIN(sp.transaction_date) AND date <= MAX(sp.transaction_date)), 0) as excess_return,
                
                -- Final market value
                MAX(sp.market_value) as final_market_value,
                
                -- Number of days held
                COUNT(*) as days_held
                
            FROM security_performance sp
            JOIN portfolio_totals pt ON sp.transaction_date = pt.transaction_date
            WHERE sp.cumulative_shares > 0
            GROUP BY sp.ticker
        )
        
        SELECT 
            ticker,
            avg_portfolio_weight_pct as portfolio_weight,
            total_return_pct as total_return,
            avg_daily_return_pct as avg_daily_return,
            contribution_bps as contribution,
            excess_return * 100 as excess_return_pct,
            final_market_value,
            days_held,
            CASE 
                WHEN contribution_bps > 0 THEN 'Contributor'
                ELSE 'Detractor'
            END as attribution_type
        FROM security_attribution
        ORDER BY contribution_bps DESC";
        
        $result = pg_query_params($this->con, $sql, [
            $this->acct_num, 
            $this->start_date, 
            $this->end_date,
            $this->benchmark
        ]);
        
        if (!$result) {
            throw new Exception('Security attribution query failed: ' . pg_last_error($this->con));
        }
        
        $securities = [];
        while ($row = pg_fetch_assoc($result)) {
            $securities[] = [
                'ticker' => $row['ticker'],
                'portfolio_weight' => round(floatval($row['portfolio_weight']), 2),
                'total_return' => round(floatval($row['total_return']), 2),
                'avg_daily_return' => round(floatval($row['avg_daily_return']), 4),
                'contribution' => round(floatval($row['contribution']), 1),
                'excess_return' => round(floatval($row['excess_return_pct']), 2),
                'final_market_value' => round(floatval($row['final_market_value']), 2),
                'days_held' => intval($row['days_held']),
                'attribution_type' => $row['attribution_type']
            ];
        }
        
        return $securities;
    }
    
    /**
     * Get attribution summary statistics
     */
    public function getAttributionSummary() {
        $securityData = $this->getSecurityAttribution();
        
        $totalContribution = array_sum(array_column($securityData, 'contribution'));
        $positiveContributions = array_filter($securityData, fn($s) => $s['contribution'] > 0);
        $negativeContributions = array_filter($securityData, fn($s) => $s['contribution'] < 0);
        
        $totalPositive = array_sum(array_column($positiveContributions, 'contribution'));
        $totalNegative = array_sum(array_column($negativeContributions, 'contribution'));
        
        // Calculate hit rates
        $positiveCount = count($positiveContributions);
        $totalCount = count($securityData);
        
        // Top and bottom contributors
        $topContributor = !empty($securityData) ? $securityData[0] : null;
        $bottomContributor = !empty($securityData) ? end($securityData) : null;
        
        return [
            'total_contribution' => round($totalContribution, 1),
            'positive_contribution' => round($totalPositive, 1),
            'negative_contribution' => round($totalNegative, 1),
            'hit_rate' => $totalCount > 0 ? round($positiveCount / $totalCount * 100, 1) : 0,
            'total_securities' => $totalCount,
            'positive_securities' => $positiveCount,
            'negative_securities' => count($negativeContributions),
            'top_contributor' => $topContributor ? [
                'ticker' => $topContributor['ticker'],
                'contribution' => $topContributor['contribution']
            ] : null,
            'bottom_contributor' => $bottomContributor ? [
                'ticker' => $bottomContributor['ticker'],
                'contribution' => $bottomContributor['contribution']
            ] : null,
            'analysis_period' => [
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'benchmark' => $this->benchmark
            ]
        ];
    }
}

try {
    $analyzer = new AttributionAnalyzer($con, $acct_num, $start_date, $end_date, $benchmark);
    
    switch ($attribution_type) {
        case 'summary':
            $result = [
                'summary' => $analyzer->getAttributionSummary(),
                'security_attribution' => $analyzer->getSecurityAttribution()
            ];
            break;
            
        case 'timeseries':
            $result = $analyzer->getTimeSeriesAttribution();
            break;
            
        case 'security':
            $result = $analyzer->getSecurityAttribution();
            break;
            
        case 'sector':
            $result = [
                'summary' => $analyzer->getAttributionSummary(),
                'sector_attribution' => $analyzer->getSectorAttribution()
            ];
            break;
            
        default:
            $result = [
                'summary' => $analyzer->getAttributionSummary(),
                'security_attribution' => $analyzer->getSecurityAttribution()
            ];
    }
    
    echo json_encode($result, JSON_NUMERIC_CHECK);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if ($con) {
        pg_close($con);
    }
}
?>