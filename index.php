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
            COALESCE(shares, 0) as shares,
            close,
            SUM(COALESCE(tr.shares, 0)) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS totalshares,
            pr.close * SUM(COALESCE(tr.shares, 0)) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS marketvalue,
            COALESCE(SUM(amount) OVER (PARTITION BY pr.ticker ORDER BY pr.date ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW), 0) AS cf,
            LAG(pr.close) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS prev_close,
            COALESCE(amount, 0) as daily_cf
        FROM pr
        LEFT JOIN tr
        ON pr.date = tr.transaction_date
        AND pr.ticker = tr.ticker
        ORDER BY ticker, date
    ),
    returns AS (
        SELECT *,
            CASE 
                WHEN prev_close IS NOT NULL AND prev_close > 0 AND totalshares > 0 THEN
                    ((close - prev_close) / prev_close) * 100
                ELSE 0
            END AS daily_return_pct
        FROM mktvalues
    )
    SELECT 
        date,
        ticker,
        shares,
        close,
        totalshares,
        marketvalue,
        cf,
        ROUND(daily_return_pct, 4) as daily_return_pct
    FROM returns
    WHERE totalshares > 0 OR shares != 0
    ORDER BY ticker, date";

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

        // Set the total height of the content div to create the scrollbar (+1 for header)
        tableContent.style.height = `${(data.length + 1) * rowHeight}px`;

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
                headerRow.style.position = 'sticky';
                headerRow.style.top = '0px';
                headerRow.style.zIndex = '10';

                // Assuming your data rows have consistent keys, we can use the first one for headers
                Object.keys(data[0]).forEach(key => {
                    const headerCell = document.createElement('div');
                    headerCell.textContent = key.replace('_', ' ').toUpperCase();
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
                rowDiv.style.top = `${(i + 1) * rowHeight}px`; // +1 to account for header
                rowDiv.style.width = '100%';

                for (const key in rowData) {
                    const cell = document.createElement('div');
                    if (key === 'daily_return_pct') {
                        const returnValue = parseFloat(rowData[key]);
                        cell.textContent = returnValue.toFixed(2) + '%';
                        cell.style.color = returnValue >= 0 ? 'green' : 'red';
                    } else if (key === 'marketvalue' || key === 'cf') {
                        cell.textContent = parseFloat(rowData[key]).toLocaleString('en-US', {
                            style: 'currency',
                            currency: 'USD'
                        });
                    } else {
                        cell.textContent = rowData[key];
                    }
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