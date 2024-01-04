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

      if (response.ok) {
        let text = await response.text();
        document.querySelector('textarea[id="attachment-details-two-column-alt-text"]').value = text.trim();
      }
    } catch (error) {
      console.error('Error:', error);
    } finally {
      document.getElementsByClassName('loader')[0].style.display = 'none';
    }
  }
});