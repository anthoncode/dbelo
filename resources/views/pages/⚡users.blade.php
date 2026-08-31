<?php

use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.site')] #[Title('Users')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function setRole(int $userId, string $role): void
    {
        abort_unless(in_array($role, [User::ROLE_USER, User::ROLE_COLLABORATOR, User::ROLE_ADMIN], true), 422);

        $user = User::findOrFail($userId);

        // Locking yourself out of the admin panel is a one-way trip.
        if ($user->id === auth()->id()) {
            session()->flash('users', 'You cannot change your own role.');

            return;
        }

        // 'role' is deliberately not mass assignable, so this is explicit.
        $user->forceFill(['role' => $role])->save();

        session()->flash('users', "{$user->name} is now a {$role}.");
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->withCount(['sounds', 'downloads'])
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('username', 'like', "%{$s}%")))
            ->when($this->role, fn ($q, $r) => $q->where('role', $r))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function counts(): array
    {
        return User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role')->all();
    }
}; ?>

<div>
    <div class="mx-auto max-w-4xl">

        <div class="mb-7">
            <div class="micro">Admin</div>
            <h1 class="mt-2 text-3xl font-semibold">Users</h1>
            <p class="mt-2 text-ink/60 dark:text-paper/60">
                Promote someone to contributor and they can upload sounds.
            </p>
        </div>

        @if (session('users'))
            <div class="mb-6 flex items-center gap-3 rounded-card bg-surface p-4 shadow-soft-md dark:bg-surface-dark">
                <span class="grid size-9 shrink-0 place-items-center rounded-[12px] bg-brand text-white">
                    <x-icon name="check" style="solid" class="text-sm" />
                </span>
                <span class="text-[0.95rem]">{{ session('users') }}</span>
            </div>
        @endif

        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex min-w-[240px] flex-1 items-center gap-3 rounded-full bg-surface py-2.5 pl-5 pr-4 shadow-soft-sm dark:bg-surface-dark">
                <x-icon name="magnifying-glass" style="solid" class="shrink-0 text-ink/35 dark:text-paper/35" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email or username"
                       class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[0.92rem] font-light placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:placeholder:text-paper/35" />
            </div>

            @foreach (['' => 'All', 'user' => 'Users', 'collaborator' => 'Contributors', 'admin' => 'Admins'] as $key => $label)
                <button wire:click="$set('role', '{{ $key }}')"
                        class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                               {{ $role === $key ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                    {{ $label }}
                    @if ($key)
                        <span class="rounded-full px-2 py-0.5 text-[0.72rem] {{ $role === $key ? 'bg-paper/20' : 'bg-ink/[0.06] dark:bg-paper/10' }}">
                            {{ $this->counts[$key] ?? 0 }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
            @forelse ($this->users as $user)
                <div wire:key="user-{{ $user->id }}"
                     class="flex flex-wrap items-center gap-4 rounded-control px-4 py-3.5 transition duration-350 ease-dbelo hover:bg-ink/[0.04] dark:hover:bg-paper/[0.06]">

                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ink text-[0.8rem] font-medium text-paper dark:bg-paper/15">
                        {{ $user->initials() }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-[0.95rem]">{{ $user->name }}</span>
                            @if ($user->id === auth()->id())
                                <span class="micro">you</span>
                            @endif
                        </div>
                        <div class="micro mt-0.5 truncate">
                            {{ $user->email }} · {{ $user->sounds_count }} {{ Str::plural('sound', $user->sounds_count) }}
                            · {{ $user->downloads_count }} {{ Str::plural('download', $user->downloads_count) }}
                        </div>
                    </div>

                    <div class="flex gap-1.5">
                        @foreach (['user' => 'User', 'collaborator' => 'Contributor', 'admin' => 'Admin'] as $key => $label)
                            <button wire:click="setRole({{ $user->id }}, '{{ $key }}')"
                                    @disabled($user->id === auth()->id())
                                    class="rounded-full px-3.5 py-2 text-[0.78rem] transition duration-300 ease-dbelo disabled:opacity-40
                                           {{ $user->role === $key
                                               ? 'bg-brand text-white shadow-soft-sm'
                                               : 'bg-paper shadow-soft-sm hover:-translate-y-0.5 dark:bg-paper/10' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="py-16 text-center">
                    <p>No users found</p>
                </div>
            @endforelse
        </div>

        <div class="mt-8">{{ $this->users->links() }}</div>
    </div>
</div>
