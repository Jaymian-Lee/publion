jQuery(document).ready(function ($) {
	// Restore active tab from localStorage or from URL param
	const urlParams = new URLSearchParams(window.location.search);
	const urlTab = urlParams.get('publion_active_tab');

	const savedTab = localStorage.getItem('publion_active_tab') || urlTab;

	if (savedTab) {
	    $('.nav-tab').removeClass('nav-tab-active');
	    $(`.nav-tab[data-tab="${savedTab}"]`).addClass('nav-tab-active');

	    $('.publion-tab-content').hide();
	    $(`#${savedTab}`).show();

	    localStorage.removeItem('publion_active_tab');
	}

	function showNotice(type, message) {
		const $notice = $('#publion-global-notice');
		if (!$notice.length) return;
		$notice.removeClass('is-success is-warning is-error').addClass('is-' + type).empty();
		$notice.append($('<strong>', { text: type === 'error' ? 'Actie nodig' : type === 'warning' ? 'Let op' : 'Gelukt' }));
		$notice.append($('<span>', { text: message }));
		$notice.stop(true, true).slideDown(150);
		if (type === 'success') setTimeout(function () { $notice.fadeOut(250); }, 4500);
	}

	function responseMessage(response, fallback) {
		if (response && response.data) {
			if (typeof response.data === 'string') return response.data;
			if (response.data.message) return response.data.message;
		}
		return fallback;
	}

	function openPublionTab(target) {
		if (!target || !$('#' + target).length) return;
		if (target === 'publion-queue') {
			localStorage.setItem('publion_active_tab', target);
			location.reload();
			return;
		}
		localStorage.setItem('publion_active_tab', target);
		$('.nav-tab').removeClass('nav-tab-active');
		$('.nav-tab[data-tab="' + target + '"]').addClass('nav-tab-active');
		$('.publion-tab-content').hide();
		$('#' + target).fadeIn(150);
	}

	let qualityModalTrigger = null;
	function closeQualityModal() {
		const $modal = $('#publion-quality-modal');
		if (!$modal.length || $modal.prop('hidden')) return;
		$modal.prop('hidden', true);
		$('body').removeClass('publion-modal-open');
		if (qualityModalTrigger) qualityModalTrigger.focus();
	}

	function openQualityModal(trigger) {
		const $modal = $('#publion-quality-modal');
		if (!$modal.length) return;
		qualityModalTrigger = trigger || document.activeElement;
		$modal.prop('hidden', false);
		$('body').addClass('publion-modal-open');
		$modal.find('.publion-modal-dialog').trigger('focus');
	}
	
    // Toggle OpenAI Instructions accordion
    $('.publion-openai-instructions-header').on('click', function () {
        $(this).next('.publion-openai-instructions-body').slideToggle(200);
        $(this).find('.publion-accordion-arrow').toggleClass('rotated');
    });

	// Tab switch logic (with refresh for Post Creation Queue tab)
	$('.nav-tab').on('click', function (e) {
	    e.preventDefault();
	    openPublionTab($(this).data('tab'));
	});

	$(document).on('click', '[data-publion-tab]', function () {
		openPublionTab($(this).data('publion-tab'));
	});

	$('#publion-open-quality-modal').on('click', function () {
		openQualityModal(this);
	});

	$(document).on('click', '[data-publion-modal-close]', closeQualityModal);
	$(document).on('click', '#publion-quality-modal [data-publion-tab]', closeQualityModal);
	$(document).on('keydown', function (event) {
		const $modal = $('#publion-quality-modal');
		if (!$modal.length || $modal.prop('hidden')) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			closeQualityModal();
			return;
		}
		if (event.key !== 'Tab') return;
		const $focusable = $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
		if (!$focusable.length) return;
		const first = $focusable.first()[0];
		const last = $focusable.last()[0];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

    // Accordion toggles for Post Creation
    $(document).on('click', '.publion-accordion-header', function () {
        const $header = $(this);
        const $body = $header.next('.publion-accordion-body');
        $body.slideToggle(200);
        $header.find('.publion-accordion-arrow').toggleClass('rotated');
    });

	// Refresh Suggestions button
	function renderSuggestions(suggestions) {
		const $list = $('#publion-suggestions').empty();
		suggestions.forEach(function (suggestion) {
			const title = suggestion.title || suggestion;
			const brief = {
				focus_keyword: suggestion.focus_keyword || title,
				search_intent: suggestion.search_intent || 'informatief',
				angle: suggestion.angle || '',
				faq_questions: Array.isArray(suggestion.faq_questions) ? suggestion.faq_questions : []
			};
			const $li = $('<li>', { class: 'publion-suggestion' }).data('topic', title).data('seo-brief', brief);
			const $button = $('<button>', { type: 'button', class: 'button button-primary add-topic', text: 'Toevoegen' });
			const $content = $('<div>', { class: 'publion-suggestion-content' });
			$content.append($('<strong>', { text: title }));
			const $meta = $('<div>', { class: 'publion-seo-meta' });
			$meta.append($('<span>', { class: 'publion-seo-tag', text: 'Focus: ' + brief.focus_keyword }));
			$meta.append($('<span>', { class: 'publion-seo-tag', text: 'Intentie: ' + brief.search_intent }));
			if (brief.angle) $content.append($('<p>', { class: 'publion-suggestion-angle', text: brief.angle }));
			$content.append($meta);
			if (brief.faq_questions.length) {
				$content.append($('<p>', { class: 'publion-faq-preview', text: 'FAQ: ' + brief.faq_questions.join(' · ') }));
			}
			$li.append($button, $content);
			$list.append($li);
		});
	}

	$('#publion-refresh').on('click', function () {
	    const category = $('#publion-category').val();
	    if (!category) {
	        showNotice('warning', 'Selecteer eerst een categorie om relevante onderwerpvoorstellen te maken.');
	        return;
	    }

	    $('#publion-loading').css('display', 'flex');
	    $('#publion-suggestions').empty();

	    $.post(Publion.ajax_url, {
	        action: 'publion_get_topics',
	        nonce: Publion.nonce,
	        category: category
	    }, function (response) {
	        $('#publion-loading').hide();

	        if (response.success) {
	            $('#publion-suggestions-heading').show();
	            renderSuggestions(response.data || []);
	        } else {
	            showNotice('error', responseMessage(response, 'Vernieuwen van voorstellen mislukt. Controleer je API-sleutel en probeer opnieuw.'));
	        }
	    }).fail(function () {
	        $('#publion-loading').hide();
	        showNotice('error', 'De verbinding met WordPress of OpenAI is onderbroken. Controleer je internetverbinding en probeer opnieuw.');
	    });
	});

	// Suggest Topics button
	$('#publion-suggest').on('click', function () {
	    const category = $('#publion-category').val();
	    if (!category) {
	        showNotice('warning', 'Selecteer eerst een categorie om relevante onderwerpvoorstellen te maken.');
	        return;
	    }

	    $('#publion-loading').css('display', 'flex');
	    $('#publion-suggestions').empty();

	    // Show heading and refresh button
	    $('#publion-suggestions-heading').show();
	    $('#publion-refresh').show();

	    // Disable the suggest button until new category selected
	    $(this).prop('disabled', true);

	    $.post(Publion.ajax_url, {
	        action: 'publion_get_topics',
	        nonce: Publion.nonce,
	        category: category
	    }, function (response) {
	        $('#publion-loading').hide();

	        if (response.success) {
	            renderSuggestions(response.data || []);
	        } else {
	            $('#publion-suggest').prop('disabled', false);
	            showNotice('error', responseMessage(response, 'Kon geen onderwerpvoorstellen ophalen. Controleer je API-sleutel en model in AI-instellingen.'));
	        }
	    }).fail(function () {
	        $('#publion-loading').hide();
	        $('#publion-suggest').prop('disabled', false);
	        showNotice('error', 'De aanvraag kon niet worden verstuurd. Controleer de verbinding en probeer opnieuw.');
	    });
	});

	// When category is changed, reset suggest button and hide suggestions
	$('#publion-category').on('change', function () {
	    $('#publion-suggest').prop('disabled', false);
	    $('#publion-refresh').hide();
	    $('#publion-suggestions-heading').hide();
	    $('#publion-suggestions').empty();
	});

	// Save queue for post creation
	$('#publion-save-queue').on('click', function () {
	    const postQueue = [];

	    $('#publion-ai-queue tbody tr').each(function () {
	        const category = $(this).find('td[data-category]').data('category');
	        const categoryLabel = $(this).find('td[data-category]').data('category-label');
	        const topic = $(this).find('td[data-topic]').data('topic') || $(this).find('td[data-topic]').text();
	        const seoBrief = $(this).data('seo-brief') || {};

	        postQueue.push({
	            category,
	            categoryLabel,
	            topic,
	            focusKeyword: seoBrief.focus_keyword || topic,
	            seoBrief: seoBrief
	        });
	    });

	    if (postQueue.length === 0) {
	        showNotice('warning', 'Kies eerst minstens één onderwerp voordat je de wachtrij opslaat.');
	        return;
	    }

	    const $saveButton = $(this);
	    const $existingStatus = $('.publion-spinner-status');

	    if ($existingStatus.length) {
	        $existingStatus.remove();
	    }

	    const $status = $('<span class="publion-spinner-status" style="margin-left:10px;"></span>');
	    $saveButton.after($status);

	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_queue',
	        nonce: Publion.nonce,
	        queue: postQueue
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green;">✅ Toegevoegd aan de wachtrij voor postcreatie!</span>');
	            showNotice('success', 'De geselecteerde onderwerpen staan nu in de wachtrij.');
	            $('#publion-ai-queue tbody').empty();

	            // Pause to show success message, then hide the section
	            setTimeout(function () {
	                const $wrapper = $('#publion-selected-topics');
	                if ($wrapper.is(':visible')) {
	                    $wrapper.stop(true, true).slideUp(200);
	                }
	            }, 1500);

	        } else {
	            $status.html('<span style="color:red;">❌ Opslaan mislukt.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

    // Default to Settings tab if API key is missing
    if (!Publion.has_api_key) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('.publion-tab-content').hide();

        $('[data-tab="publion-settings"]').addClass('nav-tab-active');
        $('#publion-settings').show();
        showNotice('warning', 'Voeg een OpenAI API-sleutel toe om met onderwerpen, artikelen en afbeeldingen te werken.');
    }

    // Toggle CTA fields on dropdown change
    $('#publion_cta_enabled').on('change', function () {
        if ($(this).val() === 'yes') {
            $('#publion_cta_fields, .publion_cta_link_row').show();
        } else {
            $('#publion_cta_fields, .publion_cta_link_row').hide();
        }
    });

	function toggleRefinedStyleControls() {
		$('.publion-refined-style-control').toggle($('#publion_article_style_mode').val() === 'refined');
	}

	$('#publion_article_style_mode').on('change', toggleRefinedStyleControls);
	toggleRefinedStyleControls();

    // Auto-save Rank Math toggle to prevent accidental loss on refresh.
    $('#publion_rank_math_integration').on('change', function () {
        $('#publion-post-settings-form').trigger('submit');
    });


    // AJAX save post settings
    $('#publion-post-settings-form').on('submit', function (e) {
        e.preventDefault();

        const $btn = $('#publion-save-button');
        const $status = $('#publion-save-status');

        $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

		const data = {
		    action: 'publion_save_post_settings',
		    nonce: Publion.nonce,
		    time_frame_days: $('#publion_time_frame_days').val(),
		    post_creation_time: $('#publion_post_creation_time').val(),
		    post_status: $('#publion_post_status').val(),
		    default_post_author: $('#publion_default_post_author').val(),
		    cta_enabled: $('#publion_cta_enabled').val(),
		    cta_text: $('#publion_cta_text').val(),
		    cta_link: $('#publion_cta_link').val(),
		    notification_email: $('#publion_notification_email').val(),
		    hide_title: $('#publion_hide_title').is(':checked') ? 'yes' : 'no',
		    auto_daily_topic: $('#publion_auto_daily_topic').is(':checked') ? 'yes' : 'no',
		    daily_topic_time: $('#publion_daily_topic_time').val(),
		    daily_topic_interval_days: $('#publion_daily_topic_interval_days').val(),
		    preferred_external_domain: $('#publion_preferred_external_domain').val(),
		    preferred_external_urls: $('#publion_preferred_external_urls').val(),
		    rank_math_integration: $('#publion_rank_math_integration').is(':checked') ? 'yes' : 'no',
		    structured_data: $('#publion_structured_data').is(':checked') ? 'yes' : 'no',
		    image_border_radius: $('#publion_image_border_radius').val(),
		    article_style_mode: $('#publion_article_style_mode').val(),
		    content_accent_color: $('#publion_content_accent_color').val(),
		    content_max_width: $('#publion_content_max_width').val(),
		    custom_article_css: $('#publion_custom_article_css').val(),
		    search_console_url: $('#publion_search_console_url').val(),
		    ga4_url: $('#publion_ga4_url').val()
		};

        $.post(Publion.ajax_url, data, function (response) {
            if (response.success) {
                $status.html('<span style="color:green;">✅ Opgeslagen!</span>');
                if (response.data && response.data.next_daily_topic !== undefined) {
                    const nextLabel = response.data.next_daily_topic || 'Niet gepland';
                    $('#publion-next-daily-topic').text(nextLabel);
                }
            } else {
                $status.html('<span style="color:red;">❌ Opslaan mislukt.</span>');
            }
        }).fail(function () {
            $status.html('<span style="color:red;">❌ AJAX error.</span>');
        });
    });

	// Restore active tab after reload
	(function () {
	    const params = new URLSearchParams(window.location.search);
	    let savedTab = localStorage.getItem('publion_active_tab') || params.get('publion_active_tab');

	    if (savedTab) {
	        $('.nav-tab').removeClass('nav-tab-active');
	        $(`[data-tab="${savedTab}"]`).addClass('nav-tab-active');

	        $('.publion-tab-content').hide();
	        $('#' + savedTab).show();

	        localStorage.removeItem('publion_active_tab');
	    }
	})();

	// Save API Key via AJAX
	$('#publion-save-api-key').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#publion-api-key-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_api_key',
	        nonce: Publion.nonce,
	        api_key: $('#publion_api_key').val()
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green; margin-left:5px;">✅ Opgeslagen!</span>');
	        } else {
	            $status.html('<span style="color:red;">❌ Opslaan mislukt.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

	// Save Model via AJAX
	$('#publion-save-model').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#publion-model-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_model',
	        nonce: Publion.nonce,
	        model: $('#publion_openai_model').val()
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green; margin-left:5px;">✅ Opgeslagen!</span>');
	        } else {
	            $status.html('<span style="color:red;">❌ Opslaan mislukt.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

	// Save Prompt via AJAX
	$('#publion-save-prompt').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#publion-prompt-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_prompt',
	        nonce: Publion.nonce,
	        prompt: $('#publion_prompt').val()
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green; margin-left:9px">✅ Opgeslagen!</span>');
	        } else {
	            $status.html('<span style="color:red;">❌ Opslaan mislukt.</span>');
	        }
	    }).fail(function () {
	        $status.html('<span style="color:red;">❌ AJAX error.</span>');
	    });
	});

	// Load Post Creation Queue and Created Posts separately
	let publionQueueOffset = 0;
	let publionCreatedOffset = 0;
	const publionQueueLimit = 10;

	function loadQueueOrCreated(type) {
	    const action = type === 'created' ? 'publion_load_created_posts' : 'publion_load_queue_entries';

	    $.post(Publion.ajax_url, {
	        action: action,
	        nonce: Publion.nonce,
	        offset: type === 'created' ? publionCreatedOffset : publionQueueOffset,
	        limit: publionQueueLimit
	    }, function (response) {
	        if (response.success) {
	            const html = response.data.rows || '';
	            const rowCount = (html.match(/<tr/g) || []).length;

	            if (type === 'created') {
	                $('#publion-created-table tbody').append(html);
	                publionCreatedOffset += publionQueueLimit;

	                if (!response.data.has_more || rowCount < publionQueueLimit) {
	                    $('#publion-load-more-created').hide();
	                }
	            } else {
                $('#publion-queue-table tbody').append(html);
	                publionQueueOffset += publionQueueLimit;

	                if (!response.data.has_more || rowCount < publionQueueLimit) {
	                    $('#publion-load-more').hide();
	                }
	            }
	        } else {
	            alert('Items laden mislukt.');
	        }
	    });
	}

	$('#publion-load-more').on('click', function () {
	    loadQueueOrCreated('pending');
	});

	$('#publion-load-more-created').on('click', function () {
	    loadQueueOrCreated('created');
	});

	setTimeout(function () {
    const $queueTab = $('#publion-queue');
	    if ($queueTab.is(':visible') && publionQueueOffset === 0) {
	        loadQueueOrCreated('pending');
	    }
	}, 100);
	
	setTimeout(function () {
    const $queueTab = $('#publion-queue');
	    if ($queueTab.is(':visible') && publionCreatedOffset === 0) {
	        loadQueueOrCreated('created');
	    }
	}, 150);

	$(document).on('click', '.publion-create-now', function () {
	    const $button = $(this);
	    const id = $button.data('id');

	    if (!confirm('Wil je nu een blogpost maken voor dit onderwerp? Dit kan even duren. Je krijgt een melding wanneer het klaar is.')) return;

	    $button.prop('disabled', true).find('.button-text').text('Aanmaken...');
	    const statusCycle = ['Tekst...', 'Afb. gen...', 'Post...'];
	    let statusIndex = 0;
	    const statusTimer = setInterval(() => {
	        statusIndex = (statusIndex + 1) % statusCycle.length;
	        $button.find('.button-text').text(statusCycle[statusIndex]);
	    }, 2000);
	    $button.next('.publion-create-spinner').addClass('is-active').show();
	    $('.publion-delete[data-id="' + id + '"]').hide();

	    $.post(ajaxurl, {
	        action: 'publion_create_post_now',
	        id: id
	    }, function (res) {
	        clearInterval(statusTimer);
	        if (res.success) {
	            alert('Post succesvol aangemaakt.');
	            localStorage.setItem('publion_active_tab', 'publion-queue');
	            location.reload();
	        } else { 
	            alert(res.data || 'Post aanmaken mislukt. Mogelijk heeft ChatGPT problemen. Probeer later opnieuw als dit aanhoudt.');
	            $button.prop('disabled', false).find('.button-text').text('Nu maken');
	            $button.next('.publion-create-spinner').removeClass('is-active').hide();
	            $('.publion-delete[data-id="' + id + '"]').show();
	        }
	    }).fail(function (jqXHR, textStatus, errorThrown) {
	        // Catch server 500s or network errors
	        try { console.error('Nu maken AJAX mislukt:', textStatus, errorThrown, jqXHR && jqXHR.responseText); } catch (e) {}
	        alert('Oeps! Er ging iets mis. De post is waarschijnlijk toch aangemaakt. Klik op OK om te verversen en te controleren. Mogelijk heeft ChatGPT problemen. Als de post niet is aangemaakt en dit blijft gebeuren, probeer later opnieuw.');
	        clearInterval(statusTimer);
	        localStorage.setItem('publion_active_tab', 'publion-queue');
	        location.reload();
	    });
	});

	$(document).on('click', '.publion-accordion-heading', function () {
	    const $header = $(this);
	    const $arrow = $header.find('.publion-accordion-arrow');
	    $header.toggleClass('active');
	    $header.next('.publion-accordion-body').slideToggle(200);

	    // Toggle arrow direction
	    if ($header.hasClass('active')) {
	        $arrow.text('▲');
	    } else {
	        $arrow.text('▼');
	    }
	});
	
	$(document).on('click', '.publion-delete', function () {
	    const $button = $(this);
	    const id = $button.data('id');

	    if (!confirm('Are you sure you want to delete this topic from the queue?')) return;

	    $button.prop('disabled', true).text('Deleting...');

	    $.post(Publion.ajax_url, {
	        action: 'publion_delete_topic',
	        nonce: Publion.nonce,
	        id: id
	    }, function (res) {
	        if (res.success) {
	            $button.closest('tr').fadeOut(300, function () {
	                $(this).remove();
	            });
	        } else {
	            alert(res.data || 'Onderwerp verwijderen mislukt.');
	            $button.prop('disabled', false).text('Verwijderen');
	        }
	    });
	});

    function getSelectedQueueIds() {
        const ids = [];
        $('#publion-queue-table tbody .publion-row-select:checked').each(function () {
            const id = parseInt($(this).data('id'), 10);
            if (id) ids.push(id);
        });
        return ids;
    }

    function setBulkStatus(html) {
        $('#publion-bulk-status').html(html);
    }

    function processBulkGenerate(ids, index) {
        if (!ids.length) {
            setBulkStatus('<span style="color:red;">❌ Geen items geselecteerd.</span>');
            return;
        }

        if (index >= ids.length) {
            setBulkStatus('<span style="color:green;">✅ Klaar! Herladen...</span>');
            localStorage.setItem('publion_active_tab', 'publion-queue');
            location.reload();
            return;
        }

        const id = ids[index];
        setBulkStatus('<span class="spinner is-active" style="float:none;display:inline-block;"></span> ' + (index + 1) + '/' + ids.length);

        $.post(Publion.ajax_url, {
            action: 'publion_create_post_now',
            nonce: Publion.nonce,
            id: id
        }, function (res) {
            if (res && res.success) {
                processBulkGenerate(ids, index + 1);
            } else {
                setBulkStatus('<span style="color:red;">❌ Mislukt bij item ' + (index + 1) + '.</span>');
            }
        }).fail(function () {
            setBulkStatus('<span style="color:red;">❌ AJAX error bij item ' + (index + 1) + '.</span>');
        });
    }

    function processBulkDelete(ids) {
        if (!ids.length) {
            setBulkStatus('<span style="color:red;">❌ Geen items geselecteerd.</span>');
            return;
        }

        setBulkStatus('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');
        let completed = 0;
        let failed = 0;

        ids.forEach(function (id) {
            $.post(Publion.ajax_url, {
                action: 'publion_delete_topic',
                nonce: Publion.nonce,
                id: id
            }, function (res) {
                if (res && res.success) {
                    $('#publion-queue-table tbody .publion-row-select[data-id="' + id + '"]').closest('tr').fadeOut(200, function () {
                        $(this).remove();
                    });
                } else {
                    failed++;
                }
            }).fail(function () {
                failed++;
            }).always(function () {
                completed++;
                if (completed >= ids.length) {
                    if (failed) {
                        setBulkStatus('<span style="color:red;">❌ ' + failed + ' mislukt.</span>');
                    } else {
                        setBulkStatus('<span style="color:green;">✅ Verwijderd.</span>');
                    }
                }
            });
        });
    }

    $(document).on('change', '#publion-select-all', function () {
        const checked = $(this).is(':checked');
        $('#publion-queue-table tbody .publion-row-select').prop('checked', checked);
    });

    $(document).on('change', '#publion-queue-table tbody .publion-row-select', function () {
        const total = $('#publion-queue-table tbody .publion-row-select').length;
        const checked = $('#publion-queue-table tbody .publion-row-select:checked').length;
        $('#publion-select-all').prop('checked', total && total === checked);
    });

    $(document).on('click', '#publion-bulk-apply', function () {
        const action = $('#publion-bulk-action').val();
        const ids = getSelectedQueueIds();

        if (!action) {
            setBulkStatus('<span style="color:red;">❌ Kies een bulkactie.</span>');
            return;
        }

        if (action === 'generate') {
            if (!confirm('Weet je zeker dat je alle geselecteerde posts wilt genereren?')) return;
            processBulkGenerate(ids, 0);
        } else if (action === 'delete') {
            if (!confirm('Weet je zeker dat je alle geselecteerde items wilt verwijderen?')) return;
            processBulkDelete(ids);
        }
    });

    // Update schedule for a queued post
    $(document).on('click', '.publion-schedule-save', function () {
        const $button = $(this);
        const $cell = $button.closest('td');
        const $row = $button.closest('tr');
        const id = $button.data('id');
        const $input = $cell.find('.publion-schedule-input');
        const value = $input.val();
        const $status = $cell.find('.publion-schedule-status');

        if (!id || !value) {
            $status.html('<span style="color:red;">❌ Ongeldige datum/tijd.</span>');
            return;
        }

        $button.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

        $.post(Publion.ajax_url, {
            action: 'publion_update_schedule',
            nonce: Publion.nonce,
            id: id,
            scheduled_at: value
        }, function (response) {
            if (response.success) {
                $status.html('<span style="color:green;">✅ Opgeslagen</span>');
                if (response.data && response.data.scheduled_input) {
                    $input.val(response.data.scheduled_input);
                }
                if (response.data && response.data.days_until !== undefined) {
                    $row.find('.publion-days-until').text(response.data.days_until);
                }
            } else {
                $status.html('<span style="color:red;">❌ Opslaan mislukt.</span>');
            }
        }).fail(function () {
            $status.html('<span style="color:red;">❌ AJAX error.</span>');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });
	
	// Remove orphaned topic from "Created Posts" when post is not found, then reload into Post Creation tab
	$(document).on('click', '.publion-remove-notfound', function () {
	    const $btn = $(this);
	    const id = $btn.data('id');
	    if (!id) return;

	    if (!confirm('Onderwerp verwijderen uit Publion?')) return;

	    $btn.prop('disabled', true).text('Verwijderen...');

	    $.post(Publion.ajax_url, {
	        action: 'publion_delete_topic',
	        nonce: Publion.nonce,
	        id: id
	    }, function (res) {
	        if (res.success) {
	            // Ensure we return to the Post Creation tab on reload
	            localStorage.setItem('publion_active_tab', 'publion-queue');
	            location.reload();
	        } else {
	            alert(res.data || 'Onderwerp verwijderen mislukt.');
	            $btn.prop('disabled', false).text('Verwijderen');
	        }
	    }).fail(function () {
	        alert('AJAX-fout bij verwijderen van onderwerp.');
	        $btn.prop('disabled', false).text('Verwijderen');
	    });
	});
	
// 👇 Show/hide "Selected Topics" section based on row count, with slide animation
function updateQueueVisibility() {
    const $wrapper = $('#publion-selected-topics');
    const hasRows = $('#publion-ai-queue tbody tr').length > 0;

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
	    const rawTopic = $li.data('topic') || $button.data('topic');
	    const seoBrief = $li.data('seo-brief') || {};
	    const category = $('#publion-category').val();
	    const categoryLabel = $('#publion-category option:selected').text();

	    // Guard against double-clicks or duplicate rows.
	    if ($button.prop('disabled')) return;
	    if ($('#publion-ai-queue tbody tr').filter(function () { return $(this).data('topic') === rawTopic && String($(this).data('category')) === String(category); }).length) {
	        $li.hide();
	        return;
	    }

	    $button.prop('disabled', true);

	    const $row = $('<tr>').data('topic', rawTopic).data('category', category).data('seo-brief', seoBrief);
	    $row.append($('<td>').append($('<button>', { type: 'button', class: 'button remove-topic', text: 'Verwijderen' }).data('restore-topic', rawTopic)));
	    $row.append($('<td>').data('category', category).data('category-label', categoryLabel).text(categoryLabel));
	    $row.append($('<td>').data('topic', rawTopic).text(rawTopic));
	    const briefText = 'Focus: ' + (seoBrief.focus_keyword || rawTopic) + ' · ' + (seoBrief.search_intent || 'informatief');
	    $row.append($('<td>', { class: 'publion-queue-brief', text: briefText }));
	    $('#publion-ai-queue tbody').append($row);

	    // Hide the original suggestion
	    $li.hide();

	    setTimeout(() => {
	        updateQueueVisibility();
	    }, 10);
	});

	// Trigger after removing a topic
	$(document).on('click', '.remove-topic', function () {
	    const $button = $(this);
	    const rawTopic = $button.data('restore-topic') || '';
	    
	    // Unhide matching suggestion
	    if (rawTopic) {
	        $('#publion-suggestions li').each(function () {
	            const $li = $(this);
	            const $addBtn = $li.find('.add-topic');
	            if ($li.data('topic') === rawTopic) {
	                $addBtn.prop('disabled', false);
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
    if ($('#publion-queue-table tbody tr').length < publionQueueLimit) {
	        $('#publion-load-more').hide();
	    }
	    if ($('#publion-created-table tbody tr').length < publionQueueLimit) {
	        $('#publion-load-more-created').hide();
	    }
	}
	
	document.addEventListener('DOMContentLoaded', function () {
	    const manualForm = document.getElementById('publion-manual-form');
	    if (manualForm) {
	        manualForm.addEventListener('submit', function () {
	            const activeTab = localStorage.getItem('publion_active_tab') || 'publion-queue';
	            const url = new URL(manualForm.action);
	            url.searchParams.set('publion_active_tab', activeTab);
	            manualForm.action = url.toString();
	        });
	    }
	});
	
	$(document).ajaxError(function (event, jqXHR, settings, thrownError) {
	    try {
	        // settings.data is a URL-encoded string for $.post
	        if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=publion_create_post_now') !== -1) {
	            alert('Oeps! Er ging iets mis. De post is waarschijnlijk toch aangemaakt. Klik op OK om te verversen en te controleren.');
	            localStorage.setItem('publion_active_tab', 'publion-queue');
	            location.reload();
	        }
	    } catch (e) {
	        // If anything goes wrong parsing, fail quietly—this is just a fallback.
	    }
	});
});

