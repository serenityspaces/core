/**
 * Serenity Spaces — Room JS
 * Handles long-polling, avatar drag, chat, highlights, pins, notes.
 */

'use strict';

// ── Role icon helper ───────────────────────────────────────────
// Returns an <img> element for the practitioner's role icon (host) or user.png (client).
function makeRoleIconEl(participantId) {
    const img = document.createElement('img');
    img.alt = '';
    img.style.cssText = 'width:22px;height:22px;object-fit:contain;vertical-align:middle;margin-right:5px;flex-shrink:0;';
    const pData = participants.get(participantId);
    if (cfg.hostParticipantId && participantId === cfg.hostParticipantId) {
        const icon = cfg.practitionerRoleIcon || 'ribbon';
        img.src       = '/assets/role_icons/' + icon + '.png';
        img.className = 'practitioner-role-icon';
    } else if (pData && pData.is_ai) {
        img.src       = '/assets/role_icons/ai.png';
        img.className = 'ai-role-icon';
    } else {
        img.src       = '/assets/role_icons/user.png';
        img.className = 'user-role-icon';
    }
    return img;
}

// ── State ──────────────────────────────────────────────────────
const cfg         = window.ROOM_CONFIG;
const participants = new Map();   // id -> participant data + DOM refs
let   messages     = new Map();   // id -> message data
let   pins         = new Map();   // message_id -> pin data
let   lastEventId   = cfg.lastEventId;
let   pollTimeout   = null;
let   notesSaveTimer = null;
let   selectedNoteParticipantId = null;
let   ctxMenuTargetJoinToken    = null;
let   selectionMsgId  = null;
let   selectionStart  = 0;
let   selectionEnd    = 0;
let   reactTargetMsgId = null;
let   reactHoverTimer  = null;
let   pinPanelOpen    = true;
let   typingTimer     = null;
let   isTypingActive  = false;
const typingHideTimers = new Map(); // participantId → timeout id
let   notingTimer     = null;
let   isNotingActive  = false;
const notingHideTimers = new Map(); // participantId → timeout id
let   pollActive      = true;

// ── Crisis Resources ───────────────────────────────────────────
let crisisResources   = [];
let crisisPanelOpen   = false;

// ── Concept Tags ───────────────────────────────────────────────
let conceptTags       = cfg.conceptTagLibrary ? [...cfg.conceptTagLibrary] : [];
let activeTagPopover  = null;
let tagPopoverTarget  = null;  // { type: 'message'|'note', id, participantId }
const activeBubbles   = new Map();   // participantId -> active chat bubble el
let   userScrolledUp  = false;       // true when user has scrolled up in chat
let   unreadCount     = 0;           // new messages arrived while scrolled up
const participantPresence = new Map(); // participantId → presence status from server
const participantPresenceState = new Map(); // participantId → 'online'|'dim'|'disconnected'

// ── Rich feature state ─────────────────────────────────────────
let voiceRecording     = false;
let mediaRecorder      = null;
let recordedChunks     = [];
let voiceBlob          = null;
let webcamActive       = false;
let webcamStream       = null;
let webcamInterval     = null;
let webcamOriginalPath = null;
const webcamCanvas     = document.createElement('canvas');
const webcamFrameSeq   = new Map(); // participant_id → seq, for cancelling stale timeouts
let pendingFile        = null;
let fileDisclaimerShown = false;
let emojiPickerEl      = null;
let emojiPickerOpen    = false;
let bgPickerLoaded     = false;
let allBgs             = [];
let currentBgTab       = 'images';

// ── DOM refs ───────────────────────────────────────────────────
const roomEl       = document.getElementById('room');
const mainEl       = document.getElementById('main');
const dividerEl    = document.getElementById('horizontal-divider');
const chatPaneEl   = document.getElementById('chat-pane');
const messagesEl   = document.getElementById('messages');
const chatInput    = document.getElementById('chat-input');
const sendBtn      = document.getElementById('send-btn');
const userListEl   = document.getElementById('user-list');
const hlToolbar    = document.getElementById('highlight-toolbar');
const ctxMenu      = document.getElementById('ctx-menu');
const avatarFileInput = document.getElementById('avatar-file-input');

// Track whether user has manually scrolled up in chat (suppress auto-scroll if so)
messagesEl.addEventListener('scroll', () => {
    const distFromBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight;
    userScrolledUp = distFromBottom > 80;
    if (!userScrolledUp) { unreadCount = 0; updateNewMsgPill(); }
});

// ── Room dimension helpers ─────────────────────────────────────
function roomW() { return roomEl.offsetWidth  || 800; }
function roomH() { return roomEl.offsetHeight || 500; }

// ── Avatar preset URIs ─────────────────────────────────────────
const avatarPresets = cfg.avatarPresets;

function resolveAvatarUrl(path) {
    if (!path) return avatarPresets['Default'];
    if (path.startsWith('preset:')) {
        const key = path.slice(7);
        return avatarPresets[key] || avatarPresets['Default'];
    }
    return path;
}

// ── Init room height ───────────────────────────────────────────
function setRoomHeight(pct) {
    mainEl.style.setProperty('--room-height', pct + '%');
    roomEl.style.height     = `calc(${pct}% - 10px)`;
    dividerEl.style.top     = `calc(${pct}%)`;
    chatPaneEl.style.height = `calc(100% - ${pct}% - 26px)`;
}
setRoomHeight(60);

// ── Sidebar toggle (mobile) ─────────────────────────────────────
const sidebarEl      = document.getElementById('sidebar');
const sidebarToggle  = document.getElementById('sidebar-toggle');

function createSidebarBackdrop() {
    let bd = document.getElementById('sidebar-backdrop');
    if (!bd) {
        bd = document.createElement('div');
        bd.id = 'sidebar-backdrop';
        document.body.appendChild(bd);
    }
    return bd;
}

function openSidebar() {
    sidebarEl.classList.add('sidebar-open');
    const bd = createSidebarBackdrop();
    bd.classList.add('visible');
    bd.onclick = closeSidebar;
}

function closeSidebar() {
    sidebarEl.classList.remove('sidebar-open');
    const bd = document.getElementById('sidebar-backdrop');
    if (bd) bd.classList.remove('visible');
}

function isMobile() { return window.innerWidth <= 768; }

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        if (sidebarEl.classList.contains('sidebar-open')) closeSidebar();
        else openSidebar();
    });
}

// Swipe-to-close on sidebar (touch right-to-left drag inside sidebar)
(function() {
    let swipeStartX = 0, swipeStartY = 0, swipeActive = false;
    sidebarEl.addEventListener('touchstart', e => {
        swipeStartX = e.touches[0].clientX;
        swipeStartY = e.touches[0].clientY;
        swipeActive = true;
    }, {passive: true});
    sidebarEl.addEventListener('touchmove', e => {
        if (!swipeActive) return;
        const dx = e.touches[0].clientX - swipeStartX;
        const dy = e.touches[0].clientY - swipeStartY;
        if (Math.abs(dx) > Math.abs(dy) && dx > 40) {
            closeSidebar();
            swipeActive = false;
        }
    }, {passive: true});
    sidebarEl.addEventListener('touchend', () => { swipeActive = false; }, {passive: true});

    // Swipe from right edge of screen to open sidebar
    document.addEventListener('touchstart', e => {
        const touch = e.touches[0];
        if (touch.clientX > window.innerWidth - 44 && isMobile()) {
            swipeStartX = touch.clientX;
            swipeStartY = touch.clientY;
            swipeActive = true;
        }
    }, {passive: true});
    document.addEventListener('touchmove', e => {
        if (!swipeActive || sidebarEl.classList.contains('sidebar-open')) return;
        const dx = swipeStartX - e.touches[0].clientX;
        const dy = e.touches[0].clientY - swipeStartY;
        if (dx > 30 && Math.abs(dy) < 60) {
            openSidebar();
            swipeActive = false;
        }
    }, {passive: true});
})();

// ── Room collapse toggle ─────────────────────────────────────────
const roomCollapseBtn  = document.getElementById('room-collapse-btn');
let   roomCollapsed    = false;

function toggleRoomCollapse() {
    roomCollapsed = !roomCollapsed;
    if (roomCollapsed) {
        mainEl.classList.add('room-collapsed');
        // Sync bg mirror for image backgrounds
        const mirror = document.getElementById('room-bg-mirror');
        if (mirror && cfg.backgroundPath && !cfg.backgroundMime?.startsWith('video/')) {
            mirror.style.backgroundImage = `url('${cfg.backgroundPath}')`;
        }
    } else {
        mainEl.classList.remove('room-collapsed');
    }
}

if (roomCollapseBtn) {
    roomCollapseBtn.addEventListener('click', e => {
        e.stopPropagation();
        toggleRoomCollapse();
    });
}

// ── Divider drag ───────────────────────────────────────────────
function applyDividerDrag(clientY) {
    const rect = mainEl.getBoundingClientRect();
    let pct = (clientY - rect.top) / rect.height * 100;
    pct = Math.max(15, Math.min(85, pct));
    setRoomHeight(pct);
    // Un-collapse if dragging while collapsed
    if (roomCollapsed) {
        roomCollapsed = false;
        mainEl.classList.remove('room-collapsed');
    }
}

dividerEl.addEventListener('mousedown', function(e) {
    // Ignore clicks on the collapse button itself
    if (e.target === roomCollapseBtn || roomCollapseBtn?.contains(e.target)) return;
    document.body.style.cursor = 'row-resize';
    const onMove = function(e) { applyDividerDrag(e.clientY); };
    const onUp   = function()  {
        document.body.style.cursor = '';
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    e.preventDefault();
});

// Touch drag for divider
dividerEl.addEventListener('touchstart', function(e) {
    if (e.target === roomCollapseBtn || roomCollapseBtn?.contains(e.target)) return;
    const onMove = t => applyDividerDrag(t.touches[0].clientY);
    const onEnd  = () => {
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onEnd);
    };
    document.addEventListener('touchmove', onMove, {passive: false});
    document.addEventListener('touchend', onEnd, {passive: true});
    e.preventDefault();
}, {passive: false});

// ── Highlight text rendering ───────────────────────────────────
/**
 * Apply highlight spans to plain text using character offsets.
 * Multiple (possibly overlapping) highlights are supported.
 * Returns HTML string with <mark> tags.
 */
// Linkify a plain text segment (call escHtml internally)
function linkifySegment(text) {
    const urlRegex = /https?:\/\/[^\s<>"']+/g;
    let result = '';
    let lastIndex = 0;
    let match;
    while ((match = urlRegex.exec(text)) !== null) {
        result += escHtml(text.slice(lastIndex, match.index));
        const url = match[0];
        result += `<a href="${escHtml(url)}" target="_blank" rel="noopener noreferrer">${escHtml(url)}</a>`;
        lastIndex = match.index + url.length;
    }
    result += escHtml(text.slice(lastIndex));
    return result;
}

function renderTextWithHighlights(text, highlights) {
    if (!highlights || highlights.length === 0) {
        return linkifySegment(text);
    }

    const sorted = [...highlights].sort((a, b) => +a.start_offset - +b.start_offset);
    const len = text.length;
    let html = '';
    let pos  = 0;

    for (const hl of sorted) {
        const start = Math.min(+hl.start_offset, len);
        const end   = Math.min(+hl.end_offset, len);
        if (start >= end) continue;
        if (start > pos) {
            html += linkifySegment(text.slice(pos, start));
        }
        const cls = 'hl-' + (hl.color || 'yellow');
        html += `<mark class="${cls}">${escHtml(text.slice(start, end))}</mark>`;
        pos = end;
    }
    if (pos < len) html += linkifySegment(text.slice(pos));
    return html;
}

// Strip embed URLs from display text (so they don't render as plain links)
function stripEmbedUrls(text) {
    return text
        .replace(/https?:\/\/(?:www\.)?(?:youtube\.com\/watch|youtu\.be\/)[^\s]*/g, '')
        .replace(/https?:\/\/open\.spotify\.com\/(?:track|album|playlist|episode)\/[^\s]*/g, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
}

// Extract YouTube and Spotify embed descriptors — uses URL API for reliable param parsing
function extractEmbeds(text) {
    const embeds = [];
    const urlRegex = /https?:\/\/[^\s]+/g;
    let m;
    while ((m = urlRegex.exec(text)) !== null) {
        try {
            const u = new URL(m[0]);
            const host = u.hostname.replace(/^www\./, '');
            if (host === 'youtube.com' && u.pathname === '/watch') {
                const v = u.searchParams.get('v');
                if (v && /^[A-Za-z0-9_-]{11}$/.test(v)) embeds.push({ type: 'youtube', id: v });
            } else if (host === 'youtu.be') {
                const v = u.pathname.slice(1).split(/[/?#]/)[0];
                if (v && /^[A-Za-z0-9_-]{11}$/.test(v)) embeds.push({ type: 'youtube', id: v });
            } else if (host === 'open.spotify.com') {
                const sp = u.pathname.match(/^\/(track|album|playlist|episode)\/([A-Za-z0-9]+)/);
                if (sp) embeds.push({ type: 'spotify', kind: sp[1], id: sp[2] });
            }
        } catch {}
    }
    return embeds;
}

function escHtml(s) {
    return s
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

function formatTime(dateStr) {
    if (!dateStr) return '';
    // Ensure ISO 8601 format: replace space separator and add Z if no timezone specified
    let s = String(dateStr).replace(' ', 'T');
    if (!s.endsWith('Z') && !s.includes('+') && !/[+-]\d{2}:\d{2}$/.test(s)) s += 'Z';
    const d = new Date(s);
    if (isNaN(d)) return '';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// ── Message rendering ──────────────────────────────────────────
// ── Crisis banner renderer ─────────────────────────────────────
function renderCrisisBanner(msg) {
    const el = document.createElement('div');
    el.className = 'crisis-banner';
    el.dataset.msgId   = msg.id;
    el.dataset.msgSort = new Date((msg.sent_at || '').replace(' ', 'T')).getTime() || Date.now();
    if (msg.participant_id) el.dataset.participantId = msg.participant_id;

    const iconCol = document.createElement('div');
    iconCol.className = 'crisis-banner-icon';
    const iconImg = document.createElement('img');
    iconImg.src = '/assets/role_icons/ribbon.png';
    iconImg.alt = '';
    iconCol.appendChild(iconImg);

    const contentCol = document.createElement('div');
    contentCol.className = 'crisis-banner-content';

    const titleEl = document.createElement('div');
    titleEl.className   = 'crisis-banner-title';
    titleEl.textContent = 'Crisis Resource';

    const bodyEl = document.createElement('div');
    bodyEl.className   = 'crisis-banner-body';
    bodyEl.textContent = msg.content;

    contentCol.appendChild(titleEl);
    contentCol.appendChild(bodyEl);
    el.appendChild(iconCol);
    el.appendChild(contentCol);
    return el;
}

function renderMessage(msg) {
    // Crisis messages get their own banner-style renderer
    if ((msg.message_type || 'text') === 'crisis') return renderCrisisBanner(msg);

    const isMe = msg.participant_id && msg.participant_id === cfg.myParticipantId;
    const el = document.createElement('div');
    el.className = 'chat-message ' + (isMe ? 'you' : 'other');
    if (msg.is_pinned) el.classList.add('pinned');
    if (msg.is_deleted) el.classList.add('msg-deleted');

    el.dataset.msgId   = msg.id;
    el.dataset.msgSort = new Date((msg.sent_at || '').replace(' ', 'T')).getTime() || Date.now();
    if (msg.participant_id) el.dataset.participantId = msg.participant_id;

    // ① Timestamp first
    const ts = document.createElement('span');
    ts.className   = 'msg-time';
    ts.textContent = formatTime(msg.sent_at);
    el.appendChild(ts);

    // ② Avatar
    const pEntry = msg.participant_id ? participants.get(msg.participant_id) : null;
    const avatarUrl = pEntry
        ? resolveAvatarUrl(pEntry.avatar_url || pEntry.avatar_path || '')
        : avatarPresets['Default'];
    const icon = document.createElement('img');
    icon.src       = avatarUrl;
    icon.className = 'chat-avatar';
    el.appendChild(icon);

    // ③ Name + content body
    const body = document.createElement('span');
    body.className = 'msg-body';

    const nm = document.createElement('strong');
    nm.textContent = (msg.display_name || '') + ': ';
    body.appendChild(nm);

    const msgType = msg.message_type || 'text';

    // Route PHI file attachments and voice notes through the authenticated serve endpoint.
    // bg_ and thumb_ backgrounds plus participant avatars are served directly (non-PHI).
    function fileServeUrl(storedPath) {
        const filename = storedPath.split('/').pop();
        const token    = cfg.myJoinToken || '';
        let url = '/api/serve_file.php?file=' + encodeURIComponent(filename);
        if (token) url += '&join_token=' + encodeURIComponent(token);
        return url;
    }

    if (msgType === 'voice_note') {
        const wrap = document.createElement('div');
        wrap.className = 'voice-note-wrap';
        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload  = 'metadata';
        audio.src      = fileServeUrl(msg.content);
        let listenReported = false;
        audio.addEventListener('timeupdate', () => {
            if (!listenReported && audio.duration > 0 && audio.currentTime / audio.duration >= 0.75) {
                listenReported = true;
                reportVoiceNoteListen(msg.id);
            }
        });
        wrap.appendChild(audio);
        if (msg.caption) {
            const cap = document.createElement('p');
            cap.className   = 'voice-note-caption';
            cap.textContent = msg.caption;
            wrap.appendChild(cap);
        }
        const listenedRow = document.createElement('div');
        listenedRow.className          = 'listened-avatars';
        listenedRow.dataset.messageId  = msg.id;
        wrap.appendChild(listenedRow);
        body.appendChild(wrap);

    } else if (msgType === 'file') {
        const wrap = document.createElement('div');
        wrap.className = 'file-attachment';
        const mime     = msg.mime_type || '';
        const origName = msg.original_name || msg.content.split('/').pop();
        const sizeStr  = msg.file_size ? formatFileSize(msg.file_size) : '';

        if (mime.startsWith('image/')) {
            const img = document.createElement('img');
            img.src       = fileServeUrl(msg.content);
            img.className = 'file-thumb';
            img.title     = origName;
            img.addEventListener('click', () => openLightbox(fileServeUrl(msg.content)));
            wrap.appendChild(img);
        } else if (mime.startsWith('video/')) {
            const vid     = document.createElement('video');
            vid.src       = fileServeUrl(msg.content);
            vid.controls  = true;
            vid.className = 'file-video';
            wrap.appendChild(vid);
        } else if (mime.startsWith('audio/')) {
            const aud    = document.createElement('audio');
            aud.src      = fileServeUrl(msg.content);
            aud.controls = true;
            wrap.appendChild(aud);
        } else {
            const link = document.createElement('a');
            link.href      = fileServeUrl(msg.content);
            link.download  = origName;
            link.className = 'file-download-row';
            link.innerHTML = `<span class="file-dl-icon">📄</span><span class="file-dl-name">${escHtml(origName)}</span><span class="file-dl-size">${escHtml(sizeStr)}</span><span class="file-dl-btn">Download</span>`;
            wrap.appendChild(link);
        }
        if (msg.caption) {
            const cap = document.createElement('p');
            cap.className   = 'file-caption';
            cap.textContent = msg.caption;
            wrap.appendChild(cap);
        }
        body.appendChild(wrap);

    } else if (msgType === 'media_rec') {
        el.classList.add('has-media-rec');
        const widget = buildMediaRecWidget(msg.content);
        body.appendChild(widget);

    } else if (msgType === 'quote_card') {
        el.classList.add('has-media-rec');
        body.appendChild(buildQuoteWidget(msg.content));

    } else {
        // Plain text — strip embed URLs so they don't appear as text links
        const embeds = extractEmbeds(msg.content);
        const displayText = embeds.length > 0 ? stripEmbedUrls(msg.content) : msg.content;
        if (displayText) {
            const textSpan = document.createElement('span');
            textSpan.className = 'msg-text';
            textSpan.innerHTML = renderTextWithHighlights(displayText, msg.highlights || []);
            body.appendChild(textSpan);
        }
        if (msg.is_deleted) {
            const deletedLabel = document.createElement('span');
            deletedLabel.className = 'msg-deleted-label';
            deletedLabel.textContent = 'Deleted' + (msg.edited_at ? ' · ' + formatTime(msg.edited_at) : '');
            body.appendChild(deletedLabel);
        } else if (msg.edited_at) {
            const editedLabel = document.createElement('span');
            editedLabel.className = 'msg-edited-label';
            editedLabel.textContent = 'Edited · ' + formatTime(msg.edited_at);
            body.appendChild(editedLabel);
        }
        // Embed(s) shown directly below text, no toggle
        if (embeds.length > 0) {
            const embedWrap = document.createElement('div');
            embedWrap.className = 'msg-embed-wrap';
            embeds.forEach(embed => {
                const frame = document.createElement('iframe');
                frame.className       = 'chat-embed';
                frame.allowFullscreen = true;
                frame.loading         = 'lazy';
                if (embed.type === 'youtube') {
                    frame.src   = `https://www.youtube.com/embed/${embed.id}?modestbranding=1`;
                    frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
                    frame.setAttribute('style', 'aspect-ratio:16/9;width:100%;');
                } else if (embed.type === 'spotify') {
                    frame.src    = `https://open.spotify.com/embed/${embed.kind}/${embed.id}?utm_source=generator`;
                    frame.allow  = 'autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture';
                    frame.height = '152';
                    frame.setAttribute('style', 'width:100%;');
                }
                embedWrap.appendChild(frame);
            });
            body.appendChild(embedWrap);
        }
    }

    el.appendChild(body);

    // ④ Reactions row (only for real messages, not system)
    if (!msg.is_system && Array.isArray(msg.reactions) && msg.reactions.length > 0) {
        el.appendChild(buildReactionsRow(msg));
    }

    // ⑤ Concept tag pills (practitioner only, non-system messages)
    if (cfg.isPractitioner && !msg.is_system && msg.id && typeof msg.id === 'number') {
        const tags = Array.isArray(msg.concept_tags) ? msg.concept_tags : [];
        const tagWrap = buildConceptTagPills(tags, msg.id, 'message');
        el.appendChild(tagWrap);

        // Tag button — shows count badge when tags are present, always visible then
        const tagBtn = document.createElement('button');
        tagBtn.className     = 'msg-tag-btn';
        tagBtn.dataset.msgId = msg.id;
        tagBtn.title         = 'Tag with concept';
        updateTagBtnLabel(tagBtn, tags.length);
        tagBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showTagPopover(e.currentTarget, { type: 'message', id: msg.id });
        });
        body.appendChild(tagBtn);
    }

    // Hover to show react toolbar
    if (!msg.is_system) {
        el.addEventListener('mouseenter', () => {
            clearTimeout(reactHoverTimer);
            reactTargetMsgId = msg.id;
            positionReactToolbar(el);
        });
        el.addEventListener('mouseleave', (e) => {
            if (hlToolbar.contains(e.relatedTarget)) return;
            reactHoverTimer = setTimeout(() => {
                if (!hlToolbar.matches(':hover')) {
                    hlToolbar.classList.remove('visible');
                    reactTargetMsgId = null;
                }
            }, 150);
        });
    }

    return el;
}

function buildReactionsRow(msg) {
    const row = document.createElement('div');
    row.className = 'msg-reactions';
    row.dataset.msgId = msg.id;

    // Group by emoji
    const groups = {};
    for (const r of (msg.reactions || [])) {
        if (!groups[r.emoji]) groups[r.emoji] = [];
        groups[r.emoji].push(r);
    }

    for (const [emoji, reactors] of Object.entries(groups)) {
        const chip = document.createElement('span');
        const isOwn = reactors.some(r => r.participant_id === cfg.myParticipantId);
        chip.className = 'reaction-chip' + (isOwn ? ' own' : '');
        chip.title     = reactors.map(r => {
            const p = participants.get(r.participant_id);
            return p ? p.display_name : 'Someone';
        }).join(', ');

        const emojiSpan = document.createElement('span');
        emojiSpan.textContent = emoji;
        chip.appendChild(emojiSpan);

        // Show up to 3 reactors' avatars
        reactors.slice(0, 3).forEach(r => {
            const img = document.createElement('img');
            img.className = 'reaction-avatar';
            img.src       = r.avatar_url || avatarPresets['Default'];
            chip.appendChild(img);
        });

        if (reactors.length > 1) {
            const cnt = document.createElement('span');
            cnt.className   = 'reaction-count';
            cnt.textContent = reactors.length;
            chip.appendChild(cnt);
        }

        chip.addEventListener('click', () => applyReaction(emoji, msg.id));
        row.appendChild(chip);
    }

    return row;
}

function positionReactToolbar(msgEl) {
    // Hover always shows React only — hide Markup section
    const markupSection = document.getElementById('hl-section-markup');
    if (markupSection) markupSection.style.display = 'none';
    // Show edit section only for own text messages that aren't deleted
    const editSection = document.getElementById('hl-section-edit');
    const msgId = reactTargetMsgId || +msgEl.dataset.msgId;
    const targMsg = messages.get(msgId);
    const isOwn = targMsg && targMsg.participant_id === cfg.myParticipantId;
    const isEditableType = !targMsg || (targMsg.message_type || 'text') === 'text';
    const isDeleted = targMsg && targMsg.is_deleted;
    if (editSection) {
        const showEdit = isOwn && isEditableType && !isDeleted;
        editSection.style.display = showEdit ? '' : 'none';
        hlToolbar.classList.toggle('edit-mode', showEdit);
    }
    hlToolbar.classList.add('visible');
    const rect = msgEl.getBoundingClientRect();
    const tbW  = hlToolbar.offsetWidth || 170;
    hlToolbar.style.top  = (rect.bottom + window.scrollY + 4) + 'px';
    hlToolbar.style.left = Math.max(4, rect.right + window.scrollX - tbW - 4) + 'px';
}

function showFullToolbar(clientX, clientY) {
    // Right-click with selection — show both Markup and React sections
    const markupSection = document.getElementById('hl-section-markup');
    if (markupSection && cfg.isPractitioner) markupSection.style.display = '';
    const editSection = document.getElementById('hl-section-edit');
    if (editSection) editSection.style.display = 'none';
    hlToolbar.classList.remove('edit-mode');
    hlToolbar.classList.add('visible');
    // Position above cursor
    const tbH = hlToolbar.offsetHeight || 100;
    hlToolbar.style.top  = Math.max(4, clientY + window.scrollY - tbH - 4) + 'px';
    hlToolbar.style.left = (clientX + window.scrollX) + 'px';
}

function addOrUpdateMessage(msg) {
    const existing = messagesEl.querySelector(`[data-msg-id="${msg.id}"]`);
    const el = renderMessage(msg);
    if (existing) {
        existing.replaceWith(el);
    } else {
        // Events arrive in chronological order from the server; always append.
        messagesEl.appendChild(el);
        if (userScrolledUp) { unreadCount++; updateNewMsgPill(); }
    }
    messages.set(msg.id, msg);
    if (!userScrolledUp) messagesEl.scrollTop = messagesEl.scrollHeight;
}

function updateNewMsgPill() {
    const pill  = document.getElementById('new-msg-pill');
    const count = document.getElementById('new-msg-count');
    const plur  = document.getElementById('new-msg-plural');
    if (!pill) return;
    if (unreadCount > 0) {
        if (count) count.textContent = unreadCount;
        if (plur)  plur.textContent  = unreadCount === 1 ? '' : 's';
        pill.style.display = '';
    } else {
        pill.style.display = 'none';
    }
}

function dismissNewMsgPill() {
    unreadCount = 0;
    updateNewMsgPill();
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function renderAllMessages() {
    messagesEl.innerHTML = '';
    cfg.initialMessages.forEach(msg => {
        messages.set(msg.id, msg);
        messagesEl.appendChild(renderMessage(msg));
    });
    messagesEl.scrollTop = messagesEl.scrollHeight; // always scroll on initial load
}

// ── Show chat bubble on room avatar ───────────────────────────
function placeBubble(b, avatarEl) {
    const R = roomEl.getBoundingClientRect();
    const A = avatarEl.getBoundingClientRect();
    const B = b.getBoundingClientRect();
    const g = 8;
    let x, y;
    if (A.right - R.left + g + B.width <= R.width &&
        A.bottom - R.top + g + B.height <= R.height) {
        x = A.right - R.left + g - 25; y = A.bottom - R.top + g;
    } else if (A.left - R.left - g - B.width >= 0 &&
               A.bottom - R.top + g + B.height <= R.height) {
        x = A.left - R.left - g - B.width + 25; y = A.bottom - R.top + g;
    } else if (A.right - R.left + g + B.width <= R.width &&
               A.top - R.top - g - B.height >= 0) {
        x = A.right - R.left + g - 25; y = A.top - R.top - g - B.height;
    } else {
        x = Math.max(0, Math.min(A.left - R.left - g - B.width + 25, R.width - B.width));
        y = Math.max(0, Math.min(A.top - R.top - g - B.height, R.height - B.height));
    }
    b.style.left = x + 'px';
    b.style.top  = y + 'px';
}

function showChatBubble(participantId, text) {
    const p = participants.get(participantId);
    if (!p || !p.avatarEl) return;

    // Remove any existing bubble for this participant
    const prev = activeBubbles.get(participantId);
    if (prev) prev.remove();

    const b = document.createElement('div');
    b.className   = 'chat-bubble';
    b.textContent = text.length > 80 ? text.slice(0, 77) + '…' : text;
    roomEl.appendChild(b);
    activeBubbles.set(participantId, b);

    requestAnimationFrame(() => {
        placeBubble(b, p.avatarEl);
        b.classList.add('show');
        setTimeout(() => {
            b.remove();
            if (activeBubbles.get(participantId) === b) activeBubbles.delete(participantId);
        }, 5000);
    });
}

// ── Avatar management ──────────────────────────────────────────
function addAvatarToRoom(p) {
    // AI participants are rendered as a small docked avatar pinned to the practitioner's avatar
    if (p.is_ai) {
        return addAiAvatarToRoom(p);
    }

    const img = document.createElement('img');
    const px0 = (p.position_x || 0.1) * roomW();
    const py0 = (p.position_y || 0.1) * roomH();

    img.src        = resolveAvatarUrl(p.avatar_url || p.avatar_path || '');
    img.className  = 'avatar';
    img.style.left = px0 + 'px';
    img.style.top  = py0 + 'px';
    img.dataset.participantId = p.id;

    if (p.linked_to) img.classList.add('linked');

    // Drag — own avatar only (target of a link inherits drag via their own avatarEl)
    if (cfg.myParticipantId && p.id === cfg.myParticipantId) {
        enableDrag(img, p);
    }

    // Context menu: own avatar (full menu) or linked partner (unlink only)
    img.addEventListener('contextmenu', e => {
        e.preventDefault(); // always suppress browser default on avatars
        const isOwn = p.id === cfg.myParticipantId;
        const myData = cfg.myParticipantId ? participants.get(cfg.myParticipantId) : null;
        const isLinkedPartner = myData && (myData.linked_to === p.id || p.linked_to === cfg.myParticipantId);
        if (!isOwn && !isLinkedPartner) return; // right-click on strangers: no menu
        ctxMenuTargetJoinToken = p.join_token || cfg.myJoinToken;
        showCtxMenu(e.clientX, e.clientY, p);
    });

    // Name label — hidden by default, shown on avatar hover
    const label = document.createElement('div');
    label.className   = 'avatar-label';
    label.style.display = 'flex';
    label.style.alignItems = 'center';
    label.dataset.labelFor = p.id;
    const roleIconEl = makeRoleIconEl(p.id);
    if (roleIconEl) label.appendChild(roleIconEl);
    label.appendChild(document.createTextNode(p.display_name));
    label.style.left  = (px0 + 50) + 'px';
    label.style.top   = (py0 + 158) + 'px';
    label.style.opacity = '0';
    roomEl.appendChild(label);
    img.addEventListener('mouseenter', () => { label.style.opacity = '1'; });
    img.addEventListener('mouseleave', () => { label.style.opacity = '0'; });

    // Typing bubble — upper-right corner of avatar
    const bubble = document.createElement('div');
    bubble.className = 'typing-bubble';
    bubble.dataset.typingFor = p.id;
    bubble.style.left = (px0 + 74) + 'px';
    bubble.style.top  = (py0 - 10) + 'px';
    bubble.style.display = 'none';
    bubble.innerHTML = '<span></span><span></span><span></span>';
    roomEl.appendChild(bubble);

    // Noting bubble — upper-left of avatar (pen icon, practitioner-only signal)
    const notingBubble = document.createElement('div');
    notingBubble.className = 'noting-bubble';
    notingBubble.dataset.notingFor = p.id;
    notingBubble.style.left = (px0 - 8) + 'px';
    notingBubble.style.top  = (py0 - 10) + 'px';
    notingBubble.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';
    roomEl.appendChild(notingBubble);

    // Webcam live badge — bottom-left of avatar
    const webcamBadge = document.createElement('div');
    webcamBadge.className = 'webcam-live-badge';
    webcamBadge.dataset.webcamFor = p.id;
    webcamBadge.textContent = '📹';
    webcamBadge.style.left = (px0 + 2) + 'px';
    webcamBadge.style.top  = (py0 + 122) + 'px';
    webcamBadge.style.display = 'none';
    roomEl.appendChild(webcamBadge);

    roomEl.appendChild(img);
    return img;
}

function updateAvatarPosition(img, x, y, participantId, animate) {
    // Clamp within room bounds
    x = Math.max(0, Math.min(x, roomW() - img.offsetWidth));
    y = Math.max(0, Math.min(y, roomH() - img.offsetHeight));

    if (animate) {
        img.style.transition = 'left 0.35s ease, top 0.35s ease';
    } else {
        img.style.transition = 'none';
    }
    img.style.left = x + 'px';
    img.style.top  = y + 'px';

    const label = roomEl.querySelector(`[data-label-for="${participantId}"]`);
    if (label) {
        if (animate) label.style.transition = 'left 0.35s ease, top 0.35s ease';
        else label.style.transition = 'none';
        label.style.left = (x + 50) + 'px';
        label.style.top  = (y + 158) + 'px';
    }
    const typingBubble = roomEl.querySelector(`[data-typing-for="${participantId}"]`);
    if (typingBubble) {
        if (animate) typingBubble.style.transition = 'left 0.35s ease, top 0.35s ease';
        else typingBubble.style.transition = 'none';
        typingBubble.style.left = (x + 74) + 'px';
        typingBubble.style.top  = (y - 10) + 'px';
    }
    const notingBubble = roomEl.querySelector(`[data-noting-for="${participantId}"]`);
    if (notingBubble) {
        if (animate) notingBubble.style.transition = 'left 0.35s ease, top 0.35s ease';
        else notingBubble.style.transition = 'none';
        notingBubble.style.left = (x - 8) + 'px';
        notingBubble.style.top  = (y - 10) + 'px';
    }
    const webcamBadge = roomEl.querySelector(`[data-webcam-for="${participantId}"]`);
    if (webcamBadge) {
        if (animate) webcamBadge.style.transition = 'left 0.35s ease, top 0.35s ease';
        else webcamBadge.style.transition = 'none';
        webcamBadge.style.left = (x + 2) + 'px';
        webcamBadge.style.top  = (y + 122) + 'px';
    }
    // Reposition chat bubble if one is active for this participant
    const chatBubble = activeBubbles.get(participantId);
    if (chatBubble && img) placeBubble(chatBubble, img);
}

function enableDrag(el, p) {
    let offX, offY, dragging = false, linkBrokenThisDrag = false;

    // Shared move logic for both mouse and touch
    const applyMove = function(clientX, clientY) {
        if (!dragging) return;
        const r = roomEl.getBoundingClientRect();
        let x = clientX - r.left - offX;
        let y = clientY - r.top  - offY;
        x = Math.max(0, Math.min(x, r.width  - el.offsetWidth));
        y = Math.max(0, Math.min(y, r.height - el.offsetHeight));

        const pData = participants.get(p.id);

        // If this participant is the INITIATOR of a link, dragging BREAKS it
        if (!linkBrokenThisDrag && pData && pData.linked_to) {
            linkBrokenThisDrag = true;
            pData.linked_to = null;
            el.classList.remove('linked');
            apiPost('/api/participants.php', {
                action:     'unlink',
                join_token: pData.join_token || cfg.myJoinToken,
            });
            renderUserList();
        }

        updateAvatarPosition(el, x, y, p.id, false);

        // If this participant is the TARGET (someone else links TO this one),
        // drag both together maintaining their relative offset
        if (!linkBrokenThisDrag) {
            participants.forEach(other => {
                if (other.id !== p.id && other.linked_to === p.id && other.avatarEl) {
                    const offsetX = (other.position_x - (pData ? pData.position_x : 0)) * r.width;
                    const offsetY = (other.position_y - (pData ? pData.position_y : 0)) * r.height;
                    const lx = Math.max(0, Math.min(x + offsetX, r.width  - other.avatarEl.offsetWidth));
                    const ly = Math.max(0, Math.min(y + offsetY, r.height - other.avatarEl.offsetHeight));
                    updateAvatarPosition(other.avatarEl, lx, ly, other.id, false);
                    other.position_x = lx / r.width;
                    other.position_y = ly / r.height;
                }
            });
        }

        if (pData) {
            pData.position_x = x / r.width;
            pData.position_y = y / r.height;
        }
        // Re-dock AI avatars during practitioner drag
        if (p.id === cfg.hostParticipantId) redockAiAvatars();
    };

    // Shared drag-end logic — called by both mouse and touch
    const onUp = function() {
        if (!dragging) return;
        dragging = false;
        el.style.cursor = 'grab';

        const pData = participants.get(p.id);
        if (!pData) return;

        const token = pData.join_token || cfg.myJoinToken;
        if (!token) return;

        // Check for avatar overlap (link mechanic) — only if not already linked and link wasn't broken
        const draggedX = parseFloat(el.style.left);
        const draggedY = parseFloat(el.style.top);

        let overlapped = null;
        if (!linkBrokenThisDrag && !pData.linked_to) {
            participants.forEach((other, otherId) => {
                if (otherId === p.id || !other.avatarEl) return;
                const ox = parseFloat(other.avatarEl.style.left);
                const oy = parseFloat(other.avatarEl.style.top);
                const dist = Math.sqrt(Math.pow(draggedX - ox, 2) + Math.pow(draggedY - oy, 2));
                if (dist < 60) overlapped = other;
            });
        }

        let linkInitiated = false;
        if (overlapped) {
            showLinkModal(pData.display_name, overlapped.display_name, () => {
                apiPost('/api/participants.php', {
                    action: 'link',
                    join_token: token,
                    target_participant_id: overlapped.id,
                })
                .then(() => {
                    pData.linked_to = overlapped.id;
                    el.classList.add('linked');
                    overlapped.avatarEl && overlapped.avatarEl.classList.add('linked');

                    // Snap: target LEFT, initiator RIGHT, 12px gap — clamp pair within room
                    const avatarPx = el.offsetWidth || 150;
                    const gap = 12;
                    const rW = roomW();
                    const rH = roomH();
                    // initX must be in [avatarPx+gap, rW-avatarPx] so tgtX always >= 0
                    let initX = Math.max(avatarPx + gap, Math.min(pData.position_x * rW, rW - avatarPx));
                    let tgtX  = initX - avatarPx - gap;
                    const snapY = Math.max(0, Math.min(pData.position_y * rH, rH - avatarPx));

                    if (overlapped.avatarEl) {
                        updateAvatarPosition(overlapped.avatarEl, tgtX, snapY, overlapped.id, true);
                    }
                    overlapped.position_x = tgtX / rW;
                    overlapped.position_y = snapY / rH;
                    updateAvatarPosition(el, initX, snapY, pData.id, true);
                    pData.position_x = initX / rW;
                    pData.position_y = snapY / rH;

                    // Save BOTH positions after snap
                    apiPost('/api/participants.php', {
                        action:     'update_position',
                        join_token: token,
                        x:          pData.position_x,
                        y:          pData.position_y,
                    });
                    if (overlapped.join_token) {
                        apiPost('/api/participants.php', {
                            action:     'update_position',
                            join_token: overlapped.join_token,
                            x:          overlapped.position_x,
                            y:          overlapped.position_y,
                        });
                    }
                    renderUserList();
                });
            });
        }

        // Save position for non-link drags
        if (!linkInitiated) {
            apiPost('/api/participants.php', {
                action:     'update_position',
                join_token: token,
                x:          pData.position_x,
                y:          pData.position_y,
            });
        }

        // Save linked partner positions (target follows this, reverse link only)
        const saveLinkedPos = (other) => {
            if (other && other.join_token) {
                apiPost('/api/participants.php', {
                    action:     'update_position',
                    join_token: other.join_token,
                    x:          other.position_x,
                    y:          other.position_y,
                });
            }
        };
        // Only save partners if this was a target-drag (not if link was broken)
        if (!linkBrokenThisDrag) {
            participants.forEach(other => {
                if (other.id !== pData.id && other.linked_to === pData.id) saveLinkedPos(other);
            });
        }
    };

    // ── Mouse drag ────────────────────────────────────────────────
    el.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        offX = e.clientX - el.getBoundingClientRect().left;
        offY = e.clientY - el.getBoundingClientRect().top;
        dragging = true;
        linkBrokenThisDrag = false;
        el.style.cursor = 'grabbing';
        const onMouseMove = ev => applyMove(ev.clientX, ev.clientY);
        const onMouseUp   = () => { onUp(); document.removeEventListener('mousemove', onMouseMove); document.removeEventListener('mouseup', onMouseUp); };
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        e.preventDefault();
    });

    // ── Touch drag + long press context menu ─────────────────────
    let longPressTimer = null;
    let touchMoved = false;

    el.addEventListener('touchstart', function(e) {
        const t = e.touches[0];
        offX = t.clientX - el.getBoundingClientRect().left;
        offY = t.clientY - el.getBoundingClientRect().top;
        touchMoved = false;

        // Long press: 500ms → context menu
        longPressTimer = setTimeout(() => {
            longPressTimer = null;
            if (touchMoved) return;
            const myData = cfg.myParticipantId ? participants.get(cfg.myParticipantId) : null;
            const isLinkedPartner = myData && (myData.linked_to === p.id || p.linked_to === cfg.myParticipantId);
            if (p.id === cfg.myParticipantId || isLinkedPartner) {
                ctxMenuTargetJoinToken = p.join_token || cfg.myJoinToken;
                showCtxMenu(t.clientX, t.clientY, p);
            }
        }, 500);

        const onTouchMove = ev => {
            touchMoved = true;
            if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
            if (!dragging) {
                dragging = true;
                linkBrokenThisDrag = false;
                el.style.cursor = 'grabbing';
            }
            if (ev.cancelable) ev.preventDefault();
            const touch = ev.touches[0];
            applyMove(touch.clientX, touch.clientY);
        };

        const onTouchEnd = () => {
            if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
            el.removeEventListener('touchmove', onTouchMove);
            el.removeEventListener('touchend', onTouchEnd);
            el.removeEventListener('touchcancel', onTouchEnd);
            onUp();
        };

        el.addEventListener('touchmove', onTouchMove, {passive: false});
        el.addEventListener('touchend',   onTouchEnd,  {passive: true});
        el.addEventListener('touchcancel', onTouchEnd, {passive: true});
    }, {passive: true});

    el.addEventListener('dragstart', e => e.preventDefault());
}

// ── Participant list rendering ─────────────────────────────────
function renderUserList() {
    userListEl.innerHTML = '';
    const rendered = new Set();

    // Sort order: host first, AI second, then others alphabetically
    const sortedParticipants = [...participants.entries()].sort(([, a], [, b]) => {
        const aHost = a.id === cfg.hostParticipantId ? 0 : 1;
        const bHost = b.id === cfg.hostParticipantId ? 0 : 1;
        if (aHost !== bHost) return aHost - bHost;
        const aAI = a.is_ai ? 0 : 1;
        const bAI = b.is_ai ? 0 : 1;
        if (aAI !== bAI) return aAI - bAI;
        return a.display_name.localeCompare(b.display_name);
    });

    sortedParticipants.forEach(([id, p]) => {
        if (rendered.has(id)) return;

        // Detect link partner: either we point to them, or they point to us
        let partner = null;
        if (p.linked_to && participants.has(p.linked_to) && !rendered.has(p.linked_to)) {
            partner = participants.get(p.linked_to);
        } else {
            for (const [, other] of participants) {
                if (other.linked_to === id && !rendered.has(other.id)) {
                    partner = other;
                    break;
                }
            }
        }

        if (partner) {
            const other = partner;
            rendered.add(id);
            rendered.add(partner.id);

            const li = document.createElement('li');
            li.className = 'user-linked';
            li.dataset.participantId = id;

            const mkHalf = (part) => {
                const half = document.createElement('div');
                half.className = 'link-half';
                // Wrap avatar in a relative container so the crown badge can anchor to it
                const avatarWrap = document.createElement('div');
                avatarWrap.className = 'link-half-avatar';
                const icon = document.createElement('img');
                icon.src       = resolveAvatarUrl(part.avatar_url || part.avatar_path || '');
                icon.className = 'participant-avatar';
                avatarWrap.appendChild(icon);
                if (cfg.hostParticipantId && part.id === cfg.hostParticipantId) {
                    const crown = document.createElement('span');
                    crown.className   = 'host-crown';
                    crown.textContent = '👑';
                    avatarWrap.appendChild(crown);
                }
                const name = document.createElement('span');
                name.style.display = 'flex';
                name.style.alignItems = 'center';
                const rIcon = makeRoleIconEl(part.id);
                if (rIcon) name.appendChild(rIcon);
                name.appendChild(document.createTextNode(part.display_name));
                half.append(avatarWrap, name);
                return half;
            };

            const heart = document.createElement('span');
            heart.className   = 'link-heart';
            heart.textContent = '🩷';

            // target (linked_to person) appears LEFT, initiator appears RIGHT
            if (p.linked_to) {
                li.append(mkHalf(other), heart, mkHalf(p));
            } else {
                li.append(mkHalf(p), heart, mkHalf(other));
            }
            userListEl.appendChild(li);
        } else {
            rendered.add(id);
            const li = document.createElement('li');
            li.className = 'participant-card';
            li.dataset.participantId = id;
            const img = document.createElement('img');
            img.src       = resolveAvatarUrl(p.avatar_url || p.avatar_path || '');
            img.className = 'participant-avatar';
            const span = document.createElement('span');
            span.className = 'participant-name';
            span.style.display = 'flex';
            span.style.alignItems = 'center';
            const roleIcon2 = makeRoleIconEl(p.id);
            if (roleIcon2) span.appendChild(roleIcon2);
            span.appendChild(document.createTextNode(p.display_name));
            li.append(img, span);
            // Crown for room host (practitioner)
            if (cfg.hostParticipantId && p.id === cfg.hostParticipantId) {
                const crown = document.createElement('span');
                crown.className   = 'host-crown';
                crown.textContent = '👑';
                li.appendChild(crown);
            }
            userListEl.appendChild(li);
        }
    });

    const countEl = document.getElementById('participant-count');
    if (countEl) countEl.textContent = participants.size;
}

// ── System message (join/leave announcements) ──────────────────
// Pass an optional serverTs (ms since epoch) to sort against server timestamps.
function addSystemMessage(text, serverTs) {
    const div = document.createElement('div');
    div.className        = 'chat-system';
    div.dataset.msgSort  = serverTs || Date.now();
    div.innerHTML = `<span class="system-badge">${escHtml(text)}</span>`;

    // Insert in chronological order (same logic as regular messages)
    const sortKey = +div.dataset.msgSort;
    const children = messagesEl.children;
    let anchor = null;
    for (let i = children.length - 1; i >= 0; i--) {
        if (+children[i].dataset.msgSort <= sortKey) { anchor = children[i]; break; }
    }
    if (anchor) anchor.after(div);
    else messagesEl.prepend(div);

    if (!userScrolledUp) messagesEl.scrollTop = messagesEl.scrollHeight;
}

// ── Invite link copy ───────────────────────────────────────────
function copyInviteLink() {
    navigator.clipboard.writeText(cfg.inviteUrl).then(() => {
        const el = document.getElementById('invite-copied');
        if (el) {
            el.style.display = 'block';
            setTimeout(() => { el.style.display = 'none'; }, 2500);
        }
    }).catch(() => {
        prompt('Copy this invite link:', cfg.inviteUrl);
    });
}
window.copyInviteLink = copyInviteLink;

// ── Add/update participant ─────────────────────────────────────
function syncParticipant(pData) {
    const existing = participants.get(pData.id);

    if (existing) {
        // Update position
        existing.position_x  = pData.position_x;
        existing.position_y  = pData.position_y;
        existing.linked_to   = pData.linked_to;
        existing.avatar_path = pData.avatar_path || '';
        existing.avatar_url  = pData.avatar_url || resolveAvatarUrl(pData.avatar_path || '');
        if (existing.avatarEl) {
            existing.avatarEl.src = existing.avatar_url;
            // Animate remote position updates; own avatar is already live during drag
            const isRemote = pData.id !== cfg.myParticipantId;
            updateAvatarPosition(existing.avatarEl, pData.position_x * roomW(), pData.position_y * roomH(), pData.id, isRemote);
            if (pData.linked_to) {
                existing.avatarEl.classList.add('linked');
            } else {
                existing.avatarEl.classList.remove('linked');
            }
        }
        // Sync typing bubble on initial load
        if (pData.typing_at) setTypingBubble(pData.id, true);
        else                 setTypingBubble(pData.id, false);
    } else {
        // New participant
        const p = {
            id:           pData.id,
            display_name: pData.display_name,
            avatar_path:  pData.avatar_path || '',
            avatar_url:   pData.avatar_url || resolveAvatarUrl(pData.avatar_path || ''),
            position_x:   pData.position_x,
            position_y:   pData.position_y,
            linked_to:    pData.linked_to,
            join_token:   pData.join_token,
            is_ai:        !!pData.is_ai,
            avatarEl:     null,
        };
        participants.set(pData.id, p);
        p.avatarEl = addAvatarToRoom(p);
    }
}

function initParticipants() {
    // Preload avatar images so they appear instantly
    cfg.initialParticipants.forEach(p => {
        const url = resolveAvatarUrl(p.avatar_path || '');
        if (url && !url.startsWith('data:')) {
            const img = new Image();
            img.src = url;
        }
    });
    // Process host first, AI last — ensures AI can dock to host on first render
    const sorted = [...cfg.initialParticipants].sort((a, b) => {
        if (a.id === cfg.hostParticipantId) return -1;
        if (b.id === cfg.hostParticipantId) return  1;
        if (a.is_ai) return  1;
        if (b.is_ai) return -1;
        return 0;
    });
    sorted.forEach(p => syncParticipant(p));
    renderUserList();
}

// ── Pins rendering ─────────────────────────────────────────────
function renderPinPanel() {
    if (!cfg.isPractitioner) return;
    const panel = document.getElementById('pin-panel');
    if (!panel) return;

    panel.innerHTML = '';
    const countEl  = document.getElementById('pin-count');
    if (countEl) countEl.textContent = pins.size ? `(${pins.size})` : '';

    pins.forEach(pin => {
        const el = document.createElement('div');
        el.className = 'pin-item';
        const textEl = document.createElement('div');
        textEl.className   = 'pin-item-text';
        textEl.textContent = (pin.display_name || '') + ': ' +
            (pin.content.length > 80 ? pin.content.slice(0,77) + '…' : pin.content);
        el.appendChild(textEl);

        if (pin.annotation) {
            const ann = document.createElement('div');
            ann.className   = 'pin-item-annotation';
            ann.textContent = pin.annotation;
            el.appendChild(ann);
        }

        el.addEventListener('click', () => {
            const msgEl = messagesEl.querySelector(`[data-msg-id="${pin.id}"]`);
            if (msgEl) {
                msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                msgEl.classList.add('flash-pin');
                setTimeout(() => msgEl.classList.remove('flash-pin'), 700);
            }
        });

        panel.appendChild(el);
    });
}

function togglePinPanel() {
    pinPanelOpen = !pinPanelOpen;
    const panel   = document.getElementById('pin-panel');
    const toggle  = document.getElementById('pin-toggle');
    if (panel)  panel.style.display  = pinPanelOpen ? '' : 'none';
    if (toggle) toggle.textContent   = pinPanelOpen ? '▼' : '▶';
}
window.togglePinPanel = togglePinPanel;

// ── Notes panel ────────────────────────────────────────────────
const liveNotes = {};  // Updated in real-time as the practitioner types/saves
let   notesPreviewActive = false;
let   notesHistoryLoaded = false;

// Note templates (#13)
const NOTE_TEMPLATES = {
    soap: 'S (Subjective):\n\nO (Objective):\n\nA (Assessment):\n\nP (Plan):\n',
    dap:  'D (Data):\n\nA (Assessment):\n\nP (Plan):\n',
    birp: 'B (Behaviour):\n\nI (Intervention):\n\nR (Response):\n\nP (Plan):\n',
    blank: '',
};

function initNotesPanel() {
    if (!cfg.isPractitioner) return;
    const select   = document.getElementById('notes-participant-select');
    const textarea = document.getElementById('notes-textarea');
    const indicator= document.getElementById('notes-save-indicator');
    if (!select || !textarea) return;

    // Seed liveNotes from server-rendered initial values
    Object.assign(liveNotes, cfg.initialNotes || {});

    select.addEventListener('change', () => {
        selectedNoteParticipantId = select.value ? parseInt(select.value) : null;
        const hasParticipant = !!selectedNoteParticipantId;

        // Show/hide toolbar, template selector, history section
        const toolbar     = document.getElementById('notes-toolbar');
        const tplRow      = document.getElementById('notes-template-row');
        const histSection = document.getElementById('notes-history-section');
        if (toolbar)     toolbar.style.display     = hasParticipant ? 'flex'  : 'none';
        if (tplRow)      tplRow.style.display      = hasParticipant ? 'block' : 'none';
        if (histSection) histSection.style.display = hasParticipant ? 'block' : 'none';

        if (hasParticipant) {
            textarea.value = liveNotes[selectedNoteParticipantId] ?? cfg.initialNotes[selectedNoteParticipantId] ?? '';
            // Reset history on participant change
            notesHistoryLoaded = false;
            const histBody = document.getElementById('notes-history-body');
            if (histBody) { histBody.innerHTML = ''; histBody.style.display = 'none'; }
            const histToggle = document.getElementById('notes-history-toggle');
            if (histToggle) histToggle.textContent = '▶';
        } else {
            textarea.value = '';
        }

        // If preview was active, switch back to edit
        if (notesPreviewActive) toggleNotesPreview();
        if (indicator) indicator.textContent = '';
    });

    // Template selector (#13)
    const tplSelect = document.getElementById('notes-template-select');
    if (tplSelect) {
        tplSelect.addEventListener('change', () => {
            const val = tplSelect.value;
            if (!val || !selectedNoteParticipantId) { tplSelect.value = ''; return; }
            const tpl = NOTE_TEMPLATES[val] ?? '';
            if (textarea.value.trim() === '' || confirm('Replace current notes with the ' + val.toUpperCase() + ' template?')) {
                textarea.value = tpl;
                liveNotes[selectedNoteParticipantId] = tpl;
                textarea.dispatchEvent(new Event('input'));
                textarea.focus();
            }
            tplSelect.value = '';
        });
    }

    textarea.addEventListener('input', () => {
        if (!selectedNoteParticipantId) return;
        liveNotes[selectedNoteParticipantId] = textarea.value;
        if (indicator) indicator.textContent = 'Saving…';
        clearTimeout(notesSaveTimer);
        notesSaveTimer = setTimeout(() => {
            const noteContent = textarea.value;
            apiPost('/api/notes.php', {
                session_id:     cfg.sessionId,
                participant_id: selectedNoteParticipantId,
                note_content:   noteContent,
            }).then(() => {
                liveNotes[selectedNoteParticipantId] = noteContent;
                if (indicator) indicator.textContent = 'Saved ✓';
                setTimeout(() => { if (indicator) indicator.textContent = ''; }, 2000);
            });
        }, 500);

        if (!isNotingActive) { isNotingActive = true; sendNoting(true); }
        clearTimeout(notingTimer);
        notingTimer = setTimeout(() => { isNotingActive = false; sendNoting(false); }, 3000);
    });

    textarea.addEventListener('blur', () => {
        if (isNotingActive) {
            clearTimeout(notingTimer);
            isNotingActive = false;
            sendNoting(false);
        }
    });
}

// Refresh notes participant dropdown when new participants join
function refreshNotesSelect() {
    if (!cfg.isPractitioner) return;
    const select = document.getElementById('notes-participant-select');
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">— Select participant —</option>';
    const nonHostParts = [];
    participants.forEach((p) => {
        if (p.id === cfg.hostParticipantId) return; // exclude practitioner
        const opt = document.createElement('option');
        opt.value       = p.id;
        opt.textContent = p.display_name;
        if (String(p.id) === current) opt.selected = true;
        select.appendChild(opt);
        nonHostParts.push(p);
    });
    // Auto-select if only one non-practitioner participant and none already selected
    if (nonHostParts.length === 1 && !select.value) {
        select.value = String(nonHostParts[0].id);
        select.dispatchEvent(new Event('change'));
    }
}

// ── Markdown toolbar (#10) ─────────────────────────────────────

function notesFmt(before, after) {
    const ta = document.getElementById('notes-textarea');
    if (!ta) return;
    const start = ta.selectionStart;
    const end   = ta.selectionEnd;
    const sel   = ta.value.substring(start, end);
    const replacement = before + sel + after;
    ta.setRangeText(replacement, start, end, 'select');
    ta.focus();
    ta.dispatchEvent(new Event('input'));
}

function notesFmtLine(prefix) {
    const ta = document.getElementById('notes-textarea');
    if (!ta) return;
    const start     = ta.selectionStart;
    const lineStart = ta.value.lastIndexOf('\n', start - 1) + 1;
    const lineEnd   = ta.value.indexOf('\n', start);
    const end       = lineEnd === -1 ? ta.value.length : lineEnd;
    const line      = ta.value.substring(lineStart, end);
    // Toggle: if line already starts with prefix, remove it; otherwise add
    const newLine   = line.startsWith(prefix) ? line.slice(prefix.length) : prefix + line;
    ta.setRangeText(newLine, lineStart, end, 'end');
    ta.focus();
    ta.dispatchEvent(new Event('input'));
}

// Minimal markdown → HTML renderer (headers, bold, italic, code, lists, line breaks)
function renderMarkdown(md) {
    let html = md
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        // headings
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm,  '<h2>$1</h2>')
        .replace(/^# (.+)$/gm,   '<h1>$1</h1>')
        // bold & italic
        .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
        .replace(/\*\*(.+?)\*\*/g,     '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,         '<em>$1</em>')
        // inline code
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        // unordered list items (group later)
        .replace(/^[-*] (.+)$/gm, '<li>$1</li>')
        // line breaks
        .replace(/\n/g, '<br>');
    // wrap consecutive <li> in <ul>
    html = html.replace(/(<li>.*?<\/li>)(<br>(<li>.*?<\/li>))*/g, (m) => {
        return '<ul>' + m.replace(/<br>/g, '') + '</ul>';
    });
    return html;
}

function toggleNotesPreview() {
    const editPane    = document.getElementById('notes-edit-pane');
    const previewPane = document.getElementById('notes-preview-pane');
    const previewBtn  = document.getElementById('notes-preview-btn');
    const textarea    = document.getElementById('notes-textarea');
    if (!editPane || !previewPane) return;

    notesPreviewActive = !notesPreviewActive;

    if (notesPreviewActive) {
        previewPane.innerHTML = renderMarkdown(textarea.value || '');
        editPane.style.display    = 'none';
        previewPane.style.display = 'block';
        if (previewBtn) previewBtn.classList.add('active');
    } else {
        editPane.style.display    = 'block';
        previewPane.style.display = 'none';
        if (previewBtn) previewBtn.classList.remove('active');
        textarea.focus();
    }
}

// ── Notes history (#12) ────────────────────────────────────────

function toggleNotesHistory() {
    const body   = document.getElementById('notes-history-body');
    const toggle = document.getElementById('notes-history-toggle');
    if (!body) return;

    const isOpen = body.style.display !== 'none';
    if (isOpen) {
        body.style.display = 'none';
        if (toggle) toggle.textContent = '▶';
    } else {
        body.style.display = 'block';
        if (toggle) toggle.textContent = '▼';
        if (!notesHistoryLoaded) loadNotesHistory();
    }
}

function loadNotesHistory() {
    const body = document.getElementById('notes-history-body');
    if (!body) return;
    body.innerHTML = '<div class="notes-history-empty">Loading…</div>';

    const params = new URLSearchParams({ action: 'history', session_id: cfg.sessionId });

    fetch('/api/notes.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            notesHistoryLoaded = true;
            const history = data.history || [];
            if (history.length === 0) {
                body.innerHTML = '<div class="notes-history-empty">No previous session notes found for this client.</div>';
                return;
            }
            body.innerHTML = '';
            history.forEach(entry => {
                const d   = new Date(entry.started_at);
                const fmt = d.toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' });
                const div = document.createElement('div');
                div.className = 'notes-history-entry';
                div.innerHTML = `<div class="notes-history-date">${fmt}</div>
                    <div class="notes-history-text">${escHtml(entry.note_content || '')}</div>`;
                body.appendChild(div);
            });
        })
        .catch(() => {
            notesHistoryLoaded = false;
            body.innerHTML = '<div class="notes-history-empty">Could not load history.</div>';
        });
}

// ── Action items (#15) ─────────────────────────────────────────

let actionItemsLoaded = false;
const actionItems = [];  // [{id, text, assigned_to, due_date, completed_at}]

function toggleActionItems() {
    const body   = document.getElementById('action-items-body');
    const toggle = document.getElementById('action-items-toggle');
    if (!body) return;
    const isOpen = body.style.display !== 'none';
    body.style.display  = isOpen ? 'none' : 'block';
    if (toggle) toggle.textContent = isOpen ? '▶' : '▼';
    if (!isOpen && !actionItemsLoaded) loadActionItems();
}

function loadActionItems() {
    fetch('/api/action_items.php?action=list&session_id=' + encodeURIComponent(cfg.sessionId))
        .then(r => r.json())
        .then(data => {
            actionItemsLoaded = true;
            actionItems.length = 0;
            (data.items || []).forEach(item => actionItems.push(item));
            renderActionItems();
        })
        .catch(() => { actionItemsLoaded = false; });
}

function renderActionItems() {
    const list  = document.getElementById('action-items-list');
    const badge = document.getElementById('action-items-count');
    if (!list) return;

    const pending = actionItems.filter(i => !i.completed_at).length;
    if (badge) badge.textContent = pending > 0 ? pending : '';

    if (actionItems.length === 0) {
        list.innerHTML = '<div class="action-items-empty">No action items yet.</div>';
        return;
    }
    list.innerHTML = '';
    actionItems.forEach(item => {
        const done = !!item.completed_at;
        const row  = document.createElement('div');
        row.className   = 'action-item-row';
        row.dataset.id  = item.id;

        const check = document.createElement('div');
        check.className = 'action-item-check' + (done ? ' done' : '');
        check.textContent = done ? '✓' : '';
        check.title = done ? 'Mark incomplete' : 'Mark complete';
        check.addEventListener('click', () => toggleActionItem(item.id, !done));

        const text = document.createElement('div');
        text.className  = 'action-item-text' + (done ? ' done' : '');
        text.textContent = item.text;

        const del = document.createElement('button');
        del.className   = 'action-item-del';
        del.textContent = '×';
        del.title = 'Delete';
        del.addEventListener('click', () => deleteActionItem(item.id));

        row.appendChild(check);
        row.appendChild(text);
        row.appendChild(del);
        list.appendChild(row);
    });
}

function addActionItem() {
    const input = document.getElementById('action-item-input');
    if (!input) return;
    const text = input.value.trim();
    if (!text) return;

    apiPost('/api/action_items.php', { action: 'add', session_id: cfg.sessionId, text })
        .then(data => {
            if (data.ok) {
                actionItems.push({ id: data.id, text, assigned_to: null, due_date: null, completed_at: null });
                renderActionItems();
                input.value = '';
            }
        });
}

function toggleActionItem(id, completed) {
    apiPost('/api/action_items.php', { action: 'complete', id, completed })
        .then(data => {
            if (data.ok) {
                const item = actionItems.find(i => i.id === id);
                if (item) item.completed_at = completed ? new Date().toISOString() : null;
                renderActionItems();
            }
        });
}

function deleteActionItem(id) {
    apiPost('/api/action_items.php', { action: 'delete', id })
        .then(data => {
            if (data.ok) {
                const idx = actionItems.findIndex(i => i.id === id);
                if (idx !== -1) actionItems.splice(idx, 1);
                renderActionItems();
            }
        });
}

// ── Highlight toolbar ──────────────────────────────────────────
if (cfg.isPractitioner) {
    // Track selection on mouseup — do NOT show toolbar yet, wait for right-click
    document.addEventListener('mouseup', (e) => {
        if (hlToolbar.contains(e.target)) return;

        const sel = window.getSelection();
        if (!sel || sel.isCollapsed) {
            selectionMsgId = null;
            return;
        }

        const range = sel.getRangeAt(0);
        const node  = range.commonAncestorContainer;
        const msgBody = (node.nodeType === 1 ? node : node.parentElement).closest('.msg-body');
        if (!msgBody) { selectionMsgId = null; return; }

        const msgEl = msgBody.closest('.chat-message');
        if (!msgEl) { selectionMsgId = null; return; }

        selectionMsgId = parseInt(msgEl.dataset.msgId);

        const msgText = msgBody.querySelector('.msg-text');
        if (!msgText) { selectionMsgId = null; return; }

        const before = range.cloneRange();
        before.selectNodeContents(msgText);
        before.setEnd(range.startContainer, range.startOffset);
        selectionStart = before.toString().length;
        selectionEnd   = selectionStart + range.toString().length;
        if (selectionStart >= selectionEnd) {
            selectionMsgId = null;
        } else {
            // Valid text selection — signal noting (highlighting in progress)
            if (!isNotingActive) { isNotingActive = true; sendNoting(true); }
            clearTimeout(notingTimer);
            notingTimer = setTimeout(() => { isNotingActive = false; sendNoting(false); }, 4000);
        }
    });

    // When selection is cleared, stop noting
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if (sel && sel.isCollapsed && isNotingActive) {
            clearTimeout(notingTimer);
            isNotingActive = false;
            sendNoting(false);
        }
    });

    // Right-click on a message: show full toolbar (Markup + React) if text is selected,
    // otherwise show React only (same as hover)
    messagesEl.addEventListener('contextmenu', (e) => {
        const msgEl = e.target.closest('.chat-message');
        if (!msgEl) return;
        e.preventDefault();
        reactTargetMsgId = parseInt(msgEl.dataset.msgId) || null;
        const msgId = parseInt(msgEl.dataset.msgId);
        if (selectionMsgId && selectionMsgId === msgId) {
            reactTargetMsgId = selectionMsgId;
            showFullToolbar(e.clientX, e.clientY);
        } else {
            positionReactToolbar(msgEl);
        }
    });

    document.addEventListener('mousedown', (e) => {
        if (!hlToolbar.contains(e.target)) {
            hlToolbar.classList.remove('visible');
        }
    });
}

// ── Reactions toolbar shared init ──────────────────────────────
// Non-practitioners: prevent contextmenu on messages (hover is their only path to React)
if (!cfg.isPractitioner) {
    const markupSection = document.getElementById('hl-section-markup');
    if (markupSection) markupSection.style.display = 'none';
    messagesEl.addEventListener('contextmenu', (e) => {
        if (e.target.closest('.chat-message')) e.preventDefault();
    });
}

// Keep toolbar open while hovering it; restore markup visibility when hiding
hlToolbar.addEventListener('mouseenter', () => clearTimeout(reactHoverTimer));
hlToolbar.addEventListener('mouseleave', () => {
    reactHoverTimer = setTimeout(() => {
        hlToolbar.classList.remove('visible');
        reactTargetMsgId = null;
        // Re-hide Markup section so next hover shows React-only
        const markupSection = document.getElementById('hl-section-markup');
        if (markupSection) markupSection.style.display = 'none';
    }, 150);
});

function applyHighlight(color) {
    if (!selectionMsgId) return;
    hlToolbar.classList.remove('visible');
    window.getSelection().removeAllRanges();

    apiPost('/api/highlights.php', {
        message_id:   selectionMsgId,
        start_offset: selectionStart,
        end_offset:   selectionEnd,
        color:        color,
    }).then(hl => {
        if (!hl || hl.error) return;
        // Update local message highlights and re-render
        const msg = messages.get(selectionMsgId);
        if (msg) {
            if (!msg.highlights) msg.highlights = [];
            if (!msg.highlights.some(h => +h.id === +hl.id)) {
                msg.highlights.push(hl);
                addOrUpdateMessage(msg);
            }
        }
    });
}
window.applyHighlight = applyHighlight;

function applyReaction(emoji, overrideMsgId) {
    const msgId = overrideMsgId ?? reactTargetMsgId ?? selectionMsgId;
    if (!msgId) return;
    hlToolbar.classList.remove('visible');
    reactTargetMsgId = null;

    apiPost('/api/reactions.php', {
        message_id: msgId,
        emoji,
        join_token: cfg.myJoinToken,
    }).then(res => {
        if (!res || res.error) return;
        const msg = messages.get(msgId);
        if (!msg) return;
        if (!msg.reactions) msg.reactions = [];

        if (res.action === 'removed') {
            msg.reactions = msg.reactions.filter(
                r => !(r.participant_id === cfg.myParticipantId && r.emoji === emoji)
            );
        } else if (res.action === 'updated') {
            const idx = msg.reactions.findIndex(r => r.participant_id === cfg.myParticipantId);
            if (idx >= 0) msg.reactions[idx].emoji = emoji;
        } else {
            const me = participants.get(cfg.myParticipantId);
            msg.reactions.push({
                participant_id: cfg.myParticipantId,
                emoji,
                avatar_url: me ? resolveAvatarUrl(me.avatar_url || me.avatar_path || '') : avatarPresets['Default'],
            });
        }
        addOrUpdateMessage(msg);
    });
}
window.applyReaction = applyReaction;

function pinFromSelection() {
    if (!selectionMsgId) return;
    hlToolbar.classList.remove('visible');
    window.getSelection().removeAllRanges();
    pinMessage(selectionMsgId);
}
window.pinFromSelection = pinFromSelection;

function pinMessage(msgId) {
    const msg = messages.get(msgId);
    if (!msg) return;
    const annotation = prompt('Add a note to this pin (optional):') || '';

    apiPost('/api/pins.php', {
        message_id: msgId,
        action:     'pin',
        annotation: annotation,
    }).then(res => {
        if (res.ok) {
            msg.is_pinned = true;
            addOrUpdateMessage(msg);
            pins.set(msgId, {
                id:           msgId,
                display_name: msg.display_name,
                content:      msg.content,
                sent_at:      msg.sent_at,
                annotation:   annotation,
            });
            renderPinPanel();
        }
    });
}

// ── Context menu ───────────────────────────────────────────────
function showCtxMenu(x, y, p) {
    const isOwn = p.id === cfg.myParticipantId;
    const editBtn = document.getElementById('ctx-edit-avatar');
    if (editBtn) editBtn.style.display = isOwn ? '' : 'none';
    const nicknameBtn = document.getElementById('ctx-edit-nickname');
    if (nicknameBtn) nicknameBtn.style.display = isOwn ? '' : 'none';
    const unlinkBtn = document.getElementById('ctx-unlink');
    if (unlinkBtn) {
        const isLinked = p.linked_to ||
            [...participants.values()].some(other => other.linked_to === p.id);
        unlinkBtn.style.display = isLinked ? '' : 'none';
    }
    const webcamBtn = document.getElementById('ctx-webcam-enable');
    if (webcamBtn) {
        webcamBtn.style.display  = isOwn ? '' : 'none';
        webcamBtn.textContent    = webcamActive ? 'Disable Webcam' : 'Enable Webcam';
    }
    ctxMenu.style.top  = y + 'px';
    ctxMenu.style.left = x + 'px';
    ctxMenu.classList.add('visible');
}

document.addEventListener('click', (e) => {
    if (!ctxMenu.contains(e.target)) {
        ctxMenu.classList.remove('visible');
    }
});

function triggerAvatarUpload() {
    ctxMenu.classList.remove('visible');
    avatarFileInput.click();
}
window.triggerAvatarUpload = triggerAvatarUpload;

function unlinkAvatar() {
    ctxMenu.classList.remove('visible');
    if (!ctxMenuTargetJoinToken) return;
    const formData = new FormData();
    formData.append('action', 'unlink');
    formData.append('join_token', ctxMenuTargetJoinToken);
    fetch('/api/participants.php', { method: 'POST', body: formData })
        .then(() => {
            participants.forEach(p => {
                if (p.join_token === ctxMenuTargetJoinToken) {
                    p.linked_to = null;
                    if (p.avatarEl) p.avatarEl.classList.remove('linked');
                }
            });
            renderUserList();
        });
}
window.unlinkAvatar = unlinkAvatar;

avatarFileInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file || !ctxMenuTargetJoinToken) return;

    const formData = new FormData();
    formData.append('action', 'update_avatar');
    formData.append('join_token', ctxMenuTargetJoinToken);
    formData.append('avatar', file);

    fetch('/api/participants.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.ok && res.avatar_url) {
                participants.forEach(p => {
                    if (p.join_token === ctxMenuTargetJoinToken) {
                        p.avatar_path = res.avatar_url;
                        p.avatar_url  = res.avatar_url;
                        if (p.avatarEl) p.avatarEl.src = res.avatar_url;
                    }
                });
                renderUserList();
            }
        });

    avatarFileInput.value = '';
});

// ── Typing bubble helper ───────────────────────────────────────
function setTypingBubble(participantId, show) {
    clearTimeout(typingHideTimers.get(participantId));
    typingHideTimers.delete(participantId);
    const bubble = roomEl.querySelector(`[data-typing-for="${participantId}"]`);
    if (!bubble) return;
    if (show) {
        bubble.style.display = 'flex';
        // Auto-hide after 6 s in case stop-typing event is missed
        typingHideTimers.set(participantId, setTimeout(() => {
            bubble.style.display = 'none';
            typingHideTimers.delete(participantId);
        }, 6000));
    } else {
        bubble.style.display = 'none';
    }
}

// ── Noting bubble helper ───────────────────────────────────────
function setNotingBubble(participantId, show) {
    clearTimeout(notingHideTimers.get(participantId));
    notingHideTimers.delete(participantId);
    const bubble = roomEl.querySelector(`[data-noting-for="${participantId}"]`);
    if (!bubble) return;
    if (show) {
        bubble.style.display = 'flex';
        // Auto-hide after 8 s in case stop-noting event is missed
        notingHideTimers.set(participantId, setTimeout(() => {
            bubble.style.display = 'none';
            notingHideTimers.delete(participantId);
        }, 8000));
    } else {
        bubble.style.display = 'none';
    }
}

// ── Noting indicator ───────────────────────────────────────────
function sendNoting(isNoting) {
    if (cfg.myParticipantId) setNotingBubble(cfg.myParticipantId, isNoting);
    const body = { session_id: cfg.sessionId, is_noting: isNoting ? 1 : 0 };
    if (cfg.myJoinToken) body.join_token = cfg.myJoinToken;
    fetch('/api/noting.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }).catch(() => {});
}

// ── Typing indicator ───────────────────────────────────────────
function sendTyping(isTyping) {
    // Immediate local update so sender sees their own bubble without waiting for poll
    if (cfg.myParticipantId) setTypingBubble(cfg.myParticipantId, isTyping);
    const body = { session_id: cfg.sessionId, is_typing: isTyping ? 1 : 0 };
    if (cfg.myJoinToken) body.join_token = cfg.myJoinToken;
    fetch('/api/typing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }).catch(() => {});
}

// ── Send message ───────────────────────────────────────────────
function sendMessage() {
    if (pendingFile) { sendFile(); return; }
    if (voiceBlob)   { sendVoiceNote(); return; }

    clearTimeout(typingTimer);
    if (isTypingActive) { isTypingActive = false; sendTyping(false); }
    const text = chatInput.value.trim();
    if (!text) return;
    chatInput.value = '';

    const body = {
        session_id: cfg.sessionId,
        content:    text,
    };
    if (cfg.myJoinToken)   body.join_token    = cfg.myJoinToken;

    // Optimistic render
    const tempId  = 'tmp_' + Date.now();
    const now     = new Date().toISOString();
    const tempMsg = {
        id:              tempId,
        participant_id:  cfg.myParticipantId,
        display_name:    findMyName(),
        content:         text,
        sent_at:         now,
        is_practitioner: cfg.isPractitioner,
        is_pinned:       false,
        highlights:      [],
    };
    addOrUpdateMessage(tempMsg);

    // Show chat bubble for own message
    if (cfg.myParticipantId) {
        showChatBubble(cfg.myParticipantId, text);
    }

    apiPost('/api/messages.php', body).then(msg => {
        if (msg && msg.id) {
            // Remove temp
            const tempEl = messagesEl.querySelector(`[data-msg-id="${tempId}"]`);
            if (tempEl) tempEl.remove();
            messages.delete(tempId);
            // Add real (poll will also deliver this via message event; dedup handled in processEvent)
            addOrUpdateMessage(msg);
        }
        // AI trigger: message starts with assistant name (case-insensitive)
        aiCheckTrigger(text);
    });
}

function findMyName() {
    if (!cfg.myParticipantId) return 'You';
    const p = participants.get(cfg.myParticipantId);
    return p ? p.display_name : 'You';
}

sendBtn.addEventListener('click', sendMessage);
chatInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});
chatInput.addEventListener('input', () => {
    if (!isTypingActive) { isTypingActive = true; sendTyping(true); }
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => { isTypingActive = false; sendTyping(false); }, 3000);
});

// ── Long polling (event-based) ─────────────────────────────────
function buildPollUrl() {
    const params = new URLSearchParams({
        session_id:    cfg.sessionId,
        last_event_id: lastEventId,
    });
    if (cfg.myJoinToken) params.set('join_token', cfg.myJoinToken);
    return '/api/poll.php?' + params.toString();
}

// (pollActive declared in state block above)

function processEvent(evt) {
    const p = evt.payload;
    switch (evt.type) {

        case 'message': {
            if (!messages.has(p.id)) {
                if (!p.reactions) p.reactions = [];
                addOrUpdateMessage(p);
                if (p.message_type === 'file' || p.message_type === 'voice_note') {
                    maybeShowFileDisclaimer(p.message_type);
                } else if (p.participant_id && p.participant_id !== cfg.myParticipantId) {
                    showChatBubble(p.participant_id, p.content);
                }
            }
            break;
        }

        case 'reaction': {
            // Ignore own echo (optimistic update already applied)
            if (p.participant_id === cfg.myParticipantId) break;
            const rMsg = messages.get(p.message_id);
            if (!rMsg) break;
            if (!rMsg.reactions) rMsg.reactions = [];

            if (p.action === 'removed') {
                rMsg.reactions = rMsg.reactions.filter(
                    r => !(r.participant_id === p.participant_id && r.emoji === p.emoji)
                );
            } else if (p.action === 'updated') {
                const idx = rMsg.reactions.findIndex(r => r.participant_id === p.participant_id);
                if (idx >= 0) rMsg.reactions[idx].emoji = p.emoji;
                else {
                    const rPart = participants.get(p.participant_id);
                    rMsg.reactions.push({ participant_id: p.participant_id, emoji: p.emoji,
                        avatar_url: rPart ? resolveAvatarUrl(rPart.avatar_url || rPart.avatar_path || '') : avatarPresets['Default'] });
                }
            } else {
                const rPart = participants.get(p.participant_id);
                rMsg.reactions.push({ participant_id: p.participant_id, emoji: p.emoji,
                    avatar_url: rPart ? resolveAvatarUrl(rPart.avatar_url || rPart.avatar_path || '') : avatarPresets['Default'] });
            }
            addOrUpdateMessage(rMsg);
            break;
        }

        case 'participant_join': {
            const alreadyKnown = participants.has(p.id);
            if (!alreadyKnown && p.id !== cfg.myParticipantId) {
                const joinTs = p.joined_at ? new Date(p.joined_at).getTime() : undefined;
                addSystemMessage(p.display_name + ' joined the room.', joinTs);
            }
            syncParticipant(p);
            renderUserList();
            refreshNotesSelect();
            break;
        }

        case 'participant_reconnect': {
            if (p.id !== cfg.myParticipantId) {
                addSystemMessage(p.display_name + ' reconnected.');
            }
            // Clear any disconnected state
            const existingForRecon = participants.get(p.id);
            if (existingForRecon?.avatarEl) existingForRecon.avatarEl.classList.remove('avatar-dim', 'avatar-disconnected');
            participantPresenceState.set(p.id, 'online');
            syncParticipant(p);
            renderUserList();
            refreshNotesSelect();
            break;
        }

        case 'position': {
            const part = participants.get(p.participant_id);
            if (part) {
                part.position_x = p.position_x;
                part.position_y = p.position_y;
                if (part.avatarEl) {
                    const isRemote = p.participant_id !== cfg.myParticipantId;
                    updateAvatarPosition(
                        part.avatarEl,
                        p.position_x * roomW(), p.position_y * roomH(),
                        p.participant_id, isRemote
                    );
                }
                // Re-dock AI avatars when practitioner moves
                if (p.participant_id === cfg.hostParticipantId) redockAiAvatars();
            }
            break;
        }

        case 'typing': {
            const part = participants.get(p.participant_id);
            if (part) {
                part.typing_at = p.typing_at;
                setTypingBubble(p.participant_id, !!p.typing_at);
            }
            break;
        }

        case 'noting': {
            const notingPart = participants.get(p.participant_id);
            if (notingPart) {
                setNotingBubble(p.participant_id, !!p.is_noting);
            }
            break;
        }

        case 'highlight': {
            const msg = messages.get(p.message_id);
            if (msg) {
                if (!msg.highlights) msg.highlights = [];
                if (!msg.highlights.some(h => +h.id === +p.id)) {
                    msg.highlights.push(p);
                    addOrUpdateMessage(msg);
                }
            }
            break;
        }

        case 'pin': {
            if (p.is_pinned) {
                pins.set(p.message_id, p);
            } else {
                pins.delete(p.message_id);
            }
            renderPinPanel();
            const pinnedMsg = messages.get(p.message_id);
            if (pinnedMsg) {
                pinnedMsg.is_pinned = p.is_pinned;
                addOrUpdateMessage(pinnedMsg);
            }
            break;
        }

        case 'avatar': {
            const part = participants.get(p.participant_id);
            if (part) {
                // Cancel pending webcam frame animations for this participant
                webcamFrameSeq.set(p.participant_id, (webcamFrameSeq.get(p.participant_id) || 0) + 1);
                part.avatar_path = p.avatar_path;
                part.avatar_url  = resolveAvatarUrl(p.avatar_path);
                if (part.avatarEl) part.avatarEl.src = part.avatar_url;
                // Update user list avatar in-place — no need to rebuild the whole list
                const listItem = userListEl.querySelector(`[data-participant-id="${p.participant_id}"]`);
                if (listItem) {
                    const listImg = listItem.querySelector('.participant-avatar');
                    if (listImg) listImg.src = part.avatar_url;
                }
                setWebcamBadge(p.participant_id, false);
            }
            break;
        }

        case 'link': {
            const part = participants.get(p.participant_id);
            if (part) {
                part.linked_to = p.linked_to;
                if (part.avatarEl) {
                    if (p.linked_to) part.avatarEl.classList.add('linked');
                    else             part.avatarEl.classList.remove('linked');
                }
                // Snap: target (linked_to person) LEFT, initiator RIGHT, 3px gap
                if (p.linked_to) {
                    const target = participants.get(p.linked_to);
                    if (target && target.avatarEl) {
                        target.avatarEl.classList.add('linked');
                        const avatarPx = 150;
                        const gap = 12;
                        const rW = roomW();
                        let initX = Math.max(avatarPx + gap, Math.min(part.position_x * rW, rW - avatarPx));
                        let tgtX  = initX - avatarPx - gap;
                        const snapY = Math.max(0, Math.min(part.position_y * roomH(), roomH() - avatarPx));
                        updateAvatarPosition(target.avatarEl, tgtX, snapY, target.id, true);
                        target.position_x = tgtX / roomW();
                        target.position_y = snapY / roomH();
                    }
                }
            }
            renderUserList();
            // Re-dock AI avatars when link state changes (affects dock side)
            redockAiAvatars();
            break;
        }

        case 'background': {
            applyBackground(p.path, p.mime);
            break;
        }

        case 'webcam_frame': {
            const wcPart = participants.get(p.participant_id);
            if (wcPart) {
                // Bump sequence to cancel any pending frame-animation timeouts
                const seq = (webcamFrameSeq.get(p.participant_id) || 0) + 1;
                webcamFrameSeq.set(p.participant_id, seq);

                const updateAvatar = (frameData) => {
                    wcPart.avatar_url = frameData;
                    if (wcPart.avatarEl) wcPart.avatarEl.src = frameData;
                    const listItem = userListEl.querySelector(`[data-participant-id="${p.participant_id}"]`);
                    if (listItem) {
                        const listImg = listItem.querySelector('.participant-avatar');
                        if (listImg) listImg.src = frameData;
                    }
                };
                if (Array.isArray(p.frames) && p.frames.length > 0) {
                    const frameInterval = Math.floor(2000 / p.frames.length);
                    updateAvatar(p.frames[0]);
                    for (let i = 1; i < p.frames.length; i++) {
                        const capturedSeq = seq;
                        const capturedI   = i;
                        setTimeout(() => {
                            if (webcamFrameSeq.get(p.participant_id) === capturedSeq) {
                                updateAvatar(p.frames[capturedI]);
                            }
                        }, capturedI * frameInterval);
                    }
                    setWebcamBadge(p.participant_id, true);
                } else if (p.frame_data) {
                    updateAvatar(p.frame_data);
                    setWebcamBadge(p.participant_id, true);
                }
            }
            break;
        }

        case 'voice_note_listened': {
            const listenedRow = messagesEl.querySelector(`.listened-avatars[data-message-id="${p.message_id}"]`);
            if (listenedRow && !listenedRow.querySelector(`img[data-listener="${p.participant_id}"]`)) {
                const img = document.createElement('img');
                img.src                  = p.avatar_url;
                img.className            = 'listener-avatar';
                img.dataset.listener     = p.participant_id;
                img.title                = (p.display_name || '') + ' listened';
                listenedRow.appendChild(img);
            }
            break;
        }

        case 'activity_start':
        case 'activity_end': {
            processActivityEvent(evt);
            break;
        }

        case 'message_edit': {
            const editedMsg = messages.get(p.message_id);
            if (editedMsg) {
                editedMsg.content   = p.content;
                editedMsg.edited_at = p.edited_at || new Date().toISOString();
                addOrUpdateMessage(editedMsg);
            }
            break;
        }

        case 'message_delete': {
            const deletedMsg = messages.get(p.message_id);
            if (deletedMsg) {
                deletedMsg.is_deleted = 1;
                if (cfg.isPractitioner) {
                    addOrUpdateMessage(deletedMsg);
                } else {
                    const domEl = messagesEl.querySelector(`[data-msg-id="${p.message_id}"]`);
                    if (domEl) domEl.remove();
                    messages.delete(p.message_id);
                }
            }
            break;
        }

        case 'participant_rename': {
            const renamedPart = participants.get(p.participant_id);
            if (renamedPart) {
                renamedPart.display_name = p.new_name;
                // Update all chat messages from this participant
                messages.forEach(msg => {
                    if (msg.participant_id === p.participant_id) {
                        msg.display_name = p.new_name;
                        addOrUpdateMessage(msg);
                    }
                });
                // Update label on canvas
                const label = roomEl.querySelector(`[data-label-for="${p.participant_id}"]`);
                if (label) label.textContent = p.new_name;
                renderUserList();
            }
            // System message
            addSystemMessage(`${p.old_name} is now known as ${p.new_name}.`);
            break;
        }

        case 'session_begun': {
            // Remove chat dim for all non-host participants
            if (!cfg.isPractitioner) {
                document.getElementById('container')?.classList.remove('session-not-begun');
                const input = document.getElementById('chat-input');
                if (input) input.removeAttribute('disabled');
                addSystemMessage('✨ Session has begun');
            }
            break;
        }

        case 'session_end': {
            if (!cfg.isPractitioner) {
                pollActive = false;
                clearTimeout(pollTimeout);
                document.body.innerHTML =
                    '<div style="display:flex;align-items:center;justify-content:center;height:100vh;' +
                    'background:#0f0e17;color:#e0dff5;font-family:-apple-system,sans-serif;text-align:center;padding:40px;">' +
                    '<div><div style="font-size:48px;margin-bottom:24px;">&#10003;</div>' +
                    '<h1 style="font-size:28px;margin-bottom:12px;color:#7c6af7;">Session Ended</h1>' +
                    '<p style="font-size:16px;color:#a09ec0;max-width:380px;margin:0 auto 24px;">' +
                    'The Host has ended this session.<br>Thank you for joining.</p>' +
                    '<button id="room-export-btn" ' +
                    'style="display:inline-block;padding:10px 22px;background:#7c6af7;color:#fff;' +
                    'border:none;border-radius:6px;font-size:14px;cursor:pointer;margin-bottom:12px;">' +
                    '&#128196; Download your transcript</button>' +
                    '<br><p style="font-size:13px;color:#6e6c88;margin-top:12px;">You may safely close this window.</p>' +
                    '</div></div>';
            document.getElementById('room-export-btn')?.addEventListener('click', () => window.open('/api/client_export.php', '_blank'));
            }
            break;
        }
    }
}

let pollEmptyStreak = 0;
async function poll() {
    if (!pollActive) return;
    let gotEvents = false;
    try {
        const resp = await fetch(buildPollUrl(), { signal: AbortSignal.timeout(25000) });
        if (!resp.ok) throw new Error('Poll error: ' + resp.status);
        const data = await resp.json();

        if (data.events && data.events.length > 0) {
            gotEvents = true;
            data.events.forEach(evt => {
                processEvent(evt);
                if (evt.id > lastEventId) lastEventId = evt.id;
            });
        }
    } catch (err) {
        if (err.name !== 'AbortError' && err.name !== 'TimeoutError') {
            if (window.SS_DEBUG) console.warn('Poll error:', err);
        }
    }

    // Exponential backoff on empty polls — 500ms → 1s → 2s → 4s (cap).
    // Reset to 500ms on any event delivery so live conversation stays snappy.
    if (gotEvents) pollEmptyStreak = 0; else pollEmptyStreak++;
    const delay = Math.min(500 * Math.pow(2, Math.max(0, pollEmptyStreak - 1)), 4000);

    if (pollActive) {
        pollTimeout = setTimeout(poll, delay);
    }
}

// ── Presence / disconnect detection ───────────────────────────
// Fetches a full participant presence snapshot every 5 seconds via
// api/presence.php?mode=snapshot (previously fetched on every poll cycle).
function applyPresenceSnapshot(snapshot) {
    for (const [idStr, status] of Object.entries(snapshot)) {
        if (status && status !== 'unknown') participantPresence.set(parseInt(idStr), status);
    }
    participants.forEach((p, id) => {
        if (!p.avatarEl || id === cfg.myParticipantId) return;
        const status = participantPresence.get(id);
        if (!status) return;
        const prev = participantPresenceState.get(id) || 'online';
        if (status === prev) return;
        participantPresenceState.set(id, status);
        if (status === 'dim') {
            p.avatarEl.classList.add('avatar-dim');
            p.avatarEl.classList.remove('avatar-disconnected');
        } else if (status === 'disconnected') {
            p.avatarEl.classList.add('avatar-dim', 'avatar-disconnected');
            addSystemMessage(p.display_name + ' disconnected.');
        } else {
            if (prev === 'disconnected') addSystemMessage(p.display_name + ' reconnected.');
            p.avatarEl.classList.remove('avatar-dim', 'avatar-disconnected');
        }
    });
}

async function checkPresence() {
    try {
        const params = new URLSearchParams({ session_id: cfg.sessionId, mode: 'snapshot' });
        if (cfg.myJoinToken) params.set('join_token', cfg.myJoinToken);
        const resp = await fetch('/api/presence.php?' + params.toString());
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.presence) applyPresenceSnapshot(data.presence);
    } catch (_) {}
}
setInterval(checkPresence, 5000);

// ── Duration management (host only) ───────────────────────────
let durationTotal   = 0;   // total seconds set
let durationLeft    = 0;   // seconds remaining
let durationRunning = false;
let durationTimer   = null;
const durationAlerts = new Set(); // milestone minutes already alerted

function durationStart() {
    if (!cfg.isPractitioner) return;
    const input = document.getElementById('duration-input');
    const mins  = input ? parseInt(input.value) : 0;
    if (!durationRunning) {
        if (mins > 0) {
            durationTotal = mins * 60;
            durationLeft  = durationTotal;
            durationAlerts.clear();
        }
        if (durationLeft <= 0) return;
    }
    durationRunning = true;
    document.getElementById('duration-start-btn').style.display  = 'none';
    document.getElementById('duration-pause-btn').style.display  = '';
    document.getElementById('duration-stop-btn').style.display   = '';
    if (input) input.disabled = true;
    durationTick();
}
window.durationStart = durationStart;

function durationPause() {
    durationRunning = false;
    clearTimeout(durationTimer);
    document.getElementById('duration-start-btn').style.display  = '';
    document.getElementById('duration-start-btn').textContent    = 'Resume';
    document.getElementById('duration-pause-btn').style.display  = 'none';
}
window.durationPause = durationPause;

function durationStop() {
    durationRunning = false;
    clearTimeout(durationTimer);
    durationLeft = 0;
    durationAlerts.clear();
    const display = document.getElementById('duration-display');
    if (display) display.textContent = '';
    const input = document.getElementById('duration-input');
    if (input) { input.value = ''; input.disabled = false; }
    document.getElementById('duration-start-btn').style.display  = '';
    document.getElementById('duration-start-btn').textContent    = 'Start';
    document.getElementById('duration-pause-btn').style.display  = 'none';
    document.getElementById('duration-stop-btn').style.display   = 'none';
}
window.durationStop = durationStop;

function durationTick() {
    if (!durationRunning) return;

    const display = document.getElementById('duration-display');
    const h = Math.floor(durationLeft / 3600);
    const m = Math.floor((durationLeft % 3600) / 60);
    const s = durationLeft % 60;
    const timeStr = (h > 0 ? h + ':' : '') +
                    String(m).padStart(h > 0 ? 2 : 1, '0') + ':' +
                    String(s).padStart(2, '0');
    if (display) {
        display.textContent = timeStr;
        display.style.color = durationLeft <= 120 ? '#dc143c' : 'var(--accent)';
    }

    const minsLeft = Math.ceil(durationLeft / 60);
    for (const milestone of [15, 10, 5, 2, 1]) {
        if (minsLeft === milestone && !durationAlerts.has(milestone)) {
            durationAlerts.add(milestone);
            addSystemMessage(`⏱ ${milestone} minute${milestone > 1 ? 's' : ''} remaining.`);
        }
    }

    if (durationLeft <= 0) {
        durationRunning = false;
        if (display) { display.textContent = '0:00'; display.style.color = '#dc143c'; }
        addSystemMessage('⏱ Session time has elapsed.');
        document.getElementById('duration-start-btn').style.display  = '';
        document.getElementById('duration-start-btn').textContent    = 'Restart';
        document.getElementById('duration-pause-btn').style.display  = 'none';
        return;
    }

    durationLeft--;
    durationTimer = setTimeout(durationTick, 1000);
}

// ── End session ────────────────────────────────────────────────
function endSession() {
    if (!cfg.isPractitioner) return;
    // Show the recap modal instead of browser confirm
    const modal = document.getElementById('recap-modal');
    if (modal) {
        modal.style.display = 'flex';
    } else {
        // Fallback if modal not in DOM (shouldn't happen for practitioners)
        _doExport({});
    }
}

function dismissRecapModal() {
    const modal = document.getElementById('recap-modal');
    if (modal) modal.style.display = 'none';
}

async function submitRecapAndExport() {
    const btn = document.getElementById('recap-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Exporting…'; }

    const recap = {
        key_summary:    (document.getElementById('recap-summary')?.value    || '').trim(),
        key_insights:   (document.getElementById('recap-insights')?.value   || '').trim(),
        agreed_actions: (document.getElementById('recap-actions')?.value    || '').trim(),
        next_focus:     (document.getElementById('recap-next-focus')?.value || '').trim(),
        series_id:      parseInt(document.getElementById('recap-series-id')?.value || '0', 10) || null,
    };

    pollActive = false;
    clearTimeout(pollTimeout);

    await _doExport(recap);
}

async function _doExport(recap) {
    try {
        const resp = await fetch('/api/export.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                session_id: cfg.sessionId,
                room_id:    cfg.roomId,
                recap:      recap,
            }),
        });

        if (!resp.ok) throw new Error('Export failed: ' + resp.status);
        const html = await resp.text();

        // Download as HTML file (user can open + print-to-PDF)
        const blob = new Blob([html], { type: 'text/html' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        const date = new Date().toISOString().slice(0, 10);
        a.href     = url;
        a.download = 'session-' + date + '.html';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        // Redirect to dashboard
        setTimeout(() => {
            window.location.href = '/dashboard.php';
        }, 1500);

    } catch (err) {
        const btn = document.getElementById('recap-submit-btn');
        if (btn) { btn.disabled = false; btn.textContent = 'Export & Close Session'; }
        alert('Export failed: ' + err.message);
        pollActive = true;
        poll();
    }
}
window.endSession          = endSession;
window.dismissRecapModal   = dismissRecapModal;
window.submitRecapAndExport = submitRecapAndExport;

// ── API helper ─────────────────────────────────────────────────
async function apiPost(url, data) {
    try {
        // Always include CSRF token — endpoints ignore it on join_token paths,
        // require it on practitioner session paths.
        const payload = Object.assign({ csrf_token: cfg.csrfToken }, data);
        const resp = await fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        return await resp.json();
    } catch (err) {
        if (window.SS_DEBUG) console.error('API error:', err);
        return null;
    }
}

// ── Utility helpers ─────────────────────────────────────────────
function formatFileSize(bytes) {
    if (bytes < 1024)             return bytes + ' B';
    if (bytes < 1024 * 1024)      return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function maybeShowFileDisclaimer(type) {
    if (type === 'voice_note') return; // Voice notes are retained permanently
    if (fileDisclaimerShown) return;
    fileDisclaimerShown = true;
    addSystemMessage('Files shared in this session are not stored permanently and will not be accessible by any party after the session ends.');
}

// ── Lightbox ────────────────────────────────────────────────────
function openLightbox(src) {
    const lb  = document.getElementById('lightbox');
    const img = document.getElementById('lightbox-img');
    if (lb && img) { img.src = src; lb.classList.add('visible'); }
}
function closeLightbox() {
    const lb = document.getElementById('lightbox');
    if (lb) lb.classList.remove('visible');
}
window.closeLightbox = closeLightbox;

// ── Voice note listen reporting ─────────────────────────────────
function reportVoiceNoteListen(messageId) {
    const body = { session_id: cfg.sessionId, message_id: messageId };
    if (cfg.myJoinToken) body.join_token = cfg.myJoinToken;
    fetch('/api/voice_note_listen.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
    }).catch(() => {});
}

// ── Background change ───────────────────────────────────────────
function applyBackground(path, mime) {
    const bgEl = document.querySelector('.bg');
    if (!bgEl) return;
    bgEl.style.transition = 'opacity 0.6s';
    bgEl.style.opacity    = '0';
    setTimeout(() => {
        if (mime && mime.startsWith('video/')) {
            bgEl.style.backgroundImage = '';
            bgEl.innerHTML = `<video autoplay loop muted playsinline oncontextmenu="return false"
                style="width:100%;height:100%;object-fit:cover;pointer-events:none;">
                <source src="${escHtml(path)}" type="${escHtml(mime)}"></video>`;
        } else {
            bgEl.innerHTML = '';
            bgEl.style.backgroundImage = `url('${path.replace(/'/g, "\\'")}')`;
        }
        bgEl.style.opacity = '1';
    }, 600);
    cfg.backgroundPath = path;
    cfg.backgroundMime = mime;
}

function openBgPicker() {
    const roomCtx = document.getElementById('room-ctx-menu');
    if (roomCtx) roomCtx.style.display = 'none';
    const picker = document.getElementById('bg-picker');
    if (!picker) return;
    picker.classList.add('visible');
    if (!bgPickerLoaded) loadBackgrounds();
}
function closeBgPicker() {
    const picker = document.getElementById('bg-picker');
    if (picker) picker.classList.remove('visible');
}
window.closeBgPicker = closeBgPicker;
window.openBgPicker  = openBgPicker;

function loadBackgrounds() {
    fetch('/api/backgrounds.php?room_id=' + cfg.roomId)
        .then(r => r.json())
        .then(data => {
            allBgs = data.backgrounds || [];
            bgPickerLoaded = true;
            renderBgGrid();
        }).catch(() => {});
}

function renderBgGrid() {
    const grid = document.getElementById('bg-picker-grid');
    if (!grid) return;
    grid.innerHTML = '';
    const filtered = allBgs.filter(b =>
        currentBgTab === 'images' ? b.mime_type.startsWith('image/') : b.mime_type.startsWith('video/')
    );
    if (filtered.length === 0) {
        grid.innerHTML = `<p class="bg-empty">No ${currentBgTab} uploaded yet.</p>`;
        return;
    }
    filtered.forEach(b => {
        const item = document.createElement('div');
        item.className = 'bg-thumb';
        if (b.file_path === cfg.backgroundPath) item.classList.add('active');
        if (b.mime_type.startsWith('video/')) {
            if (b.thumb_path) {
                item.style.backgroundImage = `url('${b.thumb_path.replace(/'/g, "\\'")}')`;
            } else {
                const vid = document.createElement('video');
                vid.src     = b.file_path;
                vid.muted   = true;
                vid.loop    = true;
                vid.preload = 'metadata';
                vid.addEventListener('mouseenter', () => vid.play());
                vid.addEventListener('mouseleave', () => vid.pause());
                item.appendChild(vid);
            }
        } else {
            item.style.backgroundImage = `url('${b.file_path.replace(/'/g, "\\'")}')`;
        }
        item.title = b.original_name;
        item.addEventListener('click', () => selectBackground(b.file_path, b.mime_type));
        grid.appendChild(item);
    });
}

function switchBgTab(tab) {
    currentBgTab = tab;
    document.getElementById('bg-tab-images').classList.toggle('active', tab === 'images');
    document.getElementById('bg-tab-videos').classList.toggle('active', tab === 'videos');
    renderBgGrid();
}
window.switchBgTab = switchBgTab;

function selectBackground(path, mime) {
    closeBgPicker();
    apiPost('/api/backgrounds.php', {
        action:          'set',
        room_id:         cfg.roomId,
        session_id:      cfg.sessionId,
        background_path: path,
        mime_type:       mime,
    }).then(res => {
        if (res && res.ok) applyBackground(path, mime);
    });
}

function extractVideoThumbnail(file) {
    return new Promise(resolve => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.muted   = true;
        const url = URL.createObjectURL(file);
        video.src = url;
        video.addEventListener('loadeddata', () => {
            video.currentTime = 0.067;
        });
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

async function uploadBackground(input) {
    const file = input.files[0];
    if (!file) return;

    const progressWrap = document.getElementById('bg-upload-progress');
    const progressBar  = document.getElementById('bg-upload-bar');
    const progressPct  = document.getElementById('bg-upload-pct');
    const progressMsg  = document.getElementById('bg-upload-msg');

    const formData = new FormData();
    formData.append('room_id', cfg.roomId);
    formData.append('file', file);

    if (file.type.startsWith('video/')) {
        const thumb = await extractVideoThumbnail(file);
        if (thumb) formData.append('thumb_data', thumb);
    }

    const xhr = new XMLHttpRequest();

    if (progressWrap) {
        progressWrap.style.display = 'block';
        if (progressBar) progressBar.style.width = '0%';
        if (progressPct) progressPct.textContent  = '0%';
        if (progressMsg) progressMsg.textContent  = 'Uploading…';
    }

    xhr.upload.addEventListener('progress', (ev) => {
        if (ev.lengthComputable && progressBar && progressPct) {
            const pct = Math.round((ev.loaded / ev.total) * 100);
            progressBar.style.width = pct + '%';
            progressPct.textContent = pct + '%';
            if (progressMsg) progressMsg.textContent = pct < 100 ? 'Uploading…' : 'Processing…';
        }
    });

    xhr.addEventListener('load', () => {
        if (progressWrap) progressWrap.style.display = 'none';
        if (xhr.status >= 200 && xhr.status < 400) {
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.ok && data.background) {
                    allBgs.unshift(data.background);
                    input.value = '';
                    selectBackground(data.background.file_path, data.background.mime_type);
                }
            } catch (err) {
                alert('Upload failed — unexpected server response.');
            }
        } else {
            alert('Upload failed (server returned ' + xhr.status + '). Check that your server allows large file uploads.');
        }
    });

    xhr.addEventListener('error', () => {
        if (progressWrap) progressWrap.style.display = 'none';
        alert('Upload failed — the server closed the connection. This usually means the file exceeds your server\'s PHP upload limit (upload_max_filesize / post_max_size). Please check your PHP configuration.');
    });

    xhr.open('POST', '/api/backgrounds.php');
    xhr.send(formData);
}
window.uploadBackground = uploadBackground;

// Room area right-click — suppress browser default for non-hosts
if (!cfg.isPractitioner) {
    roomEl.addEventListener('contextmenu', e => { e.preventDefault(); });
}

// Room area right-click — background picker (host only)
if (cfg.isPractitioner) {
    const roomCtxMenu = document.getElementById('room-ctx-menu');
    if (roomCtxMenu) {
        roomEl.addEventListener('contextmenu', (e) => {
            // Only fire when clicking the room background, not an avatar or UI element
            if (e.target.classList.contains('avatar') || e.target.closest('.avatar') ||
                e.target.closest('#ctx-menu') || e.target.closest('#bg-picker')) return;
            e.preventDefault();
            roomCtxMenu.style.top   = e.clientY + 'px';
            roomCtxMenu.style.left  = e.clientX + 'px';
            roomCtxMenu.style.display = 'block';
        });
        document.addEventListener('click', (e) => {
            if (roomCtxMenu && !roomCtxMenu.contains(e.target)) {
                roomCtxMenu.style.display = 'none';
            }
        });
    }
}

// ── Voice notes ─────────────────────────────────────────────────
async function toggleVoiceRecording() {
    if (voiceRecording) { stopRecording(); } else { await startRecording(); }
}
window.toggleVoiceRecording = toggleVoiceRecording;

async function startRecording() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recordedChunks = [];
        const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus' : 'audio/webm';
        mediaRecorder = new MediaRecorder(stream, { mimeType });
        mediaRecorder.addEventListener('dataavailable', e => {
            if (e.data.size > 0) recordedChunks.push(e.data);
        });
        mediaRecorder.addEventListener('stop', () => {
            stream.getTracks().forEach(t => t.stop());
            voiceBlob = new Blob(recordedChunks, { type: mediaRecorder.mimeType });
            showVoicePreview();
        });
        mediaRecorder.start(250); // collect data every 250ms
        voiceRecording = true;
        document.getElementById('voice-btn').classList.add('recording');
        document.getElementById('input-area').classList.add('voice-active');
        sendTyping(true); // show indicator to all participants while recording
    } catch (err) {
        alert('Microphone access is required to send voice notes.');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
    voiceRecording = false;
    document.getElementById('voice-btn').classList.remove('recording');
    document.getElementById('input-area').classList.remove('voice-active');
    sendTyping(false); // clear indicator
}

function showVoicePreview() {
    const preview = document.getElementById('voice-preview');
    if (!preview) return;
    preview.innerHTML = '';
    preview.style.display = 'flex';

    const audio = document.createElement('audio');
    audio.controls = true;
    audio.src      = URL.createObjectURL(voiceBlob);
    preview.appendChild(audio);

    const removeBtn = document.createElement('button');
    removeBtn.className   = 'btn btn-ghost';
    removeBtn.textContent = '✕ Remove';
    removeBtn.onclick     = () => {
        voiceBlob = null;
        preview.style.display = 'none';
        preview.innerHTML     = '';
    };
    preview.appendChild(removeBtn);

    const sendBtn = document.createElement('button');
    sendBtn.className   = 'btn btn-primary';
    sendBtn.textContent = 'Send';
    sendBtn.onclick     = sendVoiceNote;
    preview.appendChild(sendBtn);
}

function sendVoiceNote() {
    if (!voiceBlob) return;
    const caption = chatInput.value.trim();
    chatInput.value = '';
    const blob = voiceBlob;
    voiceBlob  = null;
    const preview = document.getElementById('voice-preview');
    if (preview) { preview.style.display = 'none'; preview.innerHTML = ''; }

    const formData = new FormData();
    formData.append('session_id', cfg.sessionId);
    formData.append('audio', blob, 'voice_note.webm');
    if (cfg.myJoinToken)  formData.append('join_token',  cfg.myJoinToken);
    if (!cfg.myJoinToken) formData.append('csrf_token',  cfg.csrfToken);
    if (caption)          formData.append('caption', caption);

    fetch('/api/voice_notes.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(msg => { if (msg && msg.id) { addOrUpdateMessage(msg); maybeShowFileDisclaimer('voice_note'); } })
        .catch(() => {});
}

// ── Webcam helpers ──────────────────────────────────────────────
function setWebcamBadge(participantId, visible) {
    const badge = roomEl.querySelector(`[data-webcam-for="${participantId}"]`);
    if (badge) badge.style.display = visible ? 'flex' : 'none';
}

// ── Webcam ──────────────────────────────────────────────────────
async function enableWebcam() {
    try {
        webcamStream = await navigator.mediaDevices.getUserMedia({
            video: { width: 150, height: 150, facingMode: 'user', frameRate: { ideal: 60 } },
        });
        const vid = document.createElement('video');
        vid.srcObject = webcamStream;
        vid.play();
        webcamCanvas.width  = 150;
        webcamCanvas.height = 150;

        // Remember original avatar for revert
        const myPart = participants.get(cfg.myParticipantId);
        if (myPart) webcamOriginalPath = myPart.avatar_path || '';

        webcamActive = true;
        const webcamBtn = document.getElementById('ctx-webcam-enable');
        if (webcamBtn) webcamBtn.textContent = 'Disable Webcam';
        setWebcamBadge(cfg.myParticipantId, true);

        const FRAME_COUNT = 12;
        const FRAME_MS    = Math.floor(2000 / FRAME_COUNT); // ~166ms

        let _sendingFrame = false;
        const sendFrame = async () => {
            if (!webcamActive || _sendingFrame) return;
            _sendingFrame = true;
            try {
                const ctx2d = webcamCanvas.getContext('2d');
                const captureOne = () => {
                    ctx2d.save();
                    ctx2d.scale(-1, 1);
                    ctx2d.drawImage(vid, -150, 0, 150, 150);
                    ctx2d.restore();
                    return webcamCanvas.toDataURL('image/jpeg', 0.5);
                };

                const frames = [];
                for (let i = 0; i < FRAME_COUNT; i++) {
                    if (!webcamActive) break;
                    frames.push(captureOne());
                    // Update own avatar on first frame
                    if (i === 0) {
                        const me = participants.get(cfg.myParticipantId);
                        if (me) {
                            me.avatar_url = frames[0];
                            if (me.avatarEl) me.avatarEl.src = frames[0];
                            const listItem = userListEl.querySelector(`[data-participant-id="${cfg.myParticipantId}"]`);
                            if (listItem) { const img = listItem.querySelector('.participant-avatar'); if (img) img.src = frames[0]; }
                        }
                    }
                    await new Promise(r => setTimeout(r, FRAME_MS));
                }

                if (!webcamActive || frames.length === 0) { _sendingFrame = false; return; }
                fetch('/api/webcam_frame.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ session_id: cfg.sessionId, join_token: cfg.myJoinToken, frames }),
                }).catch(() => {});
            } finally {
                _sendingFrame = false;
            }
        };
        webcamInterval = setInterval(sendFrame, 2000);
    } catch (err) {
        alert('Camera access denied or not available.');
    }
}

function disableWebcam() {
    if (webcamStream) { webcamStream.getTracks().forEach(t => t.stop()); webcamStream = null; }
    clearInterval(webcamInterval); webcamInterval = null;
    webcamActive = false;
    // Cancel any pending frame-animation timeouts for our own avatar
    webcamFrameSeq.set(cfg.myParticipantId, (webcamFrameSeq.get(cfg.myParticipantId) || 0) + 1);
    setWebcamBadge(cfg.myParticipantId, false);
    const webcamBtn = document.getElementById('ctx-webcam-enable');
    if (webcamBtn) webcamBtn.textContent = 'Enable Webcam';

    // Restore original avatar for everyone — delay 600ms to let any in-flight frame complete
    if (cfg.myJoinToken) {
        const _restorePath = webcamOriginalPath;
        setTimeout(() => {
            fetch('/api/participants.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'restore_avatar', join_token: cfg.myJoinToken }),
            }).then(r => r.json()).then(res => {
                if (res && res.ok) {
                    const me = participants.get(cfg.myParticipantId);
                    if (me && _restorePath !== null) {
                        me.avatar_path = _restorePath;
                        me.avatar_url  = resolveAvatarUrl(_restorePath);
                        if (me.avatarEl) me.avatarEl.src = me.avatar_url;
                    }
                }
            }).catch(() => {});
        }, 600);
    }
    webcamOriginalPath = null;
}

function toggleWebcam() {
    ctxMenu.classList.remove('visible');
    if (webcamActive) { disableWebcam(); } else { enableWebcam(); }
}
window.toggleWebcam = toggleWebcam;

// ── File sharing ────────────────────────────────────────────────
const fileInputEl   = document.getElementById('file-input');
const filePreviewEl = document.getElementById('file-preview');

function openFilePicker() {
    if (fileInputEl) fileInputEl.click();
}
window.openFilePicker = openFilePicker;

if (fileInputEl) {
    fileInputEl.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        pendingFile = file;
        showFilePreview(file);
        fileInputEl.value = '';
    });
}

function showFilePreview(file) {
    if (!filePreviewEl) return;
    filePreviewEl.innerHTML = '';
    filePreviewEl.style.display = 'flex';
    const mime = file.type || '';

    if (mime.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'file-preview-thumb';
        img.src       = URL.createObjectURL(file);
        filePreviewEl.appendChild(img);
    } else if (mime.startsWith('video/')) {
        const vid     = document.createElement('video');
        vid.className = 'file-preview-thumb';
        vid.src       = URL.createObjectURL(file);
        vid.muted     = true;
        filePreviewEl.appendChild(vid);
    } else {
        const info = document.createElement('div');
        info.className = 'file-preview-info';
        info.innerHTML = `<span class="file-dl-icon">📄</span><span class="file-dl-name">${escHtml(file.name)}</span><span class="file-dl-size">${formatFileSize(file.size)}</span>`;
        filePreviewEl.appendChild(info);
    }

    const removeBtn = document.createElement('button');
    removeBtn.className   = 'btn btn-ghost file-preview-remove';
    removeBtn.textContent = '✕';
    removeBtn.onclick     = () => {
        pendingFile = null;
        filePreviewEl.style.display = 'none';
        filePreviewEl.innerHTML     = '';
    };
    filePreviewEl.appendChild(removeBtn);
}

function sendFile() {
    if (!pendingFile) return;
    const caption = chatInput.value.trim();
    chatInput.value = '';
    const file = pendingFile;
    pendingFile = null;
    if (filePreviewEl) { filePreviewEl.style.display = 'none'; filePreviewEl.innerHTML = ''; }

    const formData = new FormData();
    formData.append('session_id', cfg.sessionId);
    formData.append('file', file);
    if (cfg.myJoinToken)  formData.append('join_token', cfg.myJoinToken);
    if (!cfg.myJoinToken) formData.append('csrf_token', cfg.csrfToken);
    if (caption)          formData.append('caption', caption);

    fetch('/api/files.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(msg => { if (msg && msg.id) { addOrUpdateMessage(msg); maybeShowFileDisclaimer('file'); } })
        .catch(() => {});
}

// ── Emoji picker ────────────────────────────────────────────────
const EMOJI_DATA = {
    'Smileys': ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','😋','😛','😜','🤪','🤔','😐','😑','😶','😏','😒','🙄','😬','🤐','😌','😔','😪','🤤','😴','😷','🤒','🤕','🥴','😵','🤯'],
    'Gestures': ['👋','🤚','🖐','✋','🖖','👌','🤏','✌','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','💪','🦵','🦶','👁','👅'],
    'Hearts': ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','♥️','💌','💋'],
    'Nature': ['🌸','🌺','🌻','🌹','🌷','🌼','💐','🍀','🌿','🌱','🌲','🌳','🌴','🌵','🍃','🍂','🍁','🍄','🌊','🔥','⭐','🌙','☀️','⛅','🌈','❄️','⚡','🌺','🦋','🐝','🌙','🌟','💫'],
    'Objects': ['🎵','🎶','🎸','🎹','🥁','🎺','🎻','🎤','🎧','📱','💻','🖥','📷','📸','📹','🎬','🎮','📚','✏️','📝','💡','🔦','⏰','⌚','🎁','🎊','🎉','🏆','🥇','🎯'],
    'Symbols': ['✅','❌','⭕','💯','🔔','💬','💭','❓','❗','⚠️','🚫','✔️','➕','➖','♻️','🔄','▶️','⏸','⏹','⏭','🔀','🔁','🆕','🔝','🔛','🔜','🔚'],
};

function buildEmojiPicker() {
    if (emojiPickerEl) return;
    emojiPickerEl = document.createElement('div');
    emojiPickerEl.id = 'emoji-picker';

    Object.entries(EMOJI_DATA).forEach(([cat, emojis]) => {
        const hdr = document.createElement('div');
        hdr.className   = 'emoji-cat-label';
        hdr.textContent = cat;
        emojiPickerEl.appendChild(hdr);

        const grid = document.createElement('div');
        grid.className = 'emoji-grid';
        emojis.forEach(em => {
            const btn = document.createElement('button');
            btn.className   = 'emoji-item';
            btn.textContent = em;
            btn.addEventListener('click', (e) => { e.stopPropagation(); insertEmoji(em); });
            grid.appendChild(btn);
        });
        emojiPickerEl.appendChild(grid);
    });

    document.body.appendChild(emojiPickerEl);
}

function insertEmoji(emoji) {
    const pos = chatInput.selectionStart != null ? chatInput.selectionStart : chatInput.value.length;
    const val = chatInput.value;
    chatInput.value = val.slice(0, pos) + emoji + val.slice(pos);
    chatInput.selectionStart = chatInput.selectionEnd = pos + emoji.length;
    chatInput.focus();
    toggleEmojiPicker(false);
}

function toggleEmojiPicker(forceClose) {
    buildEmojiPicker();
    if (forceClose === false || emojiPickerOpen) {
        emojiPickerEl.classList.remove('visible');
        emojiPickerOpen = false;
    } else {
        const btnEl = document.getElementById('emoji-btn');
        if (btnEl) {
            const rect = btnEl.getBoundingClientRect();
            emojiPickerEl.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
            emojiPickerEl.style.right  = (window.innerWidth  - rect.right)  + 'px';
        }
        emojiPickerEl.classList.add('visible');
        emojiPickerOpen = true;
    }
}
window.toggleEmojiPicker = toggleEmojiPicker;

document.addEventListener('click', (e) => {
    if (emojiPickerEl && emojiPickerOpen &&
        !emojiPickerEl.contains(e.target) &&
        e.target.id !== 'emoji-btn') {
        toggleEmojiPicker(false);
    }
});

// ── Activities ──────────────────────────────────────────────────
const ACTIVITY_DEFS = [
    { id: 'paint',      label: 'Paint',              icon: '/assets-to-be-used/paint_icon.png',    game: 1 },
    { id: 'chess',      label: 'Chess',              icon: '/assets-to-be-used/chess_icon.png',    game: 2 },
    { id: 'checkers',   label: 'Checkers',           icon: '/assets-to-be-used/checkers_icon.png', game: 3 },
    { id: 'zen_garden', label: 'Zen Garden',         icon: null,                                    game: 4 },
];

let activeActivities = [];  // [{lobby_code, activity_type, started_by_name, started_by_id}]
let openModals = {};        // lobby_code → modal element
let minimizedActivities = {}; // lobby_code → true/false

function showActivityPicker() {
    const picker = document.getElementById('activity-picker');
    if (picker) picker.classList.toggle('visible');
}
window.showActivityPicker = showActivityPicker;

function launchActivity(activityId) {
    const picker = document.getElementById('activity-picker');
    if (picker) picker.classList.remove('visible');

    const def = ACTIVITY_DEFS.find(d => d.id === activityId);
    if (!def) return;

    fetch('/api/activities.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:          'start',
            session_id:      cfg.sessionId,
            activity_type:   activityId,
            join_token:      cfg.myJoinToken,
            participant_id:  cfg.myParticipantId,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok && data.lobby_code) {
            openActivityModal(data.lobby_code, activityId, def.label, def.game);
        }
    })
    .catch(() => {});
}
window.launchActivity = launchActivity;

async function openActivityModal(lobbyCode, activityId, label, gameNum) {
    if (openModals[lobbyCode]) {
        // Already open — bring to front
        openModals[lobbyCode].style.zIndex = ++activityModalZ;
        return;
    }

    const def = ACTIVITY_DEFS.find(d => d.id === activityId) || { game: gameNum || 1 };
    const activityPath = activityId.replace(/_/g, '-');

    // Join lobby to get player number (chess/checkers use this to assign sides)
    let playerNum = 1;
    try {
        const jr = await fetch('/activities/api/lobby.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'join', lobby_id: lobbyCode, user_id: cfg.myParticipantId }),
        });
        const jd = await jr.json();
        if (jd.player_num) playerNum = jd.player_num;
    } catch {}

    const url = `/activities/games/${activityPath}/?lobby=${encodeURIComponent(lobbyCode)}&user=${cfg.myParticipantId}&game=${def.game || gameNum || 1}&player=${playerNum}`;

    const modal = document.createElement('div');
    modal.className      = 'activity-modal';
    modal.dataset.lobby  = lobbyCode;
    modal.style.zIndex   = ++activityModalZ;

    // Default position — centered-ish
    modal.style.left = '80px';
    modal.style.top  = '80px';

    modal.innerHTML = `
      <div class="activity-modal-header" data-drag-handle>
        <span class="activity-modal-title">${escHtml(label)}</span>
        <div class="activity-modal-controls">
          <button class="activity-modal-btn" title="Minimize">−</button>
          <button class="activity-modal-btn" title="Close">✕</button>
        </div>
      </div>
      <div class="activity-modal-body">
        <iframe src="${escHtml(url)}" class="activity-iframe" allowfullscreen></iframe>
      </div>`;

    modal.querySelector('.activity-modal-btn[title="Minimize"]').addEventListener('click', () => minimizeActivity(lobbyCode));
    modal.querySelector('.activity-modal-btn[title="Close"]').addEventListener('click', () => closeActivityModal(lobbyCode));
    document.body.appendChild(modal);
    openModals[lobbyCode] = modal;
    enableModalDrag(modal);

    // Listen for close message from activity iframe
    window.addEventListener('message', (ev) => {
        if (ev.data && (ev.data.type === 'activity_close' || ev.data.type === 'activity_left') && ev.data.lobby === lobbyCode) {
            closeActivityModal(lobbyCode);
        }
    });
}
window.openActivityModal = openActivityModal;

let activityModalZ = 200;

function enableModalDrag(modal) {
    const handle = modal.querySelector('[data-drag-handle]');
    if (!handle) return;
    let mOffX = 0, mOffY = 0, mDragging = false;
    handle.addEventListener('mousedown', (e) => {
        if (e.target.tagName === 'BUTTON') return;
        mOffX = e.clientX - modal.offsetLeft;
        mOffY = e.clientY - modal.offsetTop;
        mDragging = true;
        modal.style.zIndex = ++activityModalZ;
        document.addEventListener('mousemove', onMDrag);
        document.addEventListener('mouseup', onMUp);
        e.preventDefault();
    });
    const onMDrag = (e) => {
        if (!mDragging) return;
        modal.style.left = Math.max(0, e.clientX - mOffX) + 'px';
        modal.style.top  = Math.max(0, e.clientY - mOffY) + 'px';
    };
    const onMUp = () => {
        mDragging = false;
        document.removeEventListener('mousemove', onMDrag);
        document.removeEventListener('mouseup', onMUp);
    };
}

function minimizeActivity(lobbyCode) {
    const modal = openModals[lobbyCode];
    if (!modal) return;
    modal.classList.toggle('minimized');
    const minimized = modal.classList.contains('minimized');
    minimizedActivities[lobbyCode] = minimized;
    // Update minimize button
    const btn = modal.querySelector('[title="Minimize"]');
    if (btn) btn.textContent = minimized ? '+' : '−';
    renderActivityList();
}
window.minimizeActivity = minimizeActivity;

function closeActivityModal(lobbyCode) {
    const modal = openModals[lobbyCode];
    if (modal) { modal.remove(); delete openModals[lobbyCode]; }
}
window.closeActivityModal = closeActivityModal;

function endActivity(lobbyCode) {
    fetch('/api/activities.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:         'end',
            lobby_code:     lobbyCode,
            session_id:     cfg.sessionId,
            participant_id: cfg.myParticipantId,
            join_token:     cfg.myJoinToken,
        }),
    }).catch(() => {});
    closeActivityModal(lobbyCode);
}
window.endActivity = endActivity;

function renderActivityList() {
    const listEl = document.getElementById('active-activities');
    if (!listEl) return;
    listEl.innerHTML = '';
    activeActivities.forEach(act => {
        const card = document.createElement('div');
        card.className = 'activity-card';
        const def = ACTIVITY_DEFS.find(d => d.id === act.activity_type);
        const label = def ? def.label : act.activity_type;
        const isOwn = act.started_by_id === cfg.myParticipantId;
        const isMinimized = minimizedActivities[act.lobby_code];

        const nameEl = document.createElement('div');
        nameEl.className = 'activity-card-name';
        nameEl.textContent = `${act.started_by_name}'s ${label}`;
        card.appendChild(nameEl);

        const actionsEl = document.createElement('div');
        actionsEl.className = 'activity-card-actions';

        const gameNum  = def ? def.game : 1;
        const isOpen   = !!openModals[act.lobby_code];  // modal currently open = user is in/watching

        if (isOpen) {
            // Already in the activity — show Leave only
            const leaveBtn = document.createElement('button');
            leaveBtn.className = 'btn btn-sm btn-ghost';
            leaveBtn.textContent = 'Leave';
            leaveBtn.addEventListener('click', () => closeActivityModal(act.lobby_code));
            actionsEl.appendChild(leaveBtn);
        } else {
            // Not in yet — show Join or Spectate
            const joinBtn = document.createElement('button');
            joinBtn.className = 'btn btn-sm btn-ghost';
            joinBtn.textContent = isMinimized ? 'View' : 'Join';
            joinBtn.addEventListener('click', () => openActivityModal(act.lobby_code, act.activity_type, label, gameNum));
            actionsEl.appendChild(joinBtn);

            if (!(isOwn || cfg.isPractitioner)) {
                const specBtn = document.createElement('button');
                specBtn.className = 'btn btn-sm btn-ghost';
                specBtn.textContent = 'Spectate';
                specBtn.addEventListener('click', () => openActivityModal(act.lobby_code, act.activity_type, label, gameNum));
                actionsEl.appendChild(specBtn);
            }
        }

        if (isOwn || cfg.isPractitioner) {
            const endBtn = document.createElement('button');
            endBtn.className = 'btn btn-sm btn-danger';
            endBtn.textContent = 'End';
            endBtn.addEventListener('click', () => endActivity(act.lobby_code));
            actionsEl.appendChild(endBtn);
        }

        card.appendChild(actionsEl);
        listEl.appendChild(card);
    });
}

// Process activity events from polling
function processActivityEvent(evt) {
    const p = evt.payload;
    if (evt.type === 'activity_start') {
        if (!activeActivities.find(a => a.lobby_code === p.lobby_code)) {
            activeActivities.push(p);
        }
        renderActivityList();
    } else if (evt.type === 'activity_end') {
        activeActivities = activeActivities.filter(a => a.lobby_code !== p.lobby_code);
        closeActivityModal(p.lobby_code);
        renderActivityList();
    }
}

// ── Voice Chat (WebRTC) ─────────────────────────────────────────
const voicePeers        = new Map();   // participantId → RTCPeerConnection
const voiceMembers      = new Map();   // participantId → display_name (only current voice members)
let voiceLocalStream    = null;
let inVoiceChat         = false;
let voiceMuted          = false;
let voiceSignalPoll     = null;
let lastVoiceSignalId   = 0;
const STUN_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
];

function toggleVoiceChat() {
    if (inVoiceChat) leaveVoiceChat();
    else joinVoiceChat();
}
window.toggleVoiceChat = toggleVoiceChat;

async function joinVoiceChat() {
    try {
        voiceLocalStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    } catch (err) {
        alert('Microphone access is required for voice chat.');
        return;
    }

    inVoiceChat = true;
    voiceMuted  = false;
    // Add self to voice members list
    const myName = participants.get(cfg.myParticipantId)?.display_name || 'Me';
    voiceMembers.set(cfg.myParticipantId, myName);
    updateVoiceBtn();

    // Tell server we joined voice
    await fetch('/api/voice_signal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:         'join',
            session_id:     cfg.sessionId,
            join_token:     cfg.myJoinToken,
            participant_id: cfg.myParticipantId,
        }),
    }).catch(() => {});

    // Start polling for signals
    voiceSignalPoll = setTimeout(pollVoiceSignals, 500);
    renderVoiceList();
}

async function leaveVoiceChat() {
    inVoiceChat = false;
    voiceMembers.clear();
    clearTimeout(voiceSignalPoll);

    // Close all peer connections
    voicePeers.forEach(pc => pc.close());
    voicePeers.clear();

    if (voiceLocalStream) {
        voiceLocalStream.getTracks().forEach(t => t.stop());
        voiceLocalStream = null;
    }

    // Remove all remote audio elements
    document.querySelectorAll('.voice-audio').forEach(el => el.remove());

    await fetch('/api/voice_signal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:         'leave',
            session_id:     cfg.sessionId,
            join_token:     cfg.myJoinToken,
            participant_id: cfg.myParticipantId,
        }),
    }).catch(() => {});

    updateVoiceBtn();
    renderVoiceList();
}

function updateVoiceBtn() {
    const btn = document.getElementById('voice-join-btn');
    if (!btn) return;
    btn.textContent = inVoiceChat ? '🔇 Leave Voice' : '🎤 Join Voice';
    btn.classList.toggle('btn-danger', inVoiceChat);
}

function renderVoiceList() {
    const el = document.getElementById('voice-participants');
    if (!el) return;
    el.innerHTML = '';
    // Only show participants currently in voice
    voiceMembers.forEach((name, id) => {
        const li = document.createElement('div');
        li.className = 'voice-participant voice-active';
        const icon = (id === cfg.myParticipantId && voiceMuted) ? '🔇' : '🎤';
        const nameSpan = document.createElement('span');
        nameSpan.textContent = `${icon} ${name}`;
        li.appendChild(nameSpan);
        if (id === cfg.myParticipantId) {
            const muteBtn = document.createElement('button');
            muteBtn.className = 'btn btn-xs btn-ghost';
            muteBtn.style.cssText = 'margin-left:6px;padding:1px 6px;font-size:11px;';
            muteBtn.textContent = voiceMuted ? 'Unmute' : 'Mute';
            muteBtn.addEventListener('click', toggleVoiceMute);
            li.appendChild(muteBtn);
        }
        el.appendChild(li);
    });
}

function toggleVoiceMute() {
    voiceMuted = !voiceMuted;
    if (voiceLocalStream) {
        voiceLocalStream.getAudioTracks().forEach(t => { t.enabled = !voiceMuted; });
    }
    renderVoiceList();
}
window.toggleVoiceMute = toggleVoiceMute;

async function createPeerConnection(remoteParticipantId, isInitiator) {
    if (voicePeers.has(remoteParticipantId)) return voicePeers.get(remoteParticipantId);

    const pc = new RTCPeerConnection({ iceServers: STUN_SERVERS });
    voicePeers.set(remoteParticipantId, pc);

    // Add local tracks
    if (voiceLocalStream) {
        voiceLocalStream.getTracks().forEach(track => pc.addTrack(track, voiceLocalStream));
    }

    // Handle remote audio
    pc.ontrack = (event) => {
        let audioEl = document.getElementById('voice-audio-' + remoteParticipantId);
        if (!audioEl) {
            audioEl = document.createElement('audio');
            audioEl.id        = 'voice-audio-' + remoteParticipantId;
            audioEl.className = 'voice-audio';
            audioEl.autoplay  = true;
            document.body.appendChild(audioEl);
        }
        audioEl.srcObject = event.streams[0];
        renderVoiceList();
    };

    // ICE candidates → send to signaling server
    pc.onicecandidate = (event) => {
        if (!event.candidate) return;
        fetch('/api/voice_signal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:       'signal',
                session_id:   cfg.sessionId,
                join_token:   cfg.myJoinToken,
                from_id:      cfg.myParticipantId,
                to_id:        remoteParticipantId,
                type:         'ice',
                data:         event.candidate,
            }),
        }).catch(() => {});
    };

    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
            pc.close();
            voicePeers.delete(remoteParticipantId);
            renderVoiceList();
        }
    };

    if (isInitiator) {
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await fetch('/api/voice_signal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:     'signal',
                session_id: cfg.sessionId,
                join_token: cfg.myJoinToken,
                from_id:    cfg.myParticipantId,
                to_id:      remoteParticipantId,
                type:       'offer',
                data:       offer,
            }),
        }).catch(() => {});
    }
    return pc;
}

async function pollVoiceSignals() {
    if (!inVoiceChat) return;
    try {
        const resp = await fetch(`/api/voice_signal.php?session_id=${cfg.sessionId}&participant_id=${cfg.myParticipantId}&after=${lastVoiceSignalId}&join_token=${encodeURIComponent(cfg.myJoinToken || '')}`);
        if (!resp.ok) throw new Error('voice signal poll error');
        const data = await resp.json();

        if (!inVoiceChat) return;  // left while fetch was in-flight
        if (data.voice_participants) {
            // Authoritative list from server — completely replaces local voiceMembers
            voiceMembers.clear();
            data.voice_participants.forEach(vp => voiceMembers.set(vp.id, vp.display_name));
            renderVoiceList();
        }

        if (data.signals && data.signals.length > 0) {
            for (const sig of data.signals) {
                if (sig.id > lastVoiceSignalId) lastVoiceSignalId = sig.id;
                await handleVoiceSignal(sig);
            }
        }
    } catch (err) {
        // ignore
    }
    if (inVoiceChat) voiceSignalPoll = setTimeout(pollVoiceSignals, 1000);
}

async function handleVoiceSignal(sig) {
    const fromId = sig.from_participant_id;

    if (sig.type === 'join') {
        // Remote peer joined — initiate WebRTC connection (voiceMembers updated via poll)
        if (inVoiceChat && fromId !== cfg.myParticipantId) {
            await createPeerConnection(fromId, true);
        }
        return;
    }

    if (sig.type === 'leave') {
        // Peer left — close connection (voiceMembers updated via poll)
        const pc = voicePeers.get(fromId);
        if (pc) { pc.close(); voicePeers.delete(fromId); }
        const audio = document.getElementById('voice-audio-' + fromId);
        if (audio) audio.remove();
        return;
    }

    if (!inVoiceChat) return;

    if (sig.type === 'offer') {
        const pc = await createPeerConnection(fromId, false);
        await pc.setRemoteDescription(new RTCSessionDescription(sig.data));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await fetch('/api/voice_signal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:     'signal',
                session_id: cfg.sessionId,
                join_token: cfg.myJoinToken,
                from_id:    cfg.myParticipantId,
                to_id:      fromId,
                type:       'answer',
                data:       answer,
            }),
        }).catch(() => {});
    } else if (sig.type === 'answer') {
        const pc = voicePeers.get(fromId);
        if (pc && pc.signalingState !== 'stable') {
            await pc.setRemoteDescription(new RTCSessionDescription(sig.data)).catch(() => {});
        }
    } else if (sig.type === 'ice') {
        const pc = voicePeers.get(fromId);
        if (pc) {
            await pc.addIceCandidate(new RTCIceCandidate(sig.data)).catch(() => {});
        }
    }
}

// ── Global postMessage listener for activity iframes ───────────
window.addEventListener('message', (ev) => {
    if (!ev.data || !ev.data.type) return;
    if (ev.data.type === 'activity_close' || ev.data.type === 'activity_left') {
        const lobbyCode = ev.data.lobby;
        if (lobbyCode) closeActivityModal(lobbyCode);
    }
});

// Close activity picker on outside click
document.addEventListener('click', (e) => {
    const picker = document.getElementById('activity-picker');
    if (picker && picker.classList.contains('visible')) {
        if (!e.target.closest('#activities-section')) {
            picker.classList.remove('visible');
        }
    }
});

// ── Feature flag enforcement ────────────────────────────────────
(function applyFeatureFlags() {
    const f = cfg.features || {};
    if (!f.allowWebcam) {
        const webcamBtn = document.getElementById('ctx-webcam-enable');
        if (webcamBtn) webcamBtn.style.display = 'none';
    }
    if (!f.allowVoiceChat) {
        const vjBtn = document.getElementById('voice-join-btn');
        if (vjBtn) vjBtn.closest('.sidebar-section')?.style.setProperty('display', 'none');
    }
    if (!f.allowVoiceNotes) {
        const vnBtn = document.getElementById('voice-btn');
        if (vnBtn) vnBtn.style.display = 'none';
    }
    if (!f.allowAttachments) {
        const fbBtn = document.getElementById('file-btn');
        if (fbBtn) fbBtn.style.display = 'none';
    }
})();

// ── Boot ────────────────────────────────────────────────────────
(function init() {
    initParticipants();
    renderAllMessages();

    // Init pins from initial data
    cfg.initialPins.forEach(pin => pins.set(pin.id, pin));
    renderPinPanel();

    initNotesPanel();

    // Load action items for practitioners
    if (cfg.isPractitioner) {
        loadActionItems();
    }

    // Load active activities
    fetch(`/api/activities.php?session_id=${cfg.sessionId}&participant_id=${cfg.myParticipantId}&join_token=${encodeURIComponent(cfg.myJoinToken || '')}`)
        .then(r => r.json())
        .then(data => {
            if (data.activities) {
                activeActivities = data.activities;
                renderActivityList();
            }
        }).catch(() => {});

    // Start polling
    pollTimeout = setTimeout(poll, 1500);
})();

// ── Avatar resize handler ──────────────────────────────────────
let _resizeTick;
window.addEventListener('resize', () => {
    clearTimeout(_resizeTick);
    _resizeTick = setTimeout(() => {
        // Reposition all avatars proportionally
        participants.forEach(p => {
            if (!p.avatarEl) return;
            updateAvatarPosition(p.avatarEl, p.position_x * roomW(), p.position_y * roomH(), p.id, false);
        });
        // Enforce gap for linked pairs (initiator has linked_to set)
        participants.forEach(p => {
            if (!p.linked_to || !p.avatarEl) return;
            const target = participants.get(p.linked_to);
            if (!target || !target.avatarEl) return;
            const avatarPx = p.avatarEl.offsetWidth || 150;
            const gap = 12;
            const rW = roomW(), rH = roomH();
            let initX = Math.max(avatarPx + gap, Math.min(p.position_x * rW, rW - avatarPx));
            let tgtX  = initX - avatarPx - gap;
            const snapY = Math.max(0, Math.min(p.position_y * rH, rH - avatarPx));
            updateAvatarPosition(p.avatarEl, initX, snapY, p.id, false);
            updateAvatarPosition(target.avatarEl, tgtX, snapY, target.id, false);
            p.position_x = initX / rW;  target.position_x = tgtX / rW;
            p.position_y = snapY / rH;  target.position_y = snapY / rH;
        });
        // Re-dock AI avatars relative to the (now-repositioned) practitioner
        redockAiAvatars();
    }, 80);
});

// ── Link modal (replaces confirm()) ────────────────────────────
let _linkModalCallback = null;

function showLinkModal(name1, name2, onConfirm) {
    _linkModalCallback = onConfirm;
    const modal = document.getElementById('link-modal');
    const msg   = document.getElementById('link-modal-msg');
    if (msg) msg.textContent = `Link ${name1} with ${name2}?`;
    if (modal) modal.style.display = 'flex';
}

function dismissLinkModal() {
    _linkModalCallback = null;
    const modal = document.getElementById('link-modal');
    if (modal) modal.style.display = 'none';
}

function confirmLink() {
    const cb = _linkModalCallback;
    dismissLinkModal();
    if (cb) cb();
}
window.dismissLinkModal = dismissLinkModal;
window.confirmLink = confirmLink;

// ── Message edit / delete ──────────────────────────────────────
let _pendingDeleteMsgId = null;

function startEditMessage() {
    const msgId = reactTargetMsgId;
    if (!msgId) return;
    hlToolbar.classList.remove('visible');
    const msg = messages.get(msgId);
    if (!msg) return;
    const msgEl = messagesEl.querySelector(`[data-msg-id="${msgId}"]`);
    if (!msgEl) return;

    const textSpan = msgEl.querySelector('.msg-text');
    if (!textSpan) return;

    // Replace text span with an input
    const input = document.createElement('textarea');
    input.className = 'edit-msg-input';
    input.value     = msg.content;
    input.rows      = Math.max(1, Math.ceil(msg.content.length / 60));
    textSpan.replaceWith(input);
    input.focus();
    input.select();

    const saveBtn = document.createElement('button');
    saveBtn.className   = 'btn btn-primary btn-sm edit-msg-save';
    saveBtn.textContent = 'Save';
    saveBtn.onclick     = () => saveEditMessage(msgId, input, msgEl);
    input.after(saveBtn);

    const cancelBtn = document.createElement('button');
    cancelBtn.className   = 'btn btn-ghost btn-sm edit-msg-cancel';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick     = () => addOrUpdateMessage(msg); // re-render original
    saveBtn.after(cancelBtn);

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEditMessage(msgId, input, msgEl); }
        if (e.key === 'Escape') addOrUpdateMessage(msg);
    });
}

function saveEditMessage(msgId, inputEl, msgEl) {
    const newContent = inputEl.value.trim();
    if (!newContent) return;
    apiPost('/api/messages.php', {
        action:     'edit',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        message_id: msgId,
        content:    newContent,
    }).then(res => {
        if (res && res.ok) {
            const msg = messages.get(msgId);
            if (msg) {
                msg.content   = newContent;
                msg.edited_at = new Date().toISOString();
                addOrUpdateMessage(msg);
            }
        }
    });
}
window.startEditMessage = startEditMessage;

function startDeleteMessage() {
    _pendingDeleteMsgId = reactTargetMsgId;
    if (!_pendingDeleteMsgId) return;
    hlToolbar.classList.remove('visible');
    const modal = document.getElementById('delete-modal');
    if (modal) modal.style.display = 'flex';
}

function dismissDeleteModal() {
    _pendingDeleteMsgId = null;
    const modal = document.getElementById('delete-modal');
    if (modal) modal.style.display = 'none';
}

function confirmDeleteMessage() {
    const msgId = _pendingDeleteMsgId;
    dismissDeleteModal();
    if (!msgId) return;
    apiPost('/api/messages.php', {
        action:     'delete',
        session_id: cfg.sessionId,
        join_token: cfg.myJoinToken,
        message_id: msgId,
    }).then(res => {
        if (res && res.ok) {
            const msg = messages.get(msgId);
            if (msg) {
                msg.is_deleted = 1;
                if (cfg.isPractitioner) {
                    addOrUpdateMessage(msg);
                } else {
                    const domEl = messagesEl.querySelector(`[data-msg-id="${msgId}"]`);
                    if (domEl) domEl.remove();
                    messages.delete(msgId);
                }
            }
        }
    });
}
window.dismissDeleteModal = dismissDeleteModal;
window.confirmDeleteMessage = confirmDeleteMessage;
window.startDeleteMessage = startDeleteMessage;

// ── Edit Nickname ──────────────────────────────────────────────
function editNickname() {
    ctxMenu.classList.remove('visible');
    const me = cfg.myParticipantId ? participants.get(cfg.myParticipantId) : null;
    const modal = document.getElementById('nickname-modal');
    const input = document.getElementById('nickname-input');
    if (!modal || !input) return;
    input.value = me ? me.display_name : '';
    modal.style.display = 'flex';
    setTimeout(() => { input.focus(); input.select(); }, 50);
}

function dismissNicknameModal() {
    const modal = document.getElementById('nickname-modal');
    if (modal) modal.style.display = 'none';
}

function confirmNickname() {
    const input   = document.getElementById('nickname-input');
    const newName = input ? input.value.trim() : '';
    if (!newName) return;
    dismissNicknameModal();
    apiPost('/api/participants.php', {
        action:       'rename',
        join_token:   cfg.myJoinToken,
        display_name: newName,
    }).then(res => {
        if (res && res.ok) {
            const me = cfg.myParticipantId ? participants.get(cfg.myParticipantId) : null;
            if (me) {
                const oldName = me.display_name;
                me.display_name = newName;
                messages.forEach(msg => {
                    if (msg.participant_id === cfg.myParticipantId) {
                        msg.display_name = newName;
                        addOrUpdateMessage(msg);
                    }
                });
                const label = roomEl.querySelector(`[data-label-for="${cfg.myParticipantId}"]`);
                if (label) label.textContent = newName;
                addSystemMessage(`${oldName} is now known as ${newName}.`);
                renderUserList();
            }
        }
    });
}
window.editNickname = editNickname;
window.dismissNicknameModal = dismissNicknameModal;
window.confirmNickname = confirmNickname;

// ── First-session tooltip tour (#4) ───────────────────────────

(function initTour() {
    const TOUR_KEY = 'ss_tour_v1_' + (cfg.isPractitioner ? 'pract' : 'client');
    if (localStorage.getItem(TOUR_KEY)) return;

    const practSteps = [
        {
            target: '#chat-pane',
            fallback: '#main',
            title: 'Chat Pane',
            body: 'All session messages appear here in real-time. Right-click any message to highlight, pin, tag with concepts, or react.',
        },
        {
            target: '#file-btn',
            fallback: '#input-area',
            title: 'Share Files',
            body: 'Attach images, documents, or other files to share directly in the chat. Files are served securely within the session.',
        },
        {
            target: '#voice-btn',
            fallback: '#input-area',
            title: 'Voice Notes',
            body: 'Record a voice message and send it to the chat. Great for sharing tone and nuance that text alone can\'t capture.',
        },
        {
            target: '#notes-panel',
            fallback: '.sidebar',
            title: 'Session Notes',
            body: 'Your private notes — only you can see these. Select a participant and start typing. Notes auto-save and support Markdown formatting.',
        },
        {
            target: '#action-items-section',
            fallback: '.sidebar',
            title: 'Action Items',
            body: 'Track follow-up tasks for this session. Add items, tick them off, and they\'re saved to the session record.',
        },
        {
            target: '#pin-panel-section',
            fallback: '.sidebar',
            title: 'Pinned Messages',
            body: 'Right-click any message to pin it. Pinned messages appear here for quick reference and are included in your session export.',
        },
        {
            target: '#end-session-btn',
            fallback: '.sidebar',
            title: 'End & Export',
            body: 'When the session is complete, end it here. You\'ll get a full transcript with notes, highlights, and pinned messages — ready to print or save as PDF.',
        },
    ];

    const clientSteps = [
        {
            target: '#chat-pane',
            fallback: '#main',
            title: 'Chat Pane',
            body: 'All messages appear here as they\'re sent. The room is your shared space — your practitioner can see your avatar position.',
        },
        {
            target: '#chat-input',
            fallback: '#input-area',
            title: 'Chat',
            body: 'Type your message here and press Enter to send. You can also share files, voice notes, and emojis.',
        },
        {
            target: '#file-btn',
            fallback: '#input-area',
            title: 'Share Files',
            body: 'Tap here to attach an image, document, or other file to share in the session.',
        },
        {
            target: '#voice-btn',
            fallback: '#input-area',
            title: 'Voice Notes',
            body: 'Tap here to record a short voice message — your practitioner will be able to play it back.',
        },
        {
            target: '#voice-join-btn',
            fallback: '.sidebar',
            title: 'Voice & Video',
            body: 'Join the room\'s voice channel with one click. Your camera and microphone will only activate when you choose.',
        },
        {
            target: '#room',
            fallback: '#main',
            title: 'The Room',
            body: 'This is your shared space. Your avatar is here — drag it to move around. Your practitioner can see your position.',
        },
    ];

    const steps = cfg.isPractitioner ? practSteps : clientSteps;
    let currentStep = 0;

    // Build tour overlay elements
    const overlay = document.createElement('div');
    overlay.id = 'ss-tour-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:9000;pointer-events:auto;';

    const bubble = document.createElement('div');
    bubble.id = 'ss-tour-bubble';
    bubble.style.cssText = [
        'position:fixed;z-index:9001;max-width:300px;min-width:220px;',
        'background:rgba(13,10,32,0.97);border:1px solid rgba(124,106,247,0.5);',
        'border-radius:10px;padding:16px 18px;box-shadow:0 8px 40px rgba(0,0,0,0.6);',
        'font-family:-apple-system,system-ui,sans-serif;',
        'pointer-events:auto;',
    ].join('');

    const title = document.createElement('div');
    title.style.cssText = 'font-size:14px;font-weight:700;color:#e0dff5;margin-bottom:6px;';

    const body = document.createElement('div');
    body.style.cssText = 'font-size:12px;color:#a09ec0;line-height:1.6;margin-bottom:14px;';

    const footer = document.createElement('div');
    footer.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;';

    const skipBtn = document.createElement('button');
    skipBtn.textContent = 'Skip tour';
    skipBtn.style.cssText = 'background:transparent;border:none;color:#6e6c88;font-size:11px;cursor:pointer;padding:0;';
    skipBtn.addEventListener('click', endTour);

    const nextBtn = document.createElement('button');
    nextBtn.style.cssText = [
        'background:#7c6af7;color:#fff;border:none;border-radius:5px;',
        'padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;',
    ].join('');
    nextBtn.addEventListener('click', advanceTour);

    const progress = document.createElement('div');
    progress.style.cssText = 'font-size:11px;color:#6e6c88;';

    footer.appendChild(skipBtn);
    footer.appendChild(progress);
    footer.appendChild(nextBtn);
    bubble.appendChild(title);
    bubble.appendChild(body);
    bubble.appendChild(footer);
    overlay.appendChild(bubble);
    // (overlay and spotlight are attached to the DOM in showStep on first call)

    // Spotlight element — highlight the target
    const spotlight = document.createElement('div');
    spotlight.style.cssText = [
        'position:fixed;z-index:8999;border-radius:6px;',
        'box-shadow:0 0 0 9999px rgba(0,0,0,0.82);',
        'transition:all 0.25s;pointer-events:none;',
        'border:2px solid rgba(124,106,247,0.7);',
    ].join('');
    let tourAttached = false;

    function findTarget(step) {
        const selectors = step.target.split(',').map(s => s.trim());
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el) return el;
        }
        return document.querySelector(step.fallback) || document.body;
    }

    function positionBubble(targetEl) {
        const pad = 20;
        // Always anchor to bottom-right corner of the viewport so it
        // stays visible regardless of layout or pane resizing
        bubble.style.right  = pad + 'px';
        bubble.style.bottom = pad + 'px';
        bubble.style.left   = '';
        bubble.style.top    = '';

        // Spotlight over the target element
        const rect = targetEl.getBoundingClientRect();
        spotlight.style.left   = Math.max(0, rect.left - 4) + 'px';
        spotlight.style.top    = Math.max(0, rect.top  - 4) + 'px';
        spotlight.style.width  = (rect.width  + 8) + 'px';
        spotlight.style.height = (rect.height + 8) + 'px';
    }

    function showStep(index) {
        // Attach to DOM on first call so no empty bubble appears at page load
        if (!tourAttached) {
            document.body.appendChild(overlay);
            document.body.appendChild(spotlight);
            tourAttached = true;
        }
        const step   = steps[index];
        const target = findTarget(step);
        title.textContent    = step.title;
        body.textContent     = step.body;
        progress.textContent = (index + 1) + ' / ' + steps.length;
        nextBtn.textContent  = index === steps.length - 1 ? 'Done' : 'Next →';
        positionBubble(target);
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function advanceTour() {
        currentStep++;
        if (currentStep >= steps.length) { endTour(); return; }
        showStep(currentStep);
    }

    function endTour() {
        overlay.remove();
        spotlight.remove();
        localStorage.setItem(TOUR_KEY, '1');
    }

    // Start after the welcome overlay dismisses, with a fallback for rooms without one
    const fallbackTimer = setTimeout(() => showStep(0), 12000);
    document.addEventListener('ss:welcome-dismissed', () => {
        clearTimeout(fallbackTimer);
        setTimeout(() => showStep(0), 500);
    }, { once: true });
})();

// ══════════════════════════════════════════════════════════════
// CRISIS RESOURCE PANEL
// ══════════════════════════════════════════════════════════════

function loadCrisisResources() {
    fetch('/api/crisis_resources.php?action=list')
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                crisisResources = data.resources || [];
                renderCrisisPanel();
            }
        })
        .catch(() => {});
}

function renderCrisisPanel() {
    const list = document.getElementById('crisis-panel-list');
    if (!list) return;
    if (!crisisResources.length) {
        list.innerHTML = '<p style="padding:10px 14px;color:var(--text-muted);font-size:12px;">No crisis resources configured.</p>';
        return;
    }
    list.innerHTML = '';
    crisisResources.forEach(r => {
        const btn = document.createElement('button');
        btn.className = 'crisis-resource-item';
        btn.innerHTML = `<span class="crisis-resource-item-label">${escHtml(r.label)}</span><span class="crisis-resource-item-send">Send →</span>`;
        btn.addEventListener('click', () => sendCrisisResource(r.message_body));
        list.appendChild(btn);
    });
}

function toggleCrisisPanel() {
    const panel = document.getElementById('crisis-panel');
    if (!panel) return;
    crisisPanelOpen = !crisisPanelOpen;
    panel.style.display = crisisPanelOpen ? 'block' : 'none';
    if (crisisPanelOpen && !crisisResources.length) loadCrisisResources();
}

function hideCrisisPanel() {
    crisisPanelOpen = false;
    const panel = document.getElementById('crisis-panel');
    if (panel) panel.style.display = 'none';
}

function sendCrisisResource(messageBody) {
    hideCrisisPanel();
    if (!messageBody) return;

    // Optimistic render
    const tempId  = 'tmp_' + Date.now();
    const now     = new Date().toISOString();
    const tempMsg = {
        id:              tempId,
        participant_id:  cfg.myParticipantId,
        display_name:    findMyName(),
        content:         messageBody,
        message_type:    'crisis',
        sent_at:         now,
        is_practitioner: true,
        is_pinned:       false,
        highlights:      [],
        concept_tags:    [],
    };
    addOrUpdateMessage(tempMsg);
    if (cfg.myParticipantId) showChatBubble(cfg.myParticipantId, '⚠️ Crisis resource sent');

    const body = {
        session_id:   cfg.sessionId,
        content:      messageBody,
        message_type: 'crisis',
    };
    if (cfg.myJoinToken) body.join_token = cfg.myJoinToken;

    apiPost('/api/messages.php', body).then(msg => {
        if (msg && msg.id) {
            const tempEl = messagesEl.querySelector(`[data-msg-id="${tempId}"]`);
            if (tempEl) tempEl.remove();
            messages.delete(tempId);
            addOrUpdateMessage(msg);
        }
    });
}

// Close crisis panel on outside click
document.addEventListener('click', (e) => {
    if (!crisisPanelOpen) return;
    const panel = document.getElementById('crisis-panel');
    const btn   = document.getElementById('crisis-btn');
    if (panel && !panel.contains(e.target) && btn && !btn.contains(e.target)) {
        hideCrisisPanel();
    }
});

// Load crisis resources on init if practitioner
if (cfg.isPractitioner) loadCrisisResources();


// ══════════════════════════════════════════════════════════════
// CONCEPT / THEME TAGGING
// ══════════════════════════════════════════════════════════════

function updateTagBtnLabel(btn, count) {
    btn.textContent = count > 0 ? `🏷 #${count}` : '🏷';
    btn.classList.toggle('has-tags', count > 0);
}

function buildConceptTagPills(tags, targetId, targetType) {
    const wrap = document.createElement('div');
    wrap.className         = 'concept-tag-pills';
    wrap.dataset.targetId  = targetId;
    wrap.dataset.targetType = targetType;
    (tags || []).forEach(t => {
        wrap.appendChild(makeTagPill(t, targetId, targetType));
    });
    return wrap;
}

function makeTagPill(tag, targetId, targetType) {
    const pill = document.createElement('span');
    pill.className          = 'concept-tag-pill';
    pill.dataset.tagId      = tag.id;
    pill.style.background   = hexToRgba(tag.color, 0.15);
    pill.style.color        = tag.color;
    pill.style.border       = `1px solid ${hexToRgba(tag.color, 0.35)}`;
    pill.textContent        = tag.name;
    if (cfg.isPractitioner) {
        const rm = document.createElement('span');
        rm.className   = 'pill-remove';
        rm.textContent = '×';
        rm.title       = 'Remove tag';
        rm.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleConceptTag(tag.id, targetId, targetType);
        });
        pill.appendChild(rm);
    }
    return pill;
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}

function showTagPopover(anchorEl, target) {
    closeTagPopover();

    const pop = document.createElement('div');
    pop.className = 'tag-popover';
    pop.id        = 'tag-popover';

    const title = document.createElement('div');
    title.className   = 'tag-popover-title';
    title.textContent = target.type === 'note' ? 'Tag Note' : 'Tag Message';
    pop.appendChild(title);

    // Get currently applied tags for this target
    const appliedTagIds = getAppliedTagIds(target);

    conceptTags.forEach(tag => {
        const opt = document.createElement('div');
        opt.className = 'tag-option';
        opt.innerHTML = `<span class="tag-option-dot" style="background:${tag.color}"></span><span class="tag-option-name">${escHtml(tag.name)}</span>${appliedTagIds.has(tag.id) ? '<span class="tag-option-check">✓</span>' : ''}`;
        opt.addEventListener('click', () => toggleConceptTag(tag.id, target.id, target.type, target.participantId));
        pop.appendChild(opt);
    });

    // New tag input
    const newRow = document.createElement('div');
    newRow.className = 'tag-new-row';
    const inp = document.createElement('input');
    inp.type        = 'text';
    inp.className   = 'tag-new-input';
    inp.placeholder = 'New tag…';
    inp.maxLength   = 100;
    const addBtn = document.createElement('button');
    addBtn.className   = 'tag-new-btn';
    addBtn.textContent = '+';
    addBtn.addEventListener('click', () => {
        const name = inp.value.trim();
        if (!name) return;
        createConceptTag(name, target);
        inp.value = '';
    });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }});
    newRow.appendChild(inp);
    newRow.appendChild(addBtn);
    pop.appendChild(newRow);

    document.body.appendChild(pop);
    activeTagPopover = pop;
    tagPopoverTarget = target;

    // Position near anchor
    const rect = anchorEl.getBoundingClientRect();
    const popW = 240, popH = Math.min(400, pop.scrollHeight);
    let top  = rect.bottom + 6;
    let left = rect.left;
    if (top + popH > window.innerHeight - 10) top = rect.top - popH - 6;
    if (left + popW > window.innerWidth  - 10) left = window.innerWidth - popW - 10;
    pop.style.top  = top  + 'px';
    pop.style.left = left + 'px';

    setTimeout(() => inp.focus(), 50);
}

function closeTagPopover() {
    if (activeTagPopover) { activeTagPopover.remove(); activeTagPopover = null; tagPopoverTarget = null; }
}

document.addEventListener('click', (e) => {
    if (activeTagPopover && !activeTagPopover.contains(e.target)) closeTagPopover();
});

function getAppliedTagIds(target) {
    const ids = new Set();
    if (target.type === 'message') {
        const el   = messagesEl.querySelector(`[data-msg-id="${target.id}"] .concept-tag-pills`);
        if (el) el.querySelectorAll('.concept-tag-pill').forEach(p => ids.add(parseInt(p.dataset.tagId)));
    } else if (target.type === 'note') {
        const strip = document.getElementById('notes-tag-strip');
        if (strip) strip.querySelectorAll('.concept-tag-pill').forEach(p => ids.add(parseInt(p.dataset.tagId)));
    }
    return ids;
}

function toggleConceptTag(tagId, targetId, targetType, participantId) {
    const body = {
        csrf_token: cfg.csrfToken,
        tag_id:     tagId,
        session_id: cfg.sessionId,
    };
    let endpoint;
    if (targetType === 'message') {
        endpoint          = '/api/concept_tags.php?action=tag_message';
        body.message_id   = targetId;
    } else {
        endpoint           = '/api/concept_tags.php?action=tag_note';
        body.participant_id = participantId || selectedNoteParticipantId || 0;
    }

    apiPost(endpoint, body).then(data => {
        if (!data.ok) return;
        if (targetType === 'message') {
            // Update the tag pills for this message in the DOM and in messages map
            const msgEl = messagesEl.querySelector(`[data-msg-id="${targetId}"]`);
            if (msgEl) {
                const oldPills = msgEl.querySelector('.concept-tag-pills');
                const newPills = buildConceptTagPills(data.tags, targetId, 'message');
                if (oldPills) oldPills.replaceWith(newPills); else msgEl.appendChild(newPills);
                // Update tag button count
                const tBtn = msgEl.querySelector('.msg-tag-btn');
                if (tBtn) updateTagBtnLabel(tBtn, data.tags.length);
            }
            // Update messages map
            const m = messages.get(targetId);
            if (m) { m.concept_tags = data.tags; messages.set(targetId, m); }
        } else {
            renderNoteTagStrip(data.tags, participantId || selectedNoteParticipantId);
        }
        // Refresh popover if open
        if (activeTagPopover && tagPopoverTarget) {
            const anchor = activeTagPopover.previousElementSibling || document.getElementById('notes-tag-add-btn');
            const prev = tagPopoverTarget;
            closeTagPopover();
            if (anchor) showTagPopover(anchor, prev);
        }
    }).catch(() => {});
}

function createConceptTag(name, target) {
    const body = { csrf_token: cfg.csrfToken, name: name, color: randomTagColor() };
    apiPost('/api/concept_tags.php?action=save_tag', body).then(data => {
        if (!data.ok) return;
        const newTag = { id: data.id, name: name, color: body.color };
        conceptTags.push(newTag);
        conceptTags.sort((a, b) => a.name.localeCompare(b.name));
        // Apply immediately
        toggleConceptTag(data.id, target.id, target.type, target.participantId);
    }).catch(() => {});
}

function randomTagColor() {
    const palette = ['#7c6af7','#4a9eff','#3ecf8e','#f5c842','#e07a30','#e85555','#3dcfcf','#e55fa3'];
    return palette[Math.floor(Math.random() * palette.length)];
}

// Note tag strip
function renderNoteTagStrip(tags, participantId) {
    const strip = document.getElementById('notes-tag-strip');
    if (!strip) return;
    strip.innerHTML = '';
    (tags || []).forEach(t => {
        strip.appendChild(makeTagPill(t, cfg.sessionId, 'note'));
    });
}

function showNoteTagPopover(anchorEl) {
    const pid = selectedNoteParticipantId;
    showTagPopover(anchorEl, { type: 'note', id: cfg.sessionId, participantId: pid });
}

// When a note participant is selected, show the tag UI and load existing tags
const notesParticipantSelect = document.getElementById('notes-participant-select');
if (notesParticipantSelect && cfg.isPractitioner) {
    notesParticipantSelect.addEventListener('change', function() {
        const tagStrip  = document.getElementById('notes-tag-strip');
        const tagAddBtn = document.getElementById('notes-tag-add-btn');
        if (!this.value) {
            if (tagStrip)  tagStrip.style.display  = 'none';
            if (tagAddBtn) tagAddBtn.style.display = 'none';
            return;
        }
        const pid = parseInt(this.value);
        if (tagStrip)  tagStrip.style.display  = 'flex';
        if (tagAddBtn) tagAddBtn.style.display = 'inline-flex';
        // Load from initial config
        const initialTags = (cfg.noteConceptTags || {})[pid] || [];
        renderNoteTagStrip(initialTags, pid);
    });
}

// ── AI Integration ─────────────────────────────────────────────────
// Adds a 50px docked avatar for the AI assistant.
// Overlaps the bottom corner of the practitioner avatar — bottom-right by default,
// bottom-left when the practitioner is a link target (to avoid covering the link line).
function addAiAvatarToRoom(p) {
    const aiEl = document.createElement('img');
    aiEl.src = resolveAvatarUrl(p.avatar_url || p.avatar_path || '');
    aiEl.className = 'avatar ai-avatar-docked';
    aiEl.style.width  = '72px';
    aiEl.style.height = '72px';
    aiEl.style.borderRadius = '50%';
    aiEl.style.border = '2px solid rgba(124,106,247,0.6)';
    aiEl.style.boxShadow = '0 0 8px rgba(124,106,247,0.4)';
    aiEl.style.position = 'absolute';
    aiEl.style.zIndex = '5';
    aiEl.style.pointerEvents = 'none'; // AI doesn't drag
    aiEl.style.transition = 'left 0.3s ease, top 0.3s ease';
    aiEl.dataset.participantId = p.id;
    aiEl.dataset.isAi = '1';

    // Name label — matches regular avatar label: role icon + display name
    const label = document.createElement('div');
    label.className = 'avatar-label';
    label.dataset.labelFor = p.id;
    label.style.display = 'flex';
    label.style.alignItems = 'center';
    label.style.opacity = '0';
    const aiRoleIcon = makeRoleIconEl(p.id);
    if (aiRoleIcon) label.appendChild(aiRoleIcon);
    label.appendChild(document.createTextNode(p.display_name));
    roomEl.appendChild(label);

    // Typing bubble
    const bubble = document.createElement('div');
    bubble.className = 'typing-bubble';
    bubble.dataset.typingFor = p.id;
    bubble.style.display = 'none';
    bubble.innerHTML = '<span></span><span></span><span></span>';
    roomEl.appendChild(bubble);

    roomEl.appendChild(aiEl);

    // Position it relative to host practitioner avatar
    dockAiAvatar(p.id, aiEl);

    // Keep label hoverable
    aiEl.style.pointerEvents = 'auto';
    aiEl.addEventListener('mouseenter', () => label.style.opacity = '1');
    aiEl.addEventListener('mouseleave', () => label.style.opacity = '0');

    return aiEl;
}

// Position AI avatar at corner of practitioner avatar
function dockAiAvatar(aiParticipantId, aiEl) {
    if (!cfg.hostParticipantId) return;
    const host = participants.get(cfg.hostParticipantId);
    if (!host) return;

    const rW = roomW();
    const rH = roomH();
    const hostX = (host.position_x || 0) * rW;
    const hostY = (host.position_y || 0) * rH;
    const hostPx = 150; // host avatar size (matches .avatar { width:150px; height:150px } in main.css)
    const aiPx   = 72;  // AI avatar size
    const overlap = 38; // px the AI tucks into the practitioner corner (larger = further up/left)

    // Is practitioner a link target? If so, dock bottom-left; else bottom-right
    let isLinkTarget = false;
    for (const [, prt] of participants) {
        if (prt.linked_to === cfg.hostParticipantId) { isLinkTarget = true; break; }
    }

    let aiX, aiY;
    if (isLinkTarget) {
        // Bottom-left: same inset as right side, mirrored
        aiX = hostX - aiPx + overlap;
    } else {
        // Bottom-right: AI tucked into the bottom-right corner of the practitioner
        aiX = hostX + hostPx - overlap;
    }
    aiY = hostY + hostPx - overlap;

    // ── Off-screen guard: if AI would clip the room edge, nudge the practitioner ──
    const margin = 6;
    let nudgeX = 0, nudgeY = 0;
    if (aiX < margin)              nudgeX = margin - aiX;
    else if (aiX + aiPx > rW - margin) nudgeX = (rW - margin - aiPx) - aiX;
    if (aiY < margin)              nudgeY = margin - aiY;
    else if (aiY + aiPx > rH - margin) nudgeY = (rH - margin - aiPx) - aiY;

    if (nudgeX !== 0 || nudgeY !== 0) {
        aiX += nudgeX;
        aiY += nudgeY;
        // Nudge practitioner avatar to maintain spatial relationship
        if (host.avatarEl) {
            const nx = Math.max(0, Math.min(rW - hostPx, hostX + nudgeX));
            const ny = Math.max(0, Math.min(rH - hostPx, hostY + nudgeY));
            updateAvatarPosition(host.avatarEl, nx, ny, cfg.hostParticipantId, false);
        }
    }

    aiEl.style.left = aiX + 'px';
    aiEl.style.top  = aiY + 'px';

    // Update typing bubble position too
    const bubble = roomEl.querySelector(`.typing-bubble[data-typing-for="${aiParticipantId}"]`);
    if (bubble) {
        bubble.style.left = (aiX + aiPx + 2) + 'px';
        bubble.style.top  = (aiY - 8) + 'px';
    }
    const lbl = roomEl.querySelector(`.avatar-label[data-label-for="${aiParticipantId}"]`);
    if (lbl) {
        lbl.style.left = (aiX + aiPx + 4) + 'px';
        lbl.style.top  = (aiY + aiPx - 10) + 'px';
    }
}

// Re-dock all AI avatars when practitioner moves
function redockAiAvatars() {
    participants.forEach((p) => {
        if (!p.is_ai || !p.avatarEl) return;
        dockAiAvatar(p.id, p.avatarEl);
    });
}

// Hook into position updates to redock AI
const _origUpdateAvatarPosition = updateAvatarPosition;
// We patch the position update to redock AI avatars after any move
(function() {
    const orig = window.updateAvatarPosition || updateAvatarPosition;
    const afterMove = () => redockAiAvatars();
    const el = roomEl;
    if (el) {
        // We use a MutationObserver on avatar positions as a proxy for "move happened"
        // Actually simpler: hook the avatar position setter directly is complex.
        // Instead, after each position event in processEvent we call redockAiAvatars().
    }
})();

// AI trigger detection: if practitioner sends a message starting with assistant name
function aiCheckTrigger(text) {
    if (!cfg.aiConfig || !cfg.aiConfig.enabled || !cfg.aiConfig.scopes.in_session) return;
    if (!cfg.isPractitioner) return;
    const name = (cfg.aiConfig.assistantName || '').toLowerCase().trim();
    if (!name) return;
    const lower = text.toLowerCase().trim();
    if (!lower.startsWith(name)) return;

    // Trigger AI response
    aiInSessionRespond(text);
}

let aiResponding = false;
async function aiInSessionRespond(triggerMessage) {
    if (aiResponding) return;
    aiResponding = true;

    try {
        await fetch('/api/ai.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action:     'in_session_respond',
                session_id: cfg.sessionId,
                message:    triggerMessage,
                csrf_token: cfg.csrfToken,
            }),
        });
    } catch(e) {
        // Silent fail — poll will deliver any response that made it through
    } finally {
        aiResponding = false;
    }
}

// ══════════════════════════════════════════════════════════════
// MEDIA RECOMMENDATIONS
// ══════════════════════════════════════════════════════════════

let _mrmType       = 'books';  // 'books' | 'video'
let _mrmResults    = [];
let _mrmSelected   = null;

/**
 * Build the recommendation widget DOM element from a JSON string (the message content).
 */
function buildMediaRecWidget(contentStr) {
    const wrap = document.createElement('div');
    wrap.className = 'media-rec-widget';
    let data;
    try { data = JSON.parse(contentStr); } catch(e) { data = null; }
    if (!data) {
        wrap.textContent = '[Media recommendation]';
        return wrap;
    }

    const typeLabel = data.type === 'book' ? '📚 Book Recommendation'
                    : data.type === 'movie' ? '🎬 Film Recommendation'
                    : data.type === 'tv'    ? '📺 Series Recommendation'
                    : '📖 Media Recommendation';

    const coverPh = data.type === 'book' ? '📚' : data.type === 'movie' ? '🎬' : '📺';
    const authorLabel = (data.type === 'book') ? data.author : data.author;

    const metaParts = [];
    if (data.year)   metaParts.push(escHtml(data.year));
    if (data.genre)  metaParts.push(escHtml(data.genre));
    if (data.rating) metaParts.push('★ ' + escHtml(data.rating));
    const metaHtml = metaParts.map((p, i) =>
        (i > 0 ? '<span class="mrw-meta-sep">·</span>' : '') + `<span>${p}</span>`
    ).join('');

    const descHtml = data.description
        ? `<div class="mrw-desc">${escHtml(data.description)}</div>` : '';

    const noteHtml = data.note
        ? `<div class="mrw-note">"${escHtml(data.note)}"</div>` : '';

    let amazonHtml = '';
    if (cfg.mediaConfig && cfg.mediaConfig.amazon_enabled && cfg.mediaConfig.amazon_tag && data.type === 'book') {
        const q   = encodeURIComponent((data.title || '') + ' ' + (data.author || ''));
        const url = `https://www.amazon.com/s?k=${q}&tag=${encodeURIComponent(cfg.mediaConfig.amazon_tag)}`;
        amazonHtml = `<a class="mrw-amazon" href="${url}" target="_blank" rel="noopener sponsored">Buy on Amazon ↗</a>`;
    }

    // Build the "more info" URL
    let infoUrl = '';
    if (data.source === 'tmdb' && data.tmdb_id) {
        const tmdbType = data.tmdb_type === 'tv' ? 'tv' : 'movie';
        infoUrl = `https://www.themoviedb.org/${tmdbType}/${data.tmdb_id}`;
    } else if (data.source === 'openlibrary') {
        const q = encodeURIComponent([data.title, data.author].filter(Boolean).join(' '));
        infoUrl = `https://openlibrary.org/search?q=${q}`;
    } else if (data.source === 'google_books') {
        const q = encodeURIComponent([data.title, data.author].filter(Boolean).join(' '));
        infoUrl = `https://books.google.com/books?q=${q}`;
    } else {
        const q = encodeURIComponent([data.title, data.author].filter(Boolean).join(' '));
        infoUrl = `https://www.google.com/search?q=${q}`;
    }

    wrap.innerHTML = `
      <div class="mrw-inner">
        <div class="mrw-cover">
          <span class="mrw-cover-ph">${coverPh}</span>
          ${data.cover_url ? `<img src="${escHtml(data.cover_url)}" alt="" data-hide-on-error>` : ''}
        </div>
        <div class="mrw-content">
          <div class="mrw-badge">${typeLabel}</div>
          <div class="mrw-title">${escHtml(data.title || 'Unknown')}</div>
          ${authorLabel ? `<div class="mrw-author">${escHtml(authorLabel)}</div>` : ''}
          ${metaParts.length ? `<div class="mrw-meta">${metaHtml}</div>` : ''}
          ${descHtml}
          ${noteHtml}
          ${amazonHtml}
          ${infoUrl ? `<a class="mrw-more-link" href="${escHtml(infoUrl)}" target="_blank" rel="noopener noreferrer">More info ↗</a>` : ''}
        </div>
      </div>`;
    return wrap;
}

function buildQuoteWidget(contentStr) {
    const wrap = document.createElement('div');
    wrap.className = 'quote-card-widget';
    let data;
    try { data = JSON.parse(contentStr); } catch(e) { data = null; }
    if (!data) { wrap.textContent = '[Quote]'; return wrap; }
    const attrParts = [data.author, data.year].filter(Boolean);
    let attrib = attrParts.join(', ');
    if (data.source) attrib += (attrib ? ' — ' : '') + data.source;
    wrap.innerHTML = `
      <div class="qcw-label">✶ Shared Quote</div>
      <div class="qcw-body">“${escHtml(data.body || data.title || '')}”</div>
      ${attrib ? `<div class="qcw-attrib">— ${escHtml(attrib)}</div>` : ''}
      ${data.category ? `<div class="qcw-cat">${escHtml(data.category)}</div>` : ''}`;
    return wrap;
}

function openMediaRecModal() {
    const modal = document.getElementById('media-rec-modal');
    if (!modal) return;
    modal.style.display = '';
    _mrmResults  = [];
    _mrmSelected = null;
    document.getElementById('mrm-query').value = '';
    document.getElementById('mrm-note').value  = '';
    mrmShowResults();
    mrmUpdateTabs();
    document.getElementById('mrm-query').focus();
}

function closeMediaRecModal() {
    const modal = document.getElementById('media-rec-modal');
    if (modal) modal.style.display = 'none';
}

function mrmSetType(type, btn) {
    _mrmType = type;
    _mrmResults = [];
    mrmUpdateTabs();

    if (type === 'refs') {
        // Reuse the search input as a filter over saved references
        const queryEl = document.getElementById('mrm-query');
        const searchBtn = document.getElementById('mrm-search-btn');
        if (queryEl) queryEl.placeholder = 'Filter your references…';
        if (searchBtn) searchBtn.style.display = 'none';
        mrmShowReferences();
    } else {
        const queryEl = document.getElementById('mrm-query');
        const searchBtn = document.getElementById('mrm-search-btn');
        if (queryEl) queryEl.placeholder = 'Search…';
        if (searchBtn) searchBtn.style.display = '';
        mrmShowResults();
    }
}

function mrmUpdateTabs() {
    const booksTab = document.getElementById('mrm-tab-books');
    const videoTab = document.getElementById('mrm-tab-video');
    const refsTab  = document.getElementById('mrm-tab-refs');
    if (!booksTab || !videoTab) return;

    const activeStyle = 'padding:8px 18px;border:none;border-bottom:2px solid #7c6af7;background:transparent;color:#7c6af7;font-size:13px;font-weight:600;cursor:pointer;';
    const inactStyle  = 'padding:8px 18px;border:none;border-bottom:2px solid transparent;background:transparent;color:rgba(221,220,242,0.45);font-size:13px;font-weight:600;cursor:pointer;';
    booksTab.style.cssText = (_mrmType === 'books') ? activeStyle : inactStyle;
    videoTab.style.cssText = (_mrmType === 'video') ? activeStyle : inactStyle;
    if (refsTab)  refsTab.style.cssText  = (_mrmType === 'refs')  ? activeStyle : inactStyle;

    // Disable video tab if no TMDB configured
    const mc = cfg.mediaConfig;
    if (videoTab && mc && !mc.tmdb_enabled) {
        videoTab.style.opacity = '0.35';
        videoTab.title = 'Enable TMDB in Profile → Media Recommendations to search movies & TV';
    }
}

function mrmShowResults() {
    const body   = document.getElementById('mrm-body');
    const footer = document.getElementById('mrm-detail-footer');
    if (!body || !footer) return;
    footer.style.display = 'none';
    _mrmSelected = null;

    if (!_mrmResults.length) {
        body.innerHTML = `<div style="padding:40px 20px;text-align:center;color:rgba(221,220,242,0.3);font-size:13px;">
            ${_mrmType === 'books' ? 'Search for a book to recommend.' : 'Search for a film or series to recommend.'}
        </div>`;
        return;
    }

    body.innerHTML = _mrmResults.map((r, i) => {
        const coverHtml = r.cover_url
            ? `<img src="${escHtml(r.cover_url)}" alt="" data-hide-on-error>`
            : (r.type === 'book' ? '📚' : r.type === 'movie' ? '🎬' : '📺');
        const authorOrStudio = r.author || '';
        const chips = [r.year, r.genre, r.rating ? '★ ' + r.rating : null].filter(Boolean);
        // Star button — saves to /api/references.php?action=star_external.
        // Stops propagation so clicking the star doesn't also open the row's
        // detail view.
        const starBtn = `<button type="button" class="mrm-star-btn" data-mrm-star="${i}"
            title="Star to save in your References"
            style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.45);border:none;border-radius:50%;width:28px;height:28px;color:rgba(255,255,255,0.55);cursor:pointer;font-size:16px;line-height:1;display:flex;align-items:center;justify-content:center;transition:color .15s,background .15s;">☆</button>`;
        return `<div class="mrm-result-row" data-mrm-idx="${i}" style="position:relative;">
          <div class="mrm-result-cover">${coverHtml}</div>
          <div class="mrm-result-info">
            <div class="mrm-result-title">${escHtml(r.title || '')}</div>
            ${authorOrStudio ? `<div class="mrm-result-author">${escHtml(authorOrStudio)}</div>` : ''}
            ${chips.length ? `<div class="mrm-result-chips">${chips.map(c => `<span>${escHtml(c)}</span>`).join('')}</div>` : ''}
          </div>
          ${starBtn}
        </div>`;
    }).join('');
}

async function mrmStarResult(idx) {
    const r = _mrmResults[idx];
    if (!r) return false;

    // Map media_search result shape → references payload
    const kindMap = {
        'tmdb_movie':  'tmdb',
        'tmdb_tv':     'tmdb',
        'openlibrary': 'openlibrary',
        'google_books':'google_books',
        'archive_org': 'archive_org',
    };
    const kind = kindMap[r.source] || r.source || (r.type === 'book' ? 'openlibrary' : 'tmdb');
    const refType = r.type === 'book' ? 'book' : (r.type === 'movie' ? 'film' : (r.type === 'tv' ? 'tv' : 'book'));

    try {
        const res = await fetch('/api/references.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:        'star_external',
                csrf_token:    cfg.csrfToken,
                external_kind: kind,
                external_id:   String(r.id || r.external_id || (r.title + '|' + (r.year || ''))),
                ref_type:      refType,
                title:         r.title || '',
                author:        r.author || '',
                year:          r.year ? String(r.year) : '',
                source:        r.publisher || r.studio || r.source_label || '',
                source_url:    r.url || '',
                cover_url:     r.cover_url || '',
                body:          r.synopsis || r.overview || '',
            }),
        });
        const data = await res.json();
        return !!(data && data.ok);
    } catch(e) { return false; }
}

async function mrmSearch() {
    const query = document.getElementById('mrm-query').value.trim();

    // References tab — filter the locally cached list, no network call
    if (_mrmType === 'refs') {
        _refsLastQuery = query;
        renderReferencesInModal(_refsCache || [], query);
        return;
    }

    if (!query) return;

    const body   = document.getElementById('mrm-body');
    const footer = document.getElementById('mrm-detail-footer');
    body.innerHTML = `<div style="padding:32px 20px;text-align:center;color:rgba(221,220,242,0.35);font-size:13px;">Searching…</div>`;
    if (footer) footer.style.display = 'none';

    const action = _mrmType === 'books' ? 'search_books' : 'search_movies';
    try {
        const res  = await fetch('/api/media_search.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action, query, csrf_token: cfg.csrfToken }),
        });
        const data = await res.json();
        if (!data.ok) {
            body.innerHTML = `<div style="padding:32px 20px;text-align:center;color:#e85555;font-size:13px;">${escHtml(data.error || 'Search failed.')}</div>`;
            return;
        }
        _mrmResults = data.results || [];
        mrmShowResults();
    } catch(e) {
        body.innerHTML = `<div style="padding:32px 20px;text-align:center;color:#e85555;font-size:13px;">Request failed.</div>`;
    }
}

async function mrmSelectResult(idx) {
    _mrmSelected = _mrmResults[idx];
    if (!_mrmSelected) return;

    const body   = document.getElementById('mrm-body');
    const footer = document.getElementById('mrm-detail-footer');
    body.innerHTML = `<div style="padding:32px 20px;text-align:center;color:rgba(221,220,242,0.35);font-size:13px;">Loading details…</div>`;

    // Fetch full detail (for description, genre, etc.)
    try {
        const res  = await fetch('/api/media_search.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action:    'detail',
                source:    _mrmSelected.source,
                id:        _mrmSelected.id,
                tmdb_type: _mrmSelected.tmdb_type || 'movie',
                csrf_token: cfg.csrfToken,
            }),
        });
        const data = await res.json();
        if (data.ok && data.item) {
            // Merge detail into selected (preserve cover_url from list result if detail has none)
            _mrmSelected = Object.assign({}, _mrmSelected, data.item);
            if (!_mrmSelected.cover_url && _mrmResults[idx].cover_url) {
                _mrmSelected.cover_url = _mrmResults[idx].cover_url;
            }
            if (!_mrmSelected.cover_large && _mrmResults[idx].cover_large) {
                _mrmSelected.cover_large = _mrmResults[idx].cover_large;
            }
        }
    } catch(e) { /* use list data */ }

    const r       = _mrmSelected;
    const typeLabel = r.type === 'book' ? '📚 Book' : r.type === 'movie' ? '🎬 Film' : '📺 Series';
    const coverPh   = r.type === 'book' ? '📚' : r.type === 'movie' ? '🎬' : '📺';
    const coverSrc  = r.cover_large || r.cover_url;
    const chips     = [r.year, r.genre, r.rating ? '★ ' + r.rating : null, typeLabel].filter(Boolean);

    body.innerHTML = `<div class="mrm-detail-inner">
      <div class="mrm-detail-top">
        <div class="mrm-detail-cover">
          ${coverSrc ? `<img src="${escHtml(coverSrc)}" alt="" data-hide-on-error>` : coverPh}
        </div>
        <div class="mrm-detail-info">
          <div class="mrm-detail-title">${escHtml(r.title || '')}</div>
          ${r.author ? `<div class="mrm-detail-author">${escHtml(r.author)}</div>` : ''}
          <div class="mrm-detail-chips">${chips.map(c => `<span class="mrm-detail-chip">${escHtml(c)}</span>`).join('')}</div>
        </div>
      </div>
      ${r.description ? `<div class="mrm-detail-desc">${escHtml(r.description)}</div>` : ''}
    </div>`;

    if (footer) footer.style.display = '';
}

async function mrmShare() {
    if (!_mrmSelected) return;
    const note = (document.getElementById('mrm-note')?.value || '').trim();
    const recData = Object.assign({}, _mrmSelected);
    if (note) recData.note = note;

    // tmdb_id is kept so the shared card can link directly to the TMDB page

    try {
        const res = await fetch('/api/messages.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action:       'send',
                session_id:   cfg.sessionId,
                content:      JSON.stringify(recData),
                message_type: 'media_rec',
                csrf_token:   cfg.csrfToken,
            }),
        });
        const data = await res.json();
        if (data.ok || data.id) {
            closeMediaRecModal();
        }
    } catch(e) {}
}

// Close media modal on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('media-rec-modal');
        if (modal && modal.style.display !== 'none') closeMediaRecModal();
    }
});

// ── CSP: delegated listeners replacing inline handlers ────────────────────

// onerror → hide image (capture phase)
document.addEventListener('error', e => {
    if (e.target.dataset && e.target.dataset.hideOnError !== undefined) e.target.style.display = 'none';
}, true);

// Media rec result row click — and star click (delegated, star takes priority)
document.getElementById('mrm-body')?.addEventListener('click', async e => {
    const starBtn = e.target.closest('[data-mrm-star]');
    if (starBtn) {
        e.stopPropagation();
        if (starBtn.classList.contains('starred')) return;
        starBtn.disabled = true;
        const ok = await mrmStarResult(parseInt(starBtn.dataset.mrmStar, 10));
        starBtn.disabled = false;
        if (ok) {
            starBtn.textContent = '★';
            starBtn.style.color = 'var(--amber, #f5c842)';
            starBtn.classList.add('starred');
            starBtn.title = 'Saved to References';
        }
        return;
    }
    const row = e.target.closest('[data-mrm-idx]');
    if (row) mrmSelectResult(parseInt(row.dataset.mrmIdx));
});

// ══════════════════════════════════════════════════════════════
// Share UI — References tab
// Lists the practitioner's saved references inside the Share modal
// (alongside Books and Movies & TV). Searchable; click to drop into
// the chat input with full attribution.
// ══════════════════════════════════════════════════════════════
let _refsCache = null;
let _refsLastQuery = '';

function escRef(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function mrmShowReferences() {
    const body  = document.getElementById('mrm-body');
    const footer= document.getElementById('mrm-detail-footer');
    if (footer) footer.style.display = 'none';
    if (!body) return;

    if (!_refsCache) {
        body.innerHTML = '<div style="padding:32px 20px;text-align:center;color:rgba(221,220,242,0.35);font-size:13px;">Loading your references…</div>';
        try {
            const res = await fetch('/api/references.php?action=list');
            const data = await res.json();
            _refsCache = data.ok ? (data.references || []) : [];
        } catch(e) { _refsCache = []; }
    }
    renderReferencesInModal(_refsCache, _refsLastQuery);
}

function renderReferencesInModal(refs, query) {
    const body = document.getElementById('mrm-body');
    if (!body) return;

    let filtered = refs;
    if (query) {
        const q = query.toLowerCase();
        filtered = refs.filter(r => {
            const hay = ((r.body || '') + ' ' + (r.title || '') + ' ' + (r.author || '') + ' ' + (r.source || '') + ' ' + (r.category || '') + ' ' + ((r.tags || []).join(' '))).toLowerCase();
            return hay.includes(q);
        });
    }

    if (!refs.length) {
        body.innerHTML = `<div style="padding:32px 20px;text-align:center;color:rgba(221,220,242,0.4);font-size:13px;line-height:1.7;">
            No references yet.<br>
            <a href="/profile.php#references" target="_blank" style="color:#a78bfa;text-decoration:underline;">Open your Reference Library</a> to star quotes from the built-in collection or add your own.
        </div>`;
        return;
    }
    if (!filtered.length) {
        body.innerHTML = '<div style="padding:32px 20px;text-align:center;color:rgba(221,220,242,0.4);font-size:13px;">No matches.</div>';
        return;
    }

    body.innerHTML = filtered.map(r => {
        const preview = (r.body || r.title || '').slice(0, 200);
        const attrib  = [r.author, r.year].filter(Boolean).join(', ');
        const cat     = r.category || '';
        return `<div class="mrm-result-row" data-ref-send="${r.id}" style="cursor:pointer;">
            <div class="mrm-result-info" style="padding:14px 18px;">
                <div style="font-size:13px;color:#dddcf2;line-height:1.55;">${escRef(preview)}${(r.body || r.title || '').length > 200 ? '…' : ''}</div>
                ${attrib || r.source ? `<div style="font-size:11.5px;color:rgba(221,220,242,0.55);font-style:italic;margin-top:6px;">— ${escRef(attrib)}${r.source ? ' · ' + escRef(r.source) : ''}</div>` : ''}
                ${cat ? `<div style="display:inline-block;font-size:10px;font-weight:700;letter-spacing:0.4px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:rgba(124,106,247,0.15);color:#c5b8ff;border:1px solid rgba(124,106,247,0.25);margin-top:8px;">${escRef(cat)}</div>` : ''}
            </div>
        </div>`;
    }).join('');

    body.querySelectorAll('[data-ref-send]').forEach(row => {
        row.addEventListener('click', () => {
            const r = (_refsCache || []).find(x => String(x.id) === row.dataset.refSend);
            if (r) sendQuoteCard(r);
        });
    });
}

async function sendQuoteCard(r) {
    try {
        const res = await fetch('/api/messages.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action:       'send',
                session_id:   cfg.sessionId,
                content:      JSON.stringify(r),
                message_type: 'quote_card',
                csrf_token:   cfg.csrfToken,
            }),
        });
        const data = await res.json();
        if (data.ok || data.id) closeMediaRecModal();
    } catch(e) {}
}
