document.addEventListener('DOMContentLoaded', function() {
  // Add bulk processing initialization
  const urlParams = new URLSearchParams(window.location.search);
  const bulkIds = urlParams.get('bulk_ids');

  // Single image alt text generation
  document.addEventListener('click', function(event) {
      if (event.target && event.target.classList.contains('generate-alt-text-button')) {
          handleSingleImageGeneration(event);
      }
  });

  // Batch processing observer
  const observer = new MutationObserver((mutations, obs) => {
      handleBatchProcessing(obs);
  });

  const cleanupForm = document.querySelector('form[name="cleanup_stats"]');
  if (cleanupForm) {
      cleanupForm.addEventListener('submit', function(e) {
          if (!confirm('Are you sure you want to remove all generation records for deleted images?')) {
              e.preventDefault();
          }
      });
  }

  observer.observe(document.body, {
      childList: true,
      subtree: true
  });

  if (bulkIds) {
      const ids = bulkIds.split(',');
      const totalImages = ids.length;
      let processed = 0;

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
                  action: 'process_alt_text_batch',
                  ids: batch,
                  nonce: autoAltTextData.nonce
              })
          })
          .then(response => response.json())
          .then(data => {
              processed += batch.length;
              const progress = (processed / totalImages) * 100;
              updateProgressBar(progress);
              processBatch();
          });
      }

      processBatch();
  }
});

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

function showError(message) {
    const notice = document.createElement('div');
    notice.className = 'notice notice-error is-dismissible';
    notice.innerHTML = `<p>${message}</p>`;
    document.querySelector('.wrap').insertBefore(notice, document.querySelector('.wrap').firstChild);
}
function showPreviewDialog(altText, attachmentId, isCached) {

    const button = document.querySelector(`[data-attachment-id="${attachmentId}"]`);
    const nonce = button.dataset.nonce;  // Get nonce from original button

    const dialog = document.createElement('div');
    dialog.className = 'alt-text-preview-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'preview-title');

    dialog.innerHTML = `
        <div class="alt-text-preview-content">
            <h3>Preview Generated Alt Text ${isCached ? '<span class="cached-badge">Cached</span>' : ''}</h3>
            <textarea class="preview-text">${altText}</textarea>
            <div class="preview-actions">
                <button class="button button-primary apply-alt-text">Apply</button>
                ${isCached ? '<button class="button regenerate-alt-text">Generate New</button>' : ''}
                <button class="button button-secondary cancel-alt-text">Cancel</button>
            </div>
        </div>
    `;

    document.body.appendChild(dialog);
    const textarea = dialog.querySelector('.preview-text');
    textarea.focus();
    textarea.setSelectionRange(0, textarea.value.length);

    dialog.querySelector('.apply-alt-text').addEventListener('click', () => {
        const finalText = dialog.querySelector('.preview-text').value;
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

    // Close on escape
    document.addEventListener('keydown', function handleEscape(e) {
        if (e.key === 'Escape') {
            dialog.remove();
            document.removeEventListener('keydown', handleEscape);
        }
    });
}


function handleBatchProcessing(obs) {
  const mediaToolbar = document.querySelector('.media-toolbar-mode-select');
  if (mediaToolbar && !mediaToolbar.querySelector('.process-alt-text-batch')) {
      const batchButton = document.createElement('button');
      batchButton.className = 'button process-alt-text-batch';
      batchButton.textContent = 'Generate Alt Text for Selected';
      mediaToolbar.appendChild(batchButton);

      batchButton.addEventListener('click', function(e) {
          processBatchSelection(e, this);
      });
  }
}

function processBatchSelection(e, button) {
    e.preventDefault();

    const selected = Array.from(document.querySelectorAll('.attachment.selected'))
        .map(el => el.dataset.id);

    if (selected.length === 0) {
        alert('Please select images first');
        return;
    }

    const originalText = button.textContent;
    button.textContent = 'Generating...';
    button.disabled = true;

    // Initialize progress bar at 0%
    updateProgressBar(0);

    // Process images in batches with progress bar
    const ids = [...selected];
    const totalImages = ids.length;
    let processed = 0;
    const batchSize = 1;

    function processBatch() {
        const batch = ids.splice(0, batchSize);
        if (batch.length === 0) {
            setTimeout(() => {
                updateProgressBar(100);
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
            updateProgressBar(progress);
            setTimeout(processBatch, 100); // Add small delay between batches
        })
        .catch(error => console.error('Error:', error))
        .finally(() => {
            if (ids.length === 0) {
                button.textContent = originalText;
                button.disabled = false;
            }
        });
    }

    processBatch();
}


// Add updateProgressBar function
function updateProgressBar(progress) {
    let progressBar = document.querySelector('.alt-text-progress');

    if (!progressBar) {
        const progressContainer = document.createElement('div');
        progressContainer.className = 'alt-text-progress-container';
        progressContainer.innerHTML = `
            <div class="alt-text-progress">
                <div class="progress-bar"></div>
                <div class="progress-text">0%</div>
            </div>
        `;

        const batchButton = document.querySelector('.process-alt-text-batch');
        batchButton.insertAdjacentElement('afterend', progressContainer);
        progressBar = progressContainer.querySelector('.alt-text-progress');
    }

    const bar = progressBar.querySelector('.progress-bar');
    const text = progressBar.querySelector('.progress-text');

    bar.style.width = `${progress}%`;
    text.textContent = `${Math.round(progress)}%`;

    if (progress >= 100) {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const templateInput = document.getElementById('alt-text-prompt-template');
    const charCount = document.getElementById('char-count');
    const maxLength = 1000;

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
