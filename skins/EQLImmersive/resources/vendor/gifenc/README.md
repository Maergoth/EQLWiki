# gifenc 1.0.3

Unmodified CommonJS distribution (`dist/gifenc.js`) from
[mattdesl/gifenc](https://github.com/mattdesl/gifenc/tree/v1.0.3), published as
[`gifenc@1.0.3`](https://www.npmjs.com/package/gifenc/v/1.0.3).
The MIT license is preserved in `LICENSE.md`.

The npm tarball was verified against registry SHA-512 integrity:
`sha512-xdr6AdrfGBcfzncONUOlXMBuc5wJDtOueE3c5rdG0oNgtINLD+f2iFZltrBRZYzACRbKr+mSVU/x98zv2u3jmw==`.

ResourceLoader exposes it as the lazy `skins.EQLImmersive.gifEncoder` package.
Only cross-format GIF uploads load it. The upload converter quantizes one
decoded frame to at most 256 colors with GIF's one-bit transparency; matching
GIF files retain their original bytes and animation. No CDN is used at runtime.
