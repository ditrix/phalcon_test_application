<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests import</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-900">
    <div class="max-w-6xl mx-auto py-10 px-4">
        <header class="mb-8">
            <h1 class="text-3xl font-bold">Requests import</h1>
            <p class="text-slate-600 mt-2">Upload CSV or XLSX with 100,000 rows and track the import progress.</p>
        </header>

        <div id="uploadPanel" class="bg-white shadow rounded-xl p-6 mb-6">
            <form id="importForm" class="space-y-4">
                <label class="block">
                    <span class="text-sm font-medium mb-2 block">Select file</span>
                    <input id="fileInput" type="file" accept=".csv,.xlsx" class="block w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100" />
                </label>
                <p id="uploadError" class="hidden text-sm text-red-700" role="alert"></p>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md hover:bg-sky-700 transition">
                    Import file
                </button>
            </form>
        </div>

        <div id="progressPanel" class="hidden bg-white shadow rounded-xl p-6 mb-6">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-lg font-semibold">Progress</h2>
                <span id="progressStatus" class="text-sm text-slate-500">Starting</span>
            </div>
            <div class="w-full bg-slate-200 h-3 rounded-full overflow-hidden">
                <div id="progressBar" class="bg-sky-600 h-full rounded-full transition-all" style="width: 0%"></div>
            </div>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-slate-600">
                <div>Read: <span id="rowsRead">0</span></div>
                <div>Inserted: <span id="rowsInserted">0</span></div>
                <div>Offset: <span id="byteOffset">0</span></div>
            </div>
            <p id="progressError" class="hidden mt-4 text-sm text-red-700" role="alert"></p>
            <button id="resumeBtn" type="button" class="hidden mt-3 text-sm text-sky-700 hover:text-sky-900">Resume import</button>
        </div>

        <div id="resultPanel" class="hidden bg-white shadow rounded-xl p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-semibold">Import result</h2>
                <button id="resetBtn" type="button" class="text-sm text-sky-700 hover:text-sky-900">Import another file</button>
            </div>

            <div id="statsGrid" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6"></div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-left">
                            <th class="px-3 py-2 border">#</th>
                            <th class="px-3 py-2 border">external_id</th>
                            <th class="px-3 py-2 border">created_at</th>
                            <th class="px-3 py-2 border">phone</th>
                            <th class="px-3 py-2 border">email</th>
                            <th class="px-3 py-2 border">status</th>
                            <th class="px-3 py-2 border">warnings</th>
                            <th class="px-3 py-2 border">duplicate</th>
                        </tr>
                    </thead>
                    <tbody id="rowsTable"></tbody>
                </table>
            </div>

            <div id="pagination" class="mt-4 flex justify-end gap-2"></div>
        </div>
    </div>

    <script>
        const uploadPanel = document.getElementById('uploadPanel');
        const progressPanel = document.getElementById('progressPanel');
        const resultPanel = document.getElementById('resultPanel');
        const importForm = document.getElementById('importForm');
        const fileInput = document.getElementById('fileInput');
        const progressBar = document.getElementById('progressBar');
        const progressStatus = document.getElementById('progressStatus');
        const rowsRead = document.getElementById('rowsRead');
        const rowsInserted = document.getElementById('rowsInserted');
        const byteOffset = document.getElementById('byteOffset');
        const statsGrid = document.getElementById('statsGrid');
        const rowsTable = document.getElementById('rowsTable');
        const pagination = document.getElementById('pagination');
        const resetBtn = document.getElementById('resetBtn');
        const uploadError = document.getElementById('uploadError');
        const progressError = document.getElementById('progressError');
        const resumeBtn = document.getElementById('resumeBtn');

        let currentImportId = null;
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
            currentImportId = null;
            currentPage = 1;
            uploadPanel.classList.remove('hidden');
            progressPanel.classList.add('hidden');
            resultPanel.classList.add('hidden');
            progressBar.style.width = '0%';
            rowsRead.textContent = '0';
            rowsInserted.textContent = '0';
            byteOffset.textContent = '0';
            progressStatus.textContent = 'Starting';
            uploadError.textContent = '';
            uploadError.classList.add('hidden');
            progressError.textContent = '';
            progressError.classList.add('hidden');
            resumeBtn.classList.add('hidden');
            fileInput.value = '';
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>\"']/g, function (char) {
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return map[char] || char;
            });
        }

        function renderStats(stats) {
            const cards = [
                { label: 'Total rows', value: stats.total_rows ?? 0 },
                { label: 'Inserted', value: stats.rows_inserted ?? 0 },
                { label: 'Duplicates', value: stats.duplicates ?? 0 },
                { label: 'Rows with warnings', value: stats.rows_with_warnings ?? 0 },
            ];

            statsGrid.innerHTML = cards.map(item => `
                <div class="border rounded-lg p-4 bg-slate-50">
                    <div class="text-xs uppercase text-slate-500">${escapeHtml(item.label)}</div>
                    <div class="text-2xl font-bold mt-2">${escapeHtml(item.value)}</div>
                </div>
            `).join('');
        }

        function renderWarningCounts(warningCounts) {
            const entries = Object.entries(warningCounts || {});
            if (!entries.length) {
                return '<span class="text-slate-500">none</span>';
            }
            return entries.map(([code, count]) => `<span class="inline-block mr-2 mb-2 px-2 py-1 rounded-full bg-amber-100 text-amber-700">${escapeHtml(code)}: ${escapeHtml(count)}</span>`).join('');
        }

        function renderPagination(total, page) {
            const maxPage = Math.max(1, Math.ceil(total / 50));
            const buttons = [];
            for (let i = 1; i <= maxPage; i += 1) {
                buttons.push(`<button type="button" data-page="${i}" class="px-3 py-1 rounded ${i === page ? 'bg-sky-600 text-white' : 'bg-slate-200 text-slate-700'}">${i}</button>`);
            }
            pagination.innerHTML = buttons.join('');
            pagination.querySelectorAll('button').forEach(button => {
                button.addEventListener('click', () => {
                    currentPage = Number(button.dataset.page);
                    loadRows();
                });
            });
        }

        function renderRows(rows) {
            rowsTable.innerHTML = rows.map(row => `
                <tr>
                    <td class="border px-3 py-2">${escapeHtml(row.id)}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.external_id)}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.created_at)}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.phone || '-')}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.email || '-')}</td>
                    <td class="border px-3 py-2">${escapeHtml(row.status || '-')}</td>
                    <td class="border px-3 py-2">${row.warnings ? renderWarningCounts({ [row.warnings]: 1 }) : '<span class="text-slate-500">—</span>'}</td>
                    <td class="border px-3 py-2"><span class="inline-flex px-2 py-1 rounded-full ${row.is_duplicate ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'}">${row.is_duplicate ? 'Duplicate' : 'Original'}</span></td>
                </tr>
            `).join('');
        }

        async function loadRows() {
            if (!currentImportId) {
                return;
            }
            const response = await fetch(`/import/${currentImportId}/rows?page=${currentPage}`);
            const data = await response.json();
            renderRows(data.rows || []);
            renderPagination(data.total || 0, data.page || 1);
        }

        async function loadStats() {
            if (!currentImportId) {
                return;
            }
            const response = await fetch(`/import/${currentImportId}/stats`);
            const stats = await response.json();
            renderStats(stats);
            const warningCard = document.createElement('div');
            warningCard.className = 'border rounded-lg p-4 bg-slate-50';
            warningCard.innerHTML = `
                <div class="text-xs uppercase text-slate-500 mb-2">Warnings</div>
                <div>${renderWarningCounts(stats.warning_counts || {})}</div>
            `;
            statsGrid.appendChild(warningCard);
            loadRows();
        }

        async function pollImport() {
            if (!currentImportId) {
                return;
            }

            try {
                const response = await fetch('/import/step', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: currentImportId })
                });
                const payload = await response.json();

                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || `Import request failed (HTTP ${response.status})`);
                }

                progressError.classList.add('hidden');
                resumeBtn.classList.add('hidden');
                if (payload.status === 'importing') {
                    progressStatus.textContent = 'Importing';
                    const progress = Math.min(90, Math.round((payload.rows_read / 100000) * 100));
                    progressBar.style.width = `${progress}%`;
                    rowsRead.textContent = payload.rows_read || 0;
                    rowsInserted.textContent = payload.rows_inserted || 0;
                    byteOffset.textContent = payload.byte_offset || 0;
                    setTimeout(pollImport, 600);
                    return;
                }

                if (payload.status === 'done') {
                    progressStatus.textContent = 'Done';
                    progressBar.style.width = '100%';
                    rowsRead.textContent = payload.stats.total_rows || 0;
                    rowsInserted.textContent = payload.stats.total_rows || 0;
                    showResult();
                    loadStats();
                    return;
                }

                progressStatus.textContent = 'Finalizing';
                setTimeout(pollImport, 600);
            } catch (error) {
                progressStatus.textContent = 'Import paused';
                progressError.textContent = error.message || 'Unable to continue the import';
                progressError.classList.remove('hidden');
                resumeBtn.classList.remove('hidden');
            }
        }

        importForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const file = fileInput.files[0];
            if (!file) {
                return;
            }

            uploadError.textContent = '';
            uploadError.classList.add('hidden');
            showProgress();
            const formData = new FormData();
            formData.append('file', file);

            try {
                const uploadResponse = await fetch('/import/upload', {
                    method: 'POST',
                    body: formData
                });
                const upload = await uploadResponse.json();

                if (!uploadResponse.ok || !upload.ok) {
                    throw new Error(upload.error || `Upload failed (HTTP ${uploadResponse.status})`);
                }

                currentImportId = upload.import_id;
                pollImport();
            } catch (error) {
                uploadPanel.classList.remove('hidden');
                progressPanel.classList.add('hidden');
                uploadError.textContent = error.message || 'Upload failed';
                uploadError.classList.remove('hidden');
            }
        });

        resumeBtn.addEventListener('click', pollImport);
        resetBtn.addEventListener('click', resetView);
    </script>
</body>
</html>
