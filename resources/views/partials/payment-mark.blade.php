{{--
    Drawn payment marks, so the footer looks right with no image files in the
    project. Each one is plain markup the CSS styles; drop a real PNG into
    public/images/payments/ and the footer uses that instead (see the footer).

    @param string $key
    @param string $name
--}}
@switch($key)
    @case('visa')
        <b class="pm pm--visa">VISA</b>
        @break

    @case('mastercard')
        <span class="pm pm--mc">
            <svg viewBox="0 0 46 28" role="img" aria-label="{{ $name }}">
                <circle cx="18" cy="14" r="11.5" fill="#eb001b"/>
                <circle cx="28" cy="14" r="11.5" fill="#f79e1b" style="mix-blend-mode:multiply"/>
            </svg>
            <i>mastercard</i>
        </span>
        @break

    @case('amex')
        <b class="pm pm--amex">American<br>Express</b>
        @break

    @case('bkash')
        <span class="pm pm--bkash">
            <i>বিকাশ</i>
            {{-- The bird sits after the word, as on the mark itself. --}}
            <svg viewBox="0 0 34 24" aria-hidden="true">
                <path d="M1 15c6 1 11-1 15-6-1 5 1 9 5 11-7 1-12-1-15-5-1 2-3 2-5 0Z" fill="#e2136e"/>
                <path d="M16 9c3-3 8-5 13-5-3 3-5 6-6 9-2-2-4-3-7-4Z" fill="#d81b60"/>
            </svg>
        </span>
        @break

    @case('nagad')
        <span class="pm pm--nagad">
            <svg viewBox="0 0 30 30" aria-hidden="true">
                <circle cx="15" cy="15" r="14" fill="#ee6723"/>
                <path d="M15 4c6 3 9 7 9 11a9 9 0 0 1-18 0c0-3 2-5 5-7-1 4 1 6 3 7-1-4 0-8 1-11Z" fill="#fff"/>
            </svg>
            <i>নগদ</i>
        </span>
        @break

    @case('rocket')
        <span class="pm pm--rocket">
            <em>ROCKET</em>
            <i>রকেট</i>
        </span>
        @break

    @default
        <span class="pm pm--plain">{{ $name }}</span>
@endswitch
