document.addEventListener('DOMContentLoaded', function() {
  document.addEventListener('click', function(event) {
    if (event.target && event.target.classList.contains('generate-alt-text-button')) {
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
  });
});

document.addEventListener('DOMContentLoaded', function() {
  const observer = new MutationObserver((mutations, obs) => {
      const mediaToolbar = document.querySelector('.media-toolbar-mode-select');
      if (mediaToolbar && !mediaToolbar.querySelector('.process-alt-text-batch')) {
          const batchButton = document.createElement('button');
          batchButton.className = 'button process-alt-text-batch';
          batchButton.textContent = 'Generate Alt Text for Selected';
          mediaToolbar.appendChild(batchButton);

          // Handle batch processing
          batchButton.addEventListener('click', function(e) {
            e.preventDefault();

            const selected = Array.from(document.querySelectorAll('.attachment.selected'))
                .map(el => el.dataset.id);

            if (selected.length === 0) {
                alert('Please select images first');
                return;
            }

            // Add loading state
            const originalText = this.textContent;
            this.textContent = 'Generating...';
            this.disabled = true;

            fetch(autoAltTextData.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'process_image_batch',
                    nonce: autoAltTextData.nonce,
                    ids: JSON.stringify(selected)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Alt text generated for ${Object.keys(data.data).length} images`);
                    // Refresh the Media Library view
                    window.location.reload();
                }
            })
            .catch(error => console.error('Error:', error))
            .finally(() => {
                // Reset button state
                this.textContent = originalText;
                this.disabled = false;
            });
        });
      }
  });

  observer.observe(document.body, {
      childList: true,
      subtree: true
  });
});

