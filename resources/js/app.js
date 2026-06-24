import Alpine from 'alpinejs';

// Shared fetch helpers: a CSRF'd JSON POST and a JSON GET, used by every Alpine
// component instead of re-inlining the fetch + headers. / 共有fetchヘルパ。
window.wyd = {
    async postJson(url, body = {}) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
            },
            body: JSON.stringify(body),
        });
        if (! res.ok) throw new Error('http ' + res.status);
        return res.status === 204 ? null : res.json();
    },
    async getJson(url) {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        return res.json();
    },
};

window.Alpine = Alpine;
Alpine.start();
