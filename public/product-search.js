(function() {
    'use strict';

    const searchToggleBtn = document.getElementById('search-toggle-btn');
    const searchPanel = document.getElementById('search-panel');
    const searchInput = document.getElementById('product-search');
    const searchClose = document.getElementById('search-panel-close');
    const resultsContainer = document.getElementById('search-results');

    if (!searchToggleBtn || !searchPanel || !searchInput) return;

    let debounceTimer = null;
    let currentQuery = '';

    // Toggle search panel
    searchToggleBtn.addEventListener('click', function(e) {
        e.preventDefault();
        toggleSearch();
    });

    // role="button" promises Space activates it, but a link only fires click on Enter
    searchToggleBtn.addEventListener('keydown', function(e) {
        const isSpace = e.key === ' ' || e.key === 'Spacebar';
        if (!isSpace) return;
        e.preventDefault(); // stop Space scrolling the page
        toggleSearch();
    });

    function toggleSearch() {
        const isOpen = searchPanel.classList.contains('open');
        if (isOpen) {
            closeSearch();
        } else {
            openSearch();
        }
    }

    // Close button
    if (searchClose) {
        searchClose.addEventListener('click', function() {
            closeSearch();
        });
    }

    // Close on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && searchPanel.classList.contains('open')) {
            closeSearch();
        }
    });

    // Debounced search on input
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        currentQuery = query;

        clearTimeout(debounceTimer);

        if (query.length < 2) {
            hideResults();
            return;
        }

        debounceTimer = setTimeout(function() {
            performSearch(query);
        }, 1000);
    });

    // Hide results on click outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-panel') && !e.target.closest('#search-toggle-btn')) {
            if (searchPanel.classList.contains('open')) {
                closeSearch();
            }
        }
    });

    function openSearch() {
        searchPanel.removeAttribute('inert');
        searchPanel.classList.add('open');
        searchToggleBtn.setAttribute('aria-expanded', 'true');
        setTimeout(function() {
            searchInput.focus();
        }, 300);
    }

    function closeSearch() {
        // Move focus out before going inert, or the browser drops it to the document
        const focusWasInPanel = searchPanel.contains(document.activeElement);
        if (focusWasInPanel) {
            searchToggleBtn.focus();
        }
        searchPanel.classList.remove('open');
        searchPanel.setAttribute('inert', '');
        searchToggleBtn.setAttribute('aria-expanded', 'false');
        searchInput.value = '';
        hideResults();
    }

    function performSearch(query) {
        fetch('/api/search/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query: query })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (query !== currentQuery) return;

            if (data.success && data.results.length > 0) {
                showResults(data.results);
            } else {
                showNoResults();
            }
        })
        .catch(function(err) {
            console.error('Search error:', err);
            hideResults();
        });
    }

    // A missing product image hides itself. Error events do not bubble, so listen in the
    // capture phase; this replaces an inline onerror, which the nonce-only CSP blocks.
    resultsContainer.addEventListener('error', function(event) {
        if (event.target.tagName === 'IMG') event.target.style.display = 'none';
    }, true);

    function showResults(results) {
        let html = '';
        results.forEach(function(product) {
            const currencySign = window.shopCurrencySign || '£';
            const priceHtml = product.available
                ? '<span class="search-price">' + currencySign + product.price + '</span>'
                : '<span class="search-status">' + escapeHtml(product.status || 'Unavailable') + '</span>';

            html += '<a href="' + product.url + '" class="search-result-item">' +
                '<img src="' + product.image + '" alt="' + escapeHtml(product.title) + '">' +
                '<div class="search-result-info">' +
                    '<div class="search-result-title">' + escapeHtml(product.title) + '</div>' +
                    '<div class="search-result-ref">' + escapeHtml(product.reference) + '</div>' +
                    priceHtml +
                '</div>' +
            '</a>';
        });

        resultsContainer.innerHTML = html;
        resultsContainer.classList.add('visible');
    }

    function showNoResults() {
        resultsContainer.innerHTML = '<div class="search-no-results">No products found</div>';
        resultsContainer.classList.add('visible');
    }

    function hideResults() {
        resultsContainer.classList.remove('visible');
        resultsContainer.innerHTML = '';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
