/**
 * Serenity Spaces — Dashboard JS
 */

'use strict';

// ── Copy invite link ───────────────────────────────────────────
function copyLink(url, btn) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(() => {
            const orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = orig; }, 2000);
        }).catch(() => fallbackCopy(url, btn));
    } else {
        fallbackCopy(url, btn);
    }
}

function fallbackCopy(url, btn) {
    const ta = document.createElement('textarea');
    ta.value = url;
    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 2000);
    } catch (e) {
        alert('Copy failed — please copy the link manually.');
    }
    document.body.removeChild(ta);
}

window.copyLink = copyLink;

// ── Background preview on file select ─────────────────────────
function previewBg(input) {
    const file = input.files[0];
    if (!file) return;

    const wrap      = document.getElementById('bg-preview-wrap');
    const imgEl     = document.getElementById('bg-preview');
    const videoEl   = document.getElementById('bg-preview-video');
    const isVideo   = file.type.startsWith('video/');

    const url = URL.createObjectURL(file);
    if (isVideo) {
        if (imgEl)   { imgEl.style.display   = 'none'; imgEl.src = ''; }
        if (videoEl) { videoEl.src = url; videoEl.style.display = 'block'; }
    } else {
        if (videoEl) { videoEl.style.display = 'none'; videoEl.src = ''; }
        if (imgEl)   { imgEl.src = url; imgEl.style.display = 'block'; }
    }
    if (wrap) wrap.style.display = 'block';

    // Clear library selection since we're uploading a new one
    clearLibrarySelection();
    const bgSelectedId = document.getElementById('bg_selected_id');
    if (bgSelectedId) bgSelectedId.value = '';
}
window.previewBg = previewBg;

// ── Background library selection ───────────────────────────────
function selectBg(el, bgId) {
    clearLibrarySelection();
    el.style.borderColor = 'var(--accent)';
    el.style.boxShadow   = '0 0 10px var(--accent-glow)';

    const bgSelectedId = document.getElementById('bg_selected_id');
    if (bgSelectedId) bgSelectedId.value = bgId;

    // Clear file input and preview
    const bgInput = document.getElementById('background');
    if (bgInput) bgInput.value = '';
    const preview = document.getElementById('bg-preview');
    if (preview) {
        preview.style.display = 'none';
        preview.src = '';
    }
}
window.selectBg = selectBg;

function clearLibrarySelection() {
    document.querySelectorAll('#bg-library .bg-thumb').forEach(t => {
        t.style.borderColor = '';
        t.style.boxShadow   = '';
    });
}

// ── Background library tabs ────────────────────────────────────
let dashBgTab = 'images';

function switchDashTab(tab) {
    dashBgTab = tab;
    const imgBtn = document.getElementById('dash-tab-images');
    const vidBtn = document.getElementById('dash-tab-videos');
    if (imgBtn) imgBtn.classList.toggle('active', tab === 'images');
    if (vidBtn) vidBtn.classList.toggle('active', tab === 'videos');

    // Clear any selection and the hidden bg_selected_id when switching tabs
    clearLibrarySelection();
    const bgSelectedId = document.getElementById('bg_selected_id');
    if (bgSelectedId) bgSelectedId.value = '';

    document.querySelectorAll('#bg-library .bg-thumb').forEach(thumb => {
        const type = thumb.dataset.mimeType || 'image';
        thumb.style.display = (tab === 'images' ? type === 'image' : type === 'video') ? '' : 'none';
    });
}
window.switchDashTab = switchDashTab;

function switchDashLibTab(tab) {
    const imgBtn = document.getElementById('dash-lib-tab-images');
    const vidBtn = document.getElementById('dash-lib-tab-videos');
    if (imgBtn) imgBtn.classList.toggle('active', tab === 'images');
    if (vidBtn) vidBtn.classList.toggle('active', tab === 'videos');
    document.querySelectorAll('#bg-library-main .bg-thumb').forEach(thumb => {
        const type = thumb.dataset.mimeType || 'image';
        thumb.style.display = (tab === 'images' ? type === 'image' : type === 'video') ? '' : 'none';
    });
}
window.switchDashLibTab = switchDashLibTab;

// Run on load to filter both grids to images by default
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('bg-library'))      switchDashTab('images');
    if (document.getElementById('bg-library-main')) switchDashLibTab('images');
});

// ── Video thumbnail extraction ─────────────────────────────────
function extractVideoThumbnail(file) {
    return new Promise(resolve => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.muted   = true;
        const url = URL.createObjectURL(file);
        video.src = url;
        video.addEventListener('loadeddata', () => { video.currentTime = 0.067; });
        video.addEventListener('seeked', () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width  = video.videoWidth  || 320;
                canvas.height = video.videoHeight || 180;
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.7);
                URL.revokeObjectURL(url);
                resolve(dataUrl);
            } catch { URL.revokeObjectURL(url); resolve(null); }
        }, { once: true });
        video.addEventListener('error', () => { URL.revokeObjectURL(url); resolve(null); });
    });
}
window.extractVideoThumbnail = extractVideoThumbnail;

// ── Upload progress bar for room creation form ─────────────────
document.addEventListener('DOMContentLoaded', () => {
    const form        = document.getElementById('create-room-form');
    const progressWrap= document.getElementById('bg-upload-progress');
    const progressBar = document.getElementById('bg-upload-bar');
    const progressPct = document.getElementById('bg-upload-pct');

    if (form) {
        form.addEventListener('submit', async (e) => {
            const bgInput = document.getElementById('background');
            if (!bgInput || !bgInput.files || bgInput.files.length === 0) return; // let normal submit handle no-file case

            e.preventDefault();
            const formData = new FormData(form);

            // Attach thumbnail for video uploads
            const bgFile = bgInput.files[0];
            if (bgFile && bgFile.type.startsWith('video/')) {
                const thumb = await extractVideoThumbnail(bgFile);
                if (thumb) formData.append('thumb_data', thumb);
            }

            const xhr      = new XMLHttpRequest();

            if (progressWrap && progressBar && progressPct) {
                progressWrap.style.display = 'block';
                progressBar.style.width    = '0%';
                progressPct.textContent    = '0%';
            }

            xhr.upload.addEventListener('progress', (ev) => {
                if (ev.lengthComputable && progressBar && progressPct) {
                    const pct = Math.round((ev.loaded / ev.total) * 100);
                    progressBar.style.width = pct + '%';
                    progressPct.textContent = pct + '%';
                }
            });

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 400) {
                    window.location.reload();
                } else {
                    if (progressWrap) progressWrap.style.display = 'none';
                    alert('Upload failed (server returned ' + xhr.status + '). Check that your server allows large file uploads.');
                }
            });

            xhr.addEventListener('error', () => {
                if (progressWrap) progressWrap.style.display = 'none';
                alert('Upload failed — the server closed the connection. This usually means the file exceeds your server\'s PHP upload limit (upload_max_filesize / post_max_size). Please check your PHP configuration.');
            });

            // Use window.location.href — form.action is shadowed by the hidden <input name="action">
            xhr.open('POST', window.location.href);
            xhr.send(formData);
        });
    }
});

// ── Flash messages auto-dismiss ────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const alerts = document.querySelectorAll('.alert-success');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity    = '0';
            setTimeout(() => alert.remove(), 500);
        }, 4000);
    });
});
