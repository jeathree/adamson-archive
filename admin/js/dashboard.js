jQuery(document).ready(function($) {
	const $scanBtn = $('#adamson-archive-scan-button');
	const $queueBtn = $('#adamson-archive-process-queue-button');
	const $logTable = $('#adamson-archive-activity-log');
	const $queueCount = $('#adamson-queue-count');
	const $albumList = $('#adamson-archive-album-list');
	const $loadMoreBtn = $('#adamson-archive-load-more-albums');
	let currentPage = 1;

	// Configure Toastr
	toastr.options = {
		"closeButton": true,
		"debug": false,
		"newestOnTop": true,
		"progressBar": true,
		"positionClass": "toast-top-right",
		"preventDuplicates": false,
		"onclick": null,
		"showDuration": "300",
		"hideDuration": "1000",
		"timeOut": "5000",
		"extendedTimeOut": "1000",
		"showEasing": "swing",
		"hideEasing": "linear",
		"showMethod": "fadeIn",
		"hideMethod": "fadeOut"
	};

	function log(message, type = 'info') { // type can be 'info', 'success', 'error'
		// 1. Log to the main activity table
		const time = new Date().toLocaleTimeString();
		const row = `<tr>
			<td>${time}</td>
			<td>System</td>
			<td>${message}</td>
		</tr>`;
		
		$logTable.find('.no-items').remove();
		$logTable.prepend(row);

		// 2. Show a toast notification using Toastr
		toastr[type](message);
	}

	function logError(shortMessage, longMessage) {
		// 1. Log the long message to the main activity table
		const time = new Date().toLocaleTimeString();
		const row = `<tr>
			<td>${time}</td>
			<td>System</td>
			<td>${longMessage}</td>
		</tr>`;
		
		$logTable.find('.no-items').remove();
		$logTable.prepend(row);

		// 2. Show the short message as a toast notification
		toastr['error'](shortMessage);
	}


	function updateDashboardCounts() {
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_dashboard_counts',
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					const pendingCount = parseInt(response.data.pending_videos, 10);
					$queueCount.text(pendingCount);
					$queueBtn.prop('disabled', 0 === pendingCount);
				}
			}
			// No error handling needed, if it fails, the number just won't update.
		});
	}

	function loadAlbums() {
		$loadMoreBtn.show().prop('disabled', true).text('Loading...');
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_load_albums',
				page: currentPage
			},
			success: function(response) {
				if (response.success) {
					if (response.data.albums.length === 0 && currentPage === 1) {
						log('No albums found on the server.', 'info');
					}

					response.data.albums.forEach(function(album) {
						const photo_count = parseInt(album.photo_count || 0);
						const video_count = parseInt(album.video_count || 0);
						const pending_video_count = parseInt(album.pending_video_count || 0);
						const total_media = photo_count + video_count + pending_video_count;

						let counts_string = `Images: ${photo_count}, YouTube Videos: ${video_count}`;
						if (pending_video_count > 0) {
							counts_string += `, Pending Videos: ${pending_video_count}`;
						}

						let playlistCell = '<td>No Videos</td>';
						if (album.video_count > 0 && album.yt_playlist_id && album.yt_playlist_url) {
							playlistCell = `<td><a href="${album.yt_playlist_url}" target="_blank">${album.yt_playlist_id}</a></td>`;
						}

						const row = `<tr id="album-${album.id}" class="album-row">
							<th scope="row" class="check-column">
								<button type="button" class="toggle-row"><span class="screen-reader-text">Show more details</span></button>
							</th>
							<td class="album-name"><strong>${album.display_name}</strong></td>
							<td>${album.album_date}</td>
							<td class="album-counts">Total: ${total_media} (${counts_string})</td>
							${playlistCell}
						</tr>`;
						$albumList.append(row);
					});

					if (response.data.has_more) {
						$loadMoreBtn.prop('disabled', false).text('Load More');
						currentPage++;
					} else {
						$loadMoreBtn.hide();
					}
				} else {
					log('Error: ' + response.data.message, 'error');
					$loadMoreBtn.prop('disabled', false).text('Load More');
				}
			},
			error: function() {
				log('Server error while loading albums.', 'error');
				$loadMoreBtn.prop('disabled', false).text('Load More');
			}
		});
	}

	$loadMoreBtn.on('click', function(e) {
		e.preventDefault();
		loadAlbums();
	});

	$albumList.on('click', '.toggle-row', function(e) {
		e.preventDefault();
		const $row = $(this).closest('tr');
		const albumId = $row.attr('id').replace('album-', '');

		if ($row.hasClass('is-expanded')) {
			$row.removeClass('is-expanded');
			$row.next('.media-row').remove();
		} else {
			$row.addClass('is-expanded');
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_get_album_media',
					album_id: albumId
				},
				success: function(response) {
					if (response.success) {
						let mediaHtml = '<tr class="media-row"><td colspan="5">';
						const counts = response.data.counts;
						const photo_count = counts.photo_count || 0;
						const video_count = counts.video_count || 0;
						const total_media = counts.total_media || 0;

						mediaHtml += `<div class="media-summary">Total: ${total_media} (Images: ${photo_count}, Videos: ${video_count})</div>`;
						mediaHtml += '<div class="media-container">';

						response.data.media.forEach(function(media) {
							mediaHtml += `<div class="media-item">
								<img src="${media.file_url}" alt="${media.filename}" />
								<p>${media.filename}</p>
							</div>`;
						});
						mediaHtml += '</div></td></tr>';
						$row.after(mediaHtml);
					} else {
						log('Error: ' + response.data.message, 'error');
					}
				},
				error: function() {
					log('Server error while loading media.', 'error');
				}
			});
		}
	});

	// Initial load
	loadAlbums();
	updateDashboardCounts();

	// Existing functionality
	$scanBtn.on('click', function(e) {
		e.preventDefault();
		if ($scanBtn.prop('disabled')) return;

		$scanBtn.prop('disabled', true).text('Scanning Albums...');
		log('Starting library scan...', 'info');

		// Step 1: Scan Albums
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_scan_albums',
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					log(response.data.message, 'info');
					const albums = response.data.albums;
					if (albums.length > 0) {
						processAlbums(albums, 0);
					} else {
						$scanBtn.prop('disabled', false).text('Scan for New Media');
						log('No albums found.', 'info');
					}
				} else {
					log('Error: ' + response.data.message, 'error');
					$scanBtn.prop('disabled', false).text('Scan for New Media');
				}
			},
			error: function() {
				log('Server error during album scan.', 'error');
				$scanBtn.prop('disabled', false).text('Scan for New Media');
			}
		});
	});

	function processAlbums(albums, index) {
		if (index >= albums.length) {
			log('Scan complete! All albums processed.', 'success');
			$scanBtn.prop('disabled', false).text('Scan for New Media');
			
			// Refresh counts and album list
			updateDashboardCounts();
			$albumList.empty();
			currentPage = 1;
			loadAlbums();
			return;
		}

		const album = albums[index];
		$scanBtn.text(`Processing ${index + 1}/${albums.length}: ${album.name}`);

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_process_album',
				nonce: adamsonArchive.nonce,
				album_id: album.id
			},
			success: function(response) {
				if (response.success) {
					if (response.data.count > 0) {
						log(`Processed [${album.name}]: Found ${response.data.count} new files.`, 'success');
					}
					if (response.data.notices && Array.isArray(response.data.notices)) {
						response.data.notices.forEach(function(notice) {
							log(notice, 'info');
						});
					}
				} else if (!response.success) {
					log(`Error processing ${album.name}: ${response.data.message}`, 'error');
				}
				processAlbums(albums, index + 1);
			},
			error: function() {
				log(`Server error processing ${album.name}`, 'error');
				processAlbums(albums, index + 1);
			}
		});
	}

	// Queue Processing Logic
	$queueBtn.on('click', function(e) {
		e.preventDefault();
		if ($queueBtn.prop('disabled')) return;

		$queueBtn.prop('disabled', true).text('Processing Queue...');
		log('Starting queue processing...', 'info');
		processNextQueueItem();
	});

	function processNextQueueItem() {
		const disableDelete = $('#adamson-archive-disable-delete').is(':checked');

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_process_queue_item',
				nonce: adamsonArchive.nonce,
				disable_delete: disableDelete ? '1' : '0'
			},
			success: function(response) {
				if (response.success) {
					if (response.data.processed) {
						const messageType = response.data.message.includes('Skipped') ? 'info' : 'success';
						log(response.data.message, messageType);
						
						// Update UI elements live
						loadPendingVideos();
						if (response.data.album_id) {
							updateAlbumRow(response.data.album_id);
						}

						$queueCount.text(response.data.remaining);
						processNextQueueItem(); // Recursive call for next item
					} else {
						log('Queue processing complete.', 'success');
						$queueBtn.prop('disabled', true).text('Process Upload Queue');
					}
				} else {
					logError('There was a critical error.', 'Error: ' + response.data.message);
					$queueBtn.prop('disabled', false).text('Process Upload Queue');
				}
			},
			error: function(jqXHR, textStatus, errorThrown) {
				let errorMessage = jqXHR.responseText || 'An unknown server error occurred.';

				// Clean up WordPress critical error HTML
				if (errorMessage.includes('There has been a critical error on this website')) {
					errorMessage = 'Critical Error: The server process crashed. Please check your PHP error logs.';
				} else if (errorMessage.trim().startsWith('<')) {
					// Strip HTML tags to reveal the actual error (e.g. if WP_DEBUG is on)
					errorMessage = errorMessage.replace(/<[^>]*>?/gm, ' ').replace(/\s\s+/g, ' ').trim();
				}

				logError('There was a critical error.', errorMessage);
				$queueBtn.prop('disabled', false).text('Process Upload Queue');
			}
		});
	}

	function updateAlbumRow(albumId) {
		const $row = $(`#album-${albumId}`);
		if ($row.length === 0) return;

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_album_media',
				album_id: albumId
			},
			success: function(response) {
				if (response.success) {
					const counts = response.data.counts;
					const text = `Total: ${counts.total_media} (Images: ${counts.photo_count}, Videos: ${counts.video_count})`;
					$row.find('.album-counts').text(text);
				}
			}
		});
	}

	// Tab switching
	$('.nav-tab-wrapper a').on('click', function(e) {
		e.preventDefault();
		const $this = $(this);
		const target = $this.attr('href');

		$this.addClass('nav-tab-active').siblings().removeClass('nav-tab-active');
		$('.tab-content').removeClass('active');
		$(target).addClass('active');
	});

	// Inject "Disable Delete" checkbox for testing
	const $deleteMediaBtn = $('#adamson-archive-delete-all-media');
	if ($deleteMediaBtn.length) {
		
	}

	// Delete all media button
	$('#adamson-archive-delete-all-media').on('click', function(e) {
		e.preventDefault();

		if (confirm('Are you sure you want to delete all media? This action cannot be undone.')) {
			const $btn = $(this);
			$btn.prop('disabled', true).text('Deleting...');
			log('Deleting all media...', 'info');

			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_delete_all_media',
					nonce: adamsonArchive.nonce
				},
				success: function(response) {
					if (response.success) {
						log(response.data.message, 'success');
						// Refresh the album list
						$albumList.empty();
						currentPage = 1;
						loadAlbums();
					} else {
						log('Error: ' + response.data.message, 'error');
					}
				},
				error: function() {
					log('Server error during media deletion.', 'error');
				},
				complete: function() {
					$btn.prop('disabled', false).text('Delete All Media');
				}
			});
		}
	});

	// Pending Videos Modal
	const $pendingVideosLink = $('#view-pending-videos-link');
	const $pendingVideosContainer = $('#pending-videos-container');
	const $pendingVideosTableWrapper = $('#pending-videos-table-wrapper');
	const $closePendingVideosBtn = $('#close-pending-videos');

	function loadPendingVideos() {
		if (!$pendingVideosContainer.is(':visible')) return;

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_pending_videos',
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					if (response.data.videos.length > 0) {
						let tableHtml = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>File Name</th><th>Album</th><th>File Path</th></tr></thead><tbody>';
						response.data.videos.forEach(function(video) {
							tableHtml += `<tr><td>${video.filename}</td><td>${video.album_name}</td><td>${video.file_path}</td></tr>`;
						});
						tableHtml += '</tbody></table>';
						$pendingVideosTableWrapper.html(tableHtml);
					} else {
						$pendingVideosTableWrapper.html('<p>No videos are currently pending.</p>');
					}
				} else {
					// Silent fail or log if needed, but avoid spamming logs during batch
				}
			}
		});
	}

	$pendingVideosLink.on('click', function(e) {
		e.preventDefault();

		// Use jQuery's built-in toggle to handle the show/hide action.
		$pendingVideosContainer.toggle();

		// After toggling, if the container is now visible, it means we just opened it.
		// So, we should load the content.
		if ($pendingVideosContainer.is(':visible')) {
			// Show loading state
			$pendingVideosTableWrapper.html('<p>Loading pending videos...</p>');
			loadPendingVideos();
		}
	});

	$closePendingVideosBtn.on('click', function(e) {
		e.preventDefault();
		$pendingVideosContainer.hide();
	});

	// Settings
	$('#adamson-archive-disable-delete').on('change', function() {
		const isChecked = $(this).is(':checked');
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_save_settings',
				nonce: adamsonArchive.nonce,
				disable_delete: isChecked ? '1' : '0'
			},
			success: function(response) {
				if (response.success) {
					log('Settings saved.', 'success');
				} else {
					logError('Could not save settings.', response.data.message);
				}
			},
			error: function() {
				logError('Could not save settings.', 'A server error occurred.');
			}
		});
	});
});
