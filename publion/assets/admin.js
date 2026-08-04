jQuery(document).ready(function ($) {
	const translations = (window.Publion && Publion.i18n) || {};
	function t(key, fallback) {
		return Object.prototype.hasOwnProperty.call(translations, key) ? translations[key] : fallback;
	}
	function format(message) {
		const values = Array.prototype.slice.call(arguments, 1);
		let index = 0;
		return String(message).replace(/%d/g, function () {
			return index < values.length ? values[index++] : '%d';
		});
	}

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

	function errorDetails(response, fallback) {
		const data = response && response.data;
		const details = (data && typeof data === 'object' && !Array.isArray(data)) ? data : {};
		const rawResponse = response && typeof response.responseText === 'string' ? response.responseText.trim() : '';
		const sessionExpired = rawResponse === '-1' || (response && (response.status === 401 || response.status === 403));
		return {
			code: details.code || (sessionExpired ? 'session_expired' : 'operation_failed'),
			title: details.title || (sessionExpired ? t('session_expired_title', 'Je WordPress-sessie is verlopen.') : t('action_required', 'Actie nodig')),
			message: details.message || (sessionExpired ? t('session_expired_message', 'De actie is niet uitgevoerd omdat de beveiligingssessie niet meer geldig is.') : (typeof data === 'string' ? data : fallback)),
			nextStep: details.next_step || (sessionExpired ? t('session_expired_next_step', 'Ververs de pagina, meld je zo nodig opnieuw aan en probeer daarna opnieuw.') : t('generic_next_step', 'Controleer de getoonde informatie en probeer daarna opnieuw.')),
			actionLabel: details.action_label || (sessionExpired ? t('refresh_queue', 'Ververs wachtrij') : ''),
			actionTab: details.action_tab || (sessionExpired ? 'publion-queue' : ''),
			reference: details.reference || '',
			invalidItems: Array.isArray(details.invalid_items) ? details.invalid_items : [],
			retryable: details.retryable !== false
		};
	}

	function appendErrorDetails($target, details, compact) {
		if (!$target || !$target.length) return;
		const $panel = $('<div>', { class: 'publion-operation-error', role: 'alert' });
		$panel.append($('<strong>', { text: details.title }));
		$panel.append($('<p>', { text: details.message }));
		if (!compact && details.nextStep) {
			$panel.append($('<p>', { class: 'publion-operation-error-next', text: details.nextStep }));
		}
		if (details.invalidItems && details.invalidItems.length) {
			const $list = $('<ul>', { class: 'publion-operation-error-list' });
			details.invalidItems.forEach(function (item) { $list.append($('<li>', { text: item })); });
			$panel.append($list);
		}
		if (details.reference) {
			$panel.append($('<small>', { text: t('reference', 'Referentie') + ': ' + details.reference }));
		}
		if (details.actionLabel && details.actionTab) {
			$panel.append($('<button>', {
				type: 'button',
				class: 'button button-secondary publion-error-action',
				text: details.actionLabel,
				'data-publion-error-tab': details.actionTab
			}));
		}
		$target.empty().append($panel);
	}

	function showNotice(type, message, details) {
		const $notice = $('#publion-global-notice');
		if (!$notice.length) return;
		$notice.removeClass('is-success is-warning is-error').addClass('is-' + type).empty();
		$notice.append($('<strong>', { text: type === 'error' ? t('action_required', 'Actie nodig') : type === 'warning' ? t('attention', 'Let op') : t('success', 'Gelukt') }));
		$notice.append($('<span>', { text: message }));
		if (details && details.actionLabel && details.actionTab) {
			$notice.append($('<button>', { type: 'button', class: 'button button-secondary publion-error-action', text: details.actionLabel, 'data-publion-error-tab': details.actionTab }));
		}
		if (details && details.reference) {
			$notice.append($('<small>', { text: t('reference', 'Referentie') + ': ' + details.reference }));
		}
		$notice.stop(true, true).slideDown(150);
		if (type === 'success') setTimeout(function () { $notice.fadeOut(250); }, 4500);
	}

	function responseMessage(response, fallback) {
		return errorDetails(response, fallback).message;
	}

	function showActionableError(response, fallback, $target) {
		const details = errorDetails(response, fallback);
		showNotice('error', details.title + ': ' + details.message + (details.nextStep ? ' ' + details.nextStep : ''), details);
		appendErrorDetails($target, details, false);
		return details;
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

	$(document).on('click', '[data-publion-error-tab]', function () {
		openPublionTab($(this).data('publion-error-tab'));
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
	function isSafeSuggestion(suggestion) {
		if (!suggestion || typeof suggestion !== 'object' || typeof suggestion.title !== 'string') return false;
		const title = suggestion.title.trim();
		const focus = typeof suggestion.focus_keyword === 'string' ? suggestion.focus_keyword.trim() : '';
		const allowedIntents = ['informatief', 'commercieel', 'transactioneel', 'navigerend'];
		if (title.length < 16 || title.length > 140 || focus.length < 2 || /[\[\]{}\r\n]/.test(title + focus)) return false;
		if (/(?:^|\s|["'])\b(?:title|focus_keyword|search_intent|angle|faq_questions)\b\s*:/i.test(title + ' ' + focus)) return false;
		if (!allowedIntents.includes(suggestion.search_intent)) return false;
		if (typeof suggestion.angle !== 'string' || suggestion.angle.trim().length < 20) return false;
		return Array.isArray(suggestion.faq_questions) && suggestion.faq_questions.filter(function (question) {
			return typeof question === 'string' && question.trim().length >= 12;
		}).length >= 3;
	}

	function renderSuggestions(suggestions) {
		const $list = $('#publion-suggestions').empty();
		const validSuggestions = Array.isArray(suggestions) ? suggestions.filter(function (suggestion) {
			return isSafeSuggestion(suggestion);
		}) : [];
		if (!validSuggestions.length) {
			$list.append($('<li>', {
				class: 'publion-suggestion publion-suggestion-empty',
			text: t('no_valid_suggestions', 'Geen volledig gevalideerde onderwerpvoorstellen ontvangen. Er is niets opgeslagen. Vernieuw de voorstellen; blijft dit gebeuren, kies een ondersteund model of controleer de voorprompt.')
			}));
			return;
		}
		validSuggestions.forEach(function (suggestion) {
			const title = suggestion.title.trim();
			const brief = {
				focus_keyword: suggestion.focus_keyword || title,
				search_intent: suggestion.search_intent || 'informatief',
				angle: suggestion.angle || '',
				faq_questions: Array.isArray(suggestion.faq_questions) ? suggestion.faq_questions : []
			};
			const $li = $('<li>', { class: 'publion-suggestion' }).data('topic', title).data('seo-brief', brief);
			const $button = $('<button>', { type: 'button', class: 'button button-primary add-topic', text: t('add', 'Toevoegen') });
			const $content = $('<div>', { class: 'publion-suggestion-content' });
			$content.append($('<strong>', { text: title }));
			const $meta = $('<div>', { class: 'publion-seo-meta' });
			$meta.append($('<span>', { class: 'publion-seo-tag', text: t('focus', 'Focus') + ': ' + brief.focus_keyword }));
			$meta.append($('<span>', { class: 'publion-seo-tag', text: t('intent', 'Intentie') + ': ' + brief.search_intent }));
			if (brief.angle) $content.append($('<p>', { class: 'publion-suggestion-angle', text: brief.angle }));
			$content.append($meta);
			if (brief.faq_questions.length) {
				$content.append($('<p>', { class: 'publion-faq-preview', text: t('faq', 'FAQ') + ': ' + brief.faq_questions.join(' · ') }));
			}
			$li.append($button, $content);
			$list.append($li);
		});
	}

	$('#publion-refresh').on('click', function () {
	    const category = $('#publion-category').val();
	    if (!category) {
			showNotice('warning', t('select_category_first', 'Selecteer eerst een categorie om relevante onderwerpvoorstellen te maken.'));
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
				showActionableError(response, t('refresh_failed', 'Vernieuwen van voorstellen mislukt. Controleer je API-sleutel en probeer opnieuw.'));
	        }
	    }).fail(function () {
	        $('#publion-loading').hide();
		showActionableError(null, t('connection_interrupted', 'De verbinding met WordPress of OpenAI is onderbroken. Controleer je internetverbinding en probeer opnieuw.'));
	    });
	});

	// Suggest Topics button
	$('#publion-suggest').on('click', function () {
	    const category = $('#publion-category').val();
	    if (!category) {
	        showNotice('warning', t('select_category_first', 'Selecteer eerst een categorie om relevante onderwerpvoorstellen te maken.'));
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
				showActionableError(response, t('suggestions_failed', 'Kon geen onderwerpvoorstellen ophalen. Controleer je API-sleutel en model in AI-instellingen.'));
	        }
	    }).fail(function () {
	        $('#publion-loading').hide();
	        $('#publion-suggest').prop('disabled', false);
		showActionableError(null, t('request_failed', 'De aanvraag kon niet worden verstuurd. Controleer de verbinding en probeer opnieuw.'));
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
	        showNotice('warning', t('select_topic_first', 'Kies eerst minstens één onderwerp voordat je de wachtrij opslaat.'));
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
	        queue_json: JSON.stringify(postQueue)
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green;">✅ Toegevoegd aan de wachtrij voor postcreatie!</span>');
	            showNotice('success', t('selected_added_to_queue', 'De geselecteerde onderwerpen staan nu in de wachtrij.'));
	            $('#publion-ai-queue tbody').empty();

	            // Pause to show success message, then hide the section
	            setTimeout(function () {
	                const $wrapper = $('#publion-selected-topics');
	                if ($wrapper.is(':visible')) {
	                    $wrapper.stop(true, true).slideUp(200);
	                }
	            }, 1500);

            } else {
				showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
            }
        }).fail(function () {
			showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
	    });
	});

    // Default to Settings tab if API key is missing
    if (!Publion.has_api_key) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('.publion-tab-content').hide();

        $('[data-tab="publion-settings"]').addClass('nav-tab-active');
        $('#publion-settings').show();
        showNotice('warning', t('api_key_needed', 'Voeg een OpenAI API-sleutel toe om met onderwerpen, artikelen en afbeeldingen te werken.'));
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
				showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
            }
        }).fail(function () {
			showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
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
	    const apiKey = $('#publion_api_key').val().trim();
	    if (!apiKey) {
	        $status.html('<span style="color:#475569; margin-left:5px;">Laat dit veld leeg om de huidige sleutel te behouden.</span>');
	        return;
	    }
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_api_key',
	        nonce: Publion.nonce,
	        api_key: apiKey
	    }, function (response) {
	        if (response.success) {
	            $status.html('<span style="color:green; margin-left:5px;">✅ Opgeslagen!</span>');
	        } else {
	            showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
	        }
	    }).fail(function () {
	        showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
	    });
	});

	function syncCustomModelInput() {
	    const isCustom = $('#publion_openai_model').val() === '__custom__';
	    const $wrap = $('#publion-custom-model-wrap');
	    const $input = $('#publion_custom_openai_model');
	    $wrap.prop('hidden', !isCustom).attr('aria-hidden', String(!isCustom));
	    $input.prop('disabled', !isCustom);
	    if (isCustom) {
	        $input.trigger('focus');
	    }
	}

	$('#publion_openai_model').on('change', syncCustomModelInput);

	function syncCustomImageModelInput() {
	    const isCustom = $('#publion_openai_image_model').val() === '__custom__';
	    const $wrap = $('#publion-custom-image-model-wrap');
	    const $input = $('#publion_custom_openai_image_model');
	    $wrap.prop('hidden', !isCustom).attr('aria-hidden', String(!isCustom));
	    $input.prop('disabled', !isCustom);
	    if (isCustom) {
	        $input.trigger('focus');
	    }
	}

	$('#publion_openai_image_model').on('change', syncCustomImageModelInput);

	// Save Model via AJAX
	$('#publion-save-model').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#publion-model-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_model',
	        nonce: Publion.nonce,
	        model: $('#publion_openai_model').val(),
	        custom_model: $('#publion_custom_openai_model').val()
	    }, function (response) {
	        if (response.success) {
	            $status.text(response.data.message || 'Model opgeslagen.');
	        } else {
	            showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
	        }
	    }).fail(function () {
	        showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
	    });
	});

	$('#publion-save-image-model').on('click', function (e) {
	    e.preventDefault();

	    const $status = $('#publion-image-model-status');
	    $status.html('<span class="spinner is-active" style="float:none;display:inline-block;"></span>');

	    $.post(Publion.ajax_url, {
	        action: 'publion_save_image_model',
	        nonce: Publion.nonce,
	        model: $('#publion_openai_image_model').val(),
	        custom_model: $('#publion_custom_openai_image_model').val()
	    }, function (response) {
	        if (response.success) {
	            $status.text(response.data.message || 'Afbeeldingsmodel opgeslagen.');
	        } else {
	            showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
	        }
	    }).fail(function () {
	        showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
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
	            showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
	        }
	    }).fail(function () {
	        showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
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
	            showActionableError(response, t('items_load_failed', 'Items laden mislukt.'));
	        }
	    }).fail(function () {
	        showActionableError(null, t('connection_interrupted', 'De verbinding met WordPress of OpenAI is onderbroken. Controleer je internetverbinding en probeer opnieuw.'));
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

	function renderCreationProgress($progress, progress) {
		if (!$progress.length || !progress) return;
		const percent = Math.max(0, Math.min(100, parseInt(progress.percent, 10) || 0));
		const state = progress.state || 'running';
		const stage = progress.stage || t('working', 'Bezig');
		const detail = progress.detail || t('server_processing', 'De server verwerkt deze stap.');
		const error = progress.error && typeof progress.error === 'object' ? progress.error : null;
		$progress.prop('hidden', false)
			.removeClass('is-running is-completed is-failed')
			.addClass('is-' + state);
		$progress.find('.publion-create-progress-stage').text(stage);
		$progress.find('.publion-create-progress-percent').text(percent + '%');
		$progress.find('.publion-create-progress-detail').text(detail);
		const $guidance = $progress.find('.publion-create-progress-guidance');
		const $reference = $progress.find('.publion-create-progress-reference');
		const $action = $progress.find('.publion-create-progress-action');
		if (error) {
			$guidance.text(error.next_step || '').prop('hidden', !error.next_step);
			$reference.text(error.reference ? t('reference', 'Referentie') + ': ' + error.reference : '').prop('hidden', !error.reference);
			$action.text(error.action_label || '').attr('data-publion-error-tab', error.action_tab || '').prop('hidden', !(error.action_label && error.action_tab));
		} else {
			$guidance.empty().prop('hidden', true);
			$reference.empty().prop('hidden', true);
			$action.empty().removeAttr('data-publion-error-tab').prop('hidden', true);
		}
		$progress.find('.publion-create-progress-bar').css('width', percent + '%');
		$progress.find('.publion-create-progress-track')
			.attr('aria-valuenow', percent)
			.attr('aria-valuetext', stage + ': ' + percent + ' ' + t('percent', 'procent'));
	}

	function pollCreationProgress(id, $progress, $button) {
		const poll = function () {
			$.post(Publion.ajax_url, {
				action: 'publion_get_creation_progress',
				nonce: Publion.nonce,
				id: id
			}, function (response) {
				if (!response || !response.success || !response.data) return;
				renderCreationProgress($progress, response.data);
				if (response.data.state === 'running') {
					$button.find('.button-text').text((response.data.stage || t('working', 'Bezig')) + ' · ' + (response.data.percent || 0) + '%');
				}
			});
		};
		poll();
		return window.setInterval(poll, 1100);
	}

	$(document).on('click', '.publion-create-now', function () {
	    const $button = $(this);
	    const id = $button.data('id');
	    const $cell = $button.closest('.publion-queue-actions');
	    const $progress = $cell.find('.publion-create-progress');

	    if (!confirm(t('create_now_confirm', 'Wil je nu een blogpost maken voor dit onderwerp? De status hieronder volgt de echte stappen op de server.'))) return;

	    $button.prop('disabled', true).find('.button-text').text(t('request_sent', 'Aanvraag verstuurd'));
	    renderCreationProgress($progress, {
			state: 'running',
			percent: 1,
			stage: t('request_started', 'Aanvraag gestart'),
			detail: t('waiting_server', 'Publion wacht op de eerste serverbevestiging.')
		});
	    $button.siblings('.publion-create-spinner').addClass('is-active').show();
	    $('.publion-delete[data-id="' + id + '"]').hide();
	    const progressTimer = pollCreationProgress(id, $progress, $button);

	    $.post(Publion.ajax_url, {
	        action: 'publion_create_post_now',
	        nonce: Publion.nonce,
	        id: id
	    }, function (res) {
	        window.clearInterval(progressTimer);
	        if (res && res.success) {
				renderCreationProgress($progress, {
					state: 'completed',
					percent: 100,
					stage: t('complete', 'Klaar'),
					detail: t('draft_ready', 'Het artikelconcept is aangemaakt en staat klaar voor controle.')
				});
				$button.find('.button-text').text(t('complete', 'Klaar') + ' · 100%');
				$button.siblings('.publion-create-spinner').removeClass('is-active').hide();
				showNotice('success', t('post_created', 'Post succesvol aangemaakt. De wachtrij wordt bijgewerkt.'));
				localStorage.setItem('publion_active_tab', 'publion-queue');
				window.setTimeout(function () { location.reload(); }, 1100);
			} else {
				const error = showActionableError(res, t('create_failed', 'Post aanmaken mislukt. Controleer de getoonde voortgang en probeer opnieuw.'));
				renderCreationProgress($progress, { state: 'failed', percent: 0, stage: error.title, detail: error.message, error: error });
	            $button.prop('disabled', false).find('.button-text').text(t('create_now', 'Nu maken'));
	            $button.siblings('.publion-create-spinner').removeClass('is-active').hide();
	            $('.publion-delete[data-id="' + id + '"]').show();
	        }
	    }).fail(function (jqXHR, textStatus, errorThrown) {
			window.clearInterval(progressTimer);
			try { console.error('Nu maken AJAX mislukt:', textStatus, errorThrown, jqXHR && jqXHR.responseText); } catch (e) {}
			const error = errorDetails(null, t('connection_lost', 'De verbinding met WordPress viel weg. Controleer de actuele status na het verversen voordat je opnieuw start.'));
			error.title = t('connection_lost_stage', 'Verbinding onderbroken');
			error.nextStep = t('connection_lost_next_step', 'Ververs eerst de wachtrij. Start alleen opnieuw als er nog geen concept is aangemaakt.');
			error.actionLabel = t('open_queue', 'Open wachtrij');
			error.actionTab = 'publion-queue';
			renderCreationProgress($progress, { state: 'failed', percent: 0, stage: error.title, detail: error.message, error: error });
			showNotice('error', error.title + ': ' + error.message + ' ' + error.nextStep, error);
			$button.prop('disabled', false).find('.button-text').text(t('create_now', 'Nu maken'));
			$button.siblings('.publion-create-spinner').removeClass('is-active').hide();
			$('.publion-delete[data-id="' + id + '"]').show();
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

	    if (!confirm(t('delete_topic_confirm', 'Weet je zeker dat je dit onderwerp uit de wachtrij wilt verwijderen?'))) return;

	    $button.prop('disabled', true).text(t('removing', 'Verwijderen…'));

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
	            showActionableError(res, t('remove_topic_failed', 'Onderwerp verwijderen mislukt.'));
	            $button.prop('disabled', false).text(t('remove', 'Verwijderen'));
	        }
	    }).fail(function () {
	        showActionableError(null, t('delete_ajax_error', 'AJAX-fout bij verwijderen van onderwerp.'));
	        $button.prop('disabled', false).text(t('remove', 'Verwijderen'));
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
            setBulkStatus('<span style="color:red;">&#10060; ' + t('no_items_selected', 'Geen items geselecteerd.') + '</span>');
            return;
        }

        if (index >= ids.length) {
            setBulkStatus('<span style="color:green;">&#9989; ' + t('reloading', 'Klaar! Herladen…') + '</span>');
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
                setBulkStatus('<span style="color:red;">&#10060; ' + format(t('failed_at_item', 'Mislukt bij item %d.'), index + 1) + '</span>');
            }
        }).fail(function () {
            setBulkStatus('<span style="color:red;">&#10060; ' + format(t('ajax_error_at_item', 'AJAX-fout bij item %d.'), index + 1) + '</span>');
        });
    }

    function processBulkDelete(ids) {
        if (!ids.length) {
            setBulkStatus('<span style="color:red;">&#10060; ' + t('no_items_selected', 'Geen items geselecteerd.') + '</span>');
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
                        setBulkStatus('<span style="color:red;">&#10060; ' + format(t('failed_count', '%d mislukt.'), failed) + '</span>');
                    } else {
                        setBulkStatus('<span style="color:green;">&#9989; ' + t('deleted', 'Verwijderd.') + '</span>');
                    }
                }
            });
        });
    }

    function queueTopicLabel(id) {
        const $row = $('#publion-queue-table tbody .publion-row-select[data-id="' + id + '"]').closest('tr');
        return $row.find('td').eq(2).text().trim() || t('queue_item', 'Wachtrij-item') + ' #' + id;
    }

    function renderBulkSummary(total, succeeded, failures) {
        const $status = $('#publion-bulk-status').empty();
        const $summary = $('<div>', { class: 'publion-bulk-summary' + (failures.length ? ' is-error' : '') });
        const heading = failures.length
            ? format(t('bulk_completed_with_errors', 'Bulkactie afgerond: %d item(s) hebben aandacht nodig.'), failures.length)
            : t('bulk_completed', 'Bulkactie succesvol afgerond.');
        $summary.append($('<strong>', { text: heading }));
		$summary.append($('<span>', { text: format(t('bulk_progress_summary', '%d van %d verwerkt.'), succeeded + failures.length, total) + ' ' + format(t('bulk_success_summary', '%d geslaagd.'), succeeded) }));
        if (failures.length) {
            const $list = $('<ul>');
            failures.slice(0, 5).forEach(function (failure) {
                $list.append($('<li>', { text: failure.topic + ' — ' + failure.error.title + ': ' + failure.error.message + ' ' + failure.error.nextStep }));
            });
            $summary.append($list);
            const first = failures[0].error;
            if (first.actionLabel && first.actionTab) {
                $summary.append($('<button>', { type: 'button', class: 'button button-secondary publion-error-action', text: first.actionLabel, 'data-publion-error-tab': first.actionTab }));
            }
        }
        $summary.append($('<button>', { type: 'button', class: 'button publion-error-action', text: t('refresh_queue', 'Ververs wachtrij'), 'data-publion-error-tab': 'publion-queue' }));
        $status.append($summary);
    }

    function processBulkGenerateDetailed(ids, index, results) {
        results = results || { succeeded: 0, failures: [] };
        if (index >= ids.length) {
            renderBulkSummary(ids.length, results.succeeded, results.failures);
            if (results.failures.length) showActionableError({ data: results.failures[0].error }, t('bulk_failed', 'Een of meer items konden niet worden verwerkt.'));
            return;
        }
        const id = ids[index];
        setBulkStatus('<span class="spinner is-active" style="float:none;display:inline-block;"></span> ' + format(t('bulk_processing', 'Item %d wordt verwerkt.'), index + 1));
        $.post(Publion.ajax_url, { action: 'publion_create_post_now', nonce: Publion.nonce, id: id }, function (res) {
            if (res && res.success) {
                results.succeeded++;
                $('#publion-queue-table tbody .publion-row-select[data-id="' + id + '"]').closest('tr').fadeOut(180, function () { $(this).remove(); });
            } else {
                results.failures.push({ topic: queueTopicLabel(id), error: errorDetails(res, t('bulk_failed', 'Dit item kon niet worden verwerkt.')) });
            }
            processBulkGenerateDetailed(ids, index + 1, results);
        }).fail(function () {
            results.failures.push({ topic: queueTopicLabel(id), error: errorDetails(null, t('connection_lost', 'De verbinding met WordPress viel weg.')) });
            processBulkGenerateDetailed(ids, index + 1, results);
        });
    }

    function processBulkDeleteDetailed(ids) {
        const results = { succeeded: 0, failures: [] };
        let completed = 0;
        setBulkStatus('<span class="spinner is-active" style="float:none;display:inline-block;"></span> ' + t('bulk_delete_processing', 'Geselecteerde items worden verwijderd.'));
        ids.forEach(function (id) {
            const topic = queueTopicLabel(id);
            $.post(Publion.ajax_url, { action: 'publion_delete_topic', nonce: Publion.nonce, id: id }, function (res) {
                if (res && res.success) {
                    results.succeeded++;
                    $('#publion-queue-table tbody .publion-row-select[data-id="' + id + '"]').closest('tr').fadeOut(180, function () { $(this).remove(); });
                } else {
                    results.failures.push({ topic: topic, error: errorDetails(res, t('remove_topic_failed', 'Onderwerp verwijderen mislukt.')) });
                }
            }).fail(function () {
                results.failures.push({ topic: topic, error: errorDetails(null, t('delete_ajax_error', 'AJAX-fout bij verwijderen van onderwerp.')) });
            }).always(function () {
                completed++;
                if (completed === ids.length) {
                    renderBulkSummary(ids.length, results.succeeded, results.failures);
                    if (results.failures.length) showActionableError({ data: results.failures[0].error }, t('bulk_failed', 'Een of meer items konden niet worden verwijderd.'));
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
            setBulkStatus('<span style="color:red;">&#10060; ' + t('choose_bulk_action', 'Kies een bulkactie.') + '</span>');
            return;
        }

        if (!ids.length) {
            setBulkStatus('<span style="color:red;">&#10060; ' + t('no_items_selected', 'Geen items geselecteerd.') + '</span>');
            return;
        }

        if (action === 'generate') {
	            if (!confirm(t('bulk_generate_confirm', 'Weet je zeker dat je alle geselecteerde posts wilt genereren?'))) return;
            processBulkGenerateDetailed(ids, 0);
        } else if (action === 'delete') {
	            if (!confirm(t('bulk_delete_confirm', 'Weet je zeker dat je alle geselecteerde items wilt verwijderen?'))) return;
            processBulkDeleteDetailed(ids);
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
            $status.html('<span style="color:red;">&#10060; ' + t('invalid_date_time', 'Ongeldige datum/tijd.') + '</span>');
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
                $status.html('<span style="color:green;">&#9989; ' + t('saved_short', 'Opgeslagen') + '</span>');
                if (response.data && response.data.scheduled_input) {
                    $input.val(response.data.scheduled_input);
                }
                if (response.data && response.data.days_until !== undefined) {
                    $row.find('.publion-days-until').text(response.data.days_until);
                }
            } else {
                showActionableError(response, t('save_failed', 'Opslaan mislukt.'), $status);
            }
        }).fail(function () {
            showActionableError(null, t('network_save_failed', 'Opslaan mislukt door een netwerkfout.'), $status);
        }).always(function () {
            $button.prop('disabled', false);
        });
    });
	
	// Remove orphaned topic from "Created Posts" when post is not found, then reload into Post Creation tab
	$(document).on('click', '.publion-remove-notfound', function () {
	    const $btn = $(this);
	    const id = $btn.data('id');
	    if (!id) return;

	    if (!confirm(t('remove_from_publion_confirm', 'Onderwerp verwijderen uit Publion?'))) return;

	    $btn.prop('disabled', true).text(t('removing', 'Verwijderen…'));

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
	            showActionableError(res, t('remove_topic_failed', 'Onderwerp verwijderen mislukt.'));
	            $btn.prop('disabled', false).text(t('remove', 'Verwijderen'));
	        }
	    }).fail(function () {
	        showActionableError(null, t('delete_ajax_error', 'AJAX-fout bij verwijderen van onderwerp.'));
	        $btn.prop('disabled', false).text(t('remove', 'Verwijderen'));
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
	    $row.append($('<td>').append($('<button>', { type: 'button', class: 'button remove-topic', text: t('remove', 'Verwijderen') }).data('restore-topic', rawTopic)));
	    $row.append($('<td>').data('category', category).data('category-label', categoryLabel).text(categoryLabel));
	    $row.append($('<td>').data('topic', rawTopic).text(rawTopic));
	    const briefText = t('focus', 'Focus') + ': ' + (seoBrief.focus_keyword || rawTopic) + ' · ' + (seoBrief.search_intent || t('informational', 'informatief'));
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
	        if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=publion_') !== -1 && jqXHR && String(jqXHR.responseText || '').trim() === '-1') {
	            showActionableError(jqXHR, t('session_expired_message', 'De actie is niet uitgevoerd omdat de beveiligingssessie niet meer geldig is.'));
	            return;
	        }
	        // settings.data is a URL-encoded string for $.post
        if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=publion_create_post_now') !== -1) {
	            const error = errorDetails(null, t('post_status_uncertain', 'Er ging iets mis. De post is mogelijk toch aangemaakt. Vernieuw de pagina om de actuele status te controleren.'));
	            error.title = t('connection_lost_stage', 'Verbinding onderbroken');
	            error.nextStep = t('connection_lost_next_step', 'Ververs eerst de wachtrij. Start alleen opnieuw als er nog geen concept is aangemaakt.');
	            error.actionLabel = t('refresh_queue', 'Ververs wachtrij');
	            error.actionTab = 'publion-queue';
	            showNotice('error', error.title + ': ' + error.message + ' ' + error.nextStep, error);
        }
	    } catch (e) {
	        // If anything goes wrong parsing, fail quietly—this is just a fallback.
	    }
	});
});

