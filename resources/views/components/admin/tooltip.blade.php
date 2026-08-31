@props(['label'])

{{-- Only meaningful while the sidebar is collapsed: an icon on its own is a
     guess until you have hovered it once. --}}
<span x-show="collapsed" x-cloak
      class="pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-y-1/2 translate-x-1 whitespace-nowrap rounded-lg bg-paper px-3 py-1.5 text-[0.8rem] font-medium text-ink opacity-0 shadow-xl transition duration-200 ease-dbelo group-hover/item:translate-x-0 group-hover/item:opacity-100">
    {{ $label }}
</span>
