{{-- Singleton in-app prompt dialog — the app-styled replacement for
     window.prompt(). promptDialog() in app.js fills in the title, message,
     confirm-button label, and the input's value/placeholder per call, then
     resolves a promise with the trimmed text (null on cancel/Escape/empty).
     Same rationale as the confirm dialog: native prompt() is subject to
     Chrome's "prevent additional dialogs" suppression, and this matches the
     app's look. `hidden` is toggled together with `flex` in JS — never both
     static. --}}
<div id="prompt-dialog" role="dialog" aria-modal="true" aria-labelledby="prompt-dialog-title"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-md rounded-xl border border-zinc-700 bg-zinc-900 p-6 shadow-[0_24px_48px_-12px_rgba(0,0,0,0.8)]">
        <h2 id="prompt-dialog-title" class="text-base font-semibold text-zinc-100"></h2>
        <p id="prompt-dialog-message" class="mt-2 text-sm leading-relaxed text-zinc-300"></p>
        <input type="text" id="prompt-dialog-input" autocomplete="off"
               class="mt-4 w-full rounded-lg border border-zinc-600 bg-zinc-800 px-3 py-2 text-sm text-zinc-100 placeholder-zinc-500 focus:border-accent/60 focus:outline-none">
        <div class="mt-5 flex flex-wrap items-center justify-end gap-2.5">
            <button type="button" id="prompt-dialog-cancel"
                    class="rounded-lg border border-zinc-700 px-3.5 py-2 text-sm text-zinc-300 hover:bg-zinc-800">Cancel</button>
            <button type="button" id="prompt-dialog-confirm"
                    class="rounded-lg border border-accent/40 bg-accent/[0.08] px-3.5 py-2 text-sm font-medium text-accent hover:bg-accent/[0.14]"></button>
        </div>
    </div>
</div>
