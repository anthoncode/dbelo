@props([
    /** register · password · contact · login */
    'form',
])

{{--
    The widget, or nothing at all.

    Renders nothing when the captcha is off, when this form is not one of the
    protected ones, or — for login — when this visitor has not yet earned
    one. Nothing means NOTHING: no script tag, no empty div, no layout gap.
    A disabled feature that still loads a third-party script from Google on
    every page is a privacy claim you can no longer make.
--}}
@php
    $captcha = app(\App\Services\Captcha::class);

    $show = $form === 'login'
        ? $captcha->requiredForLogin(request())
        : $captcha->protects($form);
@endphp

@if ($show)
    @php
        $provider = $captcha->provider();
        $siteKey = $captcha->siteKey();
        $field = $captcha->tokenField();
    @endphp

    <div>
        @if ($provider === 'recaptcha_v2')
            <div class="g-recaptcha" data-sitekey="{{ $siteKey }}"></div>

        @elseif ($provider === 'turnstile')
            <div class="cf-turnstile" data-sitekey="{{ $siteKey }}"></div>

        @elseif ($provider === 'recaptcha_v3')
            {{-- v3 has no widget. The token is fetched at the moment of
                 submit and put in this hidden field.

                 Fetched on SUBMIT rather than on load, deliberately: a v3
                 token expires after two minutes, and a form filled in slowly
                 — which is what a careful human does — would arrive with a
                 dead token and be rejected. Asking at submit time is the
                 difference between v3 working and v3 rejecting your most
                 conscientious visitors. --}}
            <input type="hidden" name="{{ $field }}" value="">

            <script>
                (function () {
                    const input = document.currentScript.previousElementSibling;
                    const form = input.closest('form');
                    if (!form) return;

                    let passed = false;

                    form.addEventListener('submit', function (event) {
                        if (passed) return;

                        event.preventDefault();

                        // If the script never loaded — blocked, offline —
                        // submit anyway. The server still verifies, and it
                        // fails open for the same reason: a third party
                        // being down must not take the form down.
                        if (typeof grecaptcha === 'undefined') {
                            passed = true;
                            form.submit();
                            return;
                        }

                        grecaptcha.ready(function () {
                            grecaptcha.execute(@json($siteKey), { action: @json($form) })
                                .then(function (token) {
                                    input.value = token;
                                    passed = true;
                                    form.submit();
                                })
                                .catch(function () {
                                    passed = true;
                                    form.submit();
                                });
                        });
                    });
                })();
            </script>
        @endif

        @error($field)
            <p class="mt-2 text-sm text-danger">{{ $message }}</p>
        @enderror
    </div>

    {{-- Loaded once per page even if two widgets somehow appear. --}}
    @once
        <script src="{{ $captcha->scriptUrl() }}" async defer></script>
    @endonce
@endif
