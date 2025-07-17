<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('admin::app.name') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* Tailwind gray-100 */
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        /* Animação de rotação para a engrenagem */
        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        .animate-spin-custom {
            animation: spin 2s linear infinite;
        }
    </style>
    {{-- Redireciona após 10 segundos (fallback para JS desativado) --}}
    <meta http-equiv="refresh" content="10;url=https://{{$app_url}}/admin/settings">
</head>
<body class="h-screen flex items-center justify-center">
    <div class="container bg-white p-8 rounded-lg shadow-md text-center">
        <div class="flex items-center justify-center mb-6">
            {{-- Ícone de engrenagem animado (representando o "trabalho" ou processamento) --}}
            <svg class="animate-spin-custom h-20 w-20 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.125 1.125 0 0 1 1.924 1.054l-.426 1.756a1.125 1.125 0 0 0 1.054 1.924l1.756-.426c1.756.426 1.756 2.924 0 3.35l-1.756.426a1.125 1.125 0 0 0-1.054 1.924l.426 1.756c-.426 1.756-2.924 1.756-3.35 0a1.125 1.125 0 0 0-1.924-1.054l-.426-1.756a1.125 1.125 0 0 0-1.054-1.924l-1.756.426c-1.756-.426-1.756-2.924 0-3.35l1.756-.426a1.125 1.125 0 0 0 1.054-1.924l-.426-1.756Z" />
            </svg>
        </div>

        <h1 class="text-3xl font-bold text-green-600 mb-4">Integração com Facebook Concluída!</h1>
        <p class="text-gray-700 text-lg mb-6">
            Sua integração com o Facebook foi configurada com sucesso. Estamos finalizando alguns detalhes e você será redirecionado para a página de configurações em <span id="countdown" class="font-bold">10</span> segundos.
        </p>

        {{-- Barra de progresso --}}
        <div class="w-full bg-gray-200 rounded-full h-2.5 mb-6">
            <div class="bg-blue-500 h-2.5 rounded-full transition-all duration-1000 ease-linear" style="width: 0%" id="progressBar"></div>
        </div>

        <p class="text-gray-600">
            Se não for redirecionado automaticamente, clique no link abaixo:
        </p>
        <a href="https://{{$app_url}}/admin/settings" class="mt-4 inline-block bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md transition duration-300 ease-in-out">
            Redirecionar Agora
        </a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let countdownElement = document.getElementById('countdown');
            let progressBar = document.getElementById('progressBar');
            let timeLeft = 10;
            let progress = 0;
            const redirectUrl = "https://{{$app_url}}/admin/settings";

            // Atualiza o texto inicial para garantir que a contagem comece imediatamente
            if (countdownElement) {
                countdownElement.textContent = timeLeft;
            }

            let countdownInterval = setInterval(function() {
                timeLeft--;
                progress += 10; // Incrementa o progresso em 10% a cada segundo

                if (countdownElement) {
                    countdownElement.textContent = timeLeft;
                }

                if (progressBar) {
                    progressBar.style.width = progress + '%';
                }

                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = redirectUrl; // Redireciona via JavaScript
                }
            }, 1000);
        });
    </script>
</body>
</html>