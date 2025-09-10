<html>
  <head>
    <title>PHP Test</title>
  </head>
  <body>
    <?php echo '<p>Hello World</p>'; 

    $conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
    $con = pg_connect($conn_string);

    $acct_num = 592;

    $sql = "WITH tr AS ( 
        SELECT * FROM transactions_temp WHERE acct_num = ".$acct_num."
    ),
    pr AS (
        SELECT date, ticker, close 
        FROM prices_stocks
        WHERE ticker IN (SELECT DISTINCT ticker FROM transactions_temp WHERE acct_num = ".$acct_num.")
        AND date >= (SELECT MIN(transaction_date) FROM transactions_temp WHERE acct_num = ".$acct_num.")
    ),
    mktvalues AS (
        SELECT
            date,
            pr.ticker,
            shares,
            close,
            SUM(tr.shares) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS totalshares,
            pr.close * SUM(tr.shares) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS marketvalue,
            SUM(amount) OVER (PARTITION BY pr.date ORDER BY pr.date) AS cf
        FROM pr
        LEFT JOIN tr
        ON pr.date = tr.transaction_date
        AND pr.ticker = tr.ticker
        ORDER BY date, ticker
    )";

    $result = pg_query($con, $sql);
    if (!$result) {
        echo "An error occurred.\n";
      // show an error message
        echo "Error: " . pg_last_error($con);
    };

$df = [];

    while ($row = pg_fetch_assoc($result)) {
        $df[] = $row;
    };


    // Close the connection
    pg_close($con);
    
    
    ?> 

    <div id="virtual-table"></div>

    <script>

       const data = <?php echo json_encode($df); ?>;
       console.log(data);

      
      
    </script>
    

</html>