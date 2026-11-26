// Signup browser check. The server gives a seed and a number of bits. The page
// finds a number whose SHA-256 of "seed:number" starts with that many zero bits,
// then turns on the Create store button. The server checks the answer once.
(function () {
    var form = document.querySelector('form[data-proof-seed]');
    if (!form) {
        return;
    }
    var seed = form.getAttribute('data-proof-seed') || '';
    var bits = parseInt(form.getAttribute('data-proof-bits') || '0', 10);
    var nonceField = form.querySelector('input[name="proof_nonce"]');
    var button = form.querySelector('[data-proof-button]');
    var status = form.querySelector('[data-proof-status]');
    if (!nonceField || !button || !/^[0-9a-f]{32}$/.test(seed) || !(bits >= 8 && bits <= 28)) {
        return;
    }

    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];
    var w = new Array(64);
    var prefix = seed + ':';

    // First 32 bits of SHA-256 for an ASCII message shorter than 56 bytes.
    function firstWord(message) {
        var i;
        for (i = 0; i < 16; i++) {
            w[i] = 0;
        }
        for (i = 0; i < message.length; i++) {
            w[i >> 2] |= message.charCodeAt(i) << (24 - (i % 4) * 8);
        }
        w[message.length >> 2] |= 0x80 << (24 - (message.length % 4) * 8);
        w[15] = message.length * 8;
        for (i = 16; i < 64; i++) {
            var a0 = w[i - 15];
            var a1 = w[i - 2];
            var s0 = ((a0 >>> 7) | (a0 << 25)) ^ ((a0 >>> 18) | (a0 << 14)) ^ (a0 >>> 3);
            var s1 = ((a1 >>> 17) | (a1 << 15)) ^ ((a1 >>> 19) | (a1 << 13)) ^ (a1 >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
        }
        var a = 0x6a09e667, b = 0xbb67ae85, c = 0x3c6ef372, d = 0xa54ff53a;
        var e = 0x510e527f, f = 0x9b05688c, g = 0x1f83d9ab, h = 0x5be0cd19;
        for (i = 0; i < 64; i++) {
            var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
            var ch = (e & f) ^ (~e & g);
            var t1 = (h + S1 + ch + K[i] + w[i]) | 0;
            var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
            var maj = (a & b) ^ (a & c) ^ (b & c);
            var t2 = (S0 + maj) | 0;
            h = g;
            g = f;
            f = e;
            e = (d + t1) | 0;
            d = c;
            c = b;
            b = a;
            a = (t1 + t2) | 0;
        }
        return (a + 0x6a09e667) >>> 0;
    }

    var nonce = 0;
    var shift = 32 - bits;
    function work() {
        var stop = nonce + 20000;
        for (; nonce < stop; nonce++) {
            if ((firstWord(prefix + nonce) >>> shift) === 0) {
                nonceField.value = String(nonce);
                button.disabled = false;
                if (status) {
                    status.textContent = 'Your browser check is done.';
                }
                return;
            }
        }
        setTimeout(work, 0);
    }
    setTimeout(work, 0);
})();
