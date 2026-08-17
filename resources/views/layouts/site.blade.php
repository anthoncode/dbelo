<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen">

    <nav class="sticky top-0 z-50 bg-paper py-3.5 transition-colors duration-500 dark:bg-ink">
        <div class="mx-auto flex max-w-[1160px] items-center gap-6 px-6">

            <a href="{{ route('sounds.index') }}" wire:navigate class="flex items-center gap-3 text-[1.3rem] font-bold tracking-[-0.04em]">
                <span class="grid size-9 place-items-center rounded-[12px] bg-ink shadow-soft-sm dark:bg-brand">
                    <svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="#f5f4fa" stroke-width="2.4" stroke-linecap="round">
                        <path d="M3 12h2M8 6v12M13 3v18M18 8v8M21 11v2"/>
                    </svg>
                </span>
                dbelo
            </a>

            <div class="ml-auto flex items-center gap-1">
                @php($isBrowse = request()->routeIs('sounds.*'))

                <a href="{{ route('sounds.index') }}" wire:navigate
                   class="rounded-full px-4 py-2 text-sm transition duration-300 ease-dbelo
                          {{ $isBrowse
                              ? 'bg-ink text-paper shadow-soft-sm dark:bg-brand'
                              : 'text-ink/60 hover:bg-ink/5 hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper' }}">
                    Browse
                </a>

                @auth
                    @if (auth()->user()->canUpload())
                        <a href="{{ route('upload') }}" wire:navigate
                           class="flex items-center gap-2 rounded-full px-4 py-2 text-sm transition duration-300 ease-dbelo
                                  {{ request()->routeIs('upload')
                                      ? 'bg-ink text-paper shadow-soft-sm dark:bg-brand'
                                      : 'text-ink/60 hover:bg-ink/5 hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper' }}">
                            <x-icon name="arrow-up-from-bracket" style="solid" class="text-xs" />
                            Upload
                        </a>
                    @endif

                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('moderate') }}" wire:navigate
                           class="flex items-center gap-2 rounded-full px-4 py-2 text-sm transition duration-300 ease-dbelo
                                  {{ request()->routeIs('moderate')
                                      ? 'bg-ink text-paper shadow-soft-sm dark:bg-brand'
                                      : 'text-ink/60 hover:bg-ink/5 hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper' }}">
                            <x-icon name="shield-check" style="solid" class="text-xs" />
                            Review
                        </a>
                    @endif

                    <a href="{{ route('dashboard') }}" wire:navigate
                       class="rounded-full px-4 py-2 text-sm text-ink/60 transition duration-300 ease-dbelo hover:bg-ink/5 hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" wire:navigate
                       class="rounded-full px-4 py-2 text-sm text-ink/60 transition duration-300 ease-dbelo hover:bg-ink/5 hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper">
                        Log in
                    </a>
                    <a href="{{ route('register') }}" wire:navigate
                       class="rounded-full bg-brand px-5 py-2.5 text-sm font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                        Sign up
                    </a>
                @endauth
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-[1160px] px-6 py-8">
        {{ $slot }}
    </main>

    <footer class="mx-auto mt-16 max-w-[1160px] border-t border-ink/[0.07] px-6 py-10 text-sm text-ink/45 dark:border-paper/10 dark:text-paper/45">
        &copy; {{ date('Y') }} dbelo — sound effects library
    </footer>

    @fluxScripts
</body>
</html>
