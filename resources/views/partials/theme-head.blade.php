{{-- Sets the saved light/dark theme before the page paints (no flash). Allowed by the CSP via its nonce. --}}
<meta name="color-scheme" content="light dark">
<script nonce="@nonce">
    (function () {
        var theme = 'light';
        try {
            theme = localStorage.getItem('inb-theme');
            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
        } catch (e) {
            theme = 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
</script>
