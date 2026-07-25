@php($replace = $replace ?? false)
@php($enhanceOn = config('tts.enhance.enabled'))
@php($scripts = [
    ['title' => 'The Harbor', 'tagline' => 'calm narration · ~20s', 'text' => "The old harbor wakes slowly on a gray Thursday morning. Gulls wheel over the fishing boats while the tide pushes little waves against the wooden pier. By nine o'clock the market stalls are busy: bright oranges, fresh bread, and silver mackerel on beds of ice. It's an ordinary day, and that's exactly why I love it."],
    ['title' => 'The Plan', 'tagline' => 'conversational · ~20s', 'text' => "Would you believe it finally stopped raining? After six soggy days, the sky turned a brilliant, cloudless blue this afternoon. So here's the plan: we'll drive up the coast road, grab sandwiches at that little bakery in Rockport, and reach the lighthouse before sunset. Bring a jacket, though — the wind off the water gets sharp around seven. Oh, and don't forget your camera; last time the photos were spectacular!"],
    ['title' => 'Small Machines', 'tagline' => 'explanatory · ~25s', 'text' => "Here's a quick thought about everyday machines. A simple zipper has more than twenty tiny teeth per inch, each one shaped to catch its neighbor at exactly the right angle. Elevators, meanwhile, hang from six or eight steel cables, and any single one could hold the entire car. We walk past these small miracles daily — zipping a jacket, pressing a button — without ever asking how they actually work."],
])
@php($fileInputClass = 'block w-full text-sm text-zinc-400 file:mr-3 file:rounded-lg file:border-0 file:bg-white/8 file:px-3 file:py-2 file:text-sm file:text-zinc-200 hover:file:bg-white/12')

{{-- Record / Upload. Lives inside the Voice source step on both voice pages, so
     it carries no heading of its own. Recording guidance is folded into the
     Record body behind a disclosure — it's essential when you're about to speak
     and pure noise when you're uploading a file you already have.

     A clip and a built-in voice are ALTERNATIVE sources, and the provider gives
     the clip absolute precedence (ReplicateChatterboxProvider sends `voice` /
     `speaker` only when there is no reference audio). So initVoiceFlow() hides
     this whole section while a built-in is chosen — leaving it up would offer a
     control that silently overrides that choice. --}}
<div data-clip-section data-clip-replace="{{ $replace ? '1' : '' }}">
    @php($refMax = (float) config('tts.reference_max_seconds', 25))
    <div id="voice-clip-widget" data-prepare-url="{{ route('admin.voices.clips.store') }}"
         data-enhance-enabled="{{ $enhanceOn ? '1' : '' }}" data-normalize-enabled="{{ config('tts.normalize_reference') ? '1' : '' }}"
         data-target-min="15" data-target-max="25" data-max-seconds="{{ $refMax > 0 ? (int) ceil($refMax + 5) : 60 }}">
        @if($enhanceOn)
            <div class="rounded-[14px] border border-white/8 bg-inset p-2">
                {{-- Segmented control + Upload/Record bodies. Hidden once the A/B chooser is up. --}}
                <div data-clip-panel>
                    <div class="m-4 mb-2 flex gap-1.5 rounded-[11px] border border-white/8 bg-inset p-1.5">
                        <button type="button" data-clip-mode="record" class="flex-1 rounded-[8px] py-2.5 text-center text-sm text-zinc-400 transition">● Record with mic</button>
                        <button type="button" data-clip-mode="upload" class="flex-1 rounded-[8px] py-2.5 text-center text-sm text-zinc-400 transition">↑ Upload a file</button>
                    </div>

                    {{-- Upload body — visible by default so it works without JS. --}}
                    <div data-clip-body="upload" class="px-4 pb-4 pt-2">
                        <input id="audio" name="audio" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.flac" data-clip-file
                               data-dirty-group="reference clip" data-voice-source class="{{ $fileInputClass }}">
                        <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">{{ $fileHelp }}</p>
                        <button type="button" data-clip-preview class="mt-3 hidden rounded-[9px] border border-accent/40 px-3.5 py-2 text-sm text-accent transition hover:bg-accent/10">Preview cleanup</button>
                    </div>

                    {{-- Record body — script picker + teleprompter. --}}
                    <div data-clip-body="record" class="hidden px-4 pb-4 pt-2">
                        <div class="grid grid-cols-1 gap-[18px] md:grid-cols-[290px_1fr]">
                            <div>
                                <div class="mb-2.5 text-xs font-semibold text-zinc-400">Pick a script to read</div>
                                <div class="flex flex-col gap-2">
                                    @foreach($scripts as $i => $s)
                                        <label class="flex cursor-pointer items-center gap-2.5 rounded-[10px] border border-white/10 px-3.5 py-3 transition has-[:checked]:border-accent/50 has-[:checked]:bg-accent/[0.08]">
                                            <input type="radio" name="recorder_script" value="{{ $i }}" data-recorder-script
                                                   data-title="{{ $s['title'] }}" data-tagline="{{ $s['tagline'] }}" data-text="{{ $s['text'] }}"
                                                   @checked($i === 0) class="text-accent focus:ring-accent/30">
                                            <span>
                                                <span class="block text-sm font-semibold text-zinc-100">{{ $s['title'] }}</span>
                                                <span class="block text-xs text-zinc-500">{{ $s['tagline'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                {{-- The trailing sentence tracks the processing checkboxes below (refreshProcessingHint in app.js). --}}
                                <div class="mt-4 border-t border-white/8 pt-4 text-[12.5px] leading-relaxed text-zinc-500">Read the passage at a natural pace.<span data-recorder-processing> We'll clean up room noise and normalize loudness after.</span></div>
                            </div>

                            <div class="flex flex-col rounded-[12px] border border-white/10 bg-inset p-6 md:px-7">
                                <div class="mb-4 flex items-center justify-between gap-3">
                                    <div class="text-[15px] font-bold text-zinc-100"><span data-recorder-title>{{ $scripts[0]['title'] }}</span> <span class="text-[13px] font-normal text-zinc-500" data-recorder-tagline>— {{ $scripts[0]['tagline'] }}</span></div>
                                    <div class="flex flex-shrink-0 items-center gap-2">
                                        <span class="text-xs text-zinc-500">Text size</span>
                                        <button type="button" data-recorder-size="-1" aria-label="Smaller text" class="grid h-[26px] w-[26px] place-items-center rounded-[7px] border border-white/12 text-xs text-zinc-300 transition hover:bg-white/5">A−</button>
                                        <button type="button" data-recorder-size="1" aria-label="Larger text" class="grid h-[26px] w-[26px] place-items-center rounded-[7px] border border-white/12 text-[15px] text-zinc-300 transition hover:bg-white/5">A+</button>
                                    </div>
                                </div>
                                <p data-recorder-passage class="m-0 max-w-[60ch] text-[23px] font-normal leading-[1.75] tracking-[0.01em] text-zinc-100">{{ $scripts[0]['text'] }}</p>

                                <div class="mt-6 border-t border-white/8 pt-5">
                                    @include('admin.voices._recording_tips')
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="button" data-recorder-enable class="inline-flex items-center gap-2.5 rounded-[10px] bg-accent px-5 py-3 text-[15px] font-semibold text-accent-on transition hover:brightness-110"><span class="h-[11px] w-[11px] rounded-full bg-accent-on"></span>Enable microphone</button>
                                        <button type="button" data-recorder-record class="hidden items-center gap-2 rounded-[10px] bg-bad px-5 py-3 text-[15px] font-semibold text-zinc-950 transition hover:brightness-110"><span class="h-2.5 w-2.5 rounded-full bg-zinc-950"></span>Record</button>
                                        <button type="button" data-recorder-stop class="hidden items-center gap-2 rounded-[10px] bg-white/10 px-5 py-3 text-[15px] font-semibold text-zinc-100 transition hover:bg-white/15"><span class="h-2.5 w-2.5 bg-zinc-100"></span>Stop</button>
                                        <button type="button" data-recorder-redo class="hidden text-sm text-zinc-400 hover:text-zinc-200">Re-record</button>
                                        <span data-recorder-timer class="hidden font-mono text-sm text-zinc-400">0:00</span>
                                        {{-- Input picker: populated + shown once the mic is granted (labels are blank before). --}}
                                        <select data-recorder-device aria-label="Microphone input device"
                                                class="hidden max-w-[240px] rounded-[9px] border border-edge bg-inset px-2.5 py-2 text-[13px] text-zinc-300 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:opacity-50"></select>
                                        <span data-recorder-hint class="text-[13px] text-zinc-500">Mic access is requested once, in your browser.</span>
                                    </div>
                                    <div data-recorder-meter-wrap class="mt-3 hidden h-2 overflow-hidden rounded bg-white/10"><div data-recorder-meter class="h-full w-0 rounded bg-emerald-500"></div></div>
                                    <p data-recorder-guide class="mt-2 hidden text-xs text-zinc-500"></p>
                                    <div data-recorder-review class="mt-3 hidden space-y-2">
                                        <span class="aplayer aplayer--chunk" data-recorder-player>
                                            <button type="button" class="aplayer__btn"><span class="aplayer__icon"></span></button>
                                            <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                                            <span class="aplayer__time">0:00 / 0:00</span>
                                            <audio class="aplayer__native" preload="metadata"></audio>
                                        </span>
                                        <button type="button" data-recorder-use class="rounded-[9px] bg-accent px-4 py-2 text-sm font-semibold text-accent-on transition hover:brightness-110">Use this recording</button>
                                    </div>
                                    <p data-recorder-status role="status" aria-live="polite" class="mt-2 text-sm text-zinc-400"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- A/B chooser — replaces the panel once a preview is prepared. --}}
                <div data-clip-ab class="m-4 hidden space-y-4 rounded-[12px] border border-white/8 bg-inset p-5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-zinc-100">Pick the take to save</p>
                        <button type="button" data-clip-reset class="text-xs text-zinc-400 hover:text-zinc-200">Start over</button>
                    </div>
                    <p data-clip-ab-warning class="hidden text-xs text-amber-300"></p>

                    <label data-clip-row="enhanced" class="flex items-center gap-3">
                        <input type="radio" name="clip_choice" value="enhanced" data-clip-choice data-dirty-group="reference clip" class="text-accent focus:ring-accent/30">
                        <span class="w-28 shrink-0 text-sm text-zinc-200">Cleaned up <span aria-hidden="true">✨</span></span>
                        <span class="aplayer aplayer--chunk flex-1" data-clip-player="enhanced">
                            <button type="button" class="aplayer__btn"><span class="aplayer__icon"></span></button>
                            <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                            <span class="aplayer__time">0:00 / 0:00</span>
                            <audio class="aplayer__native" preload="metadata"></audio>
                        </span>
                    </label>
                    <label data-clip-row="original" class="flex items-center gap-3">
                        <input type="radio" name="clip_choice" value="original" data-clip-choice data-dirty-group="reference clip" class="text-accent focus:ring-accent/30">
                        <span class="w-28 shrink-0 text-sm text-zinc-200">Original</span>
                        <span class="aplayer aplayer--chunk flex-1" data-clip-player="original">
                            <button type="button" class="aplayer__btn"><span class="aplayer__icon"></span></button>
                            <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                            <span class="aplayer__time">0:00 / 0:00</span>
                            <audio class="aplayer__native" preload="metadata"></audio>
                        </span>
                    </label>

                    {{-- Mic path only (renderAB toggles it): discard this take and go straight back to an armed recorder. --}}
                    <button type="button" data-clip-rerecord class="hidden rounded-[9px] border border-white/15 px-3.5 py-2 text-sm text-zinc-300 transition hover:border-bad/50 hover:text-bad">Reject &amp; re-record</button>
                </div>

                <p data-clip-status role="status" aria-live="polite" class="mx-4 mb-3 text-sm text-zinc-400"></p>
            </div>

            {{-- Clip options. --}}
            <div class="mt-4 flex flex-col gap-3.5 px-1">
                <label class="flex items-start gap-3 text-sm text-zinc-300">
                    {{-- On a validation-failure redisplay, default to unchecked so an
                         unchecked box (which posts nothing) isn't silently re-checked. --}}
                    <input type="checkbox" name="enhance" value="1" data-clip-enhance data-dirty-group="reference clip" data-dirty-value="cleanup"
                           {{ old('enhance', $errors->any() ? 0 : config('tts.enhance.default_on')) ? 'checked' : '' }}
                           class="mt-0.5 rounded-[5px] border-white/25 bg-inset text-accent focus:ring-accent/30">
                    <span>Clean up the {{ $replace ? 'replacement ' : '' }}clip before saving <span class="text-zinc-500">(recommended)</span>
                        <span class="mt-0.5 block max-w-[640px] text-[12.5px] leading-relaxed text-zinc-500">Removes room noise &amp; reverb with resemble-enhance, then you preview before saving. Your pick is loudness-normalized like any upload.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 text-sm text-zinc-300">
                    <input type="checkbox" name="raw" value="1" data-clip-raw data-dirty-group="reference clip" data-dirty-value="store raw" {{ old('raw') ? 'checked' : '' }} class="mt-0.5 rounded-[5px] border-edge bg-inset text-accent focus:ring-accent/30">
                    <span>Store raw <span class="text-zinc-500">(skip auto-normalization){{ $replace ? ' — only applies when replacing the clip' : '' }}</span></span>
                </label>
            </div>

            {{-- Submitted with the form when a previewed clip is chosen; the radios above supply clip_choice. --}}
            <input type="hidden" name="clip_token" data-clip-token data-dirty-group="reference clip" data-dirty-value="new clip ready" data-voice-source value="">
        @else
            {{-- Cleanup disabled — plain upload, no recorder/preview. The tips
                 still belong here: whatever you record elsewhere lands in this box. --}}
            <div class="rounded-[14px] border border-white/8 bg-inset p-6">
                @include('admin.voices._recording_tips', ['tipsId' => 'recording-tips-plain'])
                <input id="audio" name="audio" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.flac"
                       data-dirty-group="reference clip" data-voice-source class="{{ $fileInputClass }}">
                <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">{{ $fileHelp }}</p>
            </div>
            <label class="mt-4 flex items-start gap-3 px-1 text-sm text-zinc-300">
                <input type="checkbox" name="raw" value="1" data-dirty-group="reference clip" data-dirty-value="store raw" {{ old('raw') ? 'checked' : '' }} class="mt-0.5 rounded-[5px] border-edge bg-inset text-accent focus:ring-accent/30">
                <span>Store raw <span class="text-zinc-500">(skip auto-normalization){{ $replace ? ' — only applies when replacing the clip' : '' }}</span></span>
            </label>
        @endif
    </div>

    {{-- A staged clip is a decision you can take back. The A/B chooser carries
         its own "Start over", so this stands in for every other way a clip can
         end up staged — chiefly a file picked with cleanup switched off, which
         never reaches that chooser. Shown by initVoiceFlow(). --}}
    <div data-staged-clip class="mt-4 hidden flex-wrap items-center gap-3 rounded-[12px] border border-ok/30 bg-inset px-[18px] py-3">
        <p class="min-w-0 flex-1 truncate text-[13px] text-zinc-300" data-staged-clip-text></p>
        <button type="button" data-clear-staged-clip
                class="shrink-0 rounded-[8px] border border-edge px-3.5 py-[7px] text-[13px] text-zinc-300 transition hover:border-bad/50 hover:text-bad"></button>
    </div>
</div>
