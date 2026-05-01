// Security and utility functions
const SecurityUtils = {
    generateSecureId: function() {
        const array = new Uint8Array(32);
        crypto.getRandomValues(array);
        return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    },

    isValidUrl: function(string) {
        try {
            const url = new URL(string);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (_) {
            return false;
        }
    },

    sanitizeHtml: function(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};

// API handler
const API = {
    baseUrl: window.location.origin + window.location.pathname.replace('index.html', ''),

    async createShare(content, maxViews) {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'create',
                content: content,
                maxViews: maxViews
            })
        });

        if (!response.ok) {
            if (response.status === 429) {
                throw new Error('Too many requests. Please wait and try again.');
            }
            throw new Error('HTTP error! status: ' + response.status);
        }

        return await response.json();
    },

    async getShare(shareId) {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'get',
                shareId: shareId
            })
        });

        if (!response.ok) {
            if (response.status === 429) {
                throw new Error('Too many requests. Please wait and try again.');
            }
            throw new Error('HTTP error! status: ' + response.status);
        }

        return await response.json();
    }
};

// UI Controller
const UI = {
    elements: {},

    init: function() {
        this.elements = {
            createForm: document.getElementById('createForm'),
            viewContent: document.getElementById('viewContent'),
            contentInput: document.getElementById('contentInput'),
            maxViews: document.getElementById('maxViews'),
            generateBtn: document.getElementById('generateBtn'),
            generatedLink: document.getElementById('generatedLink'),
            shareUrl: document.getElementById('shareUrl'),
            copyBtn: document.getElementById('copyBtn'),
            contentDisplay: document.getElementById('contentDisplay'),
            qrcode: document.getElementById('qrcode'),
            viewsRemaining: document.getElementById('viewsRemaining'),
            errorMessage: document.getElementById('errorMessage')
        };

        this.bindEvents();
        this.checkUrlParameters();
    },

    bindEvents: function() {
        this.elements.generateBtn.addEventListener('click', this.handleGenerate.bind(this));
        this.elements.copyBtn.addEventListener('click', this.handleCopy.bind(this));

        // Auto-resize textarea
        this.elements.contentInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    },

    checkUrlParameters: function() {
        const urlParams = new URLSearchParams(window.location.search);
        const shareId = urlParams.get('s');

        if (shareId) {
            this.showContent(shareId);
        }
    },

    async handleGenerate() {
        const content = this.elements.contentInput.value.trim();
        const maxViews = parseInt(this.elements.maxViews.value);

        if (!content) {
            this.showError('Please enter content to share');
            return;
        }

        if (!maxViews || maxViews < 1 || maxViews > 100) {
            this.showError('Maximum views must be between 1 and 100');
            return;
        }

        try {
            this.setLoading(true);
            const result = await API.createShare(content, maxViews);

            if (result.success) {
                const shareUrl = API.baseUrl + '?s=' + result.shareId;
                this.elements.shareUrl.value = shareUrl;
                this.elements.generatedLink.classList.remove('hidden');
                this.showSuccess('Link generated successfully!');
            } else {
                this.showError(result.message || 'Failed to create share');
            }
        } catch (error) {
            this.showError('Network error: ' + error.message);
        } finally {
            this.setLoading(false);
        }
    },

    async showContent(shareId) {
        this.elements.createForm.classList.add('hidden');

        try {
            const result = await API.getShare(shareId);

            if (result.success) {
                this.renderContent(result.content);
                this.elements.viewsRemaining.textContent =
                    'Views remaining: ' + result.viewsRemaining;
                this.elements.viewContent.classList.remove('hidden');
            } else {
                this.showError(result.message || 'Content not found or expired');
            }
        } catch (error) {
            this.showError('Network error: ' + error.message);
        }
    },

    renderContent: function(content) {
        // Check if content is a single URL
        if (SecurityUtils.isValidUrl(content.trim())) {
            var url = content.trim();
            this.elements.contentDisplay.classList.add('is-link');

            // Build interstitial with DOM APIs (no innerHTML with user data)
            var wrapper = document.createElement('div');
            wrapper.className = 'redirect-warning';

            var title = document.createElement('p');
            var strong = document.createElement('strong');
            strong.textContent = 'This share contains a link:';
            title.appendChild(strong);
            wrapper.appendChild(title);

            var urlDisplay = document.createElement('p');
            urlDisplay.className = 'redirect-url';
            urlDisplay.textContent = url;
            wrapper.appendChild(urlDisplay);

            var buttons = document.createElement('div');
            buttons.className = 'redirect-buttons';

            var openBtn = document.createElement('button');
            openBtn.textContent = 'Open Link';
            openBtn.className = 'btn-primary';
            openBtn.style.width = 'auto';
            openBtn.addEventListener('click', function() {
                window.location.href = url;
            });
            buttons.appendChild(openBtn);

            var copyBtn = document.createElement('button');
            copyBtn.textContent = 'Copy URL';
            copyBtn.className = 'btn-secondary';
            copyBtn.addEventListener('click', function() {
                navigator.clipboard.writeText(url).then(function() {
                    copyBtn.textContent = 'Copied!';
                    copyBtn.style.background = '#28a745';
                    copyBtn.style.color = 'white';
                    setTimeout(function() {
                        copyBtn.textContent = 'Copy URL';
                        copyBtn.style.background = '';
                        copyBtn.style.color = '';
                    }, 2000);
                });
            });
            buttons.appendChild(copyBtn);

            wrapper.appendChild(buttons);

            this.elements.contentDisplay.innerHTML = '';
            this.elements.contentDisplay.appendChild(wrapper);
            return;
        }

        // Regular text content - safe via textContent (no double encoding)
        this.elements.contentDisplay.textContent = content;
    },

    handleCopy: function() {
        var text = this.elements.shareUrl.value;
        var btn = this.elements.copyBtn;
        var originalText = btn.textContent;

        navigator.clipboard.writeText(text).then(function() {
            btn.textContent = 'Copied!';
            btn.style.background = '#28a745';
            btn.style.color = 'white';
            setTimeout(function() {
                btn.textContent = originalText;
                btn.style.background = '';
                btn.style.color = '';
            }, 2000);
        });
    },

    showError: function(message) {
        this.elements.errorMessage.textContent = message;
        this.elements.errorMessage.classList.remove('hidden');
        setTimeout(function() {
            UI.elements.errorMessage.classList.add('hidden');
        }, 5000);
    },

    showSuccess: function(message) {
        var successDiv = document.createElement('div');
        successDiv.className = 'success-message';
        successDiv.textContent = message;
        this.elements.errorMessage.parentNode.insertBefore(successDiv, this.elements.errorMessage);

        setTimeout(function() {
            successDiv.remove();
        }, 3000);
    },

    setLoading: function(loading) {
        if (loading) {
            this.elements.generateBtn.classList.add('loading');
            this.elements.generateBtn.disabled = true;
        } else {
            this.elements.generateBtn.classList.remove('loading');
            this.elements.generateBtn.disabled = false;
        }
    }
};

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    UI.init();
});

// Security: Clear sensitive data on page unload
window.addEventListener('beforeunload', function() {
    if (UI.elements.contentInput) {
        UI.elements.contentInput.value = '';
    }
    if (UI.elements.shareUrl) {
        UI.elements.shareUrl.value = '';
    }
});