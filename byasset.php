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


$sql = "WITH holdings AS (
    -- First, calculate share balances and cash flows for each ticker.
    SELECT
        t.ticker,
        SUM(CASE WHEN t.transaction_date < '".$beg."' THEN t.shares ELSE 0 END) AS beg_shares,
        SUM(CASE WHEN t.transaction_date BETWEEN '".$beg."' AND '".$end."' THEN t.shares ELSE 0 END) AS shares_bought_sold,
        SUM(CASE WHEN t.transaction_date BETWEEN '".$beg."' AND '".$end."' THEN t.amount ELSE 0 END) AS cf,
        -- Note: I corrected this to '<=' to properly include the end date in the final share count.
        SUM(CASE WHEN t.transaction_date <= '".$end."' THEN t.shares ELSE 0 END) AS end_shares
    FROM
        transactions_temp AS t
    WHERE
        t.acct_num = 592
    GROUP BY
        t.ticker
),
final_data AS (
    -- Now, for each ticker with holdings, find its correct price.
    SELECT
        h.ticker,
        h.beg_shares,
        h.shares_bought_sold,
        h.end_shares,
        h.cf,
        -- *** THE FIX IS HERE ***
        -- For each ticker, find its most recent price BEFORE the period starts.
        (SELECT close FROM prices_stocks p WHERE p.ticker = h.ticker AND p.date < '".$beg."' ORDER BY p.date DESC LIMIT 1) AS beg_price,
        -- For each ticker, find its most recent price ON or BEFORE the period ends.
        (SELECT close FROM prices_stocks p WHERE p.ticker = h.ticker AND p.date <= '".$end."' ORDER BY p.date DESC LIMIT 1) AS end_price
    FROM
        holdings h
)
-- Final calculation and presentation.
SELECT
    ticker,
    beg_shares,
    shares_bought_sold,
    end_shares,
    cf,
    (beg_shares * beg_price) AS beg_value,
    (end_shares * end_price) AS end_value,
    ((end_shares * end_price) - (beg_shares * beg_price) + cf) AS gain_loss,
    beg_price,
    end_price
FROM
    final_data
WHERE
    -- Filter out tickers that had no change in value or holdings.
    COALESCE(((end_shares * end_price) - (beg_shares * beg_price) + cf), 0) <> 0
ORDER BY
    gain_loss DESC;
";

$result = pg_query($con, $sql);
if (!$result) {
    echo "An error occurred.\n";
    exit;
  }

  $all = pg_fetch_all($result);

  echo json_encode($all, JSON_NUMERIC_CHECK);



