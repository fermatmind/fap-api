(() => {
  const button = document.getElementById('open-blog-layout');
  const status = document.getElementById('blog-preview-status');
  if (!button || !status) return;
  const target = new URL(button.dataset.previewUrl);
  const payload = JSON.parse(document.getElementById('blog-preview-data').textContent);
  let previewWindow = null;
  window.addEventListener('message', (event) => {
    if (event.origin !== target.origin || event.source !== previewWindow
      || event.data?.type !== 'fermatmind.blog-preview.ready.v1') return;
    previewWindow.postMessage(payload, target.origin);
    status.textContent = 'Layout configuration sent to the preview. Check the matching configuration SHA256 there.';
  });
  button.addEventListener('click', () => {
    previewWindow = window.open(target.href, '_blank');
    if (!previewWindow) status.textContent = 'Allow this preview tab to open, then try again.';
  });
})();
