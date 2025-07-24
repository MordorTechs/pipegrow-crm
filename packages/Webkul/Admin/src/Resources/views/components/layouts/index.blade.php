<!DOCTYPE html>

<html
    class="{{ request()->cookie('dark_mode') ? 'dark' : '' }}"
    lang="{{ app()->getLocale() }}"
    dir="{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'rtl' : 'ltr' }}"
>

<head>

    {!! view_render_event('admin.layout.head.before') !!}

    <title>{{ $title ?? '' }}</title>

    <meta charset="UTF-8">

    <meta
        http-equiv="X-UA-Compatible"
        content="IE=edge"
    >
    <meta
        http-equiv="content-language"
        content="{{ app()->getLocale() }}"
    >

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <meta
        name="base-url"
        content="{{ url()->to('/') }}"
    >
    <meta
        name="currency"
        content="{{
            json_encode([
                'code'   => config('app.currency'),
                'symbol' => core()->currencySymbol(config('app.currency'))])
            }}
        "
    >
    <meta
        name="facebook-domain-verification"
        content="xlw94z98d2zt6t7gvwdo76v11l7ytg"/>

    @stack('meta')

    {{
        vite()->set(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])
    }}

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap"
        rel="stylesheet"
    />

    <link
        rel="preload"
        as="image"
        href="{{ url('cache/logo/bagisto.png') }}"
    >

    <meta property="og:image" content="{{asset('logo.png')}}" />
    <meta property="og:type" content="website" />
    <meta property="og:title" content="PipeGrow - CRM de Vendas Consultivas" />
    <meta property="og:description" content="Centralize, automatize e feche mais negócios com a PipeGrow. Teste grátis." />
    <meta property="fb:app_id" content="751116047366950" />
    
    @if ($favicon = core()->getConfigData('general.design.admin_logo.favicon'))
        <link
            type="image/x-icon"
            href="{{ Storage::url($favicon) }}"
            rel="shortcut icon"
            sizes="16x16"
        >
    @else
        <link
            type="image/x-icon"
            href="{{ vite()->asset('images/favicon.ico') }}"
            rel="shortcut icon"
            sizes="16x16"
        />
    @endif

    @php
        $brandColor = core()->getConfigData('general.settings.menu_color.brand_color') ?? '#0E90D9';
    @endphp

    @stack('styles')

    <style>
        :root {
            --brand-color: {{ $brandColor }};
        }

        {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
    </style>

    {!! view_render_event('admin.layout.head.after') !!}

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-1FCLQXWL8C"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', 'G-1FCLQXWL8C', {
            'user_id': "{{ auth()->check() ? auth()->user()->id : 'guest' }}"
        });

        // Define user properties if the user is authenticated
        @auth
            gtag('set', 'user_properties', {
                user_role: "{{ auth()->user()->role->name ?? 'N/A' }}", // Example: 'admin', 'sales'
                user_group: "{{ auth()->user()->group->name ?? 'N/A' }}" // Example: 'default', 'premium'
                // Add other relevant user properties here
            });
        @endauth
    </script>

    @if (session('lead_updated_ga4'))
        <script>
            gtag('event', 'lead_status_changed', {
                'lead_id': '{{ session('lead_updated_ga4.lead_id') }}',
                'old_stage': '{{ session('lead_updated_ga4.old_stage') }}',
                'new_stage': '{{ session('lead_updated_ga4.new_stage') }}',
                'pipeline_name': '{{ session('lead_updated_ga4.pipeline_name') }}',
                'value': {{ session('lead_updated_ga4.lead_value') ?? 0 }} // Valor do lead
            });
        </script>
        {{ Session::forget('lead_updated_ga4') }}
    @endif

    {{-- GA4 Login Event --}}
    @if (session('user_logged_in_ga4'))
        <script>
            gtag('event', 'login', {
                'method': 'email_password', // Or 'sso', 'oauth', etc.
                'user_id': "{{ auth()->check() ? auth()->user()->id : 'guest' }}"
            });
        </script>
        {{ Session::forget('user_logged_in_ga4') }}
    @endif

</head>

<body class="h-full font-inter dark:bg-gray-950">

<!-- Facebook SDK for JavaScript -->
        <script>
          window.fbAsyncInit = function() {
            FB.init({
              appId      : '751116047366950',
              xfbml      : true,
              version    : 'v23.0'
            });
            FB.AppEvents.logPageView();
          };

          (function(d, s, id){
             var js, fjs = d.getElementsByTagName(s)[0];
             if (d.getElementById(id)) {return;}
             js = d.createElement(s); js.id = id;
             js.src = "https://connect.facebook.net/en_US/sdk.js";
             fjs.parentNode.insertBefore(js, fjs);
           }(document, 'script', 'facebook-jssdk'));
        </script>
        <!-- Fim do Facebook SDK -->

    {!! view_render_event('admin.layout.body.before') !!}

    <div
        id="app"
        class="h-full"
    >
        <!-- Flash Message Blade Component -->
        <x-admin::flash-group />

        <!-- Confirm Modal Blade Component -->
        <x-admin::modal.confirm />

        {!! view_render_event('admin.layout.content.before') !!}

        <!-- Page Header Blade Component -->
        <x-admin::layouts.header />

        <div
            class="group/container sidebar-collapsed flex gap-4"
            ref="appLayout"
        >
            <!-- Page Sidebar Blade Component -->
            <x-admin::layouts.sidebar.desktop />

            <div class="flex min-h-[calc(100vh-62px)] max-w-full flex-1 flex-col bg-gray-100 pt-3 transition-all duration-300 dark:bg-gray-950">
                <!-- Page Content Blade Component -->
                <div class="px-4 pb-6 ltr:lg:pl-[85px] rtl:lg:pr-[85px]">
                    {{ $slot }}
                </div>

                <!-- Powered By -->
                <div class="mt-auto pt-6">
                    <div class="border-t bg-white py-5 text-center text-sm font-normal dark:border-gray-800 dark:bg-gray-900 dark:text-white max-md:py-3">
                        <p>{!! core()->getConfigData('general.settings.footer.label') !!}</p>
                    </div>
                </div>
            </div>
        </div>

        {!! view_render_event('admin.layout.content.after') !!}
    </div>

    {!! view_render_event('admin.layout.body.after') !!}

    @stack('scripts')

    {!! view_render_event('admin.layout.vue-app-mount.before') !!}

    <script>
        /**
         * Load event, the purpose of using the event is to mount the application
         * after all of our `Vue` components which is present in blade file have
         * been registered in the app. No matter what `app.mount()` should be
         * called in the last.
         */
        window.addEventListener("load", function(event) {
            app.mount("#app");
        });
    </script>

    {!! view_render_event('admin.layout.vue-app-mount.after') !!}
</body>

</html>
