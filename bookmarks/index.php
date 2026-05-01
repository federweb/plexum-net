<?php require_once __DIR__ . '/../auth_gate.php'; ?>
<!DOCTYPE html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookmarks Nodepulse</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .search-container { text-align: center; margin: 20px 0; }

        #search-input {
            padding: 12px 20px;
            font-size: 16px;
            border: 2px solid #3498db;
            border-radius: 25px;
            width: 300px;
            max-width: 80%;
            outline: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #search-input:focus {
            border-color: #2980b9;
            box-shadow: 0 4px 8px rgba(52,152,219,0.2);
            transform: translateY(-1px);
        }

        #clear-search {
            position: absolute; right: 15px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            font-size: 18px; color: #999; cursor: pointer; display: none;
        }

        .search-box { position: relative; display: inline-block; }

        .bookmarks-container {
            display: flex; flex-wrap: wrap; gap: 20px; justify-content: flex-start;
        }

        .bookmark {
            height: 100px; width: calc(20% - 20px);
            background-color: white; border-radius: 8px; overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative; display: block; cursor: move;
            transition: transform 0.2s ease;
        }
        .bookmark.hidden { display: none; }
        .bookmark.dragging { opacity: 0.5; transform: scale(1.05); }

        .bookmark img {
            max-height: 30px; width: 100%; height: 150px;
            object-fit: contain; margin-top: 5px; margin-bottom: 43px;
        }

        .bookmark-title {
            font-size: 14px; text-align: center; cursor: text;
            width: 90%; margin: auto;
        }

        .delete-bookmark {
            position: absolute; top: 5px; right: 5px;
            background-color: red; color: white; border: none;
            border-radius: 50%; width: 20px; height: 20px;
            font-size: 12px; cursor: pointer; z-index: 10;
        }

        #add-bookmark {
            display: inline-block; font-size: 1em; font-weight: bold;
            color: #fff; background: linear-gradient(45deg, #3498db, #2980b9);
            padding: 12px 25px; margin: 20px auto; border: none; border-radius: 50px;
            cursor: pointer; text-transform: uppercase; letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08);
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
        }

        #add-bookmark:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(0,0,0,0.15), 0 3px 6px rgba(0,0,0,0.1);
            background: linear-gradient(45deg, #2980b9, #3498db);
        }

        .title-container { text-align: center; }

        .stylish-title {
            display: inline-block; font-size: 1.5em; font-weight: bold;
            color: #fff; background: linear-gradient(45deg, #3498db, #2980b9);
            padding: 10px 30px; border-radius: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08);
            text-transform: uppercase; letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .no-results {
            text-align: center; color: #666; font-style: italic;
            margin: 40px 0; font-size: 18px;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); display: flex;
            align-items: center; justify-content: center;
            z-index: 1000; backdrop-filter: blur(4px);
            opacity: 0; animation: fadeIn 0.3s forwards;
        }
        .loading-box {
            background: white; border-radius: 16px; padding: 40px 50px;
            text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.4s ease;
            width: 250px;
        }
        .loading-spinner {
            width: 48px; height: 48px; border: 4px solid #e0e0e0;
            border-top: 4px solid #3498db; border-radius: 50%;
            animation: spin 0.8s linear infinite; margin: 0 auto 20px;
        }
        .loading-text {
            font-size: 13px; color: #333; font-weight: 600;
            width: 250px; text-align: center;
        }
        .loading-sub {
            font-size: 11px; color: #888; margin-top: 8px;
        }
        .loading-dots {
            display: inline-block; width: 1.5em; text-align: left;
        }
        .loading-dots::after {
            content: ''; animation: dots 1.5s steps(4, end) infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeIn { to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes dots {
            0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; } 75% { content: '...'; }
        }

        @media screen and (max-width: 768px) {
            .bookmark { width: 80%; }
            .bookmarks-container { flex-direction: column; align-items: center; }
            #search-input { width: 90%; }
        }

        @media screen and (min-width: 1024px) {
            .search-container { position: absolute; top: 20px; right: 25px; }
        }
    </style>
</head>
<body>

<div class="title-container">
    <h2 class="stylish-title">BookMarks</h2>
</div>

<div class="search-container">
    <div class="search-box">
        <input type="text" id="search-input" placeholder="Search bookmarks..." />
        <button id="clear-search">&times;</button>
    </div>
</div>

<div id="bookmarks-container" class="bookmarks-container"></div>
<div id="no-results" class="no-results" style="display: none;">Nessun risultato trovato</div>
<button id="add-bookmark">Add link</button>

<script>
const container = document.getElementById('bookmarks-container');
const searchInput = document.getElementById('search-input');
const clearSearchBtn = document.getElementById('clear-search');
const noResults = document.getElementById('no-results');
let draggingEl = null;
let bookmarks = [];

function uid() {
    return Date.now().toString(36) + Math.random().toString(36).substr(2);
}

const loadingMessages = [
    'Fetching the good stuff',
    'Grabbing that link for you',
    'Almost there, hang tight',
    'Working some magic',
    'Scouting the web',
    'Reaching out to the internet',
];

function showLoading() {
    const msg = loadingMessages[Math.floor(Math.random() * loadingMessages.length)];
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.id = 'loading-overlay';
    overlay.innerHTML =
        '<div class="loading-box">' +
            '<div class="loading-spinner"></div>' +
            '<div class="loading-text">' + msg + '<span class="loading-dots"></span></div>' +
            '<div class="loading-sub">Pulling title</div>' +
        '</div>';
    document.body.appendChild(overlay);
}

function hideLoading() {
    const el = document.getElementById('loading-overlay');
    if (el) el.remove();
}

function isValidUrl(s) {
    try { const u = new URL(s); return u.protocol === 'http:' || u.protocol === 'https:'; }
    catch { return false; }
}

// --- API calls ---
function loadBookmarks() {
    fetch('get_page_info.php?action=load')
        .then(r => r.json())
        .then(data => {
            bookmarks = Array.isArray(data.bookmarks) ? data.bookmarks : [];
            bookmarks.forEach(b => { if (!b.id) b.id = uid(); });
            render();
        })
        .catch(() => { bookmarks = []; render(); });
}

function saveBookmarks() {
    fetch('get_page_info.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ bookmarks })
    });
}

// --- Render ---
function render() {
    container.innerHTML = '';
    bookmarks.forEach(bm => {
        const el = document.createElement('div');
        el.className = 'bookmark';
        el.draggable = true;
        el.dataset.id = bm.id;
        el.dataset.url = bm.url;

        const img = document.createElement('img');
        img.src = bm.image || 'placeholder.png';
        img.alt = bm.title || '';
        img.draggable = false;
        img.addEventListener('error', () => { img.src = 'placeholder.png'; });

        const title = document.createElement('div');
        title.className = 'bookmark-title';
        title.textContent = bm.title || 'Senza titolo';

        const del = document.createElement('button');
        del.className = 'delete-bookmark';
        del.textContent = 'X';

        el.appendChild(img);
        el.appendChild(title);
        el.appendChild(del);
        container.appendChild(el);

        // Open link
        el.addEventListener('click', e => {
            if (e.target.classList.contains('delete-bookmark') || e.target.classList.contains('bookmark-title')) return;
            if (isValidUrl(bm.url)) window.open(bm.url, '_blank', 'noopener,noreferrer');
        });

        // Delete
        del.addEventListener('click', () => {
            bookmarks = bookmarks.filter(b => b.id !== bm.id);
            saveBookmarks();
            render();
        });

        // Edit title on double-click
        title.addEventListener('dblclick', e => {
            e.stopPropagation();
            title.contentEditable = true;
            title.focus();
        });
        title.addEventListener('blur', () => {
            title.contentEditable = false;
            const found = bookmarks.find(b => b.id === bm.id);
            if (found) { found.title = title.textContent; saveBookmarks(); }
        });
        title.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); title.blur(); }
        });

        // Drag
        el.addEventListener('dragstart', e => {
            draggingEl = el;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
            setTimeout(() => el.classList.add('dragging'), 0);
        });
        el.addEventListener('dragend', () => {
            el.classList.remove('dragging');
            const newOrder = [];
            container.querySelectorAll('.bookmark').forEach(node => {
                const b = bookmarks.find(x => x.id === node.dataset.id);
                if (b) newOrder.push(b);
            });
            bookmarks = newOrder;
            saveBookmarks();
        });
    });
}

// Drag over
container.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const target = e.target.closest('.bookmark');
    if (target && target !== draggingEl) {
        const rect = target.getBoundingClientRect();
        const after = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
        if (after) target.parentNode.insertBefore(draggingEl, target.nextSibling);
        else target.parentNode.insertBefore(draggingEl, target);
    }
});

// --- Search ---
function searchBookmarks() {
    const q = searchInput.value.toLowerCase().trim();
    let visible = 0;
    container.querySelectorAll('.bookmark').forEach(el => {
        const match = !q
            || el.querySelector('.bookmark-title').textContent.toLowerCase().includes(q)
            || el.dataset.url.toLowerCase().includes(q);
        el.classList.toggle('hidden', !match);
        if (match) visible++;
    });
    noResults.style.display = (visible === 0 && q) ? 'block' : 'none';
    container.style.display = (visible === 0 && q) ? 'none' : 'flex';
    clearSearchBtn.style.display = q ? 'block' : 'none';
}

searchInput.addEventListener('input', searchBookmarks);
clearSearchBtn.addEventListener('click', () => {
    searchInput.value = '';
    searchBookmarks();
    searchInput.focus();
});

// --- Add bookmark ---
document.getElementById('add-bookmark').addEventListener('click', () => {
    const url = prompt('Enter the website URL:');
    if (!url) return;
    if (!isValidUrl(url)) {
        alert('Invalid URL. Please enter a URL starting with http:// or https://');
        return;
    }
    showLoading();
    fetch('get_page_info.php?action=info', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url })
    })
    .then(r => r.json())
    .then(info => {
        bookmarks.push({ id: uid(), url, title: info.title, image: info.image });
        saveBookmarks();
        render();
        hideLoading();
    })
    .catch(() => {
        hideLoading();
        try {
            bookmarks.push({ id: uid(), url, title: new URL(url).hostname, image: 'placeholder.png' });
            saveBookmarks();
            render();
        } catch {}
    });
});

// --- Daily background ---
function setDailyBackground() {
    const now = Date.now();
    let bg = null;
    try { bg = JSON.parse(localStorage.getItem('dailyBackground')); } catch {}

    if (!bg || now - bg.timestamp > 86400000) {
        fetch('https://picsum.photos/2000/1050')
            .then(r => r.blob())
            .then(blob => {
                const reader = new FileReader();
                reader.onloadend = () => {
                    const data = { dataUrl: reader.result, timestamp: now };
                    localStorage.setItem('dailyBackground', JSON.stringify(data));
                    applyBg(data.dataUrl);
                };
                reader.readAsDataURL(blob);
            });
    } else {
        applyBg(bg.dataUrl);
    }
}

function applyBg(dataUrl) {
    if (typeof dataUrl === 'string' && dataUrl.startsWith('data:image/')) {
        document.body.style.backgroundImage = "url('" + dataUrl + "')";
    }
}

// --- Init ---
loadBookmarks();
setDailyBackground();
</script>

<script src="/nodepulse-sw.js"></script>
<?php include __DIR__ . '/../menu_panel.php'; ?>
</body>
</html>
