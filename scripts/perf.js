// Enhanced integration for your existing performance_results.html
// Add this to your existing loadPerformanceData function

function loadPerformanceData(accountNum) {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;

    if (!accountNum) {
        showError('Please select an account first');
        return;
    }

    // Clear previous data
    document.querySelector('.tbl-container').style.display = 'none';
    document.getElementById('chart-container').style.display = 'none';
    document.getElementById('error').style.display = 'none';
    document.getElementById('loading').style.display = 'block';

    // Hide attribution container if it exists
    const attributionContainer = document.getElementById('attributionContainer');
    if (attributionContainer) {
        attributionContainer.style.display = 'none';
    }

    // Scroll to loading indicator
    document.getElementById('loading').scrollIntoView({ behavior: 'smooth' });

    // Build URL with date parameters
    let url = `performance_query.php?acct_num=${accountNum}`;
    if (startDate) {
        url += `&start_date=${startDate}`;
    }
    if (endDate) {
        url += `&end_date=${endDate}`;
    }

    // Fetch performance data and attribution analysis in parallel
    Promise.all([
        fetch(url).then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        }),
        // Load attribution analysis
        loadAttributionAnalysis(accountNum, startDate, endDate)
    ])
    .then(([performanceData, attributionData]) => {
        document.getElementById('loading').style.display = 'none';

        // Store current account for use in date cell clicks
        window.currentAccount = accountNum;

        if (performanceData && performanceData.length > 0) {
            // Create and show chart
            createChart(performanceData);
            document.getElementById('chart-container').style.display = 'block';

            // Clear previous header and show table
            document.getElementById('dynamicHeader').innerHTML = '';
            createHeaderColumns(performanceData);
            const container = document.querySelector('.tbl-container');
            container.style.display = 'block';

            // Clear any existing virtual scroll instance
            if (container.__virtualScroll) {
                container.__virtualScroll = null;
            }

            new VirtualScroll(container, performanceData);

            // Show attribution analysis if data is available
            if (attributionData) {
                displayAttributionAnalysis(attributionData, accountNum, startDate, endDate);
            }
        } else {
            showError('No data found for account ' + accountNum);
        }
    })
    .catch(error => {
        document.getElementById('loading').style.display = 'none';
        console.error('Error fetching data:', error);
        showError('Error loading data: ' + error.message);
    });
}

// Attribution analysis loader
async function loadAttributionAnalysis(accountNum, startDate, endDate, benchmark = 'SPY') {
    try {
        const params = new URLSearchParams({
            acct_num: accountNum,
            type: 'summary',
            benchmark: benchmark
        });

        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);

        const response = await fetch(`attribution_analysis.php?${params}`);

        if (!response.ok) {
            console.warn('Attribution analysis not available:', response.status);
            return null;
        }

        return await response.json();
    } catch (error) {
        console.warn('Attribution analysis failed:', error);
        return null;
    }
}

// Display attribution analysis results
function displayAttributionAnalysis(attributionData, accountNum, startDate, endDate) {
    // Create or get attribution container
    let container = document.getElementById('attributionContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'attributionContainer';
        container.className = 'attribution-analysis-container';

        // Insert after metrics container
        const metricsContainer = document.getElementById('metrics-container');
        if (metricsContainer) {
            metricsContainer.parentNode.insertBefore(container, metricsContainer.nextSibling);
        } else {
            // Insert before data table
            const dataTable = document.querySelector('.tbl-container');
            dataTable.parentNode.insertBefore(container, dataTable);
        }
    }

    const summary = attributionData.summary;
    const sectorData = attributionData.sector_attribution;

    container.innerHTML = `
        <div class="attribution-header">
            <h2>Portfolio Attribution Analysis</h2>
            <div class="attribution-period">
                Account: ${accountNum} | ${startDate || 'Inception'} to ${endDate || 'Latest'}
                <span class="benchmark-label">vs SPY</span>
            </div>
        </div>

        <div class="attribution-summary-grid">
            <div class="summary-card allocation-card">
                <div class="card-header">
                    <h4>Asset Allocation Effect</h4>
                    <span class="info-tooltip" title="Performance impact from sector weightings vs benchmark">ⓘ</span>
                </div>
                <div class="card-value ${summary.total_allocation_effect >= 0 ? 'positive' : 'negative'}">
                    ${summary.total_allocation_effect > 0 ? '+' : ''}${summary.total_allocation_effect} bps
                </div>
                <div class="card-subtitle">
                    Hit Rate: ${summary.allocation_hit_rate}%
                </div>
            </div>

            <div class="summary-card selection-card">
                <div class="card-header">
                    <h4>Security Selection Effect</h4>
                    <span class="info-tooltip" title="Performance impact from individual security picks">ⓘ</span>
                </div>
                <div class="card-value ${summary.total_selection_effect >= 0 ? 'positive' : 'negative'}">
                    ${summary.total_selection_effect > 0 ? '+' : ''}${summary.total_selection_effect} bps
                </div>
                <div class="card-subtitle">
                    Hit Rate: ${summary.selection_hit_rate}%
                </div>
            </div>

            <div class="summary-card total-card">
                <div class="card-header">
                    <h4>Total Active Return</h4>
                    <span class="info-tooltip" title="Total excess return vs benchmark">ⓘ</span>
                </div>
                <div class="card-value ${summary.total_active_return >= 0 ? 'positive' : 'negative'}">
                    ${summary.total_active_return > 0 ? '+' : ''}${summary.total_active_return} bps
                </div>
                <div class="card-subtitle">
                    ${summary.primary_driver} Driven Strategy
                </div>
            </div>

            <div class="summary-card insight-card">
                <div class="card-header">
                    <h4>Key Insight</h4>
                </div>
                <div class="card-insight">
                    ${generateAttributionInsight(summary, sectorData)}
                </div>
            </div>
        </div>

        <div class="attribution-details">
            <div class="detail-tabs">
                <button class="tab-btn active" onclick="showAttributionTab('sector')">Sector Breakdown</button>
                <button class="tab-btn" onclick="showAttributionTab('chart')">Visualization</button>
            </div>

            <div id="sectorTab" class="tab-content active">
                ${renderSectorAttributionTable(sectorData)}
            </div>

            <div id="chartTab" class="tab-content" style="display: none;">
                <div class="chart-container">
                    <canvas id="attributionChart" width="800" height="400"></canvas>
                </div>
            </div>
        </div>
    `;

    container.style.display = 'block';

    // Create the attribution chart (initially hidden)
    setTimeout(() => createAttributionChart(sectorData), 100);
}

// Generate insight text based on attribution results
function generateAttributionInsight(summary, sectorData) {
    const totalActive = summary.total_active_return;
    const primaryDriver = summary.primary_driver;
    const topSector = sectorData.reduce((max, sector) => 
        Math.abs(sector.total_effect) > Math.abs(max.total_effect) ? sector : max
    );

    if (totalActive > 50) {
        return `Strong outperformance of +${totalActive} bps, primarily driven by ${primaryDriver.toLowerCase()}. ${topSector.sector} sector contributed ${topSector.total_effect > 0 ? '+' : ''}${topSector.total_effect} bps.`;
    } else if (totalActive > 0) {
        return `Modest outperformance of +${totalActive} bps through ${primaryDriver.toLowerCase()}. Performance was balanced across sectors.`;
    } else if (totalActive > -50) {
        return `Slight underperformance of ${totalActive} bps. ${topSector.sector} sector was the main detractor at ${topSector.total_effect} bps.`;
    } else {
        return `Significant underperformance of ${totalActive} bps. ${primaryDriver} decisions negatively impacted returns, particularly in ${topSector.sector}.`;
    }
}

// Render sector attribution table
function renderSectorAttributionTable(sectorData) {
    const tableRows = sectorData.map(sector => `
        <tr>
            <td class="sector-name">${sector.sector}</td>
            <td class="number-cell">${sector.portfolio_weight}%</td>
            <td class="number-cell">${sector.benchmark_weight}%</td>
            <td class="number-cell">${sector.portfolio_return}%</td>
            <td class="number-cell">${sector.benchmark_return}%</td>
            <td class="number-cell ${sector.allocation_effect >= 0 ? 'positive' : 'negative'}">
                ${sector.allocation_effect > 0 ? '+' : ''}${sector.allocation_effect}
            </td>
            <td class="number-cell ${sector.selection_effect >= 0 ? 'positive' : 'negative'}">
                ${sector.selection_effect > 0 ? '+' : ''}${sector.selection_effect}
            </td>
            <td class="number-cell ${sector.total_effect >= 0 ? 'positive' : 'negative'}">
                <strong>${sector.total_effect > 0 ? '+' : ''}${sector.total_effect}</strong>
            </td>
        </tr>
    `).join('');

    return `
        <table class="attribution-table">
            <thead>
                <tr>
                    <th>Sector</th>
                    <th>Portfolio Weight</th>
                    <th>Benchmark Weight</th>
                    <th>Portfolio Return</th>
                    <th>Benchmark Return</th>
                    <th>Allocation Effect (bps)</th>
                    <th>Selection Effect (bps)</th>
                    <th>Total Effect (bps)</th>
                </tr>
            </thead>
            <tbody>
                ${tableRows}
            </tbody>
        </table>
    `;
}

// Create attribution waterfall chart
function createAttributionChart(sectorData) {
    const canvas = document.getElementById('attributionChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    // Prepare data for stacked bar chart
    const labels = sectorData.map(s => s.sector);
    const allocationData = sectorData.map(s => s.allocation_effect);
    const selectionData = sectorData.map(s => s.selection_effect);
    const interactionData = sectorData.map(s => s.interaction_effect);

    if (window.attributionChart) {
        window.attributionChart.destroy();
    }

    window.attributionChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Allocation Effect',
                    data: allocationData,
                    backgroundColor: '#3b82f6',
                    borderColor: '#2563eb',
                    borderWidth: 1
                },
                {
                    label: 'Selection Effect', 
                    data: selectionData,
                    backgroundColor: '#10b981',
                    borderColor: '#059669',
                    borderWidth: 1
                },
                {
                    label: 'Interaction Effect',
                    data: interactionData,
                    backgroundColor: '#f59e0b',
                    borderColor: '#d97706',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45
                    }
                },
                y: {
                    stacked: true,
                    title: {
                        display: true,
                        text: 'Attribution (basis points)'
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        afterBody: function(context) {
                            const dataIndex = context[0].dataIndex;
                            const sector = sectorData[dataIndex];
                            return [
                                `Portfolio Weight: ${sector.portfolio_weight}%`,
                                `Benchmark Weight: ${sector.benchmark_weight}%`,
                                `Weight Difference: ${(sector.portfolio_weight - sector.benchmark_weight).toFixed(1)}%`,
                                `Return Difference: ${(sector.portfolio_return - sector.benchmark_return).toFixed(2)}%`
                            ];
                        }
                    }
                }
            }
        }
    });
}

// Tab switching functionality
function showAttributionTab(tabName) {
    // Remove active class from all tabs and content
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.style.display = 'none');

    // Add active class to clicked tab
    event.target.classList.add('active');

    // Show corresponding content
    const contentId = tabName + 'Tab';
    const content = document.getElementById(contentId);
    if (content) {
        content.style.display = 'block';
    }

    // If showing chart tab, ensure chart is properly sized
    if (tabName === 'chart' && window.attributionChart) {
        setTimeout(() => window.attributionChart.resize(), 100);
    }
}

// Add attribution-specific CSS styles
const attributionCSS = `
    .attribution-analysis-container {
        margin: 24px 0;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        padding: 24px;
        border: 1px solid #e5e7eb;
    }

    .attribution-header {
        text-align: center;
        margin-bottom: 24px;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 16px;
    }

    .attribution-header h2 {
        color: #1f2937;
        margin: 0 0 8px 0;
        font-size: 1.5rem;
        font-weight: 700;
    }

    .attribution-period {
        color: #6b7280;
        font-size: 14px;
    }

    .benchmark-label {
        background: #f3f4f6;
        padding: 4px 8px;
        border-radius: 4px;
        margin-left: 8px;
        font-weight: 500;
    }

    .attribution-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        margin-bottom: 32px;
    }

    .summary-card {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0,0,0,0.1);
    }

    .card-header {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }

    .card-header h4 {
        margin: 0;
        color: #374151;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-tooltip {
        cursor: help;
        color: #9ca3af;
        font-size: 16px;
    }

    .card-value {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
        font-family: 'Monaco', 'Menlo', monospace;
    }

    .card-value.positive {
        color: #10b981;
    }

    .card-value.negative {
        color: #ef4444;
    }

    .card-subtitle {
        color: #6b7280;
        font-size: 12px;
        font-weight: 500;
    }

    .insight-card .card-insight {
        font-size: 14px;
        line-height: 1.5;
        color: #4b5563;
        text-align: left;
        background: #f8fafc;
        padding: 12px;
        border-radius: 6px;
        border-left: 4px solid #3b82f6;
    }

    .attribution-details {
        background: #f9fafb;
        border-radius: 12px;
        padding: 20px;
        border: 1px solid #e5e7eb;
    }

    .detail-tabs {
        display: flex;
        margin-bottom: 20px;
        border-bottom: 1px solid #e5e7eb;
    }

    .tab-btn {
        background: none;
        border: none;
        padding: 12px 24px;
        cursor: pointer;
        font-weight: 600;
        color: #6b7280;
        border-bottom: 3px solid transparent;
        transition: all 0.2s;
    }

    .tab-btn:hover {
        color: #3b82f6;
    }

    .tab-btn.active {
        color: #3b82f6;
        border-bottom-color: #3b82f6;
    }

    .tab-content {
        background: white;
        border-radius: 8px;
        padding: 16px;
    }

    .attribution-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        background: white;
    }

    .attribution-table th,
    .attribution-table td {
        padding: 12px 8px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }

    .attribution-table th {
        background: #f8fafc;
        font-weight: 600;
        color: #374151;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .attribution-table tbody tr:hover {
        background: #f8fafc;
    }

    .sector-name {
        font-weight: 600;
        color: #1f2937;
    }

    .number-cell {
        text-align: right;
        font-family: 'Monaco', 'Menlo', monospace;
        font-size: 13px;
    }

    .positive {
        color: #059669;
        font-weight: 600;
    }

    .negative {
        color: #dc2626;
        font-weight: 600;
    }

    .chart-container {
        height: 400px;
        position: relative;
        background: white;
        border-radius: 8px;
        padding: 16px;
    }

    @media (max-width: 768px) {
        .attribution-summary-grid {
            grid-template-columns: 1fr;
        }

        .attribution-analysis-container {
            padding: 16px;
            margin: 10px;
        }

        .detail-tabs {
            flex-direction: column;
        }

        .tab-btn {
            text-align: left;
            border-bottom: none;
            border-left: 3px solid transparent;
        }

        .tab-btn.active {
            border-bottom: none;
            border-left-color: #3b82f6;
            background: #f8fafc;
        }
    }
`;

// Add the CSS to the page if it doesn't exist
if (!document.getElementById('attribution-styles')) {
    const style = document.createElement('style');
    style.id = 'attribution-styles';
    style.textContent = attributionCSS;
    document.head.appendChild(style);
}

// Export functions for use in other parts of the application
window.AttributionAnalysis = {
    loadAttributionAnalysis,
    displayAttributionAnalysis,
    showAttributionTab,
    createAttributionChart
};