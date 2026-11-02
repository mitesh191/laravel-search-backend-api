<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Search demo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="search-page">
        <section class="search-card" aria-labelledby="page-title">
            <p class="eyebrow">Laravel · Native database search</p>
            <h1 id="page-title">Find a place</h1>
            <p class="intro">Search suggestions are free. Usage is counted only when you choose one.</p>

            <label for="search-input">Search records</label>
            <div class="search-control">
                <input
                    id="search-input"
                    type="search"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Try London Road"
                    aria-autocomplete="list"
                    aria-controls="suggestions"
                    aria-expanded="false"
                >
            </div>

            <div id="search-status" class="search-status" role="status" aria-live="polite" aria-atomic="true" hidden></div>
            <ul id="suggestions" class="suggestions" role="listbox" aria-label="Search suggestions"></ul>
            <p id="selection-status" class="selection-status" aria-live="polite">
                No selections counted in this session.
            </p>
            <p class="hint">Enter at least 3 characters. Use the arrow keys and Enter, or click a suggestion.</p>
        </section>
    </main>
</body>
</html>
