jQuery(document).ready(function($) {
	const $container = $('.adamson-archive-list');
	const $loadMoreBtn = $('#adamson-archive-load-more');
	const $loading = $('#adamson-archive-loading');
	let currentPage = 1;
	let isLoading = false;

	// Initial Load
	if ($container.length) {
		loadAlbums(currentPage);
	}

	// Load More Click
	$loadMoreBtn.on('click', function() {
		if (!isLoading) {
			currentPage++;
			loadAlbums(currentPage);
		}
	});

	// Album Click (Accordion)
	$container.on('click', '.adamson-album-header', function() {
		const $album = $(this).closest('.adamson-album');
		const $body = $album.find('.adamson-album-body');
		const albumId = $album.data('id');

		// Toggle visibility
		$body.slideToggle();
		$album.toggleClass('open');

		// Load media if not already loaded
		if (!$album.hasClass('loaded')) {
			loadMedia(albumId, $body);
			$album.addClass('loaded');
		}
	});

	function loadAlbums(page) {
		isLoading = true;
		$loading.show();
		$loadMoreBtn.hide();

		$.ajax({
			url: adamsonArchiveFrontend.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_load_albums',
				page: page
			},
			success: function(response) {
				if (response.success) {
					renderAlbums(response.data.albums);
					if (response.data.has_more) {
						$loadMoreBtn.show();
					}
				}
			},
			complete: function() {
				isLoading = false;
				$loading.hide();
			}
		});
	}

	function renderAlbums(albums) {
		albums.forEach(function(album) {
			const html = `
				<div class="adamson-album" data-id="${album.id}">
					<div class="adamson-album-header">
						<span class="album-date">${album.album_date || 'Unknown Date'}</span>
						<span class="album-title">${album.display_name}</span>
					</div>
					<div class="adamson-album-body" style="display:none;">
						<div class="media-grid">Loading media...</div>
					</div>
				</div>
			`;
			$container.append(html);
		});
	}

	function loadMedia(albumId, $targetContainer) {
		$.ajax({
			url: adamsonArchiveFrontend.ajax_url,
			type: 'POST',
			data: {
				action: 'adamson_archive_get_album_media',
				album_id: albumId
			},
			success: function(response) {
				if (response.success) {
					let mediaHtml = '';
					response.data.media.forEach(function(item) {
						if (item.file_type === 'photo') {
							mediaHtml += `<div class="media-item"><img src="${item.file_url}" loading="lazy" alt="${item.filename}"></div>`;
						} else {
							// Check for yt_video_id here in the future
							mediaHtml += `<div class="media-item video-item"><video controls src="${item.file_url}"></video></div>`;
						}
					});
					
					if (mediaHtml === '') mediaHtml = '<p>No media found.</p>';
					$targetContainer.find('.media-grid').html(mediaHtml);
				}
			}
		});
	}
});