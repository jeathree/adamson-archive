<!--
Adamson Archive Admin UI
Modern, unified table for scanning, batching, processing, filtering, sorting, searching, deleting, and error display.
All styles in style.css. No inline styles.
-->
<div class="wrap adamson-admin-ui">
	<h1>Adamson Archive Media Manager</h1>

	<!-- Summary Bar -->
	<div id="adamson-archive-summary">
		<strong>Total Albums:</strong> <span id="summary-albums">0</span>
		<strong>Total Images:</strong> <span id="summary-images">0</span>
		<strong>Total Videos:</strong> <span id="summary-videos">0</span>
		<strong>Total Media:</strong> <span id="summary-media">0</span>
		<strong>Errors:</strong> <span id="summary-errors">0</span>
	</div>

	<!-- Controls Bar -->
	<button type="button" class="button button-danger" id="delete-all-media-btn" style="margin-bottom:18px;">
		<span class="dashicons dashicons-trash"></span> Delete All Media
	</button>
	<form id="adamson-archive-search-form" autocomplete="off">
		<input type="text" id="search-input" placeholder="Search albums or media..." />
		<select id="filter-status">
			<option value="">All Statuses</option>
			<option value="pending">Pending</option>
			<option value="processing">Processing</option>
			<option value="error">Error</option>
			<option value="complete">Complete</option>
		</select>
		<select id="filter-date">
			<option value="">All Dates</option>
			<!-- Date options dynamically generated -->
		</select>
		<button type="button" class="button button-primary" id="scan-process-btn">
			<span class="dashicons dashicons-update"></span> Scan & Process Media
		</button>
		<button type="button" class="button button-danger" id="delete-selected-btn">
			<span class="dashicons dashicons-trash"></span> Delete Selected
		</button>
	</form>

	<!-- Table Container -->
	<div id="adamson-archive-table-container">
		<div id="adamson-archive-table-loading">
			<span class="dashicons dashicons-update spin"></span> Loading...
		</div>
		<table class="wp-list-table widefat fixed striped" id="adamson-archive-table">
			<thead>
				<tr>
					<th><input type="checkbox" id="select-all"></th>
					<th>Album</th>
					<th>Media Count</th>
					<th>Status</th>
					<th>Progress</th>
					<th>Last Updated</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="adamson-archive-table-body">
				<!-- Dynamic album rows go here -->
				<tr class="no-items">
					<td colspan="7">No albums found.</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Error Display -->
	<div id="adamson-archive-errors">
		<!-- Error messages dynamically generated -->
	</div>
</div>
