<?php
    require_once(__DIR__ . '/sync.php');
    require_once(__DIR__ . '/ajax.php');
    require_once(__DIR__ . '/youtube-settings.php');

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
    // Add YouTube Settings submenu
    add_submenu_page(
        'adamson-archive',
        'YouTube Settings',
        'YouTube Settings',
        'manage_options',
        'adamson-archive-youtube-settings',
        'adamson_archive_youtube_settings_page'
    );
}
add_action('admin_menu', 'adamson_archive_admin_menu');

function adamson_archive_admin_page() {
    echo '<div class="wrap"><h1>The Adamson Archive</h1>';
    echo '<div id="adamson-archive-progress"></div>';

    // --- Album Summary Dashboard ---
    global $wpdb;
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums");
    $processed = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums WHERE processed = 1");
    $failed = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums WHERE processed = 0 AND id IN (SELECT album_id FROM adamson_archive_media WHERE type IN ('mp4','mov','avi','mkv','webm') AND (youtube_id IS NULL OR youtube_id = ''))");
    $pending = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums WHERE processed = 0 AND id NOT IN (SELECT album_id FROM adamson_archive_media WHERE type IN ('mp4','mov','avi','mkv','webm') AND (youtube_id IS NULL OR youtube_id = ''))");
    echo '<div id="adamson-archive-summary">';
    echo '<strong>Album Summary:</strong>';
    $help_icon = function($tip) {
        return '<span class="adamson-help-icon" title="' . htmlspecialchars($tip) . '">&#9432;</span>';
    };
    echo '<span class="adamson-summary-total">Total: <b>' . $total . '</b>' . $help_icon('All albums in the archive, regardless of status.') . '</span>';
    echo '<span class="adamson-summary-processed">Processed: <b>' . $processed . '</b>' . $help_icon('Albums where all YouTube uploads and playlist actions succeeded (fully complete).') . '</span>';
    echo '<span class="adamson-summary-failed">Failed: <b>' . $failed . '</b>' . $help_icon('Albums that have at least one video or playlist that failed to upload to YouTube. These albums are not fully processed and will be retried.') . '</span>';
    echo '<span class="adamson-summary-pending">Pending: <b>' . $pending . '</b>' . $help_icon('Albums that are not yet processed and have no failed YouTube uploads—typically new albums waiting to be processed, or albums with only non-video media.') . '</span>';
    echo '</div>';
?>
<script>
jQuery(document).ready(function($) {
    // Tooltip for help icons
    $(document).on('mouseenter', '.adamson-help-icon', function() {
        var tip = $(this).attr('title');
        var $tip = $('<div class="adamson-tooltip"></div>').text(tip);
        $('body').append($tip);
        var offset = $(this).offset();
        $tip.css({
            top: offset.top - $tip.outerHeight() - 8,
            left: offset.left - ($tip.outerWidth()/2) + 10
        });
        $(this).data('adamson-tip', $tip);
    }).on('mouseleave', '.adamson-help-icon', function() {
        var $tip = $(this).data('adamson-tip');
        if ($tip) $tip.remove();
    });
});
</script>

<?php
    // Scan & Process Albums button
    echo '<form id="adamson-archive-scan-form" method="post">';
    echo '<input type="hidden" name="adamson_archive_scan" value="1">';
    echo '<button type="submit" class="button button-primary">Scan & Process Albums</button>';
    echo '</form>';

    // Delete All Albums button
    echo '<form method="post" class="adamson-inline-form">';
    echo '<input type="hidden" name="adamson_archive_delete_all" value="1">';
    echo '<button type="submit" class="button button-danger" onclick="return confirm(\'Are you sure you want to delete all albums and media? This cannot be undone.\')">Delete All Albums</button>';
    echo '</form>';

    // Handle sorting
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_created';
    $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
    $validSorts = ['name','year','visible','processed','images','videos','date_created','date_updated'];
    if (!in_array($sort, $validSorts)) $sort = 'date_created';

    // Handle search
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $searchSql = $search ? $wpdb->prepare("WHERE name LIKE %s OR year LIKE %s", "%$search%", "%$search%") : '';

    // Handle pagination (load more)
    $per_page = 2;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $albums = $wpdb->get_results("SELECT * FROM adamson_archive_albums $searchSql ORDER BY $sort $order LIMIT $per_page OFFSET $offset");
    $total_albums = $wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums $searchSql");
    echo '<h2 class="adamson-archive-albums-title">Albums</h2>';
    echo '<form id="adamson-archive-search-form" method="get">';
    echo '<input type="text" name="search" id="adamson-archive-search" value="' . esc_attr($search) . '" placeholder="Search albums..." autocomplete="off" /> ';
    echo '<input type="submit" class="button" value="Search" />';
    echo '<input type="hidden" name="sort" value="' . esc_attr($sort) . '" />';
    echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
    echo '</form>';
    echo '<script>window.ADAMSON_ARCHIVE_PER_PAGE = ' . intval($per_page) . ';</script>';
    echo '<div id="adamson-archive-table-container">';
    // Only render the table shell, let JS/AJAX fill it
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    $sortable = ['date_created','name','year','visible','processed'];
    $columns = [
        'date_created' => 'Date Created',
        'name' => 'Name',
        'year' => 'Year',
        'visible' => 'Visible',
        'processed' => 'Processed',
        'images' => 'Images',
        'videos' => 'Videos',
        'actions' => 'Actions'
    ];
    foreach ($columns as $col => $label) {
        if ($col === 'actions') {
            echo '<th>' . $label . '</th>';
            continue;
        }
        if (in_array($col, $sortable)) {
            echo '<th><a href="#" class="adamson-archive-sort" data-sort="' . esc_attr($col) . '">' . $label . ' <span class="adamson-archive-arrow adamson-archive-arrow-inactive">&#9650;</span></a></th>';
        } else {
            echo '<th>' . $label . '</th>';
        }
    }
    echo '</tr></thead><tbody></tbody></table>';
    echo '<button id="adamson-archive-load-more" class="button">Load More</button>';
    echo '</div>';
    // Output JS and CSS outside PHP
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
            var arrowUp = '\u25B2';
            var arrowDown = '\u25BC';
            $('.adamson-archive-arrow').each(function() {
                $(this).removeClass('adamson-archive-arrow-active').addClass('adamson-archive-arrow-inactive').text(arrowUp);
            });
            $('.adamson-archive-sort').each(function() {
                if ($(this).data('sort') == sort) {
                    var arrow = order === 'ASC' ? arrowUp : arrowDown;
                    $(this).find('.adamson-archive-arrow').removeClass('adamson-archive-arrow-inactive').addClass('adamson-archive-arrow-active').text(arrow);
                }
            });
        }

        function renderRows(rows, append) {
            var tbody = '';
            rows.forEach(function(row) {
                var arrow = '<span class="adamson-album-arrow" data-album-id="' + row.id + '" style="cursor:pointer;">&#9654;</span> ';
                tbody += '<tr class="adamson-album-row" data-album-id="' + row.id + '">' +
                    '<td>' + (row.date_created || '') + '</td>' +
                    '<td>' + arrow + row.name + '</td>' +
                    '<td>' + (row.year || '') + '</td>' +
                    '<td>' + row.visible + '</td>' +
                    '<td>' + row.processed + '</td>' +
                    '<td>' + row.images + '</td>' +
                    '<td>' + row.videos + '</td>' +
                    '<td>' +
                        '<form method="post" class="adamson-inline-form">' +
                        '<input type="hidden" name="adamson_archive_reprocess_album" value="' + row.id + '">' +
                        '<button type="submit" class="button">Reprocess</button>' +
                        '</form>' +
                    '</td>' +
                '</tr>';
                // Add a hidden row for media details
                tbody += '<tr class="adamson-album-media-row" data-album-id="' + row.id + '" style="display:none;"><td colspan="8"><div class="adamson-album-media-list"></div></td></tr>';
            });
                    // Album arrow click handler: slide down and load media via AJAX
                    $(document).on('click', '.adamson-album-arrow', function() {
                        var albumId = $(this).data('album-id');
                        var $arrow = $(this);
                        var $mediaRow = $('.adamson-album-media-row[data-album-id="' + albumId + '"]');
                        var $mediaList = $mediaRow.find('.adamson-album-media-list');
                        if ($mediaRow.is(':visible')) {
                            $mediaRow.slideUp();
                            $arrow.html('&#9654;');
                            return;
                        }
                        // Hide any other open media rows
                        $('.adamson-album-media-row:visible').slideUp();
                        $('.adamson-album-arrow').html('&#9654;');
                        $arrow.html('&#9660;');
                        $mediaRow.slideDown();
                        if ($mediaList.data('loaded')) return;
                        $mediaList.html('<em>Loading...</em>');
                        $.post(ajaxurl, {action: 'adamson_archive_album_media', album_id: albumId}, function(resp) {
                            if (resp.success && resp.data && resp.data.media) {
                                var html = '<ul class="adamson-media-list">';
                                resp.data.media.forEach(function(m) {
                                    var failClass = m.failed ? 'adamson-media-failed' : '';
                                    html += '<li class="' + failClass + '">' + m.filename + (m.failed ? ' <span class="adamson-fail-label">(Failed)</span>' : '') + '</li>';
                                });
                                html += '</ul>';
                                $mediaList.html(html);
                                $mediaList.data('loaded', 1);
                            } else {
                                $mediaList.html('<span style="color:red;">Failed to load media list.</span>');
                            }
                        });
                    });
                // CSS for arrow and failed media (add to style.css in a separate step)
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
        // Sorting click handler (server-side: sorts all data, paging always works)
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
            offset = 0; // Reset offset on sort change
            fetchAlbums({append: false});
        });
        // Initial state: load first page via AJAX
        fetchAlbums({append: false});

        // Scan & Process: auto-refresh table and summary after processing
        var progressInterval = null;
        function pollProgress() {
            $.post(ajaxurl, {action: 'adamson_archive_scan_progress'}, function(resp) {
                if (resp.success && resp.data && resp.data.progress) {
                    var msgs = resp.data.progress;
                    var html = '<div style="margin:20px 0;padding:10px;border:1px solid #ccc;background:#fafafa;max-width:600px;position:relative;">';
                    // Add close button if not already present
                    html += '<button class="progress-close-btn" aria-label="Close">&#10005;</button>';
                    html += '<strong style="display:block;margin-bottom:10px;text-align:center;">Sync & Process Progress:</strong>';
                    html += '<ul id="adamson-archive-progress-list" style="margin:0 0 0 20px;max-height:168px;overflow-y:auto;">';
                    for (var i = 0; i < msgs.length; i++) {
                        html += '<li>' + $("<div>").text(msgs[i]).html() + '</li>';
                    }
                    html += '</ul></div>';
                    $('#adamson-archive-progress').html(html);
                    // Attach close handler
                    $('#adamson-archive-progress .progress-close-btn').off('click').on('click', function() {
                        $('#adamson-archive-progress').empty();
                    });
                    var $list = $('#adamson-archive-progress-list');
                    if ($list.length) {
                        $list.scrollTop($list[0].scrollHeight);
                    }
                    // Only reload table and summary after SCAN COMPLETE! and confirm it persists for two polls
                    if (msgs.length && msgs[msgs.length-1].indexOf('SCAN COMPLETE!') !== -1) {
                        if (window._adamson_scan_complete_seen) {
                            clearInterval(progressInterval);
                            progressInterval = null;
                            $('.wp-list-table tbody').empty();
                            offset = 0;
                            window.offset = 0;
                            fetchAlbums({append: false});
                            // Update summary via AJAX
                            $.post(ajaxurl, {action: 'adamson_archive_summary'}, function(resp) {
                                if (resp.success && resp.data && resp.data.html) {
                                    $('#adamson-archive-summary').replaceWith(resp.data.html);
                                }
                            });
                            // Do NOT auto-clear progress, keep open for manual close
                        } else {
                            window._adamson_scan_complete_seen = true;
                        }
                    } else {
                        window._adamson_scan_complete_seen = false;
                    }
                }
            });
        }
        $('#adamson-archive-scan-form').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            var btn = form.find('button[type="submit"]');
            btn.prop('disabled', true).text('Processing...');
            $.post(ajaxurl, { action: 'adamson_archive_scan' }, function(data) {
                btn.prop('disabled', false).text('Scan & Process Albums');
            });
            // Start polling for progress
            if (progressInterval) clearInterval(progressInterval);
            progressInterval = setInterval(pollProgress, 2000);
            pollProgress();
        });
    });
    </script>
    <style>#adamson-archive-load-more{margin-top:10px;}</style>
<?php
    // Only output progress if not AJAX (AJAX handled above)
    if (isset($_POST['adamson_archive_scan']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $progress = adamson_archive_sync_and_process();
        echo '<div class="adamson-progress-box">';
        echo '<strong class="adamson-progress-title">Sync & Process Progress:</strong><ul class="adamson-progress-list">';
        foreach ($progress as $msg) {
            echo '<li>' . esc_html($msg) . '</li>';
        }
        echo '</ul></div>';
    }

    if (isset($_POST['adamson_archive_delete_all'])) {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE adamson_archive_media');
        $wpdb->query('TRUNCATE TABLE adamson_archive_albums');
        echo '<div class="adamson-error-box">';
        echo '<strong>All albums and media have been deleted.</strong>';
        echo '</div>';
    }
    echo '</div>';
}

