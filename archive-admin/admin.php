<?php
    require_once(__DIR__ . '/sync.php');
    require_once(__DIR__ . '/ajax.php');

// Adamson Archive Admin Page
function adamson_archive_admin_menu() {
    add_menu_page(
        'Adamson Archive',
        'Adamson Archive',
        'manage_options',
        'adamson-archive',
        'adamson_archive_admin_page',
        'dashicons-images-alt2',
        25
    );
}
add_action('admin_menu', 'adamson_archive_admin_menu');

function adamson_archive_admin_page() {
    echo '<div class="wrap"><h1>The Adamson Archive</h1>';
    echo '<div id="adamson-archive-progress"></div>';


    // Scan & Process Albums button
    echo '<form id="adamson-archive-scan-form" method="post" style="display:inline-block;margin-right:10px;">';
    echo '<input type="hidden" name="adamson_archive_scan" value="1">';
    echo '<button type="submit" class="button button-primary">Scan & Process Albums</button>';
    echo '</form>';

    // Delete All Albums button
    echo '<form method="post" style="display:inline-block;">';
    echo '<input type="hidden" name="adamson_archive_delete_all" value="1">';
    echo '<button type="submit" class="button button-danger" onclick="return confirm(\'Are you sure you want to delete all albums and media? This cannot be undone.\')">Delete All Albums</button>';
    echo '</form>';

    // Albums Table
    global $wpdb;
    // Handle sorting
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_created';
    $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
    $validSorts = ['name','year','visible','processed','images','videos','date_created','date_updated'];
    if (!in_array($sort, $validSorts)) $sort = 'date_created';

    // Handle search
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $searchSql = $search ? $wpdb->prepare("WHERE name LIKE %s OR year LIKE %s", "%$search%", "%$search%") : '';

    // Handle pagination (load more)
    $per_page = 2; // CHANGE THIS VALUE TO UPDATE PAGE SIZE EVERYWHERE
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $albums = $wpdb->get_results("SELECT * FROM adamson_archive_albums $searchSql ORDER BY $sort $order LIMIT $per_page OFFSET $offset");
    $total_albums = $wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums $searchSql");
    echo '<h2 style="margin-top:30px;">Albums</h2>';
    echo '<form id="adamson-archive-search-form" method="get" style="margin-bottom:10px;">';
    echo '<input type="text" name="search" id="adamson-archive-search" value="' . esc_attr($search) . '" placeholder="Search albums..." autocomplete="off" /> ';
    echo '<input type="submit" class="button" value="Search" />';
    echo '<input type="hidden" name="sort" value="' . esc_attr($sort) . '" />';
    echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
    echo '</form>';
    echo '<script>window.ADAMSON_ARCHIVE_PER_PAGE = ' . intval($per_page) . ';</script>';
    echo '<div id="adamson-archive-table-container">';
    // Only render the table shell, let JS/AJAX fill it
    echo '<table class="wp-list-table widefat fixed striped" style="max-width:1000px;">';
    echo '<thead><tr>';
    $columns = [
        'name' => 'Name',
        'year' => 'Year',
        'visible' => 'Visible',
        'processed' => 'Processed',
        'images' => 'Images',
        'videos' => 'Videos',
        'date_created' => 'Date Created',
        'actions' => 'Actions'
    ];
    foreach ($columns as $col => $label) {
        if ($col === 'actions') {
            echo '<th>' . $label . '</th>';
            continue;
        }
        // The arrow will be rendered by JS for the current sort column
        echo '<th><a href="#" class="adamson-archive-sort" data-sort="' . esc_attr($col) . '">' . $label . ' <span class="adamson-archive-arrow"></span></a></th>';
    }
    echo '</tr></thead><tbody></tbody></table>';
    echo '<button id="adamson-archive-load-more" class="button" style="display:none;">Load More</button>';
    echo '</div>';
    ?>
    <script>
    jQuery(document).ready(function($) {
        var timer = null;
        var offset = 0;
        var per_page = window.ADAMSON_ARCHIVE_PER_PAGE;
        var lastSearch = '';
        var lastSort = $('#adamson-archive-search-form input[name="sort"]').val() || 'date_created';
        var lastOrder = $('#adamson-archive-search-form input[name="order"]').val() || 'DESC';
        function updateSortArrows(sort, order) {
            $('.adamson-archive-arrow').html('');
            var arrow = order === 'ASC' ? ' &#9650;' : ' &#9660;';
            $('.adamson-archive-sort').each(function() {
                if ($(this).data('sort') === sort) {
                    $(this).find('.adamson-archive-arrow').html(arrow);
                }
            });
        }

        function renderRows(rows, append) {
            var tbody = '';
            rows.forEach(function(row) {
                tbody += '<tr>' +
                    '<td>' + row.name + '</td>' +
                    '<td>' + (row.year || '') + '</td>' +
                    '<td>' + row.visible + '</td>' +
                    '<td>' + row.processed + '</td>' +
                    '<td>' + row.images + '</td>' +
                    '<td>' + row.videos + '</td>' +
                    '<td>' + (row.date_created || '') + '</td>' +
                    '<td>' +
                        '<form method="post" style="display:inline;">' +
                        '<input type="hidden" name="adamson_archive_reprocess_album" value="' + row.id + '">' +
                        '<button type="submit" class="button">Reprocess</button>' +
                        '</form>' +
                    '</td>' +
                '</tr>';
            });
            if (append) {
                $('.wp-list-table tbody').append(tbody);
            } else {
                $('.wp-list-table tbody').html(tbody);
            }
            // Default: show arrow for current sort
            var sort = $('#adamson-archive-search-form input[name="sort"]').val();
            var order = $('#adamson-archive-search-form input[name="order"]').val();
            updateSortArrows(sort, order);
        }
        function fetchAlbums(opts) {
            opts = opts || {};
            var form = $('#adamson-archive-search-form');
            var search = form.find('input[name="search"]').val();
            var sort = form.find('input[name="sort"]').val();
            var order = form.find('input[name="order"]').val();
            var fetchOffset = opts.append ? offset : 0;
            $.post(ajaxurl, {
                action: 'adamson_archive_search_albums',
                search: search,
                sort: sort,
                order: order,
                offset: fetchOffset
            }, function(response) {
                if (opts.append) {
                    renderRows(response.rows, true);
                    offset += response.rows.length;
                } else {
                    renderRows(response.rows, false);
                    offset = response.rows.length;
                }
                // Show/hide Load More
                if (offset < response.total) {
                    $('#adamson-archive-load-more').show();
                } else {
                    $('#adamson-archive-load-more').hide();
                }
                lastSearch = search;
                lastSort = sort;
                lastOrder = order;
            });
        }
        // Search input
        $('#adamson-archive-search').on('input', function() {
            clearTimeout(timer);
            timer = setTimeout(function() {
                offset = 0;
                // Reset sort to default for new search
                $('#adamson-archive-search-form input[name="sort"]').val('date_created');
                $('#adamson-archive-search-form input[name="order"]').val('DESC');
                fetchAlbums({append: false});
            }, 300);
        });
        // Search submit
        $('#adamson-archive-search-form').on('submit', function(e) {
            e.preventDefault();
            offset = 0;
            // Reset sort to default for new search
            $('#adamson-archive-search-form input[name="sort"]').val('date_created');
            $('#adamson-archive-search-form input[name="order"]').val('DESC');
            fetchAlbums({append: false});
        });
        // Load More button
        $(document).on('click', '#adamson-archive-load-more', function(e) {
            e.preventDefault();
            fetchAlbums({append: true});
        });
        // Sorting click handler
        $(document).on('click', '.adamson-archive-sort', function(e) {
            e.preventDefault();
            var sort = $(this).data('sort');
            var currentSort = $('#adamson-archive-search-form input[name="sort"]').val();
            var currentOrder = $('#adamson-archive-search-form input[name="order"]').val();
            var order = 'DESC';
            if (sort === currentSort) {
                order = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            }
            $('#adamson-archive-search-form input[name="sort"]').val(sort);
            $('#adamson-archive-search-form input[name="order"]').val(order);
            // Client-side sort: sort only visible rows
            var rows = $('.wp-list-table tbody tr').get();
            rows.sort(function(a, b) {
                var getVal = function(row, idx) {
                    var td = $(row).children('td').eq(idx);
                    return td.text().trim();
                };
                var idx = $(e.target).closest('th').index();
                var valA = getVal(a, idx);
                var valB = getVal(b, idx);
                // Numeric sort if both are numbers
                if (!isNaN(valA) && !isNaN(valB)) {
                    valA = parseFloat(valA);
                    valB = parseFloat(valB);
                }
                if (valA < valB) return order === 'ASC' ? -1 : 1;
                if (valA > valB) return order === 'ASC' ? 1 : -1;
                return 0;
            });
            $.each(rows, function(idx, row) {
                $('.wp-list-table tbody').append(row);
            });
            updateSortArrows(sort, order);
        });
        // Initial state: load first page via AJAX
        fetchAlbums({append: false});

        // Scan & Process: auto-refresh table after processing
        $('#adamson-archive-scan-form').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            var btn = form.find('button[type="submit"]');
            btn.prop('disabled', true).text('Processing...');
            $.post(ajaxurl, {
                action: 'adamson_archive_scan',
            }, function(data) {
                btn.prop('disabled', false).text('Scan & Process Albums');
                var nothingNewMsg = 'No new or updated albums found. Everything is up to date.';
                // Show progress HTML above the table
                var $progress = $('#adamson-archive-progress');
                $progress.html(data);
                // If nothing new, show inline notification (WordPress style)
                if (typeof data === 'string' && data.indexOf(nothingNewMsg) !== -1) {
                    var notice = '<div class="notice notice-warning is-dismissible"><p>' + nothingNewMsg + '</p></div>';
                    $progress.prepend(notice);
                }
                // Auto-scroll progress list to bottom
                var $list = $('#adamson-archive-progress-list');
                if ($list.length) {
                    $list.scrollTop($list[0].scrollHeight);
                }
                // Clear table and reload first page via AJAX
                $('.wp-list-table tbody').empty();
                offset = 0;
                fetchAlbums({append: false});
            });
        });
    });

    </script>
    <style>#adamson-archive-load-more{margin-top:10px;}</style>
<?php
    // Only output progress if not AJAX (AJAX handled above)
    if (isset($_POST['adamson_archive_scan']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $progress = adamson_archive_sync_and_process();
        echo '<div style="margin:20px 0;padding:10px;border:1px solid #ccc;background:#fafafa;max-width:600px;">';
        echo '<strong style="display:block;margin-bottom:10px;text-align:center;">Sync & Process Progress:</strong><ul style="margin:0 0 0 20px;">';
        foreach ($progress as $msg) {
            echo '<li>' . esc_html($msg) . '</li>';
        }
        echo '</ul></div>';
    }

    if (isset($_POST['adamson_archive_delete_all'])) {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE adamson_archive_media');
        $wpdb->query('TRUNCATE TABLE adamson_archive_albums');
        echo '<div style="margin-top:20px;padding:10px;border:1px solid #f00;background:#fee;max-width:600px;">';
        echo '<strong>All albums and media have been deleted.</strong>';
        echo '</div>';
    }
    echo '</div>';
}

