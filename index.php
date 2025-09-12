<html>
  <head>
    <title>PHP Test</title>
    <style>
        #virtual-table-container {
            height: 600px;
            overflow-y: scroll;
            border: 1px solid #ccc;
            font-family: Arial, sans-serif;
        }

        #virtual-table-content {
            position: relative; /* This is the key change! */
        }

        .virtual-table-row {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 8px;
            position: absolute; /* Keep this to position rows vertically */
            width: 100%; /* Ensure rows fill the width */
        }

        .virtual-table-header {
            font-weight: bold;
            background-color: #f4f4f4;
            position: sticky; /* Stick the header to the top */
            top: 0;
            z-index: 10; /* Keep the header on top of the scrolling rows */
        }

        .virtual-table-row > div {
            flex: 1;
            padding: 0 10px;
        }
    </style>
  </head>
  <body>
    <?php echo '<p>Hello World</p>'; 

    $conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
    $con = pg_connect($conn_string);

    $acct_num = 592;

/*    $sql = "WITH tr AS ( 
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
            SUM(CASE WHEN transaction_type LIKE '%Div%' THEN amount ELSE 0 END) OVER (PARTITION BY pr.date, pr.ticker ORDER BY pr.date) AS cf
        FROM pr
        LEFT JOIN tr
        ON pr.date = tr.transaction_date
        AND pr.ticker = tr.ticker
        ORDER BY ticker, date
    )
    SELECT *,
        (cf / totalshares) AS dps,
        (close +(cf / totalshares)) / LAG(close) OVER (PARTITION BY ticker ORDER BY date) AS pctchange
    FROM mktvalues
    WHERE marketvalue IS NOT NULL
    AND ticker IN ('BMY', 'JPM', 'RSP')
    ORDER BY ticker, date";
      */

     // Get latest balance of each acct_num
      $sql = "SELECT
      acct_num,
      transactions_temp.ticker,
      SUM(shares) AS quantity,
      close,
      ROUND(SUM(shares) * close, 2)::money AS market_value
      FROM transactions_temp
      LEFT JOIN prices_stocks
      ON transactions_temp.ticker = prices_stocks.ticker
      AND prices_stocks.date = (SELECT MAX(date) FROM prices_stocks)
      WHERE acct_num = ".$acct_num."
      AND prices_stocks.ticker IN (SELECT DISTINCT ticker FROM transactions_temp WHERE acct_num = ".$acct_num.")
      GROUP BY acct_num, transactions_temp.ticker, close
      HAVING SUM(shares) <> 0
      ORDER BY market_value DESC";
      

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

    <div id="virtual-table-container">
        <div id="virtual-table-content"></div>
    </div>

    <script>
        const data = <?php echo json_encode($df); ?>;
        console.log(data);

        // --- Virtual Scrolling Logic ---

        const tableContainer = document.getElementById('virtual-table-container');
        const tableContent = document.getElementById('virtual-table-content');
        const rowHeight = 30; // The height of each row in pixels (adjust as needed)
        const visibleRows = Math.ceil(tableContainer.clientHeight / rowHeight);
        let start = 0;

        // Set the total height of the content div to create the scrollbar
        tableContent.style.height = `${data.length * rowHeight}px`;

        // Function to render the rows
        function renderRows() {
            const end = start + visibleRows * 2; // Render a few extra rows for smooth scrolling
            const fragment = document.createDocumentFragment();

            // Clear existing rows
            tableContent.innerHTML = '';

            // Add header row if data exists
            if (data.length > 0) {
                const headerRow = document.createElement('div');
                headerRow.className = 'virtual-table-row virtual-table-header';

                // Assuming your data rows have consistent keys, we can use the first one for headers
                Object.keys(data[0]).forEach(key => {
                    const headerCell = document.createElement('div');
                    headerCell.textContent = key;
                    headerRow.appendChild(headerCell);
                });
                fragment.appendChild(headerRow);
            }

            // Render the visible subset of the data
            for (let i = start; i < Math.min(end, data.length); i++) {
                const rowData = data[i];
                const rowDiv = document.createElement('div');
                rowDiv.className = 'virtual-table-row';
                rowDiv.style.position = 'absolute';
                rowDiv.style.top = `${i * rowHeight}px`;
                rowDiv.style.width = '100%';

                for (const key in rowData) {
                    const cell = document.createElement('div');
                    cell.textContent = rowData[key];
                    rowDiv.appendChild(cell);
                }
                fragment.appendChild(rowDiv);
            }

            tableContent.appendChild(fragment);
        }

        // Handle the scroll event
        tableContainer.addEventListener('scroll', () => {
            const newStart = Math.floor(tableContainer.scrollTop / rowHeight);
            if (newStart !== start) {
                start = newStart;
                renderRows();
            }
        });

        // Initial render
        renderRows();

    </script>
    

</html>