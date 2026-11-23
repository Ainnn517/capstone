// Photo viewer: report photos (and a report's video) open full screen on the same page instead
// of a new browser tab, with a Back button, previous/next for reports with several photos, and a
// "2 / 3" counter. A link with data-video plays the video instead of showing photos.
// Closes with Back, Escape, a tap on the dark background, or the phone/browser back button.
//
// Usage: <a href="/api/attachments/5" data-photo-viewer data-photos="/api/attachments/5,/api/attachments/6"
//           data-index="0" data-label="Photo for BR-123">...</a>
// The link still works normally (new tab) with Ctrl/Cmd-click or if this script fails to load.
(function () {
  let viewer = null;
  let photos = [];
  let index = 0;
  let label = '';
  let returnFocus = null;
  let pushedHistory = false;

  function build() {
    viewer = document.createElement('div');
    viewer.className = 'photo-viewer';
    viewer.hidden = true;
    viewer.setAttribute('role', 'dialog');
    viewer.setAttribute('aria-modal', 'true');
    viewer.setAttribute('aria-label', 'Photo viewer');
    viewer.innerHTML = `
      <div class="photo-viewer-bar">
        <button type="button" class="photo-viewer-back" data-pv="close">
          <span aria-hidden="true">&larr;</span> Back
        </button>
        <span class="photo-viewer-count" aria-live="polite"></span>
      </div>
      <div class="photo-viewer-stage" data-pv="backdrop">
        <button type="button" class="photo-viewer-nav photo-viewer-prev" data-pv="prev" aria-label="Previous photo">&lsaquo;</button>
        <img class="photo-viewer-img" alt="">
        <video class="photo-viewer-video" controls playsinline preload="metadata" hidden></video>
        <button type="button" class="photo-viewer-nav photo-viewer-next" data-pv="next" aria-label="Next photo">&rsaquo;</button>
      </div>`;
    document.body.appendChild(viewer);

    viewer.addEventListener('click', event => {
      const action = event.target.closest('[data-pv]')?.dataset.pv;
      // Background tap closes only when the dark area itself was tapped, not the photo or video.
      if (action === 'backdrop' && event.target !== event.currentTarget.querySelector('.photo-viewer-stage')) return;
      if (action === 'close' || action === 'backdrop') close();
      if (action === 'prev') show(index - 1);
      if (action === 'next') show(index + 1);
    });

    // Swipe left/right on phones to change photos.
    let startX = null;
    viewer.addEventListener('touchstart', event => { startX = event.touches[0].clientX; }, { passive: true });
    viewer.addEventListener('touchend', event => {
      if (startX === null) return;
      const dx = event.changedTouches[0].clientX - startX;
      startX = null;
      if (Math.abs(dx) > 50 && photos.length > 1) show(index + (dx < 0 ? 1 : -1));
    });
  }

  function show(next) {
    index = (next + photos.length) % photos.length;
    const img = viewer.querySelector('.photo-viewer-img');
    img.src = photos[index];
    img.alt = photos.length > 1 ? `${label} (${index + 1} of ${photos.length})` : label;
    const many = photos.length > 1;
    viewer.querySelector('.photo-viewer-count').textContent = many ? `${index + 1} / ${photos.length}` : '';
    viewer.querySelectorAll('.photo-viewer-nav').forEach(button => { button.hidden = !many; });
  }

  function open(link) {
    if (!viewer) build();
    const isVideo = link.hasAttribute('data-video');
    const img = viewer.querySelector('.photo-viewer-img');
    const video = viewer.querySelector('.photo-viewer-video');
    img.hidden = isVideo;
    video.hidden = !isVideo;
    viewer.setAttribute('aria-label', isVideo ? 'Video player' : 'Photo viewer');
    returnFocus = link;
    viewer.hidden = false;
    document.documentElement.classList.add('photo-viewer-open');
    if (isVideo) {
      photos = [];
      label = link.dataset.label || 'Report video';
      video.src = link.getAttribute('href');
      video.setAttribute('aria-label', label);
      viewer.querySelector('.photo-viewer-count').textContent = '';
      viewer.querySelectorAll('.photo-viewer-nav').forEach(button => { button.hidden = true; });
      video.play().catch(() => {});
    } else {
      photos = (link.dataset.photos || link.getAttribute('href')).split(',').filter(Boolean);
      label = link.dataset.label || 'Report photo';
      show(Number(link.dataset.index) || 0);
    }
    viewer.querySelector('.photo-viewer-back').focus();
    // So the phone's back button closes the photo instead of leaving the page.
    history.pushState({ photoViewer: true }, '');
    pushedHistory = true;
  }

  function close(fromHistory = false) {
    if (!viewer || viewer.hidden) return;
    viewer.hidden = true;
    viewer.querySelector('.photo-viewer-img').removeAttribute('src');
    const video = viewer.querySelector('.photo-viewer-video');
    video.pause();
    video.removeAttribute('src');
    video.load();
    document.documentElement.classList.remove('photo-viewer-open');
    if (pushedHistory && !fromHistory) history.back();
    pushedHistory = false;
    returnFocus?.focus();
  }

  document.addEventListener('click', event => {
    const link = event.target.closest('a[data-photo-viewer]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    open(link);
  });

  window.addEventListener('popstate', () => close(true));

  // Capture phase so Escape closes only the photo, not the report window behind it.
  document.addEventListener('keydown', event => {
    if (!viewer || viewer.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); close(); }
    if (event.key === 'ArrowLeft' && photos.length > 1) show(index - 1);
    if (event.key === 'ArrowRight' && photos.length > 1) show(index + 1);
    // Keep keyboard focus inside the viewer.
    if (event.key === 'Tab') {
      const items = [...viewer.querySelectorAll('button:not([hidden]), video:not([hidden])')];
      const pos = items.indexOf(document.activeElement);
      event.preventDefault();
      items[(pos + (event.shiftKey ? -1 : 1) + items.length) % items.length].focus();
    }
  }, true);
})();
