document.addEventListener('click', async function({ target }) {
  if(target && target.id == 'generate-alt-text') {
    event.preventDefault();

    let attachmentId = target.getAttribute('data-attachment-id');
    let nonce = target.getAttribute('data-nonce');

    document.getElementsByClassName('loader')[0].style.display = 'inline-block';

    try {
      let response = await fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=generate_alt_text_for_attachment&attachment_id=${attachmentId}&nonce=${nonce}`
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to generate alt text');
      }

      document.querySelector('textarea[id="attachment-details-two-column-alt-text"]').value = data.alt_text;

      // Show success message
      const notice = document.createElement('div');
      notice.className = 'notice notice-success is-dismissible';
      notice.innerHTML = '<p>Alt text successfully generated!</p>';
      document.querySelector('.wrap').prepend(notice);

    } catch (error) {
      console.error('Error:', error);

      // Show error message
      const notice = document.createElement('div');
      notice.className = 'notice notice-error is-dismissible';
      notice.innerHTML = `<p>Error: ${error.message}</p>`;
      document.querySelector('.wrap').prepend(notice);
    } finally {
      document.getElementsByClassName('loader')[0].style.display = 'none';
    }
  }
});

document.addEventListener('DOMContentLoaded', function() {
  // Add batch processing button
  const mediaToolbar = document.querySelector('.media-toolbar');
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

      fetch(autoAltTextData.ajaxurl, {
          method: 'POST',
          headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: new URLSearchParams({
              action: 'process_image_batch',
              nonce: autoAltTextData.nonce,
              ids: selected
          })
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              alert(`Alt text generated for ${Object.keys(data.data).length} images`);
          }
      })
      .catch(error => console.error('Error:', error));
  });
});
