<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests import</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes import-progress {
            from { transform: translateX(-120%); }
            to { transform: translateX(300%); }
        }

        .import-progress-active {
            animation: import-progress 1.4s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-900">
    <div class="max-w-6xl mx-auto py-10 px-4">
        <header class="mb-8">
            <h1 class="text-3xl font-bold">Requests import</h1>
            <p class="text-slate-600 mt-2">Upload CSV or XLSX. The uploaded file replaces the current requests dataset.</p>
        </header>

        <div id="uploadPanel" class="bg-white shadow rounded-xl p-6 mb-6">
            <form id="importForm" class="space-y-4">
                <label class="block">
                    <span class="text-sm font-medium mb-2 block">Select file</span>
                    <input id="fileInput" type="file" accept=".csv,.xlsx" class="block w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100" />
                </label>
                <p id="uploadError" class="hidden text-sm text-red-700" role="alert"></p>
                <button id="importButton" type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md hover:bg-sky-700 transition">
                    Import file
                </button>
            </form>
        </div>

        <div id="progressPanel" class="hidden bg-white shadow rounded-xl p-6 mb-6">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-lg font-semibold">Progress</h2>
                <span id="progressStatus" class="text-sm text-slate-500">Waiting</span>
            </div>
            <div class="w-full bg-slate-200 h-3 rounded-full overflow-hidden">
                <div id="progressBar" class="bg-sky-600 h-full rounded-full transition-all" style="width: 0%"></div>
            </div>
            <p class="mt-4 text-sm text-slate-600">The server is processing the file. Row counters and elapsed time will appear when the import completes.</p>
            <p id="progressError" class="hidden mt-4 text-sm text-red-700" role="alert"></p>
        </div>

        <div id="resultPanel" class="hidden bg-white shadow rounded-xl p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-semibold">Import result</h2>
                <button id="resetBtn" type="button" class="text-sm text-sky-700 hover:text-sky-900">Import another file</button>
            </div>

            <p id="resultError" class="hidden mb-4 text-sm text-red-700" role="alert"></p>
            <div id="statsGrid" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6"></div>
            <div id="warningCounts" class="mb-6"></div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-left">
                            <th class="px-3 py-2 border">id</th>
                            <th class="px-3 py-2 border">external_id</th>
                            <th class="px-3 py-2 border">created_at</th>
                            <th class="px-3 py-2 border">first_name</th>
                            <th class="px-3 py-2 border">last_name</th>
                            <th class="px-3 py-2 border">phone</th>
                            <th class="px-3 py-2 border">email</th>
                            <th class="px-3 py-2 border">warning</th>
                        </tr>
                    </thead>
                    <tbody id="rowsTable"></tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-end gap-3">
                <button id="previousPage" type="button" class="px-3 py-1 rounded bg-slate-200 text-slate-700">Previous</button>
                <span id="pageLabel" class="text-sm text-slate-600"></span>
                <button id="nextPage" type="button" class="px-3 py-1 rounded bg-slate-200 text-slate-700">Next</button>
            </div>
        </div>
    </div>

    <script>
        const uploadPanel = document.getElementById('uploadPanel');
        const progressPanel = document.getElementById('progressPanel');
        const resultPanel = document.getElementById('resultPanel');
        const importForm = document.getElementById('importForm');
        const fileInput = document.getElementById('fileInput');
        const importButton = document.getElementById('importButton');
        const progressBar = document.getElementById('progressBar');
        const progressStatus = document.getElementById('progressStatus');
        const progressError = document.getElementById('progressError');
        const uploadError = document.getElementById('uploadError');
        const resultError = document.getElementById('resultError');
        const statsGrid = document.getElementById('statsGrid');
        const warningCounts = document.getElementById('warningCounts');
        const rowsTable = document.getElementById('rowsTable');
        const pageLabel = document.getElementById('pageLabel');
        const previousPage = document.getElementById('previousPage');
        const nextPage = document.getElementById('nextPage');
        const resetBtn = document.getElementById('resetBtn');

        let importStats = null;
        let currentPage = 1;

        function showProgress() {
            uploadPanel.classList.add('hidden');
            progressPanel.classList.remove('hidden');
            resultPanel.classList.add('hidden');
        }

        function showResult() {
            progressPanel.classList.add('hidden');
            resultPanel.classList.remove('hidden');
        }

        function resetView() {
            importStats = null;
            currentPage = 1;
            uploadPanel.classList.remove('hidden');
            progressPanel.classList.add('hidden');
            resultPanel.classList.add('hidden');
            progressBar.style.width = '0%';
            progressStatus.textContent = 'Waiting';
            uploadError.textContent = '';
            uploadError.classList.add('hidden');
            progressError.textContent = '';
            progressError.classList.add('hidden');
            resultError.textContent = '';
            resultError.classList.add('hidden');
            statsGrid.innerHTML = '';
            warningCounts.innerHTML = '';
            rowsTable.innerHTML = '';
            progressBar.classList.remove('import-progress-active');
            fileInput.value = '';
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (char) {
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return map[char] || char;
            });
        }

        function renderStats(stats) {
            const cards = [
                { label: 'Total rows', value: stats.total_rows },
                { label: 'Inserted', value: stats.rows_inserted },
                { label: 'Duplicates', value: stats.duplicates },
                { label: 'Rows with warnings', value: stats.rows_with_warnings },
                { label: 'Import time (seconds)', value: stats.elapsed_seconds }
            ];

            statsGrid.innerHTML = cards.map(item => `
                <div class="border rounded-lg p-4 bg-slate-50">
                    <div class="text-xs uppercase text-slate-500">${escapeHtml(item.label)}</div>
                    <div class="text-2xl font-bold mt-2">${escapeHtml(item.value)}</div>
                </div>
            `).join('');

            const entries = Object.entries(stats.warning_counts || {});
            warningCounts.innerHTML = `
                <div class="text-sm font-semibold mb-2">Warnings by type</div>
                <div>${entries.length
                    ? entries.map(([code, count]) => `<span class="inline-block mr-2 mb-2 px-2 py-1 rounded-full bg-amber-100 text-amber-700">${escapeHtml(code)}: ${escapeHtml(count)}</span>`).join('')
                    : '<span class="text-slate-500">None</span>'}</div>
            `;
        }

        function renderRows(rows) {
            rowsTable.innerHTML = rows.map(row => `
                <tr>
                    <td class="border px-3 py-2">${escapeHtml(row.id)}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.external_id)}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.created_at)}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.first_name || '-')}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.last_name || '-')}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.phone || '-')}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.email || '-')}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.warning)}</td>
                </tr>
            `).join('');
        }

        async function loadRows() {
            const response = await fetch(`/requests/rows?page=${currentPage}`);
            const data = await response.json();
            if (!response.ok || data.error) {
                throw new Error(data.error || `Unable to load rows (HTTP ${response.status})`);
            }

            renderRows(data.rows || []);
            const maxPage = Math.max(1, Math.ceil(data.total / data.limit));
            pageLabel.textContent = `Page ${data.page} of ${maxPage}`;
            previousPage.disabled = data.page <= 1;
            nextPage.disabled = data.page >= maxPage;
            previousPage.classList.toggle('opacity-50', previousPage.disabled);
            nextPage.classList.toggle('opacity-50', nextPage.disabled);
        }

        importForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const file = fileInput.files[0];
            if (!file) {
                uploadError.textContent = 'Select a CSV or XLSX file first.';
                uploadError.classList.remove('hidden');
                return;
            }

            uploadError.textContent = '';
            uploadError.classList.add('hidden');
            progressError.textContent = '';
            progressError.classList.add('hidden');
            resultError.textContent = '';
            resultError.classList.add('hidden');
            importButton.disabled = true;
            importButton.classList.add('opacity-50');
            showProgress();
            progressStatus.textContent = `Uploading and importing ${file.name}`;
            progressBar.style.width = '35%';
            progressBar.classList.add('import-progress-active');

            const formData = new FormData();
            formData.append('file', file);

            try {
                const response = await fetch('/import/upload', {
                    method: 'POST',
                    body: formData
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || `Import failed (HTTP ${response.status})`);
                }

                importStats = payload;
                progressStatus.textContent = 'Import complete';
                progressBar.classList.remove('import-progress-active');
                progressBar.style.width = '100%';
                renderStats(importStats);
                showResult();

                try {
                    await loadRows();
                } catch (error) {
                    resultError.textContent = error.message || 'Unable to load imported rows.';
                    resultError.classList.remove('hidden');
                }
            } catch (error) {
                progressBar.classList.remove('import-progress-active');
                progressBar.style.width = '0%';
                progressPanel.classList.add('hidden');
                uploadPanel.classList.remove('hidden');
                uploadError.textContent = error.message || 'Import failed.';
                uploadError.classList.remove('hidden');
            } finally {
                importButton.disabled = false;
                importButton.classList.remove('opacity-50');
            }
        });

        previousPage.addEventListener('click', async function () {
            if (currentPage > 1) {
                currentPage--;
                await loadRows();
            }
        });

        nextPage.addEventListener('click', async function () {
            if (importStats && currentPage < Math.ceil(importStats.total_rows / 50)) {
                currentPage++;
                await loadRows();
            }
        });

        resetBtn.addEventListener('click', resetView);
    </script>
</body>
</html>
