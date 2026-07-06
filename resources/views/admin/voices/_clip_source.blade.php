@php($replace = $replace ?? false)
@php($enhanceOn = config('tts.enhance.enabled'))
@php($scripts = [
    ['title' => 'The Harbor — calm narration', 'text' => "The old harbor wakes slowly on a gray Thursday morning. Gulls wheel over the fishing boats while the tide pushes little waves against the wooden pier. By nine o'clock the market stalls are busy: bright oranges, fresh bread, and silver mackerel on beds of ice. A church bell rings twice, and somewhere behind the warehouses a dog barks at the passing train. It's an ordinary day, and that's exactly why I love it."],
    ['title' => 'The Plan — conversational', 'text' => "Would you believe it finally stopped raining? After six soggy days, the sky turned a brilliant, cloudless blue this afternoon. So here's the plan: we'll drive up the coast road, grab sandwiches at that little bakery in Rockport, and reach the lighthouse before sunset. Bring a jacket, though — the wind off the water gets sharp around seven. Oh, and don't forget your camera; last time the photos were spectacular!"],
    ['title' => 'Small Machines — explanatory', 'text' => "Here's a quick thought about everyday machines. A simple zipper has more than twenty tiny teeth per inch, each one shaped to catch its neighbor at exactly the right angle. Elevators, meanwhile, hang from six or eight steel cables, and any single one could hold the entire car. We walk past these small miracles daily — zipping a jacket, pressing a button — without ever asking how they actually work."],
])
{{--
    Reference-clip source: file upload today, with an opt-out cleanup step
    (denoise + enhance) that previews Original vs Cleaned-up before saving. The
    in-browser mic recorder attaches to this same widget in a later phase.
    Params: $replace (edit page = true), $fileHelp (help text under the input).
--}}
<div id="voice-clip-widget"
     data-prepare-url="{{ route('admin.voices.clips.store') }}"
     data-enhance-enabled="{{ $enhanceOn ? '1' : '' }}"
     data-target-min="15" data-target-max="30" data-max-seconds="60"
     class="space-y-3">
    <div>
        <label for="audio" class="mb-1.5 block text-sm font-medium">
            {{ $replace ? 'Replace reference clip' : 'Reference clip' }} <span class="text-zinc-500">(optional)</span>
        </label>
        <input id="audio" name="audio" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.flac" data-clip-file
               class="block w-full text-sm text-zinc-400 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-2 file:text-sm file:text-zinc-200 hover:file:bg-zinc-700">
        <p class="mt-1.5 text-xs text-zinc-500">{{ $fileHelp }}</p>
    </div>

    @if($enhanceOn)
        <label class="flex items-start gap-2 text-sm text-zinc-400">
            {{-- On a validation-failure redisplay, default to unchecked so an
                 unchecked box (which posts nothing) isn't silently re-checked. --}}
            <input type="checkbox" name="enhance" value="1" data-clip-enhance
                   {{ old('enhance', $errors->any() ? 0 : config('tts.enhance.default_on')) ? 'checked' : '' }}
                   class="mt-0.5 rounded border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
            <span>{{ $replace ? 'Clean up the replacement clip before saving' : 'Clean up the clip before saving' }} <span class="text-zinc-500">(recommended)</span>
                <span class="mt-0.5 block text-xs text-zinc-500">Removes room noise &amp; reverb with resemble-enhance, then you preview before saving. Your pick is loudness-normalized like any upload.</span>
            </span>
        </label>

        {{-- Preview an uploaded file's cleanup before committing. Revealed by JS
             once a file is chosen and cleanup is on. Saving without previewing
             still works (the server cleans up synchronously on the POST). --}}
        <button type="button" data-clip-preview
                class="hidden rounded-lg border border-cyan-500/40 px-3 py-1.5 text-sm text-cyan-300 hover:bg-cyan-500/10">
            Preview cleanup
        </button>

        {{-- In-browser recorder — revealed by JS only when the browser supports
             getUserMedia + MediaRecorder (progressive enhancement; upload works
             without it). Read one of the scripts for a phonetically rich sample. --}}
        <div data-recorder class="hidden space-y-3 rounded-lg border border-zinc-800 bg-zinc-900/40 p-4">
            <p class="text-sm font-medium text-zinc-200">…or record with your microphone</p>

            <div class="space-y-1.5">
                @foreach($scripts as $i => $script)
                    <label class="block cursor-pointer rounded-lg border border-zinc-700 px-3 py-2 hover:border-zinc-600">
                        <span class="flex items-center gap-2">
                            <input type="radio" name="recorder_script" value="{{ $i }}" data-recorder-script @checked($i === 0)
                                   class="border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                            <span class="text-sm font-medium text-zinc-300">{{ $script['title'] }}</span>
                        </span>
                        <span data-recorder-script-text class="mt-1.5 block text-xs leading-relaxed text-zinc-400 {{ $i === 0 ? '' : 'hidden' }}">{{ $script['text'] }}</span>
                    </label>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" data-recorder-enable class="rounded-lg bg-cyan-500 px-3 py-1.5 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Enable microphone</button>
                <button type="button" data-recorder-record class="hidden items-center gap-1.5 rounded-lg bg-red-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-400"><span class="inline-block h-2 w-2 rounded-full bg-white"></span> Record</button>
                <button type="button" data-recorder-stop class="hidden items-center gap-1.5 rounded-lg bg-zinc-700 px-3 py-1.5 text-sm font-medium text-zinc-100 hover:bg-zinc-600"><span class="inline-block h-2 w-2 bg-white"></span> Stop</button>
                <button type="button" data-recorder-redo class="hidden text-sm text-zinc-400 hover:text-zinc-200">Re-record</button>
                <span data-recorder-timer class="hidden font-mono text-sm text-zinc-400">0:00</span>
            </div>

            <div data-recorder-meter-wrap class="hidden h-2 overflow-hidden rounded bg-zinc-800">
                <div data-recorder-meter class="h-full w-0 rounded bg-emerald-500"></div>
            </div>
            <p data-recorder-guide class="hidden text-xs text-zinc-500"></p>

            <div data-recorder-review class="hidden space-y-2">
                <span class="aplayer aplayer--chunk" data-recorder-player>
                    <button type="button" class="aplayer__btn"><span class="aplayer__icon"></span></button>
                    <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                    <span class="aplayer__time">0:00 / 0:00</span>
                    <audio class="aplayer__native" preload="metadata"></audio>
                </span>
                <button type="button" data-recorder-use class="rounded-lg bg-cyan-500 px-3 py-1.5 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Use this recording</button>
            </div>

            <p data-recorder-status role="status" aria-live="polite" class="text-sm text-zinc-400"></p>
        </div>

        {{-- A/B compare — filled in by JS after the prepare call returns. --}}
        <div data-clip-ab class="hidden space-y-4 rounded-lg border border-zinc-800 bg-zinc-900/40 p-4">
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium text-zinc-200">Pick the take to save</p>
                <button type="button" data-clip-reset class="text-xs text-zinc-400 hover:text-zinc-200">Start over</button>
            </div>
            <p data-clip-ab-warning class="hidden text-xs text-amber-300"></p>

            <label data-clip-row="enhanced" class="flex items-center gap-3">
                <input type="radio" name="clip_choice" value="enhanced" data-clip-choice
                       class="border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                <span class="w-28 shrink-0 text-sm text-zinc-300">Cleaned up <span aria-hidden="true">✨</span></span>
                <span class="aplayer aplayer--chunk flex-1" data-clip-player="enhanced">
                    <button type="button" class="aplayer__btn"><span class="aplayer__icon"></span></button>
                    <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                    <span class="aplayer__time">0:00 / 0:00</span>
                    <audio class="aplayer__native" preload="metadata"></audio>
                </span>
            </label>

            <label data-clip-row="original" class="flex items-center gap-3">
                <input type="radio" name="clip_choice" value="original" data-clip-choice
                       class="border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                <span class="w-28 shrink-0 text-sm text-zinc-300">Original</span>
                <span class="aplayer aplayer--chunk flex-1" data-clip-player="original">
                    <button type="button" class="aplayer__btn"><span class="aplayer__icon"></span></button>
                    <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                    <span class="aplayer__time">0:00 / 0:00</span>
                    <audio class="aplayer__native" preload="metadata"></audio>
                </span>
            </label>
        </div>

        <p data-clip-status role="status" aria-live="polite" class="text-sm text-zinc-400"></p>
    @endif

    {{-- Submitted with the form when a previewed clip is chosen; the radios above
         supply clip_choice. Cleared by JS whenever the preview is abandoned. --}}
    <input type="hidden" name="clip_token" data-clip-token value="">
</div>
