const input = document.querySelector('#search-input');
const list = document.querySelector('#suggestions');
const searchStatus = document.querySelector('#search-status');
const selectionStatus = document.querySelector('#selection-status');

if (input && list && searchStatus && selectionStatus) {
    let debounceTimer;
    let activeSearch;
    let activeIndex = -1;
    let sessionSelections = 0;

    const updateSearchStatus = (state, title = '', description = '') => {
        searchStatus.replaceChildren();
        searchStatus.hidden = !state;
        searchStatus.className = state ? `search-status is-${state}` : 'search-status';

        if (!state) {
            return;
        }

        const icon = document.createElement('span');
        icon.className = 'search-status-icon';
        icon.setAttribute('aria-hidden', 'true');

        const content = document.createElement('span');
        content.className = 'search-status-content';

        const heading = document.createElement('strong');
        heading.textContent = title;
        content.append(heading);

        if (description) {
            const detail = document.createElement('span');
            detail.textContent = description;
            content.append(detail);
        }

        searchStatus.append(icon, content);
    };

    const closeSuggestions = () => {
        list.replaceChildren();
        list.classList.remove('is-open');
        input.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
    };

    const renderSuggestions = (suggestions) => {
        closeSuggestions();

        for (const suggestion of suggestions) {
            const option = document.createElement('li');
            const button = document.createElement('button');

            button.className = 'suggestion-option';
            button.type = 'button';
            button.setAttribute('role', 'option');
            button.textContent = suggestion.content;
            button.addEventListener('click', () => selectSuggestion(suggestion, button));

            option.append(button);
            list.append(option);
        }

        if (suggestions.length > 0) {
            list.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
        }
    };

    const selectSuggestion = async (suggestion, button) => {
        button.disabled = true;
        updateSearchStatus('searching', 'Recording selection', 'Saving your selection.');

        try {
            const response = await fetch('/api/search/selections', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ selection_token: suggestion.selection_token }),
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Selection could not be recorded.');
            }

            if (result.counted) {
                sessionSelections += 1;
            }

            input.value = suggestion.content;
            closeSuggestions();
            updateSearchStatus('');
            selectionStatus.textContent =
                `${result.total_selections.toLocaleString()} total selections recorded; ` +
                `${sessionSelections} counted from this page.`;
        } catch (error) {
            button.disabled = false;
            updateSearchStatus('error', 'Selection could not be recorded', error.message);
        }
    };

    const requestSuggestions = async () => {
        const query = input.value.trim();

        if (query.length < 3) {
            activeSearch?.abort();
            updateSearchStatus('');
            closeSuggestions();
            return;
        }

        activeSearch?.abort();
        activeSearch = new AbortController();
        updateSearchStatus('searching', 'Searching records', 'Looking for matching suggestions.');

        try {
            const response = await fetch(`/api/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                signal: activeSearch.signal,
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Search is unavailable.');
            }

            renderSuggestions(result.data);
            updateSearchStatus(
                result.data.length ? '' : 'empty',
                result.data.length ? '' : `No matches for "${query}"`,
                result.data.length ? '' : 'Try checking the spelling or using a shorter search.',
            );
        } catch (error) {
            if (error.name !== 'AbortError') {
                closeSuggestions();
                updateSearchStatus('error', 'Search is unavailable', error.message);
            }
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(requestSuggestions, 300);
    });

    input.addEventListener('keydown', (event) => {
        const options = list.querySelectorAll('.suggestion-option');

        if (event.key === 'ArrowDown' && options.length > 0) {
            event.preventDefault();
            activeIndex = (activeIndex + 1) % options.length;
            options[activeIndex].focus();
        } else if (event.key === 'Escape') {
            closeSuggestions();
        }
    });

    list.addEventListener('keydown', (event) => {
        const options = [...list.querySelectorAll('.suggestion-option')];

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options[activeIndex].focus();
        } else if (event.key === 'Escape') {
            closeSuggestions();
            input.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.search-card')) {
            closeSuggestions();
        }
    });
}
