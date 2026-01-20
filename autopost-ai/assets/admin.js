jQuery(document).ready(function ($) {
	// Restore active tab from localStorage or from URL param
	const urlParams = new URLSearchParams(window.location.search);
	const urlTab = urlParams.get('autopost_ai_active_tab');

	const savedTab = localStorage.getItem('autopost_active_tab') || urlTab;

	if (savedTab) {
	    $('.nav-tab').removeClass('nav-tab-active');
	    $(`.nav-tab[data-tab="${savedTab}"]`).addClass('nav-tab-active');

	    $('.autopost-tab-content').hide();
	    $(`#${savedTab}`).show();

	    localStorage.removeItem('autopost_active_tab');
	}
	
    // Toggle OpenAI Instructions accordion
    $('.autopost-openai-instructions-header').on('click', function () {
        $(this).next('.autopost-openai-instructions-body').slideToggle(200);
        $(this).find('.autopost-accordion-arrow').toggleClass('rotated');
    });

	// Tab switch logic (with refresh for Post Creation Queue tab)
	$('.nav-tab').on('click', function (e) {
	    e.preventDefault();
	    const target = $(this).data('tab');

	    if (target === 'autopost-queue') {
	        localStorage.setItem('autopost_active_tab', target);
	        location.reload();
	        return;
	    }

	    localStorage.setItem('autopost_active_tab', target);
	    $('.nav-tab').removeClass('nav-tab-active');
	    $(this).addClass('nav-tab-active');

	    $('.autopost-tab-content').hide();
	    $('#' + target).show();
	});

    // Accordion toggles for Post Creation
    $(document).on('click', '.autopost-accordion-header', function () {
        const $header = $(this);
        const $body = $header.next('.autopost-accordion-body');
        $body.slideToggle(200);
        $header.find('.autopost-accordion-arrow').toggleClass('rotated');
    });

	// Refresh Suggestions button
	$('#autopost-ai-refresh').on('click', function () {
	    const category = $('#autopost-ai-category').val();
	    if (!category) return alert('Please select a category.');

	    $('#autopost-ai-loading').css('display', 'flex');
	    $('#autopost-ai-suggestions').empty();

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_get_topics',
	        nonce: AutoPostAI.nonce,
	        category: category
	    }, function (response) {
	        $('#autopost-ai-loading').hide();

	        if (response.success) {
	            const suggestions = response.data;
	            $('#autopost-ai-suggestions-heading').show();
	            suggestions.forEach(topic => {
	                $('#autopost-ai-suggestions').append(
	                    `<li><button class="button add-topic" data-topic="${encodeURIComponent(topic)}" style="margin-right:10px;">Add</button>${topic}</li>`
	                );
	            });
	        } else {
	            alert('Failed to refresh suggestions.');
	        }
	    });
	});

	// Suggest Topics button
	$('#autopost-ai-suggest').on('click', function () {
	    const category = $('#autopost-ai-category').val();
	    if (!category) return alert('Please select a category.');

	    $('#autopost-ai-loading').css('display', 'flex');
	    $('#autopost-ai-suggestions').empty();

	    // Show heading and refresh button
	    $('#autopost-ai-suggestions-heading').show();
	    $('#autopost-ai-refresh').show();

	    // Disable the suggest button until new category selected
	    $(this).prop('disabled', true);

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_get_topics',
	        nonce: AutoPostAI.nonce,
	        category: category
	    }, function (response) {
	        $('#autopost-ai-loading').hide();

	        if (response.success) {
	            const suggestions = response.data;
	            suggestions.forEach(topic => {
	                $('#autopost-ai-suggestions').append(
	                    `<li><button class="button add-topic" data-topic="${encodeURIComponent(topic)}" style="margin-right:10px;">Add</button>${topic}</li>`
	                );
	            });
	        } else {
	            alert('Failed to get topic suggestions. Make sure you have entered your OpenAI API key on the OpenAI/ChatGPT Setting tab!');
	        }
	    });
	});

	// When category is changed, reset suggest button and hide suggestions
	$('#autopost-ai-category').on('change', function () {
	    $('#autopost-ai-suggest').prop('disabled', false);
	    $('#autopost-ai-refresh').hide();
	    $('#autopost-ai-suggestions-heading').hide();
	    $('#autopost-ai-suggestions').empty();
	});

	// Save queue for post creation
	$('#autopost-ai-save-queue').on('click', function () {
	    const postQueue = [];

	    $('#autopost-ai-queue tbody tr').each(function () {
	        const category = $(this).find('td[data-category]').data('category');
	        const categoryLabel = $(this).find('td[data-category]').data('category-label');
	        const topic = $(this).find('td[data-topic]').data('topic') || $(this).find('td[data-topic]').text();

	        postQueue.push({
	            category,
	            categoryLabel,
	            topic
	        });
	    });

	    if (postQueue.length === 0) {
	        return alert('No topics in queue to save.');
	    }

	    const $saveButton = $(this);
	    const $existingStatus = $('.autopost-spinner-status');

	    if ($existingStatus.length) {
	        $existingStatus.remove();
	    }

	    const $status = $('<span class="autopost-spinner-status" style="margin-left:10px;"></span>');
	    $saveButton.after($status);

	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_save_queue',
	        nonce: AutoPostAI.nonce,
	        queue: postQueue
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green;">✅ Added to Post Creation Queue!</span>');
	            $('#autopost-ai-queue tbody').empty();

	            // Pause to show success message, then hide the section
	            setTimeout(function () {
	                const $wrapper = $('#autopost-selected-topics');
	                if ($wrapper.is(':visible')) {
	                    $wrapper.stop(true, true).slideUp(200);
	                }
	            }, 1500);

	        } else {
	            $status.html('<span style="color:red;">❌ Failed to save.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

    // Default to Settings tab if API key is missing
    if (!AutoPostAI.has_api_key) {
        $('[data-tab="autopost-generate"]').removeClass('nav-tab-active');
        $('.autopost-tab-content').hide();

        $('[data-tab="autopost-settings"]').addClass('nav-tab-active');
        $('#autopost-settings').show();
    }

    // Toggle CTA fields on dropdown change
    $('#autopost_cta_enabled').on('change', function () {
        if ($(this).val() === 'yes') {
            $('#autopost_cta_fields, .autopost_cta_link_row').show();
        } else {
            $('#autopost_cta_fields, .autopost_cta_link_row').hide();
        }
    });

    // AJAX save post settings
    $('#autopost-post-settings-form').on('submit', function (e) {
        e.preventDefault();

        const $btn = $('#autopost-save-button');
        const $status = $('#autopost-save-status');

        $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

		const data = {
		    action: 'autopost_ai_save_post_settings',
		    nonce: AutoPostAI.nonce,
		    time_frame_days: $('#autopost_time_frame_days').val(),
		    post_status: $('#autopost_post_status').val(),
		    cta_enabled: $('#autopost_cta_enabled').val(),
		    cta_text: $('#autopost_cta_text').val(),
		    cta_link: $('#autopost_cta_link').val(),
		    notification_email: $('#autopost_notification_email').val(),
		    hide_title: $('#autopost_hide_title').is(':checked') ? 'yes' : 'no'  // ✅ Add this line
		};

        $.post(AutoPostAI.ajax_url, data, function (response) {
            if (response.success) {
                $status.html('<span style="color:green;">✅ Saved!</span>');
            } else {
                $status.html('<span style="color:red;">❌ Failed to save.</span>');
            }
        }).fail(function () {
            $status.html('<span style="color:red;">❌ AJAX error.</span>');
        });
    });

	// Restore active tab after reload
	(function () {
	    const params = new URLSearchParams(window.location.search);
	    let savedTab = localStorage.getItem('autopost_active_tab') || params.get('autopost_ai_active_tab');

	    if (savedTab) {
	        $('.nav-tab').removeClass('nav-tab-active');
	        $(`[data-tab="${savedTab}"]`).addClass('nav-tab-active');

	        $('.autopost-tab-content').hide();
	        $('#' + savedTab).show();

	        localStorage.removeItem('autopost_active_tab');
	    }
	})();

	// Save API Key via AJAX
	$('#autopost-save-api-key').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#autopost-api-key-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_save_api_key',
	        nonce: AutoPostAI.nonce,
	        api_key: $('#autopost_ai_api_key').val()
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green; margin-left:5px;">✅ Saved!</span>');
	        } else {
	            $status.html('<span style="color:red;">❌ Failed to save.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

	// Save Prompt via AJAX
	$('#autopost-save-prompt').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#autopost-prompt-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_save_prompt',
	        nonce: AutoPostAI.nonce,
	        prompt: $('#autopost_ai_prompt').val()
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green; margin-left:9px">✅ Saved!</span>');
	        } else {
	            $status.html('<span style="color:red;">❌ Failed to save.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

	// Load Post Creation Queue and Created Posts separately
	let autopostQueueOffset = 0;
	let autopostCreatedOffset = 0;
	const autopostQueueLimit = 10;

	function loadQueueOrCreated(type) {
	    const action = type === 'created' ? 'autopost_ai_load_created_posts' : 'autopost_ai_load_queue_entries';

	    $.post(AutoPostAI.ajax_url, {
	        action: action,
	        nonce: AutoPostAI.nonce,
	        offset: type === 'created' ? autopostCreatedOffset : autopostQueueOffset,
	        limit: autopostQueueLimit
	    }, function (response) {
	        if (response.success) {
	            const html = response.data.rows || '';
	            const rowCount = (html.match(/<tr/g) || []).length;

	            if (type === 'created') {
	                $('#autopost-created-table tbody').append(html);
	                autopostCreatedOffset += autopostQueueLimit;

	                if (!response.data.has_more || rowCount < autopostQueueLimit) {
	                    $('#autopost-load-more-created').hide();
	                }
	            } else {
	                $('#autopost-queue-table tbody').append(html);
	                autopostQueueOffset += autopostQueueLimit;

	                if (!response.data.has_more || rowCount < autopostQueueLimit) {
	                    $('#autopost-load-more').hide();
	                }
	            }
	        } else {
	            alert('Failed to load entries.');
	        }
	    });
	}

	$('#autopost-load-more').on('click', function () {
	    loadQueueOrCreated('pending');
	});

	$('#autopost-load-more-created').on('click', function () {
	    loadQueueOrCreated('created');
	});

	setTimeout(function () {
	    const $queueTab = $('#autopost-queue');
	    if ($queueTab.is(':visible') && autopostQueueOffset === 0) {
	        loadQueueOrCreated('pending');
	    }
	}, 100);
	
	setTimeout(function () {
	    const $queueTab = $('#autopost-queue');
	    if ($queueTab.is(':visible') && autopostCreatedOffset === 0) {
	        loadQueueOrCreated('created');
	    }
	}, 150);

	$(document).on('click', '.autopost-create-now', function () {
	    const $button = $(this);
	    const id = $button.data('id');

	    if (!confirm('Create a blog post for this topic now? Please be patient! This can take a minute or so. You’ll get an alert when this process is finished.')) return;

	    $button.prop('disabled', true).find('.button-text').text('Creating...');
	    $button.next('.autopost-create-spinner').addClass('is-active').show();
	    $('.autopost-delete[data-id="' + id + '"]').hide();

	    $.post(ajaxurl, {
	        action: 'autopost_ai_create_post_now',
	        id: id
	    }, function (res) {
	        if (res.success) {
	            alert('Post created successfully.');
	            localStorage.setItem('autopost_active_tab', 'autopost-queue');
	            location.reload();
	        } else { 
	            alert(res.data || 'Failed to create post. It may be that ChatGPT is having issues. If this problem persists, try again later.');
	            $button.prop('disabled', false).find('.button-text').text('Create Now');
	            $button.next('.autopost-create-spinner').removeClass('is-active').hide();
	            $('.autopost-delete[data-id="' + id + '"]').show();
	        }
	    }).fail(function (jqXHR, textStatus, errorThrown) {
	        // Catch server 500s or network errors
	        try { console.error('Create Now AJAX failed:', textStatus, errorThrown, jqXHR && jqXHR.responseText); } catch (e) {}
	        alert('Oops! There was a hiccup. The post was most likely created. Click OK to refresh and check. It may be that ChatGPT is having issues. If the post wasn\'t created and this problem persists, try again later.');
	        localStorage.setItem('autopost_active_tab', 'autopost-queue');
	        location.reload();
	    });
	});

	$(document).on('click', '.autopost-accordion-heading', function () {
	    const $header = $(this);
	    const $arrow = $header.find('.autopost-accordion-arrow');
	    $header.toggleClass('active');
	    $header.next('.autopost-accordion-body').slideToggle(200);

	    // Toggle arrow direction
	    if ($header.hasClass('active')) {
	        $arrow.text('▲');
	    } else {
	        $arrow.text('▼');
	    }
	});
	
	$(document).on('click', '.autopost-delete', function () {
	    const $button = $(this);
	    const id = $button.data('id');

	    if (!confirm('Are you sure you want to delete this topic from the queue?')) return;

	    $button.prop('disabled', true).text('Deleting...');

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_delete_topic',
	        nonce: AutoPostAI.nonce,
	        id: id
	    }, function (res) {
	        if (res.success) {
	            $button.closest('tr').fadeOut(300, function () {
	                $(this).remove();
	            });
	        } else {
	            alert(res.data || 'Failed to delete topic.');
	            $button.prop('disabled', false).text('Delete');
	        }
	    });
	});
	
	// Remove orphaned topic from "Created Posts" when post is not found, then reload into Post Creation tab
	$(document).on('click', '.autopost-remove-notfound', function () {
	    const $btn = $(this);
	    const id = $btn.data('id');
	    if (!id) return;

	    if (!confirm('Remove this topic from AutoPost AI?')) return;

	    $btn.prop('disabled', true).text('Removing...');

	    $.post(AutoPostAI.ajax_url, {
	        action: 'autopost_ai_delete_topic',
	        nonce: AutoPostAI.nonce,
	        id: id
	    }, function (res) {
	        if (res.success) {
	            // Ensure we return to the Post Creation tab on reload
	            localStorage.setItem('autopost_active_tab', 'autopost-queue');
	            location.reload();
	        } else {
	            alert(res.data || 'Failed to remove topic.');
	            $btn.prop('disabled', false).text('Remove');
	        }
	    }).fail(function () {
	        alert('AJAX error while removing topic.');
	        $btn.prop('disabled', false).text('Remove');
	    });
	});
	
// 👇 Show/hide "Selected Topics" section based on row count, with slide animation
function updateQueueVisibility() {
    const $wrapper = $('#autopost-selected-topics');
    const hasRows = $('#autopost-ai-queue tbody tr').length > 0;

    if (hasRows) {
        if (!$wrapper.is(':visible')) $wrapper.stop(true, true).slideDown(200);
    } else {
        if ($wrapper.is(':visible')) $wrapper.stop(true, true).slideUp(200);
    }
}

	// Call once on DOM ready (in case of leftover rows from old session)
	updateQueueVisibility();

	// Add topic to the selected queue and update visibility
	$(document).on('click', '.add-topic', function () {
	    const $button = $(this);
	    const $li = $button.closest('li');
	    const rawTopic = decodeURIComponent($button.data('topic'));
	    const category = $('#autopost-ai-category').val();
	    const categoryLabel = $('#autopost-ai-category option:selected').text();

	    const newRow = `
	        <tr>
	            <td><button class="button remove-topic" data-restore-topic="${encodeURIComponent(rawTopic)}">Remove</button></td>
	            <td data-category="${category}" data-category-label="${categoryLabel}">${categoryLabel}</td>
	            <td data-topic="${rawTopic}">${rawTopic}</td>
	        </tr>
	    `;

	    $('#autopost-ai-queue tbody').append(newRow);

	    // Hide the original suggestion
	    $li.hide();

	    setTimeout(() => {
	        updateQueueVisibility();
	    }, 10);
	});

	// Trigger after removing a topic
	$(document).on('click', '.remove-topic', function () {
	    const $button = $(this);
	    const rawTopic = decodeURIComponent($button.data('restore-topic') || '');
	    
	    // Unhide matching suggestion
	    if (rawTopic) {
	        $('#autopost-ai-suggestions li').each(function () {
	            const $li = $(this);
	            const $addBtn = $li.find('.add-topic');
	            if (decodeURIComponent($addBtn.data('topic')) === rawTopic) {
	                $li.show();
	            }
	        });
	    }

	    $button.closest('tr').fadeOut(150, function () {
	        $(this).remove();
	        updateQueueVisibility();
	    });
	});

	function checkLoadMoreVisibility() {
	    if ($('#autopost-queue-table tbody tr').length < autopostQueueLimit) {
	        $('#autopost-load-more').hide();
	    }
	    if ($('#autopost-created-table tbody tr').length < autopostQueueLimit) {
	        $('#autopost-load-more-created').hide();
	    }
	}
	
	document.addEventListener('DOMContentLoaded', function () {
	    const manualForm = document.getElementById('autopost-ai-manual-form');
	    if (manualForm) {
	        manualForm.addEventListener('submit', function () {
	            const activeTab = localStorage.getItem('autopost_active_tab') || 'autopost-queue';
	            const url = new URL(manualForm.action);
	            url.searchParams.set('autopost_ai_active_tab', activeTab);
	            manualForm.action = url.toString();
	        });
	    }
	});
	
	$(document).ajaxError(function (event, jqXHR, settings, thrownError) {
	    try {
	        // settings.data is a URL-encoded string for $.post
	        if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=autopost_ai_create_post_now') !== -1) {
	            alert('Oops! There was a hiccup. The post was most likely created. Click OK to refresh and check.');
	            localStorage.setItem('autopost_active_tab', 'autopost-queue');
	            location.reload();
	        }
	    } catch (e) {
	        // If anything goes wrong parsing, fail quietly—this is just a fallback.
	    }
	});
});

