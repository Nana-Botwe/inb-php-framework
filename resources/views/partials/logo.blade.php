{{-- INB PHP Framework mark: a hexagon "shield" with an INB monogram. --}}
<svg width="{{ $size ?? 40 }}" height="{{ $size ?? 40 }}" viewBox="0 0 64 64" role="img" aria-label="INB PHP Framework logo" xmlns="http://www.w3.org/2000/svg">
    <defs>
        <linearGradient id="inbGrad{{ $size ?? 40 }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#e11d48"/>
            <stop offset="1" stop-color="#f97316"/>
        </linearGradient>
    </defs>
    <path d="M32 3 57 17.5v29L32 61 7 46.5v-29z" fill="url(#inbGrad{{ $size ?? 40 }})"/>
    <path d="M32 10 51 21v22L32 54 13 43V21z" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="1.5"/>
    <text x="32" y="39" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="17" font-weight="800" fill="#fff" letter-spacing=".5">INB</text>
</svg>
