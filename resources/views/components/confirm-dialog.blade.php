{{-- Singleton in-app confirmation dialog — the app-styled replacement for
     window.confirm(). confirmDialog() in app.js fills in the title, message,
     and confirm-button label per call and resolves a promise with the choice;
     forms opt in declaratively via data-confirm attributes (see app.js).
     Native confirm() stayed vulnerable to Chrome's "prevent additional
     dialogs" checkbox, which silently disabled destructive-action guards.
     `hidden` is toggled together with `flex` in JS — never both static. --}}
<div id="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-md rounded-xl border border-zinc-700 bg-zinc-900 p-6 shadow-[0_24px_48px_-12px_rgba(0,0,0,0.8)]">
        <h2 id="confirm-dialog-title" class="text-base font-semibold text-zinc-100"></h2>
        <p id="confirm-dialog-message" class="mt-2 text-sm leading-relaxed text-zinc-300"></p>
        <div class="mt-5 flex flex-wrap items-center justify-end gap-2.5">
            <button type="button" id="confirm-dialog-cancel"
                    class="rounded-lg border border-zinc-700 px-3.5 py-2 text-sm text-zinc-300 hover:bg-zinc-800">Cancel</button>
            {{-- Tone classes (danger red / warn amber) are applied per call in JS. --}}
            <button type="button" id="confirm-dialog-confirm"></button>
        </div>
    </div>
</div>
