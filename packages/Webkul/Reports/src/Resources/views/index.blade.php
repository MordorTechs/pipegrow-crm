{{--
Este é um componente Blade totalmente redesenhado para a página de relatórios do Krayin CRM.
Inspirado na nova referência do usuário, o layout agora é totalmente compatível com os modos light e dark do Krayin.
Apresenta cards de KPI coloridos e um layout de widget modular para exibir métricas de campanha de forma clara.
--}}
<x-admin::layouts>
    <!-- Título da Página -->
    <x-slot:title>
        @lang('admin::app.reports.index.title')
        </x-slot>   

        {{-- O fundo da página será controlado pelo tema do Krayin (light/dark) --}}
        <div class="flex flex-col gap-4 text-gray-800 dark:text-gray-300">
            <!-- Cabeçalho e Filtros -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-4">
                    <!-- Filtro de Período -->
                    <div class="relative">
                        <input type="text" name="date_range" id="date_range"
                            class="w-full rounded-md border-gray-300 bg-white py-2 pl-10 pr-4 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 sm:text-sm"
                            placeholder="Selecione o período">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z"
                                    clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cards de Métricas Principais -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <!-- Card Total Investido -->
                <div class="flex items-center justify-between overflow-hidden rounded-lg p-3 text-white shadow-lg"
                    style="background-color: #3b82f6;">
                    <div class="flex-grow">
                        <p class="text-lg font-bold">R$ 12.500</p>
                        <p class="text-xs">Total Investido</p>
                    </div>
                    <div class="opacity-70">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v.01" />
                        </svg>
                    </div>
                </div>
                <!-- Card Entregas -->
                <div class="flex items-center justify-between overflow-hidden rounded-lg p-3 text-white shadow-lg"
                    style="background-color: #22c55e;">
                    <div class="flex-grow">
                        <p class="text-lg font-bold">78.9K</p>
                        <p class="text-xs">Entregas</p>
                    </div>
                    <div class="opacity-70">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.042 21.672L13.684 16.6m0 0l-2.51-2.222m2.51 2.222l2.222-2.51m-2.222 2.51l-2.222-2.51M3 10v11m0 0h11m-11 0l11-11" />
                        </svg>
                    </div>
                </div>
                <!-- Card Custo por Contato -->
                <div class="flex items-center justify-between overflow-hidden rounded-lg p-3 text-white shadow-lg"
                    style="background-color: #eab308;">
                    <div class="flex-grow">
                        <p class="text-lg font-bold">R$ 15,20</p>
                        <p class="text-xs">Custo por Contato</p>
                    </div>
                    <div class="opacity-70">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                </div>
                <!-- Card Contatos -->
                <div class="flex items-center justify-between overflow-hidden rounded-lg p-3 text-white shadow-lg"
                    style="background-color: #ef4444;">
                    <div class="flex-grow">
                        <p class="text-lg font-bold">820</p>
                        <p class="text-xs">Contatos</p>
                    </div>
                    <div class="opacity-70">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                </div>
            </div>


            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Coluna Principal -->
                <div class="lg:col-span-2 flex flex-col gap-4">
                    <!-- Widget Impressões Diárias -->
                    <div
                        class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-all hover:shadow-md dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white">Impressões Diárias no Mês</h3>
                        <div class="mt-4 h-56"><canvas id="impressoesChart"></canvas></div>
                    </div>
                    <!-- Widget Contatos Diários -->
                    <div
                        class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-all hover:shadow-md dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white">Contatos Diários</h3>
                        <div class="mt-4 h-56"><canvas id="contatosChart"></canvas></div>
                    </div>
                </div>

                <!-- Coluna Lateral -->
                <div class="lg:col-span-1 flex flex-col gap-4">
                    <!-- Widget Dispositivos -->
                    <div
                        class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-all hover:shadow-md dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white">Dispositivos</h3>
                        <div class="mt-3 flow-root">
                            <table class="min-w-full">
                                <thead class="bg-gray-50 dark:bg-white/5">
                                    <tr>
                                        <th
                                            class="px-2 py-1.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Disp.</th>
                                        <th
                                            class="px-2 py-1.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Conv.</th>
                                        <th
                                            class="px-2 py-1.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Taxa (%)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                    <tr>
                                        <td class="px-2 py-2 flex items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                            <span>Desktop</span>
                                        </td>
                                        <td class="px-2 py-2">550</td>
                                        <td class="px-2 py-2">
                                            <div class="w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div class="rounded-full bg-blue-500 p-0.5 text-center text-xs font-medium leading-none text-blue-100"
                                                    style="width: 67%">67%</div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 py-2 flex items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                            </svg>
                                            <span>Mobile</span>
                                        </td>
                                        <td class="px-2 py-2">200</td>
                                        <td class="px-2 py-2">
                                            <div class="w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div class="rounded-full bg-green-500 p-0.5 text-center text-xs font-medium leading-none text-green-100"
                                                    style="width: 24%">24%</div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 py-2 flex items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21a6 6 0 00-9-5.197m0 0A10.99 10.99 0 0112 4.354a10.99 10.99 0 017 11.45" />
                                            </svg>
                                            <span>Tablet</span>
                                        </td>
                                        <td class="px-2 py-2">70</td>
                                        <td class="px-2 py-2">
                                            <div class="w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div class="rounded-full bg-yellow-500 p-0.5 text-center text-xs font-medium leading-none text-yellow-100"
                                                    style="width: 9%">9%</div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Widget Cidades -->
                    <div
                        class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-all hover:shadow-md dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white">Cidades</h3>
                        <div class="mt-3 flow-root">
                            <table class="min-w-full">
                                <thead class="bg-gray-50 dark:bg-white/5">
                                    <tr>
                                        <th
                                            class="px-2 py-1.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Cidade</th>
                                        <th
                                            class="px-2 py-1.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Conv.</th>
                                        <th
                                            class="px-2 py-1.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Custo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                    <tr>
                                        <td class="px-2 py-2">São Paulo</td>
                                        <td class="px-2 py-2">300</td>
                                        <td class="px-2 py-2">R$ 14,50</td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 py-2">Rio de Janeiro</td>
                                        <td class="px-2 py-2">180</td>
                                        <td class="px-2 py-2">R$ 16,00</td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 py-2">Belo Horizonte</td>
                                        <td class="px-2 py-2">100</td>
                                        <td class="px-2 py-2">R$ 15,80</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        @pushOnce('scripts')
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

        <script type="module">
            window.addEventListener('load', function () {
                flatpickr("#date_range", { mode: "range", dateFormat: "d/m/Y" });

                let chartInstances = {};
                const isDarkMode = () => document.documentElement.classList.contains('dark');

                function renderCharts() {
                    Object.values(chartInstances).forEach(chart => chart.destroy());
                    if (typeof Chart === 'undefined') return;

                    const chartOptions = {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { ticks: { color: isDarkMode() ? '#9ca3af' : '#6b7280' }, grid: { color: isDarkMode() ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)' } },
                            x: { ticks: { color: isDarkMode() ? '#9ca3af' : '#6b7280' }, grid: { display: false } }
                        }
                    };
                    
                    const impressoesCtx = document.getElementById('impressoesChart');
                    if (impressoesCtx) {
                        const gradient = impressoesCtx.getContext('2d').createLinearGradient(0, 0, 0, 300);
                        gradient.addColorStop(0, 'rgba(59, 130, 246, 0.5)');
                        gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');
                        chartInstances.impressoes = new Chart(impressoesCtx, {
                            type: 'line',
                            data: {
                                labels: Array.from({length: 30}, (_, i) => i + 1),
                                datasets: [{
                                    label: 'Impressões',
                                    data: [2841, 1355, 2142, 1020, 1786, 2971, 1650, 2313, 845, 1624, 2460, 1169, 2753, 549, 1284, 303, 1456, 2920, 1221, 2394, 1896, 1710, 2187, 344, 2601, 1935, 872, 1540, 2848, 1429],
                                    borderColor: '#3b82f6', backgroundColor: gradient,
                                    tension: 0.4, fill: true, pointRadius: 0,
                                }]
                            },
                            options: chartOptions
                        });
                    }

                    const contatosCtx = document.getElementById('contatosChart');
                    if (contatosCtx) {
                        chartInstances.contatos = new Chart(contatosCtx, {
                            type: 'bar',
                            data: {
                                labels: Array.from({length: 30}, (_, i) => i + 1),
                                datasets: [{
                                    label: 'Contatos',
                                    data: [157, 275, 212, 183, 78, 98, 292, 256, 223, 65, 112, 196, 248, 177, 204, 295, 228, 139, 281, 166, 91, 246, 58, 172, 235, 88, 273, 134, 217, 300],
                                    backgroundColor: '#10b981',
                                }]
                            },
                            options: chartOptions
                        });
                    }
                }

                renderCharts();
                const themeObserver = new MutationObserver(() => renderCharts());
                themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            });
        </script>
        @endPushOnce
</x-admin::layouts>