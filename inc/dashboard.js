jQuery(document).ready(function($) {
	const $scanBtn = $('#adamson-archive-scan-button');
	const $queueBtn = $('#adamson-archive-process-queue-button');
	const $logTable = $('#adamson-archive-activity-log');
	const $queueCount = $('#adamson-queue-count');
	const $albumList = $('#adamson-archive-album-list');
	const $loadMoreBtn = $('#adamson-archive-load-more-albums');
	let currentPage = 1;
	let lastProcessedId = 0;

	// Inject Worker Status Indicator next to the queue count
	if ($queueCount.length) {
		$queueCount.after(`
			<span id="queue-worker-status" style="display:none; margin-left: 10px; vertical-align: middle;">
				<span class="spinner is-active" style="float:none; margin: 0 5px 0 0;"></span>
				<small>Processing...</small>
			</span>
		`);
	}
	const $workerStatus = $('#queue-worker-status');

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

	// Initialize Lightbox
	if (!$('#adamson-video-lightbox').length) {
		$('body').append(`
			<div id="adamson-video-lightbox" class="adamson-lightbox">
				<div class="adamson-lightbox-overlay"></div>
				<div class="adamson-lightbox-content">
					<button class="adamson-lightbox-close" title="Close">&times;</button>
					<div class="adamson-video-responsive-container"></div>
				</div>
			</div>
		`);
	}

	const $lightbox = $('#adamson-video-lightbox');
	const $lightboxContainer = $lightbox.find('.adamson-video-responsive-container');

	$lightbox.on('click', '.adamson-lightbox-overlay, .adamson-lightbox-close', function() {
		$lightbox.fadeOut(300, function() {
			$lightboxContainer.empty();
		});
	});

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
					const totalPending = parseInt(response.data.total_pending, 10);

					// Apply highlight if the count has changed
					if (parseInt($queueCount.text()) !== pendingCount) {
						$queueCount.text(pendingCount).parent().css('background-color', '#fff8e5');
						setTimeout(() => { 
							$queueCount.parent().css('transition', 'background-color 2s').css('background-color', ''); 
						}, 2000);
					}

					// Initialize lastProcessedId so we don't toast old messages on page load
					if (lastProcessedId === 0 && response.data.recent_messages && response.data.recent_messages.length > 0) {
						lastProcessedId = Math.max(...response.data.recent_messages.map(m => m.id));
					}
					
					// Update worker visibility and button state
					response.data.worker_active ? $workerStatus.show() : $workerStatus.hide();
					$queueBtn.prop('disabled', 0 === pendingCount || response.data.worker_active || totalPending > 0);
					$scanBtn.prop('disabled', response.data.worker_active || totalPending > 0);
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
				page: currentPage,
				nonce: adamsonArchive.nonce
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
						if (video_count > 0 && album.yt_playlist_id) {
							const playlistUrl = `https://www.youtube.com/playlist?list=${album.yt_playlist_id}`;
							const playlistName = album.album_date ? `${album.album_date} - ${album.display_name}` : album.display_name;
							playlistCell = `<td><a href="${playlistUrl}" target="_blank">${playlistName}</a></td>`;
						}

						const row = `<tr id="album-${album.id}" class="album-row">
							<th scope="row" class="check-column">
								<button type="button" class="toggle-row" title="Show more details"><span class="screen-reader-text">Show more details</span></button>
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

	$albumList.on('click', '.album-row', function(e) {
		// If the click was on a link, button, or input, don't toggle the row.
		if ($(e.target).is('a, a *, button, button *, input')) {
			return;
		}

		e.preventDefault();
		const $row = $(this);
		const albumId = $row.attr('id').replace('album-', '');

		if ($row.hasClass('is-expanded')) {
			// Destroy Isotope instance before removing the row
			const $mediaContainer = $row.next('.media-row').find('.media-container');
			if ($mediaContainer.data('isotope')) {
				$mediaContainer.isotope('destroy');
			}
			$row.removeClass('is-expanded');
			$row.next('.media-row').remove();
		} else {
			$row.addClass('is-expanded');
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_get_album_media',
					album_id: albumId,
					nonce: adamsonArchive.nonce
				},
				success: function(response) {
					if (response.success) {
						let mediaHtml = '<tr class="media-row"><td colspan="5">';
						const counts = response.data.counts;
						const photo_count = counts.photo_count || 0;
						const video_count = counts.video_count || 0;
						const total_media = counts.total_media || 0;

						mediaHtml += `<div class="media-container">`;

						response.data.media.forEach(function(media) {
							let item_html = '';
							if (media.file_type === 'photo') {
								item_html = `<img src="${media.file_url}" alt="${media.filename}" />`;
							} else if (media.file_type === 'video') {
								item_html = `
									<img src="${media.yt_thumbnail_url}" alt="${media.filename}" />
									<div class="play-button"></div>
								`;
							}

							mediaHtml += `<div class="media-item" data-media-id="${media.id}" data-file-type="${media.file_type}" data-embed-html="${encodeURIComponent(media.yt_embed_html || '')}">
								<div class="media-item-wrapper">
									${item_html}
									<p>${media.filename}</p>
								</div>
								<button class="button-link-delete delete-media-item" data-media-id="${media.id}">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</div>`;
						});
						mediaHtml += '</div>'; // End media-container

						mediaHtml += `<div class="album-actions">
							<button class="button button-link-delete delete-album-link" data-album-id="${albumId}">
								Delete Entire Album
							</button>
						</div>`;

						mediaHtml += '</td></tr>';
						$row.after(mediaHtml);

						// Initialize Isotope after images are loaded
						const $mediaContainer = $row.next('.media-row').find('.media-container');
						$mediaContainer.imagesLoaded(function() {
							$mediaContainer.isotope({
								itemSelector: '.media-item',
								layoutMode: 'masonry',
								percentPosition: true,
							});
						});

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

	$albumList.on('click', '.media-item', function(e) {
		const $item = $(this);
		if ($item.data('file-type') !== 'video') {
			return;
		}

		// Prevent the row from toggling if the click is on a media item
		e.stopPropagation();

		const embedHtml = decodeURIComponent($item.data('embed-html'));
		if (embedHtml) {
			$lightboxContainer.html(embedHtml);
			$lightbox.fadeIn(300);
		}
	});

	$albumList.on('click', '.delete-album-link', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const albumId = $btn.data('album-id');

		if (confirm('Are you sure? This album and its photos will be hidden from the gallery but kept on the server. You can restore it from the Settings tab.')) {
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_delete_album',
					nonce: adamsonArchive.nonce,
					album_id: albumId
				},
				success: function(response) {
					if (response.success) {
						log(response.data.message, 'success');
						$('#album-' + albumId).next('.media-row').remove();
						$('#album-' + albumId).remove();
					} else {
						logError('Error deleting album.', response.data.message);
					}
				},
				error: function() {
					logError('Error deleting album.', 'A server error occurred.');
				}
			});
		}
	});

	$albumList.on('click', '.delete-media-item', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const mediaId = $btn.data('media-id');
		const isVideo = $btn.closest('.media-item').data('file-type') === 'video';
		
		const confirmMsg = isVideo 
			? 'Are you sure? This will permanently delete the video from YouTube. This action cannot be undone and the video cannot be restored.'
			: 'Are you sure? This photo will be hidden from the gallery but kept on the server. You can restore it from the Settings tab.';

		if (confirm(confirmMsg)) {
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_delete_media_item',
					nonce: adamsonArchive.nonce,
					media_id: mediaId
				},
				success: function(response) {
					if (response.success) {
						log(response.data.message, 'success');
						const $itemToRemove = $btn.closest('.media-item');
						const $mediaRow = $itemToRemove.closest('.media-row');
						const albumId = $mediaRow.prev('.album-row').attr('id').replace('album-', '');
						
						$itemToRemove.closest('.media-container').isotope('remove', $itemToRemove).isotope('layout');
						
						updateAlbumRow(albumId);
					} else {
						logError('Error deleting media.', response.data.message);
					}
				},
				error: function() {
					logError('Error deleting media.', 'A server error occurred.');
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
		updateDashboardCounts();

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
					log(response.data.message, 'success');
					
					// Refresh the album list immediately so newly discovered albums appear right away.
					$albumList.empty();
					currentPage = 1;
					loadAlbums();

					pollQueueStatus(); // Start polling immediately to show scan progress
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

	// Queue Processing Logic
	$queueBtn.on('click', function(e) {
		e.preventDefault();
		if ($queueBtn.prop('disabled')) return;

		$queueBtn.prop('disabled', true).text('Worker Running...');
		const disableDelete = $('#adamson-archive-disable-delete').is(':checked');

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_start_queue_worker',
				nonce: adamsonArchive.nonce,
				disable_delete: disableDelete ? '1' : '0'
			},
			success: function(response) {
				if (response.success) {
					log('Background worker initiated. You can safely close this tab.', 'success');
					pollQueueStatus();
				}
			}
		});
	});

	function pollQueueStatus() {
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_dashboard_counts',
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					const remaining = parseInt(response.data.pending_videos);
					const totalPending = parseInt(response.data.total_pending, 10) || 0;

					// Apply highlight if the count has changed
					if (parseInt($queueCount.text()) !== remaining) {
						$queueCount.text(remaining).parent().css('background-color', '#fff8e5');
						setTimeout(() => { 
							$queueCount.parent().css('transition', 'background-color 2s').css('background-color', ''); 
						}, 2000);
					} else {
						$queueCount.text(remaining);
					}

					// Process all new messages found in the recent buffer
					if (response.data.recent_messages && response.data.recent_messages.length > 0) {
						// Sort ascending to ensure logs appear in chronological order
						response.data.recent_messages.sort((a, b) => a.id - b.id).forEach(function(msg) {
							if (msg.id > lastProcessedId) {
								lastProcessedId = msg.id;
								log(msg.message, 'success');
								loadPendingVideos();
								if (msg.album_id) {
									updateAlbumRow(msg.album_id);
								}
							}
						});
					}

					// Toggle visual worker status
					response.data.worker_active ? $workerStatus.show() : $workerStatus.hide();
					
					if (response.data.worker_active || totalPending > 0) {
						$queueBtn.prop('disabled', true).text('Worker Running...');
						$scanBtn.prop('disabled', true).text('Scanning Albums...');
						setTimeout(pollQueueStatus, 4000); // Poll every 4 seconds
					} else {
						$queueBtn.prop('disabled', remaining === 0).text(remaining === 0 ? 'Queue Complete' : 'Process Upload Queue');
						$scanBtn.prop('disabled', false).text('Scan for New Media');
						
						if (lastProcessedId !== 0) {
							log('All background tasks finished.', 'success');
							// Refresh the album list to show new items
							$albumList.empty();
							currentPage = 1;
							loadAlbums();
						}
					}
				}
			}
		});
	}

	function updateAlbumRow(albumId) {
		let $row = $(`#album-${albumId}`);

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_album_details',
				album_id: albumId,
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					const album = response.data.album;
					const photo_count = parseInt(album.photo_count || 0);
					const video_count = parseInt(album.video_count || 0);
					const pending_count = parseInt(album.pending_video_count || 0);
					const total = photo_count + video_count + pending_count;

					let counts_string = `Images: ${photo_count}, YouTube Videos: ${video_count}`;
					counts_string += pending_count > 0 ? `, Pending Videos: ${pending_count}` : '';

					if ($row.length > 0) {
						// Update existing row
						$row.find('.album-counts').text(`Total: ${total} (${counts_string})`);
					} else {
						// Create and prepend new row
						let playlistCell = '<td>No Videos</td>';
						if (video_count > 0 && album.yt_playlist_id) {
							const playlistUrl = `https://www.youtube.com/playlist?list=${album.yt_playlist_id}`;
							const playlistName = album.album_date ? `${album.album_date} - ${album.display_name}` : album.display_name;
							playlistCell = `<td><a href="${playlistUrl}" target="_blank">${playlistName}</a></td>`;
						}

						const newRow = `<tr id="album-${album.id}" class="album-row" style="background-color: #fff8e5;">
							<th scope="row" class="check-column">
								<button type="button" class="toggle-row" title="Show more details"><span class="screen-reader-text">Show more details</span></button>
							</th>
							<td class="album-name"><strong>${album.display_name}</strong></td>
							<td>${album.album_date || ''}</td>
							<td class="album-counts">Total: ${total} (${counts_string})</td>
							${playlistCell}
						</tr>`;
						$albumList.prepend(newRow);
						// Fade out the highlight after a few seconds
						setTimeout(() => { $(`#album-${album.id}`).css('transition', 'background-color 2s').css('background-color', ''); }, 3000);
					}
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

		if (target === '#settings') {
			loadRemovedMedia();
		}
	});

	function loadRemovedMedia() {
		let $container = $('#adamson-removed-media-list');
		if (!$container.length) {
			return;
		}

		$container.html('<p>Loading removed items...</p>');

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_removed_media',
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					if (response.data.albums.length === 0) {
						$container.html('<p>No removed content found.</p>');
						return;
					}

					let html = '<div class="removed-accordion">';
					response.data.albums.forEach(function(album) {
						const isRemoved = parseInt(album.album_removed) === 1;
						html += `
							<div class="removed-album-group" data-album-id="${album.id}">
								<div class="removed-album-header" style="display: flex; align-items: center; justify-content: space-between; background: #f6f7f7; border: 1px solid #ccd0d4; padding: 10px; cursor: pointer; margin-bottom: 5px;">
									<span>
										<strong>${isRemoved ? '[REMOVED ALBUM]' : '[ACTIVE ALBUM]'}</strong> ${album.display_name} 
										<small>(${album.removed_count} items)</small>
									</span>
									<div class="group-actions">
										<button class="button button-small restore-album-item" data-album-id="${album.id}">Restore</button>
										<button class="button button-small button-link-delete permanent-delete-album" data-album-id="${album.id}">Wipe Permanently</button>
									</div>
								</div>
								<div class="removed-album-details" style="display: none; padding: 10px; border: 1px solid #ccd0d4; border-top: none; margin-bottom: 10px;">
									<p>Loading items...</p>
								</div>
							</div>`;
					});
					html += '</div>';
					$container.html(html);
				}
			},
			error: function() {
				$container.html('<p>Error loading removed content. Please refresh and try again.</p>');
			}
		});
	}

	$(document).on('click', '.removed-album-header', function(e) {
		if ($(e.target).is('button')) return;
		const $group = $(this).closest('.removed-album-group');
		const $details = $group.find('.removed-album-details');
		const albumId = $group.data('album-id');

		$details.slideToggle();

		if (!$group.hasClass('loaded')) {
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_get_removed_media_details',
					album_id: albumId,
					nonce: adamsonArchive.nonce
				},
				success: function(response) {
					if (response.success) {
						let itemsHtml = '<ul style="margin: 0;">';
						response.data.media.forEach(function(item) {
							const canRestore = item.file_type === 'photo';
							itemsHtml += `
								<li style="display: flex; align-items: center; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eee;">
									<span><strong>[${item.file_type.toUpperCase()}]</strong> ${item.filename}</span>
									<div>
										${canRestore ? `<button class="button button-small restore-media-item" data-media-id="${item.id}">Restore</button>` : ''}
										<button class="button button-small button-link-delete permanent-delete-media" data-media-id="${item.id}">Delete Forever</button>
									</div>
								</li>`;
						});
						itemsHtml += '</ul>';
						$details.html(itemsHtml);
						$group.addClass('loaded');
					}
				}
			});
		}
	});

	$(document).on('click', '.permanent-delete-media', function() {
		const mediaId = $(this).data('media-id');
		if (confirm('Are you sure? This will permanently delete the file from the server. This cannot be undone.')) {
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_permanent_delete_media',
					media_id: mediaId,
					nonce: adamsonArchive.nonce
				},
				success: function(response) {
					if (response.success) {
						log(response.data.message, 'success');
						loadRemovedMedia();
					}
				}
			});
		}
	});

	$(document).on('click', '.permanent-delete-album', function() {
		const albumId = $(this).data('album-id');
		if (confirm('WARNING: This will permanently delete the entire folder from the server and all associated YouTube content. This action is IRREVERSIBLE.')) {
			$.ajax({
				url: adamsonArchive.ajax_url,
				type: 'POST',
				data: {
					action: 'adamson_archive_permanent_delete_album',
					album_id: albumId,
					nonce: adamsonArchive.nonce
				},
				success: function(response) {
					if (response.success) {
						log(response.data.message, 'success');
						loadRemovedMedia();
					}
				}
			});
		}
	});

	$(document).on('click', '.restore-media-item', function() {
		const mediaId = $(this).data('media-id');
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_restore_media_item',
				media_id: mediaId,
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					log(response.data.message, 'success');
					loadRemovedMedia();
					currentPage = 1;
					$albumList.empty();
					loadAlbums();
				}
			}
		});
	});

	$(document).on('click', '.restore-album-item', function() {
		const albumId = $(this).data('album-id');
		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_restore_album',
				album_id: albumId,
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					log(response.data.message, 'success');
					loadRemovedMedia();
					currentPage = 1;
					$albumList.empty();
					loadAlbums();
				}
			}
		});
	});

	// Database Setup Button Handler
	$(document).on('click', '#adamson-archive-setup-db', function(e) {
		e.preventDefault();
		const $btn = $(this);
		$btn.prop('disabled', true).text('Verifying...');

		$.ajax({
			url: adamsonArchive.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_setup_database',
				nonce: adamsonArchive.nonce
			},
			success: function(response) {
				if (response.success) {
					log(response.data.message, 'success');
					loadRemovedMedia();
				}
				$btn.prop('disabled', false).text('Create / Verify Database Tables');
			}
		});
	});

	// Check if we are starting on the settings tab
	if (window.location.hash === '#settings' || $('.nav-tab-active').attr('href') === '#settings') {
		loadRemovedMedia();
	}

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
