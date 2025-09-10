<?php

$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

$q = $_GET['q'];
$beg = $_GET['beg'];
$end = $_GET['end'];

$sql = "-- =================================================================
-- Final Resilient Query for Contribution to Return
-- =================================================================
WITH
  date_range AS (
    SELECT
      '".$beg."' :: DATE AS period_start,
      '".$end."' :: DATE AS period_end
  ),
  valid_dates AS (
    SELECT
      period_start,
      period_end,
      (SELECT MAX(date) FROM prices_stocks WHERE date < period_start) AS start_price_date,
      (SELECT MAX(date) FROM prices_stocks WHERE date <= period_end) AS end_price_date
    FROM date_range
  ),
  holding_calculations AS (
    SELECT
      tr.ticker,
      SUM(CASE WHEN tr.transaction_date < (SELECT period_start FROM valid_dates) THEN tr.shares ELSE 0 END) AS beginning_shares,
      SUM(tr.shares) AS ending_shares,
      SUM(CASE WHEN tr.transaction_date >= (SELECT period_start FROM valid_dates) AND tr.transaction_date <= (SELECT period_end FROM valid_dates) THEN tr.amount ELSE 0 END) AS net_cash_flow
    FROM transactions_temp tr
    WHERE tr.acct_num = ".$q." AND tr.transaction_date <= (SELECT period_end FROM valid_dates)
    GROUP BY tr.ticker
  ),
  performance_metrics AS (
    SELECT
      hc.ticker,
      (hc.beginning_shares * p_start.close) AS beginning_market_value,
      (hc.ending_shares * p_end.close) AS ending_market_value,
      hc.net_cash_flow,
      p_start.close AS beg_price,
      p_end.close AS end_price
    FROM
      holding_calculations hc
      CROSS JOIN valid_dates vd
      LEFT JOIN prices_stocks p_start ON hc.ticker = p_start.ticker AND p_start.date = vd.start_price_date
      LEFT JOIN prices_stocks p_end ON hc.ticker = p_end.ticker AND p_end.date = vd.end_price_date
  ),
  -- New CTE to calculate total portfolio value separately to keep the final SELECT clean
  final_calcs AS (
    SELECT *,
      SUM(COALESCE(beginning_market_value, 0)) OVER () as total_portfolio_bmv
    FROM performance_metrics
  )
-- Final calculation and presentation with protection against division by zero
SELECT
  fc.ticker,
  COALESCE(fc.beginning_market_value, 0) AS beginning_market_value,
  COALESCE(fc.ending_market_value, 0) AS ending_market_value,
  COALESCE(fc.net_cash_flow, 0) AS net_cash_flow,
  (COALESCE(fc.ending_market_value, 0) - COALESCE(fc.beginning_market_value, 0) + COALESCE(fc.net_cash_flow, 0)) AS gain_loss,
  fc.total_portfolio_bmv,
  -- *** THE FIX IS HERE ***
  -- Use a CASE statement to prevent division by zero
  CASE
    WHEN fc.total_portfolio_bmv = 0 THEN 0 -- If starting value is 0, contribution is 0
    ELSE (
      (COALESCE(fc.ending_market_value, 0) - COALESCE(fc.beginning_market_value, 0) + COALESCE(fc.net_cash_flow, 0)) / fc.total_portfolio_bmv
    ) * 100
  END AS contribution_to_return_pct
  ,beg_price
  ,end_price
FROM
  final_calcs fc
WHERE
  COALESCE(fc.beginning_market_value, 0) <> 0

  AND ticker <> ''
ORDER BY
  gain_loss DESC;
";


$sql = "WITH cte AS (
SELECT
    t.ticker,
    SUM(CASE WHEN t.transaction_date < '".$beg."' THEN t.shares ELSE 0 END) AS beg_shares,
    SUM(CASE WHEN t.transaction_date BETWEEN '".$beg."' AND '".$end."' THEN t.shares ELSE 0 END) AS shares_bought_sold,
    SUM(CASE WHEN t.transaction_date BETWEEN '".$beg."' AND '".$end."' THEN t.amount ELSE 0 END) AS cf,
    SUM(CASE WHEN t.transaction_date < '".$end."' THEN t.shares ELSE 0 END) AS end_shares,
    SUM(CASE WHEN t.transaction_date < '".$beg."' THEN t.shares ELSE 0 END) * beg_prices.close AS beg_value,
    SUM(CASE WHEN t.transaction_date < '".$end."' THEN t.shares ELSE 0 END) * end_prices.close AS end_value,
    beg_prices.close AS beg_price,
    end_prices.close AS end_price
FROM
    transactions_temp AS t
JOIN
    prices_stocks AS beg_prices ON t.ticker = beg_prices.ticker AND beg_prices.date = '".$beg."'
JOIN
    prices_stocks AS end_prices ON t.ticker = end_prices.ticker AND end_prices.date = '".$end."'
WHERE
    t.acct_num = 592
GROUP BY
    t.ticker, beg_prices.close, end_prices.close
),
gain_loss AS (
SELECT
  ticker,
  beg_shares,
  shares_bought_sold,
  end_shares,
  beg_value,
  cf,
  end_value,
  (end_value - (beg_value - cf)) AS gain_loss,
  beg_price,
  end_price
FROM cte
)
SELECT * FROM gain_loss
WHERE gain_loss <> 0
ORDER BY (end_value - (beg_value - cf)) DESC";

$result = pg_query($con, $sql);
if (!$result) {
    echo "An error occurred.\n";
    exit;
  }

  $all = pg_fetch_all($result);

  echo json_encode($all, JSON_NUMERIC_CHECK);



