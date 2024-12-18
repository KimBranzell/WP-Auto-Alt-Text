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

  loader.style.display = 'inline-block';
  fetch(ajaxurl, {
      method: 'POST',
      headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
          action: 'generate_alt_text_for_attachment',
          attachment_id: attachmentId,
          nonce: nonce
      })
  })
  .then(response => response.json())
  .then(data => {
      if (data.success && data.data.alt_text) {
          const altTextField = document.querySelector('#attachment-details-two-column-alt-text');
          if (altTextField) {
              altTextField.value = data.data.alt_text;
          }
      }
  })
  .catch(error => console.error('Error:', error))
  .finally(() => {
      loader.style.display = 'none';
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
