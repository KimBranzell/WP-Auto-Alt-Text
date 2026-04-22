document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const cleanupForm = document.querySelector('form[name="cleanup_stats"]');
    const bulkIds = urlParams.get('bulk_ids');

    /**
     * Listens for click events on the document and calls `handleSingleImageGeneration` if the clicked
     * element has the 'generate-alt-text-button' class.
     *
     * @param {Event} event - The click event object.
     */
    document.addEventListener('click', function(event) {
        if (event.target && event.target.classList.contains('generate-alt-text-button')) {
            handleSingleImageGeneration(event);
        }

        scheduleBatchProcessingRefresh();
    });

    document.addEventListener('keyup', function() {
        scheduleBatchProcessingRefresh();
    });

    /**
     * Observes the document body for mutations and calls the `handleBatchProcessing` function
     * when mutations occur.
     */
    const observer = new MutationObserver((mutations, obs) => {
        handleBatchProcessing(obs);
    });


    /**
     * Adds a submit event listener to the cleanup form that prompts the user for confirmation before
     * allowing the form to be submitted. This prevents the user from accidentally removing all
     * generation records for deleted images.
     */
    if (cleanupForm) {
        cleanupForm.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to remove all generation records for deleted images?')) {
                e.preventDefault();
            }
        });
    }

    /**
     * Observes the document body for mutations and calls the `handleBatchProcessing` function
     * when mutations occur.
     */
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style', 'aria-selected']
    });

    handleBatchProcessing(observer);

    if (bulkIds) {
        const ids = bulkIds.split(',');
        const totalImages = ids.length;
        let processed = 0;

        updateProgressBar(0, {
            processed: 0,
            total: totalImages,
            label: 'Generating alt text for selected media',
        });

        /**
         * Processes a batch of image IDs for generating alternative text.
         * This function is responsible for fetching the next batch of image IDs,
         * sending them to the server for processing, and updating the progress bar.
         * It recursively calls itself until all image IDs have been processed.
         */
        function processBatch() {
            const batch = ids.splice(0, 5);
            if (batch.length === 0) {
                updateProgressBar(100);
                return;
            }

            fetch(autoAltTextData.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'process_image_batch',
                    ids: JSON.stringify(batch),
                    nonce: autoAltTextData.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                processed += batch.length;
                const progress = (processed / totalImages) * 100;
                updateProgressBar(progress, {
                    processed,
                    total: totalImages,
                    label: 'Generating alt text for selected media',
                });
                processBatch();
            })
            .catch(error => {
                console.error('Error:', error);
                markProgressAsError('Batch generation stopped before all selected images were processed.', {
                    processed,
                    total: totalImages,
                });
                showError('Batch generation stopped before all selected images were processed.');
            });
        }

        processBatch();
    }
});

initBlockEditorImageAltTextGenerator();

/**
 * Sends an AJAX request to the plugin endpoints and returns the payload.
 *
 * @param {Object} params Request parameters.
 * @returns {Promise<Object>} The parsed payload.
 */
async function sendAutoAltTextRequest(params) {
    const response = await fetch(autoAltTextData.ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(params),
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
        throw new Error(data?.data?.message || data?.message || 'Unexpected error');
    }

    return data.data || data;
}

/**
 * Requests generated alt text for a single attachment without immediately applying it.
 *
 * @param {number|string} attachmentId The attachment ID.
 * @param {boolean} previewMode Whether to generate in preview mode.
 * @returns {Promise<Object>} The generated alt text payload.
 */
function requestGeneratedAltText(attachmentId, previewMode = true) {
    return sendAutoAltTextRequest({
        action: 'generate_alt_text_for_attachment',
        attachment_id: attachmentId,
        nonce: autoAltTextData.actionNonce,
        preview: previewMode ? 'true' : 'false',
    });
}

/**
 * Applies generated alt text to the attachment metadata.
 *
 * @param {number|string} attachmentId The attachment ID.
 * @param {string} altText The alt text to apply.
 * @returns {Promise<Object>} The updated attachment payload.
 */
function applyGeneratedAltText(attachmentId, altText) {
    return sendAutoAltTextRequest({
        action: 'apply_alt_text',
        attachment_id: attachmentId,
        alt_text: altText,
        original_text: altText,
        is_edited: '0',
        nonce: autoAltTextData.actionNonce,
    });
}

/**
 * Registers a Gutenberg inspector control for the core/image block.
 */
function initBlockEditorImageAltTextGenerator() {
    if (
        typeof window.wp === 'undefined' ||
        !window.wp.hooks ||
        !window.wp.element ||
        !window.wp.blockEditor ||
        !window.wp.components
    ) {
        return;
    }

    if (window.autoAltTextBlockEditorInitialized) {
        return;
    }

    window.autoAltTextBlockEditorInitialized = true;

    const { addFilter } = window.wp.hooks;
    const { createElement, Fragment, useState } = window.wp.element;
    const { InspectorControls } = window.wp.blockEditor;
    const { PanelBody, Button, Notice } = window.wp.components;

    const withImageAltTextControls = (BlockEdit) => {
        return function ImageAltTextControls(props) {
            const [isGenerating, setIsGenerating] = useState(false);
            const [notice, setNotice] = useState(null);

            if (props.name !== 'core/image' || !props.isSelected) {
                return createElement(BlockEdit, props);
            }

            const attachmentId = props.attributes?.id;

            const handleGenerate = async () => {
                if (!attachmentId || isGenerating) {
                    return;
                }

                setIsGenerating(true);
                setNotice(null);

                try {
                    const result = await requestGeneratedAltText(attachmentId, true);

                    if (!result.alt_text) {
                        throw new Error('No alt text was generated.');
                    }

                    await applyGeneratedAltText(attachmentId, result.alt_text);
                    props.setAttributes({ alt: result.alt_text });
                    sessionStorage.setItem(`alt_text_${attachmentId}`, result.alt_text);

                    setNotice({
                        status: 'success',
                        message: 'Alt text generated and inserted into the block. Review the Alt text field before saving.',
                    });
                } catch (error) {
                    setNotice({
                        status: 'error',
                        message: error.message || 'Failed to generate alt text.',
                    });
                } finally {
                    setIsGenerating(false);
                }
            };

            return createElement(
                Fragment,
                null,
                createElement(BlockEdit, props),
                createElement(
                    InspectorControls,
                    null,
                    createElement(
                        PanelBody,
                        {
                            title: 'Auto Alt Text',
                            initialOpen: true,
                        },
                        !attachmentId
                            ? createElement(
                                Notice,
                                {
                                    status: 'warning',
                                    isDismissible: false,
                                },
                                'Select an image from the Media Library to generate alt text.'
                            )
                            : createElement(
                                Fragment,
                                null,
                                createElement(
                                    'p',
                                    null,
                                    'Generate alt text for this image and insert it into the block.'
                                ),
                                notice
                                    ? createElement(
                                        Notice,
                                        {
                                            status: notice.status,
                                            isDismissible: false,
                                        },
                                        notice.message
                                    )
                                    : null,
                                createElement(
                                    Button,
                                    {
                                        variant: 'secondary',
                                        onClick: handleGenerate,
                                        isBusy: isGenerating,
                                        disabled: isGenerating,
                                    },
                                    isGenerating ? 'Generating alt text...' : 'Generate Alternative Text with AI'
                                )
                            )
                    )
                )
            );
        };
    };

    addFilter(
        'editor.BlockEdit',
        'wp-auto-alt-text/gutenberg-image-generator',
        withImageAltTextControls
    );
}

/**
 * Handles the generation of alternative text for a single image attachment.
 * This function is responsible for checking if a cached version of the alternative text
 * is available, and if not, it fetches the alternative text from the server and displays
 * a preview dialog for the user to review and apply the generated text.
 *
 * @param {Event} event - The event object passed to the event handler.
 */
function handleSingleImageGeneration(event) {
    event.preventDefault();
    const button = event.target;
    const attachmentId = button.dataset.attachmentId;
    const nonce = button.dataset.nonce;
    const loader = button.querySelector('.loader');

    // Check for cached version in this session
    const cachedText = sessionStorage.getItem(`alt_text_${attachmentId}`);
    if (cachedText) {
        showPreviewDialog(cachedText, attachmentId, true);
        return;
    }

    loader.style.display = 'inline-block';
    fetch(ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'generate_alt_text_for_attachment',
            attachment_id: attachmentId,
            nonce: nonce,
            preview: true
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data.alt_text) {
            // Store the generated text in session storage
            sessionStorage.setItem(`alt_text_${attachmentId}`, data.data.alt_text);
            showPreviewDialog(data.data.alt_text, attachmentId, false);
        }
    })
    .catch(error => console.error('Error:', error))
    .finally(() => {
        loader.style.display = 'none';
    });
}

/**
 * Displays an error notice on the page.
 *
 * @param {string} message - The error message to display.
 */
function showError(message) {
    const notice = document.createElement('div');
    notice.className = 'notice notice-error is-dismissible';
    notice.innerHTML = `<p>${message}</p>`;
    document.querySelector('.wrap').insertBefore(notice, document.querySelector('.wrap').firstChild);
}

/**
 * Displays a preview dialog for the generated alt text of an attachment.
 *
 * @param {string} altText - The generated alt text to preview.
 * @param {string} attachmentId - The ID of the attachment.
 * @param {boolean} isCached - Indicates whether the alt text is from a cached version.
 */
function showPreviewDialog(altText, attachmentId, isCached) {
    const dialog = document.createElement('div');
    const button = document.querySelector(`[data-attachment-id="${attachmentId}"]`);
    const nonce = button.dataset.nonce;

    dialog.className = 'alt-text-preview-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'preview-title');

    const brandVoiceButton = (typeof autoAltTextData !== 'undefined' && autoAltTextData.brandTonalityEnabled)
      ? `<button class="button feedback-option" data-type="brand_voice">Brand voice</button>`
      : '';

    dialog.innerHTML = `
        <div class="alt-text-preview-content">
            <h3>Förhandsgranska alternativ text ${isCached ? '<span class="cached-badge">Cachead</span>' : ''}</h3>
            <textarea class="preview-text">${altText}</textarea>

            <div class="feedback-section">
                <div class="feedback-header">
                    <h4>Inte nöjd? Förbättra denna alt text</h4>
                    <small>OBS: Detta kommer att räknas som en ny begäran, med tillkommande kostnad.</small>
                </div>
                <div class="feedback-options">
                    <button class="button feedback-option" data-type="more_descriptive">Mer beskrivande</button>
                    <button class="button feedback-option" data-type="more_concise">Mer kortfattad</button>
                    <button class="button feedback-option" data-type="more_accessible">Mer tillgänglig</button>
                    <button class="button feedback-option" data-type="better_seo">Sökmotorsanpassad</button>
                    <button class="button feedback-option" data-type="technical_accuracy">Teknisk noggrannhet</button>
                    ${brandVoiceButton}
                </div>
                <div class="custom-feedback">
                    <textarea placeholder="Eller skriv din egen feedback..." class="custom-feedback-text"></textarea>
                    <button class="button custom-feedback-submit">Skicka egen feedback</button>
                </div>
            </div>

            <div class="preview-actions">
                <button class="button button-primary apply-alt-text">Applicera</button>
                ${isCached ? '<button class="button regenerate-alt-text">Generera ny alternativ text</button>' : ''}
                <button class="button button-secondary cancel-alt-text">Avbryt</button>
            </div>
        </div>
    `;

    document.body.appendChild(dialog);

    const textarea = dialog.querySelector('.preview-text');
    textarea.focus();
    textarea.setSelectionRange(0, textarea.value.length);

    // Add event listeners for feedback buttons
    dialog.querySelectorAll('.feedback-option').forEach(button => {
        button.addEventListener('click', () => {
            const improvementType = button.dataset.type;
            regenerateAltTextWithFeedback(attachmentId, nonce, improvementType, '', altText, dialog);
        });
    });

    // Add event listener for custom feedback
    dialog.querySelector('.custom-feedback-submit').addEventListener('click', () => {
        const customFeedback = dialog.querySelector('.custom-feedback-text').value;
        if (customFeedback.trim()) {
            regenerateAltTextWithFeedback(attachmentId, nonce, 'custom', customFeedback, altText, dialog);
        } else {
            alert('Please enter your feedback before submitting.');
        }
    });

    /**
     * Handles the click event on the "Apply" button in the alt text preview dialog.
     * It updates the alt text for the selected attachment, saves the updated text to the session storage,
     * and updates the alt text fields in the attachment details.
     */
    dialog.querySelector('.apply-alt-text').addEventListener('click', () => {
        const finalText = dialog.querySelector('.preview-text').value;
        const isEdited = finalText.trim() !== altText.trim();
        const applyButton = dialog.querySelector('.apply-alt-text');
        applyButton.disabled = true;
        applyButton.textContent = 'Applying...';

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'apply_alt_text',
                attachment_id: attachmentId,
                alt_text: finalText,
                original_text: altText,
                is_edited: isEdited ? '1' : '0',
                nonce: nonce
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const altTextFields = document.querySelectorAll('#attachment-details-two-column-alt-text, #attachment-details-alt-text');
                altTextFields.forEach(field => {
                    field.value = finalText;
                });
                sessionStorage.setItem(`alt_text_${attachmentId}`, finalText);
            } else {
                throw new Error(data.message || 'Failed to update alt text');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError(`Failed to update alt text: ${error.message}`);
        })
        .finally(() => {
            dialog.remove();
        });
    });

    // Add regenerate functionality if showing cached version
    if (isCached) {
        dialog.querySelector('.regenerate-alt-text').addEventListener('click', () => {
            sessionStorage.removeItem(`alt_text_${attachmentId}`);
            dialog.remove();
            document.querySelector(`[data-attachment-id="${attachmentId}"]`).click();
        });
    }

    // Handle cancel
    dialog.querySelector('.cancel-alt-text').addEventListener('click', () => dialog.remove());

    // Listens for the 'keydown' event on the document and removes the 'dialog' element if the 'Escape' key is pressed.
    // It also removes the event listener to prevent further handling of the 'keydown' event.
    document.addEventListener('keydown', function handleEscape(e) {
        if (e.key === 'Escape') {
            dialog.remove();
            document.removeEventListener('keydown', handleEscape);
        }
    });
}

/**
 * Regenerates alt text based on user feedback.
 *
 * @param {string} attachmentId - The ID of the attachment.
 * @param {string} nonce - The security nonce.
 * @param {string} improvementType - The type of improvement requested.
 * @param {string} customFeedback - Any custom feedback provided by the user.
 * @param {string} originalAltText - The original alt text.
 * @param {Element} dialog - The dialog element containing the preview.
 */
function regenerateAltTextWithFeedback(attachmentId, nonce, improvementType, customFeedback, originalAltText, dialog) {
    // Show loading state
    const textarea = dialog.querySelector('.preview-text');
    const originalText = textarea.value;
    textarea.disabled = true;
    textarea.value = 'Genererar alternativ text efter feedback...';

    // Disable all feedback buttons
    const feedbackButtons = dialog.querySelectorAll('.feedback-option, .custom-feedback-submit');
    feedbackButtons.forEach(btn => btn.disabled = true);

    fetch(ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'regenerate_alt_text_with_feedback',
            attachment_id: attachmentId,
            nonce: nonce,
            improvement_type: improvementType,
            custom_feedback: customFeedback,
            original_alt_text: originalAltText
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data.alt_text) {
            // Update the preview with the new alt text
            textarea.value = data.data.alt_text;

            // Store the new generated text in session storage
            sessionStorage.setItem(`alt_text_${attachmentId}`, data.data.alt_text);

            // Show success indicator
            const feedbackSection = dialog.querySelector('.feedback-section');
            const successMessage = document.createElement('div');
            successMessage.className = 'feedback-success';
            successMessage.textContent = 'Ny alternativ text har genererats';
            feedbackSection.prepend(successMessage);

            // Remove the success message after 3 seconds
            setTimeout(() => {
                if (successMessage.parentNode) {
                    successMessage.parentNode.removeChild(successMessage);
                }
            }, 3000);
        } else {
            // Restore original text in case of error
            textarea.value = originalText;
            alert('Failed to improve alt text: ' + (data.data?.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        textarea.value = originalText;
        alert('Error processing your feedback. Please try again.');
    })
    .finally(() => {
        // Re-enable elements
        textarea.disabled = false;
        feedbackButtons.forEach(btn => btn.disabled = false);
    });
}


/**
 * Handles the batch processing of generating alt text for selected images.
 * If the media toolbar is present and does not already have a "Generate Alt Text for Selected" button,
 * this function creates the button and attaches a click event listener that calls `processBatchSelection`.
 *
 * @param {MutationObserver} obs - The MutationObserver instance that triggered this function.
 */
function handleBatchProcessing(obs) {
    const selectedIds = getSelectedAttachmentIds();
    const hasMultiSelection = selectedIds.length > 1;
    const existingButton = document.querySelector('.process-alt-text-batch');

    if (!hasMultiSelection) {
        if (existingButton) {
            existingButton.hidden = true;
            existingButton.disabled = false;
        }

        return;
    }

    const mediaToolbar = getBatchToolbarHost();
    if (!mediaToolbar) {
        return;
    }

    const batchButton = ensureBatchButton(mediaToolbar);
    batchButton.hidden = false;
}

/**
 * Handles the batch processing of generating alt text for selected images.
 * This function is called when the "Generate Alt Text for Selected" button is clicked.
 * It retrieves the selected image IDs, disables the button, initializes the progress bar,
 * and then processes the images in batches, updating the progress bar as it goes.
 * Once all images have been processed, the button is re-enabled and the page is reloaded.
 *
 * @param {Event} e - The click event object.
 * @param {HTMLButtonElement} button - The "Generate Alt Text for Selected" button element.
 */
function processBatchSelection(e, button) {
    e.preventDefault();

    const selected = getSelectedAttachmentIds();

    if (selected.length <= 1) {
        alert('Please select at least two images first');
        return;
    }

    const originalText = button.textContent;
    button.textContent = 'Generating...';
    button.disabled = true;

    // Initialize progress bar at 0%
    updateProgressBar(0, {
        processed: 0,
        total: selected.length,
        label: 'Generating alt text for selected media',
    });

    // Process images in batches with progress bar
    const ids = [...selected];
    const totalImages = ids.length;
    let processed = 0;
    const batchSize = 5;

    function processBatch() {
        const batch = ids.splice(0, batchSize);
        if (batch.length === 0) {
            setTimeout(() => {
                updateProgressBar(100, {
                    processed: totalImages,
                    total: totalImages,
                    label: 'Generating alt text for selected media',
                });
                button.textContent = originalText;
                button.disabled = false;
            }, 500);
            return;
        }

        fetch(autoAltTextData.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'process_image_batch',
                nonce: autoAltTextData.nonce,
                ids: JSON.stringify(batch)
            })
        })
        .then(response => response.json())
        .then(data => {
            processed += batch.length;
            const progress = Math.min((processed / totalImages) * 100, 99);
            updateProgressBar(progress, {
                processed,
                total: totalImages,
                label: 'Generating alt text for selected media',
            });
            processBatch();
        })
        .catch(error => {
            console.error('Error:', error);
            markProgressAsError('Batch generation stopped before all selected images were processed.', {
                processed,
                total: totalImages,
            });
            button.textContent = originalText;
            button.disabled = false;
            showError('Batch generation stopped before all selected images were processed.');
        })
        .finally(() => {
            if (ids.length === 0) {
                button.textContent = originalText;
                button.disabled = false;
            }
        });
    }

    processBatch();
}

/**
 * Updates the progress bar element with the given progress percentage.
 *
 * This function is responsible for creating the progress bar element if it doesn't
 * already exist, and updating the width and text content of the progress bar to
 * reflect the current progress.
 *
 * If the progress reaches 100%, the function will trigger a page reload after a
 * short delay.
 *
 * @param {number} progress - The progress percentage to display (between 0 and 100).
 * @param {Object} details - Additional progress metadata.
 */
function updateProgressBar(progress, details = {}) {
    const progressContainer = ensureProgressContainer();

    if (!progressContainer) {
        return;
    }

    const progressBar = progressContainer.querySelector('.alt-text-progress');
    const bar = progressContainer.querySelector('.progress-bar');
    const text = progressContainer.querySelector('.progress-text');
    const count = progressContainer.querySelector('.progress-count');
    const label = progressContainer.querySelector('.progress-label');
    const total = Number.isFinite(details.total) ? details.total : null;
    const processed = Number.isFinite(details.processed) ? details.processed : null;
    const roundedProgress = Math.round(progress);

    progressContainer.hidden = false;
    progressContainer.dataset.state = progress >= 100 ? 'complete' : 'active';
    progressBar.setAttribute('aria-valuenow', String(roundedProgress));

    if (details.label) {
        label.textContent = details.label;
    }

    if (null !== processed && null !== total) {
        count.textContent = `${processed} of ${total}`;
        text.textContent = `${roundedProgress}% complete`;
    } else {
        count.textContent = `${roundedProgress}%`;
        text.textContent = `${roundedProgress}% complete`;
    }

    bar.style.width = `${progress}%`;

    if (progress >= 100) {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
}

/**
 * Finds the current media-library toolbar host across WordPress layouts.
 *
 * @returns {HTMLElement|null}
 */
function getBatchToolbarHost() {
    const selectors = [
        '.attachments-browser .media-toolbar-primary',
        '.attachments-browser .media-toolbar-secondary',
        '.attachments-browser .media-toolbar-mode-select',
        '.attachments-browser .media-toolbar',
        '.media-frame-toolbar .media-toolbar-primary',
        '.media-frame-toolbar .media-toolbar-secondary',
        '.media-frame-toolbar .media-toolbar',
        '.mode-edit .media-toolbar',
    ];

    const seen = new Set();
    const candidates = [];

    for (const selector of selectors) {
        document.querySelectorAll(selector).forEach((element) => {
            if (!seen.has(element)) {
                seen.add(element);
                candidates.push(element);
            }
        });
    }

    for (const element of candidates) {
        if (isElementVisible(element)) {
            return element;
        }
    }

    return candidates[0] || null;
}

/**
 * Ensures the batch button exists in the active toolbar.
 *
 * @param {HTMLElement} mediaToolbar - The toolbar that should own the button.
 * @returns {HTMLButtonElement}
 */
function ensureBatchButton(mediaToolbar) {
    let batchButton = document.querySelector('.process-alt-text-batch');

    if (!batchButton) {
        batchButton = document.createElement('button');
        batchButton.className = 'button process-alt-text-batch';
        batchButton.type = 'button';
        batchButton.textContent = 'Generate Alt Text for Selected';
        batchButton.addEventListener('click', function(e) {
            processBatchSelection(e, this);
        });
    }

    if (batchButton.parentNode !== mediaToolbar) {
        mediaToolbar.appendChild(batchButton);
    }

    batchButton.hidden = false;

    return batchButton;
}

/**
 * Returns the currently selected media attachment IDs.
 *
 * @returns {string[]}
 */
function getSelectedAttachmentIds() {
    return Array.from(new Set(
        Array.from(document.querySelectorAll('.attachment.selected, .attachments .selected[data-id], [aria-selected="true"][data-id]'))
            .map((element) => element.dataset.id)
            .filter(Boolean)
    ));
}

/**
 * Schedules a batch-toolbar refresh after the current UI event finishes.
 *
 * @returns {void}
 */
function scheduleBatchProcessingRefresh() {
    window.requestAnimationFrame(() => {
        handleBatchProcessing();
    });
}

/**
 * Checks whether an element is currently visible in the layout.
 *
 * @param {Element} element - The element to inspect.
 * @returns {boolean}
 */
function isElementVisible(element) {
    if (!(element instanceof Element)) {
        return false;
    }

    const style = window.getComputedStyle(element);

    if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
        return false;
    }

    const rect = element.getBoundingClientRect();

    return rect.width > 0 && rect.height > 0;
}

/**
 * Finds a stable host element for the batch progress UI.
 *
 * @returns {HTMLElement|null}
 */
function getBatchProgressHost() {
    const selectors = [
        '.attachments-browser',
        '.media-frame-content',
        '.upload-php .wrap',
        '.wrap',
    ];

    for (const selector of selectors) {
        const element = document.querySelector(selector);
        if (element) {
            return element;
        }
    }

    return null;
}

/**
 * Returns the existing progress container or creates one in a resilient host.
 *
 * @returns {HTMLElement|null}
 */
function ensureProgressContainer() {
    let progressContainer = document.querySelector('.alt-text-progress-container');

    if (progressContainer) {
        return progressContainer;
    }

    const host = getBatchProgressHost();
    if (!host) {
        return null;
    }

    progressContainer = document.createElement('div');
    progressContainer.className = 'alt-text-progress-container';
    progressContainer.dataset.state = 'idle';
    progressContainer.hidden = true;
    progressContainer.innerHTML = `
        <div class="alt-text-progress-meta" role="status" aria-live="polite">
            <span class="progress-label">Generating alt text</span>
            <span class="progress-count">0 of 0</span>
        </div>
        <div class="alt-text-progress" role="progressbar" aria-label="Alt text generation progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <div class="progress-bar"></div>
            <div class="progress-text">0% complete</div>
        </div>
    `;

    const toolbar = getBatchToolbarHost();
    if (toolbar && toolbar.parentNode) {
        toolbar.insertAdjacentElement('afterend', progressContainer);
    } else {
        host.prepend(progressContainer);
    }

    return progressContainer;
}

/**
 * Marks the progress indicator as failed while preserving the last known count.
 *
 * @param {string} message - Error message to show in the indicator.
 * @param {Object} details - Additional progress metadata.
 */
function markProgressAsError(message, details = {}) {
    const progressContainer = ensureProgressContainer();

    if (!progressContainer) {
        return;
    }

    const label = progressContainer.querySelector('.progress-label');
    const count = progressContainer.querySelector('.progress-count');
    const text = progressContainer.querySelector('.progress-text');

    progressContainer.hidden = false;
    progressContainer.dataset.state = 'error';
    label.textContent = message;

    if (Number.isFinite(details.processed) && Number.isFinite(details.total)) {
        count.textContent = `${details.processed} of ${details.total}`;
    }

    text.textContent = 'Stopped before completion';
}

/**
 * Adds a character count and progress bar to a text input field with a maximum length.
 *
 * This code listens for the 'DOMContentLoaded' event, and then finds the text input
 * field with the ID 'alt-text-prompt-template' and the element with the ID 'char-count'.
 * It then creates a progress bar element and inserts it after the 'char-count' element.
 *
 * The code also adds an event listener to the text input field that updates the character
 * count and progress bar as the user types. If the user exceeds the maximum length of
 * 1000 characters, the input value is truncated and the progress bar is updated to
 * indicate that the limit has been reached.
 */
document.addEventListener('DOMContentLoaded', function() {
    const templateInput = document.getElementById('alt-text-prompt-template');
    const charCount = document.getElementById('char-count');
    const maxLength = 10000;

    if (templateInput && charCount) {
        // Add progress bar element
        const progressBar = document.createElement('div');
        progressBar.className = 'template-progress';
        progressBar.innerHTML = `
            <div class="progress-track">
                <div class="template-progress-bar"></div>
            </div>
        `;
        charCount.parentNode.appendChild(progressBar);

        const bar = progressBar.querySelector('.template-progress-bar');

        function updateCounter() {
            const length = templateInput.value.length;
            charCount.textContent = length;

            if (length > maxLength) {
                templateInput.value = templateInput.value.substring(0, maxLength);
                charCount.textContent = maxLength;
            }

            // Update progress bar and colors
            const progress = (length / maxLength) * 100;
            bar.style.width = `${progress}%`;

            if (length >= maxLength) {
                bar.style.backgroundColor = '#dc3232';
                charCount.title = 'Character limit reached';
            } else if (length >= maxLength * 0.9) {
                bar.style.backgroundColor = '#dba617';
                charCount.title = 'Approaching character limit';
            } else {
                bar.style.backgroundColor = '#666666';
                charCount.title = '';
            }
        }

        templateInput.setAttribute('maxlength', maxLength);
        templateInput.addEventListener('input', updateCounter);
        updateCounter();
    }
});

class AutoAltTextBulkProcessor {
    constructor() {
        this.progressBar = document.querySelector('.aat-progress-bar');
        this.progressText = document.querySelector('.aat-progress-text');
        this.processButton = document.querySelector('.aat-process-button');
    }

    /**
     * Processes a batch of attachment IDs asynchronously.
     *
     * This method first shows the progress bar, then iterates through the provided
     * attachment IDs, processing each image and updating the progress. Finally, it
     * hides the progress bar.
     *
     * @param {string[]} attachmentIds - An array of attachment IDs to process.
     * @returns {Promise<void>} - A Promise that resolves when the batch processing is complete.
     */
    async processBatch(attachmentIds) {
        this.showProgress();

        for (let i = 0; i < attachmentIds.length; i++) {
            const response = await this.processImage(attachmentIds[i]);
            this.updateProgress(response);
        }

        this.hideProgress();
    }

    /**
     * Updates the progress bar and progress text based on the provided response.
     *
     * @param {Object} response - An object containing the progress information.
     * @param {number} response.percentage - The percentage of the total progress.
     * @param {number} response.processed - The number of images processed so far.
     * @param {number} response.total - The total number of images to process.
     */
    updateProgress(response) {
        this.progressBar.style.width = `${response.percentage}%`;
        this.progressText.textContent = `Processing: ${response.processed} of ${response.total} images`;
    }

    /**
     * Shows the progress bar and disables the process button.
     */
    showProgress() {
        this.progressBar.style.display = 'block';
        this.processButton.disabled = true;
    }

    /**
     * Hides the progress bar and re-enables the process button.
     */
    hideProgress() {
        this.processButton.disabled = false;
    }
}
