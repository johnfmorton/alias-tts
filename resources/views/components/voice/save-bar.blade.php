{{-- The Edit voice page's one save path. Nothing on the page persists on its own
     — every step feeds this bar, which names exactly what changed and stays
     neutral until something actually differs from what was loaded. --}}
<div class="vsave-bar fixed inset-x-0 bottom-0 z-40 border-t border-white/8 bg-sticky/95 backdrop-blur"
     data-save-bar data-state="clean">
    <div class="mx-auto flex max-w-[1100px] flex-wrap items-center gap-x-3.5 gap-y-2 px-4 py-3.5 sm:px-8">
        <span class="vsave-bar__dot h-2 w-2 shrink-0 rounded-full bg-zinc-600" aria-hidden="true"></span>
        <span class="text-sm text-zinc-200" data-save-count role="status" aria-live="polite">No unsaved changes</span>
        <span class="min-w-0 flex-1 truncate text-[13px] text-zinc-500" data-save-detail>Nothing is sent to the engines until you save.</span>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <button type="button" data-save-discard
                    class="hidden px-3.5 py-2 text-sm text-zinc-400 transition hover:text-zinc-200">Discard</button>
            <button type="submit"
                    class="rounded-[9px] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-on transition hover:brightness-110">Save changes</button>
        </div>
    </div>
</div>
