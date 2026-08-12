// Tailwind v4 is a PostCSS plugin from its own package — `tailwindcss` itself is no longer the
// plugin, and `autoprefixer`/`postcss-import` are no longer needed (v4 does both internally).
// A v3-era config here fails with "trying to use tailwindcss directly as a PostCSS plugin".
const config = {
  plugins: {
    '@tailwindcss/postcss': {},
  },
};

export default config;
