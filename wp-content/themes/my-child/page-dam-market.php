<?php
/**
 * Template Name: About – Price & Volume
 * Description: Renders Price (line) + Volume (bar) with mode toggle, table, CSV export.
 *
 * Path: wp-content/themes/my-child/page-dam-market.php
 */
get_header(); ?>

    <main id="primary" class="site-main">
        <section class="ps-card">
            <h1 class="ps-title">DAM Market — Price & Volume</h1>

            <!-- Controls -->
            <div class="ps-controls">
                <div class="field">
                    <label for="date">Date</label>
                    <input type="date" id="date" />
                </div>

                <div class="field">
                    <label for="mode">Show</label>
                    <select id="mode">
                        <option value="both">Price + Volume</option>
                        <option value="price">Price only</option>
                        <option value="volume">Volume only</option>
                    </select>
                </div>

                <button id="loadBtn" type="button">Load</button>
            </div>

            <!-- Meta / status + hi/lo -->
            <div class="ps-meta">
                <span id="status">Pick a date and click Load.</span>
                <span class="ps-hi-lo">
        <strong>High:</strong> <span id="hiVal">—</span> <small>at</small> <span id="hiTime">—</span>
        <span class="sep">|</span>
        <strong>Low:</strong> <span id="loVal">—</span> <small>at</small> <span id="loTime">—</span>
      </span>
            </div>

            <!-- Chart -->
            <div class="ps-chart">
                <div id="psEmptyHost"></div>
                <canvas id="psChart"></canvas>
            </div>

            <!-- Export -->
            <div class="ps-table-header">
                <button id="exportBtn" type="button" disabled>Export CSV</button>
            </div>

            <!-- Table -->
            <div class="ps-table">
                <div class="ps-table-scroller">
                    <table id="psTable" class="ps-grid">
                        <thead>
                        <tr id="psHeadRow">
                            <th>#</th>
                            <th>Time</th>
                            <th>Volume (MWh)</th>
                            <th>Price (€/MWh)</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

        </section>
    </main>

<?php get_footer();
