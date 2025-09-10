
<html>
  <head>
    <title>PHP Test</title>
    <style>
      #tbl-container {
        background: #fff;
        height: 400px;
        overflow: hidden;
        position: relative;
        border: 1px solid #ccc;
        font-family: Arial, sans-serif;
      }
      
      .virtual-scroll-wrapper {
        height: 360px; /* Container height minus header */
        overflow-y: auto;
        position: relative;
      }

      .virtual-scroll-spacer {
        width: 1px;
        pointer-events: none;
      }

      .virtual-scroll-content {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        will-change: transform;
      }
      
      .div-row {
        display: flex;
        height: 40px;
        align-items: center;
      }
      
      .div-row > div {
        flex: 1;
        padding: 10px;
        border: 1px solid #ccc;
        background: white;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 100px;
        box-sizing: border-box;
      }
      
      .div-row:hover {
        background-color: #f8f9fa;
      }
      
      .div-row > div:hover {
        background-color: #f8f9fa;
      }
      
      .header-row {
        position: sticky;
        top: 0;
        background: #f1f1f1;
        z-index: 10;
        display: flex;
        border-bottom: 2px solid #dee2e6;
        height: 40px;
        align-items: center;
      }
      
      .header-row > div {
        background-color: #f1f1f1;
        font-weight: bold;
        cursor: pointer;
        user-select: none;
        flex: 1;
        padding: 10px;
        border: 1px solid #ccc;
        min-width: 100px;
        box-sizing: border-box;
      }
      
      .header-row > div:hover {
        background-color: #e9ecef;
      }

      .loading {
        padding: 20px;
        text-align: center;
        font-size: 18px;
      }
    </style>
  </head>
  <body>
    <?php 
    echo '<p>Hello World</p>'; 

    $conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
    $con = pg_connect($conn_string);

    if (!$con) {
        echo "Connection failed: " . pg_last_error();
        exit;
    }

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
    )
    SELECT * FROM mktvalues";

    $result = pg_query($con, $sql);
    if (!$result) {
        echo "An error occurred.\n";
        echo "Error: " . pg_last_error($con);
        exit;
    }

    $df = [];
    while ($row = pg_fetch_assoc($result)) {
        $df[] = $row;
    }

    // Close the connection
    pg_close($con);
    ?> 

    <div id="tbl-container">
      <div class="loading">Loading data...</div>
    </div>

    <script>
    const data = <?php echo json_encode($df); ?>;
    console.log(data);

    // Initialize the table once data is loaded
    if (data && data.length > 0) {
      renderTable(data);
    } else {
      document.getElementById('tbl-container').innerHTML = '<div class="loading">No data available</div>';
    }

    /**
     * Render the data table with virtual scrolling
     */
    function renderTable(data) {
      const container = document.getElementById('tbl-container');
      
      if (!data || data.length === 0) {
        container.innerHTML = '<div class="loading">No data to display</div>';
        return;
      }
      
      const headers = Object.keys(data[0]);
      
      // Create virtual scroll structure
      container.innerHTML = `
        <div class="header-row">
          ${headers.map(header => `
            <div onclick="sortTable('${header}')">${header}</div>
          `).join('')}
        </div>
        <div class="virtual-scroll-wrapper">
          <div class="virtual-scroll-spacer"></div>
          <div class="virtual-scroll-content"></div>
        </div>
      `;
      
      // Initialize virtual scrolling
      new VirtualScroll(container, data, headers);
    }

    /**
     * Virtual Scroll Implementation
     */
    class VirtualScroll {
      constructor(container, data, headers) {
        this.container = container;
        this.data = data;
        this.headers = headers;
        this.rowHeight = 40; // Height of each row in pixels
        this.visibleRows = Math.ceil(360 / this.rowHeight); // Container height / row height
        this.startIndex = 0;
        this.endIndex = Math.min(this.visibleRows, this.data.length);
        
        this.scrollWrapper = container.querySelector('.virtual-scroll-wrapper');
        this.spacer = container.querySelector('.virtual-scroll-spacer');
        this.content = container.querySelector('.virtual-scroll-content');
        
        // Store reference for sorting
        window.virtualScrollInstance = this;
        
        this.init();
      }

      init() {
        // Set total height for proper scrollbar
        const totalHeight = this.data.length * this.rowHeight;
        this.spacer.style.height = `${totalHeight}px`;
        
        // Add scroll event listener
        this.scrollWrapper.addEventListener('scroll', this.handleScroll.bind(this));
        
        // Initial render
        this.renderVisibleRows();
      }

      handleScroll() {
        const scrollTop = this.scrollWrapper.scrollTop;
        const newStartIndex = Math.floor(scrollTop / this.rowHeight);
        const newEndIndex = Math.min(newStartIndex + this.visibleRows + 5, this.data.length); // +5 for buffer
        
        if (newStartIndex !== this.startIndex || newEndIndex !== this.endIndex) {
          this.startIndex = newStartIndex;
          this.endIndex = newEndIndex;
          this.renderVisibleRows();
        }
      }

      renderVisibleRows() {
        const visibleData = this.data.slice(this.startIndex, this.endIndex);
        const offsetY = this.startIndex * this.rowHeight;
        
        this.content.style.transform = `translateY(${offsetY}px)`;
        this.content.innerHTML = this.generateRowsHTML(visibleData);
      }

      generateRowsHTML(data) {
        return data.map(row => `
          <div class="div-row">
            ${this.headers.map(header => `
              <div>${row[header] || ''}</div>
            `).join('')}
          </div>
        `).join('');
      }

      // Method to update data (for sorting)
      updateData(newData) {
        this.data = newData;
        this.startIndex = 0;
        this.endIndex = Math.min(this.visibleRows, this.data.length);
        
        const totalHeight = this.data.length * this.rowHeight;
        this.spacer.style.height = `${totalHeight}px`;
        this.scrollWrapper.scrollTop = 0;
        
        this.renderVisibleRows();
      }
    }

    // Global variables for sorting
    let currentSort = { column: null, ascending: true };

    /**
     * Sort the table by column
     */
    function sortTable(columnKey) {
      if (!window.virtualScrollInstance) return;
      
      const data = window.virtualScrollInstance.data;
      
      // Toggle sort direction if same column, otherwise default to ascending
      if (currentSort.column === columnKey) {
        currentSort.ascending = !currentSort.ascending;
      } else {
        currentSort.column = columnKey;
        currentSort.ascending = true;
      }

      const sortedData = [...data].sort((a, b) => {
        const valueA = a[columnKey];
        const valueB = b[columnKey];

        // Handle numeric sorting
        if (!isNaN(valueA) && !isNaN(valueB)) {
          return currentSort.ascending ? valueA - valueB : valueB - valueA;
        }

        // Handle string sorting
        const stringA = String(valueA).toLowerCase();
        const stringB = String(valueB).toLowerCase();

        if (currentSort.ascending) {
          return stringA.localeCompare(stringB);
        } else {
          return stringB.localeCompare(stringA);
        }
      });

      // Update virtual scroll with sorted data
      window.virtualScrollInstance.updateData(sortedData);
    }
    </script>
  </body>
</html>
